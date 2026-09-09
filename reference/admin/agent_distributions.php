<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'التوزيع برو — ' . SITE_NAME;

// ══════════════════════════════════════════════════════════════════
// إنشاء الجداول
// ══════════════════════════════════════════════════════════════════
$pdo->exec("CREATE TABLE IF NOT EXISTS `agent_distributions` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(150) NOT NULL          COMMENT 'اسم/عنوان التوزيع',
  `target_type`     ENUM('group','user') NOT NULL  COMMENT 'مجموعة أو عميل مباشر',
  `target_group_id` INT DEFAULT NULL               COMMENT 'pricing_groups.id',
  `target_user_id`  INT DEFAULT NULL               COMMENT 'users.id',
  `service_type`    ENUM('telecom','service','both') NOT NULL DEFAULT 'both',
  `status`          TINYINT(1) DEFAULT 1,
  `sort_order`      INT DEFAULT 0,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_group`   (`target_group_id`,`status`),
  KEY `idx_user`    (`target_user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `agent_distribution_routes` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `distribution_id` INT NOT NULL,
  `method_id`       INT NOT NULL    COMMENT 'floosak_agent_methods.method_id أو services.id',
  `op_key`          ENUM('amount','fees','bundles','order') NOT NULL DEFAULT 'amount',
  `agent_id`        INT NOT NULL    COMMENT 'telecom_agents.id',
  `priority`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_dist_route` (`distribution_id`,`method_id`,`op_key`,`agent_id`),
  KEY `idx_lookup`  (`distribution_id`,`method_id`,`op_key`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// إضافة عمود group_id لـ users إن لم يكن موجوداً
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
    if (!in_array('group_id', $cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN `group_id` INT DEFAULT NULL AFTER `role`");
    }
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════════
// معالجة الطلبات
// ══════════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? 'list';
$did    = (int)($_GET['id'] ?? 0);

// ── حفظ / تعديل توزيع ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dist'])) {
    $id          = (int)($_POST['dist_id'] ?? 0);
    $name        = trim($_POST['dist_name'] ?? '');
    $targetType  = $_POST['target_type'] === 'user' ? 'user' : 'group';
    $groupId     = $_POST['target_group_id'] !== '' ? (int)$_POST['target_group_id'] : null;
    $userId      = $_POST['target_user_id']  !== '' ? (int)$_POST['target_user_id']  : null;
    $svcType     = in_array($_POST['service_type']??'', ['telecom','service','both']) ? $_POST['service_type'] : 'both';
    $status      = isset($_POST['dist_status']) ? 1 : 0;
    $sort        = (int)($_POST['dist_sort'] ?? 0);

    if (empty($name)) { flashMessage('danger','اسم التوزيع مطلوب'); redirect(SITE_URL.'/admin/agent_distributions.php?action='.($id?'edit&id='.$id:'add')); }

    // تحقق: لا مجموعة وعميل معاً
    if ($targetType === 'group' && !$groupId) { flashMessage('danger','يجب اختيار مجموعة'); redirect(SITE_URL.'/admin/agent_distributions.php?action='.($id?'edit&id='.$id:'add')); }
    if ($targetType === 'user'  && !$userId)  { flashMessage('danger','يجب اختيار عميل');   redirect(SITE_URL.'/admin/agent_distributions.php?action='.($id?'edit&id='.$id:'add')); }

    // تحقق: لا تكرار (نفس المجموعة/العميل في توزيعين)
    if ($targetType === 'group') {
        $dup = $pdo->prepare("SELECT id FROM agent_distributions WHERE target_type='group' AND target_group_id=? AND id!=?");
        $dup->execute([$groupId, $id]);
    } else {
        $dup = $pdo->prepare("SELECT id FROM agent_distributions WHERE target_type='user' AND target_user_id=? AND id!=?");
        $dup->execute([$userId, $id]);
    }
    if ($dup->fetch()) {
        flashMessage('danger', '⚠️ هذه المجموعة/العميل مضافة بالفعل لتوزيع آخر');
        redirect(SITE_URL.'/admin/agent_distributions.php?action='.($id?'edit&id='.$id:'add'));
    }

    $finalGroupId = $targetType === 'group' ? $groupId : null;
    $finalUserId  = $targetType === 'user'  ? $userId  : null;

    if ($id) {
        $pdo->prepare("UPDATE agent_distributions SET name=?,target_type=?,target_group_id=?,target_user_id=?,service_type=?,status=?,sort_order=? WHERE id=?")
            ->execute([$name,$targetType,$finalGroupId,$finalUserId,$svcType,$status,$sort,$id]);
        flashMessage('success', "✅ تم تحديث التوزيع: $name");
    } else {
        $pdo->prepare("INSERT INTO agent_distributions (name,target_type,target_group_id,target_user_id,service_type,status,sort_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([$name,$targetType,$finalGroupId,$finalUserId,$svcType,$status,$sort]);
        $newId = (int)$pdo->lastInsertId();
        flashMessage('success', "✅ تمت إضافة التوزيع — الآن حدد المزودين");
        redirect(SITE_URL.'/admin/agent_distributions.php?action=routes&id='.$newId);
    }
    redirect(SITE_URL.'/admin/agent_distributions.php');
}

// ── حذف توزيع ───────────────────────────────────────────────────
if ($action === 'delete' && $did && $IS_ADMIN) {
    $pdo->prepare("DELETE FROM agent_distribution_routes WHERE distribution_id=?")->execute([$did]);
    $pdo->prepare("DELETE FROM agent_distributions WHERE id=?")->execute([$did]);
    flashMessage('success', 'تم حذف التوزيع');
    redirect(SITE_URL.'/admin/agent_distributions.php');
}

// ── حفظ المزودين (routes) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_routes'])) {
    $distId  = (int)($_POST['dist_id'] ?? 0);
    $routes  = $_POST['routes'] ?? [];
    $validOps = ['amount','fees','bundles','order'];

    foreach ($routes as $methodId => $ops) {
        $methodId = (int)$methodId;
        foreach ($ops as $opKey => $agentIds) {
            if (!in_array($opKey, $validOps)) continue;
            $pdo->prepare("DELETE FROM agent_distribution_routes WHERE distribution_id=? AND method_id=? AND op_key=?")
                ->execute([$distId, $methodId, $opKey]);
            if (!is_array($agentIds)) continue;
            $priority = 1;
            foreach ($agentIds as $agentId) {
                $agentId = (int)$agentId;
                if ($agentId <= 0) continue;
                $pdo->prepare("INSERT IGNORE INTO agent_distribution_routes (distribution_id,method_id,op_key,agent_id,priority) VALUES (?,?,?,?,?)")
                    ->execute([$distId,$methodId,$opKey,$agentId,$priority]);
                $priority++;
            }
        }
    }
    flashMessage('success', '✅ تم حفظ مزودي التوزيع');
    redirect(SITE_URL.'/admin/agent_distributions.php?action=routes&id='.$distId);
}

// ── تبديل حالة ──────────────────────────────────────────────────
if ($action === 'toggle' && $did) {
    $pdo->prepare("UPDATE agent_distributions SET status=IF(status=1,0,1) WHERE id=?")->execute([$did]);
    redirect(SITE_URL.'/admin/agent_distributions.php');
}

// ══════════════════════════════════════════════════════════════════
// جلب البيانات
// ══════════════════════════════════════════════════════════════════
$distributions = $pdo->query("
    SELECT d.*,
           pg.name as group_name,
           pg.default_value as group_pct,
           u.username as user_username,
           u.full_name as user_fullname,
           (SELECT COUNT(*) FROM agent_distribution_routes WHERE distribution_id=d.id) as routes_count
    FROM agent_distributions d
    LEFT JOIN pricing_groups pg ON pg.id = d.target_group_id
    LEFT JOIN users u ON u.id = d.target_user_id
    ORDER BY d.sort_order, d.id
")->fetchAll();

$agents = $pdo->query("SELECT * FROM telecom_agents WHERE status=1 ORDER BY sort_order,id")->fetchAll();

$editDist = null;
if (in_array($action, ['edit','routes']) && $did) {
    $es = $pdo->prepare("SELECT * FROM agent_distributions WHERE id=?");
    $es->execute([$did]);
    $editDist = $es->fetch();
}

// جلب المجموعات والعملاء للـ dropdown
$pricingGroups = [];
try { $pricingGroups = $pdo->query("SELECT id,name,default_value FROM pricing_groups WHERE status=1 ORDER BY name")->fetchAll(); } catch(Exception $e){}

$allUsers = $pdo->query("SELECT id,username,full_name FROM users WHERE role='customer' AND (is_deleted IS NULL OR is_deleted=0) ORDER BY username")->fetchAll();

// للصفحة routes: جلب الشبكات + الخدمات + الربط الحالي
$networkGroups = ['TOPUP'=>[],'BILLPAY'=>[]];
$servicesData  = [];
$routeMap      = []; // [method_id][op_key] = [agents ordered by priority]

if ($action === 'routes' && $editDist) {
    // شبكات Telecom
    try {
        $methods = $pdo->query("SELECT method_id,name_ar,color,icon,transaction_type FROM floosak_agent_methods WHERE status=1 ORDER BY transaction_type DESC,sort_order,id")->fetchAll();
        $methodOps = [];
        try {
            $bops = $pdo->query("SELECT DISTINCT method_id,
                MAX(CASE WHEN section IN('amount','yemen4g_credit') THEN 1 ELSE 0 END) ha,
                MAX(CASE WHEN section='fees' THEN 1 ELSE 0 END) hf,
                MAX(CASE WHEN section IN('bundles','yemen4g_change','yemen4g_internet','yemen4g_voice') THEN 1 ELSE 0 END) hb
                FROM floosak_agent_bunches WHERE status=1 GROUP BY method_id")->fetchAll();
            foreach ($bops as $bo) {
                $ops = [];
                if ($bo['ha']) $ops['amount']  = 'رصيد مفتوح';
                if ($bo['hf']) $ops['fees']    = 'فئات';
                if ($bo['hb']) $ops['bundles'] = 'باقات';
                if ($ops) $methodOps[$bo['method_id']] = $ops;
            }
        } catch(Exception $e){}
        foreach ($methods as $m) {
            $mid = (int)$m['method_id'];
            $ops = $methodOps[$mid] ?? ($m['transaction_type']==='TOPUP' ? ['amount'=>'رصيد مفتوح'] : ['amount'=>'سداد/استعلام']);
            $networkGroups[$m['transaction_type']][$mid] = ['name'=>$m['name_ar'],'color'=>$m['color']?:'#6c3fe0','icon'=>$m['icon']?:'sim-card','ops'=>$ops];
        }
    } catch(Exception $e){}

    // خدمات services
    if (in_array($editDist['service_type'], ['service','both'])) {
        try {
            $svcs = $pdo->query("SELECT s.id,s.name,c.name as cat_name FROM services s LEFT JOIN categories c ON c.id=s.category_id WHERE s.status=1 AND s.deleted_at IS NULL ORDER BY c.name,s.name")->fetchAll();
            foreach ($svcs as $s) {
                $servicesData[$s['id']] = ['name'=>$s['name'],'cat'=>$s['cat_name']??'بدون قسم'];
            }
        } catch(Exception $e){}
    }

    // الربط الحالي
    $rRows = $pdo->prepare("
        SELECT dr.method_id, dr.op_key, dr.priority, a.id as agent_id, a.name, a.type
        FROM agent_distribution_routes dr
        JOIN telecom_agents a ON a.id = dr.agent_id
        WHERE dr.distribution_id = ?
        ORDER BY dr.method_id, dr.op_key, dr.priority ASC
    ");
    $rRows->execute([$did]);
    foreach ($rRows->fetchAll() as $r) {
        $routeMap[$r['method_id']][$r['op_key']][] = ['agent_id'=>(int)$r['agent_id'],'name'=>$r['name'],'type'=>$r['type'],'priority'=>(int)$r['priority']];
    }
}

include 'header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-random" style="color:#a78bfa"></i> التوزيع برو</h2>
  <?php if($action==='list'): ?>
  <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> توزيع جديد</a>
  <?php else: ?>
  <a href="agent_distributions.php" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> رجوع</a>
  <?php endif; ?>
</div>

<?php if($action === 'list'): ?>
<!-- ══ شرح النظام ══════════════════════════════════════════════ -->
<div class="card mb-2" style="background:rgba(167,139,250,.05);border-color:rgba(167,139,250,.2)">
  <div class="card-body" style="padding:.9rem 1.2rem">
    <div style="font-size:.82rem;color:#a78bfa;font-weight:700;margin-bottom:.5rem">
      <i class="fas fa-info-circle"></i> كيف يعمل التوزيع برو؟
    </div>
    <div style="font-size:.77rem;color:#8895a7;line-height:1.8">
      كل توزيع يربط <strong style="color:#cdd">مجموعة عملاء أو عميل بعينه</strong> بقائمة مزودين مرتبة لكل شبكة.<br>
      عند وصول طلب — يُبحث أولاً عن توزيع خاص بالعميل، ثم بمجموعته، ثم التوزيع الافتراضي.<br>
      يعمل بمبدأ <strong style="color:#cdd">Failover</strong> — يجرب المزود الأول، عند الفشل ينتقل للتالي تلقائياً.
    </div>
  </div>
</div>

<!-- ══ قائمة التوزيعات ════════════════════════════════════════ -->
<div class="card">
  <?php if(empty($distributions)): ?>
  <div style="padding:3rem;text-align:center;color:#8895a7">
    <i class="fas fa-random" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:10px"></i>
    لا توجد توزيعات — أنشئ توزيعاً للبدء
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>#</th><th>الاسم</th><th>يُطبَّق على</th><th>نوع الخدمات</th><th>المزودين</th><th>الحالة</th><th>إجراء</th></tr>
      </thead>
      <tbody>
      <?php foreach($distributions as $d): ?>
      <tr>
        <td><?=$d['id']?></td>
        <td><strong><?=htmlspecialchars($d['name'])?></strong></td>
        <td>
          <?php if($d['target_type']==='group'): ?>
          <span style="background:rgba(245,166,35,.12);color:#f5a623;padding:2px 9px;border-radius:20px;font-size:.75rem">
            <i class="fas fa-layer-group"></i> <?=htmlspecialchars($d['group_name']??'—')?>
            <?php if($d['group_pct']!==null): $gv=(float)$d['group_pct']; ?>
            <span style="opacity:.7"><?=$gv>=0?'+':''?><?=number_format($gv,1)?>%</span>
            <?php endif; ?>
          </span>
          <?php else: ?>
          <span style="background:rgba(107,163,255,.12);color:#6ba3ff;padding:2px 9px;border-radius:20px;font-size:.75rem">
            <i class="fas fa-user"></i>
            <?=htmlspecialchars($d['user_fullname']?:($d['user_username']??'—'))?>
          </span>
          <?php endif; ?>
        </td>
        <td style="font-size:.75rem;color:#8895a7">
          <?=['telecom'=>'📶 شحن','service'=>'🎮 خدمات','both'=>'📶🎮 كلاهما'][$d['service_type']]?>
        </td>
        <td>
          <span style="background:rgba(0,212,170,.1);color:#00d4aa;padding:2px 9px;border-radius:20px;font-size:.75rem">
            <i class="fas fa-link"></i> <?=$d['routes_count']?> ربط
          </span>
        </td>
        <td>
          <button onclick="location.href='?action=toggle&id=<?=$d['id']?>'"
                  style="background:none;border:none;cursor:pointer;font-size:.8rem;color:<?=$d['status']?'#00d4aa':'#ff4455'?>">
            <i class="fas fa-circle"></i> <?=$d['status']?'نشط':'معطّل'?>
          </button>
        </td>
        <td style="display:flex;gap:5px;flex-wrap:wrap">
          <a href="?action=routes&id=<?=$d['id']?>" class="btn btn-primary btn-sm" title="تحديد المزودين">
            <i class="fas fa-network-wired"></i> المزودون
          </a>
          <a href="?action=edit&id=<?=$d['id']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
          <?php if($IS_ADMIN): ?>
          <a href="?action=delete&id=<?=$d['id']?>" class="btn btn-danger btn-sm"
             onclick="return confirm('حذف هذا التوزيع وجميع روابطه؟')">
            <i class="fas fa-trash"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif(in_array($action,['add','edit'])): ?>
<!-- ══ نموذج إضافة/تعديل ══════════════════════════════════════ -->
<div class="card">
  <h3><?=$action==='edit'?'تعديل التوزيع':'إضافة توزيع جديد'?></h3>

  <?php
  $ec = $editDist ?? [];
  $ecGroupId = $ec['target_group_id'] ?? null;
  $ecUserId  = $ec['target_user_id']  ?? null;
  $ecType    = $ec['target_type']     ?? 'group';
  ?>

  <form method="POST">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_dist" value="1">
    <input type="hidden" name="dist_id" value="<?=$ec['id']??0?>">

    <!-- الاسم + نوع الخدمات + الترتيب -->
    <div style="display:grid;grid-template-columns:2fr 1fr 80px;gap:1rem;margin-bottom:1.5rem">
      <div class="form-group" style="margin:0">
        <label>الاسم أو العنوان *</label>
        <input type="text" name="dist_name" class="form-control" required
               value="<?=htmlspecialchars($ec['name']??'')?>"
               placeholder="مثال: موزعو الجنوب، عميل VIP محمد...">
      </div>
      <div class="form-group" style="margin:0">
        <label>نوع الخدمات</label>
        <select name="service_type" class="form-control">
          <option value="both"    <?=($ec['service_type']??'both')==='both'   ?'selected':''?>>📶🎮 كلاهما</option>
          <option value="telecom" <?=($ec['service_type']??'')==='telecom'    ?'selected':''?>>📶 شحن فقط</option>
          <option value="service" <?=($ec['service_type']??'')==='service'    ?'selected':''?>>🎮 خدمات فقط</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label>الترتيب</label>
        <input type="number" name="dist_sort" class="form-control" value="<?=$ec['sort_order']??0?>">
      </div>
    </div>

    <!-- اختيار الهدف: مجموعة أو عميل -->
    <div style="background:rgba(167,139,250,.05);border:1px solid rgba(167,139,250,.2);border-radius:14px;padding:1.25rem;margin-bottom:1.5rem">
      <div style="font-size:.82rem;color:#a78bfa;font-weight:700;margin-bottom:1rem">
        <i class="fas fa-crosshairs"></i> يُطبَّق على
        <span style="font-size:.72rem;color:#8895a7;font-weight:400;margin-right:8px">— اختر مجموعة أو عميل (ليس كلاهما)</span>
      </div>

      <!-- نوع الهدف -->
      <div style="display:flex;gap:12px;margin-bottom:1rem">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.83rem">
          <input type="radio" name="target_type" value="group" <?=$ecType==='group'?'checked':''?>
                 onchange="setTargetType('group')"> مجموعة
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.83rem">
          <input type="radio" name="target_type" value="user" <?=$ecType==='user'?'checked':''?>
                 onchange="setTargetType('user')"> عميل محدد
        </label>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <!-- اختيار المجموعة -->
        <div class="form-group target-group-field" style="margin:0;<?=$ecType==='user'?'opacity:.4;pointer-events:none':''?>">
          <label>حدد المجموعة</label>
          <select name="target_group_id" class="form-control" id="groupSelect">
            <option value="">— بدون مجموعة —</option>
            <?php foreach($pricingGroups as $pg):
              $pv=(float)$pg['default_value'];
              $badge=$pv>0?' (+'.number_format($pv,1).'%)':($pv<0?' ('.number_format($pv,1).'%)':'');
            ?>
            <option value="<?=$pg['id']?>" <?=$ecGroupId==$pg['id']?'selected':''?>>
              <?=htmlspecialchars($pg['name'])?><?=$badge?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if(empty($pricingGroups)): ?>
          <div style="font-size:.72rem;color:#8895a7;margin-top:4px">
            <a href="pricing_groups.php" style="color:#6ba3ff">أنشئ مجموعة</a> أولاً
          </div>
          <?php endif; ?>
        </div>

        <!-- اختيار العميل -->
        <div class="form-group target-user-field" style="margin:0;<?=$ecType==='group'?'opacity:.4;pointer-events:none':''?>">
          <label>أو اختر حساب عميل</label>
          <select name="target_user_id" class="form-control" id="userSelect">
            <option value="">— بدون عميل —</option>
            <?php foreach($allUsers as $u): ?>
            <option value="<?=$u['id']?>" <?=$ecUserId==$u['id']?'selected':''?>>
              <?=htmlspecialchars($u['username'])?><?=$u['full_name']?' — '.htmlspecialchars($u['full_name']):''?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- حالة -->
    <div class="form-group">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="dist_status" value="1" <?=(!$editDist||$editDist['status'])?'checked':''?>>
        التوزيع نشط
      </label>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ</button>
      <a href="agent_distributions.php" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
</div>

<script>
function setTargetType(type) {
  const gf = document.querySelector('.target-group-field');
  const uf = document.querySelector('.target-user-field');
  if (type === 'group') {
    gf.style.opacity='1'; gf.style.pointerEvents='';
    uf.style.opacity='.4'; uf.style.pointerEvents='none';
    document.getElementById('userSelect').value='';
  } else {
    uf.style.opacity='1'; uf.style.pointerEvents='';
    gf.style.opacity='.4'; gf.style.pointerEvents='none';
    document.getElementById('groupSelect').value='';
  }
}
</script>

<?php elseif($action === 'routes' && $editDist): ?>
<!-- ══ تحديد المزودين للتوزيع ══════════════════════════════════ -->
<?php
$targetLabel = $editDist['target_type']==='group'
    ? ($pdo->query("SELECT name FROM pricing_groups WHERE id=".(int)$editDist['target_group_id'])->fetchColumn() ?: '—')
    : ($pdo->query("SELECT username FROM users WHERE id=".(int)$editDist['target_user_id'])->fetchColumn() ?: '—');
?>

<!-- شريط المعلومات -->
<div class="card mb-2" style="background:rgba(167,139,250,.05);border-color:rgba(167,139,250,.2)">
  <div class="card-body" style="padding:.85rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($editDist['name'])?></div>
      <div style="font-size:.73rem;color:#8895a7;margin-top:3px;display:flex;align-items:center;gap:8px">
        <span style="background:rgba(<?=$editDist['target_type']==='group'?'245,166,35':'107,163,255'?>,.12);color:<?=$editDist['target_type']==='group'?'#f5a623':'#6ba3ff'?>;padding:1px 8px;border-radius:20px">
          <i class="fas fa-<?=$editDist['target_type']==='group'?'layer-group':'user'?>"></i>
          <?=htmlspecialchars($targetLabel)?>
        </span>
        <span><?=['telecom'=>'📶 شحن','service'=>'🎮 خدمات','both'=>'📶🎮 كلاهما'][$editDist['service_type']]?></span>
        <span style="color:#a78bfa;font-size:.68rem"><i class="fas fa-info-circle"></i> الأول أساسي — عند الفشل ينتقل للتالي (Failover)</span>
      </div>
    </div>
    <a href="?action=edit&id=<?=$editDist['id']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> تعديل</a>
  </div>
</div>

<style>
.dist-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:12px; }
.dist-card { border-radius:14px; border:1px solid var(--border); overflow:hidden; transition:box-shadow .2s; }
.dist-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.18); }
.dist-card-header { display:flex; align-items:center; gap:10px; padding:9px 12px; background:rgba(255,255,255,.04); border-bottom:1px solid var(--border); }
.dist-card-icon { width:32px; height:32px; border-radius:9px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:.82rem; flex-shrink:0; }
.dist-card-body { padding:8px 10px; display:flex; flex-direction:column; gap:7px; }
.op-row { display:flex; align-items:center; gap:6px; }
.op-lbl { font-size:.72rem; font-weight:700; white-space:nowrap; width:52px; flex-shrink:0; }
.pchain { display:flex; align-items:center; gap:3px; flex:1; overflow-x:auto; min-height:26px; padding:1px 0; }
.pchain::-webkit-scrollbar { height:2px; }
.pitem { display:flex; align-items:center; gap:2px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); border-radius:6px; padding:2px 5px 2px 3px; font-size:.68rem; cursor:grab; user-select:none; white-space:nowrap; flex-shrink:0; }
.pitem:active { opacity:.6; cursor:grabbing; }
.pnum { background:rgba(255,255,255,.15); border-radius:3px; padding:0 3px; font-size:.58rem; font-weight:900; color:#8895a7; min-width:12px; text-align:center; }
.pdel { color:#ff6677; cursor:pointer; font-size:.58rem; padding:0 1px; opacity:.7; }
.pdel:hover { opacity:1; }
.fo-arrow { color:#4a5568; font-size:.55rem; flex-shrink:0; pointer-events:none; }
.add-agent-btn { display:flex; align-items:center; gap:2px; font-size:.65rem; color:#8895a7; background:rgba(255,255,255,.03); border:1px dashed rgba(255,255,255,.15); border-radius:6px; padding:2px 7px; cursor:pointer; white-space:nowrap; flex-shrink:0; transition:border-color .15s,color .15s; }
.add-agent-btn:hover { border-color:#a78bfa; color:#a78bfa; }
.badge-linked   { font-size:.6rem; background:rgba(0,212,170,.12); color:#00d4aa; padding:1px 6px; border-radius:5px; margin-right:auto; flex-shrink:0; }
.badge-unlinked { font-size:.6rem; background:rgba(255,68,85,.08);  color:#ff4455; padding:1px 6px; border-radius:5px; margin-right:auto; flex-shrink:0; }
.grp-div { font-size:.78rem; font-weight:900; color:#8895a7; text-transform:uppercase; letter-spacing:.08em; padding:6px 2px 8px; border-bottom:1px solid var(--border); margin-bottom:10px; display:flex; align-items:center; gap:8px; }
.grp-div .cnt { background:rgba(255,255,255,.07); border-radius:20px; padding:1px 9px; font-size:.7rem; color:#aab; }
.agent-picker-wrap { position:relative; }
.agent-picker { position:absolute; z-index:9999; bottom:calc(100% + 4px); left:0; background:var(--card-bg,#1e2532); border:1px solid var(--border); border-radius:10px; padding:4px; min-width:160px; box-shadow:0 8px 24px rgba(0,0,0,.4); display:none; }
.agent-picker.open { display:block; }
.agent-picker-item { display:flex; align-items:center; gap:6px; padding:5px 10px; border-radius:7px; cursor:pointer; font-size:.74rem; white-space:nowrap; transition:background .12s; }
.agent-picker-item:hover { background:rgba(255,255,255,.08); }
.agent-picker-item.used { opacity:.35; pointer-events:none; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span><i class="fas fa-network-wired"></i> تحديد المزودين لكل شبكة/خدمة</span>
    <span style="font-size:.72rem;color:#8895a7">اسحب لإعادة الترتيب — الأول = الأساسي</span>
  </div>
  <div class="card-body">
    <?php if(empty($agents)): ?>
    <p style="color:#8895a7">أضف وكلاء أولاً من <a href="telecom_agents.php">صفحة وكلاء الشحن</a></p>
    <?php else: ?>

    <form method="POST" id="routesForm">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_routes" value="1">
      <input type="hidden" name="dist_id" value="<?=$editDist['id']?>">

      <?php
      $groupDefs = [];
      if(in_array($editDist['service_type'],['telecom','both'])) {
        $groupDefs['📶 شبكات الشحن']           = ['methods'=>$networkGroups['TOPUP'],  'color'=>'#6ba3ff'];
        $groupDefs['🧾 خدمات السداد والفواتير'] = ['methods'=>$networkGroups['BILLPAY'],'color'=>'#f5a623'];
      }
      if(in_array($editDist['service_type'],['service','both']) && !empty($servicesData)) {
        $groupDefs['🎮 الخدمات العادية'] = ['services'=>$servicesData,'color'=>'#a78bfa'];
      }

      $opMeta = ['amount'=>['💰','رصيد','#00d4aa'],'fees'=>['🏷️','فئات','#f5a623'],'bundles'=>['📦','باقات','#6ba3ff'],'order'=>['🛒','طلب','#a78bfa']];

      foreach($groupDefs as $grpLabel => $grpData):
        $items = $grpData['methods'] ?? $grpData['services'] ?? [];
        if(empty($items)) continue;
        $isService = isset($grpData['services']);
      ?>
      <div style="margin-bottom:2rem">
        <div class="grp-div">
          <?=$grpLabel?>
          <span class="cnt"><?=count($items)?></span>
        </div>
        <div class="dist-grid">
        <?php foreach($items as $itemId => $item):
          $ops = $isService ? ['order'=>'طلب'] : ($item['ops'] ?? ['amount'=>'رصيد']);
          $hasAny = false;
          foreach(array_keys($ops) as $opk) { if(!empty($routeMap[$itemId][$opk])) $hasAny=true; }
          $iconHtml = $isService
            ? '<i class="fas fa-gamepad"></i>'
            : '<i class="fas fa-'.htmlspecialchars($item['icon']??'sim-card').'"></i>';
          $cardColor = $isService ? '#a78bfa' : htmlspecialchars($item['color']??'#6c3fe0');
          $itemName  = $isService ? $item['name'] : $item['name'];
        ?>
        <div class="dist-card">
          <div class="dist-card-header">
            <div class="dist-card-icon" style="background:<?=$cardColor?>">
              <?=$iconHtml?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:900;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                   title="<?=htmlspecialchars($itemName)?>"><?=htmlspecialchars($itemName)?></div>
              <div style="font-size:.62rem;color:#8895a7">ID: <?=$itemId?></div>
            </div>
            <?=$hasAny?'<span class="badge-linked">✓ مربوط</span>':'<span class="badge-unlinked">غير مربوط</span>'?>
          </div>
          <div class="dist-card-body">
          <?php foreach($ops as $opKey=>$opLabel):
            [$opIc,$opNm,$opCl] = $opMeta[$opKey] ?? ['⚙️',$opLabel,'#8895a7'];
            $chain = $routeMap[$itemId][$opKey] ?? [];
            $chainJson = htmlspecialchars(json_encode($chain, JSON_UNESCAPED_UNICODE));
          ?>
          <div class="op-row">
            <div class="op-lbl" style="color:<?=$opCl?>"><?=$opIc?> <?=$opNm?></div>
            <div class="pchain"
                 data-mid="<?=$itemId?>"
                 data-op="<?=$opKey?>"
                 data-chain='<?=$chainJson?>'
                 ondragover="dragOver(event)"
                 ondrop="onDrop(event,this)">
            </div>
            <div class="agent-picker-wrap">
              <button type="button" class="add-agent-btn" onclick="togglePicker(this)">
                <i class="fas fa-plus" style="font-size:.55rem"></i> مزود
              </button>
              <div class="agent-picker">
                <?php foreach($agents as $ag): ?>
                <div class="agent-picker-item"
                     data-agent-id="<?=$ag['id']?>"
                     data-agent-name="<?=htmlspecialchars($ag['name'])?>"
                     data-agent-type="<?=$ag['type']?>"
                     onclick="addAgentToChain(this)">
                  <span><?=$ag['type']==='fore'?'🤖':'📱'?></span>
                  <span><?=htmlspecialchars($ag['name'])?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
        <button type="button" class="btn btn-primary" onclick="submitRoutes()">
          <i class="fas fa-save"></i> حفظ التوزيع
        </button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
const AGENTS = <?=json_encode(array_map(fn($a)=>['id'=>(int)$a['id'],'name'=>$a['name'],'type'=>$a['type']],$agents),JSON_UNESCAPED_UNICODE)?>;

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.pchain').forEach(chain => {
    const data = JSON.parse(chain.dataset.chain || '[]');
    data.forEach(item => appendItem(chain, item.agent_id, item.name, item.type));
  });
});

function appendItem(chainEl, agentId, name, type) {
  if (chainEl.querySelector(`.pitem[data-agent-id="${agentId}"]`)) return;
  if (chainEl.querySelector('.pitem')) {
    const arr = document.createElement('span');
    arr.className = 'fo-arrow';
    arr.innerHTML = '<i class="fas fa-chevron-left"></i>';
    chainEl.appendChild(arr);
  }
  const item = document.createElement('div');
  item.className = 'pitem';
  item.draggable = true;
  item.dataset.agentId = agentId;
  item.innerHTML = `<span class="pnum">?</span><span style="font-size:.65rem">${type==='fore'?'🤖':'📱'}</span><span style="max-width:80px;overflow:hidden;text-overflow:ellipsis">${esc(name)}</span><span class="pdel" onclick="removeItem(this)">✕</span>`;
  item.addEventListener('dragstart', e => { dragSrc = item; e.dataTransfer.effectAllowed='move'; });
  chainEl.appendChild(item);
  reNumber(chainEl);
}

function reNumber(chainEl) {
  chainEl.querySelectorAll('.fo-arrow').forEach(e=>e.remove());
  const items = [...chainEl.querySelectorAll('.pitem')];
  items.forEach((item,i) => {
    item.querySelector('.pnum').textContent = i+1;
    if (i>0) {
      const arr = document.createElement('span');
      arr.className='fo-arrow'; arr.innerHTML='<i class="fas fa-chevron-left"></i>';
      chainEl.insertBefore(arr, item);
    }
  });
  // تحديث شارة البطاقة
  const card = chainEl.closest('.dist-card');
  if (card) {
    const anyLinked = !!card.querySelector('.pitem');
    const badge = card.querySelector('.badge-linked,.badge-unlinked');
    if (badge) { badge.className = anyLinked?'badge-linked':'badge-unlinked'; badge.textContent = anyLinked?'✓ مربوط':'غير مربوط'; }
  }
}

function removeItem(del) {
  const item  = del.closest('.pitem');
  const chain = item.closest('.pchain');
  item.remove(); reNumber(chain);
}

let dragSrc = null;
function dragOver(e) { e.preventDefault(); }
function onDrop(e, chainEl) {
  e.preventDefault();
  if (!dragSrc || dragSrc.closest('.pchain') !== chainEl) return;
  const after = [...chainEl.querySelectorAll('.pitem')].filter(el=>el!==dragSrc)
    .find(el => { const b=el.getBoundingClientRect(); return e.clientX>b.left && e.clientX<b.left+b.width/2; });
  if (after) chainEl.insertBefore(dragSrc, after);
  else chainEl.appendChild(dragSrc);
  reNumber(chainEl); dragSrc=null;
}

function togglePicker(btn) {
  const picker = btn.nextElementSibling;
  const wasOpen = picker.classList.contains('open');
  document.querySelectorAll('.agent-picker.open').forEach(p=>p.classList.remove('open'));
  if (!wasOpen) {
    const chain = btn.closest('.op-row').querySelector('.pchain');
    const used  = new Set([...chain.querySelectorAll('.pitem')].map(el=>el.dataset.agentId));
    picker.querySelectorAll('.agent-picker-item').forEach(it=>it.classList.toggle('used',used.has(it.dataset.agentId)));
    picker.classList.add('open');
  }
}
function addAgentToChain(el) {
  const chain = el.closest('.op-row').querySelector('.pchain');
  appendItem(chain, el.dataset.agentId, el.dataset.agentName, el.dataset.agentType);
  el.closest('.agent-picker').classList.remove('open');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.agent-picker-wrap'))
    document.querySelectorAll('.agent-picker.open').forEach(p=>p.classList.remove('open'));
});

function submitRoutes() {
  const form = document.getElementById('routesForm');
  form.querySelectorAll('.dyn-inp').forEach(e=>e.remove());
  document.querySelectorAll('.pchain').forEach(chain => {
    const mid = chain.dataset.mid;
    const op  = chain.dataset.op;
    chain.querySelectorAll('.pitem').forEach(item => {
      const inp = document.createElement('input');
      inp.type='hidden'; inp.name=`routes[${mid}][${op}][]`; inp.value=item.dataset.agentId; inp.className='dyn-inp';
      form.appendChild(inp);
    });
  });
  form.submit();
}

function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
