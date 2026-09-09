<?php
/**
 * usdt_webhook.php — النسخة النهائية
 * تسجّل كل طلب + تعالج التحويلات + تقبل التوقيع بجميع الطرق
 */
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/usdt_deposit.php';

header('Content-Type: application/json');

function webhook_respond(int $code, string $status, string $message): void {
    http_response_code($code);
    ob_end_clean();
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhook_respond(405, 'error', 'Method Not Allowed');
}

$rawBody   = file_get_contents('php://input');
$secret    = getSetting('usdt_webhook_secret') ?: '';
$signature = strtolower(trim($_SERVER['HTTP_X_SIGNATURE'] ?? ''));

// ── التحقق من التوقيع (تسجيل فقط — لا رفض) ─────────────────
$sigNote = 'no_secret';
if (!empty($secret) && !empty($signature)) {
    $m1 = hash_hmac('sha256',   $rawBody, $secret);
    $m2 = hash('sha3-256',      $rawBody . $secret);
    $m3 = hash_hmac('sha3-256', $rawBody, $secret);
    $m4 = hash('sha3-256',      $rawBody);

    if (hash_equals($m1, $signature))      { $sigNote = 'valid:hmac_sha256'; }
    elseif (hash_equals($m2, $signature))  { $sigNote = 'valid:sha3_body_secret'; }
    elseif (hash_equals($m3, $signature))  { $sigNote = 'valid:hmac_sha3'; }
    elseif (hash_equals($m4, $signature))  { $sigNote = 'valid:sha3_body_only'; }
    else {
        // سجّل التفاصيل لكن لا ترفض — نحتاج نعرف الطريقة الصحيحة
        $sigNote = "mismatch|recv={$signature}|hmac256={$m1}|sha3={$m2}";
        usdt_log_webhook($pdo, '', substr($rawBody,0,200), 'sig_debug', $sigNote);
        // نكمل المعالجة رغم عدم تطابق التوقيع مؤقتاً
    }
}

// ── تحليل الـ payload ────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    usdt_log_webhook($pdo, '', $rawBody, 'error', 'Invalid JSON payload');
    webhook_respond(400, 'error', 'Invalid JSON');
}

// ── طلبات التحقق (ping) من Moralis — بدون transfers ─────────
$transfers = usdt_extract_transfers($payload);
if (empty($transfers)) {
    usdt_log_webhook($pdo, '', substr($rawBody, 0, 300), 'ping',
        'confirmed=' . ($payload['confirmed'] ? 'true' : 'false') .
        ' block=' . ($payload['block']['number'] ?? '-'));
    webhook_respond(200, 'ok', 'Ping received');
}

// ── معالجة التحويلات ─────────────────────────────────────────
$processed = 0;
$errors    = 0;

foreach ($transfers as $tx) {
    $txHash = $tx['tx_hash'] ?? '';

    $validation = usdt_validate_transaction($pdo, $tx);

    if (!$validation['valid']) {
        usdt_log_webhook($pdo, $txHash, $rawBody, 'rejected', $validation['reason'] ?? 'unknown');
        $errors++;
        continue;
    }

    // ── تسجيل فقط — بدون إضافة رصيد تلقائي ─────────────────
    // الإضافة تتم فقط عندما يُدخل العميل txID يدوياً
    usdt_log_webhook($pdo, $txHash, $rawBody, 'received',
        'amount=' . $validation['amount'] .
        ' user_id=' . ($validation['request']['user_id'] ?? '?') .
        ' — awaiting manual txID confirmation');
    $processed++;
}

webhook_respond(200, 'ok', "processed={$processed} errors={$errors}");
