<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'أزرار التواصل — ' . SITE_NAME;

// إنشاء الجدول إذا لم يكن موجوداً
$pdo->exec("CREATE TABLE IF NOT EXISTS `contact_buttons` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `label`      VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(100) NOT NULL DEFAULT 'fa-comment',
  `url`        VARCHAR(500) NOT NULL,
  `color`      VARCHAR(20)  NOT NULL DEFAULT '#25d366',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── حذف ──────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM contact_buttons WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم الحذف');
    redirect(SITE_URL . '/admin/contact_buttons.php');
}

// ── تبديل الحالة ──────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE contact_buttons SET status = 1 - status WHERE id=?")->execute([$id]);
    redirect(SITE_URL . '/admin/contact_buttons.php');
}

// ── حفظ ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? '');
    $icon  = trim($_POST['icon']  ?? 'fa-comment');
    $url   = trim($_POST['url']   ?? '');
    $color = trim($_POST['color'] ?? '#25d366');
    $sort  = (int)($_POST['sort_order'] ?? 0);

    if (!$label || !$url) {
        flashMessage('danger', 'الاسم والرابط مطلوبان');
    } else {
        if ($id) {
            $pdo->prepare("UPDATE contact_buttons SET label=?,icon=?,url=?,color=?,sort_order=? WHERE id=?")
                ->execute([$label, $icon, $url, $color, $sort, $id]);
            flashMessage('success', 'تم التحديث');
        } else {
            $pdo->prepare("INSERT INTO contact_buttons (label,icon,url,color,sort_order) VALUES (?,?,?,?,?)")
                ->execute([$label, $icon, $url, $color, $sort]);
            flashMessage('success', 'تمت الإضافة');
        }
        redirect(SITE_URL . '/admin/contact_buttons.php');
    }
}

$editBtn = null;
if ($action === 'edit' && $id) {
    $editBtn = $pdo->prepare("SELECT * FROM contact_buttons WHERE id=?");
    $editBtn->execute([$id]);
    $editBtn = $editBtn->fetch();
}

$buttons = $pdo->query("SELECT * FROM contact_buttons ORDER BY sort_order, id")->fetchAll();

// قائمة الأيقونات المقترحة
$icons = [
    'fab fa-whatsapp'  => ['واتساب',    '#25d366'],
    'fab fa-telegram'  => ['تيليجرام',  '#0088cc'],
    'fab fa-instagram' => ['انستغرام',  '#e1306c'],
    'fab fa-twitter'   => ['تويتر/X',   '#1da1f2'],
    'fab fa-facebook'  => ['فيسبوك',    '#1877f2'],
    'fab fa-tiktok'    => ['تيك توك',   '#010101'],
    'fab fa-snapchat'  => ['سناب شات',  '#fffc00'],
    'fab fa-youtube'   => ['يوتيوب',    '#ff0000'],
    'fas fa-phone'     => ['هاتف',       '#00c853'],
    'fas fa-envelope'  => ['بريد',       '#ea4335'],
    'fas fa-globe'     => ['موقع',       '#1e6fff'],
    'fas fa-headset'   => ['دعم',        '#7c3aed'],
];

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(30,111,255,.15);color:var(--primary)">
        <i class="fas fa-comments"></i>
      </div>
      أزرار التواصل العائمة
    </div>
    <div class="page-header-sub">زر يظهر في الزاوية ويحتوي روابط التواصل</div>
  </div>
  <?php if($action==='list'): ?>
  <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة زر</a>
  <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── فورم الإضافة/التعديل ── -->
<div class="card mb-2">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-<?= $action==='edit'?'edit':'plus-circle' ?>"></i>
      <?= $action==='edit' ? 'تعديل: '.htmlspecialchars($editBtn['label']??'') : 'إضافة زر جديد' ?>
    </div>
  </div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>
      <div class="form-grid">

        <!-- الاسم -->
        <div class="form-group">
          <label><i class="fas fa-tag"></i> اسم وسيلة التواصل *</label>
          <input type="text" name="label" class="form-control"
                 value="<?= htmlspecialchars($editBtn['label']??'') ?>"
                 placeholder="مثال: واتساب الدعم" required>
        </div>

        <!-- الترتيب -->
        <div class="form-group">
          <label><i class="fas fa-sort"></i> الترتيب</label>
          <input type="number" name="sort_order" class="form-control"
                 value="<?= $editBtn['sort_order']??0 ?>" min="0">
        </div>

        <!-- الرابط -->
        <div class="form-group form-full">
          <label><i class="fas fa-link"></i> الرابط *</label>
          <input type="text" name="url" class="form-control"
                 value="<?= htmlspecialchars($editBtn['url']??'') ?>"
                 placeholder="https://wa.me/967xxxxxxxxx أو https://t.me/username" required>
          <div class="form-hint">واتساب: https://wa.me/967xxxxxxxx | تيليجرام: https://t.me/username</div>
        </div>

        <!-- اختيار الأيقونة -->
        <div class="form-group form-full">
          <label><i class="fas fa-icons"></i> الأيقونة والنمط</label>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px;margin-bottom:12px">
            <?php foreach ($icons as $cls => [$name, $defaultColor]): ?>
            <div onclick="selectIcon('<?= $cls ?>','<?= $defaultColor ?>','<?= $name ?>')"
                 class="icon-option <?= ($editBtn['icon']??'fab fa-whatsapp')===$cls?'selected':'' ?>"
                 data-icon="<?= $cls ?>"
                 style="background:var(--bg2);border:1.5px solid <?= ($editBtn['icon']??'fab fa-whatsapp')===$cls?'var(--primary)':'var(--border)' ?>;border-radius:10px;padding:10px;text-align:center;cursor:pointer;transition:.2s">
              <i class="<?= $cls ?>" style="font-size:1.3rem;color:<?= $defaultColor ?>;display:block;margin-bottom:5px"></i>
              <div style="font-size:11px;font-weight:700"><?= $name ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="icon" id="iconInput" value="<?= htmlspecialchars($editBtn['icon']??'fab fa-whatsapp') ?>">
        </div>

        <!-- اللون -->
        <div class="form-group">
          <label><i class="fas fa-palette"></i> لون الزر</label>
          <div style="display:flex;align-items:center;gap:10px">
            <input type="color" name="color" id="colorInput"
                   value="<?= htmlspecialchars($editBtn['color']??'#25d366') ?>"
                   style="width:50px;height:40px;border:none;border-radius:8px;cursor:pointer;padding:2px">
            <input type="text" id="colorText"
                   value="<?= htmlspecialchars($editBtn['color']??'#25d366') ?>"
                   oninput="document.getElementById('colorInput').value=this.value"
                   style="width:110px" class="form-control" placeholder="#25d366">
          </div>
        </div>

        <!-- معاينة -->
        <div class="form-group">
          <label><i class="fas fa-eye"></i> معاينة</label>
          <div id="btnPreview" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:14px;font-size:14px;font-weight:700;color:#fff;cursor:pointer;background:<?= htmlspecialchars($editBtn['color']??'#25d366') ?>">
            <i id="previewIcon" class="<?= htmlspecialchars($editBtn['icon']??'fab fa-whatsapp') ?>"></i>
            <span id="previewLabel"><?= htmlspecialchars($editBtn['label']??'واتساب') ?></span>
          </div>
        </div>

      </div>

      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" class="btn btn-primary">
          <i class="fas fa-save"></i> <?= $action==='edit'?'حفظ التعديلات':'إضافة الزر' ?>
        </button>
        <a href="?" class="btn btn-secondary"><i class="fas fa-times"></i> إلغاء</a>
      </div>
    </form>
  </div>
</div>

<script>
function selectIcon(cls, color, name) {
  document.getElementById('iconInput').value = cls;
  document.getElementById('colorInput').value = color;
  document.getElementById('colorText').value  = color;
  document.getElementById('previewIcon').className = cls;
  updatePreview();
  document.querySelectorAll('.icon-option').forEach(o => {
    o.style.borderColor = o.dataset.icon === cls ? 'var(--primary)' : 'var(--border)';
  });
}
function updatePreview() {
  const color = document.getElementById('colorInput').value;
  const label = document.querySelector('input[name="label"]').value || 'معاينة';
  document.getElementById('btnPreview').style.background = color;
  document.getElementById('previewLabel').textContent = label;
  document.getElementById('colorText').value = color;
}
document.getElementById('colorInput').addEventListener('input', updatePreview);
document.querySelector('input[name="label"]').addEventListener('input', updatePreview);
document.getElementById('colorText').addEventListener('input', () => {
  document.getElementById('colorInput').value = document.getElementById('colorText').value;
  updatePreview();
});
</script>

<?php else: ?>
<!-- ── قائمة الأزرار ── -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-list"></i> الأزرار المضافة (<?= count($buttons) ?>)</div>
  </div>
  <?php if (empty($buttons)): ?>
  <div class="card-body" style="text-align:center;padding:3rem;color:var(--text2)">
    <i class="fas fa-comments" style="font-size:2.5rem;opacity:.15;display:block;margin-bottom:12px"></i>
    <div>لا توجد أزرار تواصل بعد</div>
    <a href="?action=add" class="btn btn-primary" style="margin-top:16px"><i class="fas fa-plus"></i> إضافة أول زر</a>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>الأيقونة</th><th>الاسم</th><th>الرابط</th><th>الترتيب</th><th>الحالة</th><th>إجراءات</th></tr>
      </thead>
      <tbody>
      <?php foreach ($buttons as $btn): ?>
      <tr>
        <td><?= $btn['id'] ?></td>
        <td>
          <div style="width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:<?= htmlspecialchars($btn['color']) ?>22">
            <i class="<?= htmlspecialchars($btn['icon']) ?>" style="color:<?= htmlspecialchars($btn['color']) ?>;font-size:1.1rem"></i>
          </div>
        </td>
        <td><strong><?= htmlspecialchars($btn['label']) ?></strong></td>
        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--text2)">
          <?= htmlspecialchars($btn['url']) ?>
        </td>
        <td><?= $btn['sort_order'] ?></td>
        <td>
          <a href="?action=toggle&id=<?= $btn['id'] ?>" class="badge"
             style="background:<?= $btn['status']?'rgba(0,230,118,.15)':'rgba(255,68,85,.15)' ?>;color:<?= $btn['status']?'var(--green)':'var(--red)' ?>;border:1px solid <?= $btn['status']?'rgba(0,230,118,.3)':'rgba(255,68,85,.3)' ?>;text-decoration:none">
            <?= $btn['status'] ? '✓ نشط' : '✗ معطل' ?>
          </a>
        </td>
        <td>
          <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $btn['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
            <a href="?action=delete&id=<?= $btn['id'] ?>" class="btn btn-sm btn-danger"
               onclick="return confirm('حذف هذا الزر؟')"><i class="fas fa-trash"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
