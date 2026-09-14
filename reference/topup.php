<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/payment_method_currency_helper.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(SITE_URL . '/mobile.php');
}

$methodId    = (int)($_POST['method_id'] ?? 0);
$amountSent  = (float)($_POST['amount_sent'] ?? 0);
$currencyCode= strtoupper(trim($_POST['currency_code'] ?? 'USD'));
$notes       = trim($_POST['notes'] ?? '');

if (!$methodId || $amountSent <= 0) {
    flashMessage('danger', 'بيانات غير صحيحة');
    redirect(SITE_URL . '/mobile.php#wallet');
}

// التحقق من الوسيلة والعملات المسموحة على الخادم، وليس في الواجهة فقط.
$methodStmt = $pdo->prepare("SELECT id, payment_mode, status FROM payment_methods WHERE id=? LIMIT 1");
$methodStmt->execute([$methodId]);
$methodRow = $methodStmt->fetch(PDO::FETCH_ASSOC);
if (!$methodRow || (int)$methodRow['status'] !== 1 || ($methodRow['payment_mode'] ?? 'manual') !== 'manual') {
    flashMessage('danger', 'وسيلة الدفع غير متاحة');
    redirect(SITE_URL . '/mobile.php#wallet');
}
try {
    if (!paymentMethodCurrencyAllowed($pdo, $methodId, $currencyCode)) {
        flashMessage('danger', 'هذه العملة غير متاحة مع وسيلة الدفع المختارة');
        redirect(SITE_URL . '/mobile.php#wallet');
    }
} catch (Throwable $e) {
    error_log('Payment method currency validation failed: ' . $e->getMessage());
    flashMessage('danger', 'تعذر التحقق من إعدادات وسيلة الدفع');
    redirect(SITE_URL . '/mobile.php#wallet');
}

// جلب سعر الصرف
$rate = $pdo->prepare("SELECT * FROM exchange_rates WHERE currency_code=? AND status=1");
$rate->execute([$currencyCode]); $rate = $rate->fetch();
if (!$rate) {
    flashMessage('danger', 'عملة غير معتمدة');
    redirect(SITE_URL . '/mobile.php#wallet');
}

// حساب المبلغ بالدولار
$amountUSD = $amountSent * $rate['rate_to_usd'];

// رفع إيصال الدفع
$receiptPath = null;
if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['receipt'];
    $allowedTypes = ['image/jpeg','image/png','image/webp','image/gif','application/pdf'];
    $allowedExts  = ['jpg','jpeg','png','webp','gif','pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($file['type'], $allowedTypes) || in_array($ext, $allowedExts)) {
        if ($file['size'] <= 5 * 1024 * 1024) {
            $dir = __DIR__ . '/assets/uploads/receipts/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'receipt_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $receiptPath = 'assets/uploads/receipts/' . $filename;
            }
        }
    }
}

// حفظ الطلب
$pdo->prepare("INSERT INTO topup_requests (user_id,method_id,amount_sent,currency_code,amount_usd,receipt_image,notes) VALUES (?,?,?,?,?,?,?)")
    ->execute([$_SESSION['user_id'], $methodId, $amountSent, $currencyCode, $amountUSD, $receiptPath, $notes]);

flashMessage('success', 'تم إرسال طلب الشحن بنجاح! سيتم مراجعته وإضافة $' . number_format($amountUSD, 4) . ' لرصيدك قريباً ✅');
redirect(SITE_URL . '/mobile.php');
