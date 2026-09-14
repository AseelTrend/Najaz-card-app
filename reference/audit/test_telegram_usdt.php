<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paymentsSource = (string)file_get_contents($root . '/includes/telegram_bot/08_payments.php');
$handlerSource = (string)file_get_contents($root . '/includes/telegram_bot/11_handlers.php');
$settingsSource = (string)file_get_contents($root . '/includes/telegram_bot/01_settings_ui.php');
$adminSource = (string)file_get_contents($root . '/admin/telegram_bot.php');
$serviceSource = (string)file_get_contents($root . '/includes/usdt_verify_service.php');

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
};

$check(strpos($paymentsSource, "function njazTgUsdtStart") !== false, 'USDT start handler exists');
$check(strpos($paymentsSource, "function njazTgUsdtCreate") !== false, 'USDT request creation handler exists');
$check(strpos($paymentsSource, "function njazTgUsdtVerify") !== false, 'USDT verification handler exists');
$check(strpos($paymentsSource, "usdt_create_deposit_request") !== false, 'Telegram creates requests through the shared USDT service');
$check(strpos($paymentsSource, "usdt_verify_deposit_request") !== false, 'Telegram verifies through the shared atomic verification service');
$check(strpos($paymentsSource, "'callback_data' => 'topup:usdt'") !== false && strpos($paymentsSource, "'ui_scope' => 'topup:usdt'") !== false, 'USDT button has the expected callback and UI scope');
$check(strpos($paymentsSource, 'usdt_deposit_requests') !== false, 'payment history reads the modern USDT request table');
$check(strpos($paymentsSource, "'type' => 'usdt'") !== false, 'payment history exposes the USDT type');
$check(strpos($handlerSource, "'usdt_amount'") !== false && strpos($handlerSource, "'usdt_tx'") !== false, 'Telegram states cover amount and TXID input');
$check(strpos($handlerSource, "'topup:usdt'") !== false, 'Telegram callback starts USDT topup');
$check((bool)preg_match('/payment:view:\(manual\|direct\|binance\|usdt\|card\)/', $handlerSource), 'payment detail callback accepts USDT');
$check((bool)preg_match('/payments:\(all\|manual\|direct\|binance\|usdt\|card/', $handlerSource), 'payment filter callback accepts USDT');
$check(strpos($settingsSource, "'payments:usdt'") !== false && strpos($settingsSource, "'topup:usdt'") !== false, 'USDT menu definitions are registered');
$check(strpos($adminSource, "topup:(?:binance|card|floosak|usdt)") !== false, 'admin payment scope validation accepts USDT');
$check(strpos($adminSource, "renderTgPaymentForm('topup:usdt'") !== false, 'admin renders the USDT payment appearance form');
$check(strpos($serviceSource, 'usdt_credit_user_balance') !== false, 'shared service delegates crediting to the atomic balance/cashbox function');
$check(strpos($paymentsSource, 'usdt_deposit_sessions') === false && strpos($handlerSource, 'usdt_deposit_sessions') === false, 'Telegram does not use the legacy USDT sessions table');
$check(!preg_match('/users\s+SET\s+balance|UPDATE\s+users\s+SET\s+balance/i', $paymentsSource . $handlerSource), 'Telegram does not update users.balance directly');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Telegram USDT test(s) failed.\n");
    exit(1);
}

echo "All Telegram USDT local tests passed.\n";

