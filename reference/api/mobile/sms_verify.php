<?php
// api/mobile/sms_verify.php — يعيد استخدام api/sms_topup_verify.php الأصلي عبر جسر الجلسة
// الطلب المتوقع: POST { phone, amount, provider_id } — نفس بارامترات الملف الأصلي.
// نفس ملاحظة CSRF الموجودة في binance_deposit.php تنطبق هنا.
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

$_SERVER['REQUEST_METHOD'] = 'POST';
$csrf = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf;
$_POST['csrf_token']    = $csrf;

require dirname(__DIR__) . '/sms_topup_verify.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
