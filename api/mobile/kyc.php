<?php
/** Mobile KYC endpoint: status + submit. Temporary detailed diagnostics are enabled for testing. */
require_once __DIR__ . '/_common.php';
header('Content-Type: application/json; charset=utf-8');

$kycLogFile = __DIR__ . '/kyc_debug.log';
function kycDebug(string $step, array $data = []): void {
    global $kycLogFile;
    $safe = $data;
    if (isset($safe['authorization'])) $safe['authorization'] = $safe['authorization'] !== '' ? '[PRESENT]' : '[EMPTY]';
    @file_put_contents($kycLogFile, json_encode([
        'time' => date('Y-m-d H:i:s'),
        'step' => $step,
        'data' => $safe,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

kycDebug('REQUEST_START', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    'query_string' => $_SERVER['QUERY_STRING'] ?? null,
    'action_get' => $_GET['action'] ?? null,
    'action_post' => $_POST['action'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
]);

kycDebug('COMMON_BEFORE_AUTH', [
    'pdo_exists' => isset($pdo),
    'pdo_class' => isset($pdo) && is_object($pdo) ? get_class($pdo) : null,
]);

try {
    $userId = mobileAuthorizeRequest($pdo, true);
    kycDebug('AUTH_SUCCESS', ['user_id' => (int)$userId]);
} catch (Throwable $e) {
    kycDebug('AUTH_EXCEPTION', ['class' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()]);
    throw $e;
}

function kycOut(bool $ok, string $msg, array $extra = []): void {
    global $kycLogFile;
    $payload = array_merge(['ok' => $ok, 'msg' => $msg], $extra);
    @file_put_contents($kycLogFile, json_encode([
        'time' => date('Y-m-d H:i:s'),
        'step' => 'RESPONSE',
        'data' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
kycDebug('ACTION_RESOLVED', ['action' => $action, 'method' => $_SERVER['REQUEST_METHOD'] ?? null, 'user_id' => (int)$userId]);

if ($action === 'check') {
    kycDebug('CHECK_START', ['user_id' => (int)$userId]);
    $result = [
        'php_version' => PHP_VERSION,
        'script' => __FILE__,
        'script_exists' => file_exists(__FILE__),
        'log_file' => $kycLogFile,
        'log_writable' => is_writable(__DIR__),
        'request' => [
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'query' => $_GET,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        ],
        'auth' => ['ok' => true, 'user_id' => (int)$userId],
    ];
    try {
        $dbInfo = $pdo->query("SELECT DATABASE() AS db_name, VERSION() AS db_version")->fetch(PDO::FETCH_ASSOC);
        $result['database'] = ['ok' => true] + ($dbInfo ?: []);
        kycDebug('DATABASE_CONNECTION_OK', $result['database']);
    } catch (Throwable $e) {
        $result['database'] = ['ok' => false, 'error' => $e->getMessage()];
        kycDebug('DATABASE_CONNECTION_ERROR', $result['database']);
    }
    try {
        $st = $pdo->query("SHOW TABLES LIKE 'kyc_requests'");
        $result['kyc_table'] = ['exists' => (bool)$st->fetchColumn()];
        kycDebug('KYC_TABLE_CHECK', $result['kyc_table']);
    } catch (Throwable $e) {
        $result['kyc_table'] = ['exists' => false, 'error' => $e->getMessage()];
        kycDebug('KYC_TABLE_CHECK_ERROR', $result['kyc_table']);
    }
    try {
        $st = $pdo->prepare("SELECT id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields,status,admin_note,reviewed_by,reviewed_at,created_at,updated_at FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 10");
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            if (!empty($row['extra_fields'])) {
                $decoded = json_decode($row['extra_fields'], true);
                $row['extra_fields'] = is_array($decoded) ? $decoded : [];
            } else $row['extra_fields'] = [];
        }
        unset($row);
        $result['kyc_requests'] = $rows;
        $result['kyc_count'] = count($rows);
        $result['latest_status'] = $rows[0]['status'] ?? null;
        kycDebug('KYC_QUERY_OK', ['count' => count($rows), 'latest_id' => $rows[0]['id'] ?? null, 'latest_status' => $rows[0]['status'] ?? null, 'latest_reviewed_at' => $rows[0]['reviewed_at'] ?? null]);
    } catch (Throwable $e) {
        $result['kyc_requests'] = [];
        $result['kyc_query_error'] = $e->getMessage();
        kycDebug('KYC_QUERY_ERROR', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
    }
    try {
        $st = $pdo->prepare("SELECT id,username,name,email,role,status,balance FROM users WHERE id=? LIMIT 1");
        $st->execute([$userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        $result['user'] = $user ?: null;
        kycDebug('USER_QUERY_OK', ['found' => (bool)$user, 'user_id' => (int)$userId, 'role' => $user['role'] ?? null, 'status' => $user['status'] ?? null]);
    } catch (Throwable $e) {
        $result['user'] = null;
        $result['user_query_error'] = $e->getMessage();
        kycDebug('USER_QUERY_ERROR', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
    }
    $result['server_time'] = date('Y-m-d H:i:s');
    kycDebug('CHECK_COMPLETE', ['user_id' => (int)$userId, 'latest_status' => $result['latest_status'] ?? null, 'kyc_count' => $result['kyc_count'] ?? null]);
    kycOut(true, 'تم تنفيذ الفحص التفصيلي', $result);
}

if ($action === 'status') {
    kycDebug('STATUS_QUERY_START', ['user_id' => (int)$userId]);
    try {
        $st = $pdo->prepare("SELECT id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields,status,admin_note,reviewed_at,created_at,updated_at FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            kycDebug('STATUS_NOT_FOUND', ['user_id' => (int)$userId]);
            kycOut(true, 'لا يوجد طلب تحقق', ['kyc' => null, 'status' => null]);
        }
        if (!empty($row['extra_fields'])) {
            $decoded = json_decode($row['extra_fields'], true);
            $row['extra_fields'] = is_array($decoded) ? $decoded : [];
        } else $row['extra_fields'] = [];
        kycDebug('STATUS_QUERY_RESULT', ['found' => true, 'kyc_id' => $row['id'], 'status' => $row['status'], 'id_type' => $row['id_type'], 'reviewed_at' => $row['reviewed_at']]);
        kycOut(true, 'تم جلب حالة التحقق', ['kyc' => $row, 'status' => $row['status']]);
    } catch (Throwable $e) {
        kycDebug('STATUS_QUERY_ERROR', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
        kycOut(false, 'خطأ في جلب حالة التحقق', ['error' => $e->getMessage()]);
    }
}

if ($action !== 'submit' || $_SERVER['REQUEST_METHOD'] !== 'POST') kycOut(false, 'طلب غير صالح');
kycDebug('SUBMIT_START', ['user_id' => (int)$userId, 'post_keys' => array_keys($_POST), 'file_keys' => array_keys($_FILES)]);

$pdo->exec("CREATE TABLE IF NOT EXISTS `kyc_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NOT NULL,
  `id_type` ENUM('national','passport','family','electronic') NOT NULL DEFAULT 'national',
  `full_name` VARCHAR(255) NOT NULL DEFAULT '', `national_id` VARCHAR(100) NOT NULL DEFAULT '',
  `birth_date` DATE NULL, `birth_place` VARCHAR(255) DEFAULT '', `issue_date` DATE NULL,
  `expiry_date` DATE NULL, `image_front` VARCHAR(500) DEFAULT '', `image_back` VARCHAR(500) DEFAULT '',
  `extra_fields` JSON DEFAULT NULL, `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL, `reviewed_by` INT DEFAULT NULL, `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
kycDebug('TABLE_CREATE_OR_EXISTS_OK');

$existing = $pdo->prepare("SELECT id,status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
$existing->execute([$userId]);
$ex = $existing->fetch(PDO::FETCH_ASSOC);
kycDebug('EXISTING_KYC_CHECK', ['existing' => $ex ?: null]);
if ($ex && in_array($ex['status'], ['pending','approved'], true)) kycOut(false, $ex['status'] === 'approved' ? 'تم التحقق من هويتك مسبقاً ✅' : 'طلبك قيد المراجعة، يرجى الانتظار ⏳');

$idType = $_POST['id_type'] ?? '';
$fullName = trim($_POST['full_name'] ?? '');
$nationalId = trim($_POST['national_id'] ?? '');
$birthDate = $_POST['birth_date'] ?? '';
$birthPlace = trim($_POST['birth_place'] ?? '');
$issueDate = $_POST['issue_date'] ?? '';
$expiryDate = $_POST['expiry_date'] ?? '';
$allowedTypes = ['national','passport','family','electronic'];
kycDebug('SUBMIT_FIELDS', ['id_type' => $idType, 'full_name_length' => mb_strlen($fullName), 'national_id_present' => $nationalId !== '', 'birth_date' => $birthDate, 'birth_place' => $birthPlace, 'issue_date' => $issueDate, 'expiry_date' => $expiryDate]);
if (!in_array($idType, $allowedTypes, true)) kycOut(false, 'نوع الهوية غير صالح');
if (mb_strlen($fullName) < 3) kycOut(false, 'الاسم مطلوب (3 أحرف على الأقل)');
if ($nationalId === '') kycOut(false, 'الرقم الوطني / رقم الهوية مطلوب');
if ($birthDate === '') kycOut(false, 'تاريخ الميلاد مطلوب');
if ($issueDate === '') kycOut(false, 'تاريخ الإصدار مطلوب');
if ($expiryDate === '') kycOut(false, 'تاريخ الانتهاء مطلوب');

$extra = [];
foreach ($_POST as $k => $v) if (str_starts_with($k, 'extra_')) $extra[substr($k, 6)] = trim((string)$v);

$uploadDir = dirname(__DIR__) . '/uploads/kyc/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) kycOut(false, 'تعذر إنشاء مجلد رفع الصور');

function mobileUploadKyc(string $key, int $uid, string $side): string {
    global $uploadDir;
    if (empty($_FILES[$key]['tmp_name'])) {
        kycDebug('UPLOAD_MISSING', ['key' => $key, 'side' => $side]);
        return '';
    }
    $file = $_FILES[$key];
    kycDebug('UPLOAD_ATTEMPT', ['key' => $key, 'side' => $side, 'error' => $file['error'] ?? null, 'size' => $file['size'] ?? null, 'name' => $file['name'] ?? null, 'type' => $file['type'] ?? null, 'tmp_present' => !empty($file['tmp_name'])]);
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        kycDebug('UPLOAD_REJECTED_SIZE_OR_ERROR', ['key' => $key, 'error' => $file['error'] ?? null, 'size' => $file['size'] ?? null]);
        return '';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'];
    kycDebug('UPLOAD_MIME_CHECK', ['key' => $key, 'mime' => $mime, 'allowed' => isset($allowed[$mime])]);
    if (!isset($allowed[$mime])) return '';
    $name = 'kyc_' . $uid . '_' . $side . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $moved = move_uploaded_file($file['tmp_name'], $uploadDir . $name);
    kycDebug('UPLOAD_RESULT', ['key' => $key, 'side' => $side, 'moved' => $moved, 'saved_name' => $moved ? $name : null]);
    return $moved ? 'uploads/kyc/' . $name : '';
}

$imgFront = mobileUploadKyc('image_front', $userId, 'front');
$imgBack = $idType !== 'passport' ? mobileUploadKyc('image_back', $userId, 'back') : '';
if ($imgFront === '') kycOut(false, 'صورة الوجه الأمامي مطلوبة');
if ($idType !== 'passport' && $imgBack === '') kycOut(false, 'صورة الوجه الخلفي مطلوبة');
if ($ex && $ex['status'] === 'rejected') {
    $pdo->prepare("DELETE FROM kyc_requests WHERE id=?")->execute([$ex['id']]);
    kycDebug('REJECTED_PREVIOUS_REQUEST_DELETED', ['id' => $ex['id']]);
}

$pdo->prepare("INSERT INTO kyc_requests (user_id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$userId,$idType,$fullName,$nationalId,$birthDate ?: null,$birthPlace,$issueDate ?: null,$expiryDate ?: null,$imgFront,$imgBack,!empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null]);
kycDebug('SUBMIT_INSERT_OK', ['user_id' => (int)$userId, 'insert_id' => $pdo->lastInsertId(), 'id_type' => $idType, 'image_front' => $imgFront, 'image_back' => $imgBack]);

try {
    $user = getUser();
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,icon) SELECT id,'kyc','طلب تحقق هوية جديد',?,'fas fa-id-card' FROM users WHERE role IN ('admin','staff') LIMIT 5")
        ->execute(['طلب تحقق هوية جديد من العميل: ' . ($user['username'] ?? $userId)]);
    kycDebug('NOTIFICATION_OK', ['user_id' => (int)$userId]);
} catch (Throwable $e) {
    kycDebug('NOTIFICATION_ERROR', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
}

kycOut(true, 'تم إرسال طلبك بنجاح! سيتم مراجعته خلال 24 ساعة 🎉', ['status' => 'pending']);
