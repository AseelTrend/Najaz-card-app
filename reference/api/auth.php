<?php
// ══════════════════════════════════════
// معالجة طلبات Auth عبر AJAX
// ══════════════════════════════════════
require_once '../includes/config.php';
require_once '../includes/device_helper.php';
require_once '../includes/device_confirm_helpers.php';
require_once '../includes/notifications.php';
require_once '../includes/rate_limiter.php'; // [M-3 FIX]
if (file_exists(dirname(__DIR__).'/includes/referral.php'))
    require_once dirname(__DIR__).'/includes/referral.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';

// [M-3 FIX] Rate Limiting — login: 10 محاولات/دقيقة | register: 5/دقيقة
if ($action === 'login')    apiRateLimit($pdo, 'auth_login',    10, 60);
if ($action === 'register') apiRateLimit($pdo, 'auth_register',  5, 60);

// ── تسجيل الدخول ──
if ($action === 'login') {
    require_once '../includes/totp.php';
    $login    = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $totpCode = preg_replace('/\s/', '', $_POST['totp_code'] ?? '');

    if (empty($login) || empty($password)) {
        echo json_encode(['ok'=>false,'msg'=>'أدخل اسم المستخدم وكلمة المرور']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status=1 LIMIT 1");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {

        // ── 2FA مطلوب؟ ────────────────────────────────────────────────────
        if ($user['totp_enabled'] && $user['totp_secret']) {
            if (empty($totpCode)) {
                // كلمة المرور صحيحة، ننتظر رمز 2FA
                echo json_encode([
                    'ok'         => false,
                    'need_2fa'   => true,
                    'msg'        => 'أدخل رمز المصادقة الثنائية من تطبيقك',
                ]);
                exit;
            }
            if (!TOTP::verify($user['totp_secret'], $totpCode)) {
                echo json_encode([
                    'ok'       => false,
                    'need_2fa' => true,
                    'msg'      => 'رمز المصادقة غير صحيح أو منتهي الصلاحية',
                ]);
                exit;
            }
        }

        // ── فحص الجهاز ────────────────────────────────────────────────────────
        $clientDeviceId = trim($_POST['device_id'] ?? '');
        $deviceAutoApprove = getSetting('device_auto_approve');
        if ($deviceAutoApprove) {
            checkAndRegisterDevice($pdo, $user['id'], true, $clientDeviceId);
            $deviceStatus = 'approved';
        } else {
            $deviceStatus = checkAndRegisterDevice($pdo, $user['id'], false, $clientDeviceId);
        }

        if ($deviceStatus === 'blocked') {
            echo json_encode(['ok'=>false,'msg'=>'هذا الجهاز محظور. تواصل مع الإدارة.','device_blocked'=>true]);
            exit;
        }
        if ($deviceStatus === 'pending') {
            // ── إرسال بريد تأكيد الجهاز (بنفس طريقة استعادة كلمة المرور) ──
            $emailSent = false;
            $emailHint = '';
            try {
                $devStmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=? LIMIT 1");
                $devStmt->execute([$user['id'], $clientDeviceId ?: ('ua_' . hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')))]);
                $deviceRow = $devStmt->fetch();
                if ($deviceRow) {
                    $emailSent = sendDeviceConfirmEmail($pdo, $user, (int)$deviceRow['id'], [
                        'device_name' => $deviceRow['device_name'],
                        'ip_address'  => $deviceRow['ip_address'],
                    ]);
                    if ($emailSent && !empty($user['email']) && str_contains($user['email'], '@')) {
                        [$local, $domain] = explode('@', $user['email'], 2);
                        $emailHint = mb_substr($local, 0, 2) . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
                    }
                }
            } catch (Throwable $e) {
                $emailSent = false; // فشل البريد لا يكسر الاستجابة — واتساب يبقى بديلاً
            }

            echo json_encode([
                'ok'              => false,
                'msg'             => 'جهاز غير مصرح',
                'device_pending'  => true,
                'pending_user'    => $user['username'],
                'pending_did'     => $clientDeviceId ?: 'ua_fallback',
                'email_sent'      => $emailSent,
                'email_hint'      => $emailHint,
            ]);
            exit;
        }

        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
        echo json_encode([
            'ok'      => true,
            'msg'     => 'مرحباً ' . ($user['full_name'] ?: $user['username']),
            'role'    => $user['role'],
            'balance' => number_format($user['balance'], 2),
            'name'    => $user['full_name'] ?: $user['username'],
            'uid'     => str_pad($user['id'],6,'0',STR_PAD_LEFT),
        ]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'بيانات غير صحيحة، تحقق وأعد المحاولة']);
    }
    exit;
}

// ── إنشاء حساب ──
if ($action === 'register') {
    $username     = trim($_POST['username']     ?? '');
    $referralCode = trim($_POST['referral_code'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($username) < 3)
        { echo json_encode(['ok'=>false,'msg'=>'اسم المستخدم 3 أحرف على الأقل']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username))
        { echo json_encode(['ok'=>false,'msg'=>'اسم المستخدم يجب أن يحتوي على حروف إنجليزية وأرقام فقط']); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        { echo json_encode(['ok'=>false,'msg'=>'البريد الإلكتروني غير صحيح']); exit; }
    if (strlen($password) < 6)
        { echo json_encode(['ok'=>false,'msg'=>'كلمة المرور 6 أحرف على الأقل']); exit; }
    if ($password !== $password2)
        { echo json_encode(['ok'=>false,'msg'=>'كلمتا المرور غير متطابقتين']); exit; }

    $dup = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
    $dup->execute([$username, $email]);
    if ($dup->fetch())
        { echo json_encode(['ok'=>false,'msg'=>'اسم المستخدم أو البريد مستخدم مسبقاً']); exit; }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    // أضف display_name ومصدر التسجيل إن لم يكونا موجودين
    $hasRegistrationSource = false;
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
        if (!in_array('display_name', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `full_name`");
        }
        if (!in_array('registration_source', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `registration_source` VARCHAR(50) NULL DEFAULT NULL");
        }
        $hasRegistrationSource = true;
    } catch (Throwable $e) {}

    if ($hasRegistrationSource) {
        $pdo->prepare("INSERT INTO users (username,email,password,full_name,display_name,phone,registration_source) VALUES (?,?,?,?,?,?,?)")
            ->execute([$username,$email,$hash,$full_name,$full_name,$phone,'website']);
    } else {
        // توافق احتياطي مع استضافة لا تسمح بإضافة العمود تلقائياً
        $pdo->prepare("INSERT INTO users (username,email,password,full_name,display_name,phone) VALUES (?,?,?,?,?,?)")
            ->execute([$username,$email,$hash,$full_name,$full_name,$phone]);
    }
    $newId = $pdo->lastInsertId();

    // تسجيل الجهاز الأول تلقائياً كـ approved
    $regDeviceId = trim($_POST['device_id'] ?? '');
    checkAndRegisterDevice($pdo, $newId, true, $regDeviceId);

    // توليد كود الإحالة للعضو الجديد
    if (function_exists('generateReferralCode')) generateReferralCode($pdo, $newId);

    // تطبيق الإحالة إذا جاء بكود
    $welcomeBonus = 0;
    if ($referralCode && function_exists('applyReferralOnRegister')) {
        applyReferralOnRegister($pdo, $newId, $referralCode);
        $welcomeBonus = (float)getSetting('referral_welcome');
    }

    $_SESSION['user_id']  = $newId;
    $_SESSION['username'] = $username;
    $_SESSION['role']     = 'customer';

    try {
        sendNotification($pdo, $newId, 'system',
            '🎉 مرحباً بك في ' . (getSetting('site_name') ?: SITE_NAME),
            'يسعدنا انضمامك! يمكنك الآن تصفح الخدمات وطلبها بكل سهولة.',
            'gift', '#6c3fe0');
        notifyAdminNewRegister($pdo, $username, $email);
    } catch (Exception $e) {}

    echo json_encode([
        'ok'      => true,
        'msg'     => 'تم إنشاء حسابك بنجاح! مرحباً ' . ($full_name ?: $username),
        'role'    => 'customer',
        'balance' => number_format($welcomeBonus, 2),
        'name'    => $full_name ?: $username,
        'uid'     => str_pad($newId,6,'0',STR_PAD_LEFT),
    ]);
    exit;
}

// ── تسجيل الخروج ──
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'طلب غير معروف']);
