<?php
/**
 * includes/rate_limiter.php
 * ─────────────────────────
 * [M-3 FIX] Rate Limiting للـ API — يحمي من الإغراق واستنزاف الموارد
 *
 * يعتمد على جدول api_rate_limits في DB (يُنشأ تلقائياً عند أول استدعاء)
 * لا يحتاج Redis أو أي إضافات خارجية.
 *
 * الاستخدام:
 *   require_once '../includes/rate_limiter.php';
 *   apiRateLimit($pdo, 'login',       10, 60);   // 10 طلبات كل 60 ثانية
 *   apiRateLimit($pdo, 'place_order', 5,  60);   // 5 طلبات كل 60 ثانية
 */

/**
 * تطبيق Rate Limit
 *
 * @param PDO    $pdo       اتصال قاعدة البيانات
 * @param string $action    اسم العملية (login / place_order / redeem_card ...)
 * @param int    $maxHits   الحد الأقصى للطلبات في النافذة الزمنية
 * @param int    $windowSec حجم النافذة الزمنية بالثواني
 * @param bool   $perUser   true = حد لكل مستخدم | false = حد لكل IP
 */
function apiRateLimit(PDO $pdo, string $action, int $maxHits = 30, int $windowSec = 60, bool $perUser = false): void {

    // تحديد المفتاح: IP أو user_id
    $ip  = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
                            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                            ?? $_SERVER['REMOTE_ADDR']
                            ?? '0.0.0.0')[0]);

    if ($perUser && isset($_SESSION['user_id'])) {
        $key = 'u:' . (int)$_SESSION['user_id'] . ':' . $action;
    } else {
        $key = 'ip:' . $ip . ':' . $action;
    }

    $now = time();
    $windowStart = $now - $windowSec;

    try {
        // إنشاء الجدول إذا لم يكن موجوداً
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `api_rate_limits` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rl_key`     VARCHAR(150) NOT NULL,
                `hits`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `window_start` INT UNSIGNED NOT NULL,
                `last_hit`   INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_key` (`rl_key`),
                INDEX `idx_window` (`window_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // جلب السجل الحالي
        $stmt = $pdo->prepare("SELECT * FROM api_rate_limits WHERE rl_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row || $row['window_start'] < $windowStart) {
            // نافذة جديدة — أعد العداد
            $pdo->prepare("
                INSERT INTO api_rate_limits (rl_key, hits, window_start, last_hit)
                VALUES (?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    hits         = 1,
                    window_start = VALUES(window_start),
                    last_hit     = VALUES(last_hit)
            ")->execute([$key, $now, $now]);
            return; // أول طلب في النافذة — مسموح
        }

        // النافذة ما زالت نشطة
        $hits = (int)$row['hits'] + 1;

        if ($hits > $maxHits) {
            // تجاوز الحد
            $retryAfter = ($row['window_start'] + $windowSec) - $now;
            header('Content-Type: application/json; charset=utf-8');
            header('X-RateLimit-Limit: '     . $maxHits);
            header('X-RateLimit-Remaining: 0');
            header('Retry-After: '           . max(1, $retryAfter));
            http_response_code(429);
            echo json_encode([
                'status'  => false,
                'ok'      => false,
                'error'   => 'rate_limit',
                'message' => 'تجاوزت الحد المسموح. حاول بعد ' . max(1, $retryAfter) . ' ثانية.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // زيادة العداد
        $pdo->prepare("
            UPDATE api_rate_limits SET hits = ?, last_hit = ? WHERE rl_key = ?
        ")->execute([$hits, $now, $key]);

        // إضافة headers إعلامية
        header('X-RateLimit-Limit: '     . $maxHits);
        header('X-RateLimit-Remaining: ' . max(0, $maxHits - $hits));

    } catch (Exception $e) {
        // إذا فشل الجدول — لا نوقف الموقع، نتجاوز بصمت
        error_log('RateLimit error: ' . $e->getMessage());
    }
}

/**
 * تنظيف السجلات القديمة — يُنفَّذ تلقائياً بنسبة 2%
 */
function apiRateLimitCleanup(PDO $pdo, int $maxAge = 3600): void {
    if (mt_rand(1, 100) > 2) return;
    try {
        $pdo->prepare("DELETE FROM api_rate_limits WHERE last_hit < ?")
            ->execute([time() - $maxAge]);
    } catch (Exception $e) {}
}
