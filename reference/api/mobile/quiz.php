<?php
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// لا نسمح بالمشاركة إلا للحسابات الموثقة فعلياً.
function requireVerifiedQuizUser(PDO $pdo, int $userId): void {
    try {
        $stmt = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? AND status='approved' LIMIT 1");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            jsonOutMobile(false, 'يجب توثيق حسابك للمشاركة في المسابقات', ['error' => 'kyc'], 403);
        }
    } catch (Throwable $e) {
        // إذا تعذر التحقق من جدول التوثيق، نمنع المشاركة احتياطياً.
        jsonOutMobile(false, 'تعذر التحقق من حالة توثيق الحساب', ['error' => 'kyc_check'], 503);
    }
}

if ($action === 'submit') {
    requireVerifiedQuizUser($pdo, $userId);
}

// بعد المصادقة نعيد استخدام نفس منطق المسابقات الموجود في الموقع.
// mobileAuthorizeRequest يملأ $_SESSION['user_id'] لنفس الطلب، لذلك quiz.php
// يقرأ المستخدم الحالي بنفس الطريقة التي يعمل بها الموقع.
require dirname(__DIR__) . '/quiz.php';
