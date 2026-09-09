<?php
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'profile';

try {
    switch ($action) {
        case 'profile':
            $stmt = $pdo->prepare(
                'SELECT id, username, email, full_name, display_name, phone, profile_avatar, balance, role
                 FROM users WHERE id=? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) jsonOutMobile(false, 'الحساب غير موجود', [], 404);
            $user['uid'] = str_pad((string)$user['id'], 6, '0', STR_PAD_LEFT);
            $user['name'] = $user['display_name'] ?: ($user['full_name'] ?: $user['username']);
            $user['balance'] = number_format((float)$user['balance'], 2);
            jsonOutMobile(true, '', ['user' => $user]);

        case 'update_name':
            $name = trim((string)($_POST['full_name'] ?? ''));
            if (mb_strlen($name) < 2) jsonOutMobile(false, 'الاسم قصير جداً', [], 400);
            if (mb_strlen($name) > 80) jsonOutMobile(false, 'الاسم طويل جداً', [], 400);
            if (preg_match('/[<>"\'\\\/]/', $name)) jsonOutMobile(false, 'الاسم يحتوي على رموز غير مسموحة', [], 400);
            $pdo->prepare('UPDATE users SET display_name=?, full_name=? WHERE id=?')->execute([$name, $name, $userId]);
            jsonOutMobile(true, 'تم تحديث الاسم بنجاح', ['name' => $name]);

        case 'devices':
            $stmt = $pdo->prepare(
                'SELECT id, device_fingerprint, device_name, device_type, browser, os, status, is_first_device,
                        DATE_FORMAT(last_seen, "%d/%m/%Y %H:%i") AS last_seen
                 FROM user_devices WHERE user_id=? ORDER BY is_first_device DESC, last_seen DESC'
            );
            $stmt->execute([$userId]);
            jsonOutMobile(true, '', ['devices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

        case 'block_device':
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, is_first_device FROM user_devices WHERE id=? AND user_id=?');
            $stmt->execute([$deviceId, $userId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) jsonOutMobile(false, 'الجهاز غير موجود', [], 404);
            if ((int)$device['is_first_device'] === 1) jsonOutMobile(false, 'لا يمكن حظر الجهاز الأساسي', [], 400);
            $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$deviceId, $userId]);
            jsonOutMobile(true, 'تم حظر الجهاز');

        case 'unblock_device':
            $deviceId = (int)($_POST['device_id'] ?? 0);
            $stmt = $pdo->prepare('UPDATE user_devices SET status=\'approved\' WHERE id=? AND user_id=?');
            $stmt->execute([$deviceId, $userId]);
            jsonOutMobile(true, 'تم رفع حظر الجهاز');

        default:
            jsonOutMobile(false, 'إجراء غير صالح', [], 400);
    }
} catch (Throwable $e) {
    jsonOutMobile(false, 'تعذر تنفيذ العملية', [], 500);
}
