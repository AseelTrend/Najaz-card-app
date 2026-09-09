<?php
/**
 * Per-manual-payment-method currency restrictions.
 * Empty mapping means legacy behaviour: all active exchange_rates are allowed.
 */
if (!function_exists('paymentMethodCurrencyEnsureSchema')) {
    function paymentMethodCurrencyEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_method_currencies (
            method_id INT NOT NULL,
            currency_code VARCHAR(10) NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (method_id, currency_code),
            KEY idx_pmc_currency (currency_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('paymentMethodCurrencyMap')) {
    function paymentMethodCurrencyMap(PDO $pdo, array $methodIds = []): array
    {
        paymentMethodCurrencyEnsureSchema($pdo);
        $params = [];
        $where = '';
        if ($methodIds) {
            $methodIds = array_values(array_unique(array_map('intval', $methodIds)));
            $methodIds = array_values(array_filter($methodIds, fn($v) => $v > 0));
            if ($methodIds) {
                $where = ' WHERE method_id IN (' . implode(',', array_fill(0, count($methodIds), '?')) . ')';
                $params = $methodIds;
            }
        }
        $stmt = $pdo->prepare("SELECT method_id, currency_code FROM payment_method_currencies{$where} ORDER BY currency_code");
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['method_id']][] = strtoupper(trim($row['currency_code']));
        }
        return $map;
    }
}

if (!function_exists('paymentMethodCurrencySave')) {
    function paymentMethodCurrencySave(PDO $pdo, int $methodId, array $currencyCodes, array $activeRates = []): void
    {
        if ($methodId <= 0) return;
        paymentMethodCurrencyEnsureSchema($pdo);
        $valid = [];
        foreach ($currencyCodes as $code) {
            $code = strtoupper(trim((string)$code));
            if ($code !== '' && preg_match('/^[\p{L}\p{N} _-]{1,10}$/u', $code)) {
                $valid[$code] = true;
            } elseif ($code !== '') {
                throw new InvalidArgumentException('رمز العملة غير صالح: '.$code);
            }
        }
        $activeCodes = [];
        foreach ($activeRates as $rate) {
            $code = strtoupper(trim((string)($rate['currency_code'] ?? '')));
            if ($code !== '') $activeCodes[$code] = true;
        }
        if ($activeCodes) $valid = array_intersect_key($valid, $activeCodes);

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM payment_method_currencies WHERE method_id=?');
            $del->execute([$methodId]);
            if ($valid) {
                $ins = $pdo->prepare('INSERT INTO payment_method_currencies (method_id,currency_code) VALUES (?,?)');
                foreach (array_keys($valid) as $code) $ins->execute([$methodId, $code]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('paymentMethodCurrencyAllowed')) {
    function paymentMethodCurrencyAllowed(PDO $pdo, int $methodId, string $currencyCode): bool
    {
        $currencyCode = strtoupper(trim($currencyCode));
        $map = paymentMethodCurrencyMap($pdo, [$methodId]);
        // No rows = legacy unrestricted method.
        if (empty($map[$methodId])) return true;
        return in_array($currencyCode, $map[$methodId], true);
    }
}

/**
 * ربط اختياري مستقل: لكل وسيلة دفع وصندوق واحد لكل عملة.
 * عدم وجود صفوف لا يلغي cashbox_id القديم، بل يسمح بالتوافق الرجعي.
 */
if (!function_exists('paymentMethodCashboxEnsureSchema')) {
    function paymentMethodCashboxEnsureSchema(PDO $pdo): void
    {
        // MySQL/MariaDB may implicitly commit around DDL. Never run this
        // CREATE TABLE from inside the atomic top-up approval transaction.
        // The caller must provision the schema before beginning that transaction.
        if ($pdo->inTransaction()) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_method_cashboxes (
            method_id INT NOT NULL,
            currency_code VARCHAR(10) NOT NULL,
            cashbox_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (method_id, currency_code),
            KEY idx_pmcb_cashbox (cashbox_id),
            KEY idx_pmcb_currency (currency_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('paymentMethodCashboxMap')) {
    function paymentMethodCashboxMap(PDO $pdo, array $methodIds = []): array
    {
        paymentMethodCashboxEnsureSchema($pdo);
        $params = [];
        $where = '';
        if ($methodIds) {
            $methodIds = array_values(array_unique(array_map('intval', $methodIds)));
            $methodIds = array_values(array_filter($methodIds, fn($v) => $v > 0));
            if ($methodIds) {
                $where = ' WHERE x.method_id IN (' . implode(',', array_fill(0, count($methodIds), '?')) . ')';
                $params = $methodIds;
            }
        }
        $stmt = $pdo->prepare("SELECT x.method_id,x.currency_code,x.cashbox_id,
                    c.code AS cashbox_code,c.name AS cashbox_name,c.status AS cashbox_status,
                    c.account_id,a.code AS account_code,a.name AS account_name,a.status AS account_status,
                    a.currency_code AS account_currency
                FROM payment_method_cashboxes x
                LEFT JOIN accounting_cashboxes c ON c.id=x.cashbox_id
                LEFT JOIN accounting_accounts a ON a.id=c.account_id{$where}
                ORDER BY x.method_id,x.currency_code");
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $methodId = (int)$row['method_id'];
            $currency = strtoupper(trim((string)$row['currency_code']));
            $row['method_id'] = $methodId;
            $row['currency_code'] = $currency;
            $row['cashbox_id'] = (int)$row['cashbox_id'];
            $map[$methodId][$currency] = $row;
        }
        return $map;
    }
}

if (!function_exists('paymentMethodCashboxSave')) {
    function paymentMethodCashboxSave(PDO $pdo, int $methodId, array $currencyToCashbox, array $activeRates = []): void
    {
        if ($methodId <= 0) return;
        paymentMethodCashboxEnsureSchema($pdo);
        $activeCodes = [];
        foreach ($activeRates as $rate) {
            $code = strtoupper(trim((string)($rate['currency_code'] ?? '')));
            if ($code !== '') $activeCodes[$code] = true;
        }

        $clean = [];
        foreach ($currencyToCashbox as $rawCode => $rawBoxId) {
            $code = strtoupper(trim((string)$rawCode));
            $boxId = (int)$rawBoxId;
            if ($boxId <= 0) continue;
            if (!preg_match('/^[\p{L}\p{N} _-]{1,10}$/u', $code)) {
                throw new InvalidArgumentException('رمز عملة ربط الصندوق غير صالح');
            }
            if ($activeCodes && !isset($activeCodes[$code])) {
                throw new InvalidArgumentException('لا يمكن ربط صندوق بعملة غير نشطة: '.$code);
            }
            $clean[$code] = $boxId;
        }

        $boxes = [];
        if ($clean) {
            $ids = array_values(array_unique(array_values($clean)));
            $stmt = $pdo->prepare('SELECT id,currency_code,status,account_id FROM accounting_cashboxes WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $boxes[(int)$row['id']] = $row;
            foreach ($clean as $code => $boxId) {
                $box = $boxes[$boxId] ?? null;
                if (!$box) throw new InvalidArgumentException('الصندوق المحدد للعملة '.$code.' غير موجود');
                if ((string)$box['status'] !== 'active') throw new InvalidArgumentException('الصندوق المحدد للعملة '.$code.' غير نشط');
                if (strtoupper(trim((string)$box['currency_code'])) !== $code) {
                    throw new InvalidArgumentException('عملة الصندوق لا تطابق العملة '.$code);
                }
                if ((int)($box['account_id'] ?? 0) <= 0) throw new InvalidArgumentException('الصندوق المحدد للعملة '.$code.' غير مرتبط بحساب محاسبي');
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM payment_method_cashboxes WHERE method_id=?')->execute([$methodId]);
            if ($clean) {
                $ins = $pdo->prepare('INSERT INTO payment_method_cashboxes (method_id,currency_code,cashbox_id) VALUES (?,?,?)');
                foreach ($clean as $code => $boxId) $ins->execute([$methodId, $code, $boxId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

/**
 * يحل صندوق القبض من إعداد الوسيلة وعملة الطلب. لا يثق بأي صندوق مرسل من POST.
 */
if (!function_exists('paymentMethodCashboxResolve')) {
    function paymentMethodCashboxResolve(PDO $pdo, int $methodId, string $currencyCode): array
    {
        paymentMethodCashboxEnsureSchema($pdo);
        $currencyCode = strtoupper(trim($currencyCode));
        if ($methodId <= 0 || $currencyCode === '') return ['ok' => false, 'reason' => 'cashbox_not_configured'];

        $stmt = $pdo->prepare("SELECT c.id,c.code,c.name,c.currency_code,c.status,c.account_id,
                    a.id AS account_exists,a.status AS account_status,a.currency_code AS account_currency
                FROM payment_method_cashboxes x
                LEFT JOIN accounting_cashboxes c ON c.id=x.cashbox_id
                LEFT JOIN accounting_accounts a ON a.id=c.account_id
                WHERE x.method_id=? AND UPPER(x.currency_code)=? LIMIT 1");
        $stmt->execute([$methodId, $currencyCode]);
        $box = $stmt->fetch(PDO::FETCH_ASSOC);

        // إذا وُجدت أي خريطة جديدة للوسيلة، فلا نتجاوزها إلى صندوق قديم لعملة أخرى.
        $mappingExistsStmt = $pdo->prepare('SELECT 1 FROM payment_method_cashboxes WHERE method_id=? LIMIT 1');
        $mappingExistsStmt->execute([$methodId]);
        $hasNewMapping = (bool)$mappingExistsStmt->fetchColumn();

        // توافق رجعي: لا يُستخدم العمود القديم إلا إذا لم يوجد أي ربط عملة جديد.
        if (!$box && !$hasNewMapping) {
            $legacy = $pdo->prepare('SELECT cashbox_id FROM payment_methods WHERE id=? LIMIT 1');
            $legacy->execute([$methodId]);
            $legacyId = (int)$legacy->fetchColumn();
            if ($legacyId > 0) {
                $legacyBox = $pdo->prepare("SELECT c.id,c.code,c.name,c.currency_code,c.status,c.account_id,
                            a.id AS account_exists,a.status AS account_status,a.currency_code AS account_currency
                        FROM accounting_cashboxes c
                        LEFT JOIN accounting_accounts a ON a.id=c.account_id
                        WHERE c.id=? LIMIT 1");
                $legacyBox->execute([$legacyId]);
                $box = $legacyBox->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        if (!$box) return ['ok' => false, 'reason' => 'cashbox_not_configured'];
        if ((string)$box['status'] !== 'active') return ['ok' => false, 'reason' => 'cashbox_inactive', 'cashbox' => $box];
        if (strtoupper(trim((string)$box['currency_code'])) !== $currencyCode) return ['ok' => false, 'reason' => 'cashbox_currency_mismatch', 'cashbox' => $box];
        if ((int)($box['account_id'] ?? 0) <= 0 || !(int)($box['account_exists'] ?? 0) || (int)($box['account_status'] ?? 0) !== 1) {
            return ['ok' => false, 'reason' => 'cashbox_account_invalid', 'cashbox' => $box];
        }
        $accountCurrency = strtoupper(trim((string)($box['account_currency'] ?? '')));
        if ($accountCurrency !== '' && $accountCurrency !== $currencyCode) {
            return ['ok' => false, 'reason' => 'cashbox_account_currency_mismatch', 'cashbox' => $box];
        }
        return ['ok' => true, 'reason' => 'ok', 'cashbox' => $box];
    }
}
?>
