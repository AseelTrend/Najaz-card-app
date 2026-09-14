<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'مجموعات التسعير — ' . SITE_NAME;

// ══════════════════════════════════════════════════════════════════
// إنشاء الجداول
// ══════════════════════════════════════════════════════════════════
$pdo->exec("CREATE TABLE IF NOT EXISTS `pricing_groups` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL,
  `description`   VARCHAR(255) DEFAULT NULL,
  `default_type`  ENUM('percent') NOT NULL DEFAULT 'percent'
                  COMMENT 'نوع التسعير الافتراضي للمجموعة',
  `default_value` DECIMAL(8,4) NOT NULL DEFAULT 0
                  COMMENT 'موجب = زيادة % | سالب = خصم %',
  `status`        TINYINT(1) DEFAULT 1,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `pricing_group_rules` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `group_id`   INT NOT NULL,
  `service_id` INT NOT NULL,
  `rule_type`  ENUM('fixed','percent') NOT NULL DEFAULT 'fixed'
               COMMENT 'fixed=سعر ثابت | percent=نسبة مخصصة',
  `rule_value` DECIMAL(20,10) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_group_service` (`group_id`,`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// إضافة عمود group_id لجدول users إن لم يكن موجوداً
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
$gid    = (int)($_GET['id'] ?? 0);

// حفظ مجموعة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group'])) {
    $id    = (int)($_POST['group_id'] ?? 0);
    $name  = trim($_POST['group_name'] ?? '');
    $desc  = trim($_POST['group_desc'] ?? '');
    $val   = (float)($_POST['default_value'] ?? 0);
    $stat  = isset($_POST['group_status']) ? 1 : 0;

    if (empty($name)) {
        flashMessage('danger', 'اسم المجموعة مطلوب');
        redirect(SITE_URL . '/admin/pricing_groups.php?action=' . ($id ? 'edit&id='.$id : 'add'));
    }

    if ($id) {
        $pdo->prepare("UPDATE pricing_groups SET name=?,description=?,default_value=?,status=? WHERE id=?")
            ->execute([$name, $desc, $val, $stat, $id]);
        flashMessage('success', "✅ تم تحديث المجموعة: $name");
    } else {
        $pdo->prepare("INSERT INTO pricing_groups (name,description,default_value,status) VALUES (?,?,?,?)")
            ->execute([$name, $desc, $val, $stat]);
        flashMessage('success', "✅ تمت إضافة المجموعة: $name");
    }
    redirect(SITE_URL . '/admin/pricing_groups.php');
}

// حذف مجموعة
if ($action === 'delete' && $gid && $IS_ADMIN) {
    // أزل العملاء من المجموعة
    $pdo->prepare("UPDATE users SET group_id=NULL WHERE group_id=?")->execute([$gid]);
    $pdo->prepare("DELETE FROM pricing_group_rules WHERE group_id=?")->execute([$gid]);
    $pdo->prepare("DELETE FROM pricing_groups WHERE id=?")->execute([$gid]);
    flashMessage('success', 'تم حذف المجموعة');
    redirect(SITE_URL . '/admin/pricing_groups.php');
}

// حفظ قواعد الخدمات المخصصة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rules'])) {
    $gid   = (int)($_POST['group_id'] ?? 0);
    $rules = $_POST['rules'] ?? [];

    // احذف القواعد الحالية
    $pdo->prepare("DELETE FROM pricing_group_rules WHERE group_id=?")->execute([$gid]);

    foreach ($rules as $serviceId => $rule) {
        $serviceId = (int)$serviceId;
        $rType     = $rule['type'] ?? 'fixed';
        $rVal      = $rule['value'] ?? '';
        if ($rVal === '' || $rVal === null) continue;
        if (!in_array($rType, ['fixed','percent'])) $rType = 'fixed';
        $rVal = (float)$rVal;

        $pdo->prepare("INSERT INTO pricing_group_rules (group_id,service_id,rule_type,rule_value) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE rule_type=?,rule_value=?")
            ->execute([$gid, $serviceId, $rType, $rVal, $rType, $rVal]);
    }
    flashMessage('success', '✅ تم حفظ قواعد التسعير');
    redirect(SITE_URL . '/admin/pricing_groups.php?action=rules&id=' . $gid);
}

// ══════════════════════════════════════════════════════════════════
// جلب البيانات
// ══════════════════════════════════════════════════════════════════
$groups = $pdo->query("
    SELECT pg.*, COUNT(u.id) as member_count
    FROM pricing_groups pg
    LEFT JOIN users u ON u.group_id = pg.id AND u.is_deleted=0
    GROUP BY pg.id
    ORDER BY pg.id
")->fetchAll();

$editGroup = null;
if (in_array($action, ['edit','rules']) && $gid) {
    $eg = $pdo->prepare("SELECT * FROM pricing_groups WHERE id=?");
    $eg->execute([$gid]);
    $editGroup = $eg->fetch();
}

// للصفحة rules: جلب كل الخدمات مع قواعدها
$servicesWithRules = [];
if ($action === 'rules' && $editGroup) {
    $allServices = $pdo->query("
        SELECT s.id, s.name, s.price, c.name as cat_name
        FROM services s
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE s.status=1 AND s.deleted_at IS NULL
        ORDER BY c.name, s.name
    ")->fetchAll();

    $existingRules = [];
    $rs = $pdo->prepare("SELECT * FROM pricing_group_rules WHERE group_id=?");
    $rs->execute([$gid]);
    foreach ($rs->fetchAll() as $r) {
        $existingRules[$r['service_id']] = $r;
    }

    foreach ($allServices as $svc) {
        $svc['rule'] = $existingRules[$svc['id']] ?? null;
        $servicesWithRules[] = $svc;
    }
}

include 'header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-tags" style="color:#f5a623"></i> مجموعات التسعير</h2>
  <?php if($action==='list'): ?>
  <a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> مجموعة جديدة</a>
  <?php endif; ?>
  <?php if(in_array($action,['add','edit','rules'])): ?>
  <a href="pricing_groups.php" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> رجوع</a>
  <?php endif; ?>
</div>

<?php if($action === 'list'): ?>
<!-- ══ قائمة المجموعات ══════════════════════════════════════ -->

<!-- بطاقة الشرح -->
<div class="card mb-2" style="background:rgba(245,166,35,.05);border-color:rgba(245,166,35,.2)">
  <div class="card-body" style="padding:1rem 1.25rem">
    <div style="font-size:.82rem;color:#f5a623;font-weight:700;margin-bottom:.5rem"><i class="fas fa-info-circle"></i> كيف يعمل نظام مجموعات التسعير؟</div>
    <div style="font-size:.78rem;color:#8895a7;line-height:1.7">
      كل مجموعة لها <strong style="color:#cdd">نسبة % افتراضية</strong> تُطبق على جميع الخدمات (موجب = زيادة، سالب = خصم).<br>
      يمكنك <strong style="color:#cdd">تخصيص سعر ثابت أو نسبة مختلفة</strong> لأي خدمة بعينها من صفحة "قواعد الخدمات".<br>
      بعد إنشاء المجموعة، <strong style="color:#cdd">عيّن العملاء لها</strong> من صفحة إدارة العملاء.
    </div>
  </div>
</div>

<div class="card">
  <?php if(empty($groups)): ?>
  <div style="padding:3rem;text-align:center;color:#8895a7">
    <i class="fas fa-tags" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:10px"></i>
    لا توجد مجموعات — أنشئ مجموعة للبدء
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>اسم المجموعة</th>
          <th>النسبة الافتراضية</th>
          <th>الأعضاء</th>
          <th>الحالة</th>
          <th>إجراء</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($groups as $g): ?>
      <tr>
        <td><?=$g['id']?></td>
        <td>
          <strong><?=htmlspecialchars($g['name'])?></strong>
          <?php if($g['description']): ?>
          <small style="color:#8895a7;display:block;font-size:.72rem"><?=htmlspecialchars($g['description'])?></small>
          <?php endif; ?>
        </td>
        <td>
          <?php
          $v = (float)$g['default_value'];
          if ($v > 0): ?>
          <span style="color:#ff6b6b;font-weight:700">+<?=number_format($v,2)?>% <small style="font-weight:400;opacity:.7">(زيادة)</small></span>
          <?php elseif ($v < 0): ?>
          <span style="color:#00d4aa;font-weight:700"><?=number_format($v,2)?>% <small style="font-weight:400;opacity:.7">(خصم)</small></span>
          <?php else: ?>
          <span style="color:#8895a7">0% (بدون تغيير)</span>
          <?php endif; ?>
        </td>
        <td>
          <span style="background:rgba(107,163,255,.12);color:#6ba3ff;padding:2px 10px;border-radius:20px;font-size:.78rem">
            <i class="fas fa-users"></i> <?=$g['member_count']?>
          </span>
        </td>
        <td>
          <?=$g['status']
            ? '<span style="color:#00d4aa;font-size:.8rem"><i class="fas fa-circle"></i> نشطة</span>'
            : '<span style="color:#ff4455;font-size:.8rem"><i class="fas fa-circle"></i> معطّلة</span>'?>
        </td>
        <td style="display:flex;gap:5px;flex-wrap:wrap">
          <a href="?action=edit&id=<?=$g['id']?>" class="btn btn-warning btn-sm" title="تعديل"><i class="fas fa-edit"></i></a>
          <a href="?action=rules&id=<?=$g['id']?>" class="btn btn-info btn-sm" title="قواعد الخدمات">
            <i class="fas fa-sliders-h"></i> قواعد
          </a>
          <?php if($IS_ADMIN): ?>
          <a href="?action=delete&id=<?=$g['id']?>" class="btn btn-danger btn-sm"
             onclick="return confirm('حذف هذه المجموعة؟ سيتم إزالة جميع العملاء منها.')"
             title="حذف"><i class="fas fa-trash"></i></a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php elseif(in_array($action, ['add','edit'])): ?>
<!-- ══ نموذج إضافة/تعديل مجموعة ══════════════════════════════ -->
<div class="card">
  <h3><?=$action==='edit'?'تعديل المجموعة':'إنشاء مجموعة جديدة'?></h3>
  <form method="POST">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_group" value="1">
    <input type="hidden" name="group_id" value="<?=$editGroup?$editGroup['id']:0?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
      <div class="form-group">
        <label>اسم المجموعة *</label>
        <input type="text" name="group_name" class="form-control" required
               value="<?=htmlspecialchars($editGroup?$editGroup['name']:'')?>"
               placeholder="مثال: عملاء الجملة، VIP، موزعون">
      </div>
      <div class="form-group">
        <label>وصف (اختياري)</label>
        <input type="text" name="group_desc" class="form-control"
               value="<?=htmlspecialchars($editGroup?$editGroup['description']??'':'')?>"
               placeholder="وصف مختصر للمجموعة">
      </div>
    </div>

    <!-- النسبة الافتراضية -->
    <div style="background:rgba(245,166,35,.06);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:1.25rem;margin-bottom:1.5rem">
      <div style="font-size:.83rem;color:#f5a623;font-weight:700;margin-bottom:1rem">
        <i class="fas fa-percent"></i> النسبة الافتراضية على جميع الخدمات
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:end">
        <div class="form-group" style="margin:0">
          <label>النسبة %</label>
          <div style="position:relative">
            <input type="number" name="default_value" class="form-control" step="0.01"
                   value="<?=$editGroup?number_format((float)$editGroup['default_value'],2):0?>"
                   placeholder="0.00" id="pctInput"
                   oninput="updatePctPreview(this.value)">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#8895a7;pointer-events:none">%</span>
          </div>
          <div style="font-size:.72rem;color:#8895a7;margin-top:4px">
            موجب (+) = زيادة السعر &nbsp;|&nbsp; سالب (-) = خصم
          </div>
        </div>
        <div>
          <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:12px;font-size:.82rem" id="pctPreview">
            <!-- تُحدَّث بـ JS -->
          </div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="group_status" value="1"
               <?=(!$editGroup||$editGroup['status'])?'checked':''?>>
        <span>المجموعة نشطة</span>
      </label>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ</button>
      <a href="pricing_groups.php" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
</div>

<script>
function updatePctPreview(val) {
  const v = parseFloat(val) || 0;
  const base = 1000;
  const final = base * (1 + v/100);
  const diff  = final - base;
  let html = `<div style="color:#8895a7;margin-bottom:4px">مثال — خدمة سعرها <strong>1,000</strong></div>`;
  if (v > 0) {
    html += `<div style="color:#ff6b6b">سعر العميل: <strong>${final.toLocaleString('ar')}</strong> (+${diff.toLocaleString('ar')})</div>`;
  } else if (v < 0) {
    html += `<div style="color:#00d4aa">سعر العميل: <strong>${final.toLocaleString('ar')}</strong> (${diff.toLocaleString('ar')})</div>`;
  } else {
    html += `<div style="color:#8895a7">سعر العميل: <strong>1,000</strong> (بدون تغيير)</div>`;
  }
  document.getElementById('pctPreview').innerHTML = html;
}
updatePctPreview(document.getElementById('pctInput')?.value || 0);
</script>

<?php elseif($action === 'rules' && $editGroup): ?>
<?php
// إحصاء الخدمات المخصصة
$customCount = count(array_filter($servicesWithRules, fn($s) => $s['rule'] !== null));
$totalCount  = count($servicesWithRules);
$dv = (float)$editGroup['default_value'];
?>

<!-- ══ شريط المعلومات العلوي ══════════════════════════════════ -->
<div class="card mb-2" style="background:rgba(107,163,255,.05);border-color:rgba(107,163,255,.2)">
  <div class="card-body" style="padding:.85rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($editGroup['name'])?></div>
        <div style="font-size:.73rem;color:#8895a7;margin-top:2px">
          النسبة الافتراضية على جميع الخدمات:
          <strong style="color:<?=$dv>0?'#ff6b6b':($dv<0?'#00d4aa':'#8895a7')?>;font-size:.82rem">
            <?=$dv>=0?'+':''?><?=number_format($dv,2)?>%
          </strong>
        </div>
      </div>
      <!-- إحصاء -->
      <div style="display:flex;gap:8px">
        <span style="background:rgba(107,163,255,.12);color:#6ba3ff;padding:3px 10px;border-radius:20px;font-size:.72rem">
          <i class="fas fa-layer-group"></i> <?=$totalCount?> خدمة
        </span>
        <span style="background:rgba(245,166,35,.12);color:#f5a623;padding:3px 10px;border-radius:20px;font-size:.72rem" id="customCountBadge">
          <i class="fas fa-star"></i> <?=$customCount?> مخصصة
        </span>
      </div>
    </div>
    <a href="?action=edit&id=<?=$editGroup['id']?>" class="btn btn-warning btn-sm">
      <i class="fas fa-edit"></i> تعديل النسبة الافتراضية
    </a>
  </div>
</div>

<!-- ══ الجدول الرئيسي ══════════════════════════════════════════ -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span><i class="fas fa-sliders-h"></i> تسعير الخدمات</span>
    <!-- أدوات التحكم السريع -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-size:.72rem;color:#8895a7">تطبيق سريع على الكل:</span>
      <div style="display:flex;align-items:center;gap:4px">
        <input type="number" id="bulkPct" step="0.01" placeholder="%" 
               style="width:70px;font-size:.75rem;padding:3px 6px;height:28px;background:var(--card2);border:1px solid var(--border);border-radius:7px;color:inherit;text-align:center">
        <button type="button" onclick="applyBulkPct()" class="btn btn-sm"
                style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);height:28px;padding:0 10px;font-size:.72rem">
          <i class="fas fa-bolt"></i> طبّق
        </button>
        <button type="button" onclick="clearAll()" class="btn btn-sm"
                style="background:rgba(255,68,85,.1);color:#ff6677;border:1px solid rgba(255,68,85,.2);height:28px;padding:0 10px;font-size:.72rem">
          <i class="fas fa-times"></i> مسح الكل
        </button>
      </div>
    </div>
  </div>

  <div class="card-body" style="padding-top:.75rem">

    <!-- شريط الفلتر والبحث -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:1rem;flex-wrap:wrap">
      <input type="text" id="svcSearch" class="form-control" placeholder="🔍 بحث عن خدمة..."
             oninput="filterServices()" style="flex:1;min-width:200px;max-width:320px">
      <div style="display:flex;gap:4px">
        <button type="button" onclick="setFilter('all')" class="filter-btn active" data-f="all"
                style="font-size:.72rem;padding:4px 12px;border-radius:20px;border:1px solid var(--border);background:rgba(255,255,255,.07);color:#cdd;cursor:pointer">
          الكل
        </button>
        <button type="button" onclick="setFilter('custom')" class="filter-btn" data-f="custom"
                style="font-size:.72rem;padding:4px 12px;border-radius:20px;border:1px solid rgba(245,166,35,.3);background:rgba(245,166,35,.08);color:#f5a623;cursor:pointer">
          المخصصة فقط
        </button>
        <button type="button" onclick="setFilter('default')" class="filter-btn" data-f="default"
                style="font-size:.72rem;padding:4px 12px;border-radius:20px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.03);color:#8895a7;cursor:pointer">
          الافتراضية فقط
        </button>
      </div>
    </div>

    <form method="POST" id="rulesForm">
        <?= adminCsrfField() ?>
      <input type="hidden" name="save_rules" value="1">
      <input type="hidden" name="group_id" value="<?=$editGroup['id']?>">

      <!-- رأس الجدول -->
      <div style="display:grid;grid-template-columns:1fr 100px 120px 110px 90px;gap:6px;padding:4px 8px;margin-bottom:4px">
        <div style="font-size:.68rem;color:#8895a7;font-weight:700">الخدمة</div>
        <div style="font-size:.68rem;color:#8895a7;font-weight:700;text-align:center">السعر الأصلي</div>
        <div style="font-size:.68rem;color:#f5a623;font-weight:700;text-align:center">نسبة % مخصصة</div>
        <div style="font-size:.68rem;color:#6ba3ff;font-weight:700;text-align:center">سعر ثابت</div>
        <div style="font-size:.68rem;color:#8895a7;font-weight:700;text-align:center">السعر النهائي</div>
      </div>

      <?php
      $lastCat = null;
      foreach ($servicesWithRules as $svc):
        $orig      = (float)$svc['price'];
        $hasRule   = $svc['rule'] !== null;
        $isPct     = $hasRule && $svc['rule']['rule_type'] === 'percent';
        $isFixed   = $hasRule && $svc['rule']['rule_type'] === 'fixed';
        $ruleVal   = $hasRule ? (float)$svc['rule']['rule_value'] : null;

        // السعر النهائي للعرض
        if ($isFixed)       $finalPrice = $ruleVal;
        elseif ($isPct)     $finalPrice = $orig * (1 + $ruleVal/100);
        else                $finalPrice = $orig * (1 + $dv/100); // الافتراضي

        if ($svc['cat_name'] !== $lastCat):
          $lastCat = $svc['cat_name'];
      ?>
      <div class="svc-cat-header"
           style="font-size:.7rem;font-weight:900;color:#8895a7;text-transform:uppercase;letter-spacing:.06em;
                  padding:8px 8px 5px;border-bottom:1px solid var(--border);margin:12px 0 5px;
                  display:flex;align-items:center;gap:6px">
        <i class="fas fa-folder" style="opacity:.4"></i>
        <?=htmlspecialchars($svc['cat_name'] ?? 'بدون قسم')?>
      </div>
      <?php endif; ?>

      <div class="svc-rule-row"
           data-name="<?=htmlspecialchars(mb_strtolower($svc['name']))?>"
           data-custom="<?=$hasRule?'1':'0'?>"
           data-orig="<?=$orig?>"
           style="display:grid;grid-template-columns:1fr 100px 120px 110px 90px;
                  gap:6px;align-items:center;padding:5px 8px;border-radius:9px;
                  margin-bottom:3px;border:1px solid <?=$hasRule?'rgba(245,166,35,.2)':'transparent'?>;
                  background:<?=$hasRule?'rgba(245,166,35,.04)':'transparent'?>;
                  transition:all .15s">

        <!-- اسم الخدمة -->
        <div style="min-width:0">
          <div style="font-size:.78rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
               title="<?=htmlspecialchars($svc['name'])?>">
            <?=htmlspecialchars($svc['name'])?>
          </div>
        </div>

        <!-- السعر الأصلي -->
        <div style="text-align:center;font-size:.72rem;color:#8895a7">
          <?=number_format($orig, $orig < 0.01 ? 6 : 4)?>
        </div>

        <!-- حقل النسبة % -->
        <div style="position:relative">
          <input type="number"
                 name="rules[<?=$svc['id']?>][percent]"
                 class="rule-pct-inp form-control"
                 step="0.01" min="-99" max="999"
                 style="width:100%;font-size:.75rem;padding:3px 22px 3px 6px;height:28px;text-align:center"
                 placeholder="<?=number_format($dv,1)?>"
                 value="<?=$isPct?number_format($ruleVal,2):'?'?>"
                 oninput="onPctInput(this)">
          <span style="position:absolute;left:6px;top:50%;transform:translateY(-50%);font-size:.65rem;color:#f5a623;pointer-events:none">%</span>
          <?php if($isPct): ?>
          <div class="pct-indicator" style="position:absolute;right:-6px;top:50%;transform:translateY(-50%);
               width:8px;height:8px;border-radius:50%;background:#f5a623"></div>
          <?php endif; ?>
        </div>

        <!-- حقل السعر الثابت -->
        <div style="position:relative">
          <input type="number"
                 name="rules[<?=$svc['id']?>][fixed]"
                 class="rule-fixed-inp form-control"
                 step="0.0000000001" min="0"
                 style="width:100%;font-size:.75rem;padding:3px 22px 3px 6px;height:28px;text-align:center"
                 placeholder="—"
                 value="<?=$isFixed?number_format($ruleVal,4):''?>"
                 oninput="onFixedInput(this)">
          <span style="position:absolute;left:6px;top:50%;transform:translateY(-50%);font-size:.65rem;color:#6ba3ff;pointer-events:none">ر</span>
          <?php if($isFixed): ?>
          <div class="fixed-indicator" style="position:absolute;right:-6px;top:50%;transform:translateY(-50%);
               width:8px;height:8px;border-radius:50%;background:#6ba3ff"></div>
          <?php endif; ?>
        </div>

        <!-- السعر النهائي -->
        <div class="final-price" style="text-align:center;font-size:.72rem;font-weight:700;
             color:<?=$isFixed?'#6ba3ff':($isPct&&$ruleVal<0?'#00d4aa':($isPct&&$ruleVal>0?'#ff6b6b':'#8895a7'))?>">
          <?=number_format($finalPrice, $finalPrice < 0.01 ? 6 : 4)?>
          <?php if($hasRule): ?>
          <button type="button" onclick="clearRow(this)" title="مسح التخصيص"
                  style="background:none;border:none;color:#ff6677;cursor:pointer;font-size:.6rem;padding:0 2px;opacity:.6">
            ✕
          </button>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>

      <!-- رسالة "لا نتائج" -->
      <div id="noResults" style="display:none;padding:2rem;text-align:center;color:#8895a7;font-size:.82rem">
        <i class="fas fa-search" style="font-size:1.5rem;opacity:.3;display:block;margin-bottom:8px"></i>
        لا توجد خدمات مطابقة
      </div>

      <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ التسعير</button>
        <span style="font-size:.72rem;color:#8895a7" id="pendingNote" style="display:none"></span>
      </div>
    </form>
  </div>
</div>

<style>
.svc-rule-row:hover { background: rgba(255,255,255,.03) !important; border-color: rgba(255,255,255,.08) !important; }
.svc-rule-row[data-custom="1"] { background: rgba(245,166,35,.04) !important; }
.rule-pct-inp:focus, .rule-fixed-inp:focus { border-color: rgba(107,163,255,.5) !important; outline: none; }
.filter-btn.active { background: rgba(107,163,255,.15) !important; color: #6ba3ff !important; border-color: rgba(107,163,255,.4) !important; }
/* إخفاء placeholder القيمة الافتراضية عند التركيز */
.rule-pct-inp:focus::placeholder { opacity: .3; }
</style>

<script>
const DEFAULT_PCT = <?=$dv?>;

// ── إدخال نسبة % ──────────────────────────────────────────────
function onPctInput(inp) {
  const row = inp.closest('.svc-rule-row');
  // عند إدخال نسبة → امسح السعر الثابت
  const fixedInp = row.querySelector('.rule-fixed-inp');
  if (inp.value !== '') {
    fixedInp.value = '';
    markRow(row, 'pct', inp.value);
  } else {
    markRow(row, 'none', null);
  }
  updateFinalPrice(row);
  updateCustomCount();
}

// ── إدخال سعر ثابت ────────────────────────────────────────────
function onFixedInput(inp) {
  const row = inp.closest('.svc-rule-row');
  // عند إدخال ثابت → امسح النسبة
  const pctInp = row.querySelector('.rule-pct-inp');
  if (inp.value !== '') {
    pctInp.value = '';
    markRow(row, 'fixed', inp.value);
  } else {
    markRow(row, 'none', null);
  }
  updateFinalPrice(row);
  updateCustomCount();
}

// ── تلوين الصف حسب الحالة ─────────────────────────────────────
function markRow(row, type, val) {
  if (type === 'none') {
    row.style.background = '';
    row.style.borderColor = 'transparent';
    row.dataset.custom = '0';
  } else {
    row.style.background = 'rgba(245,166,35,.04)';
    row.style.borderColor = 'rgba(245,166,35,.2)';
    row.dataset.custom = '1';
  }
}

// ── تحديث السعر النهائي ───────────────────────────────────────
function updateFinalPrice(row) {
  const orig     = parseFloat(row.dataset.orig) || 0;
  const pctVal   = parseFloat(row.querySelector('.rule-pct-inp').value);
  const fixedVal = parseFloat(row.querySelector('.rule-fixed-inp').value);
  const finalEl  = row.querySelector('.final-price');

  let finalPrice, color;

  if (!isNaN(fixedVal) && row.querySelector('.rule-fixed-inp').value !== '') {
    finalPrice = fixedVal;
    color = '#6ba3ff';
  } else if (!isNaN(pctVal) && row.querySelector('.rule-pct-inp').value !== '') {
    finalPrice = orig * (1 + pctVal / 100);
    color = pctVal < 0 ? '#00d4aa' : (pctVal > 0 ? '#ff6b6b' : '#8895a7');
  } else {
    finalPrice = orig * (1 + DEFAULT_PCT / 100);
    color = '#8895a7';
  }

  const decimals = finalPrice < 0.01 ? 6 : 4;
  finalEl.style.color = color;
  finalEl.innerHTML = finalPrice.toFixed(decimals)
    + (row.dataset.custom === '1'
      ? ` <button type="button" onclick="clearRow(this)" title="مسح التخصيص" style="background:none;border:none;color:#ff6677;cursor:pointer;font-size:.6rem;padding:0 2px;opacity:.6">✕</button>`
      : '');
}

// ── مسح صف ────────────────────────────────────────────────────
function clearRow(btn) {
  const row = btn.closest('.svc-rule-row');
  row.querySelector('.rule-pct-inp').value   = '';
  row.querySelector('.rule-fixed-inp').value = '';
  markRow(row, 'none', null);
  updateFinalPrice(row);
  updateCustomCount();
}

// ── تطبيق نسبة على الكل ───────────────────────────────────────
function applyBulkPct() {
  const pct = document.getElementById('bulkPct').value;
  if (pct === '') return;
  document.querySelectorAll('.svc-rule-row:not([style*="display: none"])').forEach(row => {
    row.querySelector('.rule-pct-inp').value   = pct;
    row.querySelector('.rule-fixed-inp').value = '';
    markRow(row, 'pct', pct);
    updateFinalPrice(row);
  });
  updateCustomCount();
  document.getElementById('bulkPct').value = '';
}

// ── مسح الكل ──────────────────────────────────────────────────
function clearAll() {
  if (!confirm('مسح جميع التخصيصات المرئية؟')) return;
  document.querySelectorAll('.svc-rule-row:not([style*="display: none"])').forEach(row => {
    row.querySelector('.rule-pct-inp').value   = '';
    row.querySelector('.rule-fixed-inp').value = '';
    markRow(row, 'none', null);
    updateFinalPrice(row);
  });
  updateCustomCount();
}

// ── عداد المخصصة ──────────────────────────────────────────────
function updateCustomCount() {
  const cnt = document.querySelectorAll('.svc-rule-row[data-custom="1"]').length;
  document.getElementById('customCountBadge').innerHTML =
    `<i class="fas fa-star"></i> ${cnt} مخصصة`;
  const note = document.getElementById('pendingNote');
  note.textContent = cnt > 0 ? `${cnt} خدمة لها تسعير مخصص — لا تنسَ الحفظ` : '';
  note.style.display = cnt > 0 ? '' : 'none';
}

// ── فلتر الحالة ───────────────────────────────────────────────
let currentFilter = 'all';
function setFilter(f) {
  currentFilter = f;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.toggle('active', b.dataset.f === f));
  filterServices();
}

// ── فلتر البحث ────────────────────────────────────────────────
function filterServices() {
  const q = document.getElementById('svcSearch').value.toLowerCase().trim();
  let visibleCount = 0;

  document.querySelectorAll('.svc-rule-row').forEach(row => {
    const nameMatch   = !q || row.dataset.name.includes(q);
    const filterMatch = currentFilter === 'all'
      || (currentFilter === 'custom'  && row.dataset.custom === '1')
      || (currentFilter === 'default' && row.dataset.custom === '0');
    const show = nameMatch && filterMatch;
    row.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });

  // إخفاء عناوين الأقسام الفارغة
  document.querySelectorAll('.svc-cat-header').forEach(h => {
    let next = h.nextElementSibling;
    let visible = false;
    while (next && !next.classList.contains('svc-cat-header')) {
      if (next.style.display !== 'none' && next.classList.contains('svc-rule-row')) visible = true;
      next = next.nextElementSibling;
    }
    h.style.display = visible ? '' : 'none';
  });

  document.getElementById('noResults').style.display = visibleCount === 0 ? '' : 'none';
}

// ── قبل الإرسال: بناء الـ hidden inputs الصحيحة ──────────────
document.getElementById('rulesForm').addEventListener('submit', function(e) {
  // احذف أي hidden قديمة
  this.querySelectorAll('.dyn-rule').forEach(el => el.remove());

  document.querySelectorAll('.svc-rule-row').forEach(row => {
    const sid      = row.querySelector('.rule-pct-inp').name.match(/\[(\d+)\]/)?.[1];
    const pctVal   = row.querySelector('.rule-pct-inp').value.trim();
    const fixedVal = row.querySelector('.rule-fixed-inp').value.trim();

    if (fixedVal !== '') {
      // سعر ثابت له أولوية
      addHidden(this, `rules[${sid}][type]`,  'fixed',  'dyn-rule');
      addHidden(this, `rules[${sid}][value]`, fixedVal, 'dyn-rule');
    } else if (pctVal !== '') {
      addHidden(this, `rules[${sid}][type]`,  'percent', 'dyn-rule');
      addHidden(this, `rules[${sid}][value]`, pctVal,    'dyn-rule');
    }
    // إذا كلاهما فارغ → لا نرسل شيئاً = يُحذف من القواعد
  });

  // عطّل جميع inputs المرئية لمنع إرسالها مزدوجاً
  this.querySelectorAll('.rule-pct-inp, .rule-fixed-inp').forEach(el => el.disabled = true);
});

function addHidden(form, name, value, cls) {
  const inp = document.createElement('input');
  inp.type = 'hidden'; inp.name = name; inp.value = value; inp.className = cls;
  form.appendChild(inp);
}
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
