<?php
/**
 * Auto-fix محافظ لربط الصناديق الآلية بالحسابات المحاسبية.
 *
 * هذا الملف مستقل اختيارياً؛ يمكن تحميله بعد cashbox_helper.php.
 * لا ينفذ أي تعديل في وضع dry-run، ولا يلمس الصناديق القديمة غير الآلية.
 */

if (!function_exists('cashboxCurrencyDefaultsAutoFix')) {
    function cashboxCurrencyDefaultsAutoFix(PDO $pdo, array $activeRates = [], bool $apply = false, ?int $createdBy = null): array
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
                    'read_only' => !$apply,
                    'applied' => 0,
                    'message' => 'الجدول غير موجود: ' . $table,
                    'rows' => [],
                ];
            }
        }
        foreach ([
            'accounting_cashboxes' => ['code', 'name', 'account_id', 'currency_code', 'status', 'cashbox_role'],
            'accounting_accounts' => ['code', 'name', 'currency_code', 'account_type', 'nature', 'status', 'is_system'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (!$hasColumn($table, $column)) {
                    return [
                        'ok' => false,
                        'status' => 'schema_unavailable',
                        'read_only' => !$apply,
                        'applied' => 0,
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
            if ($currency === '' || !preg_match('/^[\p{L}\p{N} _-]{1,10}$/u', $currency)) continue;
            $rates[$currency] = trim((string)($rate['currency_name'] ?? $currency)) ?: $currency;
        }
        if (!$rates) {
            return [
                'ok' => true,
                'status' => 'nothing_to_check',
                'read_only' => !$apply,
                'applied' => 0,
                'currency_count' => 0,
                'row_count' => 0,
                'action_count' => 0,
                'blocked_count' => 0,
                'rows' => [],
            ];
        }

        // لا توجد أسماء جداول أو أعمدة ديناميكية من المستخدم؛ هذه قائمة ثابتة من مراجع النظام.
        $accountRefSpecs = [
            ['accounting_journal', 'debit_account_id'],
            ['accounting_journal', 'credit_account_id'],
            ['accounting_account_links', 'account_id'],
        ];
        $cashboxRefSpecs = [
            ['accounting_cashbox_movements', 'cashbox_id'],
            ['accounting_provider_links', 'cashbox_id'],
            ['accounting_telecom_links', 'cashbox_id'],
            ['payment_method_cashboxes', 'cashbox_id'],
            ['payment_methods', 'cashbox_id'],
            ['topup_requests', 'cashbox_id'],
            ['usdt_deposit_requests', 'cashbox_id'],
            ['orders', 'cashbox_id'],
            ['telecom_orders', 'cashbox_id'],
        ];
        $availableRefs = [];
        foreach (array_merge($accountRefSpecs, $cashboxRefSpecs) as [$table, $column]) {
            $availableRefs[$table . '.' . $column] = $hasTable($table) && $hasColumn($table, $column);
        }
        $countRefs = static function (int $id, array $specs) use ($pdo, $availableRefs): int {
            if ($id <= 0) return 0;
            $total = 0;
            foreach ($specs as [$table, $column]) {
                if (empty($availableRefs[$table . '.' . $column])) continue;
                $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
                $st->execute([$id]);
                $total += (int)$st->fetchColumn();
            }
            return $total;
        };

        $buildPlan = static function (bool $lock) use ($pdo, $rates, $accountRefSpecs, $cashboxRefSpecs, $availableRefs, $countRefs): array {
            $accountCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_accounts WHERE code=?');
            $accountFindSql = 'SELECT id,code,name,currency_code,account_type,nature,status,is_system FROM accounting_accounts WHERE code=? ORDER BY id LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
            $accountFind = $pdo->prepare($accountFindSql);
            $cashboxCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_cashboxes WHERE code=?');
            $cashboxFindSql = 'SELECT id,code,name,account_id,currency_code,status,cashbox_role FROM accounting_cashboxes WHERE code=? ORDER BY id LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
            $cashboxFind = $pdo->prepare($cashboxFindSql);
            $rows = [];

            foreach ($rates as $currency => $currencyName) {
                $safeSuffix = substr(hash('sha256', $currency), 0, 12);
                foreach ([
                    'normal' => ['N', 'صندوق عادي — ', 'حساب نقدي عادي — ', 'قبض إيداعات العملاء'],
                    'operational' => ['O', 'صندوق تشغيلي — ', 'حساب نقدي تشغيلي — ', 'عمليات التشغيل والمزودين'],
                ] as $role => [$roleCode, $boxPrefix, $accountPrefix, $note]) {
                    $accountCode = '1000-CASH-' . $safeSuffix . '-' . $roleCode;
                    $cashboxCode = 'AUTO-' . $safeSuffix . '-' . $roleCode;
                    $accountName = $accountPrefix . $currencyName . ' (' . $currency . ')';
                    $cashboxName = $boxPrefix . $currencyName . ' (' . $currency . ')';
                    $accountCount->execute([$accountCode]);
                    $accountDuplicateCount = (int)$accountCount->fetchColumn();
                    $account = null;
                    if ($accountDuplicateCount === 1) {
                        $accountFind->execute([$accountCode]);
                        $account = $accountFind->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    $cashboxCount->execute([$cashboxCode]);
                    $cashboxDuplicateCount = (int)$cashboxCount->fetchColumn();
                    $box = null;
                    if ($cashboxDuplicateCount === 1) {
                        $cashboxFind->execute([$cashboxCode]);
                        $box = $cashboxFind->fetch(PDO::FETCH_ASSOC) ?: null;
                    }

                    $issues = [];
                    $actions = [];
                    $accountRefs = $account ? $countRefs((int)$account['id'], $accountRefSpecs) : 0;
                    $boxRefs = $box ? $countRefs((int)$box['id'], $cashboxRefSpecs) : 0;
                    $accountAction = null;
                    $cashboxAction = null;

                    if ($accountDuplicateCount > 1) {
                        $issues[] = 'duplicate_account_code';
                    } elseif (!$account) {
                        $accountAction = 'create';
                        $actions[] = 'create_account';
                    } else {
                        $accountCurrency = strtoupper(trim((string)$account['currency_code']));
                        if ($accountCurrency !== $currency) {
                            $issues[] = 'account_currency_conflict';
                        } else {
                            $accountInvalid = (string)$account['account_type'] !== 'asset'
                                || (string)$account['nature'] !== 'debit'
                                || (int)$account['status'] !== 1
                                || (int)$account['is_system'] !== 1;
                            if ($accountInvalid) {
                                if ($accountRefs > 0) {
                                    $issues[] = 'account_properties_protected_by_references';
                                } else {
                                    $accountAction = 'repair';
                                    $actions[] = 'repair_account';
                                }
                            }
                        }
                    }

                    if ($cashboxDuplicateCount > 1) {
                        $issues[] = 'duplicate_cashbox_code';
                    } elseif (!$box) {
                        // وجود الحساب الصحيح يكفي لإنشاء الصندوق المفقود؛ أما الحساب المفقود
                        // فسيُنشأ أولاً في applyRows داخل المعاملة نفسها.
                        if ($account || $accountAction === 'create') {
                            $cashboxAction = 'create';
                            $actions[] = 'create_cashbox';
                        } else {
                            $issues[] = 'missing_account_for_cashbox';
                        }
                    } else {
                        $boxCurrency = strtoupper(trim((string)$box['currency_code']));
                        $boxRole = trim((string)($box['cashbox_role'] ?? ''));
                        if ($boxCurrency !== $currency) $issues[] = 'cashbox_currency_conflict';
                        if ($boxRole !== '' && $boxRole !== $role) $issues[] = 'cashbox_role_conflict';
                        $expectedAccountId = $account ? (int)$account['id'] : 0;
                        $actualAccountId = (int)($box['account_id'] ?? 0);
                        $linkMismatch = $expectedAccountId <= 0 || $actualAccountId !== $expectedAccountId;
                        $statusInvalid = (string)$box['status'] !== 'active';
                        if ($linkMismatch || $statusInvalid || $boxRole === '') {
                            if ($boxRefs > 0 && ($linkMismatch || $statusInvalid)) {
                                if ($linkMismatch) $issues[] = 'cashbox_account_link_protected_by_references';
                                if ($statusInvalid) $issues[] = 'cashbox_status_protected_by_references';
                            } else {
                                $cashboxAction = 'repair';
                                $actions[] = 'repair_cashbox';
                            }
                        }
                    }

                    $blocked = count($issues) > 0;
                    if ($blocked) {
                        // لا ننفذ أي إجراء في صف يحتوي تعارضاً أو مرجعاً محمياً.
                        $accountAction = null;
                        $cashboxAction = null;
                        $actions = [];
                    }
                    $rows[] = [
                        'currency_code' => $currency,
                        'currency_name' => $currencyName,
                        'role' => $role,
                        'expected_account_code' => $accountCode,
                        'expected_cashbox_code' => $cashboxCode,
                        'account_id' => $account ? (int)$account['id'] : null,
                        'cashbox_id' => $box ? (int)$box['id'] : null,
                        'cashbox_account_id' => $box ? (int)$box['account_id'] : null,
                        'account_refs' => $accountRefs,
                        'cashbox_refs' => $boxRefs,
                        'account_action' => $accountAction,
                        'cashbox_action' => $cashboxAction,
                        'actions' => $actions,
                        'issues' => $issues,
                        'status' => $blocked ? 'blocked' : ($actions ? 'planned' : 'ok'),
                        '_account_name' => $accountName,
                        '_cashbox_name' => $cashboxName,
                        '_note' => $note,
                        '_role_code' => $roleCode,
                    ];
                }
            }
            return $rows;
        };

        $applyRows = static function (array &$rows) use ($pdo, $createdBy): int {
            $accountInsert = $pdo->prepare("INSERT INTO accounting_accounts (code,name,account_type,nature,currency_code,is_system,status,created_by) VALUES (?,?,?,?,?,1,1,?)");
            $accountRepair = $pdo->prepare("UPDATE accounting_accounts SET name=?,account_type='asset',nature='debit',currency_code=?,is_system=1,status=1 WHERE id=?");
            $cashboxInsert = $pdo->prepare("INSERT INTO accounting_cashboxes (code,name,account_id,currency_code,status,cashbox_role,notes,created_by) VALUES (?,?,?,?,'active',?,?,?)");
            $cashboxRepair = $pdo->prepare("UPDATE accounting_cashboxes SET name=?,account_id=?,currency_code=?,status='active',cashbox_role=?,notes=COALESCE(notes,?) WHERE id=?");
            $applied = 0;
            foreach ($rows as &$row) {
                if (($row['status'] ?? '') !== 'planned') continue;
                $accountId = (int)($row['account_id'] ?? 0);
                if (($row['account_action'] ?? '') === 'create') {
                    $accountInsert->execute([
                        $row['expected_account_code'],
                        $row['_account_name'],
                        'asset',
                        'debit',
                        $row['currency_code'],
                        $createdBy,
                    ]);
                    $accountId = (int)$pdo->lastInsertId();
                    $row['account_id'] = $accountId;
                    $applied++;
                } elseif (($row['account_action'] ?? '') === 'repair') {
                    $accountRepair->execute([$row['_account_name'], $row['currency_code'], $accountId]);
                    $applied++;
                }
                if (($row['cashbox_action'] ?? '') === 'create') {
                    $cashboxInsert->execute([
                        $row['expected_cashbox_code'],
                        $row['_cashbox_name'],
                        $accountId,
                        $row['currency_code'],
                        $row['role'],
                        $row['_note'],
                        $createdBy,
                    ]);
                    $row['cashbox_id'] = (int)$pdo->lastInsertId();
                    $row['cashbox_account_id'] = $accountId;
                    $applied++;
                } elseif (($row['cashbox_action'] ?? '') === 'repair') {
                    $cashboxRepair->execute([
                        $row['_cashbox_name'],
                        $accountId,
                        $row['currency_code'],
                        $row['role'],
                        $row['_note'],
                        (int)$row['cashbox_id'],
                    ]);
                    $row['cashbox_account_id'] = $accountId;
                    $applied++;
                }
                $row['status'] = 'fixed';
            }
            unset($row);
            return $applied;
        };

        $startedHere = false;
        try {
            if ($apply) {
                if ($pdo->inTransaction()) {
                    throw new RuntimeException('cashbox_autofix_requires_top_level_transaction');
                }
                $pdo->beginTransaction();
                $startedHere = true;
            }
            $rows = $buildPlan($apply);
            $blockedCount = count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'blocked'));
            $actionCount = count(array_filter($rows, static fn(array $row): bool => in_array($row['status'], ['planned', 'fixed'], true)));
            $applied = 0;
            if ($apply && $blockedCount > 0) {
                // لا يوجد تطبيق جزئي: أي تعارض يحتاج مراجعة يوقف العملية كلها.
                $pdo->rollBack();
                $startedHere = false;
                foreach ($rows as &$row) {
                    unset($row['_account_name'], $row['_cashbox_name'], $row['_note'], $row['_role_code']);
                }
                unset($row);
                return [
                    'ok' => false,
                    'status' => 'blocked',
                    'read_only' => false,
                    'applied' => 0,
                    'currency_count' => count($rates),
                    'row_count' => count($rows),
                    'action_count' => $actionCount,
                    'blocked_count' => $blockedCount,
                    'message' => 'تم إيقاف الإصلاح بالكامل بسبب وجود صفوف محمية أو متعارضة؛ لم يتم تطبيق أي تغيير.',
                    'rows' => $rows,
                ];
            }
            if ($apply) {
                $applied = $applyRows($rows);
                $pdo->commit();
                $startedHere = false;
            }
            foreach ($rows as &$row) {
                unset($row['_account_name'], $row['_cashbox_name'], $row['_note'], $row['_role_code']);
            }
            unset($row);
            $result = [
                'ok' => $blockedCount === 0,
                'status' => $blockedCount > 0 ? 'blocked' : ($apply ? 'applied' : ($actionCount ? 'planned' : 'ok')),
                'read_only' => !$apply,
                'applied' => $applied,
                'currency_count' => count($rates),
                'row_count' => count($rows),
                'action_count' => $actionCount,
                'blocked_count' => $blockedCount,
                'rows' => $rows,
            ];
            if ($apply && function_exists('cashboxCurrencyDefaultsAudit')) {
                $result['post_audit'] = cashboxCurrencyDefaultsAudit($pdo, $activeRates);
                if (empty($result['post_audit']['ok'])) $result['ok'] = false;
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedHere && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Automatic currency cashbox auto-fix failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'status' => 'error',
                'read_only' => !$apply,
                'applied' => 0,
                'message' => 'تعذر تنفيذ الإصلاح التلقائي؛ تم التراجع عن المعاملة بالكامل.',
                'technical_code' => $e->getMessage(),
                'rows' => [],
            ];
        }
    }
}
