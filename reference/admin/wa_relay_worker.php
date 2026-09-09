<?php
/**
 * عامل طلبات سوا — يُشغّل من Cron كل دقيقة.
 * لا ينفذ من المتصفح إلا بتوكن محفوظ في whatsapp_settings باسم relay_worker_token.
 * يعتمد على wa_incoming_messages الذي يملؤه register_webhook.php، ولا ينشئ اتصال واتساب جديداً.
 */
require_once dirname(__DIR__) . '/includes/config.php';

$isCli = (PHP_SAPI === 'cli' || defined('STDIN'));
if (!$isCli) {
    $providedToken = (string)($_GET['token'] ?? '');
    $storedToken = waGetSetting($pdo, 'relay_worker_token');
    if (!$storedToken || !hash_equals($storedToken, $providedToken)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$waWorkerLogFile = dirname(__DIR__) . '/wa-worker-detailed.log';
$waWorkerRunId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
$waWorkerStartedAt = microtime(true);
$waWorkerFinished = false;

function waWorkerLogEvent(string $event, array $context = []): void {
    global $waWorkerLogFile, $waWorkerRunId;

    $safe = [];
    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $safe[(string)$key] = is_string($value)
                ? str_replace(["\r", "\n"], ' ', substr($value, 0, 300))
                : $value;
        }
    }

    $record = [
        'timestamp' => date('c'),
        'run_id' => $waWorkerRunId,
        'pid' => getmypid(),
        'event' => $event,
        'context' => $safe,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = json_encode([
            'timestamp' => date('c'),
            'run_id' => $waWorkerRunId,
            'pid' => getmypid(),
            'event' => 'logger_json_error',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // السجل الداخلي مستقل عن إعادة توجيه Cron؛ لا نكتب payload أو مفاتيح حساسة.
    $written = @file_put_contents($waWorkerLogFile, (string)$line . PHP_EOL, FILE_APPEND | LOCK_EX);

    // يبقى الإخراج ظاهراً في المتصفح وداخل سجل Cron لتسهيل الاختبار.
    if ($written === false) {
        echo sprintf('[%s][run=%s][pid=%s] LOG_FILE_WRITE_FAILED %s%s', date('c'), $waWorkerRunId, getmypid(), basename($waWorkerLogFile), PHP_EOL);
    } else {
        echo (string)$line . PHP_EOL;
    }
    if (defined('STDOUT')) @fflush(STDOUT);
}

waWorkerLogEvent('START');

register_shutdown_function(function (): void {
    global $waWorkerStartedAt, $waWorkerFinished;
    if ($waWorkerFinished) return;
    $durationMs = round((microtime(true) - $waWorkerStartedAt) * 1000, 2);
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (is_array($error) && in_array((int)($error['type'] ?? 0), $fatalTypes, true)) {
        waWorkerLogEvent('FATAL_ERROR', [
            'type' => (int)$error['type'],
            'file' => basename((string)($error['file'] ?? '')),
            'line' => (int)($error['line'] ?? 0),
            'message' => substr(str_replace(["\r", "\n"], ' ', (string)($error['message'] ?? '')), 0, 300),
            'duration_ms' => $durationMs,
        ]);
        return;
    }
    waWorkerLogEvent('END', ['status' => 'early_exit', 'duration_ms' => $durationMs]);
});

function waGetSetting(PDO $pdo, string $key): string {
    try {
        $s = $pdo->prepare("SELECT setting_value FROM whatsapp_settings WHERE setting_key=? ORDER BY id DESC LIMIT 1");
        $s->execute([$key]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? (string)$r['setting_value'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

function waExtractMsgText(array $row): string {
    foreach (['message','message_text','body','text','content','msg','caption'] as $key) {
        if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') return trim($row[$key]);
    }
    return '';
}

function waTruthyFlag($value): bool {
    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

// يمنع العامل من الرد على رسائله الذاتية إذا أعاد المزود أحداث الخروج إلى الـWebhook.
function waIsOwnIncomingMessage(array $row): bool {
    foreach (['fromMe','from_me','isFromMe','is_from_me','from_me_message'] as $key) {
        if (array_key_exists($key, $row) && waTruthyFlag($row[$key])) return true;
    }
    $direction = strtolower(trim((string)($row['direction'] ?? $row['message_direction'] ?? '')));
    if (in_array($direction, ['out','outgoing','sent'], true)) return true;

    $raw = (string)($row['raw_payload'] ?? '');
    if ($raw === '') return false;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return false;
    $stack = [[$decoded, 0]];
    while ($stack) {
        [$node, $depth] = array_pop($stack);
        if (!is_array($node) || $depth > 4) continue;
        foreach ($node as $key => $value) {
            $keyLower = strtolower((string)$key);
            if (in_array($keyLower, ['fromme','from_me','isfromme','is_from_me','from_me_message'], true) && waTruthyFlag($value)) return true;
            if (in_array($keyLower, ['direction','message_direction'], true) && in_array(strtolower(trim((string)$value)), ['out','outgoing','sent'], true)) return true;
            if (is_array($value)) $stack[] = [$value, $depth + 1];
        }
    }
    return false;
}

function waSourceFormatInstruction(string $prefix): string {
    $prefix = trim($prefix) !== '' ? trim($prefix) : 'سوا';
    return "يرجى كتابة رقم البطاقة بشكل صحيح\nاسم البطاقة ثم مسافة ثم رقمها\nمثال:\n{$prefix} 12345678901234";
}

function waTargetResultConfirmation(int $requestId, string $type): string {
    $label = $type === 'reject' ? 'الرفض' : 'الموافقة';
    return "تم إرسال رسالتك الخاصة بـ{$label} إلى مجموعة المصدر.\nرقم الطلب: #{$requestId}";
}

function waResponseMessageId($data): string {
    if (!is_array($data)) return '';
    foreach (['message_id','messageId','id','key_id'] as $key) {
        if (isset($data[$key]) && is_scalar($data[$key]) && (string)$data[$key] !== '') return (string)$data[$key];
    }
    foreach (['data','result','message','key'] as $key) {
        if (isset($data[$key])) {
            $id = waResponseMessageId($data[$key]);
            if ($id !== '') return $id;
        }
    }
    return '';
}

function waRelaySend(string $apiKey, string $sender, string $number, string $message): array {
    $ch = curl_init('https://sender.hetacloud.top/send-message');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_key' => $apiKey,
            'sender' => $sender,
            'number' => $number,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Njaz-Sawa-Worker/1.0',
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    $ok = $curlError === '' && $httpCode >= 200 && $httpCode < 300 && is_array($data) && !empty($data['status']);
    $error = $curlError;
    if (!$error && is_array($data) && !$ok) $error = (string)($data['msg'] ?? $data['message'] ?? $data['error'] ?? 'رفض مزود واتساب الإرسال');
    if (!$error && !$ok) $error = 'رد غير صالح من مزود واتساب';
    waWorkerLogEvent('relay_send_result', ['http' => $httpCode, 'ok' => $ok, 'has_message_id' => waResponseMessageId($data) !== '']);
    return ['ok' => $ok, 'message_id' => waResponseMessageId($data), 'error' => $error, 'http' => $httpCode, 'raw' => $data];
}

function waEnsureActivationQueue(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_activation_queue` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `source_message_id` VARCHAR(255) NOT NULL,
        `target_group_id` VARCHAR(120) NOT NULL,
        `message_base` VARCHAR(80) NOT NULL,
        `scheduled_at` DATETIME NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `attempt_count` INT NOT NULL DEFAULT 0,
        `last_error` VARCHAR(1000) DEFAULT NULL,
        `sent_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_activation_source_message` (`source_message_id`),
        KEY `idx_activation_due` (`status`,`scheduled_at`),
        KEY `idx_activation_target_status` (`target_group_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function waActivationStyleSend(string $apiKey, string $sender, string $number, string $message): array {
    // مطابق حرفياً لمسار رسالة واحدة في لوحة الإدارة: endpoint/payload/timeout/SSL/status.
    if ($apiKey === '' || $sender === '' || $number === '' || $message === '') {
        return ['ok' => false, 'message_id' => '', 'error' => 'missing_send_configuration'];
    }
    $ch = curl_init('https://sender.hetacloud.top/send-message');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'api_key' => $apiKey,
            'sender' => $sender,
            'number' => $number,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result = json_decode((string)$body, true);
    $ok = (!$curlError && is_array($result) && isset($result['status']) && $result['status']);
    $error = $curlError ?: ($result['msg'] ?? $result['message'] ?? $result['error'] ?? $result['reason'] ?? '');
    if (!$error && !$ok && $body) $error = 'فشل الإرسال — ' . substr(strip_tags((string)$body), 0, 120);
    if (!$error && !$ok) $error = 'لا يوجد رد من الخادم';
    waWorkerLogEvent('activation_send_result', ['ok' => $ok, 'has_message_id' => waResponseMessageId($result) !== '']);
    return ['ok' => $ok, 'message_id' => waResponseMessageId($result), 'error' => (string)$error];
}

function waProcessActivationQueue(PDO $pdo, string $apiKey, string $sender, array &$log): int {
    $rows = $pdo->query("SELECT * FROM whatsapp_activation_queue
        WHERE status='pending' AND scheduled_at <= NOW()
        ORDER BY scheduled_at ASC, id ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $processed = 0;

    foreach ($rows as $job) {
        $jobId = (int)($job['id'] ?? 0);
        $targetGroup = trim((string)($job['target_group_id'] ?? ''));
        if ($jobId <= 0 || $targetGroup === '') continue;

        $lockName = 'njaz_wa_activation_' . substr(hash('sha256', $targetGroup), 0, 16);
        $lockAcquired = false;
        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 2)');
            $lockStmt->execute([$lockName]);
            $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
            if (!$lockAcquired) continue;

            // الحجز الذري يمنع عاملين متزامنين من إرسال نفس المهمة.
            $claim = $pdo->prepare("UPDATE whatsapp_activation_queue
                SET status='sending', attempt_count=attempt_count+1, updated_at=NOW()
                WHERE id=? AND status='pending' AND scheduled_at <= NOW()");
            $claim->execute([$jobId]);
            if ($claim->rowCount() !== 1) continue;

            if (waGetSetting($pdo, 'activation_enabled') !== '1') {
                $pdo->prepare("UPDATE whatsapp_activation_queue SET status='cancelled',last_error=?,updated_at=NOW() WHERE id=?")
                    ->execute(['تم إلغاء المهمة لأن وضع التنشيط متوقف', $jobId]);
                $log[] = "[activation] أُلغيت المهمة #{$jobId} لأن الوضع متوقف.";
                $processed++;
                continue;
            }
            if (!str_ends_with($targetGroup, '@g.us')) {
                $error = 'معرّف مجموعة التنشيط غير صالح';
                $pdo->prepare("UPDATE whatsapp_activation_queue SET status='failed',last_error=?,updated_at=NOW() WHERE id=?")
                    ->execute([$error, $jobId]);
                $log[] = "[activation] فشلت المهمة #{$jobId}: {$error}.";
                $processed++;
                continue;
            }

            $messageBase = trim((string)($job['message_base'] ?? '')) ?: 'تنشيط';
            try {
                $nonce = date('YmdHis') . '-' . bin2hex(random_bytes(4));
            } catch (Throwable $e) {
                $nonce = date('YmdHis') . '-' . mt_rand(100000, 999999);
            }
            $activationText = $messageBase . ' ' . $nonce;
            $result = waActivationStyleSend($apiKey, $sender, $targetGroup, $activationText);
            $shortError = mb_substr((string)($result['error'] ?? ''), 0, 1000);

            try {
                $pdo->prepare('INSERT INTO whatsapp_logs (phone,message,status,error_msg) VALUES(?,?,?,?)')
                    ->execute([$targetGroup, '[activation]', $result['ok'] ? 'sent' : 'failed', $result['ok'] ? null : $shortError]);
            } catch (Throwable $e) {}

            if ($result['ok']) {
                $pdo->prepare("UPDATE whatsapp_activation_queue SET status='sent',sent_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=?")
                    ->execute([$jobId]);
                $log[] = "[activation] أُرسلت الإشارة المؤجلة للمهمة #{$jobId}.";
            } else {
                $pdo->prepare("UPDATE whatsapp_activation_queue SET status='failed',last_error=?,updated_at=NOW() WHERE id=?")
                    ->execute([$shortError ?: 'فشل إرسال إشارة التنشيط', $jobId]);
                $log[] = "[activation] فشل إرسال المهمة #{$jobId}: " . ($shortError ?: 'خطأ غير محدد') . '.';
            }
            $processed++;
        } catch (Throwable $e) {
            try {
                $pdo->prepare("UPDATE whatsapp_activation_queue SET status='failed',last_error=?,updated_at=NOW() WHERE id=? AND status='sending'")
                    ->execute([mb_substr($e->getMessage(), 0, 1000), $jobId]);
            } catch (Throwable $ignored) {}
            $log[] = "[activation] تعذر معالجة المهمة #{$jobId}.";
            $processed++;
        } finally {
            if ($lockAcquired) {
                try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}
            }
        }
    }
    return $processed;
}

function waRunActivationHeartbeat(PDO $pdo, string $apiKey, string $sender, array &$log, int $seconds = 55): void {
    if (waGetSetting($pdo, 'activation_enabled') !== '1' || $apiKey === '' || $sender === '') return;

    // يبقى هذا التشغيل المؤقت أقل من دورة Cron، ويمنع تشغيل نسختين متداخلتين.
    $leaseName = 'njaz_wa_activation_worker_lease';
    $lease = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lease->execute([$leaseName]);
    if ((int)$lease->fetchColumn() !== 1) return;

    try {
        $until = microtime(true) + max(5, min(55, $seconds));
        do {
            waProcessActivationQueue($pdo, $apiKey, $sender, $log);
            if (microtime(true) < $until) usleep(700000);
        } while (microtime(true) < $until);
    } finally {
        try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$leaseName]); } catch (Throwable $e) {}
    }
}

function waParseLengths(array $rule): array {
    $raw = (string)($rule['digit_lengths'] ?? '');
    if ($raw === '') $raw = (string)($rule['digit_length'] ?? '14');
    $out = [];
    foreach (preg_split('/[^0-9]+/', $raw) as $part) {
        $n = (int)$part;
        if ($n >= 4 && $n <= 32) $out[$n] = $n;
    }
    return array_values($out ?: [14]);
}

function waExtractSawaCode(string $text, string $prefix, array $lengths): string {
    $prefix = trim($prefix);
    if ($prefix === '') return '';
    $lengthPattern = implode('|', array_map(static fn($n) => '[0-9]{' . (int)$n . '}', $lengths));
    $pattern = '/(?<!\S)' . preg_quote($prefix, '/') . '(?:\s|:|-)+(' . $lengthPattern . ')(?![0-9])/u';
    return preg_match($pattern, $text, $match) ? (string)$match[1] : '';
}

// يستخرج أي تسلسل رقمي بعد البادئة، حتى نتمكن من إبلاغ المصدر بالبطاقة الناقصة
// بدلاً من إسقاط الرسالة بصمت. لا يُستخدم هذا الرقم الخام في السجل الداخلي.
function waExtractSawaCandidateDigits(string $text, string $prefix): string {
    $prefix = trim($prefix);
    if ($prefix === '') return '';
    $pattern = '/(?<!\S)' . preg_quote($prefix, '/') . '(?:\s|:|-)+([0-9]{1,64})(?![0-9])/u';
    return preg_match($pattern, $text, $match) ? (string)$match[1] : '';
}

function waMinimumSawaLength(array $lengths): int {
    $valid = array_values(array_filter(array_map('intval', $lengths), static fn($n) => $n > 0));
    if (!$valid) return 14;
    // القاعدة الافتراضية تفرض 14 رقماً، بينما القواعد المخصصة الأقصر تبقى متوافقة مع إعدادها.
    return min(14, min($valid));
}

function waReminderIntervalMinutes($value): int {
    $interval = (int)$value;
    return in_array($interval, [5, 10, 20], true) ? $interval : 10;
}

function waRemainingSeconds(int $deadline, ?int $now = null): int {
    $now = $now ?? time();
    return max(0, $deadline - $now);
}

function waRemainingTimeLabel(int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $restSeconds = $seconds % 60;
    $parts = [];
    if ($hours > 0) $parts[] = $hours . ' ' . ($hours === 1 ? 'ساعة' : 'ساعات');
    if ($minutes > 0) $parts[] = $minutes . ' ' . ($minutes === 1 ? 'دقيقة' : 'دقائق');
    if ($restSeconds > 0 || !$parts) $parts[] = $restSeconds . ' ' . ($restSeconds === 1 ? 'ثانية' : 'ثوانٍ');
    return implode(' و', $parts);
}

function waShouldSendReminder(string $status, int $forwardedAt, int $deadline, ?int $lastReminderAt, int $intervalMinutes, ?int $now = null): bool {
    $now = $now ?? time();
    if ($status !== 'waiting_reply' || $forwardedAt <= 0 || $deadline <= $forwardedAt || $now >= $deadline) return false;
    $intervalSeconds = waReminderIntervalMinutes($intervalMinutes) * 60;
    $base = ($lastReminderAt !== null && $lastReminderAt > 0) ? $lastReminderAt : $forwardedAt;
    return $now >= ($base + $intervalSeconds);
}

function waFormatTemplate(string $template, array $vars): string {
    $template = trim($template);
    if ($template === '') return '';
    return strtr($template, [
        '{id}' => (string)($vars['id'] ?? ''),
        '{code}' => (string)($vars['code'] ?? ''),
        '{amount}' => (string)($vars['amount'] ?? ''),
        '{status}' => (string)($vars['status'] ?? ''),
        '{executor_name}' => (string)($vars['executor_name'] ?? ''),
        '{executor_number}' => (string)($vars['executor_number'] ?? ''),
        '{source_group}' => (string)($vars['source_group'] ?? ''),
        '{target_group}' => (string)($vars['target_group'] ?? ''),
        '{prefix}' => (string)($vars['prefix'] ?? ''),
        '{minimum_length}' => (string)($vars['minimum_length'] ?? ''),
        '{reply_keyword}' => (string)($vars['reply_keyword'] ?? ''),
        '{remaining_time}' => (string)($vars['remaining_time'] ?? ''),
        '{remaining_minutes}' => (string)($vars['remaining_minutes'] ?? ''),
        '{deadline}' => (string)($vars['deadline'] ?? ''),
        '{reminder_count}' => (string)($vars['reminder_count'] ?? ''),
    ]);
}

function waParseReplyAmount(string $text, string $keyword): ?float {
    $keyword = trim($keyword);
    if ($keyword === '') return null;
    $pattern = '/(?<!\S)' . preg_quote($keyword, '/') . '(?:\s|:|-)+(?:#\s*[0-9]{1,12}(?:\s|:|-)+)?([0-9]+(?:[.,][0-9]+)?)(?![0-9])/u';
    if (!preg_match($pattern, $text, $match)) return null;
    return (float)str_replace(',', '.', $match[1]);
}

function waIsRejectReply(string $text): bool {
    // الصيغة الرسمية تبدأ بكلمة رفض؛ لا نعتبر جملة مثل «عدم رفض» رداً صالحاً.
    return (bool)preg_match('/^\s*رفض(?:$|[\s:()\-])/u', trim($text));
}

// الرد العكسي يجب أن يحدد الطلب صراحةً، ولا نعتمد على ترتيب الطلبات المنتظرة.
function waParseReplyRequestId(string $text): ?int {
    if (!preg_match('/(?:^|[\s:()\-])#\s*([0-9]{1,12})(?![0-9])/u', $text, $match)) {
        return null;
    }
    $id = (int)$match[1];
    return $id > 0 ? $id : null;
}

function waClaimReply(PDO $pdo, string $replyClaimId, int $requestId): bool {
    try {
        $claim = $pdo->prepare("INSERT INTO whatsapp_relay_reply_claims (reply_message_id,request_id) VALUES(?,?)");
        $claim->execute([$replyClaimId, $requestId]);
        return $claim->rowCount() === 1;
    } catch (Throwable $e) {
        $claimError = strtolower($e->getMessage());
        $errorInfo = $e instanceof PDOException && is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
        if (strpos($claimError, 'duplicate') !== false || (int)($errorInfo[1] ?? 0) === 1062) return false;
        throw $e;
    }
}

// يضمن أن رسالة المستلم تحتوي رقم الطلب وصيغتي الرد المطلوبة حتى مع وجود قالب مخصص قديم.
function waEnsureRequestIdInstruction(string $text, int $requestId, string $replyKeyword, string $instructionTemplate = ''): string {
    $text = trim($text);
    $replyKeyword = trim($replyKeyword) !== '' ? trim($replyKeyword) : 'موافق';
    $instructionTemplate = waFormatTemplate($instructionTemplate, [
        'id' => $requestId,
        'reply_keyword' => $replyKeyword,
    ]);
    $idPattern = '/(?:^|[\s:()\-])#\s*' . preg_quote((string)$requestId, '/') . '(?![0-9])/u';
    if (!preg_match($idPattern, $text)) {
        $text = "رقم الطلب: #{$requestId}\n" . $text;
    }
    $instructionPattern = '/' . preg_quote($replyKeyword, '/') . '\\s+#\\s*' . preg_quote((string)$requestId, '/') . '\\s+[0-9]+(?:[.,][0-9]+)?/u';
    if (!preg_match($instructionPattern, $text)) {
        $instructionText = $instructionTemplate !== ''
            ? $instructionTemplate
            : "للرد بعد التنفيذ أرسل بإحدى الصيغتين:\n{$replyKeyword} #{$requestId} المبلغ\nرفض #{$requestId} (والسبب اختياري)";
        $text .= "\n\n" . $instructionText;
    } elseif ($instructionTemplate === '' && !preg_match('/رفض\\s+#\\s*' . preg_quote((string)$requestId, '/') . '/u', $text)) {
        $text .= "\nرفض #{$requestId} (والسبب اختياري)";
    }
    return trim($text);
}

function waQuotedMessageId(array $row): string {
    $raw = (string)($row['raw_payload'] ?? '');
    if ($raw === '') return '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) return '';
    $keys = ['quotedMessageId','quoted_message_id','quotedId','stanzaId'];
    $stack = [$payload];
    while ($stack) {
        $node = array_pop($stack);
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true) && is_scalar($value) && (string)$value !== '') return (string)$value;
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

// بعض استجابات HetaCloud لا تعيد معرّف الرسالة الصادرة، لكن payload الرد
// قد يحتوي نص الرسالة المقتبسة. نستخدمه فقط إذا كان يطابق الرقم أو رقم الطلب.
function waQuotedText(array $row): string {
    $raw = (string)($row['raw_payload'] ?? '');
    if ($raw === '') return '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) return '';
    $keys = ['quotedMessage','quoted_message','quotedMsg','quoted_message_content','message','message_text','body','text','content','caption'];
    $stack = [$payload];
    while ($stack) {
        $node = array_pop($stack);
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true) && is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

function waEnsureReplyClaims(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_relay_reply_claims (
        reply_message_id VARCHAR(255) NOT NULL PRIMARY KEY,
        request_id BIGINT NOT NULL,
        claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_reply_claim_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // ترحيل الردود القديمة مرة واحدة؛ الرد المكرر القديم يُحتفظ بأول حجز فقط.
    $pdo->exec("INSERT IGNORE INTO whatsapp_relay_reply_claims (reply_message_id, request_id, claimed_at)
                SELECT reply_message_id, MIN(id), MIN(COALESCE(reply_received_at, created_at))
                FROM whatsapp_relay_logs
                WHERE reply_message_id IS NOT NULL AND reply_message_id <> ''
                GROUP BY reply_message_id");
}

function waEnsureRelayReminderStorage(PDO $pdo): bool {
    $ready = true;
    foreach ([
        'last_reminder_at' => 'DATETIME NULL',
        'reminder_count' => 'INT NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        try {
            $check = $pdo->prepare("SHOW COLUMNS FROM whatsapp_relay_logs LIKE ?");
            $check->execute([$column]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec("ALTER TABLE whatsapp_relay_logs ADD COLUMN `{$column}` {$definition}");
            }
        } catch (Throwable $e) {
            $ready = false;
            waWorkerLogEvent('reminder_storage_column_error', ['column' => $column, 'error_class' => get_class($e)]);
        }
    }
    return $ready;
}

function waSendReminderIfDue(PDO $pdo, string $apiKey, string $sender, string $targetGroup, array $rule, array $request, int $replyTimeoutMinutes, int $now): array {
    $requestId = (int)($request['id'] ?? 0);
    $ruleId = (int)($rule['id'] ?? 0);
    if ($requestId <= 0 || $ruleId <= 0 || !waTruthyFlag($rule['reminder_enabled'] ?? 0)) {
        return ['sent' => false, 'reason' => 'disabled'];
    }

    $lockName = 'njaz_wa_reminder_' . substr(hash('sha256', $ruleId . ':' . $requestId), 0, 24);
    $lockAcquired = false;
    try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) return ['sent' => false, 'reason' => 'lock_not_acquired'];

        $freshStmt = $pdo->prepare('SELECT status,forwarded_at,last_reminder_at,reminder_count,extracted_code FROM whatsapp_relay_logs WHERE id=? AND rule_id=? LIMIT 1');
        $freshStmt->execute([$requestId, $ruleId]);
        $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fresh || (string)$fresh['status'] !== 'waiting_reply') return ['sent' => false, 'reason' => 'not_waiting'];

        $forwardedAt = strtotime((string)$fresh['forwarded_at']);
        if ($forwardedAt <= 0) return ['sent' => false, 'reason' => 'missing_forwarded_at'];
        $deadline = $forwardedAt + max(1, $replyTimeoutMinutes) * 60;
        $lastReminderAt = !empty($fresh['last_reminder_at']) ? strtotime((string)$fresh['last_reminder_at']) : null;
        if (!waShouldSendReminder('waiting_reply', $forwardedAt, $deadline, $lastReminderAt ?: null, waReminderIntervalMinutes($rule['reminder_interval_min'] ?? 10), $now)) {
            return ['sent' => false, 'reason' => 'not_due'];
        }

        $reminderCount = (int)($fresh['reminder_count'] ?? 0) + 1;
        $remainingSeconds = waRemainingSeconds($deadline, $now);
        $remainingMinutes = (int)ceil($remainingSeconds / 60);
        $template = trim((string)($rule['reminder_template'] ?? ''));
        if ($template === '') $template = "تذكير طلب سوا\nرقم الطلب: #{id}\nرقم البطاقة: {code}\nهل شحنت البطاقة أم لا؟\nالمتبقي من مهلة الرد: {remaining_time}\nللتأكيد: {reply_keyword} #{id} المبلغ\nللرفض: رفض #{id}";
        $text = waFormatTemplate($template, [
            'id' => $requestId,
            'code' => (string)($fresh['extracted_code'] ?? $request['extracted_code'] ?? ''),
            'status' => 'قيد التنفيذ',
            'reply_keyword' => (string)($rule['reply_keyword'] ?? 'موافق'),
            'remaining_time' => waRemainingTimeLabel($remainingSeconds),
            'remaining_minutes' => $remainingMinutes,
            'deadline' => date('Y-m-d H:i:s', $deadline),
            'reminder_count' => $reminderCount,
        ]);
        $sent = waRelaySend($apiKey, $sender, $targetGroup, $text);
        if ($sent['ok']) {
            $update = $pdo->prepare("UPDATE whatsapp_relay_logs SET last_reminder_at=NOW(),reminder_count=COALESCE(reminder_count,0)+1 WHERE id=? AND rule_id=? AND status='waiting_reply'");
            $update->execute([$requestId, $ruleId]);
            waWorkerLogEvent('reminder_sent', ['rule_id' => $ruleId, 'request_id' => $requestId, 'reminder_count' => $reminderCount, 'remaining_minutes' => $remainingMinutes]);
            return ['sent' => true, 'ok' => true, 'count' => $reminderCount, 'remaining_minutes' => $remainingMinutes];
        }
        waWorkerLogEvent('reminder_send_failed', ['rule_id' => $ruleId, 'request_id' => $requestId]);
        return ['sent' => false, 'ok' => false, 'reason' => 'send_failed'];
    } catch (Throwable $e) {
        waWorkerLogEvent('reminder_error', ['rule_id' => $ruleId, 'request_id' => $requestId, 'error_class' => get_class($e)]);
        return ['sent' => false, 'ok' => false, 'reason' => 'exception'];
    } finally {
        if ($lockAcquired) {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $e) {
                waWorkerLogEvent('reminder_lock_release_error', ['rule_id' => $ruleId, 'request_id' => $requestId]);
            }
        }
    }
}

function waStatusLabel(string $status): string {
    return [
        'dispatching' => 'جارٍ الإرسال',
        'waiting_reply' => 'قيد التنفيذ',
        'completed' => 'تم التنفيذ',
        'expired' => 'منتهي',
        'error' => 'خطأ',
    ][$status] ?? $status;
}

$apiKey = waGetSetting($pdo, 'heta_api_key');
$sender = waGetSetting($pdo, 'heta_sender');
$log = [];
waWorkerLogEvent('config_loaded', ['api_key_configured' => $apiKey !== '', 'sender_configured' => $sender !== '']);

// التنشيط له مسار مستقل عن قواعد سوا، لذلك يُعالج أولاً حتى عند عدم وجود قواعد فعالة.
try {
    waEnsureActivationQueue($pdo);
    // فحص فوري عند بداية التشغيل، ثم نبض قصير قرب نهاية العامل لتقليل انتظار دورة Cron.
    waProcessActivationQueue($pdo, $apiKey, $sender, $log);
    waWorkerLogEvent('activation_initial_scan_done', ['log_entries' => count($log)]);
} catch (Throwable $e) {
    waWorkerLogEvent('activation_initial_scan_error', ['error_class' => get_class($e)]);
    $log[] = '[activation] تعذر تهيئة/معالجة الطابور.';
}

if ($apiKey === '' || $sender === '') {
    if ($log) echo implode("\n", $log) . "\n";
    waWorkerLogEvent('STOP', ['reason' => 'missing_send_configuration']);
    echo "لا يوجد اتصال واتساب مضبوط.\n";
    exit;
}

try {
    $rules = $pdo->query("SELECT * FROM whatsapp_relay_rules WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    waWorkerLogEvent('rules_loaded', ['count' => count($rules)]);
} catch (Throwable $e) {
    if ($log) echo implode("\n", $log) . "\n";
    waWorkerLogEvent('STOP', ['reason' => 'rules_query_error', 'error_class' => get_class($e)]);
    echo "جداول طلبات سوا غير جاهزة. افتح تبويب طلبات سوا في إدارة واتساب مرة واحدة.\n";
    exit;
}
if (!$rules) {
    waWorkerLogEvent('STOP', ['reason' => 'no_active_rules']);
    waRunActivationHeartbeat($pdo, $apiKey, $sender, $log);
    if ($log) echo implode("\n", $log) . "\n";
    echo "لا توجد قواعد طلبات سوا مفعّلة.\n";
    exit;
}

try {
    waEnsureReplyClaims($pdo);
} catch (Throwable $e) {
    // لا نتابع المعالجة بدون القيد الدائم، حتى لا يُعاد استخدام رد واحد لطلبات متعددة.
    waRunActivationHeartbeat($pdo, $apiKey, $sender, $log);
    echo "تعذر تهيئة قيد منع تكرار ردود سوا. لم تُعالج الطلبات.\n";
    exit;
}

$reminderStorageReady = false;
try {
    $reminderStorageReady = waEnsureRelayReminderStorage($pdo);
    if (!$reminderStorageReady) {
        waWorkerLogEvent('reminder_disabled', ['reason' => 'storage_not_ready']);
    }
} catch (Throwable $e) {
    // فشل ترحيل التذكير لا يعطل معالجة طلبات سوا أو مسارات الموافقة والرفض.
    waWorkerLogEvent('reminder_storage_error', ['error_class' => get_class($e)]);
}

$legacyProtectedMaxRequestId = 4;
foreach ($rules as $rule) {
    $ruleId = (int)$rule['id'];
    waWorkerLogEvent('rule_start', ['rule_id' => $ruleId, 'last_processed_id' => (int)$rule['last_processed_id']]);
    $sourceGroup = trim((string)$rule['source_group_id']);
    $targetGroup = trim((string)$rule['target_group_id']);
    $lastId = (int)$rule['last_processed_id'];
    $prefix = trim((string)($rule['trigger_prefix'] ?? 'سوا'));
    $lengths = waParseLengths($rule);
    $newMessages = [];

    try {
        $stmt = $pdo->prepare("SELECT * FROM wa_incoming_messages WHERE group_id=? AND is_group=1 AND id>? ORDER BY id ASC LIMIT 100");
        $stmt->execute([$sourceGroup, $lastId]);
        $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        waWorkerLogEvent('source_scan', ['rule_id' => $ruleId, 'new_messages' => count($newMessages)]);
    } catch (Throwable $e) {
        $log[] = "[{$rule['name']}] خطأ قراءة المجموعة المصدر: {$e->getMessage()}";
        continue;
    }

    $maxId = $lastId;
    foreach ($newMessages as $msg) {
        $sourceMsgId = (int)($msg['id'] ?? 0);
        $maxId = max($maxId, $sourceMsgId);
        $text = waExtractMsgText($msg);
        if ($sourceMsgId <= 0) continue;
        // نكشف الرقم القصير مستقلاً عن أطوال القاعدة، حتى لا تختفي رسالة المصدر بصمت.
        $candidateDigits = waExtractSawaCandidateDigits($text, $prefix);
        $minimumLength = waMinimumSawaLength($lengths);
        if ($candidateDigits !== '' && strlen($candidateDigits) < $minimumLength) {
            $shortNotice = waFormatTemplate((string)($rule['source_short_template'] ?? ''), [
                'code' => $candidateDigits,
                'minimum_length' => $minimumLength,
            ]);
            if ($shortNotice === '') $shortNotice = "رقم البطاقة ناقص؛ أرسل {$minimumLength} رقماً على الأقل.";
            $notice = waRelaySend($apiKey, $sender, $sourceGroup, $shortNotice);
            waWorkerLogEvent('source_short_code_rejected', [
                'rule_id' => $ruleId,
                'source_message_row' => $sourceMsgId,
                'digits_length' => strlen($candidateDigits),
                'minimum_length' => $minimumLength,
                'notice_sent' => $notice['ok'],
            ]);
            $log[] = "[{$rule['name']}] تم تنبيه المصدر: رقم البطاقة ناقص (أقل من {$minimumLength} رقماً).";
            usleep(250000);
            continue;
        }

        $code = waExtractSawaCode($text, $prefix, $lengths);
        if ($code === '') {
            $formatNotice = waFormatTemplate((string)($rule['source_format_template'] ?? ''), [
                'prefix' => $prefix,
            ]);
            if ($formatNotice === '') $formatNotice = waSourceFormatInstruction($prefix);
            $notice = waRelaySend($apiKey, $sender, $sourceGroup, $formatNotice);
            waWorkerLogEvent('source_format_rejected', [
                'rule_id' => $ruleId,
                'source_message_row' => $sourceMsgId,
                'notice_sent' => $notice['ok'],
            ]);
            $log[] = "[{$rule['name']}] تم تنبيه المصدر إلى صيغة البطاقة الصحيحة.";
            usleep(250000);
            continue;
        }

        // التكرار يعني أن الرقم حُجز/أُرسل سابقاً لنفس القاعدة، وليس مجرد رسالة فاشلة لم تُرسل.
        // لا نحدّث السجل القديم ولا نعيد لمس الطلبات التاريخية؛ نقرأه فقط لمنع إعادة الإرسال.
        try {
            $duplicateStmt = $pdo->prepare("SELECT id,status FROM whatsapp_relay_logs
                WHERE rule_id=? AND extracted_code=? AND id>?
                  AND (status IN ('waiting_reply','completed')
                       OR (status IN ('dispatching','error') AND forwarded_message_id IS NOT NULL AND forwarded_message_id <> ''))
                ORDER BY id ASC LIMIT 1");
            $duplicateStmt->execute([$ruleId, $code, $legacyProtectedMaxRequestId]);
            $previous = $duplicateStmt->fetch(PDO::FETCH_ASSOC);
            if ($previous) {
                $previousStatus = (string)$previous['status'];
                if ($previousStatus === 'waiting_reply') {
                    $duplicateNotice = waFormatTemplate((string)($rule['source_pending_template'] ?? ''), [
                        'id' => (int)$previous['id'],
                        'code' => $code,
                        'status' => 'قيد التنفيذ',
                    ]);
                    if ($duplicateNotice === '') $duplicateNotice = "رقم هذه البطاقة {$code} ما زال معلقاً، نرجو عدم التقديم مرة أخرى.";
                } else {
                    $duplicateNotice = waFormatTemplate((string)($rule['source_duplicate_template'] ?? ''), [
                        'id' => (int)$previous['id'],
                        'code' => $code,
                        'status' => 'مكرر',
                    ]);
                    if ($duplicateNotice === '') $duplicateNotice = "هذه البطاقة {$code} مكررة، وقد أُرسلت إلى المستلم سابقاً. لن تتم إعادة إرسالها.";
                }
                $notice = waRelaySend($apiKey, $sender, $sourceGroup, $duplicateNotice);
                waWorkerLogEvent('source_duplicate_skipped', [
                    'rule_id' => $ruleId,
                    'source_message_row' => $sourceMsgId,
                    'previous_request_id' => (int)$previous['id'],
                    'previous_status' => (string)$previous['status'],
                    'notice_sent' => $notice['ok'],
                ]);
                $log[] = $previousStatus === 'waiting_reply'
                    ? "[{$rule['name']}] البطاقة {$code} ما زالت معلقة؛ تم تنبيه المصدر ولم تُرسل مجدداً."
                    : "[{$rule['name']}] بطاقة مكررة؛ تم تنبيه المصدر ولم تُرسل للمستلم مرة أخرى.";
                usleep(250000);
                continue;
            }
        } catch (Throwable $e) {
            // لا نخاطر بإرسال بطاقة يمكن أن تكون مكررة إذا تعذر فحص السجل.
            waWorkerLogEvent('source_duplicate_check_error', [
                'rule_id' => $ruleId,
                'source_message_row' => $sourceMsgId,
                'error_class' => get_class($e),
            ]);
            $log[] = "[{$rule['name']}] تعذر فحص تكرار البطاقة؛ لم تُرسل للمستلم.";
            continue;
        }

        // حجز الرسالة قبل الاتصال الخارجي؛ القيد الفريد يمنع تشغيل عاملين متزامنين من إنشاء طلبين.
        try {
            $insert = $pdo->prepare("INSERT INTO whatsapp_relay_logs
                (rule_id,source_msg_id,source_message_id,extracted_code,source_text,status,created_at)
                VALUES(?,?,?,?,?,'dispatching',NOW())");
            $insert->execute([$ruleId, $sourceMsgId, (string)($msg['message_id'] ?? ''), $code, $text]);
            $requestId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            $errorInfo = $e instanceof PDOException && is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
            $isDuplicate = strpos(strtolower($e->getMessage()), 'duplicate') !== false || ((int)($errorInfo[1] ?? 0) === 1062);
            if ($isDuplicate) {
                $duplicateNotice = waFormatTemplate((string)($rule['source_duplicate_template'] ?? ''), [
                    'code' => $code,
                    'status' => 'مكرر',
                ]);
                if ($duplicateNotice === '') $duplicateNotice = "هذه البطاقة {$code} مكررة، وقد أُرسلت إلى المستلم سابقاً. لن تتم إعادة إرسالها.";
                $notice = waRelaySend($apiKey, $sender, $sourceGroup, $duplicateNotice);
                waWorkerLogEvent('source_duplicate_skipped', [
                    'rule_id' => $ruleId,
                    'source_message_row' => $sourceMsgId,
                    'notice_sent' => $notice['ok'],
                    'detected_by' => 'unique_constraint',
                ]);
                continue;
            }
            $log[] = "[{$rule['name']}] تعذر حجز الرسالة #{$sourceMsgId}: {$e->getMessage()}";
            continue;
        }

        $requestText = waFormatTemplate((string)($rule['request_template'] ?? ''), [
            'id' => $requestId, 'code' => $code, 'status' => 'قيد التنفيذ',
            'source_group' => $sourceGroup, 'target_group' => $targetGroup,
        ]);
        if ($requestText === '') $requestText = "طلب سوا جديد\nرقم الطلب: #{$requestId}\nالرقم: {$code}\nالحالة: قيد التنفيذ";
        $requestText = waEnsureRequestIdInstruction(
            $requestText,
            $requestId,
            (string)($rule['reply_keyword'] ?? 'موافق'),
            (string)($rule['reply_instruction_template'] ?? '')
        );
        waWorkerLogEvent('request_message_prepared', [
            'rule_id' => $ruleId,
            'request_id' => $requestId,
            'has_request_id' => true,
            'has_reply_instruction' => true,
        ]);
        $sent = waRelaySend($apiKey, $sender, $targetGroup, $requestText);
        if ($sent['ok']) {
            $pdo->prepare("UPDATE whatsapp_relay_logs SET status='waiting_reply',forwarded_message_id=?,forwarded_at=NOW() WHERE id=?")
                ->execute([$sent['message_id'] ?: null, $requestId]);
            $sourceConfirmation = waFormatTemplate((string)($rule['source_forward_template'] ?? ''), [
                'id' => $requestId,
                'code' => $code,
                'status' => 'قيد التنفيذ',
                'target_group' => $targetGroup,
            ]);
            if ($sourceConfirmation === '') $sourceConfirmation = "تم إرسال رقم بطاقتك {$code} إلى المزود.\nرقم الطلب: #{$requestId}\nيرجى انتظار رد المزود.";
            $sourceConfirmationResult = waRelaySend($apiKey, $sender, $sourceGroup, $sourceConfirmation);
            waWorkerLogEvent('source_forward_confirmation', [
                'rule_id' => $ruleId,
                'request_id' => $requestId,
                'confirmation_sent' => $sourceConfirmationResult['ok'],
            ]);
            $log[] = "[{$rule['name']}] أُرسل طلب سوا #{$requestId} للرقم {$code}." . ($sourceConfirmationResult['ok'] ? '' : ' تعذر إرسال تأكيد المصدر.');
        } else {
            $pdo->prepare("UPDATE whatsapp_relay_logs SET status='error',error_message=? WHERE id=?")
                ->execute([mb_substr($sent['error'], 0, 1000), $requestId]);
            $log[] = "[{$rule['name']}] فشل إرسال طلب سوا #{$requestId}: {$sent['error']}";
        }
        usleep(250000);
    }
    if ($maxId > $lastId) {
        $pdo->prepare("UPDATE whatsapp_relay_rules SET last_processed_id=? WHERE id=?")->execute([$maxId, $ruleId]);
    }

    // الطلبات التاريخية #1–#4 متروكة كما هي ولا تدخل أي دورة معالجة جديدة.
    $waitingStmt = $pdo->prepare("SELECT * FROM whatsapp_relay_logs
        WHERE rule_id=? AND status='waiting_reply' AND id>?
        ORDER BY forwarded_at ASC, id ASC");
    $waitingStmt->execute([$ruleId, $legacyProtectedMaxRequestId]);
    $waiting = $waitingStmt->fetchAll(PDO::FETCH_ASSOC);
    waWorkerLogEvent('waiting_scan', ['rule_id' => $ruleId, 'waiting_requests' => count($waiting)]);
    if (!$waiting) continue;

    // الرد العكسي لا يُعتمد إلا إذا احتوى على رقم الطلب؛ الاقتباس اختياري للتحقق الإضافي.
    // لا نستخدم fallback حسب ترتيب الطلبات، حتى لا يُربط الرد بالطلب الخطأ.
    $nowTs = time();
    $activeWaitingCount = 0;
    foreach ($waiting as $pending) {
        $pendingDeadline = strtotime((string)$pending['forwarded_at']) + max(1, (int)($rule['reply_timeout_min'] ?? 30)) * 60;
        if ($pendingDeadline > $nowTs) $activeWaitingCount++;
    }

    $earliest = min(array_map(static fn($r) => (string)$r['forwarded_at'], $waiting));
    $replyStmt = $pdo->prepare("SELECT * FROM wa_incoming_messages WHERE group_id=? AND is_group=1 AND received_at>=? ORDER BY received_at ASC,id ASC LIMIT 200");
    $replyStmt->execute([$targetGroup, $earliest]);
    $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
    waWorkerLogEvent('reply_scan', ['rule_id' => $ruleId, 'candidate_replies' => count($replies), 'active_waiting' => $activeWaitingCount]);
    $usedReplyIds = [];

    foreach ($waiting as $request) {
        $requestId = (int)$request['id'];
        $deadline = strtotime((string)$request['forwarded_at']) + max(1, (int)($rule['reply_timeout_min'] ?? 30)) * 60;
        $matched = false;
        foreach ($replies as $reply) {
            $replyId = (int)($reply['id'] ?? 0);
            if (!$replyId || isset($usedReplyIds[$replyId])) continue;
            $replyTs = strtotime((string)$reply['received_at']);
            if ($replyTs < strtotime((string)$request['forwarded_at']) || $replyTs > $deadline) continue;
            $replyText = waExtractMsgText($reply);
            $replyRequestId = waParseReplyRequestId($replyText);
            $quotedId = waQuotedMessageId($reply);
            $quotedText = waQuotedText($reply);

            // رقم الطلب هو مفتاح المطابقة الأساسي، سواء وُجد اقتباس أم لا.
            if ($replyRequestId === null) {
                waWorkerLogEvent('reply_request_id_missing', [
                    'rule_id' => $ruleId,
                    'expected_request_id' => $requestId,
                    'incoming_id' => $replyId,
                    'has_quote' => $quotedId !== '' || $quotedText !== '',
                ]);
                continue;
            }
            if ($replyRequestId !== $requestId) {
                waWorkerLogEvent('reply_request_id_mismatch', [
                    'rule_id' => $ruleId,
                    'expected_request_id' => $requestId,
                    'reply_request_id' => $replyRequestId,
                    'incoming_id' => $replyId,
                ]);
                continue;
            }

            // إذا توفر اقتباس، نتحقق منه كقيد إضافي، لكن لا نعتمد عليه وحده.
            if ($quotedId !== '') {
                if ($request['forwarded_message_id'] !== '' && $quotedId !== (string)$request['forwarded_message_id']) {
                    waWorkerLogEvent('reply_quote_mismatch', ['rule_id' => $ruleId, 'request_id' => $requestId, 'incoming_id' => $replyId]);
                    continue;
                }
                if ($request['forwarded_message_id'] === '') {
                    $hasCode = $quotedText !== '' && strpos($quotedText, (string)$request['extracted_code']) !== false;
                    $hasRequest = $quotedText !== '' && preg_match('/#\\s*' . preg_quote((string)$requestId, '/') . '(?![0-9])/u', $quotedText);
                    if (!$hasCode && !$hasRequest) continue;
                }
            } elseif ($quotedText !== '') {
                $hasCode = strpos($quotedText, (string)$request['extracted_code']) !== false;
                $hasRequest = preg_match('/#\\s*' . preg_quote((string)$requestId, '/') . '(?![0-9])/u', $quotedText);
                if (!$hasCode && !$hasRequest) continue;
            }

            $isRejected = waIsRejectReply($replyText);
            $replyKeyword = (string)($rule['reply_keyword'] ?? 'موافق');
            $amount = $isRejected ? null : waParseReplyAmount($replyText, $replyKeyword);
            waWorkerLogEvent('reply_parse', [
                'rule_id' => $ruleId,
                'request_id' => $requestId,
                'incoming_id' => $replyId,
                'has_request_id' => true,
                'has_quote' => $quotedId !== '' || $quotedText !== '',
                'reply_type' => $isRejected ? 'reject' : 'accept',
                'amount_parsed' => $amount !== null,
            ]);

            // الرفض لا يحتاج مبلغاً؛ السبب الاختياري لا يُعاد نشره ولا يُحفظ في سجل التشغيل.
            if ($isRejected) {
                $replyClaimId = trim((string)($reply['message_id'] ?? '')) ?: ('incoming:' . $replyId);
                try {
                    if (!waClaimReply($pdo, $replyClaimId, $requestId)) continue;
                } catch (Throwable $e) {
                    $log[] = "[{$rule['name']}] تعذر حجز رد الرفض للطلب #{$requestId}.";
                    waWorkerLogEvent('reply_claim_error', ['rule_id' => $ruleId, 'request_id' => $requestId, 'incoming_id' => $replyId, 'error_class' => get_class($e)]);
                    continue;
                }

                $resultText = waFormatTemplate((string)($rule['reject_result_template'] ?? ''), [
                    'id' => $requestId,
                    'code' => $request['extracted_code'],
                    'status' => 'مرفوض',
                ]);
                if ($resultText === '') $resultText = "تم رفض طلب سوا #{$requestId}: رقم البطاقة مستخدم مسبقاً أو غير صحيح.";
                waWorkerLogEvent('reply_rejected_by_target', [
                    'rule_id' => $ruleId,
                    'request_id' => $requestId,
                    'incoming_id' => $replyId,
                ]);
                $back = waRelaySend($apiKey, $sender, $sourceGroup, $resultText);
                waWorkerLogEvent('reverse_send_complete', ['rule_id' => $ruleId, 'request_id' => $requestId, 'reply_type' => 'reject', 'ok' => $back['ok']]);
                $targetConfirmation = ['ok' => false, 'message_id' => '', 'error' => 'reverse_send_failed'];
                if ($back['ok']) {
                    $targetConfirmationText = waFormatTemplate((string)($rule['target_reject_confirmation_template'] ?? ''), [
                        'id' => $requestId,
                        'code' => $request['extracted_code'],
                        'status' => 'مرفوض',
                    ]);
                    if ($targetConfirmationText === '') $targetConfirmationText = waTargetResultConfirmation($requestId, 'reject');
                    $targetConfirmation = waRelaySend($apiKey, $sender, $targetGroup, $targetConfirmationText);
                    waWorkerLogEvent('target_result_confirmation', [
                        'rule_id' => $ruleId,
                        'request_id' => $requestId,
                        'reply_type' => 'reject',
                        'confirmation_sent' => $targetConfirmation['ok'],
                    ]);
                }
                $error = $back['ok'] ? 'رفض المستلم: البطاقة مستخدمة مسبقاً أو غير صحيحة.' : ('رفض المستلم، لكن تعذر إرسال النتيجة: ' . $back['error']);
                $pdo->prepare("UPDATE whatsapp_relay_logs SET status='error',reply_message_id=?,result_message_id=?,reply_text=?,reply_received_at=?,completed_at=NOW(),error_message=? WHERE id=?")
                    ->execute([(string)($reply['message_id'] ?? '') ?: null, $back['message_id'] ?: null, $replyText, $reply['received_at'], $error, $requestId]);
                $usedReplyIds[$replyId] = true;
                $log[] = "[{$rule['name']}] رُفض طلب سوا #{$requestId} وأُبلغ المصدر." . ($back['ok'] ? '' : ' تعذر إرسال الإبلاغ.') . ($back['ok'] && !$targetConfirmation['ok'] ? ' تعذر إرسال تأكيد المستقبل.' : '');
                $matched = true;
                break;
            }

            if ($amount === null) {
                waWorkerLogEvent('reply_amount_missing', ['rule_id' => $ruleId, 'request_id' => $requestId, 'incoming_id' => $replyId]);
                continue;
            }
            $min = (float)($rule['reply_min_amount'] ?? 0);
            $max = $rule['reply_max_amount'] === null || $rule['reply_max_amount'] === '' ? null : (float)$rule['reply_max_amount'];
            if ($amount < $min || ($max !== null && $amount > $max)) {
                waWorkerLogEvent('reply_amount_mismatch', [
                    'rule_id' => $ruleId,
                    'request_id' => $requestId,
                    'incoming_id' => $replyId,
                    'within_bounds' => false,
                ]);
                continue;
            }

            $executorName = trim((string)($reply['from_name'] ?? ''));
            $executorNumber = trim((string)($reply['from_number'] ?? $reply['participant'] ?? ''));
            $resultText = waFormatTemplate((string)($rule['result_template'] ?? ''), [
                'id' => $requestId, 'code' => $request['extracted_code'], 'amount' => rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.'),
                'status' => 'تم التنفيذ', 'executor_name' => $executorName, 'executor_number' => $executorNumber,
                'source_group' => $sourceGroup, 'target_group' => $targetGroup,
            ]);
            if ($resultText === '') $resultText = "تم تنفيذ طلب سوا ✅\nرقم الطلب: #{$requestId}\nالرقم: {$request['extracted_code']}\nالمبلغ: {$amount}\nالمنفذ: " . ($executorName ?: $executorNumber) . "\nالحالة: تم التنفيذ";

            // حجز ذري دائم لمعرّف الرد قبل إرسال النتيجة؛ يمنع إعادة استخدامه في Cron لاحق.
            $replyClaimId = trim((string)($reply['message_id'] ?? '')) ?: ('incoming:' . $replyId);
            try {
                if (!waClaimReply($pdo, $replyClaimId, $requestId)) continue;
            } catch (Throwable $e) {
                $log[] = "[{$rule['name']}] تعذر حجز الرد للطلب #{$requestId}.";
                waWorkerLogEvent('reply_claim_error', ['rule_id' => $ruleId, 'request_id' => $requestId, 'incoming_id' => $replyId, 'error_class' => get_class($e)]);
                continue;
            }

            waWorkerLogEvent('reply_claimed', ['rule_id' => $ruleId, 'request_id' => $requestId, 'incoming_id' => $replyId]);
            $back = waRelaySend($apiKey, $sender, $sourceGroup, $resultText);
            waWorkerLogEvent('reverse_send_complete', ['rule_id' => $ruleId, 'request_id' => $requestId, 'reply_type' => 'accept', 'ok' => $back['ok']]);
            $targetConfirmation = ['ok' => false, 'message_id' => '', 'error' => 'reverse_send_failed'];
            if ($back['ok']) {
                $targetConfirmationText = waFormatTemplate((string)($rule['target_accept_confirmation_template'] ?? ''), [
                    'id' => $requestId,
                    'code' => $request['extracted_code'],
                    'amount' => rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.'),
                    'status' => 'تم التنفيذ',
                ]);
                if ($targetConfirmationText === '') $targetConfirmationText = waTargetResultConfirmation($requestId, 'accept');
                $targetConfirmation = waRelaySend($apiKey, $sender, $targetGroup, $targetConfirmationText);
                waWorkerLogEvent('target_result_confirmation', [
                    'rule_id' => $ruleId,
                    'request_id' => $requestId,
                    'reply_type' => 'accept',
                    'confirmation_sent' => $targetConfirmation['ok'],
                ]);
            }
            $status = 'completed';
            $error = $back['ok'] ? null : ('تم التنفيذ لكن تعذر إرسال النتيجة: ' . $back['error']);
            $pdo->prepare("UPDATE whatsapp_relay_logs SET status=?,reply_message_id=?,result_message_id=?,reply_text=?,reply_amount=?,executor_name=?,executor_number=?,reply_received_at=?,completed_at=NOW(),error_message=? WHERE id=?")
                ->execute([$status, (string)($reply['message_id'] ?? '') ?: null, $back['message_id'] ?: null, $replyText, $amount, $executorName ?: null, $executorNumber ?: null, $reply['received_at'], $error, $requestId]);
            $usedReplyIds[$replyId] = true;
            $log[] = "[{$rule['name']}] تم تنفيذ طلب سوا #{$requestId} بمبلغ {$amount}." . ($error ? " {$error}" : '') . ($back['ok'] && !$targetConfirmation['ok'] ? ' تعذر إرسال تأكيد المستقبل.' : '');
            $matched = true;
            break;
        }
        if (!$matched) {
            $cycleNow = time();
            if ($cycleNow >= $deadline) {
                // لا يوجد تذكير عند انتهاء المهلة؛ تتحول الحالة إلى منتهية فوراً.
                $pdo->prepare("UPDATE whatsapp_relay_logs SET status='expired',completed_at=NOW(),error_message=? WHERE id=? AND status='waiting_reply'")
                    ->execute(['انتهت مهلة الرد دون رد مطابق', $requestId]);
                $log[] = "[{$rule['name']}] انتهت مهلة طلب سوا #{$requestId}.";
            } elseif ($reminderStorageReady) {
                // يأتي التذكير بعد اكتمال فحص الردود، حتى لا يُرسل لطلب وصل رده في نفس الدورة.
                $reminder = waSendReminderIfDue(
                    $pdo,
                    $apiKey,
                    $sender,
                    $targetGroup,
                    $rule,
                    $request,
                    max(1, (int)($rule['reply_timeout_min'] ?? 30)),
                    $cycleNow
                );
                if (!empty($reminder['sent'])) {
                    $log[] = "[{$rule['name']}] أُرسل تذكير الطلب #{$requestId}؛ المتبقي تقريباً {$reminder['remaining_minutes']} دقيقة.";
                }
            }
        }
    }
}

// بعد دورة سوا، يستمر العامل زمناً محدوداً في مراقبة Queue؛ هذا لا يطيل Webhook.
waWorkerLogEvent('activation_heartbeat_start');
waRunActivationHeartbeat($pdo, $apiKey, $sender, $log);
waWorkerLogEvent('activation_heartbeat_end', ['log_entries' => count($log)]);
$waWorkerFinished = true;
waWorkerLogEvent('END', ['status' => 'ok', 'duration_ms' => round((microtime(true) - $waWorkerStartedAt) * 1000, 2)]);

echo $log ? implode("\n", $log) . "\n" : "لا يوجد جديد.\n";
