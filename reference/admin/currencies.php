<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'عملات العرض — ' . SITE_NAME;

// ── إنشاء الجداول ──────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `display_currencies` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `currency_code` VARCHAR(10) NOT NULL,
        `currency_name` VARCHAR(50) NOT NULL,
        `currency_symbol` VARCHAR(10) NOT NULL,
        `rate_from_usd` DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
        `rate_source` ENUM('manual','api') DEFAULT 'manual',
        `is_default` TINYINT(1) DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1,
        `sort_order` INT DEFAULT 0,
        UNIQUE KEY `unique_code` (`currency_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `currency_api_cache` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `base` VARCHAR(10) NOT NULL DEFAULT 'USD',
        `rates_json` LONGTEXT NOT NULL,
        `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_base` (`base`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ((int)$pdo->query("SELECT COUNT(*) FROM display_currencies")->fetchColumn() === 0) {
        $pdo->exec("INSERT IGNORE INTO display_currencies (currency_code,currency_name,currency_symbol,rate_from_usd,rate_source,status,sort_order,is_default) VALUES
            ('USD','دولار أمريكي','\$',1,'manual',1,0,1)");
    }
} catch(Exception $e) {}

// ── حفظ / تعديل ────────────────────────────────────────────────────────────────
// ── مزامنة أسعار API ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['sync_api_rates'])) {
    $rates = json_decode($_POST['rates_json'] ?? '{}', true);
    if (!empty($rates)) {
        $pdo->prepare("INSERT INTO currency_api_cache (base,rates_json,fetched_at) VALUES ('USD',?,NOW()) ON DUPLICATE KEY UPDATE rates_json=VALUES(rates_json),fetched_at=NOW()")
            ->execute([json_encode($rates)]);
        $apiCurs = $pdo->query("SELECT id,currency_code FROM display_currencies WHERE rate_source='api'")->fetchAll();
        $updated = 0;
        foreach ($apiCurs as $ac) {
            if (isset($rates[$ac['currency_code']])) {
                $pdo->prepare("UPDATE display_currencies SET rate_from_usd=? WHERE id=?")->execute([$rates[$ac['currency_code']],$ac['id']]);
                $updated++;
            }
        }
        flashMessage('success', "✅ تمت مزامنة $updated عملة — ".date('H:i d/m'));
    } else {
        flashMessage('danger', '❌ فشلت المزامنة — بيانات غير صالحة');
    }
    redirect(SITE_URL.'/admin/currencies.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
    $id     = (int)($_POST['id'] ?? 0);
    $code   = strtoupper(trim($_POST['currency_code'] ?? ''));
    $name   = trim($_POST['currency_name'] ?? '');
    $symbol = trim($_POST['currency_symbol'] ?? '');
    $rate   = (float)($_POST['rate_from_usd'] ?? 0);
    $source = ($_POST['rate_source'] ?? 'manual') === 'api' ? 'api' : 'manual';
    $status = isset($_POST['status']) ? 1 : 0;
    $sort   = (int)($_POST['sort_order'] ?? 0);
    $isDef  = isset($_POST['is_default']) ? 1 : 0;

    if (!$code || !$name || !$symbol || $rate <= 0) {
        flashMessage('danger', 'جميع الحقول مطلوبة');
    } else {
        if ($isDef) $pdo->exec("UPDATE display_currencies SET is_default=0");
        if ($id) {
            $pdo->prepare("UPDATE display_currencies SET currency_code=?,currency_name=?,currency_symbol=?,rate_from_usd=?,rate_source=?,status=?,sort_order=?,is_default=? WHERE id=?")
                ->execute([$code,$name,$symbol,$rate,$source,$status,$sort,$isDef,$id]);
            flashMessage('success', "✅ تم تحديث $name");
        } else {
            $ex = $pdo->prepare("SELECT id FROM display_currencies WHERE currency_code=?");
            $ex->execute([$code]);
            if ($ex->fetch()) {
                flashMessage('danger', "الكود $code موجود مسبقاً");
            } else {
                $pdo->prepare("INSERT INTO display_currencies (currency_code,currency_name,currency_symbol,rate_from_usd,rate_source,status,sort_order,is_default) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$code,$name,$symbol,$rate,$source,$status,$sort,$isDef]);
                flashMessage('success', "✅ تمت إضافة $name");
            }
        }
    }
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── إضافة دفعة من API ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_add'])) {
    $items = json_decode($_POST['bulk_items'] ?? '[]', true);
    $added = 0;
    foreach ((array)$items as $item) {
        $code   = strtoupper(trim($item['code'] ?? ''));
        $name   = trim($item['name'] ?? $code);
        $symbol = trim($item['symbol'] ?? $code);
        $rate   = (float)($item['rate'] ?? 0);
        if (!$code || $rate <= 0) continue;
        try {
            $pdo->prepare("INSERT IGNORE INTO display_currencies (currency_code,currency_name,currency_symbol,rate_from_usd,rate_source,status,sort_order) VALUES (?,?,?,?,'api',1,99)")
                ->execute([$code,$name,$symbol,$rate]);
            $added++;
        } catch(Exception $e) {}
    }
    flashMessage('success', "✅ تمت إضافة $added عملة");
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── حذف فردي ────────────────────────────────────────────────────────────────────
if (isset($_GET['del'])) {
    $row = $pdo->prepare("SELECT currency_code FROM display_currencies WHERE id=?");
    $row->execute([(int)$_GET['del']]); $row = $row->fetch();
    if ($row && $row['currency_code']==='USD') {
        flashMessage('danger', 'لا يمكن حذف الدولار');
    } else {
        $pdo->prepare("DELETE FROM display_currencies WHERE id=?")->execute([(int)$_GET['del']]);
        flashMessage('success', '✅ تم الحذف');
    }
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── حذف دفعي ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['bulk_delete'])) {
    $ids = array_filter(array_map('intval', (array)($_POST['del_ids'] ?? [])));
    if (empty($ids)) {
        flashMessage('danger', 'لم تحدد أي عملة');
    } else {
        // لا نحذف الدولار
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = $pdo->prepare("DELETE FROM display_currencies WHERE id IN ($placeholders) AND currency_code != 'USD'");
        $deleted->execute($ids);
        $count = $deleted->rowCount();
        flashMessage('success', "✅ تم حذف $count عملة");
    }
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── تفعيل/إيقاف ────────────────────────────────────────────────────────────────
if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE display_currencies SET status=IF(status=1,0,1) WHERE id=? AND currency_code!='USD'")->execute([(int)$_GET['toggle']]);
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── تعيين افتراضي ───────────────────────────────────────────────────────────────
if (isset($_GET['set_default'])) {
    $pdo->exec("UPDATE display_currencies SET is_default=0");
    $pdo->prepare("UPDATE display_currencies SET is_default=1 WHERE id=?")->execute([(int)$_GET['set_default']]);
    flashMessage('success', '✅ تم تعيين العملة الافتراضية');
    redirect(SITE_URL.'/admin/currencies.php');
}

// ── جلب البيانات ───────────────────────────────────────────────────────────────
$currencies = $pdo->query("SELECT * FROM display_currencies ORDER BY sort_order, id")->fetchAll();
$editId  = (int)($_GET['edit'] ?? 0);
$editRow = null;
foreach ($currencies as $c) { if ($c['id']==$editId) { $editRow=$c; break; } }

// cache معلومات
$apiCache = $pdo->query("SELECT fetched_at FROM currency_api_cache WHERE base='USD' LIMIT 1")->fetch();
$cacheAge = $apiCache ? (time()-strtotime($apiCache['fetched_at'])) : null;

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-coins"></i></div>
      عملات العرض
    </div>
  </div>
  <div class="page-header-actions" style="gap:8px">
    <!-- معلومات آخر مزامنة -->
    <div style="font-size:.78rem;color:var(--text3);padding:6px 12px;background:var(--card2);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;gap:6px">
      <?php
        $apiC = $pdo->query("SELECT fetched_at FROM currency_api_cache WHERE base='USD' LIMIT 1")->fetch();
        $apiCount = count(array_filter($currencies, function($c){ return $c['rate_source']==='api'; }));
        if ($apiC):
            $age = time() - strtotime($apiC['fetched_at']);
            $ageStr = $age < 60 ? 'الآن' : ($age < 3600 ? round($age/60).' دقيقة' : round($age/3600).' ساعة');
      ?>
      <i class="fas fa-<?=$age<3600?'check-circle':'clock'?>" style="color:<?=$age<3600?'#00e676':'#f5a623'?>"></i>
      آخر مزامنة: <strong><?=$ageStr?> مضت</strong> · <?=$apiCount?> عملة API
      <?php else: ?>
      <i class="fas fa-exclamation-triangle" style="color:#f5a623"></i>
      لم تتم المزامنة بعد · <?=$apiCount?> عملة API
      <?php endif; ?>
    </div>

    <!-- زر المزامنة -->
    <button onclick="syncApiRates()" id="syncBtn" class="btn btn-secondary"
            <?=$apiCount===0?'disabled title="لا توجد عملات مصدرها API"':''?>>
      <i class="fas fa-sync-alt" id="syncIcon"></i> مزامنة أسعار API
    </button>

    <!-- زر جلب العملات -->
    <button onclick="openImportModal()" class="btn btn-primary">
      <i class="fas fa-cloud-download-alt"></i> جلب عملات جديدة
    </button>
  </div>
</div>

<?php $flash=getFlash(); if($flash): ?>
<div class="alert alert-<?=$flash['type']?>" style="margin-bottom:16px"><?=htmlspecialchars($flash['message'])?></div>
<?php endif; ?>

<!-- ═══ نافذة جلب العملات ═══ -->
<div id="importModal" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9000;display:none;align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--bg2);border-radius:20px;width:100%;max-width:860px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden">

    <!-- Header -->
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0">
      <div>
        <div style="font-size:1.1rem;font-weight:900"><i class="fas fa-cloud-download-alt" style="color:#00d4aa"></i> جلب العملات من API</div>
        <div id="importStatus" style="font-size:.78rem;color:var(--text3);margin-top:2px">اضغط "جلب" لتحميل قائمة العملات</div>
      </div>
      <button onclick="closeImportModal()" style="background:var(--card2);border:none;border-radius:50%;width:34px;height:34px;color:var(--text);cursor:pointer;font-size:1rem">✕</button>
    </div>

    <!-- Search + Fetch -->
    <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;gap:8px;flex-shrink:0">
      <input type="text" id="importSearch" placeholder="بحث... مثال: SAR, EUR, ريال"
             oninput="filterImport(this.value)"
             style="flex:1;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:8px 12px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none">
      <button onclick="fetchAllCurrencies()" id="fetchBtn" class="btn btn-primary">
        <i class="fas fa-download"></i> جلب الأسعار
      </button>
      <button onclick="addSelected()" id="addSelBtn" class="btn btn-success" style="display:none">
        <i class="fas fa-plus"></i> إضافة المحددة (<span id="selCount">0</span>)
      </button>
    </div>

    <!-- قائمة العملات -->
    <div id="importList" style="flex:1;overflow-y:auto;padding:8px">
      <div style="text-align:center;padding:3rem;color:var(--text3)">
        <i class="fas fa-cloud" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:12px"></i>
        اضغط "جلب الأسعار" لتحميل 170+ عملة
      </div>
    </div>

    <!-- Footer -->
    <div id="importFooter" style="padding:10px 20px;border-top:1px solid var(--border);font-size:.75rem;color:var(--text3);display:none;flex-shrink:0">
      ✅ بيانات من <strong>open.er-api.com</strong> — مجاني وموثوق
      <span id="cacheInfo" style="margin-right:12px"></span>
    </div>

    <!-- فورم الإضافة الدفعية -->
    <form method="POST" id="bulkForm" style="display:none">
        <?= adminCsrfField() ?>
      <input type="hidden" name="bulk_add" value="1">
      <input type="hidden" name="bulk_items" id="bulkItems">
    </form>
  </div>
</div>

<!-- ═══ المحتوى الرئيسي ═══ -->
<div style="display:grid;grid-template-columns:360px 1fr;gap:16px;align-items:start">

  <!-- فورم الإضافة اليدوية -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title">
        <i class="fas fa-<?=$editRow?'edit':'plus-circle'?>" style="color:<?=$editRow?'#f5a623':'#00d4aa'?>"></i>
        <?=$editRow?'تعديل: '.htmlspecialchars($editRow['currency_name']):'إضافة يدوية'?>
      </div>
    </div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save" value="1">
        <input type="hidden" name="id" value="<?=$editRow?$editRow['id']:0?>">

        <?php $isApiEdit = ($editRow && $editRow['rate_source']==='api'); ?>
        <?php if(!$isApiEdit): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>كود العملة *</label>
            <input type="text" name="currency_code" class="form-control"
                   value="<?=htmlspecialchars($editRow['currency_code']??'')?>"
                   placeholder="USD" style="text-transform:uppercase;font-family:monospace;letter-spacing:2px"
                   oninput="this.value=this.value.toUpperCase()"
                   <?=($editRow&&$editRow['currency_code']==='USD')?'readonly':''?>>
          </div>
          <div class="form-group">
            <label>الرمز *</label>
            <input type="text" name="currency_symbol" class="form-control"
                   value="<?=htmlspecialchars($editRow['currency_symbol']??'')?>" placeholder="$">
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="currency_code" value="<?=htmlspecialchars($editRow['currency_code']??'')?>">
        <?php endif; ?>
        <?php if($isApiEdit): ?>
        <div style="background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:.82rem">
          <div style="color:#00d4ff;font-weight:700;margin-bottom:4px"><i class="fas fa-cloud"></i> عملة مصدرها API</div>
          <div style="color:var(--text3)">الاسم والسعر يُحدَّثان تلقائياً عبر المزامنة — التعديل هنا للترتيب والحالة فقط.</div>
        </div>
        <input type="hidden" name="currency_name" value="<?=htmlspecialchars($editRow['currency_name'])?>">
        <input type="hidden" name="currency_symbol" value="<?=htmlspecialchars($editRow['currency_symbol'])?>">
        <input type="hidden" name="rate_from_usd" value="<?=(float)$editRow['rate_from_usd']?>">
        <input type="hidden" name="rate_source" value="api">
        <?php else: ?>
        <div class="form-group">
          <label>الاسم *</label>
          <input type="text" name="currency_name" class="form-control"
                 value="<?=htmlspecialchars($editRow['currency_name']??'')?>" placeholder="دولار أمريكي">
        </div>
        <div class="form-group">
          <label>1 USD = كم وحدة *</label>
          <div style="display:flex;gap:8px;align-items:center">
            <span style="color:var(--text3);white-space:nowrap">1 $ =</span>
            <input type="number" name="rate_from_usd" id="rateInput" class="form-control"
                   value="<?=$editRow?(float)$editRow['rate_from_usd']:''?>"
                   step="any" min="0.000001" placeholder="مثال: 550" oninput="calcPrev()">
          </div>
          <div id="prev" style="margin-top:5px;font-size:.78rem;color:#00d4aa;min-height:16px"></div>
          <div class="form-hint">أمثلة: دولار=1 | ريال سعودي=3.75 | ريال يمني=550</div>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" class="form-control"
                   value="<?=$editRow?$editRow['sort_order']:count($currencies)?>" min="0">
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;gap:6px;justify-content:flex-end">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:7px 10px;border:1px solid var(--border);border-radius:8px">
              <input type="checkbox" name="status" value="1" <?=(!$editRow||$editRow['status'])?'checked':''?>>
              <span>مفعّلة</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:7px 10px;border:1px solid rgba(245,166,35,.3);border-radius:8px">
              <input type="checkbox" name="is_default" value="1" <?=(!empty($editRow['is_default']))?'checked':''?>>
              <span style="color:#f5a623">⭐ افتراضية</span>
            </label>
          </div>
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary" style="flex:1">
            <i class="fas fa-save"></i> <?=$editRow?'حفظ':'إضافة'?>
          </button>
          <?php if($editRow): ?><a href="currencies.php" class="btn btn-secondary">إلغاء</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- جدول العملات -->
  <div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:8px">
      <div class="card-header-title"><i class="fas fa-list"></i> العملات المضافة (<?=count($currencies)?>)</div>
      <div style="display:flex;align-items:center;gap:8px;margin-right:auto">
        <span style="font-size:.75rem;color:var(--text3)"><?=count(array_filter($currencies, function($c){ return $c['status']; }))?> مفعّلة</span>
        <!-- شريط الحذف الدفعي - يظهر عند التحديد -->
        <div id="bulkBar" style="display:none;align-items:center;gap:8px">
          <span id="selCountLbl" style="font-size:.78rem;color:#f5a623;font-weight:700"></span>
          <button onclick="bulkDelete()" class="btn btn-danger btn-sm">
            <i class="fas fa-trash"></i> حذف المحددة
          </button>
          <button onclick="clearSelection()" class="btn btn-secondary btn-sm">إلغاء</button>
        </div>
      </div>
    </div>
    <?php if(empty($currencies)): ?>
    <div class="card-body" style="text-align:center;color:var(--text3);padding:3rem">
      لا توجد عملات — أضف من الفورم أو اجلب من الإنترنت
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:36px">
              <input type="checkbox" id="checkAllCur" onchange="toggleAllCur(this)" title="تحديد الكل">
            </th>
            <th>العملة</th>
            <th>المصدر</th>
            <th style="text-align:center">1 USD = ؟</th>
            <th style="text-align:center">مثال 10$</th>
            <th style="text-align:center">افتراضي</th>
            <th style="text-align:center">الحالة</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($currencies as $c):
          $r = (float)$c['rate_from_usd'];
          $isUSD = ($c['currency_code']==='USD');
          $isDef = !empty($c['is_default']);
        ?>
        <tr style="<?=!$c['status']?'opacity:.4':'';?><?=$isDef?' background:rgba(245,166,35,.04)':''?>">
          <td>
            <?php if(!$isUSD): ?>
            <input type="checkbox" class="cur-chk" value="<?=$c['id']?>" onchange="updateBulkBar()">
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:36px;height:36px;background:<?=$isDef?'rgba(245,166,35,.15)':'rgba(30,111,255,.1)'?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:900;color:<?=$isDef?'#f5a623':'var(--primary)'?>">
                <?=htmlspecialchars($c['currency_symbol'])?>
              </div>
              <div>
                <div style="font-weight:800"><?=htmlspecialchars($c['currency_code'])?></div>
                <div style="font-size:.72rem;color:var(--text3)"><?=htmlspecialchars($c['currency_name'])?></div>
              </div>
            </div>
          </td>
          <td>
            <span style="background:<?=$c['rate_source']==='api'?'rgba(0,212,255,.12)':'rgba(245,166,35,.1)'?>;color:<?=$c['rate_source']==='api'?'#00d4ff':'#f5a623'?>;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700">
              <?=$c['rate_source']==='api'?'<i class="fas fa-cloud"></i> API':'<i class="fas fa-pen"></i> يدوي'?>
            </span>
          </td>
          <td style="text-align:center;font-family:monospace;color:#00d4ff;font-weight:700">
            <?=number_format($r,$r>=1000?0:($r>=1?2:6))?>
          </td>
          <td style="text-align:center;color:#00d4aa;font-weight:700">
            <?php $ex=$r*10; echo number_format($ex,$ex>=1000?0:($ex>=1?2:4));?> <?=htmlspecialchars($c['currency_symbol'])?>
          </td>
          <td style="text-align:center">
            <?php if($isDef): ?>
            <span style="color:#f5a623;font-size:1.1rem">⭐</span>
            <?php else: ?>
            <a href="?set_default=<?=$c['id']?>" class="btn btn-xs btn-secondary" title="تعيين كافتراضي">☆</a>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if($isUSD): ?>
            <span class="badge badge-success">ثابت</span>
            <?php else: ?>
            <a href="?toggle=<?=$c['id']?>" class="badge <?=$c['status']?'badge-success':'badge-secondary'?>" style="cursor:pointer;text-decoration:none">
              <?=$c['status']?'✅':'⏸'?>
            </a>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:4px">
              <a href="?edit=<?=$c['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-edit"></i></a>
              <?php if(!$isUSD): ?>
              <a href="?del=<?=$c['id']?>" onclick="return confirm('حذف هذه العملة؟')" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:10px 16px;border-top:1px solid var(--border);font-size:.73rem;color:var(--text3)">
      <i class="fas fa-lock" style="color:#f5a623"></i>
      أسعار الدفع من <a href="payments.php?tab=rates" style="color:var(--primary)">طرق الدفع ← أسعار الصرف</a> — هذه للعرض فقط
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
// ── معاينة السعر ──
function calcPrev() {
  var r = parseFloat(document.getElementById('rateInput').value)||0;
  var el = document.getElementById('prev');
  if(!r){el.textContent='';return;}
  function fmt(n){return n>=1000?n.toLocaleString('en-US',{maximumFractionDigits:0}):n>=1?n.toFixed(2):n.toFixed(4);}
  el.innerHTML = '10$ = <strong>'+fmt(r*10)+'</strong> &nbsp;|&nbsp; 1$ = <strong>'+fmt(r)+'</strong>';
}
calcPrev();

// ══ نافذة جلب العملات ══════════════════════════════════════════
var _allCurrencies = []; // كل العملات المجلوبة
var _selected = {};      // المحددة للإضافة

// أسماء وهويات العملات الشائعة
var CURRENCY_NAMES = {
  USD:'دولار أمريكي',SAR:'ريال سعودي',AED:'درهم إماراتي',KWD:'دينار كويتي',
  BHD:'دينار بحريني',QAR:'ريال قطري',OMR:'ريال عُماني',JOD:'دينار أردني',
  EGP:'جنيه مصري',TRY:'ليرة تركية',EUR:'يورو',GBP:'جنيه إسترليني',
  JPY:'ين ياباني',CNY:'يوان صيني',INR:'روبية هندية',RUB:'روبل روسي',
  YER:'ريال يمني',LYD:'دينار ليبي',TND:'دينار تونسي',MAD:'درهم مغربي',
  DZD:'دينار جزائري',SYP:'ليرة سورية',IQD:'دينار عراقي',LBP:'ليرة لبنانية',
  SDG:'جنيه سوداني',CAD:'دولار كندي',AUD:'دولار أسترالي',CHF:'فرنك سويسري',
  MYR:'رينغيت ماليزي',SGD:'دولار سنغافوري',THB:'بات تايلاندي',
};

var CURRENCY_SYMBOLS = {
  USD:'$',SAR:'ر.س',AED:'د.إ',KWD:'د.ك',BHD:'د.ب',QAR:'ر.ق',OMR:'ر.ع',
  JOD:'د.ا',EGP:'ج.م',TRY:'₺',EUR:'€',GBP:'£',JPY:'¥',CNY:'¥',INR:'₹',
  RUB:'₽',YER:'ر.ي',CAD:'C$',AUD:'A$',CHF:'Fr',MYR:'RM',SGD:'S$',THB:'฿',
  LYD:'ل.د',TND:'د.ت',MAD:'د.م',DZD:'دج',SYP:'ل.س',IQD:'ع.د',LBP:'ل.ل',SDG:'ج.س',
};

function openImportModal() {
  document.getElementById('importModal').style.display = 'flex';
}
function closeImportModal() {
  document.getElementById('importModal').style.display = 'none';
}

async function fetchAllCurrencies() {
  var btn = document.getElementById('fetchBtn');
  var status = document.getElementById('importStatus');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الجلب...';
  status.textContent = 'يتصل بـ API...';

  try {
    var res = await fetch('https://open.er-api.com/v6/latest/USD');
    var data = await res.json();

    if (!data.rates) throw new Error('لا توجد بيانات');

    _allCurrencies = [];
    for (var code in data.rates) {
      _allCurrencies.push({
        code:   code,
        rate:   data.rates[code],
        name:   CURRENCY_NAMES[code] || code,
        symbol: CURRENCY_SYMBOLS[code] || code,
      });
    }
    // ترتيب: العملات العربية أولاً ثم الباقي
    _allCurrencies.sort(function(a,b){
      var arab = ['SAR','AED','KWD','BHD','QAR','OMR','JOD','EGP','YER','LYD','IQD','LBP','SYP','DZD','MAD','TND','SDG'];
      var ai = arab.indexOf(a.code), bi = arab.indexOf(b.code);
      if(ai>=0&&bi<0) return -1;
      if(ai<0&&bi>=0) return 1;
      if(ai>=0&&bi>=0) return ai-bi;
      return a.code.localeCompare(b.code);
    });

    var updated = new Date(data.time_last_update_utc).toLocaleString('ar');
    status.textContent = 'تم جلب ' + _allCurrencies.length + ' عملة — آخر تحديث: ' + updated;
    document.getElementById('importFooter').style.display = 'block';
    document.getElementById('cacheInfo').textContent = 'بيانات حية من open.er-api.com';

    renderImportList(_allCurrencies);

  } catch(e) {
    status.textContent = '❌ فشل الجلب: ' + e.message;
    document.getElementById('importList').innerHTML = '<div style="text-align:center;padding:2rem;color:#ff8898"><i class="fas fa-exclamation-circle"></i> فشل الاتصال — تحقق من الإنترنت</div>';
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-sync-alt"></i> إعادة الجلب';
}

// العملات الموجودة مسبقاً
var EXISTING = <?= json_encode(array_map(function($c){ return $c['currency_code']; }, $currencies), JSON_UNESCAPED_UNICODE) ?>;

function renderImportList(list) {
  var html = '<table style="width:100%;border-collapse:collapse">';
  html += '<thead><tr style="background:rgba(255,255,255,.03)">'
    + '<th style="padding:8px 12px;text-align:right;font-size:.75rem;color:var(--text3)"><input type="checkbox" id="checkAll" onchange="toggleAll(this)"> الكل</th>'
    + '<th style="padding:8px 12px;text-align:right;font-size:.75rem;color:var(--text3)">الكود</th>'
    + '<th style="padding:8px 12px;text-align:right;font-size:.75rem;color:var(--text3)">الاسم</th>'
    + '<th style="padding:8px 12px;text-align:right;font-size:.75rem;color:var(--text3)">الرمز</th>'
    + '<th style="padding:8px 12px;text-align:center;font-size:.75rem;color:var(--text3)">1 USD =</th>'
    + '<th style="padding:8px 12px;text-align:center;font-size:.75rem;color:var(--text3)"></th>'
    + '</tr></thead><tbody>';

  list.forEach(function(cur) {
    var exists  = EXISTING.indexOf(cur.code) >= 0;
    var checked = _selected[cur.code] ? 'checked' : '';
    var rate = cur.rate >= 1000 ? Math.round(cur.rate).toLocaleString('en-US')
             : cur.rate >= 1   ? cur.rate.toFixed(2)
             : cur.rate.toFixed(6);

    html += '<tr style="border-bottom:1px solid rgba(255,255,255,.04);'+(exists?'opacity:.4':'')+(cur.code==='USD'?'display:none':'')+'">'
      + '<td style="padding:7px 12px"><input type="checkbox" data-code="'+cur.code+'" '+(exists?'disabled':checked)+' onchange="toggleSelect(this)"></td>'
      + '<td style="padding:7px 12px;font-family:monospace;font-weight:800;color:var(--primary)">' + cur.code + '</td>'
      + '<td style="padding:7px 12px;font-size:.82rem">' + cur.name + '</td>'
      + '<td style="padding:7px 12px;font-weight:700;color:#f5a623">' + cur.symbol + '</td>'
      + '<td style="padding:7px 12px;text-align:center;font-family:monospace;font-size:.8rem;color:#00d4aa">' + rate + '</td>'
      + '<td style="padding:7px 12px;text-align:center">'
        + (exists ? '<span style="font-size:.7rem;color:var(--text3)">مضاف</span>' : '')
      + '</td>'
      + '</tr>';
  });

  html += '</tbody></table>';
  document.getElementById('importList').innerHTML = html;
}

function toggleSelect(cb) {
  var code = cb.dataset.code;
  if (cb.checked) _selected[code] = true;
  else delete _selected[code];
  updateSelCount();
}

function toggleAll(masterCb) {
  var cbs = document.querySelectorAll('#importList input[type=checkbox]:not(:disabled)');
  cbs.forEach(function(cb){ cb.checked=masterCb.checked; toggleSelect(cb); });
}

function updateSelCount() {
  var n = Object.keys(_selected).length;
  document.getElementById('selCount').textContent = n;
  document.getElementById('addSelBtn').style.display = n > 0 ? 'inline-flex' : 'none';
}

function filterImport(q) {
  q = q.trim().toUpperCase();
  var filtered = _allCurrencies.filter(function(c){
    return !q || c.code.includes(q) || c.name.includes(q);
  });
  renderImportList(filtered);
  // إعادة تطبيق الـ selected
  Object.keys(_selected).forEach(function(code){
    var cb = document.querySelector('#importList [data-code="'+code+'"]');
    if(cb) cb.checked = true;
  });
}

function addSelected() {
  if (!Object.keys(_selected).length) return;
  var items = _allCurrencies.filter(function(c){ return _selected[c.code]; });
  document.getElementById('bulkItems').value = JSON.stringify(items.map(function(c){
    return {code:c.code, name:c.name, symbol:c.symbol, rate:c.rate};
  }));
  if (confirm('إضافة ' + items.length + ' عملة؟')) {
    document.getElementById('bulkForm').submit();
  }
}

// إغلاق بالـ Esc أو خارج النافذة
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeImportModal(); });
document.getElementById('importModal').addEventListener('click', function(e){ if(e.target===this) closeImportModal(); });

// ══ تحديد دفعي وحذف ══════════════════════════════════════════
function toggleAllCur(master) {
  document.querySelectorAll('.cur-chk').forEach(function(cb){ cb.checked = master.checked; });
  updateBulkBar();
}

function updateBulkBar() {
  var checked = document.querySelectorAll('.cur-chk:checked');
  var bar = document.getElementById('bulkBar');
  var lbl = document.getElementById('selCountLbl');
  var master = document.getElementById('checkAllCur');
  var all = document.querySelectorAll('.cur-chk');
  if (bar) bar.style.display = checked.length > 0 ? 'flex' : 'none';
  if (lbl) lbl.textContent = 'محدد: ' + checked.length;
  if (master) master.indeterminate = checked.length > 0 && checked.length < all.length;
  if (master && checked.length === all.length && all.length > 0) master.checked = true;
  if (master && checked.length === 0) master.checked = false;
}

function clearSelection() {
  document.querySelectorAll('.cur-chk').forEach(function(cb){ cb.checked = false; });
  var master = document.getElementById('checkAllCur');
  if (master) { master.checked = false; master.indeterminate = false; }
  updateBulkBar();
}

function bulkDelete() {
  var checked = document.querySelectorAll('.cur-chk:checked');
  if (!checked.length) return;
  if (!confirm('حذف ' + checked.length + ' عملة؟ لا يمكن التراجع!')) return;

  var form = document.createElement('form');
  form.method = 'POST';
  form.action = '';

  var inp = document.createElement('input');
  inp.type = 'hidden'; inp.name = 'bulk_delete'; inp.value = '1';
  form.appendChild(inp);

  checked.forEach(function(cb) {
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'del_ids[]'; i.value = cb.value;
    form.appendChild(i);
  });

  document.body.appendChild(form);
  form.submit();
}

// ══ مزامنة أسعار API ══════════════════════════════════════════
async function syncApiRates() {
  var btn  = document.getElementById('syncBtn');
  var icon = document.getElementById('syncIcon');
  if (!btn || btn.disabled) return;

  btn.disabled = true;
  icon.className = 'fas fa-spinner fa-spin';
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ المزامنة...';

  try {
    // جلب أسعار الصرف من API
    var res  = await fetch('https://open.er-api.com/v6/latest/USD');
    var data = await res.json();

    if (!data.rates) throw new Error('لا توجد بيانات من API');

    // إرسال للـ PHP عبر POST لحفظها في DB وتحديث العملات
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    var inp1 = document.createElement('input');
    inp1.type = 'hidden'; inp1.name = 'sync_api_rates'; inp1.value = '1';
    form.appendChild(inp1);

    var inp2 = document.createElement('input');
    inp2.type = 'hidden'; inp2.name = 'rates_json'; inp2.value = JSON.stringify(data.rates);
    form.appendChild(inp2);

    document.body.appendChild(form);
    form.submit();

  } catch(e) {
    alert('❌ فشل الاتصال بـ API: ' + e.message);
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync-alt"></i> مزامنة أسعار API';
  }
}
</script>

<?php include 'footer.php'; ?>
