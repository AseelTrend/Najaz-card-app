<?php
/**
 * api/currency_rates.php
 * جلب/تحديث أسعار الصرف من API أو Cache
 * يُستدعى من currencies.php في الأدمن
 */
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    echo json_encode(['ok' => false, 'error' => 'غير مصرح']);
    exit;
}

$action = $_GET['action'] ?? 'get';

// ── API مجاني: exchangerate-api.com (لا يحتاج مفتاح للإصدار المجاني) ─────────
// بديل: open.er-api.com/v6/latest/USD
function fetchFromAPI(): array {
    $urls = [
        'https://open.er-api.com/v6/latest/USD',
        'https://api.exchangerate-api.com/v4/latest/USD',
    ];
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $data = json_decode($res, true);
            // open.er-api.com
            if (!empty($data['rates'])) return $data['rates'];
            // exchangerate-api.com v4
            if (!empty($data['rates'])) return $data['rates'];
        }
    }
    return [];
}

if ($action === 'refresh') {
    // جلب من API وتخزين في cache
    $rates = fetchFromAPI();
    if (empty($rates)) {
        echo json_encode(['ok' => false, 'error' => 'فشل الاتصال بـ API — تحقق من الإنترنت']);
        exit;
    }

    global $pdo;
    $pdo->prepare("INSERT INTO currency_api_cache (base,rates_json,fetched_at) VALUES ('USD',?,NOW())
        ON DUPLICATE KEY UPDATE rates_json=VALUES(rates_json), fetched_at=NOW()")
        ->execute([json_encode($rates)]);

    // تحديث العملات التي مصدرها API
    $apiCurrencies = $pdo->query("SELECT * FROM display_currencies WHERE rate_source='api'")->fetchAll();
    $updated = 0;
    foreach ($apiCurrencies as $cur) {
        $code = $cur['currency_code'];
        if (isset($rates[$code])) {
            $pdo->prepare("UPDATE display_currencies SET rate_from_usd=?, rate_api=?, rate_api_at=NOW() WHERE id=?")
                ->execute([$rates[$code], $rates[$code], $cur['id']]);
            $updated++;
        }
    }

    echo json_encode(['ok' => true, 'updated' => $updated, 'total' => count($rates), 'rates' => $rates]);
    exit;
}

if ($action === 'get_cache') {
    // جلب الـ cache المحلي
    $cache = $pdo->query("SELECT * FROM currency_api_cache WHERE base='USD' LIMIT 1")->fetch();
    if (!$cache) {
        echo json_encode(['ok' => false, 'error' => 'لا يوجد cache — يرجى التحديث أولاً']);
        exit;
    }
    $rates = json_decode($cache['rates_json'], true);
    echo json_encode(['ok' => true, 'rates' => $rates, 'fetched_at' => $cache['fetched_at']]);
    exit;
}

if ($action === 'search') {
    // البحث في قائمة العملات من cache
    $q     = strtoupper(trim($_GET['q'] ?? ''));
    $cache = $pdo->query("SELECT rates_json FROM currency_api_cache WHERE base='USD' LIMIT 1")->fetch();
    if (!$cache) {
        echo json_encode(['ok' => false, 'results' => []]);
        exit;
    }
    $rates   = json_decode($cache['rates_json'], true);
    $results = [];
    foreach ($rates as $code => $rate) {
        if (!$q || str_contains($code, $q)) {
            $results[] = ['code' => $code, 'rate' => $rate];
        }
    }
    echo json_encode(['ok' => true, 'results' => array_slice($results, 0, 50)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'action غير معروف']);
