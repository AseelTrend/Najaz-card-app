<?php
/**
 * جسر آمن لتشغيل كبينة السداد من تطبيق الموبايل.
 *
 * التطبيق يستخدم JWT، بينما نظام كبينة السداد الحالي يعتمد على
 * جلسة المستخدم ($_SESSION). لذلك نتحقق من JWT أولاً ثم نعيد استخدام
 * endpoint الإنتاج الموجود في /api/telecom.php بدون نسخ منطق السداد.
 */
require_once __DIR__ . '/_common.php';

// يتحقق من JWT ويملأ $_SESSION للمستخدم في هذا الطلب فقط.
mobileAuthorizeRequest($pdo, true);

// إعادة استخدام endpoint كبينة السداد الموجود في /api/telecom.php.
require dirname(__DIR__) . '/telecom.php';
