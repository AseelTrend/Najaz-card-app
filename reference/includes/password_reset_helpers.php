<?php
/**
 * password_reset_helpers.php
 * ─────────────────────────────────────────────────────────────
 * دوال مشتركة بين forgot-password.php (العام) و admin/password_reset.php (الإدارة)
 */

require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_smtp_profiles.php';
require_once __DIR__ . '/email_template_helper.php';

/**
 * توليد كلمة مرور عشوائية قوية باستخدام random_int() (آمنة تشفيرياً بالكامل).
 * تضمن وجود حرف كبير + حرف صغير + رقم + رمز خاص على الأقل.
 * تتفادى الحروف/الأرقام المتشابهة بصرياً (0/O, 1/l/I) لتسهيل كتابتها يدوياً عند الحاجة.
 */
function generateStrongPassword(int $length = 12): string
{
    if ($length < 8) $length = 8;

    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower   = 'abcdefghijkmnopqrstuvwxyz';
    $digits  = '23456789';
    $symbols = '!@#$%^&*-_=+';
    $all     = $upper . $lower . $digits . $symbols;

    $required = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    $slots = array_fill(0, $length, null);

    // نضع الأحرف الإلزامية في مواضع عشوائية غير متكررة (كل اختيار عبر random_int)
    $freePositions = range(0, $length - 1);
    foreach ($required as $ch) {
        $pick = random_int(0, count($freePositions) - 1);
        $pos  = $freePositions[$pick];
        array_splice($freePositions, $pick, 1);
        $slots[$pos] = $ch;
    }

    // نملأ بقية المواضع بأحرف عشوائية من المجموعة الكاملة
    foreach ($slots as $i => $val) {
        if ($val === null) {
            $slots[$i] = $all[random_int(0, strlen($all) - 1)];
        }
    }

    return implode('', $slots);
}

/**
 * يبني عنوان بريد "كلمة مرور جديدة"، مع دعم تخصيص الأدمن للعنوان.
 */
function buildResetEmailSubject(string $siteName): string
{
    $default = "كلمة مرور جديدة - {$siteName}";
    return getEmailCustomText('reset', 'subject', $default);
}

/**
 * يبني محتوى بريد "كلمة المرور الجديدة" بصيغة HTML عربية متجاوبة مع الجوال.
 */
function buildResetEmailHtml(string $siteName, string $userName, string $newPassword, string $loginUrl): string
{
    $safeSite = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safePass = htmlspecialchars($newPassword, ENT_QUOTES, 'UTF-8');
    $safeUrl  = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
    $year     = date('Y');
    $logoBlock = emailLogoBlockHtml('reset', '🔑', '#3b82f6', '#22d3ee');

    $introRaw = getEmailCustomText('reset', 'intro', 'تلقّينا طلباً لاستعادة كلمة المرور الخاصة بحسابك في {site}. تم إنشاء كلمة مرور جديدة لك تلقائياً:');
    $introHtml = nl2br(str_replace(['{site}', '{name}'], [$safeSite, $safeName], htmlspecialchars($introRaw, ENT_QUOTES, 'UTF-8')));

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0b1120;font-family:Tahoma,Arial,sans-serif;">
  <div style="max-width:480px;margin:0 auto;padding:28px 14px;">
    <div style="background:linear-gradient(180deg,#0f1929 0%,#080c1a 100%);border:1px solid rgba(59,130,246,.25);border-radius:20px;overflow:hidden">
      <div style="padding:26px 24px 6px;text-align:center">
        {$logoBlock}
        <div style="color:#e2e8f5;font-size:19px;font-weight:800;margin-bottom:4px">{$safeSite}</div>
        <div style="color:#7c93b5;font-size:13px">استعادة كلمة المرور</div>
      </div>
      <div style="padding:10px 24px 26px">
        <p style="color:#e2e8f5;font-size:14.5px;line-height:1.9;margin:16px 0 8px">
          مرحباً <strong>{$safeName}</strong> 👋
        </p>
        <p style="color:#a9bbd6;font-size:13.5px;line-height:1.9;margin:0 0 18px">
          {$introHtml}
        </p>
        <div style="background:rgba(59,130,246,.08);border:1.5px dashed rgba(59,130,246,.4);border-radius:12px;padding:16px;text-align:center;margin-bottom:18px">
          <div style="color:#7c93b5;font-size:11px;margin-bottom:6px">كلمة المرور الجديدة</div>
          <div style="color:#22d3ee;font-size:20px;font-weight:800;letter-spacing:2px;font-family:monospace;direction:ltr;unicode-bidi:plaintext">{$safePass}</div>
        </div>
        <div style="text-align:center;margin-bottom:20px">
          <a href="{$safeUrl}" style="display:inline-block;background:linear-gradient(135deg,#3b82f6,#0a3fbe);color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;padding:13px 34px;border-radius:12px">
            تسجيل الدخول الآن
          </a>
        </div>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:12px 14px">
          <div style="color:#fbbf24;font-size:12.5px;font-weight:700;margin-bottom:4px">⚠️ تنبيه أمني</div>
          <div style="color:#c8d3e6;font-size:12px;line-height:1.8">
            لأسباب أمنية، يُرجى تسجيل الدخول وتغيير كلمة المرور فوراً من صفحتك الشخصية.
            إذا لم تطلب أنت استعادة كلمة المرور، تواصل مع الدعم الفني فوراً.
          </div>
        </div>
      </div>
    </div>
    <div style="text-align:center;color:#3d526e;font-size:11px;margin-top:16px">
      © {$year} {$safeSite} — هذه رسالة تلقائية، الرجاء عدم الرد عليها.
    </div>
  </div>
</body>
</html>
HTML;
}

/**
 * الحصول على IP الحقيقي للزائر (مطابق لنفس الأسلوب في device_helper.php / login.php)
 */
if (!function_exists('getClientIP')) {
    function getClientIP(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}
