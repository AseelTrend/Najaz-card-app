<?php
require_once '../includes/config.php';
requireAdmin();
$pageTitle = 'المزامنة التلقائية للأسعار — ' . SITE_NAME;

// ── تأكد من وجود الأعمدة والجدول (آمن التكرار) ──────────────────────────────
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_enabled TINYINT(1) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_interval_hours INT NOT NULL DEFAULT 6");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_last_run DATETIME NULL");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_last_result VARCHAR(255) NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    service_name VARCHAR(190) DEFAULT NULL,
    provider_id INT DEFAULT NULL,
    provider_service_id VARCHAR(60) DEFAULT NULL,
    old_price DECIMAL(18,8) DEFAULT NULL,
    provider_price DECIMAL(18,8) DEFAULT NULL,
    markup_percent DECIMAL(8,2) DEFAULT NULL,
    new_price DECIMAL(18,8) DEFAULT NULL,
    old_min INT DEFAULT NULL, new_min INT DEFAULT NULL,
    old_max INT DEFAULT NULL, new_max INT DEFAULT NULL,
    reason VARCHAR(20) NOT NULL DEFAULT 'auto',
    status VARCHAR(20) NOT NULL, message VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach ([
    "service_name VARCHAR(190) DEFAULT NULL",
    "provider_service_id VARCHAR(60) DEFAULT NULL",
    "provider_price DECIMAL(18,8) DEFAULT NULL",
    "markup_percent DECIMAL(8,2) DEFAULT NULL",
    "reason VARCHAR(20) NOT NULL DEFAULT 'auto'",
] as $col) {
    try { $pdo->exec("ALTER TABLE sync_log ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
}

// ── حفظ المفتاح العام (تشغيل/إيقاف كامل) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_global'])) {
    $cur = getSetting('price_sync_global_enabled');
    $new = ($cur === '0') ? '1' : '0';
    $exists = $pdo->prepare("SELECT id FROM settings WHERE setting_key='price_sync_global_enabled'");
    $exists->execute(); 
    if ($exists->fetch()) {
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='price_sync_global_enabled'")->execute([$new]);
    } else {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('price_sync_global_enabled',?)")->execute([$new]);
    }
    flashMessage('success', $new==='1' ? '✅ تم تشغيل المزامنة التلقائية عمومياً' : '⏸️ تم إيقاف المزامنة التلقائية عمومياً');
    redirect(SITE_URL.'/admin/price_sync.php');
}

// ── حفظ إعدادات خدمة واحدة ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_svc_sync'])) {
    $id       = (int)$_POST['svc_id'];
    $enabled  = !empty($_POST['sync_enabled']) ? 1 : 0;
    $markup   = (float)($_POST['sync_markup_percent'] ?? 0);
    $interval = max(1, (int)($_POST['sync_interval_hours'] ?? 6));
    $pdo->prepare("UPDATE services SET sync_enabled=?, sync_markup_percent=?, sync_interval_hours=? WHERE id=?")
        ->execute([$enabled, $markup, $interval, $id]);
    flashMessage('success', '✅ تم حفظ إعدادات المزامنة لهذه الخدمة');
    redirect(SITE_URL.'/admin/price_sync.php');
}

// ── مزامنة يدوية فورية (لكل الخدمات المفعّلة، بغض النظر عن الوقت المستحق) ────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['run_now'])) {
    $pdo->exec("UPDATE services SET sync_last_run=NULL WHERE sync_enabled=1"); // إجبار كل الخدمات المفعّلة على الاعتبار "مستحقة" الآن
    $GLOBALS['__SYNC_MANUAL__'] = true;
    ob_start();
    include __DIR__ . '/cron_sync_prices.php';
    ob_get_clean();
    flashMessage('success', '✅ تم تشغيل المزامنة يدوياً الآن لكل الخدمات المفعّلة. راجع السجل بالأسفل لتفاصيل النتيجة.');
    redirect(SITE_URL.'/admin/price_sync.php');
}

// ── مزامنة يدوية للخدمات المحدّدة فقط (تجاهل حالة التفعيل والوقت المستحق مؤقتاً فقط) ────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['run_selected']) && !empty($_POST['selected_ids'])) {
    $ids = array_filter(array_map('intval', $_POST['selected_ids']));
    if ($ids) {
        $GLOBALS['__SYNC_FORCE_IDS__'] = $ids;
        $GLOBALS['__SYNC_MANUAL__'] = true;
        ob_start();
        include __DIR__ . '/cron_sync_prices.php';
        ob_get_clean();
        flashMessage('success', '✅ تم تشغيل مزامنة فورية لـ ' . count($ids) . ' خدمة محدّدة (بغض النظر عن تفعيلها أو موعدها). راجع السجل بالأسفل.');
    }
    redirect(SITE_URL.'/admin/price_sync.php' . (isset($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
}

$globalOn = getSetting('price_sync_global_enabled') !== '0';

// ── فلاتر البحث ────────────────────────────────────────────────────────────
$fCat   = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
$fQ     = trim($_GET['q'] ?? '');
$fOnly  = $_GET['only'] ?? ''; // '' = الكل / 'enabled' = المفعّلة فقط / 'disabled' = غير المفعّلة
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = 'WHERE 1=1'; $params = [];
if ($fCat)  { $where .= ' AND s.category_id=?'; $params[] = $fCat; }
if ($fQ !== '') { $where .= ' AND s.name LIKE ?'; $params[] = '%'.$fQ.'%'; }
if ($fOnly === 'enabled')  $where .= ' AND s.sync_enabled=1';
if ($fOnly === 'disabled') $where .= ' AND s.sync_enabled=0';

$totalCount = $pdo->prepare("SELECT COUNT(*) FROM services s $where");
$totalCount->execute($params);
$totalCount = (int)$totalCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

$stmt = $pdo->prepare("
    SELECT s.*, c.name as cat_name,
        (SELECT p.name FROM service_providers sp JOIN providers p ON sp.provider_id=p.id WHERE sp.service_id=s.id AND sp.is_active=1 ORDER BY sp.priority ASC LIMIT 1) as active_provider_name
    FROM services s LEFT JOIN categories c ON s.category_id=c.id
    $where
    ORDER BY s.sync_enabled DESC, s.name ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$services = $stmt->fetchAll();

// نفس الفلاتر الحالية تُحفظ بالـ Query String لاستخدامها بروابط الترقيم وزر "رجوع بعد المزامنة"
$qsBase = http_build_query(array_filter(['cat_id'=>$fCat?:null,'q'=>$fQ?:null,'only'=>$fOnly?:null]));

$log = $pdo->query("
    SELECT sl.*, p.name as prov_name
    FROM sync_log sl
    LEFT JOIN providers p ON sl.provider_id = p.id
    ORDER BY sl.id DESC LIMIT 80
")->fetchAll();

$cronKey  = getSetting('cron_key') ?: 'cron_secret_key_change_me';
$cronUrl  = SITE_URL . '/admin/cron_sync_prices.php?key=' . $cronKey;

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-sync-alt"></i></div>
      المزامنة التلقائية للأسعار والكميات
    </div>
    <div class="page-header-sub">تحديث تلقائي دوري لسعر وكمية كل خدمة من مزودها الحالي المعتمد</div>
  </div>
</div>

<?php $flash=getFlash(); if($flash): ?>
<div class="alert alert-<?=$flash['type']?>" style="margin-bottom:16px"><?=htmlspecialchars($flash['message'])?></div>
<?php endif; ?>

<!-- ══ التحكم العام ══ -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
    <div>
      <div class="td-bold" style="font-size:15px">
        الحالة العامة للنظام:
        <span class="badge <?=$globalOn?'badge-success':'badge-danger'?>"><?=$globalOn?'🟢 يعمل':'🔴 موقوف'?></span>
      </div>
      <div class="td-muted" style="font-size:12px;margin-top:4px">إيقاف هذا المفتاح يوقف كل عمليات المزامنة فوراً، حتى لو كانت مفعّلة لخدمات معينة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="POST"><input type="hidden" name="toggle_global" value="1">
        <button class="btn <?=$globalOn?'btn-danger':'btn-success'?>"><i class="fas fa-power-off"></i> <?=$globalOn?'إيقاف المزامنة عمومياً':'تشغيل المزامنة عمومياً'?></button>
      </form>
      <form method="POST" onsubmit="return confirm('تشغيل مزامنة فورية الآن لكل الخدمات المفعّلة؟ قد تأخذ وقتاً حسب عددها.')">
        <input type="hidden" name="run_now" value="1">
        <button class="btn btn-primary"><i class="fas fa-bolt"></i> مزامنة الآن يدوياً (كل الخدمات المفعّلة)</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ إعداد الكرون جوب (مرجع فقط) ══ -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-clock"></i> جدولة التشغيل التلقائي (Cron Job)</div></div>
  <div class="card-body">
    <div class="td-muted" style="font-size:12.5px;margin-bottom:10px">
      عشان تشتغل المزامنة تلقائياً بدون تدخل يدوي، أضف هذا كـ Cron Job من لوحة الاستضافة (cPanel → Cron Jobs)، يعمل كل 30 دقيقة (الفحص الفعلي لكل خدمة يعتمد على "كل كم ساعة" المحدد لها بالأسفل):
    </div>
    <div style="background:var(--bg2);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;overflow-x:auto;direction:ltr;text-align:left">
      */30 * * * * php <?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '/home/USER/public_html') ?>/admin/cron_sync_prices.php
    </div>
    <div class="td-muted" style="font-size:12px;margin-top:10px">أو بديل عبر رابط (لو الاستضافة ما تدعم تشغيل PHP CLI مباشرة):</div>
    <div style="background:var(--bg2);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:11px;overflow-x:auto;direction:ltr;text-align:left"><?= htmlspecialchars($cronUrl) ?></div>
  </div>
</div>

<!-- ══ فلترة وبحث ══ -->
<div class="card" style="margin-bottom:14px">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="margin:0;min-width:180px">
        <label style="font-size:12px">القسم</label>
        <select name="cat_id" class="form-control" style="padding:8px">
          <option value="0">— كل الأقسام —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $fCat==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:180px">
        <label style="font-size:12px">حالة المزامنة</label>
        <select name="only" class="form-control" style="padding:8px">
          <option value="" <?= $fOnly===''?'selected':'' ?>>الكل</option>
          <option value="enabled" <?= $fOnly==='enabled'?'selected':'' ?>>المفعّلة فقط</option>
          <option value="disabled" <?= $fOnly==='disabled'?'selected':'' ?>>غير المفعّلة فقط</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:200px">
        <label style="font-size:12px">بحث باسم الخدمة</label>
        <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" class="form-control" style="padding:8px" placeholder="اكتب اسم الخدمة...">
      </div>
      <button class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
      <?php if ($fCat || $fQ || $fOnly): ?>
      <a href="<?=SITE_URL?>/admin/price_sync.php" class="btn btn-secondary"><i class="fas fa-times"></i> مسح الفلاتر</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ══ إعدادات المزامنة لكل خدمة ══ -->
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div class="card-header-title"><i class="fas fa-box"></i> الخدمات (<?= $totalCount ?> نتيجة<?= $totalPages>1 ? ' · صفحة '.$page.' من '.$totalPages : '' ?>)</div>
    <button type="submit" form="bulkSyncForm" class="btn btn-primary btn-sm"><i class="fas fa-bolt"></i> مزامنة المحدد الآن</button>
  </div>
  <form method="POST" id="bulkSyncForm">
    <input type="hidden" name="run_selected" value="1">
    <input type="hidden" name="back_qs" value="<?= htmlspecialchars($qsBase . ($qsBase?'&':'') . 'page='.$page) ?>">
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.svc-chk').forEach(c=>c.checked=this.checked)"></th>
        <th>الخدمة</th><th>القسم</th><th>المزود المعتمد حالياً</th><th>مفعّلة؟</th>
        <th>نسبة الزيادة %</th><th>كل كم ساعة</th><th>آخر مزامنة</th><th>آخر نتيجة</th><th></th>
      </tr></thead>
      <tbody>
        <?php if (!$services): ?>
        <tr><td colspan="9"><div class="empty-state"><span class="empty-state-icon">📭</span><div class="empty-state-title">لا توجد خدمات مطابقة</div></div></td></tr>
        <?php endif; ?>
        <?php foreach ($services as $svc): $formId = 'svcForm'.$svc['id']; ?>
        <tr style="<?= $svc['sync_enabled'] ? '' : 'opacity:.6' ?>">
          <td><input type="checkbox" name="selected_ids[]" value="<?= $svc['id'] ?>" class="svc-chk" form="bulkSyncForm"></td>
          <td class="td-bold"><?= htmlspecialchars($svc['name']) ?><div class="td-muted" style="font-size:10px">#<?= $svc['id'] ?></div></td>
          <td class="td-muted" style="font-size:12px"><?= htmlspecialchars($svc['cat_name'] ?? '—') ?></td>
          <td>
            <?php if ($svc['active_provider_name']): ?>
              <span style="color:#00d4aa"><i class="fas fa-plug" style="font-size:10px"></i> <?= htmlspecialchars($svc['active_provider_name']) ?></span>
            <?php else: ?>
              <span class="td-muted" style="font-size:11px">لا يوجد (يدوية)</span>
            <?php endif; ?>
          </td>
          <td><input type="checkbox" form="<?= $formId ?>" name="sync_enabled" value="1" <?= $svc['sync_enabled'] ? 'checked' : '' ?>></td>
          <td><input type="number" step="0.01" form="<?= $formId ?>" name="sync_markup_percent" value="<?= htmlspecialchars($svc['sync_markup_percent']) ?>" class="form-control" style="width:80px;padding:4px 6px;font-size:12px"></td>
          <td>
            <select name="sync_interval_hours" form="<?= $formId ?>" class="form-control" style="width:90px;padding:4px 6px;font-size:12px">
              <?php foreach ([1,2,6,12,24,48] as $h): ?>
              <option value="<?= $h ?>" <?= (int)$svc['sync_interval_hours']===$h?'selected':'' ?>><?= $h ?> ساعة</option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="td-muted" style="font-size:11px;white-space:nowrap"><?= $svc['sync_last_run'] ? date('Y-m-d H:i', strtotime($svc['sync_last_run'])) : '—' ?></td>
          <td class="td-muted" style="font-size:11px;max-width:220px"><?= htmlspecialchars($svc['sync_last_result'] ?? '—') ?></td>
          <td><button type="submit" form="<?= $formId ?>" class="btn btn-secondary btn-xs"><i class="fas fa-save"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </form>

  <!-- نماذج الحفظ الفردية لكل خدمة (خارج نموذج التحديد الجماعي لتفادي تداخل الـ forms) -->
  <?php foreach ($services as $svc): ?>
  <form method="POST" id="svcForm<?= $svc['id'] ?>" style="display:none">
    <input type="hidden" name="save_svc_sync" value="1">
    <input type="hidden" name="svc_id" value="<?= $svc['id'] ?>">
  </form>
  <?php endforeach; ?>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;padding:14px;border-top:1px solid var(--border)">
    <?php
      $mk = function($p) use ($qsBase) { return SITE_URL.'/admin/price_sync.php?' . $qsBase . ($qsBase?'&':'') . 'page='.$p; };
    ?>
    <a href="<?= $mk(max(1,$page-1)) ?>" class="btn btn-secondary btn-sm <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-right"></i> السابق</a>
    <?php
      $start = max(1, $page-2); $end = min($totalPages, $page+2);
      if ($start > 1) { echo '<a href="'.$mk(1).'" class="btn btn-secondary btn-sm">1</a>'; if ($start>2) echo '<span class="td-muted">...</span>'; }
      for ($p=$start; $p<=$end; $p++):
    ?>
      <a href="<?= $mk($p) ?>" class="btn btn-sm <?= $p==$page?'btn-primary':'btn-secondary' ?>"><?= $p ?></a>
    <?php endfor;
      if ($end < $totalPages) { if ($end < $totalPages-1) echo '<span class="td-muted">...</span>'; echo '<a href="'.$mk($totalPages).'" class="btn btn-secondary btn-sm">'.$totalPages.'</a>'; }
    ?>
    <a href="<?= $mk(min($totalPages,$page+1)) ?>" class="btn btn-secondary btn-sm <?= $page>=$totalPages?'disabled':'' ?>">التالي <i class="fas fa-chevron-left"></i></a>
  </div>
  <?php endif; ?>
</div>

<!-- ══ سجل آخر عمليات المزامنة ══ -->
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> سجل آخر عمليات المزامنة (آخر 80)</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>الوقت</th><th>اسم الخدمة</th><th>ID عند المزود</th><th>السعر القديم</th>
        <th>سعر المزود</th><th>الزيادة %</th><th>السعر النهائي</th><th>السبب</th><th>الحالة</th>
      </tr></thead>
      <tbody>
        <?php if (!$log): ?>
        <tr><td colspan="9"><div class="empty-state"><span class="empty-state-icon">📭</span><div class="empty-state-title">لا يوجد سجل مزامنة بعد</div></div></td></tr>
        <?php endif; ?>
        <?php foreach ($log as $l): ?>
        <tr>
          <td class="td-muted" style="font-size:11px;white-space:nowrap"><?= date('Y-m-d H:i', strtotime($l['created_at'])) ?></td>
          <td class="td-bold" style="font-size:12.5px">
            <?= htmlspecialchars($l['service_name'] ?? '#'.$l['service_id']) ?>
            <?php if ($l['prov_name']): ?><div class="td-muted" style="font-size:10px"><?= htmlspecialchars($l['prov_name']) ?></div><?php endif; ?>
          </td>
          <td class="td-muted" style="font-size:11px;font-family:monospace"><?= htmlspecialchars($l['provider_service_id'] ?? '—') ?></td>
          <td style="font-size:12px"><?= $l['old_price'] !== null ? formatMoney($l['old_price']) : '—' ?></td>
          <td style="font-size:12px"><?= $l['provider_price'] !== null ? formatMoney($l['provider_price']) : '—' ?></td>
          <td style="font-size:12px"><?= $l['markup_percent'] !== null ? htmlspecialchars($l['markup_percent']).'%' : '—' ?></td>
          <td style="font-size:12px;font-weight:800;color:#00d4aa"><?= $l['new_price'] !== null ? formatMoney($l['new_price']) : '—' ?></td>
          <td>
            <span class="badge <?= $l['reason']==='manual'?'badge-info':'badge-secondary' ?>" style="font-size:10px">
              <?= $l['reason']==='manual' ? '👤 يدوي' : '⏱ تلقائي' ?>
            </span>
          </td>
          <td>
            <span class="badge badge-<?= $l['status']==='success'?'success':'danger' ?>" title="<?= htmlspecialchars($l['message'] ?? '') ?>">
              <?= $l['status']==='success'?'✔ نجاح':'✘ '.htmlspecialchars($l['message'] ?? 'خطأ') ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include 'footer.php'; ?>
