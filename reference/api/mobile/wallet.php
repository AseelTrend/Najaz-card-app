<?php
// ============================================
// api/mobile/wallet.php — بيانات صفحة المحفظة
// (نفس استعلامات مقطع المحفظة في mobile.php، بصيغة JSON)
// ============================================
require_once __DIR__ . '/_common.php';

$userId = mobileAuthorizeRequest($pdo, true);

$user = getUser($userId);
$balance = (float)($user['balance'] ?? 0);

$transactions = [];
$totalCredit  = 0.0;
$totalDebit   = 0.0;
try {
    $stmt = $pdo->prepare("SELECT id, type, amount, balance_before, balance_after, description, created_at
                            FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 60");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $positiveTypes = ['credit', 'topup', 'refund', 'prize', 'referral', 'referral_welcome'];
    foreach ($transactions as $t) {
        if (in_array($t['type'], $positiveTypes, true)) $totalCredit += (float)$t['amount'];
        else $totalDebit += (float)$t['amount'];
    }
} catch (Exception $e) {}

$kycStatus = null;
try {
    $k = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $k->execute([$userId]);
    $kycStatus = $k->fetchColumn() ?: null;
} catch (Exception $e) {}

jsonOutMobile(true, 'wallet fetched', [
    'balance'         => $balance,
    'currency_symbol' => getSetting('currency_symbol') ?: '$',
    'kyc_status'      => $kycStatus,
    'total_credit'    => $totalCredit,
    'total_debit'     => $totalDebit,
    'transactions'    => $transactions,
]);
