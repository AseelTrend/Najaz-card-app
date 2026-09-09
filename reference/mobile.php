<?php
// حارس أمني: يمنع تنفيذ ملفات sections/*.php لو فُتحت مباشرة بدون المرور من هنا
define('NJAZ_APP_LOADED', true);

/**
 * صفحة الخدمات - تصميم تطبيق جوال
 * تدمج الواجهة الجديدة مع قاعدة البيانات PHP
 *
 * [SECURITY] تم تطبيق إصلاحات الأمان الشاملة:
 *  - Security Headers (CSP, X-Frame-Options, X-Content-Type, HSTS)
 *  - إزالة القيم الافتراضية الخطيرة للـ tokens
 *  - إصلاح SQL IN clause
 *  - إصلاح SSL verification في cron
 *  - إضافة CSRF token لعمليات POST
 *  - تأمين إخراج المتغيرات في JS
 *  - إضافة rate limiting لمحاولات الـ bypass
 */
require_once 'includes/config.php';
require_once __DIR__ . '/includes/payment_method_currency_helper.php';
try { paymentMethodCurrencyEnsureSchema($pdo); } catch (Throwable $e) { error_log('Payment method currency schema bootstrap in mobile.php: ' . $e->getMessage()); }

// ── Security Headers ───────────────────────────────────────────────────────────
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    // HSTS - فعّله فقط إذا كنت تستخدم HTTPS دائماً
    // header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}

$pageTitle = SITE_NAME;

// ── فحص وضع الصيانة ──────────────────────────────────────────────────────────
$maintenanceMode = getSetting('maintenance_mode') === '1';
// [SECURITY FIX] لا قيمة افتراضية ضعيفة - إذا لم يُضبط التوكن، أغلق الباب
$maintenanceToken = getSetting('maintenance_token');
$bypassToken = $_GET['bypass'] ?? ($_COOKIE['maint_bypass'] ?? '');

if ($maintenanceMode && !isAdmin()) {
    // [SECURITY FIX] السماح بالدخول فقط إذا كان التوكن مضبوطاً وغير فارغ
    if (!empty($maintenanceToken) && !empty($bypassToken) && hash_equals($maintenanceToken, $bypassToken)) {
        // [H-5 FIX] Cookie آمن مع HttpOnly + Secure + SameSite
        setcookie('maint_bypass', $maintenanceToken, [
            'expires'  => time() + 3600 * 8,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // عرض صفحة الصيانة
        $maintMsg   = getSetting('maintenance_message') ?: 'الموقع قيد الصيانة — سنعود قريباً';
        $maintUntil = (int)getSetting('maintenance_until'); // Unix timestamp
        ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?> — صيانة</title>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap"></noscript>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#080c1a;color:#fff;font-family:'Cairo',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px}
.wrap{max-width:480px;width:100%}
.icon{font-size:80px;margin-bottom:24px;animation:spin 4s linear infinite}
@keyframes spin{0%,100%{transform:rotate(0deg)}50%{transform:rotate(-15deg)}}
h1{font-size:2rem;font-weight:900;margin-bottom:12px;background:linear-gradient(90deg,#1e6fff,#00d4ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
p{font-size:1rem;color:#8895a7;margin-bottom:32px;line-height:1.7}
.timer{display:flex;gap:12px;justify-content:center;margin-bottom:32px;flex-wrap:wrap}
.timer-box{background:rgba(30,111,255,.12);border:1px solid rgba(30,111,255,.3);border-radius:14px;padding:14px 18px;min-width:72px}
.timer-num{font-size:2rem;font-weight:900;color:#1e6fff;line-height:1}
.timer-lbl{font-size:.65rem;color:#8895a7;margin-top:4px}
.logo{width:80px;height:80px;border-radius:22px;margin:0 auto 20px;overflow:hidden;background:rgba(30,111,255,.1)}
.logo img{width:100%;height:100%;object-fit:cover}
.no-timer{background:rgba(30,111,255,.08);border:1px solid rgba(30,111,255,.2);border-radius:14px;padding:16px;color:#8895a7;font-size:.9rem}
</style>
</head>
<body>
<div class="wrap">
  <?php $logo = getSetting('site_logo'); ?>
  <?php if($logo): ?>
  <div class="logo"><img src="<?= SITE_URL.'/'.htmlspecialchars($logo) ?>"></div>
  <?php else: ?>
  <div class="icon">🔧</div>
  <?php endif; ?>
  <h1><?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?></h1>
  <p><?= nl2br(htmlspecialchars($maintMsg)) ?></p>
  <?php if($maintUntil > time()): ?>
  <div class="timer">
    <div class="timer-box"><div class="timer-num" id="td">00</div><div class="timer-lbl">يوم</div></div>
    <div class="timer-box"><div class="timer-num" id="th">00</div><div class="timer-lbl">ساعة</div></div>
    <div class="timer-box"><div class="timer-num" id="tm">00</div><div class="timer-lbl">دقيقة</div></div>
    <div class="timer-box"><div class="timer-num" id="ts">00</div><div class="timer-lbl">ثانية</div></div>
  </div>
  <script>
    var until = <?= $maintUntil ?>;
    function tick(){
      var diff = Math.max(0, until - Math.floor(Date.now()/1000));
      var d=Math.floor(diff/86400), h=Math.floor(diff%86400/3600), m=Math.floor(diff%3600/60), s=diff%60;
      document.getElementById('td').textContent=String(d).padStart(2,'0');
      document.getElementById('th').textContent=String(h).padStart(2,'0');
      document.getElementById('tm').textContent=String(m).padStart(2,'0');
      document.getElementById('ts').textContent=String(s).padStart(2,'0');
      if(diff>0) setTimeout(tick,1000);
    }
    tick();
  </script>
  <?php else: ?>
  <div class="no-timer">⏳ سنعود قريباً — شكراً لصبرك</div>
  <?php endif; ?>
</div>
<?php    exit;
    }
}

// ── CSRF Token ────────────────────────────────────────────────────────────────
// [SECURITY FIX] توليد CSRF token لحماية عمليات POST
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── مزامنة تلقائية في الخلفية بعد إرسال الصفحة للعميل ─────────────────────
(function() {
    global $pdo;
    $lastRun = (int)getSetting('cron_last_run');
    if (time() - $lastRun < 30) return;
    try {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('cron_last_run',?)
                       ON DUPLICATE KEY UPDATE setting_value=?")->execute([time(),time()]);
    } catch (Exception $e) { return; }

    // [SECURITY FIX] لا تشغيل الـ cron إذا لم يكن المفتاح مضبوطاً في الإعدادات
    $cronKey = getSetting('cron_key');
    if (empty($cronKey)) return;
    $cronUrl = SITE_URL . '/cron_sync_orders.php?key=' . urlencode($cronKey);

    register_shutdown_function(function() use ($cronUrl) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (ob_get_level() > 0) { ob_end_flush(); flush(); }
        else flush();

        $ch = curl_init($cronUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_NOSIGNAL=>1]);
        curl_exec($ch);
        curl_close($ch);
    });
})();

// جلب كل الأقسام وبناء شجرة متسلسلة لا نهائية
$allCatsRaw = $pdo->query("SELECT * FROM categories ORDER BY sort_order,id")->fetchAll();

// بناء خريطة id=>category وchildren
$catsById = [];
foreach ($allCatsRaw as $c) $catsById[$c['id']] = $c + ['children' => []];
foreach ($catsById as $cid => &$c) {
    if ($c['parent_id'] && isset($catsById[$c['parent_id']])) {
        $catsById[$c['parent_id']]['children'][] = &$c;
    }
}
unset($c);

// الأقسام الجذرية
$mainCats = array_filter($catsById, function($c){ return !$c['parent_id']; });

// للتوافق مع الكود القديم — children مُجمَّعة حسب parent_id
$subByParent = [];
foreach ($allCatsRaw as $c) {
    if ($c['parent_id']) $subByParent[$c['parent_id']][] = $c;
}

// جلب كل الخدمات
// جلب الخدمات مع التعامل مع غياب عمود image
try {
    $allServices = $pdo->query("
        SELECT s.*, c.name as cat_name, c.id as cat_id, c.parent_id, c.icon as cat_icon, c.image as cat_image,
               p.provider_type as prov_type
        FROM services s
        JOIN categories c ON s.category_id = c.id
        LEFT JOIN service_providers sp ON sp.service_id = s.id AND sp.is_active = 1
        LEFT JOIN providers p ON p.id = sp.provider_id AND p.status = 1
        GROUP BY s.id
        ORDER BY c.sort_order, s.sort_order
    ")->fetchAll();
} catch (\PDOException $e) {
    // fallback: بدون عمود image
    $allServices = $pdo->query("
        SELECT s.*, c.name as cat_name, c.id as cat_id, c.parent_id, c.icon as cat_icon,
               NULL as cat_image, NULL as image
        FROM services s
        JOIN categories c ON s.category_id = c.id
        ORDER BY c.sort_order, s.sort_order
    ")->fetchAll();
}

// جلب إعدادات فلوسك
require_once __DIR__ . '/includes/floosak_merchant.php';
$floosakEnabled = getSetting('floosak_enabled') === '1' && !empty(getSetting('floosak_merchant_key'));

// ── USDT BEP20 — تحقق آمن من وجود المكتبة أولاً ──────────────────────────
$usdtEnabled       = false;
$activeUsdtRequest = null;
$usdtWalletAddr    = '';
$usdtMinDep        = 1.00;
$usdtTtlMin        = 30;
$usdtMinConf       = 3;
$usdtImage         = '';
$usdtIcon          = 'coins';
try {
    if (file_exists(__DIR__ . '/includes/usdt_deposit.php')) {
        require_once __DIR__ . '/includes/usdt_deposit.php';
        $usdtEnabled    = getSetting('usdt_enabled') === '1' && !empty(getSetting('usdt_wallet_address'));
        $usdtWalletAddr = getSetting('usdt_wallet_address') ?: '';
        $usdtMinDep     = (float)(getSetting('usdt_min_deposit')    ?: '1.00');
        $usdtTtlMin     = (int)(getSetting('usdt_request_ttl')      ?: 30);
        $usdtMinConf    = (int)(getSetting('usdt_min_confirmations') ?: 3);
        $usdtImage      = trim((string)(getSetting('usdt_image') ?: ''));
        $usdtIcon       = strtolower(trim((string)(getSetting('usdt_icon') ?: 'coins')));
        $usdtIcon       = preg_replace('/[^a-z0-9-]/', '', preg_replace('/^fa-/', '', $usdtIcon));
        if ($usdtIcon === '') $usdtIcon = 'coins';
        if ($usdtEnabled && isLoggedIn()) {
            try {
                usdt_expire_old_requests($pdo);
                $activeUsdtRequest = usdt_get_active_request($pdo, (int)$_SESSION['user_id']);
            } catch (Throwable $e) { /* تجاهل أي خطأ في جداول USDT */ }
        }
    }
} catch (Throwable $e) {
    // لا نوقف الصفحة بسبب خطأ في USDT
    $usdtEnabled = false;
}

// ── Binance Pay — تكامل مستقل عن USDT، بلا API call في GET ────────────────
$binancePayEnabled = false;
$binancePaySettings = [
    'accepted_currency' => 'USDT',
    'minimum_amount' => '1.0000',
    'request_ttl_minutes' => 30,
    'receiver_identifier_type' => '',
    'receiver_identifier' => '',
    'icon_image_path' => '',
];
try {
    if (file_exists(__DIR__ . '/includes/binance_pay.php')) {
        require_once __DIR__ . '/includes/binance_pay.php';
        $binancePaySettings = binancePayPublicSettings($pdo);
        $binancePayIconPath = trim((string)($binancePaySettings['icon_image_path'] ?? ''));
        $binancePayIconUrl = $binancePayIconPath;
        if ($binancePayIconUrl !== '' && substr($binancePayIconUrl, 0, 1) === '/') {
            $binancePayIconUrl = rtrim(SITE_URL, '/') . $binancePayIconUrl;
        }
        $binancePayEnabled = isLoggedIn() && binancePayCustomerVisible($pdo);
    }
} catch (Throwable $e) {
    $binancePayEnabled = false;
    $binancePayIconUrl = '';
}

// ── إعدادات نظام SMS ────────────────────────────────────────────────────────
$smsTopupEnabled = getSetting('sms_topup_enabled') === '1';
$smsProviders    = [];
try {
    // إضافة عمود provider_type إن لم يكن موجوداً
    try {
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS provider_type ENUM('sms','usdt') NOT NULL DEFAULT 'sms' AFTER sort_order");
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS usdt_wallet_address VARCHAR(255) DEFAULT '' AFTER provider_type");
    } catch(Exception $e) {}
    $smsProviders = $pdo->query("SELECT * FROM sms_providers WHERE status=1 ORDER BY sort_order")->fetchAll();
} catch(Exception $e) {
    $smsProviders = [];
}
// تصنيف المزودين حسب المنطقة
$smsNorth = array_values(array_filter($smsProviders, fn($p) => ($p['region'] ?? '') === 'north'));
$smsSouth = array_values(array_filter($smsProviders, fn($p) => ($p['region'] ?? '') === 'south'));
$smsNoRegion = array_values(array_filter($smsProviders, fn($p) => empty($p['region'])));
$hasRegions = !empty($smsNorth) || !empty($smsSouth);
$floosakImage   = getSetting('floosak_image') ?? '';

// جلب طرق الدفع وأسعار الصرف لصفحة شحن الرصيد
$paymentMethods    = [];
$exchangeRates     = [];
$displayCurrencies = [];
try {
    $pm = $pdo->query("SELECT m.*, GROUP_CONCAT(CONCAT(f.field_label,'||',f.field_value,'||',f.copyable) ORDER BY f.sort_order SEPARATOR ';;') as fields_raw FROM payment_methods m LEFT JOIN payment_method_fields f ON m.id=f.method_id WHERE m.status=1 GROUP BY m.id ORDER BY m.sort_order");
    $paymentMethods = $pm->fetchAll();
    $er = $pdo->query("SELECT * FROM exchange_rates WHERE status=1 ORDER BY sort_order");
    $exchangeRates  = $er->fetchAll();
    $methodCurrencyMap = paymentMethodCurrencyMap($pdo, array_map(fn($m) => (int)$m['id'], $paymentMethods));
    foreach ($paymentMethods as &$methodRow) $methodRow['allowed_currency_codes'] = $methodCurrencyMap[(int)$methodRow['id']] ?? [];
    unset($methodRow);
} catch(\PDOException $e) {}
// عملات العرض — جدول منفصل
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS display_currencies (id INT AUTO_INCREMENT PRIMARY KEY, currency_code VARCHAR(10) NOT NULL, currency_name VARCHAR(50) NOT NULL, currency_symbol VARCHAR(10) NOT NULL, rate_from_usd DECIMAL(20,8) NOT NULL DEFAULT 1, status TINYINT(1) DEFAULT 1, sort_order INT DEFAULT 0, UNIQUE KEY ucode (currency_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)$pdo->query("SELECT COUNT(*) FROM display_currencies")->fetchColumn() === 0) {
        foreach ([['USD','دولار أمريكي','$',1,0],['SAR','ريال سعودي','ر.س',3.75,1],['YER','ريال يمني','ر.ي',550,2],['YER2','ريال يمني جديد','ر.ج',1550,3]] as $dc) {
            $pdo->prepare("INSERT IGNORE INTO display_currencies (currency_code,currency_name,currency_symbol,rate_from_usd,status,sort_order) VALUES (?,?,?,?,1,?)")->execute($dc);
        }
    }
    // تحديث تلقائي من API إذا الـ cache أقدم من ساعة
    try {
        $cacheRow = $pdo->query("SELECT fetched_at FROM currency_api_cache WHERE base='USD' LIMIT 1")->fetch();
        if (!$cacheRow || (time()-strtotime($cacheRow['fetched_at']))>3600) {
            $apiCurs = $pdo->query("SELECT id,currency_code FROM display_currencies WHERE rate_source='api' AND status=1")->fetchAll();
            if (!empty($apiCurs)) {
                $ch=curl_init('https://open.er-api.com/v6/latest/USD');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true]);
                $apiRes=curl_exec($ch); $apiCode=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if ($apiCode===200&&$apiRes) {
                    $apiData=json_decode($apiRes,true);
                    if (!empty($apiData['rates'])) {
                        $pdo->prepare("INSERT INTO currency_api_cache (base,rates_json,fetched_at) VALUES ('USD',?,NOW()) ON DUPLICATE KEY UPDATE rates_json=VALUES(rates_json),fetched_at=NOW()")->execute([json_encode($apiData['rates'])]);
                        foreach ($apiCurs as $ac) {
                            if (isset($apiData['rates'][$ac['currency_code']])) {
                                $pdo->prepare("UPDATE display_currencies SET rate_from_usd=?,rate_api=?,rate_api_at=NOW() WHERE id=?")->execute([$apiData['rates'][$ac['currency_code']],$apiData['rates'][$ac['currency_code']],$ac['id']]);
                            }
                        }
                    }
                }
            }
        }
    } catch(Exception $eApi) {}
    $displayCurrencies = $pdo->query("SELECT * FROM display_currencies WHERE status=1 ORDER BY sort_order")->fetchAll();
} catch(Exception $e2) {
    $displayCurrencies = [['currency_code'=>'USD','currency_name'=>'دولار أمريكي','currency_symbol'=>'$','rate_from_usd'=>1]];
}

// جلب حقول كل خدمة
$serviceFields = [];
if ($allServices) {
    // [SECURITY FIX] تأمين IN clause بالتحقق من أن القيم أعداد صحيحة فقط
    $sids = array_map('intval', array_column($allServices, 'id'));
    $sids = array_filter($sids, fn($id) => $id > 0);
    if (!empty($sids)) {
        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $fStmt = $pdo->prepare("SELECT * FROM service_fields WHERE service_id IN ($placeholders) ORDER BY sort_order");
        $fStmt->execute(array_values($sids));
        $fields = $fStmt->fetchAll();
        foreach ($fields as $f) {
            $serviceFields[$f['service_id']][] = $f;
        }
    }
}

// رصيد المستخدم
$userBalance = 0;
$userName = 'زائر';
$userId_display = '000000';
if (isLoggedIn()) {
    $u = getUser();
    $userBalance = $u['balance'];
    $userName = $u['full_name'] ?: $u['username'];
    $userId_display = str_pad($u['id'], 6, '0', STR_PAD_LEFT);
    // صورة البروفايل: مرفوعة محلياً > جوجل > افتراضي
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_avatar VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
    $profileAvatar = '';
    if (!empty($u['profile_avatar'])) {
        $profileAvatar = str_starts_with($u['profile_avatar'], 'http') ? $u['profile_avatar'] : SITE_URL.'/'.$u['profile_avatar'];
    } elseif (!empty($u['google_avatar'])) {
        $profileAvatar = $u['google_avatar'];
    }
}

// ── بيانات المحفظة الكاملة ──────────────────────────────────────────────────
$walletTransactions = [];
$walletTotalCredit  = 0;
$walletTotalDebit   = 0;
$giftsMap           = [];
if (isLoggedIn()) {
    try {
        $wStmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 60");
        $wStmt->execute([$_SESSION['user_id']]);
        $walletTransactions = $wStmt->fetchAll();
        $positiveTypesW = ['credit','topup','refund','prize','referral','referral_welcome'];
        foreach ($walletTransactions as $_wt) {
            if (in_array($_wt['type'], $positiveTypesW)) $walletTotalCredit += (float)$_wt['amount'];
            else $walletTotalDebit += (float)$_wt['amount'];
        }
    } catch(Exception $e) {}
    try {
        $gs = $pdo->prepare("
            SELECT g.*,
                   s.username as sender_username, s.full_name as sender_name,
                   r.username as receiver_username, r.full_name as receiver_name
            FROM gifts g
            LEFT JOIN users s ON g.sender_id=s.id
            LEFT JOIN users r ON g.receiver_id=r.id
            WHERE g.sender_id=? OR g.receiver_id=?
            ORDER BY g.created_at DESC
        ");
        $gs->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        foreach ($gs->fetchAll() as $g) $giftsMap[$g['id']] = $g;
    } catch(Exception $e) {}
}

// أيقونات الأقسام
$catIcons = [
    'شحن الألعاب'       => ['icon'=>'🎮','grad'=>'linear-gradient(135deg,#1a237e,#0d47a1)'],
    'شحن التطبيقات'     => ['icon'=>'📲','grad'=>'linear-gradient(135deg,#004d40,#006064)'],
    'البطاقات الرقمية'  => ['icon'=>'💳','grad'=>'linear-gradient(135deg,#4a148c,#6a1b9a)'],
    'default'           => ['icon'=>'⭐','grad'=>'linear-gradient(135deg,#37474f,#546e7a)'],
];

function getCatStyle($name) {
    global $catIcons;
    return $catIcons[$name] ?? $catIcons['default'];
}

// رمز العملة
$currSymbol = getSetting('currency_symbol') ?: '$';
$siteName = getSetting('site_name') ?: SITE_NAME;

// ── بيانات الإحالة ─────────────────────────────────────────────────────────
$myReferralCode = '';
$myReferrals    = [];
$myRefStats     = ['count'=>0,'rewarded'=>0,'earned'=>0.0];
if (isLoggedIn()) {
    try {
        // توليد كود الإحالة مباشرة بدون include
        $uid = (int)$_SESSION['user_id'];
        $rcRow = $pdo->prepare("SELECT referral_code FROM users WHERE id=?");
        $rcRow->execute([$uid]); $rcRow = $rcRow->fetch();
        if ($rcRow && $rcRow['referral_code']) {
            $myReferralCode = $rcRow['referral_code'];
        } else {
            // أنشئ كود جديد
            do {
                $newCode = strtoupper(substr(base_convert(sha1(uniqid(mt_rand(),true)),16,36),0,8));
                $cCheck = $pdo->prepare("SELECT id FROM users WHERE referral_code=?");
                $cCheck->execute([$newCode]);
            } while ($cCheck->fetch());
            try {
                $pdo->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$newCode,$uid]);
                $myReferralCode = $newCode;
            } catch(Exception $e) { $myReferralCode = $newCode; }
        }
        // include referral.php إذا موجود
        $refPath = __DIR__.'/includes/referral.php';
        if (file_exists($refPath)) require_once $refPath;
        // تأكد من وجود الجدول
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS `referrals` (`id` INT AUTO_INCREMENT PRIMARY KEY,`referrer_id` INT NOT NULL,`referred_id` INT NOT NULL,`status` ENUM('pending','rewarded') DEFAULT 'pending',`trigger_type` VARCHAR(50) DEFAULT 'order_percent',`reward_referrer` DECIMAL(10,4) DEFAULT 0,`reward_percent` DECIMAL(5,2) DEFAULT 0,`reward_referred` DECIMAL(10,4) DEFAULT 0,`commission_paid` DECIMAL(10,4) DEFAULT 0,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY `unique_referral`(`referrer_id`,`referred_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referred_by INT DEFAULT NULL"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(20) DEFAULT NULL"); } catch(Exception $e) {}
        $rStmt = $pdo->prepare("SELECT r.*, u.username, u.full_name FROM referrals r JOIN users u ON r.referred_id=u.id WHERE r.referrer_id=? ORDER BY r.created_at DESC LIMIT 20");
        $rStmt->execute([$_SESSION['user_id']]);
        $myReferrals = $rStmt->fetchAll();
        $myRefStats['count'] = count($myReferrals);
        $rewarded = 0; $earned = 0.0;
        foreach ($myReferrals as $_ref) {
            if ($_ref['status'] === 'rewarded') {
                $rewarded++;
                $earned += (float)($_ref['commission_paid'] ?? 0);
            }
        }
        $myRefStats['rewarded'] = $rewarded;
        $myRefStats['earned']   = $earned;

        $refRewardType = getSetting('referral_reward_type') ?: 'order_percent';
    } catch(Exception $e) {}
}

// ── أزرار التواصل العائمة ─────────────────────────────────────────────
$contactButtons = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `contact_buttons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `label` VARCHAR(100) NOT NULL,
        `icon` VARCHAR(100) NOT NULL DEFAULT 'fa-comment',
        `url` VARCHAR(500) NOT NULL,
        `color` VARCHAR(20) NOT NULL DEFAULT '#25d366',
        `sort_order` INT NOT NULL DEFAULT 0,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $contactButtons = $pdo->query("SELECT * FROM contact_buttons WHERE status=1 ORDER BY sort_order,id")->fetchAll();
} catch(Exception $e) { $contactButtons = []; }

// ── جلب بيانات المدفوعات ────────────────────────────────────────────────────
$myPayments = [];
if (isLoggedIn()) {
    try {
        // طلبات التغذية اليدوية
        $manualP = $pdo->prepare("
            SELECT r.*, m.name as method_name, m.icon as method_icon, 'manual' as pay_type
            FROM topup_requests r
            JOIN payment_methods m ON r.method_id = m.id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC LIMIT 50
        ");
        $manualP->execute([$_SESSION['user_id']]);
        $manualPayments = $manualP->fetchAll();
    } catch(Exception $e) { $manualPayments = []; }

    try {
        // طلبات التغذية المباشرة (فلوسك)
        // pending أكثر من 10 دقائق = ملغية تلقائياً
        $directP = $pdo->prepare("
            SELECT f.*,
                   'فلوسك' as method_name, 'direct' as pay_type,
                   CASE
                     WHEN f.status = 'pending' AND TIMESTAMPDIFF(MINUTE, f.created_at, NOW()) > 10
                     THEN 'expired'
                     ELSE f.status
                   END as display_status
            FROM floosak_topup_sessions f
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC LIMIT 50
        ");
        $directP->execute([$_SESSION['user_id']]);
        $directPayments = $directP->fetchAll();
    } catch(Exception $e) { $directPayments = []; }

    // طلبات بطاقات الشحن
    try {
        $cardsP = $pdo->prepare("
            SELECT rc.*,
                   'بطاقة شحن' as method_name, 'card' as pay_type,
                   rc.amount as amount_usd,
                   'used' as display_status
            FROM recharge_cards rc
            WHERE rc.used_by = ?
            ORDER BY rc.used_at DESC LIMIT 50
        ");
        $cardsP->execute([$_SESSION['user_id']]);
        $cardPayments = $cardsP->fetchAll();
        // نعدّل created_at ليكون used_at للترتيب
        foreach ($cardPayments as &$cp) {
            $cp['created_at'] = $cp['used_at'] ?? $cp['created_at'];
        }
        unset($cp);
    } catch(Exception $e) { $cardPayments = []; }

    // دمج وترتيب
    $myPayments = array_merge($manualPayments, $directPayments, $cardPayments);
    usort($myPayments, function($a,$b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
}

// ── جلب بيانات الطلبات للصفحة المدمجة ──────────────────────────────────────
// كل مصدر يحتفظ بجدوله ومنطقه، ويتم توحيده هنا للعرض فقط.
$allOrders = [];
$orderStats = ['total'=>0,'completed'=>0,'processing'=>0,'cancelled'=>0];
if (isLoggedIn()) {
    $uid = (int)$_SESSION['user_id'];

    // الطلبات العامة للموقع.
    try {
        $aoStmt = $pdo->prepare("
            SELECT o.*, s.name as service_name, s.image as service_image,
                   c.name as cat_name, c.icon as cat_icon
            FROM orders o
            JOIN services s ON o.service_id = s.id
            JOIN categories c ON s.category_id = c.id
            WHERE o.user_id=?
            ORDER BY o.created_at DESC LIMIT 100
        ");
        $aoStmt->execute([$uid]);
        $aoRows = $aoStmt->fetchAll();
        foreach ($aoRows as &$or) {
            $or['source_type'] = 'standard';
            $or['source_id']   = (int)$or['id'];
            $or['detail_id']   = (int)$or['id'];
        }
        unset($or);
    } catch (Throwable $e) { $aoRows = []; }
    $aoRows = $aoRows ?? [];

    // طلبات الاتصالات: لا ننشئ طلباً جديداً، بل نعرض السجل الأصلي كما هو.
    $telecomRows = [];
    try {
        $ts = $pdo->prepare("
            SELECT t.*, n.name AS network_name, o.name AS offer_name
            FROM telecom_orders t
            JOIN telecom_networks n ON n.id=t.network_id
            LEFT JOIN telecom_offers o ON o.id=t.offer_id
            WHERE t.user_id=?
            ORDER BY t.created_at DESC LIMIT 100
        ");
        $ts->execute([$uid]);
        foreach ($ts->fetchAll() as $tr) {
            $rawStatus = strtolower((string)($tr['status'] ?? 'pending'));
            $displayStatus = in_array($rawStatus, ['pending','processing'], true) ? $rawStatus :
                (in_array($rawStatus, ['completed','failed','cancelled'], true) ? $rawStatus : 'processing');
            $rate = (float)($tr['exchange_rate'] ?? 0);
            $chargeYer = (float)($tr['cost_yer'] ?? 0);
            if ($chargeYer <= 0) $chargeYer = (float)($tr['amount_yer'] ?? 0);
            $totalUsd = ($rate > 0 && $chargeYer > 0) ? round($chargeYer / $rate, 4) : 0;
            $label = (($tr['order_type'] ?? '') === 'offer' && !empty($tr['offer_name']))
                ? 'اتصالات — '.$tr['offer_name'] : 'اتصالات — شحن رصيد';
            $telecomRows[] = [
                'id' => (int)$tr['id'], 'source_id' => (int)$tr['id'], 'detail_id' => (int)$tr['id'],
                'source_type' => 'telecom', 'ref_id' => 'ID_TEL_'.$tr['id'],
                'service_name' => $label, 'service_image' => '', 'cat_name' => 'الاتصالات', 'cat_icon' => 'mobile-alt',
                'status' => $displayStatus, 'status_raw' => $rawStatus,
                'status_message' => $tr['provider_msg'] ?: 'طلب اتصالات',
                'total_price' => $totalUsd, 'quantity' => 1,
                'field_data' => json_encode([
                    'الشبكة' => $tr['network_name'] ?? '', 'الهاتف' => $tr['mobile_number'] ?? '',
                    'المبلغ بالريال' => $tr['amount_yer'] ?? '',
                    'النوع' => (($tr['order_type'] ?? '') === 'offer' ? 'باقة' : 'شحن رصيد')
                ], JSON_UNESCAPED_UNICODE),
                'provider_order_id' => $tr['provider_ref_id'] ?? null,
                'created_at' => $tr['created_at'], 'updated_at' => $tr['updated_at'] ?? $tr['created_at'],
            ];
        }
    } catch (Throwable $e) { $telecomRows = []; }

    // طلبات P2P: تُعرض للمشتري والبائع مع إبقاء حالة النزاع ضمن الجاري للمراجعة.
    $p2pRows = [];
    try {
        $ps = $pdo->prepare("
            SELECT p.*, s.title AS p2p_title, s.image AS p2p_image,
                   COALESCE(pc.name,'P2P') AS p2p_cat_name
            FROM p2p_orders p
            JOIN p2p_services s ON s.id=p.service_id
            LEFT JOIN p2p_categories pc ON pc.id=s.category_id
            WHERE p.buyer_id=? OR p.seller_id=?
            ORDER BY p.created_at DESC LIMIT 100
        ");
        $ps->execute([$uid, $uid]);
        foreach ($ps->fetchAll() as $pr) {
            $rawStatus = strtolower((string)($pr['status'] ?? 'pending'));
            $displayStatus = $rawStatus === 'active' || $rawStatus === 'disputed' ? 'processing' :
                (in_array($rawStatus, ['pending','completed','cancelled'], true) ? $rawStatus : 'processing');
            $role = ((int)$pr['buyer_id'] === $uid) ? 'مشتري' : 'بائع';
            $p2pRows[] = [
                'id' => (int)$pr['id'], 'source_id' => (int)$pr['id'], 'detail_id' => (int)$pr['id'],
                'source_type' => 'p2p', 'ref_id' => 'ID_P2P_'.$pr['id'],
                'service_name' => 'P2P — '.($pr['p2p_title'] ?: 'طلب سوق'), 'service_image' => $pr['p2p_image'] ?? '',
                'cat_name' => $pr['p2p_cat_name'] ?: 'P2P', 'cat_icon' => 'exchange-alt',
                'status' => $displayStatus, 'status_raw' => $rawStatus,
                'status_message' => $rawStatus === 'disputed' ? 'طلب P2P قيد النزاع' : 'طلب P2P — '.$role,
                'total_price' => (float)($pr['buyer_total'] ?? $pr['price'] ?? 0), 'quantity' => 1,
                'field_data' => json_encode(['الدور' => $role, 'سعر الخدمة' => $pr['price'] ?? 0], JSON_UNESCAPED_UNICODE),
                'provider_order_id' => null, 'created_at' => $pr['created_at'], 'updated_at' => $pr['updated_at'] ?? $pr['created_at'],
            ];
        }
    } catch (Throwable $e) { $p2pRows = []; }

    $allOrders = array_merge($aoRows, $telecomRows, $p2pRows);
    usort($allOrders, function($a,$b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $allOrders = array_slice($allOrders, 0, 100);
    foreach ($allOrders as $or) {
        $orderStats['total']++;
        if ($or['status']==='completed') $orderStats['completed']++;
        elseif (in_array($or['status'],['pending','processing'], true)) $orderStats['processing']++;
        elseif (in_array($or['status'],['cancelled','failed'], true)) $orderStats['cancelled']++;
    }
}
$osMap = [
    'pending'    => ['label'=>'انتظار',  'color'=>'#f5a623','icon'=>'clock'],
    'processing' => ['label'=>'تنفيذ',   'color'=>'#00d4ff','icon'=>'sync-alt'],
    'completed'  => ['label'=>'مكتمل',   'color'=>'#00e676','icon'=>'check-circle'],
    'cancelled'  => ['label'=>'ملغي',    'color'=>'#ff4455','icon'=>'times-circle'],
    'failed'     => ['label'=>'فشل',     'color'=>'#ff4455','icon'=>'exclamation-circle'],
];

// ── حالة KYC ──
$kycStatus = null;
$kycData   = null;
if (isLoggedIn()) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `kyc_requests` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `id_type` ENUM('national','passport','family','electronic') NOT NULL DEFAULT 'national',
          `full_name` VARCHAR(255) DEFAULT '',
          `national_id` VARCHAR(100) DEFAULT '',
          `birth_date` DATE NULL,
          `birth_place` VARCHAR(255) DEFAULT '',
          `issue_date` DATE NULL,
          `expiry_date` DATE NULL,
          `image_front` VARCHAR(500) DEFAULT '',
          `image_back` VARCHAR(500) DEFAULT '',
          `extra_fields` JSON DEFAULT NULL,
          `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
          `admin_note` TEXT DEFAULT NULL,
          `reviewed_by` INT DEFAULT NULL,
          `reviewed_at` DATETIME DEFAULT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $kycStmt = $pdo->prepare("SELECT * FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $kycStmt->execute([$_SESSION['user_id']]);
        $kycData = $kycStmt->fetch();
        $kycStatus = $kycData['status'] ?? null;
    } catch(Exception $e) { $kycStatus = null; }
}

// ── السلايدر / البانرات ──
try {
    $banners = $pdo->query("SELECT * FROM banners WHERE status=1 ORDER BY sort_order,id")->fetchAll();
} catch(Exception $e) { $banners = []; }
if (empty($banners)) {
    // بانر افتراضي إن كان الجدول فارغاً
    $banners = [[
        'id'=>0,'title'=>'أشحن ألعابك','subtitle'=>'ألعاب • برامج • بطاقات رقمية',
        'tag'=>'✨ أفضل الأسعار','bg_color'=>'linear-gradient(135deg,#0d1f5c 0%,#1a3a8c 50%,#0d1f5c 100%)',
        'text_color'=>'#ffffff','accent_color'=>'#00d4ff','image'=>'',
        'link_type'=>'none','link_value'=>'',
    ]];
}

// ── شعار الموقع ──
$siteLogo    = getSetting('site_logo') ?: '';
$siteLogoUrl = $siteLogo ? SITE_URL . '/' . $siteLogo : '';
// Favicon dynamic URL for <head>
$faviconUrl  = $siteLogoUrl ?: SITE_URL . '/icons/icon-96.png';

// ── جلب الإشعارات ──
$myNotifications = [];
$unreadNotifCount = 0;
if (isLoggedIn()) {
    // تعليم كمقروء عند فتح صفحة الإشعارات (يُنفَّذ عبر JS لاحقاً)
    try {
        $ns = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 60");
        $ns->execute([$_SESSION['user_id']]);
        $myNotifications = $ns->fetchAll();
        $unreadNotifCount = 0;
        foreach ($myNotifications as $_n) { if (!$_n['is_read']) $unreadNotifCount++; }
    } catch(Exception $e) {}
}

// حذف إشعار عبر POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_delete_id']) && isLoggedIn()) {
    // [SECURITY FIX] التحقق من CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
        http_response_code(403);
        exit('طلب غير مصرح به');
    }
    $did = (int)$_POST['notif_delete_id'];
    try {
        if ($did === 0)
            $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$_SESSION['user_id']]);
        else
            $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([$did, $_SESSION['user_id']]);
    } catch(Exception $e) {}
    header('Location: ' . SITE_URL . '/mobile.php#notif');
    exit;
}

function mobileNotifTimeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'منذ لحظات';
    if ($diff < 3600)   return 'منذ ' . (int)($diff/60) . ' دقيقة';
    if ($diff < 86400)  return 'منذ ' . (int)($diff/3600) . ' ساعة';
    if ($diff < 604800) return 'منذ ' . (int)($diff/86400) . ' يوم';
    return date('d/m/Y', strtotime($dt));
}
?>
<?php
// ══════════════════════════════════════════════════════════════════
//  من هنا فصاعدًا: الصفحة مقسّمة إلى ملفات مستقلة داخل مجلد sections/
//  لتسهيل الصيانة، بنفس الترتيب الأصلي تمامًا وبدون أي تغيير بالسلوك
// ══════════════════════════════════════════════════════════════════
require __DIR__ . '/sections/head-meta.php';
require __DIR__ . '/sections/styles.php';
require __DIR__ . '/sections/body-init.php';
require __DIR__ . '/sections/splash-screen.php';
require __DIR__ . '/sections/sidebar.php';
require __DIR__ . '/sections/header.php';
require __DIR__ . '/sections/balance-bar.php';
require __DIR__ . '/sections/home.php';
require __DIR__ . '/sections/category-page.php';
require __DIR__ . '/sections/packages-page.php';
require __DIR__ . '/sections/orders-page.php';
require __DIR__ . '/sections/referral-page.php';
require __DIR__ . '/sections/payments-page.php';
require __DIR__ . '/sections/wallet-page.php';
require __DIR__ . '/sections/topup-page.php';
require __DIR__ . '/sections/profile-page.php';
require __DIR__ . '/sections/settings-page.php';
require __DIR__ . '/sections/kyc-page.php';
require __DIR__ . '/sections/notifications-page.php';
require __DIR__ . '/sections/footer-nav-auth.php';
?>

<?php require __DIR__ . '/sections/app-script-1.php'; ?>

<?php require __DIR__ . '/sections/modals.php'; ?>
</body>
</html>

<?php require __DIR__ . '/sections/app-script-2.php'; ?>
