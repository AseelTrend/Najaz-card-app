<?php
/** Temporary KYC diagnostic. Remove after verification. */
require_once __DIR__ . '/_common.php';
header('Content-Type: application/json; charset=utf-8');

$userId = mobileAuthorizeRequest($pdo, true);

try {
    $st = $pdo->prepare("SELECT id, status, created_at, updated_at, reviewed_at FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 10");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'user_id' => (int)$userId,
        'count' => count($rows),
        'kyc_requests' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'تعذر فحص طلبات التوثيق',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
