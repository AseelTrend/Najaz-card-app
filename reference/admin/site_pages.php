<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/site_pages.php';

if (!isAdmin()) {
    header('Location: index.php');
    exit;
}

sitePagesEnsureSchema($pdo);
sitePagesSeedDefaults($pdo);

$pageTitle = 'إدارة الصفحات العامة - ' . SITE_NAME;
$message = '';
$error = '';
$editPage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCsrfVerify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $slug = sitePagesNormalizeSlug((string)($_POST['slug'] ?? ''));
            $icon = sitePagesSafeIcon((string)($_POST['icon'] ?? 'file-alt'));
            $iconColor = sitePagesSafeColor((string)($_POST['icon_color'] ?? '#00d4ff'));
            $content = trim((string)($_POST['content'] ?? ''));
            $sortOrder = max(0, (int)($_POST['sort_order'] ?? 0));
            $isVisible = isset($_POST['is_visible']) ? 1 : 0;

            if ($title === '') throw new RuntimeException('اكتب عنوان الصفحة.');
            if ($slug === '') throw new RuntimeException('اكتب معرفاً إنجليزياً للصفحة مثل terms أو contact.');
            if ($content === '') throw new RuntimeException('اكتب محتوى الصفحة.');

            $duplicate = $pdo->prepare('SELECT id FROM site_pages WHERE slug=? AND id<>? LIMIT 1');
            $duplicate->execute([$slug, $id]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('معرف الصفحة مستخدم مسبقاً.');

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE site_pages SET slug=?, title=?, icon=?, icon_color=?, content=?, is_visible=?, sort_order=? WHERE id=?');
                $stmt->execute([$slug, $title, $icon, $iconColor, $content, $isVisible, $sortOrder, $id]);
                $message = 'تم تحديث الصفحة بنجاح.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO site_pages (slug,title,icon,icon_color,content,is_visible,sort_order) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$slug, $title, $icon, $iconColor, $content, $isVisible, $sortOrder]);
                $message = 'تمت إضافة الصفحة بنجاح.';
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('معرف الصفحة غير صحيح.');
            $stmt = $pdo->prepare('DELETE FROM site_pages WHERE id=?');
            $stmt->execute([$id]);
            $message = 'تم حذف الصفحة نهائياً.';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE site_pages SET is_visible=1-is_visible WHERE id=?');
            $stmt->execute([$id]);
            $message = 'تم تحديث ظهور الصفحة.';
        }
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'تعذر حفظ العملية. تحقق من البيانات ثم حاول مرة أخرى.';
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM site_pages WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_GET['edit']]);
    $editPage = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pages = $pdo->query('SELECT * FROM site_pages ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__ . '/header.php';
?>
<style>
/* تخطيط مستقل للصفحة حتى لا يسبب جدولها أو الحقول تمدداً أفقياً */
html,
body {
  min-height: 100%;
  height: auto !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
}
.site-pages-screen {
  width: 100%;
  min-width: 0;
  padding-bottom: 40px;
}
.site-pages-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(280px, 1fr);
  gap: 18px;
  align-items: start;
  width: 100%;
  min-width: 0;
}
.site-pages-card {
  min-width: 0;
  width: 100%;
  overflow: hidden;
  background: var(--card, #111827);
  border: 1px solid var(--border, rgba(255,255,255,.08));
  border-radius: 16px;
  padding: 20px;
}
.site-pages-card h2 { margin: 0 0 16px; font-size: 1.05rem; }
.site-pages-help {
  color: var(--text2, #8fa3bf);
  font-size: .82rem;
  line-height: 1.8;
  margin: -8px 0 16px;
}
.site-pages-table-wrap {
  width: 100%;
  max-width: 100%;
  overflow-x: auto;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;
  border-radius: 10px;
}
.site-pages-table {
  width: 100%;
  min-width: 680px;
  border-collapse: collapse;
}
.site-pages-table th,
.site-pages-table td {
  padding: 11px 8px;
  border-bottom: 1px solid var(--border, rgba(255,255,255,.08));
  text-align: right;
  vertical-align: middle;
  white-space: nowrap;
}
.site-pages-table th { color: var(--text2, #8fa3bf); font-size: .8rem; }
.site-pages-table td { font-size: .86rem; }
.site-pages-slug { direction: ltr; text-align: left; color: #8fa3bf; font-family: monospace; }
.site-pages-status { display: inline-flex; padding: 3px 9px; border-radius: 99px; font-size: .75rem; }
.site-pages-status.on { background: rgba(0,230,118,.12); color: #00e676; }
.site-pages-status.off { background: rgba(255,71,87,.12); color: #ff6677; }
.site-pages-form { display: grid; gap: 12px; min-width: 0; }
.site-pages-form label { display: grid; gap: 6px; min-width: 0; font-size: .82rem; color: var(--text2, #8fa3bf); }
.site-pages-form input,
.site-pages-form textarea {
  display: block;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  border: 1px solid var(--border, rgba(255,255,255,.1));
  background: var(--bg2, #0d1428);
  color: var(--text, #fff);
  border-radius: 10px;
  padding: 10px;
  font: inherit;
  box-sizing: border-box;
}
.site-pages-form textarea {
  min-height: 260px;
  resize: vertical;
  line-height: 1.8;
  overflow-y: auto;
}
.site-pages-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; min-width: 0; }
.site-pages-actions { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }
.site-pages-actions form { margin: 0; }
.site-pages-btn {
  border: 0;
  border-radius: 9px;
  padding: 8px 11px;
  color: #fff;
  cursor: pointer;
  font: inherit;
  font-size: .78rem;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  white-space: nowrap;
}
.site-pages-btn.primary { background: #1e6fff; }
.site-pages-btn.muted { background: #334155; }
.site-pages-btn.warn { background: #a16207; }
.site-pages-btn.danger { background: #b42338; }
.site-pages-btn.green { background: #087f5b; }
.site-pages-check { display: flex !important; grid-template-columns: none !important; align-items: center; gap: 8px !important; }
.site-pages-check input { width: auto; }
.site-pages-alert { padding: 11px 14px; border-radius: 10px; margin-bottom: 16px; }
.site-pages-alert.ok { background: rgba(0,230,118,.12); color: #7dffb2; }
.site-pages-alert.err { background: rgba(255,71,87,.12); color: #ff9aa7; }
.site-pages-preview { color: #8fa3bf; font-size: .78rem; line-height: 1.7; overflow-wrap: anywhere; }
.site-pages-preview a { color: #60a5fa; direction: ltr; display: inline-block; max-width: 100%; overflow-wrap: anywhere; }
@media (max-width: 900px) {
  .site-pages-grid { grid-template-columns: minmax(0, 1fr); }
  .site-pages-card { padding: 16px; }
}
@media (max-width: 620px) {
  .site-pages-card { padding: 13px; border-radius: 12px; }
  .site-pages-row { grid-template-columns: minmax(0, 1fr); }
  .site-pages-table { min-width: 680px; }
  .site-pages-btn { padding: 8px 9px; font-size: .74rem; }
  .site-pages-actions { gap: 6px; }
  .site-pages-form textarea { min-height: 220px; }
}
</style>
<div class="admin-main site-pages-screen">
  <div class="admin-topbar"><div><h1>إدارة الصفحات العامة</h1><p>أضف صفحات تظهر في القائمة الجانبية مثل من نحن، الخصوصية، الشروط والتواصل.</p></div></div>
  <?php if ($message): ?><div class="site-pages-alert ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error): ?><div class="site-pages-alert err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div class="site-pages-grid">
    <section class="site-pages-card">
      <h2>الصفحات الحالية</h2>
      <p class="site-pages-help">الصفحات المفعّلة تظهر تلقائياً في قسم «معلومات» داخل القائمة الجانبية. إخفاء الصفحة لا يحذف محتواها.</p>
      <div class="site-pages-table-wrap"><table class="site-pages-table"><thead><tr><th>الصفحة</th><th>المعرّف</th><th>الترتيب</th><th>الحالة</th><th>الإجراءات</th></tr></thead><tbody>
      <?php foreach ($pages as $page): ?>
        <tr>
          <td><i class="fas fa-<?= htmlspecialchars(sitePagesSafeIcon($page['icon']), ENT_QUOTES, 'UTF-8') ?>" style="color:<?= htmlspecialchars(sitePagesSafeColor($page['icon_color']), ENT_QUOTES, 'UTF-8') ?>"></i> <?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="site-pages-slug"><?= htmlspecialchars($page['slug'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)$page['sort_order'] ?></td>
          <td><span class="site-pages-status <?= $page['is_visible'] ? 'on' : 'off' ?>"><?= $page['is_visible'] ? 'مفعّلة' : 'مخفية' ?></span></td>
          <td><div class="site-pages-actions">
            <a class="site-pages-btn primary" href="?edit=<?= (int)$page['id'] ?>"><i class="fas fa-edit"></i> تعديل</a>
            <form method="post" onsubmit="return confirm('هل تريد تغيير ظهور هذه الصفحة؟')" style="display:inline"><?= adminCsrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><button class="site-pages-btn <?= $page['is_visible'] ? 'warn' : 'green' ?>" type="submit"><?= $page['is_visible'] ? 'إخفاء' : 'إظهار' ?></button></form>
            <form method="post" onsubmit="return confirm('سيتم حذف الصفحة نهائياً. هل أنت متأكد؟')" style="display:inline"><?= adminCsrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><button class="site-pages-btn danger" type="submit"><i class="fas fa-trash"></i> حذف</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    </section>
    <section class="site-pages-card">
      <h2><?= $editPage ? 'تعديل الصفحة' : 'إضافة صفحة جديدة' ?></h2>
      <p class="site-pages-help">المحتوى يقبل HTML بسيطاً مثل العناوين والفقرات والقوائم والروابط. لا تستخدم أكواد JavaScript.</p>
      <form class="site-pages-form" method="post">
        <?= adminCsrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editPage['id'] ?? 0) ?>">
        <label>عنوان الصفحة<input name="title" required maxlength="180" value="<?= htmlspecialchars($editPage['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="مثال: من نحن"></label>
        <label>المعرّف الإنجليزي<input name="slug" required maxlength="120" dir="ltr" value="<?= htmlspecialchars($editPage['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="about-us"></label>
        <div class="site-pages-row"><label>اسم أيقونة Font Awesome<input name="icon" maxlength="60" dir="ltr" value="<?= htmlspecialchars($editPage['icon'] ?? 'file-alt', ENT_QUOTES, 'UTF-8') ?>" placeholder="building"></label><label>لون الأيقونة<input name="icon_color" maxlength="30" dir="ltr" value="<?= htmlspecialchars($editPage['icon_color'] ?? '#00d4ff', ENT_QUOTES, 'UTF-8') ?>" placeholder="#00d4ff"></label></div>
        <label>الترتيب<input type="number" name="sort_order" min="0" step="1" value="<?= (int)($editPage['sort_order'] ?? 100) ?>"></label>
        <label class="site-pages-check"><input type="checkbox" name="is_visible" value="1" <?= !isset($editPage['is_visible']) || $editPage['is_visible'] ? 'checked' : '' ?>> إظهار الصفحة في القائمة الجانبية</label>
        <label>محتوى الصفحة<textarea name="content" required><?= htmlspecialchars($editPage['content'] ?? '<p>اكتب محتوى الصفحة هنا.</p>', ENT_QUOTES, 'UTF-8') ?></textarea></label>
        <div class="site-pages-actions"><button class="site-pages-btn primary" type="submit"><i class="fas fa-save"></i> حفظ الصفحة</button><?php if ($editPage): ?><a class="site-pages-btn muted" href="site_pages.php">إلغاء التعديل</a><?php endif; ?></div>
      </form>
      <?php if ($editPage): ?><p class="site-pages-preview">رابط الصفحة: <a href="<?= htmlspecialchars(SITE_URL . '/page.php?slug=' . rawurlencode($editPage['slug']), ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars(SITE_URL . '/page.php?slug=' . $editPage['slug'], ENT_QUOTES, 'UTF-8') ?></a></p><?php endif; ?>
    </section>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
