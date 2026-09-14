<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_providers_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'مزودو API - ' . SITE_NAME;

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ── إنشاء جدول provider_fields تلقائياً ──────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `provider_fields` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `provider_id`   INT NOT NULL,
  `field_key`     VARCHAR(80)  NOT NULL,
  `field_label`   VARCHAR(150) NOT NULL,
  `field_type`    ENUM('text','number','select','textarea','checkbox') DEFAULT 'text',
  `field_options` TEXT DEFAULT NULL,
  `placeholder`   VARCHAR(200) DEFAULT NULL,
  `is_required`   TINYINT(1) DEFAULT 1,
  `is_enabled`    TINYINT(1) DEFAULT 1,
  `sort_order`    INT DEFAULT 0,
  KEY `idx_provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── حذف حقل ──────────────────────────────────────────────────
if ($action === 'delete_field' && $id) {
    $pdo->prepare("DELETE FROM provider_fields WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── تبديل تفعيل حقل ─────────────────────────────────────────
if ($action === 'toggle_field' && $id) {
    $pdo->prepare("UPDATE provider_fields SET is_enabled = 1-is_enabled WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── AJAX جلب حقول مزود ──────────────────────────────────────
if ($action === 'get_fields' && $id) {
    $fields = $pdo->prepare("SELECT * FROM provider_fields WHERE provider_id=? ORDER BY sort_order,id");
    $fields->execute([$id]);
    echo json_encode(['ok'=>true,'fields'=>$fields->fetchAll()]); exit;
}

// ── حفظ حقول مزود (AJAX) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_provider_fields'])) {
    $pid = (int)$_POST['provider_id'];
    if (!$pid) { echo json_encode(['ok'=>false,'msg'=>'provider_id مطلوب']); exit; }

    $keys     = $_POST['pf_key']       ?? [];
    $labels   = $_POST['pf_label']     ?? [];
    $types    = $_POST['pf_type']      ?? [];
    $opts     = $_POST['pf_options']   ?? [];
    $plachs   = $_POST['pf_placeholder'] ?? [];
    $reqs     = $_POST['pf_required']  ?? [];
    $enabls   = $_POST['pf_enabled']   ?? [];
    $sorts    = $_POST['pf_sort']      ?? [];
    $fieldIds = $_POST['pf_id']        ?? [];

    // احذف الحقول المحذوفة (IDs المرسلة = المتبقية)
    $keepIds = array_filter(array_map('intval', $fieldIds));
    if (!empty($keepIds)) {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM provider_fields WHERE provider_id=? AND id NOT IN ($ph)")
            ->execute(array_merge([$pid], $keepIds));
    } else {
        $pdo->prepare("DELETE FROM provider_fields WHERE provider_id=?")->execute([$pid]);
    }

    foreach ($keys as $i => $key) {
        $key   = trim($key);
        $label = trim($labels[$i] ?? '');
        if (!$key || !$label) continue;
        $type  = $types[$i]   ?? 'text';
        $opt   = trim($opts[$i]   ?? '');
        $plach = trim($plachs[$i] ?? '');
        $req   = isset($reqs[$i])   ? 1 : 0;
        $en    = isset($enabls[$i]) ? 1 : 0;
        $sort  = (int)($sorts[$i]  ?? $i);
        $fid   = (int)($fieldIds[$i] ?? 0);

        if ($fid) {
            $pdo->prepare("UPDATE provider_fields SET field_key=?,field_label=?,field_type=?,field_options=?,placeholder=?,is_required=?,is_enabled=?,sort_order=? WHERE id=? AND provider_id=?")
                ->execute([$key,$label,$type,$opt,$plach,$req,$en,$sort,$fid,$pid]);
        } else {
            $pdo->prepare("INSERT INTO provider_fields (provider_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$pid,$key,$label,$type,$opt,$plach,$req,$en,$sort]);
        }
    }

    flashMessage('success','✅ تم حفظ الحقول الديناميكية');
    redirect(SITE_URL.'/admin/providers.php?action=fields&id='.$pid);
}

function headerApiRequest($baseUrl, $apiKey, $endpoint, $method = 'GET', $params = []) {
    $url = rtrim($baseUrl, '/') . $endpoint;
    if ($method === 'GET' && !empty($params)) $url .= '?' . http_build_query($params);
    $headers = ['api-token: ' . $apiKey, 'Accept: application/json'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['error'=>$err];
    return json_decode($res, true) ?? ['error'=>'invalid_json'];
}

function numbersappRequest($baseUrl, $apiKey, $endpoint, $params = []) {
    $params['api_key'] = $apiKey;
    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['error'=>$err];
    return json_decode($res, true) ?? ['error'=>'invalid_json'];
}

function smmRequest($apiUrl, $apiKey, $postData) {
    $postData['key'] = $apiKey;
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($postData), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM providers WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف المزود');
    redirect(SITE_URL . '/admin/providers.php');
}

if ($action === 'balance' && $id) {
    $prov = $pdo->prepare("SELECT * FROM providers WHERE id=?"); $prov->execute([$id]); $prov = $prov->fetch();
    if ($prov) {
        $balance = null;
        if ($prov['provider_type'] === 'numbersapp') {
            // NumbersApp لا يوفر endpoint للرصيد — نختبر الاتصال فقط
            $data = numbersappRequest($prov['api_url'], $prov['api_key'], 'getLiveSections');
            if (is_array($data) && !isset($data['error'])) {
                $balance = $prov['balance']; // نحتفظ بالرصيد الحالي
                flashMessage('info', '✅ الاتصال بـ NumbersApp ناجح (هذا المزود لا يوفر endpoint رصيد)');
            }
        } elseif (in_array($prov['provider_type'], ['oranos','ap4stor'])) {
            $data = headerApiRequest($prov['api_url'], $prov['api_key'], '/client/api/profile');
            if (isset($data['balance'])) $balance = $data['balance'];
        } else {
            $data = smmRequest($prov['api_url'], $prov['api_key'], ['action'=>'balance']);
            if (isset($data['balance'])) $balance = $data['balance'];
        }
        if ($balance !== null) {
            $pdo->prepare("UPDATE providers SET balance=?,last_check=NOW() WHERE id=?")->execute([$balance, $id]);
            flashMessage('success', 'الرصيد: ' . number_format($balance, 3));
        } else {
            flashMessage('danger', 'فشل جلب الرصيد - تحقق من الـ Token');
        }
    }
    redirect(SITE_URL . '/admin/providers.php');
}

$providerProducts = null; $fetchedProvider = null;
if ($action === 'fetch' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM providers WHERE id=?"); $stmt->execute([$id]); $fetchedProvider = $stmt->fetch();
    if ($fetchedProvider) {
        if ($fetchedProvider['provider_type'] === 'numbersapp') {
            // ── جلب خدمات NumbersApp من 3 أقسام ─────────────────
            $allProducts = [];
            $apiUrl = $fetchedProvider['api_url'];
            $apiKey = $fetchedProvider['api_key'];

            // 1) أقسام المباشر (Live) — كل خدمة × كل دولة = منتج منفصل
            $liveSections = numbersappRequest($apiUrl, $apiKey, 'getLiveSections');
            if (is_array($liveSections) && !isset($liveSections['error'])) {
                if (isset($liveSections['Title'])) $liveSections = [$liveSections];
                foreach ($liveSections as $ls) {
                    $svcCode = $ls['Service'] ?? '';
                    $svcTitle = $ls['Title'] ?? 'خدمة مباشرة';
                    // جلب الدول لكل خدمة
                    $countries = numbersappRequest($apiUrl, $apiKey, 'getServiceCountries', ['service' => $svcCode]);
                    if (is_array($countries) && !isset($countries['error'])) {
                        if (isset($countries['Title'])) $countries = [$countries];
                        foreach ($countries as $co) {
                            $countryTitle = $co['Title'] ?? '';
                            $countryCode  = $co['CountryCode'] ?? '';
                            $serverNum    = $co['ServerNumber'] ?? 0;
                            $allProducts[] = [
                                'id'       => "live:{$svcCode}:{$countryCode}:{$serverNum}",
                                'name'     => "{$svcTitle} — {$countryTitle}",
                                'category' => '📱 المباشر (Live)',
                                'price'    => $co['Price'] ?? '—',
                                'min'      => 1, 'max' => 1,
                                'params'   => [],
                                'na_type'  => 'live',
                                'na_service' => $svcCode,
                                'na_country' => $countryCode,
                                'na_server'  => $serverNum,
                            ];
                        }
                    }
                }
            }

            // 2) أقسام الباقات (Packs)
            $packSections = numbersappRequest($apiUrl, $apiKey, 'getPacksSections');
            if (is_array($packSections) && !isset($packSections['error'])) {
                foreach ($packSections as $ps) {
                    $sId = $ps['Id'] ?? '';
                    $packs = numbersappRequest($apiUrl, $apiKey, 'getAvailablePacks', ['sectionId'=>$sId]);
                    if (is_array($packs) && isset($packs['Packs'])) {
                        foreach ($packs['Packs'] as $pk) {
                            $isCount = ($packs['Type'] ?? '') === 'byCount';
                            $allProducts[] = [
                                'id'       => 'pack:' . ($pk['packId'] ?? ''),
                                'name'     => ($ps['Title']??'') . ' — ' . ($pk['Title'] ?? ''),
                                'category' => '📦 الباقات (Packs)',
                                'price'    => $pk['Price'] ?? '—',
                                'min'      => $isCount ? ($pk['MinCount']??1) : 1,
                                'max'      => $isCount ? ($pk['MaxCount']??1) : 1,
                                'params'   => $packs['Inputs'] ?? [],
                                'na_type'  => $isCount ? 'pack_count' : 'pack_fixed',
                                'na_pack_id' => $pk['packId'] ?? '',
                                'na_section' => $sId,
                                'na_inputs'  => json_encode($packs['Inputs'] ?? []),
                            ];
                        }
                    }
                }
            }

            // 3) أقسام المخزن (Store)
            $storeSections = numbersappRequest($apiUrl, $apiKey, 'getStoredSections');
            if (is_array($storeSections) && !isset($storeSections['error'])) {
                foreach ($storeSections as $ss) {
                    $sId = $ss['sectionId'] ?? '';
                    $prods = numbersappRequest($apiUrl, $apiKey, 'getStoredSectionProducts', ['sectionId'=>$sId]);
                    if (is_array($prods) && isset($prods['Products'])) {
                        foreach ($prods['Products'] as $sp) {
                            $allProducts[] = [
                                'id'       => 'store:' . ($sp['ProductId'] ?? ''),
                                'name'     => ($ss['Title']??'') . ' — ' . ($sp['Title'] ?? ''),
                                'category' => '🏪 المخزن (Store)',
                                'price'    => $sp['Price'] ?? '—',
                                'min'      => 1, 'max' => 9999,
                                'params'   => [],
                                'na_type'  => 'store',
                                'na_product_id' => $sp['ProductId'] ?? '',
                                'available' => $sp['Available'] ?? false,
                            ];
                        }
                    }
                }
            }

            if (!empty($allProducts)) $providerProducts = $allProducts;
            else flashMessage('danger', 'فشل جلب خدمات NumbersApp — تحقق من الـ API Key');

        } elseif (in_array($fetchedProvider['provider_type'], ['oranos','ap4stor'])) {
            $products = headerApiRequest($fetchedProvider['api_url'], $fetchedProvider['api_key'], '/client/api/products');
            if (is_array($products) && !isset($products['error'])) $providerProducts = $products;
            else flashMessage('danger', 'خطأ في الاتصال: ' . ($products['error'] ?? 'تأكد من رابط الـ API والـ Token'));
        } else {
            $res = smmRequest($fetchedProvider['api_url'], $fetchedProvider['api_key'], ['action'=>'services']);
            $providerProducts = is_array($res) ? $res : null;
            if (!$providerProducts) flashMessage('danger', 'فشل جلب الخدمات');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_provider'])) {
    $name = trim($_POST['name']);
    $providerType = $_POST['provider_type'];
    $apiUrl = rtrim(trim($_POST['api_url'] ?? ''), '/');
    $apiKey = trim($_POST['api_key']);
    if (empty($apiUrl)) { flashMessage('danger','رابط API مطلوب'); redirect(SITE_URL.'/admin/providers.php?action='.($id?'edit':'add').($id?'&id='.$id:'')); }
    if ($id) {
        $pdo->prepare("UPDATE providers SET name=?,api_url=?,api_key=?,provider_type=? WHERE id=?")->execute([$name,$apiUrl,$apiKey,$providerType,$id]);
    } else {
        $pdo->prepare("INSERT INTO providers (name,api_url,api_key,provider_type) VALUES (?,?,?,?)")->execute([$name,$apiUrl,$apiKey,$providerType]);
    }
    flashMessage('success', 'تم حفظ المزود');
    redirect(SITE_URL . '/admin/providers.php');
}

$providers = $pdo->query("SELECT * FROM providers ORDER BY created_at DESC")->fetchAll();
$editProv = null;
if ($id && in_array($action,['edit','add','fields'])) { $s=$pdo->prepare("SELECT * FROM providers WHERE id=?"); $s->execute([$id]); $editProv=$s->fetch(); }
$providerFields = [];
if ($action === 'fields' && $id) {
    $pf = $pdo->prepare("SELECT * FROM provider_fields WHERE provider_id=? ORDER BY sort_order,id");
    $pf->execute([$id]); $providerFields = $pf->fetchAll();
}

include 'header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-plug"></i> مزودو API</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="provider_linkages.php" class="btn btn-secondary"><i class="fas fa-project-diagram"></i> ربطيات المزودين</a><a href="?action=add" class="btn btn-primary"><i class="fas fa-plus"></i> مزود جديد</a></div>
</div>

<?php if ($action==='fields' && $editProv): ?>
<div class="page-header" style="margin-bottom:1rem">
  <div>
    <h2><i class="fas fa-sliders-h" style="color:#8b5cf6"></i> الحقول الديناميكية</h2>
    <div style="font-size:.85rem;color:#8895a7;margin-top:3px">
      المزود: <strong style="color:#fff"><?=htmlspecialchars($editProv['name'])?></strong>
      — هذه الحقول ستظهر للعميل قبل تنفيذ الطلب
    </div>
  </div>
  <a href="providers.php" class="btn btn-secondary"><i class="fas fa-arrow-right"></i> رجوع</a>
</div>

<div class="card mb-2">
  <form method="POST" id="fieldsForm">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_provider_fields" value="1">
    <input type="hidden" name="provider_id" value="<?=$editProv['id']?>">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
      <h3 style="margin:0"><i class="fas fa-list-ul"></i> الحقول (<?=count($providerFields)?>)</h3>
      <button type="button" onclick="addFieldRow()" class="btn btn-primary btn-sm">
        <i class="fas fa-plus"></i> إضافة حقل
      </button>
    </div>

    <div id="fieldsContainer">
      <?php if(empty($providerFields)): ?>
      <div id="emptyMsg" style="text-align:center;padding:2.5rem;color:#8895a7;border:2px dashed rgba(255,255,255,.08);border-radius:12px">
        <i class="fas fa-layer-group" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:12px"></i>
        لا توجد حقول بعد — اضغط "إضافة حقل" لبدء التخصيص
      </div>
      <?php else: ?>
      <?php foreach($providerFields as $fi => $pf): ?>
      <?php
        $opts = $pf['field_options'] ? json_decode($pf['field_options'],true) : [];
        $optsText = '';
        if(is_array($opts)) {
          $optsText = implode("
", array_map(fn($o)=>($o['label']??$o['value']??'').'|'.($o['value']??''), $opts));
        }
      ?>
      <div class="field-row" data-index="<?=$fi?>" style="background:var(--bg3,#161b2e);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:18px;margin-bottom:12px;position:relative">
        <input type="hidden" name="pf_id[]" value="<?=$pf['id']?>">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
          <span class="drag-handle" style="cursor:grab;color:#444;font-size:1.2rem;padding:0 4px">⠿</span>
          <strong style="font-size:.9rem;flex:1" class="field-preview-label"><?=htmlspecialchars($pf['field_label'])?></strong>
          <span class="badge" style="background:<?=$pf['is_enabled']?'rgba(0,212,170,.15)':'rgba(255,68,85,.15)'?>;color:<?=$pf['is_enabled']?'#00d4aa':'#ff4455'?>;font-size:.7rem">
            <?=$pf['is_enabled']?'مفعّل':'معطّل'?>
          </span>
          <button type="button" onclick="removeField(this)" style="background:rgba(255,68,85,.15);border:1px solid rgba(255,68,85,.2);color:#ff4455;border-radius:8px;padding:4px 10px;cursor:pointer;font-size:.8rem">
            <i class="fas fa-trash"></i>
          </button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">المفتاح (key للـ API)*</label>
            <input type="text" name="pf_key[]" value="<?=htmlspecialchars($pf['field_key'])?>" required
                   placeholder="subscriber_number" class="form-control"
                   style="font-family:monospace;font-size:.85rem">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">التسمية للعميل*</label>
            <input type="text" name="pf_label[]" value="<?=htmlspecialchars($pf['field_label'])?>" required
                   placeholder="رقم المشترك" class="form-control"
                   oninput="this.closest('.field-row').querySelector('.field-preview-label').textContent=this.value||'حقل جديد'">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">نوع الحقل</label>
            <select name="pf_type[]" class="form-control field-type-select" onchange="onTypeChange(this)">
              <?php foreach(['text'=>'📝 نص','number'=>'🔢 رقم','select'=>'📋 قائمة','textarea'=>'📄 نص طويل','checkbox'=>'☑️ مربع تأكيد'] as $tv=>$tl): ?>
              <option value="<?=$tv?>" <?=$pf['field_type']===$tv?'selected':''?>><?=$tl?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">Placeholder (اختياري)</label>
            <input type="text" name="pf_placeholder[]" value="<?=htmlspecialchars($pf['placeholder']??'')?>"
                   placeholder="مثال: 12345678" class="form-control">
          </div>
          <div class="form-group" style="margin:0;display:flex;align-items:center;gap:16px;padding-top:22px">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem">
              <input type="checkbox" name="pf_required[<?=$fi?>]" value="1" <?=$pf['is_required']?'checked':''?>>
              إجباري
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem">
              <input type="checkbox" name="pf_enabled[<?=$fi?>]" value="1" <?=$pf['is_enabled']?'checked':''?>>
              مفعّل
            </label>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem">الترتيب</label>
            <input type="number" name="pf_sort[]" value="<?=$pf['sort_order']?>" class="form-control" min="0">
          </div>
        </div>
        <!-- خيارات القائمة -->
        <div class="options-wrap" style="margin-top:12px;<?=$pf['field_type']==='select'?'':'display:none'?>">
          <label style="font-size:.72rem;color:#8895a7">خيارات القائمة (سطر لكل خيار: تسمية|قيمة)</label>
          <textarea name="pf_options[]" class="form-control" rows="4"
                    placeholder="صنعاء|1&#10;عدن|2&#10;تعز|3"
                    style="font-family:monospace;font-size:.8rem;resize:vertical"><?=htmlspecialchars($optsText)?></textarea>
          <small style="color:#8895a7">مثال: <code>صنعاء|1</code> أو فقط <code>صنعاء</code> (بدون قيمة = التسمية هي القيمة)</small>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div style="margin-top:1.25rem;display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ الحقول</button>
      <a href="providers.php" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
</div>

<!-- معاينة كيف ستظهر للعميل -->
<div class="card" style="border:1px solid rgba(139,92,246,.25)">
  <h3 style="margin-bottom:1rem;color:#8b5cf6"><i class="fas fa-eye"></i> معاينة — كيف ستظهر للعميل</h3>
  <div id="previewArea" style="background:#080c1a;border-radius:12px;padding:20px;max-width:400px">
    <p style="color:#8895a7;font-size:.82rem;text-align:center">أضف حقلاً لترى المعاينة</p>
  </div>
</div>

<script>
let fieldIndex = <?=count($providerFields)?>;

function addFieldRow() {
  document.getElementById('emptyMsg') && document.getElementById('emptyMsg').remove();
  const i = fieldIndex++;
  const row = document.createElement('div');
  row.className = 'field-row';
  row.dataset.index = i;
  row.style.cssText = 'background:#161b2e;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:18px;margin-bottom:12px;position:relative;animation:fadeIn .3s ease';
  row.innerHTML = `
    <input type="hidden" name="pf_id[]" value="0">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
      <span class="drag-handle" style="cursor:grab;color:#444;font-size:1.2rem;padding:0 4px">⠿</span>
      <strong style="font-size:.9rem;flex:1" class="field-preview-label">حقل جديد</strong>
      <button type="button" onclick="removeField(this)" style="background:rgba(255,68,85,.15);border:1px solid rgba(255,68,85,.2);color:#ff4455;border-radius:8px;padding:4px 10px;cursor:pointer;font-size:.8rem">
        <i class="fas fa-trash"></i>
      </button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">المفتاح (key)*</label>
        <input type="text" name="pf_key[]" required placeholder="field_key" class="form-control" style="font-family:monospace;font-size:.85rem">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">التسمية للعميل*</label>
        <input type="text" name="pf_label[]" required placeholder="اسم الحقل" class="form-control"
               oninput="this.closest('.field-row').querySelector('.field-preview-label').textContent=this.value||'حقل جديد'">
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">نوع الحقل</label>
        <select name="pf_type[]" class="form-control field-type-select" onchange="onTypeChange(this)">
          <option value="text">📝 نص</option>
          <option value="number">🔢 رقم</option>
          <option value="select">📋 قائمة</option>
          <option value="textarea">📄 نص طويل</option>
          <option value="checkbox">☑️ مربع تأكيد</option>
        </select>
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">Placeholder</label>
        <input type="text" name="pf_placeholder[]" placeholder="نص توضيحي" class="form-control">
      </div>
      <div class="form-group" style="margin:0;display:flex;align-items:center;gap:16px;padding-top:22px">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem">
          <input type="checkbox" name="pf_required[${i}]" value="1" checked> إجباري
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.85rem">
          <input type="checkbox" name="pf_enabled[${i}]" value="1" checked> مفعّل
        </label>
      </div>
      <div class="form-group" style="margin:0">
        <label style="font-size:.72rem">الترتيب</label>
        <input type="number" name="pf_sort[]" value="${i}" class="form-control" min="0">
      </div>
    </div>
    <div class="options-wrap" style="margin-top:12px;display:none">
      <label style="font-size:.72rem;color:#8895a7">خيارات القائمة (سطر لكل خيار: تسمية|قيمة)</label>
      <textarea name="pf_options[]" class="form-control" rows="4" placeholder="صنعاء|1&#10;عدن|2&#10;تعز|3"
                style="font-family:monospace;font-size:.8rem;resize:vertical"></textarea>
      <small style="color:#8895a7">مثال: <code>صنعاء|1</code></small>
    </div>`;
  document.getElementById('fieldsContainer').appendChild(row);
  updatePreview();
}

function removeField(btn) {
  btn.closest('.field-row').remove();
  updatePreview();
}

function onTypeChange(sel) {
  const wrap = sel.closest('.field-row').querySelector('.options-wrap');
  wrap.style.display = sel.value === 'select' ? '' : 'none';
  updatePreview();
}

function updatePreview() {
  const rows = document.querySelectorAll('.field-row');
  const area = document.getElementById('previewArea');
  if (!rows.length) {
    area.innerHTML = '<p style="color:#8895a7;font-size:.82rem;text-align:center">أضف حقلاً لترى المعاينة</p>';
    return;
  }
  let html = '<div style="font-size:.82rem;color:#8895a7;margin-bottom:14px">📋 يظهر للعميل قبل تنفيذ الطلب:</div>';
  rows.forEach(row => {
    const label   = row.querySelector('[name="pf_label[]"]')?.value || 'حقل';
    const type    = row.querySelector('[name="pf_type[]"]')?.value || 'text';
    const plach   = row.querySelector('[name="pf_placeholder[]"]')?.value || '';
    const req     = row.querySelector('[name^="pf_required"]')?.checked;
    const enabled = row.querySelector('[name^="pf_enabled"]')?.checked;
    if (!enabled) return;
    html += `<div style="margin-bottom:12px">
      <label style="display:block;font-size:.78rem;color:#aab;margin-bottom:5px">
        ${label}${req?' <span style=color:#ff4455>*</span>':''}
      </label>`;
    if (type === 'select') {
      const optsTxt = row.querySelector('[name="pf_options[]"]')?.value || '';
      const opts = optsTxt.split('
').filter(Boolean);
      html += `<select style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:8px;padding:8px 12px;font-size:.85rem" disabled>
        <option>اختر...</option>
        ${opts.map(o=>{const[l,v]=o.split('|');return`<option>${l.trim()}</option>`;}).join('')}
      </select>`;
    } else if (type === 'textarea') {
      html += `<textarea style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:8px;padding:8px 12px;font-size:.85rem" rows="3" placeholder="${plach}" disabled></textarea>`;
    } else if (type === 'checkbox') {
      html += `<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" disabled> ${plach||label}</label>`;
    } else {
      html += `<input type="${type}" style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:8px;padding:8px 12px;font-size:.85rem" placeholder="${plach}" disabled>`;
    }
    html += '</div>';
  });
  area.innerHTML = html;
}

// تشغيل معاينة عند تغيير أي input
document.getElementById('fieldsContainer').addEventListener('input', updatePreview);
document.addEventListener('DOMContentLoaded', updatePreview);

</script>
<?php endif; ?>

<?php if ($action==='add'||$action==='edit'): ?>
<div class="card mb-2">
    <h3 style="margin-bottom:1.5rem"><?= $action==='edit'?'تعديل المزود':'إضافة مزود جديد' ?></h3>
    <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_provider" value="1">
        <div class="form-group">
            <label>نوع المزود *</label>
            <select name="provider_type" id="provTypeSelect" onchange="onProviderTypeChange(this.value)" required>
                <option value="">-- اختر نوع المزود --</option>
                <option value="oranos"       <?=($editProv['provider_type']??'')==='oranos'?'selected':''?>>🌟 Oranos Market</option>
                <option value="ap4stor"      <?=($editProv['provider_type']??'')==='ap4stor'?'selected':''?>>⚡ ap4stor</option>
                <option value="smm_standard" <?=($editProv['provider_type']??'')==='smm_standard'?'selected':''?>>🔧 SMM Panel القياسي</option>
                <option value="numbersapp"   <?=($editProv['provider_type']??'')==='numbersapp'?'selected':''?>>📱 NumbersApp (أرقام + باقات + مخزن)</option>
                <option value="custom"       <?=($editProv['provider_type']??'')==='custom'?'selected':''?>>🔗 مزود مخصص</option>
            </select>
        </div>
        <div class="form-group">
            <label>اسم المزود *</label>
            <input type="text" name="name" id="provNameInput" value="<?=htmlspecialchars($editProv['name']??'')?>" required>
        </div>
        <div class="form-group">
            <label>رابط API (Base URL) *
                <small style="color:#8895a7">— يُملأ تلقائياً عند اختيار مزود معروف، ويمكنك تعديله</small>
            </label>
            <input type="url" name="api_url" id="provApiUrl"
                   value="<?=htmlspecialchars($editProv['api_url']??'')?>"
                   placeholder="https://api.example.com" required>
        </div>
        <div class="form-group">
            <label id="apiKeyLabel">API Token / Key *</label>
            <input type="text" name="api_key" value="<?=htmlspecialchars($editProv['api_key']??'')?>" placeholder="أدخل الـ Token أو Key" required>
            <small class="text-muted" id="apiKeyHint"></small>
        </div>
        <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ المزود</button>
        <a href="providers.php" class="btn btn-secondary">إلغاء</a>
    </form>
</div>
<script>
const PROVIDER_PRESETS = {
    oranos:       { name:'Oranos Market',  url:'https://api.oranosmarket.com', hint:'ضع هنا الـ api-token من لوحة Oranos Market', keyLabel:'API Token (api-token)' },
    ap4stor:      { name:'ap4stor',        url:'https://api.ap4stor.com',      hint:'ضع هنا الـ API Key من لوحة ap4stor',         keyLabel:'API Key' },
    smm_standard: { name:'',              url:'',                             hint:'ضع هنا الـ API Key من لوحة SMM',             keyLabel:'API Key' },
    numbersapp:   { name:'NumbersApp',    url:'https://api.numbersapp.online', hint:'ضع هنا الـ api_key من إدارة NumbersApp',     keyLabel:'API Key (api_key)' },
    custom:       { name:'',              url:'',                             hint:'أدخل الـ Token أو Key الخاص بالمزود',        keyLabel:'API Token / Key' },
};
function onProviderTypeChange(type) {
    const p = PROVIDER_PRESETS[type];
    if (!p) return;
    const nameInput = document.getElementById('provNameInput');
    const urlInput  = document.getElementById('provApiUrl');
    if (p.name && !nameInput.value) nameInput.value = p.name;
    if (p.url)  urlInput.value = p.url;
    document.getElementById('apiKeyHint').textContent = p.hint;
    document.getElementById('apiKeyLabel').textContent = p.keyLabel + ' *';
}
// تطبيق فوري عند التعديل
onProviderTypeChange('<?=$editProv["provider_type"]??""?>');
</script>
<?php endif; ?>

<?php if ($providerProducts && $fetchedProvider): ?>
<div class="card mb-2">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3><i class="fas fa-list"></i> <?=$fetchedProvider['provider_type']==='oranos'?'منتجات':'خدمات'?> المزود (<?=count($providerProducts)?>)</h3>
        <input type="text" id="productSearch" placeholder="🔍 بحث..." oninput="filterProducts()" style="padding:6px 12px;background:var(--bg3);border:1px solid var(--border);color:var(--text1);border-radius:8px;width:220px">
    </div>
    <div class="table-wrap" style="max-height:500px;overflow-y:auto">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>الاسم</th><th>القسم</th>
                    <?php if(in_array($fetchedProvider['provider_type'],['oranos','ap4stor'])): ?>
                    <th>الحقول</th><th>الكمية</th><th>السعر</th><th>متاح</th>
                    <?php elseif($fetchedProvider['provider_type']==='numbersapp'): ?>
                    <th>الكمية</th><th>السعر</th><th>الحالة</th>
                    <?php else: ?><th>الأدنى</th><th>الأقصى</th><th>السعر</th><?php endif; ?>
                    <th>إجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($providerProducts as $pp):
                $pid   = $pp['id'] ?? ($pp['service'] ?? '');
                $pname = $pp['name'] ?? '';
                $pcat  = $pp['category_name'] ?? ($pp['category'] ?? '');
                if ($fetchedProvider['provider_type'] === 'numbersapp') {
                    $params = $pp['params'] ?? [];
                    $pparams = is_array($params) ? implode(', ',$params) : '—';
                    $qty = null;
                    $qtyTxt = ($pp['min']??1).'–'.($pp['max']??1);
                    $avail = isset($pp['available']) ? (($pp['available'])?'<span style="color:#00d4aa">✓ متاح</span>':'<span style="color:#e05">✗</span>') : '';
                    $price = $pp['price'] ?? '—';
                    // Extra data for NumbersApp
                    $naExtra = [];
                    foreach (['na_type','na_service','na_pack_id','na_section','na_inputs','na_product_id'] as $k) {
                        if (isset($pp[$k])) $naExtra[$k] = $pp[$k];
                    }
                } elseif(in_array($fetchedProvider['provider_type'],['oranos','ap4stor'])){
                    $params = $pp['params'] ?? [];
                    $pparams = is_array($params) ? implode(', ',$params) : '—';
                    $qty = $pp['qty_values'];
                    if(!$qty) $qtyTxt='1 فقط';
                    elseif(is_array($qty)&&isset($qty['min'])) $qtyTxt=$qty['min'].'–'.($qty['max']??'∞');
                    else $qtyTxt=implode(', ',(array)$qty);
                    $avail=($pp['available']??false)?'<span style="color:#00d4aa">✓ متاح</span>':'<span style="color:#e05">✗</span>';
                    $price=number_format($pp['price']??0,3);
                    $naExtra = [];
                } else {
                    $pparams=''; $qtyTxt=($pp['min']??'—').'–'.($pp['max']??'—'); $avail=''; $price=$pp['rate']??$pp['price']??'—';
                    $naExtra = []; $qty = null;
                }
                $addUrl='services.php?action=add&provider_id='.$fetchedProvider['id']
                    .'&product_id='.urlencode($pid)
                    .'&product_name='.urlencode($pname)
                    .'&product_cat='.urlencode($pcat)
                    .'&product_price='.urlencode($pp['price']??'')
                    .'&product_params='.urlencode(json_encode($params??[]))
                    .'&qty_values='.urlencode(json_encode($qty??null))
                    .(!empty($naExtra)?'&na_extra='.urlencode(json_encode($naExtra)):'');
            ?>
            <tr class="product-row" data-name="<?=strtolower($pname.' '.$pcat)?>">
                <td><code><?=htmlspecialchars($pid)?></code></td>
                <td><?=htmlspecialchars($pname)?></td>
                <td><small class="text-muted"><?=htmlspecialchars($pcat)?></small></td>
                <?php if(in_array($fetchedProvider['provider_type'],['oranos','ap4stor'])): ?>
                <td><small style="color:#8895a7"><?=htmlspecialchars($pparams)?></small></td>
                <td><small><?=$qtyTxt?></small></td>
                <td style="color:#00d4aa"><?=$price?></td>
                <td><?=$avail?></td>
                <?php elseif($fetchedProvider['provider_type']==='numbersapp'): ?>
                <td><small><?=$qtyTxt?></small></td><td style="color:#00d4aa"><?=$price?></td><td><?=$avail?></td>
                <?php else: ?>
                <td><?=$pp['min']??'—'?></td><td><?=$pp['max']??'—'?></td><td><?=$price?></td>
                <?php endif; ?>
                <td><a href="<?=$addUrl?>" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> أضف</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function filterProducts(){
    const q=document.getElementById('productSearch').value.toLowerCase();
    document.querySelectorAll('.product-row').forEach(r=>{ r.style.display=r.dataset.name.includes(q)?'':'none'; });
}
</script>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>اسم المزود</th><th>النوع</th><th>رابط API</th><th>الرصيد</th><th>آخر فحص</th><th>إجراءات</th></tr>
            </thead>
            <tbody>
                <?php foreach($providers as $pv): ?>
                <tr>
                    <td><?=$pv['id']?></td>
                    <td><strong><?=htmlspecialchars($pv['name'])?></strong></td>
                    <td>
                        <?php
                        $typeLabels = ['oranos'=>'<span class="badge badge-info">🌟 Oranos</span>','ap4stor'=>'<span class="badge" style="background:#f59e0b;color:#000">⚡ ap4stor</span>','smm_standard'=>'<span class="badge badge-secondary">🔧 SMM قياسي</span>','numbersapp'=>'<span class="badge" style="background:#10b981;color:#fff">📱 NumbersApp</span>','custom'=>'<span class="badge" style="background:#6c3fe0">🔗 مخصص</span>'];
                        echo $typeLabels[$pv['provider_type']] ?? '<span class="badge badge-secondary">'.$pv['provider_type'].'</span>';
                        ?>
                    </td>
                    <td><small class="text-muted"><?=htmlspecialchars(substr($pv['api_url'],0,40))?>...</small></td>
                    <td style="color:#00d4aa"><?=number_format($pv['balance'],3)?></td>
                    <td><small><?=$pv['last_check']?date('Y/m/d H:i',strtotime($pv['last_check'])):'—'?></small></td>
                    <td style="display:flex;gap:0.4rem;flex-wrap:wrap">
                        <a href="?action=fetch&id=<?=$pv['id']?>" class="btn btn-sm btn-info" title="جلب المنتجات"><i class="fas fa-cloud-download-alt"></i></a>
                        <a href="?action=balance&id=<?=$pv['id']?>" class="btn btn-sm btn-success" title="فحص الرصيد"><i class="fas fa-wallet"></i></a>
                        <a href="?action=fields&id=<?=$pv['id']?>" class="btn btn-sm" style="background:#8b5cf6;color:#fff" title="الحقول الديناميكية">
                          <i class="fas fa-sliders-h"></i>
                          <?php
                            $fCount = $pdo->prepare("SELECT COUNT(*) FROM provider_fields WHERE provider_id=? AND is_enabled=1");
                            $fCount->execute([$pv['id']]); $cnt=(int)$fCount->fetchColumn();
                            if($cnt) echo '<span style="background:#fff;color:#8b5cf6;border-radius:10px;padding:1px 6px;font-size:.65rem;font-weight:900;margin-right:2px">'.$cnt.'</span>';
                          ?>
                        </a>
                        <a href="?action=edit&id=<?=$pv['id']?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                        <a href="?action=delete&id=<?=$pv['id']?>" class="btn btn-sm btn-danger" onclick="return confirmDelete()"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($providers)): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:#8895a7">لا يوجد مزودون. اضغط "مزود جديد".</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-2" style="background:#0f0f1a;border:1px solid rgba(0,212,170,0.2)">
    <h4 style="color:#00d4aa;margin-bottom:.75rem"><i class="fas fa-info-circle"></i> كيفية ربط Oranos Market</h4>
    <div style="font-size:.88rem;line-height:2;color:#8895a7">
        1. اضغط <strong style="color:#fff">مزود جديد</strong> → اختر <strong style="color:#00d4aa">Oranos Market</strong><br>
        2. ضع الـ <strong style="color:#fff">api-token</strong> من حسابك في Oranos واحفظ<br>
        3. اضغط <strong style="color:#fff">زر السحابة ☁</strong> لجلب قائمة المنتجات<br>
        4. اضغط <strong style="color:#fff">أضف</strong> على المنتج المطلوب → ستُملأ الحقول تلقائياً<br>
        5. فقط حدد السعر الذي تريد بيعه للعميل واحفظ<br>
        6. ✅ الطلبات ستُرسل تلقائياً لـ Oranos عند الشراء وتظهر في إدارة الطلبات
    </div>
</div>

<div class="card mt-2" style="background:#0f0f1a;border:1px solid rgba(16,185,129,0.3)">
    <h4 style="color:#10b981;margin-bottom:.75rem"><i class="fas fa-mobile-alt"></i> كيفية ربط NumbersApp</h4>
    <div style="font-size:.88rem;line-height:2;color:#8895a7">
        1. اضغط <strong style="color:#fff">مزود جديد</strong> → اختر <strong style="color:#10b981">📱 NumbersApp</strong><br>
        2. ضع الـ <strong style="color:#fff">api_key</strong> واحفظ (الرابط يُملأ تلقائياً)<br>
        3. اضغط <strong style="color:#fff">زر السحابة ☁</strong> لجلب الخدمات من 3 أقسام (مباشر + باقات + مخزن)<br>
        4. اضغط <strong style="color:#fff">أضف</strong> على الخدمة → ستُملأ الحقول والبيانات تلقائياً<br>
        5. حدد السعر للعميل واحفظ<br>
        6. ✅ الطلبات ستُرسل تلقائياً لـ NumbersApp — الأرقام المباشرة تُعرض فوراً والباقات يتم متابعة حالتها
    </div>
</div>

<?php include 'footer.php'; ?>
