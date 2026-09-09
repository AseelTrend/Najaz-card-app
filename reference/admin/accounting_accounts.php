<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
accountingEnsureSchema($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();

$pageTitle = 'شجرة الحسابات — ' . SITE_NAME;
$canEdit = isAdmin() || canAccess($pdo, 'perm_accounting_edit');
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    try {
        if (isset($_POST['save_account'])) {
            $accountId = (int)($_POST['account_id'] ?? 0);
            $code = trim((string)($_POST['code'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $type = (string)($_POST['account_type'] ?? 'other');
            $nature = (string)($_POST['nature'] ?? 'debit');
            $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'USD'))) ?: 'USD';
            $region = trim((string)($_POST['region'] ?? ''));
            $providerId = (int)($_POST['provider_id'] ?? 0);
            $staffId = (int)($_POST['staff_id'] ?? 0);
            $userId = (int)($_POST['user_id'] ?? 0);
            $status = !empty($_POST['status']) ? 1 : 0;
            $validTypes = ['asset','liability','equity','revenue','expense','clearing','other'];
            $validNature = ['debit','credit'];
            if ($code === '' || !preg_match('/^[A-Za-z0-9._-]{2,40}$/', $code)) throw new InvalidArgumentException('رقم الحساب مطلوب ويسمح بالأحرف والأرقام والشرطة فقط');
            if ($name === '') throw new InvalidArgumentException('اسم الحساب مطلوب');
            if (!in_array($type, $validTypes, true) || !in_array($nature, $validNature, true)) throw new InvalidArgumentException('نوع أو طبيعة الحساب غير صالحة');
            if ($accountId && $parentId === $accountId) throw new InvalidArgumentException('لا يمكن جعل الحساب أباً لنفسه');
            if ($parentId) {
                $p = $pdo->prepare('SELECT id FROM accounting_accounts WHERE id=? AND status=1 LIMIT 1'); $p->execute([$parentId]);
                if (!$p->fetchColumn()) $parentId = 0;
            }
            $params = [$code,$name,$parentId ?: null,$type,$nature,$currency,$region ?: null,$providerId ?: null,$staffId ?: null,$userId ?: null,$status];
            if ($accountId) {
                $params[] = $accountId;
                $pdo->prepare('UPDATE accounting_accounts SET code=?,name=?,parent_id=?,account_type=?,nature=?,currency_code=?,region=?,provider_id=?,staff_id=?,user_id=?,status=? WHERE id=?')
                    ->execute($params);
                logStaffAction($pdo,'edit_account','account',$accountId,'تعديل حساب محاسبي '.$code);
                flashMessage('success','تم تحديث الحساب');
            } else {
                $params[] = (int)($_SESSION['user_id'] ?? 0);
                $pdo->prepare('INSERT INTO accounting_accounts (code,name,parent_id,account_type,nature,currency_code,region,provider_id,staff_id,user_id,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($params);
                $newId = (int)$pdo->lastInsertId();
                logStaffAction($pdo,'create_account','account',$newId,'إنشاء حساب محاسبي '.$code);
                flashMessage('success','تم إنشاء الحساب');
            }
            redirect('accounting_accounts.php');
        }
        if (isset($_POST['toggle_account'])) {
            $accountId = (int)($_POST['account_id'] ?? 0);
            if (!$accountId) throw new InvalidArgumentException('الحساب غير محدد');
            $pdo->prepare('UPDATE accounting_accounts SET status=IF(status=1,0,1) WHERE id=? AND is_system=0')->execute([$accountId]);
            flashMessage('success','تم تغيير حالة الحساب');
            redirect('accounting_accounts.php');
        }
    } catch (Throwable $e) {
        flashMessage('danger', $e instanceof PDOException && str_contains($e->getMessage(),'Duplicate') ? 'رقم الحساب مستخدم مسبقاً' : $e->getMessage());
        redirect('accounting_accounts.php' . ($id ? '?action=edit&id='.$id : '?action=add'));
    }
}

$editAccount = null;
if (in_array($action, ['edit','view'], true) && $id) {
    $st = $pdo->prepare('SELECT a.*, p.name provider_name, su.username staff_username, su.full_name staff_full_name, cu.username customer_username FROM accounting_accounts a LEFT JOIN providers p ON p.id=a.provider_id LEFT JOIN users su ON su.id=a.staff_id LEFT JOIN users cu ON cu.id=a.user_id WHERE a.id=? LIMIT 1');
    $st->execute([$id]); $editAccount = $st->fetch();
    if (!$editAccount) { flashMessage('danger','الحساب غير موجود'); redirect('accounting_accounts.php'); }
}

$q = trim((string)($_GET['q'] ?? ''));
$filterParentId = max(0, (int)($_GET['parent_id'] ?? 0));
$filterType = in_array((string)($_GET['account_type'] ?? ''), ['asset','liability','equity','revenue','expense','clearing','other'], true) ? (string)$_GET['account_type'] : '';
$filterNature = in_array((string)($_GET['nature'] ?? ''), ['debit','credit'], true) ? (string)$_GET['nature'] : '';
$filterCurrency = strtoupper(trim((string)($_GET['currency'] ?? '')));
if ($filterCurrency !== '' && !preg_match('/^[A-Z0-9_-]{1,10}$/', $filterCurrency)) $filterCurrency = '';
$filterStatus = in_array((string)($_GET['status'] ?? ''), ['active','inactive'], true) ? (string)$_GET['status'] : '';
$filterProviderId = max(0, (int)($_GET['provider_id'] ?? 0));
$filterStaffId = max(0, (int)($_GET['staff_id'] ?? 0));
$filterUserId = max(0, (int)($_GET['user_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 30; $offset = ($page-1)*$perPage;
$where = ['1=1']; $params=[];
if ($q !== '') { $where[]='(a.code LIKE ? OR a.name LIKE ? OR a.region LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
if ($filterParentId > 0) { $where[]='a.parent_id=?'; $params[]=$filterParentId; }
if ($filterType !== '') { $where[]='a.account_type=?'; $params[]=$filterType; }
if ($filterNature !== '') { $where[]='a.nature=?'; $params[]=$filterNature; }
if ($filterCurrency !== '') { $where[]='a.currency_code=?'; $params[]=$filterCurrency; }
if ($filterStatus === 'active') { $where[]='a.status=1'; }
if ($filterStatus === 'inactive') { $where[]='a.status=0'; }
if ($filterProviderId > 0) { $where[]='a.provider_id=?'; $params[]=$filterProviderId; }
if ($filterStaffId > 0) { $where[]='a.staff_id=?'; $params[]=$filterStaffId; }
if ($filterUserId > 0) { $where[]='a.user_id=?'; $params[]=$filterUserId; }
$whereSql = implode(' AND ',$where);
$count = $pdo->prepare('SELECT COUNT(*) FROM accounting_accounts a WHERE '.$whereSql); $count->execute($params); $total=(int)$count->fetchColumn();
$sql = 'SELECT a.*, pa.code parent_code, pa.name parent_name, p.name provider_name, s.username staff_username, s.full_name staff_full_name, u.username customer_username FROM accounting_accounts a LEFT JOIN accounting_accounts pa ON pa.id=a.parent_id LEFT JOIN providers p ON p.id=a.provider_id LEFT JOIN users s ON s.id=a.staff_id LEFT JOIN users u ON u.id=a.user_id WHERE '.$whereSql.' ORDER BY COALESCE(pa.code,a.code), a.code LIMIT '.$perPage.' OFFSET '.$offset;
$st=$pdo->prepare($sql); $st->execute($params); $accounts=$st->fetchAll(); $pages=max(1,(int)ceil($total/$perPage));
$parents=$pdo->query("SELECT id,code,name FROM accounting_accounts WHERE status=1 ORDER BY code,name")->fetchAll();
$providers=$pdo->query("SELECT id,name FROM providers WHERE status=1 ORDER BY name")->fetchAll();
$staff=$pdo->query("SELECT id,username,full_name FROM users WHERE role IN ('staff','admin') AND status=1 ORDER BY username")->fetchAll();
$customers=$pdo->query("SELECT id,username,full_name FROM users WHERE role='customer' AND status=1 ORDER BY username LIMIT 1000")->fetchAll();
$activeAccountFilters = array_filter([$q,$filterParentId,$filterType,$filterNature,$filterCurrency,$filterStatus,$filterProviderId,$filterStaffId,$filterUserId], static fn($v) => $v !== '' && $v !== null && $v !== 0);
$accountQuery = $_GET; unset($accountQuery['page']);
$accountQueryString = http_build_query($accountQuery);
$flash=getFlash();
include 'header.php';
?>
<style>
.acct-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:1rem}.acct-search{display:flex;gap:8px;flex:1;min-width:240px}.acct-search input{flex:1}.acct-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:1rem}.acct-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px}.acct-kpi b{display:block;font-size:1.3rem;color:var(--cyan)}.acct-table td,.acct-table th{white-space:nowrap}.acct-code{font-family:monospace;color:var(--cyan);font-weight:800}.acct-indent{display:inline-flex;align-items:center;gap:7px}.acct-type{font-size:.7rem;padding:3px 7px;border-radius:12px;background:rgba(108,63,224,.14);color:#b9a5ff}.acct-link{font-size:.73rem;color:var(--text3)}@media(max-width:800px){.acct-kpis{grid-template-columns:repeat(2,1fr)}.acct-table{min-width:980px}}
</style>
<?php if($flash): ?><div class="alert alert-<?=$flash['type']?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<?php if($action==='add'||$action==='edit'): ?>
<div class="page-header"><div style="display:flex;align-items:center;gap:10px"><a href="accounting_accounts.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a><h2><?=$action==='edit'?'تعديل حساب محاسبي':'إضافة حساب محاسبي'?></h2></div></div>
<form method="post"><?=adminCsrfField()?><input type="hidden" name="save_account" value="1"><input type="hidden" name="account_id" value="<?= (int)($editAccount['id']??0) ?>">
<div class="card"><div class="card-body" style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">
<div class="form-group"><label>رقم الحساب *</label><input name="code" required value="<?=htmlspecialchars($editAccount['code']??'')?>" placeholder="مثال: 1101"></div>
<div class="form-group"><label>اسم الحساب *</label><input name="name" required value="<?=htmlspecialchars($editAccount['name']??'')?>"></div>
<div class="form-group"><label>الحساب الأب</label><select name="parent_id"><option value="">— حساب رئيسي —</option><?php foreach($parents as $p): if((int)$p['id']===(int)($editAccount['id']??0))continue; ?><option value="<?=$p['id']?>" <?=((int)($editAccount['parent_id']??0)==(int)$p['id'])?'selected':''?>><?=htmlspecialchars($p['code'].' — '.$p['name'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label>نوع الحساب</label><select name="account_type"><?php foreach(['asset'=>'أصل','liability'=>'التزام','equity'=>'حقوق ملكية','revenue'=>'إيراد','expense'=>'مصروف','clearing'=>'تسوية','other'=>'أخرى'] as $k=>$v): ?><option value="<?=$k?>" <?=($editAccount['account_type']??'other')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></div>
<div class="form-group"><label>طبيعة الحساب</label><select name="nature"><option value="debit" <?=($editAccount['nature']??'debit')==='debit'?'selected':''?>>مدين</option><option value="credit" <?=($editAccount['nature']??'')==='credit'?'selected':''?>>دائن</option></select></div>
<div class="form-group"><label>العملة</label><input name="currency_code" maxlength="10" value="<?=htmlspecialchars($editAccount['currency_code']??'USD')?>"></div>
<div class="form-group"><label>المنطقة أو الفرع</label><input name="region" value="<?=htmlspecialchars($editAccount['region']??'')?>"></div>
<div class="form-group"><label>المزود المرتبط</label><select name="provider_id"><option value="">— بدون مزود —</option><?php foreach($providers as $p): ?><option value="<?=$p['id']?>" <?=((int)($editAccount['provider_id']??0)==$p['id'])?'selected':''?>><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select></div>
<div class="form-group"><label>الموظف المرتبط</label><select name="staff_id"><option value="">— بدون موظف —</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>" <?=((int)($editAccount['staff_id']??0)==$s['id'])?'selected':''?>><?=htmlspecialchars(($s['full_name']?:$s['username']).' (#'.$s['id'].')')?></option><?php endforeach;?></select></div>
<div class="form-group"><label>العميل المرتبط</label><select name="user_id"><option value="">— بدون عميل —</option><?php foreach($customers as $u): ?><option value="<?=$u['id']?>" <?=((int)($editAccount['user_id']??0)==$u['id'])?'selected':''?>><?=htmlspecialchars(($u['full_name']?:$u['username']).' (#'.$u['id'].')')?></option><?php endforeach;?></select></div>
<div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:28px"><input type="checkbox" name="status" value="1" <?=!isset($editAccount['status'])||$editAccount['status']?'checked':''?>> <label style="margin:0">حساب نشط</label></div>
</div><div style="padding:0 16px 16px;display:flex;gap:8px"><button class="btn btn-success" type="submit"><i class="fas fa-save"></i> حفظ الحساب</button><a class="btn btn-secondary" href="accounting_accounts.php">إلغاء</a></div></div></form>
<?php else: ?>
<div class="page-header"><div><h2><i class="fas fa-sitemap" style="color:var(--cyan)"></i> شجرة الحسابات</h2><p>حسابات مالية قابلة للربط والمطابقة — دون خزنة</p></div><?php if($canEdit): ?><a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> حساب جديد</a><?php endif;?></div>
<div class="acct-kpis"><div class="acct-kpi"><span>إجمالي الحسابات</span><b><?=$total?></b></div><div class="acct-kpi"><span>حسابات نشطة</span><b><?=$pdo->query("SELECT COUNT(*) FROM accounting_accounts WHERE status=1")->fetchColumn()?></b></div><div class="acct-kpi"><span>حسابات مرتبطة بموظف</span><b><?=$pdo->query("SELECT COUNT(*) FROM accounting_accounts WHERE staff_id IS NOT NULL")->fetchColumn()?></b></div><div class="acct-kpi"><span>حسابات مرتبطة بمزود</span><b><?=$pdo->query("SELECT COUNT(*) FROM accounting_accounts WHERE provider_id IS NOT NULL")->fetchColumn()?></b></div></div>
<div class="card"><div class="card-body">
<form class="acct-toolbar" method="get">
  <div class="acct-search"><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="ابحث برقم الحساب أو اسمه أو المنطقة"><button class="btn btn-secondary" type="submit"><i class="fas fa-search"></i> بحث</button></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;width:100%">
    <select name="parent_id" aria-label="الحساب الأب"><option value="">كل الحسابات الأب</option><?php foreach($parents as $p): ?><option value="<?=$p['id']?>" <?=$filterParentId===(int)$p['id']?'selected':''?>><?=htmlspecialchars($p['code'].' — '.$p['name'])?></option><?php endforeach; ?></select>
    <select name="account_type" aria-label="نوع الحساب"><option value="">كل الأنواع</option><?php foreach(['asset'=>'أصل','liability'=>'التزام','equity'=>'حقوق ملكية','revenue'=>'إيراد','expense'=>'مصروف','clearing'=>'تسوية','other'=>'أخرى'] as $k=>$v): ?><option value="<?=$k?>" <?=$filterType===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select>
    <select name="nature" aria-label="طبيعة الحساب"><option value="">كل الطبائع</option><option value="debit" <?=$filterNature==='debit'?'selected':''?>>مدين</option><option value="credit" <?=$filterNature==='credit'?'selected':''?>>دائن</option></select>
    <input name="currency" value="<?=htmlspecialchars($filterCurrency)?>" maxlength="10" style="width:100px" placeholder="العملة">
    <select name="status" aria-label="حالة الحساب"><option value="">كل الحالات</option><option value="active" <?=$filterStatus==='active'?'selected':''?>>نشط</option><option value="inactive" <?=$filterStatus==='inactive'?'selected':''?>>موقوف</option></select>
    <select name="provider_id" aria-label="المزود"><option value="">كل المزودين</option><?php foreach($providers as $p): ?><option value="<?=$p['id']?>" <?=$filterProviderId===(int)$p['id']?'selected':''?>>مزود: <?=htmlspecialchars($p['name'])?></option><?php endforeach; ?></select>
    <select name="staff_id" aria-label="الموظف"><option value="">كل الموظفين</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>" <?=$filterStaffId===(int)$s['id']?'selected':''?>>موظف: <?=htmlspecialchars($s['full_name']?:$s['username'])?></option><?php endforeach; ?></select>
    <select name="user_id" aria-label="العميل"><option value="">كل العملاء</option><?php foreach($customers as $u): ?><option value="<?=$u['id']?>" <?=$filterUserId===(int)$u['id']?'selected':''?>>عميل: <?=htmlspecialchars(($u['full_name']?:$u['username']).' (#'.$u['id'].')')?></option><?php endforeach; ?></select>
    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-filter"></i> تطبيق الفلاتر</button>
    <?php if($activeAccountFilters): ?><a class="btn btn-secondary btn-sm" href="accounting_accounts.php"><i class="fas fa-undo"></i> مسح</a><span style="align-self:center;color:var(--cyan);font-size:.78rem"><?=count($activeAccountFilters)?> فلتر نشط</span><?php endif; ?>
  </div>
</form>
<div class="table-wrap"><table class="acct-table"><thead><tr><th>الحساب</th><th>الأب</th><th>النوع</th><th>الطبيعة</th><th>العملة</th><th>الروابط</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody><?php foreach($accounts as $a): ?><tr><td><div class="acct-indent" style="padding-right:<?=($a['parent_id']?18:0)?>px"><span class="acct-code"><?=htmlspecialchars($a['code'])?></span><strong><?=htmlspecialchars($a['name'])?></strong></div></td><td><?=htmlspecialchars($a['parent_code']?($a['parent_code'].' — '.$a['parent_name']):'حساب رئيسي')?></td><td><span class="acct-type"><?=htmlspecialchars($a['account_type'])?></span></td><td><?=($a['nature']==='debit'?'مدين':'دائن')?></td><td><?=htmlspecialchars($a['currency_code'])?></td><td class="acct-link"><?php $links=[];if($a['staff_id'])$links[]='موظف: '.($a['staff_full_name']?:$a['staff_username']);if($a['provider_id'])$links[]='مزود: '.$a['provider_name'];if($a['user_id'])$links[]='عميل: '.$a['customer_username'];echo htmlspecialchars(implode(' | ',$links)?:'—');?></td><td><?=$a['status']?'<span class="badge badge-success">نشط</span>':'<span class="badge badge-danger">موقوف</span>'?></td><td style="display:flex;gap:5px"><a class="btn btn-sm btn-secondary" href="?action=edit&id=<?=$a['id']?>"><i class="fas fa-edit"></i></a><?php if($canEdit&&!$a['is_system']): ?><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="toggle_account" value="1"><input type="hidden" name="account_id" value="<?=$a['id']?>"><button class="btn btn-sm <?=$a['status']?'btn-danger':'btn-success'?>" title="تغيير الحالة"><i class="fas fa-power-off"></i></button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$accounts): ?><tr><td colspan="8" style="text-align:center;color:var(--text3);padding:30px">لا توجد حسابات مطابقة</td></tr><?php endif;?></tbody></table></div><?php if($pages>1): ?><div style="display:flex;justify-content:center;gap:6px;margin-top:14px;flex-wrap:wrap"><?php for($i=1;$i<=$pages;$i++): ?><a class="btn btn-sm <?=$i===$page?'btn-primary':'btn-secondary'?>" href="?<?=htmlspecialchars($accountQueryString)?><?= $accountQueryString ? '&' : '' ?>page=<?=$i?>"><?=$i?></a><?php endfor;?></div><?php endif;?></div></div>
<?php endif; ?>
<?php include 'footer.php'; ?>
