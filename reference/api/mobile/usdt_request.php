<?php
// api/mobile/usdt_request.php — يعيد استخدام usdt_deposit_request.php الأصلي عبر جسر الجلسة
// الطلب المتوقع: POST JSON { "amount": 100 } — يمرَّر كما هو للملف الأصلي.
require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true);

$_SERVER['REQUEST_METHOD'] = 'POST';

require dirname(__DIR__, 2) . '/usdt_deposit_request.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
