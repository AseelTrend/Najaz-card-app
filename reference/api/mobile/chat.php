<?php
// api/mobile/chat.php
// جسر آمن لتشغيل نظام الدعم الحالي مع JWT الخاص بتطبيق الموبايل.

require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true);

require dirname(__DIR__) . '/chat.php';