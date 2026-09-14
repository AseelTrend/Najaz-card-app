<?php
/**
 * device_confirm_helpers.php
 * ─────────────────────────────────────────────────────────────
 * تأكيد الجهاز الجديد عبر البريد الإلكتروني — بنفس فلسفة استعادة
 * كلمة المرور (password_reset_helpers.php): رابط بتوكن عشوائي آمن،
 * صالح لمدة محدودة، يُخزَّن الـ hash فقط في قاعدة البيانات (وليس
 * التوكن نفسه)، ويُستهلَك مرة واحدة.
 */

require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_smtp_profiles.php';
require_once __DIR__ . '/email_template_helper.php';

// مدة صلاحية رابط التأكيد (بالثواني) — ساعة واحدة
const DEVICE_CONFIRM_TOKEN_TTL = 3600;

// حد أدنى بين إيميلين لنفس الجهاز (بالثواني) لتفادي إغراق العميل برسائل
// متكررة لو حاول الدخول عدة مرات متتالية من نفس الجهاز غير المصرح
const DEVICE_CONFIRM_EMAIL_COOLDOWN = 300; // 5 دقائق

/**
 * يولّد توكن عشوائي آمن، يخزّن الـ hash + تاريخ الانتهاء في السجل،
 * ويرسل بريد التأكيد. يعيد true عند نجاح الإرسال، false عند الفشل
 * أو التخطي (بريد غير موجود / تم الإرسال حديثاً).
 *
 * @param PDO   $pdo
 * @param array $user     صف المستخدم من جدول users (يحتاج id, email, username/full_name)
 * @param int   $deviceRowId  معرّف السجل في user_devices (id، وليس fingerprint)
 * @param array $deviceInfo   ['device_name'=>..., 'browser'=>..., 'os'=>..., 'ip_address'=>...]
 */
function sendDeviceConfirmEmail(PDO $pdo, array $user, int $deviceRowId, array $deviceInfo): bool
{
    if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return false; // لا يوجد بريد صالح لإرساله
    }

    // ── تفادي الإغراق: تحقق من آخر إرسال لنفس سجل الجهاز ──
    $chk = $pdo->prepare("SELECT confirm_email_sent_at FROM user_devices WHERE id = ?");
    $chk->execute([$deviceRowId]);
    $lastSent = $chk->fetchColumn();
    if ($lastSent && (time() - strtotime($lastSent)) < DEVICE_CONFIRM_EMAIL_COOLDOWN) {
        return false; // تم الإرسال حديثاً، لا نكرر
    }

    // ── توليد توكن عشوائي آمن (يُرسَل بالرابط) ونخزّن hash منه فقط ──
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires   = time() + DEVICE_CONFIRM_TOKEN_TTL;

    $pdo->prepare("
        UPDATE user_devices
        SET confirm_token_hash = ?, confirm_token_expires = ?, confirm_email_sent_at = NOW()
        WHERE id = ?
    ")->execute([$tokenHash, $expires, $deviceRowId]);

    $siteName    = getSetting('site_name') ?: SITE_NAME;
    $displayName = $user['full_name'] ?: ($user['username'] ?? '');
    $approveUrl  = SITE_URL . '/device_confirm.php?action=approve&token=' . $token;
    $blockUrl    = SITE_URL . '/device_confirm.php?action=block&token=' . $token;

    $subject = getEmailCustomText('device', 'subject', "🔐 تسجيل دخول من جهاز جديد - {$siteName}");
    $html    = buildDeviceConfirmEmailHtml($siteName, $displayName, $deviceInfo, $approveUrl, $blockUrl);

    $mailer = getSmtpMailerForSection($pdo, 'device');
    $sent   = $mailer->send($user['email'], $displayName, $subject, $html);

    return $sent;
}

/**
 * يتحقق من توكن رابط التأكيد ويعيد سجل الجهاز المطابق (أو null).
 * لا يستهلك التوكن — الاستهلاك (مسح الـ hash) يتم بعد اتخاذ القرار
 * في device_confirm.php نفسها.
 */
function findDeviceByConfirmToken(PDO $pdo, string $token): ?array
{
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT ud.*, u.email, u.username, u.full_name
        FROM user_devices ud
        JOIN users u ON u.id = ud.user_id
        WHERE ud.confirm_token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) return null;

    if ((int)$row['confirm_token_expires'] < time()) return null; // منتهي الصلاحية

    return $row;
}

/**
 * يمسح توكن التأكيد بعد استخدامه (نجاحاً كان أو فشلاً) لمنع إعادة الاستخدام.
 */
function clearDeviceConfirmToken(PDO $pdo, int $deviceRowId): void
{
    $pdo->prepare("
        UPDATE user_devices
        SET confirm_token_hash = NULL, confirm_token_expires = NULL
        WHERE id = ?
    ")->execute([$deviceRowId]);
}

/**
 * يبني محتوى بريد "تأكيد جهاز جديد" بصيغة HTML عربية، بنفس الطراز
 * البصري المستخدم في buildResetEmailHtml() لضمان اتساق الهوية.
 */
function buildDeviceConfirmEmailHtml(string $siteName, string $userName, array $deviceInfo, string $approveUrl, string $blockUrl): string
{
    $safeSite    = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $safeName    = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeApprove = htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8');
    $safeBlock   = htmlspecialchars($blockUrl, ENT_QUOTES, 'UTF-8');
    $safeDevice  = htmlspecialchars($deviceInfo['device_name'] ?? 'جهاز غير معروف', ENT_QUOTES, 'UTF-8');
    $safeIp      = htmlspecialchars($deviceInfo['ip_address'] ?? '—', ENT_QUOTES, 'UTF-8');
    $safeTime    = htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');
    $year        = date('Y');
    $logoBlock   = emailLogoBlockHtml('device', '🔐', '#3b82f6', '#22d3ee');

    $introRaw  = getEmailCustomText('device', 'intro', 'تم رصد محاولة تسجيل دخول لحسابك في {site} من جهاز غير مصرَّح سابقاً:');
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
        <div style="color:#7c93b5;font-size:13px">محاولة تسجيل دخول من جهاز جديد</div>
      </div>
      <div style="padding:10px 24px 26px">
        <p style="color:#e2e8f5;font-size:14.5px;line-height:1.9;margin:16px 0 8px">
          مرحباً <strong>{$safeName}</strong> 👋
        </p>
        <p style="color:#a9bbd6;font-size:13.5px;line-height:1.9;margin:0 0 18px">
          {$introHtml}
        </p>
        <div style="background:rgba(59,130,246,.08);border:1.5px solid rgba(59,130,246,.25);border-radius:12px;padding:14px 16px;margin-bottom:18px">
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الجهاز:</strong> {$safeDevice}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>عنوان IP:</strong> <span style="direction:ltr;unicode-bidi:plaintext;font-family:monospace">{$safeIp}</span></div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الوقت:</strong> {$safeTime}</div>
        </div>
        <p style="color:#a9bbd6;font-size:13px;line-height:1.9;margin:0 0 16px">
          إذا كانت المحاولة منك، اضغط "تصريح الجهاز" لتفعيله فوراً. إذا لم تكن أنت، اضغط "ليس أنا" لحظر الجهاز ومنعه من الدخول.
        </p>
        <div style="text-align:center;margin-bottom:14px">
          <a href="{$safeApprove}" style="display:inline-block;background:linear-gradient(135deg,#22c55e,#0f7a3d);color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;padding:13px 30px;border-radius:12px;margin-bottom:10px">
            ✅ تصريح الجهاز — هذا أنا
          </a>
        </div>
        <div style="text-align:center;margin-bottom:20px">
          <a href="{$safeBlock}" style="display:inline-block;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#f87171;text-decoration:none;font-weight:700;font-size:13px;padding:11px 26px;border-radius:12px">
            🚫 ليس أنا — حظر الجهاز
          </a>
        </div>
        <div style="background:rgba(251,191,36,.08);border:1px solid rgba(251,191,36,.25);border-radius:10px;padding:12px 14px">
          <div style="color:#fbbf24;font-size:12.5px;font-weight:700;margin-bottom:4px">⚠️ تنبيه أمني</div>
          <div style="color:#c8d3e6;font-size:12px;line-height:1.8">
            هذا الرابط صالح لمدة ساعة واحدة فقط. إذا لم تطلب أنت هذا الدخول ولم تضغط "ليس أنا"، ننصح بتغيير كلمة المرور فوراً والتواصل مع الدعم الفني.
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
