<?php

/**
 * عملة العرض في Telegram
 *
 * mobile.php يحفظ الاختيار في localStorage لأنه إعداد عرض محلي للمتصفح،
 * ولا يوجد في الموقع حقل عملة داخل users أو API لحفظه. لذلك نستخدم نفس
 * كتالوج display_currencies ونفس معادلة التحويل، ونحفظ اختيار محادثة Telegram
 * داخل state_data الموجود مسبقاً دون إنشاء نظام مالي أو إعداد مستقل.
 */
function njazTgDisplayCurrencies(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT currency_code,currency_name,currency_symbol,rate_from_usd,is_default FROM display_currencies WHERE status=1 ORDER BY sort_order,currency_code");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('Telegram display currencies read failed: ' . $e->getMessage());
        $rows = [];
    }
    if (!$rows) {
        return [[
            'currency_code' => 'USD',
            'currency_name' => 'دولار أمريكي',
            'currency_symbol' => '$',
            'rate_from_usd' => 1,
            'is_default' => 1,
        ]];
    }
    return $rows;
}

function njazTgDisplayCurrency(PDO $pdo, array $tgUser): array {
    $currencies = njazTgDisplayCurrencies($pdo);
    $data = njazTgStateData($tgUser);
    $selected = strtoupper(trim((string)($data['display_currency'] ?? '')));
    $fallback = '';
    foreach ($currencies as $currency) {
        $code = strtoupper(trim((string)($currency['currency_code'] ?? '')));
        if ((int)($currency['is_default'] ?? 0) === 1 && $fallback === '') $fallback = $code;
        if ($code !== '' && $code === $selected) return $currency;
    }
    if ($fallback === '') $fallback = 'USD';
    foreach ($currencies as $currency) {
        if (strtoupper((string)($currency['currency_code'] ?? '')) === $fallback) return $currency;
    }
    return $currencies[0];
}

function njazTgDisplayMoney(PDO $pdo, array $tgUser, $usdValue, bool $includeUsd = false): string {
    $currency = njazTgDisplayCurrency($pdo, $tgUser);
    $code = strtoupper(trim((string)($currency['currency_code'] ?? 'USD')));
    $symbol = trim((string)($currency['currency_symbol'] ?? $code));
    $rate = (float)($currency['rate_from_usd'] ?? 1);
    $value = (float)$usdValue;
    $converted = $code === 'USD' ? $value : $value * ($rate > 0 ? $rate : 1);
    if ($converted == 0.0) {
        $formatted = '0.00';
    } elseif ($converted >= 1000) {
        $formatted = number_format($converted, 0, '.', ',');
    } elseif ($converted >= 1 || $code === 'USD') {
        $formatted = number_format($converted, 2, '.', '');
    } else {
        $formatted = number_format($converted, 4, '.', '');
    }
    $result = $formatted . ' ' . njazTgHtml($symbol !== '' ? $symbol : $code);
    if ($includeUsd && $code !== 'USD') $result .= ' <i>(~' . number_format($value, 2, '.', '') . ' $)</i>';
    return $result;
}

function njazTgDisplayCurrencySave(PDO $pdo, array $tgUser, string $currencyCode): bool {
    $currencyCode = strtoupper(trim($currencyCode));
    $selected = null;
    foreach (njazTgDisplayCurrencies($pdo) as $currency) {
        if (strtoupper((string)($currency['currency_code'] ?? '')) === $currencyCode) {
            $selected = $currency;
            break;
        }
    }
    if (!$selected) return false;
    $data = njazTgStateData($tgUser);
    $data['display_currency'] = $currencyCode;
    try {
        $stmt = $pdo->prepare('UPDATE telegram_users SET state_data=? WHERE telegram_id=?');
        $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE), (int)$tgUser['telegram_id']]);
        return true;
    } catch (Throwable $e) {
        error_log('Telegram display currency save failed: ' . $e->getMessage());
        return false;
    }
}

function njazTgProfileScreen(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_profile')) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'بيانات الحساب غير متاحة عبر البوت حالياً.');
        return;
    }
    $u = getUser((int)$tgUser['user_id']);
    $kyc = njazTgKycCurrent($pdo, (int)$tgUser['user_id']);
    $kycStatus = $kyc ? (string)($kyc['status'] ?? '') : '';
    $kycLabel = $kycStatus === 'pending' ? '⏳ تحقق الهوية — قيد المراجعة' : ($kycStatus === 'approved' ? '✅ تحقق الهوية — موثقة' : '🪪 تحقق الهوية');
    $currency = njazTgDisplayCurrency($pdo, $tgUser);
    $currencyLabel = trim((string)($currency['currency_symbol'] ?? '') . ' ' . (string)($currency['currency_code'] ?? 'USD'));
    $languageLabel = function_exists('njazTgLanguageLabel') ? njazTgLanguageLabel($pdo, $tgUser) : 'العربية';
    $profileKb = [
        [['text' => '💱 عملة العرض: ' . $currencyLabel, 'callback_data' => 'profile:currency']],
        [['text' => '🌐 لغة العرض: ' . $languageLabel, 'callback_data' => 'profile:language']],
        [['text' => '🔐 تغيير كلمة المرور', 'callback_data' => 'profile:password']],
        [['text' => $kycLabel, 'callback_data' => 'profile:kyc:status']],
        [['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
    ];
    $text = '<b>حسابي</b>\nالمستخدم: ' . njazTgHtml($u['username'] ?? '')
        . '\nالرصيد: ' . njazTgDisplayMoney($pdo, $tgUser, $u['balance'] ?? 0);
    njazTgRender($pdo, $tgUser, $text, $profileKb, $messageId);
}

function njazTgDisplayCurrencyPicker(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $selected = strtoupper((string)(njazTgDisplayCurrency($pdo, $tgUser)['currency_code'] ?? 'USD'));
    $buttons = [];
    foreach (njazTgDisplayCurrencies($pdo) as $currency) {
        $code = strtoupper(trim((string)($currency['currency_code'] ?? '')));
        if ($code === '') continue;
        $symbol = trim((string)($currency['currency_symbol'] ?? $code));
        $name = trim((string)($currency['currency_name'] ?? $code));
        $rate = (float)($currency['rate_from_usd'] ?? 1);
        $example = 10 * ($rate > 0 ? $rate : 1);
        $exampleText = $example >= 1000 ? number_format($example, 0, '.', ',') : ($example >= 1 ? number_format($example, 2, '.', '') : number_format($example, 4, '.', ''));
        $mark = $code === $selected ? ' ✅' : '';
        $buttons[] = ['text' => $symbol . ' ' . $code . $mark . '\n' . $name . ' — 10$ ≈ ' . $exampleText . ' ' . $symbol, 'callback_data' => 'profile:currency:' . $code];
    }
    $kb = array_chunk($buttons, 1);
    $kb[] = [['text' => '↩️ حسابي', 'callback_data' => 'menu:profile']];
    $text = '<b>💱 عملة العرض</b>\n\nاختر العملة التي تريد عرض الأسعار والأرصدة بها، كما في صفحة الموبايل.\n\nالاختيار يغيّر العرض فقط، ولا يغيّر رصيدك أو أسعار النظام الأساسية.';
    njazTgRender($pdo, $tgUser, $text, $kb, $messageId);
}

function njazTgDisplayCurrencySelect(PDO $pdo, array $tgUser, string $currencyCode, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgDisplayCurrencySave($pdo, $tgUser, $currencyCode)) {
        njazTgRender($pdo, $tgUser, 'العملة غير متاحة حالياً. اختر إحدى العملات الظاهرة في القائمة.', [[['text' => '↩️ عملة العرض', 'callback_data' => 'profile:currency']]], $messageId);
        return;
    }
    $updated = $tgUser;
    $data = njazTgStateData($tgUser);
    $data['display_currency'] = strtoupper($currencyCode);
    $updated['state_data'] = json_encode($data, JSON_UNESCAPED_UNICODE);
    $currency = njazTgDisplayCurrency($pdo, $updated);
    $name = (string)($currency['currency_name'] ?? $currencyCode);
    njazTgProfileScreen($pdo, $updated, $messageId);
}

function njazTgDisplayCurrencySetContext(PDO $pdo, array $tgUser): void {
    $GLOBALS['njaz_tg_display_currency_context'] = [$pdo, $tgUser];
}

function njazTgDisplayMoneyContext($usdValue, bool $includeUsd = false): string {
    $context = $GLOBALS['njaz_tg_display_currency_context'] ?? null;
    if (is_array($context) && isset($context[0], $context[1]) && $context[0] instanceof PDO && is_array($context[1])) {
        return njazTgDisplayMoney($context[0], $context[1], $usdValue, $includeUsd);
    }
    return njazTgMoney($usdValue);
}

