<?php
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/telegram_bot.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = (int)$_SESSION['user_id'];
$isStaff = in_array($_SESSION['role'] ?? '', ['admin','staff']);

// إنشاء الجداول إذا لم تكن موجودة
try {
$pdo->exec("CREATE TABLE IF NOT EXISTS `chats` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL, `assigned_to` INT DEFAULT NULL,
  `status` ENUM('open','closed','pending') DEFAULT 'pending',
  `subject` VARCHAR(255) DEFAULT NULL,
  `unread_user` INT DEFAULT 0, `unread_staff` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chat_id` INT NOT NULL, `sender_id` INT NOT NULL,
  `sender_type` ENUM('user','staff') NOT NULL,
  `message` TEXT NOT NULL, `is_read` TINYINT(1) DEFAULT 0, `is_auto` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec("ALTER TABLE `chat_messages` MODIFY COLUMN `sender_type` ENUM('user','staff','system') NOT NULL"); } catch (Throwable $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS `chat_auto_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `keywords` VARCHAR(500) NOT NULL, `reply` TEXT NOT NULL,
  `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

switch ($action) {

// ── فتح أو جلب محادثة العميل ──────────────────────────────────
case 'open_chat':
    $subject = trim($_POST['subject'] ?? 'استفسار عام');
    // هل يوجد محادثة مفتوحة؟
    $existing = $pdo->prepare("SELECT * FROM chats WHERE user_id=? AND status != 'closed' ORDER BY updated_at DESC LIMIT 1");
    $existing->execute([$userId]);
    $chat = $existing->fetch();
    $isNew = false;
    if (!$chat) {
        $isNew = true;
        $pdo->prepare("INSERT INTO chats (user_id, subject, status) VALUES (?,?,'pending')")->execute([$userId, $subject]);
        $chatId = $pdo->lastInsertId();
        $welcome = getSetting('chat_welcome') ?: 'مرحباً! 👋 شكراً لتواصلك معنا. سيرد عليك أحد موظفينا قريباً.';
        $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'staff',?,1)")->execute([$chatId, $welcome]);
        $chat = $pdo->prepare("SELECT * FROM chats WHERE id=?"); $chat->execute([$chatId]); $chat = $chat->fetch();
    }
    // جلب الرسائل
    $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? ORDER BY cm.created_at ASC");
    $msgs->execute([$chat['id']]);
    // تعليم مقروء
    $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE chat_id=? AND sender_type='staff'")->execute([$chat['id']]);
    $pdo->prepare("UPDATE chats SET unread_user=0 WHERE id=?")->execute([$chat['id']]);
    echo json_encode(['ok'=>true, 'chat'=>$chat, 'messages'=>$msgs->fetchAll(), 'is_existing'=>!$isNew], JSON_UNESCAPED_UNICODE);
    break;

// ── إرسال رسالة من العميل ─────────────────────────────────────
case 'send':
    $chatId  = (int)($_POST['chat_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$chatId || !$message) { echo json_encode(['ok'=>false]); break; }

    // تحقق أن المحادثة تخص العميل
    $chat = $pdo->prepare("SELECT * FROM chats WHERE id=? AND user_id=?");
    $chat->execute([$chatId, $userId]); $chat = $chat->fetch();
    if (!$chat) { echo json_encode(['ok'=>false,'error'=>'not found']); break; }

    // منع الإرسال في المحادثات المغلقة
    if ($chat['status'] === 'closed') {
        echo json_encode(['ok'=>false,'error'=>'closed','message'=>'هذه المحادثة مغلقة. يرجى فتح محادثة جديدة.']);
        break;
    }

    $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message) VALUES (?,?,'user',?)")->execute([$chatId, $userId, $message]);
    $pdo->prepare("UPDATE chats SET status='open', unread_staff=unread_staff+1, updated_at=NOW() WHERE id=?")->execute([$chatId]);

    // الرد التلقائي فقط إذا لم يكن هناك موظف سبق أن رد (assigned_to = NULL)
    $autoSent = false;
    if ($chat['assigned_to'] === null) {
        $autoReplies = $pdo->query("SELECT * FROM chat_auto_replies WHERE status=1 ORDER BY sort_order")->fetchAll();
        foreach ($autoReplies as $ar) {
            $keywords = array_map('trim', explode(',', $ar['keywords']));
            foreach ($keywords as $kw) {
                if ($kw && mb_stripos($message, $kw) !== false) {
                    $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'staff',?,1)")->execute([$chatId, $ar['reply']]);
                    $pdo->prepare("UPDATE chats SET unread_user=unread_user+1 WHERE id=?")->execute([$chatId]);
                    $autoSent = true;
                    break 2;
                }
            }
        }
    }

    $newMsg = $pdo->query("SELECT * FROM chat_messages WHERE chat_id=$chatId ORDER BY id DESC LIMIT 1")->fetch();
    echo json_encode(['ok'=>true, 'message'=>$newMsg, 'auto_reply'=>$autoSent], JSON_UNESCAPED_UNICODE);
    break;

// ── جلب رسائل جديدة (polling) ────────────────────────────────
case 'poll':
    $chatId  = (int)($_GET['chat_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);
    if (!$chatId) { echo json_encode(['ok'=>false]); break; }

    if ($isStaff) {
        $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? AND cm.id>? ORDER BY cm.id ASC");
    } else {
        $chat = $pdo->prepare("SELECT id FROM chats WHERE id=? AND user_id=?"); $chat->execute([$chatId, $userId]);
        if (!$chat->fetch()) { echo json_encode(['ok'=>false]); break; }
        $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? AND cm.id>? ORDER BY cm.id ASC");
        // تعليم مقروء
        $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE chat_id=? AND sender_type='staff' AND id>?")->execute([$chatId, $afterId]);
        $pdo->prepare("UPDATE chats SET unread_user=0 WHERE id=?")->execute([$chatId]);
    }
    $msgs->execute([$chatId, $afterId]);
    echo json_encode(['ok'=>true, 'messages'=>$msgs->fetchAll()], JSON_UNESCAPED_UNICODE);
    break;

// ── للموظف: جلب كل المحادثات ─────────────────────────────────
case 'staff_list':
    if (!$isStaff) { echo json_encode(['ok'=>false]); break; }
    $status = $_GET['status'] ?? 'open';
    $where  = $status === 'all' ? '' : "AND c.status = '$status'";
    $chats  = $pdo->query("
        SELECT c.*, u.username, u.full_name,
               (SELECT message FROM chat_messages WHERE chat_id=c.id ORDER BY id DESC LIMIT 1) as last_msg,
               (SELECT created_at FROM chat_messages WHERE chat_id=c.id ORDER BY id DESC LIMIT 1) as last_time,
               s.username as staff_name
        FROM chats c
        JOIN users u ON c.user_id = u.id
        LEFT JOIN users s ON c.assigned_to = s.id
        WHERE 1=1 $where
        ORDER BY c.updated_at DESC LIMIT 50
    ")->fetchAll();
    echo json_encode(['ok'=>true, 'chats'=>$chats], JSON_UNESCAPED_UNICODE);
    break;

// ── للموظف: جلب رسائل محادثة ─────────────────────────────────
case 'staff_messages':
    if (!$isStaff) { echo json_encode(['ok'=>false]); break; }
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);
    $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? AND cm.id>? ORDER BY cm.id ASC");
    $msgs->execute([$chatId, $afterId]);
    // تعليم مقروء
    $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE chat_id=? AND sender_type='user'")->execute([$chatId]);
    $pdo->prepare("UPDATE chats SET unread_staff=0 WHERE id=?")->execute([$chatId]);
    echo json_encode(['ok'=>true, 'messages'=>$msgs->fetchAll()], JSON_UNESCAPED_UNICODE);
    break;

// ── للموظف: إرسال رد ──────────────────────────────────────────
case 'staff_send':
    if (!$isStaff) { echo json_encode(['ok'=>false]); break; }
    $chatId  = (int)($_POST['chat_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$chatId || !$message) { echo json_encode(['ok'=>false]); break; }

    // هل هذا أول رد من موظف على هذه المحادثة؟
    $chat = $pdo->prepare("SELECT user_id, assigned_to, status FROM chats WHERE id=?");
    $chat->execute([$chatId]); $chat = $chat->fetch();

    $firstReply = ($chat['status'] === 'pending' || $chat['assigned_to'] === null);

    if ($firstReply) {
        // أضف رسالة نظام: تم ربط المحادثة بالموظف
        $staff = $pdo->prepare("SELECT full_name, username FROM users WHERE id=?");
        $staff->execute([$userId]); $staff = $staff->fetch();
        $staffName = $staff['full_name'] ?: $staff['username'];
        $sysMsg = "تم ربط المحادثة بالموظف: {$staffName} ✅";
        $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'system',?,1)")->execute([$chatId, $sysMsg]);
    }

    $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message) VALUES (?,?,'staff',?)")->execute([$chatId, $userId, $message]);
    $pdo->prepare("UPDATE chats SET status='open', unread_user=unread_user+1, assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$userId, $chatId]);

    // Telegram قناة ثانوية: لا تؤثر على حفظ الرد المركزي إذا تعذر الإرسال.
    try {
        $tu = $pdo->prepare("SELECT telegram_chat_id FROM users WHERE id=? LIMIT 1");
        $tu->execute([(int)($chat['user_id'] ?? 0)]);
        $telegramChatId = (int)$tu->fetchColumn();
        if ($telegramChatId > 0) {
            $safeMessage = function_exists('njazTgHtml') ? njazTgHtml(mb_substr($message, 0, 3500)) : htmlspecialchars(mb_substr($message, 0, 3500), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            njazTgSend($pdo, $telegramChatId, '💬 <b>رد جديد من الدعم</b>\\n\\n' . $safeMessage . '\\n\\n🔖 المحادثة #' . $chatId, [[['text' => '🆘 فتح المساعدة', 'callback_data' => 'menu:help']], [['text' => '🏠 الرئيسية', 'callback_data' => 'home']]]);
        }
    } catch (Throwable $e) {
        error_log('Telegram support reply failed: ' . $e->getMessage());
    }
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    break;

// ── للموظف: تعيين موظف + إغلاق ───────────────────────────────
case 'assign':
    if (!$isStaff) { echo json_encode(['ok'=>false]); break; }
    $chatId = (int)($_POST['chat_id'] ?? 0);
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $pdo->prepare("UPDATE chats SET assigned_to=? WHERE id=?")->execute([$staffId ?: null, $chatId]);
    echo json_encode(['ok'=>true]);
    break;

case 'close':
    if (!$isStaff) { echo json_encode(['ok'=>false]); break; }
    $chatId = (int)($_POST['chat_id'] ?? 0);
    $pdo->prepare("UPDATE chats SET status='closed' WHERE id=?")->execute([$chatId]);
    echo json_encode(['ok'=>true]);
    break;

case 'new_chat':
    // إنشاء محادثة جديدة حتى لو كانت هناك محادثة مغلقة
    $subject = trim($_POST['subject'] ?? 'استفسار عام');
    $pdo->prepare("INSERT INTO chats (user_id, subject, status) VALUES (?,?,'pending')")->execute([$userId, $subject]);
    $chatId  = $pdo->lastInsertId();
    $welcome = getSetting('chat_welcome') ?: 'مرحباً! 👋 شكراً لتواصلك معنا. سيرد عليك أحد موظفينا قريباً.';
    $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'staff',?,1)")->execute([$chatId, $welcome]);
    $chat = $pdo->prepare("SELECT * FROM chats WHERE id=?"); $chat->execute([$chatId]); $chat = $chat->fetch();
    $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? ORDER BY cm.created_at ASC");
    $msgs->execute([$chatId]);
    echo json_encode(['ok'=>true,'chat'=>$chat,'messages'=>$msgs->fetchAll(),'is_existing'=>false], JSON_UNESCAPED_UNICODE);
    break;

case 'user_history':
    $hist = $pdo->prepare("
        SELECT c.*,
          (SELECT message FROM chat_messages WHERE chat_id=c.id ORDER BY id DESC LIMIT 1) as last_msg
        FROM chats c WHERE c.user_id=? ORDER BY c.updated_at DESC LIMIT 20
    ");
    $hist->execute([$userId]);
    echo json_encode(['ok'=>true,'chats'=>$hist->fetchAll()], JSON_UNESCAPED_UNICODE);
    break;

case 'load_chat':
    $chatId = (int)($_GET['chat_id'] ?? 0);
    $chk = $pdo->prepare("SELECT id FROM chats WHERE id=? AND user_id=?");
    $chk->execute([$chatId,$userId]);
    if (!$chk->fetch()) { echo json_encode(['ok'=>false]); break; }
    $msgs = $pdo->prepare("SELECT cm.*, u.full_name, u.username FROM chat_messages cm LEFT JOIN users u ON cm.sender_id=u.id WHERE cm.chat_id=? ORDER BY cm.created_at ASC");
    $msgs->execute([$chatId]);
    echo json_encode(['ok'=>true,'messages'=>$msgs->fetchAll()], JSON_UNESCAPED_UNICODE);
    break;

default:
    echo json_encode(['ok'=>false,'error'=>'invalid action']);
}
