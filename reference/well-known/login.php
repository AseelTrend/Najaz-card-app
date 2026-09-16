<?php
/**
 * login.php — محمي بـ:
 *  1. Brute Force Protection  (session-based + IP-based)
 *  2. CSRF Token              (random_bytes)
 *  3. Session Security        (session_regenerate_id)
 *  4. Device Fingerprint      (device_id + IP + User-Agent)
 *  5. رسائل عامة             (لا تكشف سبب الفشل)
 */

require_once 'includes/config.php';
require_once 'includes/device_helper.php';
require_once 'includes/device_confirm_helpers.php';
require_once 'includes/totp.php';
$pageTitle = 'تسجيل الدخول - ' . SITE_NAME;

if (isLoggedIn()) redirect(SITE_URL . '/index.php');

// ══════════════════════════════════════════════════════════════
//  1. Brute Force — ثوابت الحماية
// ══════════════════════════════════════════════════════════════
const BF_MAX_ATTEMPTS  = 5;        // أقصى عدد محاولات قبل الحظر
const BF_LOCKOUT_SECS  = 15 * 60; // مدة الحظر (15 دقيقة)
const BF_WINDOW_SECS   = 30 * 60; // نافزة تنظيف السجلات القديمة (30 دقيقة)

/**
 * الحصول على IP الحقيقي (مطابق لـ device_helper.php)
 */
function getLoginIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ══════════════════════════════════════════════════════════════
//  [H-3 FIX] Brute Force على مستوى قاعدة البيانات
//  — لا يمكن تجاوزه بمسح Session أو تغيير المتصفح
//  — يحظر بناءً على IP المُخزَّن في DB
//
//  يتطلب جدول login_attempts — راجع login_attempts.sql
// ══════════════════════════════════════════════════════════════

/**
 * جلب سجل IP من قاعدة البيانات
 */
function bfDbGet(PDO $pdo, string $ip): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE ip = ? LIMIT 1");
        $stmt->execute([$ip]);
        return $stmt->fetch() ?: ['ip'=>$ip,'attempts'=>0,'locked_until'=>0];
    } catch (Exception $e) {
        // إذا الجدول غير موجود نرجع فارغاً (لا نوقف الموقع)
        return ['ip'=>$ip,'attempts'=>0,'locked_until'=>0];
    }
}

/**
 * هل هذا IP محظور حالياً؟
 */
function bfIsLocked(PDO $pdo = null): bool {
    if (!$pdo) { global $pdo; }
    $ip = getLoginIP();
    $row = bfDbGet($pdo, $ip);
    return (int)($row['locked_until'] ?? 0) > time();
}

/**
 * ثواني الحظر المتبقية
 */
function bfSecsLeft(PDO $pdo = null): int {
    if (!$pdo) { global $pdo; }
    $ip = getLoginIP();
    $row = bfDbGet($pdo, $ip);
    return max(0, (int)($row['locked_until'] ?? 0) - time());
}

/**
 * تسجيل محاولة فاشلة في DB
 */
function bfFail(PDO $pdo = null): void {
    if (!$pdo) { global $pdo; }
    $ip  = getLoginIP();
    $row = bfDbGet($pdo, $ip);

    $attempts = (int)($row['attempts'] ?? 0) + 1;
    $locked   = $attempts >= BF_MAX_ATTEMPTS ? time() + BF_LOCKOUT_SECS : 0;

    try {
        $pdo->prepare("
            INSERT INTO login_attempts (ip, attempts, locked_until, last_attempt)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                attempts     = VALUES(attempts),
                locked_until = VALUES(locked_until),
                last_attempt = VALUES(last_attempt)
        ")->execute([$ip, $attempts, $locked, time()]);
    } catch (Exception $e) {}
}

/**
 * إعادة تعيين عداد IP بعد الدخول الناجح
 */
function bfReset(PDO $pdo = null): void {
    if (!$pdo) { global $pdo; }
    $ip = getLoginIP();
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
    } catch (Exception $e) {}
}

/**
 * تنظيف السجلات القديمة (تُنفَّذ تلقائياً بنسبة 5%)
 */
function bfCleanup(PDO $pdo = null): void {
    if (mt_rand(1,100) > 5) return; // 5% فقط من الطلبات
    if (!$pdo) { global $pdo; }
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE last_attempt < ?")
            ->execute([time() - BF_WINDOW_SECS]);
    } catch (Exception $e) {}
}

// تنظيف تلقائي
bfCleanup($pdo);

// ══════════════════════════════════════════════════════════════
//  2. CSRF Token
// ══════════════════════════════════════════════════════════════

/**
 * توليد أو جلب CSRF Token من session
 */
function csrfToken(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * التحقق من CSRF Token — يقارن بـ hash_equals لمنع timing attacks
 */
function csrfVerify(): bool {
    $submitted = $_POST['_csrf_token'] ?? '';
    $stored    = $_SESSION['_csrf_token'] ?? '';
    if (!$submitted || !$stored) return false;
    return hash_equals($stored, $submitted);
}

/**
 * تجديد CSRF Token بعد كل استخدام (valid for one-time use)
 */
function csrfRefresh(): void {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

// ══════════════════════════════════════════════════════════════
//  4. Device Fingerprint المُعزَّز
// ══════════════════════════════════════════════════════════════

/**
 * بناء معرّف جهاز مدمج من:
 *   - device_id القادم من JS (localStorage)
 *   - User-Agent
 *   - جزء ثابت من IP (أول 3 octets فقط — يتحمّل تغيير ISP)
 *
 * النتيجة: device_id أصلي إذا صحيح، وإلا fallback موقّع
 */
function buildDeviceFingerprint(string $clientDid): string {
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip       = getLoginIP();
    $ipPrefix = implode('.', array_slice(explode('.', $ip), 0, 3)); // x.x.x

    // device_id صالح من JS → نستخدمه كـ primary key ونضيف توقيعاً داخلياً
    if ($clientDid && preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $clientDid)) {
        return $clientDid; // الـ fingerprint الرئيسي هو device_id
        // ملاحظة: IP+UA يُسجَّلان في user_devices لكن لا يدخلان في التطابق
        //          هذا يتيح VPN وتغيير شبكة دون حظر
    }

    // Fallback: UA + IP prefix (أقل دقة لكن أفضل من لا شيء)
    return 'ua_' . hash('sha256', $ua . '|' . $ipPrefix);
}

/**
 * يُخفي جزءاً من البريد الإلكتروني لعرضه بأمان على شاشة "جهاز غير مصرح"
 * (مثال: ahmed@example.com → ah***@example.com)
 */
function maskEmailForDisplay(string $email): string {
    if (!$email || !str_contains($email, '@')) return '';
    [$local, $domain] = explode('@', $email, 2);
    $visible = mb_substr($local, 0, 2);
    return $visible . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
}

/**
 * تُنفَّذ بعد نجاح التحقق من (كلمة المرور + 2FA إن وُجدت):
 * فحص الجهاز، ثم إما تسجيل الدخول والتحويل، أو إرجاع حالة pending/blocked.
 * @return array{status:string, info?:array}
 */
function finalizeLogin(PDO $pdo, array $user, string $deviceFingerprint, string $clientDeviceId): array {
    $deviceStatus = checkAndRegisterDevice($pdo, $user['id'], false, $deviceFingerprint);

    if ($deviceStatus === 'blocked') {
        bfFail($pdo);
        return ['status' => 'blocked'];
    }

    if ($deviceStatus === 'pending') {
        bfReset($pdo); // بيانات الدخول صحيحة → نعيد عداد المحاولات
        $_SESSION['pending_device_user'] = $user['id'];
        $_SESSION['pending_device_name'] = $user['username'];

        // ── إرسال بريد تأكيد الجهاز (بنفس طريقة استعادة كلمة المرور) ──
        $emailSent = false;
        try {
            $devStmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=? LIMIT 1");
            $devStmt->execute([$user['id'], $deviceFingerprint]);
            $deviceRow = $devStmt->fetch();
            if ($deviceRow) {
                $emailSent = sendDeviceConfirmEmail($pdo, $user, (int)$deviceRow['id'], [
                    'device_name' => $deviceRow['device_name'],
                    'ip_address'  => $deviceRow['ip_address'],
                ]);
            }
        } catch (Throwable $e) {
            // فشل إرسال البريد لا يجب أن يكسر صفحة تسجيل الدخول — يظل خيار واتساب متاحاً
            $emailSent = false;
        }

        return [
            'status' => 'pending',
            'info'   => [
                'username'    => $user['username'],
                'device_id'   => $clientDeviceId ?: '—',
                'email_sent'  => $emailSent,
                'email_hint'  => $emailSent ? maskEmailForDisplay($user['email'] ?? '') : '',
            ],
        ];
    }

    // ── دخول ناجح ──
    bfReset($pdo);
    csrfRefresh();
    session_regenerate_id(true);

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['_login_ip'] = getLoginIP();
    $_SESSION['_login_ua'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    unset($_SESSION['pending_device_user'], $_SESSION['pending_2fa_user_id']);

    $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

    if ($user['role'] === 'admin' || $user['role'] === 'staff') {
        redirect(SITE_URL . '/admin/');
    } else {
        redirect(SITE_URL . '/index.php');
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
//  معالجة الـ POST
// ══════════════════════════════════════════════════════════════
$error         = null;
$devicePending = false;
$pendingInfo   = [];
$show2FA       = false;
$csrfToken     = csrfToken(); // نولّد/نجلب قبل أي معالجة

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── فحص Brute Force (DB-based) ─────────────────────────────────────────
    if (bfIsLocked($pdo)) {
        $mins = (int)ceil(bfSecsLeft($pdo) / 60);
        $error = "تم تجاوز عدد المحاولات المسموح بها. يرجى الانتظار {$mins} دقيقة قبل المحاولة مجدداً.";
    }
    // ── فحص CSRF ───────────────────────────────────────────────────────────
    elseif (!csrfVerify()) {
        $error = 'طلب غير صالح. يرجى إعادة المحاولة.';
        csrfRefresh(); // تجديد التوكن
    }
    // ══════════════════════════════════════════════════════════════════════
    //  إكمال تحدّي 2FA (نفس منطق التحقق الموجود في api/auth.php بالضبط)
    // ══════════════════════════════════════════════════════════════════════
    elseif (!empty($_SESSION['pending_2fa_user_id'])) {
        $clientDeviceId    = trim($_POST['device_id'] ?? '');
        $deviceFingerprint = buildDeviceFingerprint($clientDeviceId);
        $totpCode          = preg_replace('/\s/', '', $_POST['totp_code'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE id=? AND status=1");
        $stmt->execute([$_SESSION['pending_2fa_user_id']]);
        $user = $stmt->fetch();

        if (!$user || empty($user['totp_enabled']) || empty($user['totp_secret'])) {
            unset($_SESSION['pending_2fa_user_id']);
            $error = 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول من جديد.';
        } elseif (empty($totpCode) || !TOTP::verify($user['totp_secret'], $totpCode)) {
            bfFail($pdo);
            $error   = 'رمز المصادقة غير صحيح أو منتهي الصلاحية.';
            $show2FA = true;
            csrfRefresh();
        } else {
            $result = finalizeLogin($pdo, $user, $deviceFingerprint, $clientDeviceId);
            if ($result['status'] === 'blocked') {
                unset($_SESSION['pending_2fa_user_id']);
                $error = 'بيانات الدخول غير صحيحة أو الحساب موقوف.';
            } elseif ($result['status'] === 'pending') {
                unset($_SESSION['pending_2fa_user_id']);
                $devicePending = true;
                $pendingInfo   = $result['info'];
            }
        }
    }
    else {
        // جلب المدخلات
        $login          = trim($_POST['login']     ?? '');
        $password       = $_POST['password']       ?? '';
        $clientDeviceId = trim($_POST['device_id'] ?? '');

        // ── بناء fingerprint المعزَّز ──────────────────────────────────────
        $deviceFingerprint = buildDeviceFingerprint($clientDeviceId);

        // ── استعلام قاعدة البيانات ─────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status=1");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            // ── 2FA مطلوب؟ (نفس شرط api/auth.php بالضبط) ───────────────────
            if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                bfReset($pdo); // كلمة المرور صحيحة → نعيد العداد
                $_SESSION['pending_2fa_user_id'] = $user['id'];
                $show2FA = true;
                csrfRefresh();
            } else {
                $result = finalizeLogin($pdo, $user, $deviceFingerprint, $clientDeviceId);
                if ($result['status'] === 'blocked') {
                    $error = 'بيانات الدخول غير صحيحة أو الحساب موقوف.';
                } elseif ($result['status'] === 'pending') {
                    $devicePending = true;
                    $pendingInfo   = $result['info'];
                }
            }

        } else {
            // ── كلمة مرور خاطئة أو مستخدم غير موجود ──────────────────────
            // password_verify على string وهمية لتحقيق توقيت ثابت (منع User Enumeration)
            if (!$user) {
                password_verify($password, '$2y$12$invalidhashxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            }
            bfFail($pdo);
            // حساب المحاولات المتبقية من DB
            $bfRow     = bfDbGet($pdo, getLoginIP());
            $remaining = max(0, BF_MAX_ATTEMPTS - (int)($bfRow['attempts'] ?? 0));
            $error = $remaining > 0
                ? 'بيانات الدخول غير صحيحة.' . ($remaining <= 2 ? " (تبقى {$remaining} محاولة)" : '')
                : 'بيانات الدخول غير صحيحة.';
            csrfRefresh();
        }
    }

    // تجديد توكن الـ CSRF للعرض
    $csrfToken = csrfToken();
}

// ══════════════════════════════════════════════════════════════
//  متغيرات العرض
// ══════════════════════════════════════════════════════════════
$supportWhatsapp = '967781225550';
$siteName        = getSetting('site_name') ?: SITE_NAME;
// عدد المحاولات من DB
$_bfRow     = bfDbGet($pdo, getLoginIP());
$bfAttempts = (int)($_bfRow['attempts'] ?? 0);


// جلب اسم الموقع وشعاره للعرض
$siteName    = getSetting('site_name') ?: SITE_NAME;
$siteLogoUrl = getSetting('logo_url')  ?: '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>تسجيل الدخول - <?= htmlspecialchars($siteName) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap">

<style>
/* ══ Variables — مطابقة لـ mobile.php ══ */
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

/* ══ الوضع الليلي ══ */
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

/* ══ زر تبديل الوضع الليلي/النهاري ══ */
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

/* ══ Reset ══ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
  height: 100%;
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  direction: rtl;
}

body {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background:
    radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59,130,246,0.12), transparent),
    var(--bg);
}

/* ══ Card — مطابق لـ auth-sheet في mobile.php ══ */
.login-card {
  width: 100%;
  max-width: 420px;
  background: linear-gradient(180deg, var(--card-grad-1) 0%, var(--card-grad-2) 100%);
  border-radius: 28px;
  border: 1px solid rgba(59,130,246,0.2);
  overflow: hidden;
  animation: slideUp .4s cubic-bezier(.32,1.2,.6,1) both;
  box-shadow: 0 32px 80px var(--card-shadow);
}
@keyframes slideUp {
  from { opacity:0; transform:translateY(36px); }
  to   { opacity:1; transform:translateY(0); }
}

/* Handle */
.auth-handle {
  width: 40px; height: 4px;
  background: var(--handle-bg);
  border-radius: 2px;
  margin: 14px auto 0;
}

/* ══ Header ══ */
.auth-header { padding: 20px 20px 0; text-align: center; }

.auth-logo-big {
  width: 64px; height: 64px;
  border-radius: 20px;
  margin: 0 auto 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px;
  overflow: hidden;
  animation: pulse-glow 2s ease-in-out infinite;
}
.auth-logo-big.gradient {
  background: linear-gradient(135deg, var(--primary), var(--cyan));
  box-shadow: 0 8px 24px rgba(59,130,246,0.4);
}
.auth-logo-big img {
  width: 100%; height: 100%; object-fit: cover;
}
@keyframes pulse-glow {
  0%,100% { box-shadow: 0 8px 24px rgba(59,130,246,0.4); }
  50%      { box-shadow: 0 8px 40px rgba(34,211,238,0.55); }
}

.auth-header-title {
  font-size: 22px; font-weight: 900;
  margin-bottom: 4px; color: var(--text);
}
.auth-header-sub {
  font-size: 12px; color: var(--text2);
  padding-bottom: 6px;
}

/* ══ Error ══ */
.auth-error {
  background: rgba(248,113,113,0.1);
  border: 1px solid rgba(248,113,113,0.3);
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 12px;
  color: var(--red);
  margin: 12px 20px 0;
  display: flex; align-items: center; gap: 8px;
  animation: shake .3s ease;
}
@keyframes shake {
  0%,100%{transform:translateX(0)} 25%{transform:translateX(-6px)} 75%{transform:translateX(6px)}
}

/* ══ BF warning ══ */
.bf-warning {
  margin: 8px 20px 0;
  background: rgba(245,166,35,.08);
  border: 1px solid rgba(245,166,35,.22);
  border-radius: 8px;
  font-size: .75rem; color: var(--gold);
  padding: 7px 12px;
  display: flex; align-items: center; gap: 6px;
}

/* ══ Lockout ══ */
.lockout-box {
  text-align: center;
  padding: 32px 20px;
}
.lockout-box .lk-icon { font-size: 3rem; margin-bottom: 10px; }
.lockout-box p { font-size: .88rem; color: var(--text2); line-height: 1.9; }
.lockout-box strong { color: var(--gold); }

/* ══ Form ══ */
.auth-form { padding: 20px; }

.auth-field { margin-bottom: 14px; }
.auth-field-label {
  font-size: 11px; color: var(--text2); font-weight: 700;
  margin-bottom: 6px;
  display: flex; align-items: center; gap: 6px;
}
.auth-field-label i { color: var(--primary); font-size: 12px; }

.auth-input-wrap { position: relative; }

.auth-input {
  width: 100%;
  background: var(--input-bg);
  border: 1.5px solid var(--border2);
  border-radius: 14px;
  padding: 14px 14px 14px 44px;
  color: var(--text);
  font-family: var(--font);
  font-size: 15px;
  outline: none;
  transition: all .25s;
  direction: rtl;
}
.auth-input:focus {
  border-color: var(--primary);
  background: rgba(59,130,246,0.06);
  box-shadow: 0 0 0 3px rgba(59,130,246,0.14);
}
.auth-input::placeholder { color: var(--text3); }

.auth-input-icon {
  position: absolute; top: 50%; left: 14px;
  transform: translateY(-50%);
  color: var(--text3); font-size: 16px;
  pointer-events: none; transition: color .2s;
}
.auth-input:focus ~ .auth-input-icon { color: var(--primary); }

.auth-pw-toggle {
  position: absolute; top: 50%; right: 14px;
  transform: translateY(-50%);
  color: var(--text3); font-size: 15px;
  cursor: pointer; padding: 4px;
  transition: color .2s;
}
.auth-pw-toggle:hover { color: var(--text2); }

/* Device */
.auth-input.device-input {
  font-size: .78rem; color: var(--text3);
  cursor: default; font-family: monospace;
}

/* ══ Submit ══ */
.auth-submit {
  width: 100%;
  background: linear-gradient(135deg, var(--primary), #0a3fbe);
  border: none; border-radius: 14px;
  padding: 16px;
  color: #fff;
  font-family: var(--font);
  font-size: 16px; font-weight: 900;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  box-shadow: 0 6px 20px rgba(59,130,246,0.4);
  transition: all .2s;
  margin-top: 6px;
}
.auth-submit:active { transform: scale(.97); }
.auth-submit:disabled { opacity: .6; cursor: not-allowed; }
.auth-submit.loading { pointer-events: none; opacity: .75; }
.auth-submit .spin { display: none; animation: spin .7s linear infinite; }
.auth-submit.loading .spin { display: inline-block; }
.auth-submit.loading .btn-text { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ══ Divider ══ */
.auth-divider {
  display: flex; align-items: center; gap: 12px;
  margin: 16px 0;
  color: var(--text3); font-size: 11px;
}
.auth-divider::before, .auth-divider::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* ══ Google ══ */
.auth-google-btn {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  width: 100%; padding: 13px;
  border-radius: 14px;
  border: 1.5px solid rgba(66,133,244,.3);
  background: rgba(66,133,244,.06);
  color: var(--text);
  font-family: var(--font);
  font-size: 14px; font-weight: 700;
  text-decoration: none;
  transition: all .2s; margin-bottom: 4px;
}
.auth-google-btn:hover { background: rgba(66,133,244,.14); border-color: rgba(66,133,244,.5); }

/* ══ Switch & Forgot ══ */
.auth-switch {
  text-align: center; font-size: 12px;
  color: var(--text2); margin-top: 14px;
}
.auth-switch a { color: var(--cyan); font-weight: 800; text-decoration: none; }
.auth-forgot {
  text-align: center; margin-top: 8px;
  padding: 8px 0 2px;
  border-top: 1px solid var(--border);
}
.auth-forgot a {
  font-size: .82rem; color: var(--text3);
  text-decoration: none; transition: color .2s;
}
.auth-forgot a:hover { color: var(--text2); }

/* ══ Device Pending ══ */
/* ══ 2FA OTP digits ══ */
.auth-2fa-row { display: flex; gap: 8px; direction: ltr; justify-content: center; }
.auth-2fa-digit {
  width: 44px; height: 52px;
  background: var(--input-bg);
  border: 1.5px solid var(--border2);
  border-radius: 12px;
  color: var(--text);
  font-family: var(--font);
  font-size: 20px; font-weight: 800;
  text-align: center;
  outline: none;
  transition: all .2s;
}
.auth-2fa-digit:focus {
  border-color: var(--primary);
  background: rgba(59,130,246,0.06);
  box-shadow: 0 0 0 3px rgba(59,130,246,0.14);
}

.device-pending { padding: 24px 20px; text-align: center; }
.device-pending .dp-icon { font-size: 3.5rem; margin-bottom: .75rem; }
.device-pending h3 { color: var(--gold); font-size: 1.1rem; margin-bottom: .5rem; }
.device-pending p  { color: var(--text2); font-size: .88rem; line-height: 1.7; margin-bottom: 1.25rem; }
.device-info {
  background: rgba(245,166,35,.08);
  border: 1px solid rgba(245,166,35,.25);
  border-radius: 12px; padding: 1rem 1.25rem;
  margin-bottom: 1.25rem; text-align: right;
}
.device-info .di-lbl  { font-size: .75rem; color: var(--gold); font-weight: 700; margin-bottom: .6rem; }
.device-info .di-row  { font-size: .82rem; color: var(--text2); line-height: 2; }
.device-info .di-row strong { color: var(--text); }
.wa-btn {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  background: linear-gradient(135deg,#25d366,#128c7e);
  color:#fff; border-radius:14px; padding:14px 20px;
  text-decoration:none; font-weight:800; font-size:.95rem;
  margin-bottom:1rem;
  box-shadow:0 4px 16px rgba(37,211,102,.35);
  transition: opacity .2s;
}
.wa-btn:hover { opacity:.9; }
.back-link {
  display:block; color:var(--text3);
  font-size:.83rem; text-decoration:none; margin-top:.5rem;
  transition: color .2s;
}
.back-link:hover { color: var(--text2); }
</style>
<script>
// تطبيق الوضع المحفوظ قبل رسم الصفحة (لمنع وميض اللون الخاطئ) — الافتراضي: نهاري
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

  <!-- ══ Header ══ -->
  <div class="auth-header">
    <?php if ($siteLogoUrl): ?>
    <div class="auth-logo-big"><img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>"></div>
    <?php else: ?>
    <div class="auth-logo-big gradient">🚀</div>
    <?php endif; ?>
    <div class="auth-header-title"><?= $show2FA ? 'التحقق بخطوتين' : 'مرحباً بك' ?></div>
    <div class="auth-header-sub"><?= $show2FA ? 'أدخل رمز المصادقة الثنائية من تطبيقك' : 'سجل دخولك لبدء الشحن' ?></div>
  </div>

  <?php if ($devicePending): ?>
  <!-- ══ شاشة جهاز غير مصرح ══ -->
  <div class="device-pending">
    <div class="dp-icon">🔒</div>
    <h3>جهاز غير مصرح</h3>
    <p>تم التحقق من بياناتك بنجاح، لكن هذا المتصفح غير مسجّل.</p>
    <?php if (!empty($pendingInfo['email_sent'])): ?>
    <div class="device-info" style="border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.06)">
      <div class="di-lbl" style="color:#22c55e"><i class="fas fa-envelope-circle-check"></i> تم إرسال رابط تأكيد</div>
      <div class="di-row">أرسلنا رابط تصريح فوري إلى <strong><?= htmlspecialchars($pendingInfo['email_hint'] ?? '') ?></strong>. افتح البريد واضغط "تصريح الجهاز" لتسجيل الدخول مباشرة.</div>
    </div>
    <?php else: ?>
    <p style="color:var(--text2);font-size:.82rem">يرجى التواصل مع الدعم الفني لتفعيله.</p>
    <?php endif; ?>
    <div class="device-info">
      <div class="di-lbl"><i class="fas fa-info-circle"></i> المعلومات المرسلة للدعم</div>
      <div class="di-row"><strong>اسم المستخدم:</strong> <?= htmlspecialchars($pendingInfo['username'] ?? '') ?></div>
      <div class="di-row"><strong>رقم الجهاز:</strong> <span style="font-family:monospace;font-size:.74rem;word-break:break-all"><?= htmlspecialchars($pendingInfo['device_id'] ?? '') ?></span></div>
    </div>
    <?php
      $waMsg  = urlencode("مرحبا، أنا من موقع {$siteName}\nاسم المستخدم: " . ($pendingInfo['username'] ?? '') . "\nرقم الجهاز: " . ($pendingInfo['device_id'] ?? '') . "\nأرجو تصريح الجهاز.");
      $waLink = "https://wa.me/{$supportWhatsapp}?text={$waMsg}";
    ?>
    <a href="<?= $waLink ?>" target="_blank" rel="noopener" class="wa-btn">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="white">
        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413z"/>
        <path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.556 4.112 1.528 5.836L.057 24l6.305-1.654A11.954 11.954 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.894a9.875 9.875 0 0 1-5.012-1.367l-.36-.214-3.741.98 1.001-3.648-.236-.374A9.869 9.869 0 0 1 2.106 12C2.106 6.535 6.535 2.106 12 2.106S21.894 6.535 21.894 12 17.465 21.894 12 21.894z"/>
      </svg>
      تواصل مع الدعم عبر واتساب
    </a>
    <a href="login.php" class="back-link"><i class="fas fa-arrow-right"></i> العودة لتسجيل الدخول</a>
  </div>

  <?php else: ?>

  <?php if ($error): ?>
  <div class="auth-error"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <?php if ($show2FA): ?>
  <!-- ══ شاشة المصادقة الثنائية (2FA) — مطابقة لمنطق api/auth.php ══ -->
  <form method="POST" id="twoFaForm" autocomplete="off" class="auth-form">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="device_id" id="deviceIdInput">

    <div class="auth-field">
      <div class="auth-field-label"><i class="fas fa-shield-alt"></i> رمز التحقق (6 أرقام)</div>
      <div class="auth-2fa-row" id="loginOtpRow">
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1" autofocus>
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1">
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1">
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1">
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1">
        <input type="text" class="auth-2fa-digit" inputmode="numeric" maxlength="1">
      </div>
      <input type="hidden" name="totp_code" id="totpCodeHidden">
      <div style="text-align:center;margin-top:10px;font-size:.75rem;color:var(--text3)">
        الرمز يتجدد كل <span id="totpTimer" style="color:var(--cyan);font-weight:800;font-family:monospace">30s</span>
      </div>
    </div>

    <button type="submit" class="auth-submit" id="twoFaBtn">
      <i class="fas fa-spinner spin"></i>
      <span class="btn-text"><i class="fas fa-check-circle"></i> تأكيد</span>
    </button>

    <div class="auth-switch" style="margin-top:16px">
      <a href="login.php" style="color:var(--text3)"><i class="fas fa-arrow-right"></i> العودة لتسجيل الدخول</a>
    </div>
  </form>

  <?php elseif (bfIsLocked($pdo)): ?>
  <!-- ══ شاشة الحظر المؤقت ══ -->
  <div class="lockout-box">
    <div class="lk-icon">⏳</div>
    <p>تم تعليق المحاولات مؤقتاً.<br>يمكنك المحاولة مجدداً بعد <strong><?= ceil(bfSecsLeft($pdo)/60) ?> دقيقة</strong>.</p>
  </div>

  <?php else: ?>
  <!-- ══ نموذج تسجيل الدخول ══ -->
  <form method="POST" id="loginForm" autocomplete="off" class="auth-form">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <!-- اسم الدخول -->
    <div class="auth-field">
      <div class="auth-field-label"><i class="fas fa-user"></i> اسم الدخول أو البريد</div>
      <div class="auth-input-wrap">
        <input class="auth-input" type="text" name="login"
               value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
               placeholder="أدخل اسم المستخدم أو البريد"
               autocomplete="username" required autofocus>
        <i class="fas fa-user auth-input-icon"></i>
      </div>
    </div>

    <!-- كلمة المرور -->
    <div class="auth-field">
      <div class="auth-field-label"><i class="fas fa-lock"></i> كلمة المرور</div>
      <div class="auth-input-wrap">
        <input class="auth-input" type="password" name="password" id="passInput"
               placeholder="أدخل كلمة المرور"
               autocomplete="current-password" required>
        <i class="fas fa-lock auth-input-icon"></i>
        <i class="fas fa-eye auth-pw-toggle" id="pwToggle" onclick="togglePw()"></i>
      </div>
    </div>

    <!-- رقم الجهاز -->
    <div class="auth-field">
      <div class="auth-field-label"><i class="fas fa-fingerprint" style="color:#a78bfa"></i> رقم الجهاز</div>
      <div class="auth-input-wrap">
        <input class="auth-input device-input" type="text" name="device_id" id="deviceIdInput"
               placeholder="يُحدَّد تلقائياً..." readonly>
        <i class="fas fa-mobile-alt auth-input-icon" style="color:#a78bfa"></i>
      </div>
    </div>

    <?php if ($bfAttempts > 0 && $bfAttempts < BF_MAX_ATTEMPTS): ?>
    <div class="bf-warning">
      <i class="fas fa-exclamation-triangle"></i>
      تبقى <?= BF_MAX_ATTEMPTS - $bfAttempts ?> محاولة قبل التعليق المؤقت.
    </div>
    <?php endif; ?>

    <button type="submit" class="auth-submit" id="loginBtn">
      <i class="fas fa-spinner spin"></i>
      <span class="btn-text"><i class="fas fa-sign-in-alt"></i> تسجيل الدخول</span>
    </button>

    <?php if (getSetting('google_login_enabled')): ?>
    <div class="auth-divider">أو</div>
    <a href="<?= SITE_URL ?>/auth/google/redirect.php" class="auth-google-btn">
      <svg width="18" height="18" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      المتابعة عبر Google
    </a>
    <?php endif; ?>

    <div class="auth-switch">ليس لديك حساب؟ <a href="register.php">إنشاء حساب جديد</a></div>
    <div class="auth-forgot"><a href="forgot-password.php">نسيت كلمة المرور؟</a></div>
  </form>
  <?php endif; ?>
  <?php endif; ?>

</div><!-- /.login-card -->

<script>
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

<script>
/* Device ID */
(function(){
  const K = '_njaz_did';
  let d = localStorage.getItem(K);
  if(!d){
    d = 'did_'+([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g,c=>
      (c^crypto.getRandomValues(new Uint8Array(1))[0]&15>>c/4).toString(16));
    localStorage.setItem(K,d);
  }
  const i = document.getElementById('deviceIdInput');
  if(i) i.value = d;
})();

function togglePw(){
  const i = document.getElementById('passInput');
  const t = document.getElementById('pwToggle');
  i.type = i.type==='password'?'text':'password';
  t.className = 'fas fa-'+(i.type==='password'?'eye':'eye-slash')+' auth-pw-toggle';
}

const lf = document.getElementById('loginForm');
if(lf) lf.addEventListener('submit',function(){
  const b = document.getElementById('loginBtn');
  if(b) b.classList.add('loading');
});

/* ══ 2FA: تعبئة device_id + تنقل تلقائي بين الخانات + مؤقّت + إرسال تلقائي ══ */
(function(){
  const form = document.getElementById('twoFaForm');
  if (!form) return;

  const did = document.getElementById('deviceIdInput');
  if (did) did.value = localStorage.getItem('_njaz_did') || '';

  const inputs = Array.from(document.querySelectorAll('#loginOtpRow .auth-2fa-digit'));
  const hidden = document.getElementById('totpCodeHidden');

  function syncHidden(){ hidden.value = inputs.map(i => i.value).join(''); }

  inputs.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
      const val = e.target.value.replace(/\D/g, '');
      e.target.value = val;
      if (val && idx < inputs.length - 1) inputs[idx + 1].focus();
      syncHidden();
      if (hidden.value.length === 6) form.submit();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        inputs[idx - 1].focus();
        inputs[idx - 1].value = '';
        syncHidden();
      }
    });
    inp.addEventListener('paste', e => {
      e.preventDefault();
      const p = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
      p.split('').forEach((ch, i) => { if (inputs[i]) inputs[i].value = ch; });
      const last = inputs[Math.min(p.length, inputs.length) - 1];
      if (last) last.focus();
      syncHidden();
      if (hidden.value.length === 6) setTimeout(() => form.submit(), 100);
    });
  });
  if (inputs[0]) inputs[0].focus();

  form.addEventListener('submit', () => {
    const b = document.getElementById('twoFaBtn');
    if (b) b.classList.add('loading');
  });

  // مؤقّت 30 ثانية (تجدد رمز TOTP)
  function tick(){
    const sec = 30 - (Math.floor(Date.now() / 1000) % 30);
    const el = document.getElementById('totpTimer');
    if (el) {
      el.textContent = sec + 's';
      el.style.color = sec <= 5 ? '#ff4455' : 'var(--cyan)';
    }
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
