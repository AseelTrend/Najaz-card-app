<?php
/**
 * نظام تصريح الأجهزة — v2 (مُصلَّح)
 *
 * الفلسفة:
 *  - device_id يُولَّد بواسطة JavaScript في المتصفح ويُخزَّن في localStorage
 *  - يبقى ثابتاً طالما المتصفح لم يُمسح بياناته
 *  - لا يعتمد على IP نهائياً (VPN / تغيير شبكة لا يؤثر)
 *  - عند أول جهاز → approved تلقائياً
 *  - عند جهاز جديد (device_id مختلف) → pending + زر واتساب
 *
 * إصلاحات هذا الإصدار:
 *  1) صيغ preg_match الخاصة بكشف المتصفح كانت فيها delimiter خاطئ
 *     ('/Edg\\/\/i' بدل '/Edg\//i') فكانت تسبب PHP Warning: No ending
 *     delimiter '/' found — تم تصحيحها.
 *  2) الدالة checkAndRegisterDevice() كانت تعمل SELECT ثم INSERT بدون
 *     أي حماية من Race Condition: لو وصل طلبان بنفس اللحظة (تحميل مزدوج
 *     لصفحة auth/google/callback.php، إعادة تحميل سريعة، إلخ) كلاهما
 *     لا يجد الجهاز في SELECT فيحاول الاثنان عمل INSERT، فيفشل الثاني
 *     بخطأ Duplicate entry غير معالَج → Fatal Error → صفحة 500.
 *     تم لف عملية الإدخال بـ try/catch: لو حدث تعارض unique key، يعاد
 *     جلب السجل الموجود فعلياً بدل تحطيم الصفحة.
 */

// الحصول على IP الحقيقي (للتسجيل فقط، ليس للمقارنة)
function getClientIP() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// تحليل User-Agent للحصول على معلومات الجهاز
function parseUserAgent() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $os = 'Unknown'; $browser = 'Unknown'; $type = 'desktop';

    if (preg_match('/Android/i', $ua))        { $os = 'Android';   $type = 'mobile'; }
    elseif (preg_match('/iPhone|iPod/i', $ua)) { $os = 'iOS';       $type = 'mobile'; }
    elseif (preg_match('/iPad/i', $ua))        { $os = 'iPadOS';    $type = 'tablet'; }
    elseif (preg_match('/Windows NT 10/i', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/Windows/i', $ua))     $os = 'Windows';
    elseif (preg_match('/Mac OS X/i', $ua))    $os = 'macOS';
    elseif (preg_match('/Linux/i', $ua))       $os = 'Linux';

    // تم تصحيح صيغة الـ delimiter هنا (كانت \/ مكرر بالخطأ)
    if (preg_match('/Edg\//i', $ua))         $browser = 'Edge';
    elseif (preg_match('/OPR\//i', $ua))     $browser = 'Opera';
    elseif (preg_match('/Chrome\//i', $ua))  $browser = 'Chrome';
    elseif (preg_match('/Firefox\//i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua))  $browser = 'Safari';

    $name = $browser . ' على ' . $os;
    return compact('os', 'browser', 'type', 'name');
}

/**
 * التحقق من الجهاز وتسجيله
 *
 * @param PDO    $pdo
 * @param int    $userId
 * @param bool   $isNewUser  true = مستخدم جديد → أول جهاز تلقائياً
 * @param string $clientDeviceId  device_id القادم من JS عبر POST
 * @return string 'approved' | 'pending' | 'blocked'
 */
function checkAndRegisterDevice(PDO $pdo, int $userId, bool $isNewUser = false, string $clientDeviceId = ''): string {
    $ip = getClientIP();
    $ua = parseUserAgent();

    // ── استخدام device_id من client (JS) إن وُجد، وإلا fallback لـ UA hash ──
    $deviceId = '';
    if ($clientDeviceId && preg_match('/^[a-zA-Z0-9_\-]{16,128}$/', $clientDeviceId)) {
        $deviceId = $clientDeviceId;
    } else {
        // Fallback: hash من UA (يتغير مع تغيير المتصفح لكن أفضل من IP)
        $deviceId = 'ua_' . hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    }

    // ── هل الجهاز موجود مسبقاً؟ ──
    $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=?");
    $stmt->execute([$userId, $deviceId]);
    $device = $stmt->fetch();

    if ($device) {
        // جهاز معروف — تحديث آخر نشاط فقط (IP لا يؤثر على الحكم)
        $pdo->prepare("UPDATE user_devices SET ip_address=?,last_seen=NOW() WHERE id=?")->execute([$ip, $device['id']]);
        if ($device['status'] === 'blocked') return 'blocked';
        if ($device['status'] === 'pending') return 'pending';
        return 'approved';
    }

    // ── جهاز جديد ──
    // هل يوجد أي جهاز مسجّل سابقاً لهذا المستخدم؟
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM user_devices WHERE user_id=?");
    $countStmt->execute([$userId]);
    $hasDevices = (int)$countStmt->fetchColumn() > 0;

    // أول جهاز أو مستخدم جديد → approved تلقائياً
    $status  = (!$hasDevices || $isNewUser) ? 'approved' : 'pending';
    $isFirst = (!$hasDevices || $isNewUser) ? 1 : 0;

    try {
        $pdo->prepare("INSERT INTO user_devices
            (user_id, device_fingerprint, device_name, device_type, browser, os, ip_address, status, is_first_device)
            VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$userId, $deviceId, $ua['name'], $ua['type'], $ua['browser'], $ua['os'], $ip, $status, $isFirst]);
    } catch (PDOException $e) {
        // كود 23000 = Integrity constraint violation (Duplicate entry)
        // يحصل عند سباق بين طلبين متزامنين لنفس (user_id, device_fingerprint).
        // بدل تحطيم الصفحة بـ 500، نتعامل معه كأن الجهاز موجود فعلاً.
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'unique_user_device') !== false) {
            $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=?");
            $stmt->execute([$userId, $deviceId]);
            $existing = $stmt->fetch();

            if ($existing) {
                $pdo->prepare("UPDATE user_devices SET ip_address=?,last_seen=NOW() WHERE id=?")
                    ->execute([$ip, $existing['id']]);
                if ($existing['status'] === 'blocked') return 'blocked';
                if ($existing['status'] === 'pending') return 'pending';
                return 'approved';
            }
            // في حال لم نجد السجل لسبب غريب، لا نكسر تجربة المستخدم
            return $status;
        }
        // أي خطأ آخر غير متعلق بالتكرار: أعد رميه كما هو
        throw $e;
    }

    return $status;
}
