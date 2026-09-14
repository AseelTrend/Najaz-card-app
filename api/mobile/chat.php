<?php
// api/mobile/chat.php
// جسر آمن لتشغيل نظام الدعم الحالي مع JWT الخاص بتطبيق الموبايل.
require_once __DIR__ . '/_common.php';

// يتحقق من JWT ويملأ $_SESSION للمستخدم في هذا الطلب فقط.
mobileAuthorizeRequest($pdo, true);

// إعادة استخدام نظام الدعم الموجود في api/chat.php.
require dirname(__DIR__) . '/chat.php';
