<?php
require_once __DIR__ . '/_common.php';

$serviceId = (int)($_GET['service_id'] ?? 0);
if ($serviceId <= 0) jsonOutMobile(true, '', ['has_coupons' => false]);

try {
    $categoryStmt = $pdo->prepare('SELECT category_id FROM services WHERE id=? AND status=1 AND deleted_at IS NULL LIMIT 1');
    $categoryStmt->execute([$serviceId]);
    $categoryId = (int)($categoryStmt->fetchColumn() ?: 0);
    if ($categoryId <= 0) jsonOutMobile(true, '', ['has_coupons' => false]);

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM coupons
         WHERE status=1
         AND (starts_at IS NULL OR starts_at <= ?)
         AND (expires_at IS NULL OR expires_at >= ?)
         AND (usage_limit IS NULL OR used_count < usage_limit)
         AND (
             applies_to='all'
             OR (applies_to='service' AND service_id=?)
             OR (applies_to='category' AND category_id=?)
         )"
    );
    $stmt->execute([$now, $now, $serviceId, $categoryId]);
    jsonOutMobile(true, '', ['has_coupons' => (int)$stmt->fetchColumn() > 0]);
} catch (Throwable $e) {
    jsonOutMobile(true, '', ['has_coupons' => false]);
}
