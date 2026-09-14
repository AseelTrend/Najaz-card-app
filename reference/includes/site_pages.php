<?php
/**
 * صفحات المحتوى العامة القابلة للإدارة.
 * لا تُنشئ الجداول عند القراءة؛ إنشاء المخطط يتم من لوحة الإدارة فقط.
 */

if (!function_exists('sitePagesEnsureSchema')) {
    function sitePagesEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            title VARCHAR(180) NOT NULL,
            icon VARCHAR(60) NOT NULL DEFAULT 'file-alt',
            icon_color VARCHAR(30) NOT NULL DEFAULT '#00d4ff',
            content MEDIUMTEXT NOT NULL,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_site_pages_visible_sort (is_visible, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('sitePagesSeedDefaults')) {
    function sitePagesSeedDefaults(PDO $pdo): void
    {
        $aboutContent = function_exists('getSetting') ? (getSetting('page_about') ?: '<p>هذه الصفحة قيد التحديث.</p>') : '<p>هذه الصفحة قيد التحديث.</p>';
        $privacyContent = function_exists('getSetting') ? (getSetting('page_privacy') ?: '<p>هذه الصفحة قيد التحديث.</p>') : '<p>هذه الصفحة قيد التحديث.</p>';
        $defaults = [
            ['about', 'من نحن', 'building', '#00d4aa', $aboutContent, 1, 10],
            ['privacy', 'سياسة الخصوصية', 'lock', '#00d4ff', $privacyContent, 1, 20],
        ];

        $insert = $pdo->prepare("INSERT IGNORE INTO site_pages
            (slug, title, icon, icon_color, content, is_visible, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($defaults as $page) {
            $insert->execute($page);
        }

        // استرجاع المحتوى الأصلي من الإعدادات القديمة إذا كانت عملية سابقة
        // قد نزعت الوسوم وتركت CSS/HTML كنص ظاهر. لا نلمس صفحات جديدة.
        foreach ([
            ['about', $aboutContent],
            ['privacy', $privacyContent],
        ] as [$legacySlug, $legacyContent]) {
            if ($legacyContent === '' || strpos($legacyContent, '<') === false) {
                continue;
            }
            $check = $pdo->prepare('SELECT content FROM site_pages WHERE slug=? LIMIT 1');
            $check->execute([$legacySlug]);
            $currentContent = $check->fetchColumn();
            if ($currentContent === false || strpos((string)$currentContent, '<') !== false) {
                continue;
            }
            if (strpos((string)$currentContent, 'body {') !== false || strpos((string)$currentContent, '/*') !== false) {
                $restore = $pdo->prepare('UPDATE site_pages SET content=? WHERE slug=?');
                $restore->execute([$legacyContent, $legacySlug]);
            }
        }
    }
}

if (!function_exists('sitePagesNormalizeSlug')) {
    function sitePagesNormalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        return trim($slug, '-_');
    }
}

if (!function_exists('sitePagesLoadVisible')) {
    function sitePagesLoadVisible(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT id, slug, title, icon, icon_color, is_visible, sort_order
                FROM site_pages
                WHERE is_visible=1
                ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sitePagesFindVisibleBySlug')) {
    function sitePagesFindVisibleBySlug(PDO $pdo, string $slug): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM site_pages WHERE slug=? AND is_visible=1 LIMIT 1");
            $stmt->execute([sitePagesNormalizeSlug($slug)]);
            $page = $stmt->fetch(PDO::FETCH_ASSOC);
            return $page ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('sitePagesSanitizeContent')) {
    function sitePagesSanitizeContent(string $content): string
    {
        // التوافق مع الاسم القديم: المحتوى يُحفظ ويُعرض كما أدخله المدير.
        return $content;
    }
}

if (!function_exists('sitePagesSafeIcon')) {
    function sitePagesSafeIcon(string $icon): string
    {
        $icon = trim($icon);
        return preg_match('/^[a-z0-9-]{1,60}$/i', $icon) ? $icon : 'file-alt';
    }
}

if (!function_exists('sitePagesSafeColor')) {
    function sitePagesSafeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $color)) return $color;
        if (preg_match('/^rgba?\([0-9.,%\s]+\)$/i', $color)) return $color;
        return '#00d4ff';
    }
}
