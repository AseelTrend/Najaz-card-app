<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  reseller_api_test.php
 *  أداة اختبار شاملة ومستقلة لنظام /reseller_api
 *  ---------------------------------------------------------------
 *  ملف واحد مستقل تمامًا (لا يحتاج قاعدة بيانات ولا أي ملف آخر من
 *  الموقع). ارفعه في أي مكان (مثلاً app.njaz.net) وافتحه من المتصفح.
 *
 *  يختبر تلقائيًا: profile / products / content / check
 *  ويسمح اختياريًا باختبار newOrder حقيقي (بعد تأكيد صريح، لأنه
 *  يخصم رصيد فعلي وينشئ طلب حقيقي).
 * ══════════════════════════════════════════════════════════════════
 */

// ------------------------------------------------------------------
// دالة تنفيذ طلب واختبار الرد + قياس الزمن
// ------------------------------------------------------------------
function testCall(string $url, string $token): array {
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['api-token: ' . $token, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw    = curl_exec($ch);
    $err    = curl_error($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = round((microtime(true) - $t0) * 1000);

    $json = null;
    if ($raw !== false) { $json = json_decode($raw, true); }

    return [
        'url'      => $url,
        'ok'       => ($raw !== false && $code > 0 && $json !== null),
        'curl_err' => $err,
        'http'     => $code,
        'ms'       => $ms,
        'raw'      => $raw,
        'json'     => $json,
    ];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$baseUrl = rtrim(trim($_POST['base_url'] ?? $_GET['base_url'] ?? 'https://api.njaz.net'), '/');
$token   = trim($_POST['api_token'] ?? $_GET['api_token'] ?? '');
$ran     = false;
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_basic']) && $token !== '') {
    $ran = true;

    $results['profile'] = testCall("$baseUrl/client/api/profile", $token);

    $results['products'] = testCall("$baseUrl/client/api/products", $token);

    $results['products_base'] = testCall("$baseUrl/client/api/products?base=1", $token);

    $results['content'] = testCall("$baseUrl/client/api/content/0", $token);

    $checkUuid = 'test-' . bin2hex(random_bytes(8));
    $results['check'] = testCall("$baseUrl/client/api/check?orders=[$checkUuid]&uuid=1", $token);
}

// ── اختبار newOrder حقيقي (اختياري، يخصم رصيد فعلي) ──
$orderResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_order']) && $token !== '' && !empty($_POST['confirm_real_order'])) {
    $productId = trim($_POST['test_product_id'] ?? '');
    $qty       = (int)($_POST['test_qty'] ?? 1);
    $playerId  = trim($_POST['test_player_id'] ?? '');
    if ($productId !== '') {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
        );
        $params = http_build_query(['qty' => $qty, 'playerId' => $playerId, 'order_uuid' => $uuid]);
        $orderResult = testCall("$baseUrl/client/api/newOrder/$productId/params?$params", $token);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>اختبار الربط - reseller_api</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, sans-serif; background:#0f1117; color:#e5e7eb; margin:0; padding:16px; }
  h1 { font-size:19px; margin:0 0 4px; }
  h2 { font-size:15px; margin:22px 0 10px; color:#f5c518; }
  .sub { color:#9ca3af; font-size:13px; margin-bottom:18px; }
  .card { background:#171a23; border:1px solid #262a37; border-radius:12px; padding:16px; margin-bottom:14px; }
  label { display:block; font-size:13px; color:#9ca3af; margin-bottom:6px; }
  input[type=text], input[type=number] { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #2b2f3d; background:#0f1117; color:#fff; font-size:14px; margin-bottom:12px; }
  .row { display:flex; gap:10px; flex-wrap:wrap; }
  .row > div { flex:1; min-width:150px; }
  button { background:#f5c518; color:#111; border:none; padding:11px 18px; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
  button.secondary { background:#2b2f3d; color:#fff; }
  button.danger { background:#ff4455; color:#fff; }
  .result { border-radius:10px; padding:12px; margin-bottom:10px; border:1px solid #262a37; }
  .result.pass { border-color:#00d4aa55; background:#00d4aa0d; }
  .result.fail { border-color:#ff445555; background:#ff44550d; }
  .result-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px; }
  .badge { font-size:11px; padding:3px 9px; border-radius:20px; font-weight:700; }
  .badge.pass { background:#00d4aa; color:#04231c; }
  .badge.fail { background:#ff4455; color:#2a0006; }
  .meta { font-size:11px; color:#9ca3af; font-family:monospace; }
  pre { background:#0b0d13; border-radius:8px; padding:10px; overflow-x:auto; font-size:12px; direction:ltr; text-align:left; margin:0; max-height:340px; overflow-y:auto; }
  .url { font-family:monospace; font-size:11px; color:#4dabf7; word-break:break-all; direction:ltr; text-align:left; }
  .warn { background:#f5c51815; border:1px solid #f5c51855; color:#f5c518; padding:10px 12px; border-radius:8px; font-size:12.5px; margin-bottom:12px; }
  .danger-box { background:#ff445515; border:1px solid #ff445555; color:#ffb3ba; padding:10px 12px; border-radius:8px; font-size:12.5px; margin-bottom:12px; }
  a { color:#4dabf7; }
</style>
</head>
<body>

<h1>🧪 اختبار الربط الشامل — reseller_api</h1>
<div class="sub">أداة مستقلة لاختبار كل نقاط الـ API الخاصة بموقعك، تمامًا كما يستخدمها العميل الخارجي المرتبط بك.</div>

<div class="card">
  <form method="POST">
    <div class="row">
      <div>
        <label>رابط الـ API الأساسي (Base URL)</label>
        <input type="text" name="base_url" value="<?= h($baseUrl) ?>" placeholder="https://api.njaz.net">
      </div>
      <div>
        <label>API Token (نفس التوكن الظاهر بصفحة api.php لأي حساب)</label>
        <input type="text" name="api_token" value="<?= h($token) ?>" placeholder="أدخل التوكن هنا">
      </div>
    </div>
    <button type="submit" name="run_basic" value="1">🚀 تشغيل الاختبار الأساسي (Profile / Products / Content / Check)</button>
  </form>
</div>

<?php if ($ran): ?>
<h2>نتائج الاختبار الأساسي</h2>

<?php
$labels = [
    'profile'       => ['GET /client/api/profile', 'يجلب رصيد الحساب وبريده'],
    'products'      => ['GET /client/api/products', 'يجلب كل المنتجات المتاحة بكامل تفاصيلها'],
    'products_base' => ['GET /client/api/products?base=1', 'نسخة مختصرة (ID + الاسم فقط)'],
    'content'       => ['GET /client/api/content/0', 'محتوى الصفحة الرئيسية (الأقسام + المنتجات)'],
    'check'         => ['GET /client/api/check?uuid=1', 'اختبار نقطة الاستعلام عن الطلبات (بمعرّف وهمي)'],
];
foreach ($results as $key => $r):
    $label = $labels[$key][0] ?? $key;
    $desc  = $labels[$key][1] ?? '';
    $pass  = $r['ok'] && (($r['json']['status'] ?? '') !== 'ERROR' || $key === 'check');
    // لملاحظة: check بمعرف وهمي قد يرجع مصفوفة فارغة (طبيعي وسليم) وليس بالضرورة status=ERROR
?>
<div class="result <?= $pass ? 'pass' : 'fail' ?>">
  <div class="result-head">
    <div><b><?= h($label) ?></b><div class="meta"><?= h($desc) ?></div></div>
    <div>
      <span class="badge <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✔ نجح' : '✘ فشل' ?></span>
      <span class="meta">HTTP <?= h($r['http']) ?> · <?= h($r['ms']) ?>ms</span>
    </div>
  </div>
  <div class="url"><?= h($r['url']) ?></div>
  <br>
  <?php if (!$r['ok']): ?>
    <div class="danger-box">
      <?php if ($r['curl_err']): ?>خطأ اتصال: <?= h($r['curl_err']) ?><br><?php endif; ?>
      <?php if ($r['raw'] !== false && $r['json'] === null): ?>الرد ليس JSON صالح — على الأغلب صفحة HTML (تحدي Cloudflare أو خطأ PHP). أول 300 حرف من الرد:<br><?= h(substr((string)$r['raw'], 0, 300)) ?><?php endif; ?>
    </div>
  <?php endif; ?>
  <pre><?= h(json_encode($r['json'] ?? $r['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
<?php endforeach; ?>
<?php endif; ?>

<h2>⚠️ اختبار طلب حقيقي (newOrder)</h2>
<div class="warn">
  هذا الاختبار ينشئ <b>طلبًا حقيقيًا فعليًا</b> ويخصم رصيدًا حقيقيًا من حساب التوكن المُدخل أعلاه، تمامًا كأي طلب من عميل خارجي حقيقي. استخدمه فقط للتأكد من أن الطلبات تصل وتُعالَج بنجاح (مثلاً بمنتج رخيص جدًا).
</div>
<div class="card">
  <form method="POST">
    <input type="hidden" name="base_url" value="<?= h($baseUrl) ?>">
    <input type="hidden" name="api_token" value="<?= h($token) ?>">
    <div class="row">
      <div>
        <label>معرّف المنتج (Product ID)</label>
        <input type="text" name="test_product_id" value="<?= h($_POST['test_product_id'] ?? '') ?>" placeholder="مثال: 364">
      </div>
      <div>
        <label>الكمية</label>
        <input type="number" name="test_qty" value="<?= h($_POST['test_qty'] ?? '1') ?>" min="1">
      </div>
      <div>
        <label>playerId (أو أي قيمة تجريبية)</label>
        <input type="text" name="test_player_id" value="<?= h($_POST['test_player_id'] ?? 'TEST_' . date('His')) ?>">
      </div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:14px">
      <input type="checkbox" name="confirm_real_order" value="1" style="width:auto;margin:0">
      أفهم أن هذا سينشئ طلبًا حقيقيًا ويخصم رصيدًا فعليًا من الحساب
    </label>
    <button type="submit" name="run_order" value="1" class="danger">⚠️ تنفيذ طلب تجريبي حقيقي الآن</button>
  </form>

  <?php if ($orderResult): ?>
    <?php $pass = $orderResult['ok'] && (($orderResult['json']['status'] ?? '') === 'OK'); ?>
    <div class="result <?= $pass ? 'pass' : 'fail' ?>" style="margin-top:16px">
      <div class="result-head">
        <div><b>GET /client/api/newOrder/{id}/params</b></div>
        <div>
          <span class="badge <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✔ نجح' : '✘ فشل' ?></span>
          <span class="meta">HTTP <?= h($orderResult['http']) ?> · <?= h($orderResult['ms']) ?>ms</span>
        </div>
      </div>
      <div class="url"><?= h($orderResult['url']) ?></div>
      <br>
      <pre><?= h(json_encode($orderResult['json'] ?? $orderResult['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_order'])): ?>
    <div class="danger-box" style="margin-top:14px">لم يتم التنفيذ — تأكد من تعبئة معرّف المنتج والموافقة على المربع أعلاه.</div>
  <?php endif; ?>
</div>

<div class="sub" style="margin-top:20px">
  💡 هذا الملف مستقل تمامًا ولا يتصل بقاعدة بياناتك — فقط يرسل طلبات HTTP حقيقية لعنوان الـ API اللي تكتبه، تمامًا كما يفعل أي عميل خارجي مرتبط بك.
  احذفه من السيرفر بعد الانتهاء من الاختبار لأسباب أمنية (لأي شخص يعرف رابطه ويملك توكن صالح يقدر يستخدمه).
</div>

</body>
</html>
