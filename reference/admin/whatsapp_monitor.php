<?php
require_once '../includes/config.php';
requireAdmin($pdo);

function waMonE($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function waMonTable(PDO $pdo, string $name): bool {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $s->execute([$name]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
function waMonOne(PDO $pdo, string $sql, array $params = []): ?array {
    try { $s = $pdo->prepare($sql); $s->execute($params); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    catch (Throwable $e) { return null; }
}
function waMonAll(PDO $pdo, string $sql, array $params = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}
function waMonAgo(?string $date): string {
    if (!$date) return 'لا توجد بيانات';
    $ts = strtotime($date); if (!$ts) return waMonE($date);
    $seconds = max(0, time() - $ts);
    if ($seconds < 60) return 'منذ ' . $seconds . ' ثانية';
    if ($seconds < 3600) return 'منذ ' . floor($seconds / 60) . ' دقيقة';
    if ($seconds < 86400) return 'منذ ' . floor($seconds / 3600) . ' ساعة';
    return 'منذ ' . floor($seconds / 86400) . ' يوم';
}
function waMonDbCheck(PDO $pdo): array {
    $started = microtime(true); $error = null; $ok = false;
    try { $ok = ((int)$pdo->query('SELECT 1')->fetchColumn() === 1); }
    catch (Throwable $e) { $error = $e->getMessage(); }
    return ['ok'=>$ok, 'ms'=>(int)round((microtime(true)-$started)*1000), 'error'=>$error];
}
function waMonHttpCheck(string $url): array {
    $started = microtime(true); $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_USERAGENT=>'Njaz-WA-Monitor/1.0']);
    curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['ok'=>$error === '' && $code > 0, 'code'=>$code, 'ms'=>(int)round((microtime(true)-$started)*1000), 'error'=>$error];
}

// اختبار استقبال حقيقي: لا يرسل النظام رسالة عبر API؛ بل ينشئ رمزاً فريداً
// ويطلب من عضو/هاتف آخر إرسال الرمز داخل المجموعة، ثم يبحث عنه في Webhook الوارد.
if (($_GET['action'] ?? '') === 'start_incoming_test') {
    header('Content-Type: application/json; charset=utf-8');
    $groupId = trim((string)($_GET['group_id'] ?? ''));
    if ($groupId === '' || strlen($groupId) > 120 || !preg_match('/^[A-Za-z0-9._:-]+@g\.us$/', $groupId)) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>'أدخل معرف مجموعة صحيحاً ينتهي بـ @g.us'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!waMonTable($pdo, 'wa_incoming_messages')) {
        http_response_code(503);
        echo json_encode(['ok'=>false, 'error'=>'جدول الرسائل الواردة غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $token = 'NJZ-IN-' . strtoupper(bin2hex(random_bytes(4)));
    $startedAt = date('Y-m-d H:i:s');
    echo json_encode(['ok'=>true, 'group_id'=>$groupId, 'token'=>$token, 'started_at'=>$startedAt, 'timeout_seconds'=>60], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['action'] ?? '') === 'check_incoming_test') {
    header('Content-Type: application/json; charset=utf-8');
    $groupId = trim((string)($_GET['group_id'] ?? ''));
    $token = trim((string)($_GET['token'] ?? ''));
    $startedAt = trim((string)($_GET['started_at'] ?? ''));
    if ($groupId === '' || $token === '' || $startedAt === '' || !preg_match('/^[A-Za-z0-9._:-]+@g\.us$/', $groupId) || !preg_match('/^NJZ-IN-[A-F0-9]{8}$/', $token)) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>'بيانات اختبار الاستقبال غير صحيحة'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $found = null;
    if (waMonTable($pdo, 'wa_incoming_messages')) {
        $found = waMonOne($pdo, "SELECT id,message_id,group_id,group_name,message,received_at FROM wa_incoming_messages WHERE group_id=? AND received_at>=? AND message LIKE ? ORDER BY id DESC LIMIT 1", [$groupId, $startedAt, '%' . $token . '%']);
    }
    echo json_encode(['ok'=>true, 'received'=>(bool)$found, 'message_id'=>$found['message_id'] ?? null, 'received_at'=>$found['received_at'] ?? null, 'message_preview'=>isset($found['message']) ? mb_substr((string)$found['message'], 0, 120) : null], JSON_UNESCAPED_UNICODE);
    exit;
}

// اختبار تشخيصي اختياري: يرسل رسالة مميزة إلى مجموعة ثم ينتظر حتى 10 ثوانٍ.
if (($_GET['action'] ?? '') === 'send_test') {
    header('Content-Type: application/json; charset=utf-8');
    $groupId = trim((string)($_GET['group_id'] ?? ''));
    if ($groupId === '' || strlen($groupId) > 120 || !preg_match('/^[A-Za-z0-9._:-]+@g\.us$/', $groupId)) {
        http_response_code(422);
        echo json_encode(['ok'=>false, 'error'=>'أدخل معرف مجموعة صحيحاً ينتهي بـ @g.us'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $apiKeyRow = waMonOne($pdo, "SELECT setting_value FROM whatsapp_settings WHERE setting_key='heta_api_key' LIMIT 1");
    $senderRow = waMonOne($pdo, "SELECT setting_value FROM whatsapp_settings WHERE setting_key='heta_sender' LIMIT 1");
    $apiKey = (string)($apiKeyRow['setting_value'] ?? '');
    $sender = (string)($senderRow['setting_value'] ?? '');
    if ($apiKey === '' || $sender === '') {
        http_response_code(503);
        echo json_encode(['ok'=>false, 'error'=>'إعدادات HetaCloud غير مكتملة في whatsapp_settings'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $testText = '[Njaz monitor test] ' . date('Y-m-d H:i:s');
    $sentAt = date('Y-m-d H:i:s');
    $ch = curl_init('https://sender.hetacloud.top/send-message');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['api_key'=>$apiKey,'sender'=>$sender,'number'=>$groupId,'message'=>$testText], JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_USERAGENT=>'Njaz-WA-Monitor/1.0']);
    curl_exec($ch); $sendError = curl_error($ch); $sendCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $found = null; $deadline = microtime(true) + 10;
    if ($sendError === '' && waMonTable($pdo, 'wa_incoming_messages')) {
        do {
            $found = waMonOne($pdo, "SELECT id,message_id,group_id,received_at FROM wa_incoming_messages WHERE group_id=? AND received_at>=? ORDER BY id DESC LIMIT 1", [$groupId, $sentAt]);
            if ($found) break;
            if (microtime(true) < $deadline) sleep(1);
        } while (microtime(true) < $deadline);
    }
    echo json_encode(['ok'=>$sendError === '' && $sendCode >= 200 && $sendCode < 300, 'provider_http'=>$sendCode, 'provider_error'=>$sendError ?: null, 'webhook_received'=>(bool)$found, 'message_id'=>$found['message_id'] ?? null, 'received_at'=>$found['received_at'] ?? null, 'waited_seconds'=>10, 'group_id'=>$groupId], JSON_UNESCAPED_UNICODE);
    exit;
}

$tables = [];
foreach (['wa_incoming_messages','wa_webhook_log','wa_device_status','wa_message_trace','whatsapp_groups'] as $table) $tables[$table] = waMonTable($pdo, $table);
$db = waMonDbCheck($pdo);
$checks = null;
if (isset($_GET['check'])) {
    $checks = [
        'provider' => waMonHttpCheck('https://sender.hetacloud.top/'),
        'webhook' => waMonHttpCheck(rtrim(SITE_URL, '/') . '/register_webhook.php?monitor_probe=' . time()),
    ];
}
$latestWebhook = $tables['wa_webhook_log'] ? waMonOne($pdo, "SELECT id,event,processed,error,created_at FROM wa_webhook_log ORDER BY id DESC LIMIT 1") : null;
$latestMessage = $tables['wa_incoming_messages'] ? waMonOne($pdo, "SELECT id,message_id,group_id,group_name,message_type,received_at FROM wa_incoming_messages ORDER BY id DESC LIMIT 1") : null;
$latestDevice = $tables['wa_device_status'] ? waMonOne($pdo, "SELECT device,status,detail,logged_at FROM wa_device_status ORDER BY id DESC LIMIT 1") : null;
$latestTrace = $tables['wa_message_trace'] ? waMonOne($pdo, "SELECT * FROM wa_message_trace ORDER BY id DESC LIMIT 1") : null;
$traces = $tables['wa_message_trace'] ? waMonAll($pdo, "SELECT request_id,message_id,event_type,device,group_id,group_name,stage,received_at,persisted_at,http_status,processing_ms,db_insert_ms,error FROM wa_message_trace ORDER BY id DESC LIMIT 50") : [];
$logs = $tables['wa_webhook_log'] ? waMonAll($pdo, "SELECT id,event,processed,error,created_at FROM wa_webhook_log ORDER BY id DESC LIMIT 30") : [];
$gaps = $tables['wa_webhook_log'] ? waMonAll($pdo, "SELECT id,event,created_at,TIMESTAMPDIFF(SECOND,LAG(created_at) OVER (ORDER BY id),created_at) AS gap_seconds FROM wa_webhook_log WHERE event='message' ORDER BY id DESC LIMIT 30") : [];
$deviceOffline = $latestDevice && $latestDevice['status'] === 'disconnected';
$offlineSeconds = $deviceOffline && !empty($latestDevice['logged_at']) ? max(0, time() - (int)strtotime($latestDevice['logged_at'])) : null;
$diagnosis = 'أرسل رسالة اختبارية داخل مجموعة ثم راقب السجل هنا خلال ثوانٍ.';
if ($latestWebhook && $latestMessage) {
    $w = strtotime($latestWebhook['created_at']); $m = strtotime($latestMessage['received_at']);
    if ($w && $m && $w > $m + 30) $diagnosis = 'يصل Webhook أحدث من آخر رسالة محفوظة؛ افحص مرحلة الحفظ أو أخطاء قاعدة البيانات في سجل التتبع.';
    elseif ($w && $m && $m >= $w - 30) $diagnosis = 'آخر Webhook ورسالة متقاربان زمنياً؛ إذا لم تظهر الرسالة في الواجهة فالمشكلة في الاستعلام أو التخزين المؤقت للواجهة.';
} elseif ($latestWebhook && !$latestMessage) $diagnosis = 'وصل Webhook لكن لا توجد رسالة محفوظة؛ افحص الأخطاء ومرحلة التتبع.';
elseif (!$latestWebhook) $diagnosis = 'لا يوجد Webhook مسجل؛ المشكلة قبل وصول الحدث إلى الاستضافة أو في إعدادات HetaCloud.';
if ($deviceOffline) $diagnosis = 'آخر حالة مسجلة للجهاز منفصلة؛ هذا يفسر احتمال فقد الرسائل قبل إرسالها إلى Webhook. أعد ربط جلسة WhatsApp ثم أعد الاختبار.';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>مراقبة WhatsApp</title>
<style>
body{margin:0;background:#070b14;color:#e5e7eb;font-family:Cairo,Tahoma,Arial,sans-serif;padding:24px}.wrap{max-width:1280px;margin:auto}h1{margin:0 0 8px;color:#25d366;font-size:1.45rem}.sub{color:#9ca3af;margin-bottom:22px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:15px 0}.card{background:#111827;border:1px solid #1e2940;border-radius:12px;padding:16px}.label{color:#9ca3af;font-size:.78rem}.value{font-size:1.05rem;font-weight:800;margin-top:8px}.ok{color:#25d366}.bad{color:#ff6677}.warn{color:#f5a623}.muted{color:#9ca3af}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}a,button{display:inline-block;border:0;border-radius:8px;padding:10px 16px;background:#25d366;color:#06120a;text-decoration:none;font-weight:800;cursor:pointer}a.secondary{background:#1e2940;color:#e5e7eb}.notice{background:#10251c;border:1px solid #25d36655;border-radius:10px;padding:14px;margin:14px 0;color:#c9f7d8}.warning{background:#2b2110;border-color:#f5a62366;color:#ffe4a8}.test-form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}.test-form input{background:#0d1422;color:#e5e7eb;border:1px solid #33415f;border-radius:8px;padding:10px 12px;min-width:280px;direction:ltr}.gap-big{background:#3a1720!important;color:#ffb5bd}.gap-ok{color:#8eeab0}.duration{font-size:.75rem;color:#ffd78a}table{width:100%;border-collapse:collapse;min-width:900px}th,td{padding:10px 9px;border-bottom:1px solid #1e2940;text-align:right;vertical-align:top;font-size:.78rem}th{color:#9ca3af;background:#0d1422;position:sticky;top:0}.table-wrap{overflow:auto;max-height:520px}.badge{padding:3px 8px;border-radius:20px;font-weight:800;font-size:.72rem;background:#273244}.badge.ok{background:#25d36622}.badge.bad{background:#ff445522}.badge.warn{background:#f5a62322}.small{font-size:.75rem;color:#9ca3af}.err{color:#ff8b98;max-width:260px;word-break:break-word}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}body{padding:14px}}
</style><style>
:root{color-scheme:dark;--mon-bg:#070b14;--mon-text:#e5e7eb;--mon-card:#111827;--mon-border:#1e2940;--mon-muted:#9ca3af;--mon-input:#0d1422;--mon-secondary:#1e2940;--mon-th:#0d1422;--mon-notice:#10251c;--mon-warning:#2b2110;--mon-gap-danger:#3a1720;--mon-gap-danger-text:#ffb5bd}
html[data-admin-theme="light"]{color-scheme:light;--mon-bg:#f4f7fb;--mon-text:#182234;--mon-card:#fff;--mon-border:#d8e0ec;--mon-muted:#5b687a;--mon-input:#fff;--mon-secondary:#e7edf6;--mon-th:#edf2f8;--mon-notice:#edf9f1;--mon-warning:#fff7e6;--mon-gap-danger:#fde8eb;--mon-gap-danger-text:#a1263a}
body{background:var(--mon-bg);color:var(--mon-text)}.card{background:var(--mon-card);border-color:var(--mon-border)}.sub,.label,.muted,.small{color:var(--mon-muted)}a.secondary{background:var(--mon-secondary);color:var(--mon-text)}.test-form input{background:var(--mon-input);color:var(--mon-text);border-color:var(--mon-border)}th{color:var(--mon-muted);background:var(--mon-th)}td{border-color:var(--mon-border)}.notice{background:var(--mon-notice);color:var(--mon-text)}.warning{background:var(--mon-warning);color:var(--mon-text)}.theme-toggle{background:var(--mon-secondary);color:var(--mon-text);border:1px solid var(--mon-border)}.gap-big{background:var(--mon-gap-danger)!important;color:var(--mon-gap-danger-text)}
</style><script>(function(){var t=localStorage.getItem('njaz_admin_theme')||'dark';document.documentElement.setAttribute('data-admin-theme',t);window.toggleMonitorTheme=function(){var n=document.documentElement.getAttribute('data-admin-theme')==='light'?'dark':'light';document.documentElement.setAttribute('data-admin-theme',n);localStorage.setItem('njaz_admin_theme',n);var b=document.getElementById('monitorThemeToggle');if(b)b.textContent=n==='light'?'الوضع الليلي':'الوضع النهاري';};window.addEventListener('DOMContentLoaded',function(){var b=document.getElementById('monitorThemeToggle');if(b)b.textContent=document.documentElement.getAttribute('data-admin-theme')==='light'?'الوضع الليلي':'الوضع النهاري';});})();</script></head>
<body><div class="wrap">
<h1>مراقبة WhatsApp وتشخيص فقد الرسائل</h1><div class="sub">تتبع من مزود الخدمة إلى Webhook ثم قاعدة البيانات والواجهة. لا يتم عرض مفاتيح API أو الحمولة الخام.</div>
<div class="toolbar"><button type="button" class="theme-toggle" id="monitorThemeToggle" onclick="toggleMonitorTheme()">الوضع النهاري</button><a href="?check=1">فحص الاتصال الآن</a><a class="secondary" href="whatsapp.php">العودة إلى إدارة WhatsApp</a><a class="secondary" href="whatsapp_inbox.php">فتح الصندوق الوارد</a></div>
<div class="card"><h3>اختبار استقبال رسالة من المجموعة</h3><div class="small">هذا هو الاختبار الصحيح لفقد الرسائل: أدخل معرف المجموعة، ثم اضغط بدء الاختبار. سيظهر رمز فريد؛ أرسل الرمز من هاتف أو عضو آخر داخل المجموعة، وستراقب الصفحة وصوله إلى Webhook وقاعدة البيانات لمدة 60 ثانية. لا يرسل هذا الاختبار رسالة تلقائياً عبر API.</div><form class="test-form" onsubmit="return runInboundWaTest(this)"><input name="group_id" placeholder="120363...@g.us" required pattern="[A-Za-z0-9._:-]+@g\.us"><button type="submit">بدء اختبار الاستقبال</button><span id="inbound-token" class="small"></span><span id="inbound-result" class="small"></span></form><div class="small" style="margin-top:10px;color:#f5c26b">ملاحظة: اختبار «الإرسال الصادر» القديم لا يثبت وصول رسالة واردة؛ فقد لا يعيد HetaCloud الرسالة التي أرسلها API إلى Webhook الوارد.</div></div><div class="card"><h3>اختبار الإرسال الصادر إلى المزود</h3><div class="small">اختبار تشغيلي فقط: يرسل رسالة من الحساب عبر HetaCloud. نجاحه يثبت قبول المزود للإرسال، وعدم ظهور Webhook بعده لا يثبت فقد رسالة واردة.</div><form class="test-form" onsubmit="return runWaTest(this)"><input name="group_id" placeholder="120363...@g.us" required pattern="[A-Za-z0-9._:-]+@g\.us"><button type="submit">إرسال اختبار صادر</button><span id="test-result" class="small"></span></form></div>
<?php if ($checks): ?><div class="card"><b>نتيجة فحص الاتصال</b><div class="grid">
<?php foreach ([['provider','HetaCloud'],['webhook','Webhook الموقع']] as [$key,$title]): $c=$checks[$key]; ?><div class="card"><div class="label"><?=waMonE($title)?></div><div class="value <?=$c['ok']?'ok':'bad'?>"><?=$c['ok']?'متاح':'تعذر الاتصال'?></div><div class="small">HTTP <?=waMonE($c['code'])?> · <?=waMonE($c['ms'])?> ms</div><?php if($c['error']): ?><div class="err">cURL: <?=waMonE($c['error'])?></div><?php endif; ?></div><?php endforeach; ?></div></div><?php endif; ?>
<div class="grid">
<div class="card"><div class="label">قاعدة البيانات</div><div class="value <?=$db['ok']?'ok':'bad'?>"><?=$db['ok']?'متصلة':'فشل الاتصال'?></div><div class="small"><?=waMonE($db['ms'])?> ms<?=$db['error']?' · '.waMonE($db['error']):''?></div></div>
<div class="card"><div class="label">آخر Webhook</div><div class="value <?=$latestWebhook?'ok':'bad'?>"><?=$latestWebhook?waMonAgo($latestWebhook['created_at']):'لا يوجد'?></div><div class="small"><?=$latestWebhook?waMonE($latestWebhook['event']):'لم يصل حدث'?></div></div>
<div class="card"><div class="label">آخر رسالة محفوظة</div><div class="value <?=$latestMessage?'ok':'bad'?>"><?=$latestMessage?waMonAgo($latestMessage['received_at']):'لا توجد'?></div><div class="small"><?=$latestMessage?waMonE($latestMessage['group_name']?:$latestMessage['group_id']):'لا توجد بيانات'?></div></div>
<div class="card"><div class="label">حالة الجهاز الأخيرة</div><div class="value <?=$latestDevice&&$latestDevice['status']==='connected'?'ok':'warn'?>"><?=$latestDevice?waMonE($latestDevice['status']):'لا توجد'?></div><div class="small"><?=$latestDevice?waMonAgo($latestDevice['logged_at']):'لا يوجد سجل'?></div></div>
</div>
<div class="notice <?=(!$latestWebhook||($latestWebhook&&$latestWebhook['error'])||$deviceOffline)?'warning':''?>"><b>الاستنتاج الحالي:</b> <?=waMonE($diagnosis)?></div>
<?php if ($deviceOffline): ?><div class="notice warning"><b>تنبيه انقطاع الجهاز:</b> آخر سجل للجهاز هو <b>disconnected</b> منذ <?=waMonE(waMonAgo($latestDevice['logged_at']))?>. مدة الانقطاع التقريبية: <span class="duration"><?=waMonE($offlineSeconds !== null ? ($offlineSeconds < 60 ? $offlineSeconds.' ثانية' : floor($offlineSeconds/60).' دقيقة') : 'غير متاحة')?></span>. لا يمكن للموقع استرجاع رسالة لم يرسلها HetaCloud؛ أعد تنشيط الجلسة ثم أعد الاختبار.</div><?php endif; ?>
<div class="card"><h3>جاهزية مكونات المراقبة</h3><div class="grid"><?php foreach($tables as $name=>$exists): ?><div><span class="badge <?=$exists?'ok':'bad'?>"><?=$exists?'موجود':'مفقود'?></span> <span class="small"><?=waMonE($name)?></span></div><?php endforeach; ?></div></div>
<div class="card"><h3>آخر 50 عملية تتبع</h3><div class="small" style="margin-bottom:10px">أرسل عدة رسائل اختبارية من المجموعة، ثم حدّث الصفحة. المرحلة `completed` تعني وصول الرسالة وتحليلها وحفظها بنجاح.</div><div class="table-wrap"><table><thead><tr><th>الوقت</th><th>معرّف الرسالة</th><th>المجموعة</th><th>المرحلة</th><th>HTTP</th><th>المعالجة</th><th>إدراج DB</th><th>الخطأ</th></tr></thead><tbody>
<?php foreach($traces as $r): ?><tr><td dir="ltr"><?=waMonE($r['received_at'])?></td><td dir="ltr"><?=waMonE($r['message_id']?:$r['request_id'])?></td><td><?=waMonE($r['group_name']?:$r['group_id']?:'—')?></td><td><span class="badge <?=$r['stage']==='completed'?'ok':($r['stage']==='failed'?'bad':'warn')?>"><?=waMonE($r['stage'])?></span></td><td><?=waMonE($r['http_status']?:'—')?></td><td><?=waMonE($r['processing_ms']!==null?$r['processing_ms'].' ms':'—')?></td><td><?=waMonE($r['db_insert_ms']!==null?$r['db_insert_ms'].' ms':'—')?></td><td class="err"><?=waMonE($r['error']?:'—')?></td></tr><?php endforeach; ?><?php if(!$traces): ?><tr><td colspan="8" class="muted">لا توجد سجلات تتبع بعد. أرسل رسالة جديدة بعد رفع Webhook المعدل.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>سجل Webhook المختصر</h3><div class="table-wrap"><table><thead><tr><th>الرقم</th><th>الحدث</th><th>المعالجة</th><th>الخطأ</th><th>وقت الوصول</th></tr></thead><tbody><?php foreach($logs as $r): ?><tr><td><?=waMonE($r['id'])?></td><td><?=waMonE($r['event'])?></td><td><span class="badge <?=$r['processed']?'ok':'warn'?>"><?=$r['processed']?'نعم':'قيد المعالجة'?></span></td><td class="err"><?=waMonE($r['error']?:'—')?></td><td dir="ltr"><?=waMonE($r['created_at'])?></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="5" class="muted">لا يوجد Webhook مسجل.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="card"><h3>الفجوات بين رسائل Webhook</h3><div class="small">الفجوة تقاس بين رسالتي Webhook متتاليتين حسب وقت الوصول إلى الاستضافة. الفجوة الأكبر من 60 ثانية تظهر باللون الأحمر، وهي مؤشر على انقطاع وصول الأحداث إلى الموقع خلال تلك المدة، وليست دليلاً وحدها على سبب الانقطاع.</div><div class="table-wrap"><table><thead><tr><th>الرقم</th><th>وقت الوصول</th><th>الفجوة عن الرسالة السابقة</th></tr></thead><tbody><?php foreach($gaps as $r): $gap=$r['gap_seconds']===null?null:(int)$r['gap_seconds']; ?><tr class="<?=$gap !== null && $gap > 60 ? 'gap-big' : ''?>"><td><?=waMonE($r['id'])?></td><td dir="ltr"><?=waMonE($r['created_at'])?></td><td><?php if($gap===null): ?><span class="muted">—</span><?php elseif($gap>60): ?><b><?=waMonE($gap)?> ثانية</b><?php else: ?><span class="gap-ok"><?=waMonE($gap)?> ثانية</span><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$gaps): ?><tr><td colspan="3" class="muted">لا توجد رسائل Webhook كافية لحساب الفجوات.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="small">للتشخيص الدقيق: أرسل 3 رسائل متباعدة داخل مجموعة، ثم قارن وقت الإرسال في WhatsApp مع `received_at` ومرحلة التتبع. إذا لم يظهر أي Webhook فالمشكلة قبل الاستضافة؛ إذا ظهر Webhook بحالة failed فالمشكلة في المعالجة أو قاعدة البيانات؛ وإذا ظهر completed فالرسالة وصلت وحُفظت.</div>
</div><script>function runInboundWaTest(form){const tokenOut=document.getElementById('inbound-token'),out=document.getElementById('inbound-result'),group=form.elements.group_id.value.trim();tokenOut.textContent='جاري تجهيز نافذة الاختبار...';out.textContent='';fetch('?action=start_incoming_test&group_id='+encodeURIComponent(group),{headers:{'Accept':'application/json'}}).then(r=>r.json().then(x=>({ok:r.ok,data:x}))).then(({ok,data})=>{if(!ok||!data.ok){tokenOut.textContent='';out.textContent=data.error||'تعذر بدء الاختبار';out.className='err';return;}tokenOut.textContent='أرسل هذا الرمز داخل المجموعة من هاتف/عضو آخر: '+data.token;out.textContent='بانتظار الرسالة الواردة لمدة 60 ثانية...';out.className='warn';const started=Date.now();const timer=setInterval(()=>{fetch('?action=check_incoming_test&group_id='+encodeURIComponent(data.group_id)+'&token='+encodeURIComponent(data.token)+'&started_at='+encodeURIComponent(data.started_at),{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(x=>{if(x.received){clearInterval(timer);out.textContent='نجح الاختبار: وصلت الرسالة إلى Webhook عند '+x.received_at;out.className='gap-ok';}else if(Date.now()-started>=60000){clearInterval(timer);out.textContent='لم تصل الرسالة إلى Webhook خلال 60 ثانية؛ راجع جلسة HetaCloud وحالة الجهاز.';out.className='err';}}).catch(()=>{});},2000);}).catch(()=>{tokenOut.textContent='';out.textContent='تعذر الاتصال بصفحة المراقبة';out.className='err';});return false;}function runWaTest(form){const out=document.getElementById('test-result');const group=form.elements.group_id.value.trim();out.textContent='جاري الإرسال إلى HetaCloud...';fetch('?action=send_test&group_id='+encodeURIComponent(group),{headers:{'Accept':'application/json'}}).then(r=>r.json().then(x=>({ok:r.ok,data:x}))).then(({ok,data})=>{if(!ok||!data.ok){out.textContent='فشل الإرسال: '+(data.error||('HTTP '+data.provider_http));out.className='err';return;}out.textContent='تم قبول الإرسال الصادر لدى HetaCloud؛ هذا الاختبار لا يثبت وصول رسالة واردة إلى Webhook.';out.className='warn';}).catch(()=>{out.textContent='تعذر قراءة نتيجة الاختبار؛ أعد تحميل الصفحة وافحص السجل.';out.className='err';});return false;}</script></body></html>
