<?php
// api/mobile/google_config.php — الإعداد العام لتسجيل Google في تطبيق الهاتف
require_once __DIR__ . '/_common.php';

$enabled = getSetting('google_login_enabled');
$clientId = trim((string)getSetting('google_client_id'));

if (!$enabled || !$clientId) {
    jsonOutMobile(false, 'تسجيل الدخول عبر Google غير مفعّل حالياً', [], 503);
}

// Client ID معلومة عامة مطلوبة لتهيئة Google Sign-In في التطبيق.
// لا نرسل Client Secret إلى التطبيق أبداً.
jsonOutMobile(true, 'OK', [
    'enabled' => true,
    'client_id' => $clientId,
]);
