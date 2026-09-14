<?php
// api/mobile/floosak.php — يعيد استخدام api/floosak_payment.php الأصلي عبر جسر الجلسة
// action = floosak_initiate | floosak_confirm — نفس بارامترات الملف الأصلي بالضبط.
//
// الملف الأصلي يقبل فقط طلبات AJAX (X-Requested-With) أو طلبات داخلية،
// ويرفض أي Referer لا يطابق دومين الموقع. الموبايل لا يرسل Referer أصلاً،
// لذلك نضيف فقط ترويسة AJAX الداخلية هنا دون لمس منطق الدفع نفسه.
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

$_SERVER['REQUEST_METHOD']         = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH']  = 'XMLHttpRequest';
unset($_SERVER['HTTP_REFERER']);

require dirname(__DIR__) . '/floosak_payment.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
