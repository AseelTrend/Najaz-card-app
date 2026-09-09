<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║          HetaCloud WhatsApp Webhook Handler                  ║
 * ║  يستقبل جميع الأحداث الواردة من HetaCloud ويعالجها           ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * الأحداث المعالجة:
 *  - رسائل نصية واردة (شخصية + مجموعات)
 *  - وسائط (صور، فيديو، صوت، مستندات)
 *  - أحداث المجموعات (إنشاء، انضمام، مغادرة، تحديث المعلومات)
 *  - حالة الجهاز (متصل / منفصل)
 *  - إيصالات القراءة
 */

// ── لا session هنا — endpoint عام ─────────────────────────
require_once __DIR__ . '/includes/config.php';

// فحص صحة خفيف من صفحة المراقبة؛ لا ينشئ سجلاً ولا يعالج رسالة.
if (isset($_GET['monitor_probe'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'service' => 'njaz-whatsapp-webhook', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════
//  إنشاء الجداول عند الحاجة فقط
//  لا ننفذ DDL مع كل رسالة واردة؛ ذلك قد يسبب تأخيراً أو أقفالاً
//  ويؤدي إلى فقد الحدث إذا انتهت مهلة مزود Webhook.
// ══════════════════════════════════════════════════════════
$waSchemaReady = false;
try {
    $schemaCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ('wa_incoming_messages','wa_group_events','wa_device_status','wa_receipts','wa_webhook_log','whatsapp_groups','whatsapp_settings','wa_message_trace')");
    $queueCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name='whatsapp_activation_queue'");
    $waSchemaReady = ((int)$schemaCheck->fetchColumn() >= 8 && (int)$queueCheck->fetchColumn() >= 1);
} catch (Throwable $e) {
    $waSchemaReady = false;
}

if (!$waSchemaReady) {
try {
    // جدول الرسائل الواردة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_incoming_messages` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `message_id`      VARCHAR(100) DEFAULT NULL,
        `device`          VARCHAR(50)  DEFAULT NULL,
        `from_number`     VARCHAR(100) NOT NULL,
        `from_name`       VARCHAR(255) DEFAULT NULL,
        `group_id`        VARCHAR(100) DEFAULT NULL,
        `group_name`      VARCHAR(255) DEFAULT NULL,
        `participant`     VARCHAR(100) DEFAULT NULL,
        `message_type`    ENUM('text','image','video','audio','document','sticker','location','contact','poll','unknown') DEFAULT 'text',
        `message`         TEXT         DEFAULT NULL,
        `media_url`       TEXT         DEFAULT NULL,
        `media_mime`      VARCHAR(100) DEFAULT NULL,
        `media_filename`  VARCHAR(255) DEFAULT NULL,
        `caption`         TEXT         DEFAULT NULL,
        `is_group`        TINYINT(1)   DEFAULT 0,
        `is_read`         TINYINT(1)   DEFAULT 0,
        `raw_payload`     LONGTEXT     DEFAULT NULL,
        `received_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_from (`from_number`),
        INDEX idx_group (`group_id`),
        INDEX idx_device (`device`),
        INDEX idx_received (`received_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // جدول أحداث المجموعات
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_group_events` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `event_type`  VARCHAR(50)  NOT NULL,
        `group_id`    VARCHAR(100) NOT NULL,
        `group_name`  VARCHAR(255) DEFAULT NULL,
        `actor`       VARCHAR(100) DEFAULT NULL,
        `target`      VARCHAR(100) DEFAULT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `raw_payload` LONGTEXT     DEFAULT NULL,
        `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_group (`group_id`),
        INDEX idx_event (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // جدول حالة الأجهزة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_device_status` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `device`     VARCHAR(50)  NOT NULL,
        `status`     ENUM('connected','disconnected','qr_ready','error') DEFAULT 'connected',
        `detail`     VARCHAR(500) DEFAULT NULL,
        `logged_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device (`device`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // جدول إيصالات التسليم / القراءة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_receipts` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `message_id` VARCHAR(100) DEFAULT NULL,
        `phone`      VARCHAR(100) DEFAULT NULL,
        `status`     ENUM('sent','delivered','read','failed') DEFAULT 'sent',
        `logged_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_msg (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // جدول سجل Webhook الخام (للتشخيص)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_webhook_log` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `event`      VARCHAR(100) DEFAULT 'unknown',
        `ip`         VARCHAR(45)  DEFAULT NULL,
        `payload`    LONGTEXT     DEFAULT NULL,
        `processed`  TINYINT(1)   DEFAULT 0,
        `error`      TEXT         DEFAULT NULL,
        `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event (`event`),
        INDEX idx_created (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // سجل تتبع دورة الرسالة من وصول Webhook حتى الحفظ والاستجابة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `wa_message_trace` (
        `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
        `request_id`      VARCHAR(80) NOT NULL,
        `webhook_log_id`  INT DEFAULT NULL,
        `message_id`      VARCHAR(100) DEFAULT NULL,
        `event_type`      VARCHAR(100) DEFAULT NULL,
        `device`          VARCHAR(100) DEFAULT NULL,
        `group_id`        VARCHAR(100) DEFAULT NULL,
        `group_name`      VARCHAR(255) DEFAULT NULL,
        `stage`           VARCHAR(40) NOT NULL DEFAULT 'received',
        `received_at`     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        `parsed_at`       DATETIME(6) DEFAULT NULL,
        `persisted_at`    DATETIME(6) DEFAULT NULL,
        `responded_at`    DATETIME(6) DEFAULT NULL,
        `http_status`     SMALLINT DEFAULT NULL,
        `processing_ms`   INT DEFAULT NULL,
        `db_insert_ms`    INT DEFAULT NULL,
        `remote_ip`       VARCHAR(45) DEFAULT NULL,
        `payload_hash`    CHAR(64) DEFAULT NULL,
        `error`           TEXT DEFAULT NULL,
        `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_trace_message (`message_id`),
        INDEX idx_trace_stage (`stage`),
        INDEX idx_trace_received (`received_at`),
        INDEX idx_trace_event (`event_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // طابور التنشيط المؤجل — لا يُرسل من Webhook، بل يعالجه عامل Cron
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_activation_queue` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `source_message_id` VARCHAR(255) NOT NULL,
        `target_group_id` VARCHAR(120) NOT NULL,
        `message_base` VARCHAR(80) NOT NULL,
        `scheduled_at` DATETIME NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `attempt_count` INT NOT NULL DEFAULT 0,
        `last_error` VARCHAR(1000) DEFAULT NULL,
        `sent_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_activation_source_message` (`source_message_id`),
        KEY `idx_activation_due` (`status`,`scheduled_at`),
        KEY `idx_activation_target_status` (`target_group_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // تأكد من وجود جدول المجموعات
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_groups` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `group_id`          VARCHAR(100) UNIQUE NOT NULL,
        `group_name`        VARCHAR(255) NOT NULL,
        `participants_count` INT DEFAULT 0,
        `description`       TEXT DEFAULT NULL,
        `owner`             VARCHAR(100) DEFAULT NULL,
        `is_active`         TINYINT(1)  DEFAULT 1,
        `last_synced`       TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        `created_at`        TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // تأكد من وجود جدول الإعدادات
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_settings` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key`   VARCHAR(100) UNIQUE NOT NULL,
        `setting_value` TEXT,
        `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (Exception $e) {
    error_log('[WA-Webhook] DB init error: ' . $e->getMessage());
}
}

// ══════════════════════════════════════════════════════════
//  دوال مساعدة
// ══════════════════════════════════════════════════════════
function waGetSetting($key) {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM whatsapp_settings WHERE setting_key=?");
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ? $r['setting_value'] : '';
    } catch (Exception $e) { return ''; }
}

function logRaw($event, $payload, $ip) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO wa_webhook_log (event,ip,payload) VALUES(?,?,?)")
            ->execute([$event, $ip, $payload]);
        return $pdo->lastInsertId();
    } catch (Exception $e) { return null; }
}

function markLogProcessed($logId, $error = null) {
    global $pdo;
    if (!$logId) return;
    try {
        $pdo->prepare("UPDATE wa_webhook_log SET processed=1, error=? WHERE id=?")
            ->execute([$error, $logId]);
    } catch (Exception $e) {}
}

function waTraceStart($logId, $eventType, $rawBody, $ip) {
    global $pdo;
    $requestId = function_exists('random_bytes') ? bin2hex(random_bytes(12)) : uniqid('wa_', true);
    $trace = ['id' => null, 'request_id' => $requestId, 'started_at' => microtime(true)];
    try {
        $stmt = $pdo->prepare("INSERT INTO wa_message_trace
            (request_id, webhook_log_id, event_type, remote_ip, payload_hash)
            VALUES(?,?,?,?,?)");
        $stmt->execute([$requestId, $logId, $eventType, $ip, hash('sha256', (string)$rawBody)]);
        $trace['id'] = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {}
    return $trace;
}

function waTraceUpdate($trace, array $fields) {
    global $pdo;
    if (empty($trace['id']) || !$fields) return;
    $allowed = ['message_id','event_type','device','group_id','group_name','stage','parsed_at','persisted_at','responded_at','http_status','processing_ms','db_insert_ms','error'];
    $set = []; $values = [];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        $set[] = "`$key`=?"; $values[] = $value;
    }
    if (!$set) return;
    try {
        $values[] = (int)$trace['id'];
        $pdo->prepare("UPDATE wa_message_trace SET " . implode(',', $set) . " WHERE id=?")->execute($values);
    } catch (Throwable $e) {}
}

function waTraceFinish($trace, $stage, $httpStatus = 200, $error = null, $dbInsertMs = null) {
    $elapsed = !empty($trace['started_at']) ? (int)round((microtime(true) - $trace['started_at']) * 1000) : null;
    waTraceUpdate($trace, [
        'stage' => $stage,
        'http_status' => $httpStatus,
        'processing_ms' => $elapsed,
        'db_insert_ms' => $dbInsertMs,
        'responded_at' => date('Y-m-d H:i:s.u'),
        'error' => $error ? mb_substr((string)$error, 0, 2000) : null,
    ]);
}

function upsertGroup($groupId, $groupName, $participantsCount = null, $description = null, $owner = null) {
    global $pdo;
    if (!$groupId) return;
    try {
        $pdo->prepare("INSERT INTO whatsapp_groups
            (group_id, group_name, participants_count, description, owner, last_synced)
            VALUES(?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                group_name=VALUES(group_name),
                participants_count=COALESCE(VALUES(participants_count), participants_count),
                description=COALESCE(VALUES(description), description),
                owner=COALESCE(VALUES(owner), owner),
                last_synced=NOW()")
            ->execute([$groupId, $groupName ?: 'مجموعة غير مسماة', $participantsCount, $description, $owner]);
    } catch (Exception $e) {
        error_log('[WA-Webhook] upsertGroup error: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════
//  [FIX] حفظ الوسائط كملفات على السيرفر بدل base64 في القاعدة
// ══════════════════════════════════════════════════════════
const WA_MEDIA_MIME_EXT = [
    'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
    'image/webp' => 'webp', 'image/gif' => 'gif',
    'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/mpeg' => 'mpeg',
    'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/wav' => 'wav',
    'application/pdf' => 'pdf', 'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

function waGuessExt($mime, $fallbackFilename = null) {
    $mime = strtolower(trim(explode(';', $mime ?? '')[0]));
    if (isset(WA_MEDIA_MIME_EXT[$mime])) return WA_MEDIA_MIME_EXT[$mime];
    if ($fallbackFilename && str_contains($fallbackFilename, '.')) {
        return strtolower(pathinfo($fallbackFilename, PATHINFO_EXTENSION));
    }
    if (str_starts_with($mime, 'image/')) return explode('/', $mime)[1];
    return 'bin';
}

/**
 * يحفظ بيانات الوسائط الواردة (stream buffer) كملف حقيقي على القرص
 * بدل تحويلها إلى data:...;base64 وتخزينها في القاعدة.
 * يرجع رابط الملف الجديد، أو null إن لم توجد بيانات قابلة للحفظ.
 */
function waSaveIncomingMedia($media, $messageIdForFilename) {
    if (empty($media)) return [null, null, null];

    $mime          = $media['mimetype']  ?? null;
    $mediaFilename = $media['fileName']  ?? null;

    $bytes = null;
    if (!empty($media['stream']['data'])) {
        $streamData = $media['stream']['data'];
        $bytes = is_array($streamData)
            ? implode('', array_map('chr', $streamData))
            : $streamData;
    }

    if ($bytes === null) {
        // لا يوجد buffer، فقط رابط جاهز (مثلاً من CDN) — لا حاجة لحفظه محلياً
        return [$media['url'] ?? null, $mime, $mediaFilename];
    }

    $ext      = waGuessExt($mime, $mediaFilename);
    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $messageIdForFilename ?: uniqid('wa_'));
    $filename = $safeBase . '.' . $ext;

    $uploadDir = dirname(__DIR__) . '/uploads/wa_media/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (file_put_contents($uploadDir . $filename, $bytes) === false) {
        error_log('[WA-Webhook] فشل حفظ ملف الوسائط: ' . $filename);
        return [null, $mime, $mediaFilename];
    }

    $url = SITE_URL . '/uploads/wa_media/' . $filename;
    return [$url, $mime, $mediaFilename];
}

/**
 * يختصر الـ payload الخام قبل تخزينه في القاعدة: يستبدل مصفوفة بايتات
 * الوسائط الضخمة (media.stream.data) بملاحظة قصيرة، لأن البيانات
 * الفعلية أصبحت محفوظة كملف على القرص عبر waSaveIncomingMedia().
 * هذا يمنع تكرار نفس الميديا 3 مرات (مرة كملف، ومرتين كنص ضخم بالقاعدة).
 */
function waCompactPayloadForStorage($rawBody) {
    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        // ليس JSON صالح — نكتفي بمقطع مختصر لتفادي تضخيم الجدول
        return mb_strlen($rawBody) > 2000
            ? mb_substr($rawBody, 0, 2000) . '...[مقطوع، الطول الأصلي ' . mb_strlen($rawBody) . ' حرف]'
            : $rawBody;
    }

    if (isset($data['media']['stream']['data'])) {
        $n = is_array($data['media']['stream']['data']) ? count($data['media']['stream']['data']) : strlen($data['media']['stream']['data']);
        $data['media']['stream']['data'] = "[تم حفظ $n بايت كملف على القرص - محذوف من هنا لتوفير المساحة]";
    }

    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function detectMsgType($data) {
    if (!empty($data['media'])) {
        $mime = $data['media']['mimetype'] ?? '';
        if (str_starts_with($mime, 'image'))    return 'image';
        if (str_starts_with($mime, 'video'))    return 'video';
        if (str_starts_with($mime, 'audio'))    return 'audio';
        if ($mime === 'application/pdf')         return 'document';
        if (str_contains($mime, 'document'))    return 'document';
        return 'document';
    }
    if (!empty($data['location']))  return 'location';
    if (!empty($data['vcard']))     return 'contact';
    if (isset($data['poll']))       return 'poll';
    return 'text';
}

function sendAutoReply($to, $message) {
    $apiKey = waGetSetting('heta_api_key');
    $sender = waGetSetting('heta_sender');
    if (!$apiKey || !$sender || !$to || !$message) {
        return ['ok'=>false, 'error'=>'missing_send_configuration'];
    }

    // مطابق لمسار waSendMessage في لوحة الإدارة: نفس endpoint والـpayload والمهلة.
    // المهلة الأطول مهمة لأن رسالة التنشيط قد توقظ جلسة HetaCloud من السبات.
    $endpoint = 'https://sender.hetacloud.top/send-message';
    $payload = [
        'api_key' => $apiKey,
        'sender'  => $sender,
        'number'  => $to,
        'message' => $message,
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result = json_decode((string)$body, true);
    $ok = (!$curlError && is_array($result) && isset($result['status']) && $result['status']);
    $error = $curlError ?: ($result['msg'] ?? $result['message'] ?? $result['error'] ?? $result['reason'] ?? '');
    if (!$error && !$ok && $body) $error = 'فشل الإرسال — ' . substr(strip_tags((string)$body), 0, 120);
    if (!$error && !$ok) $error = 'لا يوجد رد من الخادم';

    return ['ok'=>$ok, 'error'=>$error, 'raw'=>$result];
}

/**
 * وضع التنشيط الاحتياطي المؤجل: يحجز مهمة فقط بعد حفظ رسالة واردة.
 * لا يوجد اتصال خارجي هنا؛ عامل Cron في admin/wa_relay_worker.php هو الذي يرسل عند حلول الموعد.
 * السلوك Debounce: عند وصول رسائل جديدة قبل الإرسال، يُعاد الموعد إلى (الآن + التأخير)
 * حتى لا تُرسل عدة إشارات متتابعة لنفس مجموعة التنشيط.
 */
function waMaybeSendActivation($sourceChat, $msgText, $data = [], $sourceMessageId = '') {
    global $pdo;
    if (waGetSetting('activation_enabled') !== '1') return 'disabled';

    $targetGroup = trim((string)waGetSetting('activation_group_id'));
    if ($targetGroup === '' || !str_ends_with($targetGroup, '@g.us')) return 'not_configured';
    if ($sourceChat !== '' && $sourceChat === $targetGroup) return 'target_source_skipped';

    $activationBase = trim((string)waGetSetting('activation_message'));
    if ($activationBase === '') $activationBase = 'تنشيط';

    // لا نرد على إشارة التنشيط نفسها، سواء وصلت بالنص الأساسي أو بالرمز الفريد.
    $incomingText = trim((string)$msgText);
    if ($incomingText === $activationBase || str_starts_with($incomingText, $activationBase . ' ')) {
        return 'activation_ignored';
    }

    // تجاهل أحداث الرسائل الخارجة من الجهاز إن أرسلها المزود إلى نفس الـWebhook.
    $ownFlags = [$data['fromMe'] ?? null, $data['from_me'] ?? null, $data['isFromMe'] ?? null, $data['is_from_me'] ?? null];
    foreach ($ownFlags as $flag) {
        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') return 'own_message_ignored';
    }
    $direction = strtolower(trim((string)($data['direction'] ?? $data['message_direction'] ?? '')));
    if (in_array($direction, ['out','outgoing','sent'], true)) return 'outgoing_ignored';

    // يجب أن يكون لدينا مفتاح حدث ثابت؛ نستعمل رقم الصف المحفوظ بعد نجاح INSERT.
    $sourceMessageId = trim((string)$sourceMessageId);
    if ($sourceMessageId === '') $sourceMessageId = trim((string)($data['message_id'] ?? $data['messageId'] ?? ''));
    if ($sourceMessageId === '') return 'missing_source_message_id';

    $delay = (int)(waGetSetting('activation_delay_seconds') ?: waGetSetting('activation_cooldown') ?: 15);
    $delay = max(1, min(300, $delay));
    $lockName = 'njaz_wa_activation_' . substr(hash('sha256', $targetGroup), 0, 16);
    $lockAcquired = false;
    try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) return 'lock_busy';

        $scheduledAt = date('Y-m-d H:i:s', time() + $delay);
        $existingEvent = $pdo->prepare("SELECT id,status FROM whatsapp_activation_queue
            WHERE source_message_id=? LIMIT 1 FOR UPDATE");
        $existingEvent->execute([$sourceMessageId]);
        $existingRow = $existingEvent->fetch(PDO::FETCH_ASSOC);
        if ($existingRow) {
            return (($existingRow['status'] ?? '') === 'pending') ? 'already_queued' : 'already_processed';
        }

        $pending = $pdo->prepare("SELECT id FROM whatsapp_activation_queue
            WHERE target_group_id=? AND status='pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $pending->execute([$targetGroup]);
        $pendingId = (int)$pending->fetchColumn();

        if ($pendingId > 0) {
            $update = $pdo->prepare("UPDATE whatsapp_activation_queue
                SET scheduled_at=?, message_base=?, updated_at=NOW() WHERE id=? AND status='pending'");
            $update->execute([$scheduledAt, $activationBase, $pendingId]);
            return $update->rowCount() === 1 ? 'rescheduled' : 'queue_busy';
        }

        try {
            $insert = $pdo->prepare("INSERT INTO whatsapp_activation_queue
                (source_message_id,target_group_id,message_base,scheduled_at,status,attempt_count,created_at,updated_at)
                VALUES(?,?,?,?, 'pending',0,NOW(),NOW())");
            $insert->execute([$sourceMessageId, $targetGroup, $activationBase, $scheduledAt]);
            return 'queued';
        } catch (Throwable $e) {
            $errorInfo = $e instanceof PDOException && is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
            if (strpos(strtolower($e->getMessage()), 'duplicate') !== false || (int)($errorInfo[1] ?? 0) === 1062) {
                return 'already_queued';
            }
            error_log('[WA-Activation] queue insert failed: ' . $e->getMessage());
            return 'queue_error';
        }
    } catch (Throwable $e) {
        error_log('[WA-Activation] queue error: ' . $e->getMessage());
        return 'error';
    } finally {
        if ($lockAcquired) {
            try { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); } catch (Throwable $e) {}
        }
    }
}

function waSetActivationSetting($key, $value) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO whatsapp_settings (setting_key,setting_value) VALUES(?,?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
            ->execute([$key, (string)$value]);
    } catch (Throwable $e) {}
}

// ══════════════════════════════════════════════════════════
//  التحقق من الطلب
// ══════════════════════════════════════════════════════════
$clientIp  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// دعم GET للتحقق من صحة الـ endpoint
if ($method === 'GET') {
    $challenge = $_GET['hub_challenge'] ?? $_GET['challenge'] ?? '';
    if ($challenge) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'service' => 'WhatsApp Webhook', 'time' => date('c')]);
    exit;
}

// قراءة الجسم
$rawBody  = file_get_contents('php://input');
$headers  = getallheaders();

// التحقق من Webhook Secret إذا كان مضبوطاً
$secret = waGetSetting('webhook_secret');
if ($secret) {
    $sig = $headers['X-Webhook-Signature'] ?? $headers['X-Hub-Signature'] ?? $headers['X-Signature'] ?? '';
    if ($sig && !hash_equals('sha256=' . hash_hmac('sha256', $rawBody, $secret), $sig)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// ══════════════════════════════════════════════════════════
//  تحليل البيانات
// ══════════════════════════════════════════════════════════
$data = json_decode($rawBody, true);
$jsonError = json_last_error();

// نسجل الطلب حتى لو فشل فك JSON؛ الاستجابة القديمة 200 كانت تخفي
// الحمولة المشوهة وتمنع مزود الخدمة من إعادة المحاولة أو كشف سببها.
if (!$data || !is_array($data) || $jsonError !== JSON_ERROR_NONE) {
    $invalidEvent = 'invalid_json';
    $invalidPayload = mb_strlen($rawBody) > 2000
        ? mb_substr($rawBody, 0, 2000) . '...[truncated]'
        : $rawBody;
    $invalidLogId = logRaw($invalidEvent, $invalidPayload, $clientIp);
    $invalidTrace = waTraceStart($invalidLogId, $invalidEvent, $rawBody, $clientIp);
    $invalidError = 'invalid_json: ' . json_last_error_msg();
    markLogProcessed($invalidLogId, $invalidError);
    waTraceFinish($invalidTrace, 'failed', 503, $invalidError);
    http_response_code(503);
    header('Retry-After: 5');
    echo json_encode(['status' => 'error', 'reason' => 'invalid_json', 'retry' => true]);
    exit;
}

// تسجيل الطلب الخام قبل أي استخراج للرسائل. هذا يجعل سجل Webhook
// مرجعاً مستقلاً لمعرفة هل وصل الطلب إلى الاستضافة أصلاً.
// [FIX] نسجّل نسخة مختصرة (بدون بايتات الوسائط الضخمة) لتفادي تضخم قاعدة البيانات
$eventType      = $data['event'] ?? $data['type'] ?? $data['action'] ?? 'message';
$compactPayload = waCompactPayloadForStorage($rawBody);
$logId          = logRaw($eventType, $compactPayload, $clientIp);
$trace          = waTraceStart($logId, $eventType, $rawBody, $clientIp);
waTraceUpdate($trace, ['stage' => 'received']);

// ══════════════════════════════════════════════════════════
//  معالجة الأحداث
// ══════════════════════════════════════════════════════════
// استخراج قيمة نصية من صيغ HetaCloud المتداخلة دون تسجيل الحمولة أو عرضها.
function waFindNestedScalarByKeys(array $root, array $wantedKeys, int $maxNodes = 1200): string {
    $wanted = array_fill_keys($wantedKeys, true);
    $stack = [$root];
    $seen = 0;
    while ($stack && $seen < $maxNodes) {
        $node = array_pop($stack);
        if (!is_array($node)) continue;
        $seen++;
        foreach ($node as $key => $value) {
            if (isset($wanted[(string)$key]) && is_scalar($value)) {
                $candidate = trim((string)$value);
                if ($candidate !== '') return $candidate;
            }
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

// يبحث عن معرف مجموعة فعلياً، ولا يتوقف عند قيمة أولى قد تكون معرفاً خاصاً.
function waFindNestedGroupId(array $root, int $maxNodes = 1200): string {
    $wanted = array_fill_keys([
        'group_id', 'groupId', 'chat_id', 'chatId', 'remoteJid', 'remote_jid',
        'chatJid', 'chat_jid', 'conversationId', 'conversation_id', 'jid'
    ], true);
    $stack = [$root];
    $seen = 0;
    while ($stack && $seen < $maxNodes) {
        $node = array_pop($stack);
        if (!is_array($node)) continue;
        $seen++;
        foreach ($node as $key => $value) {
            if (isset($wanted[(string)$key]) && is_scalar($value)) {
                $candidate = trim((string)$value);
                if ($candidate !== '' && str_ends_with($candidate, '@g.us')) return $candidate;
            }
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

try {

    // ── استخراج الحقول المشتركة ─────────────────────────
    $device      = $data['device']      ?? $data['sender']   ?? waGetSetting('heta_sender');
    $fromNumber  = (string)($data['from'] ?? $data['number'] ?? $data['phone'] ?? '');
    // نحتفظ بقيمة المصدر الأصلية حتى يبقى التنشيط الاحتياطي بنفس السلوك السابق.
    $activationSourceChat = $fromNumber;
    $fromName    = $data['name']        ?? $data['pushName'] ?? $data['contact_name'] ?? '';
    $participant = (string)($data['participant'] ?? ''); // في المجموعات
    $msgText     = $data['message']     ?? $data['text']     ?? $data['body']        ?? '';
    $ppUrl       = $data['ppUrl']       ?? $data['profile_picture'] ?? '';
    $groupId     = '';
    $groupName   = '';
    $isGroup     = false;

    // تحديد المجموعة: قد يضع HetaCloud معرف المحادثة داخل chatId/remoteJid/jid.
    // نستخدم قيمة تنتهي بـ @g.us حتى لا نخلط رقم المشارك بالمجموعة.
    $nestedChatId = waFindNestedGroupId($data);
    foreach ([$fromNumber, $nestedChatId] as $candidateGroupId) {
        $candidateGroupId = trim((string)$candidateGroupId);
        if ($candidateGroupId !== '' && str_ends_with($candidateGroupId, '@g.us')) {
            $isGroup = true;
            $groupId = $candidateGroupId;
            break;
        }
    }
    // توافق مع صيغة HetaCloud القديمة التي ترسل participant مع from المجموعة.
    if (!$isGroup && $participant !== '') {
        $isGroup = true;
        $groupId = $fromNumber;
    }
    if ($isGroup) {
        $groupName = $data['group_name'] ?? $data['subject'] ?? $data['groupName'] ?? '';
        if ($groupName === '') {
            $groupName = waFindNestedScalarByKeys($data, ['group_name', 'groupName', 'subject', 'subjectName']);
        }
        if ($participant !== '') $fromNumber = $participant;
    }

    waTraceUpdate($trace, [
        'stage' => 'parsed',
        'parsed_at' => date('Y-m-d H:i:s.u'),
        'device' => $device,
        'group_id' => $isGroup ? $groupId : null,
        'group_name' => $isGroup ? $groupName : null,
    ]);

    // ─────────────────────────────────────────────────────
    //  CASE 1: رسالة واردة (النمط الرئيسي لـ HetaCloud)
    // ─────────────────────────────────────────────────────
    if (!empty($data['message']) || !empty($data['media']) || isset($data['from'])) {

        $msgType   = detectMsgType($data);
        $messageId = $data['id'] ?? $data['messageId'] ?? uniqid('wa_');
        waTraceUpdate($trace, ['stage' => 'message_detected', 'message_id' => $messageId]);

        // عند إعادة إرسال الحدث من المزود بعد خطأ مؤقت لا نكرر الرسالة
        // ولا نحفظ الوسائط مرة أخرى.
        $duplicateStmt = $pdo->prepare("SELECT id FROM wa_incoming_messages WHERE message_id=? LIMIT 1");
        $duplicateStmt->execute([$messageId]);
        if ($duplicateStmt->fetchColumn()) {
            markLogProcessed($logId);
            waTraceFinish($trace, 'duplicate_ignored', 200);
            http_response_code(200);
            echo json_encode(['status' => 'ok', 'processed' => 'duplicate_ignored']);
            exit;
        }

        $mediaUrl = null; $mediaMime = null; $mediaFilename = null; $caption = null;

        // معالجة الوسائط
        // [FIX] تُحفظ الوسائط كملف حقيقي في uploads/wa_media/ بدل base64 في القاعدة
        if (!empty($data['media'])) {
            $media       = $data['media'];
            $caption     = $media['caption'] ?? null;
            [$mediaUrl, $mediaMime, $mediaFilename] = waSaveIncomingMedia($media, $messageId);
        }

        // إدراج الرسالة
        // [FIX] raw_payload مختصر (بدون بايتات الوسائط) بدل النسخة الكاملة
        $dbInsertStarted = microtime(true);
        $pdo->prepare("INSERT INTO wa_incoming_messages
            (message_id, device, from_number, from_name, group_id, group_name,
             participant, message_type, message, media_url, media_mime,
             media_filename, caption, is_group, raw_payload)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $messageId,
                $device,
                $fromNumber,
                $fromName,
                $isGroup ? $groupId   : null,
                $isGroup ? $groupName : null,
                $isGroup ? $participant : null,
                $msgType,
                $msgText,
                $mediaUrl,
                $mediaMime,
                $mediaFilename,
                $caption,
                $isGroup ? 1 : 0,
                $compactPayload,
            ]);
        $incomingRowId = (int)$pdo->lastInsertId();
        waTraceUpdate($trace, [
            'stage' => 'persisted',
            'persisted_at' => date('Y-m-d H:i:s.u'),
            'db_insert_ms' => (int)round((microtime(true) - $dbInsertStarted) * 1000),
        ]);

        // تحديث / إنشاء المجموعة تلقائياً إذا كانت رسالة مجموعة
        if ($isGroup && $groupId) {
            upsertGroup($groupId, $groupName);
        }

        // ── وضع التنشيط الاحتياطي (اختياري من لوحة الإدارة)
        // يُستدعى بعد نجاح الإدراج فقط، ويحجز مهمة مؤجلة ولا يرسل داخل Webhook.
        $activationEventId = trim((string)$messageId);
        if ($activationEventId === '') $activationEventId = 'incoming:' . $incomingRowId;
        waMaybeSendActivation($isGroup ? $activationSourceChat : $fromNumber, $msgText, $data, $activationEventId);

        // ── منطق الرد التلقائي ──────────────────────────
        // يمكن تخصيصه — الآن يرد على كلمات محددة
        if (!$isGroup && $msgText) {
            $lower = mb_strtolower(trim($msgText));

            // ترحيب
            if (in_array($lower, ['hi','hello','مرحبا','مرحبً','هاي','السلام عليكم','اهلا','أهلا','هلا'])) {
                $welcomeMsg = waGetSetting('wa_auto_reply_welcome');
                if ($welcomeMsg) {
                    sendAutoReply($fromNumber, str_replace('{name}', $fromName ?: 'عزيزي', $welcomeMsg));
                }
            }
        }

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200, null, (int)round((microtime(true) - $dbInsertStarted) * 1000));
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'message', 'type' => $msgType]);
        exit;
    }

    // ─────────────────────────────────────────────────────
    //  CASE 2: أحداث المجموعات
    // ─────────────────────────────────────────────────────
    if ($eventType === 'group.create' || $eventType === 'group_create' ||
        ($data['type'] ?? '') === 'group_create') {

        $gId   = $data['group_id'] ?? $data['id'] ?? $data['jid'] ?? '';
        $gName = $data['name'] ?? $data['subject'] ?? $data['group_name'] ?? '';
        $owner = $data['owner'] ?? $data['admin'] ?? '';
        $parts = $data['participants'] ?? $data['members'] ?? [];
        $partCount = is_array($parts) ? count($parts) : (int)$parts;

        upsertGroup($gId, $gName, $partCount, null, $owner);

        $pdo->prepare("INSERT INTO wa_group_events (event_type,group_id,group_name,actor,description,raw_payload)
            VALUES('create',?,?,?,?,?)")
            ->execute([$gId, $gName, $owner, "تم إنشاء المجموعة بـ $partCount عضو", $rawBody]);

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'group_create']);
        exit;
    }

    // مغادرة / إضافة أعضاء
    if (in_array($eventType, ['group.join','group.leave','group_join','group_leave',
                               'group.participant.add','group.participant.remove'])) {

        $gId     = $data['group_id'] ?? $data['id'] ?? $data['jid'] ?? '';
        $gName   = $data['group_name'] ?? $data['name'] ?? '';
        $actor   = $data['actor'] ?? $data['admin'] ?? '';
        $targets = $data['participants'] ?? $data['members'] ?? [$data['participant'] ?? ''];
        if (!is_array($targets)) $targets = [$targets];

        $action = str_contains($eventType, 'join') || str_contains($eventType, 'add') ? 'join' : 'leave';

        // تحديث عدد المشتركين
        if ($gId) {
            $delta = $action === 'join' ? count($targets) : -count($targets);
            $pdo->prepare("UPDATE whatsapp_groups
                SET participants_count = GREATEST(0, participants_count + ?), last_synced=NOW()
                WHERE group_id=?")->execute([$delta, $gId]);
            upsertGroup($gId, $gName);
        }

        foreach ($targets as $target) {
            $pdo->prepare("INSERT INTO wa_group_events (event_type,group_id,group_name,actor,target,description,raw_payload)
                VALUES(?,?,?,?,?,?,?)")
                ->execute([$action, $gId, $gName, $actor, $target,
                    ($action==='join'?"انضم $target للمجموعة":"غادر $target المجموعة"), $rawBody]);
        }

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => "group_$action"]);
        exit;
    }

    // تحديث معلومات المجموعة
    if (in_array($eventType, ['group.update','group_update','group.subject','group.description'])) {
        $gId   = $data['group_id'] ?? $data['id'] ?? $data['jid'] ?? '';
        $gName = $data['subject']  ?? $data['name'] ?? $data['group_name'] ?? '';
        $desc  = $data['description'] ?? $data['desc'] ?? null;

        if ($gId) {
            $pdo->prepare("UPDATE whatsapp_groups
                SET group_name=COALESCE(NULLIF(?,'')),
                    description=COALESCE(?,description),
                    last_synced=NOW()
                WHERE group_id=?")->execute([$gName, $desc, $gId]);

            $pdo->prepare("INSERT INTO wa_group_events (event_type,group_id,group_name,description,raw_payload)
                VALUES('update',?,?,?,?)")->execute([$gId,$gName,"تحديث معلومات المجموعة",$rawBody]);
        }

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'group_update']);
        exit;
    }

    // قائمة المجموعات (بعض الـ APIs ترسلها)
    if (!empty($data['groups']) && is_array($data['groups'])) {
        $saved = 0;
        foreach ($data['groups'] as $g) {
            $gid   = $g['id'] ?? $g['group_id'] ?? $g['jid'] ?? '';
            $gname = $g['name'] ?? $g['subject'] ?? $g['group_name'] ?? '';
            $parts = $g['participants'] ?? $g['size'] ?? 0;
            if (is_array($parts)) $parts = count($parts);
            $desc  = $g['desc'] ?? $g['description'] ?? '';
            $owner = $g['owner'] ?? '';
            if ($gid) { upsertGroup($gid, $gname, (int)$parts, $desc, $owner); $saved++; }
        }
        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'groups_list', 'saved' => $saved]);
        exit;
    }

    // ─────────────────────────────────────────────────────
    //  CASE 3: حالة الجهاز
    // ─────────────────────────────────────────────────────
    if (in_array($eventType, ['device.connected','device.disconnected','connected','disconnected',
                               'qr','qr_ready','device_status'])) {

        $dev    = $data['device'] ?? $data['sender'] ?? $device;
        $status = str_contains($eventType,'connect') ? 'connected'
                : (str_contains($eventType,'disconnect') ? 'disconnected'
                : (str_contains($eventType,'qr') ? 'qr_ready' : 'connected'));

        $pdo->prepare("INSERT INTO wa_device_status (device,status,detail) VALUES(?,?,?)")
            ->execute([$dev, $status, json_encode($data)]);

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'device_status', 'state' => $status]);
        exit;
    }

    // ─────────────────────────────────────────────────────
    //  CASE 4: إيصالات القراءة / التسليم
    // ─────────────────────────────────────────────────────
    if (in_array($eventType, ['message.read','message.delivered','message.sent',
                               'read','delivered','receipt'])) {

        $msgId  = $data['message_id'] ?? $data['id'] ?? null;
        $phone  = $data['from'] ?? $data['to'] ?? $data['number'] ?? null;
        $status = str_contains($eventType,'read') ? 'read'
                : (str_contains($eventType,'deliver') ? 'delivered' : 'sent');

        $pdo->prepare("INSERT INTO wa_receipts (message_id,phone,status) VALUES(?,?,?)")
            ->execute([$msgId, $phone, $status]);

        // تحديث حالة القراءة في الرسائل الواردة
        if ($msgId) {
            $pdo->prepare("UPDATE wa_incoming_messages SET is_read=1 WHERE message_id=?")
                ->execute([$msgId]);
        }

        markLogProcessed($logId);
        waTraceFinish($trace, 'completed', 200);
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'processed' => 'receipt']);
        exit;
    }

    // ─────────────────────────────────────────────────────
    //  CASE 5: حدث غير معروف — نسجله فقط
    // ─────────────────────────────────────────────────────
    $unhandledError = 'unhandled_event: ' . $eventType;
    markLogProcessed($logId, $unhandledError);
    waTraceFinish($trace, 'logged_only', 200, $unhandledError);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'processed' => 'logged_only', 'event' => $eventType]);

} catch (Throwable $e) {
    error_log('[WA-Webhook] Exception: ' . $e->getMessage() . ' | Line: ' . $e->getLine());
    markLogProcessed($logId, $e->getMessage());
    waTraceFinish($trace ?? [], 'failed', 503, $e->getMessage());

    // لا نؤكد استلام الحدث عند فشل قاعدة البيانات أو المعالجة.
    // استجابة 5xx تمنح مزود Webhook فرصة إعادة الإرسال بدلاً من فقد الرسالة.
    http_response_code(503);
    header('Retry-After: 5');
    echo json_encode(['status' => 'error', 'message' => 'temporary_processing_error']);
}
