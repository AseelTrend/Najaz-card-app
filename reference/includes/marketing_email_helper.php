<?php
/**
 * marketing_email_helper.php
 * ─────────────────────────────────────────────────────────────
 * إرسال حملات تسويقية عبر البريد للعملاء. بما أن smtp_mailer.php
 * يرسل كل رسالة عبر اتصال SMTP منفصل (بدون طوابير خلفية)، الإرسال
 * لأعداد كبيرة يجب أن يتم على دفعات صغيرة عبر طلبات AJAX متكررة من
 * المتصفح (يستدعيها لوحة الإدارة تلقائياً حتى تكتمل القائمة) بدل
 * محاولة إرسالها كلها بطلب واحد، لتفادي انتهاء مهلة تنفيذ PHP
 * (max_execution_time) على الاستضافة المشتركة.
 */

require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/email_smtp_profiles.php';
require_once __DIR__ . '/email_template_helper.php';

/** عدد الرسائل المُرسَلة في كل دفعة AJAX واحدة */
const MARKETING_BATCH_SIZE = 5;

/**
 * يبني قائمة السجل (queue) لحملة جديدة بناءً على نوع الفلتر،
 * ويخزّنها في email_campaign_recipients. تُستدعى مرة واحدة عند
 * إنشاء الحملة.
 */
function buildCampaignRecipients(PDO $pdo, int $campaignId, string $filterType): int
{
    $where = "u.status = 1 AND u.is_deleted = 0 AND u.email IS NOT NULL AND u.email != '' AND u.role = 'customer'";

    if ($filterType === 'with_balance') {
        $where .= " AND u.balance > 0";
    } elseif ($filterType === 'no_orders') {
        $where .= " AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)";
    }
    // 'all' و 'customers' يستخدمان نفس الشرط الأساسي أعلاه (كل العملاء الفعّالين)

    $users = $pdo->query("SELECT id, email FROM users u WHERE {$where}")->fetchAll();

    if (!$users) return 0;

    $insert = $pdo->prepare("INSERT INTO email_campaign_recipients (campaign_id, user_id, email, status) VALUES (?,?,?,'queued')");
    foreach ($users as $u) {
        $insert->execute([$campaignId, $u['id'], $u['email']]);
    }

    return count($users);
}

/**
 * يرسل دفعة واحدة (حتى MARKETING_BATCH_SIZE رسالة) من حملة معينة.
 * يُستدعى بشكل متكرر عبر AJAX من لوحة الإدارة حتى تنتهي القائمة.
 *
 * @return array{done:bool, sent:int, failed:int, remaining:int}
 */
function sendCampaignBatch(PDO $pdo, int $campaignId): array
{
    $campaign = $pdo->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $campaign->execute([$campaignId]);
    $campaign = $campaign->fetch();

    if (!$campaign) return ['done' => true, 'sent' => 0, 'failed' => 0, 'remaining' => 0];

    if ($campaign['status'] === 'draft') {
        $pdo->prepare("UPDATE email_campaigns SET status='sending' WHERE id=?")->execute([$campaignId]);
    }

    $batch = $pdo->prepare("
        SELECT * FROM email_campaign_recipients
        WHERE campaign_id = ? AND status = 'queued'
        LIMIT " . (int)MARKETING_BATCH_SIZE
    );
    $batch->execute([$campaignId]);
    $recipients = $batch->fetchAll();

    if (!$recipients) {
        // انتهت القائمة
        $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaignId]);
        $remaining = 0;
        $done = true;
    } else {
        $siteName = getSetting('site_name') ?: SITE_NAME;
        $mailer   = getSmtpMailerForSection($pdo, 'marketing');
        $sentNow  = 0;
        $failedNow = 0;

        foreach ($recipients as $r) {
            $html = wrapMarketingEmailHtml($siteName, $campaign['body_html']);
            $ok   = $mailer->send($r['email'], '', $campaign['subject'], $html);

            if ($ok) {
                $pdo->prepare("UPDATE email_campaign_recipients SET status='sent', sent_at=NOW() WHERE id=?")->execute([$r['id']]);
                $sentNow++;
            } else {
                $pdo->prepare("UPDATE email_campaign_recipients SET status='failed', error_message=? WHERE id=?")
                    ->execute([mb_substr($mailer->lastError, 0, 490), $r['id']]);
                $failedNow++;
            }
        }

        $pdo->prepare("UPDATE email_campaigns SET sent_count = sent_count + ?, failed_count = failed_count + ? WHERE id = ?")
            ->execute([$sentNow, $failedNow, $campaignId]);

        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM email_campaign_recipients WHERE campaign_id=? AND status='queued'");
        $remainingStmt->execute([$campaignId]);
        $remaining = (int)$remainingStmt->fetchColumn();
        $done = $remaining === 0;

        if ($done) {
            $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaignId]);
        }
    }

    $fresh = $pdo->prepare("SELECT sent_count, failed_count FROM email_campaigns WHERE id=?");
    $fresh->execute([$campaignId]);
    $totals = $fresh->fetch();

    return [
        'done'      => $done,
        'sent'      => (int)$totals['sent_count'],
        'failed'    => (int)$totals['failed_count'],
        'remaining' => $remaining,
    ];
}

/**
 * يغلّف محتوى الحملة (اللي يكتبه الأدمن كـ HTML حر) بإطار بصري
 * موحّد مطابق لهوية بقية رسائل الموقع.
 */
function wrapMarketingEmailHtml(string $siteName, string $bodyHtml): string
{
    $safeSite = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $year     = date('Y');
    $logoBlock = emailLogoBlockHtml('marketing', '📢', '#8b5cf6', '#5b21b6');

    return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#0b1120;font-family:Tahoma,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;padding:28px 14px;">
    <div style="background:linear-gradient(180deg,#0f1929 0%,#080c1a 100%);border:1px solid rgba(59,130,246,.25);border-radius:20px;overflow:hidden">
      <div style="padding:22px 24px 4px;text-align:center">
        {$logoBlock}
        <div style="color:#e2e8f5;font-size:18px;font-weight:800">{$safeSite}</div>
      </div>
      <div style="padding:14px 24px 26px;color:#c8d3e6;font-size:13.5px;line-height:1.9">
        {$bodyHtml}
      </div>
    </div>
    <div style="text-align:center;color:#3d526e;font-size:11px;margin-top:16px">
      © {$year} {$safeSite}
    </div>
  </div>
</body>
</html>
HTML;
}
