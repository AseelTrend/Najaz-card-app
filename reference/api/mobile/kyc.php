<?php
// api/mobile/kyc.php — جسر توثيق الهوية من تطبيق الموبايل
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

// تمرير الطلب لملف التحقق الأصلي
require dirname(__DIR__) . '/kyc_submit.php';