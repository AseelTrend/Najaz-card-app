<?php
/**
 * نظام الصناديق التشغيلية في نجاز.
 * الصندوق كيان مستقل عن users.balance وعن سجل الطلبات، وحركته لا تغيّر أي جدول تشغيلي قائم.
 */

if (!function_exists('cashboxEnsureSchema')) {
    function cashboxEnsureSchema(PDO $pdo): bool
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_cashboxes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(50) NOT NULL,
                name VARCHAR(200) NOT NULL,
                staff_id INT DEFAULT NULL,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'USD',
                initial_balance DECIMAL(20,8) NOT NULL DEFAULT 0,
                status ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
                notes VARCHAR(1000) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_cashbox_code (code),
                KEY idx_accounting_cashbox_staff (staff_id, status),
                KEY idx_accounting_cashbox_account (account_id, status),
                KEY idx_accounting_cashbox_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_cashbox_movements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cashbox_id BIGINT UNSIGNED NOT NULL,
                movement_type ENUM('receipt','payment','transfer_in','transfer_out','opening') NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                currency_code VARCHAR(10) NOT NULL DEFAULT 'USD',
                counterpart_cashbox_id BIGINT UNSIGNED DEFAULT NULL,
                journal_id BIGINT UNSIGNED DEFAULT NULL,
                reference_type VARCHAR(60) DEFAULT NULL,
                reference_id VARCHAR(120) DEFAULT NULL,
                description VARCHAR(1000) NOT NULL,
                staff_id INT DEFAULT NULL,
                entry_date DATE NOT NULL,
                status ENUM('posted','void') NOT NULL DEFAULT 'posted',
                transfer_group VARCHAR(100) DEFAULT NULL,
                idempotency_key VARCHAR(190) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_cashbox_movement_idempotency (idempotency_key),
                KEY idx_cashbox_movement_cashbox_date (cashbox_id, entry_date, status),
                KEY idx_cashbox_movement_reference (reference_type, reference_id),
                KEY idx_cashbox_movement_transfer (transfer_group),
                KEY idx_cashbox_movement_staff (staff_id),
                KEY idx_cashbox_movement_journal (journal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            error_log('Cashbox schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cashboxEnsureProviderColumn')) {
    function cashboxEnsureProviderColumn(PDO $pdo): bool
    {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM accounting_provider_links LIKE 'cashbox_id'")->fetch(PDO::FETCH_ASSOC);
            if (!$col) {
                $pdo->exec("ALTER TABLE accounting_provider_links ADD COLUMN cashbox_id BIGINT UNSIGNED DEFAULT NULL AFTER account_id");
            }
            $indexes = $pdo->query("SHOW INDEX FROM accounting_provider_links")->fetchAll(PDO::FETCH_ASSOC);
            $hasCashboxIndex = false;
            foreach ($indexes as $index) {
                if (($index['Key_name'] ?? '') === 'idx_accounting_provider_link_cashbox') { $hasCashboxIndex = true; break; }
            }
            if (!$hasCashboxIndex) {
                $pdo->exec("ALTER TABLE accounting_provider_links ADD KEY idx_accounting_provider_link_cashbox (cashbox_id, is_active)");
            }
            return true;
        } catch (Throwable $e) {
            error_log('Cashbox provider column error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cashboxMovementSign')) {
    function cashboxMovementSign(string $type): int
    {
        return in_array($type, ['payment', 'transfer_out'], true) ? -1 : 1;
    }
}

if (!function_exists('cashboxBalance')) {
    function cashboxBalance(PDO $pdo, int $cashboxId, ?string $toDate = null): float
    {
        $params = [$cashboxId];
        $dateSql = '';
        if ($toDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $dateSql = ' AND entry_date <= ?';
            $params[] = $toDate;
        }
        $st = $pdo->prepare("SELECT c.initial_balance + COALESCE(SUM(CASE WHEN m.movement_type IN ('payment','transfer_out') THEN -m.amount ELSE m.amount END),0)
            FROM accounting_cashboxes c
            LEFT JOIN accounting_cashbox_movements m ON m.cashbox_id=c.id AND m.status='posted' $dateSql
            WHERE c.id=? GROUP BY c.id, c.initial_balance");
        // The date parameter belongs to the JOIN before the cashbox id parameter.
        if ($dateSql !== '') {
            $st = $pdo->prepare("SELECT c.initial_balance + COALESCE(SUM(CASE WHEN m.movement_type IN ('payment','transfer_out') THEN -m.amount ELSE m.amount END),0)
                FROM accounting_cashboxes c
                LEFT JOIN accounting_cashbox_movements m ON m.cashbox_id=c.id AND m.status='posted' AND m.entry_date <= ?
                WHERE c.id=? GROUP BY c.id, c.initial_balance");
            $st->execute([$toDate, $cashboxId]);
        } else {
            $st->execute([$cashboxId]);
        }
        $value = $st->fetchColumn();
        return $value === false ? 0.0 : (float)$value;
    }
}

if (!function_exists('cashboxCreateJournalRow')) {
    function cashboxCreateJournalRow(PDO $pdo, array $data): int
    {
        $debit = (int)($data['debit_account_id'] ?? 0);
        $credit = (int)($data['credit_account_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $description = trim((string)($data['description'] ?? ''));
        if ($debit <= 0 || $credit <= 0 || $debit === $credit || $amount <= 0 || $description === '') {
            throw new InvalidArgumentException('لا يمكن إنشاء قيد للصندوق دون حسابين مختلفين ومبلغ وبيان صالحين');
        }
        $key = trim((string)($data['idempotency_key'] ?? ''));
        if ($key !== '') {
            $find = $pdo->prepare('SELECT id FROM accounting_journal WHERE idempotency_key=? LIMIT 1');
            $find->execute([$key]);
            $old = $find->fetchColumn();
            if ($old) return (int)$old;
        }
        $st = $pdo->prepare("INSERT INTO accounting_journal
            (debit_account_id,credit_account_id,amount,currency_code,base_amount,base_currency,exchange_rate,exchange_rate_source,
             description,staff_id,provider_id,service_id,user_id,reference_type,reference_id,entry_date,status,reversal_of_id,idempotency_key,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'posted',?,?,?)");
        $st->execute([
            $debit, $credit, number_format($amount, 8, '.', ''), strtoupper((string)($data['currency_code'] ?? 'USD')),
            null, 'USD', null, null, $description,
            !empty($data['staff_id']) ? (int)$data['staff_id'] : null,
            !empty($data['provider_id']) ? (int)$data['provider_id'] : null,
            !empty($data['service_id']) ? (int)$data['service_id'] : null,
            !empty($data['user_id']) ? (int)$data['user_id'] : null,
            ($data['reference_type'] ?? null) ?: null, ($data['reference_id'] ?? null) !== '' ? (string)$data['reference_id'] : null,
            $data['entry_date'] ?? date('Y-m-d'), null, $key !== '' ? $key : null,
            !empty($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('cashboxCreateMovement')) {
    function cashboxCreateMovement(PDO $pdo, array $data): int
    {
        // لا تُنفّذ DDL داخل معاملة قائمة؛ بعض محركات MySQL قد تعمل لها implicit commit.
        // المستدعي الذي بدأ معاملة يجب أن يكون قد جهّز المخطط مسبقاً.
        if (!$pdo->inTransaction()) {
            if (!cashboxEnsureSchema($pdo)) throw new RuntimeException('تعذر تهيئة جداول الصناديق');
            if (!function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) throw new RuntimeException('تعذر تهيئة جداول الحسابات');
        } elseif (!function_exists('accountingEnsureSchema')) {
            throw new RuntimeException('تعذر تحميل مساعد الحسابات');
        }
        $cashboxId = (int)($data['cashbox_id'] ?? 0);
        $type = (string)($data['movement_type'] ?? '');
        $amount = (float)($data['amount'] ?? 0);
        $description = trim((string)($data['description'] ?? ''));
        $entryDate = (string)($data['entry_date'] ?? date('Y-m-d'));
        $allowed = ['receipt','payment','opening'];
        if ($cashboxId <= 0 || !in_array($type, $allowed, true) || $amount <= 0 || $description === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            throw new InvalidArgumentException('نوع الصندوق والمبلغ والبيان والتاريخ مطلوبة');
        }
        $key = trim((string)($data['idempotency_key'] ?? ''));
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id FROM accounting_cashbox_movements WHERE idempotency_key=? LIMIT 1');
            if ($key !== '') { $find->execute([$key]); $old = $find->fetchColumn(); if ($old) { if ($startedHere) $pdo->commit(); return (int)$old; } }
            $boxSt = $pdo->prepare('SELECT * FROM accounting_cashboxes WHERE id=? FOR UPDATE');
            $boxSt->execute([$cashboxId]);
            $box = $boxSt->fetch(PDO::FETCH_ASSOC);
            if (!$box) throw new InvalidArgumentException('الصندوق غير موجود');
            if ($box['status'] !== 'active') throw new InvalidArgumentException('لا يمكن إجراء حركة على صندوق غير نشط');
            if ($type === 'opening') {
                if ((float)$box['initial_balance'] > 0) throw new InvalidArgumentException('يوجد رصيد افتتاحي أساسي للصندوق؛ لا تسجل رصيداً افتتاحياً إضافياً');
                $has = $pdo->prepare("SELECT COUNT(*) FROM accounting_cashbox_movements WHERE cashbox_id=? AND movement_type='opening' AND status='posted'");
                $has->execute([$cashboxId]);
                if ((int)$has->fetchColumn() > 0) throw new InvalidArgumentException('تم تسجيل رصيد افتتاحي لهذا الصندوق مسبقاً');
            } elseif ($type === 'payment' && cashboxBalance($pdo, $cashboxId) < $amount) {
                throw new InvalidArgumentException('الرصيد الفعلي للصندوق لا يكفي للصرف');
            }
            $journalId = null;
            if ($type !== 'opening') {
                $counterpart = (int)($data['counterpart_account_id'] ?? 0);
                $cashAccount = (int)($box['account_id'] ?? 0);
                if ($counterpart <= 0 || $cashAccount <= 0 || $cashAccount === $counterpart) {
                    throw new InvalidArgumentException('اختر حساباً مقابلاً وحساباً محاسبياً صالحاً للصندوق');
                }
                $accountCheck = $pdo->prepare('SELECT id,currency_code,status FROM accounting_accounts WHERE id=? LIMIT 1');
                $accountCheck->execute([$counterpart]);
                $counterpartRow = $accountCheck->fetch(PDO::FETCH_ASSOC);
                if (!$counterpartRow || (int)$counterpartRow['status'] !== 1) {
                    throw new InvalidArgumentException('الحساب المقابل غير موجود أو غير نشط');
                }
                if (strtoupper((string)$counterpartRow['currency_code']) !== strtoupper((string)$box['currency_code'])) {
                    throw new InvalidArgumentException('عملة الحساب المقابل لا تطابق عملة الصندوق؛ لا يوجد سعر صرف ضمني');
                }
                $cashAccountCheck = $pdo->prepare('SELECT id,currency_code,status FROM accounting_accounts WHERE id=? LIMIT 1');
                $cashAccountCheck->execute([$cashAccount]);
                $cashAccountRow = $cashAccountCheck->fetch(PDO::FETCH_ASSOC);
                if (!$cashAccountRow || (int)$cashAccountRow['status'] !== 1 || strtoupper((string)$cashAccountRow['currency_code']) !== strtoupper((string)$box['currency_code'])) {
                    throw new InvalidArgumentException('الحساب المحاسبي للصندوق غير موجود أو عملته غير مطابقة');
                }
                $journalId = cashboxCreateJournalRow($pdo, [
                    'debit_account_id' => $type === 'receipt' ? $cashAccount : $counterpart,
                    'credit_account_id' => $type === 'receipt' ? $counterpart : $cashAccount,
                    'amount' => $amount,
                    'currency_code' => $box['currency_code'],
                    'description' => $description,
                    'staff_id' => $data['staff_id'] ?? $box['staff_id'],
                    'provider_id' => $data['provider_id'] ?? null,
                    'reference_type' => $data['reference_type'] ?? 'cashbox_movement',
                    'reference_id' => $data['reference_id'] ?? null,
                    'user_id' => $data['user_id'] ?? null,
                    'entry_date' => $entryDate,
                    'idempotency_key' => $key !== '' ? 'cashbox-journal:' . $key : null,
                    'created_by' => $data['created_by'] ?? null,
                ]);
            }
            $st = $pdo->prepare("INSERT INTO accounting_cashbox_movements
                (cashbox_id,movement_type,amount,currency_code,journal_id,reference_type,reference_id,description,staff_id,entry_date,status,transfer_group,idempotency_key,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,'posted',?,?,?)");
            $st->execute([$cashboxId,$type,number_format($amount,8,'.',''),$box['currency_code'],$journalId,
                ($data['reference_type'] ?? null) ?: null, ($data['reference_id'] ?? null) !== '' ? (string)$data['reference_id'] : null,
                $description, !empty($data['staff_id']) ? (int)$data['staff_id'] : ($box['staff_id'] ?: null), $entryDate,
                ($data['transfer_group'] ?? null) ?: null, $key !== '' ? $key : null, !empty($data['created_by']) ? (int)$data['created_by'] : null]);
            $id = (int)$pdo->lastInsertId();
            if ($startedHere) $pdo->commit();
            return $id;
        } catch (Throwable $e) { if ($startedHere && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}

if (!function_exists('cashboxEnsureTelecomLinkSchema')) {
    function cashboxEnsureTelecomLinkSchema(PDO $pdo): bool
    {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_telecom_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_key VARCHAR(80) NOT NULL,
                network_id INT DEFAULT NULL,
                staff_id INT DEFAULT NULL,
                account_id BIGINT UNSIGNED DEFAULT NULL,
                cashbox_id BIGINT UNSIGNED DEFAULT NULL,
                linkage_name VARCHAR(160) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounting_telecom_link (provider_key,network_id,staff_id,account_id,cashbox_id),
                KEY idx_accounting_telecom_link_provider (provider_key,is_active),
                KEY idx_accounting_telecom_link_network (network_id,is_active),
                KEY idx_accounting_telecom_link_cashbox (cashbox_id,is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $e) {
            error_log('Telecom cashbox link schema error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cashboxTelecomLinkForOperation')) {
    /** لا يختار مساراً عشوائياً: يجب وجود ربطية شبكة واحدة أو ربطية عامة واحدة. */
    function cashboxTelecomLinkForOperation(PDO $pdo, string $providerKey, ?int $networkId = null): ?array
    {
        if (trim($providerKey) === '' || !cashboxEnsureTelecomLinkSchema($pdo) || !cashboxEnsureSchema($pdo)) return null;
        $sql = "SELECT l.*, cb.code AS cashbox_code, cb.name AS cashbox_name, cb.currency_code AS cashbox_currency_code,
                       cb.staff_id AS cashbox_staff_id, cb.account_id AS cashbox_account_id,
                       a.code AS account_code, a.name AS account_name,
                       u.username AS staff_username, u.full_name AS staff_name,
                       CASE WHEN l.network_id IS NOT NULL AND l.network_id=? THEN 0 ELSE 1 END AS specificity_rank
                FROM accounting_telecom_links l
                LEFT JOIN accounting_cashboxes cb ON cb.id=l.cashbox_id
                LEFT JOIN accounting_accounts a ON a.id=l.account_id
                LEFT JOIN users u ON u.id=l.staff_id
                WHERE l.provider_key=? AND l.is_active=1 AND (l.network_id=? OR l.network_id IS NULL)
                ORDER BY specificity_rank,l.id DESC LIMIT 3";
        $st = $pdo->prepare($sql);
        $network = $networkId ?: 0;
        $st->execute([$network, trim($providerKey), $network]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $rank = (int)$rows[0]['specificity_rank'];
        $best = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['specificity_rank'] === $rank));
        return count($best) === 1 ? $best[0] : null;
    }
}

if (!function_exists('cashboxPostTelecomOperation')) {
    function cashboxPostTelecomOperation(PDO $pdo, int $operationId, string $providerKey, ?int $networkId, float $providerAmount, string $description, ?int $userId = null, ?int $createdBy = null): array
    {
        if ($operationId <= 0 || trim($providerKey) === '' || $providerAmount <= 0) return ['posted' => false, 'reason' => 'invalid_or_missing_amount'];
        if (!cashboxEnsureSchema($pdo) || !cashboxEnsureTelecomLinkSchema($pdo) || !cashboxEnsureOperationalColumns($pdo)) return ['posted' => false, 'reason' => 'schema_unavailable'];
        $link = cashboxTelecomLinkForOperation($pdo, $providerKey, $networkId);
        if (!$link || (int)($link['cashbox_id'] ?? 0) <= 0 || (int)($link['account_id'] ?? 0) <= 0) return ['posted' => false, 'reason' => 'ambiguous_or_unlinked_telecom'];
        $currency = strtoupper((string)($link['cashbox_currency_code'] ?? ''));
        if ($currency !== 'YER') return ['posted' => false, 'reason' => 'telecom_cashbox_must_be_yer'];
        $key = 'telecom-operation:' . $operationId;
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) $pdo->beginTransaction();
        try {
            $old = $pdo->prepare('SELECT id,journal_id,cashbox_id FROM accounting_cashbox_movements WHERE idempotency_key=? LIMIT 1');
            $old->execute([$key]);
            $existing = $old->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($startedHere) $pdo->commit();
                return ['posted' => true, 'movement_id' => (int)$existing['id'], 'journal_id' => (int)($existing['journal_id'] ?? 0), 'cashbox_id' => (int)$existing['cashbox_id'], 'link' => $link, 'already_posted' => true];
            }
            $movementId = cashboxCreateMovement($pdo, [
                'cashbox_id' => (int)$link['cashbox_id'],
                'movement_type' => 'payment',
                'amount' => $providerAmount,
                'counterpart_account_id' => (int)$link['account_id'],
                'description' => $description,
                'staff_id' => !empty($link['staff_id']) ? (int)$link['staff_id'] : (!empty($link['cashbox_staff_id']) ? (int)$link['cashbox_staff_id'] : null),
                'provider_id' => null,
                'reference_type' => 'telecom_order',
                'reference_id' => 'ID' . $operationId,
                'entry_date' => date('Y-m-d'),
                'idempotency_key' => $key,
                'created_by' => $createdBy,
            ]);
            $m = $pdo->prepare('SELECT journal_id FROM accounting_cashbox_movements WHERE id=? LIMIT 1');
            $m->execute([$movementId]);
            $journalId = (int)($m->fetchColumn() ?: 0);
            $staffId = !empty($link['staff_id']) ? (int)$link['staff_id'] : (!empty($link['cashbox_staff_id']) ? (int)$link['cashbox_staff_id'] : null);
            $upd = $pdo->prepare('UPDATE telecom_orders SET cashbox_id=?,cashbox_staff_id=?,provider_link_id=?,cashbox_link_type=\'telecom\',cashbox_journal_id=?,cashbox_movement_id=?,cashbox_posted_at=NOW() WHERE id=? AND cashbox_posted_at IS NULL');
            $upd->execute([(int)$link['cashbox_id'], $staffId, (int)$link['id'], $journalId ?: null, $movementId, $operationId]);
            if ($startedHere) $pdo->commit();
            return ['posted' => true, 'movement_id' => $movementId, 'journal_id' => $journalId, 'cashbox_id' => (int)$link['cashbox_id'], 'link' => $link, 'already_posted' => false];
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Telecom cashbox posting failed for #' . $operationId . ': ' . $e->getMessage());
            return ['posted' => false, 'reason' => 'posting_failed'];
        }
    }
}

if (!function_exists('cashboxEnsureOperationalColumns')) {
    /**
     * أعمدة الإسناد الاختيارية تحفظ الصندوق والربطية على العملية نفسها،
     * من دون تغيير الجداول أو مسارات التنفيذ الأصلية.
     */
    function cashboxEnsureOperationalColumns(PDO $pdo): bool
    {
        $definitions = [
            'accounting_cashboxes' => [
                'cashbox_role' => "VARCHAR(20) DEFAULT NULL",
            ],
            'orders' => [
                'cashbox_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_staff_id' => 'INT DEFAULT NULL',
                'provider_link_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_journal_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_movement_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_posted_at' => 'DATETIME DEFAULT NULL',
                'cashbox_link_type' => "VARCHAR(20) DEFAULT NULL",
                'cashbox_posting_status' => "VARCHAR(30) DEFAULT NULL",
                'cashbox_posting_reason' => "VARCHAR(120) DEFAULT NULL",
            ],
            'telecom_orders' => [
                'cashbox_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_staff_id' => 'INT DEFAULT NULL',
                'provider_link_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_journal_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_movement_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_posted_at' => 'DATETIME DEFAULT NULL',
                'cashbox_link_type' => "VARCHAR(20) DEFAULT NULL",
                'cashbox_posting_status' => "VARCHAR(30) DEFAULT NULL",
                'cashbox_posting_reason' => "VARCHAR(120) DEFAULT NULL",
            ],
            'topup_requests' => [
                'cashbox_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_staff_id' => 'INT DEFAULT NULL',
                'cashbox_movement_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_posted_at' => 'DATETIME DEFAULT NULL',
                'cashbox_posting_status' => "VARCHAR(30) DEFAULT NULL",
                'cashbox_posting_reason' => "VARCHAR(120) DEFAULT NULL",
            ],
            'usdt_deposit_requests' => [
                'cashbox_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_staff_id' => 'INT DEFAULT NULL',
                'cashbox_journal_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_movement_id' => 'BIGINT UNSIGNED DEFAULT NULL',
                'cashbox_posted_at' => 'DATETIME DEFAULT NULL',
                'cashbox_posting_status' => "VARCHAR(30) DEFAULT NULL",
                'cashbox_posting_reason' => "VARCHAR(120) DEFAULT NULL",
            ],
            'payment_methods' => [
                'cashbox_id' => 'BIGINT UNSIGNED DEFAULT NULL',
            ],
        ];
        try {
            foreach ($definitions as $table => $columns) {
                $tableExists = $pdo->prepare('SHOW TABLES LIKE ?');
                $tableExists->execute([$table]);
                if (!$tableExists->fetchColumn()) continue;
                foreach ($columns as $column => $definition) {
                    $check = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
                    if (!$check->fetch(PDO::FETCH_ASSOC)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                    }
                }
                if ($table === 'orders') {
                    foreach ([
                        'idx_orders_cashbox_posted' => '(`cashbox_id`,`cashbox_posted_at`)',
                        'idx_orders_provider_link' => '(`provider_link_id`)',
                    ] as $index => $columnsSql) {
                        $has = $pdo->query("SHOW INDEX FROM `orders` WHERE Key_name=" . $pdo->quote($index))->fetch(PDO::FETCH_ASSOC);
                        if (!$has) $pdo->exec("ALTER TABLE `orders` ADD KEY `{$index}` {$columnsSql}");
                    }
                }
                if ($table === 'telecom_orders') {
                    foreach ([
                        'idx_telecom_cashbox_posted' => '(`cashbox_id`,`cashbox_posted_at`)',
                        'idx_telecom_provider_link' => '(`provider_link_id`)',
                    ] as $index => $columnsSql) {
                        $has = $pdo->query("SHOW INDEX FROM `telecom_orders` WHERE Key_name=" . $pdo->quote($index))->fetch(PDO::FETCH_ASSOC);
                        if (!$has) $pdo->exec("ALTER TABLE `telecom_orders` ADD KEY `{$index}` {$columnsSql}");
                    }
                }
                if ($table === 'usdt_deposit_requests') {
                    foreach ([
                        'idx_usdt_cashbox_posted' => '(`cashbox_id`,`cashbox_posted_at`)',
                        'idx_usdt_cashbox_id' => '(`cashbox_id`)',
                    ] as $index => $columnsSql) {
                        $has = $pdo->query("SHOW INDEX FROM `usdt_deposit_requests` WHERE Key_name=" . $pdo->quote($index))->fetch(PDO::FETCH_ASSOC);
                        if (!$has) $pdo->exec("ALTER TABLE `usdt_deposit_requests` ADD KEY `{$index}` {$columnsSql}");
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log('Cashbox operational columns error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cashboxEnsureCurrencyDefaults')) {
    /**
     * يجهز صندوقاً عادياً وصندوقاً تشغيلياً لكل عملة نشطة بصورة idempotent.
     * لا ينشئ الصناديق داخل معاملة اعتماد إيداع، ولا يحذف صندوقاً له تاريخ أو مرجع تشغيلي.
     */
    function cashboxEnsureCurrencyDefaults(PDO $pdo, array $activeRates = [], ?int $createdBy = null): array
    {
        if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) {
            throw new RuntimeException('تعذر تهيئة مخطط الصناديق والحسابات');
        }
        if (!$activeRates) {
            $activeRates = $pdo->query("SELECT currency_code,currency_name FROM exchange_rates WHERE status=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
        }
        $rates = [];
        foreach ($activeRates as $rate) {
            $code = strtoupper(trim((string)($rate['currency_code'] ?? '')));
            if ($code === '' || !preg_match('/^[\\p{L}\\p{N} _-]{1,10}$/u', $code)) continue;
            $rates[$code] = trim((string)($rate['currency_name'] ?? $code)) ?: $code;
        }
        if (!$rates) return ['created' => 0, 'retired' => 0, 'archived' => 0, 'defaults' => []];

        // نتحقق من الجداول قبل بدء المعاملة؛ إنشاء جدول هنا قد يسبب implicit commit في MySQL.
        $hasTable = static function (PDO $pdo, string $table): bool {
            $st = $pdo->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        };
        if ($hasTable($pdo, 'payment_methods') && !$hasTable($pdo, 'payment_method_cashboxes')) {
            if ($pdo->inTransaction()) {
                throw new RuntimeException('payment_method_cashboxes_schema_required_before_transaction');
            }
            $pdo->exec("CREATE TABLE payment_method_cashboxes (
                method_id INT NOT NULL,
                currency_code VARCHAR(10) NOT NULL,
                cashbox_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (method_id, currency_code),
                KEY idx_pmcb_cashbox (cashbox_id),
                KEY idx_pmcb_currency (currency_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        $startedHere = !$pdo->inTransaction();
        if ($startedHere) $pdo->beginTransaction();
        try {
            $defaults = [];
            $accountFind = $pdo->prepare('SELECT id,currency_code,status FROM accounting_accounts WHERE code=? LIMIT 1');
            $accountInsert = $pdo->prepare("INSERT INTO accounting_accounts (code,name,account_type,nature,currency_code,is_system,status,created_by) VALUES (?,?,?,?,?,1,1,?)");
            $accountActivate = $pdo->prepare("UPDATE accounting_accounts SET name=?,account_type='asset',nature='debit',currency_code=?,is_system=1,status=1 WHERE id=?");
            $cashboxFind = $pdo->prepare('SELECT id,currency_code,cashbox_role,status,account_id FROM accounting_cashboxes WHERE code=? LIMIT 1');
            $cashboxInsert = $pdo->prepare("INSERT INTO accounting_cashboxes (code,name,account_id,currency_code,status,cashbox_role,notes,created_by) VALUES (?,?,?,?,'active',?,?,?)");
            $cashboxActivate = $pdo->prepare("UPDATE accounting_cashboxes SET name=?,account_id=?,currency_code=?,status='active',cashbox_role=?,notes=COALESCE(notes,?) WHERE id=?");
            $created = 0;

            foreach ($rates as $currency => $currencyName) {
                $safeSuffix = substr(hash('sha256', $currency), 0, 12);
                foreach ([
                    'normal' => ['N', 'صندوق عادي — ', 'حساب نقدي عادي — ', 'قبض إيداعات العملاء'],
                    'operational' => ['O', 'صندوق تشغيلي — ', 'حساب نقدي تشغيلي — ', 'عمليات التشغيل والمزودين'],
                ] as $role => [$roleCode, $boxPrefix, $accountPrefix, $note]) {
                    // حساب مستقل لكل دور حتى لا تختلط حركة القبض بحركة التشغيل.
                    $accountCode = '1000-CASH-' . $safeSuffix . '-' . $roleCode;
                    $accountName = $accountPrefix . $currencyName . ' (' . $currency . ')';
                    $accountFind->execute([$accountCode]);
                    $account = $accountFind->fetch(PDO::FETCH_ASSOC);
                    if ($account && strtoupper(trim((string)$account['currency_code'])) !== $currency) {
                        throw new RuntimeException('cash_account_currency_conflict:' . $currency);
                    }
                    if (!$account) {
                        $accountInsert->execute([$accountCode,$accountName,'asset','debit',$currency,$createdBy]);
                        $accountId = (int)$pdo->lastInsertId();
                    } else {
                        $accountId = (int)$account['id'];
                        $accountActivate->execute([$accountName,$currency,$accountId]);
                    }

                    $cashboxCode = 'AUTO-' . $safeSuffix . '-' . $roleCode;
                    $cashboxName = $boxPrefix . $currencyName . ' (' . $currency . ')';
                    $cashboxFind->execute([$cashboxCode]);
                    $box = $cashboxFind->fetch(PDO::FETCH_ASSOC);
                    if ($box && strtoupper(trim((string)$box['currency_code'])) !== $currency) {
                        throw new RuntimeException('cashbox_currency_conflict:' . $currency);
                    }
                    if ($box && trim((string)($box['cashbox_role'] ?? '')) !== '' && (string)$box['cashbox_role'] !== $role) {
                        throw new RuntimeException('cashbox_role_conflict:' . $currency);
                    }
                    if (!$box) {
                        $cashboxInsert->execute([$cashboxCode,$cashboxName,$accountId,$currency,$role,$note,$createdBy]);
                        $boxId = (int)$pdo->lastInsertId();
                        $created++;
                    } else {
                        $boxId = (int)$box['id'];
                        $cashboxActivate->execute([$cashboxName,$accountId,$currency,$role,$note,$boxId]);
                    }
                    $defaults[$currency][$role] = $boxId;
                }
            }

            // استبدال مراجع الصناديق القديمة في إعدادات الوسائل بروابط الصندوق العادي المطابق للعملة.
            // تضمن الخريطة الجديدة وجود صندوق عادي لكل عملة مسموحة في الوسائل اليدوية.
            if ($hasTable($pdo, 'payment_methods')) {
                $manualMethods = $pdo->query("SELECT id FROM payment_methods WHERE status=1 AND (payment_mode='manual' OR payment_mode IS NULL)")->fetchAll(PDO::FETCH_COLUMN);
                $allowedByMethod = [];
                if ($hasTable($pdo, 'payment_method_currencies')) {
                    $allowedRows = $pdo->query('SELECT method_id,currency_code FROM payment_method_currencies')->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($allowedRows as $allowedRow) {
                        $allowedByMethod[(int)$allowedRow['method_id']][] = strtoupper(trim((string)$allowedRow['currency_code']));
                    }
                }
                $existingMap = [];
                $existingRows = $pdo->query('SELECT method_id,currency_code FROM payment_method_cashboxes')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($existingRows as $existingRow) {
                    $existingMap[(int)$existingRow['method_id']][strtoupper(trim((string)$existingRow['currency_code']))] = true;
                }
                $mapInsert = $pdo->prepare('INSERT INTO payment_method_cashboxes (method_id,currency_code,cashbox_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cashbox_id=VALUES(cashbox_id)');
                foreach ($manualMethods as $methodId) {
                    $methodId = (int)$methodId;
                    $codes = !empty($allowedByMethod[$methodId]) ? array_unique($allowedByMethod[$methodId]) : array_keys($rates);
                    foreach ($codes as $code) {
                        $code = strtoupper(trim((string)$code));
                        if (isset($defaults[$code]['normal']) && empty($existingMap[$methodId][$code])) {
                            $mapInsert->execute([$methodId,$code,$defaults[$code]['normal']]);
                        }
                    }
                }
            }

            if ($hasTable($pdo, 'payment_method_cashboxes')) {
                $rows = $pdo->query("SELECT x.method_id,x.currency_code,x.cashbox_id FROM payment_method_cashboxes x LEFT JOIN accounting_cashboxes c ON c.id=x.cashbox_id WHERE COALESCE(c.cashbox_role,'')='' ")->fetchAll(PDO::FETCH_ASSOC);
                $replace = $pdo->prepare('UPDATE payment_method_cashboxes SET cashbox_id=? WHERE method_id=? AND currency_code=?');
                foreach ($rows as $row) {
                    $code = strtoupper(trim((string)$row['currency_code']));
                    if (isset($defaults[$code]['normal'])) $replace->execute([$defaults[$code]['normal'],(int)$row['method_id'],$code]);
                }
            }
            if ($hasTable($pdo, 'payment_methods')) {
                $rows = $pdo->query("SELECT m.id,m.cashbox_id,c.currency_code FROM payment_methods m JOIN accounting_cashboxes c ON c.id=m.cashbox_id WHERE m.cashbox_id IS NOT NULL AND COALESCE(c.cashbox_role,'')='' ")->fetchAll(PDO::FETCH_ASSOC);
                $replace = $pdo->prepare('UPDATE payment_methods SET cashbox_id=? WHERE id=?');
                foreach ($rows as $row) {
                    $code = strtoupper(trim((string)$row['currency_code']));
                    if (isset($defaults[$code]['normal'])) $replace->execute([$defaults[$code]['normal'],(int)$row['id']]);
                }
            }
            if ($hasTable($pdo, 'accounting_provider_links')) {
                $rows = $pdo->query("SELECT l.id,c.currency_code FROM accounting_provider_links l JOIN accounting_cashboxes c ON c.id=l.cashbox_id WHERE l.cashbox_id IS NOT NULL AND COALESCE(c.cashbox_role,'')='' ")->fetchAll(PDO::FETCH_ASSOC);
                $replace = $pdo->prepare('UPDATE accounting_provider_links SET cashbox_id=? WHERE id=?');
                foreach ($rows as $row) {
                    $code = strtoupper(trim((string)$row['currency_code']));
                    if (isset($defaults[$code]['operational'])) $replace->execute([$defaults[$code]['operational'],(int)$row['id']]);
                }
            }
            if ($hasTable($pdo, 'accounting_telecom_links')) {
                $rows = $pdo->query("SELECT l.id,c.currency_code FROM accounting_telecom_links l JOIN accounting_cashboxes c ON c.id=l.cashbox_id WHERE l.cashbox_id IS NOT NULL AND COALESCE(c.cashbox_role,'')='' ")->fetchAll(PDO::FETCH_ASSOC);
                $replace = $pdo->prepare('UPDATE accounting_telecom_links SET cashbox_id=? WHERE id=?');
                foreach ($rows as $row) {
                    $code = strtoupper(trim((string)$row['currency_code']));
                    if (isset($defaults[$code]['operational'])) $replace->execute([$defaults[$code]['operational'],(int)$row['id']]);
                }
            }

            $refSpecs = [
                ['accounting_cashbox_movements','cashbox_id'],
                ['accounting_provider_links','cashbox_id'],
                ['accounting_telecom_links','cashbox_id'],
                ['payment_method_cashboxes','cashbox_id'],
                ['payment_methods','cashbox_id'],
                ['topup_requests','cashbox_id'],
                ['usdt_deposit_requests','cashbox_id'],
                ['orders','cashbox_id'],
                ['telecom_orders','cashbox_id'],
            ];
            $legacy = $pdo->query("SELECT id,account_id FROM accounting_cashboxes WHERE COALESCE(cashbox_role,'')='' FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $deleted = 0; $archived = 0;
            foreach ($legacy as $legacyRow) {
                $legacyId = (int)$legacyRow['id'];
                $legacyAccountId = (int)($legacyRow['account_id'] ?? 0);
                $references = 0;
                foreach ($refSpecs as [$table, $column]) {
                    if (!$hasTable($pdo, $table)) continue;
                    $colCheck = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
                    $colCheck->execute([$column]);
                    if (!$colCheck->fetch(PDO::FETCH_ASSOC)) continue;
                    $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
                    $count->execute([(int)$legacyId]);
                    $references += (int)$count->fetchColumn();
                }
                // قد لا توجد حركة مرتبطة بالصندوق نفسه، لكن حسابه قد يكون مستخدماً في قيد أو ربط محاسبي.
                if ($legacyAccountId > 0 && $hasTable($pdo, 'accounting_journal')) {
                    $journalRefs = $pdo->prepare('SELECT COUNT(*) FROM accounting_journal WHERE debit_account_id=? OR credit_account_id=?');
                    $journalRefs->execute([$legacyAccountId, $legacyAccountId]);
                    $references += (int)$journalRefs->fetchColumn();
                }
                if ($legacyAccountId > 0 && $hasTable($pdo, 'accounting_account_links')) {
                    $linkRefs = $pdo->prepare('SELECT COUNT(*) FROM accounting_account_links WHERE account_id=?');
                    $linkRefs->execute([$legacyAccountId]);
                    $references += (int)$linkRefs->fetchColumn();
                }
                if ($references === 0) {
                    $pdo->prepare('DELETE FROM accounting_cashboxes WHERE id=? AND COALESCE(cashbox_role,\'\')=\'\'')->execute([$legacyId]);
                    $deleted++;
                } else {
                    $pdo->prepare("UPDATE accounting_cashboxes SET status='suspended',notes=CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')='' THEN '' ELSE ' | ' END, 'أرشيف قديم؛ يحتفظ به لسلامة السجل') WHERE id=? AND COALESCE(cashbox_role,'')=''")->execute([(int)$legacyId]);
                    $archived++;
                }
            }
            if ($startedHere) $pdo->commit();
            return ['created' => $created, 'retired' => $deleted + $archived, 'deleted' => $deleted, 'archived' => $archived, 'defaults' => $defaults];
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Automatic currency cashbox provisioning failed: ' . $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('cashboxAssignProviderOperation')) {
    /** إسناد العملية للربطية والصندوق والموظف دون تسجيل مبلغ غير مؤكد. */
    function cashboxAssignProviderOperation(PDO $pdo, string $source, int $operationId, int $providerId, ?int $networkId, string $status = 'awaiting_cost', string $reason = 'provider_cost_not_confirmed'): array
    {
        $tables = ['orders' => 'orders', 'telecom' => 'telecom_orders'];
        if (!isset($tables[$source]) || $operationId <= 0 || $providerId <= 0) return ['assigned' => false, 'reason' => 'invalid_assignment'];
        if (!$pdo->inTransaction() && (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo))) return ['assigned' => false, 'reason' => 'schema_unavailable'];
        if (!function_exists('accountingProviderLinkForOperation')) return ['assigned' => false, 'reason' => 'accounting_helper_unavailable'];
        $link = accountingProviderLinkForOperation($pdo, $providerId, $networkId);
        if (!$link || (int)($link['cashbox_id'] ?? 0) <= 0) return ['assigned' => false, 'reason' => 'ambiguous_or_unlinked_provider'];
        $table = $tables[$source];
        $staffId = !empty($link['cashbox_staff_id']) ? (int)$link['cashbox_staff_id'] : (!empty($link['staff_id']) ? (int)$link['staff_id'] : null);
        $upd = $pdo->prepare("UPDATE `{$table}` SET cashbox_id=?,cashbox_staff_id=?,provider_link_id=?,cashbox_link_type=?,cashbox_posting_status=?,cashbox_posting_reason=? WHERE id=? AND cashbox_posted_at IS NULL");
        $upd->execute([(int)$link['cashbox_id'], $staffId, (int)$link['id'], $source === 'telecom' ? 'telecom' : 'provider', $status, $reason, $operationId]);
        return ['assigned' => true, 'cashbox_id' => (int)$link['cashbox_id'], 'staff_id' => $staffId, 'link' => $link, 'already_posted' => false];
    }
}

if (!function_exists('cashboxPostProviderOperation')) {
    /**
     * يرحّل المبلغ الفعلي المرسل للمزود إلى صندوق الربطية النهائية فقط.
     * عند غياب ربطية وحيدة أو تكلفة/مبلغ فعلي، لا يتم التخمين ولا تنشأ حركة.
     */
    function cashboxPostProviderOperation(PDO $pdo, string $source, int $operationId, int $providerId, ?int $networkId, float $providerAmount, string $currencyCode, string $description, ?int $userId = null, ?int $serviceId = null, ?int $createdBy = null): array
    {
        $tables = ['orders' => 'orders', 'telecom' => 'telecom_orders'];
        if (!isset($tables[$source]) || $operationId <= 0 || $providerId <= 0 || $providerAmount <= 0) {
            return ['posted' => false, 'reason' => 'invalid_or_missing_amount'];
        }
        if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) {
            return ['posted' => false, 'reason' => 'schema_unavailable'];
        }
        $link = function_exists('accountingProviderLinkForOperation')
            ? accountingProviderLinkForOperation($pdo, $providerId, $networkId)
            : null;
        if (!$link || (int)($link['cashbox_id'] ?? 0) <= 0 || (int)($link['account_id'] ?? 0) <= 0) {
            return ['posted' => false, 'reason' => 'ambiguous_or_unlinked_provider'];
        }
        $cashboxId = (int)$link['cashbox_id'];
        $cashboxCurrency = strtoupper((string)($link['cashbox_currency_code'] ?? ''));
        $currencyCode = strtoupper(trim($currencyCode));
        if ($cashboxCurrency === '' || $currencyCode === '' || $cashboxCurrency !== $currencyCode) {
            return ['posted' => false, 'reason' => 'currency_mismatch'];
        }
        $key = 'provider-operation:' . $source . ':' . $operationId;
        $table = $tables[$source];
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare("SELECT id,journal_id,cashbox_id FROM accounting_cashbox_movements WHERE idempotency_key=? LIMIT 1");
            $existing->execute([$key]);
            $old = $existing->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ($startedHere) $pdo->commit();
                return ['posted' => true, 'movement_id' => (int)$old['id'], 'journal_id' => (int)($old['journal_id'] ?? 0), 'cashbox_id' => (int)$old['cashbox_id'], 'link' => $link, 'already_posted' => true];
            }
            $movementId = cashboxCreateMovement($pdo, [
                'cashbox_id' => $cashboxId,
                'movement_type' => 'payment',
                'amount' => $providerAmount,
                'counterpart_account_id' => (int)$link['account_id'],
                'description' => $description,
                'staff_id' => !empty($link['cashbox_staff_id']) ? (int)$link['cashbox_staff_id'] : (!empty($link['staff_id']) ? (int)$link['staff_id'] : null),
                'provider_id' => $providerId,
                'reference_type' => $source === 'telecom' ? 'telecom_order' : 'order',
                'reference_id' => 'ID' . $operationId,
                'entry_date' => date('Y-m-d'),
                'idempotency_key' => $key,
                'created_by' => $createdBy,
            ]);
            $movement = $pdo->prepare('SELECT journal_id FROM accounting_cashbox_movements WHERE id=? LIMIT 1');
            $movement->execute([$movementId]);
            $journalId = (int)($movement->fetchColumn() ?: 0);
            $update = $pdo->prepare("UPDATE `{$table}` SET cashbox_id=?,cashbox_staff_id=?,provider_link_id=?,cashbox_journal_id=?,cashbox_movement_id=?,cashbox_posted_at=NOW() WHERE id=? AND cashbox_posted_at IS NULL");
            $update->execute([$cashboxId, !empty($link['cashbox_staff_id']) ? (int)$link['cashbox_staff_id'] : (!empty($link['staff_id']) ? (int)$link['staff_id'] : null), (int)$link['id'], $journalId ?: null, $movementId, $operationId]);
            if ($startedHere) $pdo->commit();
            return ['posted' => true, 'movement_id' => $movementId, 'journal_id' => $journalId, 'cashbox_id' => $cashboxId, 'link' => $link, 'already_posted' => false];
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Provider cashbox posting failed for ' . $source . '#' . $operationId . ': ' . $e->getMessage());
            return ['posted' => false, 'reason' => 'posting_failed'];
        }
    }
}

if (!function_exists('cashboxPostDeposit')) {
    /** ترحيل إيداع مؤكد إلى صندوق الوسيلة المختارة مرة واحدة فقط. */
    function cashboxPostDeposit(PDO $pdo, int $topupId, int $userId, int $cashboxId, float $amount, string $currencyCode, string $description, ?int $createdBy = null): array
    {
        $currencyCode = strtoupper(trim($currencyCode));
        if ($topupId <= 0 || $userId <= 0 || $cashboxId <= 0 || $amount <= 0 || $currencyCode === '' || trim($description) === '') return ['posted' => false, 'reason' => 'invalid_deposit'];
        // تهيئة المخطط خارج المعاملة فقط؛ اعتماد الإيداع قد يستدعي هذه الدالة داخل معاملة تحديث الرصيد.
        if (!$pdo->inTransaction()) {
            if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) return ['posted' => false, 'reason' => 'schema_unavailable'];
        } elseif (!function_exists('accountingEnsureSchema')) {
            return ['posted' => false, 'reason' => 'schema_unavailable'];
        }
        $boxSt = $pdo->prepare('SELECT c.*, a.id AS account_id_checked, a.status AS account_status_checked, a.currency_code AS account_currency_checked FROM accounting_cashboxes c LEFT JOIN accounting_accounts a ON a.id=c.account_id WHERE c.id=? AND c.status="active" LIMIT 1');
        $boxSt->execute([$cashboxId]);
        $box = $boxSt->fetch(PDO::FETCH_ASSOC);
        $boxCurrency = strtoupper(trim((string)($box['currency_code'] ?? '')));
        $accountCurrency = strtoupper(trim((string)($box['account_currency_checked'] ?? '')));
        if (!$box || (int)($box['account_id'] ?? 0) <= 0 || empty($box['account_id_checked']) || (int)($box['account_status_checked'] ?? 0) !== 1) return ['posted' => false, 'reason' => 'cashbox_account_invalid'];
        if ($boxCurrency === '' || $boxCurrency !== $currencyCode || $accountCurrency !== $boxCurrency) return ['posted' => false, 'reason' => 'cashbox_currency_mismatch'];
        $counterpartCode = $currencyCode === 'USD' ? '1100' : '1100-' . $currencyCode;
        $counterpartSt = $pdo->prepare('SELECT id FROM accounting_accounts WHERE status=1 AND currency_code=? AND code=? LIMIT 1');
        $counterpartSt->execute([$boxCurrency, $counterpartCode]);
        $counterpart = $counterpartSt->fetchColumn();
        if (!$counterpart) return ['posted' => false, 'reason' => 'wallet_account_missing_for_currency'];
        $key = 'topup-deposit:' . $topupId;
        $startedHere = !$pdo->inTransaction();
        if ($startedHere) $pdo->beginTransaction();
        try {
            $old = $pdo->prepare('SELECT id,journal_id,cashbox_id,currency_code,status FROM accounting_cashbox_movements WHERE idempotency_key=? LIMIT 1');
            $old->execute([$key]);
            $existing = $old->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((string)($existing['status'] ?? '') !== 'posted' || (int)($existing['journal_id'] ?? 0) <= 0) {
                    throw new RuntimeException('existing_movement_without_posted_journal');
                }
                if ((int)$existing['cashbox_id'] !== $cashboxId || strtoupper((string)$existing['currency_code']) !== $currencyCode) {
                    if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
                    return ['posted' => false, 'reason' => 'deposit_already_posted_different_cashbox'];
                }
                if ($startedHere) $pdo->commit();
                return ['posted' => true, 'movement_id' => (int)$existing['id'], 'journal_id' => (int)$existing['journal_id'], 'cashbox_id' => (int)$existing['cashbox_id'], 'already_posted' => true];
            }
            $movementId = cashboxCreateMovement($pdo, [
                'cashbox_id' => $cashboxId,
                'movement_type' => 'receipt',
                'amount' => $amount,
                'counterpart_account_id' => (int)$counterpart,
                'description' => $description,
                'staff_id' => $box['staff_id'] ? (int)$box['staff_id'] : null,
                'user_id' => $userId,
                'reference_type' => 'topup',
                'reference_id' => 'ID' . $topupId,
                'entry_date' => date('Y-m-d'),
                'idempotency_key' => $key,
                'created_by' => $createdBy,
            ]);
            $movement = $pdo->prepare('SELECT journal_id FROM accounting_cashbox_movements WHERE id=? LIMIT 1');
            $movement->execute([$movementId]);
            $journalId = (int)($movement->fetchColumn() ?: 0);
            if ($journalId <= 0) throw new RuntimeException('journal_missing');
            $upd = $pdo->prepare("UPDATE topup_requests SET cashbox_id=?,cashbox_staff_id=?,cashbox_movement_id=?,cashbox_posted_at=NOW(),cashbox_posting_status='posted',cashbox_posting_reason=NULL WHERE id=? AND cashbox_posted_at IS NULL");
            $upd->execute([$cashboxId, $box['staff_id'] ? (int)$box['staff_id'] : null, $movementId, $topupId]);
            $verify = $pdo->prepare('SELECT id FROM topup_requests WHERE id=? AND cashbox_movement_id=? AND cashbox_posted_at IS NOT NULL LIMIT 1');
            $verify->execute([$topupId, $movementId]);
            if (!$verify->fetchColumn()) throw new RuntimeException('topup_metadata_update_failed');
            if ($startedHere) $pdo->commit();
            return ['posted' => true, 'movement_id' => $movementId, 'journal_id' => $journalId, 'cashbox_id' => $cashboxId, 'already_posted' => false];
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            $message = $e->getMessage();
            $reason = 'posting_failed';
            if ($message === 'journal_missing') $reason = 'journal_missing';
            elseif ($message === 'topup_metadata_update_failed') $reason = 'metadata_update_failed';
            elseif ($message === 'existing_movement_without_posted_journal') $reason = 'existing_movement_without_journal';
            error_log('Deposit cashbox posting failed for topup#' . $topupId . ' [' . $reason . ']: ' . $message);
            return ['posted' => false, 'reason' => $reason];
        }
    }
}

if (!function_exists('cashboxPostUsdtDeposit')) {
    /**
     * ترحيل إيداع USDT-BEP20 المؤكد إلى صندوق USD وقيده محاسبياً مرة واحدة.
     * تُستدعى داخل معاملة اعتماد الرصيد، ولا تبدأ معاملة ثانية إذا كانت هناك معاملة قائمة.
     */
    function cashboxPostUsdtDeposit(PDO $pdo, int $requestId, int $userId, int $cashboxId, float $amount, string $description, ?int $createdBy = null): array
    {
        if ($requestId <= 0 || $userId <= 0 || $cashboxId <= 0 || $amount <= 0 || trim($description) === '') {
            return ['posted' => false, 'reason' => 'invalid_usdt_deposit'];
        }

        $startedHere = !$pdo->inTransaction();
        if ($startedHere) {
            if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) {
                return ['posted' => false, 'reason' => 'schema_unavailable'];
            }
            $pdo->beginTransaction();
        }

        $key = 'usdt-deposit:' . $requestId;
        try {
            $boxSt = $pdo->prepare('SELECT c.*, a.id AS account_id_checked FROM accounting_cashboxes c LEFT JOIN accounting_accounts a ON a.id=c.account_id WHERE c.id=? FOR UPDATE');
            $boxSt->execute([$cashboxId]);
            $box = $boxSt->fetch(PDO::FETCH_ASSOC);
            if (!$box || $box['status'] !== 'active' || (int)($box['account_id'] ?? 0) <= 0 || strtoupper((string)$box['currency_code']) !== 'USD' || empty($box['account_id_checked'])) {
                throw new InvalidArgumentException('صندوق USDT غير موجود أو غير نشط أو غير مرتبط بحساب USD صالح');
            }

            $counterpartSt = $pdo->prepare("SELECT id FROM accounting_accounts WHERE status=1 AND currency_code='USD' AND code='1100' LIMIT 1");
            $counterpartSt->execute();
            $counterpart = (int)($counterpartSt->fetchColumn() ?: 0);
            if ($counterpart <= 0) throw new RuntimeException('حساب محافظ العملاء 1100/USD غير موجود أو غير نشط');

            $existingSt = $pdo->prepare('SELECT id,journal_id,cashbox_id FROM accounting_cashbox_movements WHERE idempotency_key=? LIMIT 1');
            $existingSt->execute([$key]);
            $existing = $existingSt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $update = $pdo->prepare("UPDATE usdt_deposit_requests SET cashbox_id=?,cashbox_staff_id=?,cashbox_journal_id=?,cashbox_movement_id=?,cashbox_posted_at=COALESCE(cashbox_posted_at,NOW()),cashbox_posting_status='posted',cashbox_posting_reason=NULL WHERE id=?");
                $update->execute([$cashboxId, $box['staff_id'] ? (int)$box['staff_id'] : null, (int)($existing['journal_id'] ?? 0) ?: null, (int)$existing['id'], $requestId]);
                if ($startedHere) $pdo->commit();
                return ['posted' => true, 'movement_id' => (int)$existing['id'], 'journal_id' => (int)($existing['journal_id'] ?? 0), 'cashbox_id' => (int)$existing['cashbox_id'], 'already_posted' => true];
            }

            $movementId = cashboxCreateMovement($pdo, [
                'cashbox_id' => $cashboxId,
                'movement_type' => 'receipt',
                'amount' => $amount,
                'counterpart_account_id' => $counterpart,
                'description' => $description,
                'staff_id' => $box['staff_id'] ? (int)$box['staff_id'] : null,
                'user_id' => $userId,
                'reference_type' => 'usdt_bep20',
                'reference_id' => 'USDT#' . $requestId,
                'entry_date' => date('Y-m-d'),
                'idempotency_key' => $key,
                'created_by' => $createdBy,
            ]);

            $movementSt = $pdo->prepare('SELECT journal_id FROM accounting_cashbox_movements WHERE id=? LIMIT 1');
            $movementSt->execute([$movementId]);
            $journalId = (int)($movementSt->fetchColumn() ?: 0);
            if ($journalId <= 0) throw new RuntimeException('تم إنشاء حركة USDT دون قيد محاسبي');

            $update = $pdo->prepare("UPDATE usdt_deposit_requests SET cashbox_id=?,cashbox_staff_id=?,cashbox_journal_id=?,cashbox_movement_id=?,cashbox_posted_at=NOW(),cashbox_posting_status='posted',cashbox_posting_reason=NULL WHERE id=?");
            $update->execute([$cashboxId, $box['staff_id'] ? (int)$box['staff_id'] : null, $journalId, $movementId, $requestId]);
            if ($update->rowCount() < 1) throw new RuntimeException('تعذر حفظ بيانات ترحيل إيداع USDT');

            if ($startedHere) $pdo->commit();
            return ['posted' => true, 'movement_id' => $movementId, 'journal_id' => $journalId, 'cashbox_id' => $cashboxId, 'already_posted' => false];
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            if (!$startedHere) throw $e;
            error_log('USDT cashbox posting failed for #' . $requestId . ': ' . $e->getMessage());
            return ['posted' => false, 'reason' => 'posting_failed'];
        }
    }
}

if (!function_exists('cashboxTransfer')) {
    function cashboxTransfer(PDO $pdo, int $fromId, int $toId, float $amount, string $description, string $entryDate, ?int $createdBy = null): array
    {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId || $amount <= 0 || trim($description) === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) throw new InvalidArgumentException('بيانات التحويل غير صالحة');
        if (!cashboxEnsureSchema($pdo) || !function_exists('accountingEnsureSchema') || !accountingEnsureSchema($pdo)) throw new RuntimeException('تعذر تهيئة الجداول');
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM accounting_cashboxes WHERE id IN (?,?) ORDER BY id FOR UPDATE');
            $st->execute([$fromId,$toId]); $boxes=[]; while($r=$st->fetch(PDO::FETCH_ASSOC)) $boxes[(int)$r['id']]=$r;
            if (count($boxes)!==2) throw new InvalidArgumentException('أحد الصندوقين غير موجود');
            if ($boxes[$fromId]['status']!=='active' || $boxes[$toId]['status']!=='active') throw new InvalidArgumentException('يجب أن يكون الصندوقان نشطين');
            if ($boxes[$fromId]['currency_code'] !== $boxes[$toId]['currency_code']) throw new InvalidArgumentException('لا يمكن التحويل بين عملتين مختلفتين دون سعر صرف صريح');
            $balance = cashboxBalance($pdo,$fromId); if ($balance < $amount) throw new InvalidArgumentException('الرصيد الفعلي للصندوق المصدر لا يكفي');
            $fromAccount=(int)$boxes[$fromId]['account_id']; $toAccount=(int)$boxes[$toId]['account_id'];
            if ($fromAccount<=0 || $toAccount<=0 || $fromAccount===$toAccount) throw new InvalidArgumentException('يجب ربط الصندوقين بحسابين محاسبيين مختلفين');
            $group='cash-transfer:'.bin2hex(random_bytes(12));
            $journalId=cashboxCreateJournalRow($pdo,['debit_account_id'=>$toAccount,'credit_account_id'=>$fromAccount,'amount'=>$amount,'currency_code'=>$boxes[$fromId]['currency_code'],'description'=>$description,'staff_id'=>$boxes[$fromId]['staff_id'],'reference_type'=>'cashbox_transfer','reference_id'=>$group,'entry_date'=>$entryDate,'idempotency_key'=>$group,'created_by'=>$createdBy]);
            $ins=$pdo->prepare("INSERT INTO accounting_cashbox_movements
                (cashbox_id,movement_type,amount,currency_code,counterpart_cashbox_id,journal_id,reference_type,reference_id,description,staff_id,entry_date,status,transfer_group,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'posted',?,?)");
            $ins->execute([$fromId,'transfer_out',number_format($amount,8,'.',''),$boxes[$fromId]['currency_code'],$toId,$journalId,'cashbox_transfer',$group,$description,$boxes[$fromId]['staff_id'] ?: null,$entryDate,$group,$createdBy]);
            $outId=(int)$pdo->lastInsertId();
            $ins->execute([$toId,'transfer_in',number_format($amount,8,'.',''),$boxes[$toId]['currency_code'],$fromId,$journalId,'cashbox_transfer',$group,$description,$boxes[$toId]['staff_id'] ?: null,$entryDate,$group,$createdBy]);
            $inId=(int)$pdo->lastInsertId();
            $pdo->commit(); return ['out_id'=>$outId,'in_id'=>$inId,'journal_id'=>$journalId,'transfer_group'=>$group];
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}

if (!function_exists('cashboxCurrencyDefaultsAudit')) {
    /**
     * تدقيق قراءة فقط للصناديق الآلية والحسابات المقابلة لها لكل عملة نشطة.
     * لا يهيئ المخطط ولا ينشئ أو يعدل أو يحذف أي بيانات، لذلك يمكن تشغيله
     * على نسخة الإنتاج بعد رفع التعديلات لعرض حالة الربط الفعلية بأمان.
     */
    function cashboxCurrencyDefaultsAudit(PDO $pdo, array $activeRates = []): array
    {
        $hasTable = static function (string $table) use ($pdo): bool {
            $st = $pdo->prepare('SHOW TABLES LIKE ?');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        };
        $hasColumn = static function (string $table, string $column) use ($pdo): bool {
            $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $st->execute([$column]);
            return (bool)$st->fetch(PDO::FETCH_ASSOC);
        };

        foreach (['exchange_rates', 'accounting_accounts', 'accounting_cashboxes'] as $table) {
            if (!$hasTable($table)) {
                return [
                    'ok' => false,
                    'status' => 'schema_unavailable',
                    'read_only' => true,
                    'message' => 'الجدول غير موجود: ' . $table,
                    'rows' => [],
                ];
            }
        }
        foreach ([
            'accounting_cashboxes' => ['code', 'account_id', 'currency_code', 'status', 'cashbox_role'],
            'accounting_accounts' => ['code', 'currency_code', 'account_type', 'nature', 'status', 'is_system'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!$hasColumn($table, $column)) {
                    return [
                        'ok' => false,
                        'status' => 'schema_unavailable',
                        'read_only' => true,
                        'message' => 'العمود غير موجود: ' . $table . '.' . $column,
                        'rows' => [],
                    ];
                }
            }
        }

        if (!$activeRates) {
            $activeRates = $pdo->query("SELECT currency_code,currency_name FROM exchange_rates WHERE status=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
        }
        $rates = [];
        foreach ($activeRates as $rate) {
            $currency = strtoupper(trim((string)($rate['currency_code'] ?? '')));
            if ($currency === '' || !preg_match('/^[\\p{L}\\p{N} _-]{1,10}$/u', $currency)) continue;
            $rates[$currency] = trim((string)($rate['currency_name'] ?? $currency)) ?: $currency;
        }

        $boxFind = $pdo->prepare('SELECT id,code,name,account_id,currency_code,status,cashbox_role FROM accounting_cashboxes WHERE code=? LIMIT 1');
        $accountFind = $pdo->prepare('SELECT id,code,name,currency_code,account_type,nature,status,is_system FROM accounting_accounts WHERE code=? LIMIT 1');
        $rows = [];
        foreach ($rates as $currency => $currencyName) {
            $safeSuffix = substr(hash('sha256', $currency), 0, 12);
            foreach ([
                'normal' => ['N', 'صندوق عادي — ', 'حساب نقدي عادي — ', 'قبض إيداعات العملاء'],
                'operational' => ['O', 'صندوق تشغيلي — ', 'حساب نقدي تشغيلي — ', 'عمليات التشغيل والمزودين'],
            ] as $role => [$roleCode, $boxPrefix, $accountPrefix, $note]) {
                $expectedAccountCode = '1000-CASH-' . $safeSuffix . '-' . $roleCode;
                $expectedCashboxCode = 'AUTO-' . $safeSuffix . '-' . $roleCode;
                $boxFind->execute([$expectedCashboxCode]);
                $box = $boxFind->fetch(PDO::FETCH_ASSOC) ?: null;
                $accountFind->execute([$expectedAccountCode]);
                $account = $accountFind->fetch(PDO::FETCH_ASSOC) ?: null;
                $issues = [];

                if (!$box) {
                    $issues[] = 'missing_cashbox';
                } else {
                    if (strtoupper(trim((string)$box['currency_code'])) !== $currency) $issues[] = 'cashbox_currency_mismatch';
                    if ((string)$box['cashbox_role'] !== $role) $issues[] = 'cashbox_role_mismatch';
                    if ((string)$box['status'] !== 'active') $issues[] = 'cashbox_inactive';
                    if (!$account || (int)$box['account_id'] !== (int)$account['id']) $issues[] = 'account_link_mismatch';
                }
                if (!$account) {
                    $issues[] = 'missing_account';
                } else {
                    if (strtoupper(trim((string)$account['currency_code'])) !== $currency) $issues[] = 'account_currency_mismatch';
                    if ((string)$account['account_type'] !== 'asset') $issues[] = 'account_type_mismatch';
                    if ((string)$account['nature'] !== 'debit') $issues[] = 'account_nature_mismatch';
                    if ((int)$account['status'] !== 1) $issues[] = 'account_inactive';
                    if ((int)$account['is_system'] !== 1) $issues[] = 'account_not_system';
                }
                $rows[] = [
                    'currency_code' => $currency,
                    'currency_name' => $currencyName,
                    'role' => $role,
                    'expected_account_code' => $expectedAccountCode,
                    'expected_cashbox_code' => $expectedCashboxCode,
                    'cashbox_id' => $box ? (int)$box['id'] : null,
                    'cashbox_code' => $box['code'] ?? null,
                    'cashbox_currency_code' => $box['currency_code'] ?? null,
                    'cashbox_role' => $box['cashbox_role'] ?? null,
                    'cashbox_status' => $box['status'] ?? null,
                    'cashbox_account_id' => $box ? (int)$box['account_id'] : null,
                    'account_id' => $account ? (int)$account['id'] : null,
                    'account_code' => $account['code'] ?? null,
                    'account_currency_code' => $account['currency_code'] ?? null,
                    'account_type' => $account['account_type'] ?? null,
                    'account_nature' => $account['nature'] ?? null,
                    'account_status' => $account['status'] ?? null,
                    'account_is_system' => $account['is_system'] ?? null,
                    'status' => $issues ? 'mismatch' : 'ok',
                    'issues' => $issues,
                ];
            }
        }
        $mismatches = count(array_filter($rows, static fn(array $row): bool => $row['status'] !== 'ok'));
        return [
            'ok' => $mismatches === 0,
            'status' => $mismatches === 0 ? 'ok' : 'mismatch',
            'read_only' => true,
            'currency_count' => count($rates),
            'row_count' => count($rows),
            'mismatch_count' => $mismatches,
            'rows' => $rows,
        ];
    }
}

if (!function_exists('cashboxTypeLabel')) {
    function cashboxTypeLabel(string $type): string
    {
        return ['receipt'=>'قبض','payment'=>'صرف','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر','opening'=>'رصيد افتتاحي'][$type] ?? $type;
    }
}
