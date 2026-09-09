<?php
/**
 * Static regression test for Telegram payment-method currency visibility.
 * No database connection, HTTP request, webhook call, upload, or live payment is performed.
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

$bootstrap = $read('includes/telegram_bot/bootstrap.php');
$payments = $read('includes/telegram_bot/08_payments.php');
$handlers = $read('includes/telegram_bot/11_handlers.php');
$helper = $read('includes/payment_method_currency_helper.php');

$assert(strpos($bootstrap, "require_once NJAZ_TG_INCLUDES . '/payment_method_currency_helper.php';") !== false,
    'Telegram bootstrap must load the shared payment currency helper.');
$assert(strpos($payments, 'function njazTgTopupRates') !== false,
    'Telegram payments module must provide a method-aware rate loader.');
$assert(strpos($payments, 'paymentMethodCurrencyMap($pdo, [$methodId])') !== false,
    'Telegram rate loader must read the shared method currency map.');
$assert(strpos($payments, 'if (!$allowed) return $rates;') !== false,
    'Telegram rate loader must preserve legacy unrestricted methods when no mapping exists.');
$assert(substr_count($payments, '$rates = njazTgTopupRates($pdo, $methodId);') >= 2,
    'Both Telegram currency screens must load rates through the method-aware loader.');
$assert(strpos($payments, 'Payment details must use the method-aware currency list.') === false,
    'This assertion marker must not be present in production code.');
$assert(substr_count($payments, 'paymentMethodCurrencyAllowed($pdo, $methodId, $currency)') >= 2,
    'Review and create-topup paths must enforce the selected method currency.');
$assert(strpos($handlers, 'paymentMethodCurrencyAllowed($pdo, $methodId, $currency)') !== false,
    'Telegram currency callback must reject a currency outside the method mapping.');
$assert(strpos($helper, 'function paymentMethodCurrencyMap') !== false,
    'The shared helper must remain the source of truth.');
$assert(strpos($payments, "INSERT INTO topup_requests") !== false,
    'The existing top-up request flow must remain present.');

fwrite(STDOUT, "PASS: Telegram payment-method currency visibility static checks\n");
