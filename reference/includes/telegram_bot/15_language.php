<?php
/**
 * لغة العرض في Telegram.
 *
 * يقرأ البوت الكتالوج والترجمات من مصدر نجاز الموحد في قاعدة البيانات.
 * يبقى assets/js/i18n.js احتياطياً للتوافق مع النسخ السابقة أو تعذر قاعدة البيانات.
 */
require_once dirname(__DIR__) . '/language_catalog.php';

function njazTgSiteLanguageSource(): string
{
    return njazLanguageJsSource();
}

function njazTgSiteLanguages(): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;
    global $pdo;
    try {
        if ($pdo instanceof PDO) {
            $catalog = njazLanguageCatalog($pdo, true);
            if ($catalog) return $cache = $catalog;
        }
    } catch (Throwable $e) {
        error_log('Telegram site languages DB read failed: ' . $e->getMessage());
    }

    $fileCatalog = njazLanguageFileCatalog();
    $languages = $fileCatalog['languages'] ?? [];
    if ($languages) return $cache = $languages;
    return $cache = [
        'ar' => ['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'flag' => '🇸🇦'],
        'en' => ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'flag' => '🇬🇧'],
        'tr' => ['code' => 'tr', 'name' => 'Turkish', 'native_name' => 'Türkçe', 'direction' => 'ltr', 'flag' => '🇹🇷'],
    ];
}

function njazTgLanguageCode(PDO $pdo, array $tgUser): string
{
    $languages = njazTgSiteLanguages();
    $data = njazTgStateData($tgUser);
    $selected = strtolower(trim((string)($data['display_language'] ?? '')));
    return isset($languages[$selected]) ? $selected : (isset($languages['ar']) ? 'ar' : (string)array_key_first($languages));
}

function njazTgLanguageMap(string $language): array
{
    static $cache = [];
    global $pdo;
    $language = strtolower(trim($language));
    if (array_key_exists($language, $cache)) return $cache[$language];
    if ($language === '' || $language === 'ar') return [];
    $path = njazTgLanguagePersistentCachePath('plain', $language);
    $cached = @json_decode((string)@file_get_contents($path), true);
    if (is_array($cached) && isset($cached['expires'], $cached['map']) && (int)$cached['expires'] > time() && is_array($cached['map'])) {
        return $cache[$language] = $cached['map'];
    }
    try {
        if ($pdo instanceof PDO) {
            $map = njazLanguageTranslations($pdo, $language, true);
            @file_put_contents($path, json_encode(['expires' => time() + 30, 'map' => $map], JSON_UNESCAPED_UNICODE), LOCK_EX);
            return $cache[$language] = $map;
        }
    } catch (Throwable $e) {
        error_log('Telegram language map DB read failed: ' . $e->getMessage());
    }
    return $cache[$language] = (njazLanguageFileCatalog()['translations'][$language] ?? []);
}

function njazTgLanguagePersistentCachePath(string $kind, string $language): string
{
    $base = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'njaz_' . sha1($base . ':' . $kind . ':' . strtolower(trim($language))) . '.json';
}

function njazTgLanguageContextBundle(PDO $pdo, string $language): array
{
    static $memory = [];
    $language = strtolower(trim($language));
    if ($language === '' || $language === 'ar') return ['map' => [], 'sources' => []];
    $key = spl_object_id($pdo) . ':' . $language;
    if (isset($memory[$key])) return $memory[$key];
    $path = njazTgLanguagePersistentCachePath('context', $language);
    $cached = @json_decode((string)@file_get_contents($path), true);
    if (is_array($cached) && isset($cached['expires'], $cached['bundle']) && (int)$cached['expires'] > time() && is_array($cached['bundle'])) {
        return $memory[$key] = $cached['bundle'];
    }
    $bundle = ['map' => [], 'sources' => []];
    try {
        $stmt = $pdo->prepare("SELECT context, source_text, translated_text FROM language_translations WHERE language_code=? AND context IS NOT NULL AND context<>'' AND translated_text<>source_text AND status=1 ORDER BY id ASC");
        $stmt->execute([$language]);
        foreach ($stmt->fetchAll() as $row) {
            $context = (string)($row['context'] ?? '');
            if ($context === '') continue;
            $bundle['map'][$context] = (string)($row['translated_text'] ?? '');
            $bundle['sources'][$context] = (string)($row['source_text'] ?? '');
        }
        @file_put_contents($path, json_encode(['expires' => time() + 30, 'bundle' => $bundle], JSON_UNESCAPED_UNICODE), LOCK_EX);
    } catch (Throwable $e) {
        // fallback to an empty keyed map; the embedded text translator remains available.
    }
    return $memory[$key] = $bundle;
}

function njazTgLanguageContextMap(PDO $pdo, string $language): array
{
    return njazTgLanguageContextBundle($pdo, $language)['map'];
}

function njazTgEntityContextFromCallback(string $callback): ?string
{
    if (preg_match('/^(?:cat|thumb:cat):(\d+)(?::\d+)?$/', $callback, $m)) return 'entity:category:' . (int)$m[1] . ':name';
    if (preg_match('/^(?:svc|thumb:svc):(\d+)(?::\d+)?$/', $callback, $m)) return 'entity:service:' . (int)$m[1] . ':name';
    return null;
}

function njazTgLanguageContextSourceMap(PDO $pdo, string $language): array
{
    return njazTgLanguageContextBundle($pdo, $language)['sources'];
}

function njazTgTranslateEntityText(PDO $pdo, string $text, string $language, ?string $context = null): string
{
    if ($text === '' || strtolower($language) === 'ar' || !$context) return njazTgTranslate($text, $language);
    $map = njazTgLanguageContextMap($pdo, $language);
    $sources = njazTgLanguageContextSourceMap($pdo, $language);
    if (isset($map[$context], $sources[$context]) && $sources[$context] !== '') {
        return str_replace($sources[$context], (string)$map[$context], $text);
    }
    return njazTgTranslate($text, $language);
}

function njazTgTranslate(string $text, string $language): string
{
    static $sorted = [];
    if ($text === '' || strtolower($language) === 'ar') return $text;
    $language = strtolower(trim($language));
    if (!array_key_exists($language, $sorted)) {
        $map = njazTgLanguageMap($language);
        uksort($map, static function (string $a, string $b): int {
            return function_exists('mb_strlen') ? mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8') : strlen($b) <=> strlen($a);
        });
        $sorted[$language] = $map;
    }
    $map = $sorted[$language];
    return $map ? str_replace(array_keys($map), array_values($map), $text) : $text;
}

function njazTgTranslateKeyboard(array $keyboard, string $language, ?PDO $database = null): array
{
    global $pdo;
    $db = $database instanceof PDO ? $database : ($pdo instanceof PDO ? $pdo : null);
    foreach ($keyboard as $r => $row) {
        if (!is_array($row)) continue;
        foreach ($row as $c => $button) {
            if (is_array($button) && array_key_exists('text', $button)) {
                $context = njazTgEntityContextFromCallback((string)($button['callback_data'] ?? ''));
                $keyboard[$r][$c]['text'] = ($db instanceof PDO)
                    ? njazTgTranslateEntityText($db, (string)$button['text'], $language, $context)
                    : njazTgTranslate((string)$button['text'], $language);
            }
        }
    }
    return $keyboard;
}

function njazTgLanguageSave(PDO $pdo, array $tgUser, string $language): bool
{
    $language = strtolower(trim($language));
    if (!isset(njazTgSiteLanguages()[$language])) return false;
    $data = njazTgStateData($tgUser);
    $data['display_language'] = $language;
    try {
        $stmt = $pdo->prepare('UPDATE telegram_users SET state_data=? WHERE telegram_id=?');
        $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int)$tgUser['telegram_id']]);
        return true;
    } catch (Throwable $e) {
        error_log('Telegram display language save failed: ' . $e->getMessage());
        return false;
    }
}

function njazTgLanguagePicker(PDO $pdo, array $tgUser, ?int $messageId = null): void
{
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $selected = njazTgLanguageCode($pdo, $tgUser);
    $buttons = [];
    foreach (njazTgSiteLanguages() as $language) {
        $code = strtolower((string)($language['code'] ?? ''));
        if ($code === '') continue;
        $mark = $code === $selected ? ' ✅' : '';
        $label = trim((string)($language['flag'] ?? '') . ' ' . (string)($language['native_name'] ?? $language['name'] ?? strtoupper($code)));
        $buttons[] = [['text' => $label . $mark, 'callback_data' => 'profile:language:' . $code]];
    }
    $buttons[] = [['text' => '↩️ حسابي', 'callback_data' => 'menu:profile']];
    njazTgRender($pdo, $tgUser, '<b>🌐 لغة العرض</b>\n\nاختر اللغة نفسها المستخدمة في إعدادات صفحة الموبايل. سيظهر أي تحديث من لوحة الإدارة عند فتح القائمة أو الرسالة التالية.', $buttons, $messageId);
}

function njazTgLanguageSelect(PDO $pdo, array $tgUser, string $language, ?int $messageId = null): void
{
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgLanguageSave($pdo, $tgUser, $language)) {
        njazTgRender($pdo, $tgUser, 'اللغة غير متاحة حالياً. اختر إحدى اللغات الظاهرة في القائمة.', [[['text' => '↩️ لغة العرض', 'callback_data' => 'profile:language']]], $messageId);
        return;
    }
    $data = njazTgStateData($tgUser);
    $data['display_language'] = strtolower(trim($language));
    $updated = array_merge($tgUser, ['state_data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    njazTgProfileScreen($pdo, $updated, $messageId);
}

function njazTgLanguageLabel(PDO $pdo, array $tgUser): string
{
    $code = njazTgLanguageCode($pdo, $tgUser);
    $languages = njazTgSiteLanguages();
    $language = $languages[$code] ?? [];
    return trim((string)($language['flag'] ?? '') . ' ' . (string)($language['native_name'] ?? $language['name'] ?? strtoupper($code)));
}
