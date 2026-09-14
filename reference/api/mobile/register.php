<?php
// api/mobile/register.php
// نفس منطق api/auth.php (register) بالضبط — يرجع JWT بدل الجلسة
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/device_helper.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';
if (file_exists(dirname(__DIR__, 2) . '/includes/referral.php')) {
    require_once dirname(__DIR__, 2) . '/includes/referral.php';
}

apiRateLimit($pdo, 'mobile_register', 5, 60);

$username     = trim($_POST['username']     ?? '');
$referralCode = trim($_POST['referral_code'] ?? '');
$email        = trim($_POST['email']        ?? '');
$full_name    = trim($_POST['full_name']    ?? '');
$phone        = trim($_POST['phone']        ?? '');
$password     = $_POST['password']  ?? '';
$password2    = $_POST['password2'] ?? '';
$deviceId     = trim($_POST['device_id'] ?? '');

if (strlen($username) < 3) jsonOutMobile(false, 'اسم المستخدم 3 أحرف على الأقل', [], 400);
if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) jsonOutMobile(false, 'اسم المستخدم يجب أن يحتوي على حروف إنجليزية وأرقام فقط', [], 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonOutMobile(false, 'البريد الإلكتروني غير صحيح', [], 400);
if (strlen($password) < 6) jsonOutMobile(false, 'كلمة المرور 6 أحرف على الأقل', [], 400);
if ($password !== $password2) jsonOutMobile(false, 'كلمتا المرور غير متطابقتين', [], 400);

$dup = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
$dup->execute([$username, $email]);
if ($dup->fetch()) jsonOutMobile(false, 'اسم المستخدم أو البريد مستخدم مسبقاً', [], 409);

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO users (username,email,password,full_name,display_name,phone,registration_source) VALUES (?,?,?,?,?,?,?)")
    ->execute([$username, $email, $hash, $full_name, $full_name, $phone, 'mobile_app']);

$newId = $pdo->lastInsertId();

// أول جهاز يُعتمد تلقائياً (نفس منطق الموقع)
checkAndRegisterDevice($pdo, $newId, true, $deviceId);

if (function_exists('generateReferralCode')) generateReferralCode($pdo, $newId);

$welcomeBonus = 0;
if ($referralCode && function_exists('applyReferralOnRegister')) {
    applyReferralOnRegister($pdo, $newId, $referralCode);
    $welcomeBonus = (float)getSetting('referral_welcome');
}

try {
    if (function_exists('sendNotification')) {
        sendNotification($pdo, $newId, 'system',
            '🎉 مرحباً بك في ' . (getSetting('site_name') ?: SITE_NAME),
            'يسعدنا انضمامك عبر التطبيق! يمكنك الآن تصفح الخدمات وطلبها بكل سهولة.',
            'gift', '#6c3fe0');
    }
} catch (Exception $e) {}

$token = generateMobileJWT($newId, 'customer');

jsonOutMobile(true, 'تم إنشاء حسابك بنجاح! مرحباً ' . ($full_name ?: $username), [
    'token' => $token,
    'user'  => [
        'id'       => $newId,
        'uid'      => str_pad($newId, 6, '0', STR_PAD_LEFT),
        'name'     => $full_name ?: $username,
        'username' => $username,
        'email'    => $email,
        'balance'  => number_format($welcomeBonus, 2),
        'role'     => 'customer',
    ],
], 201);
