<?php
/**
 * مصدر لغات العرض الموحد للموقع وبوت Telegram.
 *
 * قاعدة البيانات هي المصدر الأساسي عند توفرها، بينما يبقى assets/js/i18n.js
 * احتياطياً للتوافق مع النسخ القديمة وللمزامنة الأولية.
 */

if (!function_exists('njazLanguageJsPath')) {
    function njazLanguageJsPath(): string
    {
        return dirname(__DIR__) . '/assets/js/i18n.js';
    }
}

if (!function_exists('njazLanguageDbEnsure')) {
    function njazLanguageDbEnsure(PDO $pdo): bool
    {
        static $status = [];
        $key = spl_object_id($pdo);
        if (array_key_exists($key, $status)) return $status[$key];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS display_languages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                language_code VARCHAR(20) NOT NULL,
                language_name VARCHAR(100) NOT NULL,
                language_name_native VARCHAR(100) NOT NULL,
                direction ENUM('rtl','ltr') NOT NULL DEFAULT 'ltr',
                flag_emoji VARCHAR(20) DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_display_language_code (language_code),
                KEY idx_display_languages_status_order (status, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS language_translations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                language_code VARCHAR(20) NOT NULL,
                source_text VARCHAR(500) NOT NULL,
                translated_text TEXT NOT NULL,
                context VARCHAR(100) DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_language_translation (language_code, source_text(191), context(50)),
                KEY idx_language_translations_language (language_code, status),
                KEY idx_language_translations_source (source_text(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return $status[$key] = true;
        } catch (Throwable $e) {
            error_log('Language catalog schema error: ' . $e->getMessage());
            return $status[$key] = false;
        }
    }
}

if (!function_exists('njazLanguageJsSource')) {
    function njazLanguageJsSource(): string
    {
        $source = @file_get_contents(njazLanguageJsPath());
        return is_string($source) ? $source : '';
    }
}

if (!function_exists('njazLanguageDecodeJsString')) {
    function njazLanguageDecodeJsString(string $value): string
    {
        $decoded = stripcslashes($value);
        return $decoded === '' && $value !== '' ? $value : $decoded;
    }
}

if (!function_exists('njazLanguageFileCatalog')) {
    function njazLanguageFileCatalog(): array
    {
        $source = njazLanguageJsSource();
        $languages = [];
        $translations = [];
        if ($source === '') return ['languages' => [], 'translations' => []];

        if (preg_match('/const\s+LANG_DIR\s*=\s*\{(.*?)\};/s', $source, $dirMatch)) {
            if (preg_match_all('/([a-zA-Z][a-zA-Z0-9_-]*)\s*:\s*[\'\"](rtl|ltr)[\'\"]/u', $dirMatch[1], $rows, PREG_SET_ORDER)) {
                $names = [
                    'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦'],
                    'en' => ['name' => 'English', 'native' => 'English', 'flag' => '🇬🇧'],
                    'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷'],
                ];
                foreach ($rows as $row) {
                    $code = strtolower(trim((string)$row[1]));
                    if ($code === '') continue;
                    $meta = $names[$code] ?? ['name' => strtoupper($code), 'native' => strtoupper($code), 'flag' => '🌐'];
                    $languages[$code] = [
                        'code' => $code,
                        'name' => $meta['name'],
                        'native_name' => $meta['native'],
                        'direction' => strtolower((string)$row[2]) === 'rtl' ? 'rtl' : 'ltr',
                        'flag' => $meta['flag'],
                        'status' => 1,
                        'sort_order' => count($languages),
                        'is_default' => $code === 'ar' ? 1 : 0,
                    ];
                }
            }
        }

        if (preg_match('/const\s+T\s*=\s*\{(.*)\n\s*\};/s', $source, $tMatch)) {
            $body = $tMatch[1];
            // نقرأ كل كود من LANG_DIR منفرداً، حتى لا تبتلع كتلة ar الفارغة الكتلة التالية.
            foreach (array_keys($languages) as $code) {
                $content = '';
                if (preg_match('/(?:^|\n)\s*' . preg_quote($code, '/') . '\s*:\s*\{\s*\}(?:\s*,)?[^\n]*/u', $body, $emptyBlock)) {
                    $content = '';
                } elseif (preg_match('/(?:^|\n)\s*' . preg_quote($code, '/') . '\s*:\s*\{(.*?)\n\s*\}(?:\s*,)?\s*(?=\n|$)/us', $body, $block)) {
                    $content = (string)$block[1];
                }
                $map = [];
                if ($content !== '' && preg_match_all('/[\'\"]((?:\\\\.|[^\'\"])*)[\'\"]\s*:\s*([\'\"])((?:\\\\.|(?!\2).)*)\2\s*,?/us', $content, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $key = njazLanguageDecodeJsString((string)($pair[1] ?? ''));
                        $value = njazLanguageDecodeJsString((string)($pair[3] ?? ''));
                        if ($key !== '' && $value !== '') $map[$key] = $value;
                    }
                }
                $translations[$code] = $map;
            }
        }

        return ['languages' => $languages, 'translations' => $translations];
    }
}

if (!function_exists('njazLanguageCatalog')) {
    function njazLanguageCatalog(PDO $pdo, bool $activeOnly = true): array
    {
        $fallback = njazLanguageFileCatalog();
        try {
            $sql = 'SELECT language_code AS code, language_name AS name, language_name_native AS native_name,
                           direction, flag_emoji AS flag, status, sort_order, is_default
                    FROM display_languages';
            if ($activeOnly) $sql .= ' WHERE status=1';
            $sql .= ' ORDER BY sort_order ASC, id ASC';
            $rows = $pdo->query($sql)->fetchAll();
            if (!$rows) return $fallback['languages'];
            $out = [];
            foreach ($rows as $row) {
                $code = strtolower(trim((string)$row['code']));
                if ($code === '') continue;
                $row['code'] = $code;
                $row['status'] = (int)$row['status'];
                $row['sort_order'] = (int)$row['sort_order'];
                $row['is_default'] = (int)$row['is_default'];
                $row['flag'] = (string)($row['flag'] ?? '');
                $out[$code] = $row;
            }
            return $out ?: $fallback['languages'];
        } catch (Throwable $e) {
            return $fallback['languages'];
        }
    }
}

if (!function_exists('njazLanguageTranslations')) {
    function njazLanguageTranslations(PDO $pdo, string $language, bool $activeOnly = true): array
    {
        $language = strtolower(trim($language));
        if ($language === '' || $language === 'ar') return [];
        $fallback = njazLanguageFileCatalog()['translations'][$language] ?? [];
        try {
            $sql = 'SELECT source_text, translated_text FROM language_translations WHERE language_code=? AND (context IS NULL OR context=\'\')';
            if ($activeOnly) $sql .= ' AND status=1';
            $sql .= ' ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$language]);
            $rows = $stmt->fetchAll();
            if (!$rows) return $fallback;
            $map = [];
            foreach ($rows as $row) {
                if ((string)$row['source_text'] !== '' && (string)$row['translated_text'] !== '') {
                    $map[(string)$row['source_text']] = (string)$row['translated_text'];
                }
            }
            return $map ?: $fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }
}

if (!function_exists('njazLanguageDynamicRows')) {
    /**
     * يبني كتالوجاً مستقراً للعناصر التي تأتي من جداول نجاز الحالية.
     * لا نستخدم الاسم كسجل وحيد لأن الاسم قد يتكرر؛ السياق يحفظ نوع العنصر ومعرّفه.
     */
    function njazLanguageDynamicRows(PDO $pdo): array
    {
        $rows = [];
        try {
            $categories = $pdo->query("SELECT c.id, COALESCE(NULLIF(tbc.label_override,''), c.name) AS label
                FROM categories c LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id
                WHERE c.status=1 ORDER BY c.id")->fetchAll();
            foreach ($categories as $row) {
                $label = trim((string)($row['label'] ?? ''));
                if ($label !== '') $rows[] = ['context' => 'entity:category:' . (int)$row['id'] . ':name', 'source' => $label];
            }
        } catch (Throwable $e) {
            error_log('Language category catalog failed: ' . $e->getMessage());
        }
        try {
            $services = $pdo->query("SELECT s.id, COALESCE(NULLIF(tbs.label_override,''),s.name) AS name, s.description
                FROM services s LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id
                WHERE s.status=1 ORDER BY s.id")->fetchAll();
            foreach ($services as $row) {
                $id = (int)$row['id'];
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') $rows[] = ['context' => 'entity:service:' . $id . ':name', 'source' => $name];
                $description = trim((string)($row['description'] ?? ''));
                if ($description !== '') $rows[] = ['context' => 'entity:service:' . $id . ':description', 'source' => $description];
            }
        } catch (Throwable $e) {
            error_log('Language service catalog failed: ' . $e->getMessage());
        }
        try {
            $fields = $pdo->query("SELECT id, field_label, field_options, placeholder FROM service_fields WHERE field_label IS NOT NULL AND field_label<>'' ORDER BY id")->fetchAll();
            foreach ($fields as $row) {
                $fieldId = (int)$row['id'];
                $label = trim((string)($row['field_label'] ?? ''));
                if ($label !== '') $rows[] = ['context' => 'entity:field:' . $fieldId . ':label', 'source' => $label];
                $placeholder = trim((string)($row['placeholder'] ?? ''));
                if ($placeholder !== '') $rows[] = ['context' => 'entity:field:' . $fieldId . ':placeholder', 'source' => $placeholder];
                $rawOptions = trim((string)($row['field_options'] ?? ''));
                if ($rawOptions !== '') {
                    $decoded = json_decode($rawOptions, true);
                    $options = is_array($decoded) ? array_values($decoded) : preg_split('/\\r?\\n/', $rawOptions);
                    foreach ($options as $index => $option) {
                        if (is_array($option)) $option = $option['label'] ?? ($option['name'] ?? ($option['text'] ?? ''));
                        $option = trim((string)$option);
                        if (strpos($option, '|') !== false) $option = trim((string)explode('|', $option, 2)[0]);
                        if ($option !== '') $rows[] = ['context' => 'entity:field:' . $fieldId . ':option:' . (int)$index, 'source' => $option];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Language field catalog failed: ' . $e->getMessage());
        }
        try {
            $methods = $pdo->query("SELECT id, name, description FROM payment_methods WHERE status=1 ORDER BY sort_order,id")->fetchAll();
            foreach ($methods as $row) {
                $methodId = (int)$row['id'];
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') $rows[] = ['context' => 'entity:payment_method:' . $methodId . ':name', 'source' => $name];
                $description = trim((string)($row['description'] ?? ''));
                if ($description !== '') $rows[] = ['context' => 'entity:payment_method:' . $methodId . ':description', 'source' => $description];
            }
        } catch (Throwable $e) {
            error_log('Language payment method catalog failed: ' . $e->getMessage());
        }
        try {
            $paymentFields = $pdo->query("SELECT id, field_label FROM payment_method_fields WHERE field_label IS NOT NULL AND field_label<>'' ORDER BY id")->fetchAll();
            foreach ($paymentFields as $row) {
                $fieldId = (int)$row['id']; $label = trim((string)($row['field_label'] ?? ''));
                if ($label !== '') $rows[] = ['context' => 'entity:payment_field:' . $fieldId . ':label', 'source' => $label];
            }
        } catch (Throwable $e) {
            error_log('Language payment field catalog failed: ' . $e->getMessage());
        }
        return $rows;
    }
}

if (!function_exists('njazLanguageSyncDynamicEntities')) {
    /** يضيف العناصر الجديدة ويحافظ على الترجمة اليدوية عند تغير الاسم الأصلي. */
    function njazLanguageSyncDynamicEntities(PDO $pdo, bool $replaceSource = true): int
    {
        if (!njazLanguageDbEnsure($pdo)) return 0;
        $rows = njazLanguageDynamicRows($pdo);
        if (!$rows) return 0;
        $languages = $pdo->query("SELECT language_code FROM display_languages WHERE status=1 AND language_code<>'ar'")->fetchAll(PDO::FETCH_COLUMN);
        $find = $pdo->prepare('SELECT id,source_text,translated_text FROM language_translations WHERE language_code=? AND context=? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO language_translations (language_code,source_text,translated_text,context,status) VALUES (?,?,?,?,1)');
        $updateSource = $pdo->prepare('UPDATE language_translations SET source_text=? WHERE id=?');
        $updateBoth = $pdo->prepare('UPDATE language_translations SET source_text=?,translated_text=? WHERE id=?');
        $count = 0;
        foreach ($languages as $language) {
            $language = strtolower(trim((string)$language));
            if ($language === '') continue;
            foreach ($rows as $row) {
                $source = (string)$row['source']; $context = (string)$row['context'];
                $find->execute([$language, $context]);
                $existing = $find->fetch();
                if (!$existing) {
                    $insert->execute([$language, $source, $source, $context]);
                    $count++;
                    continue;
                }
                if (!$replaceSource || (string)$existing['source_text'] === $source) continue;
                if ((string)$existing['translated_text'] === (string)$existing['source_text']) {
                    $updateBoth->execute([$source, $source, (int)$existing['id']]);
                } else {
                    $updateSource->execute([$source, (int)$existing['id']]);
                }
            }
        }
        return $count;
    }
}

if (!function_exists('njazLanguageContextTranslations')) {
    function njazLanguageContextTranslations(PDO $pdo, string $language, bool $activeOnly = true): array
    {
        $language = strtolower(trim($language));
        if ($language === '') return [];
        try {
            $sql = 'SELECT context, translated_text FROM language_translations WHERE language_code=? AND context IS NOT NULL AND context<>\'\' AND translated_text<>source_text';
            if ($activeOnly) $sql .= ' AND status=1';
            $stmt = $pdo->prepare($sql . ' ORDER BY id ASC');
            $stmt->execute([$language]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                if ((string)$row['context'] !== '' && (string)$row['translated_text'] !== '') $out[(string)$row['context']] = (string)$row['translated_text'];
            }
            return $out;
        } catch (Throwable $e) {
            error_log('Language context read failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('njazLanguageContextRow')) {
    function njazLanguageContextRow(PDO $pdo, string $language, string $context, bool $activeOnly = true): ?array
    {
        $language = strtolower(trim($language));
        if ($language === '' || $context === '') return null;
        try {
            $sql = 'SELECT source_text, translated_text FROM language_translations WHERE language_code=? AND context=?';
            if ($activeOnly) $sql .= ' AND status=1';
            $sql .= ' ORDER BY id DESC LIMIT 1';
            $stmt = $pdo->prepare($sql); $stmt->execute([$language, $context]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('njazLanguageTranslateEntity')) {
    function njazLanguageTranslateEntity(PDO $pdo, string $language, string $context, string $fallback): string
    {
        $row = njazLanguageContextRow($pdo, $language, $context, true);
        return (string)($row['translated_text'] ?? $fallback);
    }
}

if (!function_exists('njazLanguageApiPayload')) {
    function njazLanguageApiPayload(PDO $pdo, ?string $requestedLanguage = null): array
    {
        // القراءة لا تنفذ مزامنة أو INSERT/UPDATE؛ المزامنة تتم من لوحة الإدارة فقط.
        $languages = njazLanguageCatalog($pdo, true);
        $translations = [];
        $contextTranslations = [];
        $requestedLanguage = strtolower(trim((string)$requestedLanguage));
        $codes = $requestedLanguage !== '' && isset($languages[$requestedLanguage])
            ? [$requestedLanguage]
            : array_keys($languages);
        foreach ($codes as $code) {
            $translations[$code] = njazLanguageTranslations($pdo, $code, true);
            $contextTranslations[$code] = njazLanguageContextTranslations($pdo, $code, true);
        }
        return ['languages' => array_values($languages), 'translations' => $translations, 'context_translations' => $contextTranslations];
    }
}

if (!function_exists('njazLanguageSyncFromJs')) {
    function njazLanguageSyncFromJs(PDO $pdo, bool $replaceExisting = false): array
    {
        $catalog = njazLanguageFileCatalog();
        if (!njazLanguageDbEnsure($pdo)) return ['languages' => 0, 'translations' => 0];
        $languageCount = 0;
        $translationCount = 0;
        $pdo->beginTransaction();
        try {
            $languageStmt = $pdo->prepare("INSERT INTO display_languages
                (language_code,language_name,language_name_native,direction,flag_emoji,status,sort_order,is_default)
                VALUES (?,?,?,?,?,1,?,?)
                ON DUPLICATE KEY UPDATE
                    language_name=VALUES(language_name), language_name_native=VALUES(language_name_native),
                    direction=VALUES(direction), flag_emoji=COALESCE(NULLIF(flag_emoji,''),VALUES(flag_emoji))");
            foreach ($catalog['languages'] as $language) {
                $languageStmt->execute([
                    $language['code'], $language['name'], $language['native_name'], $language['direction'],
                    $language['flag'], $language['sort_order'], $language['is_default']
                ]);
                $languageCount++;
            }

            $find = $pdo->prepare('SELECT id FROM language_translations WHERE language_code=? AND source_text=? AND ((context IS NULL AND ? IS NULL) OR context=?) LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO language_translations (language_code,source_text,translated_text,context,status) VALUES (?,?,?,?,1)');
            $update = $pdo->prepare('UPDATE language_translations SET translated_text=?, status=1 WHERE id=?');
            foreach ($catalog['translations'] as $code => $map) {
                foreach ($map as $source => $translated) {
                    if ($code === 'ar' || $source === '' || $translated === '') continue;
                    $context = null;
                    $find->execute([$code, $source, $context, $context]);
                    $existing = $find->fetchColumn();
                    if ($existing) {
                        if ($replaceExisting) $update->execute([$translated, (int)$existing]);
                    } else {
                        $insert->execute([$code, $source, $translated, $context]);
                    }
                    $translationCount++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Language catalog sync error: ' . $e->getMessage());
        }
        return ['languages' => $languageCount, 'translations' => $translationCount];
    }
}

if (!function_exists('njazLanguageEnsureSeeded')) {
    function njazLanguageEnsureSeeded(PDO $pdo): void
    {
        static $seeded = [];
        $key = spl_object_id($pdo);
        if (!njazLanguageDbEnsure($pdo)) return;
        try {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM display_languages')->fetchColumn();
            if ($count === 0) njazLanguageSyncFromJs($pdo, false);
            // لا تتم مزامنة الكتالوج الديناميكي تلقائياً داخل طلبات الموقع أو Telegram.
            // العناصر الجديدة تستخدم اسمها الأصلي إلى أن يضغط المدير زر المزامنة.
            $seeded[$key] = true;
        } catch (Throwable $e) {
            error_log('Language catalog seed error: ' . $e->getMessage());
        }
    }
}
