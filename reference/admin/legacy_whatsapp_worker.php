<?php
/**
 * عامل إرسال واتساب نجاز القديم فقط.
 *
 * يعمل من Cron عبر CLI، أو تستدعيه صفحة الإدارة بعد التحقق من المشرف وCSRF.
 * لا يقرأ جداول واتساب العام ولا يرسل إلا عناصر legacy_wa_outbox.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/legacy_whatsapp_bridge.php';

function legacyWaWorkerLog(string $event, array $context = []): void
{
    $logFile = __DIR__ . '/../legacy-wa-worker.log';
    $safe = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safe[(string)$key] = $value;
        }
    }
    $line = [
        'timestamp' => date('Y-m-d H:i:sP'),
        'event' => $event,
        'context' => $safe,
    ];
    $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode(['timestamp' => date('Y-m-d H:i:sP'), 'event' => 'logger_json_error']);
    }
    @file_put_contents($logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function legacyWaRunWorker(PDO $pdo, int $limit = 20, bool $waitForDue = false, ?int $onlyOutboxId = null): array
{
    $limit = max(1, min(100, $limit));
    $result = [
        'ok' => true,
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => '',
        'error' => '',
    ];

    legacyWaWorkerLog('worker_start', ['limit' => $limit, 'wait_for_due' => $waitForDue, 'only_outbox_id' => $onlyOutboxId]);

    $schema = legacyWaEnsureSchema($pdo);
    if (!$schema['ok']) {
        $result['ok'] = false;
        $result['skipped'] = 'storage_unavailable';
        $result['error'] = legacyWaSubstr((string)($schema['error'] ?? ''), 0, 300);
        legacyWaWorkerLog('worker_stop', ['reason' => $result['skipped']]);
        return $result;
    }

    // تنظيف محدود عند تشغيل العامل فقط؛ لا يُستدعى من GET للوحة.
    $cleanup = legacyWaCleanupTerminalOutbox($pdo, 100);
    legacyWaWorkerLog('terminal_cleanup', [
        'ok' => !empty($cleanup['ok']),
        'outbox_deleted' => (int)($cleanup['outbox_deleted'] ?? 0),
        'inbound_deleted' => (int)($cleanup['inbound_deleted'] ?? 0),
    ]);

    if (legacyWaGetSetting($pdo, 'enabled') !== '1' || legacyWaGetSetting($pdo, 'mode') !== 'send') {
        $result['skipped'] = 'bridge_not_in_send_mode';
        legacyWaWorkerLog('worker_stop', ['reason' => $result['skipped']]);
        return $result;
    }

    try {
        $dueClause = $waitForDue ? '' : 'AND available_at <= NOW()';
        $idClause = ($onlyOutboxId !== null && $onlyOutboxId > 0) ? 'AND id = ?' : '';
        $params = ($onlyOutboxId !== null && $onlyOutboxId > 0) ? [$onlyOutboxId] : [];
        $stmt = $pdo->prepare("SELECT id FROM legacy_wa_outbox
            WHERE status IN ('pending','failed')
              {$dueClause}
              {$idClause}
              AND attempt_count < 5
            ORDER BY available_at ASC, id ASC LIMIT {$limit}");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = legacyWaSubstr($e->getMessage(), 0, 300);
        legacyWaWorkerLog('worker_database_error', ['stage' => 'select', 'error' => $result['error']]);
        return $result;
    }

    legacyWaWorkerLog('outbox_selected', ['count' => count($ids)]);

    foreach ($ids as $idValue) {
        $outboxId = (int)$idValue;
        if ($outboxId < 1) continue;

        $lockName = 'njaz_legacy_wa_outbox_' . $outboxId;
        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $lockStmt->execute([$lockName]);
            $locked = (int)$lockStmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            $locked = false;
        }
        if (!$locked) {
            legacyWaWorkerLog('outbox_skipped_locked', ['outbox_id' => $outboxId]);
            continue;
        }

        $row = null;
        $newAttempt = 0;
        try {
            $pdo->beginTransaction();
            $rowStmt = $pdo->prepare("SELECT * FROM legacy_wa_outbox WHERE id=? FOR UPDATE");
            $rowStmt->execute([$outboxId]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row
                || !in_array((string)$row['status'], ['pending', 'failed'], true)
                || (int)$row['attempt_count'] >= 5) {
                $pdo->rollBack();
                legacyWaWorkerLog('outbox_skipped_not_due', ['outbox_id' => $outboxId]);
                continue;
            }
            $availableTs = strtotime((string)$row['available_at']);
            if ($availableTs !== false && $availableTs > time()) {
                $pdo->rollBack();
                if (!$waitForDue) {
                    legacyWaWorkerLog('outbox_skipped_not_due', ['outbox_id' => $outboxId, 'wait_for_due' => false]);
                    continue;
                }
                // عند التشغيل اليدوي ننتظر حتى الموعد الفعلي، مهما كان الحد الأقصى المحفوظ.
                // لا يوجد سقف ثابت هنا؛ حماية القيم تتم عند حفظ الإعدادات وقراءتها.
                $waitSeconds = max(1, $availableTs - time());
                legacyWaWorkerLog('outbox_waiting_for_due', ['outbox_id' => $outboxId, 'seconds' => $waitSeconds]);
                sleep($waitSeconds);
                $pdo->beginTransaction();
                $rowStmt = $pdo->prepare("SELECT * FROM legacy_wa_outbox WHERE id=? FOR UPDATE");
                $rowStmt->execute([$outboxId]);
                $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$row
                    || !in_array((string)$row['status'], ['pending', 'failed'], true)
                    || (int)$row['attempt_count'] >= 5) {
                    $pdo->rollBack();
                    legacyWaWorkerLog('outbox_skipped_after_wait', ['outbox_id' => $outboxId]);
                    continue;
                }
                $availableTs = strtotime((string)$row['available_at']);
                if ($availableTs !== false && $availableTs > time()) {
                    $pdo->rollBack();
                    legacyWaWorkerLog('outbox_skipped_rescheduled', ['outbox_id' => $outboxId]);
                    continue;
                }
            }
            $newAttempt = (int)$row['attempt_count'] + 1;
            $pdo->prepare("UPDATE legacy_wa_outbox
                SET status='processing', attempt_count=?, last_error=NULL, updated_at=NOW()
                WHERE id=?")->execute([$newAttempt, $outboxId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['processed']++;
            $result['failed']++;
            $message = legacyWaSubstr($e->getMessage(), 0, 1000);
            try {
                $pdo->prepare("UPDATE legacy_wa_outbox
                    SET status='failed', last_error=?, available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE), updated_at=NOW()
                    WHERE id=? AND status='processing'")->execute([$message, $outboxId]);
                $pdo->prepare("INSERT INTO legacy_wa_attempts (outbox_id,ok,error_message)
                    VALUES (?,0,?)")->execute([$outboxId, $message]);
            } catch (Throwable $ignored) {}
            legacyWaWorkerLog('outbox_claim_failed', ['outbox_id' => $outboxId, 'error' => $message]);
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) {}
            continue;
        }

        try {
            $number = (string)($row['number'] ?? '');
            $message = (string)($row['message'] ?? '');
            // number وmessage مأخوذان من نفس صف outbox؛ لا يوجد رقم وجهة ثابت.
            $providerResult = legacyWaProviderSend($pdo, $number, $message);
            $result['processed']++;

            $attemptStmt = $pdo->prepare("INSERT INTO legacy_wa_attempts
                (outbox_id,ok,http_status,error_message,response_excerpt)
                VALUES (?,?,?,?,?)");
            $attemptStmt->execute([
                $outboxId,
                $providerResult['ok'] ? 1 : 0,
                (int)($providerResult['http_status'] ?? 0),
                $providerResult['error'] !== '' ? $providerResult['error'] : null,
                $providerResult['response_excerpt'] ?? null,
            ]);

            if ($providerResult['ok']) {
                $pdo->prepare("UPDATE legacy_wa_outbox
                    SET status='sent', provider_message_id=?, sent_at=NOW(), updated_at=NOW()
                    WHERE id=?")->execute([
                        $providerResult['provider_id'] !== '' ? $providerResult['provider_id'] : null,
                        $outboxId,
                    ]);
                $pdo->prepare("UPDATE legacy_wa_inbound i
                    INNER JOIN legacy_wa_outbox o ON o.inbound_id=i.id
                    SET i.status='sent', i.processed_at=NOW()
                    WHERE o.id=?")->execute([$outboxId]);
                $result['sent']++;
                legacyWaWorkerLog('outbox_sent', [
                    'outbox_id' => $outboxId,
                    'attempt' => $newAttempt,
                    'http_status' => (int)($providerResult['http_status'] ?? 0),
                    'destination_present' => $number !== '',
                ]);
            } else {
                $error = legacyWaSubstr((string)($providerResult['error'] ?? 'provider_rejected'), 0, 1000);
                $nextAvailable = date('Y-m-d H:i:s', time() + 300);
                $pdo->prepare("UPDATE legacy_wa_outbox
                    SET status='failed', last_error=?, available_at=?, updated_at=NOW()
                    WHERE id=?")->execute([$error, $nextAvailable, $outboxId]);
                $pdo->prepare("UPDATE legacy_wa_inbound i
                    INNER JOIN legacy_wa_outbox o ON o.inbound_id=i.id
                    SET i.status='failed', i.error_message=?
                    WHERE o.id=?")->execute([$error, $outboxId]);
                $result['failed']++;
                legacyWaWorkerLog('outbox_failed', [
                    'outbox_id' => $outboxId,
                    'attempt' => $newAttempt,
                    'http_status' => (int)($providerResult['http_status'] ?? 0),
                    'error' => $error,
                ]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['processed']++;
            $result['failed']++;
            $message = legacyWaSubstr($e->getMessage(), 0, 1000);
            try {
                $pdo->prepare("UPDATE legacy_wa_outbox
                    SET status='failed', last_error=?, available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE), updated_at=NOW()
                    WHERE id=? AND status='processing'")->execute([$message, $outboxId]);
                $pdo->prepare("INSERT INTO legacy_wa_attempts (outbox_id,ok,error_message)
                    VALUES (?,0,?)")->execute([$outboxId, $message]);
            } catch (Throwable $ignored) {}
            legacyWaWorkerLog('outbox_exception', ['outbox_id' => $outboxId, 'error' => $message]);
        } finally {
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $ignored) {}
        }
    }

    legacyWaWorkerLog('worker_finish', [
        'processed' => $result['processed'],
        'sent' => $result['sent'],
        'failed' => $result['failed'],
    ]);
    return $result;
}

// التشغيل المباشر مسموح من CLI فقط. صفحة الإدارة تستدعي الدالة بعد تحققها من المشرف وCSRF.
$isDirectScript = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__);
if ($isDirectScript) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("CLI only\n");
    }
    $run = legacyWaRunWorker($pdo, 20);
    echo json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
