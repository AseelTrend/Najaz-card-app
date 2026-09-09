<?php
/**
 * api/push_subscribe.php
 * يحفظ اشتراك الجهاز في قاعدة البيانات
 */
require_once '../includes/config.php';

header('Content-Type: application/json');

// يجب أن يكون المستخدم مسجّل دخول
if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'غير مسجّل دخول']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
    echo json_encode(['ok' => false, 'error' => 'بيانات الاشتراك ناقصة']);
    exit;
}

$userId   = $_SESSION['user_id'];
$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'];
$auth     = $data['keys']['auth'];
$ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

try {
    // إنشاء الجدول إذا لم يوجد
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `endpoint` TEXT NOT NULL,
        `p256dh` VARCHAR(500) NOT NULL,
        `auth` VARCHAR(200) NOT NULL,
        `user_agent` VARCHAR(300) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `last_used` TIMESTAMP NULL DEFAULT NULL,
        KEY `idx_user` (`user_id`),
        KEY `idx_endpoint` (`endpoint`(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // تحقق هل الـ endpoint موجود مسبقاً
    $stmt = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ?");
    $stmt->execute([$endpoint]);
    $existing = $stmt->fetch();

    if ($existing) {
        // تحديث البيانات
        $pdo->prepare("UPDATE push_subscriptions SET user_id=?, p256dh=?, auth=?, user_agent=?, last_used=NOW() WHERE endpoint=?")
            ->execute([$userId, $p256dh, $auth, $ua, $endpoint]);
    } else {
        // إضافة اشتراك جديد
        $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent) VALUES (?,?,?,?,?)")
            ->execute([$userId, $endpoint, $p256dh, $auth, $ua]);
    }

    echo json_encode(['ok' => true, 'message' => 'تم حفظ الاشتراك']);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
