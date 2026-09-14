<?php
// api/notifications.php — نقطة نهاية AJAX للإشعارات
require_once '../includes/config.php';
// notifications.php include removed — using inline code
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];

switch ($action) {

    case 'latest':
        try {
            $limit = min((int)($_GET['limit'] ?? 5), 20);
            $s = $pdo->prepare("SELECT id,title,message,icon,color,is_read,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT $limit");
            $s->execute([$userId]);
            echo json_encode(['notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(['notifications' => []]); }
        break;

    case 'unread_count':
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $s->execute([$userId]);
            echo json_encode(['count' => (int)$s->fetchColumn()]);
        } catch (Exception $e) { echo json_encode(['count' => 0]); }
        break;

    case 'list':
        try {
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            $s = $pdo->prepare("SELECT id,type,title,message,icon,color,reference_type,reference_id,action_type,action_url,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT $limit");
            $s->execute([$userId]);
            $notifs = $s->fetchAll();
            foreach ($notifs as &$n) $n['time_ago'] = timeAgo($n['created_at']);
            echo json_encode(['notifications' => $notifs]);
        } catch (Exception $e) { echo json_encode(['notifications' => [], 'error' => $e->getMessage()]); }
        break;

    case 'mark_read':
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id > 0) $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $userId]);
            else $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id > 0) $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$id, $userId]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { echo json_encode(['success' => false]); }
        break;

    case 'get_popups':
        try {
            $uid  = (int)$userId;
            $now  = date('Y-m-d H:i:s');
            // جلب مباشر بدون الاعتماد على دالة includes
            $all = $pdo->query("
                SELECT * FROM notification_broadcasts
                WHERE is_active = 1
                  AND (end_at IS NULL OR end_at > '$now')
                ORDER BY created_at DESC LIMIT 10
            ")->fetchAll();

            $result = [];
            foreach ($all as $nb) {
                if ($nb['target'] === 'specific') {
                    $ids = json_decode($nb['target_user_ids'] ?? '[]', true);
                    if (!in_array($uid, (array)$ids)) continue;
                }
                if ($nb['show_once']) {
                    $v = $pdo->query("SELECT COUNT(*) FROM broadcast_views WHERE broadcast_id={$nb['id']} AND user_id=$uid")->fetchColumn();
                    if ($v > 0) continue;
                }
                $result[] = $nb;
                if (count($result) >= 5) break;
            }
            echo json_encode(['popups' => $result]);
        } catch(Exception $e) {
            echo json_encode(['popups' => [], 'error' => $e->getMessage()]);
        }
        break;

    case 'mark_popup_viewed':
        $bid = (int)($_POST['broadcast_id'] ?? 0);
        if ($bid > 0) markBroadcastViewed($pdo, $bid, $userId);
        echo json_encode(['success' => true]);
        break;

    case 'check_new':
        try {
            $afterId  = (int)($_GET['after_id'] ?? 0);

            $s1 = $pdo->prepare("SELECT MAX(id) FROM notifications WHERE user_id=?");
            $s1->execute([$userId]);
            $lastId = (int)$s1->fetchColumn();

            $s2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $s2->execute([$userId]);
            $unread = (int)$s2->fetchColumn();

            $newCount = 0;
            $sound    = '';
            if ($afterId > 0 && $lastId > $afterId) {
                $s3 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND id > ?");
                $s3->execute([$userId, $afterId]);
                $newCount = (int)$s3->fetchColumn();

                $s4 = $pdo->prepare("SELECT type FROM notifications WHERE user_id=? AND id > ? ORDER BY id DESC LIMIT 1");
                $s4->execute([$userId, $afterId]);
                $last = $s4->fetch();
                if ($last) {
                    $sound = in_array($last['type'], ['order_cancelled','cancel','refund']) ? 'cancel' : 'success';
                }
            }

            echo json_encode([
                'last_id'      => $lastId,
                'new_count'    => $newCount,
                'unread_count' => $unread,
                'sound'        => $sound,
            ]);
        } catch(Exception $e) {
            echo json_encode(['last_id'=>0,'new_count'=>0,'unread_count'=>0,'sound'=>'']);
        }
        break;

    default:
        echo json_encode(['error' => 'invalid action']);
}

function timeAgo($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'الآن';
    if ($diff < 3600)   return (int)($diff/60) . ' دقيقة';
    if ($diff < 86400)  return (int)($diff/3600) . ' ساعة';
    if ($diff < 604800) return (int)($diff/86400) . ' يوم';
    return date('d/m', strtotime($datetime));
}
