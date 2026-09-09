<?php
/**
 * migrate_display_name.php
 * ─────────────────────────────────────────────────────────────────
 * ملف تنفيذ مرة واحدة — يملأ عمود display_name للعملاء السابقين
 * بناءً على full_name الموجود، أو username كاحتياط.
 *
 * ضعه في: admin/migrate_display_name.php
 * افتحه من المتصفح مرة واحدة، ثم احذفه.
 */
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

$log = [];
$errors = [];

// 1. أضف العمود إن لم يكن موجوداً
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
    if (!in_array('display_name', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `full_name`");
        $log[] = '✅ تم إضافة عمود display_name';
    } else {
        $log[] = '✅ عمود display_name موجود مسبقاً';
    }
} catch (Exception $e) {
    $errors[] = '❌ فشل إضافة العمود: ' . $e->getMessage();
}

// 2. جلب العملاء الذين display_name فارغ
try {
    $customers = $pdo->query("
        SELECT id, username, full_name, display_name
        FROM users
        WHERE role = 'customer'
          AND (is_deleted IS NULL OR is_deleted = 0)
          AND (display_name IS NULL OR display_name = '')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $log[] = "📋 عدد العملاء الذين يحتاجون تحديث: " . count($customers);
} catch (Exception $e) {
    $errors[] = '❌ فشل جلب العملاء: ' . $e->getMessage();
    $customers = [];
}

// 3. تحديث كل عميل
$updated = 0;
$stmt = $pdo->prepare("UPDATE users SET display_name=? WHERE id=?");

foreach ($customers as $c) {
    // الأولوية: full_name → username
    $displayName = !empty(trim($c['full_name'])) ? trim($c['full_name']) : $c['username'];

    // اقتص إلى 100 حرف
    $displayName = mb_substr($displayName, 0, 100);

    try {
        $stmt->execute([$displayName, $c['id']]);
        $updated++;
        $log[] = "  ✓ #{$c['id']} {$c['username']} → " . htmlspecialchars($displayName);
    } catch (Exception $e) {
        $errors[] = "  ✗ #{$c['id']}: " . $e->getMessage();
    }
}

$log[] = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
$log[] = "✅ تم تحديث $updated عميل بنجاح";
if (!empty($errors)) {
    $log[] = "⚠️ " . count($errors) . " أخطاء — راجع التفاصيل أدناه";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>ترحيل display_name</title>
<style>
  body { font-family: monospace; background:#0d1117; color:#cdd; padding:20px; font-size:14px; line-height:1.8; }
  h2   { color:#a78bfa; border-bottom:1px solid #333; padding-bottom:8px; }
  .ok  { color:#00d4aa; }
  .err { color:#ff6677; background:rgba(255,100,100,.08); padding:3px 8px; border-radius:4px; border-right:3px solid #ff4455; }
  .summary { padding:12px 16px; border-radius:8px; margin:16px 0; font-size:15px; font-weight:700; }
  .all-ok  { background:rgba(0,212,170,.1); color:#00d4aa; border:1px solid rgba(0,212,170,.3); }
  .has-err { background:rgba(255,68,85,.1);  color:#ff6677; border:1px solid rgba(255,68,85,.3); }
  .warn { background:rgba(245,166,35,.1); color:#f5a623; border:1px solid rgba(245,166,35,.3); padding:12px 16px; border-radius:8px; margin-top:20px; font-size:13px; }
</style>
</head>
<body>
<h2>🔄 ترحيل display_name للعملاء السابقين</h2>

<div class="summary <?= empty($errors) ? 'all-ok' : 'has-err' ?>">
  <?= empty($errors)
    ? "✅ اكتمل الترحيل — تم تحديث $updated عميل"
    : "⚠️ اكتمل مع " . count($errors) . " أخطاء" ?>
</div>

<div>
  <?php foreach ($log as $l): ?>
  <div class="ok"><?= htmlspecialchars($l) ?></div>
  <?php endforeach; ?>
</div>

<?php if (!empty($errors)): ?>
<div style="margin-top:16px">
  <h2>❌ الأخطاء</h2>
  <?php foreach ($errors as $e): ?>
  <div class="err"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="warn">
  ⚠️ <strong>تذكر:</strong> احذف هذا الملف بعد التنفيذ<br>
  المسار: <code>admin/migrate_display_name.php</code>
</div>

</body>
</html>
