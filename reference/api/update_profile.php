<?php
/**
 * api/update_profile.php — تحديث الاسم والصورة الشخصية
 */
require_once dirname(__DIR__) . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($ok, $msg, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn())                          out(false, 'يجب تسجيل الدخول');
if ($_SERVER['REQUEST_METHOD'] !== 'POST')  out(false, 'طلب غير صالح');

$userId   = (int)$_SESSION['user_id'];
$action   = $_POST['action'] ?? 'update_name';

// ── تحديث الاسم ──────────────────────────────────────────────────
if ($action === 'update_name') {
    $displayName = trim($_POST['full_name'] ?? '');
    if (mb_strlen($displayName) < 2)  out(false, 'الاسم قصير جداً');
    if (mb_strlen($displayName) > 80) out(false, 'الاسم طويل جداً');
    if (preg_match('/[<>"\'\\\\\/]/', $displayName)) out(false, 'الاسم يحتوي على رموز غير مسموح بها');

    // أضف عمود display_name إن لم يكن موجوداً
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
        if (!in_array('display_name', $cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `full_name`");
        }
    } catch(Exception $e) {}

    $pdo->prepare("UPDATE users SET display_name=? WHERE id=?")->execute([$displayName, $userId]);
    out(true, 'تم تحديث الاسم بنجاح ✅', ['full_name' => $displayName]);
}

// ── رفع صورة شخصية ───────────────────────────────────────────────
if ($action === 'upload_avatar') {
    if (empty($_FILES['avatar']['tmp_name'])) out(false, 'لم يتم اختيار صورة');

    $file = $_FILES['avatar'];
    if ($file['error'] !== UPLOAD_ERR_OK)      out(false, 'خطأ في رفع الصورة');
    if ($file['size'] > 4 * 1024 * 1024)       out(false, 'الصورة أكبر من 4MB');

    // التحقق من النوع الحقيقي
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) out(false, 'نوع الصورة غير مدعوم (JPG/PNG/WEBP فقط)');

    $ext      = $allowed[$mime];
    $dir      = dirname(__DIR__) . '/uploads/avatars/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // حذف الصورة القديمة إن كانت مرفوعة محلياً
    $oldRow = $pdo->prepare("SELECT profile_avatar FROM users WHERE id=?");
    $oldRow->execute([$userId]);
    $old = $oldRow->fetchColumn();
    if ($old && !str_starts_with($old, 'http') && file_exists(dirname(__DIR__) . '/' . $old)) {
        @unlink(dirname(__DIR__) . '/' . $old);
    }

    $fname = 'avatar_' . $userId . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $fname)) out(false, 'فشل حفظ الصورة');

    $path = 'uploads/avatars/' . $fname;
    $pdo->prepare("UPDATE users SET profile_avatar=? WHERE id=?")->execute([$path, $userId]);

    out(true, 'تم تحديث الصورة ✅', ['avatar_url' => SITE_URL . '/' . $path]);
}

// ── حذف الصورة والرجوع للافتراضي ─────────────────────────────────
if ($action === 'remove_avatar') {
    $oldRow = $pdo->prepare("SELECT profile_avatar FROM users WHERE id=?");
    $oldRow->execute([$userId]);
    $old = $oldRow->fetchColumn();
    if ($old && !str_starts_with($old, 'http') && file_exists(dirname(__DIR__) . '/' . $old)) {
        @unlink(dirname(__DIR__) . '/' . $old);
    }
    $pdo->prepare("UPDATE users SET profile_avatar=NULL WHERE id=?")->execute([$userId]);
    out(true, 'تم حذف الصورة');
}

out(false, 'إجراء غير معروف');
