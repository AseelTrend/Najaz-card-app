<?php
// admin/ajax_test_telegram.php
require_once '../includes/config.php';
requireAdmin();

header('Content-Type: application/json');

$token  = trim($_GET['token'] ?? '');
$chatId = trim($_GET['chat'] ?? '');

if (!$token || !$chatId) {
    echo json_encode(['success' => false, 'error' => 'بيانات ناقصة']);
    exit;
}

$siteName = getSetting('site_name') ?: 'المنصة';
$msg  = "✅ <b>اختبار إشعارات {$siteName}</b>\n";
$msg .= "🕐 " . date('Y/m/d H:i:s') . "\n";
$msg .= "🔔 نظام الإشعارات يعمل بشكل صحيح!";

$url  = "https://api.telegram.org/bot{$token}/sendMessage";
$data = ['chat_id' => $chatId, 'text' => $msg, 'parse_mode' => 'HTML'];

$ch = curl_init($url);
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

$decoded = json_decode($res, true);
if ($decoded && !empty($decoded['ok'])) {
    echo json_encode(['success' => true]);
} else {
    $err = $decoded['description'] ?? 'فشل الإرسال';
    echo json_encode(['success' => false, 'error' => $err]);
}
