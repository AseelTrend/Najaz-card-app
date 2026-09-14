<?php
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

$userId = mobileAuthorizeRequest($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

function mobileNotificationTimeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'الآن';
    if ($diff < 3600) return (int)($diff / 60) . ' دقيقة';
    if ($diff < 86400) return (int)($diff / 3600) . ' ساعة';
    if ($diff < 604800) return (int)($diff / 86400) . ' يوم';
    return date('d/m', strtotime($datetime));
}

try {
    switch ($action) {
        case 'list':
            $limit = min(max((int)($_GET['limit'] ?? 50), 1), 50);
            $stmt = $pdo->prepare(
                "SELECT id,type,title,message,icon,color,reference_type,reference_id,action_type,action_url,is_read,created_at
                 FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT $limit"
            );
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($notifications as &$notification) {
                $notification['time_ago'] = mobileNotificationTimeAgo($notification['created_at']);
            }
            jsonOutMobile(true, '', ['notifications' => $notifications]);

        case 'unread_count':
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
            $stmt->execute([$userId]);
            jsonOutMobile(true, '', ['count' => (int)$stmt->fetchColumn()]);

        case 'mark_read':
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
                $stmt->execute([$id, $userId]);
            } else {
                $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?');
                $stmt->execute([$userId]);
            }
            jsonOutMobile(true, 'تم تحديث الإشعارات');

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jsonOutMobile(false, 'معرّف الإشعار غير صحيح', [], 400);
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE id=? AND user_id=?');
            $stmt->execute([$id, $userId]);
            jsonOutMobile(true, 'تم حذف الإشعار');

        default:
            jsonOutMobile(false, 'إجراء غير صالح', [], 400);
    }
} catch (Throwable $e) {
    jsonOutMobile(false, 'تعذر تحميل الإشعارات', [], 500);
}
