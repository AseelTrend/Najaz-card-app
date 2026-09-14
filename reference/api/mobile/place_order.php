<?php
// api/mobile/place_order.php
// ─────────────────────────────────────────────────────────
// هذا الملف لا يعيد كتابة منطق الطلبات (تسعير، كوبونات، مخزون
// الأكواد، محاسبة...) بل يستخدم بالضبط نفس ملف الموقع الأصلي
// api/place_order_ajax.php عن طريق تمرير الجلسة له.
// هذا يضمن أن كل الطلبات القادمة من التطبيق تُعامَل بنفس القواعد
// المالية المستخدمة بالموقع تماماً، بدون فرصة لخطأ في إعادة الكتابة.
// ─────────────────────────────────────────────────────────
require_once __DIR__ . '/_common.php';

mobileAuthorizeRequest($pdo, true); // إجباري — يملأ $_SESSION['user_id']

// قراءة الطلب بصيغة JSON من التطبيق وتحويله لنفس صيغة $_POST المتوقعة
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$_POST['service_id']   = $input['service_id']   ?? ($_POST['service_id']   ?? 0);
$_POST['quantity']     = $input['quantity']     ?? ($_POST['quantity']     ?? 1);
$_POST['fields']       = $input['fields']       ?? ($_POST['fields']       ?? []);
$_POST['coupon_code']  = $input['coupon_code']  ?? ($_POST['coupon_code']  ?? '');
$_SERVER['REQUEST_METHOD'] = 'POST';

require dirname(__DIR__) . '/place_order_ajax.php'; // نفس الملف الأصلي — ينهي الطلب بنفسه (exit)
