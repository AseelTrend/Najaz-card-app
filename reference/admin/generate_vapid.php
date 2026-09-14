<?php
/**
 * admin/generate_vapid.php
 * توليد مفاتيح VAPID (تشغيل مرة واحدة فقط)
 * احذف هذا الملف بعد حفظ المفاتيح!
 */
require_once '../includes/config.php';
requireAdmin();

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

$generated = false;
$pubKey = $privKey = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        // توليد زوج مفاتيح EC P-256
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            throw new Exception('فشل توليد المفاتيح: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        // المفتاح العام: uncompressed point (04 + x + y) → base64url
        $publicKeyRaw = "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $pubKey = rtrim(strtr(base64_encode($publicKeyRaw), '+/', '-_'), '=');

        // المفتاح الخاص: d → base64url
        $privKey = rtrim(strtr(base64_encode(
            str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT)
        ), '+/', '-_'), '=');

        $generated = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $pubToSave  = trim($_POST['pub_key']  ?? '');
    $privToSave = trim($_POST['priv_key'] ?? '');
    $subj       = trim($_POST['subject']  ?? '');

    if ($pubToSave && $privToSave) {
        foreach ([
            'vapid_public_key'  => $pubToSave,
            'vapid_private_key' => $privToSave,
            'vapid_subject'     => $subj ?: 'mailto:admin@' . $_SERVER['HTTP_HOST'],
        ] as $k => $v) {
            $exists = $pdo->prepare("SELECT id FROM settings WHERE setting_key=?");
            $exists->execute([$k]);
            if ($exists->fetch()) {
                $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key=?")->execute([$v, $k]);
            } else {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)")->execute([$k, $v]);
            }
        }
        flashMessage('success', '✅ تم حفظ مفاتيح VAPID بنجاح!');
        header('Location: ' . SITE_URL . '/admin/push_notifications.php');
        exit;
    }
}

// قراءة المفاتيح الحالية
$currentPub  = getSetting('vapid_public_key');
$currentPriv = getSetting('vapid_private_key');
$currentSubj = getSetting('vapid_subject');

include 'header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-key"></i></div>
      إعداد مفاتيح VAPID
    </div>
  </div>
</div>

<?php if($error): ?>
<div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?=htmlspecialchars($error)?></div>
<?php endif; ?>

<div class="card" style="max-width:700px;margin:0 auto">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-info-circle"></i> ما هي مفاتيح VAPID؟</div>
  </div>
  <div class="card-body" style="font-size:.85rem;color:var(--text2);line-height:1.8">
    مفاتيح VAPID تُستخدم لمصادقة إشعارات Push. توليدها مرة واحدة فقط — لا تغيّرها بعد ذلك أو ستنقطع الإشعارات عن المشتركين الحاليين.
  </div>
</div>

<div class="card" style="max-width:700px;margin:16px auto">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-cogs"></i> المفاتيح الحالية</div>
  </div>
  <div class="card-body">
    <?php if($currentPub): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> مفاتيح VAPID مضبوطة ✅</div>
    <div class="form-group">
      <label>المفتاح العام (Public Key)</label>
      <input type="text" class="form-control" value="<?=htmlspecialchars($currentPub)?>" readonly onclick="this.select()">
    </div>
    <div class="form-group">
      <label>المفتاح الخاص (Private Key) — سري!</label>
      <input type="password" class="form-control" value="<?=htmlspecialchars($currentPriv)?>" readonly onclick="this.type='text';this.select()">
    </div>
    <div class="form-group">
      <label>Subject</label>
      <input type="text" class="form-control" value="<?=htmlspecialchars($currentSubj)?>" readonly>
    </div>
    <hr>
    <p style="color:var(--text3);font-size:.8rem">لتغيير المفاتيح: ولّد جديدة أدناه ثم احفظها.</p>
    <?php else: ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> لا توجد مفاتيح VAPID — وَلِّد وأحفظ لتفعيل الإشعارات</div>
    <?php endif; ?>
  </div>
</div>

<!-- توليد مفاتيح جديدة -->
<div class="card" style="max-width:700px;margin:0 auto 16px">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-magic"></i> توليد مفاتيح جديدة</div>
  </div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>
      <button name="generate" type="submit" class="btn btn-primary mb-3">
        <i class="fas fa-key"></i> توليد مفاتيح VAPID
      </button>
    </form>

    <?php if($generated): ?>
    <div class="alert alert-success"><i class="fas fa-check"></i> تم التوليد — احفظهم الآن!</div>
    <form method="POST">
        <?= adminCsrfField() ?>
      <div class="form-group">
        <label>المفتاح العام (Public Key) — ضعه في mobile.php أيضاً</label>
        <input type="text" name="pub_key" class="form-control" value="<?=htmlspecialchars($pubKey)?>" onclick="this.select()">
      </div>
      <div class="form-group">
        <label>المفتاح الخاص (Private Key) — سري، لا تشاركه!</label>
        <input type="text" name="priv_key" class="form-control" value="<?=htmlspecialchars($privKey)?>" onclick="this.select()">
      </div>
      <div class="form-group">
        <label>Subject (بريد أو رابط للتواصل)</label>
        <input type="text" name="subject" class="form-control" value="mailto:admin@<?=$_SERVER['HTTP_HOST']?>">
      </div>
      <div class="alert alert-warning" style="font-size:.82rem">
        <i class="fas fa-exclamation-triangle"></i>
        بعد الحفظ، غيّر <code>VAPID_PUBLIC_KEY</code> في <code>mobile.php</code> السطر ~7373 إلى المفتاح العام الجديد.
      </div>
      <button name="save" type="submit" class="btn btn-success">
        <i class="fas fa-save"></i> حفظ المفاتيح في قاعدة البيانات
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div style="max-width:700px;margin:0 auto;text-align:center;color:var(--text3);font-size:.75rem">
  ⚠️ احذف هذا الملف <code>admin/generate_vapid.php</code> بعد إتمام الإعداد
</div>

<?php include 'footer.php'; ?>
