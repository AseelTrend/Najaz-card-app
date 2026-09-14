<?php
$src = file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/telecom.php');
echo '<pre style="background:#0d1117;color:#cdd;padding:20px;font-size:13px">';
if (strpos($src, 'loan_amount') !== false) {
    echo "✅ telecom.php الجديد — يحتوي على loan_amount\n";
} else {
    echo "❌ telecom.php القديم — لا يحتوي على loan_amount\n";
    echo "   يجب رفع telecom.php في المجلد الرئيسي\n";
}
if (strpos($src, 'الرد نصي') !== false || strpos($src, 'السلفة — الحقل الصحيح') !== false) {
    echo "✅ يحتوي على إصلاح السلفة\n";
} else {
    echo "❌ لا يحتوي على إصلاح السلفة\n";
}
echo '</pre>';
