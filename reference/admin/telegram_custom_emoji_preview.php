<?php
require_once '../includes/config.php';
require_once '../includes/telegram_bot.php';
requireAdmin();

$id = trim((string)($_GET['id'] ?? ''));
if (!preg_match('/^\d{5,64}$/', $id)) {
    http_response_code(400);
    exit;
}

try {
    njazTgEnsureTables($pdo);
    njazTgCustomEmojiEnsure($pdo, $id);
    $stmt = $pdo->prepare('SELECT file_id,thumbnail_file_id FROM telegram_bot_custom_emojis WHERE custom_emoji_id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: [];
    $fileId = trim((string)($row['thumbnail_file_id'] ?? $row['file_id'] ?? ''));
    if ($fileId === '') {
        njazTgCustomEmojiSync($pdo);
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: [];
        $fileId = trim((string)($row['thumbnail_file_id'] ?? $row['file_id'] ?? ''));
    }
    if ($fileId === '') throw new RuntimeException('preview_not_found');

    $file = njazTgApi($pdo, 'getFile', ['file_id' => $fileId]);
    $path = trim((string)($file['result']['file_path'] ?? ''));
    $token = trim((string)(njazTgSettings($pdo)['bot_token'] ?? ''));
    if (empty($file['ok']) || $path === '' || $token === '') throw new RuntimeException('telegram_file_not_found');

    $ch = curl_init('https://api.telegram.org/file/bot' . rawurlencode($token) . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error || $httpCode < 200 || $httpCode >= 300 || !is_string($body) || $body === '') throw new RuntimeException('preview_download_failed');

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'webp' => 'image/webp',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webm' => 'video/webm',
        'tgs' => 'application/gzip',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, max-age=600');
    echo $body;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="20" fill="#1d2938"/><text x="48" y="56" text-anchor="middle" fill="#9eacbd" font-size="13">No preview</text></svg>';
}
