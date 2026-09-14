<?php
// api/mobile/orders.php — قائمة طلبات المستخدم الحالي
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT o.id, o.service_id, s.name AS service_name, s.image AS service_image,
                               o.quantity, o.unit_price, o.total_price, o.status, o.status_message,
                               o.ref_id, o.created_at
                        FROM orders o
                        JOIN services s ON o.service_id = s.id
                        WHERE o.user_id = ?
                        ORDER BY o.created_at DESC
                        LIMIT $limit OFFSET $offset");
$stmt->execute([$userId]);
$orders = $stmt->fetchAll();

jsonOutMobile(true, 'orders fetched', ['orders' => $orders, 'page' => $page]);
