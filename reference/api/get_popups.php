<?php
// API مستقل لجلب الـ popups - بدون أي include خارجي
require_once dirname(__DIR__) . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
// منع أي تخزين مؤقت (متصفح / CDN مثل Cloudflare / بروكسي وسيط) لهذا الرد،
// لأنه يعتمد على حالة كل مستخدم لحظيًا (إشعارات جديدة، حالة المشاهدة، إلخ)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('CDN-Cache-Control: no-store');
header('Cloudflare-CDN-Cache-Control: no-store');

if (!isLoggedIn()) {
    echo json_encode(['popups' => [], 'auth' => false]);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

try {
    $all = $pdo->query("
        SELECT * FROM notification_broadcasts
        WHERE is_active = 1
          AND (end_at IS NULL OR end_at > '$now')
        ORDER BY created_at DESC
        LIMIT 10
    ")->fetchAll();

    $result = [];
    foreach ($all as $nb) {
        if ($nb['target'] === 'specific') {
            $ids = json_decode($nb['target_user_ids'] ?? '[]', true);
            if (!in_array($uid, (array)$ids)) continue;
        }
        if ((int)$nb['show_once'] === 1) {
            $v = (int)$pdo->query("
                SELECT COUNT(*) FROM broadcast_views
                WHERE broadcast_id = {$nb['id']} AND user_id = $uid
            ")->fetchColumn();
            if ($v > 0) continue;
        }
        $result[] = $nb;
        if (count($result) >= 5) break;
    }

    echo json_encode([
        'popups' => $result,
        'uid'    => $uid,
        'total'  => count($all),
        'found'  => count($result),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['popups' => [], 'error' => $e->getMessage()]);
}
