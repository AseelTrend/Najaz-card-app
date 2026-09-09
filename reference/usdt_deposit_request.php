<?php
/**
 * usdt_deposit_request.php
 * ─────────────────────────────────────────────────────────────
 * AJAX Endpoint — إنشاء طلب إيداع USDT جديد للمستخدم
 *
 * الطلب:  POST JSON { "amount": 100 }
 * الرد:   JSON { "ok": true, "request": {...} }
 */

ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/usdt_deposit.php';

header('Content-Type: application/json; charset=utf-8');

// ── تطلب تسجيل الدخول ─────────────────────────────────────────────────────
if (!isLoggedIn()) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'غير مسجّل دخول']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ── قراءة وتحقق المدخلات ─────────────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;

if ($amount <= 0 || $amount > 1000000) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => 'مبلغ غير صالح']);
    exit;
}

// ── إلغاء منتهية الصلاحية أولاً ─────────────────────────────────────────
usdt_expire_old_requests($pdo);

// ── هل يوجد طلب نشط بالفعل؟ ──────────────────────────────────────────────
$existing = usdt_get_active_request($pdo, $userId);
if ($existing) {
    ob_end_clean();
    echo json_encode([
        'ok'      => true,
        'request' => _format_request($existing),
        'message' => 'طلب نشط موجود مسبقاً',
    ]);
    exit;
}

// ── إنشاء طلب جديد ────────────────────────────────────────────────────────
$ttl    = (int)(getSetting('usdt_request_ttl') ?: 30);
$result = usdt_create_deposit_request($pdo, $userId, $amount, $ttl);

if (!$result['ok']) {
    ob_end_clean();
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

ob_end_clean();
echo json_encode([
    'ok'      => true,
    'request' => _format_request($result['request']),
]);

// ── تنسيق بيانات الطلب للواجهة ────────────────────────────────────────────
function _format_request(array $req): array {
    return [
        'id'             => (int)$req['id'],
        'unique_amount'  => rtrim(rtrim((string)$req['unique_amount'], '0'), '.'),
        'wallet_address' => $req['wallet_address'],
        'contract'       => USDT_OFFICIAL_CONTRACT,
        'network'        => 'BNB Smart Chain (BEP20)',
        'expires_at'     => $req['expires_at'],
        'status'         => $req['status'],
    ];
}
