<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'استيراد خدمات من المزود — ' . SITE_NAME;

// ── جلب البيانات الأساسية ─────────────────────────────────────────────────────
$providers  = $pdo->query("SELECT * FROM providers ORDER BY name")->fetchAll();
$allCats    = $pdo->query("SELECT * FROM categories ORDER BY parent_id ASC, sort_order ASC")->fetchAll();

// ── جلب الخدمات من المزود (AJAX) ─────────────────────────────────────────────
if (isset($_GET['fetch_json']) && isset($_GET['provider_id'])) {
    header('Content-Type: application/json');
    $prov = $pdo->prepare("SELECT * FROM providers WHERE id=?");
    $prov->execute([(int)$_GET['provider_id']]);
    $prov = $prov->fetch();
    if (!$prov) { echo json_encode(['ok'=>false,'error'=>'مزود غير موجود']); exit; }

    // جلب الخدمات الموجودة مسبقاً لهذا المزود (من العمود القديم + جدول service_providers الجديد معاً)
    $existing = $pdo->prepare("SELECT provider_service_id FROM services WHERE provider_id=? AND provider_service_id IS NOT NULL AND provider_service_id!=''");
    $existing->execute([$prov['id']]);
    $existingIds = array_column($existing->fetchAll(), 'provider_service_id');

    $existingSp = $pdo->prepare("SELECT provider_service_id FROM service_providers WHERE provider_id=?");
    $existingSp->execute([$prov['id']]);
    $existingIds = array_unique(array_merge($existingIds, array_column($existingSp->fetchAll(), 'provider_service_id')));

    // مطابقة إضافية بالاسم (بغض النظر عن المزود) — الاعتماد الأساسي المطلوب: نفس الاسم = خدمة موجودة
    $allNames = $pdo->query("SELECT name FROM services")->fetchAll(PDO::FETCH_COLUMN);
    $existingNamesLower = array_map(fn($n) => mb_strtolower(trim($n)), $allNames);

    $checkExists = function($provId, $name) use ($existingIds, $existingNamesLower) {
        if ($provId !== '' && in_array((string)$provId, $existingIds, true)) return true;
        if ($name !== '' && in_array(mb_strtolower(trim($name)), $existingNamesLower, true)) return true;
        return false;
    };

    if (in_array($prov['provider_type'], ['oranos','ap4stor'])) {
        $ch = curl_init($prov['api_url'].'/client/api/products');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>['api-token: '.$prov['api_key']],CURLOPT_SSL_VERIFYPEER=>false]);
        $res = curl_exec($ch); curl_close($ch);
        $data = json_decode($res, true);
        if (!is_array($data) || isset($data['error'])) {
            echo json_encode(['ok'=>false,'error'=>$data['error']??'فشل الاتصال']); exit;
        }
        $services = array_map(function($p) use ($checkExists) {
            $qty = $p['qty_values'] ?? null;
            $pid  = (string)($p['id'] ?? '');
            $name = $p['name'] ?? '';
            return [
                'id'       => $pid,
                'name'     => $name,
                'category' => $p['category_name'] ?? '',
                'price'    => (float)($p['price'] ?? 0),
                'min_qty'  => is_array($qty)&&isset($qty['min']) ? $qty['min'] : 1,
                'max_qty'  => is_array($qty)&&isset($qty['max']) ? $qty['max'] : 9999,
                'params'   => $p['params'] ?? [],
                'available'=> (bool)($p['available'] ?? true),
                'exists'   => $checkExists($pid, $name),
            ];
        }, $data);
    } else {
        $ch = curl_init();
        curl_setopt_array($ch,[CURLOPT_URL=>$prov['api_url'],CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,CURLOPT_TIMEOUT=>20,
            CURLOPT_POSTFIELDS=>'key='.$prov['api_key'].'&action=services',
            CURLOPT_SSL_VERIFYPEER=>false]);
        $res = curl_exec($ch); curl_close($ch);
        $data = json_decode($res, true);
        if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'فشل الاتصال']); exit; }
        $services = array_map(function($p) use ($checkExists) {
            $pid  = (string)($p['service'] ?? $p['id'] ?? '');
            $name = $p['name'] ?? '';
            return [
                'id'       => $pid,
                'name'     => $name,
                'category' => $p['category'] ?? '',
                'price'    => (float)($p['rate'] ?? $p['price'] ?? 0),
                'min_qty'  => (int)($p['min'] ?? 1),
                'max_qty'  => (int)($p['max'] ?? 9999),
                'params'   => [],
                'available'=> true,
                'exists'   => $checkExists($pid, $name),
            ];
        }, $data);
    }
    echo json_encode(['ok'=>true,'services'=>$services,'type'=>$prov['provider_type']]);
    exit;
}

// ── دالة تحويل النص لمفتاح ────────────────────────────────────────────────────
function importSlugify($text) {
    $text = preg_replace('/^(ادخل|أدخل)\s+/u', '', trim($text));
    $text = preg_replace('/[\s\-]+/', '_', $text);
    $text = preg_replace('/[^\w]/u', '_', $text);
    $text = trim($text, '_');
    return empty($text) ? 'field_'.mt_rand(100,999) : strtolower($text);
}

// ── استيراد الخدمات (POST) ────────────────────────────────────────────────────
// ── إضافة كمزود ثانٍ فقط ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_as_secondary'])) {
    $providerId = (int)$_POST['provider_id'];
    $items      = json_decode($_POST['items_json'] ?? '[]', true);
    $added = 0; $skipped = 0; $notFound = 0;

    foreach ((array)$items as $item) {
        $provSvcId = trim($item['provider_service_id'] ?? '');
        $svcName   = trim($item['name'] ?? '');
        if (!$provSvcId) { $skipped++; continue; }

        // البحث عن الخدمة الموجودة بالاسم أو بـ provider_service_id عند أي مزود
        $existing = null;

        // بحث بـ provider_service_id عند مزودين آخرين
        $s = $pdo->prepare("SELECT id FROM services WHERE provider_service_id=? LIMIT 1");
        $s->execute([$provSvcId]); $existing = $s->fetchColumn();

        // بحث بالاسم إذا لم نجد
        if (!$existing && $svcName) {
            $s = $pdo->prepare("SELECT id FROM services WHERE name=? LIMIT 1");
            $s->execute([$svcName]); $existing = $s->fetchColumn();
        }

        if (!$existing) { $notFound++; continue; }

        // تحقق إذا المزود مضاف مسبقاً
        $dup = $pdo->prepare("SELECT id FROM service_providers WHERE service_id=? AND provider_id=?");
        $dup->execute([$existing, $providerId]);
        if ($dupRow = $dup->fetch()) {
            // موجود مسبقاً — نحدّث التسمية فقط (مفيد لو الاسم تغيّر عند المزود أو ما كانت محفوظة أصلاً)
            $pdo->prepare("UPDATE service_providers SET label=?, provider_service_id=? WHERE id=?")
                ->execute([$svcName, $provSvcId, $dupRow['id']]);
            $skipped++; continue;
        }

        // إضافة المزود الثاني
        try {
            $maxPrio = $pdo->prepare("SELECT COALESCE(MAX(priority),0)+1 FROM service_providers WHERE service_id=?");
            $maxPrio->execute([$existing]); $prio = (int)$maxPrio->fetchColumn();
            $pdo->prepare("INSERT INTO service_providers (service_id,provider_id,provider_service_id,priority,is_active,label) VALUES (?,?,?,?,1,?)")
                ->execute([$existing, $providerId, $provSvcId, $prio, $svcName]);
            $added++;
        } catch(Exception $e) { $skipped++; }
    }

    $msg = "✅ تمت إضافة $added خدمة كمزود ثانٍ";
    if ($notFound) $msg .= " · $notFound لم تُوجد";
    if ($skipped)  $msg .= " · $skipped متخطاة";
    flashMessage($added ? 'success' : 'warning', $msg);
    redirect(SITE_URL.'/admin/import_services.php');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['do_import'])) {
    $providerId  = (int)$_POST['provider_id'];
    $defaultCat  = (int)($_POST['default_cat'] ?? 0);
    $items       = json_decode($_POST['items_json'] ?? '[]', true);
    $catMap      = json_decode($_POST['cat_map']   ?? '{}', true);
    $imported    = 0; $skipped = 0; $updated = 0;

    foreach ((array)$items as $item) {
        $provSvcId = trim($item['provider_service_id'] ?? '');
        $name      = trim($item['name'] ?? '');
        $catId     = (int)($catMap[$item['category']??''] ?? $item['cat_id'] ?? $defaultCat);
        // السعر يُعالَج أدناه
        $minQty    = max(1, (int)($item['min_qty'] ?? 1));
        $maxQty    = max($minQty, (int)($item['max_qty'] ?? 9999));
        $params    = (array)($item['params'] ?? []);
        $update    = !empty($item['update_existing']);

        // استخدام defaultCat إذا لم يُحدَّد قسم
        if (!$catId) $catId = $defaultCat;
        // السعر: نقبل أي قيمة موجبة حتى لو صغيرة جداً
        $price = (float)($item['price'] ?? 0);
        if ($price <= 0) {
            // محاولة تحويل string الكبير مثل "1.5e-10"
            $price = (float)preg_replace('/[^0-9eE.\-+]/', '', (string)($item['price'] ?? ''));
        }
        if (!$provSvcId || !$name || !$catId) { $skipped++; continue; }
        if ($price <= 0) $price = 0.0001; // سعر افتراضي صغير

        // تحقق من الوجود: أولاً بنفس المزود ونفس معرّف المنتج، وإلا بنفس الاسم تمامًا (بغض النظر عن المزود)
        $ex = $pdo->prepare("SELECT id FROM services WHERE provider_id=? AND provider_service_id=?");
        $ex->execute([$providerId, $provSvcId]);
        $serviceId = $ex->fetchColumn();

        if (!$serviceId && $name !== '') {
            $exByName = $pdo->prepare("SELECT id FROM services WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1");
            $exByName->execute([$name]);
            $serviceId = $exByName->fetchColumn();
        }

        if ($serviceId) {
            if (!$update) { $skipped++; continue; }
            $pdo->prepare("UPDATE services SET name=?,category_id=?,price=?,min_qty=?,max_qty=?,status=1 WHERE id=?")
                ->execute([$name,$catId,$price,$minQty,$maxQty,$serviceId]);
            $updated++;
        } else {
            $pdo->prepare("INSERT INTO services (name,category_id,price,min_qty,max_qty,description,provider_id,provider_service_id,status) VALUES (?,?,?,?,?,?,?,?,1)")
                ->execute([$name,$catId,$price,$minQty,$maxQty,'مباشر 24 ساعة آلياً',$providerId,$provSvcId]);
            $serviceId = (int)$pdo->lastInsertId();
            $imported++;
        }

        if (!$serviceId) continue;

        // ── ربط service_providers (بدون حذف أي مزودين آخرين مربوطين مسبقاً بهذه الخدمة) ──
        try {
            $dupCheck = $pdo->prepare("SELECT id FROM service_providers WHERE service_id=? AND provider_id=?");
            $dupCheck->execute([$serviceId, $providerId]);
            if ($spRow = $dupCheck->fetch()) {
                // موجود مسبقاً لنفس المزود — نحدّث فقط الاسم/المعرّف بدل التكرار
                $pdo->prepare("UPDATE service_providers SET provider_service_id=?, label=? WHERE id=?")
                    ->execute([$provSvcId, $name, $spRow['id']]);
            } else {
                $maxPrio = $pdo->prepare("SELECT COALESCE(MAX(priority),0)+1 FROM service_providers WHERE service_id=?");
                $maxPrio->execute([$serviceId]); $prio = (int)$maxPrio->fetchColumn();
                $pdo->prepare("INSERT INTO service_providers (service_id,provider_id,provider_service_id,priority,is_active,label) VALUES (?,?,?,?,1,?)")
                    ->execute([$serviceId, $providerId, $provSvcId, $prio, $name]);
            }
        } catch(Exception $e) {}

        // ── إنشاء service_fields من params ───────────────────────────────────
        if (!empty($params)) {
            $pdo->prepare("DELETE FROM service_fields WHERE service_id=?")->execute([$serviceId]);
            $sort = 0;
            foreach ($params as $param) {
                $param = trim($param);
                if (!$param) continue;
                // أول حقل يُسمّى playerId دائماً (نفس اسم المعامل الذي يتوقعه المزود فعلياً حسب توثيقه)
                $fieldName = ($sort === 0) ? 'playerId' : importSlugify($param);
                $pdo->prepare("INSERT INTO service_fields (service_id,field_name,field_label,field_type,field_options,is_required,sort_order) VALUES (?,?,?,'text','',1,?)")
                    ->execute([$serviceId, $fieldName, $param, $sort++]);
            }
        }
    }

    $msg = "✅ تم استيراد $imported خدمة جديدة";
    if ($updated)  $msg .= " · تحديث $updated";
    if ($skipped)  $msg .= " · تخطي $skipped";
    flashMessage('success', $msg);
    redirect(SITE_URL.'/admin/import_services.php');
}

// ── الاستيراد الشامل: تصفير كامل + إعادة بناء تلقائي من المزود ──────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['full_reset_import'])) {
    $providerId      = (int)$_POST['fr_provider_id'];
    $markup          = (float)($_POST['fr_markup'] ?? 0);
    $resetCategories = !empty($_POST['fr_reset_categories']);
    $confirmWord     = trim($_POST['fr_confirm'] ?? '');

    if ($confirmWord !== 'حذف') {
        flashMessage('danger', '❌ لم تتم العملية — يجب كتابة كلمة «حذف» بالضبط لتأكيد العملية الخطيرة.');
        redirect(SITE_URL.'/admin/import_services.php');
    }

    $prov = $pdo->prepare("SELECT * FROM providers WHERE id=?");
    $prov->execute([$providerId]);
    $prov = $prov->fetch();
    if (!$prov) {
        flashMessage('danger', '❌ المزود المحدد غير موجود.');
        redirect(SITE_URL.'/admin/import_services.php');
    }

    // 1) جلب كامل قائمة منتجات المزود أولاً (قبل أي حذف، حتى لا نخسر شيء لو فشل الاتصال)
    $ch = curl_init(rtrim($prov['api_url'],'/').'/client/api/products');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['api-token: '.$prov['api_key']], CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch); $curlErr = curl_error($ch); curl_close($ch);
    $products = json_decode($res, true);

    if (!is_array($products) || isset($products['error']) || empty($products)) {
        flashMessage('danger', '❌ فشل جلب المنتجات من المزود: ' . ($curlErr ?: ($products['error'] ?? 'رد غير صالح من المزود')) . ' — لم يتم حذف أي شيء.');
        redirect(SITE_URL.'/admin/import_services.php');
    }

    // 2) التصفير الكامل الآن (بعد التأكد من نجاح الاتصال بالمزود)
    $pdo->exec("DELETE FROM service_fields");
    $pdo->exec("DELETE FROM service_providers");
    $pdo->exec("DELETE FROM services");
    $pdo->exec("ALTER TABLE services AUTO_INCREMENT = 1");
    if ($resetCategories) {
        $pdo->exec("DELETE FROM categories");
        $pdo->exec("ALTER TABLE categories AUTO_INCREMENT = 1");
    }

    // 3) بناء الأقسام تلقائياً من أسماء الفئات الموجودة بمنتجات المزود (فئة واحدة المستوى، بنفس تسمية المزود)
    $catCache = [];
    $existingCats = $pdo->query("SELECT id, name FROM categories")->fetchAll();
    foreach ($existingCats as $c) { $catCache[mb_strtolower(trim($c['name']))] = $c['id']; }

    $sortCounter = (int)($pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM categories")->fetchColumn());

    $imported = 0; $skipped = 0;
    $insCat = $pdo->prepare("INSERT INTO categories (name, status, sort_order) VALUES (?, 1, ?)");
    $insSvc = $pdo->prepare("INSERT INTO services (name, category_id, price, min_qty, max_qty, description, provider_id, provider_service_id, status) VALUES (?,?,?,?,?,?,?,?,1)");
    $insSp  = $pdo->prepare("INSERT INTO service_providers (service_id, provider_id, provider_service_id, priority, is_active, label) VALUES (?,?,?,1,1,?)");
    $insFld = $pdo->prepare("INSERT INTO service_fields (service_id, field_name, field_label, field_type, field_options, is_required, sort_order) VALUES (?,?,?,'text','',1,?)");

    foreach ($products as $p) {
        $provSvcId = (string)($p['id'] ?? '');
        $name      = trim($p['name'] ?? '');
        $catName   = trim($p['category_name'] ?? 'عام');
        if (!$provSvcId || !$name) { $skipped++; continue; }

        $catKey = mb_strtolower($catName ?: 'عام');
        if (!isset($catCache[$catKey])) {
            $sortCounter += 10;
            $insCat->execute([$catName ?: 'عام', $sortCounter]);
            $catCache[$catKey] = (int)$pdo->lastInsertId();
        }
        $catId = $catCache[$catKey];

        $qty    = $p['qty_values'] ?? null;
        $minQty = is_array($qty) && isset($qty['min']) ? (int)$qty['min'] : 1;
        $maxQty = is_array($qty) && isset($qty['max']) ? (int)$qty['max'] : 9999;
        if ($maxQty < $minQty) $maxQty = $minQty;

        $provPrice = (float)($p['price'] ?? 0);
        $ourPrice  = $provPrice * (1 + $markup / 100);
        if ($ourPrice <= 0) $ourPrice = 0.0001;

        $insSvc->execute([$name, $catId, $ourPrice, $minQty, $maxQty, 'مباشر 24 ساعة آلياً', $providerId, $provSvcId]);
        $serviceId = (int)$pdo->lastInsertId();

        $insSp->execute([$serviceId, $providerId, $provSvcId, $name]);

        $params = (array)($p['params'] ?? []);
        $sort = 0;
        foreach ($params as $param) {
            $param = trim($param);
            if (!$param) continue;
            // أول حقل يُسمّى playerId دائماً (نفس اسم المعامل الذي يتوقعه المزود فعلياً حسب توثيقه)
            $fieldName = ($sort === 0) ? 'playerId' : importSlugify($param);
            $insFld->execute([$serviceId, $fieldName, $param, $sort++]);
        }

        $imported++;
    }

    $msg = "✅ تم تصفير كل الخدمات" . ($resetCategories ? ' والأقسام' : '') . " وإعادة استيراد $imported خدمة تلقائياً من «{$prov['name']}» ضمن " . count($catCache) . " قسم.";
    if ($skipped) $msg .= " · تخطي $skipped (بيانات ناقصة)";
    flashMessage('success', $msg);
    redirect(SITE_URL.'/admin/import_services.php');
}

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa">
        <i class="fas fa-cloud-download-alt"></i>
      </div>
      استيراد خدمات من المزود
    </div>
  </div>
</div>

<?php $flash=getFlash(); if($flash): ?>
<div class="alert alert-<?=$flash['type']?>" style="margin-bottom:16px"><?=htmlspecialchars($flash['message'])?></div>
<?php endif; ?>

<!-- ══ منطقة الخطر: تصفير كامل + استيراد شامل تلقائي من المزود ══ -->
<div class="card" style="margin-bottom:20px;border:1.5px solid #ff445555">
  <div class="card-header" style="background:rgba(255,68,85,.08)">
    <div class="card-header-title" style="color:#ff6677"><i class="fas fa-exclamation-triangle"></i> استيراد شامل (تصفير كل الخدمات وإعادة بنائها تلقائياً من المزود)</div>
  </div>
  <div class="card-body">
    <div class="alert alert-warning" style="margin-bottom:16px;font-size:.85rem">
      ⚠️ هذا الخيار يحذف <b>كل الخدمات الحالية نهائياً من كل الأقسام</b> (بدون استثناء، من أي مزود)، ويعيد ترقيم الخدمات ليبدأ من <b>1</b> من جديد،
      ثم يجلب <b>كل</b> منتجات المزود المختار تلقائياً ويبني الأقسام والخدمات من الصفر بنفس تسميات المزود بالضبط. <b>لا يمكن التراجع عن هذه العملية.</b>
    </div>
    <form method="POST" onsubmit="return confirm('تأكيد نهائي: سيتم حذف كل الخدمات (وربما الأقسام) نهائياً واستبدالها بمنتجات المزود. متابعة؟')">
      <input type="hidden" name="full_reset_import" value="1">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
        <div class="form-group" style="margin:0">
          <label>المزود المصدر</label>
          <select name="fr_provider_id" class="form-control" required>
            <option value="">— اختر المزود —</option>
            <?php foreach($providers as $p): ?>
            <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label>نسبة الزيادة على سعر المزود (%)</label>
          <input type="number" step="0.01" name="fr_markup" class="form-control" value="0" placeholder="مثال: 10">
        </div>
        <div class="form-group" style="margin:0;display:flex;align-items:center">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0">
            <input type="checkbox" name="fr_reset_categories" value="1" style="width:auto">
            حذف كل الأقسام الحالية أيضاً وإعادة بنائها من الصفر
          </label>
        </div>
      </div>
      <div class="form-group">
        <label>للتأكيد، اكتب كلمة <b>حذف</b> بالضبط في الحقل التالي</label>
        <input type="text" name="fr_confirm" class="form-control" style="max-width:220px" placeholder="اكتب: حذف" required>
      </div>
      <button type="submit" class="btn btn-danger"><i class="fas fa-bomb"></i> تصفير واستيراد شامل الآن</button>
    </form>
  </div>
</div>

<!-- ══ الخطوة 1: اختيار المزود والإعدادات ══ -->
<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start" id="mainGrid">

  <!-- لوحة الإعدادات -->
  <div class="card" style="position:sticky;top:16px">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-cog"></i> إعدادات الاستيراد</div></div>
    <div class="card-body">

      <!-- وضع العمل -->
      <div class="form-group">
        <label>وضع العمل</label>
        <div style="display:flex;gap:6px">
          <label id="modeImportLbl" style="flex:1;display:flex;align-items:center;gap:6px;padding:8px 10px;border:1.5px solid var(--primary);background:rgba(30,111,255,.1);border-radius:8px;cursor:pointer;font-size:.82rem">
            <input type="radio" name="importMode" value="import" checked onchange="onModeChange()">
            <i class="fas fa-file-import" style="color:var(--primary)"></i> استيراد خدمات
          </label>
          <label id="modeSecondaryLbl" style="flex:1;display:flex;align-items:center;gap:6px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;cursor:pointer;font-size:.82rem">
            <input type="radio" name="importMode" value="secondary" onchange="onModeChange()">
            <i class="fas fa-plus-circle" style="color:#00d4aa"></i> مزود ثانٍ
          </label>
        </div>
      </div>

      <div class="form-group">
        <label>المزود *</label>
        <select id="selProvider" class="form-control" onchange="onProviderChange()">
          <option value="">— اختر مزوداً —</option>
          <?php foreach($providers as $p): ?>
          <option value="<?=$p['id']?>" data-type="<?=htmlspecialchars($p['provider_type'])?>">
            <?=htmlspecialchars($p['name'])?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="importOnlyFields">
      <div class="form-group">
        <label>
          <i class="fas fa-percentage" style="color:#f5a623"></i>
          نسبة الزيادة على سعر المزود
        </label>
        <div style="display:flex;align-items:center;gap:8px">
          <input type="number" id="markupInput" class="form-control" value="10" min="0" max="500" step="0.1" style="max-width:100px">
          <span style="color:var(--text3)">%</span>
          <span id="markupExample" style="font-size:.78rem;color:#00d4aa"></span>
        </div>
        <div class="form-hint">مثال: سعر المزود 1$ + 10% = 1.10$ في نظامك</div>
      </div>

      <div class="form-group">
        <label>القسم الافتراضي</label>
        <select id="defaultCat" class="form-control">
          <option value="0">— بدون قسم —</option>
          <?php foreach($allCats as $c): ?>
          <option value="<?=$c['id']?>"><?=$c['parent_id']?'  └ ':''?><?=htmlspecialchars($c['name'])?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">يُطبَّق على الخدمات التي لم يُحدَّد لها قسم</div>
      </div>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 10px;border:1px solid rgba(245,166,35,.3);border-radius:8px">
          <input type="checkbox" id="updateExisting" checked>
          <span style="color:#f5a623">تحديث الخدمات الموجودة مسبقاً</span>
        </label>
      </div>

      </div><!-- /importOnlyFields -->

      <button onclick="fetchServices()" id="fetchBtn" class="btn btn-primary" style="width:100%" disabled>
        <i class="fas fa-cloud-download-alt"></i> جلب الخدمات
      </button>
    </div>
  </div>

  <!-- قائمة الخدمات -->
  <div>
    <!-- إحصاءات -->
    <div id="statsBar" style="display:none;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:12px;display:none;flex-wrap:wrap;gap:16px">
      <div><span style="font-size:1.4rem;font-weight:900;color:var(--primary)" id="statTotal">0</span><div style="font-size:.72rem;color:var(--text3)">إجمالي</div></div>
      <div><span style="font-size:1.4rem;font-weight:900;color:#00e676" id="statNew">0</span><div style="font-size:.72rem;color:var(--text3)">جديدة</div></div>
      <div><span style="font-size:1.4rem;font-weight:900;color:#f5a623" id="statExist">0</span><div style="font-size:.72rem;color:var(--text3)">موجودة</div></div>
      <div><span style="font-size:1.4rem;font-weight:900;color:#00d4ff" id="statSelected">0</span><div style="font-size:.72rem;color:var(--text3)">محدد</div></div>
    </div>

    <!-- شريط البحث والتحكم -->
    <div id="controlBar" style="display:none;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:12px;display:none;flex-wrap:wrap;gap:8px;align-items:center">
      <input type="text" id="svcSearch" placeholder="🔍 بحث بالاسم أو القسم..." oninput="filterServices()"
             style="flex:1;min-width:200px;background:var(--bg);border:1.5px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none">
      <select id="filterStatus" onchange="filterServices()" class="form-control" style="width:auto">
        <option value="">الكل</option>
        <option value="new">جديدة فقط</option>
        <option value="exists">موجودة فقط</option>
      </select>
      <div style="display:flex;gap:6px">
        <button onclick="selectAll(true)"  class="btn btn-secondary btn-sm"><i class="fas fa-check-double"></i> تحديد الكل</button>
        <button onclick="selectAll(false)" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> إلغاء الكل</button>
        <button onclick="selectNewOnly()"  class="btn btn-secondary btn-sm" style="color:#00e676"><i class="fas fa-plus"></i> الجديدة فقط</button>
      </div>
    </div>

    <!-- جدول الخدمات -->
    <div class="card" id="servicesCard" style="display:none">
      <div class="card-header" style="flex-wrap:wrap;gap:8px">
        <div class="card-header-title"><i class="fas fa-list"></i> الخدمات المجلوبة</div>
        <div style="display:flex;gap:8px;margin-right:auto;flex-wrap:wrap">
          <button onclick="doImport()" id="importBtn" class="btn btn-success">
            <i class="fas fa-file-import"></i> استيراد المحددة (<span id="importCount">0</span>)
          </button>
          <button onclick="doSecondary()" id="secondaryBtn" class="btn btn-secondary" style="display:none">
            <i class="fas fa-plus-circle" style="color:#00d4aa"></i> إضافة كمزود ثانٍ (<span id="importCount2">0</span>)
          </button>
        </div>
      </div>
      <div class="table-wrap" style="max-height:600px;overflow-y:auto;overflow-x:auto">
        <table id="svcTable">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="checkAllSvc" onchange="toggleAll(this)"></th>
              <th>ID المزود</th>
              <th>اسم الخدمة</th>
              <th>القسم عند المزود</th>
              <th>قسمنا</th>
              <th style="text-align:center">سعر المزود</th>
              <th style="text-align:center">سعرنا</th>
              <th style="text-align:center">الكمية</th>
              <th style="text-align:center">الحالة</th>
            </tr>
          </thead>
          <tbody id="svcBody"></tbody>
        </table>
      </div>
      <!-- Pagination -->
      <div id="pagination" style="padding:10px 16px;border-top:1px solid var(--border)"></div>
    </div>

    <!-- فورم الاستيراد المخفي -->
    <form method="POST" id="importForm" style="display:none">
        <?= adminCsrfField() ?>
      <input type="hidden" name="do_import" value="1">
      <input type="hidden" name="provider_id" id="fProviderId">
      <input type="hidden" name="markup" id="fMarkup">
      <input type="hidden" name="default_cat" id="fDefaultCat">
      <input type="hidden" name="items_json" id="fItemsJson">
      <input type="hidden" name="cat_map" id="fCatMap">
    </form>

    <!-- فورم المزود الثاني -->
    <form method="POST" id="secondaryForm" style="display:none">
        <?= adminCsrfField() ?>
      <input type="hidden" name="add_as_secondary" value="1">
      <input type="hidden" name="provider_id" id="fProviderId2">
      <input type="hidden" name="items_json" id="fItemsJson2">
    </form>

    <div id="emptyState" style="text-align:center;padding:4rem;color:var(--text3)">
      <i class="fas fa-cloud-download-alt" style="font-size:3rem;opacity:.2;display:block;margin-bottom:16px"></i>
      اختر مزوداً واضغط "جلب الخدمات"
    </div>
  </div>
</div>

<script>
var ALL_SERVICES = [];
var CATS = <?= json_encode(array_map(function($c){ return ['id'=>$c['id'],'name'=>$c['name'],'parent'=>$c['parent_id']]; }, $allCats), JSON_UNESCAPED_UNICODE) ?>;

function onProviderChange() {
  document.getElementById('fetchBtn').disabled = !document.getElementById('selProvider').value;
  ALL_SERVICES = [];
  document.getElementById('servicesCard').style.display = 'none';
  document.getElementById('statsBar').style.display     = 'none';
  document.getElementById('controlBar').style.display   = 'none';
  document.getElementById('emptyState').style.display   = 'block';
}

// حساب سعرنا
function ourPrice(provPrice) {
  var m = parseFloat(document.getElementById('markupInput').value) || 0;
  var result = provPrice * (1 + m/100);
  for (var d = 0; d <= 8; d++) {
    if (parseFloat(result.toFixed(d)) === result) return result.toFixed(d);
  }
  return parseFloat(result.toFixed(6)).toString();
}

// تنسيق السعر للعرض مع تجنب أخطاء الفاصلة العائمة
function fmtProvPrice(price) {
  if (!price && price !== 0) return '—';
  var n = parseFloat(price);
  if (!isFinite(n)) return '—';
  // نبحث عن أقل عدد خانات عشرية تعيد نفس القيمة بدقة
  for (var d = 0; d <= 10; d++) {
    if (parseFloat(n.toFixed(d)) === n) return n.toFixed(d);
  }
  return parseFloat(n.toFixed(8)).toString();
}

// تحديث مثال الزيادة
document.getElementById('markupInput').addEventListener('input', function() {
  var m = parseFloat(this.value) || 0;
  var ex = fmtProvPrice(1 * (1+m/100));
  document.getElementById('markupExample').textContent = '1$ → ' + ex + '$';
  // تحديث أسعارنا في الجدول
  document.querySelectorAll('[data-prov-price]').forEach(function(el) {
    el.textContent = ourPrice(parseFloat(el.dataset.provPrice)) + ' $';
  });
});

async function fetchServices() {
  var btn  = document.getElementById('fetchBtn');
  var pid  = document.getElementById('selProvider').value;
  if (!pid) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الجلب...';
  document.getElementById('emptyState').innerHTML =
    '<i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--primary);display:block;margin-bottom:12px"></i>جارٍ جلب الخدمات...';
  document.getElementById('emptyState').style.display = 'block';

  try {
    var res  = await fetch('?fetch_json=1&provider_id=' + pid, {credentials:'same-origin'});
    var data = await res.json();
    if (!data.ok) throw new Error(data.error || 'فشل الجلب');

    ALL_SERVICES = data.services;
    _selIdx = {}; // إعادة تعيين التحديد
    renderTable();
    updateStats();

    document.getElementById('servicesCard').style.display = 'block';
    document.getElementById('statsBar').style.display     = 'flex';
    document.getElementById('controlBar').style.display   = 'flex';
    document.getElementById('emptyState').style.display   = 'none';

  } catch(e) {
    document.getElementById('emptyState').innerHTML =
      '<i class="fas fa-exclamation-circle" style="color:#ff4455;font-size:2rem;display:block;margin-bottom:12px"></i>' + e.message;
    document.getElementById('emptyState').style.display = 'block';
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-sync-alt"></i> إعادة الجلب';
}

// خريطة أقسام تلقائية بالاسم
var catMap = {};
function guessCat(catName) {
  if (!catName) return 0;
  if (catMap[catName]) return catMap[catName];
  // بحث تطابق
  var cn = catName.toLowerCase();
  for (var i=0; i<CATS.length; i++) {
    if (CATS[i].name.toLowerCase() === cn) { catMap[catName]=CATS[i].id; return CATS[i].id; }
  }
  return parseInt(document.getElementById('defaultCat').value) || 0;
}

var PAGE_SIZE = 50;
var currentPage = 1;
var filteredIndices = [];

function renderTable() {
  var q  = (document.getElementById('svcSearch')?.value||'').toLowerCase();
  var st = document.getElementById('filterStatus')?.value || '';

  // بناء قائمة الفلترة
  filteredIndices = [];
  ALL_SERVICES.forEach(function(svc, i) {
    var nameMatch = !q || svc.name.toLowerCase().includes(q) || (svc.category||'').toLowerCase().includes(q);
    var stMatch   = !st || (st==='new'&&!svc.exists) || (st==='exists'&&svc.exists);
    if (nameMatch && stMatch) filteredIndices.push(i);
  });

  currentPage = 1;
  renderPage();
}

function renderPage() {
  var body = document.getElementById('svcBody');
  body.innerHTML = '';

  var start = (currentPage-1)*PAGE_SIZE;
  var end   = Math.min(start+PAGE_SIZE, filteredIndices.length);
  var pageIndices = filteredIndices.slice(start, end);

  // بناء خيارات الأقسام مرة واحدة
  var catOptionsBase = '<option value="0">— بدون —</option>';
  CATS.forEach(function(c){ catOptionsBase += '<option value="'+c.id+'">'+(c.parent?'  └ ':'')+c.name+'</option>'; });

  pageIndices.forEach(function(i) {
    var svc    = ALL_SERVICES[i];
    var catId  = guessCat(svc.category);
    var op     = ourPrice(svc.price);
    var checked= (_selIdx[i] !== undefined) ? true : !svc.exists;
    // تسجيل التحديد الافتراضي
    if (!svc.exists && _selIdx[i] === undefined) _selIdx[i] = {};

    var catOpts = catOptionsBase.replace(
      'value="'+catId+'"',
      'value="'+catId+'" selected'
    );

    var tr = document.createElement('tr');
    tr.dataset.idx = i;
    tr.style.opacity    = svc.available===false ? '.5' : '';
    tr.style.background = svc.exists ? 'rgba(245,166,35,.04)' : '';
    tr.innerHTML =
      '<td><input type="checkbox" class="svc-chk" data-idx="'+i+'" '+(checked?'checked':'')+' onchange="onChkChange(this)"></td>'
      +'<td><code style="font-size:.75rem">'+svc.id+'</code></td>'
      +'<td>'
        +'<input type="text" class="svc-name form-control" data-idx="'+i+'" value="'+escHtml(svc.name)+'" style="font-size:.8rem;padding:4px 8px;min-width:120px">'
        +(svc.exists?'<div style="font-size:.65rem;color:#f5a623">⚠ موجودة</div>':'')
      +'</td>'
      +'<td><small style="color:var(--text3)">'+escHtml(svc.category||'—')+'</small></td>'
      +'<td><select class="svc-cat form-control" data-idx="'+i+'" style="font-size:.78rem;padding:3px 6px">'+catOpts+'</select></td>'
      +'<td style="text-align:center;color:var(--text3);font-size:.78rem;font-family:monospace">'+fmtProvPrice(svc.price)+'$</td>'
      +'<td><input type="number" class="svc-price form-control" data-idx="'+i+'" data-prov-price="'+svc.price+'" value="'+op+'" step="any" min="0" style="font-size:.78rem;padding:3px 6px;width:110px;text-align:center;font-family:monospace"></td>'
      +'<td style="text-align:center;font-size:.72rem">'+(svc.min_qty||1)+'–'+(svc.max_qty||'∞')+'</td>'
      +'<td style="text-align:center">'+(svc.available===false?'<span style="color:#ff4455">✗</span>':'<span style="color:#00d4aa">✓</span>')+'</td>';
    body.appendChild(tr);
  });

  // Pagination
  renderPagination();
  updateStats();
}

function renderPagination() {
  var total = filteredIndices.length;
  var pages = Math.ceil(total / PAGE_SIZE);
  var el = document.getElementById('pagination');
  if (!el) return;
  if (pages <= 1) { el.innerHTML = '<span style="font-size:.75rem;color:var(--text3)">'+total+' نتيجة</span>'; return; }

  var html = '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
  html += '<span style="font-size:.75rem;color:var(--text3)">'
    + ((currentPage-1)*PAGE_SIZE+1)+'-'+Math.min(currentPage*PAGE_SIZE,total)+' من '+total+'</span>';
  html += '<button onclick="goPage('+(currentPage-1)+')" '+(currentPage===1?'disabled':'')+' class="btn btn-xs btn-secondary">‹ السابق</button>';

  var start = Math.max(1, currentPage-2);
  var end   = Math.min(pages, start+4);
  for (var p=start; p<=end; p++) {
    html += '<button onclick="goPage('+p+')" class="btn btn-xs '+(p===currentPage?'btn-primary':'btn-secondary')+'">'+p+'</button>';
  }
  html += '<button onclick="goPage('+(currentPage+1)+')" '+(currentPage===pages?'disabled':'')+' class="btn btn-xs btn-secondary">التالي ›</button>';
  html += '</div>';
  el.innerHTML = html;
}

function goPage(p) {
  var pages = Math.ceil(filteredIndices.length / PAGE_SIZE);
  if (p<1||p>pages) return;
  currentPage = p;
  renderPage();
  document.getElementById('svcTable').scrollIntoView({behavior:'smooth',block:'start'});
}

function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function filterServices() { renderTable(); }

// مصفوفة التحديد (مستقلة عن الـ pagination)
var _selIdx = {};

function selectAll(v) {
  filteredIndices.forEach(function(i) {
    if (v) _selIdx[i] = _selIdx[i] || {};
    else delete _selIdx[i];
  });
  document.querySelectorAll('.svc-chk').forEach(function(cb){
    cb.checked = !!_selIdx[parseInt(cb.dataset.idx)];
  });
  updateStats();
}

function selectNewOnly() {
  _selIdx = {};
  filteredIndices.forEach(function(i){
    if (!ALL_SERVICES[i].exists) _selIdx[i] = {};
  });
  document.querySelectorAll('.svc-chk').forEach(function(cb){
    cb.checked = !!_selIdx[parseInt(cb.dataset.idx)];
  });
  updateStats();
}

function toggleAll(master) { selectAll(master.checked); }

function onChkChange(cb) {
  var idx = parseInt(cb.dataset.idx);
  if (cb.checked) _selIdx[idx] = _selIdx[idx] || {};
  else delete _selIdx[idx];
  updateStats();
}

function updateStats() {
  var total    = ALL_SERVICES.length;
  var newCount = ALL_SERVICES.filter(function(s){ return !s.exists; }).length;
  var exist    = ALL_SERVICES.filter(function(s){ return s.exists; }).length;
  var sel      = Object.keys(_selIdx).length;
  document.getElementById('statTotal').textContent    = total;
  document.getElementById('statNew').textContent      = newCount;
  document.getElementById('statExist').textContent    = exist;
  document.getElementById('statSelected').textContent = sel;
  document.getElementById('importCount').textContent   = sel;
}

function doImport() {
  var selCount = Object.keys(_selIdx).length;
  if (!selCount) { alert('لم تحدد أي خدمة'); return; }
  var updateExisting = document.getElementById('updateExisting').checked;
  var items = []; var catMapObj = {};

  // حفظ تعديلات الصفحة الحالية قبل الإرسال
  document.querySelectorAll('.svc-name').forEach(function(el){
    var i=parseInt(el.dataset.idx); if(_selIdx[i]!==undefined) _selIdx[i].name=el.value;
  });
  document.querySelectorAll('.svc-cat').forEach(function(el){
    var i=parseInt(el.dataset.idx); if(_selIdx[i]!==undefined) _selIdx[i].catId=parseInt(el.value)||0;
  });
  document.querySelectorAll('.svc-price').forEach(function(el){
    var i=parseInt(el.dataset.idx); if(_selIdx[i]!==undefined) _selIdx[i].price=el.value;
  });

  var defCat = parseInt(document.getElementById('defaultCat').value) || 0;

  Object.keys(_selIdx).forEach(function(idxStr) {
    var idx = parseInt(idxStr);
    var svc = ALL_SERVICES[idx];
    if (!svc) return;
    var name  = _selIdx[idx].name  || svc.name;
    var catId = _selIdx[idx].catId || guessCat(svc.category) || defCat;
    var price = _selIdx[idx].price || ourPrice(svc.price);
    if (svc.category) catMapObj[svc.category] = catId;
    items.push({
      provider_service_id: svc.id,
      name: name, category: svc.category, cat_id: catId, price: String(price),
      min_qty: svc.min_qty||1, max_qty: svc.max_qty||9999,
      params: svc.params||[], update_existing: updateExisting?1:0,
    });
  });

  if (!confirm('استيراد ' + items.length + ' خدمة؟')) return;

  document.getElementById('fProviderId').value = document.getElementById('selProvider').value;
  document.getElementById('fMarkup').value      = 0; // السعر مُطبَّق مسبقاً
  document.getElementById('fDefaultCat').value  = document.getElementById('defaultCat').value;
  document.getElementById('fItemsJson').value   = JSON.stringify(items);
  document.getElementById('fCatMap').value      = JSON.stringify(catMapObj);
  document.getElementById('importForm').submit();
}

// مثال زيادة عند التحميل
document.getElementById('markupInput').dispatchEvent(new Event('input'));

// ══ تبديل الوضع (استيراد / مزود ثانٍ) ══════════════════════
var _importMode = 'import';

function onModeChange() {
  _importMode = document.querySelector('input[name=importMode]:checked').value;
  var isSecondary = (_importMode === 'secondary');

  // إخفاء/إظهار حقول الاستيراد
  var fields = document.getElementById('importOnlyFields');
  if (fields) fields.style.display = isSecondary ? 'none' : 'block';

  // تحديث أزرار الجدول
  var importBtn    = document.getElementById('importBtn');
  var secondaryBtn = document.getElementById('secondaryBtn');
  if (importBtn)    importBtn.style.display    = isSecondary ? 'none'  : '';
  if (secondaryBtn) secondaryBtn.style.display = isSecondary ? ''      : 'none';

  // تحديث تسمية الوضع
  document.getElementById('modeImportLbl').style.borderColor    = isSecondary ? 'var(--border)'  : 'var(--primary)';
  document.getElementById('modeImportLbl').style.background     = isSecondary ? ''               : 'rgba(30,111,255,.1)';
  document.getElementById('modeSecondaryLbl').style.borderColor = isSecondary ? '#00d4aa'        : 'var(--border)';
  document.getElementById('modeSecondaryLbl').style.background  = isSecondary ? 'rgba(0,212,170,.08)' : '';

  // إعادة تعيين الخدمات
  ALL_SERVICES = []; _selIdx = {};
  document.getElementById('servicesCard').style.display = 'none';
  document.getElementById('statsBar').style.display     = 'none';
  document.getElementById('controlBar').style.display   = 'none';
  document.getElementById('emptyState').style.display   = 'block';
  document.getElementById('emptyState').innerHTML =
    '<i class="fas fa-'+(isSecondary?'plus-circle':'cloud-download-alt')+'" style="font-size:3rem;opacity:.2;display:block;margin-bottom:16px"></i>'
    + (isSecondary ? 'اختر مزوداً واضغط "جلب الخدمات" — سيتم ربط الخدمات المحددة كمزود ثانٍ' : 'اختر مزوداً واضغط "جلب الخدمات"');
}

// ══ تنفيذ الإضافة كمزود ثانٍ ══════════════════════════════
function doSecondary() {
  var selCount = Object.keys(_selIdx).length;
  if (!selCount) { alert('لم تحدد أي خدمة'); return; }

  // نرسل provider_service_id واسم الخدمة فقط
  var items = [];
  Object.keys(_selIdx).forEach(function(idxStr) {
    var idx = parseInt(idxStr);
    var svc = ALL_SERVICES[idx];
    if (!svc) return;
    items.push({
      provider_service_id: svc.id,
      name: svc.name,
    });
  });

  if (!confirm('إضافة ' + items.length + ' خدمة كمزود ثانٍ؟')) return;

  document.getElementById('fProviderId2').value  = document.getElementById('selProvider').value;
  document.getElementById('fItemsJson2').value   = JSON.stringify(items);
  document.getElementById('secondaryForm').submit();
}
</script>

<style>
@media(max-width:900px){
  #mainGrid{grid-template-columns:1fr !important}
  .svc-name{min-width:120px !important}
  #svcTable th:nth-child(4),
  #svcTable td:nth-child(4),
  #svcTable th:nth-child(8),
  #svcTable td:nth-child(8),
  #svcTable th:nth-child(9),
  #svcTable td:nth-child(9){display:none}
}
</style>
<?php include 'footer.php'; ?>
