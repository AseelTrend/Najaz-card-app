<?php
/**
 * email_template_helper.php
 * ─────────────────────────────────────────────────────────────
 * عناصر مشتركة بين كل قوالب البريد (استعادة كلمة المرور، تأكيد
 * الجهاز، تأكيد/إلغاء الشراء، التسويق) حتى يتحكم الأدمن بها من
 * مكان واحد بدل تكرارها داخل كل قالب.
 */

/**
 * يبني كتلة الشعار العلوية لأي بريد، بترتيب أولوية:
 *   1) شعار خاص بهذا القسم بالذات (email_logo_url_{section})
 *   2) الشعار العام المشترك (email_logo_url)
 *   3) أيقونة/إيموجي افتراضية داخل مربع متدرّج الألوان (حل احتياطي دائماً يعمل)
 *
 * @param string $section        مفتاح القسم: reset | device | purchase | cancelled | marketing
 * @param string $fallbackEmoji  الإيموجي الافتراضي إن لم يوجد أي شعار
 * @param string $gradFrom       لون بداية التدرّج (Hex) للمربع الافتراضي
 * @param string $gradTo         لون نهاية التدرّج (Hex) للمربع الافتراضي
 */
function emailLogoBlockHtml(string $section = '', string $fallbackEmoji = '📧', string $gradFrom = '#3b82f6', string $gradTo = '#22d3ee'): string
{
    $logoUrl = '';
    if ($section !== '') {
        $logoUrl = trim((string)(getSetting('email_logo_url_' . $section) ?: ''));
    }
    if ($logoUrl === '') {
        $logoUrl = trim((string)(getSetting('email_logo_url') ?: ''));
    }

    if ($logoUrl !== '' && filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        $safeUrl = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
        <img src="{$safeUrl}" alt="" width="56" height="56" style="width:56px;height:56px;border-radius:16px;margin:0 auto 14px;display:block;object-fit:cover">
        HTML;
    }

    $safeEmoji = htmlspecialchars($fallbackEmoji, ENT_QUOTES, 'UTF-8');
    return <<<HTML
    <div style="width:56px;height:56px;background:linear-gradient(135deg,{$gradFrom},{$gradTo});border-radius:16px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px;line-height:56px">{$safeEmoji}</div>
    HTML;
}

/**
 * يجلب نصاً قابلاً للتخصيص من الأدمن لقسم بريد معيّن (عنوان الرسالة
 * أو نص المقدمة)، ويرجع القيمة الافتراضية تلقائياً إن لم يُخصِّص
 * الأدمن شيئاً بعد. بهذا كل قسم يعمل بشكل صحيح من أول لحظة، والأدمن
 * يستبدل النص وقتما يريد من لوحة الإدارة.
 *
 * @param string $section  مفتاح القسم: reset | device | purchase | cancelled
 * @param string $field    subject | intro
 * @param string $default  القيمة الافتراضية إن لم يُخصَّص شيء
 */
function getEmailCustomText(string $section, string $field, string $default): string
{
    $val = trim((string)(getSetting("email_{$field}_{$section}") ?: ''));
    return $val !== '' ? $val : $default;
}

/**
 * يبني رقم الطلب المعروض بنفس صيغة الموقع بالضبط:
 * ref_id (مثل ID_A1B2C3D4) إن وُجد، وإلا ORD-000123.
 * هذا يضمن تطابق رقم الطلب في البريد مع الرقم الظاهر لصفحة "طلباتي".
 */
function formatOrderRefForDisplay(array $order): string
{
    if (!empty($order['ref_id'])) {
        return strtoupper($order['ref_id']);
    }
    return 'ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT);
}
