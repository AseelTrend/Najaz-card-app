<?php
// api/usdt_deposit.php — إنشاء جلسة إيداع USDT وجلب حالتها
require_once '../includes/config.php';
requireLogin();

header('Content-Type: application/json');

// ── تهيئة الجدول إن لم يكن موجوداً ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `usdt_deposit_sessions` (
      `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `user_id`         INT UNSIGNED NOT NULL,
      `method_id`       INT UNSIGNED NOT NULL,
      `amount_local`    DECIMAL(18,4) NOT NULL,
      `currency_code`   VARCHAR(10)  NOT NULL DEFAULT 'USD',
      `amount_usd`      DECIMAL(18,4) NOT NULL,
      `unique_amount`   DECIMAL(18,4) NOT NULL,
      `wallet_address`  VARCHAR(255) NOT NULL,
      `network`         VARCHAR(50)  NOT NULL DEFAULT 'BEP20',
      `min_deposit_usd` DECIMAL(10,4) NOT NULL DEFAULT 5.0000,
      `fee_percent`     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
      `amount_credited` DECIMAL(18,4) NOT NULL DEFAULT 0,
      `tx_id`           VARCHAR(255) NULL,
      `status`          ENUM('pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending',
      `expires_at`      DATETIME NOT NULL,
      `confirmed_at`    DATETIME NULL,
      `admin_note`      TEXT NULL,
      `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_user`   (`user_id`),
      INDEX `idx_status` (`status`),
      INDEX `idx_unique` (`unique_amount`),
      INDEX `idx_expire` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$action = $_GET['action'] ?? $_POST['action'] ?? 'create';
$userId = (int)$_SESSION['user_id'];

// CSRF للـ POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || $token !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'طلب غير صالح']);
        exit;
    }
}

// ══════════════════════════════
// إنشاء جلسة جديدة
// ══════════════════════════════
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $methodId    = (int)($_POST['method_id'] ?? 0);
    $amountLocal = (float)($_POST['amount_local'] ?? 0);
    $currCode    = strtoupper(trim($_POST['currency_code'] ?? 'USD'));

    if (!$methodId || $amountLocal <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'بيانات غير صحيحة']);
        exit;
    }

    // جلب إعدادات الطريقة — سواء من payment_methods أو sms_providers
    $method = null;
    try {
        $m = $pdo->prepare("SELECT * FROM payment_methods WHERE id=? AND status=1");
        $m->execute([$methodId]); $method = $m->fetch();
    } catch (Exception $e) {}
    if (!$method) {
        // تحقق من sms_providers
        try {
            $m = $pdo->prepare("SELECT id, name as name, currency, rate_to_usd FROM sms_providers WHERE id=? AND provider_type='usdt' AND status=1");
            $m->execute([$methodId]); $method = $m->fetch();
        } catch (Exception $e) {}
    }
    if (!$method) {
        echo json_encode(['ok' => false, 'msg' => 'طريقة الدفع غير موجودة']);
        exit;
    }

    // جلب إعدادات USDT من settings
    // جلب العنوان من المزود أولاً ثم من الإعدادات العامة
    $walletAddress = '';
    $network       = 'BEP20';
    $minDeposit    = (float)(getSetting('usdt_min_deposit') ?: 5);
    $feePercent    = (float)(getSetting('usdt_fee_percent') ?: 0);
    $ttlMin        = (int)(getSetting('usdt_session_ttl') ?: 20);

    // محاولة جلب من sms_providers أولاً (للمزودين من نوع usdt)
    try {
        $spRow = $pdo->prepare("SELECT usdt_wallet_address, rate_to_usd FROM sms_providers WHERE id=? AND provider_type='usdt' AND status=1");
        $spRow->execute([$methodId]);
        $spRow = $spRow->fetch();
        if ($spRow && !empty($spRow['usdt_wallet_address'])) {
            $walletAddress = $spRow['usdt_wallet_address'];
            // سعر الصرف من المزود مباشرة
            if ($spRow['rate_to_usd'] > 0) $rateVal = (float)$spRow['rate_to_usd'];
        }
    } catch (Exception $e) {}

    if (!$walletAddress) {
        $walletAddress = getSetting('usdt_bep20_address');
        $network       = getSetting('usdt_bep20_network') ?: 'BEP20';
    }

    if (!$walletAddress) {
        echo json_encode(['ok' => false, 'msg' => 'لم يتم ضبط عنوان المحفظة — تواصل مع الإدارة']);
        exit;
    }

    // تحويل للدولار — من جدول exchange_rates أو من المزود مباشرة
    $rateVal = 0;
    try {
        $rate = $pdo->prepare("SELECT rate_to_usd FROM exchange_rates WHERE currency_code=? AND status=1");
        $rate->execute([$currCode]);
        $rateVal = (float)$rate->fetchColumn();
    } catch (Exception $e) {}
    if (!$rateVal) {
        // جرب من sms_providers مباشرة
        try {
            $pr2 = $pdo->prepare("SELECT rate_to_usd FROM sms_providers WHERE id=? AND status=1");
            $pr2->execute([$methodId]); $rateVal = (float)$pr2->fetchColumn();
        } catch (Exception $e) {}
    }
    if (!$rateVal) $rateVal = 1;
    $amountUSD = round($amountLocal * $rateVal, 4);

    if ($amountUSD < $minDeposit) {
        echo json_encode(['ok' => false, 'msg' => "الحد الأدنى للإيداع هو $minDeposit\$"]);
        exit;
    }

    // إنهاء الجلسات المنتهية قديماً
    $pdo->exec("UPDATE usdt_deposit_sessions SET status='expired' WHERE status='pending' AND expires_at < NOW()");

    // توليد فاصل فريد
    // نبحث عن unique_amount غير مستخدم حالياً لنفس المبلغ
    $uniqueAmount = null;
    for ($suffix = 1; $suffix <= 9999; $suffix++) {
        $candidate = round($amountUSD + ($suffix * 0.0001), 4);
        $exists = $pdo->prepare("SELECT id FROM usdt_deposit_sessions WHERE unique_amount=? AND status='pending' AND expires_at > NOW()");
        $exists->execute([$candidate]);
        if (!$exists->fetchColumn()) {
            $uniqueAmount = $candidate;
            break;
        }
    }

    if ($uniqueAmount === null) {
        echo json_encode(['ok' => false, 'msg' => 'النظام مشغول، حاول لاحقاً']);
        exit;
    }

    // المبلغ الذي سيُضاف للرصيد (بعد الرسوم، بدون الفاصل)
    $amountCredited = round($amountUSD * (1 - $feePercent / 100), 4);
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlMin} minutes"));

    // حفظ الجلسة
    $pdo->prepare("INSERT INTO usdt_deposit_sessions
        (user_id,method_id,amount_local,currency_code,amount_usd,unique_amount,wallet_address,network,min_deposit_usd,fee_percent,amount_credited,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$userId,$methodId,$amountLocal,$currCode,$amountUSD,$uniqueAmount,$walletAddress,$network,$minDeposit,$feePercent,$amountCredited,$expiresAt]);

    $sessionId = $pdo->lastInsertId();

    echo json_encode([
        'ok'             => true,
        'session_id'     => $sessionId,
        'wallet_address' => $walletAddress,
        'network'        => $network,
        'amount_usd'     => $amountUSD,
        'unique_amount'  => $uniqueAmount,
        'amount_credited'=> $amountCredited,
        'fee_percent'    => $feePercent,
        'expires_at'     => $expiresAt,
        'ttl_seconds'    => $ttlMin * 60,
    ]);
    exit;
}

// ══════════════════════════════
// إرسال رقم العملية (tx_id)
// ══════════════════════════════
if ($action === 'submit_tx' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $txId      = trim($_POST['tx_id'] ?? '');

    if (!$sessionId || !$txId) {
        echo json_encode(['ok' => false, 'msg' => 'أدخل رقم العملية']);
        exit;
    }

    $session = $pdo->prepare("SELECT * FROM usdt_deposit_sessions WHERE id=? AND user_id=? AND status='pending'");
    $session->execute([$sessionId, $userId]);
    $session = $session->fetch();

    if (!$session) {
        echo json_encode(['ok' => false, 'msg' => 'الجلسة غير موجودة أو انتهت']);
        exit;
    }

    if (strtotime($session['expires_at']) < time()) {
        $pdo->prepare("UPDATE usdt_deposit_sessions SET status='expired' WHERE id=?")->execute([$sessionId]);
        echo json_encode(['ok' => false, 'msg' => 'انتهى وقت الجلسة، أنشئ طلباً جديداً']);
        exit;
    }

    // حفظ tx_id — ينتظر مراجعة الأدمن
    $pdo->prepare("UPDATE usdt_deposit_sessions SET tx_id=? WHERE id=? AND user_id=?")->execute([$txId, $sessionId, $userId]);

    // إرسال إشعار للأدمن
    try {
        $user = $pdo->prepare("SELECT username, full_name FROM users WHERE id=?");
        $user->execute([$userId]);
        $user = $user->fetch();
        $name = $user['full_name'] ?: $user['username'];
        $msg  = "💰 طلب إيداع USDT جديد\nالمستخدم: {$name}\nالمبلغ: {$session['unique_amount']} USDT\nرقم العملية: {$txId}";
        $tgToken = getSetting('telegram_bot_token');
        $tgChat  = getSetting('telegram_chat_id');
        if ($tgToken && $tgChat) {
            file_get_contents("https://api.telegram.org/bot{$tgToken}/sendMessage?chat_id={$tgChat}&text=" . urlencode($msg));
        }
    } catch (Exception $e) {}

    echo json_encode(['ok' => true, 'msg' => 'تم إرسال رقم العملية، سيتم مراجعته وإضافة رصيدك قريباً ✅']);
    exit;
}

// ══════════════════════════════
// جلب حالة الجلسة
// ══════════════════════════════
if ($action === 'status') {
    $sessionId = (int)($_GET['session_id'] ?? 0);
    $session = $pdo->prepare("SELECT status, expires_at, amount_credited FROM usdt_deposit_sessions WHERE id=? AND user_id=?");
    $session->execute([$sessionId, $userId]);
    $session = $session->fetch();
    if (!$session) {
        echo json_encode(['ok' => false, 'msg' => 'جلسة غير موجودة']);
        exit;
    }
    $remaining = max(0, strtotime($session['expires_at']) - time());
    echo json_encode([
        'ok'             => true,
        'status'         => $session['status'],
        'remaining'      => $remaining,
        'amount_credited'=> $session['amount_credited'],
    ]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'action غير معروف']);
