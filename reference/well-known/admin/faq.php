<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'الأسئلة الشائعة — ' . SITE_NAME;

// إنشاء الجدول
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `faq_items` (`id` INT AUTO_INCREMENT PRIMARY KEY,`question` TEXT NOT NULL,`answer` TEXT NOT NULL,`category` VARCHAR(100) DEFAULT NULL,`sort_order` INT DEFAULT 0,`status` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM faq_items WHERE id=?")->execute([$id]);
    flashMessage('success','تم الحذف');
    redirect(SITE_URL.'/admin/faq.php');
}
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE faq_items SET status=1-status WHERE id=?")->execute([$id]);
    redirect(SITE_URL.'/admin/faq.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_desc'])) {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('faq_description',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['faq_description'],$_POST['faq_description']]);
    flashMessage('success','تم الحفظ');
    redirect(SITE_URL.'/admin/faq.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_faq'])) {
    $q    = trim($_POST['question'] ?? '');
    $a    = trim($_POST['answer']   ?? '');
    $cat  = trim($_POST['category'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $editId = (int)($_POST['edit_id'] ?? 0);
    if ($q && $a) {
        if ($editId)
            $pdo->prepare("UPDATE faq_items SET question=?,answer=?,category=?,sort_order=? WHERE id=?")->execute([$q,$a,$cat?:null,$sort,$editId]);
        else
            $pdo->prepare("INSERT INTO faq_items (question,answer,category,sort_order) VALUES (?,?,?,?)")->execute([$q,$a,$cat?:null,$sort]);
        flashMessage('success','تم الحفظ');
    }
    redirect(SITE_URL.'/admin/faq.php');
}

$editItem = null;
if ($action === 'edit' && $id) {
    $e=$pdo->prepare("SELECT * FROM faq_items WHERE id=?");$e->execute([$id]);$editItem=$e->fetch();
}

$items = $pdo->query("SELECT * FROM faq_items ORDER BY category, sort_order, id")->fetchAll();
$cats  = $pdo->query("SELECT DISTINCT category FROM faq_items WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(30,111,255,.15);color:var(--primary)"><i class="fas fa-question-circle"></i></div>
      الأسئلة الشائعة
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= SITE_URL ?>/faq.php" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> معاينة</a>
    <a href="?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> سؤال جديد</a>
  </div>
</div>

<!-- وصف الصفحة -->
<div class="card mb-2">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-info-circle"></i> وصف صفحة الأسئلة (SEO)</div></div>
  <div class="card-body">
    <form method="POST" style="display:flex;gap:10px">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_desc" value="1">
      <input type="text" name="faq_description" class="form-control" value="<?= htmlspecialchars(getSetting('faq_description') ?: '') ?>" placeholder="إجابات على أكثر الأسئلة شيوعاً حول خدماتنا">
      <button type="submit" class="btn btn-primary" style="flex-shrink:0"><i class="fas fa-save"></i> حفظ</button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:16px">

<!-- فورم الإضافة/التعديل -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-<?= $editItem?'edit':'plus-circle' ?>"></i> <?= $editItem?'تعديل سؤال':'إضافة سؤال جديد' ?></div>
    <?php if($editItem): ?><a href="?" class="btn btn-secondary btn-sm">+ جديد</a><?php endif; ?>
  </div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_faq" value="1">
      <input type="hidden" name="edit_id" value="<?= $editItem['id']??0 ?>">
      <div class="form-group">
        <label><i class="fas fa-question"></i> السؤال *</label>
        <input type="text" name="question" class="form-control" value="<?= htmlspecialchars($editItem['question']??'') ?>" placeholder="مثال: كيف أشحن رصيدي؟" required>
      </div>
      <div class="form-group">
        <label><i class="fas fa-align-right"></i> الإجابة *</label>
        <textarea name="answer" class="form-control" rows="4" placeholder="اكتب الإجابة هنا..." required style="resize:vertical"><?= htmlspecialchars($editItem['answer']??'') ?></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div class="form-group">
          <label><i class="fas fa-folder"></i> التصنيف (اختياري)</label>
          <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($editItem['category']??'') ?>" placeholder="مثال: الدفع، الطلبات..." list="catList">
          <datalist id="catList">
            <?php foreach($cats as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <div class="form-group">
          <label><i class="fas fa-sort"></i> الترتيب</label>
          <input type="number" name="sort_order" class="form-control" value="<?= $editItem['sort_order']??0 ?>" min="0">
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">
        <i class="fas fa-save"></i> <?= $editItem?'حفظ التعديل':'إضافة السؤال' ?>
      </button>
    </form>
  </div>
</div>

<!-- قائمة الأسئلة -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-list"></i> الأسئلة (<?= count($items) ?>)</div>
  </div>
  <?php if(empty($items)): ?>
  <div class="card-body" style="text-align:center;padding:2rem;color:var(--text3)">
    <i class="fas fa-question-circle" style="font-size:2rem;opacity:.1;display:block;margin-bottom:10px"></i>
    لا توجد أسئلة بعد
  </div>
  <?php else: ?>
  <div style="padding:8px;max-height:600px;overflow-y:auto">
    <?php
    $prevCat = null;
    foreach ($items as $item):
      $cat = $item['category'] ?: 'عام';
      if ($cat !== $prevCat):
        $prevCat = $cat;
    ?>
    <div style="padding:6px 10px;font-size:.7rem;color:var(--text3);font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-top:<?= $prevCat!==null?'8px':'0' ?>">
      <i class="fas fa-folder" style="font-size:.65rem"></i> <?= htmlspecialchars($cat) ?>
    </div>
    <?php endif; ?>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:6px;display:flex;gap:8px;align-items:flex-start">
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.85rem;margin-bottom:3px"><?= htmlspecialchars($item['question']) ?></div>
        <div style="font-size:.75rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($item['answer']) ?></div>
      </div>
      <div style="display:flex;gap:4px;flex-shrink:0">
        <a href="?action=toggle&id=<?= $item['id'] ?>" class="btn btn-sm btn-<?= $item['status']?'success':'secondary' ?>" title="<?= $item['status']?'تعطيل':'تفعيل' ?>">
          <i class="fas fa-<?= $item['status']?'eye':'eye-slash' ?>"></i>
        </a>
        <a href="?action=edit&id=<?= $item['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
        <a href="?action=delete&id=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف هذا السؤال؟')"><i class="fas fa-trash"></i></a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<?php include 'footer.php'; ?>
