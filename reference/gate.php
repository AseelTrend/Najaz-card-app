<?php
// bypass كامل للحمايات
$_SERVER['REQUEST_URI'] = '/api/sms_webhook.php';
require __DIR__ . '/api/sms_webhook.php';