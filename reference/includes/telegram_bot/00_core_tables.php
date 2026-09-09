<?php

function njazTgEnsureTables(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        bot_token TEXT NULL,
        bot_username VARCHAR(120) NULL,
        webhook_secret VARCHAR(128) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        welcome_text TEXT NULL,
        allow_orders TINYINT(1) NOT NULL DEFAULT 1,
        allow_topup TINYINT(1) NOT NULL DEFAULT 1,
        allow_balance TINYINT(1) NOT NULL DEFAULT 1,
        allow_orders_history TINYINT(1) NOT NULL DEFAULT 1,
        allow_profile TINYINT(1) NOT NULL DEFAULT 1,
        allow_telecom TINYINT(1) NOT NULL DEFAULT 1,
        notify_customer_updates TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'allow_orders' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_topup' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_balance' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_orders_history' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_profile' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'allow_telecom' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notify_customer_updates' => 'TINYINT(1) NOT NULL DEFAULT 1'
    ] as $col => $def) { try { $pdo->exec("ALTER TABLE telegram_bot_settings ADD COLUMN IF NOT EXISTS `$col` $def"); } catch (Throwable $e) {} }
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_categories (
        category_id INT NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        label_override VARCHAR(200) NULL,
        media_type VARCHAR(16) NULL,
        media_url TEXT NULL,
        icon_custom_emoji_id VARCHAR(64) NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE telegram_bot_categories ADD COLUMN IF NOT EXISTS media_type VARCHAR(16) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_bot_categories ADD COLUMN IF NOT EXISTS media_url TEXT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE telegram_bot_categories ADD COLUMN IF NOT EXISTS icon_custom_emoji_id VARCHAR(64) NULL"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_services (
        service_id INT NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        label_override VARCHAR(200) NULL,
        icon_custom_emoji_id VARCHAR(64) NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE telegram_bot_services ADD COLUMN IF NOT EXISTS icon_custom_emoji_id VARCHAR(64) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("INSERT IGNORE INTO telegram_bot_categories (category_id) SELECT id FROM categories"); } catch (Throwable $e) {}
    try { $pdo->exec("INSERT IGNORE INTO telegram_bot_services (service_id) SELECT id FROM services"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        telegram_id BIGINT NOT NULL UNIQUE,
        chat_id BIGINT NOT NULL,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        first_name VARCHAR(160) NULL,
        state VARCHAR(60) NOT NULL DEFAULT 'menu',
        state_data LONGTEXT NULL,
        is_blocked TINYINT(1) NOT NULL DEFAULT 0,
        last_seen_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_tg_user_id (user_id),
        CONSTRAINT fk_telegram_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        update_id BIGINT NULL,
        telegram_id BIGINT NULL,
        direction ENUM('in','out','error') NOT NULL DEFAULT 'in',
        method VARCHAR(80) NULL,
        payload LONGTEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_logs_created (created_at),
        INDEX idx_tg_logs_tg (telegram_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // كتالوج محلي للمعرّفات التي أرسلها المدير أو وصلت داخل Webhook، مع حفظ file_id فقط دون Bot Token.
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_custom_emojis (
        custom_emoji_id VARCHAR(64) NOT NULL PRIMARY KEY,
        file_id VARCHAR(255) NULL,
        thumbnail_file_id VARCHAR(255) NULL,
        is_animated TINYINT(1) NOT NULL DEFAULT 0,
        is_video TINYINT(1) NOT NULL DEFAULT 0,
        last_error VARCHAR(255) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // يمنع إعادة معالجة نفس update_id عند إعادة إرسال Webhook من Telegram.
    // إعدادات Custom Emoji لكل عناصر قوائم Telegram، بما فيها القائمة الرئيسية والقوائم الفرعية والتنقل.
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_menu_items (
        menu_key VARCHAR(100) NOT NULL PRIMARY KEY,
        icon_custom_emoji_id VARCHAR(64) NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE telegram_bot_menu_items ADD COLUMN IF NOT EXISTS icon_custom_emoji_id VARCHAR(64) NULL"); } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_ui_settings (
        scope_key VARCHAR(120) NOT NULL PRIMARY KEY,
        button_style VARCHAR(16) NOT NULL DEFAULT 'primary',
        page_size TINYINT UNSIGNED NOT NULL DEFAULT 8,
        columns_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
        next_style VARCHAR(16) NOT NULL DEFAULT 'primary',
        previous_style VARCHAR(16) NOT NULL DEFAULT 'primary',
        cancel_style VARCHAR(16) NOT NULL DEFAULT 'danger',
        media_type VARCHAR(16) NULL,
        media_url TEXT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        'button_style' => "VARCHAR(16) NOT NULL DEFAULT 'primary'",
        'page_size' => 'TINYINT UNSIGNED NOT NULL DEFAULT 8',
        'columns_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 2',
        'next_style' => "VARCHAR(16) NOT NULL DEFAULT 'primary'",
        'previous_style' => "VARCHAR(16) NOT NULL DEFAULT 'primary'",
        'cancel_style' => "VARCHAR(16) NOT NULL DEFAULT 'danger'",
        'media_type' => 'VARCHAR(16) NULL',
        'media_url' => 'TEXT NULL'
    ] as $col => $def) { try { $pdo->exec("ALTER TABLE telegram_bot_ui_settings ADD COLUMN IF NOT EXISTS `$col` $def"); } catch (Throwable $e) {} }
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_subscription_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        message TEXT NULL,
        button_style VARCHAR(16) NOT NULL DEFAULT 'primary',
        verify_style VARCHAR(16) NOT NULL DEFAULT 'success',
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_subscription_targets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        target_type VARCHAR(16) NOT NULL DEFAULT 'channel',
        chat_id VARCHAR(80) NOT NULL,
        title VARCHAR(200) NULL,
        invite_url TEXT NOT NULL,
        button_text VARCHAR(255) NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tg_subscription_chat (chat_id),
        INDEX idx_tg_subscription_enabled (enabled, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bot_processed_updates (
        update_id BIGINT NOT NULL PRIMARY KEY,
        telegram_id BIGINT NULL,
        processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_tg_processed_at (processed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("INSERT IGNORE INTO telegram_bot_subscription_settings (id,message) VALUES (1,'🔒 <b>الاشتراك الإجباري</b>\\n\\nللاستمرار في استخدام البوت، يرجى الاشتراك في القنوات أو المجموعات التالية ثم الضغط على زر التحقق.')"); } catch (Throwable $e) {}
    // تنظيف دوري خفيف للسجلات القديمة مع الإبقاء على نافذة كافية لمنع الإعادة.
    try { $pdo->exec("DELETE FROM telegram_bot_processed_updates WHERE processed_at < (NOW() - INTERVAL 7 DAY)"); } catch (Throwable $e) {}
    $row = $pdo->query("SELECT id FROM telegram_bot_settings WHERE id=1")->fetchColumn();
    if (!$row) {
        $secret = bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO telegram_bot_settings (id,webhook_secret,welcome_text) VALUES (1,?,?)")
            ->execute([$secret, 'مرحباً بك في نجاز كارد. اربط حسابك للبدء.']);
    }
    try {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key=?");
        $exists->execute(['telegram_internal_secret']);
        if (!(int)$exists->fetchColumn()) {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?)")
                ->execute(['telegram_internal_secret', bin2hex(random_bytes(32))]);
        }
    } catch (Throwable $e) { error_log('Telegram settings secret init: ' . $e->getMessage()); }
    $initialized = true;
}
