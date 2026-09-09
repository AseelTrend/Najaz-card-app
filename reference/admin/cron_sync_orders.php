<?php
/**
 * cron_sync_orders.php
 * =====================
 * مزامنة تلقائية لحالة الطلبات مع المزودين
 * يُشغَّل كـ Cron Job كل دقيقة أو دقيقتين:
 *   * * * * * php /path/to/cron_sync_orders.php >> /tmp/cron_sync.log 2>&1
 *
 * أو يمكن استدعاؤه عبر URL بـ cron_key للحماية:
 *   https://yourdomain.com/cron_sync_orders.php?key=YOUR_CRON_KEY
 */

define('CRON_MODE', true);
// [FIX] هذا الملف داخل admin/ — المسار الصحيح للإعدادات خطوة للأعلى،
// وليس admin/includes/config.php (غير موجود أصلاً). كان هذا يجعل
// السكربت يفشل بالكامل (Fatal error) في كل مرة يُستدعى فيها.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/notifications.php';
ignore_user_abort(true);

// ── حماية: تشغيل CLI أو مفتاح صحيح ──────────────────────────────────────────
$cronKey = getSetting('cron_key') ?: 'cron_secret_key_change_me';
$isCli   = php_sapi_name() === 'cli';
$isWeb   = isset($_GET['key']) && hash_equals($cronKey, $_GET['key']);

if (!$isCli && !$isWeb) {
    http_response_code(403);
    exit('Forbidden');
}

// ── منع التشغيل المتزامن (Lock File) ─────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/cron_sync_orders.lock';
$fp = fopen($lockFile, 'c');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    log_msg('⏭️  تخطي: مهمة أخرى تعمل');
    exit;
}

$startTime = microtime(true);
$synced = 0; $errors = 0;

log_msg('🔄 بدء مزامنة الطلبات...');

// ── جلب الطلبات المعلقة المرتبطة بمزودين ─────────────────────────────────────
$pendingOrders = $pdo->query("
    SELECT o.*,
           s.name as service_name,
           COALESCE(p_used.api_key,  p_svc.api_key,  sp_first.api_key)  as prov_key,
           COALESCE(p_used.api_url,  p_svc.api_url,  sp_first.api_url)  as prov_url,
           COALESCE(p_used.provider_type, p_svc.provider_type, sp_first.provider_type) as provider_type,
           u.id as uid
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN users    u ON o.user_id    = u.id
    -- 1: المزود المحفوظ مع الطلب مباشرة
    LEFT JOIN providers p_used ON p_used.id = o.used_provider_id AND p_used.status = 1
    -- 2: المزود القديم من services.provider_id
    LEFT JOIN providers p_svc  ON p_svc.id  = s.provider_id AND p_svc.status = 1
    -- 3: أول مزود نشط من service_providers (الأولوية 1)
    LEFT JOIN (
        SELECT sp.service_id, p.api_key, p.api_url, p.provider_type
        FROM service_providers sp
        JOIN providers p ON p.id = sp.provider_id AND p.status = 1
        WHERE sp.is_active = 1
        ORDER BY sp.priority ASC
    ) sp_first ON sp_first.service_id = o.service_id
    WHERE o.status IN ('pending','processing')
      AND o.provider_order_id IS NOT NULL
      AND o.provider_order_id != ''
      AND COALESCE(p_used.id, p_svc.id, sp_first.api_key) IS NOT NULL
    ORDER BY o.created_at ASC
    LIMIT 100
")->fetchAll();

log_msg('📋 طلبات للفحص: ' . count($pendingOrders));
foreach ($pendingOrders as $dbg) {
    log_msg("  → #{$dbg['id']} provider_type={$dbg['provider_type']} prov_url={$dbg['prov_url']} provider_order_id={$dbg['provider_order_id']}");
}

foreach ($pendingOrders as $ord) {
    try {
        $result = checkOrderWithProvider($pdo, $ord);
        if ($result) $synced++;
    } catch (Exception $e) {
        log_msg("❌ خطأ في الطلب #{$ord['id']}: " . $e->getMessage());
        $errors++;
    }
    usleep(300000); // 300ms بين كل طلب لتفادي Rate Limit
}

$elapsed = round(microtime(true) - $startTime, 2);
log_msg("✅ انتهت المزامنة — مُحدَّث: {$synced} | أخطاء: {$errors} | وقت: {$elapsed}s");

// ── رفع القفل ─────────────────────────────────────────────────────────────────
flock($fp, LOCK_UN);
fclose($fp);
if ($isWeb) { header('Content-Type: text/plain; charset=utf-8'); echo "done: synced={$synced} errors={$errors}"; }
exit;

// ══════════════════════════════════════════════════════════════════════════════
// دالة فحص حالة طلب مع مزوده
// ══════════════════════════════════════════════════════════════════════════════
// [FIX] استخراج سبب الرفض من replay_api — الحقل الموثّق فعلياً في وثائق ap4stor
function extractReplayApiText($replayApi): string {
    if (empty($replayApi) || !is_array($replayApi)) return '';
    $parts = [];
    foreach ($replayApi as $item) {
        if (is_string($item)) {
            $parts[] = $item;
        } elseif (is_array($item)) {
            if (isset($item['replay'])) {
                $parts[] = is_array($item['replay']) ? implode(', ', $item['replay']) : (string)$item['replay'];
            } else {
                $parts[] = implode(', ', array_filter($item, 'is_string'));
            }
        }
    }
    return implode(' | ', array_filter($parts));
}

function checkOrderWithProvider(PDO $pdo, array $ord): bool {
    $type      = strtolower($ord['provider_type'] ?? '');
    $provId    = $ord['provider_order_id'];
    $orderId   = $ord['id'];

    if (in_array($type, ['oranos', 'ap4stor'])) {
        return syncOranosOrder($pdo, $ord);
    }
    if (str_contains($type, 'smm') || $type === 'smm_standard' || $type === 'custom') {
        return syncSmmOrder($pdo, $ord);
    }
    log_msg("⚠️  مزود غير مدعوم: {$type} (طلب #{$orderId})");
    return false;
}

// ══════════════════════════════════════════════════════════════════════════════
// Oranos Market / ap4stor
// ══════════════════════════════════════════════════════════════════════════════
function syncOranosOrder(PDO $pdo, array $ord): bool {
    $provOrderId = $ord['provider_order_id'];
    $apiKey      = $ord['prov_key'];
    $baseUrl     = rtrim($ord['prov_url'], '/');

    $url = $baseUrl . '/client/api/check?orders=[' . urlencode($provOrderId) . ']';
    $response = httpGet($url, ['api-token: ' . $apiKey]);

    if (!$response) {
        log_msg("⚠️  فشل الاتصال بـ Oranos للطلب #{$ord['id']}");
        return false;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['data'][0])) {
        log_msg("⚠️  رد غير صالح من Oranos للطلب #{$ord['id']}: " . substr($response, 0, 100));
        return false;
    }

    $provData  = $data['data'][0];
    $provStatus = strtolower($provData['status'] ?? '');
    // [FIX] replay_api هو الحقل الموثّق فعلياً لدى ap4stor — البقية fallback فقط
    $provNote = extractReplayApiText($provData['replay_api'] ?? null);
    if ($provNote === '') {
        $provNote = $provData['reject_reason']
                 ?? $provData['cancel_reason']
                 ?? $provData['reason']
                 ?? $provData['note']
                 ?? $provData['comment']
                 ?? $provData['message']
                 ?? $provData['error']
                 ?? $provData['description']
                 ?? '';
    }
    // log الرد الكامل للتشخيص
    log_msg("📦 Oranos رد الطلب #{$ord['id']}: " . json_encode($provData, JSON_UNESCAPED_UNICODE));

    // خريطة الحالات
    $statusMap = [
        'accept'     => 'completed',
        'accepted'   => 'completed',
        'complete'   => 'completed',
        'completed'  => 'completed',
        'done'       => 'completed',
        'success'    => 'completed',
        'reject'     => 'cancelled',
        'rejected'   => 'cancelled',
        'cancel'     => 'cancelled',
        'cancelled'  => 'cancelled',
        'refund'     => 'cancelled',
        'failed'     => 'cancelled',
        'fail'       => 'cancelled',
        'wait'       => null, // لا تغيير
        'pending'    => null,
        'inprogress' => null,
        'processing' => null,
    ];

    $newStatus = $statusMap[$provStatus] ?? null;

    if ($newStatus === null) {
        // لا تغيير في الحالة — الطلب لا يزال قيد المعالجة
        return false;
    }

    if ($newStatus === $ord['status']) {
        // نفس الحالة، لا داعي للتحديث
        return false;
    }

    $noteText = $provStatus . ($provNote ? ' — ' . $provNote : '');

    updateOrderStatus($pdo, $ord, $newStatus, $noteText, $provNote);
    log_msg("🔄 الطلب #{$ord['id']} → {$newStatus} ({$provStatus})");
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// SMM Panel القياسي
// ══════════════════════════════════════════════════════════════════════════════
function syncSmmOrder(PDO $pdo, array $ord): bool {
    $provOrderId = $ord['provider_order_id'];
    $apiKey      = $ord['prov_key'];
    $apiUrl      = $ord['prov_url'];

    $postData = http_build_query([
        'key'    => $apiKey,
        'action' => 'status',
        'order'  => $provOrderId,
    ]);
    $response = httpPost($apiUrl, $postData);

    if (!$response) {
        log_msg("⚠️  فشل الاتصال بـ SMM للطلب #{$ord['id']}");
        return false;
    }

    $data = json_decode($response, true);
    if (!$data) return false;

    $provStatus = strtolower($data['status'] ?? '');
    $provNote   = $data['reason'] ?? $data['remains'] ?? '';

    $statusMap = [
        'completed'  => 'completed',
        'complete'   => 'completed',
        'done'       => 'completed',
        'canceled'   => 'cancelled',
        'cancelled'  => 'cancelled',
        'refunded'   => 'cancelled',
        'partial'    => 'completed', // جزئي = مكتمل
        'failed'     => 'cancelled',
    ];

    $newStatus = $statusMap[$provStatus] ?? null;
    if ($newStatus === null || $newStatus === $ord['status']) return false;

    $noteText = $provStatus . ($provNote ? ' — ' . $provNote : '');

    updateOrderStatus($pdo, $ord, $newStatus, $noteText, $provNote);
    log_msg("🔄 الطلب #{$ord['id']} → {$newStatus} ({$provStatus})");
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// تحديث حالة الطلب + استرداد الرصيد + إشعار العميل
// ══════════════════════════════════════════════════════════════════════════════
function updateOrderStatus(PDO $pdo, array $ord, string $newStatus, string $noteText, string $provNote = ''): void {
    $orderId = $ord['id'];
    $userId  = $ord['user_id'];

    $pdo->beginTransaction();
    try {
        // ── استرداد الرصيد عند الإلغاء ───────────────────────────────────────
        if (in_array($newStatus, ['cancelled', 'failed']) && !in_array($ord['status'], ['cancelled', 'failed'])) {
            $user = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $user->execute([$userId]);
            $user = $user->fetch();

            if ($user && $ord['total_price'] > 0) {
                $newBalance = $user['balance'] + $ord['total_price'];
                $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $userId]);
                $pdo->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_before, balance_after, description, reference_id)
                    VALUES (?, 'credit', ?, ?, ?, ?, ?)
                ")->execute([
                    $userId,
                    $ord['total_price'],
                    $user['balance'],
                    $newBalance,
                    'استرداد تلقائي - إلغاء الطلب #' . $orderId . ($provNote ? ' | ' . $provNote : ''),
                    $orderId,
                ]);
                log_msg("💰 استرداد {$ord['total_price']} للمستخدم #{$userId} (الطلب #{$orderId})");
            }
        }

        // ── تحديث حالة الطلب ──────────────────────────────────────────────────
        // خريطة أسباب واضحة للعميل
        $statusReasons = [
            'reject'     => 'رُفض الطلب من المزود',
            'rejected'   => 'رُفض الطلب من المزود',
            'cancel'     => 'تم إلغاء الطلب',
            'cancelled'  => 'تم إلغاء الطلب',
            'refund'     => 'تم استرداد المبلغ',
            'failed'     => 'فشل تنفيذ الطلب',
            'fail'       => 'فشل تنفيذ الطلب',
            'accept'     => 'تم قبول الطلب',
            'accepted'   => 'تم قبول الطلب',
            'complete'   => 'تم تنفيذ الطلب بنجاح',
            'completed'  => 'تم تنفيذ الطلب بنجاح',
            'done'       => 'تم تنفيذ الطلب بنجاح',
            'success'    => 'تم تنفيذ الطلب بنجاح',
            'processing' => 'جاري تنفيذ الطلب',
            'inprogress' => 'جاري تنفيذ الطلب',
            'wait'       => 'في انتظار التنفيذ',
            'pending'    => 'في انتظار المعالجة',
        ];

        // السبب الواضح: من المزود أولاً، ثم الخريطة، ثم افتراضي
        // [FIX] $provStatus لم يكن مُعرَّفاً هنا أصلاً (متغير محلي في الدوال المستدعية)
        // نستخرجه من noteText بدل ذلك (أول كلمة قبل ' — ')
        $provStatusKey = strtolower(trim(explode(' — ', $noteText)[0] ?? ''));
        if (!empty($provNote)) {
            $customerMsg = $provNote;
        } elseif (isset($statusReasons[$provStatusKey])) {
            $customerMsg = $statusReasons[$provStatusKey];
        } elseif ($newStatus === 'cancelled' || $newStatus === 'failed') {
            $customerMsg = 'تم إلغاء الطلب من المزود';
        } elseif ($newStatus === 'completed') {
            $customerMsg = 'تم تنفيذ الطلب بنجاح';
        } elseif ($newStatus === 'processing') {
            $customerMsg = 'جاري تنفيذ الطلب';
        } else {
            $customerMsg = $noteText;
        }

        $pdo->prepare("
            UPDATE orders
            SET status=?, notes=?, status_message=?
            WHERE id=?
        ")->execute([$newStatus, $noteText, $customerMsg, $orderId]);

        // ── سجل تطور الحالة ──────────────────────────────────────────────────
        try {
            $pdo->prepare("
                INSERT INTO order_status_log (order_id, status, message, source, created_by)
                VALUES (?, ?, ?, 'cron', 0)
            ")->execute([$orderId, $newStatus, $noteText]);
        } catch (\PDOException $e) {}

        // ── إشعار العميل ─────────────────────────────────────────────────────
        try {
            $ordData = $pdo->prepare("
                SELECT o.*, s.name as service_name
                FROM orders o
                JOIN services s ON o.service_id = s.id
                WHERE o.id = ?
            ");
            $ordData->execute([$orderId]);
            $ordData = $ordData->fetch();
            if ($ordData) {
                notifyOrderStatusChange($pdo, $ordData, $newStatus, $customerMsg);
            }
        } catch (Exception $e) {
            log_msg("⚠️  فشل إرسال الإشعار للطلب #{$orderId}: " . $e->getMessage());
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// HTTP Helpers
// ══════════════════════════════════════════════════════════════════════════════
function httpGet(string $url, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'CronSync/1.0',
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { log_msg("cURL GET error: $err"); return null; }
    return $res ?: null;
}

function httpPost(string $url, string $postData): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'CronSync/1.0',
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { log_msg("cURL POST error: $err"); return null; }
    return $res ?: null;
}

// ══════════════════════════════════════════════════════════════════════════════
function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    // سجل في قاعدة البيانات اختياري
    // يمكن تفعيله بإضافة جدول cron_logs
}
