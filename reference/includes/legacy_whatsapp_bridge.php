<?php

function legacyWaSubstr(string $value, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($value, $start) : mb_substr($value, $start, $length);
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
}

/**
 * جسر واتساب نجاز القديم / يمن روبوت.
 *
 * هذا الملف مستقل عن جداول العملاء والطلبات ووسائل الدفع وتيليجرام وطلبات سوا.
 * يستقبل طلبات التوافق التي كان يمن روبوت يرسلها إلى ReploSend، ثم يضعها
 * في سجل وطابور مستقلين. الإرسال الفعلي عبر HetaCloud لا يحدث إلا في وضع send.
 */

function legacyWaGenerateSecretToken(): string
{
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function legacyWaDefaultSettings(): array
{
    return [
        'enabled'       => '0',
        'mode'          => 'capture', // capture | send
        'auto_send_enabled' => '1', // تشغيل المعالجة التلقائية بعد الجدولة
        'send_delay_enabled' => '1', // تأخير محافظ قبل الإرسال الفعلي
        'send_delay_min_seconds' => '60',
        'send_delay_max_seconds' => '120',
        'api_key'       => '',
        'replo_api_key' => '',
        'token'         => 'replo',
        'type'          => 'tws_ex',
        'ip_allowlist'  => '',
        'last_schema_error' => '',
        // إعدادات HetaCloud مستقلة لنظام نجاز القديم فقط.
        'heta_api_key'  => '',
        'heta_sender'   => '',
        'heta_webhook_enabled' => '1',
        'heta_webhook_secret' => '',
        'heta_webhook_expected_sender' => '',
    ];
}

function legacyWaGetSetting(PDO $pdo, string $key): string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM legacy_wa_settings WHERE setting_key=? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? (legacyWaDefaultSettings()[$key] ?? '') : (string)$value;
    } catch (Throwable $e) {
        return legacyWaDefaultSettings()[$key] ?? '';
    }
}

function legacyWaGetHetaSetting(PDO $pdo, string $key): string
{
    if (!in_array($key, ['heta_api_key', 'heta_sender'], true)) return '';
    // رقم المرسل مستقل بالكامل. مفتاح الحساب يمكن استخدامه كاحتياطي لأنه لا يحدد جهاز الإرسال.
    $local = legacyWaGetSetting($pdo, $key);
    if ($local !== '') return $local;
    if ($key === 'heta_api_key') {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM whatsapp_settings WHERE setting_key=? LIMIT 1");
            $stmt->execute(['heta_api_key']);
            $value = $stmt->fetchColumn();
            return $value === false ? '' : (string)$value;
        } catch (Throwable $e) {
            return '';
        }
    }
    return '';
}

function legacyWaSetSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("INSERT INTO legacy_wa_settings (setting_key, setting_value)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()");
    $stmt->execute([$key, $value]);
}

function legacyWaEnsureSchema(PDO $pdo): array
{
    $created = [];
    $sql = [
        'legacy_wa_settings' => "CREATE TABLE IF NOT EXISTS `legacy_wa_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_legacy_wa_setting` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_inbound' => "CREATE TABLE IF NOT EXISTS `legacy_wa_inbound` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `external_id` VARCHAR(255) DEFAULT NULL,
            `request_hash` CHAR(64) NOT NULL,
            `number` VARCHAR(120) NOT NULL,
            `message` TEXT NOT NULL,
            `method` VARCHAR(30) DEFAULT NULL,
            `token_value` VARCHAR(80) DEFAULT NULL,
            `type_value` VARCHAR(80) DEFAULT NULL,
            `amount` DECIMAL(18,4) DEFAULT NULL,
            `balance` DECIMAL(18,4) DEFAULT NULL,
            `raw_payload` LONGTEXT DEFAULT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'captured',
            `error_message` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at` DATETIME DEFAULT NULL,
            UNIQUE KEY `uq_legacy_wa_request_hash` (`request_hash`),
            KEY `idx_legacy_wa_external_id` (`external_id`),
            KEY `idx_legacy_wa_status` (`status`),
            KEY `idx_legacy_wa_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_outbox' => "CREATE TABLE IF NOT EXISTS `legacy_wa_outbox` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `inbound_id` BIGINT UNSIGNED NOT NULL,
            `number` VARCHAR(120) NOT NULL,
            `message` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `attempt_count` INT NOT NULL DEFAULT 0,
            `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_error` TEXT DEFAULT NULL,
            `provider_message_id` VARCHAR(255) DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_legacy_wa_outbox_inbound` (`inbound_id`),
            KEY `idx_legacy_wa_outbox_due` (`status`,`available_at`),
            CONSTRAINT `fk_legacy_wa_outbox_inbound` FOREIGN KEY (`inbound_id`) REFERENCES `legacy_wa_inbound` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_attempts' => "CREATE TABLE IF NOT EXISTS `legacy_wa_attempts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `outbox_id` BIGINT UNSIGNED NOT NULL,
            `ok` TINYINT(1) NOT NULL DEFAULT 0,
            `http_status` SMALLINT DEFAULT NULL,
            `error_message` VARCHAR(1000) DEFAULT NULL,
            `response_excerpt` TEXT DEFAULT NULL,
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_legacy_wa_attempt_outbox` (`outbox_id`),
            CONSTRAINT `fk_legacy_wa_attempt_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `legacy_wa_outbox` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_requests' => "CREATE TABLE IF NOT EXISTS `legacy_wa_requests` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `method` VARCHAR(12) NOT NULL,
            `request_path` VARCHAR(1000) DEFAULT NULL,
            `content_type` VARCHAR(255) DEFAULT NULL,
            `remote_ip` VARCHAR(64) DEFAULT NULL,
            `header_names` TEXT DEFAULT NULL,
            `headers_safe` TEXT DEFAULT NULL,
            `field_names` TEXT DEFAULT NULL,
            `payload_shape` LONGTEXT DEFAULT NULL,
            `payload_hash` CHAR(64) NOT NULL,
            `payload_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            `auth_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `auth_reason` VARCHAR(80) DEFAULT NULL,
            `response_status` SMALLINT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME DEFAULT NULL,
            KEY `idx_legacy_wa_requests_created` (`created_at`),
            KEY `idx_legacy_wa_requests_auth` (`auth_status`,`auth_reason`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_webhook_log' => "CREATE TABLE IF NOT EXISTS `legacy_wa_webhook_log` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_type` VARCHAR(100) NOT NULL DEFAULT 'unknown',
            `device` VARCHAR(255) DEFAULT NULL,
            `signature_status` VARCHAR(30) NOT NULL DEFAULT 'not_checked',
            `payload_hash` CHAR(64) NOT NULL,
            `payload_excerpt` LONGTEXT DEFAULT NULL,
            `remote_ip` VARCHAR(64) DEFAULT NULL,
            `http_status` SMALLINT DEFAULT NULL,
            `processing_status` VARCHAR(30) NOT NULL DEFAULT 'received',
            `processing_message` VARCHAR(500) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at` DATETIME DEFAULT NULL,
            KEY `idx_legacy_wa_whlog_created` (`created_at`),
            KEY `idx_legacy_wa_whlog_status` (`processing_status`),
            KEY `idx_legacy_wa_whlog_hash` (`payload_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_incoming_messages' => "CREATE TABLE IF NOT EXISTS `legacy_wa_incoming_messages` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `message_id` VARCHAR(255) NOT NULL,
            `device` VARCHAR(255) DEFAULT NULL,
            `from_number` VARCHAR(180) DEFAULT NULL,
            `from_name` VARCHAR(255) DEFAULT NULL,
            `chat_id` VARCHAR(180) DEFAULT NULL,
            `chat_name` VARCHAR(255) DEFAULT NULL,
            `participant` VARCHAR(180) DEFAULT NULL,
            `message_type` VARCHAR(50) NOT NULL DEFAULT 'text',
            `message` TEXT DEFAULT NULL,
            `media_url` TEXT DEFAULT NULL,
            `media_mime` VARCHAR(150) DEFAULT NULL,
            `media_filename` VARCHAR(255) DEFAULT NULL,
            `caption` TEXT DEFAULT NULL,
            `is_group` TINYINT(1) NOT NULL DEFAULT 0,
            `raw_payload` LONGTEXT DEFAULT NULL,
            `event_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_legacy_wa_incoming_message` (`message_id`),
            KEY `idx_legacy_wa_incoming_created` (`created_at`),
            KEY `idx_legacy_wa_incoming_chat` (`chat_id`),
            KEY `idx_legacy_wa_incoming_from` (`from_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_group_events' => "CREATE TABLE IF NOT EXISTS `legacy_wa_group_events` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_id` VARCHAR(255) DEFAULT NULL,
            `device` VARCHAR(255) DEFAULT NULL,
            `group_id` VARCHAR(180) DEFAULT NULL,
            `group_name` VARCHAR(255) DEFAULT NULL,
            `participant` VARCHAR(180) DEFAULT NULL,
            `action` VARCHAR(80) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `event_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_legacy_wa_group_created` (`created_at`),
            KEY `idx_legacy_wa_group_id` (`group_id`),
            KEY `idx_legacy_wa_group_event` (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_device_status' => "CREATE TABLE IF NOT EXISTS `legacy_wa_device_status` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `device` VARCHAR(255) DEFAULT NULL,
            `event_name` VARCHAR(100) NOT NULL DEFAULT 'status',
            `status_value` VARCHAR(100) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `event_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_legacy_wa_device_created` (`created_at`),
            KEY `idx_legacy_wa_device_name` (`device`,`event_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_receipts' => "CREATE TABLE IF NOT EXISTS `legacy_wa_receipts` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `receipt_id` VARCHAR(255) DEFAULT NULL,
            `message_id` VARCHAR(255) DEFAULT NULL,
            `device` VARCHAR(255) DEFAULT NULL,
            `recipient_number` VARCHAR(180) DEFAULT NULL,
            `status_value` VARCHAR(80) DEFAULT NULL,
            `receipt_at` DATETIME DEFAULT NULL,
            `raw_payload` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_legacy_wa_receipt_created` (`created_at`),
            KEY `idx_legacy_wa_receipt_msg` (`message_id`),
            KEY `idx_legacy_wa_receipt_id` (`receipt_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_message_trace' => "CREATE TABLE IF NOT EXISTS `legacy_wa_message_trace` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `webhook_log_id` BIGINT UNSIGNED DEFAULT NULL,
            `message_id` VARCHAR(255) DEFAULT NULL,
            `stage` VARCHAR(80) NOT NULL,
            `details` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_legacy_wa_trace_log` (`webhook_log_id`),
            KEY `idx_legacy_wa_trace_msg` (`message_id`),
            KEY `idx_legacy_wa_trace_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'legacy_wa_manual_sends' => "CREATE TABLE IF NOT EXISTS `legacy_wa_manual_sends` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `number` VARCHAR(180) NOT NULL,
            `message` TEXT NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'processing',
            `provider_message_id` VARCHAR(255) DEFAULT NULL,
            `http_status` SMALLINT DEFAULT NULL,
            `error_message` VARCHAR(1000) DEFAULT NULL,
            `response_excerpt` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `finished_at` DATETIME DEFAULT NULL,
            KEY `idx_legacy_wa_manual_created` (`created_at`),
            KEY `idx_legacy_wa_manual_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    try {
        foreach ($sql as $name => $statement) {
            $pdo->exec($statement);
            $created[] = $name;
        }
        // ترقية آمنة للنسخ السابقة التي أنشأت الطابور قبل إضافة موعد التأخير.
        try {
            $pdo->exec("ALTER TABLE legacy_wa_outbox ADD COLUMN delay_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER available_at");
        } catch (Throwable $ignored) {
            // العمود موجود مسبقاً؛ لا نوقف الطلب.
        }
        try {
            $pdo->exec("ALTER TABLE legacy_wa_outbox MODIFY COLUMN delay_seconds INT UNSIGNED NOT NULL DEFAULT 0");
        } catch (Throwable $ignored) {
            // قد تكون الترقية غير مطلوبة أو غير مدعومة؛ لا نوقف استقبال الطلبات.
        }
        foreach (legacyWaDefaultSettings() as $key => $value) {
            if ($key === 'last_schema_error') continue;
            $stmt = $pdo->prepare("INSERT IGNORE INTO legacy_wa_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
        }
        // لا يُنفّذ الترحيل تلقائياً هنا. الترحيل إجراء إداري POST صريح فقط،
        // حتى لا يؤدي فتح لوحة الإدارة أو استقبال طلب عادي إلى قفل الطابور أو الإرسال.
        return ['ok' => true, 'tables' => $created, 'backfilled' => 0, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'tables' => $created, 'error' => legacyWaSubstr($e->getMessage(), 0, 500)];
    }
}

function legacyWaCancelOutbox(PDO $pdo, int $outboxId): array
{
    $outboxId = (int)$outboxId;
    if ($outboxId < 1) return ['ok' => false, 'reason' => 'invalid_id'];

    $startedTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        $rowStmt = $pdo->prepare('SELECT id,status,inbound_id FROM legacy_wa_outbox WHERE id=? FOR UPDATE');
        $rowStmt->execute([$outboxId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            if ($startedTransaction) $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ((string)$row['status'] !== 'pending') {
            if ($startedTransaction) $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_cancellable', 'status' => (string)$row['status']];
        }

        $pdo->prepare("UPDATE legacy_wa_outbox
            SET status='failed', last_error='cancelled_by_admin', available_at=NOW(), updated_at=NOW()
            WHERE id=? AND status='pending'")->execute([$outboxId]);
        $pdo->prepare("UPDATE legacy_wa_inbound
            SET status='failed', error_message='cancelled_by_admin', processed_at=NOW()
            WHERE id=?")->execute([(int)$row['inbound_id']]);

        if ($startedTransaction) $pdo->commit();
        return ['ok' => true, 'id' => $outboxId, 'status' => 'failed'];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'reason' => 'database_error', 'error' => legacyWaSubstr($e->getMessage(), 0, 300)];
    }
}

function legacyWaCleanupTerminalOutbox(PDO $pdo, int $batchSize = 100): array
{
    $batchSize = max(1, min(200, $batchSize));
    $startedTransaction = false;
    $deletedOutbox = 0;
    $deletedInbound = 0;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        $stmt = $pdo->query(
            "SELECT id,inbound_id FROM legacy_wa_outbox
             WHERE (status='sent' AND COALESCE(sent_at, updated_at) <= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                OR (status='failed' AND updated_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR))
             ORDER BY id ASC LIMIT " . $batchSize . " FOR UPDATE"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            if ($startedTransaction) $pdo->commit();
            return ['ok' => true, 'outbox_deleted' => 0, 'inbound_deleted' => 0];
        }
        $ids = array_values(array_filter(array_map(static fn($row): int => (int)($row['id'] ?? 0), $rows)));
        $inboundIds = array_values(array_filter(array_map(static fn($row): int => (int)($row['inbound_id'] ?? 0), $rows)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $pdo->prepare("DELETE FROM legacy_wa_outbox WHERE id IN ($placeholders)");
        $delete->execute($ids);
        $deletedOutbox = $delete->rowCount();

        if ($inboundIds) {
            $inboundPlaceholders = implode(',', array_fill(0, count($inboundIds), '?'));
            $inboundDelete = $pdo->prepare("DELETE FROM legacy_wa_inbound
                WHERE id IN ($inboundPlaceholders)
                  AND NOT EXISTS (SELECT 1 FROM legacy_wa_outbox o WHERE o.inbound_id=legacy_wa_inbound.id)");
            $inboundDelete->execute($inboundIds);
            $deletedInbound = $inboundDelete->rowCount();
        }
        if ($startedTransaction) $pdo->commit();
        return ['ok' => true, 'outbox_deleted' => $deletedOutbox, 'inbound_deleted' => $deletedInbound];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'outbox_deleted' => 0, 'inbound_deleted' => 0, 'error' => legacyWaSubstr($e->getMessage(), 0, 300)];
    }
}

function legacyWaRescheduleOutbox(PDO $pdo, int $outboxId): array
{
    $outboxId = (int)$outboxId;
    if ($outboxId < 1) return ['ok' => false, 'reason' => 'invalid_id'];

    $settings = legacyWaGetSendDelaySettings($pdo);
    $schedule = legacyWaDelayedAt($pdo);
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $rowStmt = $pdo->prepare('SELECT id,status,available_at FROM legacy_wa_outbox WHERE id=? FOR UPDATE');
        $rowStmt->execute([$outboxId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            if ($startedTransaction) $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found'];
        }

        $status = (string)($row['status'] ?? '');
        if (!in_array($status, ['pending', 'failed'], true)) {
            if ($startedTransaction) $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_retryable', 'status' => $status];
        }
        if ($status === 'pending') {
            $availableTs = strtotime((string)($row['available_at'] ?? ''));
            if ($availableTs !== false && $availableTs > time()) {
                if ($startedTransaction) $pdo->rollBack();
                return ['ok' => false, 'reason' => 'not_due', 'available_at' => $row['available_at']];
            }
        }

        $updateSql = "UPDATE legacy_wa_outbox
            SET status='pending', attempt_count=0, available_at=?, delay_seconds=?,
                last_error=NULL, provider_message_id=NULL, sent_at=NULL, updated_at=NOW()
            WHERE id=? AND status IN ('pending','failed')";
        try {
            $update = $pdo->prepare($updateSql);
            $update->execute([$schedule['available_at'], $schedule['delay_seconds'], $outboxId]);
        } catch (Throwable $columnError) {
            if (stripos($columnError->getMessage(), 'delay_seconds') === false && stripos($columnError->getMessage(), 'unknown column') === false) throw $columnError;
            $update = $pdo->prepare("UPDATE legacy_wa_outbox
                SET status='pending', attempt_count=0, available_at=?,
                    last_error=NULL, provider_message_id=NULL, sent_at=NULL, updated_at=NOW()
                WHERE id=? AND status IN ('pending','failed')");
            $update->execute([$schedule['available_at'], $outboxId]);
        }

        if ($update->rowCount() < 1) {
            if ($startedTransaction) $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_retryable'];
        }

        $pdo->prepare("UPDATE legacy_wa_inbound
            SET status='pending', error_message=NULL, processed_at=NULL
            WHERE id=(SELECT inbound_id FROM legacy_wa_outbox WHERE id=?)")->execute([$outboxId]);

        if ($startedTransaction) $pdo->commit();
        return [
            'ok' => true,
            'id' => $outboxId,
            'delay_seconds' => (int)$schedule['delay_seconds'],
            'available_at' => $schedule['available_at'],
            'delay_enabled' => !empty($settings['enabled']),
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'reason' => 'database_error', 'error' => legacyWaSubstr($e->getMessage(), 0, 300)];
    }
}

function legacyWaBackfillPendingSchedules(PDO $pdo, int $batchSize = 100): int
{
    // يعالج فقط الرسائل القديمة pending التي لم يكن لها تأخير محفوظ.
    // لا يغير الرسائل الجديدة أو الصفوف المرسلة/الفاشلة، ولا ينشئ صفوفاً جديدة.
    // الحد الصغير يقلل مدة القفل؛ تكرار الإجراء اليدوي أو المشغل الدوري يعالج الباقي.
    $batchSize = max(1, min(100, $batchSize));
    $startedTransaction = false;
    $updated = 0;

    try {
        $settings = legacyWaGetSendDelaySettings($pdo);
        if (!$settings['enabled']) return 0;

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $stmt = $pdo->query(
            "SELECT id FROM legacy_wa_outbox
             WHERE status='pending' AND (delay_seconds IS NULL OR delay_seconds=0)
             ORDER BY id ASC LIMIT " . $batchSize . " FOR UPDATE"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            if ($startedTransaction) $pdo->commit();
            return 0;
        }

        $update = $pdo->prepare(
            "UPDATE legacy_wa_outbox
             SET delay_seconds=?, available_at=?
             WHERE id=? AND status='pending' AND (delay_seconds IS NULL OR delay_seconds=0)"
        );
        foreach ($ids as $id) {
            try {
                $delay = random_int($settings['min'], $settings['max']);
            } catch (Throwable $e) {
                $delay = $settings['min'];
            }
            $availableAt = date('Y-m-d H:i:s', time() + $delay);
            $update->execute([$delay, $availableAt, (int)$id]);
            $updated += $update->rowCount();
        }

        if ($startedTransaction) $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        return 0;
    }
}

function legacyWaGetSendDelaySettings(PDO $pdo): array
{
    $enabled = legacyWaGetSetting($pdo, 'send_delay_enabled') === '1';
    $min = (int)legacyWaGetSetting($pdo, 'send_delay_min_seconds');
    $max = (int)legacyWaGetSetting($pdo, 'send_delay_max_seconds');
        // الحد الأدنى يبدأ من ثانية واحدة، والحد الأقصى موجب بلا سقف واجهة ثابت.
        // نستخدم أكبر فرق زمني قابل للحساب في PHP لحماية random_int وعمليات حساب الوقت من overflow.
        $safeIntegerMax = max(1, PHP_INT_MAX - time());
        $min = max(1, min($safeIntegerMax, $min > 0 ? $min : 60));
        $max = max(1, min($safeIntegerMax, $max > 0 ? $max : 120));
    if ($max < $min) [$min, $max] = [$max, $min];
    return ['enabled' => $enabled, 'min' => $min, 'max' => $max];
}

function legacyWaPickSendDelaySeconds(PDO $pdo): int
{
    $settings = legacyWaGetSendDelaySettings($pdo);
    if (!$settings['enabled']) return 0;
    try {
        return random_int($settings['min'], $settings['max']);
    } catch (Throwable $e) {
        return $settings['min'];
    }
}

function legacyWaDelayedAt(PDO $pdo, ?int $delaySeconds = null): array
{
    $delay = $delaySeconds === null ? legacyWaPickSendDelaySeconds($pdo) : max(0, (int)$delaySeconds);
    return [
        'delay_seconds' => $delay,
        'available_at' => date('Y-m-d H:i:s', time() + $delay),
    ];
}

function legacyWaSchemaStatus(PDO $pdo): array
{
    $required = [
        'legacy_wa_settings', 'legacy_wa_inbound', 'legacy_wa_outbox', 'legacy_wa_attempts',
        'legacy_wa_requests', 'legacy_wa_webhook_log', 'legacy_wa_incoming_messages',
        'legacy_wa_group_events', 'legacy_wa_device_status', 'legacy_wa_receipts',
        'legacy_wa_message_trace', 'legacy_wa_manual_sends',
    ];

    try {
        $quoted = implode(',', array_map(static function (string $name): string {
            return "'" . str_replace("'", "''", $name) . "'";
        }, $required));
        $stmt = $pdo->query("SELECT table_name FROM information_schema.tables
            WHERE table_schema=DATABASE() AND table_name IN ({$quoted})");
        $found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff($required, $found));
        $delayColumnReady = false;
        if (!$missing) {
            $columnStmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name='legacy_wa_outbox'
                AND column_name='delay_seconds'");
            $delayColumnReady = (int)$columnStmt->fetchColumn() > 0;
        }
        return [
            'ok' => count($missing) === 0,
            'tables' => $found,
            'missing' => $missing,
            'delay_column_ready' => $delayColumnReady,
            'needs_upgrade' => count($missing) === 0 && !$delayColumnReady,
            'backfilled' => 0,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'tables' => [],
            'missing' => $required,
            'delay_column_ready' => false,
            'needs_upgrade' => false,
            'backfilled' => 0,
            'error' => legacyWaSubstr($e->getMessage(), 0, 500),
        ];
    }
}

function legacyWaSchemaReady(PDO $pdo): bool
{
    $status = legacyWaSchemaStatus($pdo);
    return !empty($status['ok']);
}

function legacyWaHetaHeaders(): array
{
    return function_exists('getallheaders') && is_array(getallheaders()) ? getallheaders() : [];
}

function legacyWaHetaSignatureStatus(string $rawBody, string $secret, array $headers): string
{
    if ($secret === '') return 'not_configured';
    $provided = legacyWaHeaderValue($headers, [
        'x-webhook-signature', 'x-hub-signature', 'x-hub-signature-256', 'x-signature',
        'x-hetacloud-signature', 'signature'
    ]);
    if ($provided === '') return 'missing';
    $digest = hash_hmac('sha256', $rawBody, $secret);
    $candidates = [$digest, 'sha256=' . $digest, 'sha256-' . $digest];
    foreach ($candidates as $candidate) {
        if (hash_equals($candidate, trim($provided))) return 'valid';
    }
    return 'invalid';
}

function legacyWaHetaNestedScalar(array $root, array $keys, int $maxNodes = 1500): string
{
    $wanted = [];
    foreach ($keys as $key) $wanted[strtolower((string)$key)] = true;
    $stack = [$root];
    $seen = 0;
    while ($stack && $seen < $maxNodes) {
        $node = array_pop($stack);
        if (!is_array($node)) continue;
        $seen++;
        foreach ($node as $key => $value) {
            if (isset($wanted[strtolower((string)$key)]) && is_scalar($value)) {
                $candidate = trim((string)$value);
                if ($candidate !== '') return $candidate;
            }
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

function legacyWaHetaGroupId(array $root): string
{
    $candidate = legacyWaHetaNestedScalar($root, [
        'group_id','groupId','chat_id','chatId','remoteJid','remote_jid',
        'chatJid','chat_jid','conversationId','conversation_id','jid'
    ]);
    return substr($candidate, -5) === '@g.us' ? $candidate : '';
}

function legacyWaHetaEventName(array $data): string
{
    $name = legacyWaHetaNestedScalar($data, ['event','event_type','eventType','type','action','status']);
    return legacyWaSubstr($name !== '' ? $name : 'unknown', 0, 100);
}

function legacyWaHetaDateTime(array $data): ?string
{
    $value = legacyWaHetaNestedScalar($data, ['timestamp','time','date','datetime','created_at','createdAt']);
    if ($value === '') return null;
    if (is_numeric($value)) {
        $number = (int)$value;
        if ($number > 20000000000) $number = (int)floor($number / 1000);
        if ($number > 0) return date('Y-m-d H:i:s', $number);
    }
    $time = strtotime($value);
    return $time !== false ? date('Y-m-d H:i:s', $time) : null;
}

function legacyWaJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function legacyWaReadRequest(): array
{
    $raw = file_get_contents('php://input');
    $data = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = $decoded;
        if (!$data) parse_str($raw, $formData);
        if (!$data && isset($formData) && is_array($formData)) $data = $formData;
    }
    if (!$data && !empty($_POST)) $data = $_POST;
    return ['raw' => (string)$raw, 'data' => is_array($data) ? $data : []];
}

function legacyWaFirstScalar(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_scalar($data[$key])) {
            $value = trim((string)$data[$key]);
            if ($value !== '') return $value;
        }
    }
    return '';
}

function legacyWaExtractRequest(array $data, string $raw = ''): array
{
    $number = legacyWaFirstScalar($data, ['number','phone','to','recipient','mobile']);
    $message = legacyWaFirstScalar($data, ['message','text','body','msg','content']);
    $externalId = legacyWaFirstScalar($data, ['id','message_id','messageId','request_id','requestId','transaction_id']);
    $token = legacyWaFirstScalar($data, ['token','Token','access_token']);
    $type = legacyWaFirstScalar($data, ['type','Type']);
    $method = legacyWaFirstScalar($data, ['method','send_method']);
    $address = legacyWaFirstScalar($data, ['address','jid','chat','chat_id']);
    $amount = legacyWaFirstScalar($data, ['amount','T','total']);
    $balance = legacyWaFirstScalar($data, ['balance','R']);
    $hashSource = $raw !== '' ? $raw : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'number' => $number,
        'message' => $message,
        'external_id' => $externalId,
        'token' => $token,
        'type' => $type,
        'method' => $method,
        'address' => $address,
        'amount' => is_numeric($amount) ? (float)$amount : null,
        'balance' => is_numeric($balance) ? (float)$balance : null,
        'request_hash' => hash('sha256', (string)$hashSource),
    ];
}

function legacyWaSanitizeValue($value, int $depth = 0)
{
    if ($depth > 6) return '[depth_limit]';
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string)$key);
            if (preg_match('/api.?key|authorization|password|passwd|secret|cookie|token|access.?token/i', $keyString)) {
                $out[$key] = '[redacted]';
            } else {
                $out[$key] = legacyWaSanitizeValue($item, $depth + 1);
            }
        }
        return $out;
    }
    if (is_string($value)) return legacyWaSubstr($value, 0, 4000);
    if (is_scalar($value) || $value === null) return $value;
    return '[unsupported]';
}

function legacyWaSanitizedPayload(array $data): string
{
    $safe = legacyWaSanitizeValue($data);
    $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return legacyWaSubstr($json ?: '{}', 0, 20000);
}

function legacyWaDiagnosticShape($value, int $depth = 0)
{
    if ($depth > 6) return ['type'=>'depth_limit'];
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $keyString = (string)$key;
            $lower = strtolower($keyString);
            if (preg_match('/api.?key|authorization|password|passwd|secret|cookie|token|access.?token/i', $lower)) {
                $out[$keyString] = ['type'=>'redacted'];
            } elseif (is_array($item)) {
                $out[$keyString] = legacyWaDiagnosticShape($item, $depth + 1);
            } elseif (is_string($item)) {
                $out[$keyString] = ['type'=>'string', 'length'=>strlen($item)];
            } elseif (is_bool($item)) {
                $out[$keyString] = ['type'=>'bool'];
            } elseif (is_int($item) || is_float($item)) {
                $out[$keyString] = ['type'=>'number'];
            } elseif ($item === null) {
                $out[$keyString] = ['type'=>'null'];
            } else {
                $out[$keyString] = ['type'=>'unsupported'];
            }
        }
        return $out;
    }
    return ['type'=>gettype($value)];
}

function legacyWaDiagnosticHeaders(array $headers): array
{
    $safe = [];
    foreach ($headers as $key => $value) {
        $name = (string)$key;
        $lower = strtolower($name);
        if (preg_match('/authorization|api.?key|password|passwd|secret|cookie|token/i', $lower)) {
            $safe[$name] = '[redacted]';
        } elseif (is_scalar($value)) {
            $safe[$name] = legacyWaSubstr((string)$value, 0, 255);
        } else {
            $safe[$name] = '[non_scalar]';
        }
    }
    return $safe;
}

function legacyWaRequestDiagnosticData(array $request): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $raw = (string)($request['raw'] ?? '');
    $data = is_array($request['data'] ?? null) ? $request['data'] : [];
    $hashSource = $raw !== '' ? $raw : (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') $path = (string)($_SERVER['PHP_SELF'] ?? '');
    return [
        'method' => legacyWaSubstr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12),
        'request_path' => legacyWaSubstr($path, 0, 1000),
        'content_type' => legacyWaSubstr((string)($_SERVER['CONTENT_TYPE'] ?? ''), 0, 255),
        'remote_ip' => legacyWaSubstr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'header_names' => array_values(array_map('strval', array_keys($headers))),
        'headers_safe' => legacyWaDiagnosticHeaders($headers),
        'field_names' => array_values(array_map('strval', array_keys($data))),
        'payload_shape' => legacyWaDiagnosticShape($data),
        'payload_hash' => hash('sha256', $hashSource),
        'payload_bytes' => strlen($raw),
    ];
}

function legacyWaRecordRequest(PDO $pdo, array $request): ?int
{
    try {
        $diag = legacyWaRequestDiagnosticData($request);
        $stmt = $pdo->prepare("INSERT INTO legacy_wa_requests
            (method,request_path,content_type,remote_ip,header_names,headers_safe,field_names,payload_shape,payload_hash,payload_bytes)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $diag['method'],
            $diag['request_path'],
            $diag['content_type'],
            $diag['remote_ip'],
            json_encode($diag['header_names'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($diag['headers_safe'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($diag['field_names'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($diag['payload_shape'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $diag['payload_hash'],
            $diag['payload_bytes'],
        ]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return null;
    }
}

function legacyWaFinishRequest(PDO $pdo, ?int $diagnosticId, string $status, string $reason, int $responseStatus): void
{
    if (!$diagnosticId) return;
    try {
        $stmt = $pdo->prepare("UPDATE legacy_wa_requests SET auth_status=?,auth_reason=?,response_status=?,finished_at=NOW() WHERE id=?");
        $stmt->execute([$status, legacyWaSubstr($reason, 0, 80), $responseStatus, $diagnosticId]);
    } catch (Throwable $e) {}
}

function legacyWaHeaderValue(array $headers, array $names): string
{
    foreach ($names as $name) {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0 && is_scalar($value)) return trim((string)$value);
        }
    }
    return '';
}

function legacyWaAuthorized(PDO $pdo, array $data): array
{
    $configuredKey = legacyWaGetSetting($pdo, 'api_key');
    $configuredToken = legacyWaGetSetting($pdo, 'token');
    $configuredType = legacyWaGetSetting($pdo, 'type');
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $providedKey = legacyWaFirstScalar($data, ['api_key','apikey','key','password','Password']);
    if ($providedKey === '') $providedKey = legacyWaHeaderValue($headers, [
        'x-api-key', 'X-API-Key', 'api-key', 'Api-Key',
        'x-auth-token', 'X-Auth-Token', 'x-access-token', 'X-Access-Token',
        'x-password', 'X-Password', 'password', 'Password'
    ]);
    if ($providedKey === '') {
        $authorization = legacyWaHeaderValue($headers, ['authorization','Authorization']);
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) $providedKey = trim($match[1]);
    }
    $providedToken = legacyWaFirstScalar($data, ['token','Token','access_token']);
    $providedType = legacyWaFirstScalar($data, ['type','Type']);

    $acceptedKeys = array_values(array_filter([
        $configuredKey,
        legacyWaGetSetting($pdo, 'replo_api_key'),
    ], static fn($value) => is_string($value) && $value !== ''));
    $keyAccepted = false;
    foreach ($acceptedKeys as $acceptedKey) {
        if (hash_equals($acceptedKey, $providedKey)) {
            $keyAccepted = true;
            break;
        }
    }
    if (!$keyAccepted) {
        return ['ok'=>false, 'status'=>401, 'reason'=>'invalid_api_key'];
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $isReploSendPath = is_string($requestPath) && (bool)preg_match('~/api/send/?$~i', $requestPath);
    if ($configuredToken !== '' && $providedToken !== '' && !hash_equals($configuredToken, $providedToken)) {
        return ['ok'=>false, 'status'=>401, 'reason'=>'invalid_token'];
    }
    if ($configuredType !== '' && $providedType !== '' && !hash_equals($configuredType, $providedType)) {
        return ['ok'=>false, 'status'=>400, 'reason'=>'invalid_type'];
    }
    if (!$isReploSendPath && $configuredToken !== '' && $providedToken === '') {
        return ['ok'=>false, 'status'=>401, 'reason'=>'invalid_token'];
    }
    if (!$isReploSendPath && $configuredType !== '' && $providedType === '') {
        return ['ok'=>false, 'status'=>400, 'reason'=>'invalid_type'];
    }

    $allowlist = array_values(array_filter(array_map('trim', explode(',', legacyWaGetSetting($pdo, 'ip_allowlist')))));
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($allowlist && !in_array($remoteIp, $allowlist, true)) {
        return ['ok'=>false, 'status'=>403, 'reason'=>'ip_not_allowed'];
    }
    return ['ok'=>true, 'status'=>200, 'reason'=>$isReploSendPath ? 'authorized_replo_send' : 'authorized'];
}

function legacyWaProviderSend(PDO $pdo, string $number, string $message): array
{
    $apiKey = legacyWaGetHetaSetting($pdo, 'heta_api_key');
    $sender = legacyWaGetHetaSetting($pdo, 'heta_sender');
    if ($apiKey === '' || $sender === '' || $number === '' || $message === '') {
        return ['ok'=>false, 'error'=>'missing_hetacloud_configuration', 'http_status'=>0, 'provider_id'=>''];
    }

    $endpoint = 'https://sender.hetacloud.top/send-message';
    $payload = ['api_key'=>$apiKey, 'sender'=>$sender, 'number'=>$number, 'message'=>$message];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode((string)$body, true);
    $ok = !$curlError && is_array($result) && !empty($result['status']);
    $error = $curlError ?: ($result['msg'] ?? $result['message'] ?? $result['error'] ?? $result['reason'] ?? '');
    if (!$error && !$ok && $body) $error = 'provider_rejected';
    if (!$error && !$ok) $error = 'empty_provider_response';
    $providerId = is_array($result) ? (string)($result['id'] ?? $result['message_id'] ?? $result['messageId'] ?? '') : '';
    $excerpt = is_array($result) ? json_encode(legacyWaSanitizeValue($result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return ['ok'=>$ok, 'error'=>legacyWaSubstr((string)$error, 0, 1000), 'http_status'=>$httpStatus, 'provider_id'=>$providerId, 'response_excerpt'=>legacyWaSubstr((string)$excerpt, 0, 4000)];
}

function legacyWaAuthFromSettingsForDisplay(PDO $pdo): array
{
    return [
        'enabled' => legacyWaGetSetting($pdo, 'enabled') === '1',
        'mode' => legacyWaGetSetting($pdo, 'mode'),
        'token' => legacyWaGetSetting($pdo, 'token'),
        'type' => legacyWaGetSetting($pdo, 'type'),
        'has_api_key' => legacyWaGetSetting($pdo, 'api_key') !== '',
        'ip_allowlist' => legacyWaGetSetting($pdo, 'ip_allowlist'),
    ];
}
