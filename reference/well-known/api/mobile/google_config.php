<?php
require_once __DIR__ . '/_common.php';
$enabled = getSetting('google_login_enabled');
$clientId = trim((string)getSetting('google_client_id'));
if (!$enabled || !$clientId) jsonOutMobile(false, 'تسجيل الدخول عبر Google غير مفعّل حالياً', [], 503);
jsonOutMobile(true, 'OK', ['enabled'=>true, 'client_id'=>$clientId]);
