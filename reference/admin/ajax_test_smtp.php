<?php
// admin/ajax_test_smtp.php — إرسال بريد اختباري للتحقق من صحة إعدادات SMTP
require_once '../includes/config.php';
require_once '../includes/password_reset_helpers.php';
require_once '../includes/email_smtp_profiles.php';
requireStaffOrAdmin($pdo, 'perm_settings');

header('Content-Type: application/json');

// حماية CSRF بسيطة (نفس رمز الحماية العام للوحة الإدارة)
$submittedCsrf = $_POST['_csrf'] ?? '';
if (!hash_equals($_SESSION['_admin_csrf'] ?? '', $submittedCsrf)) {
    echo json_encode(['success' => false, 'error' => 'رمز الحماية غير صالح، أعد تحميل الصفحة وحاول مجدداً.']);
    exit;
}

$to = trim($_POST['test_email'] ?? '');
$section = trim((string)($_POST['section_key'] ?? ''));
$profileId = (int)($_POST['profile_id'] ?? 0);
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'يرجى إدخال بريد إلكتروني صحيح للاختبار.']);
    exit;
}

$siteName = getSetting('site_name') ?: SITE_NAME;
$subject  = "✅ بريد اختباري - {$siteName}";
$html = '<div style="font-family:Tahoma,Arial,sans-serif;direction:rtl;text-align:right;padding:24px;background:#0b1120;color:#e2e8f5">'
      . '<h2 style="color:#3b82f6;margin-bottom:10px">اختبار إعدادات SMTP</h2>'
      . "<p>إذا وصلتك هذه الرسالة، فإعدادات SMTP الخاصة بموقع <strong>{$siteName}</strong> تعمل بنجاح ✅</p>"
      . '<p style="color:#7c93b5;font-size:12px;margin-top:14px">' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p>'
      . '</div>';

if ($profileId > 0) {
    $profile = getEmailSmtpProfile($pdo, $profileId, true);
    if (!$profile) {
        echo json_encode(['success' => false, 'error' => 'حساب SMTP غير موجود أو غير مفعّل.']);
        exit;
    }
    $mailer = emailSmtpMailerFromProfile($profile);
} elseif ($section !== '' && array_key_exists($section, emailSmtpProfileSections())) {
    $mailer = getSmtpMailerForSection($pdo, $section);
} else {
    $mailer = getSmtpMailer();
}
$ok = $mailer->send($to, '', $subject, $html);

if ($ok) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $mailer->lastError]);
}
