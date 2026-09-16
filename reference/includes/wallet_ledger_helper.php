<?php
/**
 * Helpers for balance changes that must always have a matching wallet ledger row.
 * This file is intentionally limited to customer-wallet movements.
 */

if (!function_exists('walletCreditWithLedgerInTransaction')) {
    /**
     * Credit a user's wallet while the caller owns an open transaction.
     * The caller is responsible for commit/rollback.
     */
    function walletCreditWithLedgerInTransaction(PDO $pdo, int $userId, float $amount, string $description, $referenceId = null, ?string $idempotencyDescription = null): int
    {
        if ($amount <= 0 || !$pdo->inTransaction()) {
            throw new InvalidArgumentException('Invalid wallet credit transaction');
        }
        if ($idempotencyDescription !== null) {
            $dup = $pdo->prepare("SELECT id FROM wallet_transactions
                WHERE user_id=? AND type='credit' AND reference_id=? AND description=? LIMIT 1 FOR UPDATE");
            $dup->execute([$userId, $referenceId, $idempotencyDescription]);
            $existing = $dup->fetchColumn();
            if ($existing) return 0;
        }
        $userStmt = $pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');
        $userStmt->execute([$userId]);
        $before = $userStmt->fetchColumn();
        if ($before === false) throw new RuntimeException('Wallet user not found');
        $before = (float)$before;
        $after = $before + $amount;
        $pdo->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$after, $userId]);
        $tx = $pdo->prepare("INSERT INTO wallet_transactions
            (user_id,type,amount,balance_before,balance_after,description,reference_id)
            VALUES (?,?,?,?,?,?,?)");
        $tx->execute([$userId, 'credit', $amount, $before, $after, $description, $referenceId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('walletCreditWithLedger')) {
    /**
     * Credit a user's wallet and create the matching ledger entry atomically.
     * Returns the created transaction id, or 0 when a matching idempotency key already exists.
     */
    function walletCreditWithLedger(PDO $pdo, int $userId, float $amount, string $description, $referenceId = null, ?string $idempotencyDescription = null): int
    {
        if ($amount <= 0) throw new InvalidArgumentException('Wallet credit amount must be greater than zero');
        $pdo->beginTransaction();
        try {
            $txId = walletCreditWithLedgerInTransaction($pdo, $userId, $amount, $description, $referenceId, $idempotencyDescription);
            $pdo->commit();
            return $txId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('walletDebitWithLedger')) {
    /** Debit a user's wallet and create the matching ledger entry atomically. */
    function walletDebitWithLedger(PDO $pdo, int $userId, float $amount, string $description, $referenceId = null): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Wallet debit amount must be greater than zero');
        }

        $pdo->beginTransaction();
        try {
            $userStmt = $pdo->prepare('SELECT balance FROM users WHERE id=? FOR UPDATE');
            $userStmt->execute([$userId]);
            $before = $userStmt->fetchColumn();
            if ($before === false) {
                throw new RuntimeException('Wallet user not found');
            }
            $before = (float)$before;
            if ($before < $amount) {
                throw new RuntimeException('Insufficient wallet balance');
            }
            $after = $before - $amount;

            $pdo->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$after, $userId]);
            $tx = $pdo->prepare("INSERT INTO wallet_transactions
                (user_id,type,amount,balance_before,balance_after,description,reference_id)
                VALUES (?,?,?,?,?,?,?)");
            $tx->execute([$userId, 'debit', $amount, $before, $after, $description, $referenceId]);
            $txId = (int)$pdo->lastInsertId();
            $pdo->commit();
            return $txId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
