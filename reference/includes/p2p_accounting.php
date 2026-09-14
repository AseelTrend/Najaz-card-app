<?php
require_once __DIR__ . '/accounting_helper.php';
/**
 * Shared accounting helper for P2P platform commission.
 * The commission is platform revenue and must not change either customer's wallet.
 */

if (!function_exists('p2pAccountingEnsureTable')) {
    function p2pAccountingEnsureTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_revenues (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            revenue_date DATETIME NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(18,4) NOT NULL DEFAULT 0,
            reference VARCHAR(120) DEFAULT NULL,
            source_type VARCHAR(50) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            user_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_accounting_revenue_source (source_type, source_id),
            INDEX idx_revenue_date (revenue_date),
            INDEX idx_revenue_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('recordP2PCommission')) {
    function recordP2PCommission(PDO $pdo, array $order, int $orderId, string $completionSource = 'completed'): void
    {
        $commission = round((float)($order['commission'] ?? 0), 4);
        if ($commission <= 0) return;

        // Called after table initialization; no DDL is performed inside wallet transactions.
        $stmt = $pdo->prepare("INSERT INTO accounting_revenues
            (revenue_date,category,description,amount,reference,source_type,source_id,user_id)
            VALUES (NOW(),'p2p_commission',?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE id=id");
        $stmt->execute([
            'عمولة منصة P2P #'.$orderId.' — '.$completionSource,
            $commission,
            'P2P-'.$orderId,
            'p2p_commission',
            $orderId,
            (int)($order['seller_id'] ?? 0) ?: null
        ]);

        // قيد ثنائي اختياري وآمن: لا يغيّر رصيد أي عميل ولا يوقف الطلب عند فشل طبقة التقارير.
        try {
            if (accountingEnsureSchema($pdo)) {
                $acc = $pdo->prepare("SELECT code,id FROM accounting_accounts WHERE code IN ('2100','4200')");
                $acc->execute();
                $byCode = [];
                foreach ($acc->fetchAll(PDO::FETCH_ASSOC) as $row) $byCode[$row['code']] = (int)$row['id'];
                if (!empty($byCode['2100']) && !empty($byCode['4200'])) {
                    accountingCreateJournalEntry($pdo, [
                        'debit_account_id' => $byCode['2100'],
                        'credit_account_id' => $byCode['4200'],
                        'amount' => $commission,
                        'currency_code' => 'USD',
                        'description' => 'إثبات عمولة منصة P2P #'.$orderId.' — '.$completionSource,
                        'user_id' => (int)($order['seller_id'] ?? 0) ?: null,
                        'reference_type' => 'p2p',
                        'reference_id' => 'ID'.$orderId,
                        'entry_date' => date('Y-m-d'),
                        'idempotency_key' => 'p2p_commission:'.$orderId,
                    ]);
                }
            }
        } catch (Throwable $accountingError) {
            error_log('P2P journal posting skipped: ' . $accountingError->getMessage());
        }
    }
}
