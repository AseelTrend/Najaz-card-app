<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function jsonOut($ok, $msg, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['ok'=>$ok,'message'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')[0]);

// ================== 1. التفعيل ==================
$enabled = (int)getSetting('sms_topup_enabled');
if (!$enabled) {
    jsonOut(false, 'Service disabled', [], 503);
}

// ================== 2. API KEY ==================
$storedKey = getSetting('sms_api_key');

$incomingKey = $_SERVER['HTTP_X_API_KEY']
             ?? $_SERVER['HTTP_AUTHORIZATION']
             ?? $_GET['api_key']
             ?? '';

$incomingKey = str_replace('Bearer ', '', trim($incomingKey));

if (!$storedKey || !hash_equals($storedKey, $incomingKey)) {
    jsonOut(false, 'Unauthorized', [], 401);
}

// ================== 3. DEVICE ==================
$allowedDevice = getSetting('sms_device_id');

// ================== 4. قراءة الرسالة ==================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST ?: $_GET;

// دعم البنية المتداخلة (payload) مثل تطبيق Sms Forwarder الجديد
// {"event":"sms:received","payload":{"message":"...","sender":"Jaib",...}}
$payload  = $data['payload'] ?? [];
$from     = trim($payload['sender']      ?? $data['from']      ?? $data['sender']   ?? '');
$text     = trim($payload['message']     ?? $data['text']       ?? $data['body']     ?? '');
$deviceId = $data['deviceId']            ?? $data['device_id'] ?? '';
$simSlot  = $payload['simNumber']        ?? $data['sim']        ?? null;

// وقت الرسالة
$receivedAtRaw = $payload['receivedAt']  ?? $data['receivedStamp'] ?? $data['sentStamp'] ?? null;

if ($allowedDevice && $deviceId && $deviceId !== $allowedDevice) {
    jsonOut(false, 'Device not allowed', [], 403);
}

if (!$from || !$text) {
    jsonOut(false, 'Missing data', [], 400);
}

// ================== 5. الوقت ==================
$receivedAt = date('Y-m-d H:i:s'); // افتراضي
if ($receivedAtRaw) {
    // ISO 8601: "2026-04-26T10:23:41.000+03:00"
    $ts = strtotime($receivedAtRaw);
    if ($ts) $receivedAt = date('Y-m-d H:i:s', $ts);
}

// ================== 6. منع التكرار ==================
$dup = $pdo->prepare("
    SELECT id FROM sms_inbox
    WHERE sender=? AND message_text=?
    LIMIT 1
");
$dup->execute([$from, $text]);
if ($dup->fetch()) {
    jsonOut(true, 'Duplicate', []);
}

// ================== 7. تحليل الرسالة ==================
$providers = $pdo->query("SELECT * FROM sms_providers WHERE status=1 ORDER BY sort_order")->fetchAll();

$amount     = null;
$phone      = null;
$providerId = null;
$parseNotes = 'لم يطابق أي مزود — sender: ' . $from;

foreach ($providers as $p) {
    // ── تحديد نوع المطابقة ──
    $senderMatched = false;
    switch ($p['match_type'] ?? 'contains') {
        case 'exact':
            $senderMatched = (strtolower($from) === strtolower($p['sender_match']));
            break;
        case 'regex':
            $senderMatched = (bool)@preg_match($p['sender_match'], $from);
            break;
        default: // contains
            $senderMatched = (stripos($from, $p['sender_match']) !== false);
    }
    if (!$senderMatched) continue;

    // ── تنظيف الـ pattern: إزالة \\ المضاعف إن وُجد ──
    $pattern = trim($p['parse_pattern'] ?? '');
    // إذا كان مخزناً بـ \\ بدل \ نصلحه تلقائياً
    if (strpos($pattern, '\\\\') !== false) {
        $pattern = str_replace('\\\\', '\\', $pattern);
    }

    // ── إذا لا يوجد regex — الرسالة كاملة بدون استخراج ──
    if (empty($pattern)) {
        $providerId = $p['id'];
        $parseNotes = 'بدون regex — الرسالة كاملة';
        break;
    }

    // ── تطبيق الـ regex ──
    $matchResult = @preg_match($pattern, $text, $m);

    if ($matchResult === false || $matchResult === 0) {
        // محاولة ثانية: إزالة delimiter وتطبيق بدونه
        $inner = preg_replace('#^/(.+)/[a-z]*$#', '$1', $pattern);
        $matchResult = @preg_match('/' . $inner . '/u', $text, $m);
        $parseNotes = "تطابق sender '{$p['name']}' لكن regex فشل على النص";
    }

    if ($matchResult) {
        $ag = (int)($p['amount_group'] ?? 1);
        $pg = (int)($p['phone_group']  ?? 2);
        if (isset($m[$ag])) {
            $amount = (float)str_replace([',', ' '], '', $m[$ag]);
        }
        if ($pg > 0 && isset($m[$pg])) {
            $phone = preg_replace('/[^0-9]/', '', $m[$pg]);
            if (strlen($phone) < 6) $phone = null; // رقم غير صالح
        }
        $providerId = $p['id'];
        $parseNotes = "تطابق مع '{$p['name']}' — مبلغ: $amount — رقم: $phone";
    }

    break; // أول مزود مطابق
}

// ================== 8. تحويل العملة ==================
$amountUsd = null;
if ($amount && $providerId) {
    $rate = $pdo->query("SELECT rate_to_usd FROM sms_providers WHERE id=$providerId")->fetchColumn();
    $amountUsd = $amount * (float)$rate;
}

// ================== 9. حفظ ==================
// إذا لا يوجد regex (parse_pattern فارغ) → pending بدون مبلغ
$noPattern = ($providerId && !$amount && str_contains($parseNotes, 'بدون regex'));
$status = ($amount || $noPattern) ? 'pending' : 'rejected';

$pdo->prepare("
    INSERT INTO sms_inbox
    (provider_id,sender,message_text,amount,amount_usd,phone_number,received_at,status,raw_payload,ip_address,parse_notes)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    $providerId,
    $from,
    $text,
    $amount,
    $amountUsd,
    $phone,
    $receivedAt,
    $status,
    $raw,
    $clientIp,
    $parseNotes
]);

$id = $pdo->lastInsertId();

// ================== 10. إشعار واتساب ==================
if ($providerId && $status !== 'rejected') {
    try {
        $provider = null;
        foreach ($providers as $p) {
            if ((int)$p['id'] === (int)$providerId) { $provider = $p; break; }
        }

        if ($provider && !empty($provider['wa_group_id'])) {
            // جلب إعدادات واتساب من جدول whatsapp_settings
            $waStmt = $pdo->query("SELECT setting_key, setting_value FROM whatsapp_settings WHERE setting_key IN ('heta_api_key','heta_sender')");
            $waConf = [];
            foreach ($waStmt->fetchAll() as $r) $waConf[$r['setting_key']] = $r['setting_value'];
            $waApiKey = $waConf['heta_api_key'] ?? '';
            $waSender = $waConf['heta_sender']  ?? '';
            $waGroup  = $provider['wa_group_id'];

            if ($waApiKey && $waSender && $waGroup) {
                $amountLine = $amount
                    ? number_format($amount, 0) . ' ' . ($provider['currency'] ?? '')
                      . ($amountUsd ? ' (~' . number_format($amountUsd, 4) . ' $)' : '')
                    : '—';

                $waMsg = "📩 *رسالة شحن واردة*
"
                       . "━━━━━━━━━━━━━━
"
                       . "🏦 *المزود:* " . $provider['name'] . "
"
                       . "📤 *من:* " . $from . "
"
                       . "💰 *المبلغ:* " . $amountLine . "
"
                       . ($phone ? "📱 *الرقم:* " . $phone . "
" : "")
                       . "📝 " . $text . "
"
                       . "━━━━━━━━━━━━━━
"
                       . "🕐 " . date('Y/m/d H:i:s');

                $ch = curl_init('https://sender.hetacloud.top/send-message');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'api_key' => $waApiKey,
                        'sender'  => $waSender,
                        'number'  => $waGroup,
                        'message' => $waMsg,
                    ]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    } catch (Exception $e) {
        // إشعار واتساب لا يوقف المعالجة
    }
}

// ================== 11. الرد ==================
jsonOut(true, 'Saved', [
    'id'=>$id,
    'amount'=>$amount,
    'phone'=>$phone
]);
?>