<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
accountingEnsureSchema($pdo);
accountingEnsureProviderLinkSchema($pdo);
cashboxEnsureSchema($pdo);
cashboxEnsureProviderColumn($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();
$pageTitle = 'ربطيات المزودين — ' . SITE_NAME;
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_linkage'])) {
    if (!isAdmin() && !canAccess($pdo, 'perm_accounting_edit')) { flashMessage('danger', 'ليس لديك صلاحية تعديل ربطيات المزودين'); redirect(SITE_URL . '/admin/provider_linkages.php'); }
    $lid = (int)($_POST['linkage_id'] ?? 0);
    $providerId = (int)($_POST['provider_id'] ?? 0);
    $networkId = (int)($_POST['network_id'] ?? 0) ?: null;
    $staffId = (int)($_POST['staff_id'] ?? 0) ?: null;
    $accountId = (int)($_POST['account_id'] ?? 0) ?: null;
    $cashboxId = (int)($_POST['cashbox_id'] ?? 0) ?: null;
    $name = trim((string)($_POST['linkage_name'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;
    $alert = $_POST['alert_balance'] !== '' ? (float)$_POST['alert_balance'] : null;
    $stop = $_POST['stop_balance'] !== '' ? (float)$_POST['stop_balance'] : null;
    $retry = max(0, min(99, (int)($_POST['retry_limit'] ?? 0)));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($providerId <= 0) {
        flashMessage('danger', 'اختر المزود أولاً');
    } elseif ($alert !== null && $stop !== null && $stop > $alert) {
        flashMessage('danger', 'عتبة التوقيف لا يمكن أن تكون أعلى من عتبة التنبيه');
    } else {
        $dup = $pdo->prepare("SELECT id FROM accounting_provider_links
            WHERE provider_id=? AND network_id <=> ? AND staff_id <=> ? AND account_id <=> ? AND cashbox_id <=> ? AND id<>? LIMIT 1");
        $dup->execute([$providerId,$networkId,$staffId,$accountId,$cashboxId,$lid]);
        $duplicateId = (int)($dup->fetchColumn() ?: 0);
        if ($duplicateId > 0) {
            flashMessage('danger', 'توجد ربطية مطابقة للمزود والشبكة والموظف والحساب والصندوق بالفعل');
        } elseif ($lid) {
            $st = $pdo->prepare("UPDATE accounting_provider_links SET provider_id=?,network_id=?,staff_id=?,account_id=?,cashbox_id=?,linkage_name=?,is_active=?,alert_balance=?,stop_balance=?,retry_limit=?,notes=? WHERE id=?");
            $st->execute([$providerId,$networkId,$staffId,$accountId,$cashboxId,$name ?: null,$active,$alert,$stop,$retry,$notes ?: null,$lid]);
        } else {
            $st = $pdo->prepare("INSERT INTO accounting_provider_links (provider_id,network_id,staff_id,account_id,cashbox_id,linkage_name,is_active,alert_balance,stop_balance,retry_limit,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$providerId,$networkId,$staffId,$accountId,$cashboxId,$name ?: null,$active,$alert,$stop,$retry,$notes ?: null,(int)($_SESSION['user_id'] ?? 0) ?: null]);
        }
        if ($duplicateId === 0) {
            flashMessage('success', 'تم حفظ ربطية المزود');
            redirect(SITE_URL . '/admin/provider_linkages.php');
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['toggle_linkage']) || isset($_POST['delete_linkage']))) {
    if (!isAdmin() && !canAccess($pdo, 'perm_accounting_edit')) { flashMessage('danger', 'ليس لديك صلاحية تعديل ربطيات المزودين'); redirect(SITE_URL . '/admin/provider_linkages.php'); }
    $linkageId = (int)($_POST['linkage_id'] ?? 0);
    if ($linkageId <= 0) { flashMessage('danger', 'الربطية غير محددة'); redirect(SITE_URL . '/admin/provider_linkages.php'); }
    if (isset($_POST['toggle_linkage'])) {
        $pdo->prepare('UPDATE accounting_provider_links SET is_active=1-is_active WHERE id=?')->execute([$linkageId]);
        flashMessage('success', 'تم تغيير حالة الربطية');
    } else {
        $pdo->prepare('DELETE FROM accounting_provider_links WHERE id=?')->execute([$linkageId]);
        flashMessage('success', 'تم حذف الربطية');
    }
    redirect(SITE_URL . '/admin/provider_linkages.php');
}

$providers = $pdo->query('SELECT id,name,status,provider_type FROM providers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$staff = $pdo->query("SELECT id,username,full_name FROM users WHERE role IN ('staff','admin') AND COALESCE(is_deleted,0)=0 ORDER BY full_name,username")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query('SELECT id,code,name,currency_code FROM accounting_accounts WHERE status=1 ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$cashboxes = $pdo->query("SELECT id,code,name,currency_code,status FROM accounting_cashboxes WHERE status<>'closed' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$networks = [];
try { $networks = $pdo->query('SELECT id,name,network_number FROM telecom_networks WHERE status=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { error_log('Provider link networks load: '.$e->getMessage()); }
$edit = null;
if ($action === 'edit' && $id) { $st=$pdo->prepare('SELECT * FROM accounting_provider_links WHERE id=?'); $st->execute([$id]); $edit=$st->fetch(PDO::FETCH_ASSOC); }
$q = trim((string)($_GET['q'] ?? ''));
$filter = $q !== '' ? '%' . $q . '%' : '%';
$list = $pdo->prepare("SELECT l.*,p.name provider_name,p.provider_type,p.status provider_status,n.name network_name,u.username staff_username,u.full_name staff_name,a.code account_code,a.name account_name,c.code cashbox_code,c.name cashbox_name,c.currency_code cashbox_currency
 FROM accounting_provider_links l JOIN providers p ON p.id=l.provider_id LEFT JOIN telecom_networks n ON n.id=l.network_id LEFT JOIN users u ON u.id=l.staff_id LEFT JOIN accounting_accounts a ON a.id=l.account_id LEFT JOIN accounting_cashboxes c ON c.id=l.cashbox_id
 WHERE p.name LIKE ? OR COALESCE(l.linkage_name,'') LIKE ? OR COALESCE(n.name,'') LIKE ? OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.full_name,'') LIKE ? OR COALESCE(a.code,'') LIKE ? OR COALESCE(a.name,'') LIKE ?
 ORDER BY l.is_active DESC,p.name,l.id DESC");
$list->execute([$filter,$filter,$filter,$filter,$filter,$filter,$filter]);
$rows=$list->fetchAll(PDO::FETCH_ASSOC);
$flash=getFlash();
include 'header.php';
?>
<style>.pl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.pl-grid .full{grid-column:1/-1}.pl-table{min-width:1050px}.pl-table td,.pl-table th{white-space:nowrap}.muted{color:var(--text3);font-size:.82rem}.status-on{color:#00d4aa}.status-off{color:#ffadad}@media(max-width:800px){.pl-grid{grid-template-columns:1fr}}</style>
<?php if($flash): ?><div class="alert alert-<?=htmlspecialchars($flash['type'])?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<div class="page-header"><div><h2><i class="fas fa-project-diagram" style="color:var(--cyan)"></i> ربطيات المزودين</h2><p>ربط تشغيلي ومحاسبي مستقل عن بيانات API؛ لا يمثل خزنة ولا يغيّر مسار اختيار المزود.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a href="providers.php" class="btn btn-secondary"><i class="fas fa-plug"></i> المزودون</a><a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> ربطية جديدة</a></div></div>
<div class="card"><div class="card-body"><form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><div class="form-group" style="flex:1;min-width:260px;margin:0"><label>بحث</label><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="اسم الربطية، المزود، الشبكة، الموظف أو الحساب"></div><button class="btn btn-secondary"><i class="fas fa-search"></i> بحث</button><?php if($q): ?><a href="provider_linkages.php" class="btn btn-secondary">مسح</a><?php endif; ?></form></div></div>
<?php if(in_array($action,['add','edit'],true)): ?><div class="card"><div class="card-body"><h3 style="margin-top:0"><i class="fas fa-link"></i> <?=$edit?'تعديل الربطية':'إضافة ربطية'?></h3><form method="post"><input type="hidden" name="linkage_id" value="<?=$edit['id']??0?>"><input type="hidden" name="save_linkage" value="1"><?=adminCsrfField()?>
<div class="pl-grid"><div class="form-group"><label>اسم الربطية</label><input name="linkage_name" value="<?=htmlspecialchars($edit['linkage_name']??'')?>" placeholder="مثال: مباشر كريمي شمال"></div><div class="form-group"><label>المزود <b style="color:#f66">*</b></label><select name="provider_id" required><option value="">اختر المزود</option><?php foreach($providers as $p): ?><option value="<?=$p['id']?>" <?=((int)($edit['provider_id']??0)==$p['id'])?'selected':''?>><?=htmlspecialchars($p['name'])?> — <?=htmlspecialchars($p['provider_type'])?></option><?php endforeach; ?></select></div><div class="form-group"><label>الشبكة / المجال</label><select name="network_id"><option value="">عام — كل الشبكات</option><?php foreach($networks as $n): ?><option value="<?=$n['id']?>" <?=((int)($edit['network_id']??0)==$n['id'])?'selected':''?>><?=htmlspecialchars($n['name'])?> (#<?=$n['network_number']?>)</option><?php endforeach; ?></select></div><div class="form-group"><label>الموظف المسؤول</label><select name="staff_id"><option value="">غير مرتبط بموظف</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>" <?=((int)($edit['staff_id']??0)==$s['id'])?'selected':''?>><?=htmlspecialchars($s['full_name']?:$s['username'])?> — <?=htmlspecialchars($s['username'])?></option><?php endforeach; ?></select></div><div class="form-group"><label>الحساب المالي</label><select name="account_id"><option value="">غير مرتبط بحساب</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)($edit['account_id']??0)==$a['id'])?'selected':''?>><?=htmlspecialchars($a['code'].' — '.$a['name'].' ('.$a['currency_code'].')')?></option><?php endforeach; ?></select></div><div class="form-group"><label>الصندوق التشغيلي</label><select name="cashbox_id"><option value="">غير مرتبط بصندوق</option><?php foreach($cashboxes as $c): ?><option value="<?=$c['id']?>" <?=((int)($edit['cashbox_id']??0)==$c['id'])?'selected':''?>><?=htmlspecialchars($c['name'].' — '.$c['code'].' ('.$c['currency_code'].')')?></option><?php endforeach; ?></select></div><div class="form-group"><label>حد التنبيه</label><input type="number" step="0.00000001" min="0" name="alert_balance" value="<?=htmlspecialchars((string)($edit['alert_balance']??''))?>"></div><div class="form-group"><label>حد التوقيف</label><input type="number" step="0.00000001" min="0" name="stop_balance" value="<?=htmlspecialchars((string)($edit['stop_balance']??''))?>"></div><div class="form-group"><label>عدد إعادة المحاولة</label><input type="number" min="0" max="99" name="retry_limit" value="<?=htmlspecialchars((string)($edit['retry_limit']??0))?>"></div><div class="form-group full"><label>ملاحظات</label><textarea name="notes" rows="2" placeholder="وصف داخلي للربطية أو قواعد المطابقة"><?=htmlspecialchars($edit['notes']??'')?></textarea></div><div class="form-group full"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?=(!$edit || !empty($edit['is_active']))?'checked':''?>> الربطية مفعّلة للعرض والمطابقة</label></div></div><div style="display:flex;gap:8px;margin-top:15px"><button class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button><a href="provider_linkages.php" class="btn btn-secondary">إلغاء</a></div></form></div></div><?php endif; ?>
<div class="card"><div class="card-body"><div class="table-wrap"><table class="pl-table"><thead><tr><th>الحالة</th><th>الربطية</th><th>المزود</th><th>الشبكة</th><th>الموظف</th><th>الحساب المالي</th><th>الصندوق</th><th>العتبات</th><th>إعادة المحاولة</th><th>إجراءات</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?=$r['is_active']?'<span class="status-on">شغال</span>':'<span class="status-off">متوقف</span>'?></td><td><b><?=htmlspecialchars($r['linkage_name']?:$r['provider_name'])?></b><div class="muted">#<?=$r['id']?></div></td><td><?=htmlspecialchars($r['provider_name'])?><div class="muted"><?=htmlspecialchars($r['provider_type'])?></div></td><td><?=htmlspecialchars($r['network_name']?:'عام')?></td><td><?=htmlspecialchars($r['staff_name']?:$r['staff_username']?:'—')?></td><td><?=htmlspecialchars(($r['account_code']?$r['account_code'].' — ':'').($r['account_name']?:'—'))?></td><td><?=htmlspecialchars($r['cashbox_name']?($r['cashbox_name'].' — '.$r['cashbox_code']):'—')?></td><td><?=htmlspecialchars($r['alert_balance']!==null?'تنبيه '.$r['alert_balance']:'—')?> / <?=htmlspecialchars($r['stop_balance']!==null?'توقيف '.$r['stop_balance']:'—')?></td><td><?=htmlspecialchars((string)$r['retry_limit'])?></td><td style="display:flex;gap:5px"><a class="btn btn-sm btn-secondary" href="?action=edit&id=<?=$r['id']?>">تعديل</a><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="linkage_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-secondary" name="toggle_linkage" value="1"><?=$r['is_active']?'إيقاف':'تشغيل'?></button></form><form method="post" style="display:inline" onsubmit="return confirm('حذف الربطية؟')"><?=adminCsrfField()?><input type="hidden" name="linkage_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-danger" name="delete_linkage" value="1">حذف</button></form></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text3)">لا توجد ربطيات بعد</td></tr><?php endif; ?></tbody></table></div><div class="muted" style="margin-top:12px">عدد الربطيات: <?=count($rows)?> — المطابقة لا تعتبر وجود الربطية دليلاً على تنفيذ العملية ما لم يكن مرجع العملية وقيدها مثبتين.</div></div></div>
<?php include 'footer.php'; ?>
