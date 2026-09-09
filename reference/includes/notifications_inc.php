<?php
// =====================================================
// includes/notifications.php — نظام الإشعارات
// =====================================================

/**
 * إرسال إشعار داخلي للمستخدم
 */
function sendTelegramCustomerNotification($pdo, $userId, $type, $title, $message) {
    if (in_array((string)$type, ['order_update', 'topup'], true)) return false;
    try {
        $s = $pdo->prepare('SELECT telegram_chat_id FROM users WHERE id=? LIMIT 1');
        $s->execute([(int)$userId]); $chatId = $s->fetchColumn();
        if (!$chatId) return false;
        $safeTitle = htmlspecialchars((string)$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeMessage = htmlspecialchars((string)$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (bool)sendTelegram($pdo, $chatId, "🔔 <b>{$safeTitle}</b>\n{$safeMessage}");
    } catch (Throwable $e) {
        error_log('Telegram customer notification failed: ' . $e->getMessage());
        return false;
    }
}

function sendNotification($pdo, $userId, $type, $title, $message, $icon = 'bell', $color = '#6c3fe0', $refType = null, $refId = null) {
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, icon, color, reference_type, reference_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $type, $title, $message, $icon, $color, $refType, $refId]);
        sendTelegramCustomerNotification($pdo, $userId, $type, $title, $message);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * إرسال إشعار مع إجراء (رابط توجيه)
 */
function sendNotificationWithAction($pdo, $userId, $type, $title, $message, $icon = 'bell', $color = '#6c3fe0', $actionType = 'none', $actionUrl = null) {
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, icon, color, action_type, action_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $type, $title, $message, $icon, $color, $actionType, $actionUrl]);
        sendTelegramCustomerNotification($pdo, $userId, $type, $title, $message);
        return true;
    } catch (Exception $e) {
        // fallback للعمود القديم إن لم يكن موجوداً
        return sendNotification($pdo, $userId, $type, $title, $message, $icon, $color);
    }
}

/**
 * جلب الإشعارات المنبثقة النشطة للمستخدم (لم يشاهدها بعد)
 */
function getActiveBroadcastsForUser($pdo, $userId) {
    try {
        $uid = (int)$userId;
        $now = date('Y-m-d H:i:s');

        // جلب كل النشطة أولاً
        $all = $pdo->query("
            SELECT * FROM notification_broadcasts
            WHERE is_active = 1
              AND (end_at IS NULL OR end_at > '$now')
            ORDER BY created_at DESC
            LIMIT 10
        ")->fetchAll();

        if (empty($all)) return [];

        // فلتر: target + show_once
        $result = [];
        foreach ($all as $nb) {
            // فحص الاستهداف
            if ($nb['target'] === 'specific') {
                $ids = json_decode($nb['target_user_ids'] ?? '[]', true);
                if (!in_array($uid, (array)$ids)) continue;
            }
            // فحص show_once
            if ($nb['show_once']) {
                $viewed = $pdo->query("
                    SELECT COUNT(*) FROM broadcast_views
                    WHERE broadcast_id = {$nb['id']} AND user_id = $uid
                ")->fetchColumn();
                if ($viewed > 0) continue;
            }
            $result[] = $nb;
            if (count($result) >= 5) break;
        }
        return $result;

    } catch (Exception $e) {
        error_log('getActiveBroadcastsForUser: ' . $e->getMessage());
        return [];
    }
}

/**
 * تسجيل مشاهدة الإشعار المنبثق
 */
function markBroadcastViewed($pdo, $broadcastId, $userId) {
    try {
        $pdo->prepare("INSERT IGNORE INTO broadcast_views (broadcast_id, user_id) VALUES (?,?)")
            ->execute([$broadcastId, $userId]);
    } catch (Exception $e) {}
}

/**
 * الحصول على عدد الإشعارات غير المقروءة
 */
function getUnreadCount($pdo, $userId) {
    try {
        return (int)$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")
                        ->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0")->fetchColumn() : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getUnreadNotifCount($pdo, $userId) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/**
 * إرسال رسالة تليغرام
 */
function sendTelegram($pdo, $chatId, $text) {
    $token = getSetting('telegram_bot_token');
    if (!$token || !$chatId) return false;
    $url  = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

/**
 * إرسال واتساب عبر API
 */
function sendWhatsApp($pdo, $phone, $message) {
    if (!getSetting('whatsapp_enabled')) return false;
    $apiUrl   = getSetting('whatsapp_api_url');
    $apiToken = getSetting('whatsapp_api_token');
    if (!$apiUrl || !$apiToken || !$phone) return false;
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['phone' => $phone, 'message' => $message]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiToken],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// ─── دوال الإشعار حسب الحدث ──────────────────────────────────────────────────

/**
 * إشعار عند إنشاء طلب جديد
 */
function notifyNewOrder($pdo, $order, $user, $serviceName) {
    $siteName = getSetting('site_name') ?: 'المنصة';
    $orderId  = (int)$order['id'];
    $orderRef = function_exists('formatOrderId') ? formatOrderId($order) : ('ID_' . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT));

    // 1. إشعار داخلي للعميل
    sendNotification($pdo, $user['id'], 'order_update',
        "✅ تم استلام طلبك $orderRef",
        "تم استلام طلبك للخدمة «{$serviceName}» وهو الآن قيد المراجعة.",
        'shopping-bag', '#f5a623', 'order', $orderId
    );

    // 2. تليغرام للإدارة
    if (getSetting('notify_on_order_new') && getSetting('telegram_notify_admin_orders')) {
        $adminChat = getSetting('telegram_admin_chat');
        if ($adminChat) {
            $msg = "🛍️ <b>طلب جديد {$orderRef}</b>\n"
                 . "👤 العميل: {$user['username']}\n"
                 . "📦 الخدمة: {$serviceName}\n"
                 . "💰 المبلغ: {$order['total_price']}\n"
                 . "📅 " . date('Y/m/d H:i');
            sendTelegram($pdo, $adminChat, $msg);
        }
    }

    // 3. تليغرام للعميل (إن ربط حسابه)
    if (!empty($user['telegram_chat_id']) && $user['notif_order_update']) {
        $msg = "🛍️ تم استلام طلبك {$orderRef}\n"
             . "الخدمة: {$serviceName}\n"
             . "الحالة: قيد الانتظار ⏳";
        sendTelegram($pdo, $user['telegram_chat_id'], $msg);
    }

    // 4. واتساب للعميل
    if (!empty($user['whatsapp_number']) && $user['notif_order_update']) {
        $msg = "✅ {$siteName}\nتم استلام طلبك {$orderRef}\nالخدمة: {$serviceName}\nالحالة: قيد الانتظار ⏳";
        sendWhatsApp($pdo, $user['whatsapp_number'], $msg);
    }
}

/**
 * إشعار عند تغيير حالة الطلب
 */
function notifyOrderStatusChange($pdo, $order, $newStatus, $adminNotes = '') {
    $userId      = $order['user_id'];
    $orderId     = (int)$order['id'];
    $orderRef    = function_exists('formatOrderId') ? formatOrderId($order) : ('ID_' . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT));
    $serviceName = $order['service_name'] ?? 'الخدمة';
    $siteName    = getSetting('site_name') ?: 'المنصة';

    $statusLabels = [
        'pending'    => ['⏳ قيد الانتظار',   'clock',         '#f5a623'],
        'processing' => ['🔄 قيد التنفيذ',    'sync-alt',      '#00d4ff'],
        'completed'  => ['✅ مكتمل',           'check-circle',  '#00e676'],
        'cancelled'  => ['❌ ملغي',            'times-circle',  '#ff4455'],
        'failed'     => ['⚠️ فشل',             'exclamation',   '#ff4455'],
    ];

    [$statusLabel, $icon, $color] = $statusLabels[$newStatus] ?? ['تم التحديث', 'bell', '#6c3fe0'];

    $notifTitle = "تحديث الطلب {$orderRef} — {$statusLabel}";
    $notifMsg   = "تم تحديث حالة طلبك للخدمة «{$serviceName}» إلى: {$statusLabel}";
    if ($adminNotes) $notifMsg .= "\nملاحظة: {$adminNotes}";

    // 1. إشعار داخلي
    sendNotification($pdo, $userId, 'order_update', $notifTitle, $notifMsg, $icon, $color, 'order', $orderId);

    // 2. جلب بيانات المستخدم
    $u = $pdo->prepare("SELECT telegram_chat_id, whatsapp_number, notif_order_update FROM users WHERE id=?");
    $u->execute([$userId]);
    $user = $u->fetch();

    // 3. تليغرام للعميل
    if ($user && !empty($user['telegram_chat_id']) && $user['notif_order_update']) {
        $settingKey = in_array($newStatus, ['completed']) ? 'notify_on_order_complete' : 'notify_on_order_cancel';
        if (getSetting($settingKey) || $newStatus === 'processing') {
            $msg = "{$statusLabel}\n"
                 . "طلب {$orderRef} — {$serviceName}\n"
                 . ($adminNotes ? "📝 {$adminNotes}\n" : "")
                 . "🕐 " . date('H:i');
            sendTelegram($pdo, $user['telegram_chat_id'], $msg);
        }
    }

    // 4. واتساب للعميل
    if ($user && !empty($user['whatsapp_number']) && $user['notif_order_update']) {
        $msg = "{$siteName}\n{$statusLabel}\nطلب #{$orderId} — {$serviceName}"
             . ($adminNotes ? "\nملاحظة: {$adminNotes}" : "");
        sendWhatsApp($pdo, $user['whatsapp_number'], $msg);
    }

    // 5. تليغرام للإدارة (للطلبات المكتملة/الفاشلة)
    if (in_array($newStatus, ['completed', 'failed', 'cancelled']) && getSetting('telegram_notify_admin_orders')) {
        $adminChat = getSetting('telegram_admin_chat');
        if ($adminChat) {
            $u2 = $pdo->prepare("SELECT username FROM users WHERE id=?");
            $u2->execute([$userId]);
            $uname = ($u2->fetch())['username'] ?? 'مجهول';
            $msg = "{$statusLabel} طلب {$orderRef}\n"
                 . "👤 {$uname} | 📦 {$serviceName}\n"
                 . ($adminNotes ? "📝 {$adminNotes}\n" : "")
                 . "🕐 " . date('H:i');
            sendTelegram($pdo, $adminChat, $msg);
        }
    }
}

/**
 * إشعار عند الموافقة على شحن رصيد
 */
function notifyTopupApproved($pdo, $userId, $amount, $currency) {
    $siteName = getSetting('site_name') ?: 'المنصة';
    sendNotification($pdo, $userId, 'topup',
        "💰 تم شحن رصيدك",
        "تمت الموافقة على طلب الشحن وإضافة {$amount} {$currency} إلى محفظتك.",
        'wallet', '#00e676', 'wallet', null
    );
    $u = $pdo->prepare("SELECT telegram_chat_id, whatsapp_number, notif_topup_update FROM users WHERE id=?");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return;

    if (!empty($user['telegram_chat_id']) && $user['notif_topup_update']) {
        sendTelegram($pdo, $user['telegram_chat_id'], "💰 تم شحن رصيدك\nالمبلغ: {$amount} {$currency}\n✅ تمت الموافقة");
    }
    if (!empty($user['whatsapp_number']) && $user['notif_topup_update']) {
        sendWhatsApp($pdo, $user['whatsapp_number'], "{$siteName}\n💰 تم شحن رصيدك\nالمبلغ: {$amount} {$currency}");
    }
}

/**
 * إشعار عند رفض شحن رصيد
 */
function notifyTopupRejected($pdo, $userId, $amount, $currency, $reason = '') {
    $siteName = getSetting('site_name') ?: 'المنصة';
    sendNotification($pdo, $userId, 'topup',
        "❌ تم رفض طلب الشحن",
        "تم رفض طلب شحن رصيدك ({$amount} {$currency})." . ($reason ? " السبب: {$reason}" : ''),
        'times-circle', '#ff4455', 'wallet', null
    );
    $u = $pdo->prepare("SELECT telegram_chat_id, whatsapp_number, notif_topup_update FROM users WHERE id=?");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return;
    if (!empty($user['telegram_chat_id']) && $user['notif_topup_update']) {
        sendTelegram($pdo, $user['telegram_chat_id'], "❌ تم رفض طلب الشحن\nالمبلغ: {$amount} {$currency}" . ($reason ? "\nالسبب: {$reason}" : ''));
    }
    if (!empty($user['whatsapp_number']) && $user['notif_topup_update']) {
        sendWhatsApp($pdo, $user['whatsapp_number'], "{$siteName}\n❌ تم رفض طلب الشحن\nالمبلغ: {$amount} {$currency}");
    }
}

/**
 * إشعار تسجيل مستخدم جديد للإدارة
 */
function notifyAdminNewRegister($pdo, $username, $email) {
    if (!getSetting('telegram_notify_admin_register')) return;
    $adminChat = getSetting('telegram_admin_chat');
    if (!$adminChat) return;
    $msg = "👤 <b>مستخدم جديد</b>\n"
         . "اسم المستخدم: {$username}\n"
         . "البريد: {$email}\n"
         . "📅 " . date('Y/m/d H:i');
    sendTelegram($pdo, $adminChat, $msg);
}

/**
 * إشعار طلب شحن رصيد للإدارة
 */
function notifyAdminTopupRequest($pdo, $username, $amount, $currency) {
    if (!getSetting('telegram_notify_admin_topup')) return;
    $adminChat = getSetting('telegram_admin_chat');
    if (!$adminChat) return;
    $msg = "💳 <b>طلب شحن رصيد جديد</b>\n"
         . "👤 {$username}\n"
         . "💰 {$amount} {$currency}\n"
         . "📅 " . date('Y/m/d H:i');
    sendTelegram($pdo, $adminChat, $msg);
}
