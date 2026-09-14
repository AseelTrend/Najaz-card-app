<?php
require_once '../includes/config.php';
requireAdmin($pdo);

// ── تصدير الأكواد (لازم قبل أي إخراج HTML عشان تشتغل الـ headers) ──
if (isset($_GET['export']) && !empty($_GET['batch'])) {
    $exportBatch = $_GET['batch'];
    $exportCards = $pdo->prepare("SELECT code,amount,status,created_at FROM recharge_cards WHERE batch_id=? ORDER BY id");
    $exportCards->execute([$exportBatch]);
    $rows = $exportCards->fetchAll();

    if ($rows) {
        $batchAmount = $rows[0]['amount'];
        $totalCount  = count($rows);
        $activeCount = 0;
        $usedCount   = 0;
        $firstDate   = $rows[0]['created_at'];
        foreach ($rows as $r) {
            if ($r['status'] === 'active') $activeCount++;
            if ($r['status'] === 'used')   $usedCount++;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="batch_'.$exportBatch.'.txt"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo "\xEF\xBB\xBF"; // BOM حتى يفتح بالعربي صح في أي محرر

        // ── الأكواد فقط، كل كود بسطر ──
        foreach ($rows as $r) {
            echo $r['code']."\n";
        }

        // ── بيانات الدفعة بنهاية الملف ──
        echo "\n";
        echo "----------------------------------------\n";
        echo "اسم الدفعة: ".$exportBatch."\n";
        echo "قيمة الكرت: ".number_format($batchAmount,2)." $\n";
        echo "تاريخ إنشاء الدفعة: ".date('Y-m-d H:i', strtotime($firstDate))."\n";
        echo "إجمالي عدد الأكواد: ".$totalCount."\n";
        echo "فعّال (غير مستخدم): ".$activeCount."\n";
        echo "مستخدَم: ".$usedCount."\n";
        echo "تاريخ التصدير: ".date('Y-m-d H:i')."\n";
        echo "----------------------------------------\n";
    }
    exit;
}

$pageTitle = 'بطاقات الشحن — ' . SITE_NAME;

// إنشاء الجدول
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `recharge_cards` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(32) NOT NULL UNIQUE,
  `amount` DECIMAL(10,4) NOT NULL,
  `status` ENUM('active','used','disabled') DEFAULT 'active',
  `used_by` INT DEFAULT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `batch_id` VARCHAR(20) DEFAULT NULL,
  `note` VARCHAR(200) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_code` (`code`), KEY `idx_status` (`status`), KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// توليد كود مشفر 24 حرف
function genCardCode() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // بدون 0,O,1,I للوضوح
    $code = '';
    $bytes = random_bytes(24);
    for ($i = 0; $i < 24; $i++) {
        $code .= $chars[ord($bytes[$i]) % strlen($chars)];
    }
    // تنسيق: XXXXXXXX-XXXXXXXX-XXXXXXXX
    return substr($code,0,8).'-'.substr($code,8,8).'-'.substr($code,16,8);
}

// تعطيل بطاقة
if ($action === 'disable' && $id) {
    $pdo->prepare("UPDATE recharge_cards SET status='disabled' WHERE id=? AND status='active'")->execute([$id]);
    redirect(SITE_URL.'/admin/recharge_cards.php');
}

// حذف دفعة
if ($action === 'delete_batch' && $_GET['batch']) {
    $batch = $_GET['batch'];
    $pdo->prepare("DELETE FROM recharge_cards WHERE batch_id=? AND status='active'")->execute([$batch]);
    flashMessage('success','تم حذف الدفعة (الأكواد غير المستخدمة)');
    redirect(SITE_URL.'/admin/recharge_cards.php');
}

// توليد أكواد
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['generate'])) {
    $amount   = (float)($_POST['amount'] ?? 0);
    $count    = min((int)($_POST['count'] ?? 1), 500);
    $note     = trim($_POST['note'] ?? '');
    $batchId  = date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));

    if ($amount <= 0 || $count < 1) { flashMessage('danger','قيمة أو عدد غير صحيح'); redirect(SITE_URL.'/admin/recharge_cards.php'); }

    $pdo->beginTransaction();
    $generated = 0;
    for ($i = 0; $i < $count; $i++) {
        $attempts = 0;
        do {
            $code = genCardCode();
            $chk = $pdo->prepare("SELECT id FROM recharge_cards WHERE code=?");
            $chk->execute([$code]);
            $attempts++;
        } while ($chk->fetch() && $attempts < 10);
        $pdo->prepare("INSERT INTO recharge_cards (code,amount,batch_id,note,created_by) VALUES (?,?,?,?,?)")
            ->execute([$code, $amount, $batchId, $note?:null, $_SESSION['user_id']]);
        $generated++;
    }
    $pdo->commit();
    flashMessage('success',"✅ تم توليد {$generated} كود بقيمة ".number_format($amount,2)."$ — دفعة: {$batchId}");
    redirect(SITE_URL.'/admin/recharge_cards.php?batch='.$batchId);
}

// فلتر
$filterStatus = $_GET['status'] ?? '';
$filterBatch  = $_GET['batch']  ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1,(int)($_GET['page']??1));
$limit        = 50; $offset = ($page-1)*$limit;

$where = "WHERE 1=1";
$params = [];
if ($filterStatus) { $where .= " AND c.status=?"; $params[]=$filterStatus; }
if ($filterBatch)  { $where .= " AND c.batch_id=?"; $params[]=$filterBatch; }
if ($search)       { $where .= " AND (c.code LIKE ? OR c.note LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }

$total = $pdo->prepare("SELECT COUNT(*) FROM recharge_cards c $where");
$total->execute($params); $total=(int)$total->fetchColumn();

$cards = $pdo->prepare("
    SELECT c.*, u.username as used_by_name
    FROM recharge_cards c
    LEFT JOIN users u ON c.used_by=u.id
    $where ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset
");
$cards->execute($params); $cards=$cards->fetchAll();

// إحصاءات
$stats = $pdo->query("SELECT
    COUNT(*) as total,
    SUM(status='active') as active,
    SUM(status='used') as used,
    SUM(status='disabled') as disabled,
    SUM(CASE WHEN status='used' THEN amount ELSE 0 END) as total_charged,
    SUM(CASE WHEN status='active' THEN amount ELSE 0 END) as pending_value
FROM recharge_cards")->fetch();

// الدفعات
$batches = $pdo->query("SELECT batch_id, amount, COUNT(*) as total,
    SUM(status='active') as active, SUM(status='used') as used, MIN(created_at) as created_at
    FROM recharge_cards GROUP BY batch_id, amount ORDER BY created_at DESC LIMIT 20")->fetchAll();

// ── رفع حظر IP ────────────────────────────────────────────────────────────────
if (isset($_GET['unblock_ip']) && isAdmin()) {
    $unblockIp = $_GET['unblock_ip'];
    // جلب user_id من سجل الحظر
    $bRow = $pdo->prepare("SELECT user_id FROM card_ip_blocks WHERE ip=?");
    $bRow->execute([$unblockIp]); $bRow = $bRow->fetch();
    $blockedUid = $bRow ? (int)$bRow['user_id'] : 0;

    // حذف الحظر
    $pdo->prepare("DELETE FROM card_ip_blocks WHERE ip=?")->execute([$unblockIp]);

    // تسجيل رفع الحظر — يمنحه 3 محاولات فقط
    if ($blockedUid) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS card_unblock_log (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, ip VARCHAR(50) NOT NULL, unblocked_by ENUM('admin','auto') DEFAULT 'admin', post_unblock_attempts INT DEFAULT 0, unblocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO card_unblock_log (user_id,ip,unblocked_by) VALUES (?,?,'admin')")
                ->execute([$blockedUid, $unblockIp]);
            // لا نحذف السجل — نسجل رفع الحظر فقط، والـ API سيحسب المحاولات بعده
        } catch(Exception $e) {}
    }

    $postLimit = (int)(getSetting('card_post_unblock_attempts') ?: 3);
    flashMessage('success', "✅ تم رفع الحظر — يُسمح لـ #$blockedUid بـ $postLimit محاولات جديدة فقط");
    redirect(SITE_URL.'/admin/recharge_cards.php?tab=attempts');
}
if (isset($_GET['clear_attempts']) && isAdmin()) {
    $uid = (int)$_GET['clear_attempts'];
    $pdo->prepare("DELETE FROM card_failed_attempts WHERE user_id=?")->execute([$uid]);
    flashMessage('success', 'تم مسح محاولات المستخدم #'.$uid);
    redirect(SITE_URL.'/admin/recharge_cards.php?tab=attempts');
}

// ── حذف السجل بفلتر ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_log']) && isAdmin()) {
    $delUid   = (int)($_POST['del_user_id'] ?? 0);
    $delFrom  = trim($_POST['del_from'] ?? '');
    $delTo    = trim($_POST['del_to']   ?? '');
    $delAll   = isset($_POST['del_all']);

    if ($delAll) {
        $pdo->exec("DELETE FROM card_failed_attempts");
        flashMessage('success', '✅ تم حذف جميع سجلات المحاولات');
    } elseif ($delUid && !$delFrom && !$delTo) {
        $pdo->prepare("DELETE FROM card_failed_attempts WHERE user_id=?")->execute([$delUid]);
        flashMessage('success', "✅ تم حذف سجل المستخدم #$delUid");
    } elseif ($delFrom && $delTo) {
        $w = "WHERE DATE(created_at) BETWEEN ? AND ?"; $p = [$delFrom, $delTo];
        if ($delUid) { $w .= " AND user_id=?"; $p[] = $delUid; }
        $pdo->prepare("DELETE FROM card_failed_attempts $w")->execute($p);
        flashMessage('success', "✅ تم حذف السجلات من $delFrom إلى $delTo");
    } elseif ($delFrom) {
        $w = "WHERE DATE(created_at) >= ?"; $p = [$delFrom];
        if ($delUid) { $w .= " AND user_id=?"; $p[] = $delUid; }
        $pdo->prepare("DELETE FROM card_failed_attempts $w")->execute($p);
        flashMessage('success', "✅ تم الحذف من تاريخ $delFrom");
    } elseif ($delTo) {
        $w = "WHERE DATE(created_at) <= ?"; $p = [$delTo];
        if ($delUid) { $w .= " AND user_id=?"; $p[] = $delUid; }
        $pdo->prepare("DELETE FROM card_failed_attempts $w")->execute($p);
        flashMessage('success', "✅ تم الحذف حتى تاريخ $delTo");
    } else {
        flashMessage('danger', 'يرجى تحديد فلتر أو تفعيل "حذف الكل"');
    }
    redirect(SITE_URL.'/admin/recharge_cards.php?tab=attempts');
}

// ── إعدادات المحاولات ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_attempt_settings'])) {
    $max   = max(1,(int)$_POST['card_max_attempts']);
    $hours = max(1,(int)$_POST['card_auto_block_hours']);
    $postUnblock = max(1,(int)($_POST['card_post_unblock_attempts'] ?? 3));
    foreach(['card_max_attempts'=>$max,'card_auto_block_hours'=>$hours,'card_post_unblock_attempts'=>$postUnblock] as $k=>$v) {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$v,$v]);
    }
    flashMessage('success','✅ تم حفظ الإعدادات');
    redirect(SITE_URL.'/admin/recharge_cards.php?tab=attempts');
}

// ── جلب بيانات المحاولات ───────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'cards';
$attemptsData = []; $blockedIPs = []; $topOffenders = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS card_failed_attempts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, ip VARCHAR(50) NOT NULL, code_tried VARCHAR(50) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_user_date (user_id,created_at), KEY idx_ip_date (ip,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS card_ip_blocks (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(50) NOT NULL UNIQUE, user_id INT DEFAULT NULL, reason VARCHAR(200) DEFAULT NULL, blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, unblock_at TIMESTAMP NULL DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($activeTab === 'attempts') {
        // آخر المحاولات الفاشلة
        $attemptsData = $pdo->query("
            SELECT fa.*, u.username, u.full_name,
                   COUNT(*) OVER (PARTITION BY fa.user_id, DATE(fa.created_at)) as daily_count
            FROM card_failed_attempts fa
            LEFT JOIN users u ON fa.user_id=u.id
            ORDER BY fa.created_at DESC LIMIT 100
        ")->fetchAll();

        // الـ IPs المحظورة
        $blockedIPs = $pdo->query("
            SELECT b.*, u.username
            FROM card_ip_blocks b
            LEFT JOIN users u ON b.user_id=u.id
            WHERE b.unblock_at IS NULL OR b.unblock_at > NOW()
            ORDER BY b.blocked_at DESC
        ")->fetchAll();

        // أكثر المحاولين (اليوم)
        $topOffenders = $pdo->query("
            SELECT fa.user_id, u.username, u.full_name, fa.ip,
                   COUNT(*) as attempts_today,
                   MAX(fa.created_at) as last_attempt
            FROM card_failed_attempts fa
            LEFT JOIN users u ON fa.user_id=u.id
            WHERE DATE(fa.created_at)=CURDATE()
            GROUP BY fa.user_id, fa.ip
            ORDER BY attempts_today DESC LIMIT 20
        ")->fetchAll();
    }
} catch(Exception $e) {}

include 'header.php';
?>
<style>
.card-code{font-family:'Courier New',monospace;font-size:1rem;font-weight:900;letter-spacing:2px;color:var(--cyan);background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:8px;padding:6px 12px;display:inline-block}
.batch-row{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:8px}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-credit-card"></i></div>
      بطاقات الشحن
    </div>
  </div>
</div>

<!-- تبويبات -->
<div style="display:flex;gap:8px;margin-bottom:16px">
  <a href="?tab=cards" class="btn btn-sm <?=$activeTab==='cards'?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-ticket-alt"></i> البطاقات
  </a>
  <a href="?tab=attempts" class="btn btn-sm <?=$activeTab==='attempts'?'btn-danger':'btn-secondary'?>">
    <i class="fas fa-shield-alt"></i> المحاولات الفاشلة
    <?php try { $todayFails=(int)$pdo->query("SELECT COUNT(*) FROM card_failed_attempts WHERE DATE(created_at)=CURDATE()")->fetchColumn(); if($todayFails>0) echo '<span style="background:rgba(255,68,85,.3);padding:1px 7px;border-radius:10px;font-size:11px">'.$todayFails.'</span>'; } catch(Exception $e) {} ?>
  </a>
</div>

<?php if($activeTab==='attempts'): ?>
<!-- ══ تبويب المحاولات ══ -->

<!-- الإعدادات -->
<div style="display:grid;grid-template-columns:280px 1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-cog"></i> إعدادات الحماية</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_attempt_settings" value="1">
        <div class="form-group">
          <label>الحد الأقصى للمحاولات الخاطئة/يوم</label>
          <input type="number" name="card_max_attempts" class="form-control" value="<?=getSetting('card_max_attempts')?:10?>" min="1" max="100">
        </div>
        <div class="form-group">
          <label>مدة الحظر التلقائي (ساعات)</label>
          <input type="number" name="card_auto_block_hours" class="form-control" value="<?=getSetting('card_auto_block_hours')?:24?>" min="1" max="720">
        </div>
        <div class="form-group">
          <label>محاولات ما بعد رفع الحظر</label>
          <input type="number" name="card_post_unblock_attempts" class="form-control" value="<?=getSetting('card_post_unblock_attempts')?:3?>" min="1" max="20">
          <div class="form-hint">عدد المحاولات المسموح بها بعد رفع الحظر يدوياً أو تلقائياً</div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%"><i class="fas fa-save"></i> حفظ</button>
      </form>

      <hr style="border-color:var(--border);margin:16px 0">

      <!-- حذف السجل -->
      <div style="font-weight:700;font-size:.85rem;margin-bottom:10px;color:#ff4455">
        <i class="fas fa-trash"></i> حذف السجل
      </div>
      <form method="POST" onsubmit="return confirm('تأكيد الحذف؟ لا يمكن التراجع!')">
        <?= adminCsrfField() ?>
        <input type="hidden" name="delete_log" value="1">
        <div class="form-group">
          <label style="font-size:.78rem">مستخدم معين (ID) — اختياري</label>
          <input type="number" name="del_user_id" class="form-control" placeholder="فارغ = جميع المستخدمين">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div class="form-group">
            <label style="font-size:.78rem">من تاريخ</label>
            <input type="date" name="del_from" class="form-control" style="padding:5px 8px;font-size:.8rem">
          </div>
          <div class="form-group">
            <label style="font-size:.78rem">إلى تاريخ</label>
            <input type="date" name="del_to" class="form-control" style="padding:5px 8px;font-size:.8rem">
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:10px;font-size:.82rem;color:#ff4455">
          <input type="checkbox" name="del_all" onchange="this.form.del_from.disabled=this.checked;this.form.del_to.disabled=this.checked;this.form.del_user_id.disabled=this.checked">
          حذف جميع السجلات (تجاهل الفلاتر)
        </label>
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">
          <i class="fas fa-trash"></i> تنفيذ الحذف
        </button>
      </form>
    </div>
  </div>

  <!-- أكثر المحاولين اليوم -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-user-slash" style="color:#ff4455"></i> أكثر المحاولين اليوم</div></div>
    <?php if(empty($topOffenders)): ?>
    <div class="card-body" style="text-align:center;color:var(--text3)">لا محاولات اليوم ✅</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>المستخدم</th><th>الـ IP</th><th>المحاولات</th><th>آخر محاولة</th><th></th></tr></thead>
        <tbody>
        <?php foreach($topOffenders as $of): $maxA=(int)(getSetting('card_max_attempts')?:10); ?>
        <tr style="<?=$of['attempts_today']>=$maxA?'background:rgba(255,68,85,.06)':''?>">
          <td><strong><?=htmlspecialchars($of['full_name']?:$of['username']?:'#'.$of['user_id'])?></strong></td>
          <td style="font-family:monospace;font-size:.78rem"><?=htmlspecialchars($of['ip'])?></td>
          <td>
            <span style="background:<?=$of['attempts_today']>=$maxA?'rgba(255,68,85,.2)':'rgba(245,166,35,.15)'?>;color:<?=$of['attempts_today']>=$maxA?'#ff4455':'#f5a623'?>;padding:2px 10px;border-radius:20px;font-weight:700">
              <?=$of['attempts_today']?>
            </span>
          </td>
          <td style="font-size:11px;color:var(--text3)"><?=date('H:i',strtotime($of['last_attempt']))?></td>
          <td>
            <a href="?tab=attempts&clear_attempts=<?=$of['user_id']?>" class="btn btn-xs btn-secondary" onclick="return confirm('مسح محاولات هذا المستخدم؟')"><i class="fas fa-trash"></i></a>
            <a href="?tab=attempts&unblock_ip=<?=urlencode($of['ip'])?>" class="btn btn-xs btn-success" title="رفع حظر IP"><i class="fas fa-unlock"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- الـ IPs المحظورة -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-ban" style="color:#ff4455"></i> IPs محظورة (<?=count($blockedIPs)?>)</div></div>
    <?php if(empty($blockedIPs)): ?>
    <div class="card-body" style="text-align:center;color:var(--text3)">لا يوجد حظر نشط ✅</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>IP</th><th>المستخدم</th><th>السبب</th><th>ينتهي</th><th></th></tr></thead>
        <tbody>
        <?php foreach($blockedIPs as $b): ?>
        <tr>
          <td style="font-family:monospace;font-size:.78rem;color:#ff4455"><?=htmlspecialchars($b['ip'])?></td>
          <td style="font-size:.8rem"><?=htmlspecialchars($b['username']??'—')?></td>
          <td style="font-size:.75rem;color:var(--text3)"><?=htmlspecialchars($b['reason']??'')?></td>
          <td style="font-size:.75rem;color:#f5a623"><?=$b['unblock_at']?date('d/m H:i',strtotime($b['unblock_at'])):'دائم'?></td>
          <td>
            <a href="?tab=attempts&unblock_ip=<?=urlencode($b['ip'])?>" class="btn btn-xs btn-success" onclick="return confirm('رفع الحظر؟')">
              <i class="fas fa-unlock"></i> رفع
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- سجل المحاولات الأخيرة -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-history"></i> آخر المحاولات الفاشلة</div>
    <div style="font-size:.75rem;color:var(--text3)">آخر 100 محاولة</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>الوقت</th><th>المستخدم</th><th>الـ IP</th><th>الكود المحاوَل</th><th>محاولاته اليوم</th></tr></thead>
      <tbody>
      <?php if(empty($attemptsData)): ?>
      <tr><td colspan="5" style="text-align:center;color:var(--text3);padding:2rem">لا توجد محاولات فاشلة ✅</td></tr>
      <?php else: foreach($attemptsData as $a): $maxA=(int)(getSetting('card_max_attempts')?:10); ?>
      <tr style="<?=$a['daily_count']>=$maxA?'background:rgba(255,68,85,.05)':''?>">
        <td style="font-size:.78rem;color:var(--text3);white-space:nowrap"><?=date('d/m H:i:s',strtotime($a['created_at']))?></td>
        <td>
          <strong style="font-size:.85rem"><?=htmlspecialchars($a['full_name']?:$a['username']?:'#'.$a['user_id'])?></strong>
          <div style="font-size:.7rem;color:var(--text3)">#<?=$a['user_id']?></div>
        </td>
        <td style="font-family:monospace;font-size:.78rem"><?=htmlspecialchars($a['ip'])?></td>
        <td>
          <span style="background:rgba(255,68,85,.1);color:#ff6b6b;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:.78rem;letter-spacing:1px">
            <?=htmlspecialchars($a['code_tried'])?>***
          </span>
        </td>
        <td>
          <span style="font-weight:700;color:<?=$a['daily_count']>=$maxA?'#ff4455':($a['daily_count']>=5?'#f5a623':'var(--text2)')?>">
            <?=$a['daily_count']?>/<?=$maxA?>
          </span>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<!-- ══ تبويب البطاقات (الأصلي) ══ -->

<!-- إحصاءات -->
<div style="display:grid;grid-template-columns:repeat(3,1fr) repeat(2,1fr);gap:10px;margin-bottom:16px">
  <?php foreach([
    ['إجمالي الأكواد',$stats['total'],'hashtag','var(--text2)'],
    ['فعّالة',$stats['active'],'check-circle','#00e676'],
    ['مستخدمة',$stats['used'],'check-double','var(--primary)'],
    ['إجمالي المُشحون',number_format($stats['total_charged'],2).'$','dollar-sign','#f5a623'],
    ['قيمة الفعّالة',number_format($stats['pending_value'],2).'$','wallet','#00d4aa'],
  ] as [$lbl,$val,$ico,$col]): ?>
  <div class="card" style="text-align:center;padding:12px">
    <div style="font-size:1.4rem;font-weight:900;color:<?=$col?>"><?=$val?></div>
    <div style="font-size:.72rem;color:var(--text3);margin-top:3px"><i class="fas fa-<?=$ico?>"></i> <?=$lbl?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:320px 1fr;gap:16px">

<!-- فورم التوليد -->
<div>
  <div class="card mb-2">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-magic"></i> توليد أكواد جديدة</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="generate" value="1">
        <div class="form-group">
          <label><i class="fas fa-dollar-sign" style="color:#f5a623"></i> قيمة كل كود ($) *</label>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">
            <?php foreach([1,2,5,10,20,50,100,200,500] as $v): ?>
            <button type="button" onclick="document.getElementById('amountInp').value=<?=$v?>"
              style="padding:4px 10px;border-radius:8px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text2);cursor:pointer;font-family:var(--font);font-size:.8rem;font-weight:700"><?=$v?>$</button>
            <?php endforeach; ?>
          </div>
          <input type="number" id="amountInp" name="amount" class="form-control" placeholder="أو أدخل قيمة مخصصة" min="0.01" step="0.01" required>
        </div>
        <div class="form-group">
          <label><i class="fas fa-layer-group"></i> عدد الأكواد *</label>
          <input type="number" name="count" class="form-control" value="10" min="1" max="500" required>
          <div class="form-hint">أقصى 500 كود في المرة الواحدة</div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-sticky-note"></i> ملاحظة (اختياري)</label>
          <input type="text" name="note" class="form-control" placeholder="مثال: حملة رمضان 2026">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fas fa-magic"></i> توليد الأكواد
        </button>
      </form>
    </div>
  </div>

  <!-- الدفعات -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-layer-group"></i> الدفعات (<?=count($batches)?>)</div></div>
    <div style="padding:8px;max-height:350px;overflow-y:auto">
      <?php foreach($batches as $b): ?>
      <div class="batch-row">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
          <code style="font-size:.75rem;color:var(--cyan)"><?=htmlspecialchars($b['batch_id'])?></code>
          <span style="font-weight:900;color:#f5a623"><?=number_format($b['amount'],2)?>$</span>
        </div>
        <div style="font-size:.72rem;color:var(--text3);display:flex;gap:10px">
          <span>الكل: <strong style="color:var(--text)"><?=$b['total']?></strong></span>
          <span>فعّال: <strong style="color:#00e676"><?=$b['active']?></strong></span>
          <span>مستخدم: <strong style="color:var(--primary)"><?=$b['used']?></strong></span>
        </div>
        <div style="display:flex;gap:6px;margin-top:6px">
          <a href="?batch=<?=urlencode($b['batch_id'])?>" class="btn btn-sm btn-secondary" style="font-size:10px;padding:3px 8px"><i class="fas fa-eye"></i> عرض</a>
          <a href="?batch=<?=urlencode($b['batch_id'])?>&export=1" class="btn btn-sm btn-primary" style="font-size:10px;padding:3px 8px"><i class="fas fa-download"></i> تصدير</a>
          <?php if($b['active']>0): ?>
          <a href="?action=delete_batch&batch=<?=urlencode($b['batch_id'])?>" class="btn btn-sm btn-danger" style="font-size:10px;padding:3px 8px" onclick="return confirm('حذف الأكواد غير المستخدمة؟')"><i class="fas fa-trash"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- قائمة الأكواد -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-list"></i> الأكواد
      <?php if($filterBatch): ?><span style="font-size:.75rem;color:var(--cyan)">— دفعة: <?=htmlspecialchars($filterBatch)?></span><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px">
      <?php if($filterBatch): ?>
      <a href="?batch=<?=urlencode($filterBatch)?>&export=1" class="btn btn-success btn-sm"><i class="fas fa-download"></i> تصدير</a>
      <?php endif; ?>
      <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> إلغاء الفلتر</a>
    </div>
  </div>

  <!-- بحث وفلتر -->
  <div style="padding:10px 12px;border-bottom:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
      <?php if($filterBatch): ?><input type="hidden" name="batch" value="<?=htmlspecialchars($filterBatch)?>"> <?php endif; ?>
      <input type="text" name="q" class="form-control" style="flex:1;min-width:150px" placeholder="🔍 بحث بالكود أو الملاحظة" value="<?=htmlspecialchars($search)?>">
      <select name="status" class="form-control" style="width:auto">
        <option value="">كل الحالات</option>
        <option value="active"   <?=$filterStatus==='active'?'selected':''?>>فعّالة</option>
        <option value="used"     <?=$filterStatus==='used'?'selected':''?>>مستخدمة</option>
        <option value="disabled" <?=$filterStatus==='disabled'?'selected':''?>>معطلة</option>
      </select>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>الكود</th><th>القيمة</th><th>الحالة</th><th>استُخدم بواسطة</th><th>التاريخ</th><th></th></tr>
      </thead>
      <tbody>
      <?php if(empty($cards)): ?>
      <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text3)">لا توجد أكواد</td></tr>
      <?php else: foreach($cards as $c): ?>
      <tr>
        <td>
          <span class="card-code"><?=htmlspecialchars($c['code'])?></span>
          <?php if($c['note']): ?><div style="font-size:10px;color:var(--text3);margin-top:3px"><?=htmlspecialchars($c['note'])?></div><?php endif; ?>
        </td>
        <td style="font-weight:900;color:#f5a623;font-size:.95rem"><?=number_format($c['amount'],2)?> $</td>
        <td>
          <?php if($c['status']==='active'): ?>
          <span class="badge badge-success">✓ فعّال</span>
          <?php elseif($c['status']==='used'): ?>
          <span class="badge badge-primary">✓ مستخدم</span>
          <?php else: ?>
          <span class="badge badge-danger">✗ معطل</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['used_by_name']): ?>
          <span style="font-size:.82rem;color:var(--cyan)"><?=htmlspecialchars($c['used_by_name'])?></span>
          <div style="font-size:10px;color:var(--text3)"><?=date('d/m/Y H:i',strtotime($c['used_at']))?></div>
          <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
        </td>
        <td style="font-size:11px;color:var(--text3)"><?=date('d/m/Y',strtotime($c['created_at']))?></td>
        <td>
          <?php if($c['status']==='active'): ?>
          <a href="?action=disable&id=<?=$c['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('تعطيل هذا الكود؟')">
            <i class="fas fa-ban"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($total > $limit): ?>
  <div style="padding:10px 14px;display:flex;gap:6px;justify-content:center">
    <?php for($p=1;$p<=ceil($total/$limit);$p++): ?>
    <a href="?page=<?=$p?><?=$filterStatus?'&status='.$filterStatus:''?><?=$filterBatch?'&batch='.urlencode($filterBatch):''?>"
       style="padding:4px 10px;border-radius:8px;border:1px solid var(--border);font-size:.8rem;background:<?=$p==$page?'var(--primary)':'var(--bg2)'?>;color:<?=$p==$page?'#fff':'var(--text2)'?>">
      <?=$p?>
    </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<?php endif; // end tab check ?>

<?php include 'footer.php'; ?>
