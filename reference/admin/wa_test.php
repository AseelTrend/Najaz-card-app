<?php
/**
 * wa_test.php — اختبار إرسال واتساب مع اكتشاف endpoint المجموعات
 * ضعه في /admin/wa_test.php واحذفه بعد الانتهاء
 */
require_once '../includes/config.php';
requireAdmin($pdo);

$apiKey = '';
$sender = '';
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM whatsapp_settings")->fetchAll() as $row) {
        if ($row['setting_key'] === 'heta_api_key') $apiKey = $row['setting_value'];
        if ($row['setting_key'] === 'heta_sender')  $sender = $row['setting_value'];
    }
} catch (Exception $e) {}

function doSend($endpoint, $payload) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time     = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    return [
        'http_code' => $httpCode,
        'curl_err'  => $curlErr,
        'raw'       => $raw,
        'parsed'    => json_decode($raw, true),
        'time_ms'   => round($time * 1000),
    ];
}

$results = [];
$to      = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to      = trim($_POST['to'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $isGroup = str_contains($to, '@g.us');

    $basePayload = ['api_key' => $apiKey, 'sender' => $sender, 'number' => $to, 'message' => $message];

    // جرب كل الـ endpoints الممكنة
    $endpoints = [
        'send-message'       => 'https://sender.hetacloud.top/send-message',
        'send-group-message' => 'https://sender.hetacloud.top/send-group-message',
        'send-group'         => 'https://sender.hetacloud.top/send-group',
    ];

    if ($isGroup) {
        // للمجموعات جرب أيضاً بـ group_id بدل number
        $endpoints['send-message (group_id field)'] = 'CUSTOM';
    }

    foreach ($endpoints as $label => $ep) {
        if ($ep === 'CUSTOM') {
            $payload = ['api_key' => $apiKey, 'sender' => $sender, 'group_id' => $to, 'message' => $message];
            $r = doSend('https://sender.hetacloud.top/send-message', $payload);
            $r['payload'] = $payload;
            $r['endpoint'] = 'send-message + group_id field';
        } else {
            $r = doSend($ep, $basePayload);
            $r['payload']  = $basePayload;
            $r['endpoint'] = $ep;
        }
        $results[$label] = $r;
        // إذا نجح واحد نوقف
        if (($r['parsed']['status'] ?? false) === true) break;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>اختبار إرسال واتساب</title>
<style>
body{font-family:Cairo,sans-serif;background:#070b14;color:#e5e7eb;padding:30px;max-width:860px;margin:0 auto}
h2{color:#25d366;display:flex;align-items:center;gap:10px}
label{display:block;font-size:.85rem;font-weight:700;margin-bottom:5px;color:#9ca3af}
input,textarea{width:100%;padding:10px 12px;background:#111827;border:1px solid #1e2940;border-radius:8px;color:#fff;font-family:Cairo,sans-serif;font-size:.9rem;box-sizing:border-box;margin-bottom:14px}
button{background:#25d366;color:#fff;border:none;padding:10px 28px;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer}
.card{background:#111827;border:1px solid #1e2940;border-radius:12px;padding:20px;margin-bottom:16px}
.card-title{font-weight:700;margin-bottom:12px;color:#9ca3af;font-size:.88rem}
.ok{color:#25d366;font-weight:700}
.fail{color:#ff4455;font-weight:700}
.warn{color:#f5a623;font-weight:700}
pre{background:#0a0f1a;border:1px solid #1e2940;border-radius:8px;padding:14px;overflow-x:auto;font-size:.77rem;white-space:pre-wrap;word-break:break-all;color:#a3e635;margin:8px 0 0}
.row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #1e2940;font-size:.83rem}
.row:last-child{border:none}
.lbl{color:#9ca3af}
.result-block{border-radius:10px;padding:14px 16px;margin-bottom:12px}
.result-ok{background:#25d36610;border:1px solid #25d36640}
.result-fail{background:#ff445510;border:1px solid #ff445530}
.ep-label{font-weight:800;font-size:.85rem;margin-bottom:8px}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.73rem;font-weight:700;margin-right:8px}
.b-ok{background:#25d36622;color:#25d366}
.b-fail{background:#ff445522;color:#ff4455}
</style>
</head>
<body>
<h2>📱 اختبار إرسال واتساب — HetaCloud</h2>

<div class="card">
  <div class="card-title">⚙️ الإعدادات</div>
  <div class="row"><span class="lbl">API Key</span><span><?= $apiKey ? '<span class="ok">✅ '.substr($apiKey,0,8).'...</span>' : '<span class="fail">❌ غير مضبوط</span>' ?></span></div>
  <div class="row"><span class="lbl">Sender</span><span class="ok"><?= htmlspecialchars($sender ?: '❌ غير مضبوط') ?></span></div>
</div>

<div class="card">
  <div class="card-title">📤 إرسال اختبار</div>
  <form method="POST">
    <label>رقم أو ID المجموعة</label>
    <input type="text" name="to" value="<?= htmlspecialchars($to ?: '120363411684010124@g.us') ?>" dir="ltr">
    <label>الرسالة</label>
    <textarea name="message" rows="2"><?= htmlspecialchars($message ?: 'اختبار من njaz.net ✅') ?></textarea>
    <button type="submit">🚀 اختبر جميع Endpoints</button>
  </form>
</div>

<?php if (!empty($results)): ?>
<div class="card">
  <div class="card-title">📊 نتائج الاختبار</div>
  <?php foreach ($results as $label => $r):
    $success = ($r['parsed']['status'] ?? false) === true;
  ?>
  <div class="result-block <?= $success ? 'result-ok' : 'result-fail' ?>">
    <div class="ep-label">
      <span class="badge <?= $success ? 'b-ok' : 'b-fail' ?>"><?= $success ? '✅ نجح' : '❌ فشل' ?></span>
      <?= htmlspecialchars($label) ?>
      <span style="font-size:.75rem;color:#6b7280;font-weight:400"><?= $r['time_ms'] ?>ms | HTTP <?= $r['http_code'] ?></span>
    </div>
    <?php if ($r['curl_err']): ?>
    <div class="fail" style="font-size:.8rem">cURL: <?= htmlspecialchars($r['curl_err']) ?></div>
    <?php endif; ?>
    <div style="font-size:.8rem;color:#9ca3af;margin-bottom:4px">
      Endpoint: <span dir="ltr" style="color:#60a5fa"><?= htmlspecialchars($r['endpoint']) ?></span>
    </div>
    <?php if (!empty($r['parsed'])): ?>
    <div style="font-size:.8rem;margin-bottom:2px">
      <?php foreach ($r['parsed'] as $k => $v): ?>
        <span style="color:#9ca3af"><?= $k ?>:</span>
        <span style="color:<?= $k==='status'&&$v?'#25d366':($k==='status'?'#ff4455':'#fbbf24') ?>;margin-left:4px;margin-right:12px"><?= htmlspecialchars(is_bool($v)?($v?'true':'false'):$v) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <details style="margin-top:6px">
      <summary style="font-size:.75rem;color:#6b7280;cursor:pointer">رد خام / Payload</summary>
      <pre><?= htmlspecialchars(json_encode($r['payload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
      <pre><?= htmlspecialchars($r['raw'] ?: '(فارغ)') ?></pre>
    </details>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p style="color:#374151;font-size:.75rem">⚠️ احذف هذا الملف بعد الانتهاء</p>
</body>
</html>
