<?php
// api/mobile/card_redeem.php — يعيد استخدام api/redeem_card.php الأصلي عبر جسر الجلسة
// (نفس أسلوب place_order.php: لا نعيد كتابة منطق الأمان/الحظر/المحاسبة إطلاقاً)
require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true);

$_POST['code'] = $_POST['code'] ?? '';
$_SERVER['REQUEST_METHOD'] = 'POST';

require dirname(__DIR__) . '/redeem_card.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه (exit)
