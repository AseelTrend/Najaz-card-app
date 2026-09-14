<?php
/**
 * Independent Binance Pay trade-history integration.
 * This file never sends credentials to the browser and never logs raw API data.
 */
if (!defined('NJAZ_BINANCE_PAY_ENDPOINT')) {
    define('NJAZ_BINANCE_PAY_ENDPOINT', '/sapi/v1/pay/transactions');
}

if (!function_exists('binancePayStorageKey')) {
    function binancePayStorageKey(): string
    {
        // Optional override for installations that already provide a dedicated secret.
        $material = trim((string)(getenv('NJAZ_BINANCE_PAY_STORAGE_KEY') ?: ''));
        if ($material === '') {
            // Simple zero-configuration fallback: derive a stable key from existing site config.
            // The derived value is never shown or sent to the browser.
            $parts = [];
            foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'SITE_URL', 'SITE_NAME'] as $constant) {
                if (defined($constant)) $parts[] = $constant . '=' . (string)constant($constant);
            }
            if (count($parts) >= 4) $material = 'njaz-binance-pay-storage-v1|' . implode('|', $parts);
        }
        return $material === '' ? '' : hash('sha256', $material, true);
    }
}

if (!function_exists('binancePayEncryptCredentials')) {
    function binancePayEncryptCredentials(string $apiKey, string $apiSecret): ?array
    {
        $key = binancePayStorageKey();
        if ($key === '' || !function_exists('openssl_encrypt')) return null;
        try { $iv = random_bytes(12); } catch (Throwable $e) { return null; }
        $tag = '';
        $payload = json_encode(['api_key' => $apiKey, 'api_secret' => $apiSecret], JSON_UNESCAPED_SLASHES);
        $cipher = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') return null;
        return [base64_encode($cipher), base64_encode($iv), base64_encode($tag)];
    }
}

if (!function_exists('binancePayDecryptCredentials')) {
    function binancePayDecryptCredentials(string $ciphertext, string $iv, string $tag): array
    {
        $key = binancePayStorageKey();
        if ($key === '' || !function_exists('openssl_decrypt')) return [];
        $cipher = base64_decode($ciphertext, true); $ivBytes = base64_decode($iv, true); $tagBytes = base64_decode($tag, true);
        if ($cipher === false || $ivBytes === false || $tagBytes === false) return [];
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $ivBytes, $tagBytes);
        if ($plain === false) return [];
        $decoded = json_decode($plain, true);
        if (!is_array($decoded)) return [];
        return ['api_key' => trim((string)($decoded['api_key'] ?? '')), 'api_secret' => trim((string)($decoded['api_secret'] ?? ''))];
    }
}

if (!function_exists('binancePayDatabaseCredentials')) {
    function binancePayDatabaseCredentials(PDO $pdo): array
    {
        if (binancePayStorageKey() === '') return [];
        try {
            $stmt = $pdo->query('SELECT credentials_ciphertext, credentials_iv, credentials_tag FROM binance_pay_credentials WHERE id=1 LIMIT 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return [];
            $credentials = binancePayDecryptCredentials((string)$row['credentials_ciphertext'], (string)$row['credentials_iv'], (string)$row['credentials_tag']);
            return ($credentials['api_key'] !== '' && $credentials['api_secret'] !== '') ? $credentials : [];
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('binancePaySaveDatabaseCredentials')) {
    function binancePaySaveDatabaseCredentials(PDO $pdo, string $apiKey, string $apiSecret, int $userId): bool
    {
        $encrypted = binancePayEncryptCredentials(trim($apiKey), trim($apiSecret));
        if ($encrypted === null || trim($apiKey) === '' || trim($apiSecret) === '') return false;
        $stmt = $pdo->prepare("INSERT INTO binance_pay_credentials (id, credentials_ciphertext, credentials_iv, credentials_tag, updated_by) VALUES (1,?,?,?,?) ON DUPLICATE KEY UPDATE credentials_ciphertext=VALUES(credentials_ciphertext), credentials_iv=VALUES(credentials_iv), credentials_tag=VALUES(credentials_tag), updated_by=VALUES(updated_by)");
        $stmt->execute([$encrypted[0], $encrypted[1], $encrypted[2], $userId]);
        return true;
    }
}

if (!function_exists('binancePayClearDatabaseCredentials')) {
    function binancePayClearDatabaseCredentials(PDO $pdo): void { $pdo->exec('DELETE FROM binance_pay_credentials WHERE id=1'); }
}

if (!function_exists('binancePayCredentialStatus')) {
    function binancePayCredentialStatus(PDO $pdo): array
    {
        $db = binancePayDatabaseCredentials($pdo);
        $externalKey = trim((string)(getenv('NJAZ_BINANCE_PAY_API_KEY') ?: ''));
        $externalSecret = trim((string)(getenv('NJAZ_BINANCE_PAY_API_SECRET') ?: ''));
        $active = $db ?: (($externalKey !== '' && $externalSecret !== '') ? ['api_key' => $externalKey, 'api_secret' => $externalSecret] : []);
        $masked = '';
        if (!empty($active['api_key'])) { $key = (string)$active['api_key']; $masked = strlen($key) <= 8 ? str_repeat('*', strlen($key)) : substr($key, 0, 4) . str_repeat('*', max(4, strlen($key) - 8)) . substr($key, -4); }
        return ['storage_key_ready' => binancePayStorageKey() !== '', 'database_saved' => !empty($db), 'external_ready' => $externalKey !== '' && $externalSecret !== '', 'ready' => !empty($active), 'source' => !empty($db) ? 'database' : (($externalKey !== '' && $externalSecret !== '') ? 'environment' : 'none'), 'api_key_masked' => $masked];
    }
}

if (!function_exists('binancePayLocalConfig')) {
    function binancePayLocalConfig(?PDO $pdo = null): array
    {
        static $loaded = [];
        $cacheKey = $pdo ? 'db' : 'local';
        if (isset($loaded[$cacheKey])) return $loaded[$cacheKey];
        $config = ['api_key' => trim((string)(getenv('NJAZ_BINANCE_PAY_API_KEY') ?: '')), 'api_secret' => trim((string)(getenv('NJAZ_BINANCE_PAY_API_SECRET') ?: '')), 'base_url' => trim((string)(getenv('NJAZ_BINANCE_PAY_BASE_URL') ?: 'https://api.binance.com'))];
        $localPaths = [];
        $external = trim((string)(getenv('NJAZ_BINANCE_PAY_CONFIG_FILE') ?: ''));
        if ($external !== '') $localPaths[] = $external;
        $localPaths[] = __DIR__ . '/binance_pay_local.php';
        foreach ($localPaths as $local) {
            if (!is_file($local) || !is_readable($local)) continue;
            $candidate = require $local;
            if (is_array($candidate)) foreach (['api_key', 'api_secret', 'base_url'] as $key) if (array_key_exists($key, $candidate) && trim((string)$candidate[$key]) !== '') $config[$key] = trim((string)$candidate[$key]);
        }
        if ($pdo) { $db = binancePayDatabaseCredentials($pdo); if ($db) { $config['api_key'] = $db['api_key']; $config['api_secret'] = $db['api_secret']; } }
        return $loaded[$cacheKey] = $config;
    }
}

if (!function_exists('binancePayPublicSettings')) {
    function binancePayPublicSettings(PDO $pdo): array
    {
        $defaults = [
            'id' => 1,
            'enabled' => 0,
            'accepted_currency' => 'USDT',
            'minimum_amount' => '1.0000',
            'request_ttl_minutes' => 30,
            'search_window_seconds' => 1800,
            'time_tolerance_seconds' => 300,
            'expected_order_type' => 'C2C',
            'receiver_identifier_type' => '',
            'receiver_identifier' => '',
            'cashbox_id' => null,
            'icon_image_path' => '',
            'api_base_url' => 'https://api.binance.com',
        ];
        try {
            $row = $pdo->query('SELECT * FROM binance_pay_settings WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $defaults = array_merge($defaults, $row);
        } catch (Throwable $e) {
            // The feature stays disabled until the migration is installed.
        }
        return $defaults;
    }
}

if (!function_exists('binancePayEnsureVisualColumns')) {
    /** Ensure optional public-UI settings exist for both old and new installations. */
    function binancePayEnsureVisualColumns(PDO $pdo): bool
    {
        try {
            $table = $pdo->query("SHOW TABLES LIKE 'binance_pay_settings'")->fetchColumn();
            if (!$table) return false;
            $column = $pdo->query("SHOW COLUMNS FROM binance_pay_settings LIKE 'icon_image_path'")->fetch(PDO::FETCH_ASSOC);
            if (!$column) {
                $pdo->exec("ALTER TABLE binance_pay_settings ADD COLUMN icon_image_path VARCHAR(500) DEFAULT NULL AFTER cashbox_id");
            }
            return true;
        } catch (Throwable $e) {
            error_log('Binance Pay visual column migration error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('binancePayCustomerVisibilitySnapshot')) {
    /**
     * Pure evaluation of the public-render gate. It contains no secrets and is
     * intentionally independent from credential/API health.
     */
    function binancePayCustomerVisibilitySnapshot(array $settings): array
    {
        $enabled = !empty($settings['enabled']);
        $currencyOk = strtoupper(trim((string)($settings['accepted_currency'] ?? ''))) === 'USDT';
        $minimumOk = (float)($settings['minimum_amount'] ?? 0) > 0;
        $orderType = strtoupper(trim((string)($settings['expected_order_type'] ?? '')));
        $orderTypeOk = $orderType === 'C2C';
        $receiverTypePresent = trim((string)($settings['receiver_identifier_type'] ?? '')) !== '';
        $receiverValuePresent = trim((string)($settings['receiver_identifier'] ?? '')) !== '';
        $customerVisible = $enabled && $currencyOk && $minimumOk && $orderTypeOk && $receiverTypePresent && $receiverValuePresent;
        $reasons = [];
        if (!$enabled) $reasons[] = 'disabled';
        if (!$currencyOk) $reasons[] = 'currency';
        if (!$minimumOk) $reasons[] = 'minimum_amount';
        if (!$orderTypeOk) $reasons[] = 'expected_order_type';
        if (!$receiverTypePresent) $reasons[] = 'receiver_identifier_type';
        if (!$receiverValuePresent) $reasons[] = 'receiver_identifier';
        return [
            'enabled' => $enabled,
            'currency_ok' => $currencyOk,
            'minimum_ok' => $minimumOk,
            'order_type' => $orderType,
            'order_type_ok' => $orderTypeOk,
            'receiver_type_present' => $receiverTypePresent,
            'receiver_value_present' => $receiverValuePresent,
            'customer_visible' => $customerVisible,
            'reasons' => $reasons,
        ];
    }
}

if (!function_exists('binancePayCustomerVisible')) {
    /**
     * Public UI gate: show the customer option from the persisted operational
     * settings. Credential/API health is checked when the customer submits a
     * request, so a storage-read problem cannot silently hide an enabled method.
     */
    function binancePayCustomerVisible(PDO $pdo): bool
    {
        return !empty(binancePayCustomerVisibilitySnapshot(binancePayPublicSettings($pdo))['customer_visible']);
    }
}

if (!function_exists('binancePayCustomerVisibilityDiagnostics')) {
    /**
     * Admin-safe snapshot of the exact public-render gate. Never returns credentials
     * or the configured receiver value; it only exposes booleans and the order type.
     */
    function binancePayCustomerVisibilityDiagnostics(PDO $pdo): array
    {
        $diagnostics = binancePayCustomerVisibilitySnapshot(binancePayPublicSettings($pdo));
        $diagnostics['credentials_ready'] = false;
        $diagnostics['feature_ready'] = false;
        try {
            $credentialStatus = binancePayCredentialStatus($pdo);
            $diagnostics['credentials_ready'] = !empty($credentialStatus['ready']);
            $diagnostics['feature_ready'] = binancePayFeatureReady($pdo);
        } catch (Throwable $e) {
            // Keep diagnostics safe and fail closed if the credential store cannot be read.
        }
        return $diagnostics;
    }
}

if (!function_exists('binancePayVerificationWindow')) {
    /**
     * The API lookup may need the same wider history window used by the admin
     * read-only scan, because Binance can expose a transaction a little later
     * than the customer request is created. Acceptance remains restricted to
     * the request-time window returned here as accept_start_ms/accept_end_ms.
     */
    function binancePayVerificationWindow(array $request, array $settings, ?int $now = null): array
    {
        $now = $now ?? time();
        $createdTs = strtotime((string)($request['created_at'] ?? ''));
        if ($createdTs <= 0) $createdTs = $now;
        $tolerance = max(30, min(3600, (int)($settings['time_tolerance_seconds'] ?? 300)));
        $historySeconds = max(60, min(86400, (int)($settings['search_window_seconds'] ?? 1800)));
        $acceptStartMs = max(1, ($createdTs - $tolerance) * 1000);
        $acceptEndMs = ($now + $tolerance) * 1000;
        $historyStartMs = max(1, ($now - $historySeconds) * 1000);
        return [
            'fetch_start_ms' => min($acceptStartMs, $historyStartMs),
            'accept_start_ms' => $acceptStartMs,
            'accept_end_ms' => $acceptEndMs,
            'history_seconds' => $historySeconds,
            'time_tolerance_seconds' => $tolerance,
        ];
    }
}

if (!function_exists('binancePayRetryableRequest')) {
    /**
     * Only a lookup miss may be retried. Rejections caused by amount, type,
     * receiver, expiry, locking or attempt limits remain terminal.
     */
    function binancePayRetryableRequest(array $request): bool
    {
        return (string)($request['status'] ?? '') === 'rejected'
            && (string)($request['safe_error_code'] ?? '') === 'transaction_not_found'
            && trim((string)($request['submitted_transaction_id'] ?? '')) !== '';
    }
}

if (!function_exists('binancePayFeatureReady')) {
    function binancePayFeatureReady(PDO $pdo): bool
    {
        if (!binancePayCustomerVisible($pdo)) return false;
        $credentials = binancePayLocalConfig($pdo);
        return $credentials['api_key'] !== ''
            && $credentials['api_secret'] !== ''
            && binancePayBaseUrl($credentials['base_url']) !== null;
    }
}

if (!function_exists('binancePayBaseUrl')) {
    function binancePayBaseUrl(string $baseUrl): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!$parts || (($parts['scheme'] ?? '') !== 'https')) return null;
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== 'api.binance.com') return null;
        return 'https://api.binance.com';
    }
}

if (!function_exists('binancePayBuildQuery')) {
    function binancePayBuildQuery(array $params): string
    {
        // RFC3986 is the documented percent-encoding form used for signing.
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('binancePayExtractTransactions')) {
    function binancePayExtractTransactions($decoded): array
    {
        if (!is_array($decoded)) return [];
        $isTransaction = static function ($item): bool {
            return is_array($item) && array_key_exists('transactionId', $item);
        };
        $isList = function (array $value): bool {
            if ($value === []) return true;
            return array_keys($value) === range(0, count($value) - 1);
        };
        if ($isList($decoded)) {
            return array_values(array_filter($decoded, $isTransaction));
        }
        foreach (['data', 'rows', 'transactions', 'list'] as $key) {
            if (!array_key_exists($key, $decoded)) continue;
            $candidate = $decoded[$key];
            if (is_array($candidate) && $isList($candidate)) {
                return array_values(array_filter($candidate, $isTransaction));
            }
            if (is_array($candidate)) {
                $nested = binancePayExtractTransactions($candidate);
                if ($nested) return $nested;
            }
        }
        return [];
    }
}

if (!function_exists('binancePayNormalizeReceiverType')) {
    function binancePayNormalizeReceiverType(string $raw): string
    {
        $lookup = strtolower((string)(preg_replace('/[^a-zA-Z]/', '', trim($raw)) ?? ''));
        return [
            'binanceid' => 'binanceId',
            'accountid' => 'accountId',
            'email' => 'email',
            'phone' => 'phoneNumber',
            'phonenumber' => 'phoneNumber',
        ][$lookup] ?? '';
    }
}

if (!function_exists('binancePayNormalizeTransaction')) {
    function binancePayNormalizeTransaction(array $row, bool $responseSuccess = true): array
    {
        $receiver = $row['receiverInfo'] ?? [];
        if (is_string($receiver)) {
            $decodedReceiver = json_decode($receiver, true);
            $receiver = is_array($decodedReceiver) ? $decodedReceiver : [];
        }
        $receiver = is_array($receiver) ? $receiver : [];
        $receiverIdentifier = '';
        $receiverType = '';
        $receiverIdentifierType = '';
        $receiverIdentifiers = [];
        $receiverKeys = [
            'binanceId' => ['binanceId', 'binanceID', 'binance_id'],
            'accountId' => ['accountId', 'accountID', 'account_id'],
            'email' => ['email'],
            'phoneNumber' => ['phoneNumber', 'phone_number', 'phone'],
        ];
        foreach ($receiverKeys as $canonicalType => $keys) {
            foreach ($keys as $key) {
                if (isset($receiver[$key]) && trim((string)$receiver[$key]) !== '') {
                    $receiverIdentifiers[$canonicalType] = trim((string)$receiver[$key]);
                    break;
                }
            }
            if (isset($receiverIdentifiers[$canonicalType]) && $receiverIdentifier === '') {
                $receiverIdentifier = $receiverIdentifiers[$canonicalType];
                $receiverIdentifierType = $canonicalType;
            }
        }
        if (isset($receiver['type'])) $receiverType = trim((string)$receiver['type']);
        $transactionIdentifiers = [];
        $transactionIdentifierKeys = [
            'transactionId', 'transactionID', 'transaction_id',
            'orderId', 'orderID', 'order_id',
        ];
        foreach ($transactionIdentifierKeys as $identifierKey) {
            if (!array_key_exists($identifierKey, $row)) continue;
            $identifierValue = trim((string)$row[$identifierKey]);
            if ($identifierValue !== '' && !in_array($identifierValue, $transactionIdentifiers, true)) {
                $transactionIdentifiers[] = $identifierValue;
            }
        }
        $primaryTransactionId = trim((string)($row['transactionId'] ?? ''));
        if ($primaryTransactionId !== '' && !in_array($primaryTransactionId, $transactionIdentifiers, true)) {
            array_unshift($transactionIdentifiers, $primaryTransactionId);
        }
        return [
            'transaction_id' => $primaryTransactionId,
            'transaction_identifiers' => $transactionIdentifiers,
            'transaction_time_ms' => (int)($row['transactionTime'] ?? 0),
            'amount' => (string)($row['amount'] ?? ''),
            'currency' => strtoupper(trim((string)($row['currency'] ?? ''))),
            'order_type' => strtoupper(trim((string)($row['orderType'] ?? ''))),
            'success' => array_key_exists('success', $row) ? filter_var($row['success'], FILTER_VALIDATE_BOOLEAN) : $responseSuccess,
            'receiver_type' => $receiverType,
            'receiver_identifier_type' => $receiverIdentifierType,
            'receiver_identifier' => $receiverIdentifier,
            'receiver_identifiers' => $receiverIdentifiers,
        ];
    }
}

if (!function_exists('binancePayTransactionIdMatches')) {
    function binancePayTransactionIdMatches(array $transaction, string $submittedId): bool
    {
        $submittedId = trim($submittedId);
        if ($submittedId === '') return false;
        $identifiers = $transaction['transaction_identifiers'] ?? [$transaction['transaction_id'] ?? ''];
        if (!is_array($identifiers)) $identifiers = [$identifiers];
        foreach ($identifiers as $identifier) {
            if (hash_equals((string)$identifier, $submittedId)) return true;
        }
        return false;
    }
}

if (!function_exists('binancePayFetchTransactions')) {
    function binancePayFetchTransactions(PDO $pdo, int $startMs, int $endMs, int $limit = 100): array
    {
        $credentials = binancePayLocalConfig($pdo);
        $baseUrl = binancePayBaseUrl($credentials['base_url']);
        if ($credentials['api_key'] === '' || $credentials['api_secret'] === '' || $baseUrl === null) {
            return ['ok' => false, 'error_code' => 'credentials_unavailable', 'transactions' => []];
        }
        if ($startMs <= 0 || $endMs <= $startMs || ($endMs - $startMs) > (90 * 86400000)) {
            return ['ok' => false, 'error_code' => 'invalid_time_window', 'transactions' => []];
        }
        $limit = max(1, min(100, $limit));
        $params = [
            'startTime' => $startMs,
            'endTime' => $endMs,
            'limit' => $limit,
            'recvWindow' => 5000,
            'timestamp' => (int)round(microtime(true) * 1000),
        ];
        $query = binancePayBuildQuery($params);
        $signature = hash_hmac('sha256', $query, $credentials['api_secret']);
        $url = $baseUrl . NJAZ_BINANCE_PAY_ENDPOINT . '?' . $query . '&signature=' . $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $credentials['api_key'], 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => 1,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $curlError !== '') {
            return ['ok' => false, 'error_code' => 'transport_error', 'transactions' => []];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error_code' => 'invalid_api_response', 'transactions' => []];
        }
        $apiCode = $decoded['code'] ?? null;
        if ($httpCode < 200 || $httpCode >= 300 || ($apiCode !== null && (string)$apiCode !== '200' && (int)$apiCode !== 0)) {
            return ['ok' => false, 'error_code' => 'binance_api_error', 'api_code' => (string)$apiCode, 'transactions' => []];
        }
        $responseSuccess = array_key_exists('success', $decoded)
            ? filter_var($decoded['success'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $rows = [];
        foreach (binancePayExtractTransactions($decoded) as $row) {
            $rows[] = binancePayNormalizeTransaction($row, $responseSuccess);
        }
        return ['ok' => true, 'error_code' => null, 'transactions' => $rows];
    }
}


if (!function_exists('binancePayEnsureSchema')) {
    /**
     * Install only the independent Binance Pay tables from the bundled migration.
     * This is intentionally never called from customer GET requests or API reads.
     */
    function binancePayEnsureSchema(PDO $pdo): array
    {
        $migrationPath = dirname(__DIR__) . '/binance_pay_migration.sql';
        if (!is_file($migrationPath) || !is_readable($migrationPath)) {
            return ['ok' => false, 'error_code' => 'migration_file_missing'];
        }
        $sql = file_get_contents($migrationPath);
        if ($sql === false || trim($sql) === '') {
            return ['ok' => false, 'error_code' => 'migration_file_unreadable'];
        }
        $statements = preg_split('/;\s*(?=(?:--[^\r\n]*\R|CREATE|INSERT|ALTER|UPDATE|DELETE|$))/i', $sql);
        if (!is_array($statements)) return ['ok' => false, 'error_code' => 'migration_parse_failed'];
        try {
            $pdo->beginTransaction();
            foreach ($statements as $statement) {
                $statement = trim((string)$statement);
                if ($statement === '' || preg_match('/^(?:--[^\r\n]*\R\s*)+$/', $statement)) continue;
                $pdo->exec($statement);
            }
            $pdo->commit();
            return ['ok' => true, 'error_code' => null];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Binance Pay migration failed: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'migration_failed'];
        }
    }
}
