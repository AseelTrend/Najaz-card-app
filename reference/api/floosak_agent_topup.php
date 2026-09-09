<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  Floosak Agent — نقطة نهاية AJAX للشحن عبر وكيل فلوسك
 *  POST /api/floosak_agent_topup.php
 *
 *  Actions:
 *    topup_direct   — شحن فوري (doTransaction)
 *    topup_pending  — إنشاء معاملة معلقة
 *    confirm        — تأكيد معاملة معلقة
 *    reject         — رفض معاملة معلقة
 *    check_status   — فحص حالة معاملة
 *    get_offers     — جلب الباقات المتاحة
 *    check_service  — فحص رصيد وباقات العميل
 * ══════════════════════════════════════════════════════════════
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/floosak_agent.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jr(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jrErr(string $msg, array $extra = []): void { jr(array_merge(['status' => false, 'message' => $msg], $extra)); }
function jrOk(array $d = []): void { jr(array_merge(['status' => true], $d)); }

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$user   = getUser();

// ── التحقق من تفعيل الخدمة ──────────────────────────────────
$agentCfg = floosak_agent_get_config($pdo);
if (empty($agentCfg['floosak_agent_enabled']) || $agentCfg['floosak_agent_enabled'] !== '1') {
    jrErr('خدمة شحن فلوسك غير متاحة حالياً');
}

// ════════════════════════════════════════════════════════════
//  Action: topup_direct — شحن فوري (doTransaction)
//  المُرسَل: target_number, amount, method_id, bunch_id, [with_solfa]
// ════════════════════════════════════════════════════════════
if ($action === 'topup_direct') {
    $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
    $amount       = (float)($_POST['amount']    ?? 0);
    $methodId     = (int)($_POST['method_id']   ?? 0);
    $bunchId      = trim($_POST['bunch_id']     ?? '');
    $withSolfa    = (int)($_POST['with_solfa']  ?? 0);

    // التحقق من المدخلات
    if (strlen($targetNumber) < 7)  jrErr('رقم الهاتف المستهدف غير صالح');
    if ($amount <= 0)                jrErr('المبلغ يجب أن يكون أكبر من صفر');
    if (!$methodId)                  jrErr('معرف المزود (method_id) مطلوب');
    if ($bunchId === '')             jrErr('معرف الباقة (bunch_id) مطلوب');

    // التحقق من رصيد المستخدم (إذا كان المشروع يخصم من رصيده)
    // هذا القسم اختياري — عدّله حسب منطق عملك
    // $required = ...; // احسب التكلفة المطلوبة
    // if ((float)$user['balance'] < $required) jrErr('رصيدك غير كافٍ');

    // تنفيذ الشحن
    $res = floosak_agent_topup($pdo, $targetNumber, $amount, $methodId, $bunchId, $withSolfa);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل تنفيذ الشحن — حاول مجدداً', [
            'statusCode' => $res['statusCode'] ?? null,
            'request_id' => $res['request_id'] ?? null,
        ]);
    }

    // نجح الشحن — سجّل في قاعدة البيانات
    try {
        $pdo->prepare("INSERT INTO floosak_agent_transactions
            (user_id, request_id, target_number, method_id, bunch_id, amount, status, response_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'completed', ?, NOW())")
            ->execute([
                $user['id'],
                $res['request_id'],
                $targetNumber,
                $methodId,
                $bunchId,
                $amount,
                json_encode($res['data'], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Exception $e) {
        // لا نوقف التنفيذ إذا فشل الحفظ — العملية نجحت فعلياً
    }

    jrOk([
        'message'    => 'تم الشحن بنجاح ✓',
        'request_id' => $res['request_id'],
        'statusCode' => $res['statusCode'],
        'data'       => $res['data'],
    ]);
}

// ════════════════════════════════════════════════════════════
//  Action: topup_pending — إنشاء معاملة معلقة
//  المُرسَل: target_number, amount, method_id, bunch_id, [with_solfa]
// ════════════════════════════════════════════════════════════
if ($action === 'topup_pending') {
    $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
    $amount       = (float)($_POST['amount']   ?? 0);
    $methodId     = (int)($_POST['method_id']  ?? 0);
    $bunchId      = trim($_POST['bunch_id']    ?? '');
    $withSolfa    = (int)($_POST['with_solfa'] ?? 0);

    if (strlen($targetNumber) < 7) jrErr('رقم الهاتف المستهدف غير صالح');
    if ($amount <= 0)               jrErr('المبلغ يجب أن يكون أكبر من صفر');
    if (!$methodId)                 jrErr('معرف المزود (method_id) مطلوب');
    if ($bunchId === '')            jrErr('معرف الباقة (bunch_id) مطلوب');

    $res = floosak_agent_topup_pending($pdo, $targetNumber, $amount, $methodId, $bunchId, $withSolfa);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل إنشاء المعاملة', [
            'statusCode' => $res['statusCode'] ?? null,
        ]);
    }

    $txId = $res['transaction_id'];

    // احفظ المعاملة المعلقة
    try {
        $pdo->prepare("INSERT INTO floosak_agent_transactions
            (user_id, request_id, transaction_id, target_number, method_id, bunch_id, amount, status, response_data, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())")
            ->execute([
                $user['id'],
                $res['request_id'],
                $txId,
                $targetNumber,
                $methodId,
                $bunchId,
                $amount,
                json_encode($res['data'], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Exception $e) {}

    jrOk([
        'message'        => 'تم إنشاء المعاملة — في انتظار التأكيد',
        'transaction_id' => $txId,
        'request_id'     => $res['request_id'],
        'data'           => $res['data'],
    ]);
}

// ════════════════════════════════════════════════════════════
//  Action: confirm — تأكيد معاملة معلقة
//  المُرسَل: transaction_id
// ════════════════════════════════════════════════════════════
if ($action === 'confirm') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    if (!$txId) jrErr('معرف المعاملة مطلوب');

    $res = floosak_agent_confirm($pdo, $txId);

    // حدّث الحالة في قاعدة البيانات
    try {
        $newStatus = $res['ok'] ? 'completed' : 'failed';
        $pdo->prepare("UPDATE floosak_agent_transactions SET status=?, updated_at=NOW() WHERE transaction_id=? AND user_id=?")
            ->execute([$newStatus, $txId, $user['id']]);
    } catch (Exception $e) {}

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل تأكيد المعاملة');
    }

    jrOk(['message' => 'تم تأكيد الشحن بنجاح ✓', 'data' => $res['data']]);
}

// ════════════════════════════════════════════════════════════
//  Action: reject — رفض معاملة معلقة
//  المُرسَل: transaction_id
// ════════════════════════════════════════════════════════════
if ($action === 'reject') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    if (!$txId) jrErr('معرف المعاملة مطلوب');

    $res = floosak_agent_reject($pdo, $txId);

    try {
        $pdo->prepare("UPDATE floosak_agent_transactions SET status='rejected', updated_at=NOW() WHERE transaction_id=? AND user_id=?")
            ->execute([$txId, $user['id']]);
    } catch (Exception $e) {}

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل رفض المعاملة');
    }

    jrOk(['message' => 'تم رفض المعاملة', 'data' => $res['data'] ?? null]);
}

// ════════════════════════════════════════════════════════════
//  Action: check_status — فحص حالة معاملة
//  المُرسَل: request_id
// ════════════════════════════════════════════════════════════
if ($action === 'check_status') {
    $requestId = trim($_POST['request_id'] ?? '');
    if (!$requestId) jrErr('request_id مطلوب');

    $res = floosak_agent_status($pdo, $requestId);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'المعاملة غير موجودة', ['statusCode' => $res['statusCode'] ?? null]);
    }

    jrOk([
        'statusCode'  => $res['statusCode'],
        'status_text' => floosak_agent_status_label($res['statusCode']),
        'data'        => $res['data'],
    ]);
}

// ════════════════════════════════════════════════════════════
//  Action: get_offers — جلب الباقات المتاحة
//  المُرسَل: target_number, method_id
// ════════════════════════════════════════════════════════════
if ($action === 'get_offers') {
    $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
    $methodId     = (int)($_POST['method_id'] ?? 0);

    if (strlen($targetNumber) < 7) jrErr('رقم الهاتف غير صالح');
    if (!$methodId)                 jrErr('معرف المزود مطلوب');

    $res = floosak_agent_offers($pdo, $targetNumber, $methodId);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل جلب الباقات');
    }

    jrOk([
        'offers'  => $res['data'] ?? [],
        'message' => 'تم جلب الباقات بنجاح',
    ]);
}

// ════════════════════════════════════════════════════════════
//  Action: check_service — فحص رصيد وباقات العميل
//  المُرسَل: target_number, method_id, bunch_id
// ════════════════════════════════════════════════════════════
if ($action === 'check_service') {
    $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
    $methodId     = (int)($_POST['method_id']  ?? 0);
    $bunchId      = trim($_POST['bunch_id']    ?? '');

    if (strlen($targetNumber) < 7) jrErr('رقم الهاتف غير صالح');
    if (!$methodId)                 jrErr('معرف المزود مطلوب');
    if ($bunchId === '')            jrErr('معرف الباقة مطلوب');

    $res = floosak_agent_service_info($pdo, $targetNumber, $methodId, $bunchId);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل الاستعلام عن الخدمة');
    }

    $data = $res['data'];
    jrOk([
        'balance' => $data['balance'] ?? '—',
        'loan'    => $data['loan']    ?? 0,
        'offers'  => $data['offers']  ?? [],
        'message' => 'تم الاستعلام بنجاح',
    ]);
}

// ── إجراء غير معروف ─────────────────────────────────────────
jrErr('إجراء غير صالح: ' . htmlspecialchars($action));
