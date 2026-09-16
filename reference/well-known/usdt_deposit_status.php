<?php
/**
 * usdt_deposit_status.php
 * ─────────────────────────────────────────────────────────────
 * AJAX — فحص حالة طلب الإيداع
 * GET ?id=123
 */
ob_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/usdt_deposit.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'error' => 'unauthorized']);
    exit;
}

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'error' => 'invalid_id']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT * FROM usdt_deposit_requests
    WHERE id = ? AND user_id = ?
    LIMIT 1
");
$stmt->execute([$requestId, $userId]);
$request = $stmt->fetch();

if (!$request) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'error' => 'not_found']);
    exit;
}

ob_end_clean();
echo json_encode([
    'status'   => $request['status'],
    'amount'   => $request['credited_amount'] ?? $request['unique_amount'],
    'tx_hash'  => $request['tx_hash'] ?? null,
    'expires'  => $request['expires_at'],
]);
