<?php
// check_fore_api.php — ضعه في المجلد الرئيسي واحذفه بعد الفحص
require_once 'includes/fore_api.php';

$r = new ReflectionClass('ForeYemenAPI');
$method = $r->getMethod('get');
$method->setAccessible(true);
$src = file_get_contents($r->getFileName());

echo '<pre style="direction:ltr;background:#0d1117;color:#cdd;padding:20px;font-size:13px">';
echo "fore_api.php path: " . $r->getFileName() . "\n\n";

if (strpos($src, 'الرد نصي') !== false) {
    echo "✅ الملف الجديد — يدعم الرد النصي\n";
} else {
    echo "❌ الملف القديم — لا يدعم الرد النصي\n";
    echo "   يجب رفع fore_api.php من includes/\n";
}

// اختبار parse مباشر
$raw = '0/0/1/English: Success Arabic: تمت العملية بنجاح #0#0#0';
$raw2 = trim($raw);
if (preg_match('/^(\d+)\/[^\/]*\/[^\/]*\/([^#\n]*)(?:#(.*))?$/', $raw2, $m)) {
    echo "\n✅ Regex يعمل على الرد النصي:\n";
    echo "   resultCode: " . $m[1] . "\n";
    echo "   desc: " . trim($m[2]) . "\n";
    $parts = isset($m[3]) ? explode('#', $m[3]) : [];
    echo "   parts: " . implode(', ', $parts) . "\n";
    echo "   loan_amount: " . ($parts[0] ?? '0') . "\n";
} else {
    echo "\n❌ Regex لم يتطابق مع: $raw\n";
}
echo '</pre>';
