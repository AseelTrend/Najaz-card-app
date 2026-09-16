<?php
/**
 * حسابات SMTP متعددة لمركز البريد الإلكتروني.
 *
 * يدعم ربط حساب مستقل بقسم واحد أو ربط الحساب نفسه بعدة أقسام.
 * عند عدم وجود الجداول أو عدم وجود ربط فعّال، يعود تلقائياً إلى
 * getSmtpMailer() الذي يقرأ إعدادات SMTP القديمة من جدول settings.
 */

require_once __DIR__ . '/smtp_mailer.php';

/** الأقسام التي يمكن ربطها بحسابات SMTP. */
function emailSmtpProfileSections(): array
{
    return [
        'reset'     => 'استعادة كلمة المرور',
        'device'    => 'تأكيد الجهاز',
        'purchase'  => 'تأكيد الشراء',
        'cancelled' => 'إلغاء الطلب',
        'marketing' => 'رسائل التسويق',
    ];
}

/** يتحقق من جاهزية جداول الميزة دون تنفيذ DDL داخل مسار الإرسال. */
function emailSmtpProfilesTablesReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_smtp_profiles'");
        $profiles = (bool)$stmt->fetchColumn();
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_smtp_bindings'");
        $bindings = (bool)$stmt->fetchColumn();
        $ready = $profiles && $bindings;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

/** يحول صف الحساب إلى إعدادات SmtpMailer. */
function emailSmtpProfileConfig(array $row): array
{
    return [
        'host'       => trim((string)($row['host'] ?? '')),
        'port'       => (int)($row['port'] ?? 587),
        'username'   => trim((string)($row['username'] ?? '')),
        'password'   => (string)($row['smtp_password'] ?? $row['password'] ?? ''),
        'encryption' => in_array(($row['encryption'] ?? 'tls'), ['ssl', 'tls', 'none'], true) ? $row['encryption'] : 'tls',
        'from_email' => trim((string)($row['from_email'] ?? '')),
        'from_name'  => trim((string)($row['from_name'] ?? '')),
    ];
}

function emailSmtpMailerFromProfile(array $row): SmtpMailer
{
    return new SmtpMailer(emailSmtpProfileConfig($row));
}

/** يعيد حساباً محدداً، مع إمكانية قصر النتائج على الحسابات الفعالة. */
function getEmailSmtpProfile(PDO $pdo, int $profileId, bool $activeOnly = true): ?array
{
    if ($profileId < 1 || !emailSmtpProfilesTablesReady($pdo)) return null;

    $sql = 'SELECT * FROM email_smtp_profiles WHERE id = ?';
    if ($activeOnly) $sql .= ' AND is_active = 1';
    $sql .= ' LIMIT 1';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** يعيد الحساب المرتبط بقسم، أو null عند عدم وجود ربط فعّال. */
function getEmailSmtpProfileForSection(PDO $pdo, string $section): ?array
{
    if (!array_key_exists($section, emailSmtpProfileSections()) || !emailSmtpProfilesTablesReady($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT p.*
            FROM email_smtp_bindings b
            INNER JOIN email_smtp_profiles p ON p.id = b.profile_id
            WHERE b.section_key = ? AND p.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$section]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * نقطة الإرسال الموحدة حسب القسم. لا تكسر أي تثبيت سابق:
 * إذا لم توجد الجداول أو الربط، تستخدم إعدادات settings القديمة.
 */
function getSmtpMailerForSection(PDO $pdo, string $section): SmtpMailer
{
    $profile = getEmailSmtpProfileForSection($pdo, $section);
    return $profile ? emailSmtpMailerFromProfile($profile) : getSmtpMailer();
}

/** يعيد جميع الحسابات للوحة الإدارة. */
function getEmailSmtpProfiles(PDO $pdo, bool $includeInactive = true): array
{
    if (!emailSmtpProfilesTablesReady($pdo)) return [];

    try {
        $sql = 'SELECT p.*, COUNT(b.id) AS bindings_count
                FROM email_smtp_profiles p
                LEFT JOIN email_smtp_bindings b ON b.profile_id = p.id';
        if (!$includeInactive) $sql .= ' WHERE p.is_active = 1';
        $sql .= ' GROUP BY p.id ORDER BY p.is_default DESC, p.id ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['has_pass'] = trim((string)($row['smtp_password'] ?? $row['password'] ?? '')) !== '';
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** يعيد خريطة section_key => profile_id. */
function getEmailSmtpBindings(PDO $pdo): array
{
    if (!emailSmtpProfilesTablesReady($pdo)) return [];

    try {
        $rows = $pdo->query('SELECT section_key, profile_id FROM email_smtp_bindings')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) $result[(string)$row['section_key']] = (int)$row['profile_id'];
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/** يبني إعدادات legacy في صورة صف قابل للعرض أو الاستيراد. */
function getLegacyEmailSmtpConfig(): array
{
    return [
        'host'         => getSetting('smtp_host'),
        'port'         => (int)(getSetting('smtp_port') ?: 587),
        'username'     => getSetting('smtp_username'),
        'smtp_password' => getSetting('smtp_password'),
        'encryption'   => getSetting('smtp_encryption') ?: 'tls',
        'from_email'   => getSetting('smtp_from_email') ?: getSetting('smtp_username'),
        'from_name'    => getSetting('smtp_from_name') ?: (getSetting('site_name') ?: SITE_NAME),
    ];
}
