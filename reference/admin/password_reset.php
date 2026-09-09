<?php
require_once '../includes/config.php';
require_once '../includes/password_reset_helpers.php';
require_once '../includes/device_confirm_helpers.php';
require_once '../includes/order_email_helper.php';
require_once '../includes/marketing_email_helper.php';
require_once '../includes/email_template_helper.php';
require_once '../includes/email_smtp_profiles.php';
requireStaffOrAdmin($pdo, 'perm_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN  = isAdmin();
$pageTitle = 'مركز البريد الإلكتروني - ' . SITE_NAME;

/**
 * يبني حقول HTML لتخصيص قسم بريد معيّن: شعار خاص (مع معاينة حية)،
 * عنوان الرسالة، ونص المقدمة. تُستخدم داخل نفس الفورم في كل تبويب.
 *
 * @param string $section        reset | device | purchase | cancelled | marketing
 * @param array  $data            ['logo_url'=>, 'subject'=>, 'intro'=>]
 * @param string $defaultEmoji    الأيقونة الافتراضية لمعاينة القسم لو ما فيه شعار
 * @param string|null $defaultSubject  نص placeholder لعنوان الرسالة (null = إخفاء الحقل)
 * @param string|null $defaultIntro    نص placeholder لمقدمة الرسالة (null = إخفاء الحقل)
 */
function emailAppearanceFieldsHtml(string $section, array $data, string $defaultEmoji, ?string $defaultSubject = null, ?string $defaultIntro = null): string
{
    $logoUrl = htmlspecialchars($data['logo_url'] ?? '', ENT_QUOTES, 'UTF-8');
    $subject = htmlspecialchars($data['subject'] ?? '', ENT_QUOTES, 'UTF-8');
    $intro   = htmlspecialchars($data['intro'] ?? '', ENT_QUOTES, 'UTF-8');
    $imgId   = "logoPrev_{$section}";
    $fbId    = "logoFallback_{$section}";
    $safeEmoji = htmlspecialchars($defaultEmoji, ENT_QUOTES, 'UTF-8');

    $html = '<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px">';
    $html .= '<div style="flex:1;min-width:240px">';
    $html .= '<label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px"><i class="fas fa-image"></i> شعار خاص بهذا القسم (اختياري)</label>';
    $html .= "<input type=\"url\" name=\"logo_url\" class=\"form-control\" value=\"{$logoUrl}\" placeholder=\"اتركه فارغاً لاستخدام الشعار العام أو الأيقونة الافتراضية\" oninput=\"previewSectionLogo('{$section}')\">";
    $html .= '</div>';
    $html .= '<div style="text-align:center">';
    $html .= '<div style="font-size:11px;color:var(--text3);margin-bottom:6px">معاينة</div>';
    $html .= '<div style="width:56px;height:56px;border-radius:16px;background:#0f1929;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden">';
    $html .= "<img id=\"{$imgId}\" src=\"{$logoUrl}\" style=\"width:100%;height:100%;object-fit:cover;" . ($logoUrl === '' ? 'display:none' : '') . "\" onerror=\"this.style.display='none';document.getElementById('{$fbId}').style.display='block'\">";
    $html .= "<span id=\"{$fbId}\" style=\"font-size:20px;" . ($logoUrl !== '' ? 'display:none' : '') . "\">{$safeEmoji}</span>";
    $html .= '</div></div></div>';

    if ($defaultSubject !== null) {
        $safeDefaultSubject = htmlspecialchars($defaultSubject, ENT_QUOTES, 'UTF-8');
        $html .= '<div class="form-group">';
        $html .= '<label style="font-size:12px"><i class="fas fa-heading"></i> عنوان الرسالة (Subject)</label>';
        $html .= "<input type=\"text\" name=\"subject\" class=\"form-control\" value=\"{$subject}\" placeholder=\"{$safeDefaultSubject}\">";
        $html .= '<div style="font-size:11px;color:var(--text3);margin-top:5px">يمكن استخدام <code>{site}</code>' . (str_contains($defaultSubject, '{order}') ? ' و<code>{order}</code>' : '') . ' داخل النص، وتُستبدَل تلقائياً.</div>';
        $html .= '</div>';
    }
    if ($defaultIntro !== null) {
        $safeDefaultIntro = htmlspecialchars($defaultIntro, ENT_QUOTES, 'UTF-8');
        $html .= '<div class="form-group">';
        $html .= '<label style="font-size:12px"><i class="fas fa-align-right"></i> نص المقدمة (الجملة الأولى داخل الرسالة)</label>';
        $html .= "<textarea name=\"intro\" class=\"form-control\" rows=\"2\" placeholder=\"{$safeDefaultIntro}\">{$intro}</textarea>";
        $html .= '<div style="font-size:11px;color:var(--text3);margin-top:5px">يمكن استخدام <code>{site}</code> و<code>{name}</code> داخل النص، وتُستبدَلان تلقائياً باسم الموقع واسم العميل.</div>';
        $html .= '</div>';
    }

    return $html;
}
function saveEmailAppearance(PDO $pdo, string $section, array $post, array $fields = ['subject','intro','logo_url']): void
{
    $save = function (string $key, string $value) use ($pdo) {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([$key, $value]);
    };

    if (in_array('logo_url', $fields, true)) {
        $logoUrl = trim($post['logo_url'] ?? '');
        if ($logoUrl === '' || filter_var($logoUrl, FILTER_VALIDATE_URL)) {
            $save('email_logo_url_' . $section, $logoUrl);
        }
    }
    if (in_array('subject', $fields, true)) {
        $save('email_subject_' . $section, trim($post['subject'] ?? ''));
    }
    if (in_array('intro', $fields, true)) {
        $save('email_intro_' . $section, trim($post['intro'] ?? ''));
    }
}

$activeTab = $_GET['tab'] ?? 'reset';
if (!in_array($activeTab, ['reset','device','marketing','purchase','smtp'], true)) $activeTab = 'reset';

// ─────────────────────────────────────────────────────────────
// حفظ الإعدادات / تنفيذ الإجراءات
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $formId = $_POST['_form_id'] ?? '';

    // ── [نسيت كلمة المرور] إعدادات عامة ──
    if ($formId === 'general') {
        $enabled = isset($_POST['password_reset_enabled']) ? '1' : '0';
        $hours   = (int)($_POST['password_reset_cooldown_hours'] ?? 24);
        if ($hours < 1) $hours = 1;
        if ($hours > 720) $hours = 720;

        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('password_reset_enabled',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$enabled]);
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('password_reset_cooldown_hours',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([(string)$hours]);

        flashMessage('success', 'تم حفظ إعدادات الميزة بنجاح.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=reset');
    }

    // ── [نسيت كلمة المرور] تخصيص الشعار والرسالة ──
    if ($formId === 'reset_appearance') {
        saveEmailAppearance($pdo, 'reset', $_POST);
        flashMessage('success', 'تم حفظ تخصيص رسالة استعادة كلمة المرور.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=reset');
    }


    if ($formId === 'smtp') {
        $textFields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
        foreach ($textFields as $f) {
            $val = trim($_POST[$f] ?? '');
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$f, $val]);
        }
        if (trim($_POST['smtp_password'] ?? '') !== '') {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('smtp_password',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([trim($_POST['smtp_password'])]);
        }

        // شعار البريد الإلكتروني (اختياري) — رابط صورة مباشر
        $logoUrl = trim($_POST['email_logo_url'] ?? '');
        if ($logoUrl !== '' && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
            flashMessage('danger', 'رابط الشعار غير صالح — تم حفظ باقي الإعدادات، يرجى تصحيح رابط الشعار.');
        } else {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('email_logo_url',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$logoUrl]);
        }

        flashMessage('success', 'تم حفظ إعدادات SMTP بنجاح. هذه الإعدادات مشتركة بين كل خدمات البريد (استعادة كلمة المرور، تأكيد الجهاز، التسويق، تأكيد الشراء).');
        redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
    }

    // ── [حسابات SMTP متعددة] إنشاء أو تعديل حساب ──
    if ($formId === 'smtp_profile_save') {
        if (!emailSmtpProfilesTablesReady($pdo)) {
            flashMessage('danger', 'جداول حسابات SMTP غير موجودة. نفّذ ملف email-smtp-profiles.sql مرة واحدة ثم أعد المحاولة.');
            redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
        }

        $profileId  = (int)($_POST['profile_id'] ?? 0);
        $name       = trim((string)($_POST['profile_name'] ?? ''));
        $host       = trim((string)($_POST['profile_host'] ?? ''));
        $port       = (int)($_POST['profile_port'] ?? 587);
        $username   = trim((string)($_POST['profile_username'] ?? ''));
        $password   = trim((string)($_POST['profile_password'] ?? ''));
        $encryption = (string)($_POST['profile_encryption'] ?? 'tls');
        $fromEmail  = trim((string)($_POST['profile_from_email'] ?? ''));
        $fromName   = trim((string)($_POST['profile_from_name'] ?? ''));
        $active     = isset($_POST['profile_active']) ? 1 : 0;
        $isDefault  = isset($_POST['profile_default']) ? 1 : 0;

        if ($name === '' || $host === '') {
            flashMessage('danger', 'يرجى إدخال اسم الحساب واسم خادم SMTP.');
        } elseif ($port < 1 || $port > 65535) {
            flashMessage('danger', 'منفذ SMTP غير صالح.');
        } elseif (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            flashMessage('danger', 'نوع تشفير SMTP غير صالح.');
        } elseif ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'بريد المرسل في حساب SMTP غير صالح.');
        } else {
            if ($isDefault) {
                $pdo->exec('UPDATE email_smtp_profiles SET is_default = 0');
            }

            if ($profileId > 0) {
                $existing = getEmailSmtpProfile($pdo, $profileId, false);
                if (!$existing) {
                    flashMessage('danger', 'حساب SMTP المطلوب غير موجود.');
                    redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
                }
                if ($password === '') $password = (string)($existing['smtp_password'] ?? '');
                $stmt = $pdo->prepare('UPDATE email_smtp_profiles SET profile_name=?, host=?, port=?, username=?, smtp_password=?, encryption=?, from_email=?, from_name=?, is_active=?, is_default=? WHERE id=?');
                $stmt->execute([$name, $host, $port, $username, $password, $encryption, $fromEmail, $fromName, $active, $isDefault, $profileId]);
                flashMessage('success', 'تم تحديث حساب SMTP بنجاح.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO email_smtp_profiles (profile_name, host, port, username, smtp_password, encryption, from_email, from_name, is_active, is_default) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$name, $host, $port, $username, $password, $encryption, $fromEmail, $fromName, $active, $isDefault]);
                flashMessage('success', 'تم إنشاء حساب SMTP جديد.');
            }
        }
        redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
    }

    // ── [حسابات SMTP متعددة] حذف حساب وربطاته ──
    if ($formId === 'smtp_profile_delete') {
        if (!emailSmtpProfilesTablesReady($pdo)) {
            flashMessage('danger', 'جداول حسابات SMTP غير موجودة.');
        } else {
            $profileId = (int)($_POST['profile_id'] ?? 0);
            if ($profileId > 0) {
                $pdo->prepare('DELETE FROM email_smtp_profiles WHERE id=?')->execute([$profileId]);
                flashMessage('success', 'تم حذف حساب SMTP وربطاته بالأقسام.');
            }
        }
        redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
    }

    // ── [حسابات SMTP متعددة] ربط الحسابات بالأقسام ──
    if ($formId === 'smtp_bindings_save') {
        if (!emailSmtpProfilesTablesReady($pdo)) {
            flashMessage('danger', 'جداول حسابات SMTP غير موجودة. نفّذ ملف email-smtp-profiles.sql مرة واحدة ثم أعد المحاولة.');
            redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
        }

        $profiles = getEmailSmtpProfiles($pdo, true);
        $validIds = [];
        foreach ($profiles as $profile) $validIds[(int)$profile['id']] = true;
        $bindings = is_array($_POST['smtp_binding'] ?? null) ? $_POST['smtp_binding'] : [];
        foreach (emailSmtpProfileSections() as $section => $label) {
            $profileId = (int)($bindings[$section] ?? 0);
            if ($profileId > 0 && isset($validIds[$profileId])) {
                $stmt = $pdo->prepare('INSERT INTO email_smtp_bindings (section_key, profile_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id)');
                $stmt->execute([$section, $profileId]);
            } else {
                $pdo->prepare('DELETE FROM email_smtp_bindings WHERE section_key=?')->execute([$section]);
            }
        }
        flashMessage('success', 'تم حفظ ربط حسابات SMTP بالأقسام. الحساب الواحد يمكن استخدامه في عدة أقسام.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=smtp');
    }

    // ── [نسيت كلمة المرور] إعادة تعيين يدوية ──
    if ($formId === 'manual_reset') {
        if (!$IS_ADMIN) {
            flashMessage('danger', 'هذا الإجراء متاح للمدير فقط.');
            redirect(SITE_URL . '/admin/password_reset.php?tab=reset');
        }
        $identifier = trim($_POST['manual_identifier'] ?? '');
        if ($identifier === '') {
            flashMessage('danger', 'يرجى إدخال البريد الإلكتروني أو اسم المستخدم.');
            redirect(SITE_URL . '/admin/password_reset.php?tab=reset');
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $u = $stmt->fetch();

        if (!$u) {
            flashMessage('danger', 'لم يتم العثور على مستخدم بهذا البريد/الاسم.');
        } elseif (empty($u['email'])) {
            flashMessage('danger', 'هذا الحساب لا يملك بريداً إلكترونياً مسجلاً.');
        } else {
            $newPassword = generateStrongPassword(12);
            $hashed      = password_hash($newPassword, PASSWORD_DEFAULT);
            $displayName = $u['full_name'] ?: $u['username'];
            $siteName    = getSetting('site_name') ?: SITE_NAME;
            $subject     = buildResetEmailSubject($siteName);
            $htmlBody    = buildResetEmailHtml($siteName, $displayName, $newPassword, SITE_URL . '/login.php');

            $mailer = getSmtpMailerForSection($pdo, 'reset');
            $sent   = $mailer->send($u['email'], $displayName, $subject, $htmlBody);

            $pdo->prepare("INSERT INTO password_reset_logs (user_id, username, email, ip, status, error_message) VALUES (?,?,?,?,?,?)")
                ->execute([
                    $u['id'], $u['username'], $u['email'], 'admin-panel',
                    $sent ? 'sent' : 'failed',
                    $sent ? null : mb_substr($mailer->lastError, 0, 490),
                ]);

            if ($sent) {
                $pdo->prepare("UPDATE users SET password = ?, last_password_reset_at = ? WHERE id = ?")
                    ->execute([$hashed, time(), $u['id']]);
                logStaffAction($pdo, 'password_reset_manual', 'user', $u['id'], 'إعادة تعيين كلمة مرور يدوياً لـ ' . $u['username']);
                flashMessage('success', "تم إنشاء كلمة مرور جديدة وإرسالها إلى {$u['email']}");
            } else {
                flashMessage('danger', 'تعذّر إرسال البريد الإلكتروني: ' . $mailer->lastError);
            }
        }
        redirect(SITE_URL . '/admin/password_reset.php?tab=reset');
    }

    // ── [تأكيد الجهاز] إعدادات عامة ──
    if ($formId === 'device_general') {
        $enabled = isset($_POST['device_confirm_email_enabled']) ? '1' : '0';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('device_confirm_email_enabled',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$enabled]);
        flashMessage('success', 'تم حفظ إعدادات تأكيد الجهاز.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=device');
    }

    // ── [تأكيد الجهاز] تخصيص الشعار والرسالة ──
    if ($formId === 'device_appearance') {
        saveEmailAppearance($pdo, 'device', $_POST);
        flashMessage('success', 'تم حفظ تخصيص رسالة تأكيد الجهاز.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=device');
    }

    // ── [تأكيد الشراء] إعدادات عامة ──
    if ($formId === 'purchase_general') {
        $enabled = isset($_POST['purchase_email_enabled']) ? '1' : '0';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('purchase_email_enabled',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$enabled]);
        flashMessage('success', 'تم حفظ إعدادات تأكيد الشراء.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=purchase');
    }

    // ── [تأكيد الشراء] تخصيص الشعار والرسالة ──
    if ($formId === 'purchase_appearance') {
        saveEmailAppearance($pdo, 'purchase', $_POST, ['subject','intro','logo_url']);
        flashMessage('success', 'تم حفظ تخصيص رسالة تأكيد الشراء.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=purchase');
    }

    // ── [إلغاء الشراء] تخصيص الشعار والرسالة ──
    if ($formId === 'cancelled_appearance') {
        saveEmailAppearance($pdo, 'cancelled', $_POST, ['subject','intro','logo_url']);
        flashMessage('success', 'تم حفظ تخصيص رسالة إلغاء الطلب.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=purchase');
    }

    // ── [التسويق] تخصيص الشعار ──
    if ($formId === 'marketing_appearance') {
        saveEmailAppearance($pdo, 'marketing', $_POST, ['logo_url']);
        flashMessage('success', 'تم حفظ شعار رسائل التسويق.');
        redirect(SITE_URL . '/admin/password_reset.php?tab=marketing');
    }

    // ── [التسويق] إنشاء حملة جديدة ──
    if ($formId === 'campaign_create') {
        $subject = trim($_POST['campaign_subject'] ?? '');
        $body    = trim($_POST['campaign_body'] ?? '');
        $filter  = $_POST['campaign_filter'] ?? 'customers';
        if (!in_array($filter, ['all','customers','with_balance','no_orders'], true)) $filter = 'customers';

        if ($subject === '' || $body === '') {
            flashMessage('danger', 'يرجى إدخال عنوان ومحتوى الرسالة.');
            redirect(SITE_URL . '/admin/password_reset.php?tab=marketing');
        }

        $pdo->prepare("INSERT INTO email_campaigns (subject, body_html, filter_type, status, created_by) VALUES (?,?,?, 'draft', ?)")
            ->execute([$subject, $body, $filter, $_SESSION['user_id']]);
        $campaignId = (int)$pdo->lastInsertId();

        $count = buildCampaignRecipients($pdo, $campaignId, $filter);
        $pdo->prepare("UPDATE email_campaigns SET total_recipients=? WHERE id=?")->execute([$count, $campaignId]);

        if ($count === 0) {
            $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaignId]);
            flashMessage('danger', 'لا يوجد مستلمون مطابقون لهذا الفلتر — لم تُنشأ حملة فعلية.');
        } else {
            logStaffAction($pdo, 'campaign_create', 'email_campaign', $campaignId, "إنشاء حملة بريد: {$subject} ({$count} مستلم)");
            flashMessage('success', "تم إنشاء الحملة بـ {$count} مستلم. اضغط 'بدء الإرسال' لبدء الإرسال الفعلي.");
        }
        redirect(SITE_URL . '/admin/password_reset.php?tab=marketing');
    }

    // ── [التسويق] إلغاء حملة قيد الإرسال ──
    if ($formId === 'campaign_cancel') {
        $cid = (int)($_POST['campaign_id'] ?? 0);
        if ($cid) {
            $pdo->prepare("UPDATE email_campaigns SET status='cancelled' WHERE id=? AND status IN ('draft','sending')")->execute([$cid]);
            $pdo->prepare("UPDATE email_campaign_recipients SET status='failed', error_message='تم إلغاء الحملة' WHERE campaign_id=? AND status='queued'")->execute([$cid]);
            flashMessage('success', 'تم إلغاء الحملة.');
        }
        redirect(SITE_URL . '/admin/password_reset.php?tab=marketing');
    }
}

// ─────────────────────────────────────────────────────────────
// بيانات العرض — [نسيت كلمة المرور]
// ─────────────────────────────────────────────────────────────
$featureEnabled = getSetting('password_reset_enabled') !== '0';
$resetAppearance = [
    'logo_url' => getSetting('email_logo_url_reset') ?: '',
    'subject'  => getSetting('email_subject_reset') ?: '',
    'intro'    => getSetting('email_intro_reset') ?: '',
];
$cooldownHours  = (int)(getSetting('password_reset_cooldown_hours') ?: 24);

$smtp = [
    'host'        => getSetting('smtp_host'),
    'port'        => getSetting('smtp_port') ?: '587',
    'username'    => getSetting('smtp_username'),
    'has_pass'    => getSetting('smtp_password') !== '',
    'encryption'  => getSetting('smtp_encryption') ?: 'tls',
    'from_email'  => getSetting('smtp_from_email'),
    'from_name'   => getSetting('smtp_from_name'),
];
$emailLogoUrl = getSetting('email_logo_url') ?: '';

// حسابات SMTP المتعددة — لا تمنع الصفحة من العمل قبل تنفيذ الترحيل.
$smtpProfiles = [];
$smtpBindings = [];
if (emailSmtpProfilesTablesReady($pdo)) {
    $smtpProfiles = getEmailSmtpProfiles($pdo, true);
    $smtpBindings = getEmailSmtpBindings($pdo);
}

$resetStats = ['total' => 0, 'sent' => 0, 'failed' => 0];
try {
    $r = $pdo->query("SELECT COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed FROM password_reset_logs WHERE created_at > (NOW() - INTERVAL 30 DAY)")->fetch();
    if ($r) $resetStats = ['total' => (int)$r['total'], 'sent' => (int)$r['sent'], 'failed' => (int)$r['failed']];
} catch (Exception $e) {}

$resetLogs = [];
try { $resetLogs = $pdo->query("SELECT * FROM password_reset_logs ORDER BY created_at DESC LIMIT 50")->fetchAll(); } catch (Exception $e) {}

// ─────────────────────────────────────────────────────────────
// بيانات العرض — [تأكيد الجهاز]
// ─────────────────────────────────────────────────────────────
$deviceEnabled = getSetting('device_confirm_email_enabled') !== '0';
$deviceAppearance = [
    'logo_url' => getSetting('email_logo_url_device') ?: '',
    'subject'  => getSetting('email_subject_device') ?: '',
    'intro'    => getSetting('email_intro_device') ?: '',
];

$deviceStats = ['emails_sent' => 0, 'approved' => 0, 'blocked' => 0, 'pending' => 0];
try {
    $r = $pdo->query("SELECT COUNT(*) c FROM user_devices WHERE confirm_email_sent_at > (NOW() - INTERVAL 30 DAY)")->fetch();
    $deviceStats['emails_sent'] = (int)($r['c'] ?? 0);
    $r2 = $pdo->query("SELECT SUM(status='approved') approved, SUM(status='blocked') blocked, SUM(status='pending') pending FROM user_devices")->fetch();
    $deviceStats['approved'] = (int)($r2['approved'] ?? 0);
    $deviceStats['blocked']  = (int)($r2['blocked']  ?? 0);
    $deviceStats['pending']  = (int)($r2['pending']  ?? 0);
} catch (Exception $e) {}

$deviceLogs = [];
try {
    $deviceLogs = $pdo->query("
        SELECT ud.*, u.username, u.email
        FROM user_devices ud
        JOIN users u ON u.id = ud.user_id
        WHERE ud.confirm_email_sent_at IS NOT NULL
        ORDER BY ud.confirm_email_sent_at DESC
        LIMIT 50
    ")->fetchAll();
} catch (Exception $e) {}

// ─────────────────────────────────────────────────────────────
// بيانات العرض — [تأكيد الشراء]
// ─────────────────────────────────────────────────────────────
$purchaseEnabled = getSetting('purchase_email_enabled') !== '0';
$purchaseAppearance = [
    'logo_url' => getSetting('email_logo_url_purchase') ?: '',
    'subject'  => getSetting('email_subject_purchase') ?: '',
    'intro'    => getSetting('email_intro_purchase') ?: '',
];
$cancelledAppearance = [
    'logo_url' => getSetting('email_logo_url_cancelled') ?: '',
    'subject'  => getSetting('email_subject_cancelled') ?: '',
    'intro'    => getSetting('email_intro_cancelled') ?: '',
];

$purchaseStats = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
try {
    $r = $pdo->query("SELECT COUNT(*) total, SUM(status='sent') sent, SUM(status='failed') failed, SUM(status='skipped') skipped FROM purchase_email_logs WHERE created_at > (NOW() - INTERVAL 30 DAY)")->fetch();
    if ($r) $purchaseStats = ['total'=>(int)$r['total'],'sent'=>(int)$r['sent'],'failed'=>(int)$r['failed'],'skipped'=>(int)$r['skipped']];
} catch (Exception $e) {}

$purchaseLogs = [];
try {
    $purchaseLogs = $pdo->query("SELECT pl.*, o.total_price, s.name as service_name FROM purchase_email_logs pl LEFT JOIN orders o ON o.id=pl.order_id LEFT JOIN services s ON s.id=o.service_id ORDER BY pl.created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// ─────────────────────────────────────────────────────────────
// بيانات العرض — [التسويق]
// ─────────────────────────────────────────────────────────────
$campaigns = [];
$marketingLogoUrl = getSetting('email_logo_url_marketing') ?: '';
try { $campaigns = $pdo->query("SELECT * FROM email_campaigns ORDER BY created_at DESC LIMIT 30")->fetchAll(); } catch (Exception $e) {}

$eligibleCounts = ['all' => 0, 'customers' => 0, 'with_balance' => 0, 'no_orders' => 0];
try {
    $eligibleCounts['customers']    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND is_deleted=0 AND email IS NOT NULL AND email!='' AND role='customer'")->fetchColumn();
    $eligibleCounts['all']          = $eligibleCounts['customers'];
    $eligibleCounts['with_balance'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status=1 AND is_deleted=0 AND email IS NOT NULL AND email!='' AND role='customer' AND balance>0")->fetchColumn();
    $eligibleCounts['no_orders']    = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.status=1 AND u.is_deleted=0 AND u.email IS NOT NULL AND u.email!='' AND u.role='customer' AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id=u.id)")->fetchColumn();
} catch (Exception $e) {}

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(37,99,235,0.15)"><i class="fas fa-at" style="color:var(--primary)"></i></div>
      مركز البريد الإلكتروني
    </div>
  </div>
</div>

<!-- ════ تبويبات ════ -->
<div class="ec-tabs">
  <a href="?tab=reset"     class="ec-tab <?= $activeTab==='reset'     ? 'active' : '' ?>"><i class="fas fa-key"></i> نسيت كلمة المرور</a>
  <a href="?tab=device"    class="ec-tab <?= $activeTab==='device'    ? 'active' : '' ?>"><i class="fas fa-shield-alt"></i> تأكيد الجهاز</a>
  <a href="?tab=purchase"  class="ec-tab <?= $activeTab==='purchase'  ? 'active' : '' ?>"><i class="fas fa-shopping-bag"></i> تأكيد الشراء</a>
  <a href="?tab=marketing" class="ec-tab <?= $activeTab==='marketing' ? 'active' : '' ?>"><i class="fas fa-bullhorn"></i> التسويق</a>
  <a href="?tab=smtp"      class="ec-tab <?= $activeTab==='smtp'      ? 'active' : '' ?>"><i class="fas fa-server"></i> إعدادات SMTP</a>
</div>

<?php // ═══════════════════════════════════════════════════════════ TAB: نسيت كلمة المرور ═══ ?>
<?php if ($activeTab === 'reset'): ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px">
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">إجمالي الطلبات (30 يوم)</div>
    <div style="font-size:22px;font-weight:900"><?= $resetStats['total'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">تم الإرسال بنجاح</div>
    <div style="font-size:22px;font-weight:900;color:var(--green)"><?= $resetStats['sent'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">فشل الإرسال</div>
    <div style="font-size:22px;font-weight:900;color:var(--red)"><?= $resetStats['failed'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">حالة الميزة</div>
    <div style="font-size:16px;font-weight:900">
      <?php if ($featureEnabled): ?>
        <span style="color:var(--green)"><i class="fas fa-check-circle"></i> مفعّلة</span>
      <?php else: ?>
        <span style="color:var(--red)"><i class="fas fa-times-circle"></i> معطّلة</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px" class="pr-grid">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> إعدادات الميزة</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="general">
        <div class="switch-wrap" style="margin-bottom:18px">
          <label class="switch">
            <input type="checkbox" name="password_reset_enabled" value="1" <?= $featureEnabled ? 'checked' : '' ?>>
            <span class="switch-slider"></span>
          </label>
          <span style="font-weight:700;font-size:.88rem;cursor:pointer" onclick="this.previousElementSibling.querySelector('input').click()">تفعيل ميزة استعادة كلمة المرور</span>
        </div>
        <div class="form-group">
          <label><i class="fas fa-hourglass-half"></i> مدة الانتظار بين كل طلب استعادة (بالساعات)</label>
          <input type="number" name="password_reset_cooldown_hours" class="form-control" min="1" max="720" value="<?= (int)$cooldownHours ?>">
          <div style="font-size:11px;color:var(--text3);margin-top:6px">القيمة الافتراضية: 24 ساعة.</div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ إعدادات الميزة</button>
      </form>
    </div>
  </div>

  <?php if ($IS_ADMIN): ?>
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-user-shield" style="color:var(--gold)"></i> إعادة تعيين يدوية لمستخدم</div></div>
    <div class="card-body">
      <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:14px">إنشاء كلمة مرور جديدة لأي حساب وإرسالها مباشرة إلى بريده، بغض النظر عن مدة الانتظار.</p>
      <form method="POST" onsubmit="return confirm('سيتم إنشاء كلمة مرور جديدة لهذا الحساب وإرسالها فوراً، متابعة؟')">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="manual_reset">
        <div class="form-group">
          <label><i class="fas fa-search"></i> البريد الإلكتروني أو اسم المستخدم</label>
          <input type="text" name="manual_identifier" class="form-control" placeholder="example@email.com أو username" required>
        </div>
        <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> إنشاء كلمة مرور جديدة وإرسالها</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>

<div style="margin-top:16px;margin-bottom:20px">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> سجل طلبات الاستعادة (آخر 50 طلب)</div></div>
    <div class="card-body" style="padding:0">
      <?php if (empty($resetLogs)): ?>
      <div class="empty-state"><i class="fas fa-inbox empty-state-icon"></i><div class="empty-state-title">لا توجد طلبات بعد</div></div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>التاريخ</th><th>المستخدم</th><th>البريد الإلكتروني</th><th>IP</th><th>الحالة</th><th>ملاحظة الخطأ</th></tr></thead>
          <tbody>
            <?php foreach ($resetLogs as $log): ?>
            <tr>
              <td class="td-muted"><?= htmlspecialchars(date('Y/m/d H:i', strtotime($log['created_at']))) ?></td>
              <td class="td-bold"><?= htmlspecialchars($log['username'] ?: '—') ?></td>
              <td class="td-mono"><?= htmlspecialchars($log['email']) ?></td>
              <td class="td-mono"><?= htmlspecialchars($log['ip']) ?></td>
              <td><?php if ($log['status'] === 'sent'): ?><span class="badge badge-success"><i class="fas fa-check"></i> تم الإرسال</span><?php else: ?><span class="badge badge-danger"><i class="fas fa-times"></i> فشل</span><?php endif; ?></td>
              <td class="td-muted" style="max-width:260px;white-space:normal"><?= htmlspecialchars($log['error_message'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paint-brush" style="color:var(--purple)"></i> تخصيص شكل ومحتوى الرسالة</div></div>
  <div class="card-body">
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="reset_appearance">
      <?= emailAppearanceFieldsHtml('reset', $resetAppearance, '🔑', 'كلمة مرور جديدة - {site}', 'تلقّينا طلباً لاستعادة كلمة المرور الخاصة بحسابك في {site}. تم إنشاء كلمة مرور جديدة لك تلقائياً:') ?>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px"><i class="fas fa-save"></i> حفظ التخصيص</button>
    </form>
  </div>
</div>

<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════ TAB: تأكيد الجهاز ═══ ?>
<?php if ($activeTab === 'device'): ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px">
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">رسائل مُرسلة (30 يوم)</div>
    <div style="font-size:22px;font-weight:900"><?= $deviceStats['emails_sent'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">أجهزة مصرَّحة</div>
    <div style="font-size:22px;font-weight:900;color:var(--green)"><?= $deviceStats['approved'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">أجهزة محظورة</div>
    <div style="font-size:22px;font-weight:900;color:var(--red)"><?= $deviceStats['blocked'] ?></div>
  </div>
  <div class="card" style="padding:16px">
    <div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">بانتظار التصريح الآن</div>
    <div style="font-size:22px;font-weight:900;color:var(--gold)"><?= $deviceStats['pending'] ?></div>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> إعدادات تأكيد الجهاز</div></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:14px">
      عند تسجيل الدخول من جهاز/متصفح جديد غير مصرَّح، يُرسَل للعميل بريد يحتوي رابطي "تصريح الجهاز" و"ليس أنا"، بدلاً من انتظار تواصله مع الدعم.
    </p>
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="device_general">
      <div class="switch-wrap" style="margin-bottom:14px">
        <label class="switch">
          <input type="checkbox" name="device_confirm_email_enabled" value="1" <?= $deviceEnabled ? 'checked' : '' ?>>
          <span class="switch-slider"></span>
        </label>
        <span style="font-weight:700;font-size:.88rem;cursor:pointer" onclick="this.previousElementSibling.querySelector('input').click()">تفعيل إرسال بريد تأكيد الجهاز تلقائياً</span>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> سجل رسائل تأكيد الجهاز (آخر 50)</div></div>
  <div class="card-body" style="padding:0">
    <?php if (empty($deviceLogs)): ?>
    <div class="empty-state"><i class="fas fa-inbox empty-state-icon"></i><div class="empty-state-title">لا توجد رسائل بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>التاريخ</th><th>المستخدم</th><th>البريد</th><th>الجهاز</th><th>الحالة الحالية</th></tr></thead>
        <tbody>
          <?php foreach ($deviceLogs as $log): ?>
          <tr>
            <td class="td-muted"><?= htmlspecialchars(date('Y/m/d H:i', strtotime($log['confirm_email_sent_at']))) ?></td>
            <td class="td-bold"><?= htmlspecialchars($log['username']) ?></td>
            <td class="td-mono"><?= htmlspecialchars($log['email']) ?></td>
            <td class="td-muted"><?= htmlspecialchars($log['device_name'] ?: '—') ?></td>
            <td>
              <?php if ($log['status']==='approved'): ?><span class="badge badge-success"><i class="fas fa-check"></i> مصرَّح</span>
              <?php elseif ($log['status']==='blocked'): ?><span class="badge badge-danger"><i class="fas fa-ban"></i> محظور</span>
              <?php else: ?><span class="badge badge-warning"><i class="fas fa-clock"></i> بانتظار</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:16px;margin-bottom:20px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paint-brush" style="color:var(--purple)"></i> تخصيص شكل ومحتوى الرسالة</div></div>
  <div class="card-body">
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="device_appearance">
      <?= emailAppearanceFieldsHtml('device', $deviceAppearance, '🔐', '🔐 تسجيل دخول من جهاز جديد - {site}', 'تم رصد محاولة تسجيل دخول لحسابك في {site} من جهاز غير مصرَّح سابقاً:') ?>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px"><i class="fas fa-save"></i> حفظ التخصيص</button>
    </form>
  </div>
</div>

<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════ TAB: تأكيد الشراء ═══ ?>
<?php if ($activeTab === 'purchase'): ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:16px">
  <div class="card" style="padding:16px"><div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">إجمالي (30 يوم)</div><div style="font-size:22px;font-weight:900"><?= $purchaseStats['total'] ?></div></div>
  <div class="card" style="padding:16px"><div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">تم الإرسال</div><div style="font-size:22px;font-weight:900;color:var(--green)"><?= $purchaseStats['sent'] ?></div></div>
  <div class="card" style="padding:16px"><div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">فشل</div><div style="font-size:22px;font-weight:900;color:var(--red)"><?= $purchaseStats['failed'] ?></div></div>
  <div class="card" style="padding:16px"><div style="color:var(--text3);font-size:11px;font-weight:700;margin-bottom:6px">تم التخطي (لا بريد)</div><div style="font-size:22px;font-weight:900;color:var(--gold)"><?= $purchaseStats['skipped'] ?></div></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> إعدادات تأكيد الشراء</div></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:14px">
      عند اكتمال طلب العميل (تسليم فوري من المخزون، أو تحديد الحالة "مكتمل" يدوياً من صفحة الطلبات)، يُرسَل له بريد يحتوي تفاصيل الشراء (الخدمة، الكمية، السعر، رقم الطلب، وبيانات التسليم إن وُجدت).
    </p>
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="purchase_general">
      <div class="switch-wrap" style="margin-bottom:14px">
        <label class="switch">
          <input type="checkbox" name="purchase_email_enabled" value="1" <?= $purchaseEnabled ? 'checked' : '' ?>>
          <span class="switch-slider"></span>
        </label>
        <span style="font-weight:700;font-size:.88rem;cursor:pointer" onclick="this.previousElementSibling.querySelector('input').click()">تفعيل إرسال بريد تأكيد الشراء تلقائياً</span>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> سجل رسائل تأكيد الشراء (آخر 50)</div></div>
  <div class="card-body" style="padding:0">
    <?php if (empty($purchaseLogs)): ?>
    <div class="empty-state"><i class="fas fa-inbox empty-state-icon"></i><div class="empty-state-title">لا توجد رسائل بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>التاريخ</th><th>#طلب</th><th>الخدمة</th><th>النوع</th><th>البريد</th><th>الحالة</th><th>ملاحظة</th></tr></thead>
        <tbody>
          <?php foreach ($purchaseLogs as $log): ?>
          <tr>
            <td class="td-muted"><?= htmlspecialchars(date('Y/m/d H:i', strtotime($log['created_at']))) ?></td>
            <td class="td-bold">#<?= (int)$log['order_id'] ?></td>
            <td class="td-muted"><?= htmlspecialchars($log['service_name'] ?: '—') ?></td>
            <td>
              <?php if (($log['email_type'] ?? 'purchase') === 'cancelled'): ?><span class="badge badge-danger"><i class="fas fa-ban"></i> إلغاء</span>
              <?php else: ?><span class="badge" style="background:rgba(34,197,94,.15);color:var(--green)"><i class="fas fa-shopping-bag"></i> شراء</span><?php endif; ?>
            </td>
            <td class="td-mono"><?= htmlspecialchars($log['email']) ?></td>
            <td>
              <?php if ($log['status']==='sent'): ?><span class="badge badge-success"><i class="fas fa-check"></i> تم الإرسال</span>
              <?php elseif ($log['status']==='skipped'): ?><span class="badge badge-warning"><i class="fas fa-forward"></i> تخطي</span>
              <?php else: ?><span class="badge badge-danger"><i class="fas fa-times"></i> فشل</span><?php endif; ?>
            </td>
            <td class="td-muted" style="max-width:220px;white-space:normal"><?= htmlspecialchars($log['error_message'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;margin-bottom:20px" class="pr-grid">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-paint-brush" style="color:var(--purple)"></i> تخصيص رسالة "تأكيد الشراء"</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="purchase_appearance">
        <?= emailAppearanceFieldsHtml('purchase', $purchaseAppearance, '🛍️', '🛍️ تأكيد الشراء - طلب {order} - {site}', 'تم تنفيذ طلبك بنجاح. إليك تفاصيل عملية الشراء:') ?>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px"><i class="fas fa-save"></i> حفظ التخصيص</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-paint-brush" style="color:var(--red)"></i> تخصيص رسالة "إلغاء الطلب"</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="cancelled_appearance">
        <?= emailAppearanceFieldsHtml('cancelled', $cancelledAppearance, '❌', '❌ تم إلغاء طلبك {order} - {site}', 'نأسف لإبلاغك بأنه تم إلغاء طلبك التالي:') ?>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px"><i class="fas fa-save"></i> حفظ التخصيص</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════ TAB: التسويق ═══ ?>
<?php if ($activeTab === 'marketing'): ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paper-plane"></i> حملة بريد جديدة</div></div>
  <div class="card-body">
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="campaign_create">
      <div class="form-group">
        <label><i class="fas fa-heading"></i> عنوان الرسالة (Subject)</label>
        <input type="text" name="campaign_subject" class="form-control" placeholder="🎉 عرض خاص لعملاء نجاز كارد" required>
      </div>
      <div class="form-group">
        <label><i class="fas fa-align-right"></i> محتوى الرسالة (HTML مسموح)</label>
        <textarea name="campaign_body" class="form-control" rows="7" placeholder="<p>مرحباً، لدينا عرض خاص لك...</p>" required></textarea>
        <div style="font-size:11px;color:var(--text3);margin-top:6px">يمكنك استخدام وسوم HTML بسيطة (p, strong, a, br...). سيُغلَّف المحتوى تلقائياً بإطار الهوية البصرية للموقع.</div>
      </div>
      <div class="form-group">
        <label><i class="fas fa-users"></i> المستلمون</label>
        <select name="campaign_filter" class="form-control">
          <option value="customers">كل العملاء الفعّالين (<?= $eligibleCounts['customers'] ?>)</option>
          <option value="with_balance">عملاء برصيد أكبر من صفر (<?= $eligibleCounts['with_balance'] ?>)</option>
          <option value="no_orders">عملاء بدون أي طلب سابق (<?= $eligibleCounts['no_orders'] ?>)</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> إنشاء الحملة (بدون إرسال بعد)</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> الحملات</div></div>
  <div class="card-body" style="padding:0">
    <?php if (empty($campaigns)): ?>
    <div class="empty-state"><i class="fas fa-inbox empty-state-icon"></i><div class="empty-state-title">لا توجد حملات بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>التاريخ</th><th>العنوان</th><th>المستلمون</th><th>أُرسل</th><th>فشل</th><th>الحالة</th><th>إجراء</th></tr></thead>
        <tbody>
          <?php foreach ($campaigns as $c): ?>
          <tr>
            <td class="td-muted"><?= htmlspecialchars(date('Y/m/d H:i', strtotime($c['created_at']))) ?></td>
            <td class="td-bold"><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= (int)$c['total_recipients'] ?></td>
            <td style="color:var(--green)"><?= (int)$c['sent_count'] ?></td>
            <td style="color:var(--red)"><?= (int)$c['failed_count'] ?></td>
            <td>
              <?php if ($c['status']==='completed'): ?><span class="badge badge-success"><i class="fas fa-check"></i> اكتملت</span>
              <?php elseif ($c['status']==='sending'): ?><span class="badge badge-warning"><i class="fas fa-spinner fa-spin"></i> جارٍ الإرسال</span>
              <?php elseif ($c['status']==='cancelled'): ?><span class="badge badge-danger"><i class="fas fa-ban"></i> ملغاة</span>
              <?php else: ?><span class="badge" style="background:rgba(148,163,184,.15);color:#94a3b8"><i class="fas fa-file"></i> مسودة</span><?php endif; ?>
            </td>
            <td>
              <?php if (in_array($c['status'], ['draft','sending'], true)): ?>
                <button type="button" class="btn btn-sm btn-primary campaign-send-btn" data-id="<?= (int)$c['id'] ?>" data-total="<?= (int)$c['total_recipients'] ?>" data-sent="<?= (int)$c['sent_count'] + (int)$c['failed_count'] ?>">
                  <i class="fas fa-play"></i> <?= $c['status']==='sending' ? 'متابعة الإرسال' : 'بدء الإرسال' ?>
                </button>
                <form method="POST" style="display:inline" onsubmit="return confirm('إلغاء الحملة؟ لن تُرسَل الرسائل المتبقية.')">
                  <?= adminCsrfField() ?>
                  <input type="hidden" name="_form_id" value="campaign_cancel">
                  <input type="hidden" name="campaign_id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-times"></i></button>
                </form>
              <?php else: ?>—<?php endif; ?>
              <div class="campaign-progress" id="progress-<?= (int)$c['id'] ?>" style="font-size:11px;color:var(--text3);margin-top:4px;display:none"></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:16px;margin-bottom:20px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-paint-brush" style="color:var(--purple)"></i> شعار رسائل التسويق</div></div>
  <div class="card-body">
    <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:14px">
      عنوان ومحتوى كل حملة تسويقية تُكتَب يدوياً عند إنشائها أعلاه، لكن يمكنك هنا تخصيص الشعار الظاهر أعلى كل رسالة تسويقية بشكل عام.
    </p>
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="marketing_appearance">
      <?= emailAppearanceFieldsHtml('marketing', ['logo_url' => $marketingLogoUrl], '📢') ?>
      <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px"><i class="fas fa-save"></i> حفظ الشعار</button>
    </form>
  </div>
</div>

<?php endif; ?>

<?php // ═══════════════════════════════════════════════════════════ TAB: SMTP ═══ ?>
<?php if ($activeTab === 'smtp'): ?>

<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-server" style="color:var(--cyan)"></i> إعدادات SMTP (بريد الاستضافة)</div></div>
  <div class="card-body">
    <div style="background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:10px;padding:14px;margin-bottom:16px;font-size:.8rem;color:var(--text2);line-height:1.8">
      <strong style="color:var(--primary)"><i class="fas fa-info-circle"></i> هذه الإعدادات مشتركة بين كل خدمات البريد أعلاه (استعادة كلمة المرور، تأكيد الجهاز، التسويق، تأكيد الشراء).</strong><br>
      من لوحة الاستضافة (cPanel) → Email Accounts → أنشئ بريداً مثل <code>no-reply@yourdomain.com</code> ثم اضغط "Connect Devices" لعرض بيانات SMTP.
    </div>
    <form method="POST">
      <?= adminCsrfField() ?>
      <input type="hidden" name="_form_id" value="smtp">
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div class="form-group">
          <label><i class="fas fa-network-wired"></i> SMTP Host</label>
          <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="mail.yourdomain.com">
        </div>
        <div class="form-group">
          <label><i class="fas fa-plug"></i> المنفذ (Port)</label>
          <input type="number" name="smtp_port" id="smtpPortInput" class="form-control" value="<?= htmlspecialchars($smtp['port']) ?>" placeholder="587" onchange="syncSmtpEncryption()">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
        <div class="form-group">
          <label><i class="fas fa-user"></i> اسم المستخدم (البريد الكامل)</label>
          <input type="text" name="smtp_username" class="form-control" value="<?= htmlspecialchars($smtp['username']) ?>" placeholder="no-reply@yourdomain.com">
        </div>
        <div class="form-group">
          <label><i class="fas fa-lock"></i> كلمة المرور <?= $smtp['has_pass'] ? '<span style="color:var(--green);font-size:11px">(محفوظة 🔒)</span>' : '' ?></label>
          <input type="password" name="smtp_password" class="form-control" placeholder="<?= $smtp['has_pass'] ? 'اتركه فارغاً للإبقاء على القيمة الحالية' : 'كلمة مرور بريد SMTP' ?>" autocomplete="new-password">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
        <div class="form-group">
          <label><i class="fas fa-shield-alt"></i> نوع التشفير</label>
          <select name="smtp_encryption" id="smtpEncryptionSelect" class="form-control" onchange="checkSmtpMismatch()">
            <option value="tls"  <?= $smtp['encryption']==='tls'  ? 'selected' : '' ?>>TLS (STARTTLS - المنفذ 587)</option>
            <option value="ssl"  <?= $smtp['encryption']==='ssl'  ? 'selected' : '' ?>>SSL (المنفذ 465)</option>
            <option value="none" <?= $smtp['encryption']==='none' ? 'selected' : '' ?>>بدون تشفير (المنفذ 25)</option>
          </select>
          <div id="smtpMismatchWarn" style="display:none;margin-top:6px;font-size:11px;color:var(--gold)"><i class="fas fa-exclamation-triangle"></i> المنفذ 465 يتطلب عادةً "SSL"، والمنفذ 587 يتطلب "TLS".</div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-envelope"></i> البريد المرسِل (From)</label>
          <input type="email" name="smtp_from_email" class="form-control" value="<?= htmlspecialchars($smtp['from_email']) ?>" placeholder="no-reply@yourdomain.com">
        </div>
        <div class="form-group">
          <label><i class="fas fa-signature"></i> اسم المرسِل</label>
          <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars($smtp['from_name']) ?>" placeholder="<?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ إعدادات SMTP</button>
    </form>

    <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
      <label style="font-weight:800;font-size:.85rem;display:block;margin-bottom:10px"><i class="fas fa-project-diagram" style="color:var(--cyan)"></i> حسابات SMTP حسب الأقسام</label>
      <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:14px">
        أنشئ حساباً مستقلاً لكل قسم، أو أنشئ حساباً واحداً واربطه بعدة أقسام. عند عدم اختيار حساب لقسم، يستمر النظام باستخدام إعدادات SMTP العامة القديمة أعلاه تلقائياً.
      </p>
      <?php if (!emailSmtpProfilesTablesReady($pdo)): ?>
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:10px;padding:13px;font-size:12px;line-height:1.8;color:var(--text2)">
          <strong style="color:var(--gold)"><i class="fas fa-database"></i> يلزم إنشاء جداول الحسابات أولاً.</strong><br>
          نفّذ ملف <code>audit/email-smtp-profiles.sql</code> مرة واحدة على قاعدة البيانات، ثم أعد تحميل هذه الصفحة.
        </div>
      <?php else: ?>
        <div class="smtp-profile-layout">
          <div class="smtp-profile-editor" id="smtpProfileEditor">
            <div class="section-mini-title"><i class="fas fa-plus-circle"></i> إضافة / تعديل حساب SMTP</div>
            <form method="POST" id="smtpProfileForm">
              <?= adminCsrfField() ?>
              <input type="hidden" name="_form_id" value="smtp_profile_save">
              <input type="hidden" name="profile_id" id="profileIdInput" value="0">
              <div class="form-group">
                <label>اسم تعريفي للحساب</label>
                <input type="text" name="profile_name" id="profileNameInput" class="form-control" placeholder="مثال: بريد الدعم أو بريد الفواتير" required>
              </div>
              <div class="smtp-profile-fields">
                <div class="form-group"><label>SMTP Host</label><input type="text" name="profile_host" id="profileHostInput" class="form-control" placeholder="mail.yourdomain.com" required></div>
                <div class="form-group"><label>Port</label><input type="number" name="profile_port" id="profilePortInput" class="form-control" value="587" min="1" max="65535" required></div>
                <div class="form-group"><label>اسم المستخدم</label><input type="text" name="profile_username" id="profileUsernameInput" class="form-control" placeholder="support@yourdomain.com"></div>
                <div class="form-group"><label>كلمة المرور <span id="profilePasswordHint" style="color:var(--green);font-size:11px"></span></label><input type="password" name="profile_password" id="profilePasswordInput" class="form-control" placeholder="كلمة مرور SMTP" autocomplete="new-password"></div>
                <div class="form-group"><label>التشفير</label><select name="profile_encryption" id="profileEncryptionInput" class="form-control"><option value="tls">TLS (587)</option><option value="ssl">SSL (465)</option><option value="none">بدون تشفير (25)</option></select></div>
                <div class="form-group"><label>البريد المرسِل</label><input type="email" name="profile_from_email" id="profileFromEmailInput" class="form-control" placeholder="اتركه فارغاً لاستخدام اسم المستخدم"></div>
                <div class="form-group"><label>اسم المرسِل</label><input type="text" name="profile_from_name" id="profileFromNameInput" class="form-control" placeholder="اسم الموقع أو القسم"></div>
              </div>
              <div class="profile-checks">
                <label><input type="checkbox" name="profile_active" id="profileActiveInput" value="1" checked> حساب مفعّل</label>
                <label><input type="checkbox" name="profile_default" id="profileDefaultInput" value="1"> حساب افتراضي</label>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الحساب</button>
                <button type="button" class="btn btn-secondary" onclick="resetSmtpProfileForm()"><i class="fas fa-undo"></i> حساب جديد</button>
              </div>
            </form>
          </div>
          <div class="smtp-profile-list">
            <div class="section-mini-title"><i class="fas fa-list"></i> الحسابات المحفوظة</div>
            <?php if (empty($smtpProfiles)): ?>
              <div class="empty-state" style="padding:24px 10px"><i class="fas fa-server empty-state-icon"></i><div class="empty-state-title">لا توجد حسابات SMTP إضافية</div></div>
            <?php else: ?>
              <div class="smtp-table-scroll"><table class="smtp-profiles-table"><thead><tr><th>الحساب</th><th>الخادم</th><th>الحالة</th><th>الأقسام</th><th>إجراءات</th></tr></thead><tbody>
              <?php foreach ($smtpProfiles as $profile): ?>
                <?php
                  $profileSections = [];
                  foreach (emailSmtpProfileSections() as $sectionKey => $sectionLabel) {
                      if ((int)($smtpBindings[$sectionKey] ?? 0) === (int)$profile['id']) $profileSections[] = $sectionLabel;
                  }
                  $profileForClient = [
                      'id' => (int)$profile['id'],
                      'profile_name' => (string)($profile['profile_name'] ?? ''),
                      'host' => (string)($profile['host'] ?? ''),
                      'port' => (int)($profile['port'] ?? 587),
                      'username' => (string)($profile['username'] ?? ''),
                      'encryption' => (string)($profile['encryption'] ?? 'tls'),
                      'from_email' => (string)($profile['from_email'] ?? ''),
                      'from_name' => (string)($profile['from_name'] ?? ''),
                      'is_active' => (int)($profile['is_active'] ?? 0),
                      'is_default' => (int)($profile['is_default'] ?? 0),
                      'has_pass' => !empty($profile['has_pass']),
                  ];
                  $profileJson = htmlspecialchars(json_encode($profileForClient, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                  <td><strong><?= htmlspecialchars($profile['profile_name']) ?></strong><?= !empty($profile['is_default']) ? ' <span class="badge badge-info">افتراضي</span>' : '' ?><br><small class="td-muted"><?= htmlspecialchars($profile['username'] ?: '—') ?></small></td>
                  <td class="td-mono"><?= htmlspecialchars($profile['host']) ?>:<?= (int)$profile['port'] ?><br><small><?= htmlspecialchars(strtoupper($profile['encryption'])) ?></small></td>
                  <td><?= !empty($profile['is_active']) ? '<span class="badge badge-success">مفعّل</span>' : '<span class="badge badge-danger">متوقف</span>' ?></td>
                  <td><small><?= htmlspecialchars($profileSections ? implode('، ', $profileSections) : 'غير مربوط') ?></small></td>
                  <td><div style="display:flex;gap:5px;flex-wrap:wrap"><button type="button" class="btn btn-secondary btn-sm" onclick="editSmtpProfile(<?= $profileJson ?>)"><i class="fas fa-edit"></i> تعديل</button><form method="POST" onsubmit="return confirm('حذف حساب SMTP وربطاته؟')" style="display:inline"><?= adminCsrfField() ?><input type="hidden" name="_form_id" value="smtp_profile_delete"><input type="hidden" name="profile_id" value="<?= (int)$profile['id'] ?>"><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> حذف</button></form></div></td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="smtp-binding-card">
          <div class="section-mini-title"><i class="fas fa-link"></i> ربط الحسابات بالأقسام</div>
          <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:12px">يمكن اختيار الحساب نفسه لأكثر من قسم. اختر «الإعداد العام» لإلغاء الربط واستخدام إعدادات SMTP القديمة.</p>
          <form method="POST">
            <?= adminCsrfField() ?>
            <input type="hidden" name="_form_id" value="smtp_bindings_save">
            <div class="smtp-binding-grid">
              <?php foreach (emailSmtpProfileSections() as $sectionKey => $sectionLabel): ?>
                <label class="smtp-binding-item"><span><?= htmlspecialchars($sectionLabel) ?></span><select name="smtp_binding[<?= htmlspecialchars($sectionKey) ?>]" class="form-control"><option value="0">الإعداد العام القديم</option><?php foreach ($smtpProfiles as $profile): ?><option value="<?= (int)$profile['id'] ?>" <?= ((int)($smtpBindings[$sectionKey] ?? 0) === (int)$profile['id']) ? 'selected' : '' ?>><?= htmlspecialchars($profile['profile_name']) ?><?= empty($profile['is_active']) ? ' (متوقف)' : '' ?></option><?php endforeach; ?></select></label>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-link"></i> حفظ ربط الأقسام</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
      <label style="font-weight:800;font-size:.85rem;display:block;margin-bottom:10px"><i class="fas fa-image" style="color:var(--purple)"></i> شعار رسائل البريد (اختياري)</label>
      <p style="font-size:12px;color:var(--text2);line-height:1.8;margin-bottom:12px">
        إن لم تُدخل رابطاً، تُستخدم أيقونة افتراضية ملوّنة تلقائياً لكل نوع رسالة. الشعار يظهر في <strong>كل</strong> رسائل الموقع (استعادة كلمة المرور، تأكيد الجهاز، تأكيد/إلغاء الشراء، التسويق).
      </p>
      <form method="POST" id="logoForm">
        <?= adminCsrfField() ?>
        <input type="hidden" name="_form_id" value="smtp">
        <!-- إعادة إرسال بقية حقول SMTP الحالية حتى لا تُفرَّغ عند حفظ الشعار فقط -->
        <input type="hidden" name="smtp_host" value="<?= htmlspecialchars($smtp['host']) ?>">
        <input type="hidden" name="smtp_port" value="<?= htmlspecialchars($smtp['port']) ?>">
        <input type="hidden" name="smtp_username" value="<?= htmlspecialchars($smtp['username']) ?>">
        <input type="hidden" name="smtp_encryption" value="<?= htmlspecialchars($smtp['encryption']) ?>">
        <input type="hidden" name="smtp_from_email" value="<?= htmlspecialchars($smtp['from_email']) ?>">
        <input type="hidden" name="smtp_from_name" value="<?= htmlspecialchars($smtp['from_name']) ?>">
        <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
          <div style="flex:1;min-width:240px">
            <input type="url" name="email_logo_url" id="logoUrlInput" class="form-control" value="<?= htmlspecialchars($emailLogoUrl) ?>" placeholder="https://njaz.net/assets/logo-email.png" oninput="previewEmailLogo()">
            <div style="font-size:11px;color:var(--text3);margin-top:6px">يُفضَّل صورة مربعة (PNG/JPG) بحجم لا يقل عن 112×112px، مرفوعة على رابط ثابت (HTTPS).</div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px"><i class="fas fa-save"></i> حفظ الشعار</button>
          </div>
          <div style="text-align:center">
            <div style="font-size:11px;color:var(--text3);margin-bottom:6px">معاينة</div>
            <div style="width:64px;height:64px;border-radius:16px;background:#0f1929;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden">
              <img id="logoPreviewImg" src="<?= htmlspecialchars($emailLogoUrl) ?>" style="width:100%;height:100%;object-fit:cover;<?= $emailLogoUrl === '' ? 'display:none' : '' ?>" onerror="this.style.display='none'">
              <span id="logoPreviewFallback" style="font-size:22px;<?= $emailLogoUrl !== '' ? 'display:none' : '' ?>">🔑</span>
            </div>
          </div>
        </div>
      </form>
    </div>

    <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
      <label style="font-weight:800;font-size:.85rem;display:block;margin-bottom:10px"><i class="fas fa-vial" style="color:var(--gold)"></i> إرسال بريد اختباري</label>
      <div style="display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:10px;align-items:end" class="smtp-test-grid">
        <div><label style="font-size:11px;color:var(--text3);display:block;margin-bottom:5px">البريد المستلم</label><input type="email" id="testEmailInput" class="form-control" placeholder="بريدك الشخصي لتلقي رسالة الاختبار"></div>
        <div><label style="font-size:11px;color:var(--text3);display:block;margin-bottom:5px">القسم</label><select id="testSmtpSection" class="form-control"><option value="">الإعداد العام القديم</option><?php foreach (emailSmtpProfileSections() as $sectionKey => $sectionLabel): ?><option value="<?= htmlspecialchars($sectionKey) ?>"><?= htmlspecialchars($sectionLabel) ?></option><?php endforeach; ?></select></div>
        <div><label style="font-size:11px;color:var(--text3);display:block;margin-bottom:5px">حساب محدد (اختياري)</label><select id="testSmtpProfile" class="form-control"><option value="0">حسب القسم / العام</option><?php foreach ($smtpProfiles as $profile): ?><option value="<?= (int)$profile['id'] ?>"><?= htmlspecialchars($profile['profile_name']) ?><?= empty($profile['is_active']) ? ' (متوقف)' : '' ?></option><?php endforeach; ?></select></div>
        <button type="button" class="btn btn-secondary" id="testSmtpBtn" onclick="testSmtp()"><i class="fas fa-paper-plane"></i> إرسال اختبار</button>
      </div>
      <div id="testSmtpResult" style="margin-top:10px;font-size:.82rem"></div>
      <div style="margin-top:12px">
        <a href="smtp_debug.php" class="btn btn-secondary btn-sm"><i class="fas fa-bug"></i> فتح أداة التشخيص التفصيلي</a>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<style>
.ec-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:18px; border-bottom:1px solid var(--border); padding-bottom:0 }
.ec-tab { display:inline-flex; align-items:center; gap:7px; padding:11px 18px; font-size:.85rem; font-weight:800; color:var(--text2); text-decoration:none; border-bottom:2.5px solid transparent; border-radius:10px 10px 0 0; transition:.15s }
.ec-tab:hover { color:var(--text); background:rgba(255,255,255,.03) }
.ec-tab.active { color:var(--primary); border-bottom-color:var(--primary); background:rgba(37,99,235,.08) }
.smtp-profile-layout { display:grid; grid-template-columns:minmax(280px,.9fr) minmax(420px,1.4fr); gap:16px; margin-top:14px }
.smtp-profile-editor, .smtp-profile-list, .smtp-binding-card { background:rgba(15,25,41,.52); border:1px solid var(--border); border-radius:12px; padding:14px }
.section-mini-title { font-weight:800; font-size:.84rem; margin-bottom:12px; color:var(--text) }
.section-mini-title i { color:var(--primary); margin-left:5px }
.smtp-profile-fields { display:grid; grid-template-columns:1fr 1fr; gap:10px }
.profile-checks { display:flex; gap:16px; flex-wrap:wrap; color:var(--text2); font-size:12px }
.profile-checks label { display:flex; align-items:center; gap:6px; cursor:pointer }
.smtp-table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch }
.smtp-profiles-table { min-width:720px; width:100%; border-collapse:collapse }
.smtp-profiles-table th, .smtp-profiles-table td { padding:10px 8px; border-bottom:1px solid var(--border); text-align:right; vertical-align:middle; font-size:12px }
.smtp-profiles-table th { color:var(--text3); font-size:11px; white-space:nowrap }
.smtp-binding-card { margin-top:16px }
.smtp-binding-grid { display:grid; grid-template-columns:repeat(2,minmax(220px,1fr)); gap:12px; margin-bottom:14px }
.smtp-binding-item { display:flex; align-items:center; gap:10px; color:var(--text2); font-size:12px }
.smtp-binding-item > span { min-width:120px; font-weight:700; color:var(--text) }
.smtp-binding-item .form-control { flex:1; min-width:0 }
@media (max-width: 900px) {
  .pr-grid { grid-template-columns: 1fr !important; }
  .ec-tab { flex:1; justify-content:center; padding:10px 8px; font-size:.78rem }
  .smtp-profile-layout { grid-template-columns:1fr; }
  .smtp-binding-grid { grid-template-columns:1fr; }
  .smtp-test-grid { grid-template-columns:1fr !important; }
  .smtp-binding-item { align-items:stretch; flex-direction:column; gap:5px }
  .smtp-binding-item > span { min-width:0 }
}
@media (max-width: 560px) {
  .smtp-profile-fields { grid-template-columns:1fr; }
}
</style>

<script>
const ADMIN_CSRF_TOKEN = '<?= adminCsrfToken() ?>';
const SMTP_PORT_ENCRYPTION_MAP = { '465': 'ssl', '587': 'tls', '25': 'none' };

function syncSmtpEncryption() {
  const portInput = document.getElementById('smtpPortInput');
  const encSelect = document.getElementById('smtpEncryptionSelect');
  if (!portInput || !encSelect) return;
  const suggested = SMTP_PORT_ENCRYPTION_MAP[portInput.value.trim()];
  if (suggested) encSelect.value = suggested;
  checkSmtpMismatch();
}
function checkSmtpMismatch() {
  const portInput = document.getElementById('smtpPortInput');
  const encSelect = document.getElementById('smtpEncryptionSelect');
  const warn = document.getElementById('smtpMismatchWarn');
  if (!portInput || !encSelect || !warn) return;
  const expected = SMTP_PORT_ENCRYPTION_MAP[portInput.value.trim()];
  warn.style.display = (expected && expected !== encSelect.value) ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', checkSmtpMismatch);

function previewEmailLogo() {
  const url = document.getElementById('logoUrlInput')?.value.trim();
  const img = document.getElementById('logoPreviewImg');
  const fallback = document.getElementById('logoPreviewFallback');
  if (!img || !fallback) return;
  if (url) {
    img.src = url;
    img.style.display = 'block';
    fallback.style.display = 'none';
    img.onerror = () => { img.style.display = 'none'; fallback.style.display = 'block'; };
  } else {
    img.style.display = 'none';
    fallback.style.display = 'block';
  }
}

function previewSectionLogo(section) {
  const input = document.querySelector(`input[name="logo_url"][oninput*="${section}"]`);
  const img = document.getElementById('logoPrev_' + section);
  const fallback = document.getElementById('logoFallback_' + section);
  if (!input || !img || !fallback) return;
  const url = input.value.trim();
  if (url) {
    img.src = url;
    img.style.display = 'block';
    fallback.style.display = 'none';
    img.onerror = () => { img.style.display = 'none'; fallback.style.display = 'block'; };
  } else {
    img.style.display = 'none';
    fallback.style.display = 'block';
  }
}

function resetSmtpProfileForm() {
  const form = document.getElementById('smtpProfileForm');
  if (!form) return;
  form.reset();
  document.getElementById('profileIdInput').value = '0';
  document.getElementById('profilePortInput').value = '587';
  document.getElementById('profileEncryptionInput').value = 'tls';
  document.getElementById('profileActiveInput').checked = true;
  document.getElementById('profileDefaultInput').checked = false;
  document.getElementById('profilePasswordInput').value = '';
  document.getElementById('profilePasswordInput').placeholder = 'كلمة مرور SMTP';
  document.getElementById('profilePasswordHint').textContent = '';
  const title = document.querySelector('#smtpProfileEditor .section-mini-title');
  if (title) title.innerHTML = '<i class="fas fa-plus-circle"></i> إضافة حساب SMTP';
}

function editSmtpProfile(profile) {
  if (!profile) return;
  document.getElementById('profileIdInput').value = profile.id || '0';
  document.getElementById('profileNameInput').value = profile.profile_name || '';
  document.getElementById('profileHostInput').value = profile.host || '';
  document.getElementById('profilePortInput').value = profile.port || 587;
  document.getElementById('profileUsernameInput').value = profile.username || '';
  document.getElementById('profilePasswordInput').value = '';
  document.getElementById('profilePasswordInput').placeholder = profile.has_pass ? 'اتركه فارغاً للإبقاء على كلمة المرور الحالية' : 'كلمة مرور SMTP';
  document.getElementById('profilePasswordHint').textContent = profile.has_pass ? '(محفوظة)' : '';
  document.getElementById('profileEncryptionInput').value = profile.encryption || 'tls';
  document.getElementById('profileFromEmailInput').value = profile.from_email || '';
  document.getElementById('profileFromNameInput').value = profile.from_name || '';
  document.getElementById('profileActiveInput').checked = Number(profile.is_active) === 1;
  document.getElementById('profileDefaultInput').checked = Number(profile.is_default) === 1;
  const title = document.querySelector('#smtpProfileEditor .section-mini-title');
  if (title) title.innerHTML = '<i class="fas fa-edit"></i> تعديل حساب SMTP';
  document.getElementById('smtpProfileEditor')?.scrollIntoView({behavior:'smooth', block:'center'});
}

function testSmtp() {
  const input  = document.getElementById('testEmailInput');
  const btn    = document.getElementById('testSmtpBtn');
  const result = document.getElementById('testSmtpResult');
  const email  = input.value.trim();
  if (!email) { result.innerHTML = '<span style="color:var(--red)">يرجى إدخال بريد إلكتروني أولاً</span>'; return; }
  btn.disabled = true;
  const originalHtml = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
  result.innerHTML = '';
  const fd = new FormData();
  fd.append('test_email', email);
  fd.append('section_key', document.getElementById('testSmtpSection')?.value || '');
  fd.append('profile_id', document.getElementById('testSmtpProfile')?.value || '0');
  fd.append('_csrf', ADMIN_CSRF_TOKEN);
  fetch('ajax_test_smtp.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        result.innerHTML = '<span style="color:var(--green)"><i class="fas fa-check-circle"></i> تم إرسال البريد الاختباري بنجاح!</span>';
        if (typeof showToast === 'function') showToast('تم إرسال البريد الاختباري بنجاح', 'success');
      } else {
        result.innerHTML = '<span style="color:var(--red)"><i class="fas fa-times-circle"></i> فشل الإرسال: ' + (d.error || 'خطأ غير معروف') + '</span>';
        if (typeof showToast === 'function') showToast('فشل إرسال البريد الاختباري', 'error');
      }
    })
    .catch(() => { result.innerHTML = '<span style="color:var(--red)">حدث خطأ في الاتصال بالخادم</span>'; })
    .finally(() => { btn.disabled = false; btn.innerHTML = originalHtml; });
}

// ── إرسال حملة تسويقية على دفعات (لتفادي انقطاع التنفيذ) ──
document.querySelectorAll('.campaign-send-btn').forEach(btn => {
  btn.addEventListener('click', () => runCampaign(btn));
});

function runCampaign(btn) {
  const id       = btn.dataset.id;
  const total    = parseInt(btn.dataset.total, 10) || 0;
  const progress = document.getElementById('progress-' + id);
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الإرسال...';
  progress.style.display = 'block';

  function sendNextBatch() {
    const fd = new FormData();
    fd.append('campaign_id', id);
    fetch('../api/admin_campaign_batch.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) {
          progress.innerHTML = '<span style="color:var(--red)">خطأ: ' + (d.error || 'غير معروف') + '</span>';
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> إعادة المحاولة';
          return;
        }
        progress.textContent = `أُرسل ${d.sent} / ${total} (فشل: ${d.failed})`;
        if (d.done) {
          btn.innerHTML = '<i class="fas fa-check"></i> اكتملت';
          if (typeof showToast === 'function') showToast('اكتمل إرسال الحملة', 'success');
          setTimeout(() => location.reload(), 1200);
        } else {
          setTimeout(sendNextBatch, 400);
        }
      })
      .catch(() => {
        progress.innerHTML = '<span style="color:var(--red)">تعذّر الاتصال بالخادم — أعد المحاولة</span>';
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-redo"></i> إعادة المحاولة';
      });
  }
  sendNextBatch();
}
</script>

<?php include 'footer.php'; ?>
