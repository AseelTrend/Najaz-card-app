<?php
/**
 * sync_order.php
 * ──────────────
 * يُستدعى عبر AJAX من صفحة الطلبات لفحص حالة الطلب مع المزود
 * ويحدّثها تلقائياً (إلغاء / تجهيز / إكمال) مع استرداد الرصيد والإشعار
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

// ── التحقق ────────────────────────────────────────────────────────────────────
if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'msg' => 'غير مصرح']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
if (!$orderId) {
    echo json_encode(['ok' => false, 'msg' => 'order_id مطلوب']);
    exit;
}

// [H-4 FIX] التحقق من ملكية الطلب — المستخدم لا يستطيع مزامنة طلبات غيره
// (الأدمن والموظف مستثنيان)
if (!isAdmin() && !isStaff()) {
    $ownerCheck = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $ownerCheck->execute([$orderId, $_SESSION['user_id']]);
    if (!$ownerCheck->fetch()) {
        echo json_encode(['ok' => false, 'msg' => 'غير مصرح']);
        exit;
    }
}

// ── جلب بيانات الطلب + المزود ────────────────────────────────────────────────
// [FIX] كان الاستعلام يبحث فقط عبر services.provider_id (النظام القديم)
// ويتجاهل used_provider_id و service_providers (النظام الحالي متعدد المزودين).
// النتيجة: أي خدمة تعتمد فقط على service_providers (بدون provider_id مباشر
// في جدول services) كانت لا تجد مزوداً أبداً هنا، فتتوقف المزامنة الفورية
// بصمت برسالة "لا يوجد مزود مرتبط" رغم أن الطلب أُرسل فعلياً لمزود حقيقي.
$stmt = $pdo->prepare("
    SELECT o.*,
           s.name AS service_name,
           p_note.id AS note_provider_id,
           p_svc.id AS service_provider_id,
           sp_first.provider_id AS first_provider_id,
           COALESCE(p_used.api_key,  p_note.api_key,  p_svc.api_key,  sp_first.api_key)  AS prov_key,
           COALESCE(p_used.api_url,  p_note.api_url,  p_svc.api_url,  sp_first.api_url)  AS prov_url,
           COALESCE(p_used.provider_type, p_note.provider_type, p_svc.provider_type, sp_first.provider_type) AS provider_type
    FROM orders o
    JOIN services s ON o.service_id = s.id
    LEFT JOIN providers p_used ON p_used.id = o.used_provider_id AND p_used.status = 1
    LEFT JOIN providers p_svc  ON p_svc.id  = s.provider_id AND p_svc.status = 1
    LEFT JOIN providers p_note ON p_note.status = 1
        AND LOWER(p_note.provider_type) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(o.notes, ''), ':', 1)))
    LEFT JOIN (
        SELECT sp.service_id, sp.provider_id, p.api_key, p.api_url, p.provider_type
        FROM service_providers sp
        JOIN providers p ON p.id = sp.provider_id AND p.status = 1
        WHERE sp.is_active = 1
        ORDER BY sp.priority ASC
    ) sp_first ON sp_first.service_id = o.service_id
    WHERE o.id = ? AND o.user_id = ?
");
$stmt->execute([$orderId, $_SESSION['user_id']]);
$ord = $stmt->fetch();

// تثبيت المزود الذي حُلّت به العملية على الطلب، خصوصاً للطلبات القديمة
// التي أُرسلت قبل إضافة used_provider_id.
if ($ord) {
    $resolvedProviderId = (int)($ord['used_provider_id'] ?? 0);
    if ($resolvedProviderId <= 0) {
        $resolvedProviderId = (int)($ord['note_provider_id'] ?? 0);
        if ($resolvedProviderId <= 0) $resolvedProviderId = (int)($ord['service_provider_id'] ?? 0);
        if ($resolvedProviderId <= 0) $resolvedProviderId = (int)($ord['first_provider_id'] ?? 0);
        if ($resolvedProviderId > 0) {
            $pdo->prepare("UPDATE orders SET used_provider_id=? WHERE id=? AND (used_provider_id IS NULL OR used_provider_id=0)")
                ->execute([$resolvedProviderId, $orderId]);
            $ord['used_provider_id'] = $resolvedProviderId;
        }
    }
}

if (!$ord) {
    echo json_encode(['ok' => false, 'msg' => 'الطلب غير موجود']);
    exit;
}

// ── إذا الطلب منتهٍ بالفعل ───────────────────────────────────────────────────
if (in_array($ord['status'], ['completed', 'cancelled', 'failed'])) {
    echo json_encode(['ok' => true, 'changed' => false, 'status' => $ord['status'], 'msg' => 'الطلب منتهٍ مسبقاً']);
    exit;
}

// ── إذا لا يوجد مزود أو رقم طلب عند المزود ──────────────────────────────────
if (empty($ord['provider_order_id']) || empty($ord['prov_key'])) {
    echo json_encode(['ok' => true, 'changed' => false, 'status' => $ord['status'], 'msg' => 'لا يوجد مزود مرتبط']);
    exit;
}

// ── فحص الحالة حسب نوع المزود ────────────────────────────────────────────────
$type = strtolower($ord['provider_type'] ?? '');
$result = null;

if (in_array($type, ['oranos', 'ap4stor'])) {
    $result = checkOranos($ord);
} elseif (in_array($type, ['smm_standard', 'smm', 'custom'])) {
    $result = checkSmm($ord);
} else {
    echo json_encode(['ok' => false, 'msg' => 'نوع مزود غير مدعوم: ' . $type]);
    exit;
}

if (!$result) {
    echo json_encode(['ok' => false, 'msg' => 'فشل الاتصال بالمزود']);
    exit;
}

// ── إذا لم تتغير الحالة ───────────────────────────────────────────────────────
if (!$result['changed']) {
    echo json_encode(['ok' => true, 'changed' => false, 'status' => $ord['status'],
        'provider_status' => $result['provider_status'] ?? '']);
    exit;
}

// ══ تطبيق التغيير ═══════════════════════════════════════════════════════════
$newStatus = $result['new_status'];
$noteText  = $result['note'];
$provNote  = $result['reason'] ?? '';

try {
    $pdo->beginTransaction();

    // ── استرداد الرصيد عند الإلغاء/الفشل ─────────────────────────────────────
    $refunded = false;
    if (in_array($newStatus, ['cancelled', 'failed'])) {
        $user = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $user->execute([$ord['user_id']]);
        $user = $user->fetch();

        if ($user && floatval($ord['total_price']) > 0) {
            $newBalance = $user['balance'] + $ord['total_price'];
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $user['id']]);
            $pdo->prepare("
                INSERT INTO wallet_transactions
                    (user_id, type, amount, balance_before, balance_after, description, reference_id)
                VALUES (?, 'credit', ?, ?, ?, ?, ?)
            ")->execute([
                $user['id'],
                $ord['total_price'],
                $user['balance'],
                $newBalance,
                'استرداد تلقائي — إلغاء الطلب #' . $orderId . ($provNote ? ' | السبب: ' . $provNote : ''),
                $orderId,
            ]);
            $refunded = true;
        }
    }

    // ── تحديث الطلب ───────────────────────────────────────────────────────────
    $pdo->prepare("
        UPDATE orders SET status=?, notes=?, status_message=? WHERE id=?
    ")->execute([$newStatus, $noteText, $provNote ?: $noteText, $orderId]);

    // ── سجل التطور ────────────────────────────────────────────────────────────
    try {
        $pdo->prepare("
            INSERT INTO order_status_log (order_id, status, message, source, created_by)
            VALUES (?, ?, ?, 'provider', 0)
        ")->execute([$orderId, $newStatus, $noteText]);
    } catch (\PDOException $e) {}

    // ── إشعار العميل ──────────────────────────────────────────────────────────
    try {
        $ordData = $pdo->prepare("
            SELECT o.*, s.name as service_name FROM orders o
            JOIN services s ON o.service_id=s.id WHERE o.id=?
        ");
        $ordData->execute([$orderId]);
        $ordData = $ordData->fetch();
        if ($ordData) {
            notifyOrderStatusChange($pdo, $ordData, $newStatus, $provNote ?: $noteText);
        }
    } catch (Exception $e) {}

    $pdo->commit();

    echo json_encode([
        'ok'              => true,
        'changed'         => true,
        'status'          => $newStatus,
        'provider_status' => $result['provider_status'] ?? '',
        'note'            => $noteText,
        'reason'          => $provNote,
        'refunded'        => $refunded,
        'refund_amount'   => $refunded ? floatval($ord['total_price']) : 0,
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'msg' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
}
exit;

// ══════════════════════════════════════════════════════════════════════════════
// Oranos Market / ap4stor
// ══════════════════════════════════════════════════════════════════════════════
// [FIX] replay_api هو الحقل الموثّق فعلياً في رد ap4stor — الشكل غير ثابت
// بين الأمثلة (مصفوفة نصوص أو مصفوفة كائنات فيها مفتاح replay)
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

function checkOranos(array $ord): ?array {
    $provOrderId = $ord['provider_order_id'];
    $apiKey      = $ord['prov_key'];
    $baseUrl     = rtrim($ord['prov_url'], '/');

    $url = $baseUrl . '/client/api/check?orders=[' . urlencode($provOrderId) . ']';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['api-token: ' . $apiKey, 'Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return null;
    $data = json_decode($res, true);
    if (!$data || !isset($data['data'][0])) return null;

    $item        = $data['data'][0];
    $provStatus  = strtolower($item['status'] ?? '');
    $reason      = extractReplayApiText($item['replay_api'] ?? null)
                ?: ($item['reason'] ?? $item['note'] ?? $item['message'] ?? '');

    // خريطة حالات Oranos
    $map = [
        'accept'    => 'completed',
        'accepted'  => 'completed',
        'complete'  => 'completed',
        'completed' => 'completed',
        'done'      => 'completed',
        'success'   => 'completed',
        'reject'    => 'cancelled',
        'rejected'  => 'cancelled',
        'cancel'    => 'cancelled',
        'cancelled' => 'cancelled',
        'refund'    => 'cancelled',
        'failed'    => 'cancelled',
        'fail'      => 'cancelled',
        'error'     => 'cancelled',
    ];

    $newStatus = $map[$provStatus] ?? null;

    if (!$newStatus || $newStatus === $ord['status']) {
        return ['changed' => false, 'provider_status' => $provStatus];
    }

    $note = 'Oranos: ' . $provStatus . ($reason ? ' — ' . $reason : '');
    return [
        'changed'         => true,
        'new_status'      => $newStatus,
        'provider_status' => $provStatus,
        'note'            => $note,
        'reason'          => $reason,
    ];
}

// ══════════════════════════════════════════════════════════════════════════════
// SMM Panel القياسي
// ══════════════════════════════════════════════════════════════════════════════
function checkSmm(array $ord): ?array {
    $ch = curl_init($ord['prov_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'key'    => $ord['prov_key'],
            'action' => 'status',
            'order'  => $ord['provider_order_id'],
        ]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return null;
    $data = json_decode($res, true);
    if (!$data) return null;

    $provStatus = strtolower($data['status'] ?? '');
    $reason     = $data['reason'] ?? '';

    $map = [
        'completed'  => 'completed',
        'complete'   => 'completed',
        'done'       => 'completed',
        'partial'    => 'completed',
        'canceled'   => 'cancelled',
        'cancelled'  => 'cancelled',
        'refunded'   => 'cancelled',
        'failed'     => 'cancelled',
        'error'      => 'cancelled',
    ];

    $newStatus = $map[$provStatus] ?? null;
    if (!$newStatus || $newStatus === $ord['status']) {
        return ['changed' => false, 'provider_status' => $provStatus];
    }

    $note = 'SMM: ' . $provStatus . ($reason ? ' — ' . $reason : '');
    return [
        'changed'         => true,
        'new_status'      => $newStatus,
        'provider_status' => $provStatus,
        'note'            => $note,
        'reason'          => $reason,
    ];
}
