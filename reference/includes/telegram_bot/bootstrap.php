<?php
/**
 * Telegram Bot modular bootstrap for Njaz Card.
 * يحافظ على نقطة الدخول القديمة ويحمّل الوحدات الوظيفية بالترتيب.
 */

if (!defined('NJAZ_TG_INCLUDES')) {
    define('NJAZ_TG_INCLUDES', dirname(__DIR__));
}

require_once NJAZ_TG_INCLUDES . '/telegram_internal_auth.php';
// مصدر إعداد العملات لكل وسيلة دفع هو نفس مساعد الموقع، وليس قائمة مستقلة للبوت.
require_once NJAZ_TG_INCLUDES . '/payment_method_currency_helper.php';
require_once NJAZ_TG_INCLUDES . '/binance_pay_deposit_service.php';
require_once NJAZ_TG_INCLUDES . '/usdt_verify_service.php';
require_once NJAZ_TG_INCLUDES . '/device_helper.php';
require_once NJAZ_TG_INCLUDES . '/password_reset_helpers.php';
require_once NJAZ_TG_INCLUDES . '/device_confirm_helpers.php';
require_once NJAZ_TG_INCLUDES . '/notifications.php';
if (file_exists(NJAZ_TG_INCLUDES . '/referral.php')) require_once NJAZ_TG_INCLUDES . '/referral.php';
if (file_exists(NJAZ_TG_INCLUDES . '/rate_limiter.php')) require_once NJAZ_TG_INCLUDES . '/rate_limiter.php';

$njazTgModules = [
    '00_core_tables.php',
    '01_settings_ui.php',
    '02_transport.php',
    '03_state_render.php',
    '04_catalog.php',
    '05_order_flow.php',
    '06_numbers_live.php',
    '07_orders.php',
    '08_payments.php',
    '09_telecom.php',
    '10_support_account.php',
    '12_kyc.php',
    '13_quizzes.php',
    '14_display_currency.php',
    '15_language.php',
    '11_handlers.php',
];
foreach ($njazTgModules as $njazTgModule) {
    require_once __DIR__ . '/' . $njazTgModule;
}
unset($njazTgModules, $njazTgModule);
