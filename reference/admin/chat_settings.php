<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'إعدادات المحادثة';

// إنشاء جدول الردود التلقائية
$pdo->exec("CREATE TABLE IF NOT EXISTS `chat_auto_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `keywords` VARCHAR(500) NOT NULL, `reply` TEXT NOT NULL,
  `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM chat_auto_replies WHERE id=?")->execute([$id]);
    flashMessage('success','تم الحذف');
    redirect(SITE_URL.'/admin/chat_settings.php');
}
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE chat_auto_replies SET status=1-status WHERE id=?")->execute([$id]);
    redirect(SITE_URL.'/admin/chat_settings.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_reply'])) {
    $kw    = trim($_POST['keywords'] ?? '');
    $reply = trim($_POST['reply']    ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $rid   = (int)($_POST['edit_id'] ?? 0);
    if ($kw && $reply) {
        if ($rid) $pdo->prepare("UPDATE chat_auto_replies SET keywords=?,reply=?,sort_order=? WHERE id=?")->execute([$kw,$reply,$sort,$rid]);
        else $pdo->prepare("INSERT INTO chat_auto_replies (keywords,reply,sort_order) VALUES (?,?,?)")->execute([$kw,$reply,$sort]);
        flashMessage('success','تم الحفظ');
    }
    redirect(SITE_URL.'/admin/chat_settings.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_settings'])) {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('chat_welcome',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['chat_welcome'],$_POST['chat_welcome']]);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('chat_enabled',?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$_POST['chat_enabled']??0,$_POST['chat_enabled']??0]);
    flashMessage('success','تم الحفظ');
    redirect(SITE_URL.'/admin/chat_settings.php');
}

$editReply = null;
if ($action==='edit' && $id) {
    $e=$pdo->prepare("SELECT * FROM chat_auto_replies WHERE id=?");$e->execute([$id]);$editReply=$e->fetch();
}
$replies = $pdo->query("SELECT * FROM chat_auto_replies ORDER BY sort_order,id")->fetchAll();
include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title"><i class="fas fa-cog"></i> إعدادات المحادثة المباشرة</div>
  </div>
  <a href="chat.php" class="btn btn-primary"><i class="fas fa-comments"></i> المحادثات</a>
</div>

<!-- الإعدادات العامة -->
<div class="card mb-2">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> إعدادات عامة</div></div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_settings" value="1">
      <div class="form-grid">
        <div class="form-group form-full">
          <label><i class="fas fa-comment"></i> رسالة الترحيب الأولى</label>
          <textarea name="chat_welcome" class="form-control" rows="2"><?= htmlspecialchars(getSetting('chat_welcome') ?: 'مرحباً! 👋 شكراً لتواصلك معنا. سيرد عليك أحد موظفينا قريباً.') ?></textarea>
        </div>
        <div class="form-group">
          <label>تفعيل المحادثة</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;border:1px solid var(--border);border-radius:8px">
            <input type="checkbox" name="chat_enabled" value="1" <?= getSetting('chat_enabled') ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>عرض زر المحادثة للعملاء</span>
          </label>
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
    </form>
  </div>
</div>

<!-- الردود التلقائية -->
<div style="display:grid;grid-template-columns:1fr 1.3fr;gap:16px">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-robot"></i> <?= $editReply?'تعديل رد':'إضافة رد تلقائي' ?></div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_reply" value="1">
        <input type="hidden" name="edit_id" value="<?= $editReply['id']??0 ?>">
        <div class="form-group">
          <label><i class="fas fa-key"></i> الكلمات المفتاحية (مفصولة بفاصلة)</label>
          <input type="text" name="keywords" class="form-control" value="<?= htmlspecialchars($editReply['keywords']??'') ?>" placeholder="مثال: سعر,كم,تكلفة">
          <div class="form-hint">عند مطابقة أي كلمة يُرسل الرد التلقائي</div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-reply"></i> نص الرد</label>
          <textarea name="reply" class="form-control" rows="3" placeholder="اكتب الرد التلقائي..."><?= htmlspecialchars($editReply['reply']??'') ?></textarea>
        </div>
        <div class="form-group">
          <label><i class="fas fa-sort"></i> الترتيب (الأقل = الأولوية)</label>
          <input type="number" name="sort_order" class="form-control" value="<?= $editReply['sort_order']??0 ?>">
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editReply?'حفظ التعديل':'إضافة رد' ?></button>
          <?php if($editReply): ?><a href="?" class="btn btn-secondary">إلغاء</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> الردود التلقائية (<?= count($replies) ?>)</div></div>
    <div style="padding:8px">
      <?php foreach($replies as $r): ?>
      <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin-bottom:8px">
        <div style="display:flex;align-items:flex-start;gap:8px">
          <div style="flex:1">
            <div style="font-size:11px;color:var(--cyan);font-weight:700;margin-bottom:4px">🔑 <?= htmlspecialchars($r['keywords']) ?></div>
            <div style="font-size:13px;color:var(--text)"><?= htmlspecialchars($r['reply']) ?></div>
          </div>
          <div style="display:flex;gap:4px;flex-shrink:0">
            <a href="?action=toggle&id=<?= $r['id'] ?>" class="btn btn-sm btn-<?= $r['status']?'success':'secondary' ?>">
              <i class="fas fa-<?= $r['status']?'eye':'eye-slash' ?>"></i>
            </a>
            <a href="?action=edit&id=<?= $r['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
            <a href="?action=delete&id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف؟')"><i class="fas fa-trash"></i></a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(empty($replies)): ?>
      <div style="text-align:center;padding:20px;color:var(--text3)">لا توجد ردود تلقائية</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
