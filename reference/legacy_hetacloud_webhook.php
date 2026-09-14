<?php
/**
 * Webhook مستقل لجهاز HetaCloud الخاص بنظام نجاز القديم.
 * لا يعتمد على Webhook واتساب العام ولا يرسل ردوداً تلقائية.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/legacy_whatsapp_bridge.php';

header('Content-Type: application/json; charset=utf-8');

function legacyHetaExcerpt(array $data, int $limit = 30000): string
{
    $json = json_encode(legacyWaSanitizeValue($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return legacyWaSubstr($json ?: '{}', 0, $limit);
}

function legacyHetaLogInsert(PDO $pdo, string $event, string $device, string $signature, string $raw, array $data): ?int
{
    try {
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_webhook_log
            (event_type,device,signature_status,payload_hash,payload_excerpt,remote_ip,processing_status)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            legacyWaSubstr($event ?: 'unknown', 0, 100),
            legacyWaSubstr($device, 0, 255) ?: null,
            legacyWaSubstr($signature, 0, 30),
            hash('sha256', $raw),
            legacyHetaExcerpt($data),
            legacyWaSubstr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'received',
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function legacyHetaLogFinish(PDO $pdo, ?int $id, string $status, string $message, int $httpStatus): void
{
    if (!$id) return;
    try {
        $stmt = $pdo->prepare("UPDATE legacy_wa_webhook_log
            SET processing_status=?,processing_message=?,http_status=?,processed_at=NOW() WHERE id=?");
        $stmt->execute([
            legacyWaSubstr($status, 0, 30),
            legacyWaSubstr($message, 0, 500) ?: null,
            $httpStatus,
            $id,
        ]);
    } catch (Throwable $e) {}
}

function legacyHetaTrace(PDO $pdo, ?int $logId, string $messageId, string $stage, array $details = []): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_message_trace
            (webhook_log_id,message_id,stage,details) VALUES (?,?,?,?)");
        $stmt->execute([
            $logId ?: null,
            legacyWaSubstr($messageId, 0, 255) ?: null,
            legacyWaSubstr($stage, 0, 80),
            legacyWaSubstr(json_encode(legacyWaSanitizeValue($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', 0, 6000),
        ]);
    } catch (Throwable $e) {}
}

function legacyHetaEventLooksLike(array $data, array $terms): bool
{
    $name = strtolower(legacyWaHetaEventName($data));
    foreach ($terms as $term) {
        if (strpos($name, strtolower($term)) !== false) return true;
    }
    return false;
}

function legacyHetaFirstPresent(array $data, array $keys): string
{
    return legacyWaHetaNestedScalar($data, $keys);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? $_GET['challenge'] ?? $_GET['verify_token'] ?? '';
    if (is_scalar($challenge) && trim((string)$challenge) !== '') {
        echo (string)$challenge;
        exit;
    }
    legacyWaJsonResponse([
        'status' => 'ok',
        'service' => 'njaz-legacy-hetacloud-webhook',
        'time' => date('c'),
    ]);
}

if ($method !== 'POST') {
    legacyWaJsonResponse(['status' => 'error', 'reason' => 'method_not_allowed'], 405);
}

try {
    if (!legacyWaSchemaReady($pdo)) {
        legacyWaJsonResponse([
            'status' => 'error',
            'reason' => 'legacy_schema_not_ready',
            'message' => 'افتح لوحة نظام واتساب نجاز القديم مرة واحدة لإنشاء الجداول.',
        ], 503);
    }

    if (legacyWaGetSetting($pdo, 'heta_webhook_enabled') !== '1') {
        legacyWaJsonResponse(['status' => 'error', 'reason' => 'webhook_disabled'], 503);
    }

    $request = legacyWaReadRequest();
    $raw = (string)$request['raw'];
    $data = is_array($request['data']) ? $request['data'] : [];
    if ($raw === '' && $data) $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    $headers = legacyWaHetaHeaders();
    $secret = legacyWaGetSetting($pdo, 'heta_webhook_secret');
    $signature = legacyWaHetaSignatureStatus($raw, $secret, $headers);
    $device = legacyHetaFirstPresent($data, ['device','device_id','deviceId','sender','instance','instance_id']);
    $event = legacyWaHetaEventName($data);
    $logId = legacyHetaLogInsert($pdo, $event, $device, $signature, $raw, $data);

    if ($secret !== '' && $signature !== 'valid') {
        legacyHetaLogFinish($pdo, $logId, 'rejected', 'signature_' . $signature, 401);
        legacyWaJsonResponse(['status' => 'error', 'reason' => 'invalid_signature'], 401);
    }

    if (!$data) {
        legacyHetaLogFinish($pdo, $logId, 'failed', 'invalid_json', 400);
        legacyWaJsonResponse(['status' => 'error', 'reason' => 'invalid_json'], 400);
    }

    $expectedSender = trim(legacyWaGetSetting($pdo, 'heta_webhook_expected_sender'));
    $reportedSender = legacyHetaFirstPresent($data, ['sender','device','device_id','deviceId','from_device','fromDevice']);
    if ($expectedSender !== '' && $reportedSender !== '' && !hash_equals($expectedSender, $reportedSender)) {
        legacyHetaLogFinish($pdo, $logId, 'rejected', 'unexpected_sender', 403);
        legacyWaJsonResponse(['status' => 'error', 'reason' => 'unexpected_sender'], 403);
    }

    legacyHetaTrace($pdo, $logId, '', 'received', ['event' => $event]);
    $eventTime = legacyWaHetaDateTime($data);
    $groupId = legacyWaHetaGroupId($data);
    $groupName = legacyHetaFirstPresent($data, ['group_name','groupName','subject','subjectName','chat_name','chatName']);
    $participant = legacyHetaFirstPresent($data, ['participant','participant_id','participantId','author','author_id','authorId']);
    $from = legacyHetaFirstPresent($data, ['from','from_number','fromNumber','phone','number','contact','contact_number']);
    $messageId = legacyHetaFirstPresent($data, ['id','message_id','messageId','key_id','keyId','wamid']);
    $message = legacyHetaFirstPresent($data, ['text','body','message_text','messageText','content','caption']);
    if ($message === '') {
        $message = legacyHetaFirstPresent($data, ['message']);
    }
    $messageType = legacyHetaFirstPresent($data, ['message_type','messageType','type','kind']);
    if ($messageType === '') $messageType = 'text';
    $mediaUrl = legacyHetaFirstPresent($data, ['media_url','mediaUrl','url','download_url','downloadUrl']);
    $mediaMime = legacyHetaFirstPresent($data, ['mime','mime_type','mimeType','media_mime']);
    $isGroup = $groupId !== '' || $participant !== '' || substr($from, -5) === '@g.us';
    if ($isGroup && $groupId === '' && substr($from, -5) === '@g.us') $groupId = $from;
    if ($isGroup && $participant !== '') $from = $participant;
    $messageId = $messageId !== '' ? $messageId : 'legacy-' . substr(hash('sha256', $raw), 0, 48);

    $isReceipt = legacyHetaEventLooksLike($data, ['receipt','delivered','delivery','read','ack']) &&
        $message === '' && $from === '';
    $isGroupEvent = legacyHetaEventLooksLike($data, ['group_created','group_updated','group_update','group_join','group_leave','group_event']) && !$message;
    $isStatus = legacyHetaEventLooksLike($data, ['connection','device_status','status','online','offline','connected','disconnected']) && !$message && !$isReceipt;

    if ($isReceipt) {
        $receiptStatus = legacyHetaFirstPresent($data, ['status','receipt_status','receiptStatus','ack']);
        $receiptId = legacyHetaFirstPresent($data, ['receipt_id','receiptId','id','event_id','eventId']);
        $receiptMessageId = legacyHetaFirstPresent($data, ['message_id','messageId','wamid','id']);
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_receipts
            (receipt_id,message_id,device,recipient_number,status_value,receipt_at,raw_payload)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $receiptId ?: null, $receiptMessageId ?: null, $device ?: null, $from ?: null,
            legacyWaSubstr($receiptStatus, 0, 80) ?: null, $eventTime, legacyHetaExcerpt($data, 16000)
        ]);
        legacyHetaLogFinish($pdo, $logId, 'processed', 'receipt', 200);
        legacyWaJsonResponse(['status' => 'ok', 'processed' => 'receipt']);
    }

    if ($isGroupEvent) {
        $action = legacyHetaFirstPresent($data, ['action','event','event_type','eventType','type']);
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_group_events
            (event_id,device,group_id,group_name,participant,action,details,event_at)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $messageId ?: null, $device ?: null, $groupId ?: null, $groupName ?: null,
            $participant ?: null, legacyWaSubstr($action, 0, 80) ?: null, legacyHetaExcerpt($data, 16000), $eventTime
        ]);
        legacyHetaLogFinish($pdo, $logId, 'processed', 'group_event', 200);
        legacyWaJsonResponse(['status' => 'ok', 'processed' => 'group_event']);
    }

    if ($isStatus) {
        $statusValue = legacyHetaFirstPresent($data, ['status','state','connection','value']);
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_device_status
            (device,event_name,status_value,details,event_at) VALUES (?,?,?,?,?)");
        $stmt->execute([
            $device ?: null, legacyWaSubstr($event, 0, 100), legacyWaSubstr($statusValue, 0, 100) ?: null,
            legacyHetaExcerpt($data, 16000), $eventTime
        ]);
        legacyHetaLogFinish($pdo, $logId, 'processed', 'device_status', 200);
        legacyWaJsonResponse(['status' => 'ok', 'processed' => 'device_status']);
    }

    $hasMessageEvent = $message !== '' || $from !== '' || $mediaUrl !== '' || legacyHetaEventLooksLike($data, ['message','messages','incoming']);
    if (!$hasMessageEvent) {
        legacyHetaLogFinish($pdo, $logId, 'ignored', 'unhandled_event', 200);
        legacyWaJsonResponse(['status' => 'ok', 'processed' => 'ignored']);
    }

    $duplicate = $pdo->prepare("SELECT id FROM legacy_wa_incoming_messages WHERE message_id=? LIMIT 1");
    $duplicate->execute([$messageId]);
    if ($duplicate->fetchColumn()) {
        legacyHetaTrace($pdo, $logId, $messageId, 'duplicate_ignored');
        legacyHetaLogFinish($pdo, $logId, 'duplicate', 'message_duplicate', 200);
        legacyWaJsonResponse(['status' => 'ok', 'processed' => 'duplicate_ignored']);
    }

    $stmt = $pdo->prepare("INSERT INTO legacy_wa_incoming_messages
        (message_id,device,from_number,from_name,chat_id,chat_name,participant,message_type,message,media_url,media_mime,is_group,raw_payload,event_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $fromName = legacyHetaFirstPresent($data, ['name','pushName','push_name','contact_name','contactName']);
    $stmt->execute([
        $messageId, $device ?: null, $from ?: null, $fromName ?: null, $groupId ?: null, $groupName ?: null,
        $participant ?: null, legacyWaSubstr($messageType, 0, 50), $message ?: null, $mediaUrl ?: null,
        $mediaMime ?: null, $isGroup ? 1 : 0, legacyHetaExcerpt($data), $eventTime
    ]);
    legacyHetaTrace($pdo, $logId, $messageId, 'message_persisted', [
        'is_group' => $isGroup, 'message_type' => $messageType,
    ]);
    legacyHetaLogFinish($pdo, $logId, 'processed', 'message', 200);
    legacyWaJsonResponse([
        'status' => 'ok',
        'processed' => 'message',
        'duplicate' => false,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        // لا نسجل نص الاستثناء كاملاً للمستخدم أو في الحمولة؛ نكتفي برسالة مختصرة آمنة.
        error_log('legacy_hetacloud_webhook: ' . legacyWaSubstr($e->getMessage(), 0, 300));
    }
    legacyWaJsonResponse(['status' => 'error', 'reason' => 'processing_failed'], 500);
}
