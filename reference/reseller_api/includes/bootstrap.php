<?php
/**
 * Bootstrap لنظام API الموزّعين (Reseller API) — api.njaz.net
 * ───────────────────────────────────────────────────────────
 * ضع مجلد reseller_api/ هذا داخل جذر المشروع الرئيسي (بجانب مجلد includes/
 * الأساسي)، ثم اجعل الدومين الفرعي api.njaz.net يشير (Document Root) إلى
 * هذا المجلد بالذات (reseller_api/) من إعدادات استضافتك (cPanel: Subdomains).
 *
 * بهذا الشكل يبقى المشروعان (الموقع الرئيسي + API الموزّعين) على نفس
 * الاستضافة ونفس قاعدة البيانات، فقط بجذر عرض مختلف.
 */

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/admin/pricing_helper.php';
require_once dirname(__DIR__, 2) . '/includes/code_stock_helper.php';
require_once dirname(__DIR__, 2) . '/includes/cooldown_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: api-token, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

codeStockEnsureTables($pdo);

// ── ترحيل: أعمدة إضافية على orders لدعم الطلبات القادمة من API الموزّعين ──
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS api_order_id VARCHAR(40) DEFAULT NULL");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS api_order_uuid VARCHAR(64) DEFAULT NULL");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE orders ADD UNIQUE INDEX idx_api_order_id (api_order_id)");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE orders ADD UNIQUE INDEX idx_api_order_uuid (api_order_uuid)");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS source VARCHAR(20) DEFAULT 'web'");
} catch (Exception $e) {}

// ══════════════════════════════════════════════════════════════
//  رموز الأخطاء — مطابقة تماماً لتوثيق Oranos المرجعي
// ══════════════════════════════════════════════════════════════
const RESELLER_ERR = [
    // أخطاء عامة (مصادقة / نظام)
    120 => 'Api Token is required!',
    121 => 'Token error',
    122 => 'Not allowed to use API',
    123 => 'IP not allowed',
    130 => 'The site is under maintenance',
    // أخطاء الطلبات
    100 => 'Insufficient balance',
    105 => 'Quantity not available',
    106 => 'Quantity not allowed',
    107 => 'Player ID blocked',
    108 => '2FA required',
    109 => 'Product deleted or not found',
    110 => 'Product not available now',
    111 => 'Try again after 1 minute',
    112 => 'Quantity is too small',
    113 => 'Quantity is too large',
    114 => 'Unknown error',
    500 => 'Unknown error',
];

/** الترجمة العربية المقابلة لكل رمز خطأ (تُعرض بجانب الإنجليزية دائماً) */
const RESELLER_ERR_AR = [
    120 => 'رمز API مطلوب!',
    121 => 'خطأ في رمز التوكن',
    122 => 'غير مسموح لك باستخدام الـ API',
    123 => 'عنوان الـ IP غير مسموح به',
    130 => 'الموقع تحت الصيانة حالياً',
    100 => 'الرصيد غير كافٍ',
    105 => 'الكمية غير متوفرة',
    106 => 'الكمية غير مسموحة',
    107 => 'معرّف اللاعب محظور',
    108 => 'مطلوب رمز التحقق الثنائي (2FA)',
    109 => 'المنتج محذوف أو غير موجود',
    110 => 'المنتج غير متاح حالياً',
    111 => 'الرجاء المحاولة بعد دقيقة',
    112 => 'الكمية أقل من الحد المسموح',
    113 => 'الكمية أكبر من الحد المسموح',
    114 => 'خطأ غير معروف',
    500 => 'خطأ غير معروف',
];

/** إرسال خطأ بصيغة موحّدة (إنجليزي + عربي معاً) وإنهاء التنفيذ */
function resellerFail(int $code, ?string $customMsgEn = null, ?string $customMsgAr = null): void {
    http_response_code(200); // نتبع أسلوب Oranos: 200 دائماً + status في الجسم
    echo json_encode([
        'status'     => 'ERROR',
        'code'       => $code,
        'message'    => $customMsgEn ?? (RESELLER_ERR[$code] ?? 'Unknown error'),
        'message_ar' => $customMsgAr ?? (RESELLER_ERR_AR[$code] ?? 'خطأ غير معروف'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * التحقق من api-token + الـ IP + تفعيل الوصول، ويُرجع صف المستخدم (الموزّع)
 */
function resellerAuth(PDO $pdo): array {
    // وضع الصيانة
    try {
        if ((int)(getSetting('maintenance_mode') ?? 0) === 1) resellerFail(130);
    } catch (Exception $e) {}

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = '';
    foreach ($headers as $k => $v) { if (strtolower($k) === 'api-token') { $token = trim($v); break; } }
    if ($token === '') $token = trim($_GET['api-token'] ?? $_GET['api_token'] ?? '');

    if ($token === '') resellerFail(120);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE api_key=? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) resellerFail(121);

    if (empty($user['api_enabled'])) resellerFail(122);

    $allowAll = (bool)($user['api_allow_all'] ?? false);
    if (!$allowAll) {
        $allowedIps = json_decode($user['api_ips'] ?? '[]', true) ?: [];
        $myIp = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        if (!in_array($myIp, $allowedIps, true)) resellerFail(123);
    }

    return $user;
}

/** تنسيق خدمة واحدة بصيغة "منتج" مطابقة لتوثيق Oranos */
function resellerFormatProduct(PDO $pdo, array $service, array $categoryMap, array $resellerUser, bool $baseOnly = false): array {
    if ($baseOnly) {
        return ['id' => (int)$service['id'], 'name' => $service['name']];
    }

    $priceInfo = getUserServicePrice($pdo, (int)$resellerUser['id'], $service);

    $qtyValues = null;
    if ((int)$service['min_qty'] !== 1 || (int)$service['max_qty'] !== 1) {
        $qtyValues = ['min' => (int)$service['min_qty'], 'max' => (int)$service['max_qty']];
    }

    $fieldsStmt = $pdo->prepare("SELECT field_label FROM service_fields WHERE service_id=? ORDER BY sort_order ASC");
    $fieldsStmt->execute([$service['id']]);
    $params = array_column($fieldsStmt->fetchAll(), 'field_label');

    $cat = $categoryMap[$service['category_id']] ?? null;

    return [
        'id'             => (int)$service['id'],
        'name'           => $service['name'],
        'price'          => round((float)$priceInfo['price'], 4),
        'params'         => $params,
        'category_name'  => $cat['name'] ?? '',
        'available'      => (int)$service['status'] === 1 && empty($service['deleted_at']),
        'qty_values'     => $qtyValues,
        'product_type'   => $qtyValues !== null ? 'amount' : 'package',
        'parent_id'      => 0,
        'base_price'     => round((float)$service['price'], 4),
        'category_img'   => !empty($cat['image']) ? (SITE_URL . '/' . ltrim($cat['image'], '/')) : '',
    ];
}
