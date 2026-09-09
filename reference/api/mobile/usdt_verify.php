<?php
// api/mobile/usdt_verify.php — يعيد استخدام usdt_verify_tx.php الأصلي عبر جسر الجلسة
// الطلب المتوقع: POST JSON { "request_id": 5, "tx_id": "0x..." } — يمرَّر كما هو.
require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true);

$_SERVER['REQUEST_METHOD'] = 'POST';

require dirname(__DIR__, 2) . '/usdt_verify_tx.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
