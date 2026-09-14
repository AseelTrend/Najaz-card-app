<?php
// api/mobile/google_auth.php — تسجيل/تسجيل جديد عبر Google للتطبيق
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/device_helper.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';

apiRateLimit($pdo, 'mobile_google_auth', 10, 60);

$action   = trim($_POST['action'] ?? 'login');
$idToken  = trim($_POST['id_token'] ?? '');
$deviceId = trim($_POST['device_id'] ?? '');
$phone    = trim($_POST['phone'] ?? '');

if (!$idToken) {
    jsonOutMobile(false, 'تعذر التحقق من حساب Google', [], 400);
}

$clientId = trim((string)getSetting('google_client_id'));
$enabled  = getSetting('google_login_enabled');
if (!$enabled || !$clientId) {
    jsonOutMobile(false, 'تسجيل الدخول عبر Google غير مفعّل حالياً', [], 503);
}

// نتحقق من ID Token من Google مباشرة على السيرفر.
// هذا يمنع الوثوق بأي email/name يرسله التطبيق بنفسه.
$tokenUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 15,
]);
$raw = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

$info = json_decode($raw ?: '', true);
if (!$info || !empty($curlError) || empty($info['sub']) || empty($info['email'])) {
    jsonOutMobile(false, 'رمز Google غير صالح أو منتهي', [], 401);
}

// يجب أن يكون الـ token موجهاً إلى Client ID الخاص بالمشروع.
if (($info['aud'] ?? '') !== $clientId) {
    jsonOutMobile(false, 'رمز Google غير صالح لهذا التطبيق', [], 401);
}

$email = mb_strtolower(trim((string)$info['email']));
if (($info['email_verified'] ?? 'false') !== 'true') {
    jsonOutMobile(false, 'يجب استخدام بريد Google موثّق', [], 401);
}

$googleId = (string)$info['sub'];
$fullName = trim((string)($info['name'] ?? ''));
$avatar   = trim((string)($info['picture'] ?? ''));

// تسجيل دخول: ابحث أولاً بـ Google ID ثم بالبريد.
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id=? AND status=1 LIMIT 1");
$stmt->execute([$googleId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? AND status=1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $pdo->prepare("UPDATE users SET google_id=?, google_avatar=? WHERE id=?")
            ->execute([$googleId, $avatar, $user['id']]);
    }
}

if ($action === 'login') {
    if (!$user) {
        jsonOutMobile(true, 'الحساب غير موجود، أكمل التسجيل', [
            'needs_registration' => true,
            'google' => [
                'email' => $email,
                'name' => $fullName,
                'avatar' => $avatar,
            ],
        ]);
    }

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

    $pdo->prepare("UPDATE users SET last_login=NOW(), google_avatar=? WHERE id=?")
        ->execute([$avatar, $user['id']]);

    $token = generateMobileJWT($user['id'], $user['role']);
    jsonOutMobile(true, 'مرحباً ' . ($user['full_name'] ?: $user['username']), [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'uid' => str_pad($user['id'], 6, '0', STR_PAD_LEFT),
            'name' => $user['full_name'] ?: $user['username'],
            'username' => $user['username'],
            'email' => $user['email'],
            'balance' => number_format((float)$user['balance'], 2),
            'role' => $user['role'],
        ],
    ]);
}

if ($action !== 'register') {
    jsonOutMobile(false, 'طلب غير صالح', [], 400);
}

if (!preg_match('/^\+?[0-9]{7,18}$/', $phone)) {
    jsonOutMobile(false, 'رقم الهاتف غير صحيح', [], 400);
}

if ($user) {
    jsonOutMobile(false, 'هذا البريد مرتبط بحساب موجود بالفعل، استخدم تسجيل الدخول عبر Google', [], 409);
}

$chk = $pdo->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
$chk->execute([$phone]);
if ($chk->fetch()) {
    jsonOutMobile(false, 'رقم الهاتف مستخدم بالفعل لحساب آخر', [], 409);
}

// إنشاء اسم مستخدم آمن ومميز للتسجيل عبر Google.
$tempUsername = 'g_' . time() . '_' . random_int(100, 999);
$pdo->prepare("INSERT INTO users (username,email,password,full_name,display_name,phone,google_id,google_avatar,status,role,registration_source,created_at,last_login) VALUES (?,?,?,?,?,?,?,?,1,'customer','google_mobile',NOW(),NOW())")
    ->execute([$tempUsername, $email, '', $fullName, $fullName, $phone, $googleId, $avatar]);

$newId = (int)$pdo->lastInsertId();
$finalUsername = 'g' . $newId;
$pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$finalUsername, $newId]);

checkAndRegisterDevice($pdo, $newId, true, $deviceId);

try {
    if (function_exists('sendNotification')) {
        sendNotification($pdo, $newId, 'system',
            '🎉 مرحباً بك في ' . (getSetting('site_name') ?: SITE_NAME),
            'يسعدنا انضمامك عبر Google! يمكنك الآن تصفح الخدمات وطلبها بكل سهولة.',
            'gift', '#6c3fe0');
    }
} catch (Exception $e) {}

$token = generateMobileJWT($newId, 'customer');
jsonOutMobile(true, 'تم إنشاء حسابك بنجاح!', [
    'token' => $token,
    'user' => [
        'id' => $newId,
        'uid' => str_pad($newId, 6, '0', STR_PAD_LEFT),
        'name' => $fullName ?: $finalUsername,
        'username' => $finalUsername,
        'email' => $email,
        'balance' => '0.00',
        'role' => 'customer',
    ],
], 201);
