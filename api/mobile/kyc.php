<?php
/** Mobile KYC endpoint: status + submit + authenticated diagnostic check. */
require_once __DIR__ . '/_common.php';
header('Content-Type: application/json; charset=utf-8');

$userId = mobileAuthorizeRequest($pdo, true);

function kycOut(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

/* Authenticated diagnostic: shows exactly what the mobile API sees for this account. */
if ($action === 'check') {
    try {
        $tableExists = false;
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'kyc_requests'");
        $tableExists = (bool)$tableCheck->fetchColumn();

        $rows = [];
        if ($tableExists) {
            $st = $pdo->prepare("SELECT id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields,status,admin_note,reviewed_by,reviewed_at,created_at,updated_at FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 10");
            $st->execute([$userId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                if (!empty($row['extra_fields'])) {
                    $decoded = json_decode($row['extra_fields'], true);
                    $row['extra_fields'] = is_array($decoded) ? $decoded : [];
                } else {
                    $row['extra_fields'] = [];
                }
            }
            unset($row);
        }

        $latest = $rows[0] ?? null;
        kycOut(true, 'تم فحص التحقق بنجاح', [
            'diagnostic' => [
                'authenticated' => true,
                'user_id' => (int)$userId,
                'pdo_connected' => true,
                'kyc_table_exists' => $tableExists,
                'requests_count' => count($rows),
                'latest_status' => $latest['status'] ?? null,
                'latest_kyc_id' => isset($latest['id']) ? (int)$latest['id'] : null,
                'latest_reviewed_at' => $latest['reviewed_at'] ?? null,
            ],
            'kyc' => $latest,
            'kyc_requests' => $rows,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        kycOut(false, 'حدث خطأ أثناء فحص التحقق', [
            'diagnostic' => [
                'authenticated' => true,
                'user_id' => (int)$userId,
                'pdo_connected' => true,
            ],
            'error' => $e->getMessage(),
        ]);
    }
}

if ($action === 'status') {
    $st = $pdo->prepare("SELECT id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields,status,admin_note,reviewed_at,created_at,updated_at FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) kycOut(true, 'لا يوجد طلب تحقق', ['kyc' => null, 'status' => null]);
    if (!empty($row['extra_fields'])) {
        $decoded = json_decode($row['extra_fields'], true);
        $row['extra_fields'] = is_array($decoded) ? $decoded : [];
    } else $row['extra_fields'] = [];
    kycOut(true, 'تم جلب حالة التحقق', ['kyc' => $row, 'status' => $row['status']]);
}

if ($action !== 'submit' || $_SERVER['REQUEST_METHOD'] !== 'POST') kycOut(false, 'طلب غير صالح');

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

$existing = $pdo->prepare("SELECT id,status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
$existing->execute([$userId]);
$ex = $existing->fetch(PDO::FETCH_ASSOC);
if ($ex && in_array($ex['status'], ['pending','approved'], true)) {
    kycOut(false, $ex['status'] === 'approved' ? 'تم التحقق من هويتك مسبقاً ✅' : 'طلبك قيد المراجعة، يرجى الانتظار ⏳');
}

$idType = $_POST['id_type'] ?? '';
$fullName = trim($_POST['full_name'] ?? '');
$nationalId = trim($_POST['national_id'] ?? '');
$birthDate = $_POST['birth_date'] ?? '';
$birthPlace = trim($_POST['birth_place'] ?? '');
$issueDate = $_POST['issue_date'] ?? '';
$expiryDate = $_POST['expiry_date'] ?? '';
$allowedTypes = ['national','passport','family','electronic'];
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
    if (empty($_FILES[$key]['tmp_name'])) return '';
    $file = $_FILES[$key];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 8 * 1024 * 1024) return '';
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'];
    if (!isset($allowed[$mime])) return '';
    $name = 'kyc_' . $uid . '_' . $side . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    return move_uploaded_file($file['tmp_name'], $uploadDir . $name) ? 'uploads/kyc/' . $name : '';
}

$imgFront = mobileUploadKyc('image_front', $userId, 'front');
$imgBack = $idType !== 'passport' ? mobileUploadKyc('image_back', $userId, 'back') : '';
if ($imgFront === '') kycOut(false, 'صورة الوجه الأمامي مطلوبة');
if ($idType !== 'passport' && $imgBack === '') kycOut(false, 'صورة الوجه الخلفي مطلوبة');
if ($ex && $ex['status'] === 'rejected') $pdo->prepare("DELETE FROM kyc_requests WHERE id=?")->execute([$ex['id']]);

$pdo->prepare("INSERT INTO kyc_requests (user_id,id_type,full_name,national_id,birth_date,birth_place,issue_date,expiry_date,image_front,image_back,extra_fields) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$userId,$idType,$fullName,$nationalId,$birthDate ?: null,$birthPlace,$issueDate ?: null,$expiryDate ?: null,$imgFront,$imgBack,!empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null]);

try {
    $user = getUser();
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,icon) SELECT id,'kyc','طلب تحقق هوية جديد',?,'fas fa-id-card' FROM users WHERE role IN ('admin','staff') LIMIT 5")
        ->execute(['طلب تحقق هوية جديد من العميل: ' . ($user['username'] ?? $userId)]);
} catch (Throwable $e) {}

kycOut(true, 'تم إرسال طلبك بنجاح! سيتم مراجعته خلال 24 ساعة 🎉', ['status' => 'pending']);
