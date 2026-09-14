<?php
/**
 * خدمة التحقق المشتركة لإيداعات USDT-BEP20.
 * لا تضيف رصيداً بنفسها؛ الاعتماد المالي يتم حصراً عبر usdt_credit_user_balance().
 */
require_once __DIR__ . '/usdt_deposit.php';

function usdt_verify_txid_format(string $txId): bool {
    return preg_match('/^0x[0-9a-f]{64}$/', strtolower(trim($txId))) === 1;
}

function usdt_verify_txid_error(string $code, string $message): array {
    return ['ok' => false, 'error_code' => $code, 'error' => $message];
}

/**
 * يبحث عن تحويل USDT مطابق للطلب عبر Moralis ثم BSC RPC ثم BSCScan.
 * لا يعتمد على اسم التوكن أو رسالة Telegram، بل على العقد الرسمي والعنوان والمبلغ.
 */
function usdt_lookup_verified_transfer(PDO $pdo, string $txId, array $request): array {
    $txId = strtolower(trim($txId));
    if (!usdt_verify_txid_format($txId)) {
        return usdt_verify_txid_error('invalid_txid', 'txID غير صالح — يبدأ بـ 0x ويكون 66 حرفاً');
    }

    $duplicate = $pdo->prepare('SELECT id FROM usdt_used_transactions WHERE tx_hash=? LIMIT 1');
    $duplicate->execute([$txId]);
    if ($duplicate->fetch()) return usdt_verify_txid_error('duplicate_tx', 'هذا الـ txID مستخدم سابقاً');

    $ourWallet = strtolower(trim((string)getSetting('usdt_wallet_address')));
    $contract = strtolower(USDT_OFFICIAL_CONTRACT);
    $expected = (string)($request['unique_amount'] ?? '0');
    if (!preg_match('/^0x[0-9a-f]{40}$/', $ourWallet) || !usdt_is_positive($expected)) {
        return usdt_verify_txid_error('configuration_error', 'إعدادات محفظة USDT أو مبلغ الطلب غير صالحين');
    }

    $matched = null;

    $moralisKey = trim((string)(getSetting('moralis_api_key') ?: ''));
    if ($moralisKey !== '') {
        $url = 'https://deep-index.moralis.io/api/v2.2/transaction/' . rawurlencode($txId) . '/verbose?chain=bsc';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $moralisKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200 && is_string($body) && $body !== '') {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $rawStatus = $data['receipt_status'] ?? ($data['receipt']['status'] ?? '');
                $receiptStatus = strtolower(trim((string)$rawStatus));
                $gasUsed = $data['receipt_cumulative_gas_used'] ?? 0;
                $receiptOk = in_array($receiptStatus, ['1', '0x1', 'success'], true)
                    || (is_numeric($gasUsed) && (float)$gasUsed > 0);
                if ($receiptOk) {
                    $transfers = $data['erc20Transfers'] ?? $data['erc20_transfers'] ?? [];
                    if (is_array($transfers)) foreach ($transfers as $transfer) {
                        $token = strtolower((string)($transfer['address'] ?? $transfer['contract_address'] ?? ''));
                        $to = strtolower((string)($transfer['to'] ?? $transfer['to_address'] ?? ''));
                        $from = strtolower((string)($transfer['from'] ?? $transfer['from_address'] ?? ''));
                        $rawValue = (string)($transfer['value'] ?? $transfer['value_decimal'] ?? '0');
                        if ($token !== $contract || $to !== $ourWallet) continue;
                        $decimal = strpos($rawValue, '.') !== false ? $rawValue : usdt_raw_to_decimal($rawValue);
                        if (usdt_within_tolerance($decimal, $expected, '0.01')) {
                            $matched = [
                                'tx_hash' => $txId,
                                'amount' => $decimal,
                                'from' => $from,
                                'block_number' => $data['block_number'] ?? null,
                            ];
                            break;
                        }
                    }
                }
            }
        }
    }

    if (!$matched) {
        $rpcUrl = trim((string)(getSetting('bsc_rpc_url') ?: 'https://bsc-dataseed.binance.org'));
        $rpcBody = json_encode(['jsonrpc'=>'2.0','method'=>'eth_getTransactionReceipt','params'=>[$txId],'id'=>1], JSON_UNESCAPED_SLASHES);
        $ch = curl_init($rpcUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rpcBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200 && is_string($body) && $body !== '') {
            $rpc = json_decode($body, true);
            $receipt = is_array($rpc) ? ($rpc['result'] ?? null) : null;
            if (is_array($receipt) && $receipt) {
                $receiptStatus = strtolower((string)($receipt['status'] ?? ''));
                if (in_array($receiptStatus, ['0x0', '0'], true)) {
                    return usdt_verify_txid_error('tx_failed', 'العملية فاشلة على الشبكة');
                }
                if (in_array($receiptStatus, ['0x1', '1'], true)) {
                    $topic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
                    $hexToDecimal = static function (string $hex): string {
                        $hex = preg_replace('/^0x/i', '', trim($hex));
                        if ($hex === '' || !preg_match('/^[0-9a-f]+$/i', $hex)) return '0';
                        $decimal = '0';
                        foreach (str_split(strtolower($hex)) as $digit) {
                            $decimal = bcadd(bcmul($decimal, '16', 0), (string)hexdec($digit), 0);
                        }
                        return $decimal;
                    };
                    foreach (($receipt['logs'] ?? []) as $log) {
                        if (!is_array($log)) continue;
                        $token = strtolower((string)($log['address'] ?? ''));
                        $topics = $log['topics'] ?? [];
                        if ($token !== $contract || !is_array($topics) || count($topics) < 3) continue;
                        if (strtolower((string)$topics[0]) !== $topic) continue;
                        $to = '0x' . substr(strtolower((string)$topics[2]), -40);
                        if ($to !== $ourWallet) continue;
                        $decimal = usdt_raw_to_decimal($hexToDecimal((string)($log['data'] ?? '0x0')));
                        if (!usdt_within_tolerance($decimal, $expected, '0.01')) continue;
                        $matched = [
                            'tx_hash' => $txId,
                            'amount' => $decimal,
                            'from' => '',
                            'block_number' => isset($receipt['blockNumber']) ? hexdec((string)$receipt['blockNumber']) : null,
                        ];
                        break;
                    }
                }
            }
        }
    }

    if (!$matched) {
        $key = trim((string)(getSetting('bscscan_api_key') ?: ''));
        $base = 'https://api.bscscan.com/api';
        $query = static function (array $params) use ($key): string {
            if ($key !== '') $params['apikey'] = $key;
            return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        };
        $ch = curl_init($base . '?' . $query(['module'=>'proxy','action'=>'eth_getTransactionReceipt','txhash'=>$txId]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
        $receiptBody = curl_exec($ch);
        $receiptStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($receiptStatus === 200 && is_string($receiptBody) && $receiptBody !== '') {
            $receiptJson = json_decode($receiptBody, true);
            $receipt = is_array($receiptJson) ? ($receiptJson['result'] ?? null) : null;
            if (is_array($receipt) && !empty($receipt)) {
                if (($receipt['status'] ?? '') !== '0x1') return usdt_verify_txid_error('tx_failed', 'العملية فاشلة على الشبكة');
                $block = hexdec((string)($receipt['blockNumber'] ?? '0x0'));
                $ch = curl_init($base . '?' . $query([
                    'module'=>'account','action'=>'tokentx','contractaddress'=>USDT_OFFICIAL_CONTRACT,
                    'address'=>$ourWallet,'startblock'=>$block,'endblock'=>$block,'sort'=>'desc'
                ]));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
                $txBody = curl_exec($ch);
                $txStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($txStatus === 200 && is_string($txBody) && $txBody !== '') {
                    $txJson = json_decode($txBody, true);
                    $list = is_array($txJson) ? ($txJson['result'] ?? []) : [];
                    if (is_array($list)) foreach ($list as $transfer) {
                        if (!is_array($transfer)) continue;
                        if (strtolower((string)($transfer['hash'] ?? '')) !== $txId) continue;
                        if (strtolower((string)($transfer['to'] ?? '')) !== $ourWallet) continue;
                        $decimals = (int)($transfer['tokenDecimal'] ?? 18);
                        $rawValue = (string)($transfer['value'] ?? '0');
                        $decimal = bcdiv($rawValue, bcpow('10', (string)$decimals, 18), 8);
                        if (!usdt_within_tolerance($decimal, $expected, '0.005')) {
                            return usdt_verify_txid_error('amount_mismatch', 'المبلغ غير مطابق. وجدنا: ' . number_format((float)$decimal, 4) . ' USDT — المطلوب: ' . $expected . ' USDT');
                        }
                        $matched = [
                            'tx_hash'=>$txId,'amount'=>$decimal,
                            'from'=>strtolower((string)($transfer['from'] ?? '')),
                            'block_number'=>$block ?: null,
                        ];
                        break;
                    }
                }
            }
        }
    }

    if (!$matched) return usdt_verify_txid_error('verification_failed', 'تعذّر التحقق. تأكد من صحة الـ txID أو انتظر دقيقتين وحاول مجدداً');
    return ['ok'=>true, 'tx'=>$matched];
}

/**
 * يتحقق من طلب مستخدم محدد ثم يمرر الاعتماد إلى خدمة الرصيد/الصندوق/القيد الذرية.
 */
function usdt_verify_deposit_request(PDO $pdo, int $userId, int $requestId, string $txId): array {
    $txId = strtolower(trim($txId));
    if (!usdt_verify_txid_format($txId)) return usdt_verify_txid_error('invalid_txid', 'txID غير صالح — يبدأ بـ 0x ويكون 66 حرفاً');
    $stmt = $pdo->prepare("SELECT * FROM usdt_deposit_requests WHERE id=? AND user_id=? AND status='pending' AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) return usdt_verify_txid_error('request_not_found', 'لا يوجد طلب نشط أو انتهت صلاحيته');
    $lookup = usdt_lookup_verified_transfer($pdo, $txId, $request);
    if (!($lookup['ok'] ?? false)) return $lookup;
    $credit = usdt_credit_user_balance($pdo, $request, $lookup['tx']);
    if (!($credit['ok'] ?? false)) {
        $map = [
            'REQUEST_ALREADY_PROCESSED'=>'request_already_processed',
            'DUPLICATE_TX_INSIDE_LOCK'=>'duplicate_tx',
            'USER_NOT_FOUND'=>'user_not_found',
        ];
        return ['ok'=>false, 'error_code'=>$map[$credit['error'] ?? ''] ?? 'credit_failed', 'error'=>$credit['error'] ?? 'credit_failed'];
    }
    return ['ok'=>true, 'credited'=>true, 'request'=>$request, 'tx'=>$lookup['tx'], 'amount'=>$lookup['tx']['amount'], 'credit'=>$credit];
}

function usdt_verify_user_error_message(string $code): string {
    return [
        'invalid_txid'=>'txID غير صالح — يبدأ بـ 0x ويكون 66 حرفاً',
        'request_not_found'=>'لا يوجد طلب نشط أو انتهت صلاحيته',
        'duplicate_tx'=>'هذا الـ txID مستخدم سابقاً',
        'tx_failed'=>'العملية فاشلة على الشبكة',
        'amount_mismatch'=>'المبلغ المحول لا يطابق المبلغ الفريد المطلوب لهذا الطلب',
        'user_not_found'=>'حساب غير موجود، تواصل مع الدعم',
        'request_already_processed'=>'هذا الطلب تمت معالجته سابقاً',
        'credit_failed'=>'تم العثور على العملية لكن تعذر تحديث الرصيد بأمان. تواصل مع الدعم',
        'configuration_error'=>'إعدادات USDT غير مكتملة حالياً. تواصل مع الدعم',
    ][$code] ?? 'تعذّر التحقق. تأكد من صحة الـ txID أو انتظر دقيقتين وحاول مجدداً';
}
