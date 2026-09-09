<?php

function njazTgApi(PDO $pdo, string $method, array $params = []): array {
    $settings = njazTgSettings($pdo);
    $token = trim((string)($settings['bot_token'] ?? ''));
    if ($token === '') return ['ok' => false, 'description' => 'Bot token غير مضبوط'];
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['ok' => false, 'description' => $error];
    $result = json_decode((string)$raw, true);
    return is_array($result) ? $result : ['ok' => false, 'description' => 'استجابة Telegram غير صالحة'];
}

function njazTgSend(PDO $pdo, int $chatId, string $text, ?array $keyboard = null, array $extra = []): array {
    $text = str_replace('\\n', "\n", $text);
    $params = array_merge(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'], $extra);
    if ($keyboard !== null) $params['reply_markup'] = json_encode(['inline_keyboard' => njazTgStyledKeyboard(njazTgApplyMenuIcons($pdo, $keyboard))], JSON_UNESCAPED_UNICODE);
    $result = njazTgApi($pdo, 'sendMessage', $params);
    njazTgLog($pdo, $result['ok'] ?? false ? 'out' : 'error', 'sendMessage', ['text' => $text, 'result' => $result], null, null);
    return $result;
}

function njazTgSendPhoto(PDO $pdo, int $chatId, string $imagePath, string $caption = '', ?array $keyboard = null): array {
    return njazTgSendMedia($pdo, $chatId, 'photo', $imagePath, $caption, $keyboard);
}

function njazTgSendAnimation(PDO $pdo, int $chatId, string $animationPath, string $caption = '', ?array $keyboard = null): array {
    return njazTgSendMedia($pdo, $chatId, 'animation', $animationPath, $caption, $keyboard);
}

function njazTgSendMedia(PDO $pdo, int $chatId, string $mediaType, string $mediaPath, string $caption = '', ?array $keyboard = null): array {
    $mediaPath = trim($mediaPath);
    if ($mediaPath === '') return ['ok' => false, 'description' => 'وسيط غير محدد'];
    $media = preg_match('~^https?://~i', $mediaPath) ? $mediaPath : rtrim(SITE_URL, '/') . '/' . ltrim($mediaPath, '/');
    $type = $mediaType === 'animation' ? 'animation' : 'photo';
    $params = ['chat_id' => $chatId, $type => $media, 'parse_mode' => 'HTML'];
    if ($caption !== '') $params['caption'] = str_replace('\\n', "\n", $caption);
    if ($keyboard !== null) $params['reply_markup'] = json_encode(['inline_keyboard' => njazTgStyledKeyboard(njazTgApplyMenuIcons($pdo, $keyboard))], JSON_UNESCAPED_UNICODE);
    $method = $type === 'animation' ? 'sendAnimation' : 'sendPhoto';
    $result = njazTgApi($pdo, $method, $params);
    njazTgLog($pdo, $result['ok'] ?? false ? 'out' : 'error', $method, ['media' => $media, 'result' => $result], null, null);
    return $result;
}

/** يرسل صور الصفحة كألبوم واحد حتى تظهر كشبكة مضغوطة بدلاً من رسائل منفصلة. */
function njazTgSendMediaGroup(PDO $pdo, int $chatId, array $mediaPaths, string $caption = ''): array {
    $media = [];
    foreach ($mediaPaths as $path) {
        $path = trim((string)$path);
        if ($path === '') continue;
        $url = preg_match('~^https?://~i', $path) ? $path : rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
        $type = njazTgSiteMedia($path)['type'] ?? 'photo';
        if ($type !== 'photo') continue;
        $item = ['type' => 'photo', 'media' => $url];
        if (!$media && $caption !== '') {
            $item['caption'] = str_replace('\\n', "\n", $caption);
            $item['parse_mode'] = 'HTML';
        }
        $media[] = $item;
        if (count($media) >= 10) break;
    }
    if (count($media) < 2) {
        if (count($media) === 1) return njazTgSendPhoto($pdo, $chatId, (string)$media[0]['media'], $caption);
        return ['ok' => false, 'description' => 'لا توجد صور صالحة للألبوم'];
    }
    $result = njazTgApi($pdo, 'sendMediaGroup', ['chat_id' => $chatId, 'media' => json_encode($media, JSON_UNESCAPED_UNICODE)]);
    njazTgLog($pdo, $result['ok'] ?? false ? 'out' : 'error', 'sendMediaGroup', ['count' => count($media), 'result' => $result], null, null);
    return $result;
}

function njazTgEdit(PDO $pdo, int $chatId, int $messageId, string $text, ?array $keyboard = null): array {
    $text = str_replace('\\n', "\n", $text);
    $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard !== null) $params['reply_markup'] = json_encode(['inline_keyboard' => njazTgStyledKeyboard(njazTgApplyMenuIcons($pdo, $keyboard))], JSON_UNESCAPED_UNICODE);
    $result = njazTgApi($pdo, 'editMessageText', $params);
    // بعض شاشات الكتالوج قد تكون وسائط أو قديمة؛ لا نترك المستخدم بلا رد إذا تعذر تعديلها.
    if (empty($result['ok'])) {
        $fallback = njazTgSend($pdo, $chatId, $text, $keyboard);
        if (empty($fallback['ok'])) {
            njazTgLog($pdo, 'error', 'editMessageText_fallback', ['edit' => $result, 'send' => $fallback], null, null);
        }
    }
    return $result;
}

function njazTgAnswer(PDO $pdo, string $callbackId, string $text = ''): void {
    njazTgApi($pdo, 'answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => false]);
}

function njazTgUser(PDO $pdo, array $from, int $chatId): array {
    $tgId = (int)($from['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM telegram_users WHERE telegram_id=? LIMIT 1");
    $stmt->execute([$tgId]);
    $row = $stmt->fetch();
    if ($row) {
        // تنتهي جلسات الإدخال الحساسة تلقائياً حتى لا تبقى بيانات قديمة قابلة للتنفيذ.
        $state = (string)($row['state'] ?? 'menu');
        $updatedAt = !empty($row['updated_at']) ? strtotime((string)$row['updated_at']) : 0;
        $ttl = 20 * 60;
        $stateData = json_decode((string)($row['state_data'] ?? ''), true);
        $hasPendingNumbersLive = is_array($stateData)
            && is_array($stateData['__numbers_live_pending'] ?? null)
            && (int)($stateData['__numbers_live_pending']['order_id'] ?? 0) > 0;
        if ($state !== 'menu' && !$hasPendingNumbersLive && $updatedAt > 0 && (time() - $updatedAt) > $ttl) {
            $pdo->prepare("UPDATE telegram_users SET state='menu',state_data=NULL WHERE telegram_id=?")
                ->execute([$tgId]);
            $row['state'] = 'menu';
            $row['state_data'] = null;
        }
        $pdo->prepare("UPDATE telegram_users SET chat_id=?,username=?,first_name=?,last_seen_at=NOW() WHERE telegram_id=?")
            ->execute([$chatId, $from['username'] ?? null, $from['first_name'] ?? null, $tgId]);
        return $row;
    }
    $pdo->prepare("INSERT INTO telegram_users (telegram_id,chat_id,username,first_name) VALUES (?,?,?,?)")
        ->execute([$tgId, $chatId, $from['username'] ?? null, $from['first_name'] ?? null]);
    $stmt->execute([$tgId]);
    return $stmt->fetch() ?: ['telegram_id' => $tgId, 'chat_id' => $chatId, 'state' => 'menu', 'state_data' => null, 'user_id' => null];
}
