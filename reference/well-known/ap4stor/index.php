<?php
/**
 * =====================================================
 *  Reverse Proxy API - njaz.net/ap4stor  ->  api.ap4stor.com
 * =====================================================
 * هذا الملف يستقبل أي طلب يصل إلى:
 *   https://njaz.net/ap4stor/....
 * ويعيد توجيهه بنفس الشكل تمامًا (نفس المسار، نفس الـ Query،
 * نفس الهيدرز، نفس طريقة الطلب، ونفس محتوى الجسم) إلى:
 *   https://api.ap4stor.com/....
 * ثم يعيد للنظام المستدعي نفس الرد بالضبط (نفس الـ status code
 * ونفس المحتوى) بعد فك أي ضغط Gzip/Brotli.
 *
 * لا حاجة لأي إعدادات خاصة في Apache/Nginx على استضافة cPanel،
 * فقط PHP مع تفعيل امتداد cURL (مُفعّل افتراضيًا في كل استضافات cPanel).
 * =====================================================
 */

// ------------------------------------------------------------------
// 1) الإعدادات الأساسية
// ------------------------------------------------------------------

// الرابط الحقيقي للمزود (بدون / في النهاية)
$targetBase = 'https://api.ap4stor.com';

// اسم المجلد الذي وضعت فيه هذا الملف على استضافتك
// (إذا وضعت الملف في public_html/ap4stor/ اتركه كما هو)
$proxyFolder = '/ap4stor/';

// ------------------------------------------------------------------
// 2) استخراج المسار (Path) والـ Query String من الطلب الوارد
// ------------------------------------------------------------------

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path       = parse_url($requestUri, PHP_URL_PATH);

// إزالة اسم مجلد البروكسي من بداية المسار
if (stripos($path, $proxyFolder) === 0) {
    $path = substr($path, strlen($proxyFolder));
}
$path = '/' . ltrim($path, '/');

$queryString = $_SERVER['QUERY_STRING'] ?? '';

$targetUrl = rtrim($targetBase, '/') . $path;
if ($queryString !== '') {
    $targetUrl .= '?' . $queryString;
}

// ------------------------------------------------------------------
// 3) قراءة طريقة الطلب (GET/POST/PUT/DELETE...) وجسم الطلب
// ------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = file_get_contents('php://input');

// ------------------------------------------------------------------
// 4) قراءة كل الهيدرز الواردة من النظام المستدعي وتمريرها كما هي
//    (بما فيها api-token) باستثناء بعض الهيدرز التي يجب ألا تُمرَّر
// ------------------------------------------------------------------

function proxy_get_all_headers()
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if ($h !== false) {
            return $h;
        }
    }
    // بديل احتياطي في حال عدم توفر getallheaders() على بعض الاستضافات
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = $value;
        } elseif ($key === 'CONTENT_TYPE') {
            $headers['Content-Type'] = $value;
        } elseif ($key === 'CONTENT_LENGTH') {
            $headers['Content-Length'] = $value;
        }
    }
    return $headers;
}

$incomingHeaders = proxy_get_all_headers();

// هيدرز لا يجب تمريرها كما هي إلى المزود (يتم التعامل معها من طرف cURL)
$skipRequestHeaders = ['host', 'content-length', 'connection', 'accept-encoding'];

$forwardHeaders = [];
foreach ($incomingHeaders as $name => $value) {
    if (in_array(strtolower($name), $skipRequestHeaders, true)) {
        continue;
    }
    $forwardHeaders[] = $name . ': ' . $value;
}

// ------------------------------------------------------------------
// 5) تنفيذ الطلب الفعلي عبر cURL نحو مزود الـ API
// ------------------------------------------------------------------

$ch = curl_init($targetUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,      // نحتاج الهيدرز في الرد لإعادة تمريرها
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $forwardHeaders,
    CURLOPT_ENCODING       => '',        // يطلب gzip/deflate/br تلقائيًا ويفك ضغطها
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,     // لا نتبع أي تحويلات، نُعيد للنظام كما وصل
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 15,
]);

// إرفاق جسم الطلب لكل الطرق ما عدا GET و HEAD
if (!in_array($method, ['GET', 'HEAD'], true)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);

// ------------------------------------------------------------------
// 6) معالجة الأخطاء (فشل الاتصال بالمزود)
// ------------------------------------------------------------------

if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);

    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'ERROR',
        'message' => 'Proxy connection error: ' . $error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawResponseHeaders = substr($response, 0, $headerSize);
$responseBody       = substr($response, $headerSize);

// ------------------------------------------------------------------
// 7) إعادة نفس حالة الاستجابة (HTTP Status Code)
// ------------------------------------------------------------------

http_response_code($httpCode);

// ------------------------------------------------------------------
// 8) إعادة تمرير هيدرز الرد كما وصلت من المزود، باستثناء الهيدرز
//    التي تتعارض مع كون المحتوى قد تم فك ضغطه بالفعل بواسطة cURL
// ------------------------------------------------------------------

$skipResponseHeaders = ['transfer-encoding', 'content-encoding', 'connection', 'content-length'];

// قد يحتوي الرد على أكثر من مجموعة هيدرز إذا كان هناك تحويلات داخلية
// نأخذ آخر مجموعة هيدرز فعلية قبل بدء الجسم
$headerBlocks = preg_split('/\r\n\r\n/', trim($rawResponseHeaders));
$lastHeaderBlock = end($headerBlocks);
$headerLines = explode("\r\n", $lastHeaderBlock);

foreach ($headerLines as $line) {
    $line = trim($line);
    if ($line === '' || stripos($line, 'HTTP/') === 0) {
        continue;
    }
    $parts = explode(':', $line, 2);
    if (count($parts) < 2) {
        continue;
    }
    $name = trim($parts[0]);
    if (in_array(strtolower($name), $skipResponseHeaders, true)) {
        continue;
    }
    header($line, false);
}

// ------------------------------------------------------------------
// 9) إعادة نفس محتوى الرد كما هو تمامًا
// ------------------------------------------------------------------

echo $responseBody;
