<?php
/**
 * نقطة توافق يمن روبوت القديم.
 *
 * الاستخدام المتوقع من إعدادات يمن روبوت:
 *   الرابط الأساسي: https://YOUR-DOMAIN
 *   يمن روبوت يضيف تلقائياً /api/send إلى الرابط الأساسي.
 *   Password: كلمة مرور ربطية ريبلو المحفوظة في إدارة الجسر
 *   Token: replo
 *   Type: tws_ex
 *
 * يدعم الملف أيضاً الرابط التشخيصي القديم مباشرةً للتوافق الخلفي.
 *
 * في وضع capture تحفظ الطلب فقط. وفي وضع send تضيفه إلى outbox ثم تشغّل
 * العامل المعزول فوراً إذا كان auto_send_enabled مفعلاً؛ ويبقى الطابور لإعادة المحاولة.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/legacy_whatsapp_bridge.php';

// العامل المشترك يعالج legacy_wa_outbox فقط. لا يتم تحميله إلا بعد قبول الطلب.
header('Cache-Control: no-store');

$schema = legacyWaEnsureSchema($pdo);
if (!$schema['ok']) {
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'storage_unavailable'], 503);
}

// تنظيف محدود عند وصول Webhook فقط؛ لا يُنفذ عند فتح لوحة الإدارة.
legacyWaCleanupTerminalOutbox($pdo, 100);

$request = legacyWaReadRequest();
$data = $request['data'];
$diagnosticId = legacyWaRecordRequest($pdo, $request);
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'GET') {
    legacyWaFinishRequest($pdo, $diagnosticId, 'health', 'health_check', 200);
    legacyWaJsonResponse([
        'status' => true,
        'success' => true,
        'service' => 'njaz-legacy-whatsapp-bridge',
        'mode' => legacyWaGetSetting($pdo, 'mode'),
    ]);
}

if ($requestMethod !== 'POST') {
    legacyWaFinishRequest($pdo, $diagnosticId, 'rejected', 'method_not_allowed', 405);
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'method_not_allowed'], 405);
}

if (legacyWaGetSetting($pdo, 'enabled') !== '1') {
    legacyWaFinishRequest($pdo, $diagnosticId, 'rejected', 'bridge_disabled', 503);
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'bridge_disabled'], 503);
}

$authorization = legacyWaAuthorized($pdo, $data);
if (!$authorization['ok']) {
    legacyWaFinishRequest($pdo, $diagnosticId, 'rejected', (string)$authorization['reason'], (int)$authorization['status']);
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>$authorization['reason']], $authorization['status']);
}

$extracted = legacyWaExtractRequest($data, $request['raw']);
if ($extracted['number'] === '' || $extracted['message'] === '') {
    legacyWaFinishRequest($pdo, $diagnosticId, 'rejected', 'number_and_message_required', 400);
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'number_and_message_required'], 400);
}

legacyWaFinishRequest($pdo, $diagnosticId, 'accepted', 'authorized_payload', 200);

try {
    $pdo->beginTransaction();
    $insert = $pdo->prepare("INSERT INTO legacy_wa_inbound
        (external_id, request_hash, number, message, method, token_value, type_value, amount, balance, raw_payload, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $mode = legacyWaGetSetting($pdo, 'mode') === 'send' ? 'queued' : 'captured';
    $insert->execute([
        $extracted['external_id'] !== '' ? $extracted['external_id'] : null,
        $extracted['request_hash'],
        $extracted['number'],
        $extracted['message'],
        $extracted['method'] !== '' ? $extracted['method'] : null,
        $extracted['token'] !== '' ? $extracted['token'] : null,
        $extracted['type'] !== '' ? $extracted['type'] : null,
        $extracted['amount'],
        $extracted['balance'],
        legacyWaSanitizedPayload($data),
        $mode,
    ]);
    $inboundId = (int)$pdo->lastInsertId();

    $schedule = ['delay_seconds' => 0, 'available_at' => date('Y-m-d H:i:s')];
    $outboxId = 0;
    if ($mode === 'queued') $schedule = legacyWaDelayedAt($pdo);
    if ($mode === 'queued') {
        try {
            $outbox = $pdo->prepare("INSERT INTO legacy_wa_outbox
                (inbound_id, number, message, status, available_at, delay_seconds)
                VALUES (?, ?, ?, 'pending', ?, ?)");
            $outbox->execute([$inboundId, $extracted['number'], $extracted['message'], $schedule['available_at'], $schedule['delay_seconds']]);
            $outboxId = (int)$pdo->lastInsertId();
        } catch (PDOException $columnError) {
            // توافق مع نسخة قديمة لم تسمح بإضافة العمود الاختياري؛ available_at يكفي للتأخير.
            if (stripos($columnError->getMessage(), 'delay_seconds') === false && stripos($columnError->getMessage(), 'unknown column') === false) throw $columnError;
            $outbox = $pdo->prepare("INSERT INTO legacy_wa_outbox
                (inbound_id, number, message, status, available_at)
                VALUES (?, ?, ?, 'pending', ?)");
            $outbox->execute([$inboundId, $extracted['number'], $extracted['message'], $schedule['available_at']]);
            $outboxId = (int)$pdo->lastInsertId();
        }
    }
    $pdo->commit();

    // نرسل رد القبول سريعاً، ثم نعالج الصف في الخلفية بعد انتهاء الموعد.
    // إذا تعذر تشغيل الخلفية، يبقى الصف محفوظاً ويُعالَج عند تشغيل العامل يدوياً أو عبر Cron اختياري.
    $autoScheduled = false;
    $autoDelay = 0;
    if ($mode === 'queued' && legacyWaGetSetting($pdo, 'auto_send_enabled') === '1') {
        $autoScheduled = true;
        $autoDelay = (int)$schedule['delay_seconds'];
        register_shutdown_function(function () use ($pdo, $autoDelay, $outboxId): void {
            ignore_user_abort(true);
            @set_time_limit(max(180, $autoDelay + 90));
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            if ($autoDelay > 0) sleep($autoDelay);
            require_once __DIR__ . '/../admin/legacy_whatsapp_worker.php';
            // يقتصر التشغيل المؤجل على صف الإشعار الجديد؛ لا يلتقط صفوفاً قديمة.
            legacyWaRunWorker($pdo, 1, false, $outboxId);
        });
    }

    legacyWaJsonResponse([
        'status' => true,
        'success' => true,
        'accepted' => true,
        'queued' => $mode === 'queued',
        'auto_scheduled' => $autoScheduled,
        'scheduled_delay_seconds' => $autoScheduled ? $autoDelay : 0,
        'message_id' => (string)$inboundId,
    ], 200);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorInfo = is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
    if ((int)($errorInfo[1] ?? 0) === 1062 || stripos($e->getMessage(), 'duplicate') !== false) {
        $find = $pdo->prepare("SELECT id,status FROM legacy_wa_inbound WHERE request_hash=? LIMIT 1");
        $find->execute([$extracted['request_hash']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC) ?: [];
        legacyWaJsonResponse([
            'status'=>true,
            'success'=>true,
            'accepted'=>true,
            'duplicate'=>true,
            'queued'=>false,
            'message_id'=>(string)($existing['id'] ?? ''),
        ]);
    }
    legacyWaFinishRequest($pdo, $diagnosticId, 'failed', 'storage_error', 503);
    error_log('[legacy-wa-webhook] database error: ' . legacyWaSubstr($e->getMessage(), 0, 300));
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'storage_error'], 503);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    legacyWaFinishRequest($pdo, $diagnosticId, 'failed', 'processing_error', 503);
    error_log('[legacy-wa-webhook] processing error: ' . legacyWaSubstr($e->getMessage(), 0, 300));
    legacyWaJsonResponse(['status'=>false, 'success'=>false, 'reason'=>'processing_error'], 503);
}
