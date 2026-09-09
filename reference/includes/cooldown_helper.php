<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  includes/cooldown_helper.php
 *  نظام Cooldown مركزي موحّد بين كل قنوات إنشاء الطلبات
 *  (الموقع مباشرة + الربط الخارجي API + أي قناة أخرى تستدعيه)
 * ══════════════════════════════════════════════════════════════════
 *
 * الفكرة: يُمنع إنشاء طلب جديد لنفس "المعرّف" (نفس بيانات الحقول
 * المُدخلة: رقم اللاعب/الآيدي/إلخ لنفس الخدمة) إذا كان آخر طلب بنفس
 * المعرّف حالته "pending" ولم تمر مدة الـ Cooldown المحددة بعد.
 *
 * أي حالة أخرى (completed/cancelled/failed/rejected...) لا تمنع
 * الطلب الجديد إطلاقاً، بغض النظر عن الوقت.
 */

/** يجلب مدة الـ Cooldown من الإعدادات (قابلة للتعديل من لوحة الإدارة) */
function cooldownGetSeconds(PDO $pdo): int {
    $v = getSetting('cooldown_seconds');
    if ($v === null || $v === '' || !is_numeric($v)) return 60; // القيمة الافتراضية
    return max(0, (int)$v);
}

/**
 * يبني مفتاح تطابق موحّد بين كل القنوات: نفس الخدمة + نفس بيانات
 * الحقول المُدخلة (بغض النظر عن اسم الحقل نفسه: playerId, user_id...)
 */
function cooldownBuildKey(int $serviceId, array $fieldData): string {
    $normalized = [];
    foreach ($fieldData as $k => $v) {
        $normalized[strtolower(trim((string)$k))] = trim((string)$v);
    }
    ksort($normalized);
    return md5($serviceId . '|' . json_encode($normalized, JSON_UNESCAPED_UNICODE));
}

/**
 * فحص وحجز الـ Cooldown بأمان تام ضد التزامن (Race Condition) عبر
 * قفل ذري من نوع MySQL Named Lock — يُستدعى وجوباً قبل أي عملية خصم
 * رصيد أو إرسال طلب لمزود خارجي.
 *
 * @return array
 *   نجاح:  ['ok'=>true,  'lock_name'=>string, 'cooldown_key'=>string]
 *   رفض:   ['ok'=>false, 'remaining'=>int]  (المتبقي بالثواني)
 *
 * ⚠️ في حال النجاح، يجب استدعاء cooldownRelease() حتماً بعد انتهاء
 * إنشاء الطلب (نجاحاً أو فشلاً) لتحرير القفل فوراً بدل انتظار
 * انتهاء الاتصال بقاعدة البيانات.
 */
function cooldownAcquire(PDO $pdo, int $serviceId, array $fieldData): array {
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS cooldown_key VARCHAR(32) DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE orders ADD INDEX idx_cooldown_key (cooldown_key)"); } catch (Exception $e) {}

    $seconds = cooldownGetSeconds($pdo);
    if ($seconds <= 0) return ['ok' => true, 'lock_name' => null, 'cooldown_key' => null];

    $key      = cooldownBuildKey($serviceId, $fieldData);
    $lockName = 'njaz_cd_' . $key;

    // قفل ذري يمنع طلبين متزامنين لنفس المعرّف بنفس اللحظة من تجاوز الفحص معاً
    $gotLock = $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 5)")->fetchColumn();
    if (!$gotLock) {
        // تعذّر الحصول على القفل خلال 5 ثوانٍ = طلب متزامن آخر يعالج نفس المعرّف الآن
        return ['ok' => false, 'remaining' => $seconds];
    }

    try {
        $stmt = $pdo->prepare("SELECT status, created_at FROM orders WHERE cooldown_key=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$key]);
        $last = $stmt->fetch();

        if ($last && $last['status'] === 'pending') {
            $elapsed = time() - strtotime($last['created_at']);
            if ($elapsed < $seconds) {
                $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
                return ['ok' => false, 'remaining' => $seconds - $elapsed];
            }
        }
        return ['ok' => true, 'lock_name' => $lockName, 'cooldown_key' => $key];
    } catch (Exception $e) {
        // أمان: أي خطأ غير متوقع بالفحص نفسه لا يجب أن يوقف النظام بالكامل
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
        return ['ok' => true, 'lock_name' => null, 'cooldown_key' => null];
    }
}

/** يحرر قفل الـ Cooldown بعد انتهاء إنشاء الطلب (نجاحاً أو فشلاً) */
function cooldownRelease(PDO $pdo, ?string $lockName): void {
    if (!$lockName) return;
    try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")"); } catch (Exception $e) {}
}

/** رسالة موحّدة تُعرض للمستخدم/الـ API عند الرفض بسبب الـ Cooldown (عربي) */
function cooldownMessage(int $remaining): string {
    return "الرجاء الانتظار {$remaining} ثانية قبل إرسال طلب جديد لنفس البيانات (يوجد طلب سابق قيد الانتظار)";
}

/** نفس الرسالة بالإنجليزي (لاستخدامها في حقل message الإنجليزي بردود الربط الخارجي) */
function cooldownMessageEn(int $remaining): string {
    return "Please wait {$remaining} second(s) before sending a new request for the same data (a previous request is still pending)";
}
