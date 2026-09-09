<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_customers_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'طلبات التحقق من الهوية - ' . SITE_NAME;

// إنشاء الجدول إن لم يكن موجوداً
$pdo->exec("CREATE TABLE IF NOT EXISTS `kyc_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `id_type` ENUM('national','passport','family','electronic') NOT NULL DEFAULT 'national',
  `full_name` VARCHAR(255) DEFAULT '',
  `national_id` VARCHAR(100) DEFAULT '',
  `birth_date` DATE NULL,
  `birth_place` VARCHAR(255) DEFAULT '',
  `issue_date` DATE NULL,
  `expiry_date` DATE NULL,
  `image_front` VARCHAR(500) DEFAULT '',
  `image_back` VARCHAR(500) DEFAULT '',
  `extra_fields` JSON DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── الموافقة / الرفض ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'])) {
    if (!$IS_ADMIN && !canAccess($pdo,'perm_customers_edit')) {
        flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/kyc.php');
    }
    $kycId     = (int)$_POST['kyc_id'];
    $decision  = $_POST['review_action']; // approve | reject
    $note      = trim($_POST['admin_note'] ?? '');

    if (!in_array($decision, ['approve','reject'])) { flashMessage('danger','قرار غير صالح'); redirect(SITE_URL.'/admin/kyc.php'); }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $pdo->prepare("UPDATE kyc_requests SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$newStatus, $note, $_SESSION['user_id'], $kycId]);

    // إشعار للعميل
    try {
        $kyc = $pdo->prepare("SELECT user_id FROM kyc_requests WHERE id=?"); $kyc->execute([$kycId]); $kyc = $kyc->fetch();
        if ($kyc) {
            $msg = $newStatus === 'approved'
                ? 'تهانينا! تم الموافقة على طلب التحقق من هويتك ✅'
                : 'تم رفض طلب التحقق من هويتك. السبب: ' . ($note ?: 'يرجى إعادة التقديم');
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, icon) VALUES (?,?,?,?,?)")
                ->execute([$kyc['user_id'], 'kyc', 'تحقق الهوية', $msg, 'fas fa-id-card']);
        }
    } catch(Exception $e) {}

    logStaffAction($pdo, 'kyc_review', 'kyc', $kycId, "قرار: $newStatus");
    flashMessage('success', $newStatus === 'approved' ? 'تمت الموافقة ✅' : 'تم الرفض ✗');
    redirect(SITE_URL.'/admin/kyc.php?action=view&id='.$kycId);
}

// ── حذف طلب ──
if ($action === 'delete' && $id && $IS_ADMIN) {
    $k = $pdo->prepare("SELECT image_front, image_back FROM kyc_requests WHERE id=?"); $k->execute([$id]); $k=$k->fetch();
    if ($k) {
        foreach (['image_front','image_back'] as $f)
            if (!empty($k[$f]) && file_exists(dirname(__DIR__).'/'.$k[$f])) @unlink(dirname(__DIR__).'/'.$k[$f]);
    }
    $pdo->prepare("DELETE FROM kyc_requests WHERE id=?")->execute([$id]);
    flashMessage('success','تم الحذف');
    redirect(SITE_URL.'/admin/kyc.php');
}

$typeLabels = ['national'=>'بطاقة شخصية','passport'=>'جواز سفر','family'=>'بطاقة عائلية','electronic'=>'بطاقة إلكترونية'];
$statusConfig = [
    'pending'  => ['⏳','#f5a623','badge-warning','قيد المراجعة'],
    'approved' => ['✅','#00e676','badge-success','موافق عليه'],
    'rejected' => ['✗','#ff4757','badge-danger','مرفوض'],
];

// ── عرض طلب واحد ──
if ($action === 'view' && $id) {
    $kyc = $pdo->prepare("SELECT k.*, u.username, u.email, u.full_name as user_fullname FROM kyc_requests k JOIN users u ON k.user_id=u.id WHERE k.id=?");
    $kyc->execute([$id]);
    $kyc = $kyc->fetch();
    if (!$kyc) { flashMessage('danger','الطلب غير موجود'); redirect(SITE_URL.'/admin/kyc.php'); }

    include 'header.php';
    $sc = $statusConfig[$kyc['status']] ?? $statusConfig['pending'];
    $extra = json_decode($kyc['extra_fields'] ?? '{}', true) ?: [];
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-id-card" style="color:#00d4aa"></i></div>
      طلب تحقق الهوية #<?= $kyc['id'] ?>
    </div>
  </div>
  <a href="kyc.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i> العودة</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- المعلومات -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-user"></i> بيانات مقدم الطلب</div>
      <span class="badge <?= $sc[2] ?>"><?= $sc[0].' '.$sc[3] ?></span>
    </div>
    <div class="card-body">
      <?php
      $rows = [
        ['العميل', htmlspecialchars($kyc['username']).' ('.htmlspecialchars($kyc['email'] ?? '').')'],
        ['الاسم في الهوية', htmlspecialchars($kyc['full_name'])],
        ['نوع الهوية', $typeLabels[$kyc['id_type']] ?? $kyc['id_type']],
        ['رقم الهوية', htmlspecialchars($kyc['national_id'])],
        ['تاريخ الميلاد', $kyc['birth_date'] ? date('d/m/Y', strtotime($kyc['birth_date'])) : '—'],
        ['مكان الميلاد', htmlspecialchars($kyc['birth_place'] ?: '—')],
        ['تاريخ الإصدار', $kyc['issue_date'] ? date('d/m/Y', strtotime($kyc['issue_date'])) : '—'],
        ['تاريخ الانتهاء', $kyc['expiry_date'] ? date('d/m/Y', strtotime($kyc['expiry_date'])) : '—'],
        ['تاريخ الطلب', date('d/m/Y H:i', strtotime($kyc['created_at']))],
      ];
      foreach ($extra as $k => $v) $rows[] = [htmlspecialchars($k), htmlspecialchars($v)];
      foreach ($rows as [$label, $val]):
      ?>
      <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px">
        <span style="color:var(--text2)"><?= $label ?></span>
        <span style="font-weight:700"><?= $val ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- الصور + القرار -->
  <div>
    <div class="card" style="margin-bottom:16px">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-images"></i> صور الهوية</div></div>
      <div class="card-body">
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <?php if ($kyc['image_front']): ?>
          <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:6px;font-weight:700">الوجه الأمامي</div>
            <a href="<?= SITE_URL.'/'.$kyc['image_front'] ?>" target="_blank">
              <img src="<?= SITE_URL.'/'.$kyc['image_front'] ?>" style="width:200px;max-height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in">
            </a>
          </div>
          <?php endif; ?>
          <?php if ($kyc['image_back']): ?>
          <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:6px;font-weight:700">الوجه الخلفي</div>
            <a href="<?= SITE_URL.'/'.$kyc['image_back'] ?>" target="_blank">
              <img src="<?= SITE_URL.'/'.$kyc['image_back'] ?>" style="width:200px;max-height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in">
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($kyc['status'] === 'pending' && ($IS_ADMIN || canAccess($pdo,'perm_customers_edit'))): ?>
    <!-- نموذج القرار -->
    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-gavel"></i> اتخاذ القرار</div></div>
      <div class="card-body">
        <form method="POST">
        <?= adminCsrfField() ?>
          <input type="hidden" name="kyc_id" value="<?= $kyc['id'] ?>">
          <div class="form-group">
            <label>ملاحظة (تظهر للعميل عند الرفض)</label>
            <textarea name="admin_note" class="form-control" rows="3" placeholder="اختياري..."></textarea>
          </div>
          <div style="display:flex;gap:10px;margin-top:4px">
            <button type="submit" name="review_action" value="approve"
              style="flex:1;padding:13px;background:linear-gradient(135deg,#00c853,#00e676);border:none;border-radius:12px;color:#fff;font-family:inherit;font-size:14px;font-weight:800;cursor:pointer">
              ✅ موافقة
            </button>
            <button type="submit" name="review_action" value="reject"
              onclick="return confirm('تأكيد رفض الطلب؟')"
              style="flex:1;padding:13px;background:linear-gradient(135deg,#c62828,#ff4757);border:none;border-radius:12px;color:#fff;font-family:inherit;font-size:14px;font-weight:800;cursor:pointer">
              ✗ رفض
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php elseif ($kyc['status'] !== 'pending'): ?>
    <div class="card">
      <div class="card-body">
        <div style="font-size:13px;color:var(--text2);margin-bottom:8px">القرار: <strong style="color:<?= $sc[1] ?>"><?= $sc[3] ?></strong></div>
        <?php if ($kyc['admin_note']): ?>
        <div style="font-size:13px;color:var(--text2)">الملاحظة: <?= htmlspecialchars($kyc['admin_note']) ?></div>
        <?php endif; ?>
        <?php if ($kyc['reviewed_at']): ?>
        <div style="font-size:11px;color:var(--text2);margin-top:6px">بتاريخ: <?= date('d/m/Y H:i', strtotime($kyc['reviewed_at'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($IS_ADMIN): ?>
    <div style="margin-top:10px;text-align:left">
      <a href="?action=delete&id=<?= $kyc['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف هذا الطلب نهائياً؟')">
        <i class="fas fa-trash"></i> حذف الطلب
      </a>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php include 'footer.php'; return; } // end view

// ── قائمة الطلبات ──
$filter = $_GET['filter'] ?? 'all';
$where  = $filter !== 'all' ? "WHERE k.status='$filter'" : '';
$kycs   = $pdo->query("SELECT k.*, u.username, u.email FROM kyc_requests k JOIN users u ON k.user_id=u.id $where ORDER BY k.created_at DESC")->fetchAll();
$counts = $pdo->query("SELECT status, COUNT(*) as n FROM kyc_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

include 'header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-id-card" style="color:#00d4aa"></i></div>
      طلبات التحقق من الهوية
    </div>
  </div>
</div>

<!-- إحصائيات سريعة -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <?php foreach ([
    ['pending','⏳','قيد المراجعة','#f5a623'],
    ['approved','✅','موافق عليها','#00e676'],
    ['rejected','✗','مرفوضة','#ff4757'],
  ] as [$st,$ic,$lb,$clr]): ?>
  <a href="?filter=<?=$st?>" style="text-decoration:none;background:var(--card);border:1px solid <?=$filter===$st?$clr:'var(--border)'?>;border-radius:16px;padding:14px;display:block;text-align:center;transition:.2s">
    <div style="font-size:26px"><?=$ic?></div>
    <div style="font-size:22px;font-weight:900;color:<?=$clr?>"><?=$counts[$st]??0?></div>
    <div style="font-size:11px;color:var(--text2)"><?=$lb?></div>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-list"></i> الطلبات (<?=count($kycs)?>)</div>
    <div style="display:flex;gap:8px">
      <?php foreach(['all'=>'الكل','pending'=>'قيد المراجعة','approved'=>'موافق','rejected'=>'مرفوض'] as $f=>$l): ?>
      <a href="?filter=<?=$f?>" class="btn btn-sm <?=$filter===$f?'btn-primary':'btn-secondary'?>"><?=$l?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <?php if(empty($kycs)): ?>
    <div style="text-align:center;padding:40px;color:var(--text2)">
      <i class="fas fa-id-card" style="font-size:40px;opacity:.3;display:block;margin-bottom:12px"></i>
      لا توجد طلبات
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>#</th><th>العميل</th><th>النوع</th><th>الاسم</th><th>الحالة</th><th>التاريخ</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach($kycs as $k):
        $sc = $statusConfig[$k['status']] ?? $statusConfig['pending'];
      ?>
      <tr>
        <td style="font-weight:700;color:var(--text2)"><?=$k['id']?></td>
        <td>
          <div style="font-weight:700"><?=htmlspecialchars($k['username'])?></div>
          <div style="font-size:11px;color:var(--text2)"><?=htmlspecialchars($k['email']??'')?></div>
        </td>
        <td><?=$typeLabels[$k['id_type']]??$k['id_type']?></td>
        <td><?=htmlspecialchars($k['full_name'])?></td>
        <td><span class="badge <?=$sc[2]?>"><?=$sc[0].' '.$sc[3]?></span></td>
        <td style="font-size:11px;color:var(--text2)"><?=date('d/m/Y H:i',strtotime($k['created_at']))?></td>
        <td><a href="?action=view&id=<?=$k['id']?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> مراجعة</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div><?php endif; ?>
  </div>
</div>

<?php include 'footer.php'; ?>
