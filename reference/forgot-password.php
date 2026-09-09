<?php
/**
 * forgot-password.php — استعادة كلمة المرور عبر البريد الإلكتروني
 * ─────────────────────────────────────────────────────────────
 * التدفق:
 *  1) المستخدم يدخل بريده الإلكتروني فقط.
 *  2) نتحقق من وجوده في قاعدة البيانات.
 *  3) إن لم يكن مسجلاً → رسالة "البريد الإلكتروني غير مسجل."
 *  4) إن كان مسجلاً ولم يمر 24 ساعة (أو المدة المحددة من الإدارة) منذ آخر
 *     طلب استعادة لنفس الحساب → رسالة تمنعه من إعادة الطلب الآن.
 *  5) غير ذلك: نولّد كلمة مرور عشوائية قوية، نرسلها بالبريد أولاً، ولا
 *     نُحدّث كلمة المرور في قاعدة البيانات إلا بعد نجاح الإرسال فعلياً
 *     (لمنع فقدان وصول المستخدم لحسابه في حال فشل SMTP).
 */

require_once 'includes/config.php';
require_once 'includes/password_reset_helpers.php';

if (isLoggedIn()) redirect(SITE_URL . '/index.php');

$pageTitle = 'استعادة كلمة المرور - ' . SITE_NAME;
$siteName  = getSetting('site_name') ?: SITE_NAME;

// ── إعدادات الميزة (تُدار من لوحة الإدارة) ─────────────────────────────
$featureEnabled = getSetting('password_reset_enabled') !== '0'; // مفعّلة افتراضياً
$cooldownHours  = (int)(getSetting('password_reset_cooldown_hours') ?: 24);
if ($cooldownHours < 1) $cooldownHours = 24;

// ── CSRF محلي لهذه الصفحة (نفس أسلوب login.php) ────────────────────────
function fpCsrfToken(): string
{
    if (empty($_SESSION['_fp_csrf'])) {
        $_SESSION['_fp_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_fp_csrf'];
}
function fpCsrfVerify(): bool
{
    $submitted = $_POST['_csrf_token'] ?? '';
    $stored    = $_SESSION['_fp_csrf'] ?? '';
    $ok = $submitted && $stored && hash_equals($stored, $submitted);
    $_SESSION['_fp_csrf'] = bin2hex(random_bytes(32)); // تجديد دائماً
    return $ok;
}

$error       = null;
$success     = null;
$csrfToken   = fpCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $featureEnabled) {

    if (!fpCsrfVerify()) {
        $error = 'طلب غير صالح. يرجى إعادة المحاولة.';
    } elseif (!empty($_POST['website'])) {
        // حقل خداعي (honeypot) مخفي عن المستخدمين الحقيقيين — امتلاؤه يعني بوت
        $error = 'حدث خطأ، يرجى المحاولة مجدداً.';
    } else {
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $ip    = getClientIP();

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'صيغة البريد الإلكتروني غير صحيحة.';
        } else {
            // ── حماية إضافية: تقييد عدد الطلبات لكل IP (منع إساءة الاستخدام الآلي) ──
            $ipCount = 0;
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM password_reset_logs WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
                $st->execute([$ip]);
                $ipCount = (int)$st->fetchColumn();
            } catch (Exception $e) {}

            if ($ipCount >= 8) {
                $error = 'عدد كبير من المحاولات من نفس الجهاز. يرجى المحاولة لاحقاً.';
            } else {
                $stmt = $pdo->prepare("SELECT id, username, email, full_name, last_password_reset_at FROM users WHERE email = ? AND is_deleted = 0 AND status = 1 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'البريد الإلكتروني غير مسجل.';
                } else {
                    $lastReset    = (int)($user['last_password_reset_at'] ?? 0);
                    $cooldownSecs = $cooldownHours * 3600;

                    if ($lastReset > 0 && (time() - $lastReset) < $cooldownSecs) {
                        $error = "تم إرسال كلمة مرور جديدة لهذا الحساب خلال آخر {$cooldownHours} ساعة، يرجى المحاولة لاحقاً.";
                    } else {
                        // ── توليد كلمة مرور جديدة قوية ──
                        $newPassword = generateStrongPassword(12);
                        $hashed      = password_hash($newPassword, PASSWORD_DEFAULT); // نفس آلية التشفير المستخدمة في تسجيل الدخول

                        $displayName = $user['full_name'] ?: $user['username'];
                        $loginUrl    = SITE_URL . '/login.php';
                        $subject     = buildResetEmailSubject($siteName);
                        $htmlBody    = buildResetEmailHtml($siteName, $displayName, $newPassword, $loginUrl);

                        $mailer = getSmtpMailerForSection($pdo, 'reset');
                        $sent   = $mailer->send($user['email'], $displayName, $subject, $htmlBody);

                        // نسجّل كل محاولة (نجاح أو فشل) لعرضها لاحقاً في لوحة الإدارة
                        try {
                            $pdo->prepare("INSERT INTO password_reset_logs (user_id, username, email, ip, status, error_message) VALUES (?,?,?,?,?,?)")
                                ->execute([
                                    $user['id'], $user['username'], $user['email'], $ip,
                                    $sent ? 'sent' : 'failed',
                                    $sent ? null : mb_substr($mailer->lastError, 0, 490),
                                ]);
                        } catch (Exception $e) {}

                        if ($sent) {
                            // نحدّث كلمة المرور فقط بعد نجاح الإرسال فعلياً — لحماية وصول المستخدم لحسابه
                            $pdo->prepare("UPDATE users SET password = ?, last_password_reset_at = ? WHERE id = ?")
                                ->execute([$hashed, time(), $user['id']]);
                            $success = 'تم إرسال كلمة مرور جديدة إلى بريدك الإلكتروني. يرجى مراجعة صندوق الوارد (وصندوق الرسائل غير المرغوبة إن لم تجدها).';
                        } else {
                            $error = 'تعذّر إرسال البريد الإلكتروني حالياً. يرجى المحاولة لاحقاً أو التواصل مع الدعم الفني.';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap">

<style>
:root {
  /* ══ الوضع النهاري (افتراضي) ══ */
  --bg:      #eef2f8;
  --bg2:     #ffffff;
  --card:    #ffffff;
  --card2:   #f1f5f9;
  --border:  rgba(15,23,42,0.08);
  --border2: rgba(15,23,42,0.14);
  --primary: #3b82f6;
  --cyan:    #0891b2;
  --green:   #059669;
  --red:     #dc2626;
  --gold:    #d97706;
  --text:    #0f172a;
  --text2:   #475569;
  --text3:   #94a3b8;
  --card-grad-1: #ffffff;
  --card-grad-2: #f8fafc;
  --input-bg:    rgba(15,23,42,0.03);
  --handle-bg:   rgba(15,23,42,0.14);
  --card-shadow: rgba(15,23,42,0.14);
  --font:    'Cairo', sans-serif;
}
[data-theme="dark"] {
  --bg:      #05080f;
  --bg2:     #090d1a;
  --card:    #0e1525;
  --card2:   #151f35;
  --border:  rgba(255,255,255,0.07);
  --border2: rgba(255,255,255,0.12);
  --primary: #3b82f6;
  --cyan:    #22d3ee;
  --green:   #34d399;
  --red:     #f87171;
  --gold:    #f5a623;
  --text:    #e2e8f5;
  --text2:   #7c93b5;
  --text3:   #3d526e;
  --card-grad-1: #0f1929;
  --card-grad-2: #080c1a;
  --input-bg:    rgba(255,255,255,0.04);
  --handle-bg:   rgba(255,255,255,0.12);
  --card-shadow: rgba(0,0,0,0.6);
}
.theme-toggle-btn {
  position: fixed; top: 16px; left: 16px; z-index: 50;
  width: 42px; height: 42px; border-radius: 13px;
  background: var(--card2); border: 1.5px solid var(--border2);
  color: var(--text2); font-size: 16px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all .2s;
  box-shadow: 0 4px 14px rgba(0,0,0,0.08);
}
.theme-toggle-btn:hover { color: var(--primary); border-color: var(--primary); }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--bg); color: var(--text); direction: rtl; }
body {
  min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
  background: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59,130,246,0.12), transparent), var(--bg);
}

.login-card {
  width: 100%; max-width: 420px;
  background: linear-gradient(180deg, var(--card-grad-1) 0%, var(--card-grad-2) 100%);
  border-radius: 28px; border: 1px solid rgba(59,130,246,0.2);
  overflow: hidden;
  animation: slideUp .4s cubic-bezier(.32,1.2,.6,1) both;
  box-shadow: 0 32px 80px var(--card-shadow);
}
@keyframes slideUp { from { opacity:0; transform:translateY(36px); } to { opacity:1; transform:translateY(0); } }

.auth-handle { width: 40px; height: 4px; background: var(--handle-bg); border-radius: 2px; margin: 14px auto 0; }

.auth-header { padding: 20px 20px 0; text-align: center; }
.auth-logo-big {
  width: 64px; height: 64px; border-radius: 20px; margin: 0 auto 14px;
  display: flex; align-items: center; justify-content: center; font-size: 28px;
  background: linear-gradient(135deg, var(--primary), var(--cyan));
  box-shadow: 0 8px 24px rgba(59,130,246,0.4);
  animation: pulse-glow 2s ease-in-out infinite;
}
@keyframes pulse-glow { 0%,100% { box-shadow: 0 8px 24px rgba(59,130,246,0.4); } 50% { box-shadow: 0 8px 40px rgba(34,211,238,0.55); } }
.auth-header-title { font-size: 22px; font-weight: 900; margin-bottom: 4px; color: var(--text); }
.auth-header-sub { font-size: 12px; color: var(--text2); padding-bottom: 6px; line-height: 1.8; }

.auth-error {
  background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); border-radius: 10px;
  padding: 10px 14px; font-size: 12px; color: var(--red); margin: 12px 20px 0;
  display: flex; align-items: center; gap: 8px; animation: shake .3s ease; line-height: 1.7;
}
@keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)} }

.auth-form { padding: 20px; }
.auth-field { margin-bottom: 14px; }
.auth-field-label { font-size: 11px; color: var(--text2); font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
.auth-field-label i { color: var(--primary); font-size: 12px; }
.auth-input-wrap { position: relative; }
.auth-input {
  width: 100%; background: var(--input-bg); border: 1.5px solid var(--border2);
  border-radius: 14px; padding: 14px 14px 14px 44px; color: var(--text);
  font-family: var(--font); font-size: 15px; outline: none; transition: all .25s; direction: rtl;
}
.auth-input:focus { border-color: var(--primary); background: rgba(59,130,246,0.06); box-shadow: 0 0 0 3px rgba(59,130,246,0.14); }
.auth-input::placeholder { color: var(--text3); }
.auth-input-icon { position: absolute; top: 50%; left: 14px; transform: translateY(-50%); color: var(--text3); font-size: 16px; pointer-events: none; transition: color .2s; }
.auth-input:focus ~ .auth-input-icon { color: var(--primary); }

/* حقل خداعي مخفي تماماً عن المستخدم الحقيقي */
.hp-field { position: absolute; left: -9999px; top: -9999px; opacity: 0; height: 0; width: 0; }

.auth-submit {
  width: 100%; background: linear-gradient(135deg, var(--primary), #0a3fbe);
  border: none; border-radius: 14px; padding: 16px; color: #fff;
  font-family: var(--font); font-size: 16px; font-weight: 900; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 0 6px 20px rgba(59,130,246,0.4); transition: all .2s; margin-top: 6px;
}
.auth-submit:active { transform: scale(.97); }
.auth-submit:disabled { opacity: .6; cursor: not-allowed; }
.auth-submit.loading { pointer-events: none; opacity: .75; }
.auth-submit .spin { display: none; animation: spin .7s linear infinite; }
.auth-submit.loading .spin { display: inline-block; }
.auth-submit.loading .btn-text { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

.auth-switch { text-align: center; font-size: 12px; color: var(--text2); margin-top: 14px; }
.auth-switch a { color: var(--cyan); font-weight: 800; text-decoration: none; }

/* شاشة النجاح */
.fp-success { padding: 30px 24px 26px; text-align: center; }
.fp-success-icon { font-size: 3.4rem; margin-bottom: 12px; animation: pop .4s cubic-bezier(.34,1.56,.64,1); }
@keyframes pop { from { transform: scale(0); } to { transform: scale(1); } }
.fp-success-title { color: var(--green); font-size: 1.15rem; font-weight: 900; margin-bottom: 8px; }
.fp-success-sub { color: var(--text2); font-size: .88rem; line-height: 1.9; margin-bottom: 20px; }

/* شاشة تعطيل الميزة */
.fp-disabled { padding: 34px 24px; text-align: center; }
.fp-disabled-icon { font-size: 3rem; margin-bottom: 12px; color: var(--gold); }
.fp-disabled p { color: var(--text2); font-size: .88rem; line-height: 1.9; margin-bottom: 18px; }
</style>
<script>
(function(){
  var t = localStorage.getItem('njaz_theme') || 'light';
  if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
</head>
<body>

<button type="button" class="theme-toggle-btn" id="themeToggle" onclick="toggleTheme()" aria-label="تبديل الوضع الليلي/النهاري">
  <i class="fas fa-moon" id="themeToggleIcon"></i>
</button>

<div class="login-card">
  <div class="auth-handle"></div>

  <div class="auth-header">
    <div class="auth-logo-big"><i class="fas fa-key"></i></div>
    <div class="auth-header-title">استعادة كلمة المرور</div>
    <div class="auth-header-sub">أدخل بريدك الإلكتروني المسجّل وسنرسل لك كلمة مرور جديدة</div>
  </div>

  <?php if (!$featureEnabled): ?>
  <!-- ══ الميزة معطّلة من لوحة الإدارة ══ -->
  <div class="fp-disabled">
    <div class="fp-disabled-icon"><i class="fas fa-tools"></i></div>
    <p>ميزة استعادة كلمة المرور غير متاحة حالياً.<br>يرجى التواصل مع الدعم الفني لمساعدتك.</p>
    <a href="login.php" class="auth-submit" style="text-decoration:none;display:inline-flex">
      <i class="fas fa-arrow-right"></i> العودة لتسجيل الدخول
    </a>
  </div>

  <?php elseif ($success): ?>
  <!-- ══ نجاح الإرسال ══ -->
  <div class="fp-success">
    <div class="fp-success-icon">📩</div>
    <div class="fp-success-title">تم الإرسال بنجاح</div>
    <div class="fp-success-sub"><?= htmlspecialchars($success) ?></div>
    <a href="login.php" class="auth-submit" style="text-decoration:none;display:inline-flex">
      <i class="fas fa-sign-in-alt"></i> الذهاب لتسجيل الدخول
    </a>
  </div>

  <?php else: ?>

  <?php if ($error): ?>
  <div class="auth-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <!-- ══ نموذج طلب الاستعادة ══ -->
  <form method="POST" id="fpForm" autocomplete="off" class="auth-form">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <!-- حقل خداعي لمكافحة البوتات -->
    <input type="text" name="website" class="hp-field" tabindex="-1" autocomplete="off" aria-hidden="true">

    <div class="auth-field">
      <div class="auth-field-label"><i class="fas fa-envelope"></i> البريد الإلكتروني المسجّل</div>
      <div class="auth-input-wrap">
        <input class="auth-input" type="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               placeholder="example@email.com"
               autocomplete="email" required autofocus>
        <i class="fas fa-envelope auth-input-icon"></i>
      </div>
    </div>

    <button type="submit" class="auth-submit" id="fpBtn">
      <i class="fas fa-spinner spin"></i>
      <span class="btn-text"><i class="fas fa-paper-plane"></i> إرسال كلمة مرور جديدة</span>
    </button>

    <div class="auth-switch">تذكرت كلمة المرور؟ <a href="login.php">تسجيل الدخول</a></div>
  </form>
  <?php endif; ?>

</div><!-- /.login-card -->

<script>
const ff = document.getElementById('fpForm');
if (ff) ff.addEventListener('submit', function () {
  const b = document.getElementById('fpBtn');
  if (b) b.classList.add('loading');
});

/* ══ تبديل الوضع الليلي/النهاري ══ */
function updateThemeIcon(theme){
  const icon = document.getElementById('themeToggleIcon');
  if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
function toggleTheme(){
  const cur  = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  const next = cur === 'dark' ? 'light' : 'dark';
  if (next === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  else document.documentElement.removeAttribute('data-theme');
  localStorage.setItem('njaz_theme', next);
  updateThemeIcon(next);
}
updateThemeIcon(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
</script>
</body>
</html>
