<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'وضع الصيانة — ' . SITE_NAME;

// ── حفظ الإعدادات ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // تفعيل / إيقاف
    if (isset($_POST['toggle_maintenance'])) {
        $current = getSetting('maintenance_mode') === '1';
        $new     = $current ? '0' : '1';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('maintenance_mode',?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute([$new, $new]);
        flashMessage($new === '1' ? 'warning' : 'success',
            $new === '1' ? '🔧 تم تفعيل وضع الصيانة' : '✅ تم إيقاف الصيانة — الموقع الآن متاح');
        redirect(SITE_URL.'/admin/maintenance.php');
    }

    // حفظ الإعدادات
    if (isset($_POST['save_settings'])) {
        $msg   = trim($_POST['maintenance_message'] ?? '');
        $until = $_POST['maintenance_until'] ?? '';
        $token = trim($_POST['maintenance_token'] ?? '');

        // تحويل التاريخ إلى timestamp
        $untilTs = $until ? (int)strtotime($until) : 0;

        // توليد token عشوائي إن كان فارغاً
        if (!$token) $token = bin2hex(random_bytes(8));

        foreach ([
            'maintenance_message' => $msg,
            'maintenance_until'   => $untilTs,
            'maintenance_token'   => $token,
        ] as $k => $v) {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$k, $v, $v]);
        }
        flashMessage('success', '✅ تم حفظ إعدادات الصيانة');
        redirect(SITE_URL.'/admin/maintenance.php');
    }
}

$isOn    = getSetting('maintenance_mode') === '1';
$msg     = getSetting('maintenance_message') ?: 'الموقع قيد الصيانة — سنعود قريباً';
$until   = (int)getSetting('maintenance_until');
$token   = getSetting('maintenance_token') ?: '';
if (!$token) {
    $token = bin2hex(random_bytes(8));
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('maintenance_token',?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$token, $token]);
}
$bypassUrl   = SITE_URL . '/mobile.php?bypass=' . $token;
$untilDate   = $until ? date('Y-m-d\TH:i', $until) : '';

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(245,166,35,.15);color:#f5a623">
        <i class="fas fa-tools"></i>
      </div>
      وضع الصيانة
    </div>
  </div>
</div>

<!-- حالة الصيانة -->
<div style="background:<?=$isOn?'rgba(255,68,85,.08)':'rgba(0,230,118,.06)'?>;border:2px solid <?=$isOn?'rgba(255,68,85,.4)':'rgba(0,230,118,.3)'?>;border-radius:16px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
  <div style="display:flex;align-items:center;gap:14px">
    <div style="font-size:2.5rem"><?=$isOn?'🔧':'✅'?></div>
    <div>
      <div style="font-size:1.1rem;font-weight:900;color:<?=$isOn?'#ff6b6b':'#00e676'?>">
        <?=$isOn?'الموقع قيد الصيانة الآن':'الموقع يعمل بشكل طبيعي'?>
      </div>
      <div style="font-size:.82rem;color:var(--text3);margin-top:4px">
        <?php if($isOn): ?>
          العملاء يرون صفحة الصيانة — الأدمن يمكنه الدخول بشكل طبيعي
        <?php else: ?>
          جميع الزوار يمكنهم الوصول للموقع
        <?php endif; ?>
      </div>
    </div>
  </div>
  <form method="POST">
        <?= adminCsrfField() ?>
    <button name="toggle_maintenance" type="submit"
      onclick="return confirm('<?=$isOn?'إيقاف الصيانة وفتح الموقع للعملاء؟':'تفعيل الصيانة وإغلاق الموقع على العملاء؟'?>')"
      class="btn <?=$isOn?'btn-success':'btn-danger'?>" style="font-size:1rem;padding:12px 24px">
      <i class="fas fa-<?=$isOn?'check':'tools'?>"></i>
      <?=$isOn?'إيقاف الصيانة — فتح الموقع':'تفعيل وضع الصيانة'?>
    </button>
  </form>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- إعدادات الصيانة -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-cog"></i> إعدادات الصيانة</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_settings" value="1">

        <div class="form-group">
          <label>رسالة الصيانة للعملاء</label>
          <textarea name="maintenance_message" class="form-control" rows="3"
            placeholder="الموقع قيد الصيانة — سنعود قريباً"><?= htmlspecialchars($msg) ?></textarea>
        </div>

        <div class="form-group">
          <label>
            <i class="fas fa-clock" style="color:#f5a623"></i>
            موعد انتهاء الصيانة (العداد التنازلي)
            <small style="color:var(--text3)"> — اتركه فارغاً بدون عداد</small>
          </label>
          <input type="datetime-local" name="maintenance_until" class="form-control"
                 value="<?= $untilDate ?>">
        </div>

        <div class="form-group">
          <label>
            <i class="fas fa-key" style="color:#00d4ff"></i>
            رمز الدخول البديل (Bypass Token)
          </label>
          <div style="display:flex;gap:8px">
            <input type="text" name="maintenance_token" class="form-control"
                   value="<?= htmlspecialchars($token) ?>"
                   style="font-family:monospace;font-size:.85rem">
            <button type="button" onclick="genToken()"
                    class="btn btn-secondary btn-sm" title="توليد رمز جديد">
              <i class="fas fa-sync"></i>
            </button>
          </div>
          <div class="form-hint">تغيير الرمز يُلغي الروابط القديمة</div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fas fa-save"></i> حفظ الإعدادات
        </button>
      </form>
    </div>
  </div>

  <!-- رابط الدخول البديل -->
  <div>
    <div class="card mb-3">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-link" style="color:#00d4ff"></i> رابط الدخول البديل للأدمن</div></div>
      <div class="card-body">
        <div style="font-size:.78rem;color:var(--text3);margin-bottom:10px">
          هذا الرابط يسمح بعرض موقع العميل حتى أثناء الصيانة:
        </div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-family:monospace;font-size:.75rem;word-break:break-all;color:#00d4ff;margin-bottom:10px">
          <?= htmlspecialchars($bypassUrl) ?>
        </div>
        <div style="display:flex;gap:8px">
          <button onclick="copyBypass()" class="btn btn-secondary btn-sm" style="flex:1">
            <i class="fas fa-copy"></i> نسخ الرابط
          </button>
          <a href="<?= htmlspecialchars($bypassUrl) ?>" target="_blank" class="btn btn-primary btn-sm" style="flex:1">
            <i class="fas fa-external-link-alt"></i> فتح الموقع
          </a>
        </div>
        <div style="margin-top:10px;padding:10px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;font-size:.75rem;color:#f5a623">
          <i class="fas fa-info-circle"></i>
          بعد فتح الرابط مرة واحدة، يُحفظ في الكوكي لمدة 8 ساعات — لن تحتاج لنسخه كل مرة.
        </div>
      </div>
    </div>

    <!-- معاينة صفحة الصيانة -->
    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-eye"></i> معاينة</div></div>
      <div class="card-body" style="text-align:center">
        <a href="<?= SITE_URL ?>/mobile.php" target="_blank" class="btn btn-secondary" style="margin-bottom:10px;width:100%">
          <i class="fas fa-mobile-alt"></i> فتح موقع العميل (كأدمن)
        </a>
        <div style="font-size:.75rem;color:var(--text3)">
          كأدمن ترى الموقع الكامل دائماً بغض النظر عن وضع الصيانة
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function genToken() {
  var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  var token = '';
  for (var i = 0; i < 16; i++) token += chars[Math.floor(Math.random()*chars.length)];
  document.querySelector('[name="maintenance_token"]').value = token;
}

function copyBypass() {
  var url = <?= json_encode($bypassUrl) ?>;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url).then(function(){ alert('تم نسخ الرابط ✅'); });
  } else {
    var t = document.createElement('textarea');
    t.value = url; document.body.appendChild(t); t.select();
    document.execCommand('copy'); document.body.removeChild(t);
    alert('تم نسخ الرابط ✅');
  }
}
</script>

<?php include 'footer.php'; ?>
