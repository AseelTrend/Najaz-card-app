<?php
/**
 * order_email_helper.php
 * ─────────────────────────────────────────────────────────────
 * إرسال بريد "تأكيد الشراء" للعميل عند اكتمال طلبه، يحتوي على
 * تفاصيل الشراء (الخدمة، الكمية، السعر، رقم الطلب، والكود/الملاحظة
 * إن وُجد). يُستدعى من نقطتين:
 *   - api/place_order_ajax.php  (تسليم فوري من مخزون الأكواد)
 *   - admin/orders.php          (تغيير حالة الطلب يدوياً إلى "مكتمل")
 */

require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_smtp_profiles.php';
require_once __DIR__ . '/email_template_helper.php';

/**
 * يرسل بريد تأكيد شراء لطلب مكتمل. يتحقق من:
 *  - أن الميزة مفعّلة (إعداد purchase_email_enabled، مفعّلة افتراضياً)
 *  - أن العميل لديه بريد صالح
 *  - أنه لم يُرسَل له بريد لنفس الطلب من قبل (لمنع التكرار عند تعديل الحالة مرتين)
 *
 * @param PDO $pdo
 * @param int $orderId
 * @return bool
 */
function sendPurchaseConfirmationEmail(PDO $pdo, int $orderId): bool
{
    // ── الميزة مفعّلة؟ (افتراضياً مفعّلة إلا لو عُطِّلت صراحةً) ──
    if (getSetting('purchase_email_enabled') === '0') return false;

    // ── تفادي التكرار: هل أُرسل بريد ناجح لنفس الطلب من قبل؟ ──
    $chk = $pdo->prepare("SELECT id FROM purchase_email_logs WHERE order_id = ? AND status = 'sent' AND email_type = 'purchase' LIMIT 1");
    $chk->execute([$orderId]);
    if ($chk->fetch()) return false;

    // ── جلب بيانات الطلب + الخدمة + العميل ──
    $stmt = $pdo->prepare("
        SELECT o.*, s.name AS service_name, u.email, u.username, u.full_name
        FROM orders o
        JOIN services s ON o.service_id = s.id
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    if (!$row) return false;

    if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare("INSERT INTO purchase_email_logs (order_id,user_id,email,status,error_message) VALUES (?,?,?,?,?)")
            ->execute([$orderId, $row['user_id'], $row['email'] ?? '', 'skipped', 'لا يوجد بريد إلكتروني صالح للعميل']);
        return false;
    }

    $siteName    = getSetting('site_name') ?: SITE_NAME;
    $displayName = $row['full_name'] ?: $row['username'];
    $orderRef    = formatOrderRefForDisplay($row);
    $subjectRaw  = getEmailCustomText('purchase', 'subject', '🛍️ تأكيد الشراء - طلب {order} - {site}');
    $subject     = str_replace(['{order}', '{site}'], [$orderRef, $siteName], $subjectRaw);
    $html        = buildPurchaseEmailHtml($siteName, $displayName, $row);

    $mailer = getSmtpMailerForSection($pdo, 'purchase');
    $sent   = $mailer->send($row['email'], $displayName, $subject, $html);

    $pdo->prepare("INSERT INTO purchase_email_logs (order_id,user_id,email,status,error_message) VALUES (?,?,?,?,?)")
        ->execute([
            $orderId, $row['user_id'], $row['email'],
            $sent ? 'sent' : 'failed',
            $sent ? null : mb_substr($mailer->lastError, 0, 490),
        ]);

    return $sent;
}

/**
 * يرسل بريد "تم إلغاء طلبك" — يُستدعى عند تغيير حالة الطلب إلى
 * cancelled أو failed. لا يتطلب تسجيل تسليم (لا notes)، ويُظهر
 * سبب الإلغاء إن أدخله الأدمن، ويُطمئن العميل باسترداد المبلغ.
 */
function sendOrderCancelledEmail(PDO $pdo, int $orderId, string $reason = ''): bool
{
    if (getSetting('purchase_email_enabled') === '0') return false;

    // تفادي التكرار: لا نرسل إشعار إلغاء مرتين لنفس الطلب
    $chk = $pdo->prepare("SELECT id FROM purchase_email_logs WHERE order_id = ? AND status = 'sent' AND email_type = 'cancelled' LIMIT 1");
    $chk->execute([$orderId]);
    if ($chk->fetch()) return false;

    $stmt = $pdo->prepare("
        SELECT o.*, s.name AS service_name, u.email, u.username, u.full_name
        FROM orders o
        JOIN services s ON o.service_id = s.id
        JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    if (!$row) return false;

    if (empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $pdo->prepare("INSERT INTO purchase_email_logs (order_id,user_id,email,status,error_message,email_type) VALUES (?,?,?,?,?,'cancelled')")
            ->execute([$orderId, $row['user_id'], $row['email'] ?? '', 'skipped', 'لا يوجد بريد إلكتروني صالح للعميل']);
        return false;
    }

    $siteName    = getSetting('site_name') ?: SITE_NAME;
    $displayName = $row['full_name'] ?: $row['username'];
    $orderRef    = formatOrderRefForDisplay($row);
    $subjectRaw  = getEmailCustomText('cancelled', 'subject', '❌ تم إلغاء طلبك {order} - {site}');
    $subject     = str_replace(['{order}', '{site}'], [$orderRef, $siteName], $subjectRaw);
    $html        = buildOrderCancelledEmailHtml($siteName, $displayName, $row, $reason);

    $mailer = getSmtpMailerForSection($pdo, 'cancelled');
    $sent   = $mailer->send($row['email'], $displayName, $subject, $html);

    $pdo->prepare("INSERT INTO purchase_email_logs (order_id,user_id,email,status,error_message,email_type) VALUES (?,?,?,?,?,'cancelled')")
        ->execute([
            $orderId, $row['user_id'], $row['email'],
            $sent ? 'sent' : 'failed',
            $sent ? null : mb_substr($mailer->lastError, 0, 490),
        ]);

    return $sent;
}

/**
 * يبني محتوى بريد "تم إلغاء الطلب" — نفس هوية باقي الرسائل، بلون
 * تحذيري بدل الأخضر، ويوضّح أن المبلغ يُرد لرصيد المحفظة تلقائياً.
 */
function buildOrderCancelledEmailHtml(string $siteName, string $userName, array $order, string $reason = ''): string
{
    $safeSite    = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $safeName    = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeService = htmlspecialchars($order['service_name'], ENT_QUOTES, 'UTF-8');
    $safeOrderId = htmlspecialchars(formatOrderRefForDisplay($order), ENT_QUOTES, 'UTF-8');
    $safeTotal   = number_format((float)$order['total_price'], 2);
    $safeDate    = htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');
    $year        = date('Y');
    $logoBlock   = emailLogoBlockHtml('cancelled', '❌', '#ef4444', '#991b1b');

    $introRaw  = getEmailCustomText('cancelled', 'intro', 'نأسف لإبلاغك بأنه تم إلغاء طلبك التالي:');
    $introHtml = nl2br(str_replace(['{site}', '{name}'], [$safeSite, $safeName], htmlspecialchars($introRaw, ENT_QUOTES, 'UTF-8')));

    $reasonHtml = '';
    if (trim($reason) !== '') {
        $safeReason = nl2br(htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8'));
        $reasonHtml = <<<HTML
        <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:12px 14px;margin-bottom:18px">
          <div style="color:#f87171;font-size:11.5px;font-weight:700;margin-bottom:5px">سبب الإلغاء</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:1.8">{$safeReason}</div>
        </div>
        HTML;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0b1120;font-family:Tahoma,Arial,sans-serif;">
  <div style="max-width:480px;margin:0 auto;padding:28px 14px;">
    <div style="background:linear-gradient(180deg,#0f1929 0%,#080c1a 100%);border:1px solid rgba(239,68,68,.25);border-radius:20px;overflow:hidden">
      <div style="padding:26px 24px 6px;text-align:center">
        {$logoBlock}
        <div style="color:#e2e8f5;font-size:19px;font-weight:800;margin-bottom:4px">{$safeSite}</div>
        <div style="color:#7c93b5;font-size:13px">إلغاء الطلب</div>
      </div>
      <div style="padding:10px 24px 26px">
        <p style="color:#e2e8f5;font-size:14.5px;line-height:1.9;margin:16px 0 8px">
          مرحباً <strong>{$safeName}</strong>
        </p>
        <p style="color:#a9bbd6;font-size:13.5px;line-height:1.9;margin:0 0 18px">
          {$introHtml}
        </p>
        <div style="background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.2);border-radius:12px;padding:14px 16px;margin-bottom:16px">
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>رقم الطلب:</strong> <span style="direction:ltr;unicode-bidi:plaintext;font-family:monospace">{$safeOrderId}</span></div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الخدمة:</strong> {$safeService}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>المبلغ:</strong> {$safeTotal}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>تاريخ الإلغاء:</strong> {$safeDate}</div>
        </div>
        {$reasonHtml}
        <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:12px 14px">
          <div style="color:#22c55e;font-size:12.5px;font-weight:700;margin-bottom:4px">💰 استرداد المبلغ</div>
          <div style="color:#c8d3e6;font-size:12px;line-height:1.8">تم إرجاع كامل المبلغ إلى رصيد محفظتك في الموقع تلقائياً، ويمكنك استخدامه لأي طلب آخر فوراً.</div>
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
 * يبني محتوى بريد "تأكيد الشراء" بصيغة HTML عربية، بنفس الطراز
 * البصري لبقية رسائل الموقع (استعادة كلمة المرور / تأكيد الجهاز).
 */
function buildPurchaseEmailHtml(string $siteName, string $userName, array $order): string
{
    $safeSite    = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $safeName    = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $safeService = htmlspecialchars($order['service_name'], ENT_QUOTES, 'UTF-8');
    $safeOrderId = htmlspecialchars(formatOrderRefForDisplay($order), ENT_QUOTES, 'UTF-8');
    $safeQty     = (int)$order['quantity'];
    $safeTotal   = number_format((float)$order['total_price'], 2);
    $safeDate    = htmlspecialchars(date('Y-m-d H:i', strtotime($order['updated_at'] ?? $order['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8');
    $year        = date('Y');
    $logoBlock   = emailLogoBlockHtml('purchase', '🛍️', '#22c55e', '#0f7a3d');

    $introRaw  = getEmailCustomText('purchase', 'intro', 'تم تنفيذ طلبك بنجاح. إليك تفاصيل عملية الشراء:');
    $introHtml = nl2br(str_replace(['{site}', '{name}'], [$safeSite, $safeName], htmlspecialchars($introRaw, ENT_QUOTES, 'UTF-8')));

    // إن وُجدت ملاحظة تسليم (كود، بيانات حساب، إلخ) نعرضها بأمان
    $notesHtml = '';
    if (!empty($order['notes'])) {
        $safeNotes = nl2br(htmlspecialchars($order['notes'], ENT_QUOTES, 'UTF-8'));
        $notesHtml = <<<HTML
        <div style="background:rgba(34,197,94,.08);border:1.5px dashed rgba(34,197,94,.35);border-radius:12px;padding:14px 16px;margin-bottom:18px">
          <div style="color:#22c55e;font-size:11.5px;font-weight:700;margin-bottom:6px">📦 تفاصيل التسليم</div>
          <div style="color:#c8d3e6;font-size:13px;line-height:1.9;direction:ltr;unicode-bidi:plaintext;text-align:left;font-family:monospace">{$safeNotes}</div>
        </div>
        HTML;
    }

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
        <div style="color:#7c93b5;font-size:13px">تأكيد عملية الشراء</div>
      </div>
      <div style="padding:10px 24px 26px">
        <p style="color:#e2e8f5;font-size:14.5px;line-height:1.9;margin:16px 0 8px">
          مرحباً <strong>{$safeName}</strong> 👋
        </p>
        <p style="color:#a9bbd6;font-size:13.5px;line-height:1.9;margin:0 0 18px">
          {$introHtml}
        </p>
        <div style="background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.2);border-radius:12px;padding:14px 16px;margin-bottom:16px">
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>رقم الطلب:</strong> <span style="direction:ltr;unicode-bidi:plaintext;font-family:monospace">{$safeOrderId}</span></div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الخدمة:</strong> {$safeService}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الكمية:</strong> {$safeQty}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>الإجمالي:</strong> {$safeTotal}</div>
          <div style="color:#c8d3e6;font-size:12.5px;line-height:2"><strong>التاريخ:</strong> {$safeDate}</div>
        </div>
        {$notesHtml}
        <p style="color:#7c93b5;font-size:12px;line-height:1.8;margin:0">
          يمكنك مراجعة كل تفاصيل طلباتك في أي وقت من صفحة "طلباتي" داخل حسابك.
        </p>
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
