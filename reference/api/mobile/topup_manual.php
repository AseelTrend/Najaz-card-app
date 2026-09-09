<?php
// ============================================
// api/mobile/topup_manual.php — إرسال طلب شحن يدوي (تحويل بنكي/محفظة + إيصال)
// نفس منطق topup.php بالضبط لكن يرجع JSON بدل إعادة توجيه،
// ويقبل رفع ملف الإيصال بصيغة multipart/form-data.
// ============================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/payment_method_currency_helper.php';

$userId = mobileAuthorizeRequest($pdo, true);

$methodId     = (int)($_POST['method_id'] ?? 0);
$amountSent   = (float)($_POST['amount_sent'] ?? 0);
$currencyCode = strtoupper(trim($_POST['currency_code'] ?? 'USD'));
$notes        = trim($_POST['notes'] ?? '');

if (!$methodId || $amountSent <= 0) {
    jsonOutMobile(false, 'بيانات غير صحيحة');
}

// التحقق من الوسيلة على الخادم
$methodStmt = $pdo->prepare("SELECT id, payment_mode, status FROM payment_methods WHERE id=? LIMIT 1");
$methodStmt->execute([$methodId]);
$methodRow = $methodStmt->fetch(PDO::FETCH_ASSOC);
if (!$methodRow || (int)$methodRow['status'] !== 1 || ($methodRow['payment_mode'] ?? 'manual') !== 'manual') {
    jsonOutMobile(false, 'وسيلة الدفع غير متاحة');
}

try {
    if (!paymentMethodCurrencyAllowed($pdo, $methodId, $currencyCode)) {
        jsonOutMobile(false, 'هذه العملة غير متاحة مع وسيلة الدفع المختارة');
    }
} catch (Throwable $e) {
    jsonOutMobile(false, 'تعذر التحقق من إعدادات وسيلة الدفع');
}

$rateStmt = $pdo->prepare("SELECT * FROM exchange_rates WHERE currency_code=? AND status=1");
$rateStmt->execute([$currencyCode]);
$rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
if (!$rate) {
    jsonOutMobile(false, 'عملة غير معتمدة');
}

$amountUSD = $amountSent * $rate['rate_to_usd'];

// رفع إيصال الدفع (اختياري لكن يُفضّل إرفاقه)
$receiptPath = null;
if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['receipt'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    $allowedExts  = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ((in_array($file['type'], $allowedTypes) || in_array($ext, $allowedExts)) && $file['size'] <= 5 * 1024 * 1024) {
        $dir = dirname(__DIR__, 2) . '/assets/uploads/receipts/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'receipt_' . $userId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            $receiptPath = 'assets/uploads/receipts/' . $filename;
        }
    } else {
        jsonOutMobile(false, 'صيغة الملف غير مدعومة أو الحجم أكبر من 5MB');
    }
}

$pdo->prepare("INSERT INTO topup_requests (user_id,method_id,amount_sent,currency_code,amount_usd,receipt_image,notes) VALUES (?,?,?,?,?,?,?)")
    ->execute([$userId, $methodId, $amountSent, $currencyCode, $amountUSD, $receiptPath, $notes]);

jsonOutMobile(true, 'تم إرسال طلب الشحن بنجاح! سيتم مراجعته وإضافة $' . number_format($amountUSD, 4) . ' لرصيدك قريباً ✅', [
    'amount_usd' => $amountUSD,
]);
