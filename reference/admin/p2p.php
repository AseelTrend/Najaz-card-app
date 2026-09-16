<?php
require_once '../includes/config.php';
require_once dirname(__DIR__) . '/includes/p2p_accounting.php';
p2pAccountingEnsureTable($pdo);
requireStaffOrAdmin($pdo, 'perm_orders_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'سوق P2P - ' . SITE_NAME;

// إنشاء الجداول إذا لم تكن موجودة
$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `seller_id` INT NOT NULL, `title` VARCHAR(200) NOT NULL,
  `description` TEXT, `price` DECIMAL(12,4) NOT NULL, `quantity` INT DEFAULT 1, `sold_count` INT DEFAULT 0,
  `commission_rate` DECIMAL(5,2) DEFAULT 5.00, `commission_payer` ENUM('buyer','seller') DEFAULT 'buyer',
  `image` VARCHAR(500), `status` ENUM('active','paused','sold_out','suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `service_id` INT NOT NULL, `buyer_id` INT NOT NULL, `seller_id` INT NOT NULL,
  `price` DECIMAL(12,4) NOT NULL, `commission` DECIMAL(12,4) DEFAULT 0, `commission_payer` ENUM('buyer','seller') DEFAULT 'buyer',
  `buyer_total` DECIMAL(12,4) NOT NULL, `seller_escrow` DECIMAL(12,4) NOT NULL, `seller_payout` DECIMAL(12,4) NOT NULL,
  `status` ENUM('pending','active','completed','cancelled','disputed') DEFAULT 'pending',
  `chat_expires_at` DATETIME, `completed_at` DATETIME, `cancelled_at` DATETIME,
  `cancelled_by` ENUM('buyer','seller','admin','timeout'), `cancel_reason` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `sender_id` INT NOT NULL,
  `message` TEXT NOT NULL, `is_read` TINYINT(1) DEFAULT 0, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$tab = $_GET['tab'] ?? 'orders';
$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

// ── إجراءات ──────────────────────────────────────────────
// تعليق خدمة
if ($action === 'suspend_service' && $id && $IS_ADMIN) {
    $pdo->prepare("UPDATE p2p_services SET status='suspended' WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم تعليق الخدمة');
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// تفعيل خدمة / موافقة
if ($action === 'activate_service' && $id && $IS_ADMIN) {
    $pdo->prepare("UPDATE p2p_services SET status='active', reject_reason=NULL WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم تفعيل/الموافقة على الخدمة ✅');
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// رفض خدمة جديدة
if ($action === 'reject_service' && $id && $IS_ADMIN) {
    $reason = trim($_GET['reason'] ?? '');
    $pdo->prepare("UPDATE p2p_services SET status='rejected', reject_reason=? WHERE id=?")->execute([$reason, $id]);
    flashMessage('success', 'تم رفض الخدمة');
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// قبول تعديلات معلقة
if ($action === 'approve_edit' && $id && $IS_ADMIN) {
    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=?"); $svc->execute([$id]); $svc=$svc->fetch();
    if ($svc && $svc['pending_changes']) {
        $ch = json_decode($svc['pending_changes'], true);
        if ($ch) {
            $pdo->prepare("UPDATE p2p_services SET title=?,description=?,price=?,quantity=?,category_id=?,commission_payer=?,pending_changes=NULL WHERE id=?")
                ->execute([$ch['title']??$svc['title'], $ch['description']??$svc['description'],
                           $ch['price']??$svc['price'], $ch['quantity']??$svc['quantity'],
                           $ch['category_id']??$svc['category_id'], $ch['commission_payer']??$svc['commission_payer'], $id]);
        }
        flashMessage('success', 'تم قبول التعديلات ✅');
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// رفض تعديلات معلقة
if ($action === 'reject_edit' && $id && $IS_ADMIN) {
    $pdo->prepare("UPDATE p2p_services SET pending_changes=NULL WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم رفض التعديلات');
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// حذف خدمة
if ($action === 'delete_service' && $id && $IS_ADMIN) {
    $pdo->prepare("DELETE FROM p2p_services WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف الخدمة');
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}
// طوارئ: إيقاف/تشغيل P2P
if ($action === 'emergency_stop' && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_enabled','0') ON DUPLICATE KEY UPDATE setting_value='0'")->execute();
    flashMessage('warning', '🔴 تم إيقاف نظام P2P بالكامل');
    redirect(SITE_URL . '/admin/p2p.php?tab=monitor');
}
if ($action === 'emergency_start' && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    flashMessage('success', '🟢 تم تشغيل نظام P2P');
    redirect(SITE_URL . '/admin/p2p.php?tab=monitor');
}
// إلغاء طلب بواسطة الأدمن
if ($action === 'admin_cancel' && $id && $IS_ADMIN) {
    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND status IN ('pending','active')");
    $ord->execute([$id]); $ord = $ord->fetch();
    if ($ord) {
        $pdo->beginTransaction();
        // رد المبلغ للمشتري
        $buyer = getUser($ord['buyer_id']);
        $newBB = $buyer['balance'] + $ord['buyer_total'];
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBB, $ord['buyer_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$ord['buyer_id'], 'credit', $ord['buyer_total'], $buyer['balance'], $newBB, 'استرداد P2P #'.$id.' (إلغاء إداري)', $id]);
        // رد الضمان للبائع
        $seller = getUser($ord['seller_id']);
        $newSB = $seller['balance'] + $ord['seller_escrow'];
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newSB, $ord['seller_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$ord['seller_id'], 'credit', $ord['seller_escrow'], $seller['balance'], $newSB, 'استرداد ضمان P2P #'.$id.' (إلغاء إداري)', $id]);
        $pdo->prepare("UPDATE p2p_orders SET status='cancelled', cancelled_at=NOW(), cancelled_by='admin', cancel_reason='إلغاء بواسطة الإدارة' WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$id, '🔴 تم إلغاء الطلب بواسطة الإدارة. تم إرجاع المبالغ للطرفين.']);
        $pdo->commit();
        flashMessage('success', 'تم إلغاء الطلب واسترداد المبالغ');
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=orders');
}
// إتمام طلب بواسطة الأدمن
if ($action === 'admin_complete' && $id && $IS_ADMIN) {
    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND status='active'");
    $ord->execute([$id]); $ord = $ord->fetch();
    if ($ord) {
        $pdo->beginTransaction();
        $seller = getUser($ord['seller_id']);
        $payout = (float)$ord['seller_payout'];
        $escrow = (float)$ord['seller_escrow'];
        $newSB = $seller['balance'] + $escrow + $payout;
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newSB, $ord['seller_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$ord['seller_id'], 'credit', $escrow + $payout, $seller['balance'], $newSB, 'إيراد P2P #'.$id.' (إتمام إداري)', $id]);
        $pdo->prepare("UPDATE p2p_orders SET status='completed', completed_at=NOW() WHERE id=?")->execute([$id]);
        recordP2PCommission($pdo, $ord, (int)$id, 'admin_complete');
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$id, '✅ تم إتمام الطلب بواسطة الإدارة.']);
        $pdo->commit();
        flashMessage('success', 'تم إتمام الطلب وتحويل المبلغ للبائع');
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=orders');
}

// ── إحصائيات ─────────────────────────────────────────────
$stats = [
    'services'  => (int)$pdo->query("SELECT COUNT(*) FROM p2p_services")->fetchColumn(),
    'active_s'  => (int)$pdo->query("SELECT COUNT(*) FROM p2p_services WHERE status='active'")->fetchColumn(),
    'orders'    => (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders")->fetchColumn(),
    'active_o'  => (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='active'")->fetchColumn(),
    'delivered' => (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='delivered'")->fetchColumn(),
    'disputed'  => (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='disputed'")->fetchColumn(),
    'completed' => (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='completed'")->fetchColumn(),
    'revenue'   => (float)$pdo->query("SELECT COALESCE(SUM(commission),0) FROM p2p_orders WHERE status='completed'")->fetchColumn(),
];

include 'header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-handshake" style="color:#8b5cf6"></i> سوق P2P</h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="?tab=orders" class="btn btn-<?=$tab==='orders'?'primary':'secondary'?> btn-sm">📋 الطلبات</a>
        <a href="?tab=disputes" class="btn btn-<?=$tab==='disputes'?'primary':'secondary'?> btn-sm">⚖️ النزاعات</a>
        <a href="?tab=monitor" class="btn btn-<?=$tab==='monitor'?'primary':'secondary'?> btn-sm">📡 المراقبة</a>
        <a href="?tab=chats" class="btn btn-<?=$tab==='chats'?'primary':'secondary'?> btn-sm">💬 المحادثات</a>
        <a href="?tab=services" class="btn btn-<?=$tab==='services'?'primary':'secondary'?> btn-sm">🏪 الخدمات</a>
        <a href="?tab=categories" class="btn btn-<?=$tab==='categories'?'primary':'secondary'?> btn-sm">📂 الأقسام</a>
        <a href="?tab=reports" class="btn btn-<?=$tab==='reports'?'primary':'secondary'?> btn-sm">🚨 البلاغات</a>
        <a href="?tab=fraud" class="btn btn-<?=$tab==='fraud'?'primary':'secondary'?> btn-sm">🔍 الاحتيال</a>
        <a href="?tab=ratings" class="btn btn-<?=$tab==='ratings'?'primary':'secondary'?> btn-sm">⭐ التقييمات</a>
        <a href="?tab=stats" class="btn btn-<?=$tab==='stats'?'primary':'secondary'?> btn-sm">📊 الإحصائيات</a>
        <a href="?tab=settings" class="btn btn-<?=$tab==='settings'?'primary':'secondary'?> btn-sm">⚙️ الإعدادات</a>
    </div>
</div>

<!-- ═══ تنبيهات ذكية ═══ -->
<?php
$alertDisputes = $stats['disputed'];
$alertDelivered = $stats['delivered'];
$alertDanger = 0; try { $alertDanger = (int)$pdo->query("SELECT COUNT(*) FROM p2p_risk_scores WHERE risk_level='danger'")->fetchColumn(); } catch(Exception $e) {}
$alertPendingSvc = 0; try { $alertPendingSvc = (int)$pdo->query("SELECT COUNT(*) FROM p2p_services WHERE status='pending_review'")->fetchColumn(); } catch(Exception $e) {}
$alertReports = 0; try { $alertReports = (int)$pdo->query("SELECT COUNT(*) FROM p2p_reports WHERE status='pending'")->fetchColumn(); } catch(Exception $e) {}
$hasAlerts = $alertDisputes || $alertDelivered || $alertDanger || $alertPendingSvc || $alertReports;
if ($hasAlerts): ?>
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:1rem">
    <?php if($alertDisputes): ?><a href="?tab=disputes" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:10px;text-decoration:none;color:#f5a623;font-size:.8rem;font-weight:700"><i class="fas fa-gavel"></i> <?=$alertDisputes?> نزاع مفتوح</a><?php endif; ?>
    <?php if($alertDelivered): ?><a href="?tab=monitor" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.2);border-radius:10px;text-decoration:none;color:#8b5cf6;font-size:.8rem;font-weight:700"><i class="fas fa-clock"></i> <?=$alertDelivered?> بانتظار تأكيد</a><?php endif; ?>
    <?php if($alertDanger): ?><a href="?tab=fraud" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(255,68,85,.08);border:1px solid rgba(255,68,85,.2);border-radius:10px;text-decoration:none;color:#ff4455;font-size:.8rem;font-weight:700"><i class="fas fa-user-slash"></i> <?=$alertDanger?> مستخدم خطر</a><?php endif; ?>
    <?php if($alertPendingSvc): ?><a href="?tab=services&svc_status=pending_review" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);border-radius:10px;text-decoration:none;color:#00d4ff;font-size:.8rem;font-weight:700"><i class="fas fa-store"></i> <?=$alertPendingSvc?> خدمة تنتظر الموافقة</a><?php endif; ?>
    <?php if($alertReports): ?><a href="?tab=reports" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(255,68,85,.08);border:1px solid rgba(255,68,85,.2);border-radius:10px;text-decoration:none;color:#ff4455;font-size:.8rem;font-weight:700"><i class="fas fa-flag"></i> <?=$alertReports?> بلاغ جديد</a><?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ إحصائيات سريعة ═══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:1.5rem">
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#00d4aa"><?=$stats['active_o']?></div>
        <div style="font-size:11px;color:#8895a7">طلبات نشطة</div>
    </div>
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#1e6fff"><?=$stats['completed']?></div>
        <div style="font-size:11px;color:#8895a7">مكتملة</div>
    </div>
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#8b5cf6"><?=$stats['delivered']?></div>
        <div style="font-size:11px;color:#8895a7">📦 مسلّم</div>
    </div>
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#f5a623"><?=$stats['disputed']?></div>
        <div style="font-size:11px;color:#8895a7">⚖️ نزاعات</div>
    </div>
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#8b5cf6"><?=$stats['active_s']?></div>
        <div style="font-size:11px;color:#8895a7">خدمات نشطة</div>
    </div>
    <div class="card" style="text-align:center;padding:16px">
        <div style="font-size:24px;font-weight:900;color:#f5a623"><?=number_format($stats['revenue'],2)?> $</div>
        <div style="font-size:11px;color:#8895a7">إيرادات العمولات</div>
    </div>
</div>

<?php if ($tab === 'orders'): ?>
<!-- ═══ الطلبات ═══ -->
<?php
$filterStatus = $_GET['status'] ?? '';
$where = "1=1";
$params = [];
if ($filterStatus && in_array($filterStatus, ['pending','active','delivered','completed','cancelled','disputed'])) {
    $where .= " AND o.status=?"; $params[] = $filterStatus;
}
$orders = $pdo->prepare("
    SELECT o.*, s.title as svc_title,
           buyer.username as buyer_user, buyer.full_name as buyer_name,
           seller.username as seller_user, seller.full_name as seller_name,
           (SELECT COUNT(*) FROM p2p_messages WHERE order_id=o.id) as msg_count
    FROM p2p_orders o
    JOIN p2p_services s ON s.id=o.service_id
    JOIN users buyer ON buyer.id=o.buyer_id
    JOIN users seller ON seller.id=o.seller_id
    WHERE $where
    ORDER BY o.created_at DESC LIMIT 100
");
$orders->execute($params);
$orders = $orders->fetchAll();
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3><i class="fas fa-shopping-bag"></i> طلبات P2P (<?=count($orders)?>)</h3>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <a href="?tab=orders" class="btn btn-sm <?=!$filterStatus?'btn-primary':'btn-secondary'?>">الكل</a>
            <a href="?tab=orders&status=active" class="btn btn-sm <?=$filterStatus==='active'?'btn-info':'btn-secondary'?>">نشط</a>
            <a href="?tab=orders&status=completed" class="btn btn-sm <?=$filterStatus==='completed'?'btn-success':'btn-secondary'?>">مكتمل</a>
            <a href="?tab=orders&status=cancelled" class="btn btn-sm <?=$filterStatus==='cancelled'?'btn-danger':'btn-secondary'?>">ملغي</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>#</th><th>الخدمة</th><th>المشتري</th><th>البائع</th><th>المبلغ</th><th>العمولة</th><th>الحالة</th><th>الرسائل</th><th>التاريخ</th><th>إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach($orders as $o): ?>
            <?php
                $stColor = ['pending'=>'#f5a623','active'=>'#00d4ff','delivered'=>'#8b5cf6','completed'=>'#00e676','cancelled'=>'#ff4455','disputed'=>'#ff6677'];
                $stLabel = ['pending'=>'انتظار','active'=>'نشط','delivered'=>'📦 مسلّم','completed'=>'مكتمل','cancelled'=>'ملغي','disputed'=>'نزاع'];
            ?>
            <tr>
                <td><strong><?=$o['id']?></strong></td>
                <td><?=htmlspecialchars(mb_substr($o['svc_title'],0,25))?></td>
                <td>
                    <a href="customers.php?action=view&id=<?=$o['buyer_id']?>" style="color:#00d4aa">
                        <?=htmlspecialchars($o['buyer_name'] ?: $o['buyer_user'])?>
                    </a>
                </td>
                <td>
                    <a href="customers.php?action=view&id=<?=$o['seller_id']?>" style="color:#8b5cf6">
                        <?=htmlspecialchars($o['seller_name'] ?: $o['seller_user'])?>
                    </a>
                </td>
                <td style="color:#00d4aa;font-weight:700"><?=number_format($o['price'],2)?> $</td>
                <td>
                    <small style="color:#f5a623"><?=number_format($o['commission'],2)?> $</small>
                    <small style="color:#8895a7">(<?=$o['commission_payer']==='buyer'?'مشتري':'بائع'?>)</small>
                </td>
                <td><span class="badge" style="background:<?=$stColor[$o['status']]?>22;color:<?=$stColor[$o['status']]?>"><?=$stLabel[$o['status']]?></span></td>
                <td><span class="badge badge-secondary"><?=$o['msg_count']?> 💬</span></td>
                <td><small><?=date('m/d H:i', strtotime($o['created_at']))?></small></td>
                <td style="white-space:nowrap;display:flex;gap:4px">
                    <a href="?tab=orders&action=view_order&id=<?=$o['id']?>" class="btn btn-sm btn-info" title="التفاصيل والمحادثة"><i class="fas fa-eye"></i></a>
                    <?php if (in_array($o['status'], ['active'])): ?>
                    <a href="?action=admin_complete&id=<?=$o['id']?>" class="btn btn-sm btn-success" title="إتمام" onclick="return confirm('إتمام الطلب وتحويل المبلغ للبائع؟')"><i class="fas fa-check"></i></a>
                    <?php endif; ?>
                    <?php if (in_array($o['status'], ['pending','active'])): ?>
                    <a href="?action=admin_cancel&id=<?=$o['id']?>" class="btn btn-sm btn-danger" title="إلغاء" onclick="return confirm('إلغاء الطلب واسترداد المبالغ؟')"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($orders)): ?><tr><td colspan="10" style="text-align:center;padding:2rem;color:#8895a7">لا توجد طلبات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// عرض تفاصيل طلب مع المحادثة
if ($action === 'view_order' && $id):
    $vo = $pdo->prepare("SELECT o.*, s.title as svc_title, s.description as svc_desc, s.price as svc_price,
        buyer.full_name as bname, buyer.username as buser, buyer.email as bemail,
        seller.full_name as sname, seller.username as suser, seller.email as semail
        FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id
        JOIN users buyer ON buyer.id=o.buyer_id JOIN users seller ON seller.id=o.seller_id
        WHERE o.id=?");
    $vo->execute([$id]); $vo=$vo->fetch();
    if ($vo):
    $msgs = $pdo->prepare("SELECT m.*, u.full_name, u.username FROM p2p_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.order_id=? ORDER BY m.created_at ASC");
    $msgs->execute([$id]); $msgs=$msgs->fetchAll();
    $orderLogs = []; try { $ol=$pdo->prepare("SELECT l.*, u.full_name FROM p2p_order_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.order_id=? ORDER BY l.created_at ASC"); $ol->execute([$id]); $orderLogs=$ol->fetchAll(); } catch(Exception $e) {}
    $walletTx = []; try { $wt=$pdo->prepare("SELECT * FROM wallet_transactions WHERE reference_id=? AND description LIKE '%P2P%' ORDER BY created_at ASC"); $wt->execute([$id]); $walletTx=$wt->fetchAll(); } catch(Exception $e) {}
    // risk scores
    $buyerRisk = null; $sellerRisk = null;
    try { $br=$pdo->prepare("SELECT * FROM p2p_risk_scores WHERE user_id=?"); $br->execute([$vo['buyer_id']]); $buyerRisk=$br->fetch(); } catch(Exception $e) {}
    try { $sr=$pdo->prepare("SELECT * FROM p2p_risk_scores WHERE user_id=?"); $sr->execute([$vo['seller_id']]); $sellerRisk=$sr->fetch(); } catch(Exception $e) {}
    $stColor=['pending'=>'#f5a623','active'=>'#00d4ff','delivered'=>'#8b5cf6','completed'=>'#00e676','cancelled'=>'#ff4455','disputed'=>'#ff6677'];
    $stLabel=['pending'=>'انتظار','active'=>'نشط','delivered'=>'📦 مسلّم','completed'=>'مكتمل','cancelled'=>'ملغي','disputed'=>'نزاع'];
    $rlC=['safe'=>'#00e676','suspicious'=>'#f5a623','danger'=>'#ff4455'];
?>
<div class="card mt-2" style="border:2px solid <?=$stColor[$vo['status']]??'#8895a7'?>44">
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0"><i class="fas fa-file-invoice" style="color:<?=$stColor[$vo['status']]??'#8895a7'?>"></i> طلب #<?=$id?> — <?=htmlspecialchars($vo['svc_title'])?></h3>
        <div style="display:flex;gap:6px;align-items:center">
            <span class="badge" style="background:<?=$stColor[$vo['status']]?>22;color:<?=$stColor[$vo['status']]?>;font-size:.85rem;padding:6px 14px"><?=$stLabel[$vo['status']]??$vo['status']?></span>
            <a href="?tab=orders" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-right"></i></a>
        </div>
    </div>

    <!-- الطرفان + Escrow -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:1.5rem">
        <div style="background:var(--bg3,#161b2e);border-radius:12px;padding:14px;border:1px solid rgba(0,212,170,.1)">
            <div style="font-size:.7rem;color:#00d4aa;font-weight:700;margin-bottom:6px">🛒 المشتري — <a href="customers.php?action=view&id=<?=$vo['buyer_id']?>">#<?=$vo['buyer_id']?></a></div>
            <div style="font-weight:800;margin-bottom:4px"><?=htmlspecialchars($vo['bname']?:$vo['buser'])?></div>
            <div style="font-size:.75rem;color:#00d4aa;font-weight:700">دفع: <?=number_format($vo['buyer_total'],4)?> $</div>
            <?php if($buyerRisk): $brl=$buyerRisk['risk_level']??'safe'; ?><div style="margin-top:6px"><span class="badge" style="background:<?=$rlC[$brl]?>22;color:<?=$rlC[$brl]?>"><?=$buyerRisk['risk_score']?> — <?=$brl?></span>
            <?php if($buyerRisk['is_blocked']): ?><span class="badge" style="background:#ff445522;color:#ff4455">⛔</span><?php endif; ?>
            </div><?php endif; ?>
            <div style="margin-top:6px;display:flex;gap:3px"><a href="?tab=fraud&action=view_fraud&id=<?=$vo['buyer_id']?>" class="btn btn-sm btn-info" style="font-size:.6rem"><i class="fas fa-shield-alt"></i></a>
            <a href="?tab=fraud&action=block_buy&id=<?=$vo['buyer_id']?>" class="btn btn-sm btn-danger" style="font-size:.6rem" onclick="return confirm('منع الشراء؟')"><i class="fas fa-ban"></i></a></div>
        </div>
        <div style="background:var(--bg3,#161b2e);border-radius:12px;padding:14px;border:1px solid rgba(139,92,246,.1)">
            <div style="font-size:.7rem;color:#8b5cf6;font-weight:700;margin-bottom:6px">🏪 البائع — <a href="customers.php?action=view&id=<?=$vo['seller_id']?>">#<?=$vo['seller_id']?></a></div>
            <div style="font-weight:800;margin-bottom:4px"><?=htmlspecialchars($vo['sname']?:$vo['suser'])?></div>
            <div style="font-size:.75rem;color:#8b5cf6">ضمان: <?=number_format($vo['seller_escrow'],4)?> $</div>
            <div style="font-size:.75rem;color:#8b5cf6">يستلم: <?=number_format($vo['seller_payout'],4)?> $</div>
            <?php if($sellerRisk): $srl=$sellerRisk['risk_level']??'safe'; ?><div style="margin-top:6px"><span class="badge" style="background:<?=$rlC[$srl]?>22;color:<?=$rlC[$srl]?>"><?=$sellerRisk['risk_score']?> — <?=$srl?></span></div><?php endif; ?>
            <div style="margin-top:6px;display:flex;gap:3px"><a href="?tab=fraud&action=view_fraud&id=<?=$vo['seller_id']?>" class="btn btn-sm btn-info" style="font-size:.6rem"><i class="fas fa-shield-alt"></i></a>
            <a href="?tab=fraud&action=block_sell&id=<?=$vo['seller_id']?>" class="btn btn-sm btn-danger" style="font-size:.6rem" onclick="return confirm('منع البيع؟')"><i class="fas fa-ban"></i></a></div>
        </div>
        <div style="background:var(--bg3,#161b2e);border-radius:12px;padding:14px;border:1px solid rgba(245,166,35,.1)">
            <div style="font-size:.7rem;color:#f5a623;font-weight:700;margin-bottom:6px">💰 الأموال</div>
            <div style="font-size:.75rem;color:#8895a7;margin-bottom:3px">إجمالي: <strong style="color:#fff"><?=number_format($vo['buyer_total'],4)?> $</strong></div>
            <div style="font-size:.75rem;color:#8895a7;margin-bottom:3px">عمولة: <strong style="color:#f5a623"><?=number_format($vo['commission'],4)?> $ (<?=$vo['commission_payer']==='buyer'?'مشتري':'بائع'?>)</strong></div>
            <?php $partialReleased = (float)($vo['partial_released_amount']??0); $holdAmt = (float)($vo['remaining_hold_amount']??0); ?>
            <?php if($partialReleased): ?><div style="font-size:.75rem;color:#00e676;margin-bottom:3px">✅ محرر: <?=number_format($partialReleased,4)?> $</div><?php endif; ?>
            <?php if($holdAmt > 0): ?><div style="font-size:.75rem;color:#f5a623;margin-bottom:3px">🔒 معلق: <?=number_format($holdAmt,4)?> $</div>
            <div style="font-size:.65rem;color:#475569">تحويل: <?=$vo['hold_release_at']?date('m/d H:i',strtotime($vo['hold_release_at'])):'-'?></div><?php endif; ?>
            <div style="font-size:.65rem;color:#475569;margin-top:4px">إنشاء: <?=date('Y/m/d H:i',strtotime($vo['created_at']))?></div>
        </div>
    </div>

    <!-- أزرار التحكم -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:1.5rem;padding:12px;background:var(--bg3,#161b2e);border-radius:10px">
        <span style="font-size:.7rem;color:#8895a7;font-weight:700;width:100%;margin-bottom:4px">⚡ إجراءات سريعة</span>
        <?php if(in_array($vo['status'], ['active','delivered','pending'])): ?>
        <a href="?action=admin_complete&id=<?=$id?>" class="btn btn-sm btn-success" onclick="return confirm('إتمام الطلب وتحويل المبلغ للبائع؟')"><i class="fas fa-check-double"></i> إتمام</a>
        <a href="?action=admin_cancel&id=<?=$id?>" class="btn btn-sm btn-danger" onclick="return confirm('إلغاء الطلب واسترجاع المبالغ؟')"><i class="fas fa-times"></i> إلغاء</a>
        <?php endif; ?>
        <?php if($holdAmt > 0): ?>
        <form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$id?>"><input type="hidden" name="hold_action" value="release_now"><button type="submit" class="btn btn-sm btn-success" onclick="return confirm('تحويل المبلغ المعلق الآن؟')"><i class="fas fa-unlock"></i> تحويل المعلق</button></form>
        <form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$id?>"><input type="hidden" name="hold_action" value="extend"><input type="hidden" name="hours" value="48"><button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('تمديد 48 ساعة؟')"><i class="fas fa-clock"></i> تمديد</button></form>
        <form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$id?>"><input type="hidden" name="hold_action" value="cancel"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('إرجاع المبلغ المعلق للمشتري؟')"><i class="fas fa-undo"></i> إرجاع للمشتري</button></form>
        <?php endif; ?>
        <?php if($vo['status']==='disputed'): ?>
        <a href="?tab=disputes&action=view_dispute&id=<?=$id?>" class="btn btn-sm btn-warning"><i class="fas fa-gavel"></i> إدارة النزاع</a>
        <?php endif; ?>
    </div>

    <!-- بيانات التسليم -->
    <?php if($vo['delivery_data']??''): ?>
    <div style="background:#080c1a;border:1px solid rgba(139,92,246,.1);border-radius:10px;padding:12px;margin-bottom:1rem">
        <div style="font-size:.7rem;color:#8b5cf6;font-weight:700;margin-bottom:4px">📦 بيانات التسليم (<?=['account'=>'حساب','code'=>'كود','text'=>'نص','other'=>'أخرى'][$vo['delivery_type']??'']??'—'?>)</div>
        <pre style="white-space:pre-wrap;color:#c8d6e5;margin:0;font-size:.8rem"><?=htmlspecialchars($vo['delivery_data'])?></pre>
        <div style="font-size:.65rem;color:#475569;margin-top:6px">
            تسليم: <?=$vo['delivered_at']?date('Y/m/d H:i',strtotime($vo['delivered_at'])):'—'?>
            <?php if($vo['buyer_viewed_delivery']??false): ?> · <span style="color:#00e676">👁 شاهد <?=$vo['buyer_viewed_at']?date('H:i',strtotime($vo['buyer_viewed_at'])):''?></span><?php if($vo['buyer_viewed_ip']??''): ?> · IP: <?=htmlspecialchars($vo['buyer_viewed_ip'])?><?php endif; ?><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- تأكيدات الأمان -->
    <?php if(($vo['confirmed_email_change']??0)||($vo['confirmed_password_change']??0)||($vo['confirmed_recovery_removed']??0)): ?>
    <div style="display:flex;gap:6px;margin-bottom:1rem;flex-wrap:wrap">
        <?php if($vo['confirmed_email_change']): ?><span class="badge" style="background:#00d4ff22;color:#00d4ff">✓ البريد</span><?php endif; ?>
        <?php if($vo['confirmed_password_change']): ?><span class="badge" style="background:#00d4ff22;color:#00d4ff">✓ كلمة المرور</span><?php endif; ?>
        <?php if($vo['confirmed_recovery_removed']): ?><span class="badge" style="background:#00d4ff22;color:#00d4ff">✓ الاسترجاع</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- المحادثة -->
    <div style="background:#080c1a;border:1px solid rgba(255,255,255,.05);border-radius:12px;max-height:350px;overflow-y:auto;padding:14px;margin-bottom:1rem">
        <div style="font-size:.7rem;color:#1e6fff;font-weight:700;margin-bottom:8px">💬 المحادثة (<?=count($msgs)?>)</div>
        <?php if(empty($msgs)): ?><div style="text-align:center;color:#8895a7;padding:10px">لا توجد رسائل</div>
        <?php else: foreach($msgs as $m): $isSystem=$m['sender_id']==0;$isBuyer=$m['sender_id']==$vo['buyer_id']; ?>
        <div style="margin-bottom:8px;<?=$isSystem?'text-align:center':($isBuyer?'text-align:right':'text-align:left')?>">
            <?php if($isSystem): ?><div style="display:inline-block;background:rgba(139,92,246,.1);border:1px solid rgba(139,92,246,.15);border-radius:10px;padding:6px 12px;font-size:.78rem;color:#8b5cf6"><?=nl2br(htmlspecialchars($m['message']))?></div>
            <?php else: ?><div style="font-size:.6rem;color:#8895a7;margin-bottom:2px"><?=htmlspecialchars($m['full_name']?:$m['username']?:'?')?> · <?=date('H:i',strtotime($m['created_at']))?></div>
            <div style="display:inline-block;background:<?=$isBuyer?'rgba(0,212,170,.08)':'rgba(30,111,255,.08)'?>;border:1px solid <?=$isBuyer?'rgba(0,212,170,.12)':'rgba(30,111,255,.12)'?>;border-radius:10px;padding:6px 12px;font-size:.82rem;max-width:80%;text-align:right"><?=nl2br(htmlspecialchars($m['message']))?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- السجل المالي -->
    <?php if($walletTx): ?>
    <div style="background:#080c1a;border:1px solid rgba(0,212,170,.1);border-radius:10px;padding:10px;margin-bottom:1rem">
        <div style="font-size:.7rem;color:#00d4aa;font-weight:700;margin-bottom:6px">💳 السجل المالي</div>
        <?php foreach($walletTx as $tx): ?>
        <div style="font-size:.7rem;color:#8895a7;margin-bottom:3px;display:flex;gap:6px"><span style="color:#475569;min-width:75px"><?=date('m/d H:i',strtotime($tx['created_at']))?></span>
        <span style="color:<?=$tx['type']==='credit'?'#00e676':'#ff4455'?>;font-weight:700;min-width:50px"><?=$tx['type']==='credit'?'+':'-'?><?=number_format($tx['amount'],4)?>$</span>
        <span>UID:<?=$tx['user_id']?></span><span style="color:#475569"><?=htmlspecialchars(mb_substr($tx['description'],0,50))?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- سجل الأحداث -->
    <?php if($orderLogs): ?>
    <div style="background:#080c1a;border:1px solid rgba(245,166,35,.1);border-radius:10px;padding:10px">
        <div style="font-size:.7rem;color:#f5a623;font-weight:700;margin-bottom:6px">📋 سجل الأحداث (<?=count($orderLogs)?>)</div>
        <?php foreach($orderLogs as $log): $actC=['created'=>'#1e6fff','active'=>'#00d4ff','delivered'=>'#8b5cf6','viewed_delivery'=>'#f5a623','confirmed'=>'#00e676','confirmed_partial'=>'#00e676','cancelled'=>'#ff4455','disputed'=>'#ff6677','auto_completed'=>'#00d4aa','hold_released'=>'#00e676','cancel_requested'=>'#f5a623','dispute_resolved'=>'#f5a623','hold_released_early'=>'#00e676']; ?>
        <div style="font-size:.68rem;color:#8895a7;margin-bottom:3px;display:flex;gap:6px;align-items:center">
            <span style="color:#475569;min-width:75px;flex-shrink:0"><?=date('m/d H:i',strtotime($log['created_at']))?></span>
            <span style="color:<?=$actC[$log['action']]??'#8895a7'?>;font-weight:700;min-width:100px"><?=htmlspecialchars($log['action'])?></span>
            <span><?=htmlspecialchars($log['full_name']??($log['user_id']==0?'⚙️':'#'.$log['user_id']))?></span>
            <?php if($log['ip_address']): ?><span style="color:#374151;font-size:.6rem">IP:<?=htmlspecialchars($log['ip_address'])?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; endif; ?>

<?php elseif ($tab === 'chats'): ?>
<!-- ═══ المحادثات ═══ -->
<?php
$chatFilter = $_GET['chat_status'] ?? '';
$chatWhere = "1=1";
$chatParams = [];
if ($chatFilter === 'active') { $chatWhere .= " AND o.status='active'"; }
elseif ($chatFilter === 'completed') { $chatWhere .= " AND o.status='completed'"; }
elseif ($chatFilter === 'cancelled') { $chatWhere .= " AND o.status='cancelled'"; }

$chatOrders = $pdo->prepare("
    SELECT o.id, o.status, o.created_at, o.completed_at, o.cancelled_at, o.chat_expires_at,
           s.title as svc_title,
           buyer.full_name as bname, buyer.username as buser, buyer.id as buyer_id,
           seller.full_name as sname, seller.username as suser, seller.id as seller_id,
           (SELECT COUNT(*) FROM p2p_messages WHERE order_id=o.id) as msg_count,
           (SELECT MAX(created_at) FROM p2p_messages WHERE order_id=o.id) as last_msg_at
    FROM p2p_orders o
    JOIN p2p_services s ON s.id=o.service_id
    JOIN users buyer ON buyer.id=o.buyer_id
    JOIN users seller ON seller.id=o.seller_id
    WHERE $chatWhere AND (SELECT COUNT(*) FROM p2p_messages WHERE order_id=o.id) > 0
    ORDER BY last_msg_at DESC
    LIMIT 100
");
$chatOrders->execute($chatParams);
$chatOrders = $chatOrders->fetchAll();
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3><i class="fas fa-comments" style="color:#8b5cf6"></i> محادثات P2P (<?=count($chatOrders)?>)</h3>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <a href="?tab=chats" class="btn btn-sm <?=!$chatFilter?'btn-primary':'btn-secondary'?>">الكل</a>
            <a href="?tab=chats&chat_status=active" class="btn btn-sm <?=$chatFilter==='active'?'btn-info':'btn-secondary'?>">نشطة</a>
            <a href="?tab=chats&chat_status=completed" class="btn btn-sm <?=$chatFilter==='completed'?'btn-success':'btn-secondary'?>">مكتملة</a>
            <a href="?tab=chats&chat_status=cancelled" class="btn btn-sm <?=$chatFilter==='cancelled'?'btn-danger':'btn-secondary'?>">ملغية</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>الطلب</th><th>الخدمة</th><th>المشتري</th><th>البائع</th><th>الرسائل</th><th>آخر رسالة</th><th>الحالة</th><th>عرض</th>
            </tr></thead>
            <tbody>
            <?php foreach($chatOrders as $co):
                $stColor = ['pending'=>'#f5a623','active'=>'#00d4ff','delivered'=>'#8b5cf6','completed'=>'#00e676','cancelled'=>'#ff4455','disputed'=>'#ff6677'];
                $stLabel = ['pending'=>'انتظار','active'=>'نشطة','delivered'=>'📦 مسلّمة','completed'=>'مكتملة','cancelled'=>'ملغية','disputed'=>'نزاع'];
            ?>
            <tr>
                <td><strong>#<?=$co['id']?></strong></td>
                <td><?=htmlspecialchars(mb_substr($co['svc_title'],0,25))?></td>
                <td><a href="customers.php?action=view&id=<?=$co['buyer_id']?>" style="color:#00d4aa"><?=htmlspecialchars($co['bname'] ?: $co['buser'])?></a></td>
                <td><a href="customers.php?action=view&id=<?=$co['seller_id']?>" style="color:#8b5cf6"><?=htmlspecialchars($co['sname'] ?: $co['suser'])?></a></td>
                <td><span class="badge badge-secondary" style="font-size:.8rem"><?=$co['msg_count']?> 💬</span></td>
                <td><small><?=$co['last_msg_at'] ? date('m/d H:i', strtotime($co['last_msg_at'])) : '—'?></small></td>
                <td><span class="badge" style="background:<?=$stColor[$co['status']]??'#8895a7'?>22;color:<?=$stColor[$co['status']]??'#8895a7'?>"><?=$stLabel[$co['status']]??$co['status']?></span></td>
                <td>
                    <a href="?tab=chats&action=view_chat&id=<?=$co['id']?>" class="btn btn-sm" style="background:#8b5cf6;color:#fff" title="فتح المحادثة"><i class="fas fa-eye"></i> فتح</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($chatOrders)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#8895a7">لا توجد محادثات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// ── عرض محادثة كاملة ──
if ($action === 'view_chat' && $id):
    $vo = $pdo->prepare("SELECT o.*, s.title as svc_title, s.description as svc_desc, s.price as svc_price,
        buyer.full_name as bname, buyer.username as buser, buyer.id as buyer_id, buyer.profile_avatar as b_avatar, buyer.google_avatar as b_gavatar,
        seller.full_name as sname, seller.username as suser, seller.id as seller_id, seller.profile_avatar as s_avatar, seller.google_avatar as s_gavatar
        FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id
        JOIN users buyer ON buyer.id=o.buyer_id JOIN users seller ON seller.id=o.seller_id
        WHERE o.id=?");
    $vo->execute([$id]); $vo=$vo->fetch();
    if ($vo):
    $msgs = $pdo->prepare("SELECT m.*, u.full_name, u.username FROM p2p_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.order_id=? ORDER BY m.created_at ASC");
    $msgs->execute([$id]); $msgs=$msgs->fetchAll();

    $stColor = ['pending'=>'#f5a623','active'=>'#00d4ff','delivered'=>'#8b5cf6','completed'=>'#00e676','cancelled'=>'#ff4455','disputed'=>'#ff6677'];
    $stLabel = ['pending'=>'قيد الانتظار','active'=>'نشطة','delivered'=>'📦 مسلّمة','completed'=>'مكتملة','cancelled'=>'ملغية','disputed'=>'نزاع'];
?>
<div class="card mt-2" style="border:1.5px solid rgba(139,92,246,.35)">
    <!-- رأس المحادثة -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <a href="?tab=chats<?=$chatFilter?'&chat_status='.$chatFilter:''?>" style="width:34px;height:34px;background:var(--bg3,#161b2e);border:1px solid rgba(255,255,255,.08);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#8895a7;text-decoration:none"><i class="fas fa-arrow-right" style="font-size:12px"></i></a>
            <div>
                <h3 style="margin:0;font-size:1rem"><i class="fas fa-comments" style="color:#8b5cf6"></i> محادثة الطلب #<?=$id?></h3>
                <div style="font-size:.8rem;color:#8895a7"><?=htmlspecialchars($vo['svc_title'])?> — <?=number_format($vo['price'],4)?> $</div>
            </div>
        </div>
        <span class="badge" style="background:<?=$stColor[$vo['status']]??'#8895a7'?>22;color:<?=$stColor[$vo['status']]??'#8895a7'?>;font-size:.8rem;padding:6px 14px"><?=$stLabel[$vo['status']]??$vo['status']?></span>
    </div>

    <!-- معلومات الطرفين -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1.25rem">
        <div style="background:rgba(0,212,170,.05);border:1px solid rgba(0,212,170,.15);border-radius:12px;padding:14px">
            <div style="font-size:.7rem;color:#00d4aa;font-weight:700;margin-bottom:6px">🛒 المشتري</div>
            <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($vo['bname'] ?: $vo['buser'])?></div>
            <div style="font-size:.78rem;color:#8895a7;margin-top:4px">حساب #<?=str_pad($vo['buyer_id'],6,'0',STR_PAD_LEFT)?> · دفع <?=number_format($vo['buyer_total'],4)?> $</div>
        </div>
        <div style="background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.15);border-radius:12px;padding:14px">
            <div style="font-size:.7rem;color:#8b5cf6;font-weight:700;margin-bottom:6px">🏪 البائع</div>
            <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($vo['sname'] ?: $vo['suser'])?></div>
            <div style="font-size:.78rem;color:#8895a7;margin-top:4px">حساب #<?=str_pad($vo['seller_id'],6,'0',STR_PAD_LEFT)?> · ضمان <?=number_format($vo['seller_escrow'],4)?> $ · يستلم <?=number_format($vo['seller_payout'],4)?> $</div>
        </div>
    </div>

    <!-- تفاصيل مالية -->
    <div style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap">
        <div style="background:var(--bg3,#161b2e);border-radius:8px;padding:8px 14px;font-size:.78rem">
            <span style="color:#8895a7">سعر الخدمة:</span> <strong style="color:#00d4aa"><?=number_format($vo['price'],4)?> $</strong>
        </div>
        <div style="background:var(--bg3,#161b2e);border-radius:8px;padding:8px 14px;font-size:.78rem">
            <span style="color:#8895a7">العمولة:</span> <strong style="color:#f5a623"><?=number_format($vo['commission'],4)?> $</strong>
            <small style="color:#8895a7">(<?=$vo['commission_payer']==='buyer'?'مشتري':'بائع'?>)</small>
        </div>
        <div style="background:var(--bg3,#161b2e);border-radius:8px;padding:8px 14px;font-size:.78rem">
            <span style="color:#8895a7">الرسائل:</span> <strong><?=count($msgs)?></strong>
        </div>
        <div style="background:var(--bg3,#161b2e);border-radius:8px;padding:8px 14px;font-size:.78rem">
            <span style="color:#8895a7">الإنشاء:</span> <strong><?=date('Y/m/d H:i', strtotime($vo['created_at']))?></strong>
        </div>
    </div>

    <!-- المحادثة -->
    <div style="background:#080c1a;border:1px solid rgba(255,255,255,.06);border-radius:14px;max-height:500px;overflow-y:auto;padding:16px" id="chatBox">
        <?php if(empty($msgs)): ?>
        <div style="text-align:center;color:#8895a7;padding:30px"><i class="fas fa-comment-slash" style="font-size:2rem;opacity:.3;display:block;margin-bottom:10px"></i>لا توجد رسائل</div>
        <?php else: foreach($msgs as $mi => $m):
            $isSystem = $m['sender_id'] == 0;
            $isBuyer  = $m['sender_id'] == $vo['buyer_id'];
            $isSeller = $m['sender_id'] == $vo['seller_id'];
            $senderLabel = $isSystem ? 'النظام' : ($isBuyer ? '🛒 '.$vo['bname'] : '🏪 '.$vo['sname']);
            $bgColor = $isSystem ? 'rgba(139,92,246,.08)' : ($isBuyer ? 'rgba(0,212,170,.08)' : 'rgba(30,111,255,.08)');
            $borderColor = $isSystem ? 'rgba(139,92,246,.18)' : ($isBuyer ? 'rgba(0,212,170,.18)' : 'rgba(30,111,255,.18)');
            $textColor = $isSystem ? '#8b5cf6' : ($isBuyer ? '#00d4aa' : '#1e6fff');
        ?>
        <div style="margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                <span style="font-size:.7rem;font-weight:800;color:<?=$textColor?>"><?=htmlspecialchars($senderLabel)?></span>
                <span style="font-size:.6rem;color:#5a6880"><?=date('Y/m/d H:i:s', strtotime($m['created_at']))?></span>
            </div>
            <div style="background:<?=$bgColor?>;border:1px solid <?=$borderColor?>;border-radius:12px;padding:10px 14px;font-size:.85rem;line-height:1.8"><?=nl2br(htmlspecialchars($m['message']))?></div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- إجراءات الأدمن -->
    <?php if(in_array($vo['status'], ['active','pending'])): ?>
    <div style="margin-top:1rem;display:flex;gap:8px">
        <a href="?action=admin_complete&id=<?=$vo['id']?>" class="btn btn-success" onclick="return confirm('إتمام الطلب وتحويل المبلغ للبائع؟')"><i class="fas fa-check-circle"></i> إتمام الطلب</a>
        <a href="?action=admin_cancel&id=<?=$vo['id']?>" class="btn btn-danger" onclick="return confirm('إلغاء الطلب واسترداد المبالغ؟')"><i class="fas fa-times-circle"></i> إلغاء الطلب</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; endif; ?>

<?php elseif ($tab === 'services'): ?>
<!-- ═══ الخدمات + نظام المراجعة ═══ -->
<?php
// حفظ تعديل إداري على خدمة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_edit_svc']) && $IS_ADMIN) {
    $eId = (int)$_POST['svc_id'];
    $eTitle = trim($_POST['svc_title'] ?? '');
    $eDesc = trim($_POST['svc_desc'] ?? '');
    $ePrice = (float)($_POST['svc_price'] ?? 0);
    $eQty = max(1, (int)($_POST['svc_qty'] ?? 1));
    $eCat = (int)($_POST['svc_cat'] ?? 0);
    $eComm = in_array($_POST['svc_comm']??'', ['buyer','seller']) ? $_POST['svc_comm'] : 'buyer';
    $eStatus = in_array($_POST['svc_status']??'', ['active','paused','suspended','pending_review','rejected']) ? $_POST['svc_status'] : 'active';
    if ($eTitle && $ePrice > 0) {
        $pdo->prepare("UPDATE p2p_services SET title=?,description=?,price=?,quantity=?,category_id=?,commission_payer=?,status=?,pending_changes=NULL,reject_reason=NULL WHERE id=?")
            ->execute([$eTitle, $eDesc, $ePrice, $eQty, $eCat?:null, $eComm, $eStatus, $eId]);
        flashMessage('success', 'تم تعديل الخدمة #'.$eId);
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=services');
}

$sFilter = $_GET['svc_status'] ?? '';
$sWhere = "1=1";
if ($sFilter && in_array($sFilter, ['active','paused','pending_review','rejected','suspended'])) $sWhere .= " AND s.status='$sFilter'";
$services = $pdo->query("
    SELECT s.*, u.username, u.full_name as seller_name, COALESCE(pc.name,'—') as cat_name,
           (SELECT COUNT(*) FROM p2p_orders WHERE service_id=s.id) as order_count
    FROM p2p_services s JOIN users u ON u.id=s.seller_id
    LEFT JOIN p2p_categories pc ON pc.id=s.category_id
    WHERE $sWhere
    ORDER BY FIELD(s.status,'pending_review','active','paused','rejected','suspended','sold_out'), s.created_at DESC LIMIT 200
")->fetchAll();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM p2p_services WHERE status='pending_review'")->fetchColumn();
$pendingEdits = (int)$pdo->query("SELECT COUNT(*) FROM p2p_services WHERE pending_changes IS NOT NULL AND pending_changes != ''")->fetchColumn();
$allCats = []; try { $allCats = $pdo->query("SELECT id,name FROM p2p_categories ORDER BY sort_order")->fetchAll(); } catch(Exception $e) {}

// هل نعدّل خدمة؟
$editSvc = null;
if ($action === 'edit_svc' && $id) {
    try { $es = $pdo->prepare("SELECT * FROM p2p_services WHERE id=?"); $es->execute([$id]); $editSvc = $es->fetch(); } catch(Exception $e) {}
}
?>

<?php if($editSvc): ?>
<div class="card mb-2" style="border:2px solid rgba(30,111,255,.3)">
    <h3 style="margin-bottom:1rem"><i class="fas fa-edit" style="color:var(--primary)"></i> تعديل الخدمة #<?=$editSvc['id']?> — <?=htmlspecialchars($editSvc['title'])?></h3>
    <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="admin_edit_svc" value="1">
        <input type="hidden" name="svc_id" value="<?=$editSvc['id']?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div class="form-group" style="margin:0">
                <label>اسم الخدمة *</label>
                <input type="text" name="svc_title" class="form-control" value="<?=htmlspecialchars($editSvc['title'])?>" required>
            </div>
            <div class="form-group" style="margin:0">
                <label>القسم</label>
                <select name="svc_cat" class="form-control">
                    <option value="">— بدون —</option>
                    <?php foreach($allCats as $ac): ?>
                    <option value="<?=$ac['id']?>" <?=($editSvc['category_id']??0)==$ac['id']?'selected':''?>><?=htmlspecialchars($ac['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>السعر ($)</label>
                <input type="number" name="svc_price" class="form-control" value="<?=$editSvc['price']?>" step="any" min="0.01" required>
            </div>
            <div class="form-group" style="margin:0">
                <label>الكمية</label>
                <input type="number" name="svc_qty" class="form-control" value="<?=$editSvc['quantity']?>" min="1">
            </div>
            <div class="form-group" style="margin:0">
                <label>من يتحمل العمولة</label>
                <select name="svc_comm" class="form-control">
                    <option value="buyer" <?=$editSvc['commission_payer']==='buyer'?'selected':''?>>المشتري</option>
                    <option value="seller" <?=$editSvc['commission_payer']==='seller'?'selected':''?>>البائع</option>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>الحالة</label>
                <select name="svc_status" class="form-control">
                    <option value="active" <?=$editSvc['status']==='active'?'selected':''?>>✅ نشط</option>
                    <option value="paused" <?=$editSvc['status']==='paused'?'selected':''?>>⏸ متوقف</option>
                    <option value="pending_review" <?=$editSvc['status']==='pending_review'?'selected':''?>>⏳ معلق</option>
                    <option value="rejected" <?=$editSvc['status']==='rejected'?'selected':''?>>❌ مرفوض</option>
                    <option value="suspended" <?=$editSvc['status']==='suspended'?'selected':''?>>🚫 معلّق</option>
                </select>
            </div>
        </div>
        <div class="form-group" style="margin:0 0 12px">
            <label>الوصف</label>
            <textarea name="svc_desc" class="form-control" rows="3"><?=htmlspecialchars($editSvc['description']??'')?></textarea>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ التعديل</button>
            <a href="?tab=services<?=$sFilter?"&svc_status=$sFilter":''?>" class="btn btn-secondary">إلغاء</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:8px">
        <h3><i class="fas fa-store"></i> خدمات P2P (<?=count($services)?>)
            <?php if($pendingCount): ?><span class="badge" style="background:#f5a623;color:#000;margin-right:6px"><?=$pendingCount?> بانتظار الموافقة</span><?php endif; ?>
            <?php if($pendingEdits): ?><span class="badge" style="background:#00d4ff;color:#000;margin-right:6px"><?=$pendingEdits?> تعديلات معلقة</span><?php endif; ?>
        </h3>
        <div style="display:flex;gap:4px;flex-wrap:wrap">
            <a href="?tab=services" class="btn btn-sm <?=!$sFilter?'btn-primary':'btn-secondary'?>">الكل</a>
            <a href="?tab=services&svc_status=pending_review" class="btn btn-sm <?=$sFilter==='pending_review'?'btn-warning':'btn-secondary'?>">⏳ معلقة</a>
            <a href="?tab=services&svc_status=active" class="btn btn-sm <?=$sFilter==='active'?'btn-success':'btn-secondary'?>">نشطة</a>
            <a href="?tab=services&svc_status=rejected" class="btn btn-sm <?=$sFilter==='rejected'?'btn-danger':'btn-secondary'?>">مرفوضة</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>#</th><th>الخدمة</th><th>القسم</th><th>البائع</th><th>السعر</th><th>الحالة</th><th>تعديلات</th><th>إجراءات</th>
            </tr></thead>
            <tbody>
            <?php foreach($services as $s):
                $stColor=['active'=>'#00e676','paused'=>'#f5a623','sold_out'=>'#8895a7','suspended'=>'#ff4455','pending_review'=>'#00d4ff','rejected'=>'#ff4455'];
                $stLabel=['active'=>'نشط','paused'=>'متوقف','sold_out'=>'نفد','suspended'=>'معلق','pending_review'=>'⏳ بانتظار الموافقة','rejected'=>'❌ مرفوض'];
                $hasPending = !empty($s['pending_changes']);
            ?>
            <tr style="<?=$s['status']==='pending_review'?'background:rgba(0,212,255,.03)':($hasPending?'background:rgba(245,166,35,.03)':'')?>">
                <td><?=$s['id']?></td>
                <td>
                    <strong><?=htmlspecialchars(mb_substr($s['title'],0,28))?></strong>
                    <?php if($s['status']==='rejected' && $s['reject_reason']): ?>
                    <div style="font-size:.72rem;color:#ff4455;margin-top:2px">سبب: <?=htmlspecialchars($s['reject_reason'])?></div>
                    <?php endif; ?>
                </td>
                <td><small><?=htmlspecialchars($s['cat_name'])?></small></td>
                <td><a href="customers.php?action=view&id=<?=$s['seller_id']?>"><?=htmlspecialchars($s['seller_name'] ?: $s['username'])?></a></td>
                <td style="color:#00d4aa;font-weight:700"><?=number_format($s['price'],2)?> $</td>
                <td><span class="badge" style="background:<?=$stColor[$s['status']]??'#8895a7'?>22;color:<?=$stColor[$s['status']]??'#8895a7'?>"><?=$stLabel[$s['status']]??$s['status']?></span></td>
                <td>
                    <?php if($hasPending): ?>
                    <span class="badge" style="background:#f5a623;color:#000" title="<?=htmlspecialchars($s['pending_changes'])?>">📝 تعديل معلق</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="white-space:nowrap;display:flex;gap:3px;flex-wrap:wrap">
                    <a href="?tab=services&action=edit_svc&id=<?=$s['id']?><?=$sFilter?"&svc_status=$sFilter":''?>" class="btn btn-sm btn-info" title="تعديل"><i class="fas fa-edit"></i></a>
                    <?php if($s['status']==='pending_review'): ?>
                    <a href="?action=activate_service&id=<?=$s['id']?>" class="btn btn-sm btn-success" title="موافقة" onclick="return confirm('الموافقة؟')"><i class="fas fa-check"></i></a>
                    <a href="?action=reject_service&id=<?=$s['id']?>" class="btn btn-sm btn-danger" title="رفض" onclick="var r=prompt('سبب الرفض (اختياري):');if(r===null)return false;window.location='?action=reject_service&id=<?=$s['id']?>&reason='+encodeURIComponent(r);return false;"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                    <?php if($hasPending): ?>
                    <a href="?action=approve_edit&id=<?=$s['id']?>" class="btn btn-sm btn-info" title="قبول التعديل" onclick="return confirm('قبول التعديلات؟')"><i class="fas fa-check-double"></i></a>
                    <a href="?action=reject_edit&id=<?=$s['id']?>" class="btn btn-sm btn-warning" title="رفض التعديل" onclick="return confirm('رفض التعديلات؟')"><i class="fas fa-undo"></i></a>
                    <?php endif; ?>
                    <?php if($s['status']==='active'): ?>
                    <a href="?action=suspend_service&id=<?=$s['id']?>" class="btn btn-sm btn-danger" title="تعليق" onclick="return confirm('تعليق؟')"><i class="fas fa-ban"></i></a>
                    <?php elseif(in_array($s['status'],['paused','suspended','rejected'])): ?>
                    <a href="?action=activate_service&id=<?=$s['id']?>" class="btn btn-sm btn-success" title="تفعيل"><i class="fas fa-check"></i></a>
                    <?php endif; ?>
                    <a href="?action=delete_service&id=<?=$s['id']?>" class="btn btn-sm btn-danger" title="حذف" onclick="return confirm('حذف نهائياً؟')"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($services)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#8895a7">لا توجد خدمات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'categories'): ?>
<!-- ═══ إدارة الأقسام ═══ -->
<?php
// حفظ قسم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cat']) && $IS_ADMIN) {
    $cId   = (int)($_POST['cat_id'] ?? 0);
    $cName = trim($_POST['cat_name'] ?? '');
    $cIcon = trim($_POST['cat_icon'] ?? 'category');
    $cColor= trim($_POST['cat_color'] ?? '#3b82f6');
    $cOrder= (int)($_POST['cat_order'] ?? 0);
    $cStatus=(int)($_POST['cat_status'] ?? 1);
    $cRisk = in_array($_POST['cat_risk']??'', ['low','medium','high']) ? $_POST['cat_risk'] : 'medium';
    $cAutoH = (int)($_POST['cat_auto_hours'] ?? 24);
    $cPartial = (int)($_POST['cat_partial_pct'] ?? 100);
    $cHoldH = (int)($_POST['cat_hold_hours'] ?? 0);
    $cSecConfirm = (int)($_POST['cat_sec_confirm'] ?? 0);
    $cMinDel = (int)($_POST['cat_min_del'] ?? 5);
    $cEscrow = (int)($_POST['cat_require_escrow'] ?? 1);
    // رفع صورة القسم
    $cImage = null;
    if (!empty($_FILES['cat_image']['tmp_name']) && $_FILES['cat_image']['error'] === UPLOAD_ERR_OK) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $_FILES['cat_image']['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        if (in_array($mime, $allowed)) {
            $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $dir  = dirname(__DIR__) . '/uploads/p2p_cats/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            // حذف الصورة القديمة
            if ($cId) {
                $old = $pdo->prepare("SELECT image FROM p2p_categories WHERE id=?"); $old->execute([$cId]); $old=$old->fetchColumn();
                if ($old && file_exists(dirname(__DIR__).'/'.$old)) @unlink(dirname(__DIR__).'/'.$old);
            }
            $fname = 'p2pcat_'.$cId.'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['cat_image']['tmp_name'], $dir.$fname)) {
                $cImage = 'uploads/p2p_cats/'.$fname;
            }
        }
    }
    if ($cName) {
        if ($cId) {
            $sql = "UPDATE p2p_categories SET name=?,icon=?,color=?,sort_order=?,status=?,risk_level=?,auto_complete_hours=?,partial_release_pct=?,hold_hours=?,require_security_confirm=?,min_delivery_length=?,require_seller_escrow=?" . ($cImage!==null?",image=?":'') . " WHERE id=?";
            $params = [$cName,$cIcon,$cColor,$cOrder,$cStatus,$cRisk,$cAutoH,$cPartial,$cHoldH,$cSecConfirm,$cMinDel,$cEscrow];
            if ($cImage!==null) $params[] = $cImage;
            $params[] = $cId;
            $pdo->prepare($sql)->execute($params);
            flashMessage('success', 'تم تعديل القسم');
        } else {
            $pdo->prepare("INSERT INTO p2p_categories (name,icon,color,sort_order,status,risk_level,auto_complete_hours,partial_release_pct,hold_hours,require_security_confirm,min_delivery_length,require_seller_escrow) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$cName,$cIcon,$cColor,$cOrder,$cStatus,$cRisk,$cAutoH,$cPartial,$cHoldH,$cSecConfirm,$cMinDel,$cEscrow]);
            $newCatId = (int)$pdo->lastInsertId();
            // رفع الصورة للقسم الجديد
            if ($cImage!==null) $pdo->prepare("UPDATE p2p_categories SET image=? WHERE id=?")->execute([$cImage,$newCatId]);
            flashMessage('success', 'تم إضافة القسم');
        }
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=categories');
}
// حذف قسم
if ($action === 'delete_cat' && $id && $IS_ADMIN) {
    $pdo->prepare("DELETE FROM p2p_categories WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف القسم');
    redirect(SITE_URL . '/admin/p2p.php?tab=categories');
}

try { $pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_categories` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `icon` VARCHAR(50) DEFAULT 'category', `color` VARCHAR(20) DEFAULT '#3b82f6', `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE p2p_categories ADD COLUMN IF NOT EXISTS `image` VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM p2p_services WHERE category_id=c.id) as svc_count FROM p2p_categories c ORDER BY c.sort_order, c.id")->fetchAll();
$editCat = null;
if ($action === 'edit_cat' && $id) { foreach($cats as $cc) { if ($cc['id']==$id) $editCat=$cc; } }
?>
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-folder-open" style="color:#8b5cf6"></i> إدارة أقسام P2P (<?=count($cats)?>)</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>الأيقونة</th><th>الاسم</th><th>الخطورة</th><th>تأكيد تلقائي</th><th>ضمان جزئي</th><th>تأكيد أمني</th><th>ضمان البائع</th><th>الخدمات</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach($cats as $ct): ?>
            <tr>
                <td><?=$ct['id']?></td>
                <td>
                  <?php if(!empty($ct['image'])): ?>
                  <img src="<?=SITE_URL?>/<?=htmlspecialchars($ct['image'])?>" style="width:36px;height:36px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                  <?php else: ?>
                  <span class="material-symbols-outlined" style="color:<?=htmlspecialchars($ct['color'])?>"><?=htmlspecialchars($ct['icon'])?></span>
                  <?php endif; ?>
                </td>
                <td><strong><?=htmlspecialchars($ct['name'])?></strong></td>
                <td><?php $rl=$ct['risk_level']??'medium';$rc=['low'=>'#00e676','medium'=>'#f5a623','high'=>'#ff4455'];$rlb=['low'=>'منخفض','medium'=>'متوسط','high'=>'عالي'];?><span class="badge" style="background:<?=$rc[$rl]?>22;color:<?=$rc[$rl]?>"><?=$rlb[$rl]??$rl?></span></td>
                <td><small><?=$ct['auto_complete_hours']??24?>س</small></td>
                <td><small><?=($ct['partial_release_pct']??100)?>% <?=($ct['hold_hours']??0)>0?'+ '.($ct['hold_hours']).'س حجز':''?></small></td>
                <td><?=($ct['require_security_confirm']??0)?'<span class="badge" style="background:#ff445522;color:#ff4455">🔒</span>':'—'?></td>
                <td><?=($ct['require_seller_escrow']??1)?'<span class="badge" style="background:#f5a62322;color:#f5a623">🔒 مطلوب</span>':'<span class="badge" style="background:#00e67622;color:#00e676">❌ بدون</span>'?></td>
                <td><span class="badge badge-secondary"><?=$ct['svc_count']?></span></td>
                <td><?=$ct['status']?'<span class="badge" style="background:#00e67622;color:#00e676">مفعّل</span>':'<span class="badge" style="background:#ff445522;color:#ff4455">معطّل</span>'?></td>
                <td>
                    <a href="?tab=categories&action=edit_cat&id=<?=$ct['id']?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                    <a href="?tab=categories&action=delete_cat&id=<?=$ct['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف القسم؟ الخدمات المرتبطة لن تُحذف.')"><i class="fas fa-trash"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($cats)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#8895a7">لا توجد أقسام — أضف أقساماً من الفورم أدناه</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="border:1px solid rgba(139,92,246,.2)">
    <h3 style="margin-bottom:1rem"><i class="fas fa-<?=$editCat?'edit':'plus-circle'?>" style="color:#8b5cf6"></i> <?=$editCat?'تعديل قسم #'.$editCat['id']:'إضافة قسم جديد'?></h3>
    <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_cat" value="1">
        <input type="hidden" name="cat_id" value="<?=$editCat['id']??0?>">
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:.75rem;align-items:end">
            <div class="form-group" style="margin:0">
                <label>اسم القسم *</label>
                <input type="text" name="cat_name" class="form-control" value="<?=htmlspecialchars($editCat['name']??'')?>" required placeholder="مثال: تصميم وإبداع">
            </div>
            <div class="form-group" style="margin:0">
                <label>أيقونة</label>
                <input type="text" name="cat_icon" class="form-control" value="<?=htmlspecialchars($editCat['icon']??'category')?>" placeholder="palette" style="font-family:monospace">
            </div>
            <div class="form-group" style="margin:0">
                <label>اللون</label>
                <input type="color" name="cat_color" class="form-control" value="<?=htmlspecialchars($editCat['color']??'#3b82f6')?>" style="height:38px;padding:2px">
            </div>
            <div class="form-group" style="margin:0">
                <label>الترتيب</label>
                <input type="number" name="cat_order" class="form-control" value="<?=$editCat['sort_order']??0?>" min="0">
            </div>
            <div class="form-group" style="margin:0">
                <label>الحالة</label>
                <select name="cat_status" class="form-control">
                    <option value="1" <?=($editCat['status']??1)==1?'selected':''?>>مفعّل</option>
                    <option value="0" <?=($editCat['status']??1)==0?'selected':''?>>معطّل</option>
                </select>
            </div>
        </div>
        <div style="margin-top:1rem;padding:1rem;background:rgba(245,166,35,.03);border:1px solid rgba(245,166,35,.1);border-radius:10px">
            <h5 style="margin-bottom:.75rem;color:#f5a623"><i class="fas fa-shield-alt"></i> إعدادات الأمان لهذا القسم</h5>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem">
                <div class="form-group" style="margin:0"><label>مستوى الخطورة</label><select name="cat_risk" class="form-control"><option value="low" <?=($editCat['risk_level']??'medium')==='low'?'selected':''?>>🟢 منخفض</option><option value="medium" <?=($editCat['risk_level']??'medium')==='medium'?'selected':''?>>🟡 متوسط</option><option value="high" <?=($editCat['risk_level']??'medium')==='high'?'selected':''?>>🔴 عالي</option></select></div>
                <div class="form-group" style="margin:0"><label>مهلة التأكيد (ساعة)</label><input type="number" name="cat_auto_hours" class="form-control" value="<?=$editCat['auto_complete_hours']??24?>" min="1" max="168"></div>
                <div class="form-group" style="margin:0"><label>نسبة التحويل الأولي %</label><input type="number" name="cat_partial_pct" class="form-control" value="<?=$editCat['partial_release_pct']??100?>" min="50" max="100"><small style="color:#8895a7">100 = كامل فوري</small></div>
                <div class="form-group" style="margin:0"><label>مدة حجز المتبقي (ساعة)</label><input type="number" name="cat_hold_hours" class="form-control" value="<?=$editCat['hold_hours']??0?>" min="0" max="168"><small style="color:#8895a7">0 = بدون حجز</small></div>
                <div class="form-group" style="margin:0"><label>تأكيد تغيير البيانات</label><select name="cat_sec_confirm" class="form-control"><option value="0" <?=($editCat['require_security_confirm']??0)==0?'selected':''?>>غير مطلوب</option><option value="1" <?=($editCat['require_security_confirm']??0)==1?'selected':''?>>🔒 مطلوب</option></select></div>
                <div class="form-group" style="margin:0"><label>حد أدنى للتسليم (حرف)</label><input type="number" name="cat_min_del" class="form-control" value="<?=$editCat['min_delivery_length']??5?>" min="1" max="500"></div>
                <div class="form-group" style="margin:0"><label>ضمان البائع</label><select name="cat_require_escrow" class="form-control"><option value="1" <?=($editCat['require_seller_escrow']??1)==1?'selected':''?>>🔒 مطلوب (حجز مبلغ)</option><option value="0" <?=($editCat['require_seller_escrow']??1)==0?'selected':''?>>❌ غير مطلوب</option></select><small style="color:#8895a7">بدون ضمان = البائع لا يحتاج رصيد</small></div>
            </div>
        </div>
        <!-- صورة القسم -->
        <div style="margin-top:1rem;padding:1rem;background:rgba(30,111,255,.04);border:1px solid rgba(30,111,255,.1);border-radius:10px">
          <label style="font-weight:700;margin-bottom:.5rem;display:block"><i class="fas fa-image" style="color:var(--primary)"></i> صورة القسم (اختياري)</label>
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <?php if(!empty($editCat['image'])): ?>
            <img src="<?=SITE_URL?>/<?=htmlspecialchars($editCat['image'])?>" id="catImgPreview"
                 style="width:72px;height:72px;object-fit:cover;border-radius:12px;border:2px solid var(--border)">
            <?php else: ?>
            <div id="catImgPreview" style="width:72px;height:72px;background:var(--card2);border-radius:12px;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7">
              <i class="fas fa-image" style="font-size:1.5rem"></i>
            </div>
            <?php endif; ?>
            <div>
              <input type="file" name="cat_image" id="catImgInput" accept="image/*"
                     style="display:none" onchange="prevP2PCat(this)">
              <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('catImgInput').click()">
                <i class="fas fa-upload"></i> اختر صورة
              </button>
              <div style="font-size:.72rem;color:#8895a7;margin-top:5px">JPG / PNG / WebP — تحل محل الأيقونة في الواجهة</div>
            </div>
          </div>
        </div>
        <script>
        const _P2P_WEBP  = document.createElement('canvas').toDataURL('image/webp').startsWith('data:image/webp');
        const _P2P_FMT   = _P2P_WEBP ? 'image/webp' : 'image/jpeg';
        const _P2P_EXT   = _P2P_WEBP ? 'webp' : 'jpg';
        const _P2P_MAX_KB = 150 * 1024;

        function _p2pCompress(file) {
          return new Promise(resolve => {
            const reader = new FileReader();
            reader.onload = ev => {
              const img = new Image();
              img.onload = () => {
                const MAX = 1024;
                let w = img.width, h = img.height;
                if (w > MAX || h > MAX) {
                  if (w >= h) { h = Math.round(h * MAX / w); w = MAX; }
                  else        { w = Math.round(w * MAX / h); h = MAX; }
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                let quality = 0.80;
                const attempt = () => {
                  canvas.toBlob(blob => {
                    if (!blob) { resolve(null); return; }
                    if (blob.size <= _P2P_MAX_KB || quality <= 0.25) { resolve(blob); }
                    else { quality = Math.max(0.25, quality - 0.10); attempt(); }
                  }, _P2P_FMT, quality);
                };
                attempt();
              };
              img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
          });
        }

        async function prevP2PCat(inp) {
          if (!inp.files[0]) return;
          const blob = await _p2pCompress(inp.files[0]);
          if (!blob) return;
          const compressed = new File([blob], 'image.' + _P2P_EXT, { type: _P2P_FMT });
          const dt = new DataTransfer(); dt.items.add(compressed); inp.files = dt.files;
          const url = URL.createObjectURL(blob);
          const p = document.getElementById('catImgPreview');
          p.tagName === 'IMG' ? (p.src = url) : (p.innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:cover;border-radius:10px">`);
        }
        </script>
        <div style="margin-top:.75rem;display:flex;gap:8px">
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> <?=$editCat?'حفظ التعديل':'إضافة القسم'?></button>
            <?php if($editCat): ?><a href="?tab=categories" class="btn btn-secondary">إلغاء</a><?php endif; ?>
        </div>
    </form>
</div>

<?php elseif ($tab === 'reports'): ?>
<!-- ═══ البلاغات + أسباب الإبلاغ ═══ -->
<?php
// إنشاء الجداول
$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_report_reasons` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(200) NOT NULL, `freezes_seller` TINYINT(1) DEFAULT 0, `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_reports` (`id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `reporter_id` INT NOT NULL, `reason_id` INT NOT NULL, `details` TEXT, `email` VARCHAR(200), `phone` VARCHAR(50), `evidence` TEXT, `status` ENUM('pending','reviewing','resolved','rejected') DEFAULT 'pending', `admin_reply` TEXT, `reviewed_by` INT, `reviewed_at` DATETIME, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// إجراءات البلاغات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_report']) && $IS_ADMIN) {
    $rId = (int)$_POST['report_id'];
    $reply = trim($_POST['admin_reply'] ?? '');
    $newSt = in_array($_POST['report_status']??'', ['reviewing','resolved','rejected']) ? $_POST['report_status'] : 'reviewing';
    $pdo->prepare("UPDATE p2p_reports SET status=?, admin_reply=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")->execute([$newSt, $reply, $_SESSION['user_id'], $rId]);
    flashMessage('success', 'تم تحديث البلاغ');
    redirect(SITE_URL . '/admin/p2p.php?tab=reports');
}
// حفظ سبب إبلاغ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reason']) && $IS_ADMIN) {
    $rId = (int)($_POST['reason_id'] ?? 0);
    $rName = trim($_POST['reason_name'] ?? '');
    $rFreeze = (int)($_POST['reason_freeze'] ?? 0);
    $rOrder = (int)($_POST['reason_order'] ?? 0);
    $rStatus = (int)($_POST['reason_status'] ?? 1);
    if ($rName) {
        if ($rId) { $pdo->prepare("UPDATE p2p_report_reasons SET name=?,freezes_seller=?,sort_order=?,status=? WHERE id=?")->execute([$rName,$rFreeze,$rOrder,$rStatus,$rId]); }
        else { $pdo->prepare("INSERT INTO p2p_report_reasons (name,freezes_seller,sort_order,status) VALUES (?,?,?,?)")->execute([$rName,$rFreeze,$rOrder,$rStatus]); }
        flashMessage('success', 'تم الحفظ');
    }
    redirect(SITE_URL . '/admin/p2p.php?tab=reports');
}
if ($action === 'delete_reason' && $id && $IS_ADMIN) {
    $pdo->prepare("DELETE FROM p2p_report_reasons WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم الحذف');
    redirect(SITE_URL . '/admin/p2p.php?tab=reports');
}

$reports = $pdo->query("SELECT r.*, rr.name as reason_name, u.full_name as reporter_name, u.username as reporter_user,
    o.service_id, (SELECT title FROM p2p_services WHERE id=o.service_id) as svc_title
    FROM p2p_reports r LEFT JOIN p2p_report_reasons rr ON rr.id=r.reason_id
    LEFT JOIN users u ON u.id=r.reporter_id LEFT JOIN p2p_orders o ON o.id=r.order_id
    ORDER BY FIELD(r.status,'pending','reviewing','resolved','rejected'), r.created_at DESC LIMIT 100")->fetchAll();
$reasons = $pdo->query("SELECT * FROM p2p_report_reasons ORDER BY sort_order")->fetchAll();
$pendingReports = count(array_filter($reports, fn($r) => $r['status'] === 'pending'));
?>
<!-- البلاغات -->
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-flag" style="color:#ff4455"></i> بلاغات P2P (<?=count($reports)?>)
        <?php if($pendingReports): ?><span class="badge" style="background:#ff4455;color:#fff"><?=$pendingReports?> جديد</span><?php endif; ?>
    </h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>الطلب</th><th>المبلّغ</th><th>السبب</th><th>التفاصيل</th><th>الأدلة</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach($reports as $rp):
                $stC=['pending'=>'#f5a623','reviewing'=>'#00d4ff','resolved'=>'#00e676','rejected'=>'#ff4455'];
                $stL=['pending'=>'معلق','reviewing'=>'مراجعة','resolved'=>'تم الحل','rejected'=>'مرفوض'];
            ?>
            <tr>
                <td><?=$rp['id']?></td>
                <td><a href="?tab=orders&action=view_order&id=<?=$rp['order_id']?>">#<?=$rp['order_id']?></a><br><small><?=htmlspecialchars($rp['svc_title']??'')?></small></td>
                <td><?=htmlspecialchars($rp['reporter_name']?:$rp['reporter_user'])?></td>
                <td><strong><?=htmlspecialchars($rp['reason_name']??'')?></strong></td>
                <td><small><?=htmlspecialchars(mb_substr($rp['details']??'',0,60))?></small></td>
                <td><?php $ev=json_decode($rp['evidence']??'[]',true); if($ev): ?><small><?=count($ev)?> ملف</small><?php else: ?>—<?php endif; ?></td>
                <td><span class="badge" style="background:<?=$stC[$rp['status']]??'#8895a7'?>22;color:<?=$stC[$rp['status']]??'#8895a7'?>"><?=$stL[$rp['status']]??$rp['status']?></span></td>
                <td>
                    <form method="POST" style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
        <?= adminCsrfField() ?>
                        <input type="hidden" name="reply_report" value="1"><input type="hidden" name="report_id" value="<?=$rp['id']?>">
                        <input type="text" name="admin_reply" placeholder="رد..." class="form-control" style="width:120px;font-size:.75rem" value="<?=htmlspecialchars($rp['admin_reply']??'')?>">
                        <select name="report_status" class="form-control" style="width:80px;font-size:.75rem">
                            <option value="reviewing" <?=$rp['status']==='reviewing'?'selected':''?>>مراجعة</option>
                            <option value="resolved" <?=$rp['status']==='resolved'?'selected':''?>>تم الحل</option>
                            <option value="rejected" <?=$rp['status']==='rejected'?'selected':''?>>مرفوض</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($reports)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#8895a7">لا توجد بلاغات</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- أسباب الإبلاغ -->
<div class="card" style="border:1px solid rgba(255,68,85,.15)">
    <h3 style="margin-bottom:1rem"><i class="fas fa-list-check" style="color:#f5a623"></i> أسباب الإبلاغ (<?=count($reasons)?>)</h3>
    <div class="table-wrap" style="margin-bottom:1rem">
        <table>
            <thead><tr><th>#</th><th>السبب</th><th>يجمد الرصيد</th><th>الترتيب</th><th>الحالة</th><th>إجراءات</th></tr></thead>
            <tbody>
            <?php foreach($reasons as $rs): ?>
            <tr>
                <td><?=$rs['id']?></td>
                <td><strong><?=htmlspecialchars($rs['name'])?></strong></td>
                <td><?=$rs['freezes_seller']?'<span class="badge" style="background:#ff445522;color:#ff4455">🔒 نعم</span>':'<span class="badge badge-secondary">لا</span>'?></td>
                <td><?=$rs['sort_order']?></td>
                <td><?=$rs['status']?'✅':'❌'?></td>
                <td><a href="?tab=reports&action=delete_reason&id=<?=$rs['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_reason" value="1"><input type="hidden" name="reason_id" value="0">
        <div class="form-group" style="margin:0;flex:2"><label>اسم السبب</label><input type="text" name="reason_name" class="form-control" required placeholder="مثال: احتيال"></div>
        <div class="form-group" style="margin:0;flex:1"><label>يجمد الرصيد</label><select name="reason_freeze" class="form-control"><option value="0">لا</option><option value="1">🔒 نعم</option></select></div>
        <div class="form-group" style="margin:0;width:70px"><label>ترتيب</label><input type="number" name="reason_order" class="form-control" value="0"></div>
        <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> إضافة</button>
    </form>
</div>

<?php elseif ($tab === 'disputes'): ?>
<!-- ═══ النزاعات ═══ -->
<?php
$disputes = $pdo->query("SELECT o.*, s.title as svc_title,
    buyer.full_name as bname, buyer.username as buser, buyer.id as buyer_uid,
    seller.full_name as sname, seller.username as suser, seller.id as seller_uid,
    (SELECT COUNT(*) FROM p2p_messages WHERE order_id=o.id) as msg_count
    FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id
    JOIN users buyer ON buyer.id=o.buyer_id JOIN users seller ON seller.id=o.seller_id
    WHERE o.status='disputed' ORDER BY o.updated_at DESC")->fetchAll();
$viewDp = ($action === 'view_dispute' && $id) ? $id : 0;
?>
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-balance-scale" style="color:#f5a623"></i> النزاعات المفتوحة (<?=count($disputes)?>)</h3>
    <?php if(empty($disputes)): ?>
    <div style="text-align:center;padding:2rem;color:#8895a7">لا توجد نزاعات مفتوحة</div>
    <?php else: foreach($disputes as $dp):
        $isExpanded = ($viewDp == $dp['id']);
        // جلب سجل الأحداث والمحادثة للنزاع المفتوح
        $dpLogs = []; $dpMsgs = [];
        if ($isExpanded) {
            try { $dpLogs = $pdo->prepare("SELECT l.*, u.full_name, u.username FROM p2p_order_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.order_id=? ORDER BY l.created_at ASC"); $dpLogs->execute([$dp['id']]); $dpLogs = $dpLogs->fetchAll(); } catch(Exception $e) {}
            try { $dpMsgs = $pdo->prepare("SELECT m.*, u.full_name as sender_name FROM p2p_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.order_id=? ORDER BY m.created_at ASC"); $dpMsgs->execute([$dp['id']]); $dpMsgs = $dpMsgs->fetchAll(); } catch(Exception $e) {}
        }
    ?>
    <div class="card mb-2" style="border:1px solid rgba(245,166,35,.2);background:rgba(245,166,35,.02)">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:1rem">
            <div>
                <h4 style="margin:0">⚖️ نزاع #<?=$dp['id']?> — <?=htmlspecialchars($dp['svc_title'])?></h4>
                <small style="color:#8895a7"><?=date('Y/m/d H:i', strtotime($dp['created_at']))?> · <?=$dp['msg_count']?> رسالة</small>
            </div>
            <div style="text-align:left">
                <div style="color:#f5a623;font-weight:900;font-size:1.1rem"><?=number_format($dp['price'],2)?> $</div>
                <?php if(!$isExpanded): ?><a href="?tab=disputes&action=view_dispute&id=<?=$dp['id']?>" class="btn btn-sm btn-info" style="margin-top:4px"><i class="fas fa-expand"></i> تفاصيل</a><?php endif; ?>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem">
            <div style="background:var(--bg3,#161b2e);border-radius:10px;padding:12px">
                <div style="font-size:.7rem;color:#00d4aa;font-weight:700">🛒 المشتري — <a href="customers.php?action=view&id=<?=$dp['buyer_uid']?>">#<?=$dp['buyer_uid']?></a></div>
                <div style="font-weight:800"><?=htmlspecialchars($dp['bname']?:$dp['buser'])?></div>
                <div style="font-size:.75rem;color:#8895a7">دفع: <?=number_format($dp['buyer_total'],4)?> $</div>
                <?php if($dp['buyer_viewed_delivery']??false): ?><div style="font-size:.7rem;color:#00e676">👁 شاهد البيانات <?=$dp['buyer_viewed_at']?date('m/d H:i',strtotime($dp['buyer_viewed_at'])):''?></div><?php else: ?><div style="font-size:.7rem;color:#ff4455">❌ لم يشاهد البيانات</div><?php endif; ?>
                <?php if($dp['confirmed_email_change']??false): ?><div style="font-size:.7rem;color:#00d4ff">✓ أكد تغيير البريد</div><?php endif; ?>
                <?php if($dp['confirmed_password_change']??false): ?><div style="font-size:.7rem;color:#00d4ff">✓ أكد تغيير كلمة المرور</div><?php endif; ?>
                <?php if($dp['confirmed_recovery_removed']??false): ?><div style="font-size:.7rem;color:#00d4ff">✓ أكد إزالة الاسترجاع</div><?php endif; ?>
            </div>
            <div style="background:var(--bg3,#161b2e);border-radius:10px;padding:12px">
                <div style="font-size:.7rem;color:#8b5cf6;font-weight:700">🏪 البائع — <a href="customers.php?action=view&id=<?=$dp['seller_uid']?>">#<?=$dp['seller_uid']?></a></div>
                <div style="font-weight:800"><?=htmlspecialchars($dp['sname']?:$dp['suser'])?></div>
                <div style="font-size:.75rem;color:#8895a7">ضمان: <?=number_format($dp['seller_escrow'],4)?> $ · يستلم: <?=number_format($dp['seller_payout'],4)?> $</div>
                <?php if($dp['delivered_at']??false): ?><div style="font-size:.7rem;color:#8b5cf6">📦 سلّم: <?=date('m/d H:i',strtotime($dp['delivered_at']))?></div><?php endif; ?>
                <?php if($dp['delivery_type']??''): ?><div style="font-size:.7rem;color:#8895a7">نوع: <?=['account'=>'حساب','code'=>'كود','text'=>'نص','other'=>'أخرى'][$dp['delivery_type']]??$dp['delivery_type']?></div><?php endif; ?>
            </div>
        </div>
        <?php if($dp['delivery_data']??''): ?>
        <div style="background:#080c1a;border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:10px;margin-bottom:1rem;font-size:.8rem">
            <div style="font-size:.7rem;color:#8b5cf6;font-weight:700;margin-bottom:4px">📦 بيانات التسليم</div>
            <pre style="white-space:pre-wrap;color:#c8d6e5;margin:0"><?=htmlspecialchars($dp['delivery_data'])?></pre>
        </div>
        <?php endif; ?>

        <?php if($isExpanded && $dpMsgs): ?>
        <div style="background:#080c1a;border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:10px;margin-bottom:1rem;max-height:300px;overflow-y:auto">
            <div style="font-size:.7rem;color:#1e6fff;font-weight:700;margin-bottom:8px">💬 المحادثة (<?=count($dpMsgs)?>)</div>
            <?php foreach($dpMsgs as $msg): $isSys = $msg['sender_id']==0; $isBuyer = $msg['sender_id']==$dp['buyer_id']; ?>
            <div style="margin-bottom:6px;padding:6px 8px;border-radius:8px;background:<?=$isSys?'rgba(139,92,246,.1)':($isBuyer?'rgba(0,212,170,.05)':'rgba(30,111,255,.05)')?>">
                <div style="font-size:.65rem;color:<?=$isSys?'#8b5cf6':($isBuyer?'#00d4aa':'#1e6fff')?>">
                    <?=$isSys?'📢 النظام':($isBuyer?'🛒 '.htmlspecialchars($msg['sender_name']??'المشتري'):'🏪 '.htmlspecialchars($msg['sender_name']??'البائع'))?> · <?=date('m/d H:i',strtotime($msg['created_at']))?>
                </div>
                <div style="font-size:.8rem;color:#c8d6e5"><?=nl2br(htmlspecialchars($msg['message']))?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if($isExpanded && $dpLogs): ?>
        <div style="background:#080c1a;border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:10px;margin-bottom:1rem">
            <div style="font-size:.7rem;color:#f5a623;font-weight:700;margin-bottom:8px">📋 سجل الأحداث (<?=count($dpLogs)?>)</div>
            <?php foreach($dpLogs as $log): ?>
            <div style="font-size:.7rem;color:#8895a7;margin-bottom:3px;display:flex;gap:8px">
                <span style="color:#475569;min-width:80px"><?=date('m/d H:i',strtotime($log['created_at']))?></span>
                <span style="color:#f5a623;min-width:90px;font-weight:700"><?=htmlspecialchars($log['action'])?></span>
                <span><?=htmlspecialchars($log['full_name']??($log['user_id']==0?'النظام':'#'.$log['user_id']))?></span>
                <?php if($log['ip_address']): ?><span style="color:#475569">IP: <?=htmlspecialchars($log['ip_address'])?></span><?php endif; ?>
                <?php if($log['details']): ?><span style="color:#64748b"><?=htmlspecialchars(mb_substr($log['details'],0,60))?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="padding:1rem;background:var(--bg3,#161b2e);border-radius:10px">
            <h5 style="margin-bottom:.75rem;color:#f5a623"><i class="fas fa-gavel"></i> إصدار حكم</h5>
            <input type="hidden" name="action" value="resolve_dispute"><input type="hidden" name="order_id" value="<?=$dp['id']?>">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:.5rem">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px;border-radius:8px;background:rgba(0,230,118,.05);border:1px solid rgba(0,230,118,.1)"><input type="radio" name="decision" value="buyer_wins" required> <span style="font-size:.8rem">✅ لصالح المشتري</span></label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px;border-radius:8px;background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.1)"><input type="radio" name="decision" value="seller_wins"> <span style="font-size:.8rem">✅ لصالح البائع</span></label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px;border-radius:8px;background:rgba(245,166,35,.05);border:1px solid rgba(245,166,35,.1)"><input type="radio" name="decision" value="split"> <span style="font-size:.8rem">✂️ تقسيم</span></label>
            </div>
            <div style="display:flex;gap:8px;margin-bottom:.5rem">
                <input type="number" name="buyer_pct" placeholder="نسبة المشتري %" class="form-control" style="width:150px" min="0" max="100">
                <input type="text" name="note" placeholder="ملاحظة الإدارة..." class="form-control" style="flex:1">
            </div>
            <button type="submit" class="btn btn-warning" onclick="return confirm('⚠️ تأكيد إصدار الحكم؟ هذا الإجراء لا يمكن التراجع عنه.')"><i class="fas fa-gavel"></i> تنفيذ الحكم</button>
        </form>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php elseif ($tab === 'monitor'): ?>
<!-- ═══ المراقبة المباشرة ═══ -->
<script>setTimeout(()=>location.reload(), 30000);</script>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
    <small style="color:#8895a7"><i class="fas fa-sync-alt"></i> تحديث تلقائي كل 30 ثانية</small>
    <span class="badge" style="background:<?=getSetting('p2p_enabled')==='1'?'#00e676':'#ff4455'?>;color:#000"><?=getSetting('p2p_enabled')==='1'?'🟢 النظام يعمل':'🔴 النظام متوقف'?></span>
</div>
<?php
$activeOrders = $pdo->query("SELECT o.*, s.title as svc_title, buyer.full_name as bname, seller.full_name as sname FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id JOIN users buyer ON buyer.id=o.buyer_id JOIN users seller ON seller.id=o.seller_id WHERE o.status='active' ORDER BY o.created_at DESC")->fetchAll();
$deliveredOrders = $pdo->query("SELECT o.*, s.title as svc_title, buyer.full_name as bname, seller.full_name as sname FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id JOIN users buyer ON buyer.id=o.buyer_id JOIN users seller ON seller.id=o.seller_id WHERE o.status='delivered' ORDER BY o.delivered_at DESC")->fetchAll();
$disputedCount = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='disputed'")->fetchColumn();
$holdOrders = [];
try { $holdOrders = $pdo->query("SELECT o.*, s.title as svc_title, seller.full_name as sname FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id JOIN users seller ON seller.id=o.seller_id WHERE o.status='completed' AND o.remaining_hold_amount>0 ORDER BY o.hold_release_at ASC")->fetchAll(); } catch(Exception $e) {}
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:1.5rem">
    <div class="card" style="text-align:center;padding:14px;border:1px solid rgba(30,111,255,.2)"><div style="font-size:28px;font-weight:900;color:#1e6fff"><?=count($activeOrders)?></div><div style="font-size:10px;color:#8895a7">🔵 نشط الآن</div></div>
    <div class="card" style="text-align:center;padding:14px;border:1px solid rgba(139,92,246,.2)"><div style="font-size:28px;font-weight:900;color:#8b5cf6"><?=count($deliveredOrders)?></div><div style="font-size:10px;color:#8895a7">📦 بانتظار تأكيد</div></div>
    <div class="card" style="text-align:center;padding:14px;border:1px solid rgba(245,166,35,.2)"><div style="font-size:28px;font-weight:900;color:#f5a623"><?=$disputedCount?></div><div style="font-size:10px;color:#8895a7">⚖️ نزاعات</div></div>
    <div class="card" style="text-align:center;padding:14px;border:1px solid rgba(0,230,118,.2)"><div style="font-size:28px;font-weight:900;color:#00e676"><?=count($holdOrders)?></div><div style="font-size:10px;color:#8895a7">🔒 ضمان معلق</div></div>
</div>

<?php if($deliveredOrders): ?>
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-clock" style="color:#8b5cf6"></i> بانتظار تأكيد المشتري (<?=count($deliveredOrders)?>)</h3>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>الخدمة</th><th>البائع</th><th>المشتري</th><th>المبلغ</th><th>تسليم</th><th>إتمام تلقائي</th><th>👁</th><th>إجراء</th></tr></thead><tbody>
    <?php foreach($deliveredOrders as $do): ?>
    <tr><td><?=$do['id']?></td><td><?=htmlspecialchars(mb_substr($do['svc_title'],0,18))?></td><td><?=htmlspecialchars($do['sname'])?></td><td><?=htmlspecialchars($do['bname'])?></td>
    <td style="color:#00d4aa;font-weight:700"><?=number_format($do['price'],2)?>$</td>
    <td><small><?=$do['delivered_at']?date('m/d H:i',strtotime($do['delivered_at'])):'-'?></small></td>
    <td><small style="color:#f5a623"><?=$do['auto_complete_at']?date('m/d H:i',strtotime($do['auto_complete_at'])):'-'?></small></td>
    <td><?=($do['buyer_viewed_delivery']??0)?'<span style="color:#00e676">✓</span>':'<span style="color:#ff4455">✗</span>'?></td>
    <td style="white-space:nowrap"><a href="?action=admin_complete&id=<?=$do['id']?>" class="btn btn-sm btn-success" onclick="return confirm('إتمام؟')"><i class="fas fa-check"></i></a>
    <a href="?action=admin_cancel&id=<?=$do['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('إلغاء؟')"><i class="fas fa-times"></i></a></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if($holdOrders): ?>
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-lock" style="color:#f5a623"></i> ضمان جزئي معلق (<?=count($holdOrders)?>)</h3>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>الخدمة</th><th>البائع</th><th>المعلق</th><th>موعد التحويل</th><th>تحويل</th><th>تمديد</th><th>إلغاء</th></tr></thead><tbody>
    <?php foreach($holdOrders as $ho): ?>
    <tr><td><?=$ho['id']?></td><td><?=htmlspecialchars(mb_substr($ho['svc_title'],0,18))?></td><td><?=htmlspecialchars($ho['sname'])?></td>
    <td style="color:#f5a623;font-weight:700"><?=number_format($ho['remaining_hold_amount'],4)?>$</td>
    <td><small><?=$ho['hold_release_at']?date('m/d H:i',strtotime($ho['hold_release_at'])):'-'?></small></td>
    <td><form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$ho['id']?>"><input type="hidden" name="hold_action" value="release_now"><button type="submit" class="btn btn-sm btn-success" onclick="return confirm('تحويل الآن؟')"><i class="fas fa-unlock"></i></button></form></td>
    <td><form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$ho['id']?>"><input type="hidden" name="hold_action" value="extend"><input type="hidden" name="hours" value="48"><button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('تمديد 48 ساعة؟')"><i class="fas fa-clock"></i></button></form></td>
    <td><form method="POST" action="<?=SITE_URL?>
        <?= adminCsrfField() ?>/api/p2p.php" style="display:inline"><input type="hidden" name="action" value="manage_hold"><input type="hidden" name="order_id" value="<?=$ho['id']?>"><input type="hidden" name="hold_action" value="cancel"><button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('إرجاع للمشتري؟')"><i class="fas fa-undo"></i></button></form></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-bottom:1rem"><i class="fas fa-exclamation-triangle" style="color:#ff4455"></i> أدوات الطوارئ</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if(getSetting('p2p_enabled')==='1'): ?>
        <a href="?action=emergency_stop" class="btn btn-danger" onclick="return confirm('⚠️ إيقاف نظام P2P بالكامل؟ لن يتمكن المستخدمون من الشراء أو البيع.')"><i class="fas fa-power-off"></i> إيقاف P2P</a>
        <?php else: ?>
        <a href="?action=emergency_start" class="btn btn-success"><i class="fas fa-play"></i> تشغيل P2P</a>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'fraud'): ?>
<!-- ═══ كشف الاحتيال ═══ -->
<?php
// إجراءات الحظر
if ($action === 'block_p2p' && $id && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO p2p_risk_scores (user_id,is_blocked,block_reason) VALUES (?,1,?) ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=?")->execute([$id, 'حظر إداري', 'حظر إداري']);
    flashMessage('success', 'تم حظر المستخدم من P2P'); redirect(SITE_URL.'/admin/p2p.php?tab=fraud');
}
if ($action === 'unblock_p2p' && $id && $IS_ADMIN) {
    $pdo->prepare("UPDATE p2p_risk_scores SET is_blocked=0, sell_blocked=0, buy_blocked=0, block_reason=NULL WHERE user_id=?")->execute([$id]);
    flashMessage('success', 'تم فك الحظر'); redirect(SITE_URL.'/admin/p2p.php?tab=fraud');
}
if ($action === 'block_sell' && $id && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO p2p_risk_scores (user_id,sell_blocked) VALUES (?,1) ON DUPLICATE KEY UPDATE sell_blocked=1")->execute([$id]);
    flashMessage('success', 'تم منع البيع'); redirect(SITE_URL.'/admin/p2p.php?tab=fraud');
}
if ($action === 'block_buy' && $id && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO p2p_risk_scores (user_id,buy_blocked) VALUES (?,1) ON DUPLICATE KEY UPDATE buy_blocked=1")->execute([$id]);
    flashMessage('success', 'تم منع الشراء'); redirect(SITE_URL.'/admin/p2p.php?tab=fraud');
}
if ($action === 'reset_risk' && $id && $IS_ADMIN) {
    $pdo->prepare("UPDATE p2p_risk_scores SET risk_score=0, risk_level='safe', sell_blocked=0, buy_blocked=0, is_blocked=0 WHERE user_id=?")->execute([$id]);
    flashMessage('success', 'تم إعادة تعيين الخطورة'); redirect(SITE_URL.'/admin/p2p.php?tab=fraud');
}

// المستخدمون المشبوهون
$riskyUsers = [];
try {
    $riskyUsers = $pdo->query("SELECT rs.*, u.full_name, u.username, u.email,
        (SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id=rs.user_id OR seller_id=rs.user_id) AND status='disputed') as disputes,
        (SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id=rs.user_id OR seller_id=rs.user_id) AND status='cancelled') as cancels,
        (SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id=rs.user_id OR seller_id=rs.user_id) AND status='completed') as completed,
        (SELECT COUNT(*) FROM p2p_orders WHERE buyer_id=rs.user_id OR seller_id=rs.user_id) as total_orders
        FROM p2p_risk_scores rs JOIN users u ON u.id=rs.user_id
        ORDER BY rs.risk_score DESC, rs.is_blocked DESC LIMIT 50")->fetchAll();
} catch(Exception $e) {}

// سجل الاحتيال الأخير
$recentFraud = [];
try { $recentFraud = $pdo->query("SELECT fl.*, u.full_name, u.username FROM p2p_fraud_logs fl LEFT JOIN users u ON u.id=fl.user_id ORDER BY fl.created_at DESC LIMIT 30")->fetchAll(); } catch(Exception $e) {}

// عرض تفاصيل مستخدم معين
$viewUser = ($action === 'view_fraud' && $id) ? $id : 0;
$viewUserData = null; $viewUserIPs = []; $viewUserLogs = [];
if ($viewUser) {
    try { $vu = $pdo->prepare("SELECT u.*, rs.risk_score, rs.risk_level, rs.is_blocked, rs.sell_blocked, rs.buy_blocked FROM users u LEFT JOIN p2p_risk_scores rs ON rs.user_id=u.id WHERE u.id=?"); $vu->execute([$viewUser]); $viewUserData = $vu->fetch(); } catch(Exception $e) {}
    try { $vi = $pdo->prepare("SELECT ip_address, action, COUNT(*) as cnt, MAX(created_at) as last_seen FROM p2p_ip_log WHERE user_id=? GROUP BY ip_address, action ORDER BY last_seen DESC LIMIT 20"); $vi->execute([$viewUser]); $viewUserIPs = $vi->fetchAll(); } catch(Exception $e) {}
    try { $vl = $pdo->prepare("SELECT * FROM p2p_fraud_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 20"); $vl->execute([$viewUser]); $viewUserLogs = $vl->fetchAll(); } catch(Exception $e) {}
}
?>

<?php if($viewUserData): ?>
<div class="card mb-2" style="border:1px solid rgba(245,166,35,.2)">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3><i class="fas fa-user-shield" style="color:#f5a623"></i> <?=htmlspecialchars($viewUserData['full_name']?:$viewUserData['username'])?> — #<?=$viewUser?></h3>
        <a href="?tab=fraud" class="btn btn-sm btn-secondary">← رجوع</a>
    </div>
    <?php $rs=$viewUserData['risk_score']??0; $rl=$viewUserData['risk_level']??'safe'; $rc=['safe'=>'#00e676','suspicious'=>'#f5a623','danger'=>'#ff4455']; ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:1rem">
        <div class="card" style="text-align:center;padding:10px;border:2px solid <?=$rc[$rl]?>44"><div style="font-size:28px;font-weight:900;color:<?=$rc[$rl]?>"><?=$rs?></div><div style="font-size:9px;color:#8895a7">درجة الخطورة</div></div>
        <div class="card" style="text-align:center;padding:10px"><div style="font-size:18px;font-weight:900;color:#ff4455"><?=$viewUserData['is_blocked']?'⛔ محظور':($viewUserData['sell_blocked']?'🚫 ممنوع البيع':($viewUserData['buy_blocked']?'🚫 ممنوع الشراء':'✅ عادي'))?></div></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:1rem">
        <?php if(!$viewUserData['is_blocked']): ?><a href="?tab=fraud&action=block_p2p&id=<?=$viewUser?>" class="btn btn-sm btn-danger" onclick="return confirm('حظر كامل؟')"><i class="fas fa-ban"></i> حظر P2P</a><?php else: ?><a href="?tab=fraud&action=unblock_p2p&id=<?=$viewUser?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> فك الحظر</a><?php endif; ?>
        <a href="?tab=fraud&action=block_sell&id=<?=$viewUser?>" class="btn btn-sm btn-warning"><i class="fas fa-store-slash"></i> منع البيع</a>
        <a href="?tab=fraud&action=block_buy&id=<?=$viewUser?>" class="btn btn-sm btn-warning"><i class="fas fa-cart-arrow-down"></i> منع الشراء</a>
        <a href="?tab=fraud&action=reset_risk&id=<?=$viewUser?>" class="btn btn-sm btn-info" onclick="return confirm('إعادة تعيين؟')"><i class="fas fa-redo"></i> تصفير الخطورة</a>
    </div>
    <?php if($viewUserIPs): ?>
    <div style="background:#080c1a;border-radius:8px;padding:10px;margin-bottom:1rem"><div style="font-size:.7rem;font-weight:700;color:#1e6fff;margin-bottom:6px">🌐 سجل IP</div>
    <?php foreach($viewUserIPs as $vip): ?><div style="font-size:.7rem;color:#8895a7;display:flex;gap:8px"><span style="color:#64748b;min-width:120px"><?=htmlspecialchars($vip['ip_address'])?></span><span><?=htmlspecialchars($vip['action'])?></span><span style="color:#475569">×<?=$vip['cnt']?></span><span style="color:#475569"><?=date('m/d H:i',strtotime($vip['last_seen']))?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if($viewUserLogs): ?>
    <div style="background:#080c1a;border-radius:8px;padding:10px"><div style="font-size:.7rem;font-weight:700;color:#ff4455;margin-bottom:6px">🚨 سجل الأحداث المشبوهة</div>
    <?php foreach($viewUserLogs as $fl): $sc=['low'=>'#00e676','medium'=>'#f5a623','high'=>'#ff4455','critical'=>'#ff0033']; ?>
    <div style="font-size:.7rem;color:#8895a7;margin-bottom:4px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03)"><span style="color:<?=$sc[$fl['severity']]??'#8895a7'?>;font-weight:700">[<?=$fl['severity']?>]</span> <?=htmlspecialchars($fl['description'])?> <span style="color:#475569">· <?=date('m/d H:i',strtotime($fl['created_at']))?><?=$fl['ip_address']?" · IP: {$fl['ip_address']}":''?></span></div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-shield-alt" style="color:#ff4455"></i> المستخدمون حسب الخطورة (<?=count($riskyUsers)?>)</h3>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>المستخدم</th><th>الخطورة</th><th>المستوى</th><th>طلبات</th><th>نزاعات</th><th>إلغاءات</th><th>نسبة نجاح</th><th>الحالة</th><th>تفاصيل</th></tr></thead><tbody>
    <?php foreach($riskyUsers as $ru):
        $rlC=['safe'=>'#00e676','suspicious'=>'#f5a623','danger'=>'#ff4455'];
        $rlL=['safe'=>'🟢 آمن','suspicious'=>'🟡 مشبوه','danger'=>'🔴 خطر'];
        $sr=$ru['total_orders']>0?round($ru['completed']/$ru['total_orders']*100):0;
    ?>
    <tr style="<?=$ru['is_blocked']?'background:rgba(255,68,85,.03)':($ru['risk_level']==='danger'?'background:rgba(255,68,85,.02)':'')?>">
        <td><?=$ru['user_id']?></td>
        <td><a href="customers.php?action=view&id=<?=$ru['user_id']?>"><?=htmlspecialchars($ru['full_name']?:$ru['username'])?></a></td>
        <td style="font-weight:900;color:<?=$rlC[$ru['risk_level']]??'#8895a7'?>"><?=$ru['risk_score']?></td>
        <td><span class="badge" style="background:<?=$rlC[$ru['risk_level']]??'#8895a7'?>22;color:<?=$rlC[$ru['risk_level']]??'#8895a7'?>"><?=$rlL[$ru['risk_level']]??$ru['risk_level']?></span></td>
        <td><?=$ru['total_orders']?></td>
        <td style="<?=$ru['disputes']?'color:#ff4455;font-weight:700':''?>"><?=$ru['disputes']?></td>
        <td><?=$ru['cancels']?></td>
        <td style="color:<?=$sr>=70?'#00e676':($sr>=40?'#f5a623':'#ff4455')?>"><?=$sr?>%</td>
        <td><?=$ru['is_blocked']?'<span class="badge" style="background:#ff445522;color:#ff4455">⛔ محظور</span>':($ru['sell_blocked']?'<span class="badge" style="background:#f5a62322;color:#f5a623">🚫 بيع</span>':($ru['buy_blocked']?'<span class="badge" style="background:#f5a62322;color:#f5a623">🚫 شراء</span>':'—'))?></td>
        <td><a href="?tab=fraud&action=view_fraud&id=<?=$ru['user_id']?>" class="btn btn-sm btn-info"><i class="fas fa-search"></i></a></td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($riskyUsers)): ?><tr><td colspan="10" style="text-align:center;padding:2rem;color:#8895a7">لا توجد بيانات خطورة بعد — النظام يتعلم مع كل عملية</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php if($recentFraud): ?>
<div class="card">
    <h3 style="margin-bottom:1rem"><i class="fas fa-exclamation-circle" style="color:#f5a623"></i> آخر الأحداث المشبوهة</h3>
    <div class="table-wrap"><table><thead><tr><th>الوقت</th><th>المستخدم</th><th>القاعدة</th><th>الشدة</th><th>الوصف</th><th>IP</th></tr></thead><tbody>
    <?php foreach($recentFraud as $rf): $sc=['low'=>'#00e676','medium'=>'#f5a623','high'=>'#ff4455','critical'=>'#ff0033']; ?>
    <tr><td><small><?=date('m/d H:i',strtotime($rf['created_at']))?></small></td>
    <td><a href="?tab=fraud&action=view_fraud&id=<?=$rf['user_id']?>"><?=htmlspecialchars($rf['full_name']?:$rf['username']?:'#'.$rf['user_id'])?></a></td>
    <td><code style="font-size:.7rem"><?=htmlspecialchars($rf['rule_code'])?></code></td>
    <td><span style="color:<?=$sc[$rf['severity']]??'#8895a7'?>;font-weight:700"><?=$rf['severity']?></span></td>
    <td><small><?=htmlspecialchars(mb_substr($rf['description'],0,50))?></small></td>
    <td><small style="color:#475569"><?=htmlspecialchars($rf['ip_address']??'')?></small></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'ratings'): ?>
<!-- ═══ إدارة التقييمات ═══ -->
<?php
if ($action === 'delete_rating' && $id && $IS_ADMIN) {
    $pdo->prepare("DELETE FROM p2p_ratings WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف التقييم');
    redirect(SITE_URL . '/admin/p2p.php?tab=ratings');
}
$ratings = [];
try {
    $ratings = $pdo->query("SELECT r.*, rater.full_name as rater_name, rater.username as rater_user, rated.full_name as rated_name, rated.username as rated_user, s.title as svc_title
        FROM p2p_ratings r JOIN users rater ON rater.id=r.rater_id JOIN users rated ON rated.id=r.rated_id
        LEFT JOIN p2p_orders o ON o.id=r.order_id LEFT JOIN p2p_services s ON s.id=o.service_id
        ORDER BY r.created_at DESC LIMIT 100")->fetchAll();
} catch(Exception $e) {}
?>
<div class="card">
    <h3 style="margin-bottom:1rem"><i class="fas fa-star" style="color:#f5a623"></i> التقييمات (<?=count($ratings)?>)</h3>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>المقيّم</th><th>المُقيَّم</th><th>الخدمة</th><th>التقييم</th><th>التعليق</th><th>التاريخ</th><th>حذف</th></tr></thead><tbody>
    <?php foreach($ratings as $rt): ?>
    <tr><td><?=$rt['id']?></td>
    <td><?=htmlspecialchars($rt['rater_name']?:$rt['rater_user'])?></td>
    <td><?=htmlspecialchars($rt['rated_name']?:$rt['rated_user'])?></td>
    <td><small><?=htmlspecialchars(mb_substr($rt['svc_title']??'',0,20))?></small></td>
    <td><?=str_repeat('⭐',$rt['rating'])?></td>
    <td><small><?=htmlspecialchars(mb_substr($rt['comment']??'',0,40))?></small></td>
    <td><small><?=date('m/d H:i',strtotime($rt['created_at']))?></small></td>
    <td><a href="?tab=ratings&action=delete_rating&id=<?=$rt['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف التقييم؟')"><i class="fas fa-trash"></i></a></td></tr>
    <?php endforeach; ?>
    <?php if(empty($ratings)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#8895a7">لا توجد تقييمات</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php elseif ($tab === 'stats'): ?>
<!-- ═══ الإحصائيات ═══ -->
<?php
$monthlyRevenue = $pdo->query("SELECT DATE_FORMAT(completed_at,'%Y-%m') as m, SUM(commission) as rev, COUNT(*) as cnt FROM p2p_orders WHERE status='completed' GROUP BY m ORDER BY m DESC LIMIT 12")->fetchAll();
$topSellers = $pdo->query("SELECT u.full_name, u.username, COUNT(o.id) as sales, SUM(o.seller_payout) as earnings
    FROM p2p_orders o JOIN users u ON u.id=o.seller_id WHERE o.status='completed'
    GROUP BY o.seller_id ORDER BY sales DESC LIMIT 10")->fetchAll();
?>
<div class="card mb-2">
    <h3 style="margin-bottom:1rem"><i class="fas fa-chart-bar" style="color:#00d4ff"></i> إيرادات العمولات الشهرية</h3>
    <?php if(empty($monthlyRevenue)): ?>
    <div style="text-align:center;padding:2rem;color:#8895a7">لا توجد بيانات بعد</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الشهر</th><th>عدد العمليات</th><th>إيرادات العمولات</th></tr></thead>
            <tbody>
            <?php foreach($monthlyRevenue as $mr): ?>
            <tr>
                <td><strong><?=$mr['m']?></strong></td>
                <td><?=$mr['cnt']?></td>
                <td style="color:#00d4aa;font-weight:700"><?=number_format($mr['rev'],2)?> $</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<div class="card">
    <h3 style="margin-bottom:1rem"><i class="fas fa-trophy" style="color:#f5a623"></i> أفضل البائعين</h3>
    <?php if(empty($topSellers)): ?>
    <div style="text-align:center;padding:2rem;color:#8895a7">لا توجد بيانات</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>#</th><th>البائع</th><th>العمليات</th><th>الأرباح</th></tr></thead>
            <tbody>
            <?php foreach($topSellers as $i=>$ts): ?>
            <tr>
                <td><span style="color:#f5a623;font-weight:900"><?=$i+1?></span></td>
                <td><strong><?=htmlspecialchars($ts['full_name'] ?: $ts['username'])?></strong></td>
                <td><?=$ts['sales']?></td>
                <td style="color:#00d4aa;font-weight:700"><?=number_format($ts['earnings'],2)?> $</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'settings'): ?>
<!-- ═══ الإعدادات ═══ -->
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_p2p_settings']) && $IS_ADMIN) {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_enabled',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_enabled']??'0', $_POST['p2p_enabled']??'0']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_default_commission',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_default_commission']??'5', $_POST['p2p_default_commission']??'5']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_chat_duration',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_chat_duration']??'30', $_POST['p2p_chat_duration']??'30']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_escrow_rate',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_escrow_rate']??'100', $_POST['p2p_escrow_rate']??'100']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_auto_approve_new',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_auto_approve_new']??'0', $_POST['p2p_auto_approve_new']??'0']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_auto_approve_edit',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_auto_approve_edit']??'0', $_POST['p2p_auto_approve_edit']??'0']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_auto_complete_hours',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_auto_complete_hours']??'24', $_POST['p2p_auto_complete_hours']??'24']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_partial_release_pct',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_partial_release_pct']??'80', $_POST['p2p_partial_release_pct']??'80']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_hold_hours',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_hold_hours']??'48', $_POST['p2p_hold_hours']??'48']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_require_security_confirm',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_require_security_confirm']??'1', $_POST['p2p_require_security_confirm']??'1']);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('p2p_min_delivery_length',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['p2p_min_delivery_length']??'10', $_POST['p2p_min_delivery_length']??'10']);
    flashMessage('success', '✅ تم حفظ إعدادات P2P');
    redirect(SITE_URL . '/admin/p2p.php?tab=settings');
}
?>
<div class="card">
    <h3 style="margin-bottom:1.5rem"><i class="fas fa-cog" style="color:#8b5cf6"></i> إعدادات سوق P2P</h3>
    <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_p2p_settings" value="1">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group">
                <label>تفعيل سوق P2P</label>
                <select name="p2p_enabled" class="form-control">
                    <option value="1" <?=getSetting('p2p_enabled')==='1'?'selected':''?>>✅ مفعّل</option>
                    <option value="0" <?=getSetting('p2p_enabled')!=='1'?'selected':''?>>❌ معطّل</option>
                </select>
            </div>
            <div class="form-group">
                <label>نسبة العمولة (%)</label>
                <input type="number" name="p2p_default_commission" class="form-control" value="<?=getSetting('p2p_default_commission')?:5?>" min="0" max="50" step="0.5">
                <small style="color:#8895a7">البائع يختار من يتحمل العمولة</small>
            </div>
            <div class="form-group">
                <label>مدة المحادثة (دقيقة)</label>
                <input type="number" name="p2p_chat_duration" class="form-control" value="<?=getSetting('p2p_chat_duration')?:30?>" min="5" max="120">
            </div>
            <div class="form-group">
                <label>نسبة الضمان من البائع (%)</label>
                <input type="number" name="p2p_escrow_rate" class="form-control" value="<?=getSetting('p2p_escrow_rate')?:100?>" min="0" max="200">
                <small style="color:#8895a7">100% = حجز نفس سعر الخدمة كضمان</small>
            </div>
        </div>

        <div style="margin-top:1.5rem;padding:1.25rem;background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.15);border-radius:12px">
            <h4 style="margin-bottom:1rem;color:#8b5cf6"><i class="fas fa-shield-alt"></i> إعدادات الموافقة والمراجعة</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label>الخدمات الجديدة</label>
                    <select name="p2p_auto_approve_new" class="form-control">
                        <option value="0" <?=getSetting('p2p_auto_approve_new')!=='1'?'selected':''?>>⏳ تحتاج موافقة الإدارة</option>
                        <option value="1" <?=getSetting('p2p_auto_approve_new')==='1'?'selected':''?>>⚡ قبول تلقائي (تُنشر مباشرة)</option>
                    </select>
                    <small style="color:#8895a7">عند إضافة البائع خدمة جديدة</small>
                </div>
                <div class="form-group">
                    <label>تعديلات الخدمات</label>
                    <select name="p2p_auto_approve_edit" class="form-control">
                        <option value="0" <?=getSetting('p2p_auto_approve_edit')!=='1'?'selected':''?>>⏳ تحتاج موافقة الإدارة</option>
                        <option value="1" <?=getSetting('p2p_auto_approve_edit')==='1'?'selected':''?>>⚡ قبول تلقائي (تُطبق مباشرة)</option>
                    </select>
                    <small style="color:#8895a7">عند تعديل البائع لخدمة موجودة</small>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success" style="margin-top:1rem"><i class="fas fa-save"></i> حفظ الإعدادات</button>

        <div style="margin-top:1.5rem;padding:1.25rem;background:rgba(0,212,170,.05);border:1px solid rgba(0,212,170,.15);border-radius:12px">
            <h4 style="margin-bottom:1rem;color:#00d4aa"><i class="fas fa-clock"></i> إعدادات التسليم والضمان</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label>مهلة التأكيد التلقائي (ساعة)</label>
                    <input type="number" name="p2p_auto_complete_hours" class="form-control" value="<?=getSetting('p2p_auto_complete_hours')?:24?>" min="1" max="168">
                    <small style="color:#8895a7">بعد تسليم البائع — إذا لم يؤكد المشتري</small>
                </div>
                <div class="form-group">
                    <label>نسبة التحويل الأولي (%)</label>
                    <input type="number" name="p2p_partial_release_pct" class="form-control" value="<?=getSetting('p2p_partial_release_pct')?:80?>" min="50" max="100">
                    <small style="color:#8895a7">النسبة التي تُحوَّل فور التأكيد (الباقي يُحجز)</small>
                </div>
                <div class="form-group">
                    <label>مدة حجز المبلغ المتبقي (ساعة)</label>
                    <input type="number" name="p2p_hold_hours" class="form-control" value="<?=getSetting('p2p_hold_hours')?:48?>" min="1" max="168">
                    <small style="color:#8895a7">المدة قبل تحويل النسبة المتبقية للبائع</small>
                </div>
                <div class="form-group">
                    <label>التحقق الأمني عند التأكيد</label>
                    <select name="p2p_require_security_confirm" class="form-control">
                        <option value="1" <?=getSetting('p2p_require_security_confirm')!=='0'?'selected':''?>>🔒 مطلوب (تأكيد تغيير البيانات)</option>
                        <option value="0" <?=getSetting('p2p_require_security_confirm')==='0'?'selected':''?>>❌ غير مطلوب</option>
                    </select>
                    <small style="color:#8895a7">إجبار المشتري على تأكيد تغيير البريد + كلمة المرور + الاسترجاع</small>
                </div>
                <div class="form-group">
                    <label>الحد الأدنى لبيانات التسليم (حرف)</label>
                    <input type="number" name="p2p_min_delivery_length" class="form-control" value="<?=getSetting('p2p_min_delivery_length')?:10?>" min="1" max="500">
                    <small style="color:#8895a7">لمنع إدخال بيانات وهمية</small>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-success" style="margin-top:1rem"><i class="fas fa-save"></i> حفظ جميع الإعدادات</button>
    </form>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
