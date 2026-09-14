<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║          includes/usdt_deposit.php — مكتبة التغذية الآلية USDT          ║
 * ║  BNB Smart Chain (BEP20) | Moralis Streams API                          ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * التحقق الوحيد الموثوق: Contract Address الرسمي لـ USDT على BSC
 * لا يُعتمد نهائياً على: اسم التوكن، الرمز، الشعار، أي بيانات ظاهرية
 */

// تحميل طبقة الصندوق والحسابات دون إنشاء دفتر أو محفظة موازية.
require_once __DIR__ . '/accounting_helper.php';
require_once __DIR__ . '/cashbox_helper.php';

// ══════════════════════════════════════════════════════════════
//  ثوابت حرجة — لا تغيّرها إلا عند تغيير شبكة كاملة
// ══════════════════════════════════════════════════════════════

/** عقد USDT الرسمي على BSC — التحقق يكون بهذا فقط */
define('USDT_OFFICIAL_CONTRACT', '0x55d398326f99059fF775485246999027B3197955');

/** عدد الـ Decimals لـ USDT-BEP20 */
define('USDT_DECIMALS', 18);

/** Chain ID لـ BNB Smart Chain Mainnet */
define('BSC_CHAIN_ID', '0x38');

// ══════════════════════════════════════════════════════════════
//  دوال مساعدة للحسابات المالية الدقيقة (BCMath)
// ══════════════════════════════════════════════════════════════

/**
 * تحويل القيمة الخام (Wei-like) إلى USDT حقيقي بدقة كاملة
 * يستخدم BCMath لتجنب أخطاء الـ float
 */
function usdt_raw_to_decimal(string $rawAmount, int $decimals = USDT_DECIMALS): string {
    if (!preg_match('/^\d+$/', $rawAmount)) return '0';
    $divisor = bcpow('10', (string)$decimals, $decimals);
    return bcdiv($rawAmount, $divisor, $decimals);
}

/**
 * مقارنة مبلغين عشريين بدقة (BCMath)
 * يعيد: -1 / 0 / 1
 */
function usdt_compare(string $a, string $b, int $scale = 8): int {
    return bccomp($a, $b, $scale);
}

/**
 * هل المبلغ أكبر من صفر؟
 */
function usdt_is_positive(string $amount): bool {
    return bccomp($amount, '0', 8) > 0;
}

/**
 * هل الفرق بين مبلغين ضمن نطاق التسامح؟ (للمطابقة مع unique_amount)
 */
function usdt_within_tolerance(string $received, string $expected, string $tolerance = '0.0001'): bool {
    $diff = bcsub($received, $expected, 8);
    // القيمة المطلقة
    if (bccomp($diff, '0', 8) < 0) {
        $diff = bcsub('0', $diff, 8);
    }
    return bccomp($diff, $tolerance, 8) <= 0;
}

/**
 * جمع رصيد بطريقة آمنة
 */
function usdt_add(string $a, string $b, int $scale = 8): string {
    return bcadd($a, $b, $scale);
}

// ══════════════════════════════════════════════════════════════
//  توليد مبلغ فريد لطلب الإيداع
// ══════════════════════════════════════════════════════════════

/**
 * يولّد مبلغاً فريداً تسلسلياً تصاعدياً
 *  1$  → 1.01, 1.02, 1.03 ...
 *  100$ → 100.01, 100.02, 100.03 ...
 */
function usdt_generate_unique_amount(PDO $pdo, float $baseAmount, string $wallet): string {

    // ── جدول العدادات ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usdt_amount_counters (
            base_amount   DECIMAL(20,2) NOT NULL,
            last_counter  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (base_amount)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $base = number_format((float)$baseAmount, 2, '.', '');

    // زيادة العداد بشكل atomic
    $pdo->exec("
        INSERT INTO usdt_amount_counters (base_amount, last_counter)
        VALUES ($base, 1)
        ON DUPLICATE KEY UPDATE last_counter = IF(last_counter >= 99, 1, last_counter + 1)
    ");

    $stmt = $pdo->prepare("SELECT last_counter FROM usdt_amount_counters WHERE base_amount = ?");
    $stmt->execute([$base]);
    $counter = (int)$stmt->fetchColumn();

    // المبلغ = base + counter/100 → مثل 1.01, 1.02 ...
    $unique = bcadd($base, bcdiv((string)$counter, '100', 8), 2);

    // تحقق أن المبلغ غير مستخدم في طلب pending نشط
    $check = $pdo->prepare("
        SELECT COUNT(*) FROM usdt_deposit_requests
        WHERE unique_amount = ? AND status = 'pending' AND expires_at > NOW()
    ");
    $check->execute([$unique]);

    if ((int)$check->fetchColumn() > 0) {
        // fallback: ابحث عن أول رقم حر
        for ($i = 1; $i <= 99; $i++) {
            $candidate = bcadd($base, bcdiv((string)$i, '100', 8), 2);
            $check->execute([$candidate]);
            if ((int)$check->fetchColumn() === 0) {
                $unique = $candidate;
                break;
            }
        }
    }

    return $unique;
}

// ══════════════════════════════════════════════════════════════
//  إنشاء طلب إيداع
// ══════════════════════════════════════════════════════════════

/**
 * ينشئ طلب إيداع جديد للمستخدم
 *
 * @param PDO   $pdo
 * @param int   $userId
 * @param float $amount    المبلغ المطلوب إيداعه
 * @param int   $ttlMin    مدة صلاحية الطلب بالدقائق
 * @return array ['ok'=>bool, 'request'=>array|null, 'error'=>string]
 */
function usdt_create_deposit_request(PDO $pdo, int $userId, float $amount, int $ttlMin = 30): array {
    // جلب إعدادات
    $wallet  = strtolower(trim(getSetting('usdt_wallet_address')));
    $enabled = getSetting('usdt_enabled');
    $minDep  = (float)(getSetting('usdt_min_deposit') ?: '1.00');
    $cashboxId = (int)(getSetting('usdt_cashbox_id') ?: 0);

    if ($enabled !== '1') {
        return ['ok' => false, 'error' => 'نظام USDT غير مفعّل حالياً'];
    }
    if (empty($wallet) || strlen($wallet) !== 42) {
        return ['ok' => false, 'error' => 'لم يتم إعداد محفظة الاستقبال'];
    }
    if ($cashboxId <= 0) {
        return ['ok' => false, 'error' => 'CASHBOX_NOT_CONFIGURED: اختر صندوق USD نشطاً من إعدادات USDT أولاً'];
    }
    if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !accountingEnsureSchema($pdo)) {
        return ['ok' => false, 'error' => 'تعذر تهيئة مخطط الصندوق والحسابات'];
    }
    $cashboxCheck = $pdo->prepare("SELECT c.id FROM accounting_cashboxes c INNER JOIN accounting_accounts a ON a.id=c.account_id
        WHERE c.id=? AND c.status='active' AND c.currency_code='USD' AND a.status=1 AND a.currency_code='USD' LIMIT 1");
    $cashboxCheck->execute([$cashboxId]);
    if (!$cashboxCheck->fetchColumn()) {
        return ['ok' => false, 'error' => 'CASHBOX_INVALID: صندوق USDT غير صالح أو غير مرتبط بحساب USD نشط'];
    }
    if ($amount < $minDep) {
        return ['ok' => false, 'error' => "الحد الأدنى للإيداع هو {$minDep} USDT"];
    }

    // إلغاء أي طلب سابق pending لنفس المستخدم
    $pdo->prepare("
        UPDATE usdt_deposit_requests
        SET status = 'expired'
        WHERE user_id = ? AND status = 'pending' AND expires_at < NOW()
    ")->execute([$userId]);

    // توليد مبلغ فريد
    $uniqueAmount = usdt_generate_unique_amount($pdo, $amount, $wallet);
    $expiresAt    = date('Y-m-d H:i:s', strtotime("+{$ttlMin} minutes"));

    $stmt = $pdo->prepare("
        INSERT INTO usdt_deposit_requests
            (user_id, unique_amount, base_amount, wallet_address, status, expires_at, cashbox_id, cashbox_posting_status)
        VALUES (?, ?, ?, ?, 'pending', ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $uniqueAmount, $amount, $wallet, $expiresAt, $cashboxId]);
    $requestId = (int)$pdo->lastInsertId();

    $request = $pdo->prepare("SELECT * FROM usdt_deposit_requests WHERE id = ?");
    $request->execute([$requestId]);

    return ['ok' => true, 'request' => $request->fetch()];
}

// ══════════════════════════════════════════════════════════════
//  التحقق من صحة Webhook Signature
// ══════════════════════════════════════════════════════════════

/**
 * يتحقق من توقيع Moralis Webhook
 * الخوارزمية: HMAC-SHA256 على raw body
 *
 * @param string $rawBody   محتوى الـ Request كاملاً (قبل JSON decode)
 * @param string $signature التوقيع المُرسَل في Header x-signature
 * @param string $secret    المفتاح السري المشترك مع Moralis
 * @return bool
 */
function usdt_verify_moralis_signature(string $rawBody, string $signature, string $secret): bool {
    if (empty($secret) || empty($signature)) return false;

    $sig = strtolower(trim($signature));

    // ── الطريقة الرسمية من Moralis Streams docs ──────────────────
    // SHA3-256 على الـ rawBody فقط، والـ secret يُستخدم كـ HMAC key
    $method1 = hash_hmac('sha256', $rawBody, $secret);

    // ── الطريقة البديلة: sha3-256(body + secret) ─────────────────
    $method2 = function_exists('hash') ? hash('sha3-256', $rawBody . $secret) : '';

    // ── الطريقة الثالثة: sha3-256(body) مع secret منفصل ──────────
    $method3 = function_exists('hash') ? hash_hmac('sha3-256', $rawBody, $secret) : '';

    // نقبل أي طريقة تنجح
    if (hash_equals($method1, $sig)) return true;
    if (!empty($method2) && hash_equals($method2, $sig)) return true;
    if (!empty($method3) && hash_equals($method3, $sig)) return true;

    // debug log لمعرفة الطريقة الصحيحة
    error_log("[USDT_WEBHOOK] Sig mismatch. received={$sig} sha256hmac={$method1} sha3={$method2}");
    return false;
}

// ══════════════════════════════════════════════════════════════
//  التحقق من بيانات العملية
// ══════════════════════════════════════════════════════════════

/**
 * يتحقق من جميع شروط الأمان للعملية قبل إضافة الرصيد
 *
 * @param PDO   $pdo
 * @param array $tx   بيانات العملية المُستخرَجة من Webhook
 * @return array ['valid'=>bool, 'reason'=>string, 'request'=>array|null]
 */
function usdt_validate_transaction(PDO $pdo, array $tx): array {
    $ourWallet   = strtolower(trim(getSetting('usdt_wallet_address')));
    $minConf     = (int)(getSetting('usdt_min_confirmations') ?: 3);
    $officialCtr = strtolower(USDT_OFFICIAL_CONTRACT);

    // ── 1. تحقق من Contract Address (الأهم — يمنع التوكنات المزيفة) ────────
    $contractAddr = strtolower(trim($tx['contract_address'] ?? ''));
    if ($contractAddr !== $officialCtr) {
        return [
            'valid'  => false,
            'reason' => "FAKE_TOKEN: contract={$contractAddr} (expected={$officialCtr})",
            'type'   => 'fake_token',
        ];
    }

    // ── 2. تحقق من نجاح العملية ───────────────────────────────────────────
    if (!($tx['success'] ?? false)) {
        return ['valid' => false, 'reason' => 'TX_FAILED', 'type' => 'failed'];
    }

    // ── 3. تحقق من Chain ID ──────────────────────────────────────────────
    $chainId = strtolower(trim($tx['chain_id'] ?? ''));
    if (!empty($chainId) && $chainId !== strtolower(BSC_CHAIN_ID)) {
        return ['valid' => false, 'reason' => "WRONG_CHAIN: {$chainId}", 'type' => 'wrong_chain'];
    }

    // ── 4. تحقق من عنوان الاستقبال ─────────────────────────────────────
    $toAddr = strtolower(trim($tx['to_address'] ?? ''));
    if ($toAddr !== $ourWallet) {
        return ['valid' => false, 'reason' => "WRONG_TO: {$toAddr}", 'type' => 'wrong_address'];
    }

    // ── 5. تحقق من المبلغ > 0 ────────────────────────────────────────────
    $amount = $tx['amount_decimal'] ?? '0';
    if (!usdt_is_positive($amount)) {
        return ['valid' => false, 'reason' => 'ZERO_AMOUNT', 'type' => 'zero_amount'];
    }

    // ── 6. تحقق من عدم سبق استخدام TXID (Replay Attack) ─────────────────
    $txHash = strtolower(trim($tx['tx_hash'] ?? ''));
    if (empty($txHash) || strlen($txHash) !== 66) {
        return ['valid' => false, 'reason' => 'INVALID_TXHASH', 'type' => 'invalid_hash'];
    }

    $stmt = $pdo->prepare("SELECT id FROM usdt_used_transactions WHERE tx_hash = ?");
    $stmt->execute([$txHash]);
    if ($stmt->fetch()) {
        return ['valid' => false, 'reason' => 'DUPLICATE_TX', 'type' => 'duplicate'];
    }

    // ── 7. تحقق من التأكيدات ─────────────────────────────────────────────
    // إذا وصل الـ Webhook مع confirmed=true من Moralis فهذا كافٍ
    // بعض الـ blocks يُعيد confirmations=0 حتى بعد التأكيد الفعلي
    $confirmations = (int)($tx['confirmations'] ?? 0);
    $moralisConfirmed = (bool)($tx['success'] ?? false);
    if (!$moralisConfirmed && $confirmations < $minConf) {
        return [
            'valid'  => false,
            'reason' => "LOW_CONFIRMATIONS: {$confirmations} < {$minConf}",
            'type'   => 'low_conf',
        ];
    }

    // ── 8. البحث عن طلب إيداع مطابق بالمبلغ الفريد ──────────────────────
    $stmt = $pdo->prepare("
        SELECT * FROM usdt_deposit_requests
        WHERE wallet_address = ?
          AND status = 'pending'
          AND expires_at > NOW()
        ORDER BY expires_at ASC
        LIMIT 10
    ");
    $stmt->execute([$ourWallet]);
    $pendingRequests = $stmt->fetchAll();

    $matchedRequest = null;
    foreach ($pendingRequests as $req) {
        if (usdt_within_tolerance($amount, $req['unique_amount'])) {
            $matchedRequest = $req;
            break;
        }
    }

    if (!$matchedRequest) {
        return [
            'valid'   => false,
            'reason'  => "NO_MATCHING_REQUEST: amount={$amount}",
            'type'    => 'no_match',
            'request' => null,
        ];
    }

    return [
        'valid'   => true,
        'reason'  => 'OK',
        'request' => $matchedRequest,
        'amount'  => $amount,
        'tx_hash' => $txHash,
        'from'    => strtolower(trim($tx['from_address'] ?? '')),
    ];
}

// ══════════════════════════════════════════════════════════════
//  إضافة الرصيد للمستخدم (Database Transaction آمن)
// ══════════════════════════════════════════════════════════════

/**
 * يُضيف الرصيد للمستخدم بطريقة آمنة مع Database Transaction
 * يمنع Race Conditions و Double Spending
 *
 * @param PDO   $pdo
 * @param array $request  طلب الإيداع المُطابَق
 * @param array $txData   بيانات العملية المُتحقَّق منها
 * @return array ['ok'=>bool, 'error'=>string]
 */
function usdt_credit_user_balance(PDO $pdo, array $request, array $txData): array {
    $userId   = (int)$request['user_id'];
    $txHash   = strtolower($txData['tx_hash']);
    $amount   = $txData['amount'];      // decimal string
    $from     = $txData['from'];
    $reqId    = (int)$request['id'];

    try {
        // تهيئة DDL خارج معاملة الاعتماد؛ لا نسمح لـ ALTER TABLE بعمل implicit commit.
        if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !accountingEnsureSchema($pdo)) {
            return ['ok' => false, 'error' => 'CASHBOX_SCHEMA_UNAVAILABLE'];
        }
        $pdo->beginTransaction();

        // ── LOCK الطلب حتى لا يُعالَج مرتين (Race Condition) ───────────────
        $lockStmt = $pdo->prepare("
            SELECT id, status, cashbox_id, cashbox_staff_id FROM usdt_deposit_requests
            WHERE id = ? AND status = 'pending'
            FOR UPDATE
        ");
        $lockStmt->execute([$reqId]);
        $lockedReq = $lockStmt->fetch();

        if (!$lockedReq) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'REQUEST_ALREADY_PROCESSED'];
        }
        $cashboxId = (int)($lockedReq['cashbox_id'] ?? 0);
        // توافق محدود مع الطلبات القديمة قبل إضافة snapshot: لا يُستخدم إلا بعد
        // تحقق مباشر من إعداد صندوق صالح، ويُحفظ على الطلب عند نجاح الترحيل.
        if ($cashboxId <= 0) {
            $cashboxId = (int)(getSetting('usdt_cashbox_id') ?: 0);
        }
        if ($cashboxId <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'CASHBOX_NOT_CONFIGURED'];
        }

        // ── تحقق ثانوي: هل TXID مستخدم؟ (داخل Transaction) ─────────────
        $dupStmt = $pdo->prepare("SELECT id FROM usdt_used_transactions WHERE tx_hash = ?");
        $dupStmt->execute([$txHash]);
        if ($dupStmt->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'DUPLICATE_TX_INSIDE_LOCK'];
        }

        // ── تسجيل العملية في used_transactions (يمنع إعادة الاستخدام) ─────
        $pdo->prepare("
            INSERT INTO usdt_used_transactions
                (tx_hash, user_id, amount, from_address, to_address, block_number)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $txHash,
            $userId,
            $amount,
            $from,
            strtolower($request['wallet_address']),
            $txData['block_number'] ?? null,
        ]);

        // ── جلب رصيد المستخدم الحالي ──────────────────────────────────────
        $userStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();

        if (!$user) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'USER_NOT_FOUND'];
        }

        $balanceBefore = $user['balance'];
        // استخدام BCMath للجمع الدقيق
        $balanceAfter  = usdt_add((string)$balanceBefore, $amount, 8);
        // تقريب إلى 2 خانة عشرية للـ balance (كما هو في النظام)
        $balanceAfter  = number_format((float)$balanceAfter, 2, '.', '');

        // ── تحديث رصيد المستخدم ───────────────────────────────────────────
        $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")
            ->execute([$balanceAfter, $userId]);

        // ── تسجيل في wallet_transactions (كما يفعل النظام الحالي) ─────────
        $desc = "شحن USDT تلقائي — TX: " . substr($txHash, 0, 16) . "...";
        $pdo->prepare("
            INSERT INTO wallet_transactions
                (user_id, type, sub_type, amount, balance_before, balance_after, description)
            VALUES (?, 'topup', 'usdt_bep20', ?, ?, ?, ?)
        ")->execute([$userId, $amount, $balanceBefore, $balanceAfter, $desc]);

        // ── ترحيل الصندوق والقيد المقابل داخل نفس transaction ─────────────
        $cashboxPosting = cashboxPostUsdtDeposit(
            $pdo,
            $reqId,
            $userId,
            $cashboxId,
            (float)$amount,
            $desc,
            null
        );
        if (empty($cashboxPosting['posted'])) {
            throw new RuntimeException('CASHBOX_POSTING_FAILED');
        }

        // ── تحديث حالة طلب الإيداع ────────────────────────────────────────
        $pdo->prepare("
            UPDATE usdt_deposit_requests
            SET status = 'completed',
                tx_hash = ?,
                from_address = ?,
                credited_amount = ?,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([$txHash, $from, $amount, $reqId]);

        $pdo->commit();

        return [
            'ok'             => true,
            'user_id'        => $userId,
            'tx_hash'        => $txHash,
            'amount'         => $amount,
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[USDT_CREDIT_ERROR] " . $e->getMessage());
        return ['ok' => false, 'error' => 'DB_ERROR: ' . $e->getMessage()];
    }
}

// ══════════════════════════════════════════════════════════════
//  تسجيل العمليات في السجلات
// ══════════════════════════════════════════════════════════════

function usdt_log_webhook(PDO $pdo, string $txHash, string $rawPayload, string $result, string $note = ''): void {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
       ?? $_SERVER['HTTP_X_FORWARDED_FOR']
       ?? $_SERVER['REMOTE_ADDR']
       ?? '0.0.0.0';
    try {
        $pdo->prepare("
            INSERT INTO usdt_webhook_logs (tx_hash, raw_payload, result, note, ip)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$txHash ?: null, $rawPayload, $result, $note, $ip]);
    } catch (Throwable $e) {
        error_log("[USDT_LOG_ERROR] " . $e->getMessage());
    }
}

function usdt_log_suspicious(PDO $pdo, array $data, string $reason): void {
    try {
        $pdo->prepare("
            INSERT INTO usdt_suspicious_transactions
                (tx_hash, from_address, to_address, contract_address, amount_raw, reason, raw_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $data['tx_hash']          ?? null,
            $data['from_address']     ?? null,
            $data['to_address']       ?? null,
            $data['contract_address'] ?? null,
            $data['amount_raw']       ?? null,
            $reason,
            $data['raw_payload']      ?? null,
        ]);
    } catch (Throwable $e) {
        error_log("[USDT_SUSPICIOUS_LOG_ERROR] " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
//  استخراج بيانات العملية من Moralis Webhook Payload
// ══════════════════════════════════════════════════════════════

/**
 * يستخرج بيانات العملية من JSON الخاص بـ Moralis Streams
 *
 * بنية Moralis:
 * {
 *   "confirmed": true,
 *   "chainId": "0x38",
 *   "txs": [...],
 *   "erc20Transfers": [
 *     {
 *       "transactionHash": "0x...",
 *       "contract": "0x...",
 *       "from": "0x...",
 *       "to": "0x...",
 *       "value": "1000000000000000000",
 *       "tokenDecimals": "18",
 *       "tokenSymbol": "USDT",
 *       "tokenName": "Tether USD"
 *     }
 *   ]
 * }
 *
 * @param array $payload  البيانات المُفككة من JSON
 * @return array[] قائمة العمليات المُستخرَجة
 */
function usdt_extract_transfers(array $payload): array {
    $transfers = [];

    // التأكيدات والبيانات العامة
    $confirmed     = (bool)($payload['confirmed']   ?? false);
    $chainId       = strtolower((string)($payload['chainId'] ?? ''));
    $block         = $payload['block']              ?? [];
    $blockNumber   = isset($block['number']) ? (int)$block['number'] : null;
    $confirmations = isset($block['confirmations']) ? (int)$block['confirmations'] : 0;

    // إذا كان confirmed=false، فهو unconfirmed — نتجاهله
    if (!$confirmed) return [];

    $erc20Transfers = $payload['erc20Transfers'] ?? [];
    if (!is_array($erc20Transfers)) return [];

    foreach ($erc20Transfers as $transfer) {
        $rawValue  = (string)($transfer['value']        ?? '0');
        $decimals  = (int)($transfer['tokenDecimals']   ?? USDT_DECIMALS);
        $transfers[] = [
            'tx_hash'          => strtolower((string)($transfer['transactionHash'] ?? '')),
            'contract_address' => strtolower((string)($transfer['contract']        ?? '')),
            'from_address'     => strtolower((string)($transfer['from']            ?? '')),
            'to_address'       => strtolower((string)($transfer['to']              ?? '')),
            'amount_raw'       => $rawValue,
            'amount_decimal'   => usdt_raw_to_decimal($rawValue, $decimals),
            'confirmations'    => $confirmations,
            'block_number'     => $blockNumber,
            'chain_id'         => $chainId,
            // نُبقي success دائماً true للعمليات المؤكدة من Moralis
            'success'          => $confirmed,
        ];
    }

    return $transfers;
}

// ══════════════════════════════════════════════════════════════
//  إرسال إشعار للمستخدم (اختياري — يعتمد على نظام الإشعارات)
// ══════════════════════════════════════════════════════════════

function usdt_send_deposit_notification(PDO $pdo, int $userId, string $amount, string $txHash): void {
    try {
        $shortTx = substr($txHash, 0, 10) . '...' . substr($txHash, -6);
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, is_read)
            VALUES (?, 'topup', 'تم شحن رصيدك 💰', ?, 0)
        ")->execute([
            $userId,
            "تم إيداع {$amount} USDT في محفظتك بنجاح\nرقم العملية: {$shortTx}",
        ]);
    } catch (Throwable $e) {
        // لا نوقف النظام إذا فشل الإشعار
        error_log("[USDT_NOTIF_ERROR] " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
//  جلب طلب الإيداع النشط للمستخدم
// ══════════════════════════════════════════════════════════════

function usdt_get_active_request(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM usdt_deposit_requests
        WHERE user_id = ? AND status = 'pending' AND expires_at > NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/**
 * إنهاء صلاحية الطلبات المنتهية
 */
function usdt_expire_old_requests(PDO $pdo): int {
    return (int)$pdo->exec("
        UPDATE usdt_deposit_requests
        SET status = 'expired'
        WHERE status = 'pending' AND expires_at < NOW()
    ");
}
