<?php
require_once '../includes/config.php';
require_once '../includes/cashbox_helper.php';
require_once '../includes/cashbox_helper_autofix.php';

requireStaffOrAdmin($pdo, 'perm_accounting_view');

$pageTitle = 'الإصلاح الآلي لربط الصناديق — ' . SITE_NAME;
$canFix = isAdmin() || canAccess($pdo, 'perm_accounting_edit');
$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cashbox_autofix'])) {
    if (!$canFix) {
        http_response_code(403);
        $error = 'لا تملك صلاحية تطبيق إصلاحات المحاسبة.';
    } elseif (!adminCsrfVerify($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        $error = 'انتهت صلاحية النموذج. أعد تحميل الصفحة ثم حاول مرة أخرى.';
    } elseif (($_POST['confirmation'] ?? '') !== 'FIX-CASHBOX-LINKS') {
        $error = 'يجب كتابة رمز التأكيد بشكل صحيح قبل التطبيق.';
    } else {
        $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $result = cashboxCurrencyDefaultsAutoFix($pdo, [], true, $createdBy);
    }
} else {
    // GET = معاينة فقط، ولا يوجد أي تعديل.
    $result = cashboxCurrencyDefaultsAutoFix($pdo, [], false, null);
}

function cashboxAutofixEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function cashboxAutofixIssueLabel(string $issue): string
{
    return [
        'duplicate_account_code' => 'تكرار كود الحساب',
        'duplicate_cashbox_code' => 'تكرار كود الصندوق',
        'account_currency_conflict' => 'تعارض عملة الحساب',
        'cashbox_currency_conflict' => 'تعارض عملة الصندوق',
        'cashbox_role_conflict' => 'تعارض دور الصندوق',
        'account_properties_protected_by_references' => 'خصائص الحساب محمية بمراجع',
        'cashbox_account_link_protected_by_references' => 'ربط الصندوق محمي بمراجع',
        'cashbox_status_protected_by_references' => 'حالة الصندوق محمية بمراجع',
        'missing_account_for_cashbox' => 'الحساب المقابل غير موجود',
    ][$issue] ?? $issue;
}

include 'header.php';
?>
<style>
.af-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.af-kpi{padding:16px;border:1px solid var(--border);border-radius:10px;background:var(--card)}
.af-kpi strong{display:block;font-size:1.35rem;margin-top:5px}
.af-ok{color:#00a878;font-weight:700}.af-bad{color:#d6455d;font-weight:700}.af-muted{color:var(--text3);font-size:.84rem}
.af-table{min-width:1300px}.af-table td,.af-table th{white-space:nowrap;vertical-align:top}
.af-pill{display:inline-block;margin:2px;padding:3px 7px;border-radius:5px;background:rgba(214,69,93,.12);color:#d6455d;font-size:.78rem}
.af-confirm{max-width:700px;margin-top:16px}.af-warning{border-right:4px solid #d6455d}
@media(max-width:900px){.af-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.af-grid{grid-template-columns:1fr}}
</style>

<div class="page-header">
    <div>
        <h2><i class="fas fa-tools" style="color:var(--cyan)"></i> الإصلاح الآلي لربط الصناديق</h2>
        <p>معاينة وإصلاح محافظ للحسابات والصناديق الآلية لكل عملة نشطة.</p>
    </div>
    <div>
        <a href="cashbox_currency_audit.php" class="btn btn-secondary"><i class="fas fa-shield-alt"></i> التدقيق فقط</a>
        <a href="cashbox_currency_autofix.php" class="btn btn-primary"><i class="fas fa-sync-alt"></i> إعادة المعاينة</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?=cashboxAutofixEsc($error)?></div>
<?php endif; ?>

<?php if (($result['status'] ?? '') === 'schema_unavailable' || ($result['status'] ?? '') === 'error'): ?>
    <div class="alert alert-danger">
        <?=cashboxAutofixEsc($result['message'] ?? 'تعذر قراءة مخطط المحاسبة.')?>
    </div>
<?php elseif ($result): ?>
    <?php $blockedCount = (int)($result['blocked_count'] ?? 0); $actionCount = (int)($result['action_count'] ?? 0); $isApplied = (($result['status'] ?? '') === 'applied'); ?>
    <div class="alert <?=!empty($result['ok']) ? 'alert-success' : 'alert-danger'?>">
        <?php if ($isApplied): ?>
            <?=cashboxAutofixEsc($result['message'] ?? 'اكتمل الإصلاح الآلي.')?> تم تنفيذ <?=cashboxAutofixEsc($result['applied'] ?? 0)?> عملية داخل معاملة واحدة.
        <?php elseif ($blockedCount > 0): ?>
            توجد صفوف محمية أو متعارضة. لم يتم تطبيق أي تغيير.
        <?php elseif ($actionCount > 0): ?>
            هذه معاينة فقط. سيحتاج <?=cashboxAutofixEsc($actionCount)?> صفاً إلى إصلاح إذا وافقت على التطبيق.
        <?php else: ?>
            لا توجد إصلاحات مطلوبة.
        <?php endif; ?>
        <span class="af-muted"> — وضع القراءة فقط: <?=!empty($result['read_only']) ? 'نعم' : 'لا'?></span>
    </div>

    <div class="af-grid">
        <div class="af-kpi"><span class="af-muted">العملات النشطة</span><strong><?=cashboxAutofixEsc($result['currency_count'] ?? 0)?></strong></div>
        <div class="af-kpi"><span class="af-muted">صفوف الفحص</span><strong><?=cashboxAutofixEsc($result['row_count'] ?? 0)?></strong></div>
        <div class="af-kpi"><span class="af-muted">إجراءات مخططة</span><strong><?=cashboxAutofixEsc($actionCount)?></strong></div>
        <div class="af-kpi"><span class="af-muted">صفوف محمية</span><strong class="<?=($blockedCount ? 'af-bad' : 'af-ok')?>"><?=cashboxAutofixEsc($blockedCount)?></strong></div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-wrap">
                <table class="af-table">
                    <thead><tr><th>الحالة</th><th>العملة</th><th>الدور</th><th>الحساب</th><th>الصندوق</th><th>المراجع</th><th>الإجراءات</th><th>الأسباب</th></tr></thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <?php $status = (string)($row['status'] ?? ''); ?>
                        <tr>
                            <td class="<?=$status === 'blocked' ? 'af-bad' : ($status === 'ok' || $status === 'fixed' ? 'af-ok' : '')?>">
                                <?=cashboxAutofixEsc(['ok'=>'سليم','planned'=>'سيُصلح','fixed'=>'تم الإصلاح','blocked'=>'محمي/متعارض'][$status] ?? $status)?>
                            </td>
                            <td><b><?=cashboxAutofixEsc($row['currency_code'] ?? '')?></b><div class="af-muted"><?=cashboxAutofixEsc($row['currency_name'] ?? '')?></div></td>
                            <td><?=($row['role'] ?? '') === 'normal' ? 'عادي' : 'تشغيلي'?></td>
                            <td><?=cashboxAutofixEsc($row['account_id'] ?? 'مفقود')?><div class="af-muted"><?=cashboxAutofixEsc($row['expected_account_code'] ?? '')?></div></td>
                            <td><?=cashboxAutofixEsc($row['cashbox_id'] ?? 'مفقود')?><div class="af-muted"><?=cashboxAutofixEsc($row['expected_cashbox_code'] ?? '')?></div></td>
                            <td>حساب: <?=cashboxAutofixEsc($row['account_refs'] ?? 0)?><br>صندوق: <?=cashboxAutofixEsc($row['cashbox_refs'] ?? 0)?></td>
                            <td><?=implode('، ', array_map('cashboxAutofixEsc', $row['actions'] ?? [])) ?: 'لا يوجد'?></td>
                            <td><?php foreach (($row['issues'] ?? []) as $issue): ?><span class="af-pill"><?=cashboxAutofixEsc(cashboxAutofixIssueLabel((string)$issue))?></span><?php endforeach; ?><?php if (empty($row['issues'])): ?><span class="af-ok">لا يوجد</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!$isApplied && $canFix && $actionCount > 0 && $blockedCount === 0): ?>
        <div class="card af-confirm af-warning">
            <div class="card-body">
                <h3 style="margin-top:0">تطبيق الإصلاح بعد مراجعة المعاينة</h3>
                <p>سيتم إنشاء أو تصحيح الحسابات والصناديق الآلية القابلة للإصلاح فقط. ستُوقف العملية بالكامل إذا ظهر تعارض، ولن تُحذف أي قيود أو صناديق لها مراجع.</p>
                <form method="post" onsubmit="return confirm('سيتم تطبيق إصلاحات الربط داخل معاملة واحدة. هل راجعت المعاينة وتريد المتابعة؟');">
                    <?=adminCsrfField()?>
                    <input type="hidden" name="run_cashbox_autofix" value="1">
                    <div class="form-group">
                        <label>اكتب رمز التأكيد: <code>FIX-CASHBOX-LINKS</code></label>
                        <input name="confirmation" required autocomplete="off" placeholder="FIX-CASHBOX-LINKS">
                    </div>
                    <button class="btn btn-danger"><i class="fas fa-wrench"></i> تطبيق الإصلاح الآمن</button>
                </form>
            </div>
        </div>
    <?php elseif (!$canFix && $actionCount > 0): ?>
        <div class="alert alert-warning">المعاينة متاحة، لكن تطبيق الإصلاح يتطلب صلاحية تعديل المحاسبة أو حساب مدير.</div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
