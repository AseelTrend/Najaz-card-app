<?php
// api/mobile/chat.php
// جسر آمن لتشغيل نظام الدعم الحالي مع JWT الخاص بتطبيق الموبايل.
require_once __DIR__ . '/_common.php';

// يتحقق من JWT ويملأ $_SESSION للمستخدم في هذا الطلب فقط،
// ثم نعيد استخدام نظام chat.php الحالي بدون تغيير نظام الموقع.
mobileAuthorizeRequest($pdo, true);

require_once dirname(__DIR__, 2) . '/reference/api/chat.php';
