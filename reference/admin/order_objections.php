<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_orders_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'اعتراضات العمليات — ' . SITE_NAME;

$pdo->exec("CREATE TABLE IF NOT EXISTS `order_objections` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`   INT NOT NULL,
  `user_id`    INT NOT NULL,
  `reason`     TEXT NOT NULL,
  `status`     ENUM('pending','reviewing','resolved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order` (`order_id`),
  KEY `idx_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// تحديث اعتراض
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_objection'])) {
    $oid       = (int)$_POST['obj_id'];
    $status    = in_array($_POST['obj_status']??'', ['pending','reviewing','resolved','rejected']) ? $_POST['obj_status'] : 'pending';
    $adminNote = trim($_POST['admin_note'] ?? '');
    $pdo->prepare("UPDATE order_objections SET status=?, admin_note=? WHERE id=?")
        ->execute([$status, $adminNote ?: null, $oid]);
    flashMessage('success', '✅ تم تحديث الاعتراض');
    redirect(SITE_URL . '/admin/order_objections.php' . ($_GET['status'] ? '?status='.$_GET['status'] : ''));
}

$filterStatus = $_GET['status'] ?? 'all';
$where = $filterStatus !== 'all' ? "AND ob.status = " . $pdo->quote($filterStatus) : '';

$objections = $pdo->query("
    SELECT ob.*,
           u.username, u.full_name, u.display_name,
           o.total_price, o.quantity, o.status as order_status,
           s.name as service_name
    FROM order_objections ob
    JOIN users   u ON u.id  = ob.user_id
    JOIN orders  o ON o.id  = ob.order_id
    JOIN services s ON s.id = o.service_id
    WHERE 1=1 $where
    ORDER BY ob.created_at DESC
")->fetchAll();

$counts = [];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) as c FROM order_objections GROUP BY status")->fetchAll() as $r) {
        $counts[$r['status']] = (int)$r['c'];
    }
} catch(Exception $e){}
$total = array_sum($counts);

// الاعتراض المفتوح للتعديل
$editId = (int)($_GET['edit'] ?? 0);

include 'header.php';
?>

<div class="page-header">
  <h2>
    <i class="fas fa-flag" style="color:#ff6677"></i> اعتراضات العمليات
    <?php if(!empty($counts['pending'])): ?>
    <span style="background:#ff4455;color:#fff;border-radius:20px;padding:1px 10px;font-size:.72rem;margin-right:6px;vertical-align:middle"><?=$counts['pending']?> جديد</span>
    <?php endif; ?>
  </h2>
</div>

<!-- تبويبات الفلتر -->
<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
<?php
$tabs = ['all'=>['الكل','#6ba3ff'],'pending'=>['قيد المراجعة','#f5a623'],'reviewing'=>['جاري المراجعة','#00d4ff'],'resolved'=>['تم الحل','#00e676'],'rejected'=>['مرفوض','#ff4455']];
foreach($tabs as $k=>[$lbl,$clr]):
  $cnt = $k==='all' ? $total : ($counts[$k]??0);
  $active = $filterStatus===$k;
?>
<a href="?status=<?=$k?>"
   style="padding:4px 14px;border-radius:20px;font-size:.77rem;font-weight:700;text-decoration:none;
          border:1px solid <?=$active?$clr:'rgba(255,255,255,.12)'?>;
          background:<?=$active?"$clr".'22':'rgba(255,255,255,.04)'?>;
          color:<?=$active?$clr:'#8895a7'?>">
  <?=$lbl?><?=$cnt?' ('.$cnt.')':''?>
</a>
<?php endforeach; ?>
</div>

<!-- الجدول -->
<div class="card">
<?php if(empty($objections)): ?>
<div style="padding:3rem;text-align:center;color:#8895a7">
  <i class="fas fa-flag" style="font-size:2rem;opacity:.2;display:block;margin-bottom:10px"></i>
  لا توجد اعتراضات
</div>
<?php else: ?>
<?php
$stCfg = [
  'pending'   => ['قيد المراجعة','#f5a623'],
  'reviewing' => ['جاري المراجعة','#00d4ff'],
  'resolved'  => ['تم الحل','#00e676'],
  'rejected'  => ['مرفوض','#ff4455'],
];
foreach($objections as $ob):
  [$stLbl,$stClr] = $stCfg[$ob['status']] ?? [$ob['status'],'#8895a7'];
  $name = $ob['display_name'] ?: ($ob['full_name'] ?: $ob['username']);
  $isEdit = $editId === (int)$ob['id'];
?>

<!-- بطاقة الاعتراض -->
<div style="border-bottom:1px solid var(--border);padding:14px 18px">
  <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">

    <!-- معلومات العميل والطلب -->
    <div style="flex:1;min-width:200px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <span style="font-weight:800;font-size:.88rem"><?=htmlspecialchars($name)?></span>
        <span style="font-size:.7rem;color:#8895a7">@<?=htmlspecialchars($ob['username'])?></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <a href="orders.php?search=<?=$ob['order_id']?>"
           style="color:var(--cyan);font-size:.78rem;font-weight:700;text-decoration:none">
          #<?=str_pad($ob['order_id'],6,'0',STR_PAD_LEFT)?>
        </a>
        <span style="font-size:.75rem;color:#8895a7"><?=htmlspecialchars(mb_substr($ob['service_name'],0,30))?></span>
        <span style="font-size:.72rem;color:#8895a7"><?=number_format((float)$ob['total_price'],4)?></span>
      </div>
      <div style="margin-top:6px;font-size:.8rem;color:var(--text);line-height:1.6;background:rgba(255,255,255,.03);border-radius:8px;padding:8px 10px;border-right:3px solid <?=$stClr?>">
        <?=nl2br(htmlspecialchars($ob['reason']))?>
      </div>
      <?php if($ob['admin_note']): ?>
      <div style="margin-top:5px;font-size:.74rem;color:#a78bfa;background:rgba(167,139,250,.07);padding:6px 10px;border-radius:7px">
        <i class="fas fa-reply" style="font-size:.65rem"></i> <?=htmlspecialchars($ob['admin_note'])?>
      </div>
      <?php endif; ?>
    </div>

    <!-- الحالة والإجراء -->
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0">
      <span style="background:<?=$stClr?>18;color:<?=$stClr?>;border:1px solid <?=$stClr?>33;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700">
        <?=$stLbl?>
      </span>
      <div style="font-size:.68rem;color:#8895a7"><?=date('Y-m-d H:i', strtotime($ob['created_at']))?></div>
      <a href="?<?=http_build_query(array_merge($_GET, ['edit' => $isEdit ? 0 : $ob['id']]))?>#obj<?=$ob['id']?>"
         style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:.75rem;font-weight:700;text-decoration:none;
                background:<?=$isEdit?'rgba(245,166,35,.15)':'rgba(255,255,255,.06)'?>;
                border:1px solid <?=$isEdit?'rgba(245,166,35,.4)':'rgba(255,255,255,.12)'?>;
                color:<?=$isEdit?'#f5a623':'var(--text2)'?>">
        <i class="fas fa-<?=$isEdit?'times':'edit'?>"></i>
        <?=$isEdit?'إغلاق':'تعديل'?>
      </a>
    </div>
  </div>

  <!-- نموذج التعديل — inline بدون modal -->
  <?php if($isEdit): ?>
  <div id="obj<?=$ob['id']?>"
       style="margin-top:12px;background:rgba(245,166,35,.05);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:16px">
    <div style="font-size:.8rem;font-weight:800;color:#f5a623;margin-bottom:12px">
      <i class="fas fa-edit"></i> تعديل الاعتراض #<?=$ob['id']?>
    </div>
    <form method="POST" action="order_objections.php<?=$filterStatus!=='all'?'?status='.$filterStatus:''?>
        <?= adminCsrfField() ?>">
      <input type="hidden" name="update_objection" value="1">
      <input type="hidden" name="obj_id" value="<?=$ob['id']?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="display:block;font-size:.75rem;color:#8895a7;margin-bottom:4px">الحالة</label>
          <select name="obj_status" class="form-control">
            <?php foreach(['pending'=>'قيد المراجعة','reviewing'=>'جاري المراجعة','resolved'=>'تم الحل','rejected'=>'مرفوض'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=$ob['status']===$v?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:.75rem;color:#8895a7;margin-bottom:4px">رد للعميل <span style="opacity:.6">(اختياري)</span></label>
          <input type="text" name="admin_note" class="form-control"
                 value="<?=htmlspecialchars($ob['admin_note']??'')?>"
                 placeholder="رسالة مختصرة للعميل...">
        </div>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-success btn-sm">
          <i class="fas fa-save"></i> حفظ التغييرات
        </button>
        <a href="?status=<?=$filterStatus?>" class="btn btn-secondary btn-sm">إلغاء</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<?php include 'footer.php'; ?>
