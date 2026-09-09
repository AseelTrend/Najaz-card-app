<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/device_helper.php';
require_once __DIR__ . '/../../includes/notifications.php';

// ── جلب إعدادات Google من DB ──────────────────────────────────────────────
$googleClientId     = getSetting('google_client_id');
$googleClientSecret = getSetting('google_client_secret');
$googleEnabled      = getSetting('google_login_enabled');
$redirectUri        = SITE_URL . '/auth/google/callback.php';

if (!$googleEnabled || !$googleClientId || !$googleClientSecret) {
    flashMessage('danger', 'تسجيل الدخول عبر Google غير مفعّل حالياً');
    redirect(SITE_URL . '/login.php');
}

// ── التحقق من state لمنع CSRF ─────────────────────────────────────────────
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['google_oauth_state'] ?? '')) {
    flashMessage('danger', 'طلب غير صحيح، حاول مجدداً');
    redirect(SITE_URL . '/login.php');
}
unset($_SESSION['google_oauth_state']);

// ── فحص وجود code ─────────────────────────────────────────────────────────
if (empty($_GET['code'])) {
    flashMessage('danger', 'فشل تسجيل الدخول عبر Google');
    redirect(SITE_URL . '/login.php');
}

// ── استبدال code بـ access_token ──────────────────────────────────────────
$tokenUrl  = 'https://oauth2.googleapis.com/token';
$tokenData = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => $googleClientId,
    'client_secret' => $googleClientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $tokenData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$tokenRes = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenRes['access_token'])) {
    flashMessage('danger', 'فشل الحصول على رمز Google، حاول مجدداً');
    redirect(SITE_URL . '/login.php');
}

// ── جلب بيانات المستخدم من Google ────────────────────────────────────────
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenRes['access_token']],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$userInfo = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($userInfo['id']) || empty($userInfo['email'])) {
    flashMessage('danger', 'تعذّر جلب بيانات الحساب من Google');
    redirect(SITE_URL . '/login.php');
}

$googleId    = $userInfo['id'];
$email       = $userInfo['email'];
$fullName    = $userInfo['name']        ?? '';
$googleName  = $userInfo['given_name']  ?? '';
$avatar      = $userInfo['picture']     ?? '';
$verified    = $userInfo['verified_email'] ?? false;

// ── البحث عن حساب مرتبط بـ Google ID ─────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id=? AND status=1");
$stmt->execute([$googleId]);
$user = $stmt->fetch();

// ── إذا لم يوجد، ابحث بالإيميل ───────────────────────────────────────────
if (!$user) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND status=1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        // ربط الحساب الموجود بـ Google
        $pdo->prepare("UPDATE users SET google_id=?, google_avatar=?, last_login=NOW() WHERE id=?")
            ->execute([$googleId, $avatar, $user['id']]);
    }
}

// ── إذا لم يوجد حساب → اطلب إتمام التسجيل ──────────────────────────────────
if (!$user) {
    // احفظ بيانات جوجل في الجلسة وأرسله لصفحة الإتمام
    $_SESSION['google_pending'] = [
        'google_id' => $googleId,
        'email'     => $email,
        'full_name' => $fullName,
        'avatar'    => $avatar,
    ];
    redirect(SITE_URL . '/complete_profile.php');
    exit;
}

// ── تسجيل الدخول ──────────────────────────────────────────────────────────
$deviceStatus = checkAndRegisterDevice($pdo, $user['id'], false);

if ($deviceStatus === 'blocked') {
    flashMessage('danger', 'هذا الجهاز محظور. تواصل مع الإدارة.');
    redirect(SITE_URL . '/login.php');
}

$_SESSION['user_id']  = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role']     = $user['role'];
unset($_SESSION['pending_device_user']);

$pdo->prepare("UPDATE users SET last_login=NOW(), google_avatar=? WHERE id=?")
    ->execute([$avatar, $user['id']]);

redirect(SITE_URL . '/index.php');
