<?php
// =====================================================
// ملف الإعدادات الرئيسي - قم بتعديل البيانات أدناه
// =====================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'njazbuzz_admin');     // اسم قاعدة البيانات
define('DB_USER', 'njazbuzz_admin');            // اسم مستخدم قاعدة البيانات
define('DB_PASS', 'njazbuzz_admin');                // كلمة مرور قاعدة البيانات

// رابط الموقع - يتم تحديده تلقائياً من السيرفر
// نعتمد على X-Forwarded-Proto لأن الموقع خلف Cloudflare، وهو الهيدر
// الموثوق اللي يرسله البروكسي دائماً حتى لو $_SERVER['HTTPS'] ما انضبط
// من السيرفر الأصلي. وبما أن الموقع يعمل على https فقط، نجبرها دائماً
// بدل ما نتركها تتردد بين http/https حسب حالة الاتصال مع Cloudflare —
// هذا التذبذب كان يسبب فشل متقطع في تسجيل الدخول عبر Google
// (redirect_uri_mismatch) لأن الرابط المُرسَل لجوجل كان يتغيّر أحياناً
// إلى http:// بدل https://.
$_protocol = 'https';
$_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_rootPath = dirname(__DIR__);
$_docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
$_relPath  = str_replace('\\', '/', str_replace($_docRoot, '', $_rootPath));
define('SITE_URL', $_protocol . '://' . $_host . rtrim($_relPath, '/'));
define('SITE_NAME', 'نجاز كارد');
date_default_timezone_set('Asia/Riyadh'); // UTC+3 توقيت السعودية

// إعداد الاتصال بقاعدة البيانات
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET time_zone='+03:00'"
        ]
    );
} catch (PDOException $e) {
    // [M-1 FIX] لا نكشف تفاصيل الخطأ للمستخدم — نسجّلها فقط
    error_log('DB Connection Error: ' . $e->getMessage());
    die('<div style="font-family:Arial;padding:20px;background:#fff3f3;border:1px solid #e00;margin:20px;border-radius:8px;">
        <h3 style="color:#c00">خطأ في الاتصال</h3>
        <p>حدث خطأ مؤقت. يرجى المحاولة لاحقاً أو التواصل مع الدعم.</p>
    </div>');
}

session_start();

// دوال مساعدة
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function formatMoney($amount) {
    global $pdo;
    $symbol = '$';
    $amount = (float)$amount;
    // إذا الرقم صغير جداً نعرضه بدقة كافية
    if ($amount > 0 && $amount < 0.01) {
        // إزالة الأصفار الزائدة من اليمين
        $formatted = rtrim(rtrim(number_format($amount, 10), '0'), '.');
        return $formatted . ' ' . $symbol;
    }
    return number_format($amount, 2) . ' ' . $symbol;
}

function getUser($id = null) {
    global $pdo, $_SESSION;
    $id = $id ?? $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getSetting($key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : '';
}

function flashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}


// ─── نظام صلاحيات الموظفين ─────────────────────────────────────────────────

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function canAccess($pdo, $perm) {
    if (isAdmin()) return true;
    if (!isStaff()) return false;

    // [C-1 FIX] Whitelist صارم مع دعم الاستدعاءات القديمة والجديدة.
    // تعريف الصلاحيات يستخدم الاسم المنطقي، بينما أعمدة الجدول تستخدم perm_*.
    $logicalPerm = preg_replace('/^perm_/', '', (string)$perm);
    $allowedPerms = array_keys(getAllPermissions());
    if (!in_array($logicalPerm, $allowedPerms, true)) return false;

    $uid = $_SESSION['user_id'] ?? 0;
    $column = 'perm_' . $logicalPerm;
    // لا يمكن أن يأتي اسم العمود إلا من القائمة البيضاء أعلاه.
    $stmt = $pdo->prepare("SELECT `{$column}` FROM staff_permissions WHERE user_id=?");
    try {
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        return $row && !empty($row[$column]);
    } catch (Exception $e) { return false; }
}

function requireStaffOrAdmin($pdo, $perm = null) {
    if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
    if ($perm && !canAccess($pdo, $perm)) {
        $_SESSION['flash'] = ['type'=>'danger','message'=>'ليس لديك صلاحية للوصول لهذا القسم'];
        header('Location: ' . SITE_URL . '/admin/');
        exit;
    }
}

function getStaffPermissions($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM staff_permissions WHERE user_id=?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [];
}

function logStaffAction($pdo, $action, $targetType = null, $targetId = null, $desc = null) {
    if (!isLoggedIn()) return;
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $pdo->prepare("INSERT INTO staff_activity_log (user_id,action,target_type,target_id,description,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], $action, $targetType, $targetId, $desc, $ip]);
    } catch (Exception $e) {}
}

// قائمة الصلاحيات مع التسميات
function getAllPermissions() {
    return [
        'dashboard'  => ['label'=>'لوحة التحكم',  'icon'=>'chart-line',    'group'=>'عام'],
        'orders_view'    => ['label'=>'عرض الطلبات',    'icon'=>'eye',           'group'=>'الطلبات'],
        'orders_process' => ['label'=>'تجهيز الطلبات',  'icon'=>'sync-alt',      'group'=>'الطلبات'],
        'orders_complete'=> ['label'=>'إكمال الطلبات',  'icon'=>'check-circle',  'group'=>'الطلبات'],
        'orders_cancel'  => ['label'=>'إلغاء الطلبات',  'icon'=>'times-circle',  'group'=>'الطلبات'],
        'customers_view'   => ['label'=>'عرض العملاء',    'icon'=>'users',         'group'=>'العملاء'],
        'customers_edit'   => ['label'=>'تعديل بيانات',   'icon'=>'user-edit',     'group'=>'العملاء'],
        'customers_balance'=> ['label'=>'تعديل الرصيد',   'icon'=>'wallet',        'group'=>'العملاء'],
        'customers_devices'=> ['label'=>'تصريح الأجهزة',  'icon'=>'mobile-alt',    'group'=>'العملاء'],
        'services_view' => ['label'=>'عرض الخدمات',   'icon'=>'box',           'group'=>'المحتوى'],
        'services_edit' => ['label'=>'تعديل الخدمات', 'icon'=>'edit',          'group'=>'المحتوى'],
        'categories_view'=> ['label'=>'عرض الأقسام',  'icon'=>'folder-open',   'group'=>'المحتوى'],
        'categories_edit'=> ['label'=>'تعديل الأقسام','icon'=>'folder-plus',   'group'=>'المحتوى'],
        'providers_view' => ['label'=>'عرض المزودين',  'icon'=>'plug',          'group'=>'النظام'],
        'providers_edit' => ['label'=>'تعديل المزودين','icon'=>'cogs',          'group'=>'النظام'],
        'code_stock_view'=> ['label'=>'عرض مخزون الأكواد',   'icon'=>'key',      'group'=>'المحتوى'],
        'code_stock_edit'=> ['label'=>'تعديل مخزون الأكواد', 'icon'=>'barcode',  'group'=>'المحتوى'],
        'settings'       => ['label'=>'الإعدادات',     'icon'=>'sliders-h',     'group'=>'النظام'],
        'staff'          => ['label'=>'إدارة الموظفين','icon'=>'user-tie',      'group'=>'النظام'],
        'accounting_view' => ['label'=>'عرض الحسابات والقيود','icon'=>'calculator','group'=>'المحاسبة'],
        'accounting_edit' => ['label'=>'إدارة الحسابات والقيود','icon'=>'file-invoice-dollar','group'=>'المحاسبة'],
    ];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * عرض اسم المستخدم — يضيف لاحقة [محذوف] إذا كان العميل محذوفاً
 */
function formatUsername($user, $showId = false) {
    if (empty($user)) return '—';
    $isDeleted = !empty($user['is_deleted']) && $user['is_deleted'];
    // نزيل لاحقة __deleted__ID إذا كانت موجودة
    $name = preg_replace('/__deleted__\d+$/', '', $user['username'] ?? '');
    $fullName = $user['full_name'] ?? '';
    $display = $fullName ?: $name;
    if ($showId) $display .= ' (#'.($user['id'] ?? '').') ';
    if ($isDeleted) $display .= ' [محذوف]';
    return $display;
}

/**
 * عرض رقم الطلب — يستخدم ref_id إذا توفر، وإلا الـ ID الرقمي
 */
function formatOrderId($order) {
    if (!empty($order['ref_id'])) return strtoupper($order['ref_id']);
    return 'ORD-' . str_pad($order['id'] ?? 0, 6, '0', STR_PAD_LEFT);
}

// ─── [C-2 FIX] CSRF لوحة الإدارة ──────────────────────────────────────────────

/**
 * توليد أو جلب CSRF Token خاص بلوحة الإدارة
 */
function adminCsrfToken(): string {
    if (empty($_SESSION['_admin_csrf'])) {
        $_SESSION['_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_admin_csrf'];
}

/**
 * التحقق من CSRF Token في POST — يوقف التنفيذ فوراً عند الفشل
 */
function adminCsrfVerify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $submitted = $_POST['_csrf'] ?? '';
    $stored    = $_SESSION['_admin_csrf'] ?? '';
    if (!$submitted || !$stored || !hash_equals($stored, $submitted)) {
        http_response_code(403);
        die('<div style="font-family:Arial;padding:40px;text-align:center;background:#0d1428;color:#fff;min-height:100vh">
            <h2 style="color:#ff4455">⛔ CSRF Verification Failed</h2>
            <p style="color:#8895a7">طلب غير صالح — يرجى إعادة المحاولة من الصفحة الرئيسية.</p>
            <a href="javascript:history.back()" style="color:#1e6fff">← رجوع</a>
        </div>');
    }
    // تجديد التوكن بعد كل استخدام
    $_SESSION['_admin_csrf'] = bin2hex(random_bytes(32));
}

/**
 * طباعة حقل CSRF المخفي داخل نماذج الأدمن
 */
function adminCsrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(adminCsrfToken()) . '">';
}
