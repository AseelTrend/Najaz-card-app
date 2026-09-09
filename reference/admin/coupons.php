<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'كوبونات الخصم — ' . SITE_NAME;

foreach (["CREATE TABLE IF NOT EXISTS `coupons` (`id` INT AUTO_INCREMENT PRIMARY KEY,`code` VARCHAR(50) NOT NULL UNIQUE,`name` VARCHAR(200) NOT NULL,`discount_type` ENUM('percent','fixed') DEFAULT 'percent',`discount_value` DECIMAL(10,4) DEFAULT 0,`min_order` DECIMAL(10,4) DEFAULT 0,`max_discount` DECIMAL(10,4) DEFAULT NULL,`applies_to` ENUM('all','category','service') DEFAULT 'all',`category_id` INT DEFAULT NULL,`service_id` INT DEFAULT NULL,`condition_type` ENUM('none','not_referred','referred') DEFAULT 'none',`usage_limit` INT DEFAULT NULL,`usage_per_user` INT DEFAULT 1,`used_count` INT DEFAULT 0,`starts_at` TIMESTAMP NULL,`expires_at` TIMESTAMP NULL,`status` TINYINT(1) DEFAULT 1,`created_by` INT DEFAULT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS `coupon_uses` (`id` INT AUTO_INCREMENT PRIMARY KEY,`coupon_id` INT NOT NULL,`user_id` INT NOT NULL,`order_id` INT DEFAULT NULL,`discount` DECIMAL(10,4) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `coupon_code` VARCHAR(50) DEFAULT NULL",
"ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `coupon_discount` DECIMAL(10,4) DEFAULT 0"
] as $sql) { try { $pdo->exec($sql); } catch(Exception $e) {} }

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM coupon_uses WHERE coupon_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM coupons WHERE id=?")->execute([$id]);
    flashMessage('success','تم الحذف'); redirect(SITE_URL.'/admin/coupons.php');
}
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE coupons SET status=1-status WHERE id=?")->execute([$id]);
    redirect(SITE_URL.'/admin/coupons.php');
}
// إعادة تنشيط الكوبون لعميل معين (حذف سجل استخدامه)
if ($action === 'revoke_use' && $id) {
    $useId  = (int)($_GET['use_id'] ?? 0);
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($useId) {
        $pdo->prepare("DELETE FROM coupon_uses WHERE id=?")->execute([$useId]);
        $pdo->prepare("UPDATE coupons SET used_count=GREATEST(0,used_count-1) WHERE id=?")->execute([$id]);
        flashMessage('success','✅ تم إعادة تنشيط الكوبون للعميل');
    }
    redirect(SITE_URL.'/admin/coupons.php?action=uses&id='.$id);
}
// تمديد انتهاء الكوبون
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['extend_expiry'])) {
    $newExpiry = $_POST['expires_at'] ?? '';
    $pdo->prepare("UPDATE coupons SET expires_at=?, status=1 WHERE id=?")->execute([$newExpiry?:null, $id]);
    flashMessage('success','✅ تم تمديد صلاحية الكوبون');
    redirect(SITE_URL.'/admin/coupons.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
    $d = $_POST;
    $editId = (int)($d['edit_id'] ?? 0);
    $vals = [
        strtoupper(trim($d['code'])),
        trim($d['name']),
        $d['discount_type'],
        (float)$d['discount_value'],
        (float)($d['min_order'] ?? 0),
        $d['max_discount'] !== '' ? (float)$d['max_discount'] : null,
        $d['applies_to'],
        $d['category_id'] ?: null,
        $d['service_id'] ?: null,
        $d['condition_type'],
        $d['usage_limit'] !== '' ? (int)$d['usage_limit'] : null,
        (int)($d['usage_per_user'] ?? 1),
        $d['starts_at'] ?: null,
        $d['expires_at'] ?: null,
    ];
    if ($editId) {
        $pdo->prepare("UPDATE coupons SET code=?,name=?,discount_type=?,discount_value=?,min_order=?,max_discount=?,applies_to=?,category_id=?,service_id=?,condition_type=?,usage_limit=?,usage_per_user=?,starts_at=?,expires_at=? WHERE id=?")->execute([...$vals, $editId]);
    } else {
        $pdo->prepare("INSERT INTO coupons (code,name,discount_type,discount_value,min_order,max_discount,applies_to,category_id,service_id,condition_type,usage_limit,usage_per_user,starts_at,expires_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$vals, $_SESSION['user_id']]);
    }
    flashMessage('success','✅ تم حفظ الكوبون');
    redirect(SITE_URL.'/admin/coupons.php');
}

$editCoupon = null;
if ($action==='edit' && $id) { $e=$pdo->prepare("SELECT * FROM coupons WHERE id=?");$e->execute([$id]);$editCoupon=$e->fetch(); }

// صفحة استخدامات كوبون معين
$couponUses = []; $viewCoupon = null;
if ($action === 'uses' && $id) {
    $vc=$pdo->prepare("SELECT * FROM coupons WHERE id=?");$vc->execute([$id]);$viewCoupon=$vc->fetch();
    $couponUses = $pdo->query("
        SELECT cu.*, u.username, u.full_name, u.email,
               o.id as order_num, o.total_price as order_total
        FROM coupon_uses cu
        JOIN users u ON cu.user_id=u.id
        LEFT JOIN orders o ON cu.order_id=o.id
        WHERE cu.coupon_id=$id
        ORDER BY cu.created_at DESC
    ")->fetchAll();
}

$now_ts = time();
$coupons = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM coupon_uses WHERE coupon_id=c.id) as real_uses,
        CASE
            WHEN c.status=0 THEN 'disabled'
            WHEN c.expires_at IS NOT NULL AND c.expires_at < NOW() THEN 'expired'
            WHEN c.starts_at IS NOT NULL AND c.starts_at > NOW() THEN 'scheduled'
            WHEN c.usage_limit IS NOT NULL AND c.used_count >= c.usage_limit THEN 'exhausted'
            ELSE 'active'
        END as computed_status
    FROM coupons c ORDER BY c.created_at DESC
")->fetchAll();

// إحصاءات
$stats = [
    'total'    => count($coupons),
    'active'   => count(array_filter($coupons, function($c){ return $c['computed_status']==='active'; })),
    'expired'  => count(array_filter($coupons, function($c){ return $c['computed_status']==='expired'; })),
    'total_discount' => (float)$pdo->query("SELECT COALESCE(SUM(discount),0) FROM coupon_uses")->fetchColumn(),
];
$categories = $pdo->query("SELECT id, name FROM categories WHERE status=1 ORDER BY sort_order")->fetchAll();
$services   = $pdo->query("SELECT id, name FROM services WHERE status=1 ORDER BY name")->fetchAll();

include 'header.php';
?>
<style>
.cond-section{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:12px}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-ticket-alt"></i></div>
      كوبونات الخصم
    </div>
  </div>
  <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> كوبون جديد</a>
</div>

<?php if ($action==='add' || $action==='edit'): ?>
<div class="card mb-2">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-<?=$editCoupon?'edit':'plus-circle'?>"></i> <?=$editCoupon?'تعديل':'إضافة'?> كوبون</div>
    <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> القائمة</a>
  </div>
  <div class="card-body">
  <form method="POST" id="couponForm">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save" value="1">
    <input type="hidden" name="edit_id" value="<?=$editCoupon['id']??0?>">

    <div class="form-grid">
      <div class="form-group">
        <label><i class="fas fa-tag"></i> كود الكوبون *</label>
        <div style="display:flex;gap:8px">
          <input type="text" name="code" id="couponCode" class="form-control" value="<?=htmlspecialchars($editCoupon['code']??'')?>" placeholder="مثال: SAVE20" required style="text-transform:uppercase;font-weight:900;letter-spacing:2px">
          <button type="button" onclick="genCode()" class="btn btn-secondary" title="توليد عشوائي"><i class="fas fa-random"></i></button>
        </div>
      </div>
      <div class="form-group">
        <label><i class="fas fa-info-circle"></i> اسم/وصف الكوبون *</label>
        <input type="text" name="name" class="form-control" value="<?=htmlspecialchars($editCoupon['name']??'')?>" placeholder="مثال: خصم 20% لعملاء رمضان" required>
      </div>
    </div>

    <!-- نوع الخصم -->
    <div class="cond-section">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:10px"><i class="fas fa-percent" style="color:var(--primary)"></i> قيمة الخصم</div>
      <div class="form-grid">
        <div class="form-group">
          <label>نوع الخصم</label>
          <select name="discount_type" class="form-control" id="discountType" onchange="toggleMaxDiscount()">
            <option value="percent" <?=($editCoupon['discount_type']??'percent')==='percent'?'selected':''?>>نسبة مئوية (%)</option>
            <option value="fixed"   <?=($editCoupon['discount_type']??'')==='fixed'?'selected':''?>>مبلغ ثابت ($)</option>
          </select>
        </div>
        <div class="form-group">
          <label>قيمة الخصم *</label>
          <input type="number" name="discount_value" class="form-control" value="<?=$editCoupon['discount_value']??''?>" min="0.01" step="0.01" required>
        </div>
        <div class="form-group" id="maxDiscountField" style="display:<?=($editCoupon['discount_type']??'percent')==='percent'?'block':'none'?>">
          <label>أقصى خصم ($) <small style="color:var(--text3)">اختياري</small></label>
          <input type="number" name="max_discount" class="form-control" value="<?=$editCoupon['max_discount']??''?>" min="0" step="0.01" placeholder="بلا حد">
        </div>
        <div class="form-group">
          <label>الحد الأدنى للطلب ($)</label>
          <input type="number" name="min_order" class="form-control" value="<?=$editCoupon['min_order']??0?>" min="0" step="0.01">
        </div>
      </div>
    </div>

    <!-- نطاق التطبيق -->
    <div class="cond-section">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:10px"><i class="fas fa-bullseye" style="color:#f5a623"></i> نطاق التطبيق</div>
      <div class="form-grid">
        <div class="form-group">
          <label>ينطبق على</label>
          <select name="applies_to" class="form-control" id="appliesTo" onchange="toggleAppliesTo()">
            <option value="all"      <?=($editCoupon['applies_to']??'all')==='all'?'selected':''?>>كل الخدمات</option>
            <option value="category" <?=($editCoupon['applies_to']??'')==='category'?'selected':''?>>قسم محدد</option>
            <option value="service"  <?=($editCoupon['applies_to']??'')==='service'?'selected':''?>>خدمة محددة</option>
          </select>
        </div>
        <div class="form-group" id="categoryField" style="display:<?=($editCoupon['applies_to']??'')==='category'?'block':'none'?>">
          <label>القسم</label>
          <select name="category_id" class="form-control">
            <option value="">— اختر قسم —</option>
            <?php foreach($categories as $c): ?>
            <option value="<?=$c['id']?>" <?=($editCoupon['category_id']??'')==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="serviceField" style="display:<?=($editCoupon['applies_to']??'')==='service'?'block':'none'?>">
          <label>الخدمة</label>
          <select name="service_id" class="form-control">
            <option value="">— اختر خدمة —</option>
            <?php foreach($services as $s): ?>
            <option value="<?=$s['id']?>" <?=($editCoupon['service_id']??'')==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- شرط الإحالة -->
    <div class="cond-section">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:10px"><i class="fas fa-users" style="color:#00c853"></i> شرط الإحالة</div>
      <div class="form-group">
        <select name="condition_type" class="form-control">
          <option value="none"         <?=($editCoupon['condition_type']??'none')==='none'?'selected':''?>>بدون شرط — للجميع</option>
          <option value="not_referred" <?=($editCoupon['condition_type']??'')==='not_referred'?'selected':''?>>فقط للعملاء غير المدعوين</option>
          <option value="referred"     <?=($editCoupon['condition_type']??'')==='referred'?'selected':''?>>فقط للعملاء المدعوين عبر إحالة</option>
        </select>
      </div>
    </div>

    <!-- حدود الاستخدام والصلاحية -->
    <div class="cond-section">
      <div style="font-weight:800;font-size:.88rem;margin-bottom:10px"><i class="fas fa-clock" style="color:var(--cyan)"></i> حدود الاستخدام والصلاحية</div>
      <div class="form-grid">
        <div class="form-group">
          <label>عدد الاستخدامات الكلي <small style="color:var(--text3)">اتركه فارغاً = بلا حد</small></label>
          <input type="number" name="usage_limit" class="form-control" value="<?=$editCoupon['usage_limit']??''?>" min="1" placeholder="بلا حد">
        </div>
        <div class="form-group">
          <label>مرات لكل عميل</label>
          <input type="number" name="usage_per_user" class="form-control" value="<?=$editCoupon['usage_per_user']??1?>" min="1">
        </div>
        <div class="form-group">
          <label>تاريخ البداية <small style="color:var(--text3)">اختياري</small></label>
          <input type="datetime-local" name="starts_at" class="form-control" value="<?=$editCoupon['starts_at']?date('Y-m-d\TH:i',strtotime($editCoupon['starts_at'])):''; ?>">
        </div>
        <div class="form-group">
          <label>تاريخ الانتهاء <small style="color:var(--text3)">اختياري</small></label>
          <input type="datetime-local" name="expires_at" class="form-control" value="<?=$editCoupon['expires_at']?date('Y-m-d\TH:i',strtotime($editCoupon['expires_at'])):''; ?>">
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> حفظ الكوبون</button>
  </form>
  </div>
</div>
<?php endif; ?>

<!-- إحصاءات -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">
  <?php foreach([
    ['إجمالي الكوبونات',$stats['total'],'ticket-alt','var(--primary)'],
    ['فعّالة',$stats['active'],'check-circle','#00e676'],
    ['منتهية',$stats['expired'],'times-circle','#ff4455'],
    ['إجمالي الخصم',number_format($stats['total_discount'],2).'$','dollar-sign','#f5a623'],
  ] as [$lbl,$val,$icon,$color]): ?>
  <div class="card" style="text-align:center;padding:14px">
    <div style="font-size:1.6rem;font-weight:900;color:<?=$color?>"><?=$val?></div>
    <div style="font-size:.75rem;color:var(--text3);margin-top:3px"><i class="fas fa-<?=$icon?>"></i> <?=$lbl?></div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($action==='uses' && $viewCoupon): ?>
<!-- ══ صفحة استخدامات الكوبون ══ -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-history"></i> استخدامات كوبون: <code style="color:var(--cyan)"><?=htmlspecialchars($viewCoupon['code'])?></code>
      <span style="font-size:12px;color:var(--text3);margin-right:8px"><?=count($couponUses)?> استخدام</span>
    </div>
    <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i> رجوع</a>
  </div>
  <?php if(empty($couponUses)): ?>
  <div class="card-body" style="text-align:center;color:var(--text3);padding:2rem">لا توجد استخدامات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>العميل</th><th>الطلب</th><th>الخصم</th><th>التاريخ</th><th>إجراء</th></tr></thead>
      <tbody>
      <?php foreach($couponUses as $u): ?>
      <tr>
        <td><?=$u['id']?></td>
        <td>
          <strong><?=htmlspecialchars($u['full_name']?:$u['username'])?></strong>
          <div style="font-size:11px;color:var(--text3)"><?=htmlspecialchars($u['email']??'')?></div>
        </td>
        <td>
          <?php if($u['order_num']): ?>
          <a href="orders.php?id=<?=$u['order_num']?>" style="color:var(--cyan);font-weight:700">#<?=$u['order_num']?></a>
          <div style="font-size:11px;color:var(--text3)"><?=number_format($u['order_total'],4)?> $</div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="color:#f5a623;font-weight:800">-<?=number_format($u['discount'],4)?> $</td>
        <td style="font-size:11px;color:var(--text3)"><?=date('d/m/Y H:i',strtotime($u['created_at']))?></td>
        <td>
          <a href="?action=revoke_use&id=<?=$viewCoupon['id']?>&use_id=<?=$u['id']?>&user_id=<?=$u['user_id']?>"
             class="btn btn-sm btn-warning"
             onclick="return confirm('إعادة تنشيط الكوبون للعميل <?=htmlspecialchars(addslashes($u['username']))?>؟ سيتمكن من استخدامه مجدداً')"
             title="إعادة تنشيط للعميل">
            <i class="fas fa-redo"></i> إعادة تنشيط
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>

<!-- ══ فلتر الكوبونات ══ -->
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
  <button onclick="filterCoupons('all',this)"     class="cpn-filter active">الكل (<?=count($coupons)?>)</button>
  <button onclick="filterCoupons('active',this)"  class="cpn-filter">✅ فعّالة (<?=$stats['active']?>)</button>
  <button onclick="filterCoupons('expired',this)" class="cpn-filter">❌ منتهية (<?=$stats['expired']?>)</button>
  <button onclick="filterCoupons('exhausted',this)" class="cpn-filter">🚫 مستنفدة</button>
  <button onclick="filterCoupons('disabled',this)"  class="cpn-filter">⏸ معطلة</button>
</div>

<!-- قائمة الكوبونات -->
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> الكوبونات (<?=count($coupons)?>)</div></div>
  <?php if(empty($coupons)): ?>
  <div class="card-body" style="text-align:center;padding:2rem;color:var(--text3)">
    <i class="fas fa-ticket-alt" style="font-size:2rem;opacity:.1;display:block;margin-bottom:10px"></i>
    لا توجد كوبونات بعد
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>الكود</th><th>الخصم</th><th>ينطبق على</th><th>الشرط</th><th>الاستخدام</th><th>الصلاحية</th><th>الحالة</th><th>إجراءات</th></tr></thead>
      <tbody>
      <?php foreach($coupons as $c):
        $now = time();
        $expired = $c['expires_at'] && strtotime($c['expires_at']) < $now;
        $notStarted = $c['starts_at'] && strtotime($c['starts_at']) > $now;
      ?>
      <tr data-status="<?=$cs?>">
        <td>
          <code style="font-size:.9rem;font-weight:900;color:var(--cyan);letter-spacing:1px"><?=htmlspecialchars($c['code'])?></code>
          <div style="font-size:11px;color:var(--text3)"><?=htmlspecialchars($c['name'])?></div>
        </td>
        <td style="font-weight:900;color:#f5a623">
          <?=$c['discount_type']==='percent'
            ? number_format($c['discount_value'],1).'%'
            : number_format($c['discount_value'],2).'$'?>
          <?php if($c['min_order']>0): ?><div style="font-size:10px;color:var(--text3)">حد أدنى: <?=number_format($c['min_order'],2)?>$</div><?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?php if($c['applies_to']==='all'): ?>
          <span style="color:var(--text2)">كل الخدمات</span>
          <?php elseif($c['applies_to']==='category'): ?>
          <span style="color:var(--primary)"><i class="fas fa-folder"></i> قسم #<?=$c['category_id']?></span>
          <?php else: ?>
          <span style="color:var(--cyan)"><i class="fas fa-box"></i> خدمة #<?=$c['service_id']?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px">
          <?php $condMap=['none'=>['الجميع','var(--text3)'],'not_referred'=>['غير مدعو','#f5a623'],'referred'=>['مدعو فقط','#00c853']]; $cond=$condMap[$c['condition_type']]??['—','var(--text3)']; ?>
          <span style="color:<?=$cond[1]?>"><?=$cond[0]?></span>
        </td>
        <td style="font-size:12px">
          <strong><?=$c['real_uses']?></strong><?=$c['usage_limit']?' / '.$c['usage_limit']:' / ∞'?>
          <div style="font-size:10px;color:var(--text3)"><?=$c['usage_per_user']?> لكل عميل</div>
        </td>
        <td style="font-size:11px;color:var(--text3)">
          <?php if($expired): ?><span style="color:#ff4455">منتهي</span>
          <?php elseif($notStarted): ?><span style="color:#f5a623">لم يبدأ</span>
          <?php elseif($c['expires_at']): ?><?=date('d/m/Y',strtotime($c['expires_at']))?>
          <?php else: ?>دائم<?php endif; ?>
        </td>
        <td>
          <?php
            $cs = $c['computed_status'];
            $csMap = [
              'active'    => ['✓ فعّال',   'badge-success'],
              'expired'   => ['❌ منتهي',  'badge-danger'],
              'exhausted' => ['🚫 مستنفد','badge-secondary'],
              'scheduled' => ['⏳ مجدول', 'badge-warning'],
              'disabled'  => ['⏸ معطل',  'badge-secondary'],
            ];
            [$cslabel,$csbadge] = $csMap[$cs] ?? ['؟','badge-secondary'];
          ?>
          <span class="badge <?=$csbadge?>"><?=$cslabel?></span>
        </td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            <a href="?action=uses&id=<?=$c['id']?>" class="btn btn-sm btn-primary" title="الاستخدامات">
              <i class="fas fa-history"></i>
              <?php if($c['real_uses']>0): ?><span style="background:rgba(255,255,255,.2);border-radius:8px;padding:0 5px"><?=$c['real_uses']?></span><?php endif; ?>
            </a>
            <a href="?action=toggle&id=<?=$c['id']?>" class="btn btn-sm btn-<?=$c['status']?'secondary':'success'?>" title="<?=$c['status']?'تعطيل':'تفعيل'?>">
              <i class="fas fa-<?=$c['status']?'pause':'play'?>"></i>
            </a>
            <?php if($c['computed_status']==='expired'): ?>
            <button onclick="extendCoupon(<?=$c['id']?>)" class="btn btn-sm btn-warning" title="تمديد الصلاحية"><i class="fas fa-calendar-plus"></i></button>
            <?php endif; ?>
            <a href="?action=edit&id=<?=$c['id']?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
            <a href="?action=delete&id=<?=$c['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف كوبون <?=htmlspecialchars(addslashes($c['code']))?>؟')"><i class="fas fa-trash"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; // end if not uses page ?>

<style>
.cpn-filter{padding:5px 14px;border-radius:20px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text2);font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer;transition:.15s}
.cpn-filter.active{background:var(--primary);border-color:var(--primary);color:#fff}
</style>

<script>
function filterCoupons(f, btn) {
  document.querySelectorAll('.cpn-filter').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('#couponsTable tbody tr').forEach(tr => {
    tr.style.display = f==='all' || tr.dataset.status===f ? '' : 'none';
  });
}
function extendCoupon(id) {
  const newDate = prompt('أدخل تاريخ الانتهاء الجديد (YYYY-MM-DD HH:MM):');
  if (!newDate) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '?action=extend&id=' + id;
  form.innerHTML = '<input name="extend_expiry" value="1"><input name="expires_at" value="'+newDate+':00">';
  document.body.appendChild(form);
  form.submit();
}
function genCode() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let code = '';
  for (let i=0;i<8;i++) code += chars[Math.floor(Math.random()*chars.length)];
  document.getElementById('couponCode').value = code;
}
function toggleMaxDiscount() {
  document.getElementById('maxDiscountField').style.display =
    document.getElementById('discountType').value==='percent' ? 'block' : 'none';
}
function toggleAppliesTo() {
  const v = document.getElementById('appliesTo').value;
  document.getElementById('categoryField').style.display = v==='category' ? 'block' : 'none';
  document.getElementById('serviceField').style.display  = v==='service'  ? 'block' : 'none';
}
</script>

<?php include 'footer.php'; ?>
