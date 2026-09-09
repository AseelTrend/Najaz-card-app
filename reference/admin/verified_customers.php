<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_customers_view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCsrfVerify();
}

$IS_ADMIN  = isAdmin();
$pageTitle = 'العملاء الموثقون - ' . SITE_NAME;
$action    = $_GET['action'] ?? 'list';
$id        = (int)($_GET['id'] ?? 0);
$filter    = $_GET['filter'] ?? 'approved';
$search    = trim($_GET['q'] ?? '');
$filterIdType = in_array((string)($_GET['id_type'] ?? ''), ['national','passport','family','electronic'], true) ? (string)$_GET['id_type'] : '';
$filterAccountStatus = in_array((string)($_GET['account_status'] ?? ''), ['active','blocked'], true) ? (string)$_GET['account_status'] : '';
$filterGroupId = max(0, (int)($_GET['group_id'] ?? 0));
$validDate = static fn($value) => preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$value) ? (string)$value : '';
$filterIssueFrom = $validDate($_GET['issue_from'] ?? '');
$filterIssueTo = $validDate($_GET['issue_to'] ?? '');
$filterExpiryFrom = $validDate($_GET['expiry_from'] ?? '');
$filterExpiryTo = $validDate($_GET['expiry_to'] ?? '');
$perPage   = 30;
$page      = max(1, (int)($_GET['page'] ?? 1));

$typeLabels = [
    'national'   => 'بطاقة شخصية',
    'passport'   => 'جواز سفر',
    'family'     => 'بطاقة عائلية',
    'electronic' => 'بطاقة إلكترونية',
];
$statusConfig = [
    'pending'  => ['⏳', '#f5a623', 'قيد المراجعة', 'badge-warning'],
    'approved' => ['✅', '#00e676', 'موثّق', 'badge-success'],
    'rejected' => ['✗', '#ff4757', 'مرفوض', 'badge-danger'],
];

// ضمان وجود الجدول في التركيبات التي لم تفتح صفحة التحقق من قبل.
try {
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
} catch (Throwable $e) {
    // الجدول موجود غالباً؛ لا نوقف الصفحة بسبب اختلاف ترقية قديمة.
}

function verifiedKycImageUpload(string $field, int $userId, string $side): string
{
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('الصورة يجب ألا تتجاوز 8MB');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('نوع الصورة غير مسموح. استخدم JPG أو PNG أو WEBP');
    }

    $dir = dirname(__DIR__) . '/uploads/kyc/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('تعذر تجهيز مجلد الصور');
    }
    $name = 'kyc_' . $userId . '_' . $side . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        throw new RuntimeException('تعذر حفظ الصورة المرفوعة');
    }
    return 'uploads/kyc/' . $name;
}

function removeVerifiedKycImage(string $relativePath): void
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/kyc/')) return;
    $base = realpath(dirname(__DIR__) . '/uploads/kyc');
    $file = realpath(dirname(__DIR__) . '/' . $relativePath);
    if ($base && $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file)) {
        @unlink($file);
    }
}

// ── حفظ تعديل ملف التوثيق ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_kyc'])) {
    if (!$IS_ADMIN && !canAccess($pdo, 'perm_customers_edit')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل بيانات التوثيق');
        redirect(SITE_URL . '/admin/verified_customers.php');
    }

    $kycId      = (int)($_POST['kyc_id'] ?? 0);
    $idType     = $_POST['id_type'] ?? '';
    $fullName   = trim($_POST['full_name'] ?? '');
    $nationalId = trim($_POST['national_id'] ?? '');
    $birthDate  = trim($_POST['birth_date'] ?? '');
    $birthPlace = trim($_POST['birth_place'] ?? '');
    $issueDate  = trim($_POST['issue_date'] ?? '');
    $expiryDate = trim($_POST['expiry_date'] ?? '');
    $status     = $_POST['status'] ?? 'approved';
    $adminNote  = trim($_POST['admin_note'] ?? '');
    $extraRaw   = trim($_POST['extra_fields'] ?? '');

    if (!isset($typeLabels[$idType]) || !isset($statusConfig[$status])) {
        flashMessage('danger', 'نوع الهوية أو الحالة غير صالح');
        redirect(SITE_URL . '/admin/verified_customers.php?action=edit&id=' . $kycId);
    }
    if (mb_strlen($fullName) < 3 || $nationalId === '') {
        flashMessage('danger', 'الاسم ورقم الهوية مطلوبان');
        redirect(SITE_URL . '/admin/verified_customers.php?action=edit&id=' . $kycId);
    }

    $extra = null;
    if ($extraRaw !== '') {
        $decoded = json_decode($extraRaw, true);
        if (!is_array($decoded)) {
            flashMessage('danger', 'الحقول الإضافية يجب أن تكون JSON صحيحة');
            redirect(SITE_URL . '/admin/verified_customers.php?action=edit&id=' . $kycId);
        }
        $extra = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    $oldStmt = $pdo->prepare("SELECT k.*, u.username FROM kyc_requests k JOIN users u ON u.id=k.user_id WHERE k.id=? AND u.role='customer'");
    $oldStmt->execute([$kycId]);
    $oldKyc = $oldStmt->fetch();
    if (!$oldKyc) {
        flashMessage('danger', 'ملف التوثيق غير موجود');
        redirect(SITE_URL . '/admin/verified_customers.php');
    }

    $newFront = '';
    $newBack  = '';
    try {
        $newFront = verifiedKycImageUpload('image_front', (int)$oldKyc['user_id'], 'front');
        $newBack  = verifiedKycImageUpload('image_back', (int)$oldKyc['user_id'], 'back');

        $front = $newFront ?: ($oldKyc['image_front'] ?? '');
        $back  = $newBack  ?: ($oldKyc['image_back'] ?? '');
        if (!empty($_POST['remove_front'])) $front = '';
        if (!empty($_POST['remove_back']))  $back  = '';

        $reviewedAt = $status === 'approved' ? ($oldKyc['reviewed_at'] ?: date('Y-m-d H:i:s')) : ($status === 'pending' ? null : $oldKyc['reviewed_at']);
        $reviewedBy = $status === 'approved' ? ($oldKyc['reviewed_by'] ?: (int)$_SESSION['user_id']) : ($status === 'pending' ? null : $oldKyc['reviewed_by']);

        $pdo->prepare("UPDATE kyc_requests SET id_type=?, full_name=?, national_id=?, birth_date=?, birth_place=?, issue_date=?, expiry_date=?, image_front=?, image_back=?, extra_fields=?, status=?, admin_note=?, reviewed_by=?, reviewed_at=? WHERE id=?")
            ->execute([
                $idType, $fullName, $nationalId,
                $birthDate !== '' ? $birthDate : null,
                $birthPlace,
                $issueDate !== '' ? $issueDate : null,
                $expiryDate !== '' ? $expiryDate : null,
                $front, $back, $extra, $status, $adminNote !== '' ? $adminNote : null,
                $reviewedBy, $reviewedAt, $kycId,
            ]);

        if ($newFront && !empty($oldKyc['image_front'])) removeVerifiedKycImage($oldKyc['image_front']);
        if ($newBack && !empty($oldKyc['image_back'])) removeVerifiedKycImage($oldKyc['image_back']);
        if (!empty($_POST['remove_front']) && !$newFront && !empty($oldKyc['image_front'])) removeVerifiedKycImage($oldKyc['image_front']);
        if (!empty($_POST['remove_back']) && !$newBack && !empty($oldKyc['image_back'])) removeVerifiedKycImage($oldKyc['image_back']);

        logStaffAction($pdo, 'edit_kyc', 'kyc', $kycId, 'تعديل ملف توثيق العميل ' . ($oldKyc['username'] ?? ('#' . $oldKyc['user_id'])));
        flashMessage('success', 'تم حفظ ملف التوثيق والصور بنجاح');
    } catch (Throwable $e) {
        if ($newFront) removeVerifiedKycImage($newFront);
        if ($newBack) removeVerifiedKycImage($newBack);
        flashMessage('danger', 'تعذر حفظ التعديل: ' . $e->getMessage());
    }
    redirect(SITE_URL . '/admin/verified_customers.php?action=edit&id=' . $kycId);
}

// ── تحميل ملف التعديل ───────────────────────────────────────────────────────
$editKyc = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT k.*, u.username, u.email, u.full_name AS user_full_name, u.phone FROM kyc_requests k JOIN users u ON u.id=k.user_id WHERE k.id=? AND u.role='customer'");
    $stmt->execute([$id]);
    $editKyc = $stmt->fetch();
    if (!$editKyc) {
        flashMessage('danger', 'ملف التوثيق غير موجود');
        redirect(SITE_URL . '/admin/verified_customers.php');
    }
}

// ── القائمة والإحصاءات ──────────────────────────────────────────────────────
$whereParts = ["u.role='customer'"];
$params = [];
if ($filter === 'approved') {
    $whereParts[] = "k.status='approved'";
    $whereParts[] = "k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)";
} elseif (in_array($filter, ['pending', 'rejected'], true)) {
    $whereParts[] = 'k.status=?';
    $params[] = $filter;
    $whereParts[] = "k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)";
} else {
    $filter = 'all';
    $whereParts[] = "k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)";
}
if ($search !== '') {
    $whereParts[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR k.full_name LIKE ? OR k.national_id LIKE ? OR k.birth_place LIKE ?)';
    for ($i = 0; $i < 7; $i++) $params[] = '%' . $search . '%';
}
if ($filterIdType !== '') { $whereParts[] = 'k.id_type=?'; $params[] = $filterIdType; }
if ($filterAccountStatus === 'active') $whereParts[] = 'u.status=1';
if ($filterAccountStatus === 'blocked') $whereParts[] = '(u.status IS NULL OR u.status=0)';
if ($filterGroupId > 0) { $whereParts[] = 'u.group_id=?'; $params[] = $filterGroupId; }
if ($filterIssueFrom !== '') { $whereParts[] = 'k.issue_date>=?'; $params[] = $filterIssueFrom; }
if ($filterIssueTo !== '') { $whereParts[] = 'k.issue_date<=?'; $params[] = $filterIssueTo; }
if ($filterExpiryFrom !== '') { $whereParts[] = 'k.expiry_date>=?'; $params[] = $filterExpiryFrom; }
if ($filterExpiryTo !== '') { $whereParts[] = 'k.expiry_date<=?'; $params[] = $filterExpiryTo; }
$whereSql = implode(' AND ', $whereParts);

$counts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
try {
    $counts['approved'] = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests k WHERE k.status='approved' AND k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)")->fetchColumn();
    $counts['pending']  = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests k WHERE k.status='pending' AND k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)")->fetchColumn();
    $counts['rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests k WHERE k.status='rejected' AND k.id=(SELECT MAX(k2.id) FROM kyc_requests k2 WHERE k2.user_id=k.user_id)")->fetchColumn();
} catch (Throwable $e) {}

$total = 0;
$rows = [];
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM kyc_requests k JOIN users u ON u.id=k.user_id LEFT JOIN pricing_groups pg ON pg.id=u.group_id WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $listSql = "SELECT k.*, u.username, u.email, u.full_name AS user_full_name, u.phone, u.status AS user_status, u.group_id, pg.name AS group_name, u.created_at AS user_created_at
                FROM kyc_requests k JOIN users u ON u.id=k.user_id
                LEFT JOIN pricing_groups pg ON pg.id=u.group_id
                WHERE $whereSql ORDER BY k.updated_at DESC, k.id DESC LIMIT $perPage OFFSET $offset";
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
} catch (Throwable $e) {
    flashMessage('danger', 'تعذر تحميل قائمة العملاء الموثقين');
}

$totalPages = max(1, (int)ceil($total / $perPage));
$groupOptions = [];
try { $groupOptions = $pdo->query("SELECT id,name FROM pricing_groups WHERE status=1 ORDER BY name ASC")->fetchAll(); } catch (Throwable $e) {}
$verifiedFilterCount = count(array_filter([$search,$filterIdType,$filterAccountStatus,$filterGroupId,$filterIssueFrom,$filterIssueTo,$filterExpiryFrom,$filterExpiryTo], static fn($v) => $v !== '' && $v !== null && $v !== 0));
$verifiedQuery = $_GET; unset($verifiedQuery['page'], $verifiedQuery['action'], $verifiedQuery['id']);
$verifiedQueryString = http_build_query($verifiedQuery);

include 'header.php';
?>
<style>
.vc-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px}
.vc-search{display:flex;gap:8px;flex:1;min-width:260px}
.vc-search input{flex:1}
.vc-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px}
.vc-stat{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:15px;text-decoration:none;transition:.2s}
.vc-stat:hover{transform:translateY(-2px);border-color:var(--primary)}
.vc-stat-label{font-size:12px;color:var(--text2);margin-bottom:5px}.vc-stat-value{font-size:25px;font-weight:900}
.vc-table{min-width:1100px}.vc-table td{vertical-align:middle}
.vc-user{display:flex;align-items:center;gap:10px;min-width:190px}.vc-avatar{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#00d4aa,#6c3fe0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900}
.vc-thumb{width:58px;height:42px;object-fit:cover;border-radius:8px;border:1px solid var(--border);vertical-align:middle;margin-left:4px}
.vc-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.vc-edit-grid .full{grid-column:1/-1}
.vc-image-card{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:14px}.vc-image-preview{display:block;width:100%;height:190px;object-fit:contain;background:#10131a;border-radius:10px;margin-bottom:10px}
.vc-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}.vc-meta-item{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px}.vc-meta-label{font-size:11px;color:var(--text2);margin-bottom:5px}.vc-meta-value{font-weight:800;word-break:break-word}
@media(max-width:800px){.vc-stats{grid-template-columns:1fr}.vc-edit-grid,.vc-meta{grid-template-columns:1fr}.vc-edit-grid .full{grid-column:auto}}
</style>

<?php if ($action === 'edit' && $editKyc):
    $extraJson = json_decode($editKyc['extra_fields'] ?? '', true);
    $extraText = $extraJson ? json_encode($extraJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
    $sc = $statusConfig[$editKyc['status']] ?? $statusConfig['pending'];
?>
<div class="page-header">
  <div class="page-header-left"><div class="page-header-title"><div class="page-header-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-user-check" style="color:#00d4aa"></i></div>تعديل توثيق العميل</div></div>
  <a href="verified_customers.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i> العودة للعملاء الموثقين</a>
</div>

<div class="vc-meta">
  <div class="vc-meta-item"><div class="vc-meta-label">العميل</div><div class="vc-meta-value"><?=htmlspecialchars($editKyc['username'])?></div></div>
  <div class="vc-meta-item"><div class="vc-meta-label">البريد الإلكتروني</div><div class="vc-meta-value"><?=htmlspecialchars($editKyc['email'] ?? '—')?></div></div>
  <div class="vc-meta-item"><div class="vc-meta-label">حالة التوثيق الحالية</div><div class="vc-meta-value" style="color:<?=$sc[1]?>"><?=$sc[0].' '.$sc[2]?></div></div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-id-card"></i> بيانات التوثيق والصور</div><a href="customers.php?action=view&id=<?=$editKyc['user_id']?>" class="btn btn-secondary btn-sm"><i class="fas fa-user"></i> ملف العميل</a></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <?=adminCsrfField()?>
      <input type="hidden" name="kyc_id" value="<?=$editKyc['id']?>">
      <div class="vc-edit-grid">
        <div class="form-group"><label>نوع الهوية</label><select name="id_type" class="form-control">
          <?php foreach($typeLabels as $v=>$l): ?><option value="<?=$v?>" <?=$editKyc['id_type']===$v?'selected':''?>><?=$l?></option><?php endforeach; ?>
        </select></div>
        <div class="form-group"><label>حالة التوثيق</label><select name="status" class="form-control">
          <?php foreach($statusConfig as $v=>$cfg): ?><option value="<?=$v?>" <?=$editKyc['status']===$v?'selected':''?>><?=$cfg[0].' '.$cfg[2]?></option><?php endforeach; ?>
        </select></div>
        <div class="form-group"><label>الاسم كما في الهوية <span style="color:#ff4757">*</span></label><input class="form-control" name="full_name" required value="<?=htmlspecialchars($editKyc['full_name'] ?? '')?>"></div>
        <div class="form-group"><label>رقم الهوية <span style="color:#ff4757">*</span></label><input class="form-control" name="national_id" required value="<?=htmlspecialchars($editKyc['national_id'] ?? '')?>"></div>
        <div class="form-group"><label>تاريخ الميلاد</label><input type="date" class="form-control" name="birth_date" value="<?=htmlspecialchars($editKyc['birth_date'] ?? '')?>"></div>
        <div class="form-group"><label>مكان الميلاد</label><input class="form-control" name="birth_place" value="<?=htmlspecialchars($editKyc['birth_place'] ?? '')?>"></div>
        <div class="form-group"><label>تاريخ الإصدار</label><input type="date" class="form-control" name="issue_date" value="<?=htmlspecialchars($editKyc['issue_date'] ?? '')?>"></div>
        <div class="form-group"><label>تاريخ الانتهاء</label><input type="date" class="form-control" name="expiry_date" value="<?=htmlspecialchars($editKyc['expiry_date'] ?? '')?>"></div>
        <div class="form-group full"><label>ملاحظة الإدارة</label><textarea class="form-control" name="admin_note" rows="3"><?=htmlspecialchars($editKyc['admin_note'] ?? '')?></textarea></div>
        <div class="form-group full"><label>الحقول الإضافية بصيغة JSON</label><textarea class="form-control" name="extra_fields" rows="5" dir="ltr" style="text-align:left;font-family:monospace"><?=htmlspecialchars($extraText)?></textarea><small style="color:var(--text2)">اتركه فارغاً إذا لم توجد حقول إضافية.</small></div>
      </div>

      <div class="vc-edit-grid" style="margin-top:14px">
        <?php foreach([['front','image_front','الوجه الأمامي','صورة الوجه الأمامي'],['back','image_back','الوجه الخلفي','صورة الوجه الخلفي']] as [$side,$field,$label,$title]): ?>
        <div class="vc-image-card">
          <div style="font-weight:800;margin-bottom:10px"><i class="fas fa-image" style="color:var(--cyan);margin-left:6px"></i><?=$label?></div>
          <?php if(!empty($editKyc[$field])): ?><a href="<?=SITE_URL.'/'.htmlspecialchars($editKyc[$field])?>" target="_blank"><img class="vc-image-preview" src="<?=SITE_URL.'/'.htmlspecialchars($editKyc[$field])?>" alt="<?=htmlspecialchars($title)?>"></a><?php else: ?><div class="vc-image-preview" style="display:flex;align-items:center;justify-content:center;color:var(--text2)">لا توجد صورة</div><?php endif; ?>
          <input type="file" name="<?=$field?>" class="form-control" accept="image/jpeg,image/png,image/webp,image/heic">
          <?php if(!empty($editKyc[$field])): ?><label style="display:block;margin-top:9px;font-size:12px;color:var(--text2)"><input type="checkbox" name="remove_<?=$side?>" value="1"> إزالة الصورة الحالية</label><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-start;margin-top:18px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit" name="update_kyc"><i class="fas fa-save"></i> حفظ كل التعديلات</button>
        <a class="btn btn-secondary" href="kyc.php?action=view&id=<?=$editKyc['id']?>"><i class="fas fa-external-link-alt"></i> فتح صفحة الطلب الأصلية</a>
        <a class="btn btn-secondary" href="verified_customers.php"><i class="fas fa-times"></i> إلغاء</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div class="page-header">
  <div class="page-header-left"><div class="page-header-title"><div class="page-header-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-user-check" style="color:#00d4aa"></i></div>العملاء الموثقون</div></div>
  <a href="kyc.php" class="btn btn-secondary btn-sm"><i class="fas fa-id-card"></i> طلبات التحقق</a>
</div>

<div class="vc-stats">
  <?php foreach([['approved','✅','الموثقون','#00e676'],['pending','⏳','أحدث طلبات معلقة','#f5a623'],['rejected','✗','أحدث المرفوضين','#ff4757']] as [$key,$icon,$label,$color]): ?>
  <a class="vc-stat" href="?filter=<?=$key?><?= $search!==''?'&q='.urlencode($search):'' ?>"><div style="font-size:22px"><?=$icon?></div><div class="vc-stat-value" style="color:<?=$color?>"><?=$counts[$key]?></div><div class="vc-stat-label"><?=$label?></div></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-users"></i> ملفات التوثيق (<?=$total?>)</div></div>
  <div class="card-body">
    <form method="GET" class="vc-toolbar">
      <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
      <div class="vc-search"><input class="form-control" name="q" value="<?=htmlspecialchars($search)?>" placeholder="ابحث بالاسم أو اسم المستخدم أو البريد أو الهاتف أو رقم الهوية أو مكان الميلاد"><button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> بحث</button></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;width:100%">
        <select name="id_type" aria-label="نوع الهوية"><option value="">كل أنواع الهوية</option><?php foreach($typeLabels as $v=>$label): ?><option value="<?=$v?>" <?=$filterIdType===$v?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select>
        <select name="account_status" aria-label="حالة الحساب"><option value="">كل حالات الحساب</option><option value="active" <?=$filterAccountStatus==='active'?'selected':''?>>حساب نشط</option><option value="blocked" <?=$filterAccountStatus==='blocked'?'selected':''?>>حساب موقوف</option></select>
        <select name="group_id" aria-label="مجموعة التسعير"><option value="">كل مجموعات التسعير</option><?php foreach($groupOptions as $g): ?><option value="<?=$g['id']?>" <?=$filterGroupId===(int)$g['id']?'selected':''?>><?=htmlspecialchars($g['name'])?></option><?php endforeach; ?></select>
        <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text2)">إصدار من <input type="date" name="issue_from" value="<?=htmlspecialchars($filterIssueFrom)?>"></label>
        <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text2)">إلى <input type="date" name="issue_to" value="<?=htmlspecialchars($filterIssueTo)?>"></label>
        <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text2)">انتهاء من <input type="date" name="expiry_from" value="<?=htmlspecialchars($filterExpiryFrom)?>"></label>
        <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text2)">إلى <input type="date" name="expiry_to" value="<?=htmlspecialchars($filterExpiryTo)?>"></label>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter"></i> تطبيق الفلاتر</button>
        <?php if($verifiedFilterCount): ?><a class="btn btn-secondary btn-sm" href="verified_customers.php?filter=<?=urlencode($filter)?>"><i class="fas fa-undo"></i> مسح</a><span style="align-self:center;color:var(--cyan);font-size:.78rem"><?= $verifiedFilterCount ?> فلاتر نشطة</span><?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;width:100%"><?php foreach(['approved'=>'الموثقون','pending'=>'المعلق','rejected'=>'المرفوض','all'=>'الكل'] as $f=>$label): ?><a href="?filter=<?=$f?><?= $verifiedQueryString!==''?'&'.htmlspecialchars($verifiedQueryString):'' ?>" class="btn btn-sm <?=$filter===$f?'btn-primary':'btn-secondary'?>"><?=$label?></a><?php endforeach; ?></div>
    </form>

    <?php if(!$rows): ?><div style="text-align:center;padding:45px;color:var(--text2)"><i class="fas fa-user-check" style="font-size:42px;opacity:.3;display:block;margin-bottom:12px"></i>لا توجد ملفات توثيق مطابقة للبحث</div><?php else: ?>
    <div class="table-wrap"><table class="table vc-table"><thead><tr><th>العميل</th><th>بيانات الهوية</th><th>الصور</th><th>الحالة</th><th>تاريخ التوثيق</th><th>تاريخ الحساب</th><th></th></tr></thead><tbody>
    <?php foreach($rows as $row): $sc=$statusConfig[$row['status']]??$statusConfig['pending']; $initial=mb_substr($row['full_name'] ?: ($row['username']??'?'),0,1); ?>
    <tr>
      <td><div class="vc-user"><div class="vc-avatar"><?=htmlspecialchars($initial)?></div><div><div style="font-weight:800"><?=htmlspecialchars($row['full_name'] ?: ($row['user_full_name'] ?: $row['username']))?></div><div style="font-size:11px;color:var(--text2)">@<?=htmlspecialchars($row['username'])?> · #<?=$row['user_id']?></div><div style="font-size:11px;color:var(--text2)"><?=htmlspecialchars($row['email']??'')?></div></div></div></td>
      <td><div style="font-weight:700"><?=$typeLabels[$row['id_type']]??htmlspecialchars($row['id_type'])?></div><div style="font-size:12px;color:var(--text2)"><?=htmlspecialchars($row['national_id'])?></div></td>
      <td><?php if($row['image_front']): ?><a href="<?=SITE_URL.'/'.htmlspecialchars($row['image_front'])?>" target="_blank"><img class="vc-thumb" src="<?=SITE_URL.'/'.htmlspecialchars($row['image_front'])?>" alt="front"></a><?php endif; ?><?php if($row['image_back']): ?><a href="<?=SITE_URL.'/'.htmlspecialchars($row['image_back'])?>" target="_blank"><img class="vc-thumb" src="<?=SITE_URL.'/'.htmlspecialchars($row['image_back'])?>" alt="back"></a><?php endif; ?></td>
      <td><span class="badge <?=$sc[3]?>"><?=$sc[0].' '.$sc[2]?></span></td>
      <td style="font-size:12px;color:var(--text2)"><?= $row['reviewed_at'] ? date('d/m/Y H:i',strtotime($row['reviewed_at'])) : '—' ?></td>
      <td style="font-size:12px;color:var(--text2)"><?= $row['user_created_at'] ? date('d/m/Y',strtotime($row['user_created_at'])) : '—' ?></td>
      <td><a class="btn btn-sm btn-primary" href="?action=edit&id=<?=$row['id']?>"><i class="fas fa-edit"></i> تعديل</a></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if($totalPages>1): ?><div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:18px"><?php for($p=1;$p<=$totalPages;$p++): ?><a class="btn btn-sm <?=$p===$page?'btn-primary':'btn-secondary'?>" href="?filter=<?=$filter?>&page=<?=$p?><?= $search!==''?'&q='.urlencode($search):'' ?>"><?=$p?></a><?php endfor; ?></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php include 'footer.php'; ?>
