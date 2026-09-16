<?php
// api/mobile/service_detail.php — تفاصيل خدمة + الحقول المطلوبة لتعبئتها بالطلب
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/pricing_helper.php';

$userId = mobileAuthorizeRequest($pdo, false);

$id = (int)($_GET['id'] ?? 0);
if (!$id) jsonOutMobile(false, 'معرّف الخدمة مطلوب', [], 400);

$stmt = $pdo->prepare("SELECT * FROM services WHERE id=? AND status=1 AND deleted_at IS NULL");
$stmt->execute([$id]);
$service = $stmt->fetch();
if (!$service) jsonOutMobile(false, 'الخدمة غير موجودة أو متوقفة', [], 404);

$price = $userId ? getUserServicePrice($pdo, $userId, $service)['price'] : (float)$service['price'];

$fieldsStmt = $pdo->prepare("SELECT field_name, field_label, field_type, field_options, is_required
                              FROM service_fields WHERE service_id=? ORDER BY sort_order ASC");
$fieldsStmt->execute([$id]);
$fields = $fieldsStmt->fetchAll();

jsonOutMobile(true, 'service fetched', [
    'service' => [
        'id'          => (int)$service['id'],
        'category_id' => (int)$service['category_id'],
        'name'        => $service['name'],
        'description' => $service['description'],
        'image'       => $service['image'],
        // [FIX] نفس مشكلة services.php: round($price, 2) كانت تُصفّر أي
        // سعر أصغر من 0.005 قبل وصوله للتطبيق. لا علاقة لهذا بحساب
        // المبلغ الفعلي المخصوم عند الطلب (place_order.php يستخدم القيمة
        // الكاملة دون تقريب) — هذا يخص العرض فقط.
        'price'       => round($price, 8),
        'min_qty'     => (int)$service['min_qty'],
        'max_qty'     => (int)$service['max_qty'],
        'fields'      => $fields,
    ],
]);
