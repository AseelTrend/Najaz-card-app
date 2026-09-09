<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_services_view');
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة الخدمات - ' . SITE_NAME;

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);


// ─── دالة رفع صورة الخدمة ──────────────────────────────────────────────────
function uploadServiceImage($fileKey, $oldImage = '') {
    if (empty($_FILES[$fileKey]['name']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return $oldImage;
    $file        = $_FILES[$fileKey];
    $allowedMime = ['image/jpeg','image/png','image/webp','image/gif'];
    $allowedExt  = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // [H-2 FIX] التحقق من MIME الحقيقي عبر finfo وليس من المتصفح (قابل للتزوير)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($realMime, $allowedMime, true)) return $oldImage;
    if (!in_array($ext, $allowedExt, true))       return $oldImage;
    // التحقق الإضافي: أن الملف صورة حقيقية وليس ملف PHP مُغلَّف
    if (!@getimagesize($file['tmp_name']))         return $oldImage;

    if ($file['size'] > 3 * 1024 * 1024) return $oldImage; // 3MB
    $filename = 'svc_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
    $dir      = dirname(__DIR__) . '/assets/uploads/services/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        // حذف الصورة القديمة
        if ($oldImage) {
            $oldPath = dirname(__DIR__) . '/' . $oldImage;
            if (file_exists($oldPath)) @unlink($oldPath);
        }
        return 'assets/uploads/services/' . $filename;
    }
    return $oldImage;
}

// ── جدول service_providers (يُنشأ مرة واحدة فقط من phpMyAdmin) ──────────

if ($action === 'delete' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_services_edit')) { flashMessage('danger','ليس لديك صلاحية حذف الخدمات'); redirect(SITE_URL.'/admin/services.php'); }
    $svcRow = $pdo->prepare("SELECT name, image FROM services WHERE id=?"); $svcRow->execute([$id]); $svcRow = $svcRow->fetch();
    if (!$svcRow) { flashMessage('danger','الخدمة غير موجودة'); redirect(SITE_URL.'/admin/services.php'); }
    // تحقق من الطلبات المرتبطة
    try {
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE service_id=?"); $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            flashMessage('danger', '⚠️ لا يمكن حذف "' . $svcRow['name'] . '" — مرتبطة بطلبات موجودة');
            redirect(SITE_URL.'/admin/services.php');
        }
    } catch(Exception $e) {}
    if ($svcRow['image']) @unlink(dirname(__DIR__) . '/' . $svcRow['image']);
    $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$id]);
    try { $pdo->prepare("DELETE FROM service_providers WHERE service_id=?")->execute([$id]); } catch(Exception $e) {}
    try {
        $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
        flashMessage('success', '✅ تم حذف الخدمة: ' . $svcRow['name']);
    } catch(Exception $e) {
        flashMessage('danger', '⚠️ لا يمكن حذف "' . $svcRow['name'] . '" — مرتبطة بسجلات أخرى');
    }
    redirect(SITE_URL . '/admin/services.php');
}

if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE services SET status=IF(status=1,0,1) WHERE id=?")->execute([$id]);
    redirect(SITE_URL . '/admin/services.php');
}

// [C-2 FIX] CSRF verification لجميع طلبات POST في صفحة الخدمات
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

// ── حذف دفعي ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_delete_services'])) {
    if (!isAdmin() && !canAccess($pdo,'perm_services_edit')) {
        flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/services.php');
    }
    $ids = array_filter(array_map('intval', (array)($_POST['svc_ids'] ?? [])));
    if (empty($ids)) { flashMessage('danger','لم تحدد أي خدمة'); redirect(SITE_URL.'/admin/services.php'); }

    $deleted  = 0;
    $skipped  = []; // أسماء الخدمات التي لم تُحذف

    foreach ($ids as $did) {
        // جلب بيانات الخدمة
        $svcRow = $pdo->prepare("SELECT id, name, image FROM services WHERE id=?");
        $svcRow->execute([$did]);
        $svcRow = $svcRow->fetch();
        if (!$svcRow) continue;

        // تحقق: هل الخدمة مرتبطة بطلبات؟
        try {
            $inUse = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE service_id=?");
            $inUse->execute([$did]);
            if ((int)$inUse->fetchColumn() > 0) {
                $skipped[] = $svcRow['name'];
                continue;
            }
        } catch(Exception $e) {}

        // احذف
        if ($svcRow['image']) @unlink(dirname(__DIR__).'/'.$svcRow['image']);
        $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$did]);
        try { $pdo->prepare("DELETE FROM service_providers WHERE service_id=?")->execute([$did]); } catch(Exception $e) {}
        try {
            $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$did]);
            $deleted++;
        } catch(Exception $e) {
            $skipped[] = $svcRow['name']; // foreign key أو سبب آخر
        }
    }

    // رسالة النتيجة
    $msg = '';
    if ($deleted > 0) $msg .= "✅ تم حذف $deleted خدمة. ";
    if (!empty($skipped)) {
        $msg .= "⚠️ لم يتم حذف " . count($skipped) . " خدمة لارتباطها بطلبات: " . implode('، ', $skipped);
        flashMessage($deleted > 0 ? 'warning' : 'danger', trim($msg));
    } else {
        flashMessage('success', trim($msg));
    }
    redirect(SITE_URL.'/admin/services.php');
}

// ── حذف قسم كامل ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_cat_services'])) {
    if (!isAdmin()) { flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/services.php'); }
    $catId = (int)($_POST['del_cat_id'] ?? 0);
    if (!$catId) { flashMessage('danger','اختر قسماً'); redirect(SITE_URL.'/admin/services.php'); }
    $svcs = $pdo->prepare("SELECT id,image FROM services WHERE category_id=?"); $svcs->execute([$catId]); $svcs=$svcs->fetchAll();
    $deleted = 0;
    foreach ($svcs as $s) {
        if ($s['image']) @unlink(dirname(__DIR__).'/'.$s['image']);
        $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$s['id']]);
        try { $pdo->prepare("DELETE FROM service_providers WHERE service_id=?")->execute([$s['id']]); } catch(Exception $e) {}
        $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$s['id']]);
        $deleted++;
    }
    flashMessage('success', "✅ تم حذف $deleted خدمة من القسم");
    redirect(SITE_URL.'/admin/services.php');
}

// ── تصفير كامل: حذف كل الخدمات من كل الأقسام وإعادة الترقيم لـ 1 ──────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['wipe_all_services'])) {
    if (!isAdmin()) { flashMessage('danger','هذا الإجراء للمدير فقط'); redirect(SITE_URL.'/admin/services.php'); }
    $confirmWord = trim($_POST['wipe_confirm'] ?? '');
    if ($confirmWord !== 'حذف الكل') {
        flashMessage('danger', '❌ لم تتم العملية — يجب كتابة العبارة «حذف الكل» بالضبط لتأكيد التصفير الكامل.');
        redirect(SITE_URL.'/admin/services.php');
    }

    // حذف صور الخدمات من السيرفر أولاً
    $imgs = $pdo->query("SELECT image FROM services WHERE image IS NOT NULL AND image!=''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($imgs as $img) { @unlink(dirname(__DIR__).'/'.$img); }

    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn();

    // تعطيل مؤقت لفحص المفاتيح الأجنبية — لأن جدول orders مرتبط بـ service_id
    // (الطلبات القديمة تبقى محفوظة، فقط يصبح رابطها بالخدمة المحذوفة معلّقاً/فارغاً)
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("DELETE FROM service_fields");
    try { $pdo->exec("DELETE FROM service_providers"); } catch(Exception $e) {}
    try { $pdo->exec("DELETE FROM code_stock"); } catch(Exception $e) {} // مخزون الأكواد مرتبط بخدمات لم تعد موجودة
    $pdo->exec("DELETE FROM services");
    $pdo->exec("ALTER TABLE services AUTO_INCREMENT = 1");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    flashMessage('success', "✅ تم تصفير كل الخدمات بالكامل ($countBefore خدمة) من جميع الأقسام، وإعادة ترقيم الخدمات ليبدأ من 1 من جديد.");
    redirect(SITE_URL.'/admin/services.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service'])) {
    $name              = trim($_POST['name']);
    $catId             = (int)$_POST['category_id'];
    $price             = (float)$_POST['price'];
    $minQty            = (int)$_POST['min_qty'];
    $maxQty            = (int)$_POST['max_qty'];
    $desc              = trim($_POST['description']);
    $providerId        = (int)$_POST['provider_id'] ?: null;
    $providerServiceId = trim($_POST['provider_service_id']) ?: null;
    $serviceType       = in_array($_POST['service_type'] ?? '', ['default','numbers_live','numbers_pack','numbers_store']) ? $_POST['service_type'] : 'default';

    // إنشاء عمود service_type إذا لم يكن موجوداً
    try { $pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS service_type ENUM('default','numbers_live','numbers_pack','numbers_store') DEFAULT 'default' AFTER provider_service_id"); } catch(Exception $e) {}

    if ($id) {
        try {
            $oldImg = $pdo->prepare("SELECT image FROM services WHERE id=?"); $oldImg->execute([$id]); $oldImg = $oldImg->fetch();
            // حذف الصورة إذا طلب المستخدم ذلك
            if (!empty($_POST['delete_image']) && $oldImg['image']) {
                $delPath = dirname(__DIR__) . '/' . $oldImg['image'];
                if (file_exists($delPath)) @unlink($delPath);
                $oldImg['image'] = '';
            }
            $image = uploadServiceImage('image', $oldImg['image'] ?? '');
            // [ملاحظة] لا نلمس provider_id/provider_service_id هنا إطلاقاً — هذان الحقلان قديمان
            // (النظام الحالي يعتمد على جدول service_providers لتعدد المزودين)، وكانا يُكتبان
            // فارغين (NULL) بالغلط في كل مرة تُحفظ فيها الخدمة (حتى لو فقط لرفع صورة)، مما كان
            // يمسح ربط الخدمة الأصلي بالمزود ويكسر مطابقة الاستيراد لاحقاً.
            $pdo->prepare("UPDATE services SET name=?,category_id=?,price=?,min_qty=?,max_qty=?,description=?,service_type=?,image=? WHERE id=?")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$serviceType,$image,$id]);
        } catch (\PDOException $e) {
            $pdo->prepare("UPDATE services SET name=?,category_id=?,price=?,min_qty=?,max_qty=?,description=?,service_type=? WHERE id=?")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$serviceType,$id]);
        }
    } else {
        $image = uploadServiceImage('image', '');
        try {
            $pdo->prepare("INSERT INTO services (name,category_id,price,min_qty,max_qty,description,provider_id,provider_service_id,service_type,image) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId,$serviceType,$image]);
        } catch (\PDOException $e) {
            $pdo->prepare("INSERT INTO services (name,category_id,price,min_qty,max_qty,description,provider_id,provider_service_id,service_type) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId,$serviceType]);
        }
        $id = $pdo->lastInsertId();
    }

    $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$id]);
    $fieldNames  = $_POST['field_name']   ?? [];
    $fieldLabels = $_POST['field_label']  ?? [];
    $fieldTypes  = $_POST['field_type']   ?? [];
    $fieldOpts   = $_POST['field_options'] ?? [];
    $fieldReq    = $_POST['field_required'] ?? [];

    foreach ($fieldNames as $i => $fname) {
        if (empty($fname)) continue;
        $pdo->prepare("INSERT INTO service_fields (service_id,field_name,field_label,field_type,field_options,is_required,sort_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $fname, $fieldLabels[$i]??$fname, $fieldTypes[$i]??'text', $fieldOpts[$i]??'', isset($fieldReq[$i])?1:0, $i]);
    }

    // ── حفظ المزودين المتعددين ────────────────────────────────────────────
    try {
        $pdo->prepare("DELETE FROM service_providers WHERE service_id=?")->execute([$id]);
        $spIds      = $_POST['sp_provider_id']      ?? [];
        $spSvcIds   = $_POST['sp_provider_svc_id']  ?? [];
        $spLabels   = $_POST['sp_label']             ?? [];
        $spActives  = $_POST['sp_active']            ?? [];

        // ── إذا كان نوع أرقام مباشرة → بناء provider_service_id من الحقول الخاصة ──
        if ($serviceType === 'numbers_live') {
            $naService = trim($_POST['na_field_service'] ?? '');
            $naCountry = trim($_POST['na_field_country'] ?? '');
            $naServer  = trim($_POST['na_field_server']  ?? '0');
            if ($naService && $naCountry) {
                $naPsid = "live:{$naService}:{$naCountry}:{$naServer}";
                // ابحث عن مزود NumbersApp في القائمة أو أضفه
                $naProvFound = false;
                foreach ($spIds as $i => $spPid) {
                    if (!empty($spPid)) {
                        // تحديث أول مزود ليكون بالـ psid الصحيح
                        $spSvcIds[$i] = $naPsid;
                        $naProvFound = true;
                        break;
                    }
                }
                // إذا لم يُضف مزود → ابحث عن أي مزود numbersapp وأضفه
                if (!$naProvFound) {
                    $naProv = $pdo->query("SELECT id FROM providers WHERE provider_type='numbersapp' AND status=1 LIMIT 1")->fetch();
                    if ($naProv) {
                        $spIds[]     = $naProv['id'];
                        $spSvcIds[]  = $naPsid;
                        $spLabels[]  = '';
                        $spActives[] = '1';
                    }
                }
            }
        }

        foreach ($spIds as $i => $spPid) {
            if (empty($spPid) || empty($spSvcIds[$i])) continue;
            $pdo->prepare("INSERT INTO service_providers (service_id,provider_id,provider_service_id,priority,is_active,label) VALUES (?,?,?,?,?,?)")
                ->execute([$id, (int)$spPid, trim($spSvcIds[$i]), $i+1, isset($spActives[$i])?1:1, trim($spLabels[$i]??'')]);
        }
    } catch(Exception $e) {}

    flashMessage('success', 'تم حفظ الخدمة ✓');
    redirect(SITE_URL . '/admin/services.php?action=edit&id=' . $id);
}

// ── Pagination (فقط في صفحة القائمة) ───────────────────────────────────────
$services    = [];
$totalCount  = 0;
$totalPages  = 1;
$currentPage = 1;
$showAll  = isset($_GET['showall']) && $_GET['showall'] == '1';
$perPage  = $showAll ? 99999 : 100;
// فلاتر البحث
$fCat      = (int)($_GET['fcat']    ?? 0);
$fSearch   = trim($_GET['fsearch']  ?? '');
$fProvider = array_key_exists('fprov', $_GET) ? (int)$_GET['fprov'] : -1;

if ($action === 'list') {
    $where = ['1=1']; $params = [];
    if ($fCat > 0)        { $where[] = 's.category_id=?';  $params[] = $fCat; }
    if ($fSearch !== '')  { $where[] = 's.name LIKE ?';     $params[] = "%$fSearch%"; }
    if ($fProvider === 0) { $where[] = '(s.provider_id IS NULL OR s.provider_id=0)'; }
    elseif ($fProvider > 0) { $where[] = 's.provider_id=?'; $params[] = $fProvider; }
    $w = implode(' AND ', $where);

    $currentPage = max(1, (int)($_GET['page'] ?? 1));
    $cs = $pdo->prepare("SELECT COUNT(*) FROM services s JOIN categories c ON s.category_id=c.id LEFT JOIN providers p ON s.provider_id=p.id WHERE $w");
    $cs->execute($params); $totalCount = (int)$cs->fetchColumn();
    $totalPages  = max(1, (int)ceil($totalCount / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset      = ($currentPage - 1) * $perPage;
    $st = $pdo->prepare("SELECT s.*, c.name as cat_name, p.name as provider_name FROM services s JOIN categories c ON s.category_id=c.id LEFT JOIN providers p ON s.provider_id=p.id WHERE $w ORDER BY c.sort_order, s.sort_order LIMIT $perPage OFFSET $offset");
    $st->execute($params); $services = $st->fetchAll();
}

// جلب عدد مزودي كل خدمة (فقط في صفحة القائمة)
$spCounts = [];
if ($action === 'list') {
    try {
        $spC = $pdo->query("SELECT service_id, COUNT(*) as cnt FROM service_providers WHERE is_active=1 GROUP BY service_id");
        foreach ($spC->fetchAll() as $row) $spCounts[$row['service_id']] = $row['cnt'];
    } catch(Exception $e) {}
}

// جلب الأقسام والمزودين فقط عند الإضافة أو التعديل
$allCats   = [];
$providers = [];
try {
    $allCats   = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC")->fetchAll();
    $providers = $pdo->query("SELECT * FROM providers WHERE status=1")->fetchAll();
} catch(Exception $e) {}

// جلب مزودي الخدمة الحاليين عند التعديل
$editServiceProviders = [];
if ($id) {
    try {
        $spStmt = $pdo->prepare("SELECT sp.*, p.name as pname FROM service_providers sp JOIN providers p ON sp.provider_id=p.id WHERE sp.service_id=? ORDER BY sp.priority ASC");
        $spStmt->execute([$id]);
        $editServiceProviders = $spStmt->fetchAll();
    } catch(Exception $e) {}
}

$editSvc = null; $editFields = [];
if ($id && in_array($action, ['edit','add'])) {
    $s = $pdo->prepare("SELECT * FROM services WHERE id=?"); $s->execute([$id]); $editSvc = $s->fetch();
    $s2 = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order"); $s2->execute([$id]); $editFields = $s2->fetchAll();
}

// ─── بيانات من providers.php (إضافة خدمة من منتج Oranos) ────────────────────
$prefillProductId   = $_GET['product_id']     ?? '';
$prefillProductName = $_GET['product_name']   ?? '';
$prefillProductCat  = $_GET['product_cat']    ?? '';
$prefillProductPrice= $_GET['product_price']  ?? '';
$prefillProviderId  = $_GET['provider_id']    ?? '';
$prefillParams      = json_decode($_GET['product_params'] ?? '[]', true) ?: [];
$prefillQty         = json_decode($_GET['qty_values'] ?? 'null', true);
$prefillNaExtra     = json_decode($_GET['na_extra'] ?? '{}', true) ?: [];

// إذا كان المزود NumbersApp → تجهيز product_id بصيغة type:id
$prefillServiceType = 'default';
if (!empty($prefillNaExtra['na_type'])) {
    $naType = $prefillNaExtra['na_type'];
    if ($naType === 'live') {
        // product_id already in format: live:SERVICE:COUNTRY:SERVER
        $prefillServiceType = 'numbers_live';
        $prefillParams = []; // لا حاجة لحقول — الدولة والسيرفر محفوظين في الـ ID
    } elseif ($naType === 'pack_fixed' || $naType === 'pack_count') {
        $prefillProductId = $naType . ':' . ($prefillNaExtra['na_pack_id'] ?? '');
        $prefillServiceType = 'numbers_pack';
        // إضافة حقول الباقات من Inputs
        $naInputs = json_decode($prefillNaExtra['na_inputs'] ?? '[]', true) ?: [];
        if (!empty($naInputs)) $prefillParams = $naInputs;
    } elseif ($naType === 'store') {
        $prefillProductId = 'store:' . ($prefillNaExtra['na_product_id'] ?? '');
        $prefillServiceType = 'numbers_store';
        $prefillParams = []; // المخزن لا يحتاج حقول
    }
}

// تحويل qty_values لـ min/max
$prefillMinQty = 1; $prefillMaxQty = 9999;
if ($prefillQty === null) { $prefillMinQty = 1; $prefillMaxQty = 1; }
elseif (is_array($prefillQty) && isset($prefillQty['min'])) {
    $prefillMinQty = (int)$prefillQty['min'];
    $prefillMaxQty = is_numeric($prefillQty['max']) ? (int)$prefillQty['max'] : 9999;
} elseif (is_array($prefillQty)) {
    $prefillMinQty = min($prefillQty); $prefillMaxQty = max($prefillQty);
}


// دالة تحويل النص لمفتاح
function slugify($text) {
    $text = preg_replace('/^(ادخل|أدخل)\s+/u', '', trim($text));
    $text = preg_replace('/[\s\-]+/', '_', $text);
    $text = preg_replace('/[^\w]/u', '_', $text);
    $text = trim($text, '_');
    return empty($text) ? 'field_'.mt_rand(100,999) : strtolower($text);
}

include 'header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-box"></i> إدارة الخدمات</h2>
    <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> خدمة جديدة</a>
</div>

<?php if ($action !== 'add' && $action !== 'edit'): ?>
<div class="card mb-2" style="border:1.5px solid #ff445555">
  <div class="card-header" style="background:rgba(255,68,85,.08)">
    <div class="card-header-title" style="color:#ff6677"><i class="fas fa-exclamation-triangle"></i> تصفير كامل لكل الخدمات</div>
  </div>
  <div class="card-body">
    <div class="alert alert-warning" style="margin-bottom:14px;font-size:.85rem">
      ⚠️ يحذف <b>كل الخدمات نهائياً من جميع الأقسام</b> (بدون استثناء)، وحقولها، وربطها بالمزودين، ومخزون الأكواد المرتبط بها، ويعيد ترقيم الخدمات ليبدأ من <b>1</b> من جديد.
      الطلبات القديمة تبقى محفوظة بسجلاتها لكن يصبح رابطها بالخدمة (المحذوفة) فارغاً. <b>لا يمكن التراجع.</b>
      لاستيراد خدمات جديدة بعدها مباشرة من مزود، استخدم صفحة <a href="<?=SITE_URL?>/admin/import_services.php">استيراد خدمات</a>.
    </div>
    <form method="POST" onsubmit="return confirm('تأكيد نهائي وأخير: سيتم حذف كل الخدمات من كل الأقسام نهائياً. لا يمكن التراجع. متابعة؟')" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <?= adminCsrfField() ?>
      <input type="hidden" name="wipe_all_services" value="1">
      <div class="form-group" style="margin:0">
        <label>للتأكيد، اكتب العبارة <b>حذف الكل</b> بالضبط</label>
        <input type="text" name="wipe_confirm" class="form-control" style="max-width:220px" placeholder="اكتب: حذف الكل" required>
      </div>
      <button type="submit" class="btn btn-danger"><i class="fas fa-bomb"></i> تصفير كل الخدمات الآن</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($action==='add'||$action==='edit'): ?>
<div class="card mb-2">
    <?php if ($prefillProductName): ?>
    <div style="background:rgba(0,212,170,0.1);border:1px solid rgba(0,212,170,0.3);border-radius:8px;padding:12px 16px;margin-bottom:1.25rem;font-size:.9rem">
        <i class="fas fa-link" style="color:#00d4aa"></i>
        إضافة خدمة مرتبطة بـ <strong style="color:#00d4aa"><?=htmlspecialchars($prefillProductName)?></strong>
        (ID: <?=htmlspecialchars($prefillProductId)?>) — سعر المزود: <strong><?=number_format($prefillProductPrice,3)?></strong>
    </div>
    <?php endif; ?>

    <h3 style="margin-bottom:1.5rem"><?=$action==='edit'?'تعديل الخدمة':'إضافة خدمة جديدة'?></h3>
    <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_service" value="1">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group">
                <label>اسم الخدمة *</label>
                <input type="text" name="name" value="<?=htmlspecialchars($editSvc['name']??$prefillProductName??'')?>" required>
            </div>
            <div class="form-group">
                <label>القسم *</label>
                <select name="category_id" required>
                    <?php foreach($allCats as $c): ?>
                    <option value="<?=$c['id']?>" <?=($editSvc['category_id']??'')==$c['id']?'selected':''?>><?=$c['parent_id']?'  └ ':''?><?=htmlspecialchars($c['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>سعر البيع للعميل *
                    <?php if($prefillProductPrice): ?>
                    <small style="color:#8895a7">(سعر المزود: <?=number_format($prefillProductPrice,3)?> — ضع سعراً أعلى)</small>
                    <?php endif; ?>
                </label>
                <input type="number" name="price" step="any" min="0" inputmode="decimal" value="<?=$editSvc['price']??($prefillProductPrice?$prefillProductPrice:0)?>" required placeholder="0.00000001">
            </div>
            <div class="form-group">
                <label>الحد الأدنى للكمية</label>
                <input type="number" name="min_qty" min="1" value="<?=$editSvc['min_qty']??$prefillMinQty?>">
            </div>
            <div class="form-group">
                <label>الحد الأقصى للكمية</label>
                <input type="number" name="max_qty" min="1" value="<?=$editSvc['max_qty']??$prefillMaxQty?>">
            </div>
            <div class="form-group">
                <label>وصف الخدمة</label>
                <input type="text" name="description" value="<?=htmlspecialchars($editSvc['description']??'')?>">
            </div>
            <div class="form-group">
                <label><i class="fas fa-cog" style="color:#8b5cf6"></i> نوع الخدمة *</label>
                <?php
                  $curType = $editSvc['service_type'] ?? $prefillServiceType ?? 'default';
                  // استخراج بيانات الأرقام: أولاً من service_providers (المصدر الصحيح بعد الحفظ)
                  $naPsid = '';
                  if ($curType === 'numbers_live' && !empty($editServiceProviders)) {
                      foreach ($editServiceProviders as $_sp) {
                          if (str_starts_with($_sp['provider_service_id'] ?? '', 'live:')) {
                              $naPsid = $_sp['provider_service_id'];
                              break;
                          }
                      }
                  }
                  // fallback للإضافة الجديدة أو prefill
                  if (!$naPsid) $naPsid = $editSvc['provider_service_id'] ?? $prefillProductId ?? '';
                  $naParts = explode(':', $naPsid);
                  $naFieldService = $naParts[1] ?? '';
                  $naFieldCountry = $naParts[2] ?? '';
                  $naFieldServer  = $naParts[3] ?? '0';
                ?>
                <select name="service_type" id="svcTypeSelect" onchange="onSvcTypeChange(this.value)">
                    <option value="default"       <?=$curType==='default'?'selected':''?>>📦 خدمة عادية (SMM / Oranos / مخصص)</option>
                    <option value="numbers_live"   <?=$curType==='numbers_live'?'selected':''?>>📱 أرقام مباشرة (NumbersApp Live)</option>
                    <option value="numbers_pack"   <?=$curType==='numbers_pack'?'selected':''?>>📦 باقة NumbersApp (Packs)</option>
                    <option value="numbers_store"  <?=$curType==='numbers_store'?'selected':''?>>🏪 مخزن NumbersApp (Store)</option>
                </select>
                <small id="svcTypeHint" style="color:#8895a7;font-size:.78rem;display:block;margin-top:4px"></small>
            </div>

            <!-- ═══ حقول أرقام NumbersApp المباشرة ═══ -->
            <div id="naLiveFields" style="grid-column:1/-1;display:<?=$curType==='numbers_live'?'block':'none'?>;background:rgba(16,185,129,.06);border:1.5px solid rgba(16,185,129,.2);border-radius:14px;padding:18px;margin-bottom:4px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                    <span style="width:32px;height:32px;border-radius:10px;background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;font-size:14px">📱</span>
                    <div>
                        <div style="font-size:13px;font-weight:800;color:#10b981">إعدادات الرقم المباشر</div>
                        <div style="font-size:11px;color:#8895a7">هذه البيانات تُرسل تلقائياً عند شراء العميل — لا تظهر له</div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                    <div class="form-group" style="margin:0">
                        <label style="font-size:.78rem">رمز الخدمة (service) *</label>
                        <input type="text" name="na_field_service" id="naFieldService" value="<?=htmlspecialchars($naFieldService)?>"
                               placeholder="wa_0" style="font-family:monospace;font-size:.9rem"
                               oninput="_naBuildPsid()">
                        <small style="color:#8895a7;font-size:.7rem">مثال: wa_0 , tg_0 , ig_0</small>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:.78rem">رمز الدولة (countryCode) *</label>
                        <input type="text" name="na_field_country" id="naFieldCountry" value="<?=htmlspecialchars($naFieldCountry)?>"
                               placeholder="11" style="font-family:monospace;font-size:.9rem"
                               oninput="_naBuildPsid()">
                        <small style="color:#8895a7;font-size:.7rem">مثال: 11 = قرغزستان</small>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-size:.78rem">رقم السيرفر (serverNumber)</label>
                        <input type="text" name="na_field_server" id="naFieldServer" value="<?=htmlspecialchars($naFieldServer)?>"
                               placeholder="0" style="font-family:monospace;font-size:.9rem"
                               oninput="_naBuildPsid()">
                        <small style="color:#8895a7;font-size:.7rem">عادة 0</small>
                    </div>
                </div>
                <div style="margin-top:10px;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:8px;font-family:monospace;font-size:.78rem;color:#8895a7">
                    رقم الربط: <strong id="naBuiltPsid" style="color:#10b981">live:<?=htmlspecialchars($naFieldService.':'.$naFieldCountry.':'.$naFieldServer)?></strong>
                </div>
            </div>

        <!-- صورة الخدمة -->
        <div class="form-group" style="grid-column:1/-1">
            <label><i class="fas fa-image"></i> صورة الخدمة</label>
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                <div style="width:90px;height:70px;border-radius:10px;border:2px solid var(--border);background:var(--bg3);overflow:hidden;display:flex;align-items:center;justify-content:center;position:relative" id="svcImgBox">
                    <?php if (!empty($editSvc['image'])): ?>
                    <img src="<?= SITE_URL ?>/<?= htmlspecialchars($editSvc['image']) ?>" id="svcImgEl" style="width:100%;height:100%;object-fit:cover">
                    <i class="fas fa-image" id="svcImgPlaceholder" style="font-size:2rem;color:var(--text3);display:none"></i>
                    <?php else: ?>
                    <i class="fas fa-image" id="svcImgPlaceholder" style="font-size:2rem;color:var(--text3)"></i>
                    <img src="" id="svcImgEl" style="display:none;width:100%;height:100%;object-fit:cover">
                    <?php endif; ?>
                </div>
                <div>
                    <label style="display:inline-flex;align-items:center;gap:6px;background:var(--bg3);border:1px dashed var(--border);color:var(--text2);padding:8px 16px;border-radius:8px;cursor:pointer;font-size:.85rem" for="svcImageInput">
                        <i class="fas fa-cloud-upload-alt"></i> اختر صورة
                    </label>
                    <input type="file" name="image" id="svcImageInput" accept="image/*" style="display:none" onchange="previewImg(this,'svcImgEl','svcImgPlaceholder')">
                    <input type="hidden" name="delete_image" id="deleteImageFlag" value="0">
                    <?php if (!empty($editSvc['image'])): ?>
                    <button type="button" id="deleteImageBtn" onclick="deleteServiceImage()" style="display:inline-flex;align-items:center;gap:6px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:8px 14px;border-radius:8px;cursor:pointer;font-size:.85rem;margin-top:6px">
                        <i class="fas fa-trash"></i> حذف الصورة
                    </button>
                    <?php else: ?>
                    <button type="button" id="deleteImageBtn" onclick="deleteServiceImage()" style="display:none;align-items:center;gap:6px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:8px 14px;border-radius:8px;cursor:pointer;font-size:.85rem;margin-top:6px">
                        <i class="fas fa-trash"></i> حذف الصورة
                    </button>
                    <?php endif; ?>
                    <div style="font-size:.78rem;color:#8895a7;margin-top:4px">PNG, JPG, WebP — حجم أقصى 2MB</div>
                </div>
            </div>
        </div>

        </div>

        <!-- ════ مزودو API المتعددون ════ -->
        <div style="margin:1.5rem 0 0;border:1px solid var(--border2);border-radius:12px;overflow:hidden">
          <div style="background:var(--card2);padding:14px 18px;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px">
              <i class="fas fa-plug" style="color:var(--cyan)"></i>
              مزودو API
              <span id="spCount" style="background:var(--cyan);color:#000;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:800">
                <?= count($editServiceProviders) ?>
              </span>
            </div>
            <button type="button" onclick="addProviderRow()" style="background:rgba(6,182,212,0.15);border:1px solid rgba(6,182,212,0.4);color:var(--cyan);border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;font-family:var(--font)">
              <i class="fas fa-plus"></i> إضافة مزود
            </button>
          </div>

          <div id="spList" style="padding:12px 16px;display:flex;flex-direction:column;gap:10px">
            <?php if(empty($editServiceProviders)): ?>
            <div id="spEmpty" style="text-align:center;padding:20px;color:var(--text3);font-size:13px">
              <i class="fas fa-plug" style="font-size:24px;opacity:.3;display:block;margin-bottom:8px"></i>
              لا يوجد مزود — الطلبات ستُنفَّذ يدوياً
            </div>
            <?php else: ?>
            <?php foreach($editServiceProviders as $i => $sp): ?>
            <div class="sp-row" data-index="<?=$i?>" style="background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:12px 14px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span style="background:var(--cyan);color:#000;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:800">
                  #<?=$i+1?>
                </span>
                <span style="font-size:12px;color:var(--text2)"><?=htmlspecialchars($sp['pname'])?></span>
                <div style="flex:1"></div>
                <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text2);cursor:pointer">
                  <input type="checkbox" name="sp_active[]" value="1" <?=$sp['is_active']?'checked':''?> style="accent-color:var(--green)"> مفعّل
                </label>
                <button type="button" onclick="removeProviderRow(this)" style="background:rgba(239,68,68,.15);border:none;color:var(--red);border-radius:6px;padding:4px 8px;cursor:pointer">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
                <div>
                  <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">المزود</label>
                  <select name="sp_provider_id[]" style="width:100%">
                    <option value="">-- اختر --</option>
                    <?php foreach($providers as $pv): ?>
                    <option value="<?=$pv['id']?>" <?=$sp['provider_id']==$pv['id']?'selected':''?>><?=htmlspecialchars($pv['name'])?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">
                    ID الخدمة عند المزود
                    <span style="color:var(--cyan);font-size:10px;margin-right:4px">← اكتب للبحث</span>
                  </label>
                  <div class="sp-ac-wrap">
                    <input type="text" name="sp_provider_svc_id[]"
                           value="<?=htmlspecialchars($sp['provider_service_id'])?>"
                           placeholder="اكتب ID أو اسم الخدمة..."
                           style="width:100%"
                           oninput="onSvcIdInput(this)"
                           onfocus="onSvcIdInput(this)"
                           autocomplete="off">
                    <div class="sp-ac-dropdown"></div>
                  </div>
                </div>
                <div>
                  <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">اسم الخدمة عند هذا المزود (اختياري)</label>
                  <input type="text" name="sp_label[]" value="<?=htmlspecialchars($sp['label']??'')?>" placeholder="مثال: يوهو (كما يسميها هذا المزود)" style="width:100%">
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div style="padding:10px 16px;background:var(--bg2);border-top:1px solid var(--border);font-size:11px;color:var(--text3)">
            <i class="fas fa-info-circle"></i>
            الترتيب = الأولوية — المزود الأول يُستخدم دائماً، وإذا فشل ينتقل للتالي تلقائياً
          </div>
        </div>

        <!-- [تمت إزالة الحقلين المخفيين القديمين هنا] كانا يُرسلان دائماً فارغين ويمحوان
             ربط الخدمة الأصلي بالمزود (provider_id/provider_service_id) عند كل حفظ،
             مما كان يكسر مطابقة الاستيراد لاحقاً. الربط الحقيقي الآن يُدار حصراً عبر
             قسم "المزودون المتعددون" بالأعلى (جدول service_providers). -->

        <!-- الحقول المخصصة -->
        <h4 style="margin:1.25rem 0 .75rem;color:#8895a7"><i class="fas fa-list-ul"></i> حقول بيانات الطلب</h4>
        <div id="fields-container">
            <?php
            // إذا جاء من providers.php مع params → أنشئ الحقول تلقائياً
            $fieldsToShow = $editFields;
            if (empty($fieldsToShow) && !empty($prefillParams)) {
                foreach ($prefillParams as $param) {
                    $fieldsToShow[] = [
                        'field_name'  => slugify($param),
                        'field_label' => $param,
                        'field_type'  => 'text',
                        'field_options' => '',
                        'is_required' => 1,
                    ];
                }
            }
            foreach ($fieldsToShow as $i => $ef): ?>
            <div class="field-row" style="display:grid;grid-template-columns:2fr 2fr 1fr 1fr auto;gap:.75rem;align-items:end;margin-bottom:.75rem">
                <div class="form-group" style="margin:0">
                    <label>المفتاح (بدون مسافات) <small style="color:#00d4aa">يُرسل للمزود كـ parameter</small></label>
                    <input type="text" name="field_name[]" value="<?=htmlspecialchars($ef['field_name'])?>" placeholder="player_id">
                </div>
                <div class="form-group" style="margin:0">
                    <label>التسمية للعميل</label>
                    <input type="text" name="field_label[]" value="<?=htmlspecialchars($ef['field_label'])?>" placeholder="رقم اللاعب">
                </div>
                <div class="form-group" style="margin:0">
                    <label>النوع</label>
                    <select name="field_type[]">
                        <?php foreach(['text','number','email','select'] as $t): ?>
                        <option value="<?=$t?>" <?=$ef['field_type']==$t?'selected':''?>><?=$t?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label><input type="checkbox" name="field_required[<?=$i?>]" <?=$ef['is_required']?'checked':''?>> إجباري</label>
                    <input type="text" name="field_options[]" value="<?=htmlspecialchars($ef['field_options']??'')?>" placeholder="خيارات للـ select">
                </div>
                <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-sm mb-2" onclick="addField()">
            <i class="fas fa-plus"></i> إضافة حقل
        </button>
        <div style="margin-top:1rem">
            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ الخدمة</button>
            <a href="services.php" class="btn btn-secondary">إلغاء</a>
        </div>
    </form>
</div>

<script>
let fieldIdx = <?=count($fieldsToShow??[])?>;
function addField() {
    const i = fieldIdx++;
    const div = document.createElement('div');
    div.className = 'field-row';
    div.style.cssText = 'display:grid;grid-template-columns:2fr 2fr 1fr 1fr auto;gap:.75rem;align-items:end;margin-bottom:.75rem';
    div.innerHTML = `
        <div class="form-group" style="margin:0"><label>المفتاح</label><input type="text" name="field_name[]" placeholder="player_id"></div>
        <div class="form-group" style="margin:0"><label>التسمية</label><input type="text" name="field_label[]" placeholder="رقم اللاعب"></div>
        <div class="form-group" style="margin:0"><label>النوع</label>
            <select name="field_type[]"><option value="text">text</option><option value="number">number</option><option value="email">email</option><option value="select">select</option></select>
        </div>
        <div class="form-group" style="margin:0">
            <label><input type="checkbox" name="field_required[${i}]" checked> إجباري</label>
            <input type="text" name="field_options[]" placeholder="خيارات (select)">
        </div>
        <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>`;
    document.getElementById('fields-container').appendChild(div);
}

// ── نوع الخدمة: تلميحات + إظهار/إخفاء حقول الأرقام ──
const SVC_TYPE_HINTS = {
  'default':       '',
  'numbers_live':  '📱 عند العميل: يضغط شراء → يظهر الرقم فوراً + مؤقت + فحص كود تلقائي',
  'numbers_pack':  '📦 عند العميل: ستظهر حقول الإدخال المطلوبة ثم الشراء والمتابعة التلقائية',
  'numbers_store': '🏪 عند العميل: شراء مباشر وتسليم المنتج فوراً بدون انتظار',
};
function onSvcTypeChange(v) {
  var h = document.getElementById('svcTypeHint');
  if (h) h.textContent = SVC_TYPE_HINTS[v] || '';
  var naFields = document.getElementById('naLiveFields');
  if (naFields) naFields.style.display = v === 'numbers_live' ? 'block' : 'none';
  // عند التبديل لأرقام → بناء الـ psid
  if (v === 'numbers_live') _naBuildPsid();
}
onSvcTypeChange(document.getElementById('svcTypeSelect')?.value || 'default');

// بناء provider_service_id من حقول الأرقام
function _naBuildPsid() {
  var svc = (document.getElementById('naFieldService')?.value || '').trim();
  var cc  = (document.getElementById('naFieldCountry')?.value || '').trim();
  var sn  = (document.getElementById('naFieldServer')?.value || '0').trim();
  var psid = 'live:' + svc + ':' + cc + ':' + sn;
  var el = document.getElementById('naBuiltPsid');
  if (el) el.textContent = psid;
  // تعبئة حقل provider_service_id المخفي في قسم المزودين
  var spInputs = document.querySelectorAll('[name="sp_provider_svc_id[]"]');
  if (spInputs.length > 0 && document.getElementById('svcTypeSelect')?.value === 'numbers_live') {
    spInputs[0].value = psid;
  }
}
</script>
<?php endif; ?>

<div class="card">
  <!-- شريط التحكم -->
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <!-- بحث نصي -->
    <input type="text" name="fsearch" id="svcSearchInput" placeholder="🔍 بحث باسم الخدمة..."
           value="<?=htmlspecialchars($fSearch)?>"
           style="background:var(--bg);border:1.5px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none;flex:1;min-width:160px"
           onkeydown="if(event.key==='Enter')submitFilter()">

    <!-- فلتر القسم -->
    <select id="filterCat" onchange="submitFilter()" class="form-control" style="width:auto;min-width:160px">
      <option value="">— كل الأقسام —</option>
      <?php foreach($allCats as $ac): ?>
      <option value="<?=$ac['id']?>" <?=($fCat==$ac['id'])?'selected':''?>><?=$ac['parent_id']?'  └ ':''?><?=htmlspecialchars($ac['name'])?></option>
      <?php endforeach; ?>
    </select>

    <!-- فلتر المزود -->
    <select id="filterProvider" onchange="submitFilter()" class="form-control" style="width:auto;min-width:150px">
      <option value="">— كل المزودين —</option>
      <option value="0" <?=($fProvider===0)?'selected':''?>>يدوي (بدون مزود)</option>
      <?php foreach($providers as $pv): ?>
      <option value="<?=$pv['id']?>" <?=($fProvider===$pv['id'])?'selected':''?>><?=htmlspecialchars($pv['name'])?></option>
      <?php endforeach; ?>
    </select>

    <button onclick="submitFilter()" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> بحث</button>
    <?php if($fCat||$fSearch||$fProvider>=0): ?>
    <a href="?action=list" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> مسح</a>
    <?php endif; ?>

    <!-- عداد النتائج + زر عرض الكل -->
    <span style="font-size:.78rem;color:#8895a7;white-space:nowrap"><?=$totalCount?> خدمة</span>
    <?php
      $showAllUrl = '?' . http_build_query(array_merge($_GET, ['showall'=>'1','page'=>1]));
      $hideAllUrl = '?' . http_build_query(array_filter(array_merge($_GET, ['showall'=>null,'page'=>1]), fn($v)=>$v!==null));
    ?>
    <?php if(!$showAll): ?>
    <a href="<?=$showAllUrl?>" class="btn btn-secondary btn-sm" title="عرض كل الخدمات بدون ترقيم صفحات">
      <i class="fas fa-list"></i> عرض الكل
    </a>
    <?php else: ?>
    <a href="<?=$hideAllUrl?>" class="btn btn-secondary btn-sm">
      <i class="fas fa-compress-alt"></i> عرض 100
    </a>
    <?php endif; ?>

    <!-- شريط الحذف الدفعي -->
    <div id="bulkSvcBar" style="display:none;align-items:center;gap:8px;margin-right:auto">
      <span id="bulkSvcCount" style="font-size:.78rem;color:#ff4455;font-weight:700"></span>
      <button onclick="doBulkDelete()" class="btn btn-danger btn-sm">
        <i class="fas fa-trash"></i> حذف المحددة
      </button>
      <button onclick="clearSvcSel()" class="btn btn-secondary btn-sm">إلغاء</button>
    </div>

    <!-- حذف قسم كامل -->
    <div style="display:flex;gap:6px;align-items:center;margin-right:<?php echo empty($allCats)?'auto':'0'?>">
      <form method="POST" onsubmit="return confirm('حذف كل خدمات هذا القسم؟ لا يمكن التراجع!')" style="display:flex;gap:6px">
        <?= adminCsrfField() ?>
        <input type="hidden" name="delete_cat_services" value="1">
        <select name="del_cat_id" class="form-control" style="width:auto;min-width:140px" required>
          <option value="">— حذف خدمات قسم —</option>
          <?php foreach($allCats as $c): ?>
          <option value="<?=$c['id']?>"><?=$c['parent_id']?'  └ ':''?><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
      </form>
    </div>
  </div>

  <div class="table-wrap">
    <form method="POST" id="bulkSvcForm">
      <input type="hidden" name="bulk_delete_services" value="1">
      <table>
        <thead>
          <tr>
            <th style="width:36px"><input type="checkbox" id="checkAllSvc" onchange="toggleAllSvc(this)"></th>
            <th>#</th><th>الصورة</th><th>الخدمة</th><th>النوع</th><th>القسم</th><th>السعر</th><th>الكمية</th><th>المزود</th><th>الحالة</th><th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
                <?php foreach($services as $svc): $svcOff = !$svc['status']; ?>
                <tr data-cat="<?=$svc['category_id']?>" data-name="<?=strtolower(htmlspecialchars($svc['name']))?>" data-provider="<?=(int)($svc['provider_id']??0)?>"
                    <?php if ($svcOff): ?>style="opacity:.45"<?php endif; ?>>
                    <td><input type="checkbox" name="svc_ids[]" value="<?=$svc['id']?>" class="svc-chk" onchange="updateSvcBar()"></td>
                    <td><?=$svc['id']?></td>
                    <td><?= !empty($svc['image']) ? '<img src="'.SITE_URL.'/'.$svc['image'].'" style="width:44px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">' : '<span style="color:#8895a7">—</span>' ?></td>
                    <td><strong><?=htmlspecialchars($svc['name'])?></strong></td>
                    <td>
                        <?php
                        $stLabels = [
                            'default'       => '',
                            'numbers_live'  => '<span class="badge" style="background:#10b981;color:#fff;font-size:.65rem">📱 أرقام</span>',
                            'numbers_pack'  => '<span class="badge" style="background:#8b5cf6;color:#fff;font-size:.65rem">📦 باقة</span>',
                            'numbers_store' => '<span class="badge" style="background:#f59e0b;color:#000;font-size:.65rem">🏪 مخزن</span>',
                        ];
                        echo $stLabels[$svc['service_type'] ?? 'default'] ?? '';
                        ?>
                    </td>
                    <td><?=htmlspecialchars($svc['cat_name'])?></td>
                    <td style="color:#00d4aa"><strong><?=formatMoney($svc['price'])?></strong></td>
                    <td><?=$svc['min_qty']?> - <?=$svc['max_qty']?></td>
                    <td>
                        <?php
                        $spCnt = $spCounts[$svc['id']] ?? 0;
                        if ($spCnt > 0): ?>
                        <span style="color:#00d4aa;font-weight:700">
                            <i class="fas fa-plug" style="font-size:10px"></i>
                            <?= $spCnt ?> مزود<?= $spCnt > 1 ? 'ين' : '' ?>
                        </span>
                        <?php elseif($svc['provider_name']): ?>
                        <span style="color:#00d4aa"><?=htmlspecialchars($svc['provider_name'])?></span>
                        <small class="text-muted">#<?=$svc['provider_service_id']?></small>
                        <?php else: ?>
                        <span class="badge badge-secondary">يدوي</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?action=toggle&id=<?=$svc['id']?>" title="<?=$svc['status']?'اضغط لإيقاف الخدمة':'اضغط لتفعيل الخدمة'?>">
                            <span class="badge badge-<?=$svc['status']?'success':'danger'?>"><i class="fas fa-<?=$svc['status']?'check-circle':'pause-circle'?>" style="font-size:.65rem"></i> <?=$svc['status']?'نشط':'متوقف'?></span>
                        </a>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?action=edit&id=<?=$svc['id']?>" class="btn btn-sm btn-warning" title="تعديل"><i class="fas fa-edit"></i></a>
                        <a href="?action=delete&id=<?=$svc['id']?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('حذف الخدمة: <?=addslashes(htmlspecialchars($svc['name']))?> ؟\nلا يمكن التراجع!')"
                           title="حذف"><i class="fas fa-trash"></i> حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </form>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;align-items:center;justify-content:center;gap:6px;padding:1.25rem 0;flex-wrap:wrap">

    <?php
    // بناء رابط الصفحة مع الحفاظ على باقي المعاملات
    $qBase = array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY);
    function pgLink($p, $base) {
        $params = array_merge($base, ['page' => $p]);
        return '?' . http_build_query($params);
    }
    ?>

    <?php /* زر السابق */ ?>
    <?php if ($currentPage > 1): ?>
    <a href="<?= pgLink($currentPage - 1, $qBase) ?>"
       style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.85rem;transition:all .2s"
       onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
       onmouseout="this.style.background='var(--card)';this.style.color='var(--text)';this.style.borderColor='var(--border)'">
        <i class="fas fa-chevron-right"></i> السابق
    </a>
    <?php else: ?>
    <span style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text-muted);font-size:0.85rem;opacity:.5;cursor:not-allowed">
        <i class="fas fa-chevron-right"></i> السابق
    </span>
    <?php endif; ?>

    <?php /* أرقام الصفحات */ ?>
    <?php
    $range = 2; // عدد الصفحات قبل وبعد الحالية
    $start = max(1, $currentPage - $range);
    $end   = min($totalPages, $currentPage + $range);
    ?>

    <?php if ($start > 1): ?>
        <a href="<?= pgLink(1, $qBase) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.85rem;font-weight:600;transition:all .2s"
           onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
           onmouseout="this.style.background='var(--card)';this.style.color='var(--text)';this.style.borderColor='var(--border)'">1</a>
        <?php if ($start > 2): ?>
            <span style="color:var(--text-muted);font-size:0.85rem;padding:0 2px">...</span>
        <?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $start; $p <= $end; $p++): ?>
        <?php if ($p === $currentPage): ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:var(--primary);border:1px solid var(--primary);color:#fff;font-size:0.85rem;font-weight:700">
            <?= $p ?>
        </span>
        <?php else: ?>
        <a href="<?= pgLink($p, $qBase) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.85rem;font-weight:600;transition:all .2s"
           onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
           onmouseout="this.style.background='var(--card)';this.style.color='var(--text)';this.style.borderColor='var(--border)'"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?>
            <span style="color:var(--text-muted);font-size:0.85rem;padding:0 2px">...</span>
        <?php endif; ?>
        <a href="<?= pgLink($totalPages, $qBase) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.85rem;font-weight:600;transition:all .2s"
           onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
           onmouseout="this.style.background='var(--card)';this.style.color='var(--text)';this.style.borderColor='var(--border)'"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php /* زر التالي */ ?>
    <?php if ($currentPage < $totalPages): ?>
    <a href="<?= pgLink($currentPage + 1, $qBase) ?>"
       style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.85rem;transition:all .2s"
       onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
       onmouseout="this.style.background='var(--card)';this.style.color='var(--text)';this.style.borderColor='var(--border)'">
        التالي <i class="fas fa-chevron-left"></i>
    </a>
    <?php else: ?>
    <span style="display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:8px;background:var(--card);border:1px solid var(--border);color:var(--text-muted);font-size:0.85rem;opacity:.5;cursor:not-allowed">
        التالي <i class="fas fa-chevron-left"></i>
    </span>
    <?php endif; ?>

    <span style="color:var(--text-muted);font-size:0.8rem;margin-right:8px">
        صفحة <?= $currentPage ?> من <?= $totalPages ?> (<?= $totalCount ?> خدمة)
    </span>
</div>
<?php endif; ?>

<script>
// ══ تحديد دفعي للخدمات ══════════════════════════════════════
function toggleAllSvc(master) {
  var visible = document.querySelectorAll('.svc-chk:not([style*="none"])');
  // نحدد الـ checkboxes الظاهرة فقط
  document.querySelectorAll('.svc-chk').forEach(function(cb) {
    var tr = cb.closest('tr');
    if (!tr || tr.style.display !== 'none') cb.checked = master.checked;
  });
  updateSvcBar();
}

function updateSvcBar() {
  var checked = document.querySelectorAll('.svc-chk:checked');
  var bar = document.getElementById('bulkSvcBar');
  var lbl = document.getElementById('bulkSvcCount');
  if (bar) bar.style.display = checked.length > 0 ? 'flex' : 'none';
  if (lbl) lbl.textContent = 'محدد: ' + checked.length + ' خدمة';
  var master = document.getElementById('checkAllSvc');
  var all = document.querySelectorAll('.svc-chk');
  if (master) {
    master.indeterminate = checked.length > 0 && checked.length < all.length;
    if (checked.length === all.length && all.length > 0) master.checked = true;
    if (checked.length === 0) master.checked = false;
  }
}

function clearSvcSel() {
  document.querySelectorAll('.svc-chk').forEach(function(cb){ cb.checked=false; });
  var master = document.getElementById('checkAllSvc');
  if (master) { master.checked=false; master.indeterminate=false; }
  updateSvcBar();
}

function doBulkDelete() {
  var checked = document.querySelectorAll('.svc-chk:checked');
  if (!checked.length) return;
  if (!confirm('حذف ' + checked.length + ' خدمة؟ لا يمكن التراجع!')) return;
  document.getElementById('bulkSvcForm').submit();
}

// ══ فلترة الجدول ══════════════════════════════════════════════
function filterByCat() { filterSvcs(); } // legacy alias

function submitFilter() {
  var params = new URLSearchParams();
  params.set('action','list');
  var s = document.getElementById('svcSearchInput').value.trim();
  var cat = document.getElementById('filterCat').value;
  var prov = document.getElementById('filterProvider').value;
  if (s)    params.set('fsearch', s);
  if (cat)  params.set('fcat', cat);
  if (prov !== '') params.set('fprov', prov);
  window.location.href = '?' + params.toString();
}

function filterSvcs() {
  var cat      = document.getElementById('filterCat').value;
  var provider = document.getElementById('filterProvider').value;
  var q        = (document.getElementById('svcSearchInput').value||'').toLowerCase().trim();
  var rows     = document.querySelectorAll('#bulkSvcForm tbody tr');
  var visible  = 0;

  rows.forEach(function(tr) {
    var catOk  = !cat      || tr.dataset.cat === cat;
    var prvOk  = !provider || tr.dataset.provider === provider;
    var nameOk = !q        || (tr.dataset.name||'').includes(q);
    var show   = catOk && prvOk && nameOk;
    tr.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  // عداد النتائج
  var total = rows.length;
  var countEl = document.getElementById('svcFilterCount');
  if (countEl) {
    countEl.textContent = (cat||provider||q) ? visible + ' من ' + total + ' خدمة' : total + ' خدمة';
  }

  // زر مسح الفلاتر
  var clearBtn = document.getElementById('clearFiltersBtn');
  if (clearBtn) clearBtn.style.display = (cat||provider||q) ? '' : 'none';

  clearSvcSel();
}

function clearFilters() {
  document.getElementById('filterCat').value      = '';
  document.getElementById('filterProvider').value = '';
  document.getElementById('svcSearchInput').value = '';
  filterSvcs();
}

// عرض العداد عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', function(){ filterSvcs(); });

// ── ضغط الصور إلى WebP / JPEG بحجم أقل من 150KB ────────────
const USE_WEBP_SVC = document.createElement('canvas')
                       .toDataURL('image/webp').startsWith('data:image/webp');
const IMG_FORMAT   = USE_WEBP_SVC ? 'image/webp' : 'image/jpeg';
const IMG_EXT      = USE_WEBP_SVC ? 'webp' : 'jpg';
const IMG_TARGET   = 150 * 1024;  // 150 KB

function compressAdminImage(file) {
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
            if (blob.size <= IMG_TARGET || quality <= 0.25) { resolve(blob); }
            else { quality = Math.max(0.25, quality - 0.10); attempt(); }
          }, IMG_FORMAT, quality);
        };
        attempt();
      };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });
}

async function previewImg(input, imgId, placeholderId) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (!file.type.startsWith('image/')) { alert('الملف يجب أن يكون صورة'); input.value=''; return; }

    // ضغط الصورة
    const blob = await compressAdminImage(file);
    if (!blob) return;

    // استبدال الملف في الـ input بالنسخة المضغوطة
    const compressed = new File([blob], 'image.' + IMG_EXT, { type: IMG_FORMAT });
    const dt = new DataTransfer();
    dt.items.add(compressed);
    input.files = dt.files;

    // معاينة
    const url = URL.createObjectURL(blob);
    const img = document.getElementById(imgId);
    const ph  = document.getElementById(placeholderId);
    if (img) { img.src = url; img.style.display = 'block'; }
    if (ph)  { ph.style.display = 'none'; }
    // إظهار زر الحذف وإلغاء فلاغ الحذف
    const delBtn  = document.getElementById('deleteImageBtn');
    const delFlag = document.getElementById('deleteImageFlag');
    if (delBtn)  { delBtn.style.display = 'inline-flex'; }
    if (delFlag) { delFlag.value = '0'; }
}
</script>

<style>
/* ── Autocomplete dropdown ── */
.sp-ac-wrap{position:relative}
.sp-ac-dropdown{
  position:absolute;top:calc(100% + 4px);right:0;left:0;z-index:9999;
  background:var(--card);border:1px solid var(--border2);border-radius:10px;
  box-shadow:0 8px 24px rgba(0,0,0,.4);max-height:240px;overflow-y:auto;
  display:none;scrollbar-width:thin;
}
.sp-ac-dropdown.open{display:block}
.sp-ac-item{
  padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;transition:background .1s;
}
.sp-ac-item:last-child{border-bottom:none}
.sp-ac-item:hover,.sp-ac-item.focused{background:rgba(6,182,212,.12)}
.sp-ac-id{
  font-size:11px;font-weight:800;color:var(--cyan);background:rgba(6,182,212,.12);
  border-radius:5px;padding:2px 7px;flex-shrink:0;font-family:monospace;
}
.sp-ac-name{font-size:12px;color:var(--text);flex:1;line-height:1.3}
.sp-ac-price{font-size:11px;color:var(--green);flex-shrink:0;font-weight:700}
.sp-ac-cat{font-size:10px;color:var(--text3);display:block;margin-top:1px}
.sp-ac-loading{padding:14px;text-align:center;color:var(--text3);font-size:12px}
.sp-ac-empty{padding:14px;text-align:center;color:var(--text3);font-size:12px}
.sp-svc-badge{
  display:inline-flex;align-items:center;gap:6px;margin-top:5px;
  background:rgba(6,182,212,.1);border:1px solid rgba(6,182,212,.25);
  border-radius:8px;padding:4px 10px;font-size:11px;color:var(--cyan);
}
</style>

<script>
// ══════════════════════════════════════════════════════════════
// مزودو API المتعددون + Autocomplete — JS
// ══════════════════════════════════════════════════════════════
const PROVIDERS_LIST = <?php echo json_encode(
  array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name']], $providers),
  JSON_UNESCAPED_UNICODE
); ?>;

// cache لخدمات كل مزود { providerId: [...services] }
const _svcCache = {};
let _acTimer    = null;
let _activeDropdown = null;

// ── إغلاق كل الـ dropdowns عند الضغط خارجها ──────────────────
document.addEventListener('click', e => {
  if (!e.target.closest('.sp-ac-wrap')) closeAllDropdowns();
});

function closeAllDropdowns() {
  document.querySelectorAll('.sp-ac-dropdown.open')
    .forEach(d => d.classList.remove('open'));
  _activeDropdown = null;
}

// ── جلب خدمات المزود (مع cache) ──────────────────────────────
async function fetchProviderServices(providerId, q = '') {
  if (!providerId) return [];
  const cacheKey = `${providerId}_all`;

  // إذا في cache نستخدمه مباشرة مع فلترة
  if (_svcCache[cacheKey]) {
    return filterServices(_svcCache[cacheKey], q);
  }

  // جلب من الـ API
  const res = await fetch(
    `<?= SITE_URL ?>/api/provider_services.php?provider_id=${providerId}&q=`,
    { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
  );
  const data = await res.json();
  if (data.ok && data.services) {
    _svcCache[cacheKey] = data.services;
    return filterServices(data.services, q);
  }
  return [];
}

function filterServices(list, q) {
  if (!q) return list.slice(0, 50);
  q = q.toLowerCase();
  return list.filter(s =>
    s.id.toLowerCase().includes(q) ||
    s.name.toLowerCase().includes(q) ||
    (s.category||'').toLowerCase().includes(q)
  ).slice(0, 50);
}

// ── بناء HTML لعنصر في القائمة ────────────────────────────────
function buildAcItem(svc) {
  const div = document.createElement('div');
  div.className = 'sp-ac-item';
  div.dataset.id    = svc.id;
  div.dataset.name  = svc.name;
  div.dataset.price = svc.price || '';
  div.innerHTML = `
    <span class="sp-ac-id">${escHtml(svc.id)}</span>
    <span class="sp-ac-name">
      ${escHtml(svc.name)}
      ${svc.category ? `<span class="sp-ac-cat">${escHtml(svc.category)}</span>` : ''}
    </span>
    ${svc.price ? `<span class="sp-ac-price">$${parseFloat(svc.price).toFixed(3)}</span>` : ''}
  `;
  return div;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── حذف صورة الخدمة ──────────────────────────────────────────
function deleteServiceImage() {
  const imgEl       = document.getElementById('svcImgEl');
  const placeholder = document.getElementById('svcImgPlaceholder');
  const flag        = document.getElementById('deleteImageFlag');
  const btn         = document.getElementById('deleteImageBtn');
  const fileInput   = document.getElementById('svcImageInput');

  // إخفاء الصورة وإظهار placeholder
  if (imgEl)       { imgEl.src = ''; imgEl.style.display = 'none'; }
  if (placeholder) placeholder.style.display = '';
  // تعيين الفلاغ للحذف
  if (flag) flag.value = '1';
  // إخفاء زر الحذف
  if (btn)  btn.style.display = 'none';
  // مسح أي ملف تم اختياره
  if (fileInput) fileInput.value = '';
}

// ── التعامل مع الكتابة في حقل الـ ID ─────────────────────────
async function onSvcIdInput(input) {
  const row        = input.closest('.sp-row');
  const provSelect = row.querySelector('select[name="sp_provider_id[]"]');
  const providerId = provSelect ? provSelect.value : '';
  const dropdown   = row.querySelector('.sp-ac-dropdown');
  const badge      = row.querySelector('.sp-svc-badge');

  if (badge) badge.style.display = 'none';
  if (!providerId || !dropdown) return;

  const q = input.value.trim();

  // أظهر spinner
  dropdown.innerHTML = '<div class="sp-ac-loading"><i class="fas fa-spinner fa-spin"></i> جاري الجلب...</div>';
  dropdown.classList.add('open');

  clearTimeout(_acTimer);
  _acTimer = setTimeout(async () => {
    try {
      const list = await fetchProviderServices(providerId, q);
      renderDropdown(dropdown, list, input);
    } catch(e) {
      dropdown.innerHTML = '<div class="sp-ac-empty">⚠️ فشل الاتصال بالمزود</div>';
    }
  }, 350);
}

function renderDropdown(dropdown, list, input) {
  dropdown.innerHTML = '';
  if (!list.length) {
    dropdown.innerHTML = '<div class="sp-ac-empty">لا توجد نتائج</div>';
    return;
  }
  list.forEach(svc => {
    const item = buildAcItem(svc);
    item.addEventListener('click', () => selectService(svc, input));
    dropdown.appendChild(item);
  });
}

// ── اختيار خدمة من القائمة ────────────────────────────────────
function selectService(svc, input) {
  const row = input.closest('.sp-row');

  // ضع الـ ID في الحقل
  input.value = svc.id;

  // أظهر badge بالاسم
  let badge = row.querySelector('.sp-svc-badge');
  if (!badge) {
    badge = document.createElement('div');
    badge.className = 'sp-svc-badge';
    input.parentNode.appendChild(badge);
  }
  badge.style.display = 'inline-flex';
  badge.innerHTML = `
    <i class="fas fa-check-circle"></i>
    <span>${escHtml(svc.name)}</span>
    ${svc.price ? `<strong>$${parseFloat(svc.price).toFixed(3)}</strong>` : ''}
  `;

  // أغلق الـ dropdown
  const dropdown = row.querySelector('.sp-ac-dropdown');
  if (dropdown) dropdown.classList.remove('open');
}

// ── عند تغيير المزود — امسح الـ cache وأعد تحميل ──────────────
function updateRowLabel(select) {
  const row      = select.closest('.sp-row');
  const nameSpan = row.querySelector('.sp-index-badge + span');
  if (nameSpan) nameSpan.textContent = select.options[select.selectedIndex].text;

  // امسح حقل الـ ID والـ badge
  const svcInput = row.querySelector('input[name="sp_provider_svc_id[]"]');
  const badge    = row.querySelector('.sp-svc-badge');
  if (svcInput) svcInput.value = '';
  if (badge)    badge.style.display = 'none';

  // جلب مسبق للخدمات في الخلفية
  if (select.value) fetchProviderServices(select.value, '');
}

// ── بناء صف مزود جديد ────────────────────────────────────────
function getSpCount() {
  return document.querySelectorAll('#spList .sp-row').length;
}
function updateSpBadge() {
  const b = document.getElementById('spCount');
  if (b) b.textContent = getSpCount();
}
function updateSpIndexes() {
  document.querySelectorAll('#spList .sp-row').forEach((row, i) => {
    const b = row.querySelector('.sp-index-badge');
    if (b) b.textContent = '#' + (i+1);
    row.dataset.index = i;
  });
}
function removeProviderRow(btn) {
  btn.closest('.sp-row').remove();
  updateSpIndexes();
  updateSpBadge();
  if (getSpCount() === 0) {
    const e = document.createElement('div');
    e.id = 'spEmpty';
    e.style.cssText = 'text-align:center;padding:20px;color:#475569;font-size:13px';
    e.innerHTML = '<i class="fas fa-plug" style="font-size:24px;opacity:.3;display:block;margin-bottom:8px"></i>لا يوجد مزود — الطلبات ستُنفَّذ يدوياً';
    document.getElementById('spList').appendChild(e);
  }
}

function addProviderRow() {
  const empty = document.getElementById('spEmpty');
  if (empty) empty.remove();

  const idx  = getSpCount();
  let opts   = '<option value="">-- اختر --</option>';
  PROVIDERS_LIST.forEach(p => {
    opts += `<option value="${p.id}">${p.name}</option>`;
  });

  const row = document.createElement('div');
  row.className   = 'sp-row';
  row.dataset.index = idx;
  row.draggable   = true;
  row.style.cssText = 'background:var(--bg2);border:1px solid var(--border2);border-radius:10px;padding:12px 14px';
  row.innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <span class="sp-index-badge" style="background:var(--cyan);color:#000;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:800">#${idx+1}</span>
      <span style="font-size:12px;color:var(--text2)">مزود جديد</span>
      <div style="flex:1"></div>
      <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text2);cursor:pointer">
        <input type="checkbox" name="sp_active[]" value="1" checked style="accent-color:var(--green)"> مفعّل
      </label>
      <button type="button" onclick="removeProviderRow(this)" style="background:rgba(239,68,68,.15);border:none;color:var(--red);border-radius:6px;padding:4px 8px;cursor:pointer">
        <i class="fas fa-trash"></i>
      </button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
      <div>
        <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">المزود</label>
        <select name="sp_provider_id[]" style="width:100%" onchange="updateRowLabel(this)">
          ${opts}
        </select>
      </div>
      <div>
        <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">
          ID الخدمة عند المزود
          <span style="color:var(--cyan);font-size:10px;margin-right:4px">← اكتب للبحث</span>
        </label>
        <div class="sp-ac-wrap">
          <input type="text" name="sp_provider_svc_id[]"
                 placeholder="اكتب ID أو اسم الخدمة..."
                 style="width:100%"
                 oninput="onSvcIdInput(this)"
                 onfocus="onSvcIdInput(this)"
                 autocomplete="off">
          <div class="sp-ac-dropdown"></div>
        </div>
      </div>
      <div>
        <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">ملاحظة (اختياري)</label>
        <input type="text" name="sp_label[]" placeholder="مثال: مزود احتياطي" style="width:100%">
      </div>
    </div>
  `;
  document.getElementById('spList').appendChild(row);
  updateSpBadge();
}

// ── Drag to reorder ───────────────────────────────────────────
let dragSrc = null;
const spList = document.getElementById('spList');
spList.addEventListener('dragstart', e => {
  dragSrc = e.target.closest('.sp-row');
  if (dragSrc) { dragSrc.style.opacity = '0.5'; e.dataTransfer.effectAllowed = 'move'; }
});
spList.addEventListener('dragend', () => {
  document.querySelectorAll('#spList .sp-row').forEach(r => r.style.opacity = '1');
  updateSpIndexes();
});
spList.addEventListener('dragover', e => {
  e.preventDefault();
  const target = e.target.closest('.sp-row');
  if (target && target !== dragSrc) {
    const after = e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
    spList.insertBefore(dragSrc, after ? target.nextSibling : target);
  }
});
document.querySelectorAll('#spList .sp-row').forEach(r => r.draggable = true);

// ── تفعيل الـ autocomplete على الصفوف الموجودة مسبقاً ─────────
document.querySelectorAll('#spList .sp-row').forEach(row => {
  const svcInput  = row.querySelector('input[name="sp_provider_svc_id[]"]');
  const provSelect = row.querySelector('select[name="sp_provider_id[]"]');

  if (!svcInput) return;

  // أضف الـ dropdown إذا لم يكن موجوداً
  if (!svcInput.parentNode.classList.contains('sp-ac-wrap')) {
    const wrap = document.createElement('div');
    wrap.className = 'sp-ac-wrap';
    svcInput.parentNode.insertBefore(wrap, svcInput);
    wrap.appendChild(svcInput);
    const dd = document.createElement('div');
    dd.className = 'sp-ac-dropdown';
    wrap.appendChild(dd);
  }

  svcInput.setAttribute('oninput', 'onSvcIdInput(this)');
  svcInput.setAttribute('onfocus', 'onSvcIdInput(this)');
  svcInput.setAttribute('autocomplete', 'off');
  svcInput.setAttribute('placeholder', 'اكتب ID أو اسم الخدمة...');

  // إضافة badge للقيمة الموجودة مسبقاً
  if (svcInput.value && provSelect && provSelect.value) {
    fetchProviderServices(provSelect.value, svcInput.value).then(list => {
      const matched = list.find(s => s.id == svcInput.value);
      if (matched) {
        let badge = document.createElement('div');
        badge.className = 'sp-svc-badge';
        badge.innerHTML = `<i class="fas fa-check-circle"></i><span>${escHtml(matched.name)}</span>${matched.price ? `<strong>$${parseFloat(matched.price).toFixed(3)}</strong>` : ''}`;
        svcInput.parentNode.appendChild(badge);
      }
    });
    // جلب مسبق للـ cache
    fetchProviderServices(provSelect.value, '');
  }
});
</script>
<?php include 'footer.php'; ?>
