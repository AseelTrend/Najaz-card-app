<?php
// api/mobile/services.php — قراءة فقط
// إذا أُرسل توكن صالح: يحسب السعر حسب مجموعة تسعير المستخدم (نفس منطق الموقع بالضبط)
// إذا بدون توكن: يعرض السعر الأساسي (تصفح كضيف)
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/pricing_helper.php';

$userId = mobileAuthorizeRequest($pdo, false); // false = التوكن اختياري هنا

$categoryId = $_GET['category_id'] ?? null;

$sql = "SELECT * FROM services WHERE status = 1 AND deleted_at IS NULL";
$params = [];
if ($categoryId) {
    $sql .= " AND category_id = ?";
    $params[] = $categoryId;
}
$sql .= " ORDER BY sort_order ASC, id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$result = [];
foreach ($services as $s) {
    if ($userId) {
        $priceInfo = getUserServicePrice($pdo, $userId, $s);
        $price = $priceInfo['price'];
    } else {
        $price = (float)$s['price'];
    }
    $result[] = [
        'id'          => (int)$s['id'],
        'category_id' => (int)$s['category_id'],
        'name'        => $s['name'],
        'description' => $s['description'],
        'image'       => $s['image'],
        'price'       => round($price, 2),
        'min_qty'     => (int)$s['min_qty'],
        'max_qty'     => (int)$s['max_qty'],
    ];
}

jsonOutMobile(true, 'services fetched', ['services' => $result]);
