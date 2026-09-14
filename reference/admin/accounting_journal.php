<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
accountingEnsureSchema($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();
$pageTitle = 'القيود والمطابقة — ' . SITE_NAME;
$canEdit = isAdmin() || canAccess($pdo, 'perm_accounting_edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    try {
        if (isset($_POST['post_journal'])) {
            $entryId = accountingCreateJournalEntry($pdo, [
                'debit_account_id'=>(int)($_POST['debit_account_id']??0),
                'credit_account_id'=>(int)($_POST['credit_account_id']??0),
                'amount'=>$_POST['amount']??0,
                'currency_code'=>$_POST['currency_code']??'USD',
                'base_amount'=>$_POST['base_amount']??null,
                'base_currency'=>$_POST['base_currency']??'USD',
                'exchange_rate'=>$_POST['exchange_rate']??null,
                'exchange_rate_source'=>$_POST['exchange_rate_source']??null,
                'description'=>$_POST['description']??'',
                'staff_id'=>(int)($_POST['staff_id']??0),
                'provider_id'=>(int)($_POST['provider_id']??0),
                'service_id'=>(int)($_POST['service_id']??0),
                'user_id'=>(int)($_POST['user_id']??0),
                'reference_type'=>$_POST['reference_type']??null,
                'reference_id'=>$_POST['reference_id']??null,
                'entry_date'=>$_POST['entry_date']??date('Y-m-d'),
                'idempotency_key'=>'manual:'.bin2hex(random_bytes(12)),
                'created_by'=>(int)($_SESSION['user_id']??0),
            ]);
            logStaffAction($pdo,'create_journal','journal',$entryId,'إنشاء قيد محاسبي يدوي');
            flashMessage('success','تم ترحيل القيد رقم #'.$entryId);
            redirect('accounting_journal.php');
        }
        if (isset($_POST['reverse_journal'])) {
            $entryId=accountingReverseJournalEntry($pdo,(int)($_POST['journal_id']??0),(int)($_SESSION['user_id']??0),trim((string)($_POST['reason']??''))?:null);
            logStaffAction($pdo,'reverse_journal','journal',$entryId,'عكس قيد محاسبي');
            flashMessage('success','تم إنشاء القيد العكسي رقم #'.$entryId);
            redirect('accounting_journal.php');
        }
    } catch (Throwable $e) {
        flashMessage('danger',$e->getMessage());
        redirect('accounting_journal.php?action=add');
    }
}

$action=$_GET['action']??'list';
$accounts=$pdo->query("SELECT id,code,name,account_type,nature FROM accounting_accounts WHERE status=1 ORDER BY code,name")->fetchAll();
$providers=$pdo->query("SELECT id,name FROM providers ORDER BY name")->fetchAll();
$staff=$pdo->query("SELECT id,username,full_name FROM users WHERE role IN ('staff','admin') ORDER BY username")->fetchAll();
$services=$pdo->query("SELECT id,name FROM services WHERE deleted_at IS NULL OR deleted_at='' ORDER BY name LIMIT 3000")->fetchAll();
$customers=$pdo->query("SELECT id,username,full_name FROM users WHERE role='customer' ORDER BY username LIMIT 3000")->fetchAll();

$from=$_GET['from']??''; $to=$_GET['to']??''; $q=trim((string)($_GET['q']??'')); $currency=strtoupper(trim((string)($_GET['currency']??''))); $status=$_GET['status']??'posted';
$page=max(1,(int)($_GET['page']??1)); $pp=30; $offset=($page-1)*$pp;
$where=['1=1'];$params=[];
if($from!==''){$where[]='j.entry_date>=?';$params[]=$from;}if($to!==''){$where[]='j.entry_date<=?';$params[]=$to;}if($currency!==''){$where[]='j.currency_code=?';$params[]=$currency;}if(in_array($status,['posted','reversed','void'],true)){$where[]='j.status=?';$params[]=$status;}
if($q!==''){
    $like='%'.$q.'%'; $numeric=(int)preg_replace('/\D+/','',$q);
    $where[]='(j.description LIKE ? OR j.reference_id LIKE ? OR j.id=? OR da.code LIKE ? OR da.name LIKE ? OR ca.code LIKE ? OR ca.name LIKE ? OR p.name LIKE ? OR s.name LIKE ? OR su.username LIKE ? OR su.full_name LIKE ? OR cu.username LIKE ? OR cu.full_name LIKE ?)';
    $params=array_merge($params,[$like,$like,$numeric,$like,$like,$like,$like,$like,$like,$like,$like,$like,$like]);
}
$baseFrom=" FROM accounting_journal j JOIN accounting_accounts da ON da.id=j.debit_account_id JOIN accounting_accounts ca ON ca.id=j.credit_account_id LEFT JOIN providers p ON p.id=j.provider_id LEFT JOIN services s ON s.id=j.service_id LEFT JOIN users su ON su.id=j.staff_id LEFT JOIN users cu ON cu.id=j.user_id WHERE ".implode(' AND ',$where);
$count=$pdo->prepare('SELECT COUNT(*)'.$baseFrom);$count->execute($params);$total=(int)$count->fetchColumn();
$sql='SELECT j.*,da.code debit_code,da.name debit_name,ca.code credit_code,ca.name credit_name,p.name provider_name,s.name service_name,su.username staff_username,su.full_name staff_full_name,cu.username customer_username,cu.full_name customer_full_name'.$baseFrom.' ORDER BY j.entry_date DESC,j.id DESC LIMIT '.$pp.' OFFSET '.$offset;
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();$pages=max(1,(int)ceil($total/$pp));
$flash=getFlash();
include 'header.php';
?>
<style>
.j-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.j-toolbar .form-group{margin:0;min-width:135px;flex:1}.j-toolbar .wide{min-width:260px}.j-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:1rem}.j-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px}.j-kpi b{display:block;font-size:1.3rem;color:var(--cyan)}.j-table{min-width:1250px}.j-table td,.j-table th{white-space:nowrap}.j-amount{font-family:monospace;font-weight:800;color:#f5a623}.j-side{font-size:.75rem}.j-side.debit{color:#ffadad}.j-side.credit{color:#8ff0cc}.j-ref{font-size:.72rem;color:var(--cyan)}.j-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.j-form-grid .full{grid-column:1/-1}.j-hint{font-size:.73rem;color:var(--text3);line-height:1.7}@media(max-width:800px){.j-kpis{grid-template-columns:1fr}.j-form-grid{grid-template-columns:1fr}.j-form-grid .full{grid-column:auto}}
</style>
<?php if($flash): ?><div class="alert alert-<?=$flash['type']?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<?php if($action==='add'): ?>
<div class="page-header"><div style="display:flex;align-items:center;gap:10px"><a href="accounting_journal.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a><div><h2>ترحيل قيد ثنائي</h2><p>لا يمكن تعديل القيد المرحّل؛ التصحيح يتم بقيد عكسي</p></div></div></div>
<form method="post"><?=adminCsrfField()?><input type="hidden" name="post_journal" value="1"><div class="card"><div class="card-body"><div class="j-form-grid">
<div class="form-group"><label>الحساب المدين *</label><select name="debit_account_id" required><option value="">اختر الحساب</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['code'].' — '.$a['name'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label>الحساب الدائن *</label><select name="credit_account_id" required><option value="">اختر الحساب</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>"><?=htmlspecialchars($a['code'].' — '.$a['name'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label>المبلغ *</label><input name="amount" type="number" step="0.00000001" min="0.00000001" required></div>
<div class="form-group"><label>العملة الأصلية *</label><input name="currency_code" value="USD" maxlength="10" required></div>
<div class="form-group"><label>المبلغ بعملة الأساس <small>(اختياري)</small></label><input name="base_amount" type="number" step="0.00000001" min="0"></div>
<div class="form-group"><label>سعر الصرف الصريح <small>(اختياري)</small></label><input name="exchange_rate" type="number" step="0.0000000001" min="0"></div>
<div class="form-group"><label>مصدر سعر الصرف <small>(اختياري)</small></label><input name="exchange_rate_source" placeholder="مثال: مركز سعر الصرف — 2026-08-22"></div>
<div class="form-group"><label>تاريخ القيد *</label><input name="entry_date" type="date" value="<?=date('Y-m-d')?>" required></div>
<div class="form-group full"><label>البيان *</label><textarea name="description" rows="3" required placeholder="سبب القيد ومصدره بالتفصيل"></textarea></div>
<div class="form-group"><label>الموظف المنفذ</label><select name="staff_id"><option value="">— غير محدد —</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars(($s['full_name']?:$s['username']).' (#'.$s['id'].')')?></option><?php endforeach;?></select></div>
<div class="form-group"><label>المزود</label><select name="provider_id"><option value="">— غير محدد —</option><?php foreach($providers as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label>الخدمة</label><select name="service_id"><option value="">— غير محددة —</option><?php foreach($services as $s): ?><option value="<?=$s['id']?>"><?=htmlspecialchars($s['name'].' (#'.$s['id'].')')?></option><?php endforeach;?></select></div>
<div class="form-group"><label>العميل</label><select name="user_id"><option value="">— غير محدد —</option><?php foreach($customers as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars(($u['full_name']?:$u['username']).' (#'.$u['id'].')')?></option><?php endforeach;?></select></div>
<div class="form-group"><label>نوع المرجع</label><select name="reference_type"><option value="">— بدون مرجع —</option><option value="order">طلب</option><option value="topup">شحن رصيد</option><option value="refund">استرداد</option><option value="p2p">P2P</option><option value="expense">مصروف</option><option value="reconciliation">مطابقة</option><option value="other">أخرى</option></select></div>
<div class="form-group"><label>رقم المرجع</label><input name="reference_id" placeholder="مثال: 123 أو ID123"></div>
</div><div class="j-hint" style="margin-top:14px">يُحفظ USD كعملة أساس افتراضية، لكن لا يتم تحويل أي قيد إلى USD دون إدخال مبلغ أساس وسعر صرف صريح. اترك المرجع فارغاً فقط عند وجود سبب موثق في البيان.</div></div><div style="padding:0 16px 16px;display:flex;gap:8px"><button class="btn btn-success"><i class="fas fa-check"></i> ترحيل القيد</button><a href="accounting_journal.php" class="btn btn-secondary">إلغاء</a></div></div></form>
<?php else: ?>
<div class="page-header"><div><h2><i class="fas fa-file-invoice-dollar" style="color:var(--cyan)"></i> القيود والمطابقة</h2><p>القيود الثنائية الفعلية فقط — لا يُعرض الفرق كربح تلقائياً</p></div><?php if($canEdit): ?><a class="btn btn-primary" href="?action=add"><i class="fas fa-plus"></i> قيد جديد</a><?php endif;?></div>
<div class="j-kpis"><div class="j-kpi"><span>نتائج البحث</span><b><?=$total?></b></div><div class="j-kpi"><span>قيود بلا مرجع</span><b><?=$pdo->query("SELECT COUNT(*) FROM accounting_journal WHERE status='posted' AND (reference_id IS NULL OR reference_id='')")->fetchColumn()?></b></div><div class="j-kpi"><span>قيود اليوم</span><b><?=$pdo->query("SELECT COUNT(*) FROM accounting_journal WHERE entry_date=CURDATE() AND status='posted'")->fetchColumn()?></b></div></div>
<div class="card"><div class="card-body"><form class="j-toolbar" method="get"><div class="form-group wide"><label>بحث شامل</label><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="الموظف، المزود، الخدمة، العميل، الحساب، البيان أو ID123"></div><div class="form-group"><label>من</label><input name="from" type="date" value="<?=htmlspecialchars($from)?>"></div><div class="form-group"><label>إلى</label><input name="to" type="date" value="<?=htmlspecialchars($to)?>"></div><div class="form-group"><label>العملة</label><input name="currency" value="<?=htmlspecialchars($currency)?>" placeholder="USD / YER"></div><div class="form-group"><label>الحالة</label><select name="status"><option value="posted" <?=$status==='posted'?'selected':''?>>مرحّل</option><option value="reversed" <?=$status==='reversed'?'selected':''?>>معكوس</option><option value="void" <?=$status==='void'?'selected':''?>>ملغى</option><option value="all" <?=$status==='all'?'selected':''?>>الكل</option></select></div><button class="btn btn-secondary" type="submit"><i class="fas fa-search"></i> بحث</button><?php if($q||$from||$to||$currency||$status!=='posted'): ?><a class="btn btn-secondary" href="accounting_journal.php">مسح</a><?php endif;?></form></div></div>
<div class="card"><div class="card-body"><div class="table-wrap"><table class="j-table"><thead><tr><th>#</th><th>التاريخ</th><th>مدين</th><th>دائن</th><th>المبلغ</th><th>العملة</th><th>البيان</th><th>الموظف</th><th>المزود/الخدمة</th><th>العميل</th><th>المرجع</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=$r['id']?></td><td><?=htmlspecialchars($r['entry_date'])?></td><td class="j-side debit"><?=htmlspecialchars($r['debit_code'].' — '.$r['debit_name'])?></td><td class="j-side credit"><?=htmlspecialchars($r['credit_code'].' — '.$r['credit_name'])?></td><td class="j-amount"><?=number_format((float)$r['amount'],8,'.',',')?></td><td><?=htmlspecialchars($r['currency_code'])?></td><td style="max-width:260px;white-space:normal"><?=htmlspecialchars($r['description'])?></td><td><?=htmlspecialchars($r['staff_full_name']?:($r['staff_username']?:'—'))?></td><td><?=htmlspecialchars(($r['provider_name']?:'—').($r['service_name']?' / '.$r['service_name']:''))?></td><td><?=htmlspecialchars($r['customer_full_name']?:($r['customer_username']?:'—'))?></td><td class="j-ref"><?=htmlspecialchars(accountingReferenceLabel($r['reference_type'],$r['reference_id']))?></td><td><?=$r['status']==='posted'?'<span class="badge badge-success">مرحّل</span>':($r['status']==='reversed'?'<span class="badge badge-warning">معكوس</span>':'<span class="badge badge-danger">ملغى</span>')?></td><td><?php if($canEdit&&$r['status']==='posted'): ?><form method="post" style="display:flex;gap:4px" onsubmit="return confirm('سيتم إنشاء قيد عكسي ولا يمكن التراجع عنه. متابعة؟')"><?=adminCsrfField()?><input type="hidden" name="reverse_journal" value="1"><input type="hidden" name="journal_id" value="<?=$r['id']?>"><input type="hidden" name="reason" value="عكس القيد #<?=$r['id']?>"><button class="btn btn-sm btn-warning" title="إنشاء قيد عكسي"><i class="fas fa-undo"></i></button></form><?php else: ?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$rows): ?><tr><td colspan="13" style="text-align:center;color:var(--text3);padding:30px">لا توجد قيود مطابقة</td></tr><?php endif;?></tbody></table></div><?php if($pages>1): ?><div style="display:flex;justify-content:center;gap:6px;margin-top:14px;flex-wrap:wrap"><?php for($i=1;$i<=$pages;$i++): ?><a class="btn btn-sm <?=$i===$page?'btn-primary':'btn-secondary'?>" href="?page=<?=$i?>&q=<?=urlencode($q)?>&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>&currency=<?=urlencode($currency)?>&status=<?=urlencode($status)?>"><?=$i?></a><?php endfor;?></div><?php endif;?></div></div>
<?php endif; ?>
<?php include 'footer.php'; ?>
