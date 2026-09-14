<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/rate_limiter.php'; // [M-3 FIX]
require_once dirname(__DIR__) . '/includes/telegram_internal_auth.php';

if (!isLoggedIn() && !telegramAuthorizeInternalRequest($pdo)) {
    echo '{"ok":false,"error":"login"}';
    exit;
}

// [M-3 FIX] Rate Limiting — 10 محاولات كل 60 ثانية لكل مستخدم (منع تخمين الكروت)
apiRateLimit($pdo, 'redeem_card', 10, 60, true);

$rawCode = $_POST['code'] ?? '';
$code    = strtoupper(trim(str_replace(['-',' '], '', $rawCode)));
$userId  = (int)$_SESSION['user_id'];
$ip      = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (strlen($code) < 4) {
    echo '{"ok":false,"error":"الكود قصير جداً"}';
    exit;
}

// ── إنشاء الجداول ─────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `recharge_cards` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(32) NOT NULL UNIQUE,
        `amount` DECIMAL(10,4) NOT NULL,
        `status` ENUM('active','used','disabled') DEFAULT 'active',
        `used_by` INT DEFAULT NULL,
        `used_at` TIMESTAMP NULL DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `batch_id` VARCHAR(20) DEFAULT NULL,
        `note` VARCHAR(200) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `card_failed_attempts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `ip` VARCHAR(50) NOT NULL,
        `code_tried` VARCHAR(50) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user_date` (`user_id`, `created_at`),
        KEY `idx_ip_date`   (`ip`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `card_ip_blocks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ip` VARCHAR(50) NOT NULL UNIQUE,
        `user_id` INT DEFAULT NULL,
        `reason` VARCHAR(200) DEFAULT NULL,
        `blocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `unblock_at` TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ── إنشاء جدول سجل رفع الحظر ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `card_unblock_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NOT NULL,
        `ip` VARCHAR(50) NOT NULL,
        `unblocked_by` ENUM('admin','auto') DEFAULT 'admin',
        `post_unblock_attempts` INT DEFAULT 0 COMMENT 'المحاولات بعد رفع الحظر',
        `unblocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ── فحص حظر الـ IP ───────────────────────────────────────────────────────────
$blocked = $pdo->prepare("SELECT * FROM card_ip_blocks WHERE ip=? AND (unblock_at IS NULL OR unblock_at > NOW())");
$blocked->execute([$ip]);
if ($blocked->fetch()) {
    echo '{"ok":false,"error":"تم حظرك بسبب محاولات متكررة — تواصل مع الدعم"}';
    exit;
}

// ── تحديد الحد الفعلي للمحاولات ─────────────────────────────────────────────
$maxAttempts      = (int)(getSetting('card_max_attempts') ?: 10);
$postUnblockLimit = (int)(getSetting('card_post_unblock_attempts') ?: 3);
$autoBlockHours   = (int)(getSetting('card_auto_block_hours') ?: 24);

// هل رُفع حظره اليوم؟ → يطبق عليه حد ما بعد الرفع
$lastUnblock = $pdo->prepare(
    "SELECT * FROM card_unblock_log WHERE user_id=? AND DATE(unblocked_at)=CURDATE() ORDER BY unblocked_at DESC LIMIT 1"
);
$lastUnblock->execute([$userId]);
$lastUnblock = $lastUnblock->fetch();

if ($lastUnblock) {
    // محاولاته بعد رفع الحظر اليوم فقط
    $afterUnblock = $pdo->prepare(
        "SELECT COUNT(*) FROM card_failed_attempts WHERE user_id=? AND created_at > ?"
    );
    $afterUnblock->execute([$userId, $lastUnblock['unblocked_at']]);
    $attemptsAfterUnblock = (int)$afterUnblock->fetchColumn();

    if ($attemptsAfterUnblock >= $postUnblockLimit) {
        // أعد الحظر
        try {
            $pdo->prepare("INSERT INTO card_ip_blocks (ip,user_id,reason,unblock_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? HOUR))
                ON DUPLICATE KEY UPDATE reason=VALUES(reason),blocked_at=NOW(),unblock_at=VALUES(unblock_at)")
                ->execute([$ip, $userId, "تجاوز حد ما بعد رفع الحظر ($postUnblockLimit محاولات)", $autoBlockHours]);
        } catch(Exception $e) {}
        echo json_encode(['ok'=>false,'error'=>"لقد استنفذت محاولاتك بعد رفع الحظر ($postUnblockLimit محاولات فقط). تم إعادة تعليق حسابك.",'blocked'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // إبلاغه بالمتبقي
    $effectiveMax   = $postUnblockLimit;
    $attemptsToday  = $attemptsAfterUnblock;
} else {
    // حالة عادية — محاولات اليوم كله
    $effectiveMax  = $maxAttempts;
    $todayCount    = $pdo->prepare("SELECT COUNT(*) FROM card_failed_attempts WHERE user_id=? AND DATE(created_at)=CURDATE()");
    $todayCount->execute([$userId]);
    $attemptsToday = (int)$todayCount->fetchColumn();

    if ($attemptsToday >= $effectiveMax) {
        try {
            $pdo->prepare("INSERT INTO card_ip_blocks (ip,user_id,reason,unblock_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? HOUR))
                ON DUPLICATE KEY UPDATE reason=VALUES(reason),blocked_at=NOW(),unblock_at=VALUES(unblock_at)")
                ->execute([$ip, $userId, 'تجاوز الحد اليومي للمحاولات الخاطئة', $autoBlockHours]);
        } catch(Exception $e) {}
        echo json_encode(['ok'=>false,'error'=>"لقد تجاوزت الحد المسموح به ($effectiveMax محاولات اليوم). تم تعليق حسابك مؤقتاً.",'blocked'=>true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── جلب البطاقة ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM recharge_cards WHERE REPLACE(code,'-','')=? LIMIT 1");
$stmt->execute([$code]);
$card = $stmt->fetch();

if (!$card) {
    // تسجيل محاولة فاشلة
    $pdo->prepare("INSERT INTO card_failed_attempts (user_id,ip,code_tried) VALUES (?,?,?)")
        ->execute([$userId, $ip, substr($code, 0, 20)]);

    $remaining = $maxAttempts - $attemptsToday - 1;
    $msg = $remaining > 0
        ? "الكود غير صحيح — تبقى لك $remaining محاولة اليوم"
        : "الكود غير صحيح — لقد نفذت محاولاتك لهذا اليوم";

    echo json_encode(['ok' => false, 'error' => $msg, 'attempts_left' => max(0,$remaining)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($card['status'] === 'used') {
    // تسجيل المحاولة أيضاً (قد يكون يجرب أكواد مستخدمة)
    $pdo->prepare("INSERT INTO card_failed_attempts (user_id,ip,code_tried) VALUES (?,?,?)")
        ->execute([$userId, $ip, substr($code, 0, 20)]);
    echo '{"ok":false,"error":"هذا الكود استُخدم مسبقاً"}';
    exit;
}
if ($card['status'] === 'disabled') {
    echo '{"ok":false,"error":"هذا الكود معطل"}';
    exit;
}

// ── الشحن ────────────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE recharge_cards SET status='used', used_by=?, used_at=NOW() WHERE id=?")
        ->execute([$userId, $card['id']]);

    $ub = $pdo->prepare("SELECT balance FROM users WHERE id=?");
    $ub->execute([$userId]); $ub = $ub->fetch();
    $before = (float)($ub['balance'] ?? 0);
    $after  = $before + (float)$card['amount'];

    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$after, $userId]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?)")
        ->execute([$userId, 'topup', (float)$card['amount'], $before, $after, 'شحن بكود: '.$card['code']]);

    $pdo->commit();

    echo json_encode([
        'ok'          => true,
        'message'     => 'تم شحن رصيدك بـ '.number_format((float)$card['amount'],2).'$ بنجاح!',
        'amount'      => number_format((float)$card['amount'],2),
        'new_balance' => number_format($after,2),
    ], JSON_UNESCAPED_UNICODE);

} catch(Exception $e) {
    $pdo->rollBack();
    echo '{"ok":false,"error":"خطأ في المعالجة"}';
}
