<?php
require_once dirname(__DIR__) . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$serviceId = (int)($_GET['service_id'] ?? 0);
if (!$serviceId) { echo json_encode(['has_coupons'=>false]); exit; }

try {
    $now = date('Y-m-d H:i:s');
    // هل يوجد كوبون فعّال ينطبق على هذه الخدمة أو قسمها أو كل الخدمات
    $cat = $pdo->query("SELECT category_id FROM services WHERE id=$serviceId")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM coupons
        WHERE status=1
        AND (starts_at IS NULL OR starts_at <= ?)
        AND (expires_at IS NULL OR expires_at >= ?)
        AND (usage_limit IS NULL OR used_count < usage_limit)
        AND (
            applies_to = 'all'
            OR (applies_to = 'service'  AND service_id  = ?)
            OR (applies_to = 'category' AND category_id = ?)
        )
    ");
    $stmt->execute([$now, $now, $serviceId, $cat ?: 0]);
    $count = (int)$stmt->fetchColumn();
    echo json_encode(['has_coupons' => $count > 0]);
} catch(Exception $e) {
    echo json_encode(['has_coupons' => false]);
}
