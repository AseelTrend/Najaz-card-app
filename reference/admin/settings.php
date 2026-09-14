<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'الإعدادات - ' . SITE_NAME;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!isAdmin() && !canAccess($pdo,'perm_settings')) { flashMessage('danger','ليس لديك صلاحية تعديل الإعدادات'); redirect(SITE_URL.'/admin/settings.php'); }

  // قائمة كل الـ checkboxes في الإعدادات — إذا لم تُرسل تعني 0
  $checkboxKeys = ['device_auto_approve', 'google_login_enabled'];



  // ── رفع شعار الموقع ──
  if (!empty($_FILES['site_logo']['tmp_name'])) {
    $uploadDir = dirname(__DIR__) . '/uploads/logo/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp','svg','gif']) && $_FILES['site_logo']['size'] <= 2*1024*1024) {
      $oldLogo = getSetting('site_logo');
      if ($oldLogo && file_exists(dirname(__DIR__) . '/' . $oldLogo)) @unlink(dirname(__DIR__) . '/' . $oldLogo);
      $fileName = 'logo_' . time() . '.' . $ext;
      if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $uploadDir . $fileName)) {
        $logoPath = 'uploads/logo/' . $fileName;
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('site_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$logoPath, $logoPath]);
      }
    }
  }
  // حذف الشعار
  if (!empty($_POST['delete_site_logo']) && $_POST['delete_site_logo'] === '1') {
    $oldLogo = getSetting('site_logo');
    if ($oldLogo && file_exists(dirname(__DIR__) . '/' . $oldLogo)) @unlink(dirname(__DIR__) . '/' . $oldLogo);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('site_logo','') ON DUPLICATE KEY UPDATE setting_value=''")->execute();
  }

  // ── معالجة ملف الصوت المرفوع ──
  $checkboxKeys[] = 'success_sound_enabled';
  $checkboxKeys[] = 'success_vibrate_enabled';
  $checkboxKeys[] = 'success_animation_enabled';
  $checkboxKeys[] = 'cancel_sound_enabled';

  // حذف النغمة المخصصة
  if (!empty($_POST['delete_custom_sound']) && $_POST['delete_custom_sound'] === '1') {
    $oldSound = getSetting('success_sound_custom');
    if ($oldSound && file_exists(dirname(__DIR__) . '/' . $oldSound)) {
      @unlink(dirname(__DIR__) . '/' . $oldSound);
    }
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('success_sound_custom','') ON DUPLICATE KEY UPDATE setting_value=''")->execute();
  }

  // رفع نغمة مخصصة جديدة
  if (!empty($_FILES['success_sound_file']['tmp_name'])) {
    $uploadDir = dirname(__DIR__) . '/uploads/sounds/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($_FILES['success_sound_file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['mp3','wav','ogg']) && $_FILES['success_sound_file']['size'] <= 2*1024*1024) {
      // حذف القديمة
      $oldSound = getSetting('success_sound_custom');
      if ($oldSound && file_exists(dirname(__DIR__) . '/' . $oldSound)) @unlink(dirname(__DIR__) . '/' . $oldSound);
      $fileName  = 'sound_' . time() . '.' . $ext;
      if (move_uploaded_file($_FILES['success_sound_file']['tmp_name'], $uploadDir . $fileName)) {
        $soundPath = 'uploads/sounds/' . $fileName;
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('success_sound_custom',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$soundPath, $soundPath]);
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('success_sound_type','custom') ON DUPLICATE KEY UPDATE setting_value='custom'")->execute();
      }
    }
  }

  // ── حذف نغمة الإلغاء ──
  if (!empty($_POST['delete_cancel_sound']) && $_POST['delete_cancel_sound'] === '1') {
    $old = getSetting('cancel_sound_custom');
    if ($old && file_exists(dirname(__DIR__) . '/' . $old)) @unlink(dirname(__DIR__) . '/' . $old);
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('cancel_sound_custom','') ON DUPLICATE KEY UPDATE setting_value=''")->execute();
  }
  // ── رفع نغمة الإلغاء ──
  if (!empty($_FILES['cancel_sound_file']['tmp_name'])) {
    $uploadDir = dirname(__DIR__) . '/uploads/sounds/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($_FILES['cancel_sound_file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['mp3','wav','ogg']) && $_FILES['cancel_sound_file']['size'] <= 2*1024*1024) {
      $old = getSetting('cancel_sound_custom');
      if ($old && file_exists(dirname(__DIR__) . '/' . $old)) @unlink(dirname(__DIR__) . '/' . $old);
      $fn = 'cancel_' . time() . '.' . $ext;
      if (move_uploaded_file($_FILES['cancel_sound_file']['tmp_name'], $uploadDir . $fn)) {
        $cp = 'uploads/sounds/' . $fn;
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('cancel_sound_custom',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$cp, $cp]);
      }
    }
  }

  // حفظ القيم العادية
  foreach ($_POST as $key => $value) {
    if (strpos($key,'_')===0) continue;
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
      ->execute([$key,trim($value),trim($value)]);
  }

  // حفظ الـ checkboxes — فقط الخاصة بالفورم المُرسَل
  // كل فورم يُرسل _form_id يُحدد أي checkboxes تخصه
  $formId = $_POST['_form_id'] ?? 'general';
  $formCheckboxMap = [
    'devices'  => ['device_auto_approve'],
    'google'   => ['google_login_enabled'],
    'sound'    => ['success_sound_enabled','success_vibrate_enabled','success_animation_enabled','cancel_sound_enabled'],
    'sound_success' => ['success_sound_enabled','success_vibrate_enabled','success_animation_enabled'],
    'sound_cancel'  => ['cancel_sound_enabled'],
    'general'  => [],
  ];
  $activeCheckboxes = $formCheckboxMap[$formId] ?? [];
  foreach ($activeCheckboxes as $cbKey) {
    $cbVal = isset($_POST[$cbKey]) ? '1' : '0';
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
      ->execute([$cbKey, $cbVal, $cbVal]);
  }
  // الـ checkboxes القديمة من $checkboxKeys لا تزال تُعالج للتوافق
  foreach ($checkboxKeys as $cbKey) {
    if (in_array($cbKey, $activeCheckboxes)) continue; // سبق معالجتها
    if (isset($_POST[$cbKey])) { // فقط إذا أُرسلت صراحةً
      $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$cbKey, '1', '1']);
    }
  }

  flashMessage('success','تم حفظ الإعدادات بنجاح');
  redirect(SITE_URL.'/admin/settings.php');
}
include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(37,99,235,0.15)"><i class="fas fa-sliders-h" style="color:var(--primary)"></i></div>
      إعدادات المنصة
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- General Settings -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-globe"></i> الإعدادات العامة</div>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="general">
        <div class="form-group">
          <label><i class="fas fa-tag"></i> اسم المنصة</label>
          <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?>">
        </div>

        <div class="form-group">
          <label><i class="fas fa-image"></i> شعار الموقع (Logo)</label>
          <div style="margin-top:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">

            <!-- Current Logo Preview -->
            <?php $currentLogo = getSetting('site_logo'); ?>
            <?php if ($currentLogo): ?>
            <div id="currentLogoWrap" style="position:relative;display:inline-block">
              <img src="<?= SITE_URL . '/' . htmlspecialchars($currentLogo) ?>" alt="الشعار الحالي"
                style="height:60px;max-width:160px;object-fit:contain;border-radius:10px;border:1px solid var(--border2);background:#0d1428;padding:6px">
              <button type="button" onclick="deleteLogo()"
                style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;background:#ff4757;border:none;border-radius:50%;color:white;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1">✕</button>
            </div>
            <?php else: ?>
            <div id="logoPreviewWrap" style="display:none;position:relative">
              <img id="logoPreview" src="" alt="معاينة"
                style="height:60px;max-width:160px;object-fit:contain;border-radius:10px;border:1px solid var(--border2);background:#0d1428;padding:6px">
            </div>
            <?php endif; ?>

            <!-- Upload Button -->
            <label style="background:var(--bg);border:2px dashed var(--border2);border-radius:10px;padding:10px 18px;cursor:pointer;font-size:12px;color:var(--text2);display:flex;align-items:center;gap:8px;transition:.2s" id="logoUploadLbl">
              <i class="fas fa-cloud-upload-alt" style="font-size:16px;color:var(--primary)"></i>
              <span id="logoUploadText"><?= $currentLogo ? 'استبدال الشعار' : 'رفع شعار' ?></span>
              <input type="file" name="site_logo" accept=".png,.jpg,.jpeg,.webp,.svg,.gif" style="display:none"
                onchange="previewLogo(this)">
            </label>

            <div style="font-size:11px;color:var(--text2);line-height:1.6">
              PNG أو SVG بخلفية شفافة<br>الحد الأقصى: 2MB
            </div>
          </div>
          <input type="hidden" name="delete_site_logo" value="0" id="deleteLogoFlag">
        </div>

        <div class="form-group">
          <label><i class="fas fa-money-bill"></i> رمز العملة</label>
          <input type="text" name="currency_symbol" class="form-control" value="<?= htmlspecialchars(getSetting('currency_symbol')?:'$') ?>" placeholder="$">
        </div>

        <div class="form-group">
          <label><i class="fas fa-stopwatch"></i> مدة Cooldown بين الطلبات (بالثواني)</label>
          <?php $cooldownVal = getSetting('cooldown_seconds'); ?>
          <input type="number" min="0" name="cooldown_seconds" class="form-control"
                 value="<?= htmlspecialchars($cooldownVal !== null && $cooldownVal !== '' ? $cooldownVal : '60') ?>">
          <div class="text-xs text-muted" style="margin-top:6px">
            يمنع إنشاء طلب جديد لنفس بيانات الطلب (نفس رقم اللاعب/الآيدي لنفس الخدمة) طالما وُجد طلب سابق بنفس البيانات لا يزال "قيد الانتظار" ولم تمر هذه المدة على إنشائه.
            يُطبَّق موحّداً على طلبات الموقع وطلبات الربط الخارجي (API) معاً. ضع <b>0</b> لتعطيل هذه الحماية بالكامل.
          </div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-code"></i> كود العملة</label>
          <input type="text" name="currency" class="form-control" value="<?= htmlspecialchars(getSetting('currency')?:'SAR') ?>" placeholder="SAR">
        </div>
        <div class="form-group">
          <label><i class="fas fa-envelope"></i> إيميل التواصل</label>
          <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars(getSetting('contact_email')?:'') ?>" placeholder="admin@example.com">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
      </form>
    </div>
  </div>

  <!-- Cron & System -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-robot"></i> تحديث تلقائي للطلبات</div>
      </div>
      <div class="card-body">
        <p style="font-size:12px;color:var(--text2);margin-bottom:14px;line-height:1.8">لتحديث حالة الطلبات من مزودي API تلقائياً، أضف هذا الأمر إلى Cron Jobs في cPanel:</p>
        <div style="background:var(--bg);padding:12px;border-radius:var(--radius-sm);font-family:monospace;font-size:11px;color:var(--cyan);border:1px solid var(--border2);word-break:break-all">
          */5 * * * * php <?= realpath('../api/cron.php') ?>
        </div>
        <div style="margin-top:14px">
          <a href="<?= SITE_URL ?>/api/cron.php?secret=admin123" class="btn btn-warning btn-sm" target="_blank">
            <i class="fas fa-play"></i> تشغيل يدوياً الآن
          </a>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-info-circle"></i> معلومات النظام</div>
      </div>
      <div class="card-body">
        <?php
        $sysInfo = [
          'إصدار PHP' => PHP_VERSION,
          'قاعدة البيانات' => 'MySQL / PDO',
          'التوقيت' => date('Y/m/d H:i:s'),
          'المجلد' => dirname(__DIR__),
        ];
        foreach ($sysInfo as $k=>$v): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px">
          <span style="color:var(--text3)"><?= $k ?></span>
          <span class="code"><?= htmlspecialchars($v) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ════ إعدادات الأجهزة ════ -->
<div style="margin-top:20px;margin-bottom:4px">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-mobile-alt" style="color:#00d4aa"></i> إعدادات تصريح الأجهزة</div>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="devices">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">
          <label style="display:flex;align-items:flex-start;gap:12px;background:var(--bg);border:1.5px solid var(--border);border-radius:12px;padding:14px;cursor:pointer" id="autoApproveLbl">
            <input type="checkbox" name="device_auto_approve" value="1"
              <?= getSetting('device_auto_approve') ? 'checked' : '' ?>
              onchange="highlightDeviceMode()"
              style="width:18px;height:18px;accent-color:#00d4aa;flex-shrink:0;margin-top:2px">
            <div>
              <div style="font-weight:800;font-size:.9rem">✅ تصريح تلقائي</div>
              <div style="font-size:.75rem;color:#8895a7;margin-top:4px;line-height:1.6">
                أي جهاز جديد يُصرَّح له تلقائياً دون الحاجة لموافقة الإدارة
              </div>
            </div>
          </label>
          <div style="background:rgba(245,166,35,.06);border:1px solid rgba(245,166,35,.15);border-radius:12px;padding:14px;font-size:.78rem;color:#8895a7;line-height:1.8">
            <div style="color:#f5a623;font-weight:800;margin-bottom:6px"><i class="fas fa-info-circle"></i> عند إيقاف التصريح التلقائي:</div>
            <div>• الجهاز الأول يُصرَّح تلقائياً دائماً</div>
            <div>• أي جهاز جديد يظل معلقاً حتى تصرّحه من قسم العملاء</div>
            <div>• يُرسَل إشعار للمستخدم عند التصريح</div>
          </div>
        </div>
        <div style="margin-top:14px">
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ إعدادات الأجهزة</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ════ Google OAuth ════ -->
<div style="margin-top:20px;margin-bottom:20px">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title">
        <svg width="18" height="18" viewBox="0 0 48 48" style="margin-left:6px"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        تسجيل الدخول عبر Google
      </div>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="google">
        <div style="background:rgba(66,133,244,.07);border:1px solid rgba(66,133,244,.2);border-radius:10px;padding:14px;margin-bottom:16px;font-size:.82rem;color:#8895a7;line-height:1.8">
          <strong style="color:#4285f4"><i class="fas fa-info-circle"></i> كيفية الإعداد:</strong><br>
          ١. روح <a href="https://console.cloud.google.com" target="_blank" style="color:#4285f4">console.cloud.google.com</a><br>
          ٢. APIs &amp; Services → Credentials → Create OAuth 2.0 Client ID<br>
          ٣. Application Type: <strong style="color:#fff">Web application</strong><br>
          ٤. Authorized redirect URIs أضف: <code style="background:rgba(0,0,0,.3);padding:2px 8px;border-radius:4px;color:#00d4ff"><?= SITE_URL ?>/auth/google/callback.php</code><br>
          ٥. انسخ Client ID و Client Secret وضعهما أدناه
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label><i class="fab fa-google" style="color:#4285f4"></i> Google Client ID</label>
            <input type="text" name="google_client_id" class="form-control" value="<?= htmlspecialchars(getSetting('google_client_id')?:'') ?>" placeholder="XXXXXXXX.apps.googleusercontent.com">
          </div>
          <div class="form-group">
            <label><i class="fas fa-key" style="color:#f5a623"></i> Google Client Secret</label>
            <input type="password" name="google_client_secret" class="form-control" value="<?= htmlspecialchars(getSetting('google_client_secret')?:'') ?>" placeholder="GOCSPX-XXXXXXXXXX">
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:16px">
          <input type="checkbox" name="google_login_enabled" value="1" <?= getSetting('google_login_enabled') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#4285f4">
          <span style="font-weight:700">تفعيل تسجيل الدخول عبر Google</span>
        </label>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ إعدادات Google</button>
      </form>
    </div>
  </div>
</div>

<!-- ════ صفحات المحتوى ════ -->
<div style="margin-top:20px">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
    <div style="width:38px;height:38px;background:rgba(0,212,170,.12);border-radius:10px;display:flex;align-items:center;justify-content:center"><i class="fas fa-file-alt" style="color:#00d4aa"></i></div>
    <div>
      <div style="font-weight:900;font-size:1rem">صفحات المحتوى</div>
      <div style="font-size:.75rem;color:#8895a7">تعديل محتوى صفحة "من نحن" و"سياسة الخصوصية" بـ HTML</div>
    </div>
  </div>

  <!-- تبويبات -->
  <div style="display:flex;gap:8px;margin-bottom:14px" id="pageTabBtns">
    <button class="btn btn-primary btn-sm" id="tabBtn_about" onclick="switchPageTab('about')"><i class="fas fa-building"></i> من نحن</button>
    <button class="btn btn-secondary btn-sm" id="tabBtn_privacy" onclick="switchPageTab('privacy')"><i class="fas fa-lock"></i> سياسة الخصوصية</button>
    <a href="<?= SITE_URL ?>/about.php" target="_blank" class="btn btn-secondary btn-sm" style="margin-right:auto"><i class="fas fa-external-link-alt"></i> معاينة</a>
  </div>

  <!-- About -->
  <div id="pageTab_about" class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-building"></i> محتوى صفحة "من نحن"</div>
      <a href="<?= SITE_URL ?>/about.php" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> معاينة</a>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <div class="form-group">
          <label style="display:flex;align-items:center;justify-content:space-between">
            <span><i class="fas fa-code"></i> محتوى HTML</span>
            <div style="display:flex;gap:6px">
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('about_ta','<h2>العنوان</h2>\n<p>النص هنا...</p>')">+ عنوان</button>
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('about_ta','<ul>\n  <li>نقطة 1</li>\n  <li>نقطة 2</li>\n</ul>')">+ قائمة</button>
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('about_ta','<blockquote>نص مميز</blockquote>')">+ اقتباس</button>
            </div>
          </label>
          <textarea name="page_about" id="about_ta" rows="16"
            style="width:100%;background:var(--bg);border:1px solid var(--border2);border-radius:10px;padding:12px;color:#e2e8f0;font-family:monospace;font-size:.82rem;resize:vertical;line-height:1.7"
            placeholder="<h2>من نحن</h2>&#10;<p>منصتنا تقدم...</p>"><?= htmlspecialchars(getSetting('page_about') ?: '') ?></textarea>
          <div style="font-size:.72rem;color:#8895a7;margin-top:5px">
            <i class="fas fa-info-circle"></i> يمكنك استخدام HTML كامل: h1-h3, p, ul/ol/li, strong, blockquote, hr, img, table
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ صفحة "من نحن"</button>
          <a href="<?= SITE_URL ?>/about.php" target="_blank" class="btn btn-secondary"><i class="fas fa-external-link-alt"></i> معاينة</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Privacy -->
  <div id="pageTab_privacy" class="card" style="display:none">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-lock"></i> محتوى صفحة "سياسة الخصوصية"</div>
      <a href="<?= SITE_URL ?>/privacy.php" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> معاينة</a>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <div class="form-group">
          <label style="display:flex;align-items:center;justify-content:space-between">
            <span><i class="fas fa-code"></i> محتوى HTML</span>
            <div style="display:flex;gap:6px">
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('privacy_ta','<h2>العنوان</h2>\n<p>النص هنا...</p>')">+ عنوان</button>
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('privacy_ta','<ul>\n  <li>نقطة 1</li>\n  <li>نقطة 2</li>\n</ul>')">+ قائمة</button>
              <button type="button" class="btn btn-xs btn-secondary" onclick="insertHtml('privacy_ta','<blockquote>نص مميز</blockquote>')">+ اقتباس</button>
            </div>
          </label>
          <textarea name="page_privacy" id="privacy_ta" rows="16"
            style="width:100%;background:var(--bg);border:1px solid var(--border2);border-radius:10px;padding:12px;color:#e2e8f0;font-family:monospace;font-size:.82rem;resize:vertical;line-height:1.7"
            placeholder="<h2>سياسة الخصوصية</h2>&#10;<p>نحترم خصوصيتك...</p>"><?= htmlspecialchars(getSetting('page_privacy') ?: '') ?></textarea>
          <div style="font-size:.72rem;color:#8895a7;margin-top:5px">
            <i class="fas fa-info-circle"></i> يمكنك استخدام HTML كامل: h1-h3, p, ul/ol/li, strong, blockquote, hr, img, table
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ سياسة الخصوصية</button>
          <a href="<?= SITE_URL ?>/privacy.php" target="_blank" class="btn btn-secondary"><i class="fas fa-external-link-alt"></i> معاينة</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ─── إعدادات الإشعارات ───────────────────────────────────────────────────── -->
<div style="margin-top:20px">
  <h3 style="font-size:1rem;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-bell" style="color:var(--gold)"></i> إعدادات الإشعارات
  </h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <!-- تليغرام -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fab fa-telegram" style="color:#29b6f6"></i> تليغرام</div>
      </div>
      <div class="card-body">
        <form method="POST">
        <?= adminCsrfField() ?>
          <div class="form-group">
            <label><i class="fas fa-key"></i> Bot Token</label>
            <input type="text" name="telegram_bot_token" class="form-control" value="<?= htmlspecialchars(getSetting('telegram_bot_token')) ?>" placeholder="123456:ABC-DEF...">
            <small style="color:var(--text3);font-size:11px;margin-top:4px;display:block">أنشئ بوت من <a href="https://t.me/BotFather" target="_blank" style="color:#29b6f6">@BotFather</a> واحصل على التوكن</small>
          </div>
          <div class="form-group">
            <label><i class="fas fa-hashtag"></i> Chat ID للإدارة</label>
            <input type="text" name="telegram_admin_chat" class="form-control" value="<?= htmlspecialchars(getSetting('telegram_admin_chat')) ?>" placeholder="-100xxxxxxxxxx">
            <small style="color:var(--text3);font-size:11px;margin-top:4px;display:block">Chat ID للمجموعة أو المحادثة التي تستقبل إشعارات الإدارة</small>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
            <label style="font-size:12px;color:var(--text2);font-weight:600">إشعارات الإدارة:</label>
            <?php
            $tgSettings = [
              'telegram_notify_admin_orders'   => 'طلبات جديدة',
              'telegram_notify_admin_topup'    => 'طلبات شحن رصيد',
              'telegram_notify_admin_register' => 'تسجيل مستخدم جديد',
            ];
            foreach ($tgSettings as $key => $label):
            ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer">
              <input type="checkbox" name="<?= $key ?>" value="1" <?= getSetting($key) ? 'checked' : '' ?> style="width:16px;height:16px">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-left:8px"><i class="fas fa-save"></i> حفظ</button>
          <button type="button" class="btn btn-secondary btn-sm" onclick="testTelegram()"><i class="fas fa-paper-plane"></i> اختبار الإرسال</button>
        </form>
      </div>
    </div>

    <!-- واتساب + أحداث الإشعارات -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <!-- واتساب -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-title"><i class="fab fa-whatsapp" style="color:#25d366"></i> واتساب (API)</div>
        </div>
        <div class="card-body">
          <form method="POST">
        <?= adminCsrfField() ?>
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="whatsapp_enabled" value="1" <?= getSetting('whatsapp_enabled') ? 'checked' : '' ?>>
                تفعيل واتساب
              </label>
            </div>
            <div class="form-group">
              <label><i class="fas fa-link"></i> API URL</label>
              <input type="url" name="whatsapp_api_url" class="form-control" value="<?= htmlspecialchars(getSetting('whatsapp_api_url')) ?>" placeholder="https://api.whatsapp.example.com/send">
            </div>
            <div class="form-group">
              <label><i class="fas fa-key"></i> API Token</label>
              <input type="text" name="whatsapp_api_token" class="form-control" value="<?= htmlspecialchars(getSetting('whatsapp_api_token')) ?>" placeholder="Bearer token...">
            </div>
            <div class="form-group">
              <label><i class="fas fa-phone"></i> رقم الإدارة</label>
              <input type="text" name="whatsapp_admin_number" class="form-control" value="<?= htmlspecialchars(getSetting('whatsapp_admin_number')) ?>" placeholder="9665xxxxxxxx">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> حفظ</button>
          </form>
        </div>
      </div>

      <!-- أحداث الإشعارات -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-title"><i class="fas fa-sliders-h"></i> أحداث الإشعارات للعملاء</div>
        </div>
        <div class="card-body">
          <form method="POST">
        <?= adminCsrfField() ?>
            <div style="display:flex;flex-direction:column;gap:10px">
              <?php
              $evSettings = [
                'notify_on_order_new'      => ['🛍️ طلب جديد (تأكيد للعميل)',      '#f5a623'],
                'notify_on_order_complete' => ['✅ اكتمال الطلب',                   '#00e676'],
                'notify_on_order_cancel'   => ['❌ إلغاء/فشل الطلب',               '#ff4455'],
                'notify_on_topup_approve'  => ['💰 موافقة على شحن الرصيد',         '#00e676'],
                'notify_on_topup_reject'   => ['🚫 رفض طلب شحن الرصيد',           '#ff4455'],
              ];
              foreach ($evSettings as $key => [$label, $color]):
              ?>
              <label style="display:flex;align-items:center;gap:10px;font-size:12px;cursor:pointer;padding:8px 10px;border-radius:8px;border:1px solid var(--border)">
                <input type="checkbox" name="<?= $key ?>" value="1" <?= getSetting($key) !== '0' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:<?= $color ?>">
                <span><?= $label ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:14px"><i class="fas fa-save"></i> حفظ</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- ════ إعدادات صوت النجاح ════ -->
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-music" style="color:#f5a623"></i> نغمة نجاح الشحن</div>
  </div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data" action="">
        <?= adminCsrfField() ?>
    <input type="hidden" name="_form_id" value="sound_success">

      <!-- اختيار النغمة المدمجة -->
      <div class="form-group">
        <label><i class="fas fa-volume-up"></i> النغمة المدمجة</label>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:8px" id="builtinSounds">
          <?php
          $builtins = [
            'default' => ['🎵', 'نغمة افتراضية',  'صاعدة ثلاثية كلاسيكية'],
            'coin'    => ['🪙', 'نغمة العملة',     'مناسبة لمنصات الشحن'],
            'bell'    => ['🔔', 'جرس رسمي',        'ding-dong هادئة'],
            'game'    => ['🎮', 'نغمة ألعاب',      'power-up مبهجة'],
          ];
          $current = getSetting('success_sound_builtin') ?: 'default';
          foreach ($builtins as $key => [$ico, $name, $desc]):
            $sel = $current === $key;
          ?>
          <label style="display:flex;align-items:flex-start;gap:12px;background:var(--bg);border:2px solid <?= $sel ? '#f5a623' : 'var(--border)' ?>;border-radius:12px;padding:12px;cursor:pointer;transition:.2s" onclick="selectBuiltin(this,'<?= $key ?>')">
            <input type="radio" name="success_sound_builtin" value="<?= $key ?>" <?= $sel ? 'checked' : '' ?> style="display:none">
            <span style="font-size:22px"><?= $ico ?></span>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:700;color:var(--text)"><?= $name ?></div>
              <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= $desc ?></div>
            </div>
            <button type="button" onclick="previewSound('uploads/sounds/success_<?= $key ?>.wav',event)"
              style="background:rgba(245,166,35,0.15);border:1px solid rgba(245,166,35,0.3);color:#f5a623;border-radius:8px;padding:4px 10px;cursor:pointer;font-size:11px;white-space:nowrap;flex-shrink:0">
              ▶ معاينة
            </button>
          </label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="success_sound_type" value="<?= getSetting('success_sound_type') ?: 'builtin' ?>">
      </div>

      <!-- رفع نغمة مخصصة -->
      <div class="form-group" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
        <label><i class="fas fa-upload"></i> أو ارفع نغمة مخصصة (MP3 / WAV — بحد أقصى 2MB)</label>
        <div style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">

          <!-- Current custom sound -->
          <?php $customSound = getSetting('success_sound_custom'); ?>
          <?php if ($customSound): ?>
          <div id="currentCustom" style="display:flex;align-items:center;gap:8px;background:rgba(245,166,35,0.1);border:1px solid rgba(245,166,35,0.3);border-radius:10px;padding:8px 14px;font-size:12px;color:#f5a623">
            <i class="fas fa-music"></i>
            <span><?= htmlspecialchars(basename($customSound)) ?></span>
            <button type="button" onclick="previewSound('<?= htmlspecialchars($customSound) ?>',event)"
              style="background:rgba(245,166,35,0.2);border:none;color:#f5a623;border-radius:6px;padding:2px 8px;cursor:pointer;font-size:11px">▶</button>
            <button type="button" onclick="deleteCustomSound()" title="حذف"
              style="background:rgba(255,71,87,0.2);border:none;color:#ff4757;border-radius:6px;padding:2px 8px;cursor:pointer;font-size:11px">✕</button>
          </div>
          <?php endif; ?>

          <!-- Upload input -->
          <label style="background:var(--bg);border:2px dashed var(--border);border-radius:10px;padding:10px 18px;cursor:pointer;font-size:12px;color:var(--muted);transition:.2s;display:flex;align-items:center;gap:8px" id="uploadLbl">
            <i class="fas fa-cloud-upload-alt" style="font-size:16px;color:var(--primary)"></i>
            <span id="uploadLblText"><?= $customSound ? 'استبدال النغمة' : 'اختر ملف صوتي' ?></span>
            <input type="file" name="success_sound_file" accept=".mp3,.wav,.ogg" style="display:none" onchange="handleSoundFile(this)">
          </label>
        </div>
        <input type="hidden" name="delete_custom_sound" value="0" id="deleteCustomSoundFlag">
      </div>

      <!-- إعدادات إضافية -->
      <div class="form-group" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
        <label><i class="fas fa-sliders-h"></i> خيارات إضافية</label>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:10px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <input type="checkbox" name="success_sound_enabled" value="1" <?= getSetting('success_sound_enabled') !== '0' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#f5a623">
            <span style="font-size:13px">تفعيل النغمة الصوتية عند اتمام الطلب</span>
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <input type="checkbox" name="success_vibrate_enabled" value="1" <?= getSetting('success_vibrate_enabled') !== '0' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#f5a623">
            <span style="font-size:13px">تفعيل الاهتزاز (على الأجهزة المحمولة)</span>
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
            <input type="checkbox" name="success_animation_enabled" value="1" <?= getSetting('success_animation_enabled') !== '0' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#f5a623">
            <span style="font-size:13px">تفعيل تأثير الاحتفال 🎉 (confetti) عند الطلب</span>
          </label>
        </div>
      </div>

      <!-- معاينة كاملة -->
      <div style="margin-top:16px;padding:14px;background:rgba(245,166,35,0.07);border:1px solid rgba(245,166,35,0.2);border-radius:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:gap">
        <div style="font-size:12px;color:var(--muted)">جرّب التجربة الكاملة كما ستظهر للعميل</div>
        <button type="button" onclick="fullPreview()"
          style="background:linear-gradient(135deg,#f5a623,#e67e00);border:none;color:white;font-size:13px;font-weight:700;padding:8px 20px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:7px">
          <i class="fas fa-play-circle"></i> معاينة كاملة
        </button>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:18px"><i class="fas fa-save"></i> حفظ إعدادات الصوت</button>
    </form>
  </div>
</div>

<!-- ════ نغمة الإلغاء ════ -->
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-times-circle" style="color:#ff4757"></i> نغمة إلغاء الطلب</div>
  </div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data" action="">
        <?= adminCsrfField() ?>
    <input type="hidden" name="_form_id" value="sound_cancel">

      <div class="form-group">
        <label><i class="fas fa-upload"></i> نغمة مخصصة عند إلغاء الطلب (MP3 / WAV — بحد أقصى 2MB)</label>
        <div style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <?php $cancelSound = getSetting('cancel_sound_custom'); ?>
          <?php if ($cancelSound): ?>
          <div id="currentCancelCustom" style="display:flex;align-items:center;gap:8px;background:rgba(255,71,87,0.1);border:1px solid rgba(255,71,87,0.3);border-radius:10px;padding:8px 14px;font-size:12px;color:#ff4757">
            <i class="fas fa-music"></i>
            <span><?= htmlspecialchars(basename($cancelSound)) ?></span>
            <button type="button" onclick="previewSound('<?= htmlspecialchars($cancelSound) ?>',event)"
              style="background:rgba(255,71,87,0.15);border:none;color:#ff4757;border-radius:6px;padding:2px 8px;cursor:pointer;font-size:11px">▶</button>
            <button type="button" onclick="deleteCancelSound()" title="حذف"
              style="background:rgba(255,71,87,0.2);border:none;color:#ff4757;border-radius:6px;padding:2px 8px;cursor:pointer;font-size:11px">✕</button>
          </div>
          <?php else: ?>
          <div style="background:rgba(255,71,87,0.06);border:1px dashed rgba(255,71,87,0.2);border-radius:10px;padding:10px 16px;font-size:12px;color:#8895a7;display:flex;align-items:center;gap:8px">
            <i class="fas fa-volume-mute" style="color:#ff4757"></i>
            لا توجد نغمة إلغاء — سيتم الإلغاء بصمت
          </div>
          <?php endif; ?>
          <label style="background:var(--bg);border:2px dashed var(--border);border-radius:10px;padding:10px 18px;cursor:pointer;font-size:12px;color:var(--muted);display:flex;align-items:center;gap:8px" id="cancelUploadLbl">
            <i class="fas fa-cloud-upload-alt" style="font-size:16px;color:#ff4757"></i>
            <span id="cancelUploadLblText"><?= $cancelSound ? 'استبدال نغمة الإلغاء' : 'اختر ملف صوتي للإلغاء' ?></span>
            <input type="file" name="cancel_sound_file" accept=".mp3,.wav,.ogg" style="display:none" onchange="handleCancelSoundFile(this)">
          </label>
        </div>
        <input type="hidden" name="delete_cancel_sound" value="0" id="deleteCancelSoundFlag">
      </div>

      <div class="form-group" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="cancel_sound_enabled" value="1" <?= getSetting('cancel_sound_enabled') !== '0' ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#ff4757">
          <span style="font-size:13px">تفعيل نغمة الإلغاء عند إلغاء الطلب</span>
        </label>
      </div>

      <div style="margin-top:14px">
        <button type="submit" class="btn btn-sm" style="background:rgba(255,71,87,0.15);color:#ff4757;border:1px solid rgba(255,71,87,0.3)">
          <i class="fas fa-save"></i> حفظ إعدادات الإلغاء
        </button>
      </div>
    </form>
  </div>
</div>


<script>


// ── Logo Upload ──────────────────────
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  if (file.size > 2 * 1024 * 1024) { alert('حجم الشعار أكبر من 2MB'); input.value = ''; return; }
  document.getElementById('logoUploadText').textContent = '✅ ' + file.name;
  // Show preview
  const reader = new FileReader();
  reader.onload = e => {
    const pw = document.getElementById('logoPreviewWrap');
    const pi = document.getElementById('logoPreview');
    if (pw && pi) { pi.src = e.target.result; pw.style.display = 'inline-block'; }
  };
  reader.readAsDataURL(file);
}
function deleteLogo() {
  document.getElementById('deleteLogoFlag').value = '1';
  const w = document.getElementById('currentLogoWrap');
  if (w) w.remove();
}

// ── Sound Settings ──────────────────
function selectBuiltin(lbl, key) {
  document.querySelectorAll('#builtinSounds label').forEach(l => {
    l.style.borderColor = 'var(--border)';
  });
  lbl.style.borderColor = '#f5a623';
  lbl.querySelector('input[type=radio]').checked = true;
  // set type to builtin when user picks one
  document.querySelector('[name=success_sound_type]').value = 'builtin';
}

function previewSound(url, e) {
  if (e) { e.preventDefault(); e.stopPropagation(); }
  const audio = new Audio(url.startsWith('http') ? url : '<?= SITE_URL ?>/' + url);
  audio.volume = 0.7;
  audio.play().catch(() => alert('تعذّر تشغيل الصوت'));
}

function handleSoundFile(input) {
  if (!input.files.length) return;
  const file = input.files[0];
  if (file.size > 2 * 1024 * 1024) { alert('حجم الملف أكبر من 2MB'); input.value=''; return; }
  document.getElementById('uploadLblText').textContent = '✅ ' + file.name;
  document.querySelector('[name=success_sound_type]').value = 'custom';
}

function deleteCustomSound() {
  document.getElementById('deleteCustomSoundFlag').value = '1';
  const el = document.getElementById('currentCustom');
  if (el) el.remove();
}

function handleCancelSoundFile(input) {
  if (!input.files || !input.files[0]) return;
  document.getElementById('cancelUploadLblText').textContent = '✓ ' + input.files[0].name;
  document.getElementById('cancelUploadLbl').style.borderColor = '#ff4757';
  document.getElementById('deleteCancelSoundFlag').value = '0';
}

function deleteCancelSound() {
  document.getElementById('deleteCancelSoundFlag').value = '1';
  const el = document.getElementById('currentCancelCustom');
  if (el) el.remove();
  document.getElementById('cancelUploadLblText').textContent = 'اختر ملف صوتي للإلغاء';
}

function fullPreview() {
  // Determine current selection
  const radio = document.querySelector('[name=success_sound_builtin]:checked');
  const type = document.querySelector('[name=success_sound_type]').value;
  const soundEnabled = document.querySelector('[name=success_sound_enabled]')?.checked;
  const vibrateEnabled = document.querySelector('[name=success_vibrate_enabled]')?.checked;
  const animEnabled = document.querySelector('[name=success_animation_enabled]')?.checked;

  // Play sound
  if (soundEnabled) {
    let url;
    const custom = document.getElementById('currentCustom');
    if (type === 'custom' && custom) {
      const a = custom.querySelector('button[onclick^="previewSound"]');
      if (a) { a.click(); }
    } else if (radio) {
      previewSound('uploads/sounds/success_' + radio.value + '.wav', null);
    }
  }

  // Vibrate
  if (vibrateEnabled && navigator.vibrate) {
    navigator.vibrate([100, 50, 200, 50, 100]);
  }

  // Confetti preview
  if (animEnabled) {
    launchAdminConfetti();
  }

  // Show mock toast
  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#00c853,#00e676);color:white;font-weight:700;padding:12px 24px;border-radius:20px;font-size:14px;z-index:99999;box-shadow:0 8px 24px rgba(0,200,83,0.4);direction:rtl;white-space:nowrap;animation:fadeIn .3s ease';
  t.textContent = '✅  تم تنفيذ الطلب بنجاح!';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2500);
}

function launchAdminConfetti() {
  const colors = ['#f5a623','#1e6fff','#00d4ff','#00e676','#ff4757'];
  for (let i = 0; i < 60; i++) {
    const c = document.createElement('div');
    c.style.cssText = `
      position:fixed;top:${Math.random()*30+20}%;left:${Math.random()*100}%;
      width:8px;height:8px;border-radius:${Math.random()>0.5?'50%':'2px'};
      background:${colors[Math.floor(Math.random()*colors.length)]};
      z-index:99998;pointer-events:none;
      animation:confettiFall ${(Math.random()*1.5+1).toFixed(1)}s ease-in forwards;
      transform:rotate(${Math.random()*360}deg);
    `;
    document.body.appendChild(c);
    setTimeout(() => c.remove(), 3000);
  }
  if (!document.getElementById('confettiStyle')) {
    const st = document.createElement('style');
    st.id = 'confettiStyle';
    st.textContent = '@keyframes confettiFall{from{transform:translateY(0) rotate(0deg);opacity:1}to{transform:translateY(80vh) rotate(720deg);opacity:0}}';
    document.head.appendChild(st);
  }
}

function testTelegram() {
    const token = document.querySelector('[name="telegram_bot_token"]').value;
    const chat  = document.querySelector('[name="telegram_admin_chat"]').value;
    if (!token || !chat) { alert('أدخل Bot Token و Chat ID أولاً'); return; }
    fetch('<?= SITE_URL ?>/admin/ajax_test_telegram.php?token=' + encodeURIComponent(token) + '&chat=' + encodeURIComponent(chat))
        .then(r => r.json()).then(d => alert(d.success ? '✅ تم الإرسال بنجاح!' : '❌ فشل: ' + (d.error||'خطأ غير معروف')))
        .catch(() => alert('❌ خطأ في الاتصال'));
}
function switchPageTab(tab) {
  ['about','privacy'].forEach(t => {
    document.getElementById('pageTab_'+t).style.display = t===tab ? 'block' : 'none';
    const btn = document.getElementById('tabBtn_'+t);
    btn.className = t===tab ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
  });
  // update preview link
  const previewLink = document.querySelector('#pageTabBtns a[target="_blank"]');
  if(previewLink) previewLink.href = '<?= SITE_URL ?>/' + tab + '.php';
}

function insertHtml(taId, html) {
  const ta = document.getElementById(taId);
  const start = ta.selectionStart, end = ta.selectionEnd;
  ta.value = ta.value.slice(0,start) + '\n' + html + '\n' + ta.value.slice(end);
  ta.selectionStart = ta.selectionEnd = start + html.length + 2;
  ta.focus();
}
</script>

<?php include 'footer.php'; ?>
