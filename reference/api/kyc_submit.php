<?php
/**
 * api/kyc_submit.php  — رفع طلب تحقق الهوية
 */
require_once dirname(__DIR__) . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($ok, $msg, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) out(false, 'يجب تسجيل الدخول أولاً');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'طلب غير صالح');

// إنشاء الجدول إن لم يكن موجوداً
$pdo->exec("CREATE TABLE IF NOT EXISTS `kyc_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `id_type` ENUM('national','passport','family','electronic') NOT NULL DEFAULT 'national',
  `full_name` VARCHAR(255) NOT NULL DEFAULT '',
  `national_id` VARCHAR(100) NOT NULL DEFAULT '',
  `birth_date` DATE NULL,
  `birth_place` VARCHAR(255) DEFAULT '',
  `issue_date` DATE NULL,
  `expiry_date` DATE NULL,
  `image_front` VARCHAR(500) DEFAULT '',
  `image_back` VARCHAR(500) DEFAULT '',
  `extra_fields` JSON DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `reviewed_by` INT DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$userId = (int)$_SESSION['user_id'];

// هل يوجد طلب معلق بالفعل؟
$existing = $pdo->prepare("SELECT id, status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
$existing->execute([$userId]);
$ex = $existing->fetch();
if ($ex && in_array($ex['status'], ['pending','approved'])) {
    out(false, $ex['status'] === 'approved' ? 'تم التحقق من هويتك مسبقاً ✅' : 'طلبك قيد المراجعة، يرجى الانتظار ⏳');
}

// التحقق من الحقول
$idType    = $_POST['id_type']    ?? '';
$fullName  = trim($_POST['full_name']  ?? '');
$nationalId= trim($_POST['national_id']?? '');
$birthDate = $_POST['birth_date']  ?? '';
$birthPlace= trim($_POST['birth_place'] ?? '');
$issueDate = $_POST['issue_date']  ?? '';
$expiryDate= $_POST['expiry_date'] ?? '';

$allowedTypes = ['national','passport','family','electronic'];
if (!in_array($idType, $allowedTypes))      out(false, 'نوع الهوية غير صالح');
if (mb_strlen($fullName) < 3)               out(false, 'الاسم مطلوب (3 أحرف على الأقل)');
if (empty($nationalId))                     out(false, 'الرقم الوطني / رقم الهوية مطلوب');
if (empty($birthDate))                      out(false, 'تاريخ الميلاد مطلوب');
if (empty($issueDate))                      out(false, 'تاريخ الإصدار مطلوب');
if (empty($expiryDate))                     out(false, 'تاريخ الانتهاء مطلوب');

// الحقول الإضافية
$extra = [];
foreach ($_POST as $k => $v) {
    if (str_starts_with($k, 'extra_')) {
        $extra[substr($k, 6)] = trim($v);
    }
}

// رفع الصور
$uploadDir = dirname(__DIR__) . '/uploads/kyc/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

function uploadKycImage($fileKey, $userId, $side) {
    global $uploadDir;
    if (empty($_FILES[$fileKey]['tmp_name'])) return '';
    $file = $_FILES[$fileKey];
    if ($file['error'] !== UPLOAD_ERR_OK) return '';
    if ($file['size'] > 8 * 1024 * 1024) return '';

    // التحقق من النوع
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/webp','image/heic'];
    if (!in_array($mime, $allowed)) return '';

    $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'][$mime];
    $name = 'kyc_' . $userId . '_' . $side . '_' . time() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $name))
        return 'uploads/kyc/' . $name;
    return '';
}

$imgFront = uploadKycImage('image_front', $userId, 'front');
$imgBack  = $idType !== 'passport' ? uploadKycImage('image_back', $userId, 'back') : '';

if (empty($imgFront)) out(false, 'صورة الوجه الأمامي مطلوبة');
if ($idType !== 'passport' && empty($imgBack)) out(false, 'صورة الوجه الخلفي مطلوبة');

// حذف الطلب القديم المرفوض إن وجد
if ($ex && $ex['status'] === 'rejected') {
    $pdo->prepare("DELETE FROM kyc_requests WHERE id=?")->execute([$ex['id']]);
}

// الحفظ
$pdo->prepare("INSERT INTO kyc_requests 
    (user_id, id_type, full_name, national_id, birth_date, birth_place, issue_date, expiry_date, image_front, image_back, extra_fields)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
->execute([
    $userId, $idType, $fullName, $nationalId,
    $birthDate ?: null, $birthPlace,
    $issueDate ?: null, $expiryDate ?: null,
    $imgFront, $imgBack,
    !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null
]);

// إشعار للإدارة
try {
    $user = getUser();
    $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, icon) 
        SELECT id, 'kyc', 'طلب تحقق هوية جديد', ?, 'fas fa-id-card'
        FROM users WHERE role IN ('admin','staff') LIMIT 5")
    ->execute(["طلب تحقق هوية جديد من العميل: " . ($user['username'] ?? $userId)]);
} catch(Exception $e) {}

out(true, 'تم إرسال طلبك بنجاح! سيتم مراجعته خلال 24 ساعة 🎉');
