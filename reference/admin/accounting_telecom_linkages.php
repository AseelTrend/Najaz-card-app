<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';

accountingEnsureSchema($pdo);
cashboxEnsureSchema($pdo);
cashboxEnsureTelecomLinkSchema($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();

$pageTitle = 'ربطيات الاتصالات — ' . SITE_NAME;
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_linkage'])) {
    if (!isAdmin() && !canAccess($pdo, 'perm_accounting_edit')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل ربطيات الاتصالات');
        redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
    }

    $lid = (int)($_POST['linkage_id'] ?? 0);
    $providerKey = strtolower(trim((string)($_POST['provider_key'] ?? '')));
    $networkId = (int)($_POST['network_id'] ?? 0) ?: null;
    $staffId = (int)($_POST['staff_id'] ?? 0) ?: null;
    $accountId = (int)($_POST['account_id'] ?? 0) ?: null;
    $cashboxId = (int)($_POST['cashbox_id'] ?? 0) ?: null;
    $name = trim((string)($_POST['linkage_name'] ?? ''));
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($providerKey === '') {
        flashMessage('danger', 'اختر مزود الاتصالات أولاً');
    } elseif (!preg_match('/^[a-z0-9_.-]{2,80}$/', $providerKey)) {
        flashMessage('danger', 'مفتاح مزود الاتصالات غير صالح');
    } elseif (!$cashboxId || !$accountId) {
        flashMessage('danger', 'يجب اختيار الصندوق والحساب المالي حتى يمكن ترحيل العملية بدقة');
    } else {
        $cashboxCheck = $pdo->prepare("SELECT id FROM accounting_cashboxes WHERE id=? AND status='active' LIMIT 1");
        $cashboxCheck->execute([$cashboxId]);
        $accountCheck = $pdo->prepare("SELECT id FROM accounting_accounts WHERE id=? AND status=1 LIMIT 1");
        $accountCheck->execute([$accountId]);
        if (!$cashboxCheck->fetchColumn()) {
            flashMessage('danger', 'الصندوق غير موجود أو غير نشط');
        } elseif (!$accountCheck->fetchColumn()) {
            flashMessage('danger', 'الحساب المالي غير موجود أو غير نشط');
        } else {
            $dup = $pdo->prepare("SELECT id FROM accounting_telecom_links
                WHERE provider_key=? AND network_id <=> ? AND staff_id <=> ? AND account_id <=> ? AND cashbox_id <=> ? AND id<>? LIMIT 1");
            $dup->execute([$providerKey, $networkId, $staffId, $accountId, $cashboxId, $lid]);
            $duplicateId = (int)($dup->fetchColumn() ?: 0);
            if ($duplicateId > 0) {
                flashMessage('danger', 'توجد ربطية مطابقة لمزود الاتصالات والشبكة والموظف والحساب والصندوق بالفعل');
            } elseif ($lid) {
                $st = $pdo->prepare("UPDATE accounting_telecom_links
                    SET provider_key=?,network_id=?,staff_id=?,account_id=?,cashbox_id=?,linkage_name=?,is_active=? WHERE id=?");
                $st->execute([$providerKey, $networkId, $staffId, $accountId, $cashboxId, $name ?: null, $active, $lid]);
                flashMessage('success', 'تم تحديث ربطية الاتصالات');
                redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
            } else {
                $st = $pdo->prepare("INSERT INTO accounting_telecom_links
                    (provider_key,network_id,staff_id,account_id,cashbox_id,linkage_name,is_active,created_by)
                    VALUES (?,?,?,?,?,?,?,?)");
                $st->execute([$providerKey, $networkId, $staffId, $accountId, $cashboxId, $name ?: null, $active, (int)($_SESSION['user_id'] ?? 0) ?: null]);
                flashMessage('success', 'تم حفظ ربطية الاتصالات');
                redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['toggle_linkage']) || isset($_POST['delete_linkage']))) {
    if (!isAdmin() && !canAccess($pdo, 'perm_accounting_edit')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل ربطيات الاتصالات');
        redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
    }
    $linkageId = (int)($_POST['linkage_id'] ?? 0);
    if ($linkageId <= 0) {
        flashMessage('danger', 'الربطية غير محددة');
        redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
    }
    if (isset($_POST['toggle_linkage'])) {
        $pdo->prepare('UPDATE accounting_telecom_links SET is_active=1-is_active WHERE id=?')->execute([$linkageId]);
        flashMessage('success', 'تم تغيير حالة ربطية الاتصالات');
    } else {
        $used = 0;
        try {
            $usedSt = $pdo->prepare("SELECT COUNT(*) FROM telecom_orders WHERE provider_link_id=?");
            $usedSt->execute([$linkageId]);
            $used = (int)$usedSt->fetchColumn();
        } catch (Throwable $e) { error_log('Telecom linkage usage check: ' . $e->getMessage()); }
        if ($used > 0) {
            flashMessage('danger', 'لا يمكن حذف الربطية لأنها مرتبطة بعمليات سابقة؛ أوقفها بدلاً من حذفها.');
        } else {
            $pdo->prepare('DELETE FROM accounting_telecom_links WHERE id=?')->execute([$linkageId]);
            flashMessage('success', 'تم حذف ربطية الاتصالات');
        }
    }
    redirect(SITE_URL . '/admin/accounting_telecom_linkages.php');
}

$staff = $pdo->query("SELECT id,username,full_name FROM users WHERE role IN ('staff','admin') AND COALESCE(is_deleted,0)=0 ORDER BY full_name,username")->fetchAll(PDO::FETCH_ASSOC);
$accounts = $pdo->query('SELECT id,code,name,currency_code FROM accounting_accounts WHERE status=1 ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$cashboxes = $pdo->query("SELECT id,code,name,currency_code,status FROM accounting_cashboxes WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$networks = [];
try {
    $networks = $pdo->query('SELECT id,name,network_number FROM telecom_networks WHERE status=1 ORDER BY sort_order,id')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Telecom linkage networks load: ' . $e->getMessage()); }

$providerKeys = ['fore' => 'fore — مزود الاتصالات الافتراضي', 'momaiz' => 'momaiz — مزود الاتصالات'];
try {
    $existingKeys = $pdo->query("SELECT DISTINCT provider_key FROM accounting_telecom_links WHERE provider_key<>'' ORDER BY provider_key")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($existingKeys as $key) if (!isset($providerKeys[$key])) $providerKeys[$key] = $key . ' — قيمة مضافة سابقاً';
} catch (Throwable $e) { error_log('Telecom provider keys load: ' . $e->getMessage()); }

$edit = null;
if ($action === 'edit' && $id) {
    $st = $pdo->prepare('SELECT * FROM accounting_telecom_links WHERE id=?');
    $st->execute([$id]);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
}

$q = trim((string)($_GET['q'] ?? ''));
$filter = $q !== '' ? '%' . $q . '%' : '%';
$list = $pdo->prepare("SELECT l.*,n.name network_name,n.network_number,
        u.username staff_username,u.full_name staff_name,
        a.code account_code,a.name account_name,a.currency_code account_currency,
        c.code cashbox_code,c.name cashbox_name,c.currency_code cashbox_currency,c.status cashbox_status
    FROM accounting_telecom_links l
    LEFT JOIN telecom_networks n ON n.id=l.network_id
    LEFT JOIN users u ON u.id=l.staff_id
    LEFT JOIN accounting_accounts a ON a.id=l.account_id
    LEFT JOIN accounting_cashboxes c ON c.id=l.cashbox_id
    WHERE l.provider_key LIKE ? OR COALESCE(l.linkage_name,'') LIKE ? OR COALESCE(n.name,'') LIKE ?
       OR COALESCE(u.username,'') LIKE ? OR COALESCE(u.full_name,'') LIKE ?
       OR COALESCE(a.code,'') LIKE ? OR COALESCE(a.name,'') LIKE ?
       OR COALESCE(c.code,'') LIKE ? OR COALESCE(c.name,'') LIKE ?
    ORDER BY l.is_active DESC,l.provider_key,l.id DESC");
$list->execute([$filter,$filter,$filter,$filter,$filter,$filter,$filter,$filter,$filter]);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
include 'header.php';
?>
<style>
.tl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.tl-grid .full{grid-column:1/-1}.tl-table{min-width:1050px}.tl-table td,.tl-table th{white-space:nowrap}.muted{color:var(--text3);font-size:.82rem}.status-on{color:#00d4aa}.status-off{color:#ffadad}.hint{color:var(--text3);font-size:.82rem;line-height:1.7}@media(max-width:800px){.tl-grid{grid-template-columns:1fr}.tl-grid .full{grid-column:auto}}
</style>
<?php if ($flash): ?><div class="alert alert-<?=htmlspecialchars($flash['type'])?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<div class="page-header">
  <div><h2><i class="fas fa-signal" style="color:var(--cyan)"></i> ربطيات الاتصالات</h2><p>ربط كل مزود اتصالات بالشبكة والصندوق والموظف والحساب قبل ترحيل تكلفة العملية.</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="provider_linkages.php" class="btn btn-secondary"><i class="fas fa-project-diagram"></i> ربطيات المزودين</a><a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> ربطية جديدة</a></div>
</div>
<div class="card"><div class="card-body"><div class="hint"><b>قاعدة الترحيل:</b> لا تُرحّل عملية الاتصالات مالياً إلا عند وجود ربطية نشطة وحيدة مطابقة للمزود والشبكة، وصندوقها بعملة <b>YER</b>. عند وجود أكثر من ربطية مطابقة أو نقص الصندوق/الحساب، تبقى العملية دون قيد مالي حتى تتم المعالجة الصحيحة.</div></div></div>
<?php if (in_array($action,['add','edit'],true)): ?>
<div class="card"><div class="card-body"><h3 style="margin-top:0"><i class="fas fa-link"></i> <?=$edit ? 'تعديل ربطية الاتصالات' : 'إضافة ربطية اتصالات'?></h3>
<form method="post"><input type="hidden" name="linkage_id" value="<?=$edit['id']??0?>"><input type="hidden" name="save_linkage" value="1"><?=adminCsrfField()?>
<div class="tl-grid">
  <div class="form-group"><label>مزود الاتصالات <b style="color:#f66">*</b></label><select name="provider_key" required><option value="">اختر المزود</option><?php foreach($providerKeys as $key=>$label): ?><option value="<?=htmlspecialchars($key)?>" <?=((string)($edit['provider_key']??'')===(string)$key)?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select><small class="hint">القيم المستخدمة حالياً في مسار الاتصالات: fore وmomaiz.</small></div>
  <div class="form-group"><label>اسم الربطية</label><input name="linkage_name" value="<?=htmlspecialchars($edit['linkage_name']??'')?>" placeholder="مثال: fore — مباشر كريمي شمال"></div>
  <div class="form-group"><label>الشبكة / المجال</label><select name="network_id"><option value="">عام — كل الشبكات</option><?php foreach($networks as $n): ?><option value="<?=$n['id']?>" <?=((int)($edit['network_id']??0)===(int)$n['id'])?'selected':''?>><?=htmlspecialchars($n['name'])?><?=($n['network_number']!==null?' (#'.htmlspecialchars($n['network_number']).')':'')?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>الموظف المسؤول</label><select name="staff_id"><option value="">غير مرتبط بموظف</option><?php foreach($staff as $s): ?><option value="<?=$s['id']?>" <?=((int)($edit['staff_id']??0)===(int)$s['id'])?'selected':''?>><?=htmlspecialchars($s['full_name']?:$s['username'])?> — <?=htmlspecialchars($s['username'])?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>الحساب المالي <b style="color:#f66">*</b></label><select name="account_id" required><option value="">اختر الحساب</option><?php foreach($accounts as $a): ?><option value="<?=$a['id']?>" <?=((int)($edit['account_id']??0)===(int)$a['id'])?'selected':''?>><?=htmlspecialchars($a['code'].' — '.$a['name'].' ('.$a['currency_code'].')')?></option><?php endforeach; ?></select></div>
  <div class="form-group"><label>الصندوق التشغيلي <b style="color:#f66">*</b></label><select name="cashbox_id" required><option value="">اختر صندوق الاتصالات</option><?php foreach($cashboxes as $c): ?><option value="<?=$c['id']?>" <?=((int)($edit['cashbox_id']??0)===(int)$c['id'])?'selected':''?>><?=htmlspecialchars($c['name'].' — '.$c['code'].' ('.$c['currency_code'].')')?></option><?php endforeach; ?></select><small class="hint">يجب أن تكون عملة صندوق الاتصالات YER.</small></div>
  <div class="form-group full"><label>حالة الربطية</label><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?=(!$edit || !empty($edit['is_active']))?'checked':''?>> مفعّلة للترحيل والمطابقة</label></div>
</div><div style="display:flex;gap:8px;margin-top:15px"><button class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button><a href="accounting_telecom_linkages.php" class="btn btn-secondary">إلغاء</a></div>
</form></div></div>
<?php endif; ?>
<div class="card"><div class="card-body"><div style="overflow-x:auto"><table class="tl-table"><thead><tr><th>الحالة</th><th>الربطية</th><th>المزود</th><th>الشبكة</th><th>الموظف</th><th>الحساب</th><th>الصندوق</th><th>إجراءات</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?=$r['is_active']?'<span class="status-on">شغالة</span>':'<span class="status-off">متوقفة</span>'?></td>
<td><b><?=htmlspecialchars($r['linkage_name']?:$r['provider_key'])?></b><div class="muted">#<?=$r['id']?></div></td>
<td><?=htmlspecialchars($r['provider_key'])?></td>
<td><?=htmlspecialchars($r['network_name']?:'عام')?></td>
<td><?=htmlspecialchars($r['staff_name']?:$r['staff_username']?:'—')?></td>
<td><?=htmlspecialchars(($r['account_code']?$r['account_code'].' — ':'').($r['account_name']?:'—'))?><div class="muted"><?=htmlspecialchars($r['account_currency']?:'')?></div></td>
<td><?=htmlspecialchars($r['cashbox_name']?($r['cashbox_name'].' — '.$r['cashbox_code']):'—')?><div class="muted"><?=htmlspecialchars($r['cashbox_currency']?:'')?></div></td>
<td style="display:flex;gap:5px"><a class="btn btn-sm btn-secondary" href="?action=edit&id=<?=$r['id']?>">تعديل</a><form method="post" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="linkage_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-secondary" name="toggle_linkage" value="1"><?=$r['is_active']?'إيقاف':'تشغيل'?></button></form><form method="post" style="display:inline" onsubmit="return confirm('حذف ربطية الاتصالات؟')"><?=adminCsrfField()?><input type="hidden" name="linkage_id" value="<?=$r['id']?>"><button class="btn btn-sm btn-danger" name="delete_linkage" value="1">حذف</button></form></td>
</tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text3)">لا توجد ربطيات اتصالات بعد</td></tr><?php endif; ?></tbody></table></div>
<div class="muted" style="margin-top:12px">عدد الربطيات: <?=count($rows)?> — الصندوق والموظف الظاهران هنا هما مرجع الترحيل التشغيلي والمحاسبي للعمليات الجديدة.</div></div></div>
<?php include 'footer.php'; ?>
