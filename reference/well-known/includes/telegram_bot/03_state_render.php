<?php

function njazTgNumbersLiveNormalizeOrders($raw): array {
    $orders = [];
    if (!is_array($raw)) return $orders;
    foreach ($raw as $key => $order) {
        if (!is_array($order)) continue;
        $orderId = (int)($order['order_id'] ?? $key);
        if ($orderId <= 0) continue;
        $order['order_id'] = $orderId;
        $orders[(string)$orderId] = $order;
    }
    return $orders;
}

function njazTgState(PDO $pdo, int $tgId, string $state, array $data = []): void {
    $existingStmt = $pdo->prepare('SELECT state_data FROM telegram_users WHERE telegram_id=? LIMIT 1');
    $existingStmt->execute([$tgId]);
    $existing = json_decode((string)$existingStmt->fetchColumn(), true);
    $existing = is_array($existing) ? $existing : [];

    $orders = njazTgNumbersLiveNormalizeOrders($existing['__numbers_live_pending_orders'] ?? []);
    if (!$orders && is_array($existing['__numbers_live_pending'] ?? null)) {
        $legacy = $existing['__numbers_live_pending'];
        if ((int)($legacy['order_id'] ?? 0) > 0) $orders[(string)(int)$legacy['order_id']] = $legacy;
    }
    foreach (njazTgNumbersLiveNormalizeOrders($data['__numbers_live_pending_orders'] ?? []) as $id => $order) {
        $orders[$id] = array_merge($orders[$id] ?? [], $order);
    }
    $incomingPending = is_array($data['__numbers_live_pending'] ?? null) ? $data['__numbers_live_pending'] : null;
    if ($incomingPending && (int)($incomingPending['order_id'] ?? 0) > 0) {
        $orders[(string)(int)$incomingPending['order_id']] = array_merge($orders[(string)(int)$incomingPending['order_id']] ?? [], $incomingPending);
    }
    unset($data['__numbers_live_pending'], $data['__numbers_live_pending_orders']);

    // تفضيلات العرض مستقلة عن حالة التدفق، ويجب ألا تُحذف عند التنقل.
    foreach (['display_currency', 'display_language'] as $preferenceKey) {
        if (!array_key_exists($preferenceKey, $data) && !empty($existing[$preferenceKey])) {
            $data[$preferenceKey] = (string)$existing[$preferenceKey];
        }
    }

    $selectedId = (int)($existing['active_numbers_live_order_id'] ?? 0);
    if (in_array($state, ['numbers_live_waiting', 'numbers_live_processing'], true) && (int)($data['order_id'] ?? 0) > 0) {
        $selectedId = (int)$data['order_id'];
        $orders[(string)$selectedId] = array_merge($orders[(string)$selectedId] ?? [], $data);
    } elseif ($state === 'menu') {
        // تبقى كل الطلبات المعلقة محفوظة أثناء التنقل، ولا تُحذف إلا بعد اكتمالها أو إلغائها.
        $selectedId = 0;
    }

    if ($orders) {
        if ($selectedId <= 0 || !isset($orders[(string)$selectedId])) $selectedId = (int)array_key_first($orders);
        $data['__numbers_live_pending_orders'] = $orders;
        $data['__numbers_live_pending'] = $orders[(string)$selectedId] ?? reset($orders);
        $data['active_numbers_live_order_id'] = $selectedId;
    }

    $encoded = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
    $pdo->prepare("UPDATE telegram_users SET state=?,state_data=? WHERE telegram_id=?")
        ->execute([$state, $encoded, $tgId]);
}

function njazTgStateData(array $tgUser): array {
    $data = json_decode((string)($tgUser['state_data'] ?? ''), true);
    return is_array($data) ? $data : [];
}

function njazTgNumbersLivePendingOrders(array $tgUser): array {
    $data = njazTgStateData($tgUser);
    $orders = njazTgNumbersLiveNormalizeOrders($data['__numbers_live_pending_orders'] ?? []);
    if (!$orders && is_array($data['__numbers_live_pending'] ?? null)) {
        $legacy = $data['__numbers_live_pending'];
        if ((int)($legacy['order_id'] ?? 0) > 0) $orders[(string)(int)$legacy['order_id']] = $legacy;
    }
    if (!$orders && in_array((string)($tgUser['state'] ?? ''), ['numbers_live_waiting', 'numbers_live_processing'], true) && (int)($data['order_id'] ?? 0) > 0) {
        $orders[(string)(int)$data['order_id']] = $data;
    }
    return $orders;
}

/** يستخرج طلباً محدداً أو الطلب المحدد حالياً، مع دعم الحالة القديمة ذات الطلب الواحد. */
function njazTgNumbersLivePendingData(array $tgUser, ?int $orderId = null): array {
    $data = njazTgStateData($tgUser);
    if ($orderId !== null && $orderId > 0) {
        $orders = njazTgNumbersLivePendingOrders($tgUser);
        return $orders[(string)$orderId] ?? [];
    }
    if (in_array((string)($tgUser['state'] ?? ''), ['numbers_live_waiting', 'numbers_live_processing'], true) && (int)($data['order_id'] ?? 0) > 0) return $data;
    $selectedId = (int)($data['active_numbers_live_order_id'] ?? 0);
    $orders = njazTgNumbersLivePendingOrders($tgUser);
    if ($selectedId > 0 && isset($orders[(string)$selectedId])) return $orders[(string)$selectedId];
    return $orders ? reset($orders) : [];
}

function njazTgClearNumbersLivePending(PDO $pdo, int $tgId, ?int $orderId = null): void {
    $stmt = $pdo->prepare('SELECT state_data FROM telegram_users WHERE telegram_id=? LIMIT 1');
    $stmt->execute([$tgId]);
    $data = json_decode((string)$stmt->fetchColumn(), true);
    if (!is_array($data)) return;
    $orders = njazTgNumbersLiveNormalizeOrders($data['__numbers_live_pending_orders'] ?? []);
    if (!$orders && is_array($data['__numbers_live_pending'] ?? null)) {
        $legacy = $data['__numbers_live_pending'];
        if ((int)($legacy['order_id'] ?? 0) > 0) $orders[(string)(int)$legacy['order_id']] = $legacy;
    }
    if ($orderId === null || $orderId <= 0) {
        unset($data['__numbers_live_pending_orders'], $data['__numbers_live_pending'], $data['active_numbers_live_order_id']);
    } else {
        unset($orders[(string)$orderId]);
        if ($orders) {
            $selectedId = (int)($data['active_numbers_live_order_id'] ?? 0);
            if ($selectedId <= 0 || !isset($orders[(string)$selectedId])) $selectedId = (int)array_key_first($orders);
            $data['__numbers_live_pending_orders'] = $orders;
            $data['__numbers_live_pending'] = $orders[(string)$selectedId];
            $data['active_numbers_live_order_id'] = $selectedId;
        } else {
            unset($data['__numbers_live_pending_orders'], $data['__numbers_live_pending'], $data['active_numbers_live_order_id']);
        }
    }
    $encoded = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
    $pdo->prepare('UPDATE telegram_users SET state_data=? WHERE telegram_id=?')->execute([$encoded, $tgId]);
}

/**
 * يحجز حالة تنفيذ حساسة لمرة واحدة. نجاح أول UPDATE فقط يسمح باستدعاء النظام المركزي.
 * بذلك لا يؤدي ضغط زر التأكيد مرتين أو وصول callback مكرر إلى طلبين.
 */
function njazTgClaimStateAction(PDO $pdo, array $tgUser, string $expectedState, string $processingState): ?array {
    $tgId = (int)($tgUser['telegram_id'] ?? 0);
    if ($tgId <= 0 || (string)($tgUser['state'] ?? '') !== $expectedState) return null;
    $data = njazTgStateData($tgUser);
    $stmt = $pdo->prepare("UPDATE telegram_users SET state=?,state_data=? WHERE telegram_id=? AND state=?");
    $stmt->execute([$processingState, json_encode($data, JSON_UNESCAPED_UNICODE), $tgId, $expectedState]);
    return $stmt->rowCount() === 1 ? $data : null;
}

/** يمنع إعادة تنفيذ update_id عند إعادة إرسال Webhook. */
function njazTgClaimUpdate(PDO $pdo, int $updateId, int $telegramId = 0): bool {
    if ($updateId <= 0) return true;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO telegram_bot_processed_updates (update_id,telegram_id) VALUES (?,?)");
        $stmt->execute([$updateId, $telegramId > 0 ? $telegramId : null]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        // لا نعطل البوت إذا كانت قاعدة سجل الحماية غير متاحة؛ الحماية التجارية تبقى في API المركزي.
        error_log('Telegram update claim failed: ' . $e->getMessage());
        return true;
    }
}

function njazTgStateKey(PDO $pdo): string {
    return hash('sha256', telegramInternalSecret($pdo), true);
}

function njazTgEncryptSecret(PDO $pdo, string $value): string {
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', njazTgStateKey($pdo), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . ($cipher ?: ''));
}

function njazTgDecryptSecret(PDO $pdo, string $value): string {
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', njazTgStateKey($pdo), OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

function njazTgHtml($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function njazTgMoney($value): string {
    $context = $GLOBALS['njaz_tg_display_currency_context'] ?? null;
    if (is_array($context) && isset($context[0], $context[1]) && $context[0] instanceof PDO && is_array($context[1]) && function_exists('njazTgDisplayMoney')) {
        return njazTgDisplayMoney($context[0], $context[1], $value, true);
    }
    return number_format((float)$value, 4, '.', '') . ' $';
}

/**
 * أدوات عرض موحدة لواجهات Telegram. لا تحتوي على أي منطق تجاري؛ مهمتها تحسين
 * الترتيب والصياغة فقط مع إبقاء الأسعار والطلبات من مصدر نجاز المركزي.
 */
function njazTgScreen(string $icon, string $title, string $body = ''): string {
    $heading = '<b>' . njazTgHtml($icon . ' ' . trim($title)) . '</b>';
    return $body === '' ? $heading : $heading . "\n\n" . $body;
}

function njazTgClipLabel($value, int $max = 34): string {
    $label = trim((string)$value);
    if ($label === '') return 'غير مسمى';
    if (function_exists('mb_strlen') && mb_strlen($label, 'UTF-8') > $max) {
        return mb_substr($label, 0, max(1, $max - 1), 'UTF-8') . '…';
    }
    return $label;
}

function njazTgStatusLabel($status): string {
    $key = strtolower(trim((string)$status));
    $map = [
        'pending' => '⏳ قيد الانتظار', 'processing' => '🔄 قيد المعالجة',
        'completed' => '✅ مكتملة', 'complete' => '✅ مكتملة',
        'ready' => '✅ جاهزة', 'approved' => '✅ معتمدة',
        'rejected' => '❌ مرفوضة', 'cancelled' => '🚫 ملغاة',
        'canceled' => '🚫 ملغاة', 'failed' => '⚠️ فاشلة',
    ];
    return $map[$key] ?? (trim((string)$status) !== '' ? njazTgHtml($status) : '—');
}

function njazTgNavKeyboard(string $backCallback = 'home', string $backLabel = 'الرئيسية', bool $withHelp = false): array {
    $row = [['text' => '↩️ ' . $backLabel, 'callback_data' => $backCallback]];
    $kb = [$row];
    if ($withHelp) $kb[] = [['text' => '🆘 مساعدة', 'callback_data' => 'menu:help']];
    return $kb;
}

function njazTgRender(PDO $pdo, array $tgUser, string $text, ?array $keyboard = null, ?int $messageId = null): void {
    if (function_exists('njazTgLanguageCode') && function_exists('njazTgTranslate')) {
        $language = njazTgLanguageCode($pdo, $tgUser);
        $text = njazTgTranslate($text, $language);
        if ($keyboard !== null && function_exists('njazTgTranslateKeyboard')) {
            $keyboard = njazTgTranslateKeyboard($keyboard, $language);
        }
    }
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $keyboard);
    else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

/**
 * يحول مسار الصورة الذي يستخدمه الموقع إلى رابط عام يصلح لـ Telegram.
 * لا ينشئ نسخة جديدة من الصورة؛ يقرأ نفس ملف categories.image أو services.image.
 */
function njazTgSiteMedia(?string $imagePath, ?string $forcedType = null): array {
    $imagePath = trim((string)$imagePath);
    if ($imagePath === '') return ['type' => null, 'url' => null];
    $url = preg_match('~^https?://~i', $imagePath)
        ? $imagePath
        : rtrim(SITE_URL, '/') . '/' . ltrim($imagePath, '/');
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    $type = $forcedType === 'animation' || ($forcedType === null && $ext === 'gif') ? 'animation' : 'photo';
    return ['type' => $type, 'url' => $url];
}

/** يعرض صورة القسم أو الخدمة الأصلية من الموقع، مع وسيط Telegram القديم كاحتياطي اختياري. */
function njazTgRenderSection(PDO $pdo, array $tgUser, string $text, ?array $keyboard, ?int $messageId, $mediaType = null, $mediaUrl = null): void {
    $mediaType = strtolower(trim((string)$mediaType));
    $source = njazTgSiteMedia((string)$mediaUrl, in_array($mediaType, ['photo', 'animation'], true) ? $mediaType : null);
    $mediaType = $source['type'];
    $mediaUrl = $source['url'];
    if (!$mediaUrl || !$mediaType) {
        njazTgRender($pdo, $tgUser, $text, $keyboard, $messageId);
        return;
    }
    $chatId = (int)$tgUser['chat_id'];
    if (function_exists('njazTgLanguageCode') && function_exists('njazTgTranslate')) {
        $language = njazTgLanguageCode($pdo, $tgUser);
        $text = njazTgTranslate($text, $language);
        if ($keyboard !== null && function_exists('njazTgTranslateKeyboard')) {
            $keyboard = njazTgTranslateKeyboard($keyboard, $language);
        }
    }
    if (!$messageId) {
        $sent = njazTgSendMedia($pdo, $chatId, $mediaType, $mediaUrl, $text, $keyboard);
        if (empty($sent['ok'])) njazTgRender($pdo, $tgUser, $text, $keyboard, null);
        return;
    }
    $media = ['type' => $mediaType, 'media' => $mediaUrl];
    if ($text !== '') { $media['caption'] = str_replace('\\n', "\n", $text); $media['parse_mode'] = 'HTML'; }
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'media' => json_encode($media, JSON_UNESCAPED_UNICODE)];
    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode([
            'inline_keyboard' => njazTgStyledKeyboard(njazTgApplyMenuIcons($pdo, $keyboard))
        ], JSON_UNESCAPED_UNICODE);
    }
    $edited = njazTgApi($pdo, 'editMessageMedia', $params);
    if (!empty($edited['ok'])) return;
    njazTgApi($pdo, 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    $sent = njazTgSendMedia($pdo, $chatId, $mediaType, $mediaUrl, $text, $keyboard);
    if (empty($sent['ok'])) njazTgSend($pdo, $chatId, $text, $keyboard);
}
