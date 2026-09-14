<?php
/**
 * طبقة الحسابات المحاسبية في نجاز.
 * لا تنشئ صندوقاً ولا تغيّر wallet_transactions؛ مهمتها القيود الثنائية
 * والروابط المرجعية القابلة للتدقيق فقط.
 */

if (!function_exists('accountingSchemaStatements')) {
    function accountingSchemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS accounting_accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(200) NOT NULL,
                parent_id BIGINT UNSIGNED DEFAULT NULL,
                account_type ENUM('asset','liability','equity','revenue','expense','clearing','other') NOT NULL DEFAULT 'other',
                nature ENUM('debit','credit') NOT NULL DEFAULT 'debit',
                currency_code VARCHAR(10) NOT NULL DEFAULT 'USD',
                region VARCHAR(100) DEFAULT NULL,
                provider_id INT DEFAULT NULL,
                staff_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_accounts_code (code),
                KEY idx_accounting_accounts_parent (parent_id),
                KEY idx_accounting_accounts_provider (provider_id),
                KEY idx_accounting_accounts_staff (staff_id),
                KEY idx_accounting_accounts_user (user_id),
                KEY idx_accounting_accounts_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS accounting_journal (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                debit_account_id BIGINT UNSIGNED NOT NULL,
                credit_account_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'USD',
                base_amount DECIMAL(20,8) DEFAULT NULL,
                base_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
                exchange_rate DECIMAL(20,10) DEFAULT NULL,
                exchange_rate_source VARCHAR(120) DEFAULT NULL,
                description VARCHAR(1000) NOT NULL,
                staff_id INT DEFAULT NULL,
                provider_id INT DEFAULT NULL,
                service_id INT DEFAULT NULL,
                user_id INT DEFAULT NULL,
                reference_type VARCHAR(50) DEFAULT NULL,
                reference_id VARCHAR(100) DEFAULT NULL,
                entry_date DATE NOT NULL,
                status ENUM('posted','void','reversed') NOT NULL DEFAULT 'posted',
                reversal_of_id BIGINT UNSIGNED DEFAULT NULL,
                idempotency_key VARCHAR(190) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_journal_idempotency (idempotency_key),
                KEY idx_accounting_journal_date (entry_date, status),
                KEY idx_accounting_journal_debit (debit_account_id),
                KEY idx_accounting_journal_credit (credit_account_id),
                KEY idx_accounting_journal_staff (staff_id),
                KEY idx_accounting_journal_provider (provider_id),
                KEY idx_accounting_journal_service (service_id),
                KEY idx_accounting_journal_user (user_id),
                KEY idx_accounting_journal_reference (reference_type, reference_id),
                KEY idx_accounting_journal_reversal (reversal_of_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS accounting_account_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id BIGINT UNSIGNED NOT NULL,
                entity_type ENUM('staff','provider','customer','service') NOT NULL,
                entity_id INT NOT NULL,
                starts_at DATE DEFAULT NULL,
                ends_at DATE DEFAULT NULL,
                status TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_link (account_id, entity_type, entity_id),
                KEY idx_accounting_link_entity (entity_type, entity_id, status),
                KEY idx_accounting_link_dates (starts_at, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
    }
}

if (!function_exists('accountingEnsureUserAccountColumn')) {
    function accountingEnsureUserAccountColumn(PDO $pdo): bool
    {
        try {
            $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'account_id'")->fetch(PDO::FETCH_ASSOC);
            if (!$column) {
                $pdo->exec("ALTER TABLE users ADD COLUMN account_id BIGINT UNSIGNED DEFAULT NULL");
            }
            return true;
        } catch (Throwable $e) {
            error_log('Accounting user link migration error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('accountingEnsurePermissionColumns')) {
    function accountingEnsurePermissionColumns(PDO $pdo): void
    {
        // staff.php يحفظ كل المفاتيح التي تعرّفها getAllPermissions().
        // نضمن وجود الأعمدة قبل عرض النموذج أو استقبال POST حتى لا يفشل INSERT
        // عند إضافة صلاحية جديدة مستقبلاً.
        $keys = function_exists('getAllPermissions')
            ? array_keys(getAllPermissions())
            : ['accounting_view', 'accounting_edit'];

        foreach (array_unique($keys) as $key) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string)$key)) continue;
            $column = 'perm_' . $key;
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM staff_permissions LIKE ?");
                $stmt->execute([$column]);
                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->exec("ALTER TABLE staff_permissions ADD COLUMN `{$column}` TINYINT(1) NOT NULL DEFAULT 0");
                }
            } catch (Throwable $e) {
                error_log('Permission column migration error for ' . $column . ': ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('accountingEnsureSchema')) {
    function accountingEnsureSchema(PDO $pdo): bool
    {
        try {
            foreach (accountingSchemaStatements() as $sql) {
                $pdo->exec($sql);
            }
            accountingEnsureUserAccountColumn($pdo);
            accountingEnsurePermissionColumns($pdo);
            accountingSeedSystemAccounts($pdo);
            return true;
        } catch (Throwable $e) {
            error_log('Accounting schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('accountingSeedSystemAccounts')) {
    function accountingSeedSystemAccounts(PDO $pdo): void
    {
        $accounts = [
            ['1100', 'محافظ العملاء', 'asset', 'debit', 'USD'],
            ['1100-YER', 'محافظ العملاء — YER', 'asset', 'debit', 'YER'],
            ['1200', 'أرصدة المزودين', 'asset', 'debit', 'USD'],
            ['2100', 'حسابات التسوية', 'liability', 'credit', 'USD'],
            ['4100', 'إيرادات الخدمات', 'revenue', 'credit', 'USD'],
            ['4200', 'عمولات المنصة', 'revenue', 'credit', 'USD'],
            ['5100', 'تكلفة الخدمات لدى المزودين', 'expense', 'debit', 'USD'],
            ['5900', 'مصروفات تشغيلية', 'expense', 'debit', 'USD'],
        ];
        $stmt = $pdo->prepare("INSERT INTO accounting_accounts
            (code,name,account_type,nature,currency_code,is_system,status)
            VALUES (?,?,?,?,?,1,1)
            ON DUPLICATE KEY UPDATE name=VALUES(name), account_type=VALUES(account_type), nature=VALUES(nature), currency_code=VALUES(currency_code)");
        foreach ($accounts as $account) {
            $stmt->execute($account);
        }
    }
}

if (!function_exists('accountingGetAccount')) {
    function accountingGetAccount(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM accounting_accounts WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('accountingCreateJournalEntry')) {
    /**
     * ينشئ قيداً ثنائي الطرف. إذا تكرر idempotency_key يعيد القيد السابق.
     * لا يحوّل العملة تلقائياً ولا يسمح بقيد ذاتي أو مبلغ غير موجب.
     */
    function accountingCreateJournalEntry(PDO $pdo, array $data): int
    {
        if (!accountingEnsureSchema($pdo)) {
            throw new RuntimeException('تعذر تهيئة جداول الحسابات');
        }
        $debit = (int)($data['debit_account_id'] ?? 0);
        $credit = (int)($data['credit_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $description = trim((string)($data['description'] ?? ''));
        if ($debit <= 0 || $credit <= 0 || $debit === $credit) {
            throw new InvalidArgumentException('يجب تحديد حسابين مختلفين للقيد');
        }
        if ($amount <= 0 || $description === '') {
            throw new InvalidArgumentException('مبلغ وبيان القيد مطلوبان');
        }
        $currency = strtoupper(trim((string)($data['currency_code'] ?? 'USD'))) ?: 'USD';
        $entryDate = (string)($data['entry_date'] ?? date('Y-m-d'));
        $idempotency = trim((string)($data['idempotency_key'] ?? ''));

        if ($idempotency !== '') {
            $existing = $pdo->prepare('SELECT id FROM accounting_journal WHERE idempotency_key=? LIMIT 1');
            $existing->execute([$idempotency]);
            $found = $existing->fetchColumn();
            if ($found) return (int)$found;
        }

        $sql = "INSERT INTO accounting_journal
            (debit_account_id,credit_account_id,amount,currency_code,base_amount,base_currency,
             exchange_rate,exchange_rate_source,description,staff_id,provider_id,service_id,user_id,
             reference_type,reference_id,entry_date,status,reversal_of_id,idempotency_key,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'posted',?,?,?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $debit,
            $credit,
            number_format($amount, 8, '.', ''),
            $currency,
            isset($data['base_amount']) && $data['base_amount'] !== '' ? $data['base_amount'] : null,
            strtoupper(trim((string)($data['base_currency'] ?? 'USD'))) ?: 'USD',
            isset($data['exchange_rate']) && $data['exchange_rate'] !== '' ? $data['exchange_rate'] : null,
            ($data['exchange_rate_source'] ?? null) ?: null,
            $description,
            !empty($data['staff_id']) ? (int)$data['staff_id'] : null,
            !empty($data['provider_id']) ? (int)$data['provider_id'] : null,
            !empty($data['service_id']) ? (int)$data['service_id'] : null,
            !empty($data['user_id']) ? (int)$data['user_id'] : null,
            ($data['reference_type'] ?? null) ?: null,
            ($data['reference_id'] ?? null) !== '' ? (string)$data['reference_id'] : null,
            $entryDate,
            !empty($data['reversal_of_id']) ? (int)$data['reversal_of_id'] : null,
            $idempotency !== '' ? $idempotency : null,
            !empty($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('accountingReverseJournalEntry')) {
    function accountingReverseJournalEntry(PDO $pdo, int $journalId, ?int $createdBy = null, ?string $reason = null): int
    {
        $stmt = $pdo->prepare('SELECT * FROM accounting_journal WHERE id=? LIMIT 1');
        $stmt->execute([$journalId]);
        $original = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$original) throw new InvalidArgumentException('القيد المطلوب عكسه غير موجود');
        if ($original['status'] !== 'posted') throw new InvalidArgumentException('لا يمكن عكس قيد غير مرحّل');
        $key = 'reverse:' . $journalId;
        $existing = $pdo->prepare('SELECT id FROM accounting_journal WHERE idempotency_key=? LIMIT 1');
        $existing->execute([$key]);
        $found = $existing->fetchColumn();
        if ($found) return (int)$found;
        $newId = accountingCreateJournalEntry($pdo, [
            'debit_account_id' => $original['credit_account_id'],
            'credit_account_id' => $original['debit_account_id'],
            'amount' => $original['amount'],
            'currency_code' => $original['currency_code'],
            'base_amount' => $original['base_amount'],
            'base_currency' => $original['base_currency'],
            'exchange_rate' => $original['exchange_rate'],
            'exchange_rate_source' => $original['exchange_rate_source'],
            'description' => $reason ?: ('عكس القيد #' . $journalId),
            'staff_id' => $original['staff_id'],
            'provider_id' => $original['provider_id'],
            'service_id' => $original['service_id'],
            'user_id' => $original['user_id'],
            'reference_type' => 'journal_reversal',
            'reference_id' => $journalId,
            'entry_date' => date('Y-m-d'),
            'reversal_of_id' => $journalId,
            'idempotency_key' => $key,
            'created_by' => $createdBy,
        ]);
        $pdo->prepare("UPDATE accounting_journal SET status='reversed' WHERE id=? AND status='posted'")->execute([$journalId]);
        return $newId;
    }
}

if (!function_exists('accountingLinkEntity')) {
    function accountingLinkEntity(PDO $pdo, int $accountId, string $entityType, int $entityId, ?string $startsAt = null, ?string $endsAt = null, int $createdBy = 0): void
    {
        $allowed = ['staff', 'provider', 'customer', 'service'];
        if (!in_array($entityType, $allowed, true) || $accountId <= 0 || $entityId <= 0) {
            throw new InvalidArgumentException('رابط الحساب غير صالح');
        }
        accountingEnsureSchema($pdo);
        $stmt = $pdo->prepare("INSERT INTO accounting_account_links
            (account_id,entity_type,entity_id,starts_at,ends_at,status,created_by)
            VALUES (?,?,?,?,?,1,?)
            ON DUPLICATE KEY UPDATE starts_at=VALUES(starts_at), ends_at=VALUES(ends_at), status=1");
        $stmt->execute([$accountId, $entityType, $entityId, $startsAt ?: null, $endsAt ?: null, $createdBy ?: null]);
    }
}

if (!function_exists('accountingReferenceLabel')) {
    function accountingReferenceLabel(?string $type, $id): string
    {
        if (!$type && !$id) return '—';
        if (strtolower((string)$type) === 'order') return 'ID' . (string)$id;
        return trim((string)$type . ' #' . (string)$id);
    }
}


if (!function_exists('accountingEnsureProviderLinkSchema')) {
    /**
     * روابط تشغيلية/محاسبية للمزود. تمثل «المصدر الحسابي» في نجاز،
     * ولا تنشئ صندوقاً نقدياً ولا تغيّر توجيه الطلبات أو بيانات API.
     */
    function accountingEnsureProviderLinkSchema(PDO $pdo): bool
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_provider_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_id INT NOT NULL,
                network_id INT DEFAULT NULL,
                staff_id INT DEFAULT NULL,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                cashbox_id BIGINT UNSIGNED DEFAULT NULL,
                linkage_name VARCHAR(160) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                alert_balance DECIMAL(20,8) DEFAULT NULL,
                stop_balance DECIMAL(20,8) DEFAULT NULL,
                retry_limit INT NOT NULL DEFAULT 0,
                notes VARCHAR(500) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_provider_link (provider_id, network_id, staff_id, account_id, cashbox_id),
                KEY idx_accounting_provider_link_provider (provider_id, is_active),
                KEY idx_accounting_provider_link_network (network_id, is_active),
                KEY idx_accounting_provider_link_staff (staff_id, is_active),
                KEY idx_accounting_provider_link_account (account_id, is_active),
                KEY idx_accounting_provider_link_cashbox (cashbox_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $column = $pdo->query("SHOW COLUMNS FROM accounting_provider_links LIKE 'cashbox_id'")->fetch(PDO::FETCH_ASSOC);
            if (!$column) $pdo->exec("ALTER TABLE accounting_provider_links ADD COLUMN cashbox_id BIGINT UNSIGNED DEFAULT NULL AFTER account_id");
            $idxRows = $pdo->query("SHOW INDEX FROM accounting_provider_links")->fetchAll(PDO::FETCH_ASSOC);
            $hasNew = false; $hasOld = false; $hasCashboxIdx = false;
            foreach ($idxRows as $idx) {
                if (($idx['Key_name'] ?? '') === 'uq_accounting_provider_link_cashbox') $hasNew = true;
                if (($idx['Key_name'] ?? '') === 'uq_accounting_provider_link') $hasOld = true;
                if (($idx['Key_name'] ?? '') === 'idx_accounting_provider_link_cashbox') $hasCashboxIdx = true;
            }
            if ($hasOld) {
                $pdo->exec("ALTER TABLE accounting_provider_links DROP INDEX uq_accounting_provider_link");
            }
            if (!$hasNew) {
                $pdo->exec("ALTER TABLE accounting_provider_links ADD UNIQUE KEY uq_accounting_provider_link_cashbox (provider_id, network_id, staff_id, account_id, cashbox_id)");
            }
            if (!$hasCashboxIdx) $pdo->exec("ALTER TABLE accounting_provider_links ADD KEY idx_accounting_provider_link_cashbox (cashbox_id, is_active)");
            return true;
        } catch (Throwable $e) {
            error_log('Accounting provider link schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('accountingProviderLinkForOperation')) {
    /**
     * يعيد الرابط النشط الأكثر تحديداً: شبكة ثم المزود العام.
     * لا يعيد بيانات حساسة مثل api_key.
     */
    function accountingProviderLinkForOperation(PDO $pdo, int $providerId, ?int $networkId = null): ?array
    {
        if ($providerId <= 0) return null;
        // لا تُنفّذ DDL داخل معاملة الطلب؛ يجب أن تتم التهيئة قبل beginTransaction.
        if (!$pdo->inTransaction() && !accountingEnsureProviderLinkSchema($pdo)) return null;
        if (function_exists('cashboxEnsureSchema') && !$pdo->inTransaction()) cashboxEnsureSchema($pdo);
        $sql = "SELECT l.*, aa.code AS account_code, aa.name AS account_name,
                       u.username AS staff_username, u.full_name AS staff_name,
                       p.name AS provider_name, cb.id AS cashbox_id, cb.code AS cashbox_code, cb.name AS cashbox_name,
                       cb.account_id AS cashbox_account_id, cb.staff_id AS cashbox_staff_id,
                       cb.currency_code AS cashbox_currency_code, cb.status AS cashbox_status,
                       CASE WHEN l.network_id IS NOT NULL AND l.network_id=? THEN 0 ELSE 1 END AS specificity_rank
                FROM accounting_provider_links l
                LEFT JOIN accounting_accounts aa ON aa.id=l.account_id
                LEFT JOIN users u ON u.id=l.staff_id
                LEFT JOIN providers p ON p.id=l.provider_id
                LEFT JOIN accounting_cashboxes cb ON cb.id=l.cashbox_id
                WHERE l.provider_id=? AND l.is_active=1
                  AND (l.network_id=? OR l.network_id IS NULL)
                ORDER BY specificity_rank, l.id DESC LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $network = $networkId ?: 0;
        $stmt->execute([$network, $providerId, $network]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$matches) return null;
        $bestRank = (int)$matches[0]['specificity_rank'];
        $best = array_values(array_filter($matches, static fn(array $row): bool => (int)$row['specificity_rank'] === $bestRank));
        return count($best) === 1 ? $best[0] : null;
    }
}

if (!function_exists('accountingOperationReference')) {
    function accountingOperationReference(string $source, int $id): string
    {
        return 'ID' . $id;
    }
}

if (!function_exists('accountingOperationReferenceTypes')) {
    function accountingOperationReferenceTypes(string $source): array
    {
        return match ($source) {
            'orders' => ['order', 'orders'],
            'telecom' => ['telecom', 'telecom_order'],
            'p2p' => ['p2p', 'p2p_order'],
            default => ['order', 'orders', 'telecom', 'telecom_order', 'p2p', 'p2p_order'],
        };
    }
}
