<?php
$root = dirname(__DIR__);
$autofix = file_get_contents($root . '/includes/cashbox_helper_autofix.php');
$page = file_get_contents($root . '/admin/cashbox_currency_autofix.php');

$checks = [
    'دالة Auto-fix موجودة' => strpos($autofix, 'function cashboxCurrencyDefaultsAutoFix') !== false,
    'وضع dry-run موجود' => strpos($autofix, 'bool $apply = false') !== false,
    'المعاينة لا تنفذ تعديلاً' => strpos($autofix, "'read_only' => !\$apply") !== false,
    'المعاملة تبدأ عند التطبيق' => strpos($autofix, '$pdo->beginTransaction()') !== false,
    'المعاملة تلتزم بعد النجاح' => strpos($autofix, '$pdo->commit()') !== false,
    'المعاملة تتراجع عند الفشل' => strpos($autofix, '$pdo->rollBack()') !== false,
    'منع التطبيق الجزئي' => strpos($autofix, 'if ($apply && $blockedCount > 0)') !== false,
    'فحص مراجع القيود' => strpos($autofix, 'accounting_journal') !== false,
    'فحص مراجع حركات الصندوق' => strpos($autofix, 'accounting_cashbox_movements') !== false,
    'منع حذف البيانات' => strpos($autofix, 'DELETE FROM') === false,
    'عدم استدعاء تهيئة صامتة' => strpos($autofix, 'cashboxEnsureCurrencyDefaults') === false,
    'صفحة الإدارة محمية' => strpos($page, "requireStaffOrAdmin(\$pdo, 'perm_accounting_view')") !== false,
    'صلاحية التطبيق منفصلة' => strpos($page, "canAccess(\$pdo, 'perm_accounting_edit')") !== false,
    'حماية CSRF' => strpos($page, 'adminCsrfVerify') !== false && strpos($page, 'adminCsrfField') !== false,
    'تأكيد يدوي إضافي' => strpos($page, 'FIX-CASHBOX-LINKS') !== false,
    'طلب POST فقط للتطبيق' => strpos($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'") !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode(' | ', $failed) . PHP_EOL);
    exit(1);
}
echo 'PASS: cashbox autofix safety static checks (' . count($checks) . ')' . PHP_EOL;
