<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');
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
<!-- ══ قواعد الخدمات المخصصة ══════════════════════════════════ -->
<div class="card mb-2" style="background:rgba(107,163,255,.05);border-color:rgba(107,163,255,.2)">
  <div class="card-body" style="padding:.9rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
      <div style="font-weight:700;font-size:.9rem"><?=htmlspecialchars($editGroup['name'])?></div>
      <div style="font-size:.75rem;color:#8895a7;margin-top:2px">
        النسبة الافتراضية:
        <?php $dv=(float)$editGroup['default_value']; ?>
        <strong style="color:<?=$dv>=0?'#ff6b6b':'#00d4aa'?>"><?=$dv>=0?'+':''?><?=number_format($dv,2)?>%</strong>
        &nbsp;—&nbsp; أي خدمة بدون قاعدة مخصصة ستُحسب بهذه النسبة
      </div>
    </div>
    <a href="?action=edit&id=<?=$editGroup['id']?>" class="btn btn-warning btn-sm">
      <i class="fas fa-edit"></i> تعديل النسبة الافتراضية
    </a>
  </div>
</div>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <span><i class="fas fa-sliders-h"></i> قواعد مخصصة لكل خدمة (اختياري)</span>
    <span style="font-size:.72rem;color:#8895a7">اتركها فارغة لتطبيق النسبة الافتراضية</span>
  </div>
  <div class="card-body">

    <!-- فلتر بحث -->
    <div style="margin-bottom:1rem">
      <input type="text" id="svcSearch" class="form-control" placeholder="🔍 بحث عن خدمة..."
             oninput="filterServices(this.value)" style="max-width:350px">
    </div>

    <form method="POST" id="rulesForm">
      <input type="hidden" name="save_rules" value="1">
      <input type="hidden" name="group_id" value="<?=$editGroup['id']?>">

      <?php
      $lastCat = null;
      foreach ($servicesWithRules as $svc):
        if ($svc['cat_name'] !== $lastCat):
          $lastCat = $svc['cat_name'];
      ?>
      <div class="svc-cat-header" style="font-size:.75rem;font-weight:900;color:#8895a7;text-transform:uppercase;letter-spacing:.06em;padding:8px 2px 5px;border-bottom:1px solid var(--border);margin:10px 0 6px">
        <?=htmlspecialchars($svc['cat_name'] ?? 'بدون قسم')?>
      </div>
      <?php endif; ?>

      <div class="svc-rule-row" data-name="<?=htmlspecialchars(mb_strtolower($svc['name']))?>"
           style="display:flex;align-items:center;gap:10px;padding:7px 6px;border-radius:9px;margin-bottom:4px;border:1px solid transparent;transition:background .1s"
           onmouseover="this.style.background='rgba(255,255,255,.03)'" onmouseout="this.style.background=''">

        <!-- اسم الخدمة -->
        <div style="flex:1;min-width:0">
          <div style="font-size:.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?=htmlspecialchars($svc['name'])?>
          </div>
          <div style="font-size:.68rem;color:#8895a7">
            السعر الأصلي: <?=number_format((float)$svc['price'],4)?>
          </div>
        </div>

        <!-- نوع القاعدة -->
        <select name="rules[<?=$svc['id']?>][type]" class="form-control rule-type-sel"
                data-sid="<?=$svc['id']?>"
                onchange="onRuleTypeChange(this)"
                style="width:120px;font-size:.75rem;padding:3px 6px;height:30px">
          <option value="none"    <?=!$svc['rule']?'selected':''?>>— افتراضي —</option>
          <option value="percent" <?=($svc['rule']&&$svc['rule']['rule_type']==='percent')?'selected':''?>>نسبة %</option>
          <option value="fixed"   <?=($svc['rule']&&$svc['rule']['rule_type']==='fixed')?'selected':''?>>سعر ثابت</option>
        </select>

        <!-- القيمة -->
        <div style="position:relative">
          <input type="number" name="rules[<?=$svc['id']?>][value]"
                 class="form-control rule-value-inp"
                 step="0.0000000001"
                 style="width:130px;font-size:.75rem;padding:3px 24px 3px 8px;height:30px"
                 placeholder="<?=($svc['rule']&&$svc['rule']['rule_type']==='percent')?'مثال: -10':'مثال: 0.50'?>"
                 value="<?=$svc['rule']?number_format((float)$svc['rule']['rule_value'],4):''?>"
                 <?=!$svc['rule']?'disabled':''?>>
          <span class="rule-unit" style="position:absolute;left:6px;top:50%;transform:translateY(-50%);font-size:.68rem;color:#8895a7;pointer-events:none">
            <?=($svc['rule']&&$svc['rule']['rule_type']==='percent')?'%':'ر'?>
          </span>
        </div>

        <!-- معاينة السعر -->
        <div class="rule-preview" style="font-size:.72rem;min-width:90px;text-align:center;color:#8895a7">
          <?php
          if ($svc['rule']) {
              $orig = (float)$svc['price'];
              if ($svc['rule']['rule_type']==='fixed') {
                  $fp = (float)$svc['rule']['rule_value'];
                  echo '<span style="color:#6ba3ff">'.number_format($fp,4).'</span>';
              } else {
                  $pct = (float)$svc['rule']['rule_value'];
                  $fp  = $orig * (1 + $pct/100);
                  $cl  = $pct < 0 ? '#00d4aa' : '#ff6b6b';
                  echo '<span style="color:'.$cl.'">'.number_format($fp,4).'</span>';
              }
          } else {
              echo '<span>—</span>';
          }
          ?>
        </div>
      </div>
      <?php endforeach; ?>

      <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border)">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ القواعد</button>
      </div>
    </form>
  </div>
</div>

<script>
// فلتر البحث
function filterServices(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.svc-rule-row').forEach(row => {
    row.style.display = (!q || row.dataset.name.includes(q)) ? '' : 'none';
  });
  document.querySelectorAll('.svc-cat-header').forEach(h => {
    let next = h.nextElementSibling;
    let visible = false;
    while (next && !next.classList.contains('svc-cat-header')) {
      if (next.style.display !== 'none') visible = true;
      next = next.nextElementSibling;
    }
    h.style.display = visible ? '' : 'none';
  });
}

// تفعيل/تعطيل حقل القيمة
function onRuleTypeChange(sel) {
  const row = sel.closest('.svc-rule-row');
  const inp = row.querySelector('.rule-value-inp');
  const unit = row.querySelector('.rule-unit');
  const preview = row.querySelector('.rule-preview');

  if (sel.value === 'none') {
    inp.disabled = true;
    inp.value    = '';
    unit.textContent = '';
    preview.innerHTML = '<span>—</span>';
    inp.name = inp.name.replace('[value]','') + '[value]'; // keep name
  } else {
    inp.disabled = false;
    unit.textContent = sel.value === 'percent' ? '%' : 'ر';
    inp.placeholder  = sel.value === 'percent' ? 'مثال: -10' : 'مثال: 0.50';
    inp.addEventListener('input', () => updatePreview(row));
  }
}

function updatePreview(row) {
  const sel  = row.querySelector('.rule-type-sel');
  const inp  = row.querySelector('.rule-value-inp');
  const prev = row.querySelector('.rule-preview');
  const orig = parseFloat(row.querySelector('[style*="السعر الأصلي"]')?.textContent?.match(/[\d.]+/)?.[0]) || 0;
  const val  = parseFloat(inp.value);
  if (isNaN(val)) { prev.innerHTML = '<span>—</span>'; return; }

  if (sel.value === 'fixed') {
    prev.innerHTML = `<span style="color:#6ba3ff">${val.toFixed(4)}</span>`;
  } else {
    const fp = orig * (1 + val/100);
    const cl = val < 0 ? '#00d4aa' : '#ff6b6b';
    prev.innerHTML = `<span style="color:${cl}">${fp.toFixed(4)}</span>`;
  }
}

// تفعيل المعاينة على جميع الصفوف الموجودة
document.querySelectorAll('.svc-rule-row').forEach(row => {
  const inp = row.querySelector('.rule-value-inp');
  if (inp && !inp.disabled) {
    inp.addEventListener('input', () => updatePreview(row));
  }
});

// استبعاد القيم الفارغة (none) من الإرسال
document.getElementById('rulesForm').addEventListener('submit', function() {
  document.querySelectorAll('.rule-type-sel').forEach(sel => {
    if (sel.value === 'none') {
      const row = sel.closest('.svc-rule-row');
      row.querySelector('.rule-value-inp').disabled = true;
      sel.name = ''; // لا يُرسل
    }
  });
});
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
