<?php
// =====================================================
// api/sms_topup_verify.php
// مطابقة فورية — إذا وُجدت الرسالة شحن مباشرة، وإلا خطأ واضح
// =====================================================
require_once '../includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function jsonResp($ok, $msg, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);

// تحقق من تفعيل النظام
if (!getSetting('sms_topup_enabled')) {
    jsonResp(false, 'خدمة الشحن عبر SMS غير مفعّلة', [], 503);
}

// CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    jsonResp(false, 'طلب غير صالح', [], 403);
}

// ─── قراءة البيانات ────────────────────────────────
$phone      = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
$amount     = (float)($_POST['amount'] ?? 0);
$providerId = (int)($_POST['provider_id'] ?? 0);

if (strlen($phone) < 8)  jsonResp(false, 'رقم الهاتف غير صحيح');
if ($amount <= 0)        jsonResp(false, 'المبلغ يجب أن يكون أكبر من صفر');

// ─── Rate limit: 5 محاولات في 10 دقائق ────────────
$attempts = $pdo->prepare("
    SELECT COUNT(*) FROM sms_topup_requests
    WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
");
$attempts->execute([$userId]);
if ((int)$attempts->fetchColumn() >= 5) {
    jsonResp(false, 'حاولت كثيراً، انتظر قليلاً ثم حاول مجدداً', [], 429);
}

// ─── جلب بيانات المزود من DB (العملة والسعر من المصدر) ──
$rateToUsd    = 0.002;
$providerName = '';
$currency     = 'YER'; // افتراضي دائماً YER
if ($providerId > 0) {
    $prov = $pdo->prepare("SELECT rate_to_usd, name, currency FROM sms_providers WHERE id = ? AND status = 1");
    $prov->execute([$providerId]);
    $prov = $prov->fetch();
    if ($prov) {
        $rateToUsd    = (float)$prov['rate_to_usd'];
        $providerName = $prov['name'];
        $currency     = $prov['currency'] ?: 'YER';
    }
}

// ─── تسجيل الطلب ──────────────────────────────────
$pdo->prepare("
    INSERT INTO sms_topup_requests
        (user_id, phone_number, amount, currency, provider_id, status, expires_at, ip_address)
    VALUES (?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 1 MINUTE), ?)
")->execute([$userId, $phone, $amount, $currency, $providerId ?: null, $clientIp]);
$requestId = $pdo->lastInsertId();

// ─── البحث عن الرسالة في sms_inbox ───────────────
// شروط المطابقة:
// 1. الرسالة حالتها 'pending' (لم تُستخدم)
// 2. المبلغ مطابق تماماً (أو فارق لا يتجاوز 1 وحدة)
// 3. الرقم: آخر 8 أرقام تتطابق (لمرونة أكثر)
//    أو phone_number فارغ (مثل ون كاش التي لا تحتوي رقماً)
// 4. الرسالة خلال آخر 24 ساعة

$phoneEnd = substr($phone, -8); // آخر 8 أرقام

$q = $pdo->prepare("
    SELECT si.*, sp.rate_to_usd, sp.name AS provider_name
    FROM sms_inbox si
    LEFT JOIN sms_providers sp ON si.provider_id = sp.id
    WHERE si.status = 'pending'
      AND ABS(si.amount - ?) <= 1
      AND si.received_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
      AND (
            si.phone_number LIKE ?
         OR si.phone_number IS NULL
         OR si.phone_number = ''
      )
      AND (? = 0 OR si.provider_id = ?)
    ORDER BY ABS(si.amount - ?) ASC, si.received_at DESC
    LIMIT 1
");
$maxAgeMins = (int)(getSetting('sms_max_age_minutes') ?: 60);
$q->execute([$amount, $maxAgeMins, "%{$phoneEnd}", $providerId, $providerId, $amount]);
$sms = $q->fetch();

// ─── لم تُوجد الرسالة ─────────────────────────────
if (!$sms) {
    $pdo->prepare("UPDATE sms_topup_requests SET status='failed', failure_reason=? WHERE id=?")
        ->execute(["لم تُوجد رسالة مطابقة في النظام", $requestId]);

    // رسالة توضيحية للمستخدم
    $msg = 'لم يتم العثور على عملية مطابقة.' . "\n";
    $msg .= 'تأكد من:' . "\n";
    $msg .= '• صحة رقم الهاتف والمبلغ' . "\n";
    $msg .= '• أن الرسالة وصلت (تحقق من صندوق الرسائل)' . "\n";
    $msg .= '• اختيار المحفظة الصحيحة';

    jsonResp(false, $msg);
}

// ─── وُجدت — شحن الرصيد فوراً ─────────────────────
try {
    $pdo->beginTransaction();

    // قفل الصف لمنع التكرار
    $lock = $pdo->prepare("SELECT id FROM sms_inbox WHERE id = ? AND status = 'pending' FOR UPDATE");
    $lock->execute([$sms['id']]);
    if (!$lock->fetch()) {
        $pdo->rollBack();
        jsonResp(false, 'هذه الرسالة استُخدمت مسبقاً بواسطة مستخدم آخر');
    }

    // استخدام سعر المزود من الرسالة أو من طلب المستخدم
    $usedRate  = (float)($sms['rate_to_usd'] ?: $rateToUsd);
    $amountUsd = round((float)$sms['amount'] * $usedRate, 8);

    // رصيد المستخدم قبل وبعد
    $balBefore = (float)$pdo->query("SELECT balance FROM users WHERE id = {$userId} FOR UPDATE")->fetchColumn();
    $balAfter  = $balBefore + $amountUsd;

    // تحديث رصيد المستخدم
    $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")
        ->execute([$balAfter, $userId]);

    // تسجيل في wallet_transactions
    $desc = 'شحن رصيد SMS';
    if ($sms['provider_name']) $desc .= ' — ' . $sms['provider_name'];
    $desc .= ' — ' . number_format((float)$sms['amount'], 0) . ' من ' . $phone;

    $pdo->prepare("
        INSERT INTO wallet_transactions
            (user_id, type, amount, balance_before, balance_after, description, reference_id, sub_type)
        VALUES (?, 'topup', ?, ?, ?, ?, ?, 'sms')
    ")->execute([$userId, $amountUsd, $balBefore, $balAfter, $desc, $sms['id']]);

    // تحديث حالة الرسالة → used
    $pdo->prepare("
        UPDATE sms_inbox SET status = 'used', used_by = ?, used_at = NOW() WHERE id = ?
    ")->execute([$userId, $sms['id']]);

    // تحديث الطلب → credited
    $pdo->prepare("
        UPDATE sms_topup_requests
        SET status = 'credited', sms_inbox_id = ?, amount_usd = ?, credited_at = NOW()
        WHERE id = ?
    ")->execute([$sms['id'], $amountUsd, $requestId]);

    $pdo->commit();

    jsonResp(true, '✅ تم شحن رصيدك بنجاح!', [
        'credited'    => true,
        'amount_usd'  => $amountUsd,
        'new_balance' => $balAfter,
        'sms_amount'  => (float)$sms['amount'],
        'provider'    => $sms['provider_name'],
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('SMS topup error: ' . $e->getMessage());
    jsonResp(false, 'حدث خطأ داخلي، يرجى المحاولة مجدداً', [], 500);
}
