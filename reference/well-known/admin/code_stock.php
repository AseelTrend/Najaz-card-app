<?php
require_once '../includes/config.php';
require_once '../includes/code_stock_helper.php';
requireStaffOrAdmin($pdo, 'code_stock_view');
$canEdit = isAdmin() || canAccess($pdo, 'code_stock_edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

codeStockEnsureTables($pdo);

$pageTitle = 'إدارة مخزون الأكواد — ' . SITE_NAME;
$adminId   = $_SESSION['user_id'] ?? null;

// ── قائمة الخدمات (لكل القوائم المنسدلة) ────────────────────────────────────
$allServices = $pdo->query("
    SELECT s.id, s.name, COALESCE(c.name,'بدون قسم') AS category_name, COALESCE(c.sort_order,9999) AS cat_sort
    FROM services s
    LEFT JOIN categories c ON s.category_id = c.id
    WHERE s.deleted_at IS NULL
    ORDER BY cat_sort ASC, c.name ASC, s.sort_order ASC, s.name ASC
")->fetchAll();

// تجميع الخدمات حسب القسم لعرضها بشكل منظّم
$servicesByCategory = [];
foreach ($allServices as $s) {
    $servicesByCategory[$s['category_name']][] = $s;
}

// ── إجراءات (POST) ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {

    // إضافة أكواد جديدة
    if (isset($_POST['add_codes'])) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $codesText = (string)($_POST['codes_text'] ?? '');
        if ($serviceId <= 0 || trim($codesText) === '') {
            flashMessage('danger', 'يرجى اختيار الخدمة وإدخال كود واحد على الأقل');
        } else {
            $res = codeStockAddCodes($pdo, $serviceId, $codesText, $adminId);
            $msg = "✅ تمت إضافة {$res['added']} كود";
            if ($res['duplicates'] > 0) $msg .= " — تم تجاهل {$res['duplicates']} كود مكرر";
            flashMessage('success', $msg);
        }
        redirect(SITE_URL . '/admin/code_stock.php?service_id=' . $serviceId);
    }

    // حفظ إعدادات خدمة (تفعيل + حد التنبيه)
    if (isset($_POST['save_settings'])) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $enabled   = isset($_POST['enabled']);
        $threshold = (int)($_POST['low_stock_threshold'] ?? 20);
        if ($serviceId > 0) {
            codeStockSetServiceSettings($pdo, $serviceId, $enabled, $threshold);
            flashMessage('success', '✅ تم حفظ إعدادات الخدمة');
        }
        redirect(SITE_URL . '/admin/code_stock.php?service_id=' . $serviceId);
    }

    // حذف كود واحد
    if (isset($_POST['delete_code'])) {
        $codeId = (int)($_POST['code_id'] ?? 0);
        $row = $pdo->prepare("SELECT service_id FROM code_stock WHERE id=?");
        $row->execute([$codeId]);
        $svc = $row->fetchColumn();
        $pdo->prepare("UPDATE code_stock SET status='deleted', deleted_at=NOW(), deleted_by=? WHERE id=? AND status='available'")
            ->execute([$adminId, $codeId]);
        codeStockLog($pdo, $svc ?: null, $codeId, 'delete', $adminId, 'حذف كود فردي');
        flashMessage('success', '✅ تم حذف الكود');
        redirect(SITE_URL . '/admin/code_stock.php?service_id=' . (int)$svc);
    }

    // حذف مجموعة أكواد (المحدَّدة من الجدول)
    if (isset($_POST['delete_bulk'])) {
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE code_stock SET status='deleted', deleted_at=NOW(), deleted_by=? WHERE id IN ($in) AND status='available'")
                ->execute(array_merge([$adminId], $ids));
            foreach ($ids as $cid) codeStockLog($pdo, $serviceId, $cid, 'delete', $adminId, 'حذف ضمن مجموعة');
            flashMessage('success', '✅ تم حذف ' . count($ids) . ' كود');
        }
        redirect(SITE_URL . '/admin/code_stock.php?service_id=' . $serviceId);
    }
}

// ── الخدمة المختارة حالياً ───────────────────────────────────────────────────
$selectedServiceId = (int)($_GET['service_id'] ?? 0);
if (!$selectedServiceId && $allServices) $selectedServiceId = (int)$allServices[0]['id'];

$selectedService = null;
foreach ($allServices as $s) { if ((int)$s['id'] === $selectedServiceId) { $selectedService = $s; break; } }

$settingsStmt = $pdo->prepare("SELECT * FROM code_stock_settings WHERE service_id=?");
$settingsStmt->execute([$selectedServiceId]);
$svcSettings = $settingsStmt->fetch() ?: ['enabled' => 0, 'low_stock_threshold' => 20];

$stats = $selectedServiceId ? codeStockGetServiceStats($pdo, $selectedServiceId) : ['total'=>0,'available_count'=>0,'used_count'=>0,'deleted_count'=>0];

// ── بحث/فلترة الأكواد للخدمة المختارة ────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 50; $offset = ($page - 1) * $limit;

$codes = []; $totalCodes = 0;
if ($selectedServiceId) {
    $where  = "WHERE c.service_id = ?";
    $params = [$selectedServiceId];
    if ($filterStatus) { $where .= " AND c.status=?"; $params[] = $filterStatus; }
    if ($search) {
        $where .= " AND (c.code LIKE ? OR c.order_id=? OR c.user_id=?)";
        $params[] = "%$search%";
        $params[] = is_numeric($search) ? (int)$search : 0;
        $params[] = is_numeric($search) ? (int)$search : 0;
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM code_stock c $where");
    $totalStmt->execute($params);
    $totalCodes = (int)$totalStmt->fetchColumn();

    $listStmt = $pdo->prepare("
        SELECT c.*, u.username AS customer_name
        FROM code_stock c
        LEFT JOIN users u ON c.user_id = u.id
        $where
        ORDER BY c.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $listStmt->execute($params);
    $codes = $listStmt->fetchAll();
}

$lowStockAlerts = codeStockLowStockAlerts($pdo);
$allStats       = codeStockGetAllServiceStats($pdo);

include 'header.php';
?>
<style>
.cs-code{font-family:'Courier New',monospace;font-size:.85rem;letter-spacing:1px;color:var(--cyan);background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:6px;padding:3px 9px;display:inline-block}
.cs-badge{font-size:11px;padding:2px 9px;border-radius:20px;font-weight:700}
.cs-badge.available{background:rgba(0,200,83,.15);color:#00c853}
.cs-badge.used{background:rgba(30,111,255,.15);color:#1e6fff}
.cs-badge.deleted{background:rgba(255,68,85,.15);color:#ff4455}
.cs-stat{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;flex:1;min-width:120px}
.cs-stat b{display:block;font-size:1.4rem}
.cs-svc-tab{padding:7px 14px;border-radius:10px;border:1px solid var(--border);font-size:.8rem;white-space:nowrap;display:inline-flex;gap:6px;align-items:center}
.cs-svc-tab.on{background:var(--primary);color:#fff;border-color:var(--primary)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-key"></i></div>
      إدارة مخزون الأكواد
    </div>
  </div>
</div>

<?php if ($lowStockAlerts): ?>
<div class="card" style="border-color:rgba(255,166,35,.4);margin-bottom:16px">
  <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
    <div style="font-weight:800;color:#f5a623"><i class="fas fa-exclamation-triangle"></i> تنبيه: مخزون بعض الخدمات أوشك على النفاد</div>
    <?php foreach ($lowStockAlerts as $a): ?>
    <div style="font-size:.85rem;color:var(--text2)">
      <a href="?service_id=<?= $a['service_id'] ?>" style="color:#f5a623;font-weight:700"><?= htmlspecialchars($a['service_name']) ?></a>
      — متبقي <strong><?= (int)$a['available_count'] ?></strong> فقط (الحد الأدنى: <?= (int)$a['low_stock_threshold'] ?>)
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- اختيار الخدمة (مجمّعة حسب القسم) -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body">
    <label style="font-size:.8rem;color:var(--text3);margin-bottom:6px;display:block"><i class="fas fa-folder-open"></i> اختر القسم ثم الخدمة</label>
    <select onchange="if(this.value) location.href='?service_id='+this.value" class="form-control" style="width:100%">
      <option value="">— اختر خدمة —</option>
      <?php foreach ($servicesByCategory as $catName => $svcList): ?>
      <optgroup label="<?= htmlspecialchars($catName) ?>">
        <?php foreach ($svcList as $s):
              $svcStat = null;
              foreach ($allStats as $st) { if ((int)$st['service_id'] === (int)$s['id']) { $svcStat = $st; break; } }
        ?>
        <option value="<?= $s['id'] ?>" <?= $selectedServiceId == $s['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($s['name']) ?><?= $svcStat ? ' — متاح: '.(int)$svcStat['available_count'].($svcStat['enabled']?' ✅':'') : '' ?>
        </option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>

    <?php if ($selectedService): ?>
    <div style="margin-top:10px;font-size:.8rem;color:var(--text3)">
      <i class="fas fa-folder"></i> القسم: <strong style="color:var(--text2)"><?= htmlspecialchars($selectedService['category_name']) ?></strong>
    </div>
    <?php endif; ?>

    <!-- روابط سريعة للخدمات المفعّلة كخدمات أكواد -->
    <?php $enabledOnes = array_filter($allStats, fn($st) => !empty($st['enabled'])); ?>
    <?php if ($enabledOnes): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px">
      <?php foreach ($enabledOnes as $st): ?>
      <a href="?service_id=<?= $st['service_id'] ?>" class="cs-svc-tab <?= $selectedServiceId == $st['service_id'] ? 'on' : '' ?>">
        <i class="fas fa-circle" style="font-size:6px;color:#00c853"></i>
        <?= htmlspecialchars($st['service_name']) ?>
        <span style="opacity:.7">(<?= (int)$st['available_count'] ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedService): ?>

<!-- إحصائيات الخدمة -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div class="cs-stat"><span style="color:var(--text3);font-size:.75rem">إجمالي الأكواد</span><b><?= number_format($stats['total']) ?></b></div>
  <div class="cs-stat"><span style="color:var(--text3);font-size:.75rem">متاح</span><b style="color:#00c853"><?= number_format($stats['available_count']) ?></b></div>
  <div class="cs-stat"><span style="color:var(--text3);font-size:.75rem">مستخدم</span><b style="color:#1e6fff"><?= number_format($stats['used_count']) ?></b></div>
  <div class="cs-stat"><span style="color:var(--text3);font-size:.75rem">محذوف</span><b style="color:#ff4455"><?= number_format($stats['deleted_count']) ?></b></div>
  <div class="cs-stat">
    <span style="color:var(--text3);font-size:.75rem">نسبة نفاد المخزون</span>
    <?php $pct = $stats['total'] > 0 ? round(($stats['total']-$stats['available_count']) / $stats['total'] * 100) : 0; ?>
    <b><?= $pct ?>%</b>
  </div>
</div>

<div style="display:grid;grid-template-columns:320px 1fr;gap:16px;align-items:start">

  <!-- العمود الجانبي: إضافة أكواد + إعدادات -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-plus"></i> إضافة أكواد جديدة</div></div>
      <div class="card-body">
        <form method="POST">
          <?= adminCsrfField() ?>
          <input type="hidden" name="add_codes" value="1">
          <input type="hidden" name="service_id" value="<?= $selectedServiceId ?>">
          <div class="form-group">
            <label>الأكواد (كل سطر = كود واحد)</label>
            <textarea name="codes_text" class="form-control" rows="8" placeholder="ABCD-1234-EFGH&#10;ZXCV-5678-QWER" required></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%"><i class="fas fa-upload"></i> إضافة إلى المخزون</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-cog"></i> إعدادات الخدمة</div></div>
      <div class="card-body">
        <form method="POST">
          <?= adminCsrfField() ?>
          <input type="hidden" name="save_settings" value="1">
          <input type="hidden" name="service_id" value="<?= $selectedServiceId ?>">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px">
            <input type="checkbox" name="enabled" <?= !empty($svcSettings['enabled']) ? 'checked' : '' ?>>
            <span>تفعيل التسليم التلقائي من المخزون لهذه الخدمة</span>
          </label>
          <div class="form-group">
            <label>حد التنبيه الأدنى (عدد الأكواد المتاحة)</label>
            <input type="number" name="low_stock_threshold" class="form-control" min="0" value="<?= (int)($svcSettings['low_stock_threshold'] ?? 20) ?>">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="width:100%"><i class="fas fa-save"></i> حفظ الإعدادات</button>
        </form>
        <div class="form-hint" style="margin-top:10px">
          عند التفعيل: أي طلب على هذه الخدمة يُسلَّم له تلقائياً أول كود متاح (FIFO)، وتتحول حالة الطلب إلى "مكتمل" فوراً.
        </div>
      </div>
    </div>
    <?php else: ?>
    <div class="card"><div class="card-body" style="color:var(--text3)">لا تملك صلاحية تعديل المخزون — العرض فقط.</div></div>
    <?php endif; ?>
  </div>

  <!-- قائمة الأكواد -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div class="card-header-title">
        <i class="fas fa-list"></i> الأكواد (<?= number_format($totalCodes) ?>)
        <?php if ($selectedService): ?>
        <div style="font-size:.72rem;font-weight:400;color:var(--text3);margin-top:3px">
          الخدمة: <strong style="color:var(--cyan)"><?= htmlspecialchars($selectedService['name']) ?></strong>
          — القسم: <strong style="color:var(--text2)"><?= htmlspecialchars($selectedService['category_name']) ?></strong>
        </div>
        <?php endif; ?>
      </div>
      <form method="GET" style="display:flex;gap:6px;flex-wrap:wrap">
        <input type="hidden" name="service_id" value="<?= $selectedServiceId ?>">
        <input type="text" name="q" class="form-control" style="max-width:200px" placeholder="بحث بالكود / رقم الطلب / العميل" value="<?= htmlspecialchars($search) ?>">
        <select name="status" class="form-control" style="max-width:140px">
          <option value="">كل الحالات</option>
          <option value="available" <?= $filterStatus==='available'?'selected':'' ?>>متاح</option>
          <option value="used" <?= $filterStatus==='used'?'selected':'' ?>>مستخدم</option>
          <option value="deleted" <?= $filterStatus==='deleted'?'selected':'' ?>>محذوف</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-search"></i></button>
      </form>
    </div>

    <?php if ($canEdit): ?>
    <form method="POST" id="bulkForm" onsubmit="return confirm('تأكيد حذف الأكواد المحددة؟')">
      <?= adminCsrfField() ?>
      <input type="hidden" name="delete_bulk" value="1">
      <input type="hidden" name="service_id" value="<?= $selectedServiceId ?>">
      <input type="hidden" name="ids" id="bulkIds">
      <div style="padding:8px 16px">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return collectIds()"><i class="fas fa-trash"></i> حذف المحدد</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <?php if ($canEdit): ?><th style="width:30px"><input type="checkbox" onclick="toggleAll(this)"></th><?php endif; ?>
            <th>#</th><th>الكود</th><th>الخدمة</th><th>الحالة</th><th>تاريخ الإضافة</th><th>تاريخ الاستخدام</th><th>الطلب</th><th>العميل</th>
            <?php if ($canEdit): ?><th>إجراءات</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php if ($codes): foreach ($codes as $c): ?>
        <tr>
          <?php if ($canEdit): ?><td><?php if ($c['status']==='available'): ?><input type="checkbox" class="rowchk" value="<?= $c['id'] ?>"><?php endif; ?></td><?php endif; ?>
          <td><?= $c['id'] ?></td>
          <td><span class="cs-code"><?= htmlspecialchars($c['code']) ?></span></td>
          <td style="font-size:11px">
            <div style="color:var(--text2)"><?= htmlspecialchars($selectedService['name'] ?? '—') ?></div>
            <div style="color:var(--text3)"><?= htmlspecialchars($selectedService['category_name'] ?? '—') ?></div>
          </td>
          <td><span class="cs-badge <?= $c['status'] ?>"><?= ['available'=>'متاح','used'=>'مستخدم','deleted'=>'محذوف'][$c['status']] ?? $c['status'] ?></span></td>
          <td style="font-size:11px;color:var(--text3)"><?= date('Y-m-d H:i', strtotime($c['added_at'])) ?></td>
          <td style="font-size:11px;color:var(--text3)"><?= $c['used_at'] ? date('Y-m-d H:i', strtotime($c['used_at'])) : '—' ?></td>
          <td><?= $c['order_id'] ? '<a href="orders.php?order='.$c['order_id'].'">#'.$c['order_id'].'</a>' : '—' ?></td>
          <td><?= $c['customer_name'] ? htmlspecialchars($c['customer_name']) : ($c['user_id'] ? '#'.$c['user_id'] : '—') ?></td>
          <?php if ($canEdit): ?>
          <td>
            <?php if ($c['status']==='available'): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا الكود؟')">
              <?= adminCsrfField() ?>
              <input type="hidden" name="delete_code" value="1">
              <input type="hidden" name="code_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger" style="padding:2px 8px"><i class="fas fa-trash"></i></button>
            </form>
            <?php else: ?>—<?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text3);padding:20px">لا توجد أكواد مطابقة</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalCodes > $limit): $pages = ceil($totalCodes / $limit); ?>
    <div style="display:flex;gap:6px;padding:12px;flex-wrap:wrap">
      <?php for ($p=1; $p<=$pages; $p++): ?>
      <a href="?service_id=<?= $selectedServiceId ?>&status=<?= $filterStatus ?>&q=<?= urlencode($search) ?>&page=<?= $p ?>"
         class="btn btn-sm <?= $p==$page?'btn-primary':'btn-secondary' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="card"><div class="card-body" style="text-align:center;color:var(--text3)">لا توجد خدمات بعد. أضف خدمة أولاً من صفحة "الخدمات".</div></div>
<?php endif; ?>

<script>
function toggleAll(cb) { document.querySelectorAll('.rowchk').forEach(el => el.checked = cb.checked); }
function collectIds() {
    const ids = Array.from(document.querySelectorAll('.rowchk:checked')).map(el => el.value);
    if (!ids.length) { alert('يرجى تحديد كود واحد على الأقل'); return false; }
    document.getElementById('bulkIds').value = ids.join(',');
    return true;
}
</script>

<?php include 'footer.php'; ?>
