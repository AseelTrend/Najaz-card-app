<?php
/**
 * api/user_devices.php — إدارة أجهزة المستخدم
 * GET  ?action=list                    → جلب أجهزة المستخدم الحالي
 * POST action=block        device_id=N → حظر جهاز
 * POST action=unblock      device_id=N → رفع حظر جهاز
 * POST action=approve_by_fingerprint fingerprint=did_xxx → تصريح جهاز بالـ fingerprint
 */
require_once '../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

function out($ok, $msg, $extra = []) {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) out(false, 'يجب تسجيل الدخول أولاً');

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── جلب الأجهزة ────────────────────────────────────────────────────────────
if ($action === 'list') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, device_fingerprint, device_name, device_type, browser, os,
                   ip_address, status, is_first_device,
                   DATE_FORMAT(last_seen, '%d/%m/%Y %H:%i') as last_seen
            FROM user_devices
            WHERE user_id = ?
            ORDER BY is_first_device DESC, last_seen DESC
        ");
        $stmt->execute([$userId]);
        $devices = $stmt->fetchAll();
        out(true, 'ok', ['devices' => $devices]);
    } catch(Exception $e) {
        out(false, 'خطأ في قاعدة البيانات');
    }
}

// ── حظر جهاز ────────────────────────────────────────────────────────────────
if ($action === 'block') {
    $deviceId = (int)($_POST['device_id'] ?? 0);
    if (!$deviceId) out(false, 'معرّف الجهاز مطلوب');

    // تأكد أن الجهاز يخص المستخدم الحالي
    $d = $pdo->prepare("SELECT id, is_first_device FROM user_devices WHERE id=? AND user_id=?");
    $d->execute([$deviceId, $userId]);
    $device = $d->fetch();
    if (!$device) out(false, 'الجهاز غير موجود');
    if ($device['is_first_device']) out(false, 'لا يمكن حظر الجهاز الأساسي');

    $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$deviceId, $userId]);
    out(true, 'تم حظر الجهاز بنجاح');
}

// ── رفع الحظر ────────────────────────────────────────────────────────────────
if ($action === 'unblock') {
    $deviceId = (int)($_POST['device_id'] ?? 0);
    if (!$deviceId) out(false, 'معرّف الجهاز مطلوب');

    $d = $pdo->prepare("SELECT id FROM user_devices WHERE id=? AND user_id=?");
    $d->execute([$deviceId, $userId]);
    if (!$d->fetch()) out(false, 'الجهاز غير موجود');

    $pdo->prepare("UPDATE user_devices SET status='approved' WHERE id=? AND user_id=?")->execute([$deviceId, $userId]);
    out(true, 'تم رفع الحظر بنجاح');
}

// ── تصريح جهاز بالـ fingerprint ──────────────────────────────────────────────
if ($action === 'approve_by_fingerprint') {
    $fingerprint = trim($_POST['fingerprint'] ?? '');
    if (!$fingerprint) out(false, 'رقم الجهاز مطلوب');
    if (!preg_match('/^[a-zA-Z0-9_\-]{8,128}$/', $fingerprint)) out(false, 'رقم الجهاز غير صحيح');

    // هل هذا الجهاز مسجّل لهذا المستخدم؟
    $d = $pdo->prepare("SELECT id, status FROM user_devices WHERE user_id=? AND device_fingerprint=?");
    $d->execute([$userId, $fingerprint]);
    $device = $d->fetch();

    if ($device) {
        if ($device['status'] === 'approved') out(false, 'هذا الجهاز مصرّح بالفعل ✅');
        // تصريح الجهاز المعلّق
        $pdo->prepare("UPDATE user_devices SET status='approved' WHERE id=?")->execute([$device['id']]);
        out(true, 'تم تصريح الجهاز بنجاح ✅');
    } else {
        // جهاز جديد لم يحاول الدخول بعد — أضفه مباشرة كـ approved
        require_once '../includes/device_helper.php';
        $ua = parseUserAgent();
        $pdo->prepare("
            INSERT INTO user_devices
            (user_id, device_fingerprint, device_name, device_type, browser, os, ip_address, status, is_first_device)
            VALUES (?,?,?,?,?,?,?,?,0)
        ")->execute([
            $userId, $fingerprint,
            'جهاز مُضاف يدوياً', 'unknown', 'unknown', 'unknown',
            '0.0.0.0', 'approved'
        ]);
        out(true, 'تم إضافة الجهاز وتصريحه ✅');
    }
}

out(false, 'إجراء غير معروف');
