<?php
/**
 * API: جلب خدمات مزود معين للـ autocomplete
 * GET /api/provider_services.php?provider_id=X&q=search_term
 */
require_once dirname(__DIR__) . '/includes/config.php';
requireStaffOrAdmin($pdo, 'perm_services_view');

header('Content-Type: application/json; charset=utf-8');

$providerId = (int)($_GET['provider_id'] ?? 0);
$q          = strtolower(trim($_GET['q'] ?? ''));

if (!$providerId) {
    echo json_encode(['ok' => false, 'error' => 'missing provider_id']);
    exit;
}

// جلب بيانات المزود
$stmt = $pdo->prepare("SELECT * FROM providers WHERE id=? AND status=1");
$stmt->execute([$providerId]);
$prov = $stmt->fetch();

if (!$prov) {
    echo json_encode(['ok' => false, 'error' => 'provider not found']);
    exit;
}

// ── دوال الـ API ──────────────────────────────────────────────
function headerApiGet($baseUrl, $apiKey, $endpoint) {
    $url = rtrim($baseUrl, '/') . $endpoint;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['api-token: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function smmGet($apiUrl, $apiKey, $postData) {
    $postData['key'] = $apiKey;
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// ── جلب الخدمات حسب نوع المزود ────────────────────────────────
$services = [];

try {
    if (in_array($prov['provider_type'], ['oranos', 'ap4stor'])) {
        // Oranos / ap4stor — يرجع مصفوفة منتجات
        $raw = headerApiGet($prov['api_url'], $prov['api_key'], '/client/api/products');
        if (is_array($raw)) {
            foreach ($raw as $item) {
                // دعم كلا الصيغتين
                $id    = $item['id']    ?? $item['product_id'] ?? '';
                $name  = $item['name']  ?? $item['title']      ?? '';
                $price = $item['price'] ?? $item['cost']       ?? '';
                $cat   = $item['category'] ?? $item['cat'] ?? '';

                if (!$id) continue;

                $qty    = $item['qty_values'] ?? null;
                $min    = is_array($qty) ? ($qty['min'] ?? 1) : 1;
                $max    = is_array($qty) ? ($qty['max'] ?? 9999) : 9999;
                $params = $item['params'] ?? [];
                $cat    = $item['category_name'] ?? $item['category'] ?? $item['cat'] ?? '';

                $services[] = [
                    'id'          => (string)$id,
                    'name'        => $name,
                    'price'       => $price,
                    'category'    => $cat,
                    'min'         => (int)$min,
                    'max'         => (int)$max,
                    'params'      => is_array($params) ? $params : [],
                    'available'   => (bool)($item['available'] ?? true),
                    'description' => $item['description'] ?? $item['desc'] ?? '',
                ];
            }
        }
    } else {
        // SMM Panel — action=services
        $raw = smmGet($prov['api_url'], $prov['api_key'], ['action' => 'services']);
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $id    = $item['service'] ?? $item['id'] ?? '';
                $name  = $item['name']    ?? '';
                $price = $item['rate']    ?? $item['price'] ?? '';
                $cat   = $item['category'] ?? $item['type'] ?? '';

                if (!$id) continue;

                $services[] = [
                    'id'          => (string)$id,
                    'name'        => $name,
                    'price'       => $price,
                    'category'    => $cat,
                    'min'         => (int)($item['min'] ?? 1),
                    'max'         => (int)($item['max'] ?? 9999),
                    'params'      => [],
                    'available'   => true,
                    'description' => $item['description'] ?? $item['desc'] ?? '',
                ];
            }
        }
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// ── فلترة بالبحث ─────────────────────────────────────────────
if ($q !== '') {
    $services = array_values(array_filter($services, function($s) use ($q) {
        return str_contains(strtolower($s['name']), $q)
            || str_contains(strtolower($s['id']), $q)
            || str_contains(strtolower($s['category']), $q);
    }));
}

// إرجاع أول 50 نتيجة فقط
$services = array_slice($services, 0, 50);

echo json_encode([
    'ok'       => true,
    'provider' => $prov['name'],
    'type'     => $prov['provider_type'],
    'count'    => count($services),
    'services' => $services,
], JSON_UNESCAPED_UNICODE);
