<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
accountingEnsureSchema($pdo);
cashboxEnsureSchema($pdo);
cashboxEnsureProviderColumn($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
$canEdit = isAdmin() || canAccess($pdo, 'perm_accounting_edit');
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();
$pageTitle = 'الصناديق التشغيلية — ' . SITE_NAME;
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cashbox'])) {
    if (!$canEdit) { flashMessage('danger', 'ليس لديك صلاحية إدارة الصناديق'); redirect(SITE_URL . '/admin/accounting_cashboxes.php'); }
    $boxId = (int)($_POST['cashbox_id'] ?? 0);
    $code = trim((string)($_POST['code'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $staffId = (int)($_POST['staff_id'] ?? 0) ?: null;
    $accountId = (int)($_POST['account_id'] ?? 0) ?: null;
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'USD')));
    $initial = (float)($_POST['initial_balance'] ?? 0);
    $status = (string)($_POST['status'] ?? 'active');
    $notes = trim((string)($_POST['notes'] ?? ''));
    try {
        if (!preg_match('/^[A-Za-z0-9_-]{2,50}$/', $code)) throw new InvalidArgumentException('رقم الصندوق يجب أن يتكون من 2 إلى 50 حرفاً أو رقماً');
        if ($name === '') throw new InvalidArgumentException('اسم الصندوق مطلوب');
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) throw new InvalidArgumentException('رمز العملة غير صالح');
        if ($initial < 0) throw new InvalidArgumentException('الرصيد الافتتاحي لا يمكن أن يكون سالباً');
        if (!in_array($status, ['active','suspended','closed'], true)) throw new InvalidArgumentException('حالة الصندوق غير صالحة');
        if ($boxId) {
            $oldSt = $pdo->prepare('SELECT * FROM accounting_cashboxes WHERE id=? FOR UPDATE');
            $oldSt->execute([$boxId]);
            $old = $oldSt->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new InvalidArgumentException('الصندوق المطلوب تعديله غير موجود');
            $movementSt = $pdo->prepare("SELECT COUNT(*) FROM accounting_cashbox_movements WHERE cashbox_id=? AND status='posted'");
            $movementSt->execute([$boxId]);
            if ((int)$movementSt->fetchColumn() > 0 && (
                (float)$old['initial_balance'] !== $initial ||
                strtoupper((string)$old['currency_code']) !== $currency ||
                (int)($old['account_id'] ?? 0) !== (int)($accountId ?? 0)
            )) {
                throw new InvalidArgumentException('لا يمكن تغيير الرصيد الافتتاحي أو العملة أو الحساب بعد تسجيل حركات؛ أنشئ صندوقاً جديداً أو استخدم قيد تصحيح موثقاً');
            }
            $st = $pdo->prepare('UPDATE accounting_cashboxes SET code=?,name=?,staff_id=?,account_id=?,currency_code=?,initial_balance=?,status=?,notes=? WHERE id=?');
            $st->execute([$code,$name,$staffId,$accountId,$currency,number_format($initial,8,'.',''),$status,$notes ?: null,$boxId]);
            logStaffAction($pdo, 'تعديل صندوق', 'cashbox', $boxId, $name);
        } else {
            $st = $pdo->prepare('INSERT INTO accounting_cashboxes (code,name,staff_id,account_id,currency_code,initial_balance,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$code,$name,$staffId,$accountId,$currency,number_format($initial,8,'.',''),$status,$notes ?: null,(int)($_SESSION['user_id'] ?? 0) ?: null]);
            $boxId = (int)$pdo->lastInsertId();
            logStaffAction($pdo, 'إضافة صندوق', 'cashbox', $boxId, $name);
        }
        flashMessage('success', 'تم حفظ الصندوق بنجاح');
        redirect(SITE_URL . '/admin/accounting_cashboxes.php');
    } catch (Throwable $e) { $flash = ['type'=>'danger','message'=>$e->getMessage()]; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['toggle_cashbox']) || isset($_POST['delete_cashbox']))) {
    if (!$canEdit) { flashMessage('danger', 'ليس لديك صلاحية إدارة الصناديق'); redirect(SITE_URL . '/admin/accounting_cashboxes.php'); }
    $cashboxId = (int)($_POST['cashbox_id'] ?? 0);
    if ($cashboxId <= 0) { flashMessage('danger', 'الصندوق غير محدد'); redirect(SITE_URL . '/admin/accounting_cashboxes.php'); }
    if (isset($_POST['toggle_cashbox'])) {
        $st=$pdo->prepare("UPDATE accounting_cashboxes SET status=CASE WHEN status='active' THEN 'suspended' ELSE 'active' END WHERE id=? AND status<>'closed'");
        $st->execute([$cashboxId]);
        flashMessage('success', 'تم تحديث حالة الصندوق');
    } else {
        try {
            $st=$pdo->prepare('SELECT COUNT(*) FROM accounting_cashbox_movements WHERE cashbox_id=?'); $st->execute([$cashboxId]);
            $movements=(int)$st->fetchColumn();
            $st=$pdo->prepare('SELECT COUNT(*) FROM accounting_provider_links WHERE cashbox_id=?'); $st->execute([$cashboxId]);
            $links=(int)$st->fetchColumn();
            if ($movements || $links) throw new RuntimeException('لا يمكن حذف صندوق مرتبط بحركات أو ربطيات؛ أوقفه بدلاً من حذف السجل');
            $pdo->prepare('DELETE FROM accounting_cashboxes WHERE id=?')->execute([$cashboxId]);
            logStaffAction($pdo, 'حذف صندوق', 'cashbox', $cashboxId, null);
            flashMessage('success', 'تم حذف الصندوق');
        } catch (Throwable $e) { flashMessage('danger', $e->getMessage()); }
    }
    redirect(SITE_URL . '/admin/accounting_cashboxes.php');
}

$edit = null;
if ($action === 'edit' && $id) { $st=$pdo->prepare('SELECT * FROM accounting_cashboxes WHERE id=?'); $st->execute([$id]); $edit=$st->fetch(PDO::FETCH_ASSOC); }
$staff = $pdo->query("SELECT id,username,full_name FROM users WHERE role IN ('staff','admin') AND COALESCE(is_deleted,0)=0 ORDER BY full_name,username")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query('SELECT id,code,name,currency_code FROM accounting_accounts WHERE status=1 ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$q=trim((string)($_GET['q'] ?? ''));
$filter=$q!==''?'%'.$q.'%':'%';
$st=$pdo->prepare("SELECT c.*,u.username staff_username,u.full_name staff_name,a.code account_code,a.name account_name,
    COALESCE((SELECT COUNT(*) FROM accounting_cashbox_movements m WHERE m.cashbox_id=c.id),0) movement_count,
    COALESCE((SELECT COUNT(*) FROM accounting_provider_links pl WHERE pl.cashbox_id=c.id AND pl.is_active=1),0) linkage_count,
    c.initial_balance + COALESCE((SELECT SUM(CASE WHEN m2.movement_type IN ('payment','transfer_out') THEN -m2.amount ELSE m2.amount END) FROM accounting_cashbox_movements m2 WHERE m2.cashbox_id=c.id AND m2.status='posted'),0) current_balance
    FROM accounting_cashboxes c LEFT JOIN users u ON u.id=c.staff_id LEFT JOIN accounting_accounts a ON a.id=c.account_id
    WHERE c.code LIKE ? OR c.name LIKE ? OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.full_name,'') LIKE ? OR COALESCE(a.code,'') LIKE ? OR COALESCE(a.name,'') LIKE ?
    ORDER BY c.status='active' DESC,c.name,c.id DESC");
$st->execute([$filter,$filter,$filter,$filter,$filter,$filter]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
$flash=$flash ?: getFlash();
include 'header.php';
?>
<style>
.cb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.cb-grid .full{grid-column:1/-1}.cb-table{min-width:1180px}.cb-table td,.cb-table th{white-space:nowrap}.cb-muted{color:var(--text3);font-size:.82rem}.cb-positive{color:#00d4aa}.cb-negative{color:#ff8f9d}.cb-status-active{color:#00d4aa}.cb-status-suspended{color:#f5c26b}.cb-status-closed{color:#ff8f9d}@media(max-width:800px){.cb-grid{grid-template-columns:1fr}}
</style>
<?php if($flash): ?><div class="alert alert-<?=htmlspecialchars($flash['type'])?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<div class="page-header"><div><h2><i class="fas fa-cash-register" style="color:var(--cyan)"></i> الصناديق التشغيلية</h2><p>مصادر مالية مستقلة مرتبطة بموظف وحساب وربطيات مزودين — الرصيد مبني على حركات فعلية فقط.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a href="accounting_cashbox_movements.php" class="btn btn-secondary"><i class="fas fa-exchange-alt"></i> يومية وحركات الصناديق</a><?php if($canEdit): ?><a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> صندوق جديد</a><?php endif; ?></div></div>
<div class="card"><div class="card-body"><form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><div class="form-group" style="flex:1;min-width:260px;margin:0"><label>بحث</label><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="رقم الصندوق، الاسم، الموظف أو الحساب"></div><button class="btn btn-secondary"><i class="fas fa-search"></i> بحث</button><?php if($q): ?><a href="accounting_cashboxes.php" class="btn btn-secondary">مسح</a><?php endif; ?></form></div></div>
<?php if(in_array($action,['add','edit'],true)): ?><div class="card"><div class="card-body"><h3 style="margin-top:0"><i class="fas fa-cash-register"></i> <?=$edit?'تعديل الصندوق':'إضافة صندوق'?></h3><form method="post"><input type="hidden" name="cashbox_id" value="<?=$edit['id']??0?>"><input type="hidden" name="save_cashbox" value="1"><?=adminCsrfField()?><div class="cb-grid"><div class="form-group"><label>رقم الصندوق</label><input name="code" required maxlength="50" value="<?=htmlspecialchars($edit['code']??'')?>" placeholder="مثال: MC-01"></div><div class="form-group"><label>اسم الصندوق</label><input name="name" required maxlength="200" value="<?=htmlspecialchars($edit['name']??'')?>" placeholder="مثال: مباشر كاش"></div><div class="form-group"><label>الموظف المسؤول</label><select name="staff_id"><option value="">غير مرتبط بموظف</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>" <?=((int)($edit['staff_id']??0)==$s['id'])?'selected':''?>><?=htmlspecialchars($s['full_name']?:$s['username'])?> — <?=htmlspecialchars($s['username'])?></option><?php endforeach; ?></select></div><div class="form-group"><label>الحساب المحاسبي للصندوق</label><select name="account_id"><option value="">اختر الحساب المحاسبي</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)($edit['account_id']??0)==$a['id'])?'selected':''?>><?=htmlspecialchars($a['code'].' — '.$a['name'].' ('.$a['currency_code'].')')?></option><?php endforeach; ?></select><small class="cb-muted">مطلوب قبل إنشاء قبض أو صرف مرحّل.</small></div><div class="form-group"><label>العملة</label><input name="currency_code" maxlength="10" value="<?=htmlspecialchars($edit['currency_code']??'USD')?>"></div><div class="form-group"><label>الرصيد الافتتاحي الفعلي</label><input type="number" name="initial_balance" step="0.00000001" min="0" value="<?=htmlspecialchars((string)($edit['initial_balance']??'0'))?>"><small class="cb-muted">لا يُحتسب كربح ولا يُنشئ قيداً تلقائياً.</small></div><div class="form-group"><label>الحالة</label><select name="status"><option value="active" <?=($edit['status']??'active')==='active'?'selected':''?>>نشط</option><option value="suspended" <?=($edit['status']??'')==='suspended'?'selected':''?>>موقوف مؤقتاً</option><option value="closed" <?=($edit['status']??'')==='closed'?'selected':''?>>مغلق</option></select></div><div class="form-group full"><label>ملاحظات</label><textarea name="notes" rows="2" maxlength="1000" placeholder="وصف الصندوق أو قواعد المطابقة"><?=htmlspecialchars($edit['notes']??'')?></textarea></div></div><div style="display:flex;gap:8px;margin-top:15px"><button class="btn btn-primary"><i class="fas fa-save"></i> حفظ الصندوق</button><a href="accounting_cashboxes.php" class="btn btn-secondary">إلغاء</a></div></form></div></div><?php endif; ?>
<div class="card"><div class="card-body"><div class="table-wrap"><table class="cb-table"><thead><tr><th>الحالة</th><th>الصندوق</th><th>الموظف</th><th>الحساب</th><th>العملة</th><th>الرصيد الافتتاحي</th><th>الرصيد الفعلي</th><th>الحركات</th><th>الربطيات</th><th>إجراءات</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><span class="cb-status-<?=htmlspecialchars($r['status'])?>"><?=htmlspecialchars(['active'=>'نشط','suspended'=>'موقوف','closed'=>'مغلق'][$r['status']]??$r['status'])?></span></td><td><b><?=htmlspecialchars($r['name'])?></b><div class="cb-muted"><?=htmlspecialchars($r['code'])?> — #<?=$r['id']?></div></td><td><?=htmlspecialchars($r['staff_name']?:$r['staff_username']?:'—')?></td><td><?=htmlspecialchars(($r['account_code']?$r['account_code'].' — ':'').($r['account_name']?:'غير مربوط'))?></td><td><?=htmlspecialchars($r['currency_code'])?></td><td><?=number_format((float)$r['initial_balance'],8,'.',',')?></td><td class="<?=((float)$r['current_balance']<0?'cb-negative':'cb-positive')?>"><b><?=number_format((float)$r['current_balance'],8,'.',',')?></b></td><td><a href="accounting_cashbox_movements.php?cashbox_id=<?=$r['id']?>"><?=number_format((int)$r['movement_count'])?></a></td><td><?=number_format((int)$r['linkage_count'])?></td><td style="display:flex;gap:5px"><a class="btn btn-sm btn-secondary" href="?action=edit&id=<?=$r['id']?>">تعديل</a><?php if($canEdit && $r['status']!=='closed'): ?><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="cashbox_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-secondary" name="toggle_cashbox" value="1"><?=$r['status']==='active'?'إيقاف':'تشغيل'?></button></form><?php endif; ?><?php if($canEdit && !$r['movement_count'] && !$r['linkage_count']): ?><form method="post" style="display:inline" onsubmit="return confirm('حذف الصندوق نهائياً؟')"><?=adminCsrfField()?><input type="hidden" name="cashbox_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-danger" name="delete_cashbox" value="1">حذف</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text3)">لا توجد صناديق بعد</td></tr><?php endif; ?></tbody></table></div><div class="cb-muted" style="margin-top:12px">عدد الصناديق: <?=count($rows)?> — لا يتم ربط الصندوق برصيد العميل، ولا تُعرض الأرباح من الرصيد وحده.</div></div></div>
<?php include 'footer.php'; ?>
