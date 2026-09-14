<?php
/**
 * Telegram webhook endpoint.
 * يوضع في جذر المشروع: /telegram_webhook.php
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/telegram_bot.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $settings = njazTgSettings($pdo);
    $secret = (string)($settings['webhook_secret'] ?? '');
    $incomingSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($secret === '' || $incomingSecret === '' || !hash_equals($secret, $incomingSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    if ((int)($settings['enabled'] ?? 0) !== 1) {
        echo json_encode(['ok' => true, 'disabled' => true]);
        exit;
    }

    $raw = file_get_contents('php://input');
    $update = json_decode($raw ?: '', true);
    if (!is_array($update)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }

    njazTgProcessUpdate($pdo, $update);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('Telegram webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}
?>
