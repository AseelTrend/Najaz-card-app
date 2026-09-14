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
    $file    = $_FILES[$fileKey];
    $allowedMime = ['image/jpeg','image/png','image/webp','image/gif'];
    $allowedExt  = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file['type'], $allowedMime) && !in_array($ext, $allowedExt)) return $oldImage;
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

// ── إنشاء جدول service_providers تلقائياً إن لم يكن موجوداً ──────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `service_providers` (
        `id`                  int(11) NOT NULL AUTO_INCREMENT,
        `service_id`          int(11) NOT NULL,
        `provider_id`         int(11) NOT NULL,
        `provider_service_id` varchar(100) NOT NULL,
        `priority`            int(11) NOT NULL DEFAULT 1,
        `is_active`           tinyint(1) NOT NULL DEFAULT 1,
        `label`               varchar(100) DEFAULT NULL,
        `created_at`          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_service_priority` (`service_id`,`priority`,`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

if ($action === 'delete' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_services_edit')) { flashMessage('danger','ليس لديك صلاحية حذف الخدمات'); redirect(SITE_URL.'/admin/services.php'); }
    $old = $pdo->prepare("SELECT image FROM services WHERE id=?"); $old->execute([$id]); $old = $old->fetch();
    if ($old && $old['image']) @unlink(dirname(__DIR__) . '/' . $old['image']);
    $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$id]);
    try { $pdo->prepare("DELETE FROM service_providers WHERE service_id=?")->execute([$id]); } catch(Exception $e) {}
    $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف الخدمة');
    redirect(SITE_URL . '/admin/services.php');
}

if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE services SET status=IF(status=1,0,1) WHERE id=?")->execute([$id]);
    redirect(SITE_URL . '/admin/services.php');
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

    if ($id) {
        try {
            $oldImg = $pdo->prepare("SELECT image FROM services WHERE id=?"); $oldImg->execute([$id]); $oldImg = $oldImg->fetch();
            $image = uploadServiceImage('image', $oldImg['image'] ?? '');
            $pdo->prepare("UPDATE services SET name=?,category_id=?,price=?,min_qty=?,max_qty=?,description=?,provider_id=?,provider_service_id=?,image=? WHERE id=?")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId,$image,$id]);
        } catch (\PDOException $e) {
            $pdo->prepare("UPDATE services SET name=?,category_id=?,price=?,min_qty=?,max_qty=?,description=?,provider_id=?,provider_service_id=? WHERE id=?")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId,$id]);
        }
    } else {
        $image = uploadServiceImage('image', '');
        // محاولة الحفظ مع الصورة، وإذا فشل نحفظ بدونها (في حال عمود image غير موجود)
        try {
            $pdo->prepare("INSERT INTO services (name,category_id,price,min_qty,max_qty,description,provider_id,provider_service_id,image) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId,$image]);
        } catch (\PDOException $e) {
            // عمود image غير موجود في قاعدة البيانات
            $pdo->prepare("INSERT INTO services (name,category_id,price,min_qty,max_qty,description,provider_id,provider_service_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$desc,$providerId,$providerServiceId]);
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
        foreach ($spIds as $i => $spPid) {
            if (empty($spPid) || empty($spSvcIds[$i])) continue;
            $pdo->prepare("INSERT INTO service_providers (service_id,provider_id,provider_service_id,priority,is_active,label) VALUES (?,?,?,?,?,?)")
                ->execute([$id, (int)$spPid, trim($spSvcIds[$i]), $i+1, isset($spActives[$i])?1:1, trim($spLabels[$i]??'')]);
        }
    } catch(Exception $e) {}

    flashMessage('success', 'تم حفظ الخدمة');
    redirect(SITE_URL . '/admin/services.php');
}

$services = $pdo->query("
    SELECT s.*, c.name as cat_name, p.name as provider_name
    FROM services s
    JOIN categories c ON s.category_id=c.id
    LEFT JOIN providers p ON s.provider_id=p.id
    ORDER BY c.sort_order, s.sort_order
")->fetchAll();

// جلب عدد مزودي كل خدمة
$spCounts = [];
try {
    $spC = $pdo->query("SELECT service_id, COUNT(*) as cnt FROM service_providers WHERE is_active=1 GROUP BY service_id");
    foreach ($spC->fetchAll() as $row) $spCounts[$row['service_id']] = $row['cnt'];
} catch(Exception $e) {}

$allCats   = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC")->fetchAll();
$providers = $pdo->query("SELECT * FROM providers WHERE status=1")->fetchAll();

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
                  <label style="font-size:11px;color:var(--text3);display:block;margin-bottom:4px">ملاحظة (اختياري)</label>
                  <input type="text" name="sp_label[]" value="<?=htmlspecialchars($sp['label']??'')?>" placeholder="مثال: مزود احتياطي" style="width:100%">
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

        <!-- حقل مخفي للتوافق مع الكود القديم -->
        <input type="hidden" name="provider_id" value="">
        <input type="hidden" name="provider_service_id" value="">

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
</script>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الصورة</th><th>الخدمة</th><th>القسم</th><th>السعر</th><th>الكمية</th><th>المزود</th><th>الحالة</th><th>إجراءات</th></tr>
            </thead>
            <tbody>
                <?php foreach($services as $svc): ?>
                <tr>
                    <td><?=$svc['id']?></td>
                    <td><?= !empty($svc['image']) ? '<img src="'.SITE_URL.'/'.$svc['image'].'" style="width:44px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">' : '<span style="color:#8895a7">—</span>' ?></td>
                    <td><strong><?=htmlspecialchars($svc['name'])?></strong></td>
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
                        <a href="?action=toggle&id=<?=$svc['id']?>">
                            <span class="badge badge-<?=$svc['status']?'success':'danger'?>"><?=$svc['status']?'نشط':'متوقف'?></span>
                        </a>
                    </td>
                    <td>
                        <a href="?action=edit&id=<?=$svc['id']?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                        <a href="?action=delete&id=<?=$svc['id']?>" class="btn btn-sm btn-danger" onclick="return confirmDelete()"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function previewImg(input, imgId, placeholderId) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    // Validate type and size
    if (!file.type.startsWith('image/')) { alert('الملف يجب أن يكون صورة'); input.value=''; return; }
    if (file.size > 2 * 1024 * 1024) { alert('حجم الصورة يجب أن يكون أقل من 2MB'); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const img = document.getElementById(imgId);
        const ph  = document.getElementById(placeholderId);
        if (img) { img.src = e.target.result; img.style.display = 'block'; }
        if (ph)  { ph.style.display = 'none'; }
    };
    reader.readAsDataURL(file);
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
  const row  = input.closest('.sp-row');
  const form = document.querySelector('form');

  // ── 1. ملء حقل الـ ID ──────────────────────────────────────
  input.value = svc.id;

  // ── 2. badge تأكيد ─────────────────────────────────────────
  let badge = row.querySelector('.sp-svc-badge');
  if (!badge) {
    badge = document.createElement('div');
    badge.className = 'sp-svc-badge';
    input.closest('.sp-ac-wrap').appendChild(badge);
  }
  badge.style.display = 'inline-flex';
  badge.innerHTML = `
    <i class="fas fa-check-circle"></i>
    <span>${escHtml(svc.name)}</span>
    ${svc.price   ? `<strong style="color:var(--green)">$${parseFloat(svc.price).toFixed(3)}</strong>` : ''}
    ${svc.min||svc.max ? `<span style="color:var(--text3);font-size:10px">الكمية: ${svc.min||1}–${svc.max||'∞'}</span>` : ''}
    ${svc.available===false ? '<span style="color:var(--red);font-size:10px">⚠ غير متاح</span>' : ''}
  `;

  if (!form) {
    const dd = row.querySelector('.sp-ac-dropdown');
    if (dd) dd.classList.remove('open');
    return;
  }

  // ── 3. الاسم ───────────────────────────────────────────────
  const nameField = form.querySelector('input[name="name"]');
  if (nameField && !nameField.value.trim()) {
    nameField.value = svc.name;
    flash(nameField, '#00d4aa');
  }

  // ── 4. السعر (+ 10% هامش ربح) ─────────────────────────────
  if (svc.price) {
    const priceField = form.querySelector('input[name="price"]');
    if (priceField) {
      priceField.value = (parseFloat(svc.price) * 1.1).toFixed(5);
      priceField.title = 'سعر المزود: $' + parseFloat(svc.price).toFixed(5) + ' — هامش ربح 10%';
      flash(priceField, '#00e676');
    }
  }

  // ── 5. الحد الأدنى والأقصى للكمية ─────────────────────────
  const minField = form.querySelector('input[name="min_qty"]');
  const maxField = form.querySelector('input[name="max_qty"]');
  if (svc.min && minField) { minField.value = svc.min; flash(minField, '#00d4aa'); }
  if (svc.max && svc.max < 999999 && maxField) { maxField.value = svc.max; flash(maxField, '#00d4aa'); }

  // ── 6. الوصف ───────────────────────────────────────────────
  if (svc.description) {
    const descField = form.querySelector('input[name="description"],textarea[name="description"]');
    if (descField && !descField.value.trim()) {
      descField.value = svc.description;
      flash(descField, '#00d4aa');
    }
  }

  // ── 7. التصنيف — يبحث عن تطابق نصي في الـ select ──────────
  if (svc.category) {
    const catSelect = form.querySelector('select[name="category_id"]');
    if (catSelect) {
      const catLower = svc.category.toLowerCase();
      let bestOpt = null, bestScore = 0;
      Array.from(catSelect.options).forEach(opt => {
        const optLower = opt.text.toLowerCase();
        // مطابقة كاملة
        if (optLower === catLower) { bestOpt = opt; bestScore = 3; return; }
        // يحتوي على الاسم
        if (bestScore < 2 && optLower.includes(catLower)) { bestOpt = opt; bestScore = 2; }
        if (bestScore < 1 && catLower.includes(optLower) && optLower.length > 2) { bestOpt = opt; bestScore = 1; }
      });
      if (bestOpt && bestOpt.value) {
        catSelect.value = bestOpt.value;
        flash(catSelect, '#00d4aa');
      }
    }
  }

  // ── 8. الحقول المخصصة (params من Oranos) ──────────────────
  if (svc.params && svc.params.length > 0) {
    // إذا لم يكن في حقول مخصصة، أضفها تلقائياً
    const fieldsContainer = document.getElementById('fields-container');
    if (fieldsContainer) {
      const existingFields = fieldsContainer.querySelectorAll('input[name="field_name[]"]');
      if (existingFields.length === 0) {
        svc.params.forEach(param => {
          // استدعاء addField() مباشرة بدل الزر
          if (typeof addField === 'function') {
            addField();
            // انتظر ثم ملء الحقل المضاف
            setTimeout(() => {
              const allNames  = fieldsContainer.querySelectorAll('input[name="field_name[]"]');
              const allLabels = fieldsContainer.querySelectorAll('input[name="field_label[]"]');
              const last = allNames.length - 1;
              if (allNames[last])  { allNames[last].value  = slugify(param); }
              if (allLabels[last]) { allLabels[last].value = param; }
            }, 50);
          }
        });
        showToast(`✅ تم اختيار: ${svc.name} — أُضيف ${svc.params.length} حقل مخصص`);
      } else {
        showToast(`✅ تم اختيار: ${svc.name}`);
      }
    } else {
      showToast(`✅ تم اختيار: ${svc.name}`);
    }
  } else {
    showToast(`✅ تم اختيار: ${svc.name}`);
  }

  // ── 9. أغلق الـ dropdown ────────────────────────────────────
  const dropdown = row.querySelector('.sp-ac-dropdown');
  if (dropdown) dropdown.classList.remove('open');
}

// تحويل النص إلى slug للحقول
function slugify(str) {
  return str.toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_]/g, '')
    .replace(/_+/g, '_');
}

// تأثير إضاءة الحقل عند الملء التلقائي
function flash(el, color) {
  el.style.transition = 'box-shadow .2s, border-color .2s';
  el.style.borderColor = color;
  el.style.boxShadow = `0 0 0 3px ${color}33`;
  setTimeout(() => {
    el.style.borderColor = '';
    el.style.boxShadow = '';
  }, 1800);
}

// toast بسيط
function showToast(msg) {
  let t = document.getElementById('sp-toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'sp-toast';
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1a2340;border:1px solid rgba(0,212,170,.4);color:#00d4aa;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:700;z-index:9999;transition:opacity .3s;pointer-events:none';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.style.opacity = '1';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.style.opacity = '0', 2500);
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
