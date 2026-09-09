<?php
require_once __DIR__ . '/../includes/binance_pay.php';

function check_true($condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: {$message}\n";
}

$fixture = [
    'code' => '000000',
    'data' => [[
        'transactionId' => 'P_448758114245730304',
        'orderId' => '448758114245730304',
        'transactionTime' => 1760000000000,
        'amount' => '12.3400',
        'currency' => 'USDT',
        'orderType' => 'C2C',
        'receiverInfo' => [
            'binanceId' => '167477033',
            'type' => 'USER',
        ],
    ]],
    'success' => true,
];
$rows = binancePayExtractTransactions($fixture);
check_true(count($rows) === 1, 'extracts nested transaction list');
$normalized = binancePayNormalizeTransaction($rows[0], filter_var($fixture['success'], FILTER_VALIDATE_BOOLEAN));
check_true($normalized['transaction_id'] === 'P_448758114245730304', 'preserves Pay transaction id as string');
check_true(in_array('448758114245730304', $normalized['transaction_identifiers'], true), 'keeps numeric order id as alternate identifier');
check_true(binancePayTransactionIdMatches($normalized, '448758114245730304'), 'matches customer numeric order id');
check_true(binancePayTransactionIdMatches($normalized, 'P_448758114245730304'), 'matches Pay transaction id');
check_true(!binancePayTransactionIdMatches($normalized, '448758114245730305'), 'rejects unrelated identifier');
check_true($normalized['currency'] === 'USDT' && $normalized['order_type'] === 'C2C', 'normalizes C2C and currency');
check_true($normalized['success'] === true, 'inherits top-level response success when row has no success');
check_true($normalized['receiver_identifiers']['binanceId'] === '167477033', 'normalizes receiverInfo Binance ID');
$legacyReceiverRow = [
    'transactionId' => 'legacy-1',
    'receiverInfo' => '{"binance_id":"167477033","phone_number":"+967000000000"}',
    'orderType' => 'C2C',
    'currency' => 'USDT',
];
$legacyNormalized = binancePayNormalizeTransaction($legacyReceiverRow, true);
check_true($legacyNormalized['receiver_identifiers']['binanceId'] === '167477033', 'normalizes alternate Binance ID key');
check_true($legacyNormalized['receiver_identifiers']['phoneNumber'] === '+967000000000', 'normalizes alternate phone key');
check_true(binancePayBaseUrl('https://api.binance.com') === 'https://api.binance.com', 'accepts official HTTPS API host');
check_true(binancePayBaseUrl('http://api.binance.com') === null, 'rejects non-HTTPS API host');
$query = binancePayBuildQuery(['startTime' => 1, 'symbol' => 'USDT/Pay', 'timestamp' => 2]);
check_true(strpos($query, 'symbol=USDT%2FPay') !== false, 'uses RFC3986 query encoding');
check_true(strpos($query, 'secret') === false, 'fixture query contains no secret');
$visibleSettings = [
    'enabled' => 1,
    'accepted_currency' => 'USDT',
    'minimum_amount' => '1.0000',
    'expected_order_type' => 'C2C',
    'receiver_identifier_type' => 'binanceId',
    'receiver_identifier' => '167477033',
];
$visibleSnapshot = binancePayCustomerVisibilitySnapshot($visibleSettings);
check_true($visibleSnapshot['customer_visible'] === true, 'C2C settings make customer option visible');
$legacySettings = $visibleSettings;
$legacySettings['expected_order_type'] = 'PAY';
$legacySnapshot = binancePayCustomerVisibilitySnapshot($legacySettings);
check_true($legacySnapshot['customer_visible'] === false, 'legacy PAY settings hide customer option');
check_true(in_array('expected_order_type', $legacySnapshot['reasons'], true), 'legacy PAY settings expose expected_order_type reason');
$requestWindowFixture = ['created_at' => '2026-08-23 10:00:00'];
$windowSettings = ['search_window_seconds' => 86400, 'time_tolerance_seconds' => 300];
$window = binancePayVerificationWindow($requestWindowFixture, $windowSettings, strtotime('2026-08-23 10:10:00'));
check_true($window['fetch_start_ms'] < $window['accept_start_ms'], 'verification can fetch a wider history window');
check_true($window['accept_start_ms'] < $window['accept_end_ms'], 'verification keeps a bounded acceptance window');
$shortWindow = binancePayVerificationWindow($requestWindowFixture, ['search_window_seconds' => 60, 'time_tolerance_seconds' => 300], strtotime('2026-08-23 10:10:00'));
check_true($shortWindow['fetch_start_ms'] === $shortWindow['accept_start_ms'], 'short history does not widen before request window');
$retryableRequest = [
    'status' => 'rejected',
    'safe_error_code' => 'transaction_not_found',
    'submitted_transaction_id' => '448758114245730304',
];
check_true(binancePayRetryableRequest($retryableRequest) === true, 'allows retry only for transaction_not_found with saved identifier');
$wrongReasonRequest = $retryableRequest;
$wrongReasonRequest['safe_error_code'] = 'amount_mismatch';
check_true(binancePayRetryableRequest($wrongReasonRequest) === false, 'blocks retry after amount mismatch');
$missingIdentifierRequest = $retryableRequest;
$missingIdentifierRequest['submitted_transaction_id'] = '';
check_true(binancePayRetryableRequest($missingIdentifierRequest) === false, 'blocks retry without the original identifier');
echo "All Binance Pay local fixture tests passed.\n";
