<?php
/**
 * AJAX — فلوسك دفع مباشر
 * يستقبل طلبات العميل لشحن محفظته
 */

// ══════════════════════════════════════════════════════════════
// 🛡️ أول شيء — قبل أي include أو requireLogin
//    إذا لم يكن الطلب AJAX → 404 فوري بدون أي رد
// ══════════════════════════════════════════════════════════════
$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$hasInternalHeader = !empty($_SERVER['HTTP_X_NJAZ_INTERNAL']);
if (!$isAjaxRequest && !$hasInternalHeader) {
    http_response_code(404);
    exit();
}
$telegramInternalRequest = false;

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/accounting_helper.php';
require_once dirname(__DIR__) . '/includes/cashbox_helper.php';
require_once dirname(__DIR__) . '/includes/telegram_internal_auth.php';
if (file_exists(dirname(__DIR__).'/includes/referral.php')) require_once dirname(__DIR__).'/includes/referral.php';
require_once dirname(__DIR__) . '/includes/floosak_merchant.php';
if ($hasInternalHeader) $telegramInternalRequest = telegramAuthorizeInternalRequest($pdo);
if (!$telegramInternalRequest) requireLogin();

// 2. التحقق من أن الطلب قادم من نفس الموقع (منع CSRF)
$referer    = $_SERVER['HTTP_REFERER'] ?? '';
$siteOrigin = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    if (!$telegramInternalRequest && !empty($referer) && !str_starts_with($referer, $siteOrigin)) {
        http_response_code(403);
        exit(json_encode(['status' => false, 'message' => 'غير مصرح']));
    }

// 3. Rate Limiting — منع إساءة الاستخدام (5 طلبات/دقيقة لكل مستخدم)
function floosak_rate_limit(PDO $pdo, int $userId): void {
    $windowSec   = 60;  // نافذة زمنية: دقيقة
    $maxRequests = 5;   // الحد الأقصى للطلبات

    try {
        // أنشئ الجدول إن لم يكن موجوداً
        $pdo->exec("CREATE TABLE IF NOT EXISTS `floosak_rate_limits` (
            `id`         BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id`    INT NOT NULL,
            `created_at` DATETIME NOT NULL,
            KEY `idx_user_time` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // احذف السجلات القديمة خارج النافذة الزمنية
        $pdo->prepare("
            DELETE FROM floosak_rate_limits
            WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
        ")->execute([$userId, $windowSec]);

        // عدّ الطلبات الحالية
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM floosak_rate_limits
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$userId, $windowSec]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $maxRequests) {
            http_response_code(429);
            echo json_encode([
                'status'  => false,
                'message' => 'تجاوزت الحد المسموح به. الرجاء المحاولة بعد دقيقة.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // سجّل الطلب الحالي
        $pdo->prepare("INSERT INTO floosak_rate_limits (user_id, created_at) VALUES (?, NOW())")
            ->execute([$userId]);

    } catch (Exception $e) {
        // في حالة فشل الجدول — لا نوقف الخدمة، فقط نتابع
    }
}

// ══════════════════════════════════════════════════════════════

header('Content-Type: application/json; charset=utf-8');

function jr(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jrErr(string $msg): void { jr(['status' => false, 'message' => $msg]); }
function jrOk(array $d = []): void { jr(array_merge(['status' => true], $d)); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user   = getUser();

// طبّق Rate Limiting على الإجراءات الحساسة فقط
if (in_array($action, ['floosak_initiate', 'floosak_confirm'], true)) {
    floosak_rate_limit($pdo, (int)$user['id']);
}

// ──────────────────────────────────────────────────────────────
// الخطوة 1: العميل يطلب بدء الدفع
// يرسل: amount, phone
// يُرسل OTP لهاتف العميل من فلوسك
// ──────────────────────────────────────────────────────────────
if ($action === 'floosak_initiate') {
    $amount = (float)($_POST['amount'] ?? 0);
    $phone  = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');

    if ($amount < 100) jrErr('الحد الأدنى للشحن 100 ريال');
    if (strlen($phone) < 9) jrErr('أدخل رقم هاتفك في فلوسك بشكل صحيح');

    // جلب إعدادات فلوسك
    $cfg = floosak_get_config($pdo);
    if (empty($cfg['floosak_merchant_key'])) jrErr('بوابة فلوسك غير مفعّلة حالياً');

    $key      = $cfg['floosak_merchant_key'];
    $walletId = (int)($cfg['floosak_wallet_id'] ?? 0);
    if (!$walletId) jrErr('إعداد المحفظة غير مكتمل');

    // توليد reference_id فريد
    $refId = 'NJ' . $user['id'] . '_' . time() . '_' . mt_rand(1000, 9999);

    // استدعاء P2MCL
    $res = floosak_p2mcl($key, $walletId, $phone, $amount, $refId);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل إرسال طلب الدفع');
    }

    $purchaseId = $res['data']['id'] ?? null;
    if (!$purchaseId) jrErr('لم يتم استلام معرف العملية من فلوسك');

    // احفظ العملية مؤقتاً في قاعدة البيانات
    $pdo->prepare("INSERT INTO floosak_topup_sessions (user_id, purchase_id, reference_id, amount_yer, phone, status, created_at)
                   VALUES (?, ?, ?, ?, ?, 'pending', NOW())")
        ->execute([$user['id'], $purchaseId, $refId, $amount, $phone]);

    jrOk([
        'purchase_id' => $purchaseId,
        'amount'      => $amount,
        'gross'       => $res['data']['gross'] ?? $amount,
        'message'     => 'تم إرسال رمز التحقق إلى هاتفك المسجل في فلوسك',
    ]);
}

// ──────────────────────────────────────────────────────────────
// الخطوة 2: العميل يدخل OTP لتأكيد الدفع
// يرسل: purchase_id, otp
// ──────────────────────────────────────────────────────────────
if ($action === 'floosak_confirm') {
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $otp        = trim($_POST['otp'] ?? '');

    if (!$purchaseId) jrErr('معرف العملية مفقود');
    if (strlen($otp) !== 6 || !ctype_digit($otp)) jrErr('رمز التحقق يجب أن يكون 6 أرقام');

    // تحقق من أن هذه العملية تخص المستخدم الحالي
    $session = $pdo->prepare("SELECT * FROM floosak_topup_sessions WHERE purchase_id=? AND user_id=? AND status='pending'");
    $session->execute([$purchaseId, $user['id']]);
    $session = $session->fetch();

    if (!$session) jrErr('العملية غير موجودة أو منتهية الصلاحية');

    // تحقق من انتهاء الصلاحية (10 دقائق)
    if (strtotime($session['created_at']) < time() - 600) {
        $pdo->prepare("UPDATE floosak_topup_sessions SET status='expired' WHERE id=?")->execute([$session['id']]);
        jrErr('انتهت مهلة إدخال الرمز (10 دقائق). الرجاء المحاولة مجدداً');
    }

    $cfg = floosak_get_config($pdo);
    if (empty($cfg['floosak_merchant_key'])) jrErr('بوابة فلوسك غير متاحة');

    // تأكيد الدفع
    $res = floosak_confirm($cfg['floosak_merchant_key'], $purchaseId, $otp);

    if (!$res['ok']) {
        jrErr($res['message'] ?: 'فشل تأكيد الدفع — تحقق من الرمز وحاول مجدداً');
    }

    // تحقق أن الحالة Completed
    $resStatus = $res['data']['status']['en'] ?? '';
    if (strtolower($resStatus) !== 'completed') {
        jrErr('لم تكتمل العملية بعد (الحالة: ' . $resStatus . ')');
    }

    // حساب المبلغ بالدولار
    $amountYer  = (float)$session['amount_yer'];
    $amountUsd  = 0;
    try {
        $rate = $pdo->query("SELECT rate_to_usd FROM exchange_rates WHERE currency_code='YER' AND status=1")->fetchColumn();
        $amountUsd = $rate ? round($amountYer * $rate, 6) : 0;
    } catch (Exception $e) {}

    // تهيئة جداول الصناديق قبل بدء معاملة تحديث الرصيد؛ لا ننفذ DDL داخل المعاملة.
    // عدم اكتمال إعداد الصندوق لا يمنع الإيداع، لكنه يُسجل كحالة انتظار للمطابقة.
    try {
        cashboxEnsureSchema($pdo);
        cashboxEnsureOperationalColumns($pdo);
        accountingEnsureSchema($pdo);
    } catch (Throwable $e) {
        error_log('Floosak cashbox schema preparation: ' . $e->getMessage());
    }

    // إضافة الرصيد للمستخدم
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $u->execute([$user['id']]); $u = $u->fetch();
        $oldBal = (float)$u['balance'];
        $newBal = $oldBal + $amountUsd;

        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $user['id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$user['id'], 'credit', $amountUsd, $oldBal, $newBal,
                       'شحن فلوسك — ' . number_format($amountYer) . ' ريال — مرجع: ' . $session['reference_id']]);

        $pdo->prepare("UPDATE floosak_topup_sessions SET status='completed', amount_usd=?, completed_at=NOW() WHERE id=?")
            ->execute([$amountUsd, $session['id']]);

        // سجّل في topup_requests للأرشيف، واربط الإيداع بصندوق وسيلة الدفع التلقائية عند ضبطه.
        $topupId = 0;
        $methodCashboxId = 0;
        try {
            $methodSt = $pdo->query("SELECT id,cashbox_id FROM payment_methods WHERE payment_mode='auto' AND status=1 ORDER BY id ASC LIMIT 1");
            $method = $methodSt ? $methodSt->fetch(PDO::FETCH_ASSOC) : false;
            if ($method) {
                $methodId = (int)$method['id'];
                $methodCashboxId = (int)($method['cashbox_id'] ?? 0);
                $pdo->prepare("INSERT INTO topup_requests (user_id,method_id,currency_code,amount_sent,amount_usd,status,admin_notes,created_at,reviewed_at)
                               VALUES (?,?,'YER',?,?,'approved','فلوسك تلقائي',NOW(),NOW())")
                    ->execute([$user['id'], $methodId, $amountYer, $amountUsd]);
                $topupId = (int)$pdo->lastInsertId();

                if ($topupId > 0 && $methodCashboxId > 0) {
                    $cashboxPost = cashboxPostDeposit(
                        $pdo,
                        $topupId,
                        (int)$user['id'],
                        $methodCashboxId,
                        $amountYer,
                        'YER',
                        'إيداع فلوسك تلقائي — ID' . $topupId . ' — مرجع: ' . $session['reference_id'],
                        (int)($_SESSION['user_id'] ?? 0) ?: null
                    );
                    $postStatus = !empty($cashboxPost['posted']) ? 'posted' : 'posting_failed';
                    $postReason = !empty($cashboxPost['posted']) ? null : (string)($cashboxPost['reason'] ?? 'cashbox_posting_failed');
                    $pdo->prepare("UPDATE topup_requests SET cashbox_posting_status=?,cashbox_posting_reason=? WHERE id=?")
                        ->execute([$postStatus, $postReason, $topupId]);
                } elseif ($topupId > 0) {
                    $pdo->prepare("UPDATE topup_requests SET cashbox_posting_status='awaiting_cashbox',cashbox_posting_reason='automatic_payment_method_cashbox_not_configured' WHERE id=?")
                        ->execute([$topupId]);
                }
            }
        } catch (Throwable $e) {
            error_log('Floosak topup archive/cashbox posting: ' . $e->getMessage());
            if ($topupId > 0) {
                try { $pdo->prepare("UPDATE topup_requests SET cashbox_posting_status='posting_failed',cashbox_posting_reason=? WHERE id=?")->execute(['archive_or_cashbox_error', $topupId]); } catch (Throwable $ignored) {}
            }
        }

        $pdo->commit();
        // ── عمولة الإحالة ────────────────────────────────────────────
        try { if (function_exists('applyReferralOnTopup')) applyReferralOnTopup($pdo, $user['id'], (float)$amountUsd); } catch(Exception $e){}

        jrOk([
            'added_usd'   => $amountUsd,
            'new_balance' => $newBal,
            'message'     => 'تمت العملية بنجاح! تم إضافة $' . number_format($amountUsd, 4) . ' لرصيدك',
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        // سجّل الفشل
        $pdo->prepare("UPDATE floosak_topup_sessions SET status='failed' WHERE id=?")->execute([$session['id']]);
        jrErr('خطأ أثناء إضافة الرصيد — تواصل مع الدعم مع رقم العملية: ' . $session['reference_id']);
    }
}

// أي طلب غير معروف — 400 بدل 200 لعدم تأكيد وجود الملف
http_response_code(400);
jrErr('طلب غير مقبول');
