<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'إدارة واتساب — ' . SITE_NAME;

// ══════════════════════════════════════════════════════════════
//  إنشاء جداول قاعدة البيانات
// ══════════════════════════════════════════════════════════════
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) UNIQUE NOT NULL,
        `setting_value` TEXT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_campaigns` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NOT NULL,
        `recipients_type` ENUM('manual','customers','excel','groups') DEFAULT 'manual',
        `recipients_count` INT DEFAULT 0,
        `sent_count` INT DEFAULT 0,
        `failed_count` INT DEFAULT 0,
        `status` ENUM('draft','sending','done','failed') DEFAULT 'draft',
        `media_url` TEXT DEFAULT NULL,
        `media_type` VARCHAR(20) DEFAULT NULL,
        `group_ids` TEXT DEFAULT NULL,
        `created_by` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `finished_at` TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `campaign_id` INT DEFAULT NULL,
        `phone` VARCHAR(100) NOT NULL,
        `message` TEXT,
        `status` ENUM('sent','failed') DEFAULT 'sent',
        `error_msg` VARCHAR(500) DEFAULT NULL,
        `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // جدول المجموعات المحفوظة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_groups` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `group_id` VARCHAR(100) UNIQUE NOT NULL,
        `group_name` VARCHAR(255) NOT NULL,
        `participants_count` INT DEFAULT 0,
        `description` TEXT DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `last_synced` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // قواعد الربط العامة — تُستخدم أيضاً من العامل الخلفي لطلبات سوا
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_relay_rules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `source_group_id` VARCHAR(120) NOT NULL,
        `target_group_id` VARCHAR(120) NOT NULL,
        `trigger_prefix` VARCHAR(50) NOT NULL DEFAULT 'سوا',
        `digit_lengths` VARCHAR(100) NOT NULL DEFAULT '14',
        `digit_length` INT NOT NULL DEFAULT 14,
        `reply_keyword` VARCHAR(50) NOT NULL DEFAULT 'تم',
        `reply_min_amount` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `reply_max_amount` DECIMAL(18,4) DEFAULT 1000,
        `reply_timeout_min` INT NOT NULL DEFAULT 30,
        `reminder_enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `reminder_interval_min` SMALLINT NOT NULL DEFAULT 10,
        `reminder_template` TEXT NULL,
        `request_template` TEXT NOT NULL,
        `result_template` TEXT NOT NULL,
        `reply_instruction_template` TEXT NULL,
        `source_format_template` TEXT NULL,
        `source_short_template` TEXT NULL,
        `source_pending_template` TEXT NULL,
        `source_duplicate_template` TEXT NULL,
        `source_forward_template` TEXT NULL,
        `reject_result_template` TEXT NULL,
        `target_accept_confirmation_template` TEXT NULL,
        `target_reject_confirmation_template` TEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 0,
        `last_processed_id` BIGINT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_relay_active` (`is_active`),
        KEY `idx_relay_source` (`source_group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // طابور التنشيط المؤجل — يملؤه Webhook ويعالجه عامل Cron
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

    // سجل الطلبات والردود — القيد الفريد يمنع إنشاء طلبين للرسالة نفسها
    $pdo->exec("CREATE TABLE IF NOT EXISTS `whatsapp_relay_logs` (
        `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
        `rule_id` INT NOT NULL,
        `source_msg_id` BIGINT NOT NULL,
        `source_message_id` VARCHAR(255) DEFAULT NULL,
        `forwarded_message_id` VARCHAR(255) DEFAULT NULL,
        `reply_message_id` VARCHAR(255) DEFAULT NULL,
        `result_message_id` VARCHAR(255) DEFAULT NULL,
        `extracted_code` VARCHAR(64) NOT NULL,
        `source_text` TEXT,
        `reply_text` TEXT,
        `reply_amount` DECIMAL(18,4) DEFAULT NULL,
        `executor_name` VARCHAR(255) DEFAULT NULL,
        `executor_number` VARCHAR(120) DEFAULT NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'dispatching',
        `error_message` TEXT,
        `forwarded_at` DATETIME DEFAULT NULL,
        `reply_received_at` DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `last_reminder_at` DATETIME DEFAULT NULL,
        `reminder_count` INT NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_relay_rule_source` (`rule_id`,`source_msg_id`),
        KEY `idx_relay_status` (`rule_id`,`status`),
        KEY `idx_relay_forwarded` (`forwarded_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (Exception $e) {}

// قوالب رسائل سوا الإضافية: ترحيل آمن للجداول القديمة دون إسقاط أي بيانات.
try {
    $sawaMessageColumns = [
        'reply_instruction_template' => 'TEXT NULL',
        'source_format_template' => 'TEXT NULL',
        'source_short_template' => 'TEXT NULL',
        'source_pending_template' => 'TEXT NULL',
        'source_duplicate_template' => 'TEXT NULL',
        'source_forward_template' => 'TEXT NULL',
        'reject_result_template' => 'TEXT NULL',
        'target_accept_confirmation_template' => 'TEXT NULL',
        'target_reject_confirmation_template' => 'TEXT NULL',
        'reminder_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'reminder_interval_min' => 'SMALLINT NOT NULL DEFAULT 10',
        'reminder_template' => 'TEXT NULL',
    ];
    foreach ($sawaMessageColumns as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE whatsapp_relay_rules ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) { /* العمود موجود مسبقاً */ }
    }
    foreach ([
        'last_reminder_at' => 'DATETIME NULL',
        'reminder_count' => 'INT NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        try {
            $pdo->exec("ALTER TABLE whatsapp_relay_logs ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) { /* العمود موجود مسبقاً */ }
    }
} catch (Throwable $e) {}

// ── إصلاح: إزالة أي صفوف مكررة لنفس المفتاح (بقايا من نسخة سابقة بلا Unique Index) ──
// وضمان وجود Unique Index فعلي حتى يعمل ON DUPLICATE KEY UPDATE بشكل صحيح مستقبلاً.
try {
    $pdo->exec("DELETE t1 FROM whatsapp_settings t1
                INNER JOIN whatsapp_settings t2
                ON t1.setting_key = t2.setting_key AND t1.id < t2.id");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE whatsapp_settings ADD UNIQUE INDEX idx_setting_key (setting_key)");
} catch (Exception $e) { /* الفهرس موجود مسبقاً على الأغلب — تجاهل */ }

// ══════════════════════════════════════════════════════════════
//  دوال مساعدة
// ══════════════════════════════════════════════════════════════
function waGet($key) {
    global $pdo;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM whatsapp_settings WHERE setting_key=? ORDER BY id DESC LIMIT 1");
        $s->execute([$key]);
        $r = $s->fetch();
        return $r ? $r['setting_value'] : '';
    } catch (Exception $e) { return ''; }
}
function waSet($key, $val) {
    global $pdo;
    // لا نعتمد فقط على ON DUPLICATE KEY UPDATE (قد لا يوجد Unique Index فعلي على الجدول القديم)
    // بل نتحقق يدوياً: تحديث إن وُجد المفتاح، أو إدراج جديد إن لم يوجد.
    $check = $pdo->prepare("SELECT id FROM whatsapp_settings WHERE setting_key=? ORDER BY id DESC LIMIT 1");
    $check->execute([$key]);
    $existing = $check->fetch();
    if ($existing) {
        $pdo->prepare("UPDATE whatsapp_settings SET setting_value=? WHERE id=?")->execute([$val, $existing['id']]);
        // احتياطاً: احذف أي صفوف قديمة أخرى بنفس المفتاح إن وُجدت
        $pdo->prepare("DELETE FROM whatsapp_settings WHERE setting_key=? AND id<>?")->execute([$key, $existing['id']]);
    } else {
        $pdo->prepare("INSERT INTO whatsapp_settings (setting_key,setting_value) VALUES(?,?)")->execute([$key, $val]);
    }
}

// ── تأخير ذكي عشوائي بين الرسائل ───────────────────────
function waSmartDelay($sentCount = 0) {
    $delayMin      = (int)(waGet('delay_min')       ?: 3);
    $delayMax      = (int)(waGet('delay_max')       ?: 7);
    $batchSize     = (int)(waGet('batch_size')      ?: 10);
    $batchPauseMin = (int)(waGet('batch_pause_min') ?: 15);
    $batchPauseMax = (int)(waGet('batch_pause_max') ?: 45);

    // تأكد أن min <= max
    if ($delayMin > $delayMax) $delayMax = $delayMin;
    if ($batchPauseMin > $batchPauseMax) $batchPauseMax = $batchPauseMin;

    // توقف طويل بعد كل batch
    if ($batchSize > 0 && $sentCount > 0 && ($sentCount % $batchSize === 0)) {
        $pause = rand($batchPauseMin, $batchPauseMax);
        sleep($pause);
        return ['type' => 'batch', 'seconds' => $pause];
    }

    // تأخير عشوائي عادي بين الرسائل
    $delay = rand($delayMin * 10, $delayMax * 10) / 10; // دقة 0.1 ثانية
    usleep((int)($delay * 1000000));
    return ['type' => 'normal', 'seconds' => $delay];
}

// دالة الإرسال عبر HetaCloud
function waSendMessage($apiKey, $sender, $number, $message, $mediaUrl='', $mediaType='') {
    // المجموعات: الرقم ينتهي بـ @g.us
    $isGroup = str_ends_with(trim($number), '@g.us');

    if ($mediaUrl) {
        $endpoint = "https://sender.hetacloud.top/send-media";
        $payload  = ['api_key'=>$apiKey,'sender'=>$sender,'number'=>$number,
                     'media_type'=>$mediaType?:'image','url'=>$mediaUrl,'caption'=>$message];
    } elseif ($isGroup) {
        // endpoint مخصص للمجموعات — إذا فشل نرجع لـ send-message
        $endpoint = "https://sender.hetacloud.top/send-message";
        $payload  = ['api_key'=>$apiKey,'sender'=>$sender,'number'=>$number,'message'=>$message];
    } else {
        $endpoint = "https://sender.hetacloud.top/send-message";
        $payload  = ['api_key'=>$apiKey,'sender'=>$sender,'number'=>$number,'message'=>$message];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>false,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $result = json_decode($resp, true);
    $ok = (!$err && isset($result['status']) && $result['status']);
    $errMsg = $err ?: ($result['msg'] ?? $result['message'] ?? $result['error'] ?? $result['reason'] ?? '');
    if (!$errMsg && !$ok && $resp) $errMsg = 'فشل الإرسال — ' . substr(strip_tags($resp), 0, 120);
    if (!$errMsg && !$ok) $errMsg = 'لا يوجد رد من الخادم';
    return ['ok'=>$ok,'error'=>$errMsg,'raw'=>$result];
}

$tab          = $_GET['tab'] ?? 'dashboard';
$apiKey       = waGet('heta_api_key');
$senderNumber = waGet('heta_sender');
$isConnected  = !empty($apiKey) && !empty($senderNumber);

// ══════════════════════════════════════════════════════════════
//  معالجة AJAX — جلب المجموعات من API
// ══════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    // نتائج الرسائل الواردة ديناميكية: امنع كاش المتصفح أو البروكسي حتى لا تظهر الرسائل دفعة واحدة بعد مدة.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    // جلب المجموعات من HetaCloud
    if ($_GET['action'] === 'fetch_groups') {
        if (!$isConnected) { echo json_encode(['ok'=>false,'msg'=>'غير متصل']); exit; }

        // محاولة endpoint جلب المجموعات — HetaCloud يستخدم نفس نمط Baileys/WA-Web
        $endpoints_to_try = [
            "https://sender.hetacloud.top/get-groups",
            "https://sender.hetacloud.top/groups",
            "https://sender.hetacloud.top/list-groups",
        ];

        $found = false;
        $groups = [];
        foreach ($endpoints_to_try as $ep) {
            $ch = curl_init($ep . '?api_key=' . urlencode($apiKey) . '&sender=' . urlencode($senderNumber));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200 && $resp) {
                $data = json_decode($resp, true);
                if (isset($data['groups']) && is_array($data['groups'])) {
                    $groups = $data['groups'];
                    $found = true;
                    break;
                } elseif (isset($data['data']) && is_array($data['data'])) {
                    $groups = $data['data'];
                    $found = true;
                    break;
                } elseif (is_array($data) && count($data) && isset($data[0]['id'])) {
                    $groups = $data;
                    $found = true;
                    break;
                }
            }
        }

        if ($found && !empty($groups)) {
            // حفظ في قاعدة البيانات
            $saved = 0;
            foreach ($groups as $g) {
                $gid   = $g['id'] ?? $g['group_id'] ?? '';
                $gname = $g['name'] ?? $g['subject'] ?? $g['group_name'] ?? 'مجموعة بدون اسم';
                $gpart = $g['participants'] ?? $g['size'] ?? $g['members_count'] ?? 0;
                if (is_array($gpart)) $gpart = count($gpart);
                $gdesc = $g['desc'] ?? $g['description'] ?? '';
                if ($gid) {
                    $pdo->prepare("INSERT INTO whatsapp_groups (group_id,group_name,participants_count,description,last_synced)
                        VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE group_name=?,participants_count=?,description=?,last_synced=NOW()")
                        ->execute([$gid,$gname,(int)$gpart,$gdesc,$gname,(int)$gpart,$gdesc]);
                    $saved++;
                }
            }
            echo json_encode(['ok'=>true,'count'=>$saved,'msg'=>"تم جلب $saved مجموعة"]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'endpoint المجموعات غير متاح من HetaCloud. يمكنك إضافة المجموعات يدوياً.','manual'=>true]);
        }
        exit;
    }

    // رفع ملف وسائط وإرجاع رابطه
    if ($_GET['action'] === 'upload_media') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'msg'=>'خطأ في الرفع: كود '.(($_FILES['file']['error'])??'غير معروف')]);
            exit;
        }
        $file     = $_FILES['file'];
        $maxSize  = 20 * 1024 * 1024; // 20MB
        if ($file['size'] > $maxSize) {
            echo json_encode(['ok'=>false,'msg'=>'حجم الملف أكبر من 20MB']);
            exit;
        }
        $allowed = ['image/jpeg','image/png','image/gif','image/webp',
                    'video/mp4','video/3gpp','video/mpeg',
                    'audio/mpeg','audio/ogg','audio/wav','audio/mp4',
                    'application/pdf','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            echo json_encode(['ok'=>false,'msg'=>'نوع الملف غير مسموح: '.$mime]);
            exit;
        }
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = uniqid('wa_') . '.' . strtolower($ext);
        $uploadDir = dirname(__DIR__) . '/uploads/wa_media/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
            echo json_encode(['ok'=>false,'msg'=>'فشل حفظ الملف']);
            exit;
        }
        $url = SITE_URL . '/uploads/wa_media/' . $safeName;
        echo json_encode(['ok'=>true,'url'=>$url,'mime'=>$mime,'name'=>$file['name']]);
        exit;
    }

    // اكتشاف المجموعات من الرسائل الواردة
    if ($_GET['action'] === 'discover_groups') {
        try {
            $rows = $pdo->query("
                SELECT
                    w.group_id,
                    COALESCE(
                        NULLIF((SELECT TRIM(m.group_name)
                                FROM wa_incoming_messages m
                                WHERE m.group_id = w.group_id
                                  AND m.is_group = 1
                                  AND TRIM(COALESCE(m.group_name, '')) <> ''
                                ORDER BY m.received_at DESC, m.id DESC
                                LIMIT 1), ''),
                        NULLIF((SELECT TRIM(g.group_name)
                                FROM whatsapp_groups g
                                WHERE g.group_id = w.group_id
                                LIMIT 1), ''),
                        CONCAT('مجموعة ', SUBSTRING(w.group_id, 1, 15))
                    ) AS group_name,
                    MAX(w.received_at) AS last_seen,
                    COUNT(*) AS msg_count,
                    (SELECT m.message
                     FROM wa_incoming_messages m
                     WHERE m.group_id = w.group_id AND m.is_group = 1
                     ORDER BY m.received_at DESC, m.id DESC
                     LIMIT 1) AS last_message,
                    (SELECT m.received_at
                     FROM wa_incoming_messages m
                     WHERE m.group_id = w.group_id AND m.is_group = 1
                     ORDER BY m.received_at DESC, m.id DESC
                     LIMIT 1) AS last_message_at
                FROM wa_incoming_messages w
                WHERE w.is_group = 1
                  AND w.group_id IS NOT NULL
                  AND w.group_id != ''
                GROUP BY w.group_id
                ORDER BY last_seen DESC
            ")->fetchAll();

            $existing = $pdo->query("SELECT group_id FROM whatsapp_groups")->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_flip($existing);

            $discovered = [];
            foreach ($rows as $r) {
                $gid  = $r['group_id'] ?? '';
                if (!$gid) continue;
                $discovered[] = [
                    'group_id'      => $gid,
                    'group_name'    => $r['group_name'] ?: 'مجموعة ' . substr($gid, 0, 15),
                    'last_seen'        => $r['last_seen'] ?? '',
                    'last_message'     => $r['last_message'] ?? '',
                    'last_message_at'  => $r['last_message_at'] ?? ($r['last_seen'] ?? ''),
                    'msg_count'        => (int)($r['msg_count'] ?? 0),
                    'already_added' => isset($existing[$gid]),
                ];
            }
            echo json_encode(['ok' => true, 'groups' => $discovered]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // حفظ مجموعات مستوردة (batch)
    if ($_GET['action'] === 'import_discovered') {
        $groups = json_decode(file_get_contents('php://input'), true) ?? [];
        $saved = 0;
        foreach ($groups as $g) {
            $gid  = trim($g['group_id'] ?? '');
            $name = trim($g['group_name'] ?? '');
            if (!$gid || !$name) continue;
            try {
                $pdo->prepare("INSERT INTO whatsapp_groups (group_id, group_name, is_active)
                    VALUES(?, ?, 1)
                    ON DUPLICATE KEY UPDATE group_name = VALUES(group_name), is_active = 1")
                    ->execute([$gid, $name]);
                $saved++;
            } catch (Exception $e) {}
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);
        exit;
    }

    // تعديل اسم مجموعة
    if ($_GET['action'] === 'rename_group') {
        $gid  = (int)($_GET['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($gid && $name) {
            $pdo->prepare("UPDATE whatsapp_groups SET group_name=? WHERE id=?")->execute([$name, $gid]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false]);
        }
        exit;
    }

    // حذف مجموعة
    if ($_GET['action'] === 'delete_group') {
        $gid = (int)($_GET['id'] ?? 0);
        if ($gid) $pdo->prepare("DELETE FROM whatsapp_groups WHERE id=?")->execute([$gid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // تبديل حالة مجموعة
    if ($_GET['action'] === 'toggle_group') {
        $gid = (int)($_GET['id'] ?? 0);
        if ($gid) $pdo->prepare("UPDATE whatsapp_groups SET is_active=IF(is_active=1,0,1) WHERE id=?")->execute([$gid]);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  معالجة POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // حفظ الإعدادات — كل حقل يُحفظ فقط إن كان موجوداً فعلياً في الفورم المُرسَل
    // (حتى لا يمسح فورم "التأخير" مثلاً قيمة API Key الخاصة بفورم آخر)
    if (isset($_POST['save_settings'])) {
        if (isset($_POST['api_key']))         waSet('heta_api_key',   trim($_POST['api_key']));
        if (isset($_POST['sender_number']))   waSet('heta_sender',    trim($_POST['sender_number']));
        if (isset($_POST['webhook_secret']))  waSet('webhook_secret', trim($_POST['webhook_secret']));
        if (isset($_POST['activation_enabled'])) {
            waSet('activation_enabled', $_POST['activation_enabled'] === '1' ? '1' : '0');
        }
        if (array_key_exists('activation_group_id', $_POST)) {
            $activationGroupId = trim((string)$_POST['activation_group_id']);
            if ($activationGroupId !== '' && !str_ends_with($activationGroupId, '@g.us')) {
                flashMessage('warning', 'لم يُحفظ معرّف مجموعة التنشيط: يجب أن ينتهي بـ @g.us أو يُترك فارغاً.');
                $activationGroupId = '';
            }
            waSet('activation_group_id', $activationGroupId);
        }
        if (isset($_POST['activation_message'])) {
            $activationMessage = trim((string)$_POST['activation_message']);
            waSet('activation_message', mb_substr($activationMessage !== '' ? $activationMessage : 'تنشيط', 0, 80));
        }
        // الاسم الجديد يمثل التأخير الحقيقي قبل الإرسال بواسطة Cron.
        // نُبقي activation_cooldown متوافقاً مع النسخ القديمة، لكنه لم يعد يمنع الإرسال داخل Webhook.
        if (array_key_exists('activation_delay_seconds', $_POST)) {
            $activationDelay = max(1, min(300, (int)$_POST['activation_delay_seconds']));
            waSet('activation_delay_seconds', $activationDelay);
            waSet('activation_cooldown', $activationDelay);
        } elseif (isset($_POST['activation_cooldown'])) {
            $activationDelay = max(1, min(300, (int)$_POST['activation_cooldown']));
            waSet('activation_delay_seconds', $activationDelay);
            waSet('activation_cooldown', $activationDelay);
        }
        if (isset($_POST['relay_worker_token'])) {
            $relayToken = trim((string)$_POST['relay_worker_token']);
            if ($relayToken === '') $relayToken = bin2hex(random_bytes(24));
            waSet('relay_worker_token', substr($relayToken, 0, 128));
        }
        if (isset($_POST['delay_min']))       waSet('delay_min',       max(1,  min(60,  (int)$_POST['delay_min'])));
        if (isset($_POST['delay_max']))       waSet('delay_max',       max(1,  min(60,  (int)$_POST['delay_max'])));
        if (isset($_POST['batch_size']))      waSet('batch_size',      max(1,  min(100, (int)$_POST['batch_size'])));
        if (isset($_POST['batch_pause_min'])) waSet('batch_pause_min', max(1,  min(300, (int)$_POST['batch_pause_min'])));
        if (isset($_POST['batch_pause_max'])) waSet('batch_pause_max', max(1,  min(300, (int)$_POST['batch_pause_max'])));
        flashMessage('success','✅ تم حفظ إعدادات واتساب');
        redirect(SITE_URL.'/admin/whatsapp.php?tab=settings');
    }

    // إضافة مجموعة يدوياً
    if (isset($_POST['add_group_manual'])) {
        $gid   = trim($_POST['group_id'] ?? '');
        $gname = trim($_POST['group_name'] ?? '');
        $gpart = (int)($_POST['participants_count'] ?? 0);
        if ($gid && $gname) {
            try {
                $pdo->prepare("INSERT INTO whatsapp_groups (group_id,group_name,participants_count) VALUES(?,?,?)
                    ON DUPLICATE KEY UPDATE group_name=?,participants_count=?")->execute([$gid,$gname,$gpart,$gname,$gpart]);
                flashMessage('success','✅ تمت إضافة المجموعة');
            } catch(Exception $e) { flashMessage('danger','خطأ: '.$e->getMessage()); }
        } else { flashMessage('warning','يجب إدخال ID واسم المجموعة'); }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=groups');
    }

    // إرسال رسالة واحدة
    if (isset($_POST['send_single'])) {
        $phoneRaw = trim($_POST['phone']??'');
        if (str_contains($phoneRaw, '@')) {
            $phone = $phoneRaw;
        } else {
            $phone = preg_replace('/[^0-9]/','',$phoneRaw);
        }
        $msg   = trim($_POST['message']??'');
        if ($phone && $msg && $isConnected) {
            $res = waSendMessage($apiKey,$senderNumber,$phone,$msg);
            $pdo->prepare("INSERT INTO whatsapp_logs (phone,message,status,error_msg) VALUES(?,?,?,?)")
                ->execute([$phone,$msg,$res['ok']?'sent':'failed',$res['error']]);
            flashMessage($res['ok']?'success':'danger', $res['ok']?"✅ أُرسلت إلى $phone":"❌ فشل: ".$res['error']);
        } else { flashMessage('warning','تأكد من الرقم والرسالة والاتصال'); }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=single');
    }

    // ── أدوات الإرسال ──────────────────────────────────────
    if (isset($_POST['send_tool']) && $isConnected) {
        $toolType = $_POST['tool_type'] ?? '';
        $toRaw    = trim($_POST['tool_to'] ?? '');
        $to       = str_contains($toRaw,'@') ? $toRaw : preg_replace('/[^0-9]/','',$toRaw);

        function toolPost($endpoint, $payload, $apiKey, $sender) {
            $payload['api_key'] = $apiKey;
            $payload['sender']  = $sender;
            $ch = curl_init($endpoint);
            curl_setopt_array($ch,[
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode($payload),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>15,
                CURLOPT_SSL_VERIFYPEER=>false,
            ]);
            $r = curl_exec($ch); $e = curl_error($ch); curl_close($ch);
            $d = json_decode($r,true);
            $ok = !$e && ($d['status']??false)===true;
            $msg = $e ?: ($d['msg']??$d['message']??$d['error']??'');
            if(!$msg&&!$ok) $msg='لا يوجد رد من الخادم';
            return ['ok'=>$ok,'error'=>$msg,'raw'=>$d];
        }

        $res = null;
        $base = 'https://sender.hetacloud.top/';

        switch($toolType) {
            case 'media':
                $mediaUrl  = trim($_POST['media_url']??'');
                $mediaType = $_POST['media_type']??'image';
                $caption   = trim($_POST['media_caption']??'');
                $res = toolPost($base.'send-media',['number'=>$to,'media_type'=>$mediaType,'url'=>$mediaUrl,'caption'=>$caption],$apiKey,$senderNumber);
                break;

            case 'poll':
                $name    = trim($_POST['poll_name']??'');
                $opts    = array_filter(array_map('trim', explode("
", $_POST['poll_options']??'')));
                $multi   = isset($_POST['poll_multi']) ? '0' : '1';
                $res = toolPost($base.'send-poll',['number'=>$to,'name'=>$name,'option'=>array_values($opts),'countable'=>$multi],$apiKey,$senderNumber);
                break;

            case 'button':
                $btnMsg    = trim($_POST['btn_message']??'');
                $btnFooter = trim($_POST['btn_footer']??'');
                $btns      = array_filter(array_map('trim', explode("
", $_POST['btn_options']??'')));
                $btnsArr   = array_map(fn($b)=>['type'=>'reply','displayText'=>$b], array_values($btns));
                $res = toolPost($base.'send-button',['number'=>$to,'message'=>$btnMsg,'button'=>$btnsArr,'footer'=>$btnFooter],$apiKey,$senderNumber);
                break;

            case 'list':
                $listTitle  = trim($_POST['list_title']??'');
                $listBtnTxt = trim($_POST['list_btntext']??'فتح القائمة');
                $listMsg    = trim($_POST['list_message']??'');
                $listFooter = trim($_POST['list_footer']??'');
                $listItems  = array_filter(array_map('trim', explode("
", $_POST['list_items']??'')));
                $res = toolPost($base.'send-list',['number'=>$to,'title'=>$listTitle,'buttontext'=>$listBtnTxt,'message'=>$listMsg,'footer'=>$listFooter,'list'=>array_values($listItems)],$apiKey,$senderNumber);
                break;

            case 'location':
                $lat = trim($_POST['loc_lat']??'');
                $lng = trim($_POST['loc_lng']??'');
                $res = toolPost($base.'send-location',['number'=>$to,'latitude'=>$lat,'longitude'=>$lng],$apiKey,$senderNumber);
                break;

            case 'vcard':
                $vcName  = trim($_POST['vc_name']??'');
                $vcPhone = trim($_POST['vc_phone']??'');
                $res = toolPost($base.'send-vcard',['number'=>$to,'name'=>$vcName,'phone'=>$vcPhone],$apiKey,$senderNumber);
                break;

            case 'sticker':
                $stickerUrl = trim($_POST['sticker_url']??'');
                $res = toolPost($base.'send-sticker',['number'=>$to,'url'=>$stickerUrl],$apiKey,$senderNumber);
                break;
        }

        if ($res) {
            flashMessage($res['ok']?'success':'danger', $res['ok']?"✅ أُرسلت بنجاح":"❌ فشل: ".$res['error']);
        } else {
            flashMessage('warning','نوع غير معروف');
        }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=tools');
    }

    // إرسال لمجموعة/مجموعات
    if (isset($_POST['send_to_groups'])) {
        $selectedGroups = $_POST['selected_groups'] ?? [];
        $msg       = trim($_POST['group_message']??'');
        $mediaUrl  = trim($_POST['media_url']??'');
        $mediaType = trim($_POST['media_type']??'');
        $sendAll   = isset($_POST['send_all_groups']);

        if ($sendAll) {
            $rows = $pdo->query("SELECT * FROM whatsapp_groups WHERE is_active=1")->fetchAll();
            $selectedGroups = array_column($rows,'id');
        }

        if (empty($selectedGroups) || !$msg || !$isConnected) {
            flashMessage('warning','اختر مجموعة واحدة على الأقل وأدخل الرسالة');
            redirect(SITE_URL.'/admin/whatsapp.php?tab=groups');
            exit;
        }

        try {
            // جلب بيانات المجموعات المحددة
            $placeholders = implode(',', array_fill(0, count($selectedGroups), '?'));
            $stmtGrp = $pdo->prepare("SELECT * FROM whatsapp_groups WHERE id IN ($placeholders)");
            $stmtGrp->execute(array_values($selectedGroups));
            $groupRows = $stmtGrp->fetchAll();

            $groupIds   = array_column($groupRows,'group_id');
            $groupNames = array_column($groupRows,'group_name');

            if (empty($groupIds)) {
                flashMessage('warning','لم يتم العثور على المجموعات المحددة');
                redirect(SITE_URL.'/admin/whatsapp.php?tab=groups');
                exit;
            }

            // إنشاء الحملة
            $createdBy = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
            $title = 'رسالة مجموعات — ' . implode('، ', array_slice($groupNames,0,3)) . (count($groupNames)>3?'...':'');
            try {
                $stmt = $pdo->prepare("INSERT INTO whatsapp_campaigns (title,message,recipients_type,recipients_count,status,media_url,media_type,group_ids,created_by)
                    VALUES(?,?,'groups',?,?,?,?,?,?)");
                $stmt->execute([$title,$msg,count($groupIds),'sending',$mediaUrl?:null,$mediaType?:null,implode(',',$groupIds),$createdBy]);
                $campId = $pdo->lastInsertId();
            } catch (Exception $e) {
                // إذا فشل إنشاء الحملة نكمل الإرسال بدونها
                $campId = null;
            }

            $sent=0; $failed=0;
            foreach ($groupIds as $gid) {
                $res = waSendMessage($apiKey,$senderNumber,$gid,$msg,$mediaUrl,$mediaType);
                $res['ok'] ? $sent++ : $failed++;
                try {
                    $pdo->prepare("INSERT INTO whatsapp_logs (campaign_id,phone,message,status,error_msg) VALUES(?,?,?,?,?)")
                        ->execute([$campId,$gid,$msg,$res['ok']?'sent':'failed',$res['error']]);
                } catch (Exception $e) {}
                waSmartDelay($sent + $failed);
            }

            if ($campId) {
                try {
                    $pdo->prepare("UPDATE whatsapp_campaigns SET sent_count=?,failed_count=?,status='done',finished_at=NOW() WHERE id=?")
                        ->execute([$sent,$failed,$campId]);
                } catch (Exception $e) {}
            }

            flashMessage($sent>0?'success':'danger',"✅ أُرسلت لـ $sent مجموعة" . ($failed?" | ❌ فشل $failed":''));
        } catch (Exception $e) {
            flashMessage('danger','خطأ: ' . $e->getMessage());
        }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=groups');
        exit;
    }

    // إنشاء حملة جماعية (أرقام)
    if (isset($_POST['create_campaign'])) {
        $title      = trim($_POST['campaign_title']??'');
        $msg        = trim($_POST['campaign_message']??'');
        $type       = $_POST['recipients_type']??'manual';
        $phones_raw = trim($_POST['phones_manual']??'');
        $mediaUrl   = trim($_POST['media_url']??'');
        $mediaType  = trim($_POST['media_type']??'');
        $phones = [];

        if ($type === 'manual') {
            foreach (preg_split('/[\r\n,;]+/',$phones_raw) as $l) {
                $c = preg_replace('/[^0-9]/','',trim($l));
                if (strlen($c)>=8) $phones[]=$c;
            }
        } elseif ($type === 'customers') {
            $rows = $pdo->query("SELECT phone FROM users WHERE role='customer' AND status=1 AND phone IS NOT NULL AND phone!='' LIMIT 5000")->fetchAll();
            foreach ($rows as $r) { $c=preg_replace('/[^0-9]/','',trim($r['phone'])); if(strlen($c)>=8) $phones[]=$c; }
        }
        $phones = array_unique($phones);

        if ($title && $msg && count($phones)>0 && $isConnected) {
            $stmt = $pdo->prepare("INSERT INTO whatsapp_campaigns (title,message,recipients_type,recipients_count,status,media_url,media_type,created_by)
                VALUES(?,?,?,?,'sending',?,?,?)");
            $stmt->execute([$title,$msg,$type,count($phones),$mediaUrl?:null,$mediaType?:null,$_SESSION['user_id']]);
            $campId = $pdo->lastInsertId();
            $sent=0; $failed=0;
            foreach ($phones as $phone) {
                $res = waSendMessage($apiKey,$senderNumber,$phone,$msg,$mediaUrl,$mediaType);
                $res['ok']?$sent++:$failed++;
                $pdo->prepare("INSERT INTO whatsapp_logs (campaign_id,phone,message,status,error_msg) VALUES(?,?,?,?,?)")
                    ->execute([$campId,$phone,$msg,$res['ok']?'sent':'failed',$res['error']]);
                waSmartDelay($sent + $failed);
            }
            $pdo->prepare("UPDATE whatsapp_campaigns SET sent_count=?,failed_count=?,status='done',finished_at=NOW() WHERE id=?")->execute([$sent,$failed,$campId]);
            flashMessage('success',"✅ الحملة مكتملة: $sent تم إرسالها، $failed فشلت");
        } elseif(count($phones)===0) { flashMessage('warning','لم يتم العثور على أرقام');
        } else { flashMessage('warning','تأكد من ملء جميع الحقول'); }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=bulk');
    }

    // إرسال من Excel
    if (isset($_POST['send_from_excel'])) {
        $msg = trim($_POST['excel_message']??'');
        $phones = [];
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error']===UPLOAD_ERR_OK) {
            $lines = file($_FILES['excel_file']['tmp_name'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $l) {
                $parts = str_getcsv($l);
                foreach ($parts as $p) { $c=preg_replace('/[^0-9]/','',trim($p)); if(strlen($c)>=8){$phones[]=$c;break;} }
            }
        }
        $phones = array_unique($phones);
        if (count($phones)>0 && $msg && $isConnected) {
            $stmt = $pdo->prepare("INSERT INTO whatsapp_campaigns (title,message,recipients_type,recipients_count,status,created_by) VALUES(?,?,'excel',?,'sending',?)");
            $stmt->execute(['حملة Excel — '.date('Y-m-d H:i'),$msg,count($phones),$_SESSION['user_id']]);
            $campId=$pdo->lastInsertId(); $sent=0; $failed=0;
            foreach ($phones as $ph) {
                $res = waSendMessage($apiKey,$senderNumber,$ph,$msg);
                $res['ok']?$sent++:$failed++;
                $pdo->prepare("INSERT INTO whatsapp_logs (campaign_id,phone,message,status) VALUES(?,?,?,?)")->execute([$campId,$ph,$msg,$res['ok']?'sent':'failed']);
                waSmartDelay($sent + $failed);
            }
            $pdo->prepare("UPDATE whatsapp_campaigns SET sent_count=?,failed_count=?,status='done',finished_at=NOW() WHERE id=?")->execute([$sent,$failed,$campId]);
            flashMessage('success',"✅ Excel: $sent أُرسلت، $failed فشلت");
        } else { flashMessage('warning','تأكد من الملف والرسالة والاتصال'); }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=bulk');
    }

    // ── حفظ قاعدة طلبات سوا ──────────────────────────────────
    if (isset($_POST['save_sawa_rule'])) {
        $ruleId = (int)($_POST['sawa_rule_id'] ?? $_POST['rule_id'] ?? 0);
        $name = trim((string)($_POST['sawa_name'] ?? $_POST['rule_name'] ?? ''));
        $sourceGroup = trim((string)($_POST['sawa_source_group_id'] ?? $_POST['source_group_id'] ?? ''));
        $targetGroup = trim((string)($_POST['sawa_target_group_id'] ?? $_POST['target_group_id'] ?? ''));
        $prefix = trim((string)($_POST['sawa_trigger_prefix'] ?? $_POST['trigger_prefix'] ?? 'سوا'));
        $lengthParts = preg_split('/[^0-9]+/', (string)($_POST['sawa_digit_lengths'] ?? $_POST['digit_lengths'] ?? '14'));
        $lengths = [];
        foreach ($lengthParts as $part) {
            $n = (int)$part;
            if ($n >= 4 && $n <= 32) $lengths[$n] = $n;
        }
        $lengths = array_values($lengths);
        $digitLengths = implode(',', $lengths);
        $digitLength = (int)($lengths[0] ?? 14);
        $keyword = trim((string)($_POST['sawa_reply_keyword'] ?? $_POST['reply_keyword'] ?? 'تم'));
        $minAmount = max(0, (float)($_POST['sawa_reply_min_amount'] ?? $_POST['reply_min_amount'] ?? 0));
        $maxRaw = trim((string)($_POST['sawa_reply_max_amount'] ?? $_POST['reply_max_amount'] ?? '1000'));
        $maxAmount = $maxRaw === '' ? null : max($minAmount, (float)$maxRaw);
        $timeout = max(1, min(10080, (int)($_POST['sawa_reply_timeout_min'] ?? $_POST['reply_timeout_min'] ?? 30)));
        $reminderEnabled = isset($_POST['sawa_reminder_enabled']) ? 1 : 0;
        $reminderIntervalRaw = (int)($_POST['sawa_reminder_interval_min'] ?? 10);
        $reminderInterval = in_array($reminderIntervalRaw, [5, 10, 20], true) ? $reminderIntervalRaw : 10;
        $reminderTemplate = trim((string)($_POST['sawa_reminder_template'] ?? ''));
        if ($reminderTemplate === '') $reminderTemplate = "تذكير طلب سوا\nرقم الطلب: #{id}\nرقم البطاقة: {code}\nهل شحنت البطاقة أم لا؟\nالمتبقي من مهلة الرد: {remaining_time}\nللتأكيد: {reply_keyword} #{id} المبلغ\nللرفض: رفض #{id}";
        $requestTemplate = trim((string)($_POST['sawa_request_template'] ?? $_POST['request_template'] ?? ''));
        $resultTemplate = trim((string)($_POST['sawa_result_template'] ?? $_POST['result_template'] ?? ''));
        $replyInstructionTemplate = trim((string)($_POST['sawa_reply_instruction_template'] ?? ''));
        $sourceFormatTemplate = trim((string)($_POST['sawa_source_format_template'] ?? ''));
        $sourceShortTemplate = trim((string)($_POST['sawa_source_short_template'] ?? ''));
        $sourcePendingTemplate = trim((string)($_POST['sawa_source_pending_template'] ?? ''));
        $sourceDuplicateTemplate = trim((string)($_POST['sawa_source_duplicate_template'] ?? ''));
        $sourceForwardTemplate = trim((string)($_POST['sawa_source_forward_template'] ?? ''));
        $rejectResultTemplate = trim((string)($_POST['sawa_reject_result_template'] ?? ''));
        $targetAcceptConfirmationTemplate = trim((string)($_POST['sawa_target_accept_confirmation_template'] ?? ''));
        $targetRejectConfirmationTemplate = trim((string)($_POST['sawa_target_reject_confirmation_template'] ?? ''));
        $active = isset($_POST['sawa_is_active']) || isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $sourceGroup === '' || $targetGroup === '' || $sourceGroup === $targetGroup || $prefix === '' || $keyword === '' || !$lengths || $requestTemplate === '' || $resultTemplate === '') {
            flashMessage('warning', 'أكمل الحقول المطلوبة، وتأكد أن مجموعتي الاستقبال والتنفيذ مختلفتان.');
        } else {
            try {
                if ($ruleId > 0) {
                    $pdo->prepare("UPDATE whatsapp_relay_rules SET name=?,source_group_id=?,target_group_id=?,trigger_prefix=?,digit_lengths=?,digit_length=?,reply_keyword=?,reply_min_amount=?,reply_max_amount=?,reply_timeout_min=?,reminder_enabled=?,reminder_interval_min=?,reminder_template=?,request_template=?,result_template=?,reply_instruction_template=?,source_format_template=?,source_short_template=?,source_pending_template=?,source_duplicate_template=?,source_forward_template=?,reject_result_template=?,target_accept_confirmation_template=?,target_reject_confirmation_template=?,is_active=? WHERE id=?")
                        ->execute([$name,$sourceGroup,$targetGroup,$prefix,$digitLengths,$digitLength,$keyword,$minAmount,$maxAmount,$timeout,$reminderEnabled,$reminderInterval,$reminderTemplate,$requestTemplate,$resultTemplate,$replyInstructionTemplate,$sourceFormatTemplate,$sourceShortTemplate,$sourcePendingTemplate,$sourceDuplicateTemplate,$sourceForwardTemplate,$rejectResultTemplate,$targetAcceptConfirmationTemplate,$targetRejectConfirmationTemplate,$active,$ruleId]);
                    flashMessage('success', '✅ تم تحديث إعدادات طلبات سوا');
                } else {
                    $pdo->prepare("INSERT INTO whatsapp_relay_rules (name,source_group_id,target_group_id,trigger_prefix,digit_lengths,digit_length,reply_keyword,reply_min_amount,reply_max_amount,reply_timeout_min,reminder_enabled,reminder_interval_min,reminder_template,request_template,result_template,reply_instruction_template,source_format_template,source_short_template,source_pending_template,source_duplicate_template,source_forward_template,reject_result_template,target_accept_confirmation_template,target_reject_confirmation_template,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$name,$sourceGroup,$targetGroup,$prefix,$digitLengths,$digitLength,$keyword,$minAmount,$maxAmount,$timeout,$reminderEnabled,$reminderInterval,$reminderTemplate,$requestTemplate,$resultTemplate,$replyInstructionTemplate,$sourceFormatTemplate,$sourceShortTemplate,$sourcePendingTemplate,$sourceDuplicateTemplate,$sourceForwardTemplate,$rejectResultTemplate,$targetAcceptConfirmationTemplate,$targetRejectConfirmationTemplate,$active]);
                    flashMessage('success', '✅ تمت إضافة قاعدة طلبات سوا');
                }
            } catch (Throwable $e) {
                flashMessage('danger', 'تعذر حفظ قاعدة طلبات سوا. تأكد من فتح الصفحة مرة واحدة لإنشاء الجداول.');
            }
        }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=sawa');
        exit;
    }

    // حذف قاعدة سوا مع إبقاء سجل عملياتها التاريخي
    if (isset($_POST['delete_sawa_rule'])) {
        $ruleId = (int)($_POST['sawa_rule_id'] ?? $_POST['rule_id'] ?? 0);
        if ($ruleId > 0) {
            try {
                $pdo->prepare("DELETE FROM whatsapp_relay_rules WHERE id=?")->execute([$ruleId]);
                flashMessage('success', 'تم حذف القاعدة مع إبقاء سجل العمليات السابق.');
            } catch (Throwable $e) {
                flashMessage('danger', 'تعذر حذف القاعدة.');
            }
        }
        redirect(SITE_URL.'/admin/whatsapp.php?tab=sawa');
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
//  جلب البيانات
// ══════════════════════════════════════════════════════════════
$campaigns = $pdo->query("SELECT * FROM whatsapp_campaigns ORDER BY created_at DESC LIMIT 30")->fetchAll();
$logs      = $pdo->query("SELECT * FROM whatsapp_logs ORDER BY sent_at DESC LIMIT 50")->fetchAll();
$waGroups  = $pdo->query("SELECT * FROM whatsapp_groups ORDER BY is_active DESC, group_name ASC")->fetchAll();
$totalSent   = (int)$pdo->query("SELECT COALESCE(SUM(sent_count),0) FROM whatsapp_campaigns WHERE status='done'")->fetchColumn();
$totalFailed = (int)$pdo->query("SELECT COALESCE(SUM(failed_count),0) FROM whatsapp_campaigns WHERE status='done'")->fetchColumn();
$totalCamps  = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_campaigns")->fetchColumn();
$totalGroups = count($waGroups);
$activeGroups= count(array_filter($waGroups, fn($g)=>$g['is_active']));
$waGroupNames = [];
foreach ($waGroups as $groupRow) {
    $waGroupNames[(string)$groupRow['group_id']] = (string)$groupRow['group_name'];
}
$customersWithPhone = 0;
try { $customersWithPhone=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND status=1 AND phone IS NOT NULL AND phone!=''")->fetchColumn(); } catch(Exception $e){}

// بيانات تبويب طلبات سوا — لا تمنع بقية إدارة واتساب من العمل عند غياب الجداول.
$sawaRules = [];
$sawaLogs = [];
try { $sawaRules = $pdo->query("SELECT * FROM whatsapp_relay_rules ORDER BY is_active DESC, id DESC")->fetchAll(); } catch (Throwable $e) {}
try { $sawaLogs = $pdo->query("SELECT l.*, r.name AS rule_name, r.source_group_id, r.target_group_id FROM whatsapp_relay_logs l LEFT JOIN whatsapp_relay_rules r ON r.id=l.rule_id ORDER BY l.id DESC LIMIT 100")->fetchAll(); } catch (Throwable $e) {}
$sawaEditId = (int)($_GET['edit_sawa'] ?? 0);
$sawaEdit = null;
foreach ($sawaRules as $candidate) {
    if ((int)$candidate['id'] === $sawaEditId) { $sawaEdit = $candidate; break; }
}
$sawaDefaultRequestTemplate = "طلب سوا جديد\nرقم الطلب: #{id}\nالرقم: {code}\nالحالة: قيد التنفيذ";
$sawaDefaultResultTemplate = "تم تنفيذ طلب سوا ✅\nرقم الطلب: #{id}\nالرقم: {code}\nالمبلغ: {amount}\nالمنفذ: {executor_name} {executor_number}\nالحالة: تم التنفيذ";
$sawaDefaultReminderTemplate = "تذكير طلب سوا\nرقم الطلب: #{id}\nرقم البطاقة: {code}\nهل شحنت البطاقة أم لا؟\nالمتبقي من مهلة الرد: {remaining_time}\nللتأكيد: {reply_keyword} #{id} المبلغ\nللرفض: رفض #{id}";

include 'header.php';
?>
<style>
/* ════════════════════════════════════════════
   WhatsApp Admin — Styles
════════════════════════════════════════════ */
.wa-wrap{max-width:1120px;margin:0 auto;padding:20px 0}
.wa-tabs{display:flex;gap:6px;margin-bottom:22px;flex-wrap:wrap}
.wa-tab{padding:9px 18px;border-radius:10px;font-size:.82rem;font-weight:700;background:var(--card);border:1px solid var(--border);color:var(--text2);cursor:pointer;text-decoration:none;transition:all .2s;display:flex;align-items:center;gap:7px}
.wa-tab:hover,.wa-tab.active{background:#25d366;border-color:#25d366;color:#fff}
.wa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:24px}
.wa-stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 16px;text-align:center}
.wa-stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin:0 auto 10px}
.wa-stat-num{font-size:1.7rem;font-weight:900;line-height:1;margin-bottom:4px}
.wa-stat-label{font-size:.72rem;color:var(--text3)}
.wa-conn-card{background:var(--card);border:2px solid var(--border);border-radius:16px;padding:22px;display:flex;align-items:center;gap:18px;margin-bottom:22px}
.wa-conn-card.connected{border-color:#25d36640}
.wa-conn-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0}
.wa-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:20px}
.wa-card-title{font-size:1rem;font-weight:800;margin-bottom:18px;display:flex;align-items:center;gap:8px;color:var(--text1)}
.wa-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:680px){.wa-form-grid{grid-template-columns:1fr}}
.wa-input{width:100%;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:10px;color:var(--text1);font-size:.875rem;font-family:inherit;transition:border .2s;box-sizing:border-box}
.wa-input:focus{outline:none;border-color:#25d366;box-shadow:0 0 0 3px #25d36618}
.wa-textarea{min-height:110px;resize:vertical}
.wa-label{font-size:.78rem;font-weight:700;color:var(--text2);margin-bottom:5px;display:block}
.wa-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:10px;font-size:.875rem;font-weight:700;border:none;cursor:pointer;transition:all .2s;text-decoration:none}
.wa-btn-green{background:#25d366;color:#fff}
.wa-btn-green:hover{background:#1eb559;transform:translateY(-1px)}
.wa-btn-red{background:#ff4455;color:#fff}
.wa-btn-red:hover{background:#e03344}
.wa-btn-outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.wa-btn-outline:hover{border-color:#25d366;color:#25d366}
.wa-btn-sm{padding:6px 14px;font-size:.78rem}
.wa-table-wrap{overflow-x:auto}
.wa-table{width:100%;border-collapse:collapse;font-size:.83rem}
.wa-table th{padding:10px 12px;background:var(--bg);color:var(--text3);font-weight:700;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.wa-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text1);vertical-align:middle}
.wa-table tr:hover td{background:rgba(37,211,102,.04)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
.badge-green{background:#25d36620;color:#25d366}
.badge-red{background:#ff445520;color:#ff4455}
.badge-blue{background:#3b82f620;color:#3b82f6}
.badge-gold{background:#f5a62320;color:#f5a623}
.badge-purple{background:#a78bfa20;color:#a78bfa}
.badge-gray{background:var(--border);color:var(--text3)}

/* ── Groups Grid ─────────────────────────────── */
.groups-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:20px}
.group-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;transition:all .2s;position:relative}
.group-card:hover{border-color:#25d36650}
.group-card.inactive{opacity:.55}
.group-card-check{position:absolute;top:14px;left:14px}
.group-card-check input[type=checkbox]{width:18px;height:18px;accent-color:#25d366;cursor:pointer}
.group-card-body{padding-right:0}
.group-icon{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,#25d366,#128c7e);display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:10px}
.group-name{font-weight:800;font-size:.9rem;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-left:30px}
.group-id{font-size:.72rem;color:var(--text3);direction:ltr;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.group-meta{display:flex;align-items:center;justify-content:space-between;margin-top:10px}
.group-actions{display:flex;gap:6px}

/* ── Select All Bar ──────────────────────────── */
.select-bar{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.select-bar-count{font-size:.8rem;color:var(--text2);font-weight:700}

/* ── Progress ────────────────────────────────── */
.wa-progress{width:100%;height:6px;background:var(--border);border-radius:4px;overflow:hidden;margin-top:6px}
.wa-progress-bar{height:100%;background:#25d366;border-radius:4px;transition:width .4s}

/* ── Send Panel (slide in) ───────────────────── */
.send-panel{background:linear-gradient(135deg,#0a1628,#0d1f35);border:1px solid #25d36630;border-radius:16px;padding:20px;margin-bottom:20px;display:none}
.send-panel.show{display:block;animation:slideDown .25s ease}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

.wa-empty{text-align:center;padding:50px;color:var(--text3)}
.wa-empty i{font-size:3rem;display:block;margin-bottom:12px;opacity:.3}

.info-box{border-radius:10px;padding:12px 16px;font-size:.82rem;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start}
.info-box.yellow{background:#f5a62312;border:1px solid #f5a62330;color:#f5a623}
.info-box.green{background:#25d36612;border:1px solid #25d36630;color:#25d366}
.info-box.red{background:#ff445512;border:1px solid #ff445530;color:#ff4455}

/* Recipient selector */
.wa-rtab{padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:700;background:var(--bg);border:1px solid var(--border);color:var(--text3);cursor:pointer}
.wa-rtab.sel{background:#25d36615;border-color:#25d366;color:#25d366}
.wa-phone-area{display:none}
.wa-phone-area.show{display:block}
</style>

<div class="wa-wrap">

<!-- ── Header ─────────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:10px;margin:0">
      <span style="width:40px;height:40px;background:#25d36620;border-radius:12px;display:flex;align-items:center;justify-content:center">
        <i class="fab fa-whatsapp" style="color:#25d366"></i>
      </span>
      إدارة واتساب
    </h2>
    <p style="color:var(--text3);font-size:.8rem;margin-top:4px">رسائل، مجموعات، حملات — مدعوم بـ HetaCloud</p>
  </div>
  <?php if(!$isConnected): ?>
  <a href="?tab=settings" class="wa-btn wa-btn-green"><i class="fab fa-whatsapp"></i> ربط واتساب</a>
  <?php else: ?>
  <span class="badge badge-green" style="padding:7px 14px;font-size:.8rem"><i class="fas fa-circle" style="font-size:.5rem"></i> متصل — <?= htmlspecialchars($senderNumber) ?></span>
  <?php endif; ?>
</div>

<!-- ── Stats ──────────────────────────────────────────────────── -->
<div class="wa-stats">
  <div class="wa-stat">
    <div class="wa-stat-icon" style="background:#25d36615"><i class="fas fa-paper-plane" style="color:#25d366"></i></div>
    <div class="wa-stat-num" style="color:#25d366"><?= number_format($totalSent) ?></div>
    <div class="wa-stat-label">رسائل مُرسلة</div>
  </div>
  <div class="wa-stat">
    <div class="wa-stat-icon" style="background:#a78bfa15"><i class="fas fa-users" style="color:#a78bfa"></i></div>
    <div class="wa-stat-num" style="color:#a78bfa"><?= number_format($activeGroups) ?></div>
    <div class="wa-stat-label">مجموعات نشطة</div>
  </div>
  <div class="wa-stat">
    <div class="wa-stat-icon" style="background:#3b82f615"><i class="fas fa-bullhorn" style="color:#3b82f6"></i></div>
    <div class="wa-stat-num" style="color:#3b82f6"><?= number_format($totalCamps) ?></div>
    <div class="wa-stat-label">الحملات</div>
  </div>
  <div class="wa-stat">
    <div class="wa-stat-icon" style="background:#ff445515"><i class="fas fa-times-circle" style="color:#ff4455"></i></div>
    <div class="wa-stat-num" style="color:#ff4455"><?= number_format($totalFailed) ?></div>
    <div class="wa-stat-label">فشل الإرسال</div>
  </div>
  <div class="wa-stat">
    <div class="wa-stat-icon" style="background:#f5a62315"><i class="fas fa-user-check" style="color:#f5a623"></i></div>
    <div class="wa-stat-num" style="color:#f5a623"><?= number_format($customersWithPhone) ?></div>
    <div class="wa-stat-label">عملاء بأرقام</div>
  </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────── -->
<div class="wa-tabs">
  <?php
  $tabs=[
    'dashboard'=>['chart-line','لوحة التحكم'],
    'groups'   =>['users','المجموعات'],
    'single'   =>['comment','رسالة واحدة'],
    'bulk'     =>['bullhorn','رسائل جماعية'],
    'campaigns'=>['list','الحملات'],
    'logs'     =>['history','السجلات'],
    'tools'    =>['toolbox','أدوات الإرسال'],
    'sawa'     =>['exchange-alt','طلبات سوا'],
    'settings' =>['cog','الإعدادات'],
  ];
  foreach ($tabs as $k=>[$icon,$label]):
  ?>
  <a href="?tab=<?= $k ?>" class="wa-tab <?= $tab===$k?'active':'' ?>">
    <i class="fas fa-<?= $icon ?>"></i> <?= $label ?>
    <?php if($k==='groups' && $totalGroups>0): ?><span style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 7px;font-size:.7rem"><?= $totalGroups ?></span><?php endif; ?>
    <?php if($k==='sawa' && count($sawaRules)>0): ?><span style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 7px;font-size:.7rem"><?= count($sawaRules) ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php /* ══════════════════════════════════════════
   TAB: DASHBOARD
══════════════════════════════════════════ */ if($tab==='dashboard'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-chart-bar" style="color:#25d366"></i> آخر الحملات</div>
  <?php if(empty($campaigns)): ?>
  <div class="wa-empty"><i class="fas fa-bullhorn"></i>لا توجد حملات بعد</div>
  <?php else: ?>
  <div class="wa-table-wrap">
    <table class="wa-table">
      <thead><tr><th>#</th><th>عنوان الحملة</th><th>النوع</th><th>الإرسال</th><th>نسبة النجاح</th><th>الحالة</th><th>التاريخ</th></tr></thead>
      <tbody>
        <?php foreach($campaigns as $c):
          $pct = $c['recipients_count']>0?round(($c['sent_count']/$c['recipients_count'])*100):0;
          $tl=['manual'=>['badge-blue','يدوي'],'customers'=>['badge-gold','عملاء'],'excel'=>['badge-green','Excel'],'groups'=>['badge-purple','مجموعات']];
          $ti=$tl[$c['recipients_type']]??['badge-blue',$c['recipients_type']];
          $sl=['draft'=>['badge-gray','مسودة'],'sending'=>['badge-gold','جارٍ'],'done'=>['badge-green','مكتملة'],'failed'=>['badge-red','فشلت']];
          $si=$sl[$c['status']]??['badge-gray',$c['status']];
        ?>
        <tr>
          <td style="color:var(--text3)"><?= $c['id'] ?></td>
          <td style="font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['title']) ?></td>
          <td><span class="badge <?= $ti[0] ?>"><?= $ti[1] ?></span></td>
          <td><span style="color:#25d366;font-weight:700"><?= $c['sent_count'] ?></span><span style="color:var(--text3)"> / <?= $c['recipients_count'] ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div class="wa-progress" style="width:70px"><div class="wa-progress-bar" style="width:<?= $pct ?>%"></div></div>
              <span style="font-size:.75rem"><?= $pct ?>%</span>
            </div>
          </td>
          <td><span class="badge <?= $si[0] ?>"><?= $si[1] ?></span></td>
          <td style="color:var(--text3);font-size:.77rem"><?= date('Y/m/d H:i',strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
  <?php foreach([['groups','users','المجموعات','أرسل لمجموعات واتساب المتصلة','#a78bfa'],['single','comment','رسالة واحدة','إرسال فوري لرقم أو مجموعة','#25d366'],['bulk','bullhorn','رسائل جماعية','حملة لعملائك أو قائمة أرقام','#3b82f6']] as [$t,$ic,$lb,$ds,$cl]): ?>
  <a href="?tab=<?= $t ?>" class="wa-card" style="text-decoration:none;cursor:pointer;transition:border .2s" onmouseover="this.style.borderColor='<?= $cl ?>'" onmouseout="this.style.borderColor='var(--border)'">
    <div style="font-size:1.8rem;color:<?= $cl ?>;margin-bottom:8px"><i class="fas fa-<?= $ic ?>"></i></div>
    <div style="font-weight:800;margin-bottom:4px"><?= $lb ?></div>
    <div style="font-size:.8rem;color:var(--text3)"><?= $ds ?></div>
  </a>
  <?php endforeach; ?>
</div>

<?php /* ══════════════════════════════════════════
   TAB: GROUPS
══════════════════════════════════════════ */ elseif($tab==='groups'): ?>

<!-- إرشادات -->
<div class="info-box yellow">
  <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0"></i>
  <div>
    <strong>كيف تحصل على ID المجموعة؟</strong> — اضغط "مزامنة المجموعات" إذا كان الـ API يدعمه، أو أضف المجموعة يدوياً عبر ID الذي يبدو هكذا: <code style="background:rgba(0,0,0,.2);padding:1px 6px;border-radius:4px;font-family:monospace">120363xxxxxxxxx@g.us</code>
  </div>
</div>

<!-- شريط الأدوات -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
  <button onclick="syncGroups(this)" class="wa-btn wa-btn-green" <?= !$isConnected?'disabled':'' ?>>
    <i class="fas fa-sync-alt"></i> مزامنة المجموعات
  </button>
  <button onclick="document.getElementById('addGroupModal').style.display='flex'" class="wa-btn wa-btn-outline">
    <i class="fas fa-plus"></i> إضافة يدوية
  </button>
  <button onclick="discoverGroups()" class="wa-btn" style="background:#3b82f6;color:#fff">
    <i class="fas fa-search"></i> اكتشاف من الرسائل
  </button>
  <?php if(count($waGroups)>0): ?>
  <button onclick="toggleSendPanel()" class="wa-btn" style="background:#a78bfa;color:#fff" id="sendPanelBtn">
    <i class="fas fa-paper-plane"></i> إرسال للمحددة <span id="selectedCount" style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 8px;font-size:.75rem">0</span>
  </button>
  <?php endif; ?>
  <span style="font-size:.8rem;color:var(--text3);margin-right:auto"><?= count($waGroups) ?> مجموعة محفوظة | <?= $activeGroups ?> نشطة</span>
</div>

<!-- Send Panel -->
<div class="send-panel" id="sendPanel">
  <div style="font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px">
    <i class="fab fa-whatsapp" style="color:#25d366"></i>
    إرسال رسالة للمجموعات المحددة
    <span id="sendPanelCount" style="font-size:.8rem;color:#a78bfa;font-weight:400"></span>
  </div>
  <form method="POST" id="groupSendForm">
    <input type="hidden" name="send_to_groups" value="1">
    <div id="hiddenGroupInputs"></div>
    <div class="wa-form-grid">
      <div style="grid-column:1/-1">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
          <label style="display:flex;align-items:center;gap:7px;font-size:.82rem;cursor:pointer">
            <input type="checkbox" name="send_all_groups" id="sendAllCheck" style="width:16px;height:16px;accent-color:#25d366" onchange="toggleSendAll(this)">
            <span>إرسال لجميع المجموعات النشطة (<?= $activeGroups ?>)</span>
          </label>
        </div>
        <textarea name="group_message" class="wa-input wa-textarea" placeholder="اكتب رسالتك للمجموعات..." required style="min-height:90px"></textarea>
      </div>
      <div>
        <label class="wa-label">نوع وسائط (اختياري)</label>
        <select name="media_type" class="wa-input">
          <option value="">— بدون وسائط —</option>
          <option value="image">صورة</option>
          <option value="video">فيديو</option>
          <option value="document">مستند</option>
        </select>
      </div>
      <div>
        <label class="wa-label">رابط الوسائط</label>
        <input type="url" name="media_url" class="wa-input" placeholder="https://example.com/image.jpg">
      </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px">
      <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-paper-plane"></i> إرسال الآن</button>
      <button type="button" onclick="toggleSendPanel()" class="wa-btn wa-btn-outline">إلغاء</button>
    </div>
  </form>
</div>

<!-- بطاقات المجموعات -->
<?php if(empty($waGroups)): ?>
<div class="wa-card">
  <div class="wa-empty">
    <i class="fas fa-users"></i>
    <div style="font-weight:700;margin-bottom:8px">لا توجد مجموعات محفوظة</div>
    <div style="font-size:.83rem">اضغط "مزامنة المجموعات" لجلبها تلقائياً، أو أضف يدوياً.</div>
  </div>
</div>
<?php else: ?>
<!-- Select All Bar -->
<div class="select-bar">
  <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.83rem;font-weight:700">
    <input type="checkbox" id="selectAllGroups" onchange="selectAll(this)" style="width:16px;height:16px;accent-color:#25d366">
    تحديد الكل
  </label>
  <span class="select-bar-count" id="selBarCount">0 محدد</span>
  <button onclick="toggleSendPanel()" class="wa-btn wa-btn-green wa-btn-sm" style="margin-right:auto">
    <i class="fas fa-paper-plane"></i> إرسال للمحددة
  </button>
</div>

<div class="groups-grid" id="groupsGrid">
  <?php foreach($waGroups as $g): ?>
  <div class="group-card <?= $g['is_active']?'':'inactive' ?>" id="gcard_<?= $g['id'] ?>">
    <div class="group-card-check">
      <input type="checkbox" class="group-cb" value="<?= $g['id'] ?>" onchange="updateSelected()" <?= !$g['is_active']?'disabled':'' ?>>
    </div>
    <div class="group-icon">👥</div>
    <div class="group-name" title="<?= htmlspecialchars($g['group_name']) ?>"><?= htmlspecialchars($g['group_name']) ?></div>
    <div class="group-id" title="<?= htmlspecialchars($g['group_id']) ?>"><?= htmlspecialchars($g['group_id']) ?></div>
    <div class="group-meta">
      <div style="display:flex;gap:6px;align-items:center">
        <?php if($g['participants_count']>0): ?>
        <span class="badge badge-blue"><i class="fas fa-user" style="font-size:.6rem"></i> <?= $g['participants_count'] ?></span>
        <?php endif; ?>
        <span class="badge <?= $g['is_active']?'badge-green':'badge-gray' ?>"><?= $g['is_active']?'نشطة':'مُعطلة' ?></span>
      </div>
      <div class="group-actions">
        <button onclick="toggleGroup(<?= $g['id'] ?>)" class="wa-btn wa-btn-outline wa-btn-sm" title="تفعيل/تعطيل">
          <i class="fas fa-power-off" style="color:<?= $g['is_active']?'#25d366':'#ff4455' ?>"></i>
        </button>
        <button onclick="renameGroup(<?= $g['id'] ?>, '<?= htmlspecialchars(addslashes($g['group_name'])) ?>')" class="wa-btn wa-btn-outline wa-btn-sm" title="تعديل الاسم">
          <i class="fas fa-edit" style="color:#60a5fa"></i>
        </button>
        <button onclick="copyId('<?= htmlspecialchars($g['group_id']) ?>')" class="wa-btn wa-btn-outline wa-btn-sm" title="نسخ الـ ID">
          <i class="fas fa-copy"></i>
        </button>
        <button onclick="deleteGroup(<?= $g['id'] ?>)" class="wa-btn wa-btn-outline wa-btn-sm" title="حذف">
          <i class="fas fa-trash" style="color:#ff4455"></i>
        </button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal إضافة مجموعة يدوياً -->
<div id="addGroupModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:10000;align-items:center;justify-content:center">
  <div style="background:#111827;border:1px solid #1e2940;border-radius:18px;padding:28px;width:480px;max-width:95vw;direction:rtl">
    <div style="font-weight:800;font-size:1rem;margin-bottom:18px;display:flex;align-items:center;gap:8px">
      <i class="fas fa-plus-circle" style="color:#25d366"></i> إضافة مجموعة يدوياً
    </div>
    <form method="POST">
      <input type="hidden" name="add_group_manual" value="1">
      <div style="margin-bottom:12px">
        <label class="wa-label">ID المجموعة <span style="color:#ff4455">*</span></label>
        <input type="text" name="group_id" class="wa-input" placeholder="120363xxxxxxxxx@g.us" required dir="ltr">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">يمكن الحصول عليه من تطبيق WA Business أو أدوات الـ API</div>
      </div>
      <div style="margin-bottom:12px">
        <label class="wa-label">اسم المجموعة <span style="color:#ff4455">*</span></label>
        <input type="text" name="group_name" class="wa-input" placeholder="مثال: عملاء VIP" required>
      </div>
      <div style="margin-bottom:18px">
        <label class="wa-label">عدد المشتركين <span style="color:var(--text3)">(اختياري)</span></label>
        <input type="number" name="participants_count" class="wa-input" placeholder="0" min="0">
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-save"></i> حفظ المجموعة</button>
        <button type="button" onclick="document.getElementById('addGroupModal').style.display='none'" class="wa-btn wa-btn-outline">إلغاء</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal اكتشاف المجموعات من الرسائل الواردة -->
<div id="discoverModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:10001;align-items:center;justify-content:center;padding:16px">
  <div style="background:#111827;border:1px solid #1e2940;border-radius:18px;padding:28px;width:1100px;max-width:96vw;max-height:85vh;display:flex;flex-direction:column;direction:rtl">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-shrink:0">
      <div style="font-weight:800;font-size:1rem;display:flex;align-items:center;gap:8px">
        <i class="fas fa-search" style="color:#3b82f6"></i>
        مجموعات مكتشفة من الرسائل الواردة
      </div>
      <div style="display:flex;align-items:center;gap:8px">
         <button onclick="refreshDiscoveredGroups(true)" class="wa-btn wa-btn-outline" style="padding:6px 10px;font-size:.75rem" title="تحديث الرسائل الآن"><i class="fas fa-sync-alt"></i> تحديث</button>
         <button onclick="closeDiscoverModal()" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:1.2rem" title="إغلاق"><i class="fas fa-times"></i></button>
       </div>
    </div>

    <div id="discoverLoading" style="text-align:center;padding:40px;color:var(--text3)">
      <i class="fas fa-spinner fa-spin" style="font-size:2rem;display:block;margin-bottom:12px"></i>
      جاري البحث في الرسائل الواردة...
    </div>

    <div id="discoverList" style="overflow:auto;flex:1;display:none">
      <div class="info-box green" style="margin-bottom:12px">
        <i class="fas fa-info-circle"></i>
        يمكنك تعديل اسم المجموعة قبل الاستيراد — الـ ID يُستخدم للإرسال
      </div>
      <div id="discoverEmpty" style="display:none;text-align:center;padding:40px;color:var(--text3)">
        <i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.25"></i>
        لا توجد رسائل مجموعات في الصندوق الوارد بعد
      </div>
      <table style="width:100%;min-width:920px;border-collapse:collapse;font-size:.82rem" id="discoverTable">
        <thead>
          <tr style="border-bottom:1px solid var(--border)">
            <th style="padding:8px 6px;text-align:right;color:var(--text3);width:34px">
              <input type="checkbox" id="discoverSelectAll" onchange="discoverToggleAll(this)" style="accent-color:#3b82f6;width:15px;height:15px">
            </th>
            <th style="padding:8px 6px;text-align:right;color:var(--text3)">ID المجموعة</th>
            <th style="padding:8px 6px;text-align:right;color:var(--text3)">الاسم (قابل للتعديل)</th>
            <th style="padding:8px 6px;text-align:right;color:var(--text3);min-width:230px">آخر رسالة</th>
            <th style="padding:8px 6px;text-align:center;color:var(--text3);white-space:nowrap">تاريخ الرسالة</th>
            <th style="padding:8px 6px;text-align:center;color:var(--text3)">رسائل</th>
            <th style="padding:8px 6px;text-align:center;color:var(--text3)">الحالة</th>
          </tr>
        </thead>
        <tbody id="discoverTableBody"></tbody>
      </table>
    </div>

    <div id="discoverActions" style="margin-top:16px;display:none;gap:10px;flex-wrap:wrap;flex-shrink:0;align-items:center">
      <button onclick="importDiscovered()" class="wa-btn wa-btn-green">
        <i class="fas fa-file-import"></i> استيراد المحددة
      </button>
<button onclick="closeDiscoverModal()" class="wa-btn wa-btn-outline">إلغاء</button>
       <span id="discoverSelCount" style="font-size:.8rem;color:var(--text3);margin-right:auto">0 محدد</span>
       <span id="discoverRefreshStatus" style="font-size:.72rem;color:var(--text3)">التحديث التلقائي كل 30 ثانية</span>
    </div>
  </div>
</div>

<?php /* ══════════════════════════════════════════
   TAB: SINGLE
══════════════════════════════════════════ */ elseif($tab==='single'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-comment" style="color:#25d366"></i> إرسال رسالة واحدة</div>
  <?php if(!$isConnected): ?><div class="info-box red"><i class="fas fa-exclamation-triangle"></i> يجب ربط واتساب من <a href="?tab=settings" style="color:#ff4455;font-weight:700">الإعدادات</a></div>
  <?php else: ?>
  <form method="POST">
    <div class="wa-form-grid">
      <div>
        <label class="wa-label">رقم أو ID المجموعة <span style="color:#ff4455">*</span></label>
        <input type="text" name="phone" class="wa-input" placeholder="967778123456 أو 120363xxx@g.us" required dir="ltr">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">أرقام: مع رمز الدولة | مجموعات: ID كامل</div>
      </div>
      <div>
        <label class="wa-label">أو اختر مجموعة محفوظة</label>
        <select class="wa-input" onchange="if(this.value)document.querySelector('[name=phone]').value=this.value">
          <option value="">— اختر مجموعة —</option>
          <?php foreach($waGroups as $g): ?>
          <option value="<?= htmlspecialchars($g['group_id']) ?>"><?= htmlspecialchars($g['group_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="margin-top:14px">
      <label class="wa-label">الرسالة <span style="color:#ff4455">*</span></label>
      <textarea name="message" class="wa-input wa-textarea" placeholder="اكتب رسالتك..." required></textarea>
    </div>
    <div style="margin-top:14px">
      <button type="submit" name="send_single" class="wa-btn wa-btn-green"><i class="fab fa-whatsapp"></i> إرسال</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php
$singleLogs=$pdo->query("SELECT * FROM whatsapp_logs WHERE campaign_id IS NULL ORDER BY sent_at DESC LIMIT 15")->fetchAll();
if(!empty($singleLogs)): ?>
<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-history" style="color:var(--text3)"></i> آخر الرسائل</div>
  <div class="wa-table-wrap">
    <table class="wa-table">
      <thead><tr><th>المستلم</th><th>الرسالة</th><th>الحالة</th><th>الوقت</th></tr></thead>
      <tbody>
        <?php foreach($singleLogs as $l): ?>
        <tr>
          <td style="font-weight:600;direction:ltr;font-size:.8rem"><?= htmlspecialchars($l['phone']) ?></td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($l['message'],0,55)) ?></td>
          <td><span class="badge <?= $l['status']==='sent'?'badge-green':'badge-red' ?>"><?= $l['status']==='sent'?'✓ أُرسلت':'✗ فشلت' ?></span></td>
          <td style="color:var(--text3);font-size:.77rem"><?= date('H:i',strtotime($l['sent_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php /* ══════════════════════════════════════════
   TAB: BULK
══════════════════════════════════════════ */ elseif($tab==='bulk'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-bullhorn" style="color:#25d366"></i> حملة رسائل جماعية للأرقام</div>
  <?php if(!$isConnected): ?><div class="info-box red"><i class="fas fa-exclamation-triangle"></i> يجب ربط واتساب أولاً</div>
  <?php else: ?>
  <form method="POST">
    <div class="wa-form-grid">
      <div style="grid-column:1/-1">
        <label class="wa-label">عنوان الحملة *</label>
        <input type="text" name="campaign_title" class="wa-input" placeholder="مثال: عرض رمضان 2025" required>
      </div>
    </div>
    <div style="margin-top:12px">
      <label class="wa-label">المستلمون *</label>
      <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <div class="wa-rtab sel" onclick="selR('manual',this)"><i class="fas fa-keyboard"></i> يدوي</div>
        <div class="wa-rtab" onclick="selR('customers',this)"><i class="fas fa-users"></i> عملاء الموقع (<?= number_format($customersWithPhone) ?>)</div>
      </div>
      <input type="hidden" name="recipients_type" id="rt" value="manual">
      <div class="wa-phone-area show" id="area_manual">
        <textarea name="phones_manual" class="wa-input wa-textarea" placeholder="أرقام مفصولة بسطر جديد أو فاصلة:&#10;967778123456&#10;967712345678"></textarea>
      </div>
      <div class="wa-phone-area" id="area_customers">
        <div class="info-box green"><i class="fas fa-info-circle"></i> سيُرسل لـ <strong><?= number_format($customersWithPhone) ?></strong> عميل لديهم أرقام مسجلة</div>
      </div>
    </div>
    <div style="margin-top:12px">
      <label class="wa-label">الرسالة *</label>
      <textarea name="campaign_message" class="wa-input wa-textarea" required placeholder="نص الرسالة..."></textarea>
    </div>
    <div style="margin-top:12px;background:var(--bg);border-radius:10px;padding:14px">
      <div style="font-size:.82rem;font-weight:700;margin-bottom:10px;color:var(--text2)"><i class="fas fa-paperclip"></i> وسائط (اختياري)</div>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">نوع الوسائط</label>
          <select name="media_type" class="wa-input"><option value="">بدون وسائط</option><option value="image">صورة</option><option value="video">فيديو</option><option value="audio">صوت</option><option value="document">مستند</option></select>
        </div>
        <div>
          <label class="wa-label">رابط مباشر</label>
          <input type="url" name="media_url" class="wa-input" placeholder="https://example.com/image.jpg">
        </div>
      </div>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button type="submit" name="create_campaign" class="wa-btn wa-btn-green"><i class="fas fa-paper-plane"></i> بدء الحملة</button>
      <span style="font-size:.77rem;color:var(--text3)"><i class="fas fa-clock"></i> فاصل 0.3 ث بين كل رسالة</span>
    </div>
  </form>
  <?php endif; ?>
</div>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-file-csv" style="color:#25d366"></i> إرسال من ملف CSV</div>
  <?php if(!$isConnected): ?><div class="info-box red"><i class="fas fa-exclamation-triangle"></i> يجب ربط واتساب أولاً</div>
  <?php else: ?>
  <div class="info-box yellow"><i class="fas fa-info-circle"></i> الملف يجب أن يكون <strong>CSV أو TXT</strong> — الأرقام في العمود الأول مع رمز الدولة</div>
  <form method="POST" enctype="multipart/form-data">
    <div class="wa-form-grid">
      <div><label class="wa-label">الملف *</label><input type="file" name="excel_file" class="wa-input" accept=".csv,.txt" required></div>
      <div><label class="wa-label">الرسالة *</label><textarea name="excel_message" class="wa-input" style="height:80px" required placeholder="نص الرسالة..."></textarea></div>
    </div>
    <div style="margin-top:12px">
      <button type="submit" name="send_from_excel" class="wa-btn wa-btn-green"><i class="fas fa-upload"></i> رفع وإرسال</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════
   TAB: CAMPAIGNS
══════════════════════════════════════════ */ elseif($tab==='campaigns'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-list" style="color:#25d366"></i> جميع الحملات (<?= count($campaigns) ?>)</div>
  <?php if(empty($campaigns)): ?><div class="wa-empty"><i class="fas fa-bullhorn"></i>لا توجد حملات</div>
  <?php else: ?>
  <div class="wa-table-wrap">
    <table class="wa-table">
      <thead><tr><th>#</th><th>العنوان</th><th>النوع</th><th>المجموعات / الأرقام</th><th>نجح</th><th>فشل</th><th>نسبة النجاح</th><th>الحالة</th><th>التاريخ</th></tr></thead>
      <tbody>
        <?php foreach($campaigns as $c):
          $pct=$c['recipients_count']>0?round(($c['sent_count']/$c['recipients_count'])*100):0;
          $tl=['manual'=>['badge-blue','يدوي'],'customers'=>['badge-gold','عملاء'],'excel'=>['badge-green','Excel'],'groups'=>['badge-purple','مجموعات']];
          $ti=$tl[$c['recipients_type']]??['badge-blue',$c['recipients_type']];
          $sl=['draft'=>['badge-gray','مسودة'],'sending'=>['badge-gold','جارٍ'],'done'=>['badge-green','مكتملة'],'failed'=>['badge-red','فشلت']];
          $si=$sl[$c['status']]??['badge-gray',$c['status']];
        ?>
        <tr>
          <td style="color:var(--text3)"><?= $c['id'] ?></td>
          <td style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($c['title']) ?>"><?= htmlspecialchars(mb_substr($c['title'],0,35)) ?></td>
          <td><span class="badge <?= $ti[0] ?>"><?= $ti[1] ?></span></td>
          <td style="font-size:.8rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text3)">
            <?php if($c['recipients_type']==='groups' && $c['group_ids']): ?>
              <span title="<?= htmlspecialchars($c['group_ids']) ?>">
                <?= count(explode(',',$c['group_ids'])) ?> مجموعة
              </span>
            <?php else: ?>
              <?= $c['recipients_count'] ?> رقم
            <?php endif; ?>
          </td>
          <td style="color:#25d366;font-weight:700"><?= $c['sent_count'] ?></td>
          <td style="color:<?= $c['failed_count']>0?'#ff4455':'var(--text3)' ?>"><?= $c['failed_count'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <div class="wa-progress" style="width:60px"><div class="wa-progress-bar" style="width:<?= $pct ?>%"></div></div>
              <span style="font-size:.75rem"><?= $pct ?>%</span>
            </div>
          </td>
          <td><span class="badge <?= $si[0] ?>"><?= $si[1] ?></span></td>
          <td style="color:var(--text3);font-size:.77rem"><?= date('Y/m/d H:i',strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════
   TAB: LOGS
══════════════════════════════════════════ */ elseif($tab==='logs'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-history" style="color:#25d366"></i> سجل الرسائل (آخر 50)</div>
  <?php if(empty($logs)): ?><div class="wa-empty"><i class="fas fa-history"></i>لا توجد سجلات</div>
  <?php else: ?>
  <div class="wa-table-wrap">
    <table class="wa-table">
      <thead><tr><th>#</th><th>المستلم</th><th>الحملة</th><th>الرسالة</th><th>الحالة</th><th>خطأ</th><th>الوقت</th></tr></thead>
      <tbody>
        <?php foreach($logs as $l): ?>
        <tr>
          <td style="color:var(--text3)"><?= $l['id'] ?></td>
          <td style="font-weight:600;direction:ltr;font-size:.78rem;max-width:150px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($l['phone']) ?></td>
          <td style="color:var(--text3)"><?= $l['campaign_id']?'#'.$l['campaign_id']:'—' ?></td>
          <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)"><?= htmlspecialchars(mb_substr($l['message']??'',0,45)) ?></td>
          <td><span class="badge <?= $l['status']==='sent'?'badge-green':'badge-red' ?>"><?= $l['status']==='sent'?'✓':'✗' ?></span></td>
          <td style="color:#ff4455;font-size:.75rem"><?= htmlspecialchars(mb_substr($l['error_msg']??'',0,35)) ?></td>
          <td style="color:var(--text3);font-size:.77rem"><?= date('Y/m/d H:i',strtotime($l['sent_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══════════════════════════════════════════
   TAB: TOOLS
══════════════════════════════════════════ */ elseif($tab==='tools'): ?>

<?php if(!$isConnected): ?>
<div class="info-box red"><i class="fas fa-exclamation-triangle"></i> يجب ربط واتساب من <a href="?tab=settings" style="color:#ff4455;font-weight:700">الإعدادات</a></div>
<?php else: ?>

<!-- حقل المستلم المشترك -->
<div class="wa-card" style="margin-bottom:6px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div style="font-weight:800;font-size:.9rem;white-space:nowrap"><i class="fas fa-paper-plane" style="color:#25d366"></i> المستلم (مشترك لجميع الأدوات)</div>
    <div style="flex:1;min-width:220px">
      <input type="text" id="tool_to_global" class="wa-input" placeholder="967778123456 أو 120363xxx@g.us" dir="ltr" style="margin:0">
    </div>
    <div style="min-width:180px">
      <select class="wa-input" style="margin:0" onchange="if(this.value)document.getElementById('tool_to_global').value=this.value">
        <option value="">— أو اختر مجموعة —</option>
        <?php foreach($waGroups as $g): ?>
        <option value="<?= htmlspecialchars($g['group_id']) ?>"><?= htmlspecialchars($g['group_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<?php
$toolSections = [
  'media'    => ['photo-video','وسائط (صورة/فيديو/صوت/مستند)','#3b82f6'],
  'poll'     => ['poll-h','استطلاع رأي','#a78bfa'],
  'button'   => ['hand-pointer','رسالة بأزرار','#f59e0b'],
  'list'     => ['list-ul','رسالة بقائمة','#10b981'],
  'location' => ['map-marker-alt','موقع جغرافي','#ef4444'],
  'vcard'    => ['address-card','بطاقة جهة اتصال (vCard)','#06b6d4'],
  'sticker'  => ['sticky-note','ملصق (Sticker)','#ec4899'],
];
?>

<?php foreach($toolSections as $type=>[$icon,$title,$color]): ?>
<div class="wa-card" style="margin-bottom:10px">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">
    <i class="fas fa-<?= $icon ?>" style="color:<?= $color ?>"></i>
    <span style="font-weight:800;font-size:.9rem"><?= $title ?></span>
    <i class="fas fa-chevron-down" style="color:var(--text3);margin-right:auto;font-size:.75rem"></i>
  </div>
  <div>
  <form method="POST" onsubmit="syncToolTo(this)">
    <input type="hidden" name="send_tool" value="1">
    <input type="hidden" name="tool_type" value="<?= $type ?>">
    <input type="hidden" name="tool_to" id="to_<?= $type ?>" value="">

    <?php if($type==='media'): ?>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">نوع الوسائط</label>
          <select name="media_type" id="media_type_sel" class="wa-input" onchange="autoDetectType()">
            <option value="image">صورة (image)</option>
            <option value="video">فيديو (video)</option>
            <option value="audio">صوت (audio)</option>
            <option value="document">مستند (document)</option>
          </select>
        </div>
        <div>
          <label class="wa-label">رابط مباشر للملف <span style="color:#ff4455">*</span></label>
          <input type="url" name="media_url" id="media_url_input" class="wa-input" placeholder="https://example.com/image.jpg" dir="ltr">
          <div style="margin-top:6px;display:flex;align-items:center;gap:8px">
            <span style="font-size:.75rem;color:var(--text3)">أو</span>
            <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;background:#1e2940;border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:.8rem;font-weight:700;transition:.2s" onmouseover="this.style.background='#253352'" onmouseout="this.style.background='#1e2940'">
              <i class="fas fa-upload" style="color:#3b82f6"></i> رفع ملف
              <input type="file" id="media_file_input" style="display:none" accept="image/*,video/*,audio/*,.pdf,.doc,.docx" onchange="uploadMediaFile(this)">
            </label>
            <span id="media_upload_status" style="font-size:.75rem;color:var(--text3)"></span>
          </div>
          <!-- معاينة -->
          <div id="media_preview" style="margin-top:8px;display:none">
            <img id="media_preview_img" style="max-height:80px;border-radius:8px;display:none">
            <div id="media_preview_file" style="background:#1e2940;border-radius:8px;padding:8px 12px;font-size:.8rem;display:none">
              <i class="fas fa-file"></i> <span id="media_preview_name"></span>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-top:10px">
        <label class="wa-label">تعليق (اختياري)</label>
        <textarea name="media_caption" class="wa-input wa-textarea" rows="2" placeholder="نص مع الوسائط..."></textarea>
      </div>

    <?php elseif($type==='poll'): ?>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">سؤال الاستطلاع <span style="color:#ff4455">*</span></label>
          <input type="text" name="poll_name" class="wa-input" placeholder="ما هو لونك المفضل؟" required>
        </div>
        <div>
          <label class="wa-label">الخيارات (كل خيار في سطر) <span style="color:#ff4455">*</span></label>
          <textarea name="poll_options" class="wa-input" rows="4" placeholder="أحمر&#10;أزرق&#10;أخضر" required></textarea>
        </div>
      </div>
      <div style="margin-top:10px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="poll_multi" style="accent-color:#a78bfa;width:16px;height:16px">
          <span style="font-size:.85rem">السماح باختيار متعدد</span>
        </label>
      </div>

    <?php elseif($type==='button'): ?>
      <div>
        <label class="wa-label">نص الرسالة <span style="color:#ff4455">*</span></label>
        <textarea name="btn_message" class="wa-input wa-textarea" rows="2" placeholder="اختر أحد الخيارات:" required></textarea>
      </div>
      <div class="wa-form-grid" style="margin-top:10px">
        <div>
          <label class="wa-label">الأزرار (كل زر في سطر، بحد أقصى 5) <span style="color:#ff4455">*</span></label>
          <textarea name="btn_options" class="wa-input" rows="5" placeholder="تأكيد&#10;إلغاء&#10;المزيد" required></textarea>
        </div>
        <div>
          <label class="wa-label">تذييل (اختياري)</label>
          <input type="text" name="btn_footer" class="wa-input" placeholder="njaz.net">
        </div>
      </div>

    <?php elseif($type==='list'): ?>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">عنوان القائمة <span style="color:#ff4455">*</span></label>
          <input type="text" name="list_title" class="wa-input" placeholder="اختر خدمة" required>
        </div>
        <div>
          <label class="wa-label">نص زر القائمة <span style="color:#ff4455">*</span></label>
          <input type="text" name="list_btntext" class="wa-input" placeholder="فتح القائمة" required>
        </div>
      </div>
      <div style="margin-top:10px">
        <label class="wa-label">نص الرسالة <span style="color:#ff4455">*</span></label>
        <textarea name="list_message" class="wa-input" rows="2" placeholder="مرحباً، اختر من القائمة:" required></textarea>
      </div>
      <div class="wa-form-grid" style="margin-top:10px">
        <div>
          <label class="wa-label">عناصر القائمة (كل عنصر في سطر) <span style="color:#ff4455">*</span></label>
          <textarea name="list_items" class="wa-input" rows="5" placeholder="خدمة 1&#10;خدمة 2&#10;خدمة 3" required></textarea>
        </div>
        <div>
          <label class="wa-label">تذييل (اختياري)</label>
          <input type="text" name="list_footer" class="wa-input" placeholder="njaz.net">
        </div>
      </div>

    <?php elseif($type==='location'): ?>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">خط العرض (Latitude) <span style="color:#ff4455">*</span></label>
          <input type="text" name="loc_lat" class="wa-input" placeholder="24.121231" dir="ltr" required>
        </div>
        <div>
          <label class="wa-label">خط الطول (Longitude) <span style="color:#ff4455">*</span></label>
          <input type="text" name="loc_lng" class="wa-input" placeholder="55.112122" dir="ltr" required>
        </div>
      </div>
      <div style="margin-top:8px;font-size:.75rem;color:var(--text3)">
        <i class="fas fa-info-circle"></i>
        للحصول على الإحداثيات: افتح Google Maps ← انقر على الموقع ← انسخ الأرقام من أسفل الشاشة
      </div>

    <?php elseif($type==='vcard'): ?>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">الاسم <span style="color:#ff4455">*</span></label>
          <input type="text" name="vc_name" class="wa-input" placeholder="نجاز كارد" required>
        </div>
        <div>
          <label class="wa-label">رقم الهاتف <span style="color:#ff4455">*</span></label>
          <input type="text" name="vc_phone" class="wa-input" placeholder="967778123456" dir="ltr" required>
        </div>
      </div>

    <?php elseif($type==='sticker'): ?>
      <div>
        <label class="wa-label">رابط الملصق (رابط مباشر .webp) <span style="color:#ff4455">*</span></label>
        <input type="url" name="sticker_url" id="sticker_url_input" class="wa-input" placeholder="https://example.com/sticker.webp" dir="ltr">
        <div style="margin-top:6px;display:flex;align-items:center;gap:8px">
          <span style="font-size:.75rem;color:var(--text3)">أو</span>
          <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;background:#1e2940;border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-size:.8rem;font-weight:700" onmouseover="this.style.background='#253352'" onmouseout="this.style.background='#1e2940'">
            <i class="fas fa-upload" style="color:#ec4899"></i> رفع ملصق
            <input type="file" style="display:none" accept="image/webp,image/*" onchange="uploadGenericFile(this,'sticker_url_input','sticker_upload_status')">
          </label>
          <span id="sticker_upload_status" style="font-size:.75rem;color:var(--text3)"></span>
        </div>
      </div>
    <?php endif; ?>

    <div style="margin-top:14px">
      <button type="submit" class="wa-btn" style="background:<?= $color ?>;color:#fff">
        <i class="fas fa-paper-plane"></i> إرسال <?= $title ?>
      </button>
    </div>
  </form>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php /* ══════════════════════════════════════════
   TAB: SETTINGS
══════════════════════════════════════════ */ elseif($tab==='settings'): ?>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-cog" style="color:#25d366"></i> إعدادات HetaCloud API</div>
  <form method="POST">
    <input type="hidden" name="save_settings" value="1">
    <div class="wa-form-grid">
      <div>
        <label class="wa-label">API Key *</label>
        <input type="text" name="api_key" class="wa-input" value="<?= htmlspecialchars($apiKey) ?>" placeholder="مفتاح HetaCloud API">
      </div>
      <div>
        <label class="wa-label">رقم الإرسال *</label>
        <input type="text" name="sender_number" class="wa-input" value="<?= htmlspecialchars($senderNumber) ?>" placeholder="967778123456">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">رمز الدولة + الرقم بدون + أو صفر</div>
      </div>
      <div>
        <label class="wa-label">Webhook Secret (اختياري)</label>
        <input type="text" name="webhook_secret" class="wa-input" value="<?= htmlspecialchars(waGet('webhook_secret')) ?>">
      </div>
      <div>
        <label class="wa-label">رابط Webhook</label>
        <div style="display:flex;gap:6px">
          <input type="text" class="wa-input" value="<?= SITE_URL ?>/register_webhook.php" readonly style="background:var(--bg);cursor:copy" onclick="this.select()">
          <button type="button" class="wa-btn wa-btn-outline" onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/register_webhook.php');showToast('✅ تم النسخ')"><i class="fas fa-copy"></i></button>
        </div>
      </div>
      <div>
        <label class="wa-label">رمز عامل طلبات سوا</label>
        <input type="text" name="relay_worker_token" class="wa-input" value="<?= htmlspecialchars(waGet('relay_worker_token')) ?>" placeholder="اتركه فارغاً لتوليد رمز عشوائي" dir="ltr">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">يُستخدم لحماية رابط التشغيل عبر المتصفح. التشغيل من Cron لا يحتاج الرمز.</div>
      </div>
    </div>
    <div style="margin-top:16px">
      <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-save"></i> حفظ إعدادات API</button>
    </div>
  </form>
</div>

<!-- ── وضع التنشيط الاحتياطي ─────────────────────────────── -->
<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-bolt" style="color:#f5a623"></i> وضع التنشيط الاحتياطي لاستقبال الرسائل</div>
  <div class="info-box yellow" style="margin-bottom:18px">
    <i class="fas fa-shield-alt" style="flex-shrink:0;margin-top:2px"></i>
        <div>عند تفعيله، يحجز الموقع إشارة قصيرة إلى مجموعة فارغة بعد حفظ أي رسالة واردة، ثم يرسلها العامل الخلفي بعد التأخير المحدد. هذا وضع احتياطي لجلسة HetaCloud، وليس بديلاً عن إصلاح المزود. اختر مجموعة فارغة لا تُستخدم للطلبات.</div>
  </div>
  <form method="POST">
    <input type="hidden" name="save_settings" value="1">
    <div class="wa-form-grid">
      <div>
        <label class="wa-label">حالة الوضع</label>
        <select name="activation_enabled" class="wa-input">
          <option value="0" <?= waGet('activation_enabled') === '1' ? '' : 'selected' ?>>متوقف — الوضع العادي</option>
          <option value="1" <?= waGet('activation_enabled') === '1' ? 'selected' : '' ?>>مفعّل — إرسال إشارة التنشيط</option>
        </select>
      </div>
      <div>
        <label class="wa-label">معرّف مجموعة التنشيط *</label>
        <input type="text" name="activation_group_id" class="wa-input" list="waActivationGroups" dir="ltr"
          value="<?= htmlspecialchars((string)waGet('activation_group_id'), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="120363...@g.us">
        <datalist id="waActivationGroups">
          <?php foreach ($waGroups as $activationGroup): if (!empty($activationGroup['is_active'])): ?>
            <option value="<?= htmlspecialchars((string)$activationGroup['group_id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$activationGroup['group_name'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endif; endforeach; ?>
        </datalist>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">يجب أن يكون المعرّف لمجموعة فارغة وينتهي بـ @g.us.</div>
      </div>
      <div>
        <label class="wa-label">نص إشارة التنشيط</label>
        <input type="text" name="activation_message" class="wa-input" maxlength="80"
          value="<?= htmlspecialchars((string)(waGet('activation_message') ?: 'تنشيط'), ENT_QUOTES, 'UTF-8') ?>">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">لا تضع هنا نص طلب أو بيانات عميل. يُفضّل إبقاؤه كلمة قصيرة.</div>
      </div>
      <div>
        <label class="wa-label">تأخير الإرسال بعد آخر رسالة واردة (ثانية)</label>
        <input type="number" name="activation_delay_seconds" class="wa-input" min="1" max="300"
          value="<?= (int)(waGet('activation_delay_seconds') ?: (waGet('activation_cooldown') ?: 15)) ?>">
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">يُعاد تأجيل المهمة عند وصول رسالة جديدة قبل الإرسال، لتقليل الضغط. مع Cron كل دقيقة قد يتم الإرسال بعد الموعد بما يصل إلى دورة Cron إضافية تقريباً؛ هذا ليس توقيتاً دقيقاً بالثانية.</div>
      </div>
    </div>
    <div style="margin-top:16px">
      <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-save"></i> حفظ إعدادات التنشيط</button>
    </div>
  </form>
</div>

<!-- ── إعدادات التأخير الذكي ─────────────────────────────── -->
<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-clock" style="color:#f5a623"></i> إعدادات التأخير الذكي بين الرسائل</div>
  <div class="info-box yellow" style="margin-bottom:18px">
    <i class="fas fa-shield-alt" style="flex-shrink:0;margin-top:2px"></i>
    <div>التأخير العشوائي يحمي الحساب من الحظر — يختار النظام وقتاً عشوائياً بين الحد الأدنى والأعلى لكل رسالة، ويتوقف توقفاً أطول بعد كل مجموعة رسائل.</div>
  </div>
  <form method="POST">
    <input type="hidden" name="save_settings" value="1">


    <div style="margin-bottom:20px">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:12px;color:var(--text2);display:flex;align-items:center;gap:6px">
        <i class="fas fa-paper-plane" style="color:#25d366;font-size:.8rem"></i>
        التأخير بين كل رسالة والتالية
      </div>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">الحد الأدنى (ثانية) — 1 إلى 60</label>
          <input type="number" name="delay_min" class="wa-input" min="1" max="60"
            value="<?= (int)(waGet('delay_min') ?: 3) ?>" oninput="updateDelayPreview()">
        </div>
        <div>
          <label class="wa-label">الحد الأعلى (ثانية) — 1 إلى 60</label>
          <input type="number" name="delay_max" class="wa-input" min="1" max="60"
            value="<?= (int)(waGet('delay_max') ?: 7) ?>" oninput="updateDelayPreview()">
        </div>
      </div>
      <div id="delayPreview" style="font-size:.78rem;color:#25d366;margin-top:-6px">
        <!-- يُحدَّث بـ JS -->
      </div>
    </div>

    <div style="border-top:1px solid var(--border);padding-top:20px;margin-bottom:20px">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:12px;color:var(--text2);display:flex;align-items:center;gap:6px">
        <i class="fas fa-layer-group" style="color:#a78bfa;font-size:.8rem"></i>
        توقف طويل بعد كل مجموعة رسائل (Batch)
      </div>
      <div class="wa-form-grid">
        <div>
          <label class="wa-label">حجم المجموعة (عدد الرسائل) — 1 إلى 100</label>
          <input type="number" name="batch_size" class="wa-input" min="1" max="100"
            value="<?= (int)(waGet('batch_size') ?: 10) ?>" oninput="updateDelayPreview()">
        </div>
        <div>
          <!-- spacer -->
        </div>
        <div>
          <label class="wa-label">مدة التوقف الأدنى (ثانية) — 1 إلى 300</label>
          <input type="number" name="batch_pause_min" class="wa-input" min="1" max="300"
            value="<?= (int)(waGet('batch_pause_min') ?: 15) ?>" oninput="updateDelayPreview()">
        </div>
        <div>
          <label class="wa-label">مدة التوقف الأعلى (ثانية) — 1 إلى 300</label>
          <input type="number" name="batch_pause_max" class="wa-input" min="1" max="300"
            value="<?= (int)(waGet('batch_pause_max') ?: 45) ?>" oninput="updateDelayPreview()">
        </div>
      </div>
      <div id="batchPreview" style="font-size:.78rem;color:#a78bfa;margin-top:-6px">
        <!-- يُحدَّث بـ JS -->
      </div>
    </div>

    <!-- معاينة حية -->
    <div id="timingSimBox" style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:18px;font-size:.8rem;display:flex;flex-wrap:wrap;gap:14px">
      <div>
        <div style="color:var(--text3);margin-bottom:3px">مثال: إرسال 30 رسالة</div>
        <div id="simResult" style="color:#f5a623;font-weight:700">—</div>
      </div>
      <div>
        <div style="color:var(--text3);margin-bottom:3px">أسرع وقت ممكن</div>
        <div id="simMin" style="color:#25d366;font-weight:700">—</div>
      </div>
      <div>
        <div style="color:var(--text3);margin-bottom:3px">أبطأ وقت ممكن</div>
        <div id="simMax" style="color:#ff4455;font-weight:700">—</div>
      </div>
    </div>

    <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-save"></i> حفظ إعدادات التأخير</button>
  </form>
</div>

<?php /* ══════════════════════════════════════════
   TAB: SAWA RELAY
══════════════════════════════════════════ */ elseif($tab==='sawa'): ?>

<?php
  $sawaForm = $sawaEdit ?: [];
  $sawaSource = (string)($sawaForm['source_group_id'] ?? '');
  $sawaTarget = (string)($sawaForm['target_group_id'] ?? '');
  $sawaLengths = (string)($sawaForm['digit_lengths'] ?? '14');
  $sawaReplyKeyword = (string)($sawaForm['reply_keyword'] ?? 'تم');
  $sawaRequestTemplate = (string)($sawaForm['request_template'] ?? $sawaDefaultRequestTemplate);
  $sawaResultTemplate = (string)($sawaForm['result_template'] ?? $sawaDefaultResultTemplate);
  $sawaReplyInstructionTemplate = (string)($sawaForm['reply_instruction_template'] ?? "للرد بعد التنفيذ أرسل بإحدى الصيغتين:\n{reply_keyword} #{id} المبلغ\nرفض #{id} (والسبب اختياري)");
  $sawaSourceFormatTemplate = (string)($sawaForm['source_format_template'] ?? "يرجى كتابة رقم البطاقة بشكل صحيح.\nالصيغة: اسم البطاقة ثم مسافة ثم الرقم.\nمثال: {prefix} 12345678901234");
  $sawaSourceShortTemplate = (string)($sawaForm['source_short_template'] ?? "رقم البطاقة {code} ناقص؛ أرسل {minimum_length} رقماً على الأقل.");
  $sawaSourcePendingTemplate = (string)($sawaForm['source_pending_template'] ?? "رقم هذه البطاقة {code} ما زال معلقاً، نرجو عدم التقديم مرة أخرى.");
  $sawaSourceDuplicateTemplate = (string)($sawaForm['source_duplicate_template'] ?? "هذه البطاقة {code} مكررة وتم إرسالها إلى المزود سابقاً، ولن يعاد إرسالها.");
  $sawaSourceForwardTemplate = (string)($sawaForm['source_forward_template'] ?? "تم إرسال رقم بطاقتك {code} إلى المزود.\nرقم الطلب: #{id}\nيرجى انتظار رد المزود.");
  $sawaRejectResultTemplate = (string)($sawaForm['reject_result_template'] ?? "تم رفض طلب سوا #{id}: رقم البطاقة مستخدم مسبقاً أو غير صحيح.");
  $sawaTargetAcceptConfirmationTemplate = (string)($sawaForm['target_accept_confirmation_template'] ?? "تم إرسال رسالتك بالموافقة إلى مجموعة المصدر للطلب #{id}.");
  $sawaTargetRejectConfirmationTemplate = (string)($sawaForm['target_reject_confirmation_template'] ?? "تم إرسال رسالتك بالرفض إلى مجموعة المصدر للطلب #{id}.");
  $sawaReminderTemplate = (string)($sawaForm['reminder_template'] ?? $sawaDefaultReminderTemplate);
  $sawaReminderInterval = (int)($sawaForm['reminder_interval_min'] ?? 10);
  if (!in_array($sawaReminderInterval, [5, 10, 20], true)) $sawaReminderInterval = 10;
?>
<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-exchange-alt" style="color:#f5a623"></i> إعدادات طلبات سوا</div>
  <div class="info-box yellow" style="margin-bottom:18px">
    <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:2px"></i>
    <div>يراقب النظام رسائل المجموعة المصدر فقط. عند مطابقة كلمة البداية وطول الرقم، يرسل الطلب إلى مجموعة التنفيذ. يعتمد أول رد صحيح بصيغة <b>كلمة الرد #رقم الطلب المبلغ</b> أو <b>رفض #رقم الطلب</b>، ثم يرسل النتيجة إلى المجموعة المصدر.</div>
  </div>
  <form method="POST">
    <input type="hidden" name="save_sawa_rule" value="1">
    <input type="hidden" name="sawa_rule_id" value="<?= (int)($sawaForm['id'] ?? 0) ?>">
    <div class="wa-form-grid">
      <div>
        <label class="wa-label">اسم القاعدة</label>
        <input type="text" name="sawa_name" class="wa-input" value="<?= htmlspecialchars((string)($sawaForm['name'] ?? 'طلبات سوا')) ?>" maxlength="100" required>
      </div>
      <div>
        <label class="wa-label">كلمة بداية الطلب</label>
        <input type="text" name="sawa_trigger_prefix" class="wa-input" value="<?= htmlspecialchars((string)($sawaForm['trigger_prefix'] ?? 'سوا')) ?>" maxlength="50" required>
      </div>
      <div>
        <label class="wa-label">كلمة رد المنفذ</label>
        <input type="text" name="sawa_reply_keyword" class="wa-input" value="<?= htmlspecialchars($sawaReplyKeyword) ?>" maxlength="50" required>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">مثال: موافق #رقم الطلب 500 — وتبقى صيغة الرفض: رفض #رقم الطلب.</div>
      </div>
      <div>
        <label class="wa-label">مجموعة استقبال الطلبات</label>
        <select name="sawa_source_group_id" class="wa-input" required>
          <option value="">اختر المجموعة</option>
          <?php foreach($waGroups as $g): ?><option value="<?= htmlspecialchars($g['group_id']) ?>" <?= $sawaSource===(string)$g['group_id']?'selected':'' ?>><?= htmlspecialchars($g['group_name'].' — '.$g['group_id']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="wa-label">مجموعة التنفيذ</label>
        <select name="sawa_target_group_id" class="wa-input" required>
          <option value="">اختر المجموعة</option>
          <?php foreach($waGroups as $g): ?><option value="<?= htmlspecialchars($g['group_id']) ?>" <?= $sawaTarget===(string)$g['group_id']?'selected':'' ?>><?= htmlspecialchars($g['group_name'].' — '.$g['group_id']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="wa-label">أطوال الرقم المقبولة</label>
        <input type="text" name="sawa_digit_lengths" class="wa-input" value="<?= htmlspecialchars($sawaLengths) ?>" placeholder="14 أو 14,15" dir="ltr" required>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">اكتب طولاً واحداً أو عدة أطوال مفصولة بفاصلة.</div>
      </div>
      <div>
        <label class="wa-label">الحد الأدنى لمبلغ الرد</label>
        <input type="number" name="sawa_reply_min_amount" class="wa-input" min="0" max="1000000" step="0.01" value="<?= htmlspecialchars((string)($sawaForm['reply_min_amount'] ?? '0')) ?>" required>
      </div>
      <div>
        <label class="wa-label">الحد الأقصى لمبلغ الرد</label>
        <input type="number" name="sawa_reply_max_amount" class="wa-input" min="0" max="1000000" step="0.01" value="<?= htmlspecialchars((string)($sawaForm['reply_max_amount'] ?? '1000')) ?>" required>
      </div>
      <div>
        <label class="wa-label">مهلة الرد بالدقائق</label>
        <input type="number" name="sawa_reply_timeout_min" class="wa-input" min="1" max="10080" value="<?= (int)($sawaForm['reply_timeout_min'] ?? 30) ?>" required>
      </div>
      <div>
        <label class="wa-label">فاصل التذكير</label>
        <select name="sawa_reminder_interval_min" class="wa-input">
          <option value="5" <?= $sawaReminderInterval===5?'selected':'' ?>>كل 5 دقائق</option>
          <option value="10" <?= $sawaReminderInterval===10?'selected':'' ?>>كل 10 دقائق</option>
          <option value="20" <?= $sawaReminderInterval===20?'selected':'' ?>>كل 20 دقيقة</option>
        </select>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">يعمل الفاصل في أول دورة Cron بعد استحقاقه، وقد يتأخر حتى قرابة دقيقة.</div>
      </div>
    </div>
    <div style="margin-top:14px">
      <label class="wa-label">قالب رسالة مجموعة التنفيذ</label>
      <textarea name="sawa_request_template" class="wa-input" rows="5" required><?= htmlspecialchars($sawaRequestTemplate) ?></textarea>
      <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {status}، {source_group}، {target_group}</div>
    </div>
    <div style="margin-top:14px">
      <label class="wa-label">قالب رسالة النتيجة للمجموعة المصدر عند الموافقة</label>
      <textarea name="sawa_result_template" class="wa-input" rows="6" required><?= htmlspecialchars($sawaResultTemplate) ?></textarea>
      <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {amount}، {executor_name}، {executor_number}، {status}</div>
    </div>

    <div class="wa-card" style="margin:18px 0 0;padding:14px;background:var(--bg2,rgba(0,0,0,.03));border:1px solid var(--border)">
      <div class="wa-card-title" style="font-size:1rem"><i class="fas fa-comment-dots" style="color:#25d366"></i> مربعات الردود والتنبيهات</div>
      <div class="info-box" style="margin-bottom:12px">اترك أي مربع فارغاً لاستخدام النص الافتراضي. المتغيرات المتاحة موضحة أسفل كل مربع.</div>
      <label style="display:flex;align-items:center;gap:8px;margin:10px 0 14px;cursor:pointer">
        <input type="checkbox" name="sawa_reminder_enabled" value="1" <?= (int)($sawaForm['reminder_enabled'] ?? 0)===1 ? 'checked' : '' ?> style="accent-color:#25d366;width:17px;height:17px">
        <span><b>تفعيل التذكير الدوري لمجموعة التنفيذ</b></span>
      </label>
      <div style="margin-top:12px">
        <label class="wa-label">قالب التذكير الدوري لمجموعة التنفيذ</label>
        <textarea name="sawa_reminder_template" class="wa-input" rows="7"><?= htmlspecialchars($sawaReminderTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {remaining_time}، {remaining_minutes}، {deadline}، {reply_keyword}، {reminder_count}. لا يُكتب رقم البطاقة الكامل في سجل العامل الداخلي.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">تعليمات الرد داخل رسالة مجموعة التنفيذ</label>
        <textarea name="sawa_reply_instruction_template" class="wa-input" rows="4"><?= htmlspecialchars($sawaReplyInstructionTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {reply_keyword}. يجب أن تبقى صيغتا {reply_keyword} #{id} المبلغ ورفض #{id} واضحة.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">رسالة صيغة البطاقة غير الصحيحة للمصدر</label>
        <textarea name="sawa_source_format_template" class="wa-input" rows="4"><?= htmlspecialchars($sawaSourceFormatTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {prefix}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">رسالة البطاقة الناقصة للمصدر</label>
        <textarea name="sawa_source_short_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaSourceShortTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {code}، {minimum_length}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">رسالة البطاقة المعلقة للمصدر</label>
        <textarea name="sawa_source_pending_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaSourcePendingTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {code}، {id}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">رسالة البطاقة المكررة للمصدر</label>
        <textarea name="sawa_source_duplicate_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaSourceDuplicateTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {code}، {id}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">تأكيد إرسال البطاقة إلى مجموعة التنفيذ للمصدر</label>
        <textarea name="sawa_source_forward_template" class="wa-input" rows="4"><?= htmlspecialchars($sawaSourceForwardTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {target_group}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">نتيجة الرفض المرسلة إلى المجموعة المصدر</label>
        <textarea name="sawa_reject_result_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaRejectResultTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {status}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">تأكيد الموافقة المرسل إلى مجموعة التنفيذ</label>
        <textarea name="sawa_target_accept_confirmation_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaTargetAcceptConfirmationTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}، {amount}.</div>
      </div>
      <div style="margin-top:12px">
        <label class="wa-label">تأكيد الرفض المرسل إلى مجموعة التنفيذ</label>
        <textarea name="sawa_target_reject_confirmation_template" class="wa-input" rows="3"><?= htmlspecialchars($sawaTargetRejectConfirmationTemplate) ?></textarea>
        <div style="font-size:.72rem;color:var(--text3);margin-top:4px">المتغيرات: {id}، {code}.</div>
      </div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;margin-top:16px;cursor:pointer">
      <input type="checkbox" name="sawa_is_active" value="1" <?= !isset($sawaForm['is_active']) || (int)$sawaForm['is_active']===1 ? 'checked' : '' ?> style="accent-color:#25d366;width:17px;height:17px">
      <span>تفعيل هذه القاعدة</span>
    </label>
    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
      <button type="submit" class="wa-btn wa-btn-green"><i class="fas fa-save"></i> <?= $sawaEdit ? 'حفظ التعديل' : 'إضافة قاعدة' ?></button>
      <?php if($sawaEdit): ?><a href="?tab=sawa" class="wa-btn wa-btn-outline">إلغاء التعديل</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-list"></i> قواعد طلبات سوا</div>
  <?php if(!$sawaRules): ?>
    <div class="info-box">لا توجد قواعد حتى الآن. أضف قاعدة وحدد مجموعتي الاستقبال والتنفيذ.</div>
  <?php else: ?>
    <div style="overflow:auto"><table class="wa-table"><thead><tr><th>القاعدة</th><th>الاستقبال</th><th>التنفيذ</th><th>الشروط</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
    <?php foreach($sawaRules as $r): ?>
      <tr>
        <td><b><?= htmlspecialchars($r['name']) ?></b><div style="font-size:.72rem;color:var(--text3)"><?= htmlspecialchars($r['trigger_prefix']) ?></div></td>
        <td><?= htmlspecialchars($waGroupNames[(string)$r['source_group_id']] ?? $r['source_group_id']) ?><div style="font-size:.68rem;color:var(--text3)"><?= htmlspecialchars($r['source_group_id']) ?></div></td>
        <td><?= htmlspecialchars($waGroupNames[(string)$r['target_group_id']] ?? $r['target_group_id']) ?><div style="font-size:.68rem;color:var(--text3)"><?= htmlspecialchars($r['target_group_id']) ?></div></td>
        <td><?= htmlspecialchars($r['digit_lengths']) ?><br><span style="font-size:.72rem">من <?= htmlspecialchars($r['reply_min_amount']) ?> إلى <?= htmlspecialchars($r['reply_max_amount']) ?></span></td>
        <td><span class="status-badge <?= (int)$r['is_active']?'success':'muted' ?>"><?= (int)$r['is_active']?'مفعلة':'متوقفة' ?></span></td>
        <td style="white-space:nowrap"><a class="wa-btn wa-btn-outline" href="?tab=sawa&edit_sawa=<?= (int)$r['id'] ?>"><i class="fas fa-edit"></i></a> <form method="POST" style="display:inline" onsubmit="return confirm('حذف قاعدة طلبات سوا؟')"><input type="hidden" name="delete_sawa_rule" value="1"><input type="hidden" name="sawa_rule_id" value="<?= (int)$r['id'] ?>"><button class="wa-btn" style="background:#8f2435;color:#fff" type="submit"><i class="fas fa-trash"></i></button></form></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<div class="wa-card">
  <div class="wa-card-title"><i class="fas fa-history" style="color:#a78bfa"></i> سجل طلبات سوا — آخر 100 عملية</div>
  <?php if(!$sawaLogs): ?><div class="info-box">لا توجد عمليات مسجلة بعد.</div><?php else: ?>
  <div style="overflow:auto"><table class="wa-table"><thead><tr><th>#</th><th>الرقم</th><th>الحالة</th><th>المبلغ</th><th>المنفذ</th><th>الرسائل</th><th>التواريخ</th><th>الخطأ</th></tr></thead><tbody>
  <?php foreach($sawaLogs as $l):
    $statusLabels=['dispatching'=>'قيد الإرسال','waiting_reply'=>'قيد التنفيذ','in_progress'=>'قيد التنفيذ','completed'=>'تم التنفيذ','expired'=>'منتهي','rejected'=>'مرفوض','error'=>'خطأ'];
  ?>
    <tr><td>#<?= (int)$l['id'] ?><div style="font-size:.68rem;color:var(--text3)"><?= htmlspecialchars($l['rule_name'] ?? '') ?></div></td><td><b><?= htmlspecialchars($l['extracted_code']) ?></b><div style="font-size:.68rem;color:var(--text3)"><?= htmlspecialchars((string)$l['source_message_id']) ?></div></td><td><?= htmlspecialchars($statusLabels[$l['status']] ?? $l['status']) ?></td><td><?= htmlspecialchars((string)($l['reply_amount'] ?? '—')) ?></td><td><?= htmlspecialchars(trim(($l['executor_name'] ?? '').' '.($l['executor_number'] ?? '')) ?: '—') ?></td><td style="font-size:.7rem;direction:ltr;text-align:left">src: <?= htmlspecialchars((string)$l['source_message_id']) ?><br>fwd: <?= htmlspecialchars((string)$l['forwarded_message_id']) ?><br>reply: <?= htmlspecialchars((string)$l['reply_message_id']) ?><br>result: <?= htmlspecialchars((string)$l['result_message_id']) ?></td><td style="font-size:.72rem"><?= htmlspecialchars((string)$l['created_at']) ?><br><?= htmlspecialchars((string)($l['completed_at'] ?? '')) ?></td><td style="max-width:220px;white-space:normal;color:#ff9aa8;font-size:.72rem"><?= htmlspecialchars((string)($l['error_message'] ?? '')) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<?php endif; ?>
</div><!-- /wa-wrap -->

<script>
// ── تبديل نوع المستلمين (bulk) ────────────────────────────
function selR(type,el){
  document.querySelectorAll('.wa-rtab').forEach(t=>t.classList.remove('sel'));
  el.classList.add('sel');
  document.getElementById('rt').value=type;
  document.querySelectorAll('.wa-phone-area').forEach(a=>a.classList.remove('show'));
  const a=document.getElementById('area_'+type);
  if(a)a.classList.add('show');
}

// ── تحديد المجموعات ───────────────────────────────────────
let selectedGroups = new Set();

function updateSelected(){
  selectedGroups.clear();
  document.querySelectorAll('.group-cb:checked').forEach(cb=>selectedGroups.add(cb.value));
  const n = selectedGroups.size;
  document.getElementById('selectedCount').textContent = n;
  document.getElementById('selBarCount').textContent   = n + ' محدد';

  // تحديث الـ hidden inputs
  const container = document.getElementById('hiddenGroupInputs');
  container.innerHTML='';
  selectedGroups.forEach(id=>{
    const inp=document.createElement('input');
    inp.type='hidden'; inp.name='selected_groups[]'; inp.value=id;
    container.appendChild(inp);
  });

  // تحديث عنوان send panel
  const sp=document.getElementById('sendPanelCount');
  if(sp) sp.textContent = n>0 ? `(${n} مجموعات محددة)` : '';
}

function selectAll(masterCb){
  document.querySelectorAll('.group-cb:not(:disabled)').forEach(cb=>cb.checked=masterCb.checked);
  updateSelected();
}

function toggleSendAll(cb){
  document.querySelectorAll('.group-cb').forEach(c=>c.disabled=cb.checked);
  document.getElementById('selectAllGroups') && (document.getElementById('selectAllGroups').disabled=cb.checked);
  updateSelected();
}

// ── فتح/إغلاق لوحة الإرسال ───────────────────────────────
function toggleSendPanel(){
  const panel=document.getElementById('sendPanel');
  if(!panel) return;
  panel.classList.toggle('show');
  if(panel.classList.contains('show')){
    panel.scrollIntoView({behavior:'smooth',block:'center'});
    updateSelected();
  }
}

// ── مزامنة المجموعات ──────────────────────────────────────
function syncGroups(btn){
  btn.disabled=true;
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جارٍ المزامنة...';
  fetch('?action=fetch_groups')
    .then(r=>r.json())
    .then(d=>{
      if(d.ok){
        showToast('✅ '+d.msg,'success');
        setTimeout(()=>location.reload(),1200);
      } else {
        showToast('⚠️ '+d.msg,'warning');
        if(d.manual){
          document.getElementById('addGroupModal').style.display='flex';
        }
      }
    })
    .catch(()=>showToast('خطأ في الاتصال','error'))
    .finally(()=>{
      btn.disabled=false;
      btn.innerHTML='<i class="fas fa-sync-alt"></i> مزامنة المجموعات';
    });
}

// ── تبديل حالة مجموعة ────────────────────────────────────
function toggleGroup(id){
  fetch(`?action=toggle_group&id=${id}`)
    .then(r=>r.json())
    .then(d=>{
      if(d.ok){
        const card=document.getElementById('gcard_'+id);
        if(card) card.classList.toggle('inactive');
        showToast('تم التحديث','success');
      }
    });
}

// ── حذف مجموعة ───────────────────────────────────────────
function deleteGroup(id){
  if(!confirm('حذف هذه المجموعة من القائمة؟')) return;
  fetch(`?action=delete_group&id=${id}`)
    .then(r=>r.json())
    .then(d=>{ if(d.ok){ document.getElementById('gcard_'+id)?.remove(); showToast('تم الحذف','success'); }});
}

// ── نسخ ID ───────────────────────────────────────────────
function copyId(id){
  navigator.clipboard.writeText(id);
  showToast('✅ تم نسخ: '+id,'success');
}

// ── إغلاق modal بالنقر خارجه ─────────────────────────────
document.getElementById('addGroupModal')?.addEventListener('click',function(e){
  if(e.target===this) this.style.display='none';
});

// ── Toast ────────────────────────────────────────────────
function showToast(msg,type='success'){
  const colors={success:'#25d366',error:'#ff4455',warning:'#f5a623'};
  const t=document.createElement('div');
  t.style.cssText=`position:fixed;bottom:24px;left:24px;background:${colors[type]||colors.success};color:#fff;padding:12px 20px;border-radius:10px;font-size:.85rem;font-weight:700;z-index:99999;box-shadow:0 8px 24px rgba(0,0,0,.4)`;
  t.textContent=msg;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

// ── Character counter ────────────────────────────────────
document.querySelectorAll('textarea').forEach(ta=>{
  const c=document.createElement('div');
  c.style.cssText='font-size:.72rem;color:var(--text3);text-align:left;margin-top:3px';
  ta.parentNode.appendChild(c);
  ta.addEventListener('input',()=>{ c.textContent=ta.value.length+' حرف'; });
});// ── اكتشاف المجموعات من الرسائل الواردة ─────────────────
let discoveredGroups = [];
let discoverRefreshTimer = null;
let discoverRequestInFlight = false;

function closeDiscoverModal() {
  const modal = document.getElementById('discoverModal');
  if (modal) modal.style.display = 'none';
  if (discoverRefreshTimer) {
    clearTimeout(discoverRefreshTimer);
    discoverRefreshTimer = null;
  }
}

function scheduleDiscoverRefresh() {
  if (discoverRefreshTimer) clearTimeout(discoverRefreshTimer);
  const modal = document.getElementById('discoverModal');
  if (!modal || modal.style.display !== 'flex') return;
  discoverRefreshTimer = setTimeout(() => refreshDiscoveredGroups(false), 30000);
}

function discoverRequest() {
  return fetch('?action=discover_groups&_ts=' + Date.now(), {
    cache: 'no-store',
    headers: {'X-Requested-With': 'XMLHttpRequest'}
  }).then(r => r.json());
}

function refreshDiscoveredGroups(manual = false) {
  if (discoverRequestInFlight) return;
  discoverRequestInFlight = true;
  const status = document.getElementById('discoverRefreshStatus');
  if (status && manual) status.textContent = 'جارٍ تحديث الرسائل...';

  discoverRequest()
    .then(d => {
      if (!d.ok) throw new Error(d.msg || 'فشل تحديث الرسائل');
      const rowsById = new Map();
      document.querySelectorAll('#discoverTableBody tr[data-group-id]').forEach(row => rowsById.set(row.dataset.groupId, row));
      discoveredGroups = d.groups || [];

      discoveredGroups.forEach(g => {
        const row = rowsById.get(String(g.group_id || ''));
        if (!row) return; // المجموعة الجديدة تظهر عند الضغط على تحديث الاكتشاف الكامل، دون مسح تحديد المستخدم الحالي.
        const msgCell = row.querySelector('.discover-last-message');
        const dateCell = row.querySelector('.discover-last-date');
        const countCell = row.querySelector('.discover-msg-count');
        if (msgCell) {
          msgCell.title = g.last_message || '—';
          msgCell.innerHTML = g.last_message
            ? discoverEsc(g.last_message).replace(/\n/g, '<br>')
            : '<span style="color:var(--text3)">—</span>';
        }
        if (dateCell) dateCell.textContent = discoverDate(g.last_message_at || g.last_seen || '');
        if (countCell) countCell.textContent = Number(g.msg_count || 0).toLocaleString('ar-EG');
      });

      if (status) {
        const now = new Date();
        status.textContent = 'آخر تحديث: ' + now.toLocaleTimeString('ar-EG', {hour:'2-digit', minute:'2-digit'});
      }
    })
    .catch(e => {
      if (status && manual) status.textContent = 'تعذر التحديث — حاول مرة أخرى';
      if (manual) showToast('❌ ' + (e.message || 'تعذر تحديث الرسائل'), 'error');
    })
    .finally(() => {
      discoverRequestInFlight = false;
      scheduleDiscoverRefresh();
    });
}

function discoverEsc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function discoverDate(value) {
  const raw = String(value ?? '');
  const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  return match ? `${match[1]}/${match[2]}/${match[3]} ${match[4]}:${match[5]}` : (raw || '—');
}

function discoverGroups() {
  const modal = document.getElementById('discoverModal');
  modal.style.display = 'flex';
  document.getElementById('discoverLoading').style.display = 'block';
  document.getElementById('discoverList').style.display   = 'none';
  document.getElementById('discoverActions').style.display = 'none';

  discoverRequest()
    .then(d => {
      document.getElementById('discoverLoading').style.display = 'none';
      if (!d.ok) { showToast('❌ ' + (d.msg || 'خطأ في الاكتشاف'), 'error'); scheduleDiscoverRefresh(); return; }

      discoveredGroups = d.groups || [];
      const tbody = document.getElementById('discoverTableBody');
      tbody.innerHTML = '';

      if (discoveredGroups.length === 0) {
        document.getElementById('discoverEmpty').style.display = 'block';
        document.getElementById('discoverTable').style.display = 'none';
        document.getElementById('discoverList').style.display  = 'block';
        scheduleDiscoverRefresh();
        return;
      }

      document.getElementById('discoverEmpty').style.display  = 'none';
      document.getElementById('discoverTable').style.display  = '';
      document.getElementById('discoverList').style.display   = 'block';
      document.getElementById('discoverActions').style.display = 'flex';

      discoveredGroups.forEach((g, i) => {
        const already = g.already_added;
        const tr = document.createElement('tr');
        tr.dataset.groupId = String(g.group_id || '');
        tr.style.borderBottom = '1px solid var(--border)';
        tr.innerHTML = `
          <td style="padding:8px 6px;text-align:center">
            <input type="checkbox" class="discover-cb" data-idx="${i}"
              ${already ? 'checked disabled' : ''}
              onchange="updateDiscoverCount()"
              style="accent-color:#3b82f6;width:15px;height:15px">
          </td>
          <td style="padding:8px 6px;font-size:.72rem;direction:ltr;color:var(--text3);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
              title="${discoverEsc(g.group_id)}">${discoverEsc(g.group_id)}</td>
          <td style="padding:5px 6px">
            <input type="text" class="discover-name wa-input" data-idx="${i}"
              value="${discoverEsc(g.group_name)}"
              style="padding:5px 10px;font-size:.8rem;width:100%"
              ${already ? 'disabled' : ''}>
          </td>
          <td class="discover-last-message" style="padding:8px 6px;max-width:260px;color:var(--text1);font-size:.78rem;line-height:1.6;word-break:break-word" title="${discoverEsc(g.last_message || '—')}">
            ${g.last_message ? discoverEsc(g.last_message).replace(/\n/g, '<br>') : '<span style="color:var(--text3)">—</span>'}
          </td>
          <td class="discover-last-date" style="padding:8px 6px;text-align:center;color:var(--text3);font-size:.75rem;white-space:nowrap;direction:ltr">${discoverEsc(discoverDate(g.last_message_at || g.last_seen || ''))}</td>
          <td class="discover-msg-count" style="padding:8px 6px;text-align:center;color:var(--text3);font-size:.78rem">${Number(g.msg_count || 0).toLocaleString('ar-EG')}</td>
          <td style="padding:8px 6px;text-align:center">
            ${already
              ? '<span class="badge badge-green" style="font-size:.7rem">✓ مضافة</span>'
              : '<span class="badge badge-blue" style="font-size:.7rem">جديدة</span>'}
          </td>`;
        tbody.appendChild(tr);
      });

      updateDiscoverCount();
      refreshDiscoveredGroups(false);
    })
    .catch(e => { showToast('خطأ في الاتصال', 'error'); console.error(e); scheduleDiscoverRefresh(); });
}

function discoverToggleAll(master) {
  document.querySelectorAll('.discover-cb:not(:disabled)').forEach(cb => cb.checked = master.checked);
  updateDiscoverCount();
}

function updateDiscoverCount() {
  const n = document.querySelectorAll('.discover-cb:checked:not(:disabled)').length;
  document.getElementById('discoverSelCount').textContent = n + ' محدد';
}

function importDiscovered() {
  const toImport = [];
  document.querySelectorAll('.discover-cb:checked:not(:disabled)').forEach(cb => {
    const idx = parseInt(cb.dataset.idx);
    const nameInput = document.querySelector(`.discover-name[data-idx="${idx}"]`);
    toImport.push({
      group_id:   discoveredGroups[idx].group_id,
      group_name: (nameInput ? nameInput.value.trim() : '') || discoveredGroups[idx].group_name,
    });
  });

  if (!toImport.length) { showToast('اختر مجموعة واحدة على الأقل', 'warning'); return; }

  fetch('?action=import_discovered', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify(toImport),
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      showToast(`✅ تم استيراد ${d.saved} مجموعة`, 'success');
      document.getElementById('discoverModal').style.display = 'none';
      setTimeout(() => location.reload(), 1300);
    } else {
      showToast('❌ فشل الاستيراد', 'error');
    }
  })
  .catch(() => showToast('خطأ في الاتصال', 'error'));
}

document.getElementById('discoverModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeDiscoverModal();
});

// ── مزامنة حقل المستلم لجميع نماذج أدوات الإرسال ──
function syncToolTo(form) {
  const globalTo = document.getElementById('tool_to_global');
  if (!globalTo || !globalTo.value.trim()) {
    alert('الرجاء إدخال رقم المستلم أو اختيار مجموعة أولاً');
    return false;
  }
  const hiddenTo = form.querySelector('[name=tool_to]');
  if (hiddenTo) hiddenTo.value = globalTo.value.trim();
}

// ── رفع ملف وسائط (مع معاينة وكشف نوع تلقائي) ──
async function uploadMediaFile(input) {
  const file = input.files[0];
  if (!file) return;
  const status = document.getElementById('media_upload_status');
  const urlInput = document.getElementById('media_url_input');
  const preview = document.getElementById('media_preview');
  const previewImg = document.getElementById('media_preview_img');
  const previewFile = document.getElementById('media_preview_file');
  const previewName = document.getElementById('media_preview_name');

  status.textContent = '⏳ جاري الرفع...';
  status.style.color = '#f5a623';

  const fd = new FormData();
  fd.append('file', file);

  try {
    const res = await fetch('?action=upload_media', { method: 'POST', body: fd });
    const d   = await res.json();
    if (d.ok) {
      urlInput.value = d.url;
      status.textContent = '✅ ' + d.name;
      status.style.color = '#25d366';

      // معاينة
      preview.style.display = 'block';
      if (d.mime.startsWith('image/')) {
        previewImg.src = d.url; previewImg.style.display = 'block';
        previewFile.style.display = 'none';
      } else {
        previewFile.style.display = 'block'; previewImg.style.display = 'none';
        previewName.textContent = d.name;
      }

      // كشف النوع تلقائياً
      autoDetectType(d.mime);
    } else {
      status.textContent = '❌ ' + d.msg;
      status.style.color = '#ff4455';
    }
  } catch(e) {
    status.textContent = '❌ خطأ في الاتصال';
    status.style.color = '#ff4455';
  }
}

function autoDetectType(mime) {
  const sel = document.getElementById('media_type_sel');
  if (!sel) return;
  if (!mime) {
    const url = document.getElementById('media_url_input')?.value || '';
    if (/\.(jpg|jpeg|png|gif|webp)$/i.test(url))      mime = 'image/jpeg';
    else if (/\.(mp4|3gp|mpeg)$/i.test(url))          mime = 'video/mp4';
    else if (/\.(mp3|ogg|wav|m4a)$/i.test(url))       mime = 'audio/mpeg';
    else if (/\.(pdf|doc|docx)$/i.test(url))           mime = 'application/pdf';
  }
  if (!mime) return;
  if (mime.startsWith('image/'))       sel.value = 'image';
  else if (mime.startsWith('video/'))  sel.value = 'video';
  else if (mime.startsWith('audio/'))  sel.value = 'audio';
  else                                  sel.value = 'document';
}

// ── رفع ملف عام (ملصق وغيره) ──
async function uploadGenericFile(input, targetId, statusId) {
  const file = input.files[0];
  if (!file) return;
  const status  = document.getElementById(statusId);
  const urlInput = document.getElementById(targetId);
  status.textContent = '⏳ جاري الرفع...';
  status.style.color = '#f5a623';

  const fd = new FormData();
  fd.append('file', file);
  try {
    const res = await fetch('?action=upload_media', { method: 'POST', body: fd });
    const d   = await res.json();
    if (d.ok) {
      urlInput.value = d.url;
      status.textContent = '✅ ' + d.name;
      status.style.color = '#25d366';
    } else {
      status.textContent = '❌ ' + d.msg;
      status.style.color = '#ff4455';
    }
  } catch(e) {
    status.textContent = '❌ خطأ في الاتصال';
    status.style.color = '#ff4455';
  }
}

// تعديل اسم مجموعة
function renameGroup(id, currentName) {
  const newName = prompt('اسم المجموعة الجديد:', currentName);
  if (!newName || !newName.trim() || newName.trim() === currentName) return;
  fetch(`?action=rename_group&id=${id}`, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'name=' + encodeURIComponent(newName.trim())
  })
  .then(r => r.json())
  .then(d => {
    if (d.ok) {
      const card = document.getElementById('gcard_' + id);
      if (card) {
        const nameEl = card.querySelector('.group-name');
        if (nameEl) { nameEl.textContent = newName.trim(); nameEl.title = newName.trim(); }
      }
      showToast('✅ تم تعديل الاسم', 'success');
    } else {
      showToast('❌ فشل التعديل', 'error');
    }
  })
  .catch(() => showToast('خطأ في الاتصال', 'error'));
}

// ── معاينة إعدادات التأخير ───────────────────────────────
function fmtSec(s) {
  if (s < 60) return s.toFixed(1) + ' ث';
  const m = Math.floor(s / 60), r = s % 60;
  return m + ' د' + (r > 0 ? ' ' + r.toFixed(0) + ' ث' : '');
}

function updateDelayPreview() {
  const dMin  = parseFloat(document.querySelector('[name=delay_min]')?.value  || 3);
  const dMax  = parseFloat(document.querySelector('[name=delay_max]')?.value  || 7);
  const bSize = parseInt(document.querySelector('[name=batch_size]')?.value   || 10);
  const bMin  = parseFloat(document.querySelector('[name=batch_pause_min]')?.value || 15);
  const bMax  = parseFloat(document.querySelector('[name=batch_pause_max]')?.value || 45);

  const dp = document.getElementById('delayPreview');
  const bp = document.getElementById('batchPreview');
  const sr = document.getElementById('simResult');
  const smn = document.getElementById('simMin');
  const smx = document.getElementById('simMax');

  if (dp) dp.textContent = `← النظام سيختار عشوائياً بين ${dMin} و ${dMax} ثانية بين كل رسالة`;
  if (bp) bp.textContent = `← بعد كل ${bSize} رسائل، يتوقف النظام بين ${bMin} و ${bMax} ثانية`;

  // محاكاة 30 رسالة
  const N = 30;
  const batches = Math.floor((N - 1) / bSize);
  const avgDelay = (dMin + dMax) / 2;
  const avgPause = (bMin + bMax) / 2;
  const avgTotal = (N - 1) * avgDelay + batches * avgPause;
  const minTotal = (N - 1) * dMin + batches * bMin;
  const maxTotal = (N - 1) * dMax + batches * bMax;

  if (sr)  sr.textContent  = '~' + fmtSec(avgTotal) + ' (متوسط)';
  if (smn) smn.textContent = fmtSec(minTotal);
  if (smx) smx.textContent = fmtSec(maxTotal);
}

// تشغيل فوري عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', updateDelayPreview);
</script>
