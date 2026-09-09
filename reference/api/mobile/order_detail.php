<?php
// api/mobile/order_detail.php — يعيد استخدام api/order_detail.php الأصلي عبر جسر الجلسة
require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true);

require dirname(__DIR__) . '/order_detail.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
