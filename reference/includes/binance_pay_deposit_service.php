<?php
/**
 * Shared Binance Pay deposit domain service.
 *
 * This file contains no HTTP/session/CSRF concerns. The customer API and the
 * Telegram bot both call these functions with an already authenticated user id.
 * Binance Pay remains read-only and accepts only incoming C2C USDT payments.
 */

require_once __DIR__ . '/binance_pay.php';
require_once __DIR__ . '/wallet_ledger_helper.php';

if (!function_exists('binancePayDepositPayload')) {
    function binancePayDepositPayload(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'expected_amount' => (string)$row['expected_amount'],
            'expected_currency' => (string)$row['expected_currency'],
            'status' => (string)$row['status'],
            'submitted_transaction_id' => $row['submitted_transaction_id'] !== null ? (string)$row['submitted_transaction_id'] : null,
            'expires_at' => (string)$row['expires_at'],
            'created_at' => (string)$row['created_at'],
            'verified_at' => $row['verified_at'] !== null ? (string)$row['verified_at'] : null,
            'credited_at' => $row['credited_at'] !== null ? (string)$row['credited_at'] : null,
            'safe_error_code' => $row['safe_error_code'] !== null ? (string)$row['safe_error_code'] : null,
        ];
    }
}

if (!function_exists('binancePayDepositResult')) {
    function binancePayDepositResult(bool $ok, string $errorCode = '', int $httpStatus = 200, array $extra = []): array
    {
        $result = ['ok' => $ok];
        if ($errorCode !== '') $result['error_code'] = $errorCode;
        if ($httpStatus !== 200) $result['_http_status'] = $httpStatus;
        foreach ($extra as $key => $value) $result[$key] = $value;
        return $result;
    }
}

if (!function_exists('binancePayAmount')) {
    function binancePayAmount(string $value, int $maxScale = 4): ?string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,8})?$/', $value)) return null;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $maxScale) return null;
        $whole = ltrim($whole, '0');
        if ($whole === '') $whole = '0';
        $result = $whole . ($fraction !== '' ? '.' . $fraction : '');
        if ($result === '0') return null;
        return $result;
    }
}

if (!function_exists('binancePayDecimalEqual')) {
    function binancePayDecimalEqual(string $left, string $right): bool
    {
        $canonical = static function (string $value): ?string {
            $value = trim($value);
            if (!preg_match('/^(?:0|\\d+)(?:\\.\\d+)?$/', $value)) return null;
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $whole = ltrim($whole, '0');
            if ($whole === '') $whole = '0';
            $fraction = rtrim($fraction, '0');
            return $whole . ($fraction !== '' ? '.' . $fraction : '');
        };
        $a = $canonical($left); $b = $canonical($right);
        return $a !== null && $b !== null && $a === $b;
    }
}

if (!function_exists('binancePayReceiverMatches')) {
    function binancePayReceiverMatches(array $tx, array $settings): bool
    {
        $rawType = trim((string)($settings['receiver_identifier_type'] ?? ''));
        $type = function_exists('binancePayNormalizeReceiverType') ? binancePayNormalizeReceiverType($rawType) : $rawType;
        $expected = trim((string)($settings['receiver_identifier'] ?? ''));
        $actual = $tx['receiver_identifiers'][$type] ?? null;
        if ($type === '' || $expected === '' || $actual === null || trim((string)$actual) === '') return false;
        $actual = trim((string)$actual);
        if ($type === 'email') return strcasecmp($actual, $expected) === 0;
        return hash_equals($expected, $actual);
    }
}

if (!function_exists('binancePayEnsureIdentifierTable')) {
    function binancePayEnsureIdentifierTable(PDO $pdo): bool
    {
        static $ready = [];
        $key = spl_object_id($pdo);
        if (!empty($ready[$key])) return true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS binance_pay_deposit_identifiers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                deposit_id BIGINT UNSIGNED NOT NULL,
                identifier VARBINARY(128) NOT NULL,
                identifier_kind VARCHAR(32) NOT NULL DEFAULT 'transaction',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_binance_pay_identifier (identifier),
                KEY idx_binance_pay_identifier_deposit (deposit_id),
                CONSTRAINT fk_binance_pay_identifier_deposit FOREIGN KEY (deposit_id) REFERENCES binance_pay_deposits (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // Backfill legacy submitted IDs so previously credited deposits are protected too.
            $pdo->exec("INSERT IGNORE INTO binance_pay_deposit_identifiers (deposit_id, identifier, identifier_kind)
                SELECT id, submitted_transaction_id, 'legacy_submitted'
                FROM binance_pay_deposits
                WHERE submitted_transaction_id IS NOT NULL AND TRIM(submitted_transaction_id) <> ''");
            $ready[$key] = true;
            return true;
        } catch (Throwable $e) {
            error_log('Binance Pay identifier table unavailable: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('binancePayCandidateIdentifiers')) {
    function binancePayCandidateIdentifiers(array $transaction, string $submittedId): array
    {
        $values = [];
        $add = static function (string $value, string $kind) use (&$values): void {
            $value = trim($value);
            if ($value === '' || strlen($value) > 128) return;
            foreach ($values as $existing) if ($existing['identifier'] === $value) return;
            $values[] = ['identifier' => $value, 'kind' => $kind];
        };
        $add($submittedId, 'submitted');
        $primary = trim((string)($transaction['transaction_id'] ?? ''));
        $add($primary, 'transactionId');
        foreach ((array)($transaction['transaction_identifiers'] ?? []) as $identifier) {
            $identifier = trim((string)$identifier);
            $add($identifier, $identifier === $primary ? 'transactionId' : 'orderId');
        }
        return $values;
    }
}

if (!function_exists('binancePayExpireUnsubmittedDeposits')) {
    /**
     * Mark stale requests with no customer-supplied identifier as terminal.
     * Binance deposit requests have a hard 30-minute customer-entry window;
     * this is intentionally limited to pending rows without an identifier.
     */
    function binancePayExpireUnsubmittedDeposits(PDO $pdo): int
    {
        try {
            $stmt = $pdo->prepare("UPDATE binance_pay_deposits
                SET status='expired', safe_error_code='request_expired'
                WHERE status='pending'
                  AND (submitted_transaction_id IS NULL OR TRIM(submitted_transaction_id)='')
                  AND (expires_at <= NOW() OR created_at <= DATE_SUB(NOW(), INTERVAL 30 MINUTE))");
            $stmt->execute();
            return (int)$stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Binance Pay stale deposit expiry failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('binancePayCreateDeposit')) {
    function binancePayCreateDeposit(PDO $pdo, int $userId, string $amountInput): array
    {
        if ($userId <= 0) return binancePayDepositResult(false, 'login_required', 401);
        $settings = binancePayPublicSettings($pdo);
        if (!binancePayFeatureReady($pdo)) return binancePayDepositResult(false, 'feature_not_ready', 503);

        // The wallet/credit column is DECIMAL(10,2), so the shared flow accepts
        // no more than two fractional digits even though Binance supports more.
        $amount = binancePayAmount($amountInput, 2);
        $minimum = binancePayAmount((string)$settings['minimum_amount'], 2);
        if ($amount === null || $minimum === null || (float)$amount < (float)$minimum) {
            return binancePayDepositResult(false, 'invalid_amount', 422);
        }

        try {
            $open = $pdo->prepare("SELECT COUNT(*) FROM binance_pay_deposits WHERE user_id=? AND status IN ('pending','verifying') AND expires_at>NOW()");
            $open->execute([$userId]);
            if ((int)$open->fetchColumn() >= 3) {
                return binancePayDepositResult(false, 'too_many_pending', 429);
            }

            // The customer-entry window is intentionally capped at 30 minutes.
            $ttl = max(5, min(30, (int)$settings['request_ttl_minutes']));
            $expiresAt = date('Y-m-d H:i:s', time() + ($ttl * 60));
            $stmt = $pdo->prepare("INSERT INTO binance_pay_deposits
                (user_id, expected_amount, expected_currency, credit_amount, expires_at)
                VALUES (?,?,?,?,?)");
            $stmt->execute([$userId, $amount, 'USDT', $amount, $expiresAt]);
            $id = (int)$pdo->lastInsertId();
            $rowStmt = $pdo->prepare('SELECT * FROM binance_pay_deposits WHERE id=? AND user_id=? LIMIT 1');
            $rowStmt->execute([$id, $userId]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return binancePayDepositResult(false, 'request_create_failed', 500);

            return binancePayDepositResult(true, '', 200, [
                'request' => binancePayDepositPayload($row),
                'instructions' => [
                    'receiver_identifier_type' => (string)$settings['receiver_identifier_type'],
                    'receiver_identifier' => (string)$settings['receiver_identifier'],
                    'currency' => 'USDT',
                    'amount' => (string)$amount,
                    'ttl_minutes' => $ttl,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('Binance Pay deposit create failed: ' . $e->getMessage());
            return binancePayDepositResult(false, 'request_create_failed', 500);
        }
    }
}

if (!function_exists('binancePayVerifyDeposit')) {
    function binancePayVerifyDeposit(PDO $pdo, int $userId, int $requestId, string $submittedId): array
    {
        if ($userId <= 0) return binancePayDepositResult(false, 'login_required', 401);
        $submittedId = trim($submittedId);
        if ($requestId <= 0 || $submittedId === '' || strlen($submittedId) > 128 || preg_match('/[\x00-\x1F\x7F]/', $submittedId)) {
            return binancePayDepositResult(false, 'invalid_transaction_id', 422);
        }
        $settings = binancePayPublicSettings($pdo);
        if (!binancePayFeatureReady($pdo)) return binancePayDepositResult(false, 'feature_not_ready', 503);
        if (!binancePayEnsureIdentifierTable($pdo)) return binancePayDepositResult(false, 'request_lock_failed', 500);
        try {
            $used = $pdo->prepare('SELECT deposit_id FROM binance_pay_deposit_identifiers WHERE identifier=? LIMIT 1');
            $used->execute([$submittedId]);
            if ($used->fetchColumn()) return binancePayDepositResult(false, 'transaction_already_used', 422, ['credited' => false]);
        } catch (Throwable $e) {
            error_log('Binance Pay identifier lookup failed: ' . $e->getMessage());
            return binancePayDepositResult(false, 'request_lock_failed', 500);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM binance_pay_deposits WHERE id=? AND user_id=? FOR UPDATE');
            $stmt->execute([$requestId, $userId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) {
                $pdo->rollBack();
                return binancePayDepositResult(false, 'request_not_found', 404);
            }
            if ($request['status'] === 'credited') {
                $pdo->commit();
                return binancePayDepositResult(true, '', 200, ['credited' => true, 'already_credited' => true, 'request' => binancePayDepositPayload($request)]);
            }
            $createdAt = strtotime((string)($request['created_at'] ?? ''));
            $hardExpiry = $createdAt > 0 ? ($createdAt + 30 * 60) : 0;
            $configuredExpiry = strtotime((string)($request['expires_at'] ?? ''));
            if (($hardExpiry > 0 && $hardExpiry <= time()) || ($configuredExpiry > 0 && $configuredExpiry < time())) {
                $pdo->prepare("UPDATE binance_pay_deposits SET status='expired', safe_error_code='request_expired' WHERE id=?")->execute([$requestId]);
                $pdo->commit();
                return binancePayDepositResult(false, 'request_expired', 422, ['credited' => false]);
            }
            $retryableRejection = function_exists('binancePayRetryableRequest')
                ? binancePayRetryableRequest($request) && (int)$request['verify_attempts'] < 6
                : false;
            if ((int)$request['verify_attempts'] >= 5 && !$retryableRejection) {
                $pdo->rollBack();
                return binancePayDepositResult(false, 'attempt_limit', 429);
            }
            if ($request['status'] === 'verifying' && !empty($request['last_verify_at']) && strtotime((string)$request['last_verify_at']) > (time() - 30)) {
                $pdo->rollBack();
                return binancePayDepositResult(false, 'verification_in_progress', 409);
            }
            if ($request['submitted_transaction_id'] !== null && !hash_equals((string)$request['submitted_transaction_id'], $submittedId)) {
                $pdo->rollBack();
                return binancePayDepositResult(false, 'transaction_locked', 422);
            }
            $pdo->prepare("UPDATE binance_pay_deposits SET submitted_transaction_id=?, status='verifying', verify_attempts=verify_attempts+1, last_verify_at=NOW(), safe_error_code=NULL WHERE id=?")
                ->execute([$submittedId, $requestId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Binance Pay deposit lock failed: ' . $e->getMessage());
            return binancePayDepositResult(false, 'request_lock_failed', 500);
        }

        $verificationWindow = binancePayVerificationWindow($request, $settings);
        $api = binancePayFetchTransactions($pdo, (int)$verificationWindow['fetch_start_ms'], (int)$verificationWindow['accept_end_ms'], 100);
        if (!$api['ok']) {
            try {
                $pdo->prepare("UPDATE binance_pay_deposits SET status='error', safe_error_code=? WHERE id=? AND status='verifying'")
                    ->execute([$api['error_code'] ?? 'api_error', $requestId]);
            } catch (Throwable $e) {
                error_log('Binance Pay API error state update failed: ' . $e->getMessage());
            }
            return binancePayDepositResult(false, (string)($api['error_code'] ?? 'api_error'), 502, ['retryable' => true, 'credited' => false]);
        }

        $found = null;
        foreach ($api['transactions'] as $candidate) {
            if (function_exists('binancePayTransactionIdMatches')
                ? binancePayTransactionIdMatches($candidate, $submittedId)
                : (string)($candidate['transaction_id'] ?? '') === $submittedId) {
                $found = $candidate;
                break;
            }
        }

        $acceptStartMs = (int)$verificationWindow['accept_start_ms'];
        $acceptEndMs = (int)$verificationWindow['accept_end_ms'];
        $rejectCode = null;
        if ($found === null) {
            $rejectCode = 'transaction_not_found';
        } elseif (!$found['success']) {
            $rejectCode = 'transaction_unsuccessful';
        } elseif ($found['order_type'] !== 'C2C') {
            $rejectCode = 'wrong_order_type';
        } elseif ($found['currency'] !== 'USDT') {
            $rejectCode = 'wrong_currency';
        } elseif ($found['amount'] === '' || strpos($found['amount'], '-') === 0 || !binancePayDecimalEqual($found['amount'], (string)$request['expected_amount'])) {
            $rejectCode = 'amount_mismatch';
        } elseif ($found['transaction_time_ms'] <= 0 || $found['transaction_time_ms'] < $acceptStartMs || $found['transaction_time_ms'] > $acceptEndMs) {
            $rejectCode = 'time_outside_window';
        } elseif (!binancePayReceiverMatches($found, $settings)) {
            $rejectCode = 'receiver_mismatch';
        }

        if ($rejectCode !== null) {
            try {
                $pdo->prepare("UPDATE binance_pay_deposits SET status='rejected', safe_error_code=?, verified_amount=?, verified_currency=?, verified_order_type=?, verified_transaction_time_ms=?, verified_receiver_type=?, verified_receiver_identifier_type=?, verified_receiver_identifier=? WHERE id=? AND status='verifying'")
                    ->execute([
                        $rejectCode,
                        $found['amount'] ?? null,
                        $found['currency'] ?? null,
                        $found['order_type'] ?? null,
                        $found['transaction_time_ms'] ?? null,
                        $found['receiver_type'] ?? null,
                        $found['receiver_identifier_type'] ?? null,
                        $found['receiver_identifier'] ?? null,
                        $requestId,
                    ]);
            } catch (Throwable $e) {
                error_log('Binance Pay rejection state update failed: ' . $e->getMessage());
                return binancePayDepositResult(false, 'state_update_failed', 500, ['credited' => false]);
            }
            return binancePayDepositResult(false, $rejectCode, 422, ['credited' => false, 'retryable' => false]);
        }

        $description = 'Binance Pay deposit #' . $requestId;
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM binance_pay_deposits WHERE id=? AND user_id=? FOR UPDATE');
            $lock->execute([$requestId, $userId]);
            $lockedRequest = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$lockedRequest) throw new RuntimeException('Request disappeared');
            if ($lockedRequest['status'] === 'credited') {
                $pdo->commit();
                return binancePayDepositResult(true, '', 200, ['credited' => true, 'already_credited' => true]);
            }
            $pdo->prepare("UPDATE binance_pay_deposits SET status='verified', verified_amount=?, verified_currency=?, verified_order_type=?, verified_transaction_time_ms=?, verified_receiver_type=?, verified_receiver_identifier_type=?, verified_receiver_identifier=?, verified_at=NOW(), safe_error_code=NULL WHERE id=?")
                ->execute([
                    $found['amount'], 'USDT', $found['order_type'], $found['transaction_time_ms'],
                    $found['receiver_type'], $found['receiver_identifier_type'], $found['receiver_identifier'], $requestId,
                ]);
            $identifierInsert = $pdo->prepare('INSERT INTO binance_pay_deposit_identifiers (deposit_id,identifier,identifier_kind) VALUES (?,?,?)');
            foreach (binancePayCandidateIdentifiers($found, $submittedId) as $candidateIdentifier) {
                $identifierInsert->execute([$requestId, $candidateIdentifier['identifier'], $candidateIdentifier['kind']]);
            }
            $txId = walletCreditWithLedgerInTransaction($pdo, $userId, (float)$lockedRequest['credit_amount'], $description, $requestId, $description);
            if ($txId === 0) {
                $dup = $pdo->prepare("SELECT id FROM wallet_transactions WHERE user_id=? AND type='credit' AND reference_id=? AND description=? LIMIT 1 FOR UPDATE");
                $dup->execute([$userId, $requestId, $description]);
                $txId = (int)$dup->fetchColumn();
            }
            if ($txId <= 0) throw new RuntimeException('Ledger credit was not created');
            $pdo->prepare("UPDATE binance_pay_deposits SET status='credited', wallet_transaction_id=?, credited_at=NOW(), safe_error_code=NULL WHERE id=?")
                ->execute([$txId, $requestId]);
            $pdo->commit();
            return binancePayDepositResult(true, '', 200, ['credited' => true, 'amount' => (string)$lockedRequest['credit_amount'], 'wallet_transaction_id' => $txId]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $duplicateIdentifier = $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062;
            if ($duplicateIdentifier || preg_match('/duplicate|unique/i', $e->getMessage())) {
                try {
                    $pdo->prepare("UPDATE binance_pay_deposits SET status='rejected', safe_error_code='transaction_already_used' WHERE id=? AND status IN ('verifying','verified')")
                        ->execute([$requestId]);
                } catch (Throwable $stateError) {
                    error_log('Binance Pay duplicate state update failed: ' . $stateError->getMessage());
                }
                return binancePayDepositResult(false, 'transaction_already_used', 422, ['credited' => false]);
            }
            try {
                $pdo->prepare("UPDATE binance_pay_deposits SET status='error', safe_error_code='credit_failed' WHERE id=? AND status IN ('verifying','verified')")
                    ->execute([$requestId]);
            } catch (Throwable $stateError) {
                error_log('Binance Pay credit error state update failed: ' . $stateError->getMessage());
            }
            error_log('Binance Pay ledger credit failed: ' . $e->getMessage());
            return binancePayDepositResult(false, 'credit_failed', 500, ['credited' => false]);
        }
    }
}
?>
