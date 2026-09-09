<?php
require_once 'includes/config.php';
requireLogin();
$pageTitle = 'إشعاراتي — ' . (getSetting('site_name') ?: SITE_NAME);

// تعليم الكل كمقروء عند فتح الصفحة
try {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$_SESSION['user_id']]);
} catch (Exception $e) {}

// جلب الإشعارات
try {
    $s = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
    $s->execute([$_SESSION['user_id']]);
    $notifications = $s->fetchAll();
} catch (Exception $e) {
    $notifications = [];
}

// حذف إشعار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $did = (int)$_POST['delete_id'];
    try {
        $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$did, $_SESSION['user_id']]);
    } catch (Exception $e) {}
    header('Location: ' . SITE_URL . '/notifications.php');
    exit;
}

function notifTimeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'منذ لحظات';
    if ($diff < 3600)   return 'منذ ' . (int)($diff/60) . ' دقيقة';
    if ($diff < 86400)  return 'منذ ' . (int)($diff/3600) . ' ساعة';
    if ($diff < 604800) return 'منذ ' . (int)($diff/86400) . ' يوم';
    return date('d/m/Y', strtotime($dt));
}

include 'includes/header.php';
?>
<style>
.notif-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.notif-page-title {
    font-size: 1.4rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}
.notif-page-title .icon-wrap {
    width: 42px; height: 42px;
    background: rgba(108,63,224,0.15);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; color: #a78bfa;
}
.notif-list { display: flex; flex-direction: column; gap: 10px; }
.notif-item {
    background: var(--card, #111827);
    border: 1px solid var(--border, #1e2940);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    gap: 14px;
    align-items: flex-start;
    transition: border-color .2s;
    position: relative;
}
.notif-item:hover { border-color: rgba(108,63,224,0.4); }
.notif-item.unread { border-left: 3px solid #6c3fe0; }
.notif-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.notif-body { flex: 1; min-width: 0; }
.notif-title {
    font-weight: 600;
    font-size: .95rem;
    margin-bottom: 4px;
    color: #fff;
}
.notif-message {
    font-size: .85rem;
    color: #9ca3af;
    line-height: 1.5;
    white-space: pre-line;
}
.notif-time {
    font-size: .75rem;
    color: #6b7280;
    margin-top: 6px;
    display: flex; align-items: center; gap: 4px;
}
.notif-delete {
    position: absolute;
    top: 12px; left: 12px;
    background: transparent;
    border: none;
    color: #6b7280;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: .8rem;
    transition: color .2s, background .2s;
}
.notif-delete:hover { color: #ff4455; background: rgba(255,68,85,.1); }
.notif-empty {
    text-align: center;
    padding: 60px 20px;
    color: #6b7280;
}
.notif-empty i { font-size: 3rem; margin-bottom: 16px; display: block; opacity: .3; }
.notif-ref-link {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .78rem;
    color: #a78bfa;
    margin-top: 6px;
    text-decoration: none;
}
.notif-ref-link:hover { text-decoration: underline; }
.btn-clear-all {
    background: rgba(255,68,85,.1);
    color: #ff4455;
    border: 1px solid rgba(255,68,85,.2);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: .85rem;
    cursor: pointer;
    transition: background .2s;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-clear-all:hover { background: rgba(255,68,85,.2); }
</style>

<div class="notif-page-header">
    <div class="notif-page-title">
        <div class="icon-wrap"><i class="fas fa-bell"></i></div>
        إشعاراتي
        <span style="font-size:.85rem;color:#6b7280;font-weight:400">(<?= count($notifications) ?>)</span>
    </div>
    <?php if ($notifications): ?>
    <form method="POST" onsubmit="return confirm('حذف جميع الإشعارات؟')">
        <input type="hidden" name="delete_id" value="0">
        <button type="submit" name="delete_all" class="btn-clear-all">
            <i class="fas fa-trash-alt"></i> مسح الكل
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="notif-empty">
    <i class="fas fa-bell-slash"></i>
    <p>لا توجد إشعارات بعد</p>
    <small>ستظهر هنا إشعارات طلباتك وحسابك</small>
</div>
<?php else: ?>
<div class="notif-list">
<?php foreach ($notifications as $n):
    $refLink = '';
    if ($n['reference_type'] === 'order' && $n['reference_id'])
        $refLink = SITE_URL . '/orders.php?order=' . $n['reference_id'];
    elseif ($n['reference_type'] === 'wallet' || $n['reference_type'] === 'topup')
        $refLink = SITE_URL . '/wallet.php';
?>
<div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
    <div class="notif-icon" style="background:<?= htmlspecialchars($n['color']) ?>22; color:<?= htmlspecialchars($n['color']) ?>">
        <i class="fas fa-<?= htmlspecialchars($n['icon']) ?>"></i>
    </div>
    <div class="notif-body">
        <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
        <div class="notif-message"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
        <?php if ($refLink): ?>
        <a href="<?= $refLink ?>" class="notif-ref-link">
            <i class="fas fa-arrow-left"></i>
            <?= $n['reference_type'] === 'order' ? 'عرض الطلب #' . $n['reference_id'] : 'عرض المحفظة' ?>
        </a>
        <?php endif; ?>
        <div class="notif-time">
            <i class="fas fa-clock"></i>
            <?= notifTimeAgo($n['created_at']) ?>
        </div>
    </div>
    <form method="POST" style="position:absolute;top:10px;left:10px">
        <input type="hidden" name="delete_id" value="<?= $n['id'] ?>">
        <button type="submit" class="notif-delete" title="حذف">
            <i class="fas fa-times"></i>
        </button>
    </form>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
