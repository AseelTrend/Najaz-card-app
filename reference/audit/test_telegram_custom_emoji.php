<?php
declare(strict_types=1);

$admin = file_get_contents(__DIR__ . '/../admin/telegram_bot.php');
$payments = file_get_contents(__DIR__ . '/../includes/telegram_bot/08_payments.php');
$settings = file_get_contents(__DIR__ . '/../includes/telegram_bot/01_settings_ui.php');
$catalog = file_get_contents(__DIR__ . '/../includes/telegram_bot/04_catalog.php');

$checks = [
    'admin_scope' => strpos($admin, "renderTgPaymentForm('topup:binance'") !== false,
    'admin_field' => strpos($admin, 'payment_icon_custom_emoji_id') !== false,
    'admin_validation' => strpos($admin, "preg_match('/^\\\\d{5,64}$/", 0) !== false || strpos($admin, "preg_match('/^\\d{5,64}$/", 0) !== false,
    'telegram_button_scope' => strpos($payments, "'ui_scope' => 'topup:binance'") !== false,
    'menu_definition' => strpos($settings, "['key'=>'topup:binance'") !== false,
    'menu_mapping' => strpos($settings, "'topup:binance'=>'topup:binance'") !== false,
    'button_custom_emoji_support' => strpos($catalog, "'icon_custom_emoji_id'") !== false,
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    echo "PASS: {$name}\n";
}

echo "Telegram Custom Emoji ID checks passed without DB, Telegram API, or financial operations.\n";
