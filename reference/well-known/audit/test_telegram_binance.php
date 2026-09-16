<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/binance_pay_deposit_service.php';
require_once __DIR__ . '/../includes/telegram_bot/08_payments.php';

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
};

$check(function_exists('binancePayCreateDeposit'), 'shared create service is loaded');
$check(function_exists('binancePayVerifyDeposit'), 'shared verify service is loaded');
$check(function_exists('njazTgBinancePayStart'), 'Telegram Binance start handler is loaded');
$check(function_exists('njazTgBinancePayCreate'), 'Telegram Binance create handler is loaded');
$check(function_exists('njazTgBinancePayVerify'), 'Telegram Binance verify handler is loaded');
$check(binancePayAmount('1.00', 2) === '1', 'accepts a two-decimal display amount');
$check(binancePayAmount('1.001', 2) === null, 'rejects amounts beyond wallet precision');
$check(binancePayAmount('0', 2) === null, 'rejects zero amount');
$check(binancePayDecimalEqual('1.0000', '1'), 'compares decimal amounts canonically');
$check(binancePayDecimalEqual('2.50', '2.5000'), 'ignores trailing decimal zeroes');
$check(!binancePayDecimalEqual('-1', '1'), 'does not treat negative amount as equal');

$matchingTx = ['receiver_identifiers' => ['binanceId' => '123456789']];
$wrongTx = ['receiver_identifiers' => ['binanceId' => '987654321']];
$settings = ['receiver_identifier_type' => 'binanceId', 'receiver_identifier' => '123456789'];
$check(binancePayReceiverMatches($matchingTx, $settings), 'matches the configured Binance receiver');
$check(!binancePayReceiverMatches($wrongTx, $settings), 'rejects an unrelated Binance receiver');

$handlerSource = (string)file_get_contents(__DIR__ . '/../includes/telegram_bot/11_handlers.php');
$paymentsSource = (string)file_get_contents(__DIR__ . '/../includes/telegram_bot/08_payments.php');
$settingsSource = (string)file_get_contents(__DIR__ . '/../includes/telegram_bot/01_settings_ui.php');
$check(strpos($handlerSource, "'topup:binance'") !== false, 'Telegram callback starts Binance topup');
$check(strpos($handlerSource, "'binance:verify'") !== false, 'Telegram callback verifies Binance identifier');
$check(strpos($handlerSource, "'binance_amount'") !== false && strpos($handlerSource, "'binance_transaction'") !== false, 'Telegram states cover amount and identifier input');
$check(strpos($paymentsSource, "binancePayFeatureReady") !== false, 'Binance button is gated by operational readiness');
$check(strpos($paymentsSource, "binance_pay_deposits") !== false, 'Telegram payment history reads the shared Binance table');
$check(strpos($settingsSource, "'topup:binance'") !== false && strpos($settingsSource, "'payments:binance'") !== false, 'new callbacks are present in Telegram settings map');
$check(strpos($handlerSource, 'api_' . 'key') === false && strpos($handlerSource, 'api_' . 'secret') === false, 'Telegram handler does not expose Binance credentials');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Telegram Binance test(s) failed.\n");
    exit(1);
}

echo "All Telegram Binance local tests passed.\n";
