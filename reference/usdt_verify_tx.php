<?php
/**
 * usdt_verify_tx.php — واجهة الويب للتحقق من txID وإضافة الرصيد.
 * POST JSON: { "request_id": 5, "tx_id": "0x..." }
 * منطق التحقق والمحاسبة موجود في includes/usdt_verify_service.php.
 */
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/usdt_verify_service.php';

header('Content-Type: application/json; charset=utf-8');

$fail = static function (string $message): void {
    ob_end_clean();
    echo json_encode(['ok'=>false, 'error'=>$message], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isLoggedIn()) $fail('غير مسجّل دخول');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Method Not Allowed');

$userId = (int)($_SESSION['user_id'] ?? 0);
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$requestId = (int)($body['request_id'] ?? 0);
$txId = strtolower(trim((string)($body['tx_id'] ?? '')));

if ($requestId <= 0) $fail('request_id غير صالح');
if (!usdt_verify_txid_format($txId)) $fail('txID غير صالح — يبدأ بـ 0x ويكون 66 حرفاً');

$result = usdt_verify_deposit_request($pdo, $userId, $requestId, $txId);
if (($result['ok'] ?? false) && !empty($result['credited'])) {
    ob_end_clean();
    echo json_encode(['ok'=>true, 'amount'=>number_format((float)($result['amount'] ?? 0), 2, '.', '')], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = (string)($result['error_code'] ?? 'verification_failed');
$error = usdt_verify_user_error_message($code);
if (!empty($result['error']) && in_array($code, ['amount_mismatch'], true)) $error = (string)$result['error'];
$legacyMap = [
    'transaction_already_used'=>'هذا الـ txID مستخدم سابقاً',
    'REQUEST_ALREADY_PROCESSED'=>'هذا الطلب تمت معالجته سابقاً',
];
$error = $legacyMap[$code] ?? $error;
$fail($error);
