<?php
/**
 * admin/smtp_debug.php — أداة تشخيص فعلية لآلية إرسال SMTP
 * ─────────────────────────────────────────────────────────────
 * تعرض المحادثة الكاملة بين الموقع وخادم SMTP سطراً بسطر:
 * كل أمر أُرسِل، وكل ردّ استلمناه من الخادم بنصّه الحرفي — لمعرفة
 * بالضبط عند أي خطوة يفشل الاتصال ولماذا (بدل رسالة ملخّصة واحدة فقط).
 * كلمة المرور لا تظهر أبداً في السجل، حتى مُرمَّزة.
 */

require_once '../includes/config.php';
require_once '../includes/password_reset_helpers.php';
requireStaffOrAdmin($pdo, 'perm_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

$pageTitle = 'تشخيص SMTP - ' . SITE_NAME;

$result   = null; // true|false|null
$mailer   = null;
$testTo   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!$testTo || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        flashMessage('danger', 'يرجى إدخال بريد إلكتروني صحيح لإجراء الاختبار عليه.');
        redirect(SITE_URL . '/admin/smtp_debug.php');
    }

    $siteName = getSetting('site_name') ?: SITE_NAME;
    $subject  = "🧪 اختبار تشخيصي - {$siteName}";
    $html     = '<div style="font-family:Tahoma,Arial;direction:rtl;text-align:right;padding:20px">'
              . "<h3>هذه رسالة اختبار تشخيصي من أداة SMTP Debug</h3>"
              . '<p>' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p></div>';

    $mailer = getSmtpMailer();
    $result = $mailer->send($testTo, '', $subject, $html);
}

$smtp = [
    'host'       => getSetting('smtp_host'),
    'port'       => getSetting('smtp_port') ?: '587',
    'username'   => getSetting('smtp_username'),
    'encryption' => getSetting('smtp_encryption') ?: 'tls',
    'from_email' => getSetting('smtp_from_email'),
];

include 'header.php';
?>
<style>
.smtp-log {
  background: #05070d; border: 1px solid var(--border2); border-radius: 12px;
  padding: 14px; font-family: 'Courier New', monospace; font-size: 12.5px;
  line-height: 1.9; direction: ltr; text-align: left; overflow-x: auto;
  max-height: 520px; overflow-y: auto;
}
.smtp-log .ln { display: flex; gap: 8px; padding: 2px 0; white-space: pre-wrap; word-break: break-all; }
.smtp-log .tag { flex-shrink: 0; font-weight: 800; width: 26px; text-align: center; border-radius: 4px; font-size: 10px; padding: 1px 0; height: fit-content; }
.smtp-log .tag.C { background: rgba(37,99,235,.2); color: #60a5fa; }
.smtp-log .tag.S { background: rgba(16,185,129,.18); color: #34d399; }
.smtp-log .tag.i { background: rgba(148,163,184,.15); color: #94a3b8; }
.smtp-log .txt.C { color: #93c5fd; }
.smtp-log .txt.S { color: #6ee7b7; }
.smtp-log .txt.i { color: #94a3b8; font-style: italic; }
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(139,92,246,0.15)"><i class="fas fa-bug" style="color:var(--purple,#8b5cf6)"></i></div>
      تشخيص SMTP التفصيلي
    </div>
  </div>
  <a href="<?= SITE_URL ?>/admin/password_reset.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i> رجوع للإعدادات</a>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> الإعدادات الحالية المستخدَمة في الاختبار</div></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;font-size:12.5px">
      <div><span style="color:var(--text3)">Host:</span> <span class="td-mono"><?= htmlspecialchars($smtp['host'] ?: '—') ?></span></div>
      <div><span style="color:var(--text3)">Port:</span> <span class="td-mono"><?= htmlspecialchars($smtp['port']) ?></span></div>
      <div><span style="color:var(--text3)">Encryption:</span> <span class="td-mono"><?= htmlspecialchars($smtp['encryption']) ?></span></div>
      <div><span style="color:var(--text3)">Username:</span> <span class="td-mono"><?= htmlspecialchars($smtp['username'] ?: '—') ?></span></div>
      <div><span style="color:var(--text3)">From:</span> <span class="td-mono"><?= htmlspecialchars($smtp['from_email'] ?: '—') ?></span></div>
    </div>
    <div style="margin-top:10px;font-size:11.5px;color:var(--text3)">لتغيير هذه القيم اذهب إلى <a href="<?= SITE_URL ?>/admin/password_reset.php" style="color:var(--primary)">صفحة إعدادات SMTP</a>.</div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-vial"></i> تشغيل اختبار جديد</div></div>
  <div class="card-body">
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap">
      <?= adminCsrfField() ?>
      <input type="email" name="test_email" class="form-control" style="flex:1;min-width:220px" placeholder="بريد لتلقي الاختبار" value="<?= htmlspecialchars($testTo) ?>" required>
      <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> تشغيل الاختبار وعرض السجل الكامل</button>
    </form>
  </div>
</div>

<?php if ($mailer !== null): ?>
<div class="card">
  <div class="card-header">
    <div class="card-header-title">
      <?php if ($result): ?>
        <i class="fas fa-check-circle" style="color:var(--green)"></i> نجح الإرسال
      <?php else: ?>
        <i class="fas fa-times-circle" style="color:var(--red)"></i> فشل الإرسال
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <?php if (!$result): ?>
    <div class="alert alert-danger" style="margin-bottom:14px"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($mailer->lastError) ?></div>
    <?php endif; ?>

    <div style="font-size:12px;color:var(--text3);margin-bottom:8px">
      <span class="badge badge-primary">C</span> = أمر أرسله الموقع &nbsp;
      <span class="badge badge-success">S</span> = ردّ الخادم &nbsp;
      <span class="badge badge-secondary">i</span> = معلومة عامة
    </div>
    <div class="smtp-log">
      <?php foreach ($mailer->log as $entry): ?>
      <div class="ln">
        <span class="tag <?= $entry['dir'] ?>"><?= htmlspecialchars($entry['dir']) ?></span>
        <span class="txt <?= $entry['dir'] ?>"><?= htmlspecialchars($entry['text']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
