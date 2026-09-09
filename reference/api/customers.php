<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_customers_view');
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة العملاء - ' . SITE_NAME;

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ─── تبديل حالة الحساب ─────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_edit')) { flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/customers.php'); }
    $pdo->prepare("UPDATE users SET status=IF(status=1,0,1) WHERE id=? AND role='customer'")->execute([$id]);
    flashMessage('success','تم تحديث حالة الحساب');
    redirect(SITE_URL.'/admin/customers.php');
}

// ─── تصريح / حظر جهاز ─────────────────────────────────────────────────────
if ($action === 'approve_device' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_devices')) { flashMessage('danger','ليس لديك صلاحية تصريح الأجهزة'); redirect(SITE_URL.'/admin/customers.php'); }
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("UPDATE user_devices SET status='approved',approved_by=?,approved_at=NOW() WHERE id=? AND user_id=?")
            ->execute([$_SESSION['user_id'], $did, $id]);
        logStaffAction($pdo,'approve_device','user',$id,'تصريح جهاز للعميل #'.$id);
    flashMessage('success','تم تصريح الجهاز بنجاح');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}
if ($action === 'block_device' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_devices')) { flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/customers.php'); }
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$did, $id]);
        flashMessage('success','تم حظر الجهاز');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}
if ($action === 'delete_device' && $id) {
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("DELETE FROM user_devices WHERE id=? AND user_id=?")->execute([$did, $id]);
        flashMessage('success','تم حذف الجهاز');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تبديل API ──────────────────────────────────────────────────────────────
if ($action === 'toggle_api' && $id) {
    try {
        $u = $pdo->prepare("SELECT api_enabled,api_key FROM users WHERE id=?"); $u->execute([$id]); $u=$u->fetch();
        if ($u['api_enabled']) {
            $pdo->prepare("UPDATE users SET api_enabled=0 WHERE id=?")->execute([$id]);
            flashMessage('success','تم إيقاف API للعميل');
        } else {
            $key    = bin2hex(random_bytes(20));
            $secret = bin2hex(random_bytes(20));
            $pdo->prepare("UPDATE users SET api_enabled=1,api_key=COALESCE(api_key,?),api_secret=COALESCE(api_secret,?) WHERE id=?")->execute([$key,$secret,$id]);
            flashMessage('success','تم تفعيل API للعميل');
        }
    } catch (\PDOException $e) {
        flashMessage('danger','يجب تشغيل update_database.sql أولاً لإضافة أعمدة API');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تجديد مفاتيح API ──────────────────────────────────────────────────────
if ($action === 'regen_api' && $id) {
    $key    = bin2hex(random_bytes(20));
    $secret = bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE users SET api_key=?,api_secret=? WHERE id=?")->execute([$key,$secret,$id]);
    flashMessage('success','تم تجديد مفاتيح API');
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تعديل بيانات العميل ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_user'])) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_edit')) { flashMessage('danger','ليس لديك صلاحية تعديل بيانات العملاء'); redirect(SITE_URL.'/admin/customers.php'); }
    $uid      = (int)$_POST['user_id'];
    $fullName = trim($_POST['full_name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $newPw    = trim($_POST['new_password']);

    $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=? WHERE id=? AND role='customer'")
        ->execute([$fullName,$email,$phone,$uid]);

    if ($newPw) {
        if (strlen($newPw) < 6) {
            flashMessage('danger','كلمة المرور يجب 6 أحرف على الأقل');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPw,PASSWORD_DEFAULT),$uid]);
            flashMessage('success','تم تحديث بيانات العميل وكلمة المرور');
        }
    } else {
        logStaffAction($pdo,'edit_customer','user',$uid,'تعديل بيانات العميل #'.$uid);
    flashMessage('success','تم تحديث بيانات العميل');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$uid);
}

// ─── تعديل رصيد ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_balance'])) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_balance')) { flashMessage('danger','ليس لديك صلاحية تعديل الرصيد'); redirect(SITE_URL.'/admin/customers.php'); }
    $uid    = (int)$_POST['user_id'];
    $amount = (float)$_POST['amount'];
    $type   = $_POST['type'];
    $desc   = trim($_POST['description']) ?: 'تعديل يدوي من الإدارة';
    $u2 = $pdo->prepare("SELECT * FROM users WHERE id=?"); $u2->execute([$uid]); $u2=$u2->fetch();
    if ($u2 && $amount > 0) {
        $nb = $type==='credit' ? $u2['balance']+$amount : max(0,$u2['balance']-$amount);
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$nb,$uid]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?)")
            ->execute([$uid,$type,$amount,$u2['balance'],$nb,$desc]);
        logStaffAction($pdo,'update_balance','user',$uid,'تعديل رصيد العميل #'.$uid.' — '.$type.' '.$amount);
    flashMessage('success','تم تحديث الرصيد');
    }
    redirect(SITE_URL.'/admin/customers.php'.($id?"?action=view&id=$id":''));
}

// ─── صفحة تفاصيل عميل ──────────────────────────────────────────────────────
$viewCustomer = null;
$viewDevices  = [];
$viewOrders   = [];
if ($action === 'view' && $id) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='customer'"); $s->execute([$id]); $viewCustomer=$s->fetch();
    if (!$viewCustomer) { flashMessage('danger','العميل غير موجود'); redirect(SITE_URL.'/admin/customers.php'); }

    $ds = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? ORDER BY created_at DESC"); $ds->execute([$id]); $viewDevices=$ds->fetchAll();
    $os = $pdo->prepare("SELECT o.*,s.name as svc_name FROM orders o JOIN services s ON o.service_id=s.id WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 10"); $os->execute([$id]); $viewOrders=$os->fetchAll();
    // KYC
    try {
        $kycStmt = $pdo->prepare("SELECT k.*, u2.username as reviewer FROM kyc_requests k LEFT JOIN users u2 ON k.reviewed_by=u2.id WHERE k.user_id=? ORDER BY k.id DESC LIMIT 1");
        $kycStmt->execute([$id]);
        $viewKyc = $kycStmt->fetch();
    } catch(Exception $e) { $viewKyc = null; }
}

// ─── قائمة العملاء ──────────────────────────────────────────────────────────
$custFilter = $_GET['filter'] ?? 'all'; // all | google | normal
$googleWhere = $custFilter === 'google' ? "AND u.google_id IS NOT NULL AND u.google_id != ''" :
               ($custFilter === 'normal' ? "AND (u.google_id IS NULL OR u.google_id = '')" : '');

try {
    $customers = $pdo->query("
        SELECT u.*,
            COUNT(DISTINCT o.id) as orders_count,
            COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price ELSE 0 END),0) as total_spent,
            COUNT(DISTINCT CASE WHEN d.status='pending' THEN d.id END) as pending_devices,
            (SELECT k.status FROM kyc_requests k WHERE k.user_id=u.id ORDER BY k.id DESC LIMIT 1) as kyc_status
        FROM users u
        LEFT JOIN orders o ON u.id=o.user_id
        LEFT JOIN user_devices d ON u.id=d.user_id
        WHERE u.role='customer' $googleWhere
        GROUP BY u.id ORDER BY u.created_at DESC
    ")->fetchAll();
} catch (\PDOException $e) {
    $customers = $pdo->query("
        SELECT u.*,
            COUNT(DISTINCT o.id) as orders_count,
            COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price ELSE 0 END),0) as total_spent,
            0 as pending_devices,
            NULL as kyc_status
        FROM users u
        LEFT JOIN orders o ON u.id=o.user_id
        WHERE u.role='customer'
        GROUP BY u.id ORDER BY u.created_at DESC
    ")->fetchAll();
}
$googleCount = 0;
try { $googleCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND google_id IS NOT NULL AND google_id != ''")->fetchColumn(); } catch(Exception $e){}

include 'header.php';

$statusColors = ['pending'=>'#f5a623','approved'=>'#00d4aa','blocked'=>'#ff4455'];
$statusLabels = ['pending'=>'انتظار','approved'=>'مصرح','blocked'=>'محظور'];
$deviceTypes  = ['desktop'=>'🖥️','mobile'=>'📱','tablet'=>'📟','unknown'=>'🖥️'];
?>
<style>
.cust-tabs{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:1.5rem;padding-bottom:0}
.cust-tab{padding:10px 20px;border-radius:10px 10px 0 0;cursor:pointer;font-weight:700;font-size:.87rem;color:var(--text2);border:1px solid transparent;border-bottom:none;transition:.2s;background:none;font-family:var(--font)}
.cust-tab.active{background:var(--card2);border-color:var(--border);color:#fff}
.cust-tab-pane{display:none}
.cust-tab-pane.active{display:block}
.device-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px;margin-bottom:10px}
.device-status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.device-icon{font-size:2rem;width:48px;text-align:center;flex-shrink:0}
.device-info{flex:1;min-width:0}
.device-name{font-weight:800;font-size:.95rem}
.device-meta{font-size:.78rem;color:#8895a7;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.device-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.pending-pulse{animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.info-item{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:14px}
.info-item-label{font-size:.75rem;color:#8895a7;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.info-item-value{font-size:1.1rem;font-weight:800}
.api-key-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:.82rem;word-break:break-all;color:var(--text2);margin-top:6px;display:flex;align-items:center;gap:8px}
.api-key-box span{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>

<?php if ($action === 'view' && $viewCustomer): ?>

<!-- ─── رأس صفحة العميل ──────────────────────────────────────────────────── -->
<div class="page-header" style="margin-bottom:1rem">
  <div style="display:flex;align-items:center;gap:14px">
    <a href="customers.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a>
    <div style="width:52px;height:52px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;flex-shrink:0">
      <?= mb_strtoupper(mb_substr($viewCustomer['username'],0,1)) ?>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <?= htmlspecialchars($viewCustomer['username']) ?>
        <?php if($viewCustomer['full_name']): ?><small style="font-size:.7em;color:#8895a7"> — <?=htmlspecialchars($viewCustomer['full_name'])?></small><?php endif; ?>
        <?php if(($viewKyc['status']??'') === 'approved'): ?>
        <span style="background:rgba(0,200,83,.15);color:#00e676;border:1px solid rgba(0,200,83,.35);border-radius:20px;font-size:12px;font-weight:800;padding:2px 10px;display:inline-flex;align-items:center;gap:4px">
          <i class="fas fa-shield-check" style="font-size:10px"></i> موثّق
        </span>
        <?php elseif(($viewKyc['status']??'') === 'pending'): ?>
        <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);border-radius:20px;font-size:12px;font-weight:800;padding:2px 10px">
          ⏳ تحقق معلق
        </span>
        <?php endif; ?>
      </h2>
      <div style="font-size:.82rem;color:#8895a7;margin-top:2px">
        #<?=str_pad($viewCustomer['id'],6,'0',STR_PAD_LEFT)?> &nbsp;·&nbsp;
        <?=htmlspecialchars($viewCustomer['email'])?> &nbsp;·&nbsp;
        <?php if($viewCustomer['status']): ?>
        <span style="color:#00d4aa"><i class="fas fa-circle" style="font-size:7px"></i> حساب نشط</span>
        <?php else: ?>
        <span style="color:#ff4455"><i class="fas fa-circle" style="font-size:7px"></i> حساب موقوف</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?action=toggle&id=<?=$viewCustomer['id']?>" class="btn btn-sm <?=$viewCustomer['status']?'btn-danger':'btn-success'?>"
       onclick="return confirm('<?=$viewCustomer['status']?'إيقاف':'تفعيل'?> هذا الحساب؟')">
      <i class="fas fa-<?=$viewCustomer['status']?'ban':'check'?>"></i>
      <?=$viewCustomer['status']?'إيقاف الحساب':'تفعيل الحساب'?>
    </a>
  </div>
</div>

<!-- ─── بطاقات الإحصاء ──────────────────────────────────────────────────── -->
<?php
$totalOrders  = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?"); $totalOrders->execute([$id]); $totalOrders=$totalOrders->fetchColumn();
$totalSpent   = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE user_id=? AND status='completed'"); $totalSpent->execute([$id]); $totalSpent=$totalSpent->fetchColumn();
$pendingDevs  = count(array_filter($viewDevices, fn($d)=>$d['status']==='pending'));
?>
<div class="info-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="info-item">
    <div class="info-item-label">الرصيد</div>
    <div class="info-item-value" style="color:#00d4aa"><?=formatMoney($viewCustomer['balance'])?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">إجمالي الطلبات</div>
    <div class="info-item-value"><?=$totalOrders?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">إجمالي المدفوع</div>
    <div class="info-item-value" style="color:#6c3fe0"><?=formatMoney($totalSpent)?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">الأجهزة <?=$pendingDevs>0?'<span class="pending-pulse" style="color:#f5a623;font-size:11px"> ('.$pendingDevs.' انتظار)</span>':''?></div>
    <div class="info-item-value"><?=count($viewDevices)?></div>
  </div>
</div>

<!-- ─── تبويبات ──────────────────────────────────────────────────────────── -->
<div class="card">
<div class="cust-tabs">
  <button class="cust-tab active" onclick="switchTab('devices',this)"><i class="fas fa-mobile-alt"></i> الأجهزة (<?=count($viewDevices)?>)</button>
  <button class="cust-tab" onclick="switchTab('kyc',this)" id="kycTabBtn">
    <i class="fas fa-id-card"></i> تحقق الهوية
    <?php if(($viewKyc['status']??'') === 'approved'): ?>
    <span style="background:rgba(0,200,83,.2);color:#00e676;border-radius:10px;padding:1px 7px;font-size:11px;margin-right:4px">✓</span>
    <?php elseif(($viewKyc['status']??'') === 'pending'): ?>
    <span style="background:rgba(245,166,35,.2);color:#f5a623;border-radius:10px;padding:1px 7px;font-size:11px;margin-right:4px">⏳</span>
    <?php endif; ?>
  </button>
  <button class="cust-tab" onclick="switchTab('edit',this)"><i class="fas fa-user-edit"></i> تعديل البيانات</button>
  <button class="cust-tab" onclick="switchTab('balance',this)"><i class="fas fa-wallet"></i> تعديل الرصيد</button>
  <button class="cust-tab" onclick="switchTab('api',this)"><i class="fas fa-code"></i> صلاحيات API</button>
  <button class="cust-tab" onclick="switchTab('orders',this)"><i class="fas fa-list"></i> آخر الطلبات</button>
</div>

<!-- ─── تبويب تحقق الهوية ───────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-kyc">
<?php
$kycTypeLabels = ['national'=>'بطاقة شخصية','passport'=>'جواز سفر','family'=>'بطاقة عائلية','electronic'=>'بطاقة إلكترونية'];
$kycStatusCfg  = [
    'pending'  => ['⏳','#f5a623','قيد المراجعة'],
    'approved' => ['✅','#00e676','موافق عليه'],
    'rejected' => ['✗' ,'#ff4757','مرفوض'],
];
if (!$viewKyc): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-id-card" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.3"></i>
    <div style="font-weight:700;margin-bottom:4px">لم يُقدّم هذا العميل طلب تحقق بعد</div>
    <div style="font-size:.85rem">سيظهر هنا بعد تقديم الطلب من التطبيق</div>
  </div>
<?php else:
  $sc = $kycStatusCfg[$viewKyc['status']] ?? $kycStatusCfg['pending'];
?>

  <!-- شارة الحالة -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:28px"><?= $sc[0] ?></span>
      <div>
        <div style="font-size:16px;font-weight:900;color:<?= $sc[1] ?>"><?= $sc[2] ?></div>
        <div style="font-size:12px;color:#8895a7">
          <?= $viewKyc['reviewed_at'] ? 'تمت المراجعة: '.date('d/m/Y H:i',strtotime($viewKyc['reviewed_at'])) : 'أُرسل: '.date('d/m/Y H:i',strtotime($viewKyc['created_at'])) ?>
          <?php if($viewKyc['reviewer']): ?> · بواسطة: <?= htmlspecialchars($viewKyc['reviewer']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <a href="kyc.php?action=view&id=<?= $viewKyc['id'] ?>" class="btn btn-sm btn-primary">
      <i class="fas fa-external-link-alt"></i> فتح في صفحة التحقق
    </a>
  </div>

  <!-- ملاحظة الرفض -->
  <?php if($viewKyc['status'] === 'rejected' && $viewKyc['admin_note']): ?>
  <div style="background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#ff4757">
    <i class="fas fa-exclamation-circle" style="margin-left:6px"></i>سبب الرفض: <?= htmlspecialchars($viewKyc['admin_note']) ?>
  </div>
  <?php endif; ?>

  <!-- البيانات الشخصية -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
    <?php
    $kycRows = [
      ['fas fa-id-card','نوع الهوية',       $kycTypeLabels[$viewKyc['id_type']] ?? $viewKyc['id_type']],
      ['fas fa-user',   'الاسم كما في الهوية', $viewKyc['full_name']],
      ['fas fa-hashtag','رقم الهوية',        $viewKyc['national_id']],
      ['fas fa-birthday-cake','تاريخ الميلاد', $viewKyc['birth_date'] ? date('d/m/Y',strtotime($viewKyc['birth_date'])) : '—'],
      ['fas fa-map-marker-alt','مكان الميلاد', $viewKyc['birth_place'] ?: '—'],
      ['fas fa-calendar-plus','تاريخ الإصدار', $viewKyc['issue_date']  ? date('d/m/Y',strtotime($viewKyc['issue_date']))  : '—'],
      ['fas fa-calendar-times','تاريخ الانتهاء',$viewKyc['expiry_date'] ? date('d/m/Y',strtotime($viewKyc['expiry_date'])) : '—'],
      ['fas fa-clock','تاريخ الطلب',         date('d/m/Y H:i',strtotime($viewKyc['created_at']))],
    ];
    // حقول إضافية
    $extra = json_decode($viewKyc['extra_fields'] ?? '{}', true) ?: [];
    foreach($extra as $ek => $ev) $kycRows[] = ['fas fa-plus-circle', htmlspecialchars($ek), htmlspecialchars($ev)];
    foreach($kycRows as [$icon,$label,$val]):
    ?>
    <div style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px">
      <div style="font-size:11px;color:#8895a7;margin-bottom:6px;display:flex;align-items:center;gap:5px">
        <i class="<?= $icon ?>" style="color:var(--primary);width:14px;text-align:center"></i><?= $label ?>
      </div>
      <div style="font-size:15px;font-weight:800;color:#fff"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- صور الهوية -->
  <div style="margin-bottom:20px">
    <div style="font-size:13px;font-weight:800;color:#8895a7;margin-bottom:12px;display:flex;align-items:center;gap:6px">
      <i class="fas fa-images" style="color:var(--cyan)"></i> صور الهوية
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <?php if($viewKyc['image_front']): ?>
      <div>
        <div style="font-size:11px;color:#8895a7;margin-bottom:6px;font-weight:700">الوجه الأمامي</div>
        <a href="<?= SITE_URL.'/'.$viewKyc['image_front'] ?>" target="_blank" title="فتح بالحجم الكامل">
          <img src="<?= SITE_URL.'/'.$viewKyc['image_front'] ?>"
            style="width:220px;height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in;transition:.2s"
            onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform=''">
        </a>
      </div>
      <?php endif; ?>
      <?php if($viewKyc['image_back']): ?>
      <div>
        <div style="font-size:11px;color:#8895a7;margin-bottom:6px;font-weight:700">الوجه الخلفي</div>
        <a href="<?= SITE_URL.'/'.$viewKyc['image_back'] ?>" target="_blank" title="فتح بالحجم الكامل">
          <img src="<?= SITE_URL.'/'.$viewKyc['image_back'] ?>"
            style="width:220px;height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in;transition:.2s"
            onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform=''">
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<!-- ─── تبويب الأجهزة ──────────────────────────────────────────────────── -->
<div class="cust-tab-pane active" id="tab-devices">
  <?php if (empty($viewDevices)): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-mobile-alt" style="font-size:3rem;margin-bottom:1rem;display:block;opacity:.3"></i>
    لم يسجّل هذا العميل دخولاً من أي جهاز بعد
  </div>
  <?php else: ?>
  <?php foreach ($viewDevices as $dv): ?>
  <?php $col = $statusColors[$dv['status']]; $lbl = $statusLabels[$dv['status']]; $ico = $deviceTypes[$dv['device_type']] ?? '🖥️'; ?>
  <div class="device-card">
    <div class="device-status-dot" style="background:<?=$col?>;box-shadow:0 0 8px <?=$col?>80;<?=$dv['status']==='pending'?'animation:pulse 1.5s infinite':''?>"></div>
    <div class="device-icon"><?=$ico?></div>
    <div class="device-info">
      <div class="device-name">
        <?=htmlspecialchars($dv['device_name'] ?: 'جهاز غير معروف')?>
        <?php if($dv['is_first_device']): ?><span style="background:rgba(0,212,170,0.15);color:#00d4aa;font-size:10px;padding:2px 8px;border-radius:20px;margin-right:6px">أول جهاز</span><?php endif; ?>
      </div>
      <div class="device-meta">
        <?=htmlspecialchars($dv['browser']??'')?> · <?=htmlspecialchars($dv['os']??'')?>
        · IP: <?=htmlspecialchars($dv['ip_address']??'—')?>
        · آخر نشاط: <?=$dv['last_seen'] ? date('d/m H:i',strtotime($dv['last_seen'])) : '—'?>
      </div>
      <div style="margin-top:5px">
        <span style="background:<?=$col?>22;color:<?=$col?>;border:1px solid <?=$col?>55;font-size:10px;padding:2px 10px;border-radius:20px">
          <?=$lbl?>
        </span>
        <small style="color:#8895a7;margin-right:8px">مضاف: <?=date('d/m/Y',strtotime($dv['created_at']))?></small>
      </div>
    </div>
    <div class="device-actions">
      <?php if($dv['status'] !== 'approved'): ?>
      <a href="?action=approve_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-success" title="تصريح الجهاز">
        <i class="fas fa-check"></i> تصريح
      </a>
      <?php endif; ?>
      <?php if($dv['status'] !== 'blocked'): ?>
      <a href="?action=block_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-warning" title="حظر الجهاز" onclick="return confirm('حظر هذا الجهاز؟')">
        <i class="fas fa-ban"></i>
      </a>
      <?php endif; ?>
      <a href="?action=delete_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-danger" title="حذف الجهاز" onclick="return confirm('حذف سجل هذا الجهاز؟')">
        <i class="fas fa-trash"></i>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ─── تبويب تعديل البيانات ────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-edit">
  <form method="POST">
    <input type="hidden" name="update_user" value="1">
    <input type="hidden" name="user_id" value="<?=$viewCustomer['id']?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>اسم المستخدم</label>
        <input type="text" value="<?=htmlspecialchars($viewCustomer['username'])?>" disabled style="opacity:.5">
      </div>
      <div class="form-group">
        <label>الاسم الكامل</label>
        <input type="text" name="full_name" value="<?=htmlspecialchars($viewCustomer['full_name']??'')?>">
      </div>
      <div class="form-group">
        <label>البريد الإلكتروني *</label>
        <input type="email" name="email" value="<?=htmlspecialchars($viewCustomer['email']??'')?>" required>
      </div>
      <div class="form-group">
        <label>رقم الهاتف</label>
        <input type="tel" name="phone" value="<?=htmlspecialchars($viewCustomer['phone']??'')?>">
      </div>
      <div class="form-group">
        <label>كلمة المرور الجديدة <small style="color:#8895a7">(اتركها فارغة إذا لم تريد تغييرها)</small></label>
        <input type="text" name="new_password" placeholder="أدخل كلمة مرور جديدة..." autocomplete="off">
      </div>
      <div class="form-group" style="display:flex;flex-direction:column;justify-content:flex-end">
        <div style="background:rgba(0,212,170,0.08);border:1px solid rgba(0,212,170,0.2);border-radius:10px;padding:12px;font-size:.83rem;color:#8895a7">
          <i class="fas fa-info-circle" style="color:#00d4aa"></i>
          يمكنك تغيير كلمة المرور وإرسالها للعميل عبر الواتساب/بريد
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ التعديلات</button>
  </form>
</div>

<!-- ─── تبويب تعديل الرصيد ──────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-balance">
  <div style="background:var(--card2);border-radius:12px;padding:16px;margin-bottom:1.5rem;display:flex;align-items:center;gap:14px">
    <div style="font-size:2rem">💰</div>
    <div>
      <div style="font-size:.8rem;color:#8895a7">الرصيد الحالي</div>
      <div style="font-size:1.8rem;font-weight:900;color:#00d4aa"><?=formatMoney($viewCustomer['balance'])?></div>
    </div>
  </div>
  <form method="POST">
    <input type="hidden" name="update_balance" value="1">
    <input type="hidden" name="user_id" value="<?=$viewCustomer['id']?>">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>نوع العملية</label>
        <select name="type" id="balTypeSelect">
          <option value="credit">➕ إضافة رصيد</option>
          <option value="debit">➖ خصم رصيد</option>
        </select>
      </div>
      <div class="form-group">
        <label>المبلغ ($)</label>
        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
      </div>
      <div class="form-group">
        <label>السبب</label>
        <input type="text" name="description" placeholder="شحن رصيد / حوالة...">
      </div>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-wallet"></i> تأكيد العملية</button>
  </form>
</div>

<!-- ─── تبويب API ──────────────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-api">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:10px">
    <div>
      <div style="font-size:1rem;font-weight:800">صلاحية API للعميل</div>
      <div style="font-size:.83rem;color:#8895a7;margin-top:3px">السماح للعميل بالوصول للخدمات عبر API</div>
    </div>
    <a href="?action=toggle_api&id=<?=$id?>" class="btn btn-sm <?=$viewCustomer['api_enabled']?'btn-danger':'btn-success'?>"
       onclick="return confirm('<?=$viewCustomer['api_enabled']?'إيقاف':'تفعيل'?> صلاحية API؟')">
      <i class="fas fa-<?=$viewCustomer['api_enabled']?'times':'check'?>"></i>
      <?=$viewCustomer['api_enabled']?'إيقاف API':'تفعيل API'?>
    </a>
  </div>

  <?php if($viewCustomer['api_enabled'] && $viewCustomer['api_key']): ?>
  <div style="background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);border-radius:12px;padding:1.25rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <span style="color:#00d4aa;font-weight:800"><i class="fas fa-check-circle"></i> API مفعّل</span>
      <a href="?action=regen_api&id=<?=$id?>" class="btn btn-sm btn-warning" onclick="return confirm('تجديد المفاتيح؟ المفاتيح القديمة ستتوقف عن العمل')">
        <i class="fas fa-sync-alt"></i> تجديد المفاتيح
      </a>
    </div>
    <div class="form-group">
      <label style="font-size:.8rem;color:#8895a7">API Key</label>
      <div class="api-key-box">
        <span><?=htmlspecialchars($viewCustomer['api_key'])?></span>
        <button type="button" class="btn btn-xs btn-secondary" onclick="copyText(this,'<?=htmlspecialchars($viewCustomer['api_key'])?>','key')"><i class="fas fa-copy"></i></button>
      </div>
    </div>
    <div class="form-group" style="margin:0">
      <label style="font-size:.8rem;color:#8895a7">API Secret</label>
      <div class="api-key-box">
        <span id="secretDisplay">••••••••••••••••••••••••••••••••••••••••</span>
        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleSecret('<?=htmlspecialchars($viewCustomer['api_secret'])?>')"><i class="fas fa-eye" id="secretEyeIcon"></i></button>
        <button type="button" class="btn btn-xs btn-secondary" onclick="copyText(this,'<?=htmlspecialchars($viewCustomer['api_secret'])?>','secret')"><i class="fas fa-copy"></i></button>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div style="background:var(--card2);border-radius:12px;padding:2rem;text-align:center;color:#8895a7">
    <i class="fas fa-code" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:1rem"></i>
    <div>API غير مفعّل لهذا العميل</div>
    <div style="font-size:.82rem;margin-top:.5rem">اضغط "تفعيل API" لمنح صلاحية الوصول</div>
  </div>
  <?php endif; ?>
</div>

<!-- ─── تبويب الطلبات ────────────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-orders">
  <?php if (empty($viewOrders)): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">لا توجد طلبات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>الخدمة</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
      <tbody>
        <?php foreach($viewOrders as $o):
          $stMap=['pending'=>['قيد الانتظار','warning'],'processing'=>['تنفيذ','info'],'completed'=>['مكتمل','success'],'cancelled'=>['ملغي','danger'],'failed'=>['فشل','danger']];
          $st=$stMap[$o['status']]??['—','secondary'];
        ?>
        <tr>
          <td><code>#<?=$o['id']?></code></td>
          <td><?=htmlspecialchars($o['svc_name'])?></td>
          <td style="color:#00d4aa;font-weight:800"><?=formatMoney($o['total_price'])?></td>
          <td><span class="badge badge-<?=$st[1]?>"><?=$st[0]?></span></td>
          <td style="color:#8895a7;font-size:.82rem"><?=date('d/m H:i',strtotime($o['created_at']))?></td>
          <td><a href="orders.php?id=<?=$o['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div style="margin-top:1rem">
    <a href="orders.php?user=<?=$id?>" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> عرض كل الطلبات</a>
  </div>
</div>

</div><!-- end card -->

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.cust-tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.cust-tab').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    btn.classList.add('active');
}
let secretVisible = false;
function toggleSecret(val) {
    secretVisible = !secretVisible;
    document.getElementById('secretDisplay').textContent = secretVisible ? val : '••••••••••••••••••••••••••••••••••••••••';
    document.getElementById('secretEyeIcon').className = secretVisible ? 'fas fa-eye-slash' : 'fas fa-eye';
}
function copyText(btn, text, type) {
    navigator.clipboard.writeText(text).then(()=>{
        const old = btn.innerHTML;
        btn.innerHTML='<i class="fas fa-check" style="color:#00d4aa"></i>';
        setTimeout(()=>btn.innerHTML=old, 1500);
    });
}
</script>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
  قائمة العملاء الرئيسية
════════════════════════════════════════════════════════════════════════════ -->

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(16,185,129,0.15)"><i class="fas fa-users" style="color:var(--green)"></i></div>
      إدارة العملاء
    </div>
    <div class="page-header-sub"><?=count($customers)?> عميل مسجل</div>
  </div>
</div>

<!-- فلتر نوع التسجيل -->
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
  <a href="?filter=all" class="btn btn-sm <?=$custFilter==='all'?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-users"></i> الكل <span style="opacity:.7">(<?=count($customers)?>)</span>
  </a>
  <a href="?filter=google" class="btn btn-sm <?=$custFilter==='google'?'btn-primary':'btn-secondary'?>" style="<?=$custFilter==='google'?'':'border-color:rgba(66,133,244,.3)'?>">
    <svg width="14" height="14" viewBox="0 0 48 48" style="margin-left:4px"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
    سجّلوا عبر Google <span style="opacity:.7">(<?=$googleCount?>)</span>
  </a>
  <a href="?filter=normal" class="btn btn-sm <?=$custFilter==='normal'?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-user"></i> تسجيل عادي
  </a>
</div>

<div class="card">
  <div class="filter-bar">
    <div class="filter-search">
      <i class="fas fa-search filter-search-icon"></i>
      <input type="text" id="custSearch" placeholder="بحث باسم أو بريد أو هاتف..." oninput="filterCustomers()">
    </div>
    <div style="margin-right:auto;font-size:12px;color:var(--text3)"><?=count($customers)?> عميل</div>
  </div>
  <div class="table-wrap">
    <table id="custTable">
      <thead>
        <tr><th>#</th><th>العميل</th><th>البريد</th><th>الرصيد</th><th>الطلبات</th><th>التحقق</th><th>API</th><th>الأجهزة</th><th>الحالة</th><th>التسجيل</th><th>إجراءات</th></tr>
      </thead>
      <tbody>
      <?php if($customers): foreach($customers as $c): ?>
      <tr data-search="<?=strtolower(htmlspecialchars($c['username'].' '.($c['email']??'').' '.($c['full_name']??'')))?>">
        <td class="td-id"><?=str_pad($c['id'],6,'0',STR_PAD_LEFT)?></td>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <?php if(!empty($c['google_avatar'])): ?>
            <img src="<?=htmlspecialchars($c['google_avatar'])?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(66,133,244,.3)">
            <?php else: ?>
            <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;flex-shrink:0">
              <?=mb_strtoupper(mb_substr($c['username'],0,1))?>
            </div>
            <?php endif; ?>
            <div>
              <div class="td-bold">
                <?=htmlspecialchars($c['username'])?>
                <?php if(!empty($c['google_id'])): ?>
                <svg width="12" height="12" viewBox="0 0 48 48" style="margin-right:4px;vertical-align:middle" title="سجّل عبر Google"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                <?php endif; ?>
              </div>
              <?php if($c['full_name']): ?><div class="td-muted" style="font-size:11px"><?=htmlspecialchars($c['full_name'])?></div><?php endif; ?>
            </div>
          </div>
        </td>
        <td class="td-muted text-sm"><?=htmlspecialchars($c['email']??'—')?></td>
        <td><span style="font-weight:900;color:<?=$c['balance']>0?'var(--green)':'var(--text2)'?>"><?=formatMoney($c['balance'])?></span></td>
        <td><span class="badge badge-primary"><?=$c['orders_count']?></span></td>
        <td>
          <?php if(($c['kyc_status']??'') === 'approved'): ?>
          <span style="background:rgba(0,200,83,.15);color:#00e676;border:1px solid rgba(0,200,83,.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:3px">
            <i class="fas fa-shield-check" style="font-size:9px"></i> موثّق
          </span>
          <?php elseif(($c['kyc_status']??'') === 'pending'): ?>
          <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">⏳ معلق</span>
          <?php elseif(($c['kyc_status']??'') === 'rejected'): ?>
          <span style="background:rgba(255,71,87,.1);color:#ff4757;border:1px solid rgba(255,71,87,.25);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">✗ مرفوض</span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['api_enabled']): ?>
          <span style="background:rgba(0,212,170,0.15);color:#00d4aa;border:1px solid rgba(0,212,170,0.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">✓ API</span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['pending_devices'] > 0): ?>
          <span style="background:rgba(245,166,35,0.2);color:#f5a623;border:1px solid rgba(245,166,35,0.3);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700" class="pending-pulse">
            ⏳ <?=$c['pending_devices']?> انتظار
          </span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?=$c['status']?'badge-success':'badge-danger'?>"><?=$c['status']?'✓ نشط':'✗ موقوف'?></span>
        </td>
        <td class="td-muted" style="font-size:11px"><?=date('d/m/Y',strtotime($c['created_at']))?></td>
        <td>
          <a href="?action=view&id=<?=$c['id']?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> إدارة</a>
        </td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="10"><div class="empty-state"><span class="empty-state-icon">👥</span><div class="empty-state-title">لا يوجد عملاء بعد</div></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">إجمالي الأرصدة: <?=formatMoney(array_sum(array_column($customers,'balance')))?></div>
</div>

<script>
function filterCustomers() {
  const q = document.getElementById('custSearch').value.toLowerCase();
  document.querySelectorAll('#custTable tbody tr[data-search]').forEach(r=>{
    r.style.display = r.dataset.search.includes(q) ? '' : 'none';
  });
}
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
