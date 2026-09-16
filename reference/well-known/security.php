<?php
require_once 'includes/config.php';
require_once 'includes/totp.php';
requireLogin();

$siteName = getSetting('site_name') ?: SITE_NAME;
$userId   = $_SESSION['user_id'];

// جلب بيانات المستخدم
$u = $pdo->prepare("SELECT * FROM users WHERE id=?");
$u->execute([$userId]); $user = $u->fetch();

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$msgType = '';

// ── توليد secret جديد ──────────────────────────────────────────────────────────
if ($action === 'generate') {
    $secret = TOTP::generateSecret();
    $_SESSION['totp_pending_secret'] = $secret;
    header('Content-Type: application/json');
    echo json_encode([
        'ok'     => true,
        'secret' => $secret,
        'qr'     => TOTP::getQrUrl($secret, $user['username'], $siteName),
    ]);
    exit;
}

// ── تفعيل 2FA (تحقق من الكود أولاً) ──────────────────────────────────────────
if ($action === 'enable') {
    $code   = preg_replace('/\s/','',$_POST['code']??'');
    $secret = $_SESSION['totp_pending_secret'] ?? '';
    if (!$secret) { $message='انتهت صلاحية الجلسة، أعد المحاولة'; $msgType='error'; }
    elseif (!TOTP::verify($secret, $code)) { $message='الكود غير صحيح، تحقق من التطبيق وأعد المحاولة'; $msgType='error'; }
    else {
        $pdo->prepare("UPDATE users SET totp_secret=?, totp_enabled=1, totp_verified_at=NOW() WHERE id=?")
            ->execute([$secret, $userId]);
        unset($_SESSION['totp_pending_secret']);
        $message='✅ تم تفعيل المصادقة الثنائية بنجاح! حسابك أكثر أماناً الآن.';
        $msgType='success';
        // reload user
        $u->execute([$userId]); $user = $u->fetch();
    }
}

// ── تعطيل 2FA ────────────────────────────────────────────────────────────────
if ($action === 'disable') {
    $code    = preg_replace('/\s/','',$_POST['code']??'');
    $confirm = $_POST['confirm_password'] ?? '';
    if (!password_verify($confirm, $user['password'])) {
        $message='كلمة المرور غير صحيحة'; $msgType='error';
    } elseif ($user['totp_enabled'] && !TOTP::verify($user['totp_secret'], $code)) {
        $message='كود المصادقة غير صحيح'; $msgType='error';
    } else {
        $pdo->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0 WHERE id=?")->execute([$userId]);
        $message='تم تعطيل المصادقة الثنائية'; $msgType='info';
        $u->execute([$userId]); $user = $u->fetch();
    }
}

$enabled = (bool)($user['totp_enabled'] ?? false);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#080c1a">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>حماية الحساب — <?= htmlspecialchars($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;--border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);--primary:#1e6fff;--cyan:#00d4ff;--green:#00e676;--red:#ff4455;--gold:#f5a623;--text:#fff;--text2:#8fa3bf;--text3:#4d6080;--font:'Cairo',sans-serif}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);direction:rtl;min-height:100vh}
a{text-decoration:none;color:inherit}
#app{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg)}

.top-header{background:linear-gradient(135deg,#0d1428,#0a1535);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:58px;position:sticky;top:0;z-index:100}
.logo-wrap{display:flex;align-items:center;gap:8px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;box-shadow:0 4px 12px rgba(30,111,255,.4)}
.logo-text{font-size:17px;font-weight:900;background:linear-gradient(90deg,#fff,var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hbtn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:16px;text-decoration:none}

/* Hero */
.page-hero{padding:28px 20px 24px;text-align:center;border-bottom:1px solid var(--border);position:relative;overflow:hidden}
.page-hero-bg{position:absolute;inset:0;background:linear-gradient(135deg,<?= $enabled ? '#001a0a' : '#0d1428' ?>,#080c1a);z-index:0}
.page-hero-glow{position:absolute;top:-60px;right:50%;transform:translateX(50%);width:200px;height:200px;background:radial-gradient(circle,<?= $enabled ? 'rgba(0,230,118,.15)' : 'rgba(30,111,255,.15)' ?> 0%,transparent 70%);pointer-events:none;z-index:0}
.hero-icon{width:72px;height:72px;border-radius:22px;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 14px;position:relative;z-index:1;transition:.3s;<?= $enabled ? 'background:linear-gradient(135deg,rgba(0,230,118,.2),rgba(0,212,170,.1));border:2px solid rgba(0,230,118,.35)' : 'background:linear-gradient(135deg,rgba(30,111,255,.2),rgba(0,212,255,.1));border:2px solid rgba(30,111,255,.3)' ?>}
.hero-title{font-size:1.2rem;font-weight:900;position:relative;z-index:1;margin-bottom:6px}
.hero-sub{font-size:.82rem;color:var(--text2);position:relative;z-index:1}

/* Status badge */
.status-banner{margin:14px 16px 0;border-radius:14px;padding:12px 16px;display:flex;align-items:center;gap:12px;<?= $enabled ? 'background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.2)' : 'background:rgba(255,68,85,.08);border:1px solid rgba(255,68,85,.18)' ?>}
.status-dot{width:10px;height:10px;border-radius:50%;animation:pulse2fa 2s infinite;flex-shrink:0;<?= $enabled ? 'background:#00e676;box-shadow:0 0 8px #00e676' : 'background:#ff4455;box-shadow:0 0 8px #ff4455' ?>}
@keyframes pulse2fa{0%,100%{opacity:1}50%{opacity:.5}}
.status-text{font-size:.9rem;font-weight:800;<?= $enabled ? 'color:#00e676' : 'color:#ff4455' ?>}
.status-sub{font-size:.73rem;color:var(--text2);margin-top:2px}

/* Message */
.msg-box{margin:12px 16px 0;border-radius:12px;padding:12px 14px;font-size:.85rem;font-weight:700;display:flex;align-items:flex-start;gap:8px}
.msg-box.success{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.25);color:#00e676}
.msg-box.error{background:rgba(255,68,85,.1);border:1px solid rgba(255,68,85,.25);color:#ff4455}
.msg-box.info{background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);color:var(--cyan)}

/* Cards */
.section{padding:14px 16px 0}
.sec-title{font-size:.72rem;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;display:flex;align-items:center;gap:5px}
.info-card{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px}
.info-card-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.info-card-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.info-card-title{font-weight:800;font-size:.9rem}
.info-card-sub{font-size:.73rem;color:var(--text2);margin-top:2px}
.steps-list{display:flex;flex-direction:column;gap:8px}
.step-item{display:flex;align-items:center;gap:10px;font-size:.82rem;color:var(--text2)}
.step-num{width:24px;height:24px;border-radius:50%;background:rgba(30,111,255,.15);color:var(--primary);font-weight:900;font-size:.72rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}

/* Setup wizard */
.setup-card{background:var(--card2);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;margin-bottom:12px}
.setup-step{padding:16px;border-bottom:1px solid var(--border)}
.setup-step:last-child{border-bottom:none}
.setup-step-head{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.setup-step-num{width:28px;height:28px;border-radius:50%;background:var(--primary);color:#fff;font-weight:900;font-size:.78rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.setup-step-title{font-weight:800;font-size:.9rem}

.qr-wrap{text-align:center;padding:14px 0}
.qr-img{width:160px;height:160px;border-radius:12px;background:var(--card);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;overflow:hidden}
.qr-img img{width:100%;height:100%;border-radius:10px}
.qr-loader{color:var(--text2);font-size:.85rem;display:flex;flex-direction:column;align-items:center;gap:8px}

.secret-box{background:var(--bg);border:1px solid var(--border2);border-radius:10px;padding:12px 14px;font-family:monospace;font-size:.9rem;color:var(--cyan);letter-spacing:2px;text-align:center;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:.15s;word-break:break-all}
.secret-box:active{background:rgba(0,212,255,.05)}
.secret-copied{font-size:.7rem;color:#00e676;margin-top:4px;text-align:center;display:none}

/* Inputs */
.inp-group{margin-bottom:12px}
.inp-label{font-size:.75rem;color:var(--text2);font-weight:700;margin-bottom:6px;display:block}
.inp{width:100%;background:var(--bg);border:1.5px solid var(--border2);border-radius:10px;padding:11px 14px;color:#fff;font-family:var(--font);font-size:.92rem;outline:none;transition:.2s;text-align:center;letter-spacing:4px;font-weight:800}
.inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(30,111,255,.12)}
.inp.normal{letter-spacing:normal;text-align:right;font-weight:400}

/* Buttons */
.btn{display:flex;align-items:center;justify-content:center;gap:7px;width:100%;padding:13px;border:none;border-radius:12px;font-family:var(--font);font-size:.92rem;font-weight:800;cursor:pointer;transition:.2s}
.btn-primary{background:linear-gradient(135deg,var(--primary),#3b82f6);color:#fff;box-shadow:0 4px 14px rgba(30,111,255,.3)}
.btn-primary:active{transform:scale(.98)}
.btn-danger{background:linear-gradient(135deg,#7f1d1d,#450a0a);color:#ff4455;border:1px solid rgba(255,68,85,.25)}
.btn-secondary{background:var(--card2);border:1px solid var(--border);color:var(--text2)}
.btn-sm{padding:8px 16px;width:auto;font-size:.82rem;border-radius:10px}
.btn:disabled{opacity:.5;pointer-events:none}
.spinner{width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* OTP input styling */
.otp-row{display:flex;gap:8px;justify-content:center;margin-bottom:8px}
.otp-digit{width:44px;height:54px;background:var(--bg);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s;caret-color:var(--primary)}
.otp-digit:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(30,111,255,.12)}

/* App store links */
.app-links{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.app-link{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:9px 12px;display:flex;align-items:center;gap:8px;text-decoration:none;transition:.15s}
.app-link:active{background:var(--card2)}
.app-link-icon{font-size:1.2rem;flex-shrink:0}
.app-link-text{font-size:.72rem;font-weight:700;color:var(--text2)}
.app-link-name{font-size:.8rem;font-weight:800;color:#fff}

/* Disable card */
.danger-card{background:rgba(255,68,85,.04);border:1px solid rgba(255,68,85,.15);border-radius:14px;padding:16px;margin-bottom:20px}
.danger-card-title{font-size:.78rem;color:#ff4455;font-weight:800;display:flex;align-items:center;gap:5px;margin-bottom:10px}
</style>
</head>
<body>
<div id="app">

<!-- HEADER -->
<div class="top-header">
  <div class="logo-wrap">
    <div class="logo-icon">🚀</div>
    <div class="logo-text"><?= htmlspecialchars($siteName) ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= SITE_URL ?>/mobile.php" class="hbtn"><i class="fas fa-home"></i></a>
  </div>
</div>

<!-- HERO -->
<div class="page-hero">
  <div class="page-hero-bg"></div>
  <div class="page-hero-glow"></div>
  <div class="hero-icon"><?= $enabled ? '🛡️' : '🔓' ?></div>
  <div class="hero-title">حماية الحساب</div>
  <div class="hero-sub">المصادقة الثنائية (2FA)</div>
</div>

<!-- STATUS BANNER -->
<div class="status-banner">
  <div class="status-dot"></div>
  <div>
    <div class="status-text"><?= $enabled ? 'المصادقة الثنائية مفعّلة ✓' : 'المصادقة الثنائية غير مفعّلة' ?></div>
    <div class="status-sub"><?= $enabled ? 'حسابك محمي بطبقة أمان إضافية' : 'فعّلها لحماية حسابك' ?></div>
  </div>
</div>

<?php if($message): ?>
<div class="msg-box <?= $msgType ?>">
  <i class="fas fa-<?= $msgType==='success'?'check-circle':($msgType==='error'?'exclamation-circle':'info-circle') ?>"></i>
  <span><?= htmlspecialchars($message) ?></span>
</div>
<?php endif; ?>

<!-- ═══ غير مفعّل: عرض خطوات الإعداد ═══ -->
<?php if(!$enabled): ?>
<div class="section">
  <div class="sec-title"><i class="fas fa-shield-alt"></i> ما هي المصادقة الثنائية؟</div>
  <div class="info-card">
    <div class="steps-list">
      <div class="step-item"><div class="step-num">1</div>عند تسجيل الدخول، ستحتاج رمز إضافي من التطبيق</div>
      <div class="step-item"><div class="step-num">2</div>الرمز يتغير كل 30 ثانية ولا يمكن اختراقه</div>
      <div class="step-item"><div class="step-num">3</div>حتى لو سُرقت كلمة مرورك، حسابك يبقى آمناً</div>
    </div>
  </div>

  <div class="sec-title" style="margin-top:6px"><i class="fas fa-download"></i> حمّل تطبيق المصادقة</div>
  <div class="info-card">
    <div class="app-links">
      <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" class="app-link">
        <div class="app-link-icon">🤖</div>
        <div><div class="app-link-text">Google Play</div><div class="app-link-name">Google Auth</div></div>
      </a>
      <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank" class="app-link">
        <div class="app-link-icon">🍎</div>
        <div><div class="app-link-text">App Store</div><div class="app-link-name">Google Auth</div></div>
      </a>
      <a href="https://play.google.com/store/apps/details?id=com.authy.authy" target="_blank" class="app-link">
        <div class="app-link-icon">🔐</div>
        <div><div class="app-link-text">Google Play</div><div class="app-link-name">Authy</div></div>
      </a>
      <a href="https://apps.apple.com/app/twilio-authy/id494168017" target="_blank" class="app-link">
        <div class="app-link-icon">🔐</div>
        <div><div class="app-link-text">App Store</div><div class="app-link-name">Authy</div></div>
      </a>
    </div>
  </div>

  <div class="sec-title" style="margin-top:6px"><i class="fas fa-cog"></i> إعداد المصادقة الثنائية</div>
  <div class="setup-card">
    <!-- الخطوة 1: QR Code -->
    <div class="setup-step">
      <div class="setup-step-head">
        <div class="setup-step-num">1</div>
        <div class="setup-step-title">امسح رمز QR بالتطبيق</div>
      </div>
      <div class="qr-wrap" id="qrWrap">
        <div class="qr-img" id="qrImgBox">
          <div class="qr-loader"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;color:var(--text3)"></i><span>اضغط لتوليد الرمز</span></div>
        </div>
        <div id="secretDisplay" style="display:none">
          <div style="font-size:.73rem;color:var(--text2);margin-bottom:8px">أو أدخل المفتاح يدوياً في التطبيق:</div>
          <div class="secret-box" onclick="copySecret()" id="secretBox">
            <i class="fas fa-key" style="font-size:.8rem;color:var(--text3)"></i>
            <span id="secretText">--</span>
            <i class="fas fa-copy" style="font-size:.75rem;color:var(--text3)"></i>
          </div>
          <div class="secret-copied" id="secretCopied">✓ تم نسخ المفتاح</div>
        </div>
      </div>
      <button class="btn btn-secondary btn-sm" style="margin:0 auto;display:flex" onclick="generateQR()" id="genBtn">
        <i class="fas fa-qrcode"></i> <span>توليد رمز QR</span>
      </button>
    </div>

    <!-- الخطوة 2: إدخال الكود -->
    <div class="setup-step" id="verifyStep" style="opacity:.4;pointer-events:none">
      <div class="setup-step-head">
        <div class="setup-step-num">2</div>
        <div class="setup-step-title">أدخل الرمز من التطبيق للتأكيد</div>
      </div>
      <form method="POST" onsubmit="return submitEnable(event)">
        <input type="hidden" name="action" value="enable">
        <div style="direction:ltr">
          <div class="otp-row" id="otpRow">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
          </div>
        </div>
        <input type="hidden" name="code" id="otpHidden">
        <button type="submit" class="btn btn-primary" id="enableBtn">
          <div class="spinner" id="enableSpinner"></div>
          <i class="fas fa-shield-alt" id="enableIcon"></i>
          تفعيل المصادقة الثنائية
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ═══ مفعّل: عرض خيار التعطيل ═══ -->
<?php else: ?>
<div class="section">
  <div class="sec-title"><i class="fas fa-check-circle" style="color:#00e676"></i> حسابك محمي</div>
  <div class="info-card">
    <div class="info-card-head">
      <div class="info-card-icon" style="background:rgba(0,230,118,.12);color:#00e676"><i class="fas fa-mobile-alt"></i></div>
      <div>
        <div class="info-card-title">Google Authenticator</div>
        <div class="info-card-sub">مفعّل منذ <?= $user['totp_verified_at'] ? date('d/m/Y', strtotime($user['totp_verified_at'])) : 'اليوم' ?></div>
      </div>
      <div style="margin-right:auto;background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.2);border-radius:8px;padding:4px 12px;font-size:.7rem;color:#00e676;font-weight:800">مفعّل</div>
    </div>
    <div style="font-size:.8rem;color:var(--text2);padding-top:8px;border-top:1px solid var(--border);line-height:1.7">
      عند كل تسجيل دخول جديد، ستحتاج إلى إدخال رمز من تطبيق المصادقة. هذا يحمي حسابك حتى لو عرف أحد كلمة مرورك.
    </div>
  </div>

  <div class="sec-title" style="margin-top:6px;color:#ff4455"><i class="fas fa-exclamation-triangle"></i> تعطيل المصادقة الثنائية</div>
  <div class="danger-card">
    <div class="danger-card-title"><i class="fas fa-exclamation-triangle"></i> تحذير: تعطيل 2FA يجعل حسابك أقل أماناً</div>
    <form method="POST">
      <input type="hidden" name="action" value="disable">
      <div class="inp-group">
        <label class="inp-label">رمز المصادقة الحالي (من التطبيق)</label>
        <div style="direction:ltr">
          <div class="otp-row" id="disOtpRow">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
            <input class="otp-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric">
          </div>
        </div>
        <input type="hidden" name="code" id="disOtpHidden">
      </div>
      <div class="inp-group">
        <label class="inp-label">كلمة المرور الحالية للتأكيد</label>
        <input type="password" name="confirm_password" class="inp normal" placeholder="أدخل كلمة مرورك" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-danger" onclick="return confirm('هل أنت متأكد من تعطيل المصادقة الثنائية؟')">
        <i class="fas fa-lock-open"></i> تعطيل المصادقة الثنائية
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<div style="height:32px"></div>
</div>

<script>
const SITE_URL = '<?= SITE_URL ?>';
let pendingSecret = '';

// ── توليد QR ────────────────────────────────────────────────────────────────
async function generateQR() {
  const btn = document.getElementById('genBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التوليد...';

  try {
    const fd = new FormData();
    fd.append('action', 'generate');
    const r = await fetch(SITE_URL + '/security.php', {method:'POST', body:fd});
    const d = await r.json();
    if (!d.ok) throw new Error('خطأ');

    pendingSecret = d.secret;

    // عرض QR
    const box = document.getElementById('qrImgBox');
    box.innerHTML = '<img src="' + d.qr + '" alt="QR Code" style="width:100%;height:100%;border-radius:8px" onerror="this.parentNode.innerHTML=\'<div style=\'padding:10px;font-size:.75rem;color:#8895a7;text-align:center\'>تعذّر تحميل QR — استخدم المفتاح أدناه</div>\'">';

    // عرض المفتاح
    document.getElementById('secretText').textContent = d.secret.match(/.{1,4}/g).join(' ');
    document.getElementById('secretDisplay').style.display = 'block';

    // تفعيل خطوة التحقق
    const vs = document.getElementById('verifyStep');
    vs.style.opacity = '1';
    vs.style.pointerEvents = 'auto';
    vs.scrollIntoView({behavior:'smooth', block:'center'});

    btn.innerHTML = '<i class="fas fa-sync-alt"></i> إعادة التوليد';
    btn.disabled = false;
  } catch(e) {
    btn.innerHTML = '<i class="fas fa-qrcode"></i> توليد رمز QR';
    btn.disabled = false;
  }
}

// ── نسخ المفتاح ──────────────────────────────────────────────────────────────
function copySecret() {
  if (!pendingSecret) return;
  navigator.clipboard?.writeText(pendingSecret).then(()=>{
    const el = document.getElementById('secretCopied');
    el.style.display = 'block';
    setTimeout(()=>el.style.display='none', 2000);
  });
}

// ── OTP inputs auto-advance ──────────────────────────────────────────────────
function initOtpInputs(rowId, hiddenId) {
  const row    = document.getElementById(rowId);
  if (!row) return;
  const inputs = row.querySelectorAll('.otp-digit');
  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
      const val = e.target.value.replace(/\D/,'');
      e.target.value = val;
      if (val && idx < inputs.length - 1) inputs[idx+1].focus();
      updateHidden();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        inputs[idx-1].focus();
        inputs[idx-1].value = '';
        updateHidden();
      }
    });
    inp.addEventListener('paste', e => {
      e.preventDefault();
      const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      paste.split('').forEach((ch,i)=>{ if(inputs[i]) inputs[i].value=ch; });
      if(inputs[Math.min(paste.length,5)]) inputs[Math.min(paste.length,5)].focus();
      updateHidden();
    });
  });
  function updateHidden() {
    const code = Array.from(inputs).map(i=>i.value).join('');
    document.getElementById(hiddenId).value = code;
  }
}

initOtpInputs('otpRow',    'otpHidden');
initOtpInputs('disOtpRow', 'disOtpHidden');

// ── Submit enable ─────────────────────────────────────────────────────────────
function submitEnable(e) {
  const code = document.getElementById('otpHidden').value;
  if (code.length !== 6) {
    e.preventDefault();
    // flash the inputs
    document.querySelectorAll('#otpRow .otp-digit').forEach(i=>{
      i.style.borderColor='#ff4455';
      setTimeout(()=>i.style.borderColor='',700);
    });
    return false;
  }
  const btn = document.getElementById('enableBtn');
  document.getElementById('enableSpinner').style.display = 'block';
  document.getElementById('enableIcon').style.display = 'none';
  btn.disabled = true;
  return true;
}

// auto-scroll to message if exists
<?php if($message): ?>
window.scrollTo({top:0,behavior:'smooth'});
<?php endif; ?>
</script>
</body>
</html>
