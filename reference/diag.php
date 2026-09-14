<?php
// ══════════════════════════════════════════════
//  ملف التشخيص الشامل — njaz.net
//  احذف هذا الملف فور الانتهاء من التشخيص!
// ══════════════════════════════════════════════

// إخفاء الأخطاء من المتصفح — نجمعها بأنفسنا
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 0);
$errors = [];

// ─── دالة مساعدة ───────────────────────────
function badge(bool $ok, string $yes = 'يعمل ✅', string $no = 'مشكلة ❌'): string {
    return $ok
        ? "<span class='ok'>$yes</span>"
        : "<span class='fail'>$no</span>";
}
function row(string $label, string $value, bool $ok = true): string {
    $cls = $ok ? 'ok' : 'fail';
    return "<tr><td>$label</td><td class='$cls'>$value</td></tr>";
}

// ─── 1. PHP ─────────────────────────────────
$phpVer    = PHP_VERSION;
$phpOk     = version_compare($phpVer, '7.4', '>=');
$phpMajor  = (int)PHP_MAJOR_VERSION;
$phpMinor  = (int)PHP_MINOR_VERSION;

// ─── 2. Extensions ──────────────────────────
$exts = [
    'pdo'        => 'PDO',
    'pdo_mysql'  => 'PDO MySQL',
    'mysqli'     => 'MySQLi',
    'curl'       => 'cURL',
    'json'       => 'JSON',
    'mbstring'   => 'Multibyte String',
    'openssl'    => 'OpenSSL',
    'gd'         => 'GD (الصور)',
    'zip'        => 'ZIP',
    'fileinfo'   => 'FileInfo',
    'session'    => 'Sessions',
    'tokenizer'  => 'Tokenizer',
    'xml'        => 'XML',
    'intl'       => 'Intl',
];

// ─── 3. Database ─────────────────────────────
$dbHost = '';
$dbOk   = false;
$dbErr  = '';
$dbVer  = '';
$dbTables = 0;

// محاولة قراءة بيانات الاتصال من ملفات الموقع
$configPaths = [
    __DIR__ . '/includes/config.php',
    __DIR__ . '/config.php',
    __DIR__ . '/includes/db.php',
    __DIR__ . '/db.php',
];
$dbCreds = ['host' => 'localhost', 'user' => '', 'pass' => '', 'name' => ''];

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $src = file_get_contents($path);
        // استخراج بيانات الاتصال بـ regex
        if (preg_match("/DB_HOST['\"],\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['host'] = $m[1];
        if (preg_match("/DB_USER['\"],\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['user'] = $m[1];
        if (preg_match("/DB_PASS['\"],\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['pass'] = $m[1];
        if (preg_match("/DB_NAME['\"],\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['name'] = $m[1];
        // نمط define
        if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['host'] = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['user'] = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['pass'] = $m[1];
        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)/", $src, $m)) $dbCreds['name'] = $m[1];
        if ($dbCreds['user']) break;
    }
}

if (extension_loaded('pdo_mysql') && $dbCreds['user']) {
    try {
        $dsn = "mysql:host={$dbCreds['host']};dbname={$dbCreds['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbCreds['user'], $dbCreds['pass'], [PDO::ATTR_TIMEOUT => 5]);
        $dbOk  = true;
        $dbVer = $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbTables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{$dbCreds['name']}'")->fetchColumn();
    } catch (Exception $e) {
        $dbErr = $e->getMessage();
    }
} elseif (!$dbCreds['user']) {
    $dbErr = 'لم يتم العثور على بيانات الاتصال تلقائياً';
}

// ─── 4. PHP.ini المهمة ───────────────────────
$iniKeys = [
    'upload_max_filesize' => 'أقصى حجم رفع',
    'post_max_size'       => 'أقصى حجم POST',
    'max_execution_time'  => 'أقصى وقت تنفيذ (ثانية)',
    'memory_limit'        => 'الذاكرة المتاحة',
    'max_input_vars'      => 'أقصى متغيرات Input',
    'date.timezone'       => 'المنطقة الزمنية',
    'session.save_path'   => 'مسار Sessions',
    'disable_functions'   => 'دوال معطلة',
    'open_basedir'        => 'open_basedir',
];

// ─── 5. الملفات والمجلدات ────────────────────
$paths = [
    '/'                   => 'جذر الموقع',
    '/uploads'            => 'مجلد uploads',
    '/assets'             => 'مجلد assets',
    '/assets/uploads'     => 'assets/uploads',
    '/includes'           => 'مجلد includes',
    '/includes/config.php'=> 'config.php',
    '/.htaccess'          => 'ملف .htaccess',
    '/admin'              => 'مجلد admin',
];

// ─── 6. Session Test ─────────────────────────
$sessionOk = false;
$sessionErr = '';
try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['diag_test'] = time();
    $sessionOk = isset($_SESSION['diag_test']);
} catch (Exception $e) {
    $sessionErr = $e->getMessage();
}

// ─── 7. cURL / اتصال خارجي ───────────────────
$curlOk  = false;
$curlErr = '';
if (extension_loaded('curl')) {
    $ch = curl_init('https://www.google.com');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_NOBODY         => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $curlCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $curlOk = ($curlCode >= 200 && $curlCode < 400);
}

// ─── 8. Error Log آخر 30 سطر ─────────────────
$logContent = '';
$logPaths = [
    ini_get('error_log'),
    __DIR__ . '/error_log',
    __DIR__ . '/../error_log',
    '/var/log/apache2/error.log',
    '/usr/local/apache/logs/error_log',
];
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp)) {
        $lines = file($lp);
        $last  = array_slice($lines, -30);
        $logContent = htmlspecialchars(implode('', $last));
        break;
    }
}

// ─── الإخراج ────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🔍 تشخيص الموقع</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Tahoma,Arial,sans-serif;background:#0d1117;color:#e6edf3;direction:rtl;padding:20px;font-size:14px}
  h1{text-align:center;color:#58a6ff;margin-bottom:6px;font-size:22px}
  .subtitle{text-align:center;color:#8b949e;margin-bottom:24px;font-size:12px}
  .warn{background:#3d1f00;border:1px solid #d29922;color:#e3b341;padding:12px 16px;border-radius:8px;margin-bottom:20px;text-align:center;font-weight:bold}
  .card{background:#161b22;border:1px solid #30363d;border-radius:10px;margin-bottom:18px;overflow:hidden}
  .card-header{background:#21262d;padding:12px 16px;font-weight:bold;font-size:15px;border-bottom:1px solid #30363d;display:flex;align-items:center;gap:8px}
  table{width:100%;border-collapse:collapse}
  td{padding:9px 14px;border-bottom:1px solid #21262d;vertical-align:top;word-break:break-all}
  td:first-child{color:#8b949e;width:40%;font-size:13px}
  tr:last-child td{border-bottom:none}
  .ok{color:#3fb950;font-weight:600}
  .fail{color:#f85149;font-weight:600}
  .warn-text{color:#e3b341;font-weight:600}
  .badge-ok{background:#0d4429;color:#3fb950;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:bold}
  .badge-fail{background:#490202;color:#f85149;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:bold}
  .badge-warn{background:#341a00;color:#e3b341;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:bold}
  .ext-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;padding:14px}
  .ext-item{background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:8px 12px;display:flex;justify-content:space-between;align-items:center;font-size:13px}
  pre{background:#0d1117;padding:14px;overflow-x:auto;font-size:11px;line-height:1.6;max-height:300px;overflow-y:auto;color:#8b949e;border-top:1px solid #30363d}
  .summary{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:20px}
  .sum-card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:14px;text-align:center}
  .sum-card .num{font-size:28px;font-weight:900;margin-bottom:4px}
  .sum-card .lbl{font-size:11px;color:#8b949e}
  .green .num{color:#3fb950}.red .num{color:#f85149}.blue .num{color:#58a6ff}.yellow .num{color:#e3b341}
  .delete-notice{background:#490202;border:1px solid #f85149;color:#f85149;padding:14px;border-radius:8px;text-align:center;margin-top:20px;font-size:13px}
</style>
</head>
<body>

<h1>🔍 تشخيص شامل للموقع</h1>
<p class="subtitle">تم التشغيل: <?= date('Y-m-d H:i:s') ?> — PHP <?= $phpVer ?></p>

<div class="warn">⚠️ تحذير: احذف هذا الملف فور الانتهاء من التشخيص — لأنه يكشف معلومات حساسة عن السيرفر</div>

<?php
// ─── ملخص سريع ─────────────────────────────
$extFails  = 0;
foreach ($exts as $k => $label) if (!extension_loaded($k)) $extFails++;
$pathFails = 0;
foreach ($paths as $rel => $label) if (!file_exists(__DIR__ . $rel) && !file_exists($rel)) $pathFails++;
?>

<div class="summary">
  <div class="sum-card <?= $phpOk ? 'green' : 'red' ?>">
    <div class="num"><?= $phpMajor . '.' . $phpMinor ?></div>
    <div class="lbl">إصدار PHP</div>
  </div>
  <div class="sum-card <?= $dbOk ? 'green' : 'red' ?>">
    <div class="num"><?= $dbOk ? '✅' : '❌' ?></div>
    <div class="lbl">قاعدة البيانات</div>
  </div>
  <div class="sum-card <?= $extFails === 0 ? 'green' : 'red' ?>">
    <div class="num"><?= $extFails ?></div>
    <div class="lbl">extensions مفقودة</div>
  </div>
  <div class="sum-card <?= $sessionOk ? 'green' : 'red' ?>">
    <div class="num"><?= $sessionOk ? '✅' : '❌' ?></div>
    <div class="lbl">Sessions</div>
  </div>
  <div class="sum-card <?= $curlOk ? 'green' : 'yellow' ?>">
    <div class="num"><?= $curlOk ? '✅' : '❌' ?></div>
    <div class="lbl">اتصال خارجي</div>
  </div>
  <div class="sum-card blue">
    <div class="num"><?= $dbTables ?></div>
    <div class="lbl">جداول DB</div>
  </div>
</div>

<!-- PHP -->
<div class="card">
  <div class="card-header">🐘 PHP</div>
  <table>
    <?= row('الإصدار', $phpVer . ($phpOk ? '' : ' — يُفضّل 8.0 أو أحدث'), $phpOk) ?>
    <?= row('المسار', PHP_BINARY ?: 'غير معروف') ?>
    <?= row('SAPI', PHP_SAPI) ?>
    <?= row('نظام التشغيل', PHP_OS . ' ' . php_uname('r')) ?>
    <?= row('المنطقة الزمنية', date_default_timezone_get()) ?>
  </table>
</div>

<!-- Database -->
<div class="card">
  <div class="card-header">🗄️ قاعدة البيانات</div>
  <table>
    <?= row('الاتصال', $dbOk ? 'ناجح ✅' : 'فشل ❌ — ' . htmlspecialchars($dbErr), $dbOk) ?>
    <?php if ($dbOk): ?>
    <?= row('إصدار MySQL', $dbVer) ?>
    <?= row('اسم القاعدة', $dbCreds['name']) ?>
    <?= row('المضيف', $dbCreds['host']) ?>
    <?= row('عدد الجداول', $dbTables . ' جدول') ?>
    <?php endif; ?>
    <?php if (!$dbCreds['user']): ?>
    <?= row('ملاحظة', 'أضف بيانات DB يدوياً في الكود إذا لزم', false) ?>
    <?php endif; ?>
  </table>
</div>

<!-- Extensions -->
<div class="card">
  <div class="card-header">🧩 PHP Extensions</div>
  <div class="ext-grid">
    <?php foreach ($exts as $k => $label):
        $loaded = extension_loaded($k); ?>
    <div class="ext-item">
      <span><?= $label ?></span>
      <span class="<?= $loaded ? 'badge-ok' : 'badge-fail' ?>"><?= $loaded ? 'يعمل' : 'مفقود' ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- PHP.ini -->
<div class="card">
  <div class="card-header">⚙️ إعدادات PHP.ini</div>
  <table>
    <?php foreach ($iniKeys as $k => $label):
      $val = ini_get($k) ?: '(غير محدد)';
      $ok  = true;
      if ($k === 'memory_limit') {
          $mb = (int)$val;
          $ok = $mb >= 128;
      }
      if ($k === 'disable_functions') $ok = (trim($val) === '');
    ?>
    <?= row($label . "<br><small style='color:#6e7681'>$k</small>", htmlspecialchars($val), $ok) ?>
    <?php endforeach; ?>
  </table>
</div>

<!-- ملفات ومجلدات -->
<div class="card">
  <div class="card-header">📁 الملفات والمجلدات</div>
  <table>
    <?php foreach ($paths as $rel => $label):
      $full   = __DIR__ . $rel;
      $exists = file_exists($full);
      $writable = $exists && is_writable($full);
      $perm   = $exists ? substr(sprintf('%o', fileperms($full)), -4) : '—';
      $status = $exists
        ? ($writable ? "موجود ✅ — صلاحيات: $perm (قابل للكتابة)" : "موجود ⚠️ — صلاحيات: $perm (للقراءة فقط)")
        : 'غير موجود ❌';
    ?>
    <?= row($label, $status, $exists) ?>
    <?php endforeach; ?>
    <tr><td>الجذر الكامل</td><td style="color:#8b949e;font-size:12px"><?= __DIR__ ?></td></tr>
  </table>
</div>

<!-- Sessions -->
<div class="card">
  <div class="card-header">🔐 Sessions & Cookies</div>
  <table>
    <?= row('Sessions', $sessionOk ? 'تعمل ✅' : 'لا تعمل ❌ — ' . htmlspecialchars($sessionErr), $sessionOk) ?>
    <?= row('مسار حفظ Sessions', ini_get('session.save_path') ?: 'افتراضي') ?>
    <?= row('Session handler', ini_get('session.save_handler')) ?>
    <?= row('مدة Session (ثانية)', ini_get('session.gc_maxlifetime')) ?>
  </table>
</div>

<!-- اتصال خارجي -->
<div class="card">
  <div class="card-header">🌐 الاتصال الخارجي</div>
  <table>
    <?= row('cURL', extension_loaded('curl') ? 'مفعّل ✅' : 'غير مفعّل ❌', extension_loaded('curl')) ?>
    <?= row('اتصال Google', $curlOk ? 'ناجح ✅' : 'فشل ❌ — ' . htmlspecialchars($curlErr), $curlOk) ?>
    <?= row('allow_url_fopen', ini_get('allow_url_fopen') ? 'مفعّل' : 'معطّل') ?>
    <?= row('OpenSSL', extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'غير مفعّل', extension_loaded('openssl')) ?>
  </table>
</div>

<!-- Server Info -->
<div class="card">
  <div class="card-header">🖥️ معلومات السيرفر</div>
  <table>
    <?= row('Web Server', $_SERVER['SERVER_SOFTWARE'] ?? 'غير معروف') ?>
    <?= row('اسم السيرفر', $_SERVER['SERVER_NAME'] ?? '—') ?>
    <?= row('IP السيرفر', $_SERVER['SERVER_ADDR'] ?? '—') ?>
    <?= row('IP الزائر', $_SERVER['REMOTE_ADDR'] ?? '—') ?>
    <?= row('بروتوكول', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'HTTPS ✅' : 'HTTP ⚠️') ?>
    <?= row('Document Root', $_SERVER['DOCUMENT_ROOT'] ?? '—') ?>
    <?php
      $total = disk_total_space('/');
      $free  = disk_free_space('/');
      $used  = $total - $free;
      $pct   = $total > 0 ? round(($used / $total) * 100) : 0;
      $diskOk = $pct < 90;
    ?>
    <?= row('المساحة المستخدمة', round($used/1073741824, 2) . ' GB / ' . round($total/1073741824, 2) . ' GB (' . $pct . '%)', $diskOk) ?>
  </table>
</div>

<!-- Error Log -->
<div class="card">
  <div class="card-header">📋 آخر أخطاء السيرفر (Error Log)</div>
  <?php if ($logContent): ?>
  <pre><?= $logContent ?></pre>
  <?php else: ?>
  <table><tr><td class="warn-text">⚠️ لم يتم العثور على Error Log أو لا صلاحية للقراءة.<br>
  تحقق من cPanel ← Logs ← Error Log يدوياً.</td></tr></table>
  <?php endif; ?>
</div>

<!-- phpinfo كامل -->
<div class="card">
  <div class="card-header">📄 phpinfo() — معلومات PHP الكاملة</div>
  <table><tr><td>
  <details>
    <summary style="cursor:pointer;color:#58a6ff;padding:8px 0">اضغط لعرض phpinfo() الكامل</summary>
    <?php ob_start(); phpinfo(); $pi = ob_get_clean();
      // تنظيف وعزل phpinfo
      $pi = preg_replace('/<a.*?<\/a>/i', '', $pi);
      $pi = preg_replace('/<style.*?<\/style>/si', '', $pi);
      $pi = preg_replace('/<head.*?<\/head>/si', '', $pi);
      $pi = str_replace(['<html>','</html>','<body>','</body>','<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">'], '', $pi);
      echo '<div style="font-size:11px;overflow-x:auto">' . $pi . '</div>';
    ?>
  </details>
  </td></tr></table>
</div>

<div class="delete-notice">
  🗑️ <strong>مهم جداً:</strong> احذف ملف <code>diag.php</code> من السيرفر فور الانتهاء من التشخيص
</div>

</body>
</html>
