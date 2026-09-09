<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$pageTitle = 'إدارة السلايدر - ' . SITE_NAME;

// ── إنشاء الجدول إن لم يكن موجوداً ──
$pdo->exec("CREATE TABLE IF NOT EXISTS `banners` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) DEFAULT '',
  `subtitle` VARCHAR(255) DEFAULT '',
  `tag` VARCHAR(100) DEFAULT '',
  `bg_color` VARCHAR(200) DEFAULT 'linear-gradient(135deg,#0d1f5c,#1a3a8c)',
  `text_color` VARCHAR(20) DEFAULT '#ffffff',
  `accent_color` VARCHAR(20) DEFAULT '#00d4ff',
  `image` VARCHAR(500) DEFAULT '',
  `link_type` ENUM('none','category','service','url') DEFAULT 'none',
  `link_value` VARCHAR(500) DEFAULT '',
  `sort_order` INT DEFAULT 0,
  `status` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uploadDir = dirname(__DIR__) . '/uploads/banners/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ── الحذف ──
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $b = $pdo->prepare("SELECT image FROM banners WHERE id=?")->execute([$id]);
    $row = $pdo->prepare("SELECT image FROM banners WHERE id=?");
    $row->execute([$id]);
    $r = $row->fetch();
    if ($r && $r['image'] && file_exists(dirname(__DIR__).'/'.$r['image'])) @unlink(dirname(__DIR__).'/'.$r['image']);
    $pdo->prepare("DELETE FROM banners WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف البانر');
    redirect(SITE_URL . '/admin/banners.php');
}

// ── تغيير الحالة ──
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $pdo->prepare("UPDATE banners SET status = 1 - status WHERE id=?")->execute([$id]);
    redirect(SITE_URL . '/admin/banners.php');
}

// ── الحفظ (إضافة / تعديل) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_banner'])) {
    $id       = (int)($_POST['banner_id'] ?? 0);
    $title    = trim($_POST['title']    ?? '');
    $subtitle = trim($_POST['subtitle'] ?? '');
    $tag      = trim($_POST['tag']      ?? '');
    $bgColor  = trim($_POST['bg_color'] ?? 'linear-gradient(135deg,#0d1f5c,#1a3a8c)');
    $txtColor = trim($_POST['text_color']   ?? '#ffffff');
    $accColor = trim($_POST['accent_color'] ?? '#00d4ff');
    $linkType = $_POST['link_type']  ?? 'none';
    $linkVal  = trim($_POST['link_value'] ?? '');
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $status   = isset($_POST['status']) ? 1 : 0;

    // رفع الصورة
    $imagePath = trim($_POST['old_image'] ?? '');
    if (!empty($_FILES['banner_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif']) && $_FILES['banner_image']['size'] <= 3*1024*1024) {
            if ($imagePath && file_exists(dirname(__DIR__).'/'.$imagePath)) @unlink(dirname(__DIR__).'/'.$imagePath);
            $fname = 'banner_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $uploadDir . $fname))
                $imagePath = 'uploads/banners/' . $fname;
        }
    }
    if (!empty($_POST['delete_image']) && $imagePath) {
        if (file_exists(dirname(__DIR__).'/'.$imagePath)) @unlink(dirname(__DIR__).'/'.$imagePath);
        $imagePath = '';
    }

    if ($id) {
        $pdo->prepare("UPDATE banners SET title=?,subtitle=?,tag=?,bg_color=?,text_color=?,accent_color=?,image=?,link_type=?,link_value=?,sort_order=?,status=? WHERE id=?")
            ->execute([$title,$subtitle,$tag,$bgColor,$txtColor,$accColor,$imagePath,$linkType,$linkVal,$sort,$status,$id]);
        flashMessage('success', 'تم تحديث البانر');
    } else {
        $pdo->prepare("INSERT INTO banners (title,subtitle,tag,bg_color,text_color,accent_color,image,link_type,link_value,sort_order,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$title,$subtitle,$tag,$bgColor,$txtColor,$accColor,$imagePath,$linkType,$linkVal,$sort,$status]);
        flashMessage('success', 'تم إضافة البانر');
    }
    redirect(SITE_URL . '/admin/banners.php');
}

// ── جلب البيانات ──
$banners = $pdo->query("SELECT * FROM banners ORDER BY sort_order, id")->fetchAll();
$cats    = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY sort_order")->fetchAll();
$svcs    = $pdo->query("SELECT id, name FROM services WHERE status=1 ORDER BY sort_order LIMIT 200")->fetchAll();

// بانر للتعديل
$editBanner = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM banners WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editBanner = $s->fetch();
}

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-icon" style="background:rgba(245,166,35,0.15)"><i class="fas fa-images" style="color:#f5a623"></i></div>
      إدارة السلايدر
    </div>
  </div>
  <button class="btn btn-primary btn-sm" onclick="toggleForm()">
    <i class="fas fa-plus"></i> بانر جديد
  </button>
</div>

<!-- ══ نموذج الإضافة / التعديل ══ -->
<div class="card" id="bannerForm" style="display:<?= $editBanner ? 'block' : 'none' ?>;margin-bottom:20px">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-edit"></i>
      <span id="formTitle"><?= $editBanner ? 'تعديل البانر' : 'إضافة بانر جديد' ?></span>
    </div>
    <button class="btn btn-secondary btn-sm" onclick="toggleForm()"><i class="fas fa-times"></i></button>
  </div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_banner" value="1">
      <input type="hidden" name="banner_id" id="fId" value="<?= $editBanner['id'] ?? 0 ?>">
      <input type="hidden" name="old_image" id="fOldImg" value="<?= htmlspecialchars($editBanner['image'] ?? '') ?>">
      <input type="hidden" name="delete_image" id="fDelImg" value="">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

        <!-- العنوان -->
        <div class="form-group">
          <label><i class="fas fa-heading"></i> العنوان الرئيسي</label>
          <input type="text" name="title" id="fTitle" class="form-control" value="<?= htmlspecialchars($editBanner['title'] ?? '') ?>" placeholder="مثال: أشحن ألعابك (اختياري إذا أضفت صورة)">
        </div>

        <!-- الوسم Tag -->
        <div class="form-group">
          <label><i class="fas fa-tag"></i> الوسم (Tag)</label>
          <input type="text" name="tag" id="fTag" class="form-control" value="<?= htmlspecialchars($editBanner['tag'] ?? '') ?>" placeholder="مثال: ✨ أفضل الأسعار">
        </div>

        <!-- العنوان الفرعي -->
        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-text-width"></i> النص الفرعي</label>
          <input type="text" name="subtitle" id="fSub" class="form-control" value="<?= htmlspecialchars($editBanner['subtitle'] ?? '') ?>" placeholder="مثال: ألعاب • برامج • بطاقات رقمية">
        </div>

        <!-- لون الخلفية -->
        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-palette"></i> خلفية البانر (Gradient أو لون)</label>
          <input type="text" name="bg_color" id="fBg" class="form-control" value="<?= htmlspecialchars($editBanner['bg_color'] ?? 'linear-gradient(135deg,#0d1f5c,#1a3a8c)') ?>" placeholder="linear-gradient(135deg,#0d1f5c,#1a3a8c)">
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <?php
            $presets = [
              'linear-gradient(135deg,#0d1f5c,#1a3a8c)'     => 'أزرق',
              'linear-gradient(135deg,#1a0533,#4a148c)'      => 'بنفسجي',
              'linear-gradient(135deg,#003300,#006400)'       => 'أخضر',
              'linear-gradient(135deg,#3e0000,#8b0000)'       => 'أحمر',
              'linear-gradient(135deg,#1a1100,#7a4f00)'       => 'ذهبي',
              'linear-gradient(135deg,#001a33,#003d6b)'       => 'نيلي',
            ];
            foreach ($presets as $grad => $name): ?>
            <div onclick="document.getElementById('fBg').value='<?= $grad ?>'" title="<?= $name ?>"
              style="width:40px;height:28px;border-radius:8px;cursor:pointer;background:<?= $grad ?>;border:2px solid rgba(255,255,255,0.2)"></div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- لون النص ولون التمييز -->
        <div class="form-group">
          <label><i class="fas fa-font"></i> لون النص</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" name="text_color" id="fTxt" value="<?= htmlspecialchars($editBanner['text_color'] ?? '#ffffff') ?>" style="width:44px;height:36px;border-radius:8px;border:1px solid var(--border2);background:none;cursor:pointer">
            <input type="text" id="fTxtHex" class="form-control" value="<?= htmlspecialchars($editBanner['text_color'] ?? '#ffffff') ?>" style="flex:1" onchange="document.getElementById('fTxt').value=this.value">
          </div>
        </div>

        <div class="form-group">
          <label><i class="fas fa-highlighter"></i> لون التمييز (Tag / Span)</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" name="accent_color" id="fAcc" value="<?= htmlspecialchars($editBanner['accent_color'] ?? '#00d4ff') ?>" style="width:44px;height:36px;border-radius:8px;border:1px solid var(--border2);background:none;cursor:pointer">
            <input type="text" id="fAccHex" class="form-control" value="<?= htmlspecialchars($editBanner['accent_color'] ?? '#00d4ff') ?>" style="flex:1" onchange="document.getElementById('fAcc').value=this.value">
          </div>
        </div>

        <!-- الصورة -->
        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-image"></i> صورة البانر (اختياري — تظهر على اليسار)</label>
          <div style="display:flex;align-items:center;gap:12px;margin-top:8px;flex-wrap:wrap">
            <?php if (!empty($editBanner['image'])): ?>
            <div style="position:relative">
              <img src="<?= SITE_URL.'/'.$editBanner['image'] ?>" style="height:60px;border-radius:10px;border:1px solid var(--border2)">
              <button type="button" onclick="document.getElementById('fDelImg').value='1';this.parentElement.remove()"
                style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:#ff4757;border:none;border-radius:50%;color:white;font-size:11px;cursor:pointer">✕</button>
            </div>
            <?php endif; ?>
            <label style="background:var(--bg);border:2px dashed var(--border2);border-radius:10px;padding:10px 18px;cursor:pointer;font-size:12px;color:var(--text2);display:flex;align-items:center;gap:8px">
              <i class="fas fa-cloud-upload-alt" style="color:var(--primary)"></i>
              <span id="imgLblTxt">رفع صورة (PNG/JPG — 3MB)</span>
              <input type="file" name="banner_image" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none"
                onchange="document.getElementById('imgLblTxt').textContent='✅ '+this.files[0].name">
            </label>
          </div>
        </div>

        <!-- الرابط -->
        <div class="form-group">
          <label><i class="fas fa-link"></i> نوع الرابط</label>
          <select name="link_type" id="fLinkType" class="form-control" onchange="toggleLinkVal()">
            <option value="none"     <?= ($editBanner['link_type']??'none')==='none'     ? 'selected':'' ?>>بدون رابط</option>
            <option value="category" <?= ($editBanner['link_type']??'')==='category' ? 'selected':'' ?>>قسم</option>
            <option value="service"  <?= ($editBanner['link_type']??'')==='service'  ? 'selected':'' ?>>خدمة</option>
            <option value="url"      <?= ($editBanner['link_type']??'')==='url'      ? 'selected':'' ?>>رابط خارجي</option>
          </select>
        </div>

        <div class="form-group" id="linkValWrap">
          <label><i class="fas fa-arrow-left"></i> <span id="linkValLabel">القيمة</span></label>
          <!-- Category select -->
          <select id="fLinkCat" class="form-control" style="display:none">
            <option value="">-- اختر قسماً --</option>
            <?php foreach ($cats as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($editBanner['link_type']??'')==='category' && $editBanner['link_value']==$cat['id'] ? 'selected':'' ?>>
              <?= htmlspecialchars($cat['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <!-- Service select -->
          <select id="fLinkSvc" class="form-control" style="display:none">
            <option value="">-- اختر خدمة --</option>
            <?php foreach ($svcs as $svc): ?>
            <option value="<?= $svc['id'] ?>" <?= ($editBanner['link_type']??'')==='service' && $editBanner['link_value']==$svc['id'] ? 'selected':'' ?>>
              <?= htmlspecialchars($svc['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <!-- URL input -->
          <input type="url" id="fLinkUrl" class="form-control" placeholder="https://..." style="display:none"
            value="<?= ($editBanner['link_type']??'')==='url' ? htmlspecialchars($editBanner['link_value']) : '' ?>">
          <input type="hidden" name="link_value" id="fLinkVal" value="<?= htmlspecialchars($editBanner['link_value'] ?? '') ?>">
        </div>

        <!-- الترتيب والحالة -->
        <div class="form-group">
          <label><i class="fas fa-sort"></i> الترتيب</label>
          <input type="number" name="sort_order" id="fSort" class="form-control" value="<?= $editBanner['sort_order'] ?? 0 ?>" min="0">
        </div>

        <div class="form-group" style="display:flex;align-items:center;gap:12px;padding-top:28px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="status" value="1" <?= ($editBanner['status'] ?? 1) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
            <span>تفعيل البانر</span>
          </label>
        </div>

      </div>

      <!-- معاينة -->
      <div style="margin:16px 0;padding:14px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid var(--border2)">
        <div style="font-size:12px;color:var(--text2);margin-bottom:10px"><i class="fas fa-eye"></i> معاينة</div>
        <div id="bannerPreview" style="height:120px;border-radius:14px;overflow:hidden;position:relative;cursor:pointer">
          <div id="prevBg" style="position:absolute;inset:0;background:linear-gradient(135deg,#0d1f5c,#1a3a8c)"></div>
          <div style="position:absolute;inset:0;padding:14px 18px;display:flex;flex-direction:column;justify-content:space-between">
            <div>
              <div id="prevTag" style="background:rgba(0,212,255,0.2);border:1px solid rgba(0,212,255,0.4);color:#00d4ff;font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-block">✨ أفضل الأسعار</div>
              <div id="prevTitle" style="font-size:22px;font-weight:900;color:#fff;line-height:1.2;margin-top:6px">أشحن ألعابك</div>
            </div>
            <div id="prevSub" style="font-size:11px;color:rgba(255,255,255,0.7)">ألعاب • برامج • بطاقات رقمية</div>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
        <button type="button" class="btn btn-secondary" onclick="toggleForm()">إلغاء</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ قائمة البانرات ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-list"></i> البانرات (<?= count($banners) ?>)</div>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($banners)): ?>
    <div style="text-align:center;padding:40px;color:var(--text2)">
      <i class="fas fa-images" style="font-size:40px;opacity:.3;display:block;margin-bottom:12px"></i>
      لا توجد بانرات بعد — أضف أول بانر الآن
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>معاينة</th><th>العنوان</th><th>الرابط</th><th>الترتيب</th><th>الحالة</th><th>إجراءات</th>
      </tr></thead>
      <tbody>
      <?php foreach ($banners as $b): ?>
      <tr>
        <td>
          <div style="width:90px;height:50px;border-radius:8px;overflow:hidden;position:relative;background:<?= htmlspecialchars($b['bg_color']) ?>">
            <?php if ($b['image']): ?>
            <img src="<?= SITE_URL.'/'.$b['image'] ?>" style="width:100%;height:100%;object-fit:cover;opacity:.5">
            <?php endif; ?>
            <div style="position:absolute;inset:0;padding:6px 8px">
              <?php if ($b['tag']): ?><div style="font-size:7px;color:<?= htmlspecialchars($b['accent_color']) ?>;font-weight:700"><?= htmlspecialchars($b['tag']) ?></div><?php endif; ?>
              <div style="font-size:11px;color:<?= htmlspecialchars($b['text_color']) ?>;font-weight:900;margin-top:2px"><?= htmlspecialchars($b['title']) ?></div>
            </div>
          </div>
        </td>
        <td>
          <div style="font-weight:700"><?= htmlspecialchars($b['title']) ?></div>
          <div style="font-size:11px;color:var(--text2)"><?= htmlspecialchars($b['subtitle']) ?></div>
        </td>
        <td>
          <?php
          $linkLabels = ['none'=>'—','category'=>'قسم','service'=>'خدمة','url'=>'رابط خارجي'];
          echo '<span style="font-size:12px">' . ($linkLabels[$b['link_type']] ?? '—');
          if ($b['link_value']) echo ' #' . htmlspecialchars($b['link_value']);
          echo '</span>';
          ?>
        </td>
        <td><?= $b['sort_order'] ?></td>
        <td>
          <a href="?toggle=<?= $b['id'] ?>" style="text-decoration:none">
            <span class="badge <?= $b['status'] ? 'badge-success' : 'badge-danger' ?>">
              <?= $b['status'] ? 'مفعّل' : 'معطّل' ?>
            </span>
          </a>
        </td>
        <td>
          <div style="display:flex;gap:6px">
            <a href="?edit=<?= $b['id'] ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
            <a href="?delete=<?= $b['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف هذا البانر؟')"><i class="fas fa-trash"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div><?php endif; ?>
  </div>
</div>

<script>
function toggleForm() {
  const f = document.getElementById('bannerForm');
  const shown = f.style.display !== 'none';
  if (shown) {
    f.style.display = 'none';
  } else {
    f.style.display = 'block';
    document.getElementById('fId').value = 0;
    document.getElementById('formTitle').textContent = 'إضافة بانر جديد';
    f.querySelector('form').reset();
    document.getElementById('fOldImg').value = '';
    updatePreview();
    f.scrollIntoView({behavior:'smooth'});
  }
}

function toggleLinkVal() {
  const type = document.getElementById('fLinkType').value;
  document.getElementById('fLinkCat').style.display = type === 'category' ? 'block' : 'none';
  document.getElementById('fLinkSvc').style.display = type === 'service'  ? 'block' : 'none';
  document.getElementById('fLinkUrl').style.display = type === 'url'      ? 'block' : 'none';
  const labels = {none:'—',category:'اختر قسماً',service:'اختر خدمة',url:'رابط URL'};
  document.getElementById('linkValLabel').textContent = labels[type] || '—';
}

// مزامنة link_value قبل الإرسال
document.querySelector('form').addEventListener('submit', () => {
  const type = document.getElementById('fLinkType').value;
  let val = '';
  if (type === 'category') val = document.getElementById('fLinkCat').value;
  else if (type === 'service') val = document.getElementById('fLinkSvc').value;
  else if (type === 'url') val = document.getElementById('fLinkUrl').value;
  document.getElementById('fLinkVal').value = val;
});

// مزامنة hex مع color picker
document.getElementById('fTxt').addEventListener('input', e => document.getElementById('fTxtHex').value = e.target.value);
document.getElementById('fAcc').addEventListener('input', e => document.getElementById('fAccHex').value = e.target.value);

// معاينة حية
function updatePreview() {
  document.getElementById('prevBg').style.background = document.getElementById('fBg').value;
  document.getElementById('prevTitle').textContent = document.getElementById('fTitle').value || 'العنوان';
  document.getElementById('prevTitle').style.color = document.getElementById('fTxt').value;
  document.getElementById('prevSub').textContent   = document.getElementById('fSub').value;
  document.getElementById('prevSub').style.color   = document.getElementById('fTxt').value;
  const acc = document.getElementById('fAcc').value;
  document.getElementById('prevTag').textContent   = document.getElementById('fTag').value || 'الوسم';
  document.getElementById('prevTag').style.color   = acc;
  document.getElementById('prevTag').style.borderColor = acc + '80';
  document.getElementById('prevTag').style.background  = acc + '22';
}

['fTitle','fSub','fTag','fBg','fTxt','fAcc'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', updatePreview);
});

toggleLinkVal();
updatePreview();
</script>

<?php include 'footer.php'; ?>
