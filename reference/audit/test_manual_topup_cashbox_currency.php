<?php
/**
 * فحص ثابت لمسار اعتماد الإيداع اليدوي وربط الصناديق حسب العملة.
 * لا يتصل بقاعدة البيانات ولا ينشئ طلباً أو يعدل رصيداً.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$payments = file_get_contents($root . '/admin/payments.php');
$methods = file_get_contents($root . '/admin/payments_tabs/tab_methods.php');
$requests = file_get_contents($root . '/admin/payments_tabs/tab_requests.php');
$cashbox = file_get_contents($root . '/includes/cashbox_helper.php');
$currencyHelper = file_get_contents($root . '/includes/payment_method_currency_helper.php');
$migration = file_get_contents($root . '/database/migrations/2026_08_24_payment_method_cashboxes.sql');
$ratesAdmin = file_get_contents($root . '/admin/exchange_rates.php');

$approveStart = strpos($payments, "if (\$action === 'approve' && \$id)");
$postCall = strpos($payments, 'cashboxPostDeposit($pdo', $approveStart === false ? 0 : $approveStart);
$balanceUpdate = strpos($payments, 'UPDATE users SET balance=', $approveStart === false ? 0 : $approveStart);
$checks = [
    'يقرأ عملة الطلب من topup_requests' => strpos($payments, "\$currencyCode = strtoupper(trim((string)(\$req['currency_code'] ?? 'USD')))") !== false,
    'يحل الصندوق من method_id وعملة الطلب' => strpos($payments, 'paymentMethodCashboxResolve($pdo, (int)$req[\'method_id\'], $currencyCode)') !== false,
    'لا يثق في cashbox_id المرسل من POST' => strpos($payments, 'الصندوق لا يُؤخذ من POST') !== false,
    'يستخدم الصندوق المربوط تلقائياً في الترحيل' => strpos($payments, '$cashboxId = (int)$cashboxResolution[\'cashbox\'][\'id\'];') !== false,
    'يعرض الصندوق التلقائي في مودال الاعتماد' => strpos($payments, 'approveCashboxInfo') !== false && strpos($methods, 'cashbox_by_currency') !== false,
    'يمرر عملة الطلب والصندوق المعروض للمودال' => strpos($requests, 'openApprove(') !== false && strpos($requests, '$approveCurrency') !== false && strpos($requests, '$approveCashboxLabel') !== false,
    'يمنع الصندوق غير المربوط بالعملة' => strpos($currencyHelper, 'hasNewMapping') !== false && strpos($currencyHelper, 'cashbox_not_configured') !== false,
    'يحافظ على التوافق مع cashbox_id القديم' => strpos($currencyHelper, 'SELECT cashbox_id FROM payment_methods') !== false && strpos($payments, '$legacyCashboxId') !== false,
    'يحفظ عدة صناديق حسب العملات' => strpos($methods, 'name="cashbox_by_currency[') !== false && strpos($currencyHelper, 'paymentMethodCashboxSave') !== false,
    'يقبل رموز العملات العربية والمخصصة' => strpos($currencyHelper, '\\p{L}\\p{N}') !== false && strpos($currencyHelper, "رمز العملة غير صالح") !== false,
    'يتحقق من تطابق عملة الصندوق عند الحفظ' => strpos($currencyHelper, 'عملة الصندوق لا تطابق العملة') !== false,
    'يقفل طلب الشحن ويعيد فحص أنه معلق' => strpos($payments, 'SELECT * FROM topup_requests WHERE id=? FOR UPDATE') !== false && strpos($payments, 'topup_not_pending') !== false,
    'يترحل الصندوق قبل تحديث رصيد العميل' => $postCall !== false && $balanceUpdate !== false && $postCall < $balanceUpdate,
    'لا يعتمد دون نجاح cashboxPostDeposit' => strpos($payments, "if (empty(\$post['posted'])) throw new RuntimeException('cashbox:'") !== false,
    'توجد معاملة وrollback عند أي فشل' => strpos($payments, '$pdo->beginTransaction();') !== false && strpos($payments, '$pdo->rollBack();') !== false && strpos($payments, '$pdo->commit();') !== false,
    'توجد حماية دفترية من شحن الطلب نفسه مرتين' => strpos($payments, "reference_id=? AND sub_type='topup'") !== false && strpos($payments, 'wallet_credit_already_exists') !== false,
    'يربط حركة المحفظة بمرجع الطلب' => strpos($payments, 'description,reference_id,sub_type') !== false && strpos($payments, "'topup'") !== false,
    'يخزن حالة الترحيل الناجح في الطلب' => strpos($cashbox, "cashbox_posting_status='posted'") !== false && strpos($cashbox, 'cashbox_posted_at=NOW()') !== false,
    'يتحقق من حساب الصندوق النشط وعملته' => strpos($cashbox, 'account_status_checked') !== false && strpos($cashbox, 'account_currency_checked') !== false && strpos($cashbox, 'cashbox_currency_mismatch') !== false,
    'يستخدم حساب محفظة العملة نفسها' => strpos($cashbox, "\$counterpartCode = \$currencyCode === 'USD' ? '1100' : '1100-' . \$currencyCode") !== false && strpos($cashbox, 'wallet_account_missing_for_currency') !== false,
    'ينشئ حركة قبضاً بقيد idempotency' => strpos($cashbox, "'movement_type' => 'receipt'") !== false && strpos($cashbox, "'idempotency_key' => \$key") !== false,
    'يرفض حركة بلا قيد' => strpos($cashbox, "throw new RuntimeException('journal_missing')") !== false,
    'ينشئ جدول الربط متعدد العملات' => strpos($migration, 'CREATE TABLE IF NOT EXISTS payment_method_cashboxes') !== false && strpos($migration, 'PRIMARY KEY (method_id, currency_code)') !== false,
    'يسجل سبباً آمناً ومرجع تتبع للمدير' => strpos($payments, '$safeMessages = [') !== false && strpos($payments, '$traceId =') !== false && strpos($payments, "flashMessage('danger','تعذر اعتماد الإيداع: '") !== false,
    'يحدّث سعر الصرف بالمعرّف الحقيقي' => strpos($payments, 'WHERE id=?') !== false && strpos($payments, 'SELECT id,currency_code FROM exchange_rates WHERE id=? FOR UPDATE') !== false,
    'يسجل حذف سعر الصرف بالمعرّف' => strpos($payments, "deleted_rate_ids") !== false && strpos($payments, "DELETE FROM exchange_rates WHERE id=?") !== false,
    'لا يعيد إنشاء الصف القديم عند التعديل' => strpos($payments, 'INSERT INTO exchange_rates') !== false && strpos($payments, 'if ($rowId > 0)') !== false,
    'يعالج الحذف في صفحة أسعار الصرف المستقلة' => strpos($ratesAdmin, 'deleted_rate_ids') !== false && strpos($ratesAdmin, "DELETE FROM exchange_rates WHERE id=?") !== false && strpos($ratesAdmin, '$pdo->beginTransaction();') !== false,
    'يمنع حذف الدولار الأساسي' => strpos($payments, 'لا يمكن حذف الدولار الأساسي') !== false && strpos($ratesAdmin, 'لا يمكن حذف الدولار الأساسي') !== false,
    'ينشئ حساباً مستقلاً لكل دور صندوق' => strpos($cashbox, '1000-CASH-') !== false && strpos($cashbox, "'normal' => ['N'") !== false && strpos($cashbox, "'operational' => ['O'") !== false,
    'يربط الإيداع بالصندوق العادي والتشغيل بالصندوق التشغيلي' => strpos($cashbox, '$defaults[$currency][$role]') !== false && strpos($cashbox, '$defaults[$code][\'normal\']') !== false && strpos($cashbox, '$defaults[$code][\'operational\']') !== false,
    'يحمي حسابات الصناديق القديمة ذات القيود' => strpos($cashbox, 'debit_account_id=? OR credit_account_id=?') !== false && strpos($cashbox, 'accounting_account_links') !== false,
    'يبني كود الحساب وكود الصندوق من الدور نفسه' => strpos($cashbox, "\$accountCode = '1000-CASH-' . \$safeSuffix . '-' . \$roleCode") !== false && strpos($cashbox, "\$cashboxCode = 'AUTO-' . \$safeSuffix . '-' . \$roleCode") !== false,
    'يربط account_id بالحساب الخاص بالدور عند الإدراج والتفعيل' => strpos($cashbox, 'accounting_cashboxes (code,name,account_id,currency_code,status,cashbox_role') !== false && strpos($cashbox, "\$cashboxInsert->execute([\$cashboxCode,\$cashboxName,\$accountId,\$currency,\$role") !== false && strpos($cashbox, "\$cashboxActivate->execute([\$cashboxName,\$accountId,\$currency,\$role") !== false,
    'يفرض للحساب الآلي asset/debit/active/system' => strpos($cashbox, "account_type='asset',nature='debit',currency_code=?,is_system=1,status=1") !== false && strpos($cashbox, "VALUES (?,?,?,?,?,1,1,?)") !== false,
    'يحتوي تدقيقاً لكل صندوق عادي وتشغيلي' => strpos($cashbox, 'function cashboxCurrencyDefaultsAudit') !== false && strpos($cashbox, "'normal' => ['N'") !== false && strpos($cashbox, "'operational' => ['O'") !== false && strpos($cashbox, 'expected_account_code') !== false && strpos($cashbox, 'account_link_mismatch') !== false,
    'يتحقق التدقيق من العملة والدور والحساب النشط' => strpos($cashbox, 'cashbox_currency_mismatch') !== false && strpos($cashbox, 'cashbox_role_mismatch') !== false && strpos($cashbox, 'account_currency_mismatch') !== false && strpos($cashbox, 'account_inactive') !== false && strpos($cashbox, 'account_not_system') !== false,
    'التدقيق قراءة فقط ولا يهيئ المخطط' => ($auditStart = strpos($cashbox, 'function cashboxCurrencyDefaultsAudit')) !== false && ($auditBody = substr($cashbox, $auditStart, strpos($cashbox, "if (!function_exists('cashboxTypeLabel'))", $auditStart) - $auditStart)) !== false && strpos($auditBody, 'INSERT ') === false && strpos($auditBody, 'UPDATE ') === false && strpos($auditBody, 'DELETE ') === false && strpos($auditBody, 'cashboxEnsure') === false,
    'يفحص مراجع القيود والروابط قبل حذف الصندوق القديم' => ($deleteGuard = strpos($cashbox, 'if ($references === 0)')) !== false && strpos($cashbox, 'accounting_journal WHERE debit_account_id=? OR credit_account_id=?') < $deleteGuard && strpos($cashbox, 'accounting_account_links WHERE account_id=?') < $deleteGuard,
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "FAILED\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "PASS: manual topup atomic multi-currency cashbox checks (" . count($checks) . ")\n";
exit(0);
