<?php

declare(strict_types=1);

require_once '../includes/config.php';
requireAdmin($pdo);

$probeConfig = require __DIR__ . '/../wa_probe_data/config.php';
$logFile = (string)($probeConfig['log_file'] ?? (__DIR__ . '/../wa_probe_data/requests.jsonl'));
$token = (string)($probeConfig['token'] ?? '');
$rows = [];
$error = null;

if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach (array_reverse($lines) as $line) {
            $item = json_decode($line, true);
            if (is_array($item)) $rows[] = $item;
            if (count($rows) >= 100) break;
        }
    } else {
        $error = 'تعذر قراءة ملف سجل الاختبار.';
    }
}

function probeE(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function probeText(string $value, int $length): string { return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length); }
function probeJson(mixed $value): string {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return probeE($json === false ? '—' : $json);
}
function probeBody(array $row): string {
    $raw = '';
    if (!empty($row['body_base64'])) {
        $decoded = base64_decode((string)$row['body_base64'], true);
        if ($decoded !== false) $raw = $decoded;
    }
    if ($raw === '') return '—';
    if (strlen($raw) > 12000) $raw = probeText($raw, 12000) . "\n… تم اختصار الحمولة في العرض";
    $json = json_decode($raw, true);
    return probeE($json !== null ? (json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $raw) : $raw);
}
function probeGap(?string $current, ?string $previous): ?int {
    if (!$current || !$previous) return null;
    $a = strtotime($current); $b = strtotime($previous);
    return ($a !== false && $b !== false) ? max(0, $a - $b) : null;
}
function probeDuration(?int $seconds): string {
    if ($seconds === null) return '—';
    if ($seconds < 60) return $seconds . ' ثانية';
    if ($seconds < 3600) return floor($seconds / 60) . ' دقيقة و' . ($seconds % 60) . ' ثانية';
    return floor($seconds / 3600) . ' ساعة و' . floor(($seconds % 3600) / 60) . ' دقيقة';
}

$probeUrl = rtrim(SITE_URL, '/') . '/wa_webhook_probe.php?token=' . rawurlencode($token);
$count = count($rows);
$last = $rows[0] ?? null;
$lastTime = $last['received_at'] ?? null;
$lastGap = isset($rows[1]) ? probeGap($lastTime, $rows[1]['received_at'] ?? null) : null;
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Webhook تشخيصي مستقل</title>
<style>
:root{color-scheme:dark;--bg:#070b18;--card:#111827;--line:#263247;--muted:#aab5c8;--ok:#29db78;--warn:#f5c26b;--bad:#ff6b6b;--accent:#7c5cff}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#070b18,#0d1424);color:#f3f6fb;font-family:Tahoma,Arial,sans-serif;line-height:1.7}.wrap{max-width:1250px;margin:0 auto;padding:26px 16px 60px}h1{margin:0 0 8px;color:#38e77d;font-size:clamp(24px,4vw,36px)}h2{margin:0 0 12px;font-size:21px}.sub,.small{color:var(--muted);font-size:14px}.toolbar{display:flex;flex-wrap:wrap;gap:10px;margin:20px 0}.btn,a.btn{display:inline-block;border:0;border-radius:12px;padding:11px 16px;background:var(--accent);color:#fff;text-decoration:none;font-weight:700}.btn.secondary,a.btn.secondary{background:#1e2b42}.card{background:rgba(17,24,39,.94);border:1px solid var(--line);border-radius:17px;padding:18px;margin:14px 0;box-shadow:0 12px 32px rgba(0,0,0,.16)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.stat{border:1px solid var(--line);border-radius:13px;padding:14px}.label{color:var(--muted);font-size:13px}.value{font-size:22px;font-weight:700;margin-top:4px}.ok{color:var(--ok)}.warn{color:var(--warn)}.bad{color:var(--bad)}.url{direction:ltr;text-align:left;overflow:auto;background:#0b1220;border:1px solid var(--line);border-radius:10px;padding:12px;color:#dbe7ff;white-space:nowrap}.notice{border-right:4px solid var(--warn);background:#211c11;padding:12px 14px;border-radius:9px;color:#ffe1a0}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}table{border-collapse:collapse;width:100%;min-width:980px}th,td{padding:10px 11px;border-bottom:1px solid var(--line);vertical-align:top;text-align:right}th{background:#182238;color:#dbe5f7;white-space:nowrap}tr:last-child td{border-bottom:0}.gap-big td{background:rgba(255,107,107,.09)}.ltr{direction:ltr;text-align:left;font-family:Consolas,monospace;font-size:12px}.hash{max-width:190px;word-break:break-all}.code{max-width:460px;max-height:260px;overflow:auto;direction:ltr;text-align:left;white-space:pre-wrap;background:#080d18;border:1px solid #293750;border-radius:9px;padding:10px;font:12px/1.5 Consolas,monospace}.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#173d2b;color:var(--ok);font-size:12px}.empty{padding:28px;text-align:center;color:var(--muted)}@media(max-width:650px){.wrap{padding:18px 10px}.card{padding:13px}.url{font-size:11px}}
</style>
</head>
<body><main class="wrap">
<h1>Webhook تشخيصي مستقل</h1>
<div class="sub">يستقبل طلبات HetaCloud ويسجلها في ملف مستقل، من دون تحليل أو اتصال بقاعدة بيانات نجاز. استخدمه مؤقتاً فقط أثناء الاختبار.</div>
<div class="toolbar"><a class="btn secondary" href="<?=probeE(SITE_URL)?>/admin/whatsapp_monitor.php">العودة إلى مراقبة WhatsApp</a><a class="btn secondary" href="<?=probeE(SITE_URL)?>/admin/whatsapp.php">إدارة WhatsApp</a><a class="btn" href="<?=probeE($_SERVER['REQUEST_URI'] ?? '')?>">تحديث السجل</a></div>
<div class="notice"><b>تنبيه مهم:</b> الصورة السابقة توضح أن HetaCloud يسمح برابط Webhook واحد للجهاز. عند وضع رابط الاختبار في HetaCloud سيتوقف Webhook الإنتاج مؤقتاً عن استقبال الأحداث حتى تعيد الرابط إلى <span dir="ltr">register_webhook.php</span>.</div>
<div class="card"><h2>رابط الاختبار</h2><div class="small">انسخ الرابط التالي وضعه مؤقتاً في خانة Update Device Webhook داخل HetaCloud. لا تشاركه خارج لوحة الإدارة.</div><div class="url" dir="ltr"><?=probeE($probeUrl)?></div><div class="small" style="margin-top:8px">يسجل الرابط نوع الطلب، وقت الوصول، عنوان IP، الرؤوس بعد إخفاء رموز المصادقة، حجم الحمولة، بصمة SHA-256، ونسخة الحمولة الخام داخل سجل محمي.</div></div>
<div class="grid"><div class="stat"><div class="label">الطلبات المسجلة</div><div class="value ok"><?=probeE($count)?></div></div><div class="stat"><div class="label">آخر وصول</div><div class="value" dir="ltr"><?=probeE($lastTime ?: 'لا يوجد')?></div></div><div class="stat"><div class="label">الفجوة منذ الطلب السابق</div><div class="value <?=($lastGap !== null && $lastGap > 60) ? 'bad' : 'ok'?>"><?=probeE(probeDuration($lastGap))?></div></div><div class="stat"><div class="label">السجل</div><div class="value <?=is_writable(dirname($logFile)) ? 'ok' : 'bad'?>"><?=is_writable(dirname($logFile)) ? 'قابل للكتابة' : 'غير قابل للكتابة'?></div></div></div>
<?php if ($error): ?><div class="card bad"><?=probeE($error)?></div><?php endif; ?>
<div class="card"><h2>طريقة الاختبار</h2><p class="small">بعد حفظ الرابط في HetaCloud، أرسل ثلاث رسائل نصية متتالية داخل المجموعة من هاتف آخر، وسجل وقت الإرسال. ثم حدّث هذه الصفحة. إذا ظهرت الطلبات الثلاثة هنا، فهذا يثبت أن HetaCloud سلّمها إلى الرابط المستقل، وتكون المشكلة في Webhook الإنتاج أو تحليله. إذا ظهرت رسالة واحدة فقط هنا، فالفقد يحدث قبل أي كود في موقع نجاز، أي في جلسة WhatsApp أو HetaCloud أو سياسة إرسال أحداث المجموعات.</p><p class="small">بعد انتهاء الاختبار، أعد فوراً رابط الإنتاج: <span dir="ltr">https://<?=probeE(parse_url(SITE_URL, PHP_URL_HOST) ?: '')?>/register_webhook.php</span>.</p></div>
<div class="card"><h2>آخر 100 طلب خام</h2><?php if (!$rows): ?><div class="empty">لم يصل أي طلب إلى الرابط المستقل بعد.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>وقت الوصول</th><th>الفجوة</th><th>الطريقة</th><th>الحجم</th><th>JSON</th><th>البصمة</th><th>معرف الاختبار</th><th>الحمولة الخام</th></tr></thead><tbody><?php foreach ($rows as $i => $row): $gap = $i + 1 < count($rows) ? probeGap($row['received_at'] ?? null, $rows[$i+1]['received_at'] ?? null) : null; $summary = is_array($row['json_summary'] ?? null) ? $row['json_summary'] : null; ?><tr class="<?=($gap !== null && $gap > 60) ? 'gap-big' : ''?>"><td class="ltr"><?=probeE($row['received_at'] ?? '—')?></td><td><?=probeE(probeDuration($gap))?></td><td><span class="badge"><?=probeE($row['method'] ?? '—')?></span></td><td dir="ltr"><?=probeE((string)($row['captured_length'] ?? 0))?> / <?=probeE((string)($row['declared_length'] ?? 0))?> B</td><td><?=($summary && !empty($summary['valid_json'])) ? '<span class="ok">صالح</span>' : '<span class="warn">غير JSON/فارغ</span>'?><div class="small"><?=probeE(implode(', ', array_slice((array)($summary['keys'] ?? []),0,8)))?></div></td><td class="ltr hash"><?=probeE($row['body_sha256'] ?? '—')?></td><td class="ltr"><?=probeE($row['request_id'] ?? '—')?></td><td><div class="code"><?=probeBody($row)?></div></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</main></body></html>
