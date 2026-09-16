<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$payments = file_get_contents($root . '/admin/payments.php');
$mapping = file_get_contents($root . '/includes/payment_method_currency_helper.php');

$checks = [];
$checks[] = [
    'تهيئة مخطط الخرائط قبل beginTransaction',
    strpos($payments, 'paymentMethodCashboxEnsureSchema($pdo);') < strpos($payments, '$pdo->beginTransaction();'),
];
$checks[] = [
    'منع DDL داخل معاملة في مساعد مخطط الخرائط',
    strpos($mapping, 'if ($pdo->inTransaction()) return;') !== false,
];
$checks[] = [
    'حل الصندوق ما زال مستدعياً بعد تهيئة المخطط',
    strpos($payments, 'paymentMethodCashboxEnsureSchema($pdo);') < strpos($payments, 'paymentMethodCashboxResolve($pdo, (int)$req[\'method_id\'], $currencyCode);'),
];
$checks[] = [
    'وجود commit للمعاملة المالية',
    strpos($payments, '$pdo->commit();') !== false,
];
$checks[] = [
    'تسجيل نجاح commit قبل سجل النشاط',
    strpos($payments, '$transactionCommitted = true;') < strpos($payments, 'logStaffAction('),
];
$checks[] = [
    'عدم محاولة rollback بعد commit',
    strpos($payments, 'if ($transactionCommitted) {') < strpos($payments, 'if ($pdo->inTransaction()) $pdo->rollBack();'),
];
$checks[] = [
    'حماية سجل النشاط بعد commit',
    strpos($payments, 'Topup post-commit audit log failed') !== false,
];
$checks[] = [
    'إبقاء rollback للفشل قبل commit',
    substr_count($payments, '$pdo->rollBack();') >= 1,
];

$failed = 0;
foreach ($checks as [$label, $ok]) {
    echo ($ok ? 'PASS' : 'FAIL') . " — {$label}\n";
    if (!$ok) $failed++;
}

echo "النتيجة: " . (count($checks) - $failed) . '/' . count($checks) . " تحققاً ناجحاً\n";
exit($failed ? 1 : 0);
