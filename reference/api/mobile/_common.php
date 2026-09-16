<?php
// ============================================
// api/mobile/_common.php
// إعدادات ودوال مشتركة لكل ملفات API الموبايل
// ============================================

// 1. ترويسات CORS للسماح بالاتصال من أي تطبيق ويب أو هاتف
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// معالجة طلبات Preflight للـ CORS فوراً
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/config.php';

// المفتاح السري لتشفير الـ JWT
if (!defined('MOBILE_JWT_SECRET')) {
    define('MOBILE_JWT_SECRET', 'njaz_mobile_secure_jwt_key_prod_2026_x87b1c');
}

function jsonOutMobile($ok, $message, $extra = [], $httpCode = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function base64url_encode_m($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode_m($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// توليد توكن لمستخدم مسجل دخول من التطبيق (صالح 60 يوم)
function generateMobileJWT($userId, $role) {
    $header  = base64url_encode_m(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode_m(json_encode([
        'user_id' => (int)$userId,
        'role'    => $role,
        'exp'     => time() + (60 * 60 * 24 * 60),
    ]));
    $signature = base64url_encode_m(hash_hmac('sha256', "$header.$payload", MOBILE_JWT_SECRET, true));
    return "$header.$payload.$signature";
}

// التحقق من التوكن
function validateMobileJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $payload, $signature] = $parts;
    $expected = base64url_encode_m(hash_hmac('sha256', "$header.$payload", MOBILE_JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) return false;
    $data = json_decode(base64url_decode_m($payload), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return false;
    return $data;
}

// قراءة توكن Bearer من الهيدر
function getBearerToken() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth && preg_match('/Bearer\s+(\S+)/', $auth, $m)) return $m[1];
    return null;
}

// جسر التحقق من التوكن وتفعيل الجلسة للطلب الحالي
function mobileAuthorizeRequest($pdo, $required = true) {
    $token = getBearerToken();
    if (!$token) {
        if ($required) jsonOutMobile(false, 'يجب تسجيل الدخول', [], 401);
        return null;
    }
    $data = validateMobileJWT($token);
    if (!$data) {
        if ($required) jsonOutMobile(false, 'جلسة منتهية، سجّل الدخول مجدداً', [], 401);
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id=? LIMIT 1");
    $stmt->execute([$data['user_id']]);
    $user = $stmt->fetch();
    if (!$user || (int)$user['status'] !== 1) {
        if ($required) jsonOutMobile(false, 'الحساب غير متاح', [], 401);
        return null;
    }
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    return (int)$user['id'];
}