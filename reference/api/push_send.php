<?php
/**
 * api/push_send.php
 * إرسال Web Push Notifications
 * يُستدعى من لوحة الأدمن (admin/push_notifications.php)
 */
require_once '../includes/config.php';
require_once '../includes/vapid.php';

header('Content-Type: application/json');

// صلاحيات الأدمن فقط
if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    echo json_encode(['ok' => false, 'error' => 'غير مصرح']);
    exit;
}

// ─── إعدادات VAPID ────────────────────────────────────────────────
// المفاتيح مخزّنة في جدول settings أو fallback للثوابت
$vapidPublicKey  = getSetting('vapid_public_key')
    ?: 'BEJacYbEinWKvQ4UG2hUmCY_ldzr3yAr25RVspPYSP3FfHqMPK44K7aBMurSsR-fadwGjMixbiKTHnw6MWnWw6o';
$vapidPrivateKey = getSetting('vapid_private_key') ?: '';
$vapidSubject    = getSetting('vapid_subject') ?: ('mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

if (empty($vapidPrivateKey)) {
    echo json_encode(['ok' => false, 'error' => 'مفتاح VAPID الخاص غير مضبوط — اذهب إلى الإعدادات']);
    exit;
}

// ─── بيانات الإشعار ────────────────────────────────────────────────
$action   = $_POST['action'] ?? '';
$title    = trim($_POST['title']   ?? '');
$body     = trim($_POST['body']    ?? '');
$url      = trim($_POST['url']     ?? '/mobile.php');
$targetUid = (int)($_POST['target_user'] ?? 0);

if ($action !== 'send') {
    echo json_encode(['ok' => false, 'error' => 'action غير صالح']);
    exit;
}
if (!$title || !$body) {
    echo json_encode(['ok' => false, 'error' => 'العنوان والنص مطلوبان']);
    exit;
}

// ─── جلب المشتركين ────────────────────────────────────────────────
try {
    if ($targetUid > 0) {
        $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$targetUid]);
    } else {
        $stmt = $pdo->query("SELECT * FROM push_subscriptions");
    }
    $subscriptions = $stmt->fetchAll();
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'خطأ في قراءة المشتركين: ' . $e->getMessage()]);
    exit;
}

if (empty($subscriptions)) {
    echo json_encode(['ok' => false, 'error' => 'لا يوجد مشتركون' . ($targetUid ? ' لهذا المستخدم' : '')]);
    exit;
}

// ─── إعداد الرسالة ────────────────────────────────────────────────
$siteUrl = SITE_URL;
$payload = json_encode([
    'title' => $title,
    'body'  => $body,
    'url'   => (str_starts_with($url, 'http') ? $url : $siteUrl . $url),
    'icon'  => $siteUrl . '/icons/icon-192.png',
    'badge' => $siteUrl . '/icons/icon-96.png',
    'tag'   => 'shahenpro-' . time(),
], JSON_UNESCAPED_UNICODE);

// ─── تحويل المفتاح الخاص إلى PEM ─────────────────────────────────
try {
    $privateKeyPem = VapidHelper::privateKeyToPem($vapidPrivateKey, $vapidPublicKey);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'خطأ في تحويل مفتاح VAPID: ' . $e->getMessage()]);
    exit;
}

// ─── الإرسال ──────────────────────────────────────────────────────
$sent   = 0;
$failed = 0;
$goneSubs = []; // اشتراكات منتهية يجب حذفها

foreach ($subscriptions as $sub) {
    try {
        $result = VapidHelper::sendNotification(
            [
                'endpoint' => $sub['endpoint'],
                'p256dh'   => $sub['p256dh'],
                'auth'     => $sub['auth'],
            ],
            $payload,
            $vapidPublicKey,
            $privateKeyPem,
            $vapidSubject
        );

        if ($result['ok']) {
            $sent++;
            // تحديث last_used
            $pdo->prepare("UPDATE push_subscriptions SET last_used=NOW() WHERE id=?")
                ->execute([$sub['id']]);
        } else {
            $failed++;
            // إذا الاشتراك منتهي (HTTP 410) احذفه
            if ($result['gone']) {
                $goneSubs[] = $sub['id'];
            }
        }
    } catch (Exception $e) {
        $failed++;
    }
}

// حذف الاشتراكات المنتهية
if (!empty($goneSubs)) {
    $ids = implode(',', array_map('intval', $goneSubs));
    $pdo->exec("DELETE FROM push_subscriptions WHERE id IN ($ids)");
}

// ─── تسجيل في push_log ────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(200) NOT NULL,
        `body` TEXT,
        `url` VARCHAR(500),
        `target` ENUM('all','user') DEFAULT 'all',
        `target_user_id` INT DEFAULT NULL,
        `sent_count` INT DEFAULT 0,
        `failed_count` INT DEFAULT 0,
        `sent_by` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->prepare("INSERT INTO push_log (title, body, url, target, target_user_id, sent_count, failed_count, sent_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([
            $title, $body, $url,
            $targetUid ? 'user' : 'all',
            $targetUid ?: null,
            $sent, $failed,
            $_SESSION['user_id']
        ]);
} catch (Exception $e) {
    // تسجيل اختياري — لا نوقف الاستجابة
}

echo json_encode([
    'ok'     => true,
    'sent'   => $sent,
    'failed' => $failed,
    'total'  => count($subscriptions),
]);
