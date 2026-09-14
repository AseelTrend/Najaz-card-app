<?php
// api/mobile/binance_deposit.php — يعيد استخدام api/binance_pay_deposit.php الأصلي عبر جسر الجلسة
// action = create | verify | list — نفس بارامترات الملف الأصلي بالضبط.
//
// الملف الأصلي يتطلب csrf_token مطابق لجلسة الموقع (حماية من CSRF بالمتصفح).
// هذا غير ذي معنى لتطبيق الموبايل (الحماية هنا هي JWT في Authorization header)،
// لذلك نولّد توكن هنا ونضعه بالجلسة المؤقتة لهذا الطلب فقط قبل استدعاء الملف الأصلي،
// دون أي تعديل على منطق الأمان أو المحاسبة داخل الملف نفسه.
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

$_SERVER['REQUEST_METHOD'] = 'POST';
$csrf = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrf;
$_POST['csrf_token']    = $csrf;

require dirname(__DIR__) . '/binance_pay_deposit.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه
