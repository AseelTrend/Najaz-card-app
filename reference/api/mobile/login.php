<?php
// api/mobile/login.php
// نفس منطق api/auth.php (login) بالضبط — نفس فحص الجهاز ونفس 2FA —
// الفرق الوحيد: يرجع JWT توكن بدل الاعتماد على كوكيز الجلسة
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/device_helper.php';
require_once dirname(__DIR__, 2) . '/includes/device_confirm_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/totp.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';

apiRateLimit($pdo, 'mobile_login', 10, 60);

$login    = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';
$totpCode = preg_replace('/\s/', '', $_POST['totp_code'] ?? '');
$deviceId = trim($_POST['device_id'] ?? ''); // معرّف ثابت يولّده التطبيق ويخزّنه محلياً

if (!$login || !$password) {
    jsonOutMobile(false, 'أدخل اسم المستخدم وكلمة المرور', [], 400);
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status=1 LIMIT 1");
$stmt->execute([$login, $login]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    jsonOutMobile(false, 'بيانات غير صحيحة، تحقق وأعد المحاولة', [], 401);
}

// ── 2FA ──
if ($user['totp_enabled'] && $user['totp_secret']) {
    if (empty($totpCode)) {
        jsonOutMobile(false, 'أدخل رمز المصادقة الثنائية', ['need_2fa' => true], 200);
    }
    if (!TOTP::verify($user['totp_secret'], $totpCode)) {
        jsonOutMobile(false, 'رمز المصادقة غير صحيح أو منتهي', ['need_2fa' => true], 200);
    }
}

// ── فحص الجهاز (نفس منطق الموقع تماماً) ──
$deviceAutoApprove = getSetting('device_auto_approve');
if ($deviceAutoApprove) {
    checkAndRegisterDevice($pdo, $user['id'], true, $deviceId);
    $deviceStatus = 'approved';
} else {
    $deviceStatus = checkAndRegisterDevice($pdo, $user['id'], false, $deviceId);
}

if ($deviceStatus === 'blocked') {
    jsonOutMobile(false, 'هذا الجهاز محظور. تواصل مع الإدارة.', ['device_blocked' => true], 403);
}
if ($deviceStatus === 'pending') {
    jsonOutMobile(false, 'جهاز غير مصرح، تحقق من بريدك أو واتساب لتفعيله', ['device_pending' => true], 403);
}

$pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

$token = generateMobileJWT($user['id'], $user['role']);

jsonOutMobile(true, 'مرحباً ' . ($user['full_name'] ?: $user['username']), [
    'token'   => $token,
    'user'    => [
        'id'       => $user['id'],
        'uid'      => str_pad($user['id'], 6, '0', STR_PAD_LEFT),
        'name'     => $user['full_name'] ?: $user['username'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'balance'  => number_format((float)$user['balance'], 2),
        'role'     => $user['role'],
    ],
]);
