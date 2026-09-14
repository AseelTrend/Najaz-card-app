<?php
/**
 * تشخيص الرد العكسي لطلبات سوا — ملف مؤقت للرفع داخل admin/
 * هذا الملف للقراءة فقط: لا يرسل واتساب، لا يعدل قاعدة البيانات، ولا يعرض raw_payload أو المفاتيح.
 * يُحذف فور انتهاء التشخيص.
 */
require_once '../includes/config.php';
requireAdmin($pdo);

header('Content-Type: text/html; charset=utf-8');

function srd_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function srd_table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function srd_columns(PDO $pdo, string $table): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        return array_fill_keys(array_map(static fn($r) => (string)$r['Field'], $rows), true);
    } catch (Throwable $e) {
        return [];
    }
}

function srd_mask_id($value): string {
    $value = trim((string)$value);
    if ($value === '') return '—';
    if (strlen($value) <= 12) return $value;
    return substr($value, 0, 5) . '…' . substr($value, -7);
}

function srd_mask_text($value): string {
    $text = trim((string)$value);
    if ($text === '') return '—';
    // نعرض بنية النص اللازمة للتشخيص مع إخفاء الأرقام الطويلة.
    $text = preg_replace('/[0-9٠-٩]{4,}/u', '[رقم محجوب]', $text) ?? $text;
    if (function_exists('mb_substr')) return mb_substr($text, 0, 180, 'UTF-8');
    return substr($text, 0, 180);
}

function srd_extract_text(array $row): string {
    foreach (['message','message_text','body','text','content','msg','caption'] as $key) {
        if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') return trim($row[$key]);
    }
    return '';
}

function srd_parse_lengths(array $rule): array {
    $raw = (string)($rule['digit_lengths'] ?? '');
    if ($raw === '') $raw = (string)($rule['digit_length'] ?? '14');
    $out = [];
    foreach (preg_split('/[^0-9]+/', $raw) as $part) {
        $n = (int)$part;
        if ($n >= 4 && $n <= 32) $out[$n] = $n;
    }
    return array_values($out ?: [14]);
}

function srd_extract_code(string $text, string $prefix, array $lengths): string {
    $prefix = trim($prefix);
    if ($prefix === '') return '';
    $lengthPattern = implode('|', array_map(static fn($n) => '[0-9]{' . (int)$n . '}', $lengths));
    $pattern = '/(?<!\\S)' . preg_quote($prefix, '/') . '(?:\\s|:|-)+(' . $lengthPattern . ')(?![0-9])/u';
    return preg_match($pattern, $text, $match) ? (string)$match[1] : '';
}

function srd_reply_amount(string $text, string $keyword): ?float {
    $keyword = trim($keyword);
    if ($keyword === '') return null;
    $pattern = '/(?<!\\S)' . preg_quote($keyword, '/') . '(?:\\s|:|-)+([0-9]+(?:[.,][0-9]+)?)(?![0-9])/u';
    if (!preg_match($pattern, $text, $match)) return null;
    return (float)str_replace(',', '.', $match[1]);
}

function srd_nested_value(array $payload, array $keys): string {
    $stack = [$payload];
    while ($stack) {
        $node = array_pop($stack);
        foreach ($node as $key => $value) {
            if (in_array((string)$key, $keys, true) && is_scalar($value) && trim((string)$value) !== '') return trim((string)$value);
            if (is_array($value)) $stack[] = $value;
        }
    }
    return '';
}

function srd_quote_info(array $row): array {
    $raw = (string)($row['raw_payload'] ?? '');
    if ($raw === '') return ['id' => '', 'text' => '', 'available' => false];
    $payload = json_decode($raw, true);
    if (!is_array($payload)) return ['id' => '', 'text' => '', 'available' => false];
    $id = srd_nested_value($payload, ['quotedMessageId','quoted_message_id','quotedId','stanzaId']);
    $text = srd_nested_value($payload, ['quotedMessage','quoted_message','quotedMsg','quoted_message_content']);
    return ['id' => $id, 'text' => $text, 'available' => ($id !== '' || $text !== '')];
}

function srd_group_match(string $actual, string $configured): bool {
    $normalize = static function (string $v): string {
        $v = strtolower(trim($v));
        $v = preg_replace('/\\s+/', '', $v) ?? $v;
        return preg_replace('/@g\\.us$/', '', $v) ?? $v;
    };
    return $normalize($actual) !== '' && $normalize($actual) === $normalize($configured);
}

function srd_status_class(string $status): string {
    return in_array($status, ['ok','warning','error'], true) ? $status : 'info';
}

function srd_check(string $label, string $status, string $detail): string {
    return '<tr><td><strong>' . srd_h($label) . '</strong></td><td><span class="badge ' . srd_status_class($status) . '">' . srd_h($status) . '</span></td><td>' . srd_h($detail) . '</td></tr>';
}

$ruleId = max(0, (int)($_GET['rule_id'] ?? 0));
$limit = min(100, max(10, (int)($_GET['limit'] ?? 40)));
$tables = [
    'wa_incoming_messages',
    'whatsapp_relay_rules',
    'whatsapp_relay_logs',
    'whatsapp_relay_reply_claims',
    'whatsapp_settings',
];
$exists = [];
$cols = [];
foreach ($tables as $table) {
    $exists[$table] = srd_table_exists($pdo, $table);
    $cols[$table] = $exists[$table] ? srd_columns($pdo, $table) : [];
}

$rules = [];
$selectedRule = null;
$errors = [];
if ($exists['whatsapp_relay_rules']) {
    try {
        $rules = $pdo->query("SELECT * FROM whatsapp_relay_rules ORDER BY is_active DESC,id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rules as $candidate) {
            if ((int)$candidate['id'] === $ruleId) {
                $selectedRule = $candidate;
                break;
            }
        }
        if (!$selectedRule && $rules) $selectedRule = $rules[0];
    } catch (Throwable $e) {
        $errors[] = 'تعذر قراءة قواعد سوا.';
    }
}

$checks = [];
foreach ($tables as $table) {
    $checks[] = srd_check('جدول ' . $table, $exists[$table] ? 'ok' : 'error', $exists[$table] ? 'موجود' : 'غير موجود');
}
if ($selectedRule) {
    $source = trim((string)($selectedRule['source_group_id'] ?? ''));
    $target = trim((string)($selectedRule['target_group_id'] ?? ''));
    $checks[] = srd_check('القاعدة المختارة', !empty($selectedRule['is_active']) ? 'ok' : 'warning', '#' . (int)$selectedRule['id'] . ' — ' . (string)($selectedRule['name'] ?? 'بدون اسم'));
    $checks[] = srd_check('مصدر سوا', $source !== '' ? 'ok' : 'error', 'المعرّف: ' . srd_mask_id($source) . ' | الطول: ' . strlen($source));
    $checks[] = srd_check('مستلم سوا', $target !== '' ? 'ok' : 'error', 'المعرّف: ' . srd_mask_id($target) . ' | الطول: ' . strlen($target));
} else {
    $checks[] = srd_check('قاعدة سوا', 'error', 'لا توجد قاعدة قابلة للقراءة.');
}

$sourceMessages = [];
$targetMessages = [];
$waiting = [];
$recentRules = [];
if ($selectedRule && $exists['wa_incoming_messages']) {
    $source = trim((string)$selectedRule['source_group_id']);
    $target = trim((string)$selectedRule['target_group_id']);
    $lastId = (int)($selectedRule['last_processed_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT id,message_id,group_id,is_group,message_type,message,caption,received_at FROM wa_incoming_messages WHERE group_id=? AND is_group=1 AND id>? ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$source, $lastId]);
        $sourceMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $checks[] = srd_check('رسائل مصدر جديدة بعد last_processed_id', count($sourceMessages) ? 'ok' : 'warning', count($sourceMessages) . ' رسالة | last_processed_id=' . $lastId);
    } catch (Throwable $e) {
        $errors[] = 'تعذر قراءة رسائل المصدر.';
    }
    if ($exists['whatsapp_relay_logs']) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM whatsapp_relay_logs WHERE rule_id=? ORDER BY id DESC LIMIT {$limit}");
            $stmt->execute([(int)$selectedRule['id']]);
            $recentRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $waiting = array_values(array_filter($recentRules, static fn($r) => ($r['status'] ?? '') === 'waiting_reply'));
            $checks[] = srd_check('سجلات طلبات سوا', count($recentRules) ? 'ok' : 'warning', count($recentRules) . ' سجل حديث');
            $checks[] = srd_check('طلبات بانتظار الرد', count($waiting) ? 'warning' : 'info', count($waiting) . ' طلب');
        } catch (Throwable $e) {
            $errors[] = 'تعذر قراءة سجل طلبات سوا.';
        }
    }
    if ($exists['wa_incoming_messages'] && $waiting) {
        try {
            $earliest = min(array_map(static fn($r) => (string)$r['forwarded_at'], $waiting));
            $stmt = $pdo->prepare("SELECT id,message_id,group_id,is_group,message_type,message,caption,raw_payload,received_at FROM wa_incoming_messages WHERE group_id=? AND is_group=1 AND received_at>=? ORDER BY received_at ASC,id ASC LIMIT {$limit}");
            $stmt->execute([$target, $earliest]);
            $targetMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $checks[] = srd_check('رسائل مجموعة المستلم بعد أول طلب', count($targetMessages) ? 'ok' : 'warning', count($targetMessages) . ' رسالة | بعد ' . $earliest);
        } catch (Throwable $e) {
            $errors[] = 'تعذر قراءة رسائل مجموعة المستلم.';
        }
    }
}

$settings = [];
if ($exists['whatsapp_settings']) {
    try {
        $rows = $pdo->query("SELECT setting_key,setting_value,updated_at FROM whatsapp_settings WHERE setting_key IN ('heta_sender','activation_enabled','relay_worker_token') ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $settings[(string)$row['setting_key']] = $row;
    } catch (Throwable $e) {
        $errors[] = 'تعذر قراءة إعدادات واتساب الآمنة.';
    }
}

?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تشخيص الرد العكسي — سوا</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f4f6f9;color:#1f2937;margin:0;padding:24px;line-height:1.7}.wrap{max-width:1300px;margin:auto}.card{background:#fff;border:1px solid #dbe2ea;border-radius:12px;padding:18px;margin:0 0 18px;box-shadow:0 2px 8px #0000000b}h1{margin:0 0 8px;font-size:24px}h2{font-size:18px;margin:0 0 12px;color:#123c68}.notice{background:#fff8e1;border:1px solid #f0c36d;border-radius:10px;padding:12px 14px;margin:14px 0}.danger{background:#fff0f0;border-color:#e3a1a1}.muted{color:#64748b;font-size:13px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:9px 8px;border-bottom:1px solid #e5e7eb;text-align:right;vertical-align:top}th{background:#f8fafc;color:#334155}.badge{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;background:#e2e8f0}.badge.ok{background:#dcfce7;color:#166534}.badge.warning{background:#fef3c7;color:#92400e}.badge.error{background:#fee2e2;color:#991b1b}.badge.info{background:#e0f2fe;color:#075985}.scroll{overflow:auto}form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}select,input,button{font:inherit;padding:8px 10px;border:1px solid #cbd5e1;border-radius:7px}button{background:#145da0;color:white;border:0;cursor:pointer}.code{font-family:monospace;direction:ltr;text-align:left;display:inline-block}.small{font-size:12px}.good{color:#166534}.bad{color:#991b1b}
</style>
</head>
<body><div class="wrap">
<div class="card">
<h1>تشخيص الرد العكسي لطلبات سوا</h1>
<p class="muted">هذا التقرير يقرأ قاعدة البيانات فقط. لا يرسل واتساب، لا يشغّل العامل، لا ينشئ سجلاً، ولا يعرض المفتاح أو raw payload. احذف الملف بعد انتهاء التشخيص.</p>
<div class="notice"><strong>طريقة الاستخدام:</strong> اختر قاعدة سوا، ثم أرسل أو استخدم بيانات اختبار موجودة مسبقاً. التقرير يوضح هل وصلت رسالة المصدر، وهل أُنشئ طلب، وهل وصلت رسالة الرد إلى مجموعة المستلم، وهل طابقت كلمة الرد/المبلغ والاقتباس.</div>
<form method="get"><label for="rule_id">قاعدة سوا:</label><select id="rule_id" name="rule_id"><option value="0">القاعدة الأولى</option><?php foreach ($rules as $rule): ?><option value="<?= (int)$rule['id'] ?>" <?= $selectedRule && (int)$selectedRule['id'] === (int)$rule['id'] ? 'selected' : '' ?>>#<?= (int)$rule['id'] ?> — <?= srd_h($rule['name'] ?? '') ?><?= !empty($rule['is_active']) ? ' — مفعّلة' : ' — متوقفة' ?></option><?php endforeach; ?></select><label for="limit">عدد السجلات:</label><input id="limit" name="limit" type="number" min="10" max="100" value="<?= (int)$limit ?>"><button type="submit">تحديث التقرير</button></form>
</div>
<?php if ($errors): ?><div class="card notice danger"><strong>تنبيهات:</strong><ul><?php foreach ($errors as $error): ?><li><?= srd_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><h2>الخلاصة</h2><div class="scroll"><table><thead><tr><th>الفحص</th><th>الحالة</th><th>التفصيل</th></tr></thead><tbody><?= implode('', $checks) ?></tbody></table></div></div>
<?php if ($selectedRule): ?>
<div class="card"><h2>إعداد القاعدة المختارة</h2><div class="scroll"><table><tbody>
<tr><th>رقم القاعدة</th><td>#<?= (int)$selectedRule['id'] ?></td><th>الحالة</th><td><?= !empty($selectedRule['is_active']) ? '<span class="good">مفعّلة</span>' : '<span class="bad">متوقفة</span>' ?></td></tr>
<tr><th>البادئة</th><td><span class="code"><?= srd_h($selectedRule['trigger_prefix'] ?? '') ?></span></td><th>أطوال الرقم</th><td><?= srd_h($selectedRule['digit_lengths'] ?? ($selectedRule['digit_length'] ?? '')) ?></td></tr>
<tr><th>كلمة الرد</th><td><span class="code"><?= srd_h($selectedRule['reply_keyword'] ?? '') ?></span></td><th>حدود المبلغ</th><td><?= srd_h($selectedRule['reply_min_amount'] ?? '0') ?> — <?= srd_h($selectedRule['reply_max_amount'] ?? 'بدون حد') ?></td></tr>
<tr><th>المصدر</th><td><span class="code"><?= srd_h(srd_mask_id($selectedRule['source_group_id'] ?? '')) ?></span></td><th>المستلم</th><td><span class="code"><?= srd_h(srd_mask_id($selectedRule['target_group_id'] ?? '')) ?></span></td></tr>
<tr><th>آخر رسالة معالجة</th><td><?= (int)($selectedRule['last_processed_id'] ?? 0) ?></td><th>مهلة الرد</th><td><?= (int)($selectedRule['reply_timeout_min'] ?? 0) ?> دقيقة</td></tr>
</tbody></table></div></div>
<?php endif; ?>
<?php if ($sourceMessages): ?><div class="card"><h2>رسائل المصدر الجديدة التي يقرأها العامل</h2><p class="muted">إذا كانت الرسالة موجودة هنا، فWebhook حفظها في المجموعة التي تطابق القاعدة. يظهر النص بعد إخفاء الأرقام الطويلة.</p><div class="scroll"><table><thead><tr><th>صف الرسالة</th><th>المعرّف</th><th>المجموعة</th><th>النص الآمن</th><th>كود سوا</th><th>التاريخ</th></tr></thead><tbody><?php foreach ($sourceMessages as $msg): $text=srd_extract_text($msg); $code=srd_extract_code($text,(string)($selectedRule['trigger_prefix']??''),srd_parse_lengths($selectedRule)); ?><tr><td>#<?= (int)$msg['id'] ?></td><td><?= srd_h(srd_mask_id($msg['message_id'] ?? '')) ?></td><td><?= srd_h(srd_mask_id($msg['group_id'] ?? '')) ?><br><span class="small">is_group=<?= (int)$msg['is_group'] ?></span></td><td><?= srd_h(srd_mask_text($text)) ?></td><td><?= $code !== '' ? '<span class="good">موجود</span>' : '<span class="bad">غير مطابق</span>' ?></td><td><?= srd_h($msg['received_at'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($recentRules): ?><div class="card"><h2>سجل طلبات سوا</h2><div class="scroll"><table><thead><tr><th>الطلب</th><th>الكود</th><th>الحالة</th><th>رسالة الإرسال</th><th>رسالة الرد</th><th>الخطأ المختصر</th><th>التواريخ</th></tr></thead><tbody><?php foreach ($recentRules as $row): ?><tr><td>#<?= (int)$row['id'] ?></td><td><?= srd_h(srd_mask_id($row['extracted_code'] ?? '')) ?></td><td><?= srd_h($row['status'] ?? '') ?></td><td><?= srd_h(srd_mask_id($row['forwarded_message_id'] ?? '')) ?></td><td><?= srd_h(srd_mask_id($row['reply_message_id'] ?? '')) ?></td><td><?= srd_h(srd_mask_text($row['error_message'] ?? '')) ?></td><td>إرسال: <?= srd_h($row['forwarded_at'] ?? '') ?><br>رد: <?= srd_h($row['reply_received_at'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php if ($targetMessages && $selectedRule): ?><div class="card"><h2>رسائل مجموعة المستلم بعد إنشاء الطلب</h2><p class="muted">يتم تحليل كلمة الرد والاقتباس محلياً فقط. لا يتم عرض محتوى raw payload.</p><div class="scroll"><table><thead><tr><th>صف الرسالة</th><th>المعرّف</th><th>النص الآمن</th><th>كلمة الرد والمبلغ</th><th>الاقتباس</th><th>التاريخ</th></tr></thead><tbody><?php foreach ($targetMessages as $msg): $text=srd_extract_text($msg); $amount=srd_reply_amount($text,(string)($selectedRule['reply_keyword']??'')); $quote=srd_quote_info($msg); ?><tr><td>#<?= (int)$msg['id'] ?></td><td><?= srd_h(srd_mask_id($msg['message_id'] ?? '')) ?></td><td><?= srd_h(srd_mask_text($text)) ?></td><td><?= $amount === null ? '<span class="bad">لم تطابق</span>' : '<span class="good">' . srd_h((string)$amount) . '</span>' ?></td><td><?= $quote['available'] ? '<span class="good">موجود</span>' : '<span class="warning">غير موجود</span>' ?><?php if ($quote['id'] !== ''): ?><br><span class="small">ID: <?= srd_h(srd_mask_id($quote['id'])) ?></span><?php endif; ?><?php if ($quote['text'] !== ''): ?><br><span class="small">نص مقتبس: <?= srd_h(srd_mask_text($quote['text'])) ?></span><?php endif; ?></td><td><?= srd_h($msg['received_at'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<div class="card"><h2>إعدادات التشغيل الآمنة</h2><div class="scroll"><table><thead><tr><th>الإعداد</th><th>الحالة</th><th>آخر تحديث</th></tr></thead><tbody><tr><td>heta_sender</td><td><?= !empty($settings['heta_sender']['setting_value']) ? 'موجود — ' . srd_h(srd_mask_id($settings['heta_sender']['setting_value'])) : '<span class="bad">غير موجود</span>' ?></td><td><?= srd_h($settings['heta_sender']['updated_at'] ?? '') ?></td></tr><tr><td>activation_enabled</td><td><?= srd_h($settings['activation_enabled']['setting_value'] ?? 'غير موجود') ?></td><td><?= srd_h($settings['activation_enabled']['updated_at'] ?? '') ?></td></tr><tr><td>relay_worker_token</td><td><?= !empty($settings['relay_worker_token']['setting_value']) ? '<span class="good">موجود</span>' : '<span class="warning">غير موجود</span>' ?></td><td><?= srd_h($settings['relay_worker_token']['updated_at'] ?? '') ?></td></tr></tbody></table></div></div>
<div class="notice danger"><strong>مهم بعد الانتهاء:</strong> احذف هذا الملف من الخادم مباشرة. لا تترك أداة التشخيص متاحة على الموقع حتى مع وجود حماية الإدارة.</div>
</div></body></html>
