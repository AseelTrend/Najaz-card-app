<?php
/**
 * device_confirm.php — يُفتَح من رابط بريد "تأكيد جهاز جديد".
 * GET:
 *   ?action=approve&token=xxxx  → يصرّح الجهاز (status='approved')
 *   ?action=block&token=xxxx    → يحظر الجهاز (status='blocked')
 * لا يتطلب تسجيل دخول (الرابط نفسه هو التوثيق)، والتوكن يُستهلَك مرة واحدة فقط.
 */

require_once 'includes/config.php';
require_once 'includes/device_confirm_helpers.php';

$pageTitle = 'تأكيد الجهاز - ' . SITE_NAME;
$action    = $_GET['action'] ?? '';
$token     = trim($_GET['token'] ?? '');

$state   = 'invalid'; // invalid | approved | blocked | already
$message = 'الرابط غير صالح أو منتهي الصلاحية.';

if (in_array($action, ['approve', 'block'], true) && $token) {
    $device = findDeviceByConfirmToken($pdo, $token);

    if (!$device) {
        $state   = 'invalid';
        $message = 'انتهت صلاحية هذا الرابط أو تم استخدامه مسبقاً. إذا كنت لا تزال بحاجة لتصريح الجهاز، حاول تسجيل الدخول مجدداً وسنرسل رابطاً جديداً.';
    } elseif ($device['status'] !== 'pending') {
        // تم اتخاذ قرار بشأن هذا الجهاز مسبقاً (من صفحة الحساب مثلاً)
        clearDeviceConfirmToken($pdo, (int)$device['id']);
        $state   = 'already';
        $message = $device['status'] === 'approved'
            ? 'هذا الجهاز مصرَّح بالفعل، يمكنك تسجيل الدخول مباشرة.'
            : 'هذا الجهاز محظور بالفعل.';
    } else {
        $newStatus = $action === 'approve' ? 'approved' : 'blocked';
        $pdo->prepare("UPDATE user_devices SET status = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$newStatus, $device['id']]);
        clearDeviceConfirmToken($pdo, (int)$device['id']);

        if ($action === 'approve') {
            $state   = 'approved';
            $message = 'تم تصريح الجهاز بنجاح. يمكنك الآن تسجيل الدخول من هذا الجهاز مباشرة.';
        } else {
            $state   = 'blocked';
            $message = 'تم حظر الجهاز بنجاح. لن يتمكن أي شخص من الدخول لحسابك من هذا الجهاز.';
        }
    }
}

$icon = [
    'approved' => '✅',
    'blocked'  => '🚫',
    'already'  => 'ℹ️',
    'invalid'  => '⚠️',
][$state];

$color = [
    'approved' => '#22c55e',
    'blocked'  => '#f87171',
    'already'  => '#60a5fa',
    'invalid'  => '#fbbf24',
][$state];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
</head>
<body style="margin:0;padding:0;background:#0b1120;font-family:Tahoma,Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center">
  <div style="max-width:420px;width:100%;margin:24px;padding:8px">
    <div style="background:linear-gradient(180deg,#0f1929 0%,#080c1a 100%);border:1px solid rgba(59,130,246,.25);border-radius:20px;padding:36px 26px;text-align:center">
      <div style="font-size:52px;margin-bottom:16px"><?= $icon ?></div>
      <div style="color:<?= $color ?>;font-size:18px;font-weight:800;margin-bottom:10px">
        <?= htmlspecialchars(SITE_NAME) ?>
      </div>
      <p style="color:#c8d3e6;font-size:14px;line-height:1.9;margin:0 0 24px"><?= htmlspecialchars($message) ?></p>
      <a href="<?= SITE_URL ?>/login.php" style="display:inline-block;background:linear-gradient(135deg,#3b82f6,#0a3fbe);color:#fff;text-decoration:none;font-weight:800;font-size:14px;padding:12px 30px;border-radius:12px">
        الذهاب لتسجيل الدخول
      </a>
    </div>
  </div>
</body>
</html>
