<?php
/**
 * Static safety test for per-manual-payment-method currency restrictions.
 * No database connection, HTTP request, upload, balance update, or live payment is performed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException("Missing file: {$relative}");
    return (string)file_get_contents($path);
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$helper = $read('includes/payment_method_currency_helper.php');
$mobile = $read('mobile.php');
$wallet = $read('wallet.php');
$topup = $read('topup.php');
$admin = $read('admin/payments.php');
$adminTab = $read('admin/payments_tabs/tab_methods.php');
$app = $read('sections/app-script-1.php');
$migration = $read('database/migrations/2026_08_24_payment_method_currencies.sql');

$assert(strpos($helper, 'CREATE TABLE IF NOT EXISTS payment_method_currencies') !== false, 'Helper must define the currency mapping table.');
$assert(strpos($helper, 'function paymentMethodCurrencyAllowed') !== false, 'Helper must expose server-side allow check.');
$assert(strpos($admin, 'paymentMethodCurrencySave') !== false, 'Admin save must persist selected currencies.');
$assert(strpos($adminTab, 'name="allowed_currencies[]"') !== false, 'Admin form must expose currency checkboxes.');
$assert(strpos($mobile, 'allowed_currency_codes') !== false, 'Mobile page must serialize allowed currencies.');
$assert(strpos($wallet, 'allowed_currency_codes') !== false, 'Wallet page must serialize allowed currencies.');
$assert(strpos($app, 'applyMethodCurrencyFilter') !== false, 'Main UI must filter currency chips by method.');
$assert(strpos($wallet, 'applyWalletMethodCurrencyFilter') !== false, 'Wallet UI must filter currency chips by method.');
$assert(strpos($topup, 'paymentMethodCurrencyAllowed') !== false, 'Server endpoint must enforce the selected method currency rule.');
$assert(strpos($topup, "payment_mode'] ?? 'manual') !== 'manual'") !== false, 'Receipt endpoint must remain manual-payment-only.');
$assert(strpos($migration, 'payment_method_currencies') !== false, 'Migration file must be included.');
$assert(strpos($topup, 'users.balance') === false, 'Receipt endpoint must not directly update user balance.');
$assert(strpos($topup, 'usdt_deposit_sessions') === false, 'Manual receipt flow must not use legacy USDT sessions.');

fwrite(STDOUT, "PASS: payment method currency restrictions static checks\n");
