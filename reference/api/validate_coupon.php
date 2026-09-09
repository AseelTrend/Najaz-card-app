<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/rate_limiter.php'; // [M-3 FIX]
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { echo json_encode(['ok'=>false,'error'=>'login']); exit; }

// [M-3 FIX] Rate Limiting — 20 محاولة كل 60 ثانية لكل مستخدم (منع تخمين الكوبونات)
apiRateLimit($pdo, 'validate_coupon', 20, 60, true);

$code      = strtoupper(trim($_POST['code'] ?? ''));
$serviceId = (int)($_POST['service_id'] ?? 0);
$amount    = (float)($_POST['amount'] ?? 0);
$userId    = (int)$_SESSION['user_id'];

if (!$code || !$serviceId || !$amount) {
    echo json_encode(['ok'=>false,'error'=>'بيانات ناقصة']); exit;
}

// إنشاء الجداول إذا لم تكن موجودة
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,`code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(200) NOT NULL,`discount_type` ENUM('percent','fixed') DEFAULT 'percent',
        `discount_value` DECIMAL(10,4) DEFAULT 0,`min_order` DECIMAL(10,4) DEFAULT 0,
        `max_discount` DECIMAL(10,4) DEFAULT NULL,
        `applies_to` ENUM('all','category','service') DEFAULT 'all',
        `category_id` INT DEFAULT NULL,`service_id` INT DEFAULT NULL,
        `condition_type` ENUM('none','not_referred','referred') DEFAULT 'none',
        `usage_limit` INT DEFAULT NULL,`usage_per_user` INT DEFAULT 1,`used_count` INT DEFAULT 0,
        `starts_at` TIMESTAMP NULL,`expires_at` TIMESTAMP NULL,
        `status` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_uses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,`coupon_id` INT NOT NULL,
        `user_id` INT NOT NULL,`order_id` INT DEFAULT NULL,
        `discount` DECIMAL(10,4) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// جلب الكوبون
$coupon = $pdo->prepare("SELECT * FROM coupons WHERE code=? AND status=1");
$coupon->execute([$code]); $coupon = $coupon->fetch();

if (!$coupon) {
    echo json_encode(['ok'=>false,'error'=>'كود الخصم غير صحيح أو منتهي']); exit;
}

// فحص التواريخ
$now = time();
if ($coupon['starts_at'] && strtotime($coupon['starts_at']) > $now) {
    echo json_encode(['ok'=>false,'error'=>'هذا الكوبون لم يبدأ بعد']); exit;
}
if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < $now) {
    echo json_encode(['ok'=>false,'error'=>'انتهت صلاحية هذا الكوبون']); exit;
}

// فحص عدد الاستخدامات الكلي
if ($coupon['usage_limit'] && $coupon['used_count'] >= $coupon['usage_limit']) {
    echo json_encode(['ok'=>false,'error'=>'تم استنفاد هذا الكوبون']); exit;
}

// فحص استخدام العميل
$userUses = $pdo->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_id=? AND user_id=?");
$userUses->execute([$coupon['id'], $userId]);
if ((int)$userUses->fetchColumn() >= (int)$coupon['usage_per_user']) {
    echo json_encode(['ok'=>false,'error'=>'استخدمت هذا الكوبون مسبقاً']); exit;
}

// فحص الحد الأدنى
if ($coupon['min_order'] > 0 && $amount < $coupon['min_order']) {
    echo json_encode(['ok'=>false,'error'=>'الحد الأدنى للطلب ' . number_format($coupon['min_order'],2) . '$']); exit;
}

// فحص الخدمة/القسم
if ($coupon['applies_to'] === 'service' && $coupon['service_id']) {
    if ($coupon['service_id'] != $serviceId) {
        echo json_encode(['ok'=>false,'error'=>'هذا الكوبون لا ينطبق على هذه الخدمة']); exit;
    }
}
if ($coupon['applies_to'] === 'category' && $coupon['category_id']) {
    $svcCat = $pdo->prepare("SELECT category_id FROM services WHERE id=?");
    $svcCat->execute([$serviceId]); $svcCat = $svcCat->fetchColumn();
    if ($svcCat != $coupon['category_id']) {
        echo json_encode(['ok'=>false,'error'=>'هذا الكوبون لا ينطبق على قسم هذه الخدمة']); exit;
    }
}

// فحص شرط الإحالة
if ($coupon['condition_type'] !== 'none') {
    $isReferred = false;
    try {
        $refCheck = $pdo->prepare("SELECT referred_by FROM users WHERE id=?");
        $refCheck->execute([$userId]); $refRow = $refCheck->fetch();
        $isReferred = !empty($refRow['referred_by']);
    } catch(Exception $e) {}

    if ($coupon['condition_type'] === 'not_referred' && $isReferred) {
        echo json_encode(['ok'=>false,'error'=>'هذا الكوبون متاح فقط للعملاء غير المدعوين']); exit;
    }
    if ($coupon['condition_type'] === 'referred' && !$isReferred) {
        echo json_encode(['ok'=>false,'error'=>'هذا الكوبون متاح فقط للعملاء المدعوين']); exit;
    }
}

// احتساب الخصم
$discount = 0;
if ($coupon['discount_type'] === 'percent') {
    $discount = round($amount * $coupon['discount_value'] / 100, 4);
    if ($coupon['max_discount'] && $discount > $coupon['max_discount'])
        $discount = (float)$coupon['max_discount'];
} else {
    $discount = min((float)$coupon['discount_value'], $amount);
}

$finalAmount = max(0, $amount - $discount);

echo json_encode([
    'ok'           => true,
    'coupon_id'    => $coupon['id'],
    'name'         => $coupon['name'],
    'discount_type'=> $coupon['discount_type'],
    'discount_value'=> $coupon['discount_value'],
    'discount'     => round($discount, 4),
    'final_amount' => round($finalAmount, 4),
    'message'      => '🎉 تم تطبيق خصم ' . ($coupon['discount_type']==='percent' ? number_format($coupon['discount_value'],1).'%' : number_format($discount,2).'$'),
], JSON_UNESCAPED_UNICODE);
