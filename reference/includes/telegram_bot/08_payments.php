<?php

function njazTgBinancePayErrorMessage(string $code): string {
    return [
        'feature_not_ready' => 'خدمة بينانس مباشر غير جاهزة حالياً. جرّب لاحقاً.',
        'invalid_amount' => 'المبلغ غير صحيح أو أقل من الحد الأدنى المحدد في الموقع.',
        'too_many_pending' => 'لديك ثلاثة طلبات شحن بينانس مفتوحة كحد أقصى. أكمل الطلبات الحالية أو انتظر انتهاءها.',
        'request_create_failed' => 'تعذر إنشاء طلب Binance حالياً. حاول لاحقاً.',
        'request_not_found' => 'طلب Binance غير موجود أو لا يتبع حسابك.',
        'request_expired' => 'انتهت صلاحية طلب Binance. أنشئ طلباً جديداً.',
        'invalid_transaction_id' => 'معرّف المعاملة غير صحيح. أرسله كما يظهر في Binance.',
        'transaction_locked' => 'تم قفل الطلب على معرّف مختلف. أنشئ طلباً جديداً إذا أدخلت المعرّف بالخطأ.',
        'verification_in_progress' => 'جارٍ التحقق من هذا الطلب. انتظر قليلاً ثم راجع مدفوعاتك.',
        'attempt_limit' => 'تم استنفاد محاولات التحقق لهذا الطلب. أنشئ طلباً جديداً.',
        'transaction_not_found' => 'لم تظهر المعاملة في سجل Binance ضمن نافذة التحقق. لم تتم إضافة أي رصيد.',
        'transaction_already_used' => 'رقم المعاملة هذا استُخدم سابقاً في طلب Binance. لا يمكن إعادة شحنه مرة أخرى.',
        'request_lock_failed' => 'تعذر تأمين رقم المعاملة حالياً. لم تتم إضافة أي رصيد. حاول لاحقاً.',
        'transaction_unsuccessful' => 'المعاملة غير ناجحة في Binance. لم تتم إضافة أي رصيد.',
        'wrong_order_type' => 'نوع المعاملة غير مقبول. يقبل النظام تحويل C2C الوارد فقط.',
        'wrong_currency' => 'عملة المعاملة غير مقبولة. يقبل النظام USDT فقط.',
        'amount_mismatch' => 'مبلغ المعاملة لا يطابق مبلغ طلب الشحن. لم تتم إضافة أي رصيد.',
        'time_outside_window' => 'المعاملة خارج نافذة طلب الشحن. لم تتم إضافة أي رصيد.',
        'receiver_mismatch' => 'المستلم في المعاملة لا يطابق حساب Binance المعتمد. لم تتم إضافة أي رصيد.',
        'credentials_unavailable' => 'تعذر الوصول إلى إعدادات Binance حالياً. حاول لاحقاً.',
        'transport_error' => 'تعذر الاتصال بسجل Binance حالياً. لم تتم إضافة أي رصيد.',
        'invalid_api_response' => 'تعذر قراءة رد Binance حالياً. لم تتم إضافة أي رصيد.',
        'binance_api_error' => 'أعاد Binance خطأ أثناء قراءة السجل. حاول لاحقاً.',
        'credit_failed' => 'تم العثور على المعاملة لكن تعذر تحديث الرصيد بأمان. تواصل مع الدعم.',
    ][$code] ?? 'تعذر إكمال التحقق حالياً. لم تتم إضافة أي رصيد.';
}

function njazTgBinancePayReceiverLabel(string $type): string {
    return [
        'binanceId' => 'Binance ID',
        'accountId' => 'Account ID',
        'email' => 'البريد الإلكتروني',
        'phoneNumber' => 'رقم الهاتف',
    ][$type] ?? 'معرّف المستلم';
}

function njazTgBinancePayKeyboard(string $identifier, bool $withVerify = true): array {
    $rows = [];
    $identifier = trim($identifier);
    if ($identifier !== '' && strlen($identifier) <= 256 && !preg_match('/[\\x00-\\x1F\\x7F]/', $identifier)) {
        $rows[] = [['text' => '📋 نسخ Binance ID', 'copy_text' => ['text' => $identifier]]];
    }
    if ($withVerify) {
        $rows[] = [['text' => '🔎 تحقق من المعاملة', 'callback_data' => 'binance:verify']];
    }
    $rows[] = [['text' => 'إلغاء', 'callback_data' => 'topup:cancel']];
    return $rows;
}

function njazTgBinancePayStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.');
        return;
    }
    if (!binancePayFeatureReady($pdo)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'خدمة «بينانس مباشر» غير جاهزة حالياً من إعدادات الموقع.');
        return;
    }
    $settings = binancePayPublicSettings($pdo);
    $minimum = number_format((float)$settings['minimum_amount'], 2, '.', '');
    $ttl = max(5, min(1440, (int)$settings['request_ttl_minutes']));
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'binance_amount', []);
    $text = '<b>◈ شحن مباشر عبر Binance</b>\n\nأرسل المبلغ المطلوب بالدولار الرقمي <code>USDT</code>.\nالحد الأدنى: <b>' . njazTgHtml($minimum) . ' USDT</b>\nصلاحية الطلب بعد إنشائه: <b>' . $ttl . ' دقيقة</b>.\n\nسيظهر لك بعد ذلك معرّف المستلم والمبلغ المطلوب تحويله.';
    $keyboard = [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]];
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

function njazTgBinancePayCreate(PDO $pdo, array $tgUser, string $amountText): void {
    $amount = binancePayAmount(trim($amountText), 2);
    if ($amount === null) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'أرسل مبلغاً صحيحاً بالدولار الرقمي، مثل: <code>1</code> أو <code>2.50</code>.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        return;
    }
    $result = binancePayCreateDeposit($pdo, (int)$tgUser['user_id'], $amount);
    if (!($result['ok'] ?? false)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر إنشاء طلب Binance: ' . njazTgHtml(njazTgBinancePayErrorMessage((string)($result['error_code'] ?? 'request_create_failed'))), [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        return;
    }
    $request = $result['request'] ?? [];
    $instructions = $result['instructions'] ?? [];
    $requestId = (int)($request['id'] ?? 0);
    $identifierType = (string)($instructions['receiver_identifier_type'] ?? '');
    $identifier = (string)($instructions['receiver_identifier'] ?? '');
    $expiresAt = (string)($request['expires_at'] ?? '');
    $data = ['request_id' => $requestId, 'expected_amount' => (string)($request['expected_amount'] ?? $amount), 'expires_at' => $expiresAt, 'receiver_identifier' => $identifier];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'binance_transaction', $data);
    $displayAmount = njazTgHtml((string)($instructions['amount'] ?? $amount));
    $safeRequestId = njazTgHtml((string)$requestId);
    $safeIdentifier = njazTgHtml($identifier);
    $safeExpiresAt = njazTgHtml($expiresAt);
    $text = '<b>◈ طلب Binance #' . $safeRequestId . '</b>\n\n'
        . '<b>المبلغ المطلوب</b>\n'
        . '<code>' . $displayAmount . ' USDT</code>\n\n'
        . '<b>حساب الإيداع</b>\n'
        . 'Binance ID: <code>' . $safeIdentifier . '</code>\n\n'
        . '<b>الخطوة التالية</b>\n'
        . 'بعد إتمام التحويل من حساب Binance الآخر، أرسل هنا معرّف المعاملة/الطلب الموجود في تفاصيل التحويل.\n\n'
        . '<b>تنبيه المطابقة</b>\n'
        . 'لن يُضاف الرصيد إلا بعد مطابقة المبلغ آلياً ومباشرةً وبدون تدخلنا، وكون العملية C2C واردة بعملة USDT، ومطابقة المستلم والوقت.\n\n'
        . '<b>الصلاحية حتى</b>\n'
        . '<code>' . $safeExpiresAt . '</code>';
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, njazTgBinancePayKeyboard($identifier));
}

function njazTgBinancePayVerify(PDO $pdo, array $tgUser, array $data, string $identifier): void {
    $identifier = trim($identifier);
    if ($identifier === '' || strlen($identifier) > 128 || preg_match('/[\\x00-\\x1F\\x7F]/', $identifier)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'أرسل معرّف المعاملة/الطلب كما يظهر في تفاصيل تحويل Binance.', njazTgBinancePayKeyboard((string)($data['receiver_identifier'] ?? '')));
        return;
    }
    $requestId = (int)($data['request_id'] ?? 0);
    $result = binancePayVerifyDeposit($pdo, (int)$tgUser['user_id'], $requestId, $identifier);
    if (($result['ok'] ?? false) && !empty($result['credited'])) {
        $user = getUser((int)$tgUser['user_id']);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        njazTgSend($pdo, (int)$tgUser['chat_id'], '✅ <b>تم اعتماد إيداع Binance بنجاح</b>\nتمت إضافة <b>' . njazTgHtml((string)($result['amount'] ?? $data['expected_amount'] ?? '')) . ' USDT</b> إلى رصيدك.\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>', [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $code = (string)($result['error_code'] ?? 'verification_failed');
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '❌ <b>لم يتم اعتماد إيداع Binance</b>\n' . njazTgHtml(njazTgBinancePayErrorMessage($code)) . '\n\nلم تتم إضافة أي رصيد إلى حسابك.', [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup']]]);
}

function njazTgUsdtFeatureReady(PDO $pdo): bool {
    $wallet = strtolower(trim((string)getSetting('usdt_wallet_address')));
    return (string)getSetting('usdt_enabled') === '1'
        && preg_match('/^0x[0-9a-f]{40}$/', $wallet) === 1
        && (int)(getSetting('usdt_cashbox_id') ?: 0) > 0;
}

function njazTgUsdtKeyboard(array $request): array {
    $wallet = trim((string)($request['wallet_address'] ?? ''));
    $amount = trim((string)($request['unique_amount'] ?? ''));
    $rows = [];
    if ($wallet !== '' && strlen($wallet) <= 256) $rows[] = [['text' => '📋 نسخ عنوان المحفظة', 'copy_text' => ['text' => $wallet]]];
    if ($amount !== '' && strlen($amount) <= 64) $rows[] = [['text' => '📋 نسخ المبلغ', 'copy_text' => ['text' => $amount]]];
    $rows[] = [['text' => 'إلغاء', 'callback_data' => 'topup:cancel']];
    return $rows;
}

function njazTgUsdtInstructions(PDO $pdo, array $tgUser, array $request, bool $askTx = true): void {
    $requestId = njazTgHtml((string)($request['id'] ?? ''));
    $amount = njazTgHtml(rtrim(rtrim((string)($request['unique_amount'] ?? ''), '0'), '.'));
    $wallet = njazTgHtml((string)($request['wallet_address'] ?? ''));
    $expiresAt = njazTgHtml((string)($request['expires_at'] ?? ''));
    $text = '<b>◈ طلب USDT — BEP20 مباشر #' . $requestId . '</b>\n\n'
        . '<b>المبلغ الفريد المطلوب</b>\n<code>' . $amount . ' USDT</code>\n\n'
        . '<b>عنوان الاستقبال</b>\n<code>' . $wallet . '</code>\n\n'
        . '<b>الشبكة</b>\nBNB Smart Chain (BEP20) فقط\n\n'
        . '<b>بعد التحويل</b>\nأرسل هنا TXID الخاص بالمعاملة، وسيتم التحقق منه فوراً على الشبكة. لا ترسل رقم طلب Binance أو أي معرّف آخر.\n\n'
        . '<b>مهم</b>\nلن يُضاف الرصيد إلا بعد مطابقة العقد الرسمي والمستلم والمبلغ ومنع استخدام TXID سابقاً.\n\n'
        . '<b>الصلاحية حتى</b>\n<code>' . $expiresAt . '</code>';
    $keyboard = njazTgUsdtKeyboard($request);
    njazTgState($pdo, (int)$tgUser['telegram_id'], $askTx ? 'usdt_tx' : 'usdt_tx', [
        'request_id' => (int)($request['id'] ?? 0),
        'expected_amount' => (string)($request['unique_amount'] ?? ''),
        'expires_at' => (string)($request['expires_at'] ?? ''),
    ]);
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

function njazTgUsdtStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.');
        return;
    }
    if (!njazTgUsdtFeatureReady($pdo)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'خدمة USDT — BEP20 غير جاهزة حالياً من إعدادات الموقع.');
        return;
    }
    usdt_expire_old_requests($pdo);
    $active = usdt_get_active_request($pdo, (int)$tgUser['user_id']);
    if ($active) {
        njazTgUsdtInstructions($pdo, $tgUser, $active);
        return;
    }
    $min = number_format((float)(getSetting('usdt_min_deposit') ?: '1.00'), 2, '.', '');
    $ttl = max(5, min(1440, (int)(getSetting('usdt_request_ttl') ?: 30)));
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'usdt_amount', []);
    $text = '<b>₮ شحن USDT — BEP20 مباشر</b>\n\nأرسل المبلغ المطلوب إيداعه بعملة <code>USDT</code>.\nالحد الأدنى: <b>' . njazTgHtml($min) . ' USDT</b>\nصلاحية الطلب بعد إنشائه: <b>' . $ttl . ' دقيقة</b>.';
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
}

function njazTgUsdtCreate(PDO $pdo, array $tgUser, string $amountText): void {
    $normalized = str_replace(',', '.', trim($amountText));
    if (!preg_match('/^\\d+(?:\\.\\d{1,8})?$/', $normalized) || (float)$normalized <= 0 || (float)$normalized > 1000000) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'أرسل مبلغاً صحيحاً بعملة USDT، مثل: <code>1</code> أو <code>2.50</code>.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        return;
    }
    usdt_expire_old_requests($pdo);
    $active = usdt_get_active_request($pdo, (int)$tgUser['user_id']);
    if ($active) {
        njazTgUsdtInstructions($pdo, $tgUser, $active);
        return;
    }
    $ttl = max(5, min(1440, (int)(getSetting('usdt_request_ttl') ?: 30)));
    $result = usdt_create_deposit_request($pdo, (int)$tgUser['user_id'], (float)$normalized, $ttl);
    if (!($result['ok'] ?? false)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر إنشاء طلب USDT: ' . njazTgHtml((string)($result['error'] ?? 'حاول لاحقاً')), [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        return;
    }
    njazTgUsdtInstructions($pdo, $tgUser, (array)($result['request'] ?? []));
}

function njazTgUsdtVerify(PDO $pdo, array $tgUser, array $data, string $txId): void {
    $txId = strtolower(trim($txId));
    if (!usdt_verify_txid_format($txId)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'أرسل TXID صحيحاً يبدأ بـ <code>0x</code> ويتكون من 66 حرفاً، مثلما يظهر في مستكشف الشبكة.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        return;
    }
    $requestId = (int)($data['request_id'] ?? 0);
    $result = usdt_verify_deposit_request($pdo, (int)$tgUser['user_id'], $requestId, $txId);
    if (($result['ok'] ?? false) && !empty($result['credited'])) {
        $user = getUser((int)$tgUser['user_id']);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        njazTgSend($pdo, (int)$tgUser['chat_id'], '✅ <b>تم اعتماد إيداع USDT بنجاح</b>\n\nتمت إضافة <b>' . njazTgHtml((string)($result['amount'] ?? '')) . ' USDT</b> إلى رصيدك.\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>', [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $code = (string)($result['error_code'] ?? 'verification_failed');
    $message = usdt_verify_user_error_message($code);
    if ($code === 'amount_mismatch' && !empty($result['error'])) $message = (string)$result['error'];
    if (in_array($code, ['request_not_found', 'request_already_processed'], true)) njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '❌ <b>لم يتم اعتماد إيداع USDT</b>\n\n' . njazTgHtml($message) . '\n\nلم تتم إضافة أي رصيد.', [[['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgTopupMethods(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_topup')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.'); return; }
    $user = getUser((int)$tgUser['user_id']);
    $methods = $pdo->query("SELECT * FROM payment_methods WHERE status=1 ORDER BY sort_order,id")->fetchAll();
    $manual = $auto = [];
    foreach ($methods as $m) {
        if (($m['payment_mode'] ?? 'manual') === 'auto') $auto[] = $m; else $manual[] = $m;
    }
    $kb = [];
    if ($manual) {
        $kb[] = [['text' => '🏦 طرق الدفع اليدوية', 'callback_data' => 'topup:manual']];
        $buttons = [];
        foreach ($manual as $m) {
            $methodName = (string)$m['name'];
            if (function_exists('njazTgTranslateEntityText')) $methodName = njazTgTranslateEntityText($pdo, $methodName, njazTgLanguageCode($pdo, $tgUser), 'entity:payment_method:' . (int)$m['id'] . ':name');
            $buttons[] = ['text' => ($m['icon'] ? '💳 ' : '') . $methodName, 'callback_data' => 'pm:' . (int)$m['id']];
        }
        foreach (array_chunk($buttons, 2) as $row) $kb[] = $row;
    }
    if ($auto) {
        $kb[] = [['text' => '⚡ طرق الدفع المباشرة', 'callback_data' => 'topup:auto']];
        $buttons = [];
        foreach ($auto as $m) {
            $methodName = (string)$m['name'];
            if (function_exists('njazTgTranslateEntityText')) $methodName = njazTgTranslateEntityText($pdo, $methodName, njazTgLanguageCode($pdo, $tgUser), 'entity:payment_method:' . (int)$m['id'] . ':name');
            $buttons[] = ['text' => '⚡ ' . $methodName, 'callback_data' => 'pm:' . (int)$m['id']];
        }
        foreach (array_chunk($buttons, 2) as $row) $kb[] = $row;
    }
    if (njazTgUsdtFeatureReady($pdo)) $kb[] = [['text' => '₮ USDT — BEP20 مباشر', 'callback_data' => 'topup:usdt', 'ui_scope' => 'topup:usdt']];
    if (binancePayFeatureReady($pdo)) $kb[] = [['text' => '◈ بينانس مباشر', 'callback_data' => 'topup:binance', 'ui_scope' => 'topup:binance']];
    $kb[] = [['text' => '🎫 شحن بكود البطاقة', 'callback_data' => 'topup:card']];
    if (getSetting('floosak_enabled') === '1' && trim((string)getSetting('floosak_merchant_key')) !== '') $kb[] = [['text' => '💳 فلوسك — دفع مباشر', 'callback_data' => 'topup:floosak']];
    $kb[] = [['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']];
    $text = '<b>💳 شحن الرصيد</b>\n\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>\n\nاختر طريقة الدفع كما تظهر في صفحة الموبايل. ستظهر تعليمات الطريقة ثم تختار العملة والمبلغ.';
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_method');
}

function njazTgTopupRates(PDO $pdo, int $methodId = 0): array {
    $rates = $pdo->query("SELECT * FROM exchange_rates WHERE status=1 ORDER BY sort_order,currency_code")->fetchAll(PDO::FETCH_ASSOC);
    if ($methodId <= 0) return $rates;
    try {
        $map = paymentMethodCurrencyMap($pdo, [$methodId]);
        $allowed = [];
        foreach ((array)($map[$methodId] ?? []) as $code) $allowed[strtoupper(trim((string)$code))] = true;
        // نفس سلوك الموقع: عدم وجود صفوف يعني أن الوسيلة قديمة وغير مقيّدة.
        if (!$allowed) return $rates;
        return array_values(array_filter($rates, static function (array $rate) use ($allowed): bool {
            $code = strtoupper(trim((string)($rate['currency_code'] ?? '')));
            return $code !== '' && isset($allowed[$code]);
        }));
    } catch (Throwable $e) {
        error_log('Telegram topup currency map read failed: ' . $e->getMessage());
        return [];
    }
}

function njazTgTopupCurrency(PDO $pdo, array $tgUser, array $data): void {
    $methodId = (int)($data['method_id'] ?? 0);
    $rates = njazTgTopupRates($pdo, $methodId);
    $kb = [];
    foreach ($rates as $r) {
        $label = trim(($r['currency_symbol'] ?? '') . ' ' . ($r['currency_code'] ?? ''));
        $kb[] = ['text' => $label !== '' ? $label : (string)$r['currency_code'], 'callback_data' => 'cur:' . strtoupper((string)$r['currency_code'])];
    }
    $rows = array_chunk($kb, 2);
    $rows[] = [['text' => 'إلغاء', 'callback_data' => 'topup:cancel']];
    $data['amount'] = null;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_currency', $data);
    $user = getUser((int)$tgUser['user_id']);
    $text = '<b>حساب المبلغ</b>\n\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>\nاختر العملة التي أودعت بها المبلغ:';
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $rows);
}

function njazTgPaymentDetails(PDO $pdo, array $tgUser, int $methodId): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.'); return; }
    $m = $pdo->prepare("SELECT * FROM payment_methods WHERE id=? AND status=1 LIMIT 1"); $m->execute([$methodId]); $method = $m->fetch();
    if (!$method) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'طريقة الدفع غير متاحة.'); return; }
    $f = $pdo->prepare("SELECT * FROM payment_method_fields WHERE method_id=? ORDER BY sort_order,id"); $f->execute([$methodId]); $fields = $f->fetchAll();
    $language = function_exists('njazTgLanguageCode') ? njazTgLanguageCode($pdo, $tgUser) : 'ar';
    $methodName = (string)$method['name'];
    $methodDescription = (string)($method['description'] ?? '');
    if (function_exists('njazTgTranslateEntityText')) {
        $methodName = njazTgTranslateEntityText($pdo, $methodName, $language, 'entity:payment_method:' . $methodId . ':name');
        if ($methodDescription !== '') $methodDescription = njazTgTranslateEntityText($pdo, $methodDescription, $language, 'entity:payment_method:' . $methodId . ':description');
    }
    $text = '<b>' . (($method['payment_mode'] ?? 'manual') === 'auto' ? '⚡' : '🏦') . ' ' . njazTgHtml($methodName) . '</b>';
    if ($methodDescription !== '') $text .= '\n\n' . njazTgHtml($methodDescription);
    if ($fields) {
        $text .= '\n\n<b>بيانات التحويل</b>';
        foreach ($fields as $row) {
            $value = njazTgHtml((string)($row['field_value'] ?? ''));
            $copy = (int)($row['copyable'] ?? 0) === 1 ? ' 📋' : '';
            $fieldLabel = (string)($row['field_label'] ?? 'بيان');
            if (function_exists('njazTgTranslateEntityText')) $fieldLabel = njazTgTranslateEntityText($pdo, $fieldLabel, $language, 'entity:payment_field:' . (int)($row['id'] ?? 0) . ':label');
            $text .= '\n' . njazTgHtml($fieldLabel) . ': <code>' . $value . '</code>' . $copy;
        }
    }
    $text .= '\n\nاختر العملة التي أودعت بها المبلغ.';
    $data = ['method_id' => $methodId, 'method_name' => (string)$method['name'], 'payment_mode' => (string)($method['payment_mode'] ?? 'manual')];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_currency', $data);
    $rates = njazTgTopupRates($pdo, $methodId);
    $rateButtons = [];
    foreach ($rates as $r) {
        $code = strtoupper(trim((string)($r['currency_code'] ?? '')));
        if ($code === '') continue;
        $label = trim((string)($r['currency_symbol'] ?? '') . ' ' . $code);
        $rateButtons[] = ['text' => $label !== '' ? $label : $code, 'callback_data' => 'cur:' . $code];
    }
    $kb = array_chunk($rateButtons, 2);
    if (!$rateButtons) $text .= '\n\nلا توجد عملات مفعّلة لهذه الوسيلة حالياً.';
    $kb[] = [['text' => '↩️ طرق الدفع', 'callback_data' => 'menu:topup']];
    if (!empty($method['image'])) {
        $sent = njazTgSendPhoto($pdo, (int)$tgUser['chat_id'], (string)$method['image'], $text, $kb);
        if (!($sent['ok'] ?? false)) njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
    } else {
        njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
    }
}

function njazTgTopupReview(PDO $pdo, array $tgUser, array $data): void {
    $currency = strtoupper(trim((string)($data['currency'] ?? '')));
    $amount = (float)($data['amount'] ?? 0);
    if ($currency === '' || $amount <= 0) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'أدخل مبلغاً صحيحاً بعد اختيار العملة.'); return; }
    $methodId = (int)($data['method_id'] ?? 0);
    if ($methodId > 0 && !paymentMethodCurrencyAllowed($pdo, $methodId, $currency)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه العملة غير مفعّلة لوسيلة الدفع المختارة. اختر إحدى العملات الظاهرة فقط.');
        return;
    }
    $rateStmt = $pdo->prepare("SELECT currency_name,currency_symbol,rate_to_usd FROM exchange_rates WHERE currency_code=? AND status=1 LIMIT 1");
    $rateStmt->execute([$currency]); $rate = $rateStmt->fetch();
    if (!$rate || (float)$rate['rate_to_usd'] <= 0) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'العملة غير متاحة حالياً. اختر عملة أخرى.'); return; }
    $usd = round($amount * (float)$rate['rate_to_usd'], 8);
    $data['currency'] = $currency; $data['amount'] = $amount; $data['amount_usd_preview'] = $usd;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_receipt', $data);
    $symbol = (string)($rate['currency_symbol'] ?? $currency);
    $methodName = (string)($data['method_name'] ?? 'طريقة الدفع');
    if (!empty($data['method_id']) && function_exists('njazTgTranslateEntityText')) $methodName = njazTgTranslateEntityText($pdo, $methodName, njazTgLanguageCode($pdo, $tgUser), 'entity:payment_method:' . (int)$data['method_id'] . ':name');
    $text = '<b>تأكيد بيانات الشحن</b>\n\nالطريقة: <b>' . njazTgHtml($methodName) . '</b>'
        . '\nالمبلغ المرسل: <b>' . njazTgHtml(number_format($amount, 8, '.', '')) . ' ' . njazTgHtml($symbol) . '</b>'
        . '\nالمبلغ الذي سيُحتسب: <b>' . njazTgMoney($usd) . '</b>'
        . '\n\nأرسل صورة الإيصال الآن، أو أرسل <code>/skip</code> إذا لم يتوفر لديك إيصال.';
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
}

function njazTgTopupCardStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.'); return; }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_card');
    njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>🎫 شحن بكود البطاقة</b>\n\nأرسل كود البطاقة كما هو، ويمكنك استخدام الشرطات أو بدونها.\nمثال: <code>XXXX-XXXX-XXXX</code>', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
}

function njazTgRedeemCard(PDO $pdo, array $tgUser, string $code): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.'); return; }
    $code = strtoupper(trim($code));
    if (strlen(preg_replace('/[-\\s]/', '', $code)) < 4) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الكود قصير جداً. أرسله مرة أخرى أو اضغط إلغاء.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/redeem_card.php', ['code' => $code]);
    $ok = (bool)($result['ok'] ?? $result['status'] ?? false);
    if ($ok) {
        $user = getUser((int)$tgUser['user_id']);
        $text = '✅ <b>تم شحن البطاقة بنجاح</b>\n' . njazTgHtml($result['message'] ?? '') . '\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? ($result['new_balance'] ?? 0)) . '</b>';
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
    } else {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر شحن البطاقة: ' . njazTgHtml($result['error'] ?? $result['message'] ?? 'خطأ غير معروف') . '\nأرسل كوداً آخر أو اضغط إلغاء.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
    }
}

function njazTgDownloadReceipt(PDO $pdo, string $fileId, int $userId): ?string {
    $file = njazTgApi($pdo, 'getFile', ['file_id' => $fileId]);
    $filePath = $file['result']['file_path'] ?? ''; if ($filePath === '') return null;
    $settings = njazTgSettings($pdo); $token = trim((string)$settings['bot_token']);
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)); if (!in_array($ext, ['jpg','jpeg','png','webp','gif','pdf'], true)) $ext = 'jpg';
    $dir = dirname(NJAZ_TG_INCLUDES) . '/assets/uploads/receipts/'; if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'receipt_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext; $full = $dir . $name;
    $ch = curl_init('https://api.telegram.org/file/bot' . rawurlencode($token) . '/' . $filePath);
    $fp = fopen($full, 'wb'); curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]); $ok = curl_exec($ch); curl_close($ch); fclose($fp);
    if (!$ok || !is_file($full) || filesize($full) > 5 * 1024 * 1024) { @unlink($full); return null; }
    return 'assets/uploads/receipts/' . $name;
}

function njazTgCreateTopup(PDO $pdo, array $tgUser, array $data, ?string $receiptPath = null, string $notes = ''): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_topup')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'شحن الرصيد غير متاح عبر البوت حالياً.'); return; }
    $methodId = (int)($data['method_id'] ?? 0); $currency = strtoupper(trim((string)($data['currency'] ?? ''))); $amount = (float)($data['amount'] ?? 0);
    if ($methodId <= 0 || $amount <= 0 || $currency === '') { njazTgSend($pdo, (int)$tgUser['chat_id'], 'بيانات الشحن غير مكتملة. ابدأ العملية من جديد.'); return; }
    $methodStmt = $pdo->prepare("SELECT name,payment_mode FROM payment_methods WHERE id=? AND status=1 LIMIT 1"); $methodStmt->execute([$methodId]); $method = $methodStmt->fetch();
    if (!paymentMethodCurrencyAllowed($pdo, $methodId, $currency)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه العملة غير مفعّلة لوسيلة الدفع المختارة. ابدأ من جديد واختر إحدى العملات الظاهرة.');
        return;
    }
    $rateStmt = $pdo->prepare("SELECT rate_to_usd FROM exchange_rates WHERE currency_code=? AND status=1 LIMIT 1"); $rateStmt->execute([$currency]); $rate = (float)$rateStmt->fetchColumn();
    if (!$method || $rate <= 0) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'طريقة الدفع أو العملة غير متاحة حالياً.'); return; }
    $usd = round($amount * $rate, 8);
    try {
        $pdo->prepare("INSERT INTO topup_requests (user_id,method_id,amount_sent,currency_code,amount_usd,receipt_image,notes) VALUES (?,?,?,?,?,?,?)")
            ->execute([(int)$tgUser['user_id'], $methodId, $amount, $currency, $usd, $receiptPath, mb_substr($notes, 0, 1000)]);
    } catch (Throwable $e) {
        error_log('Telegram topup insert failed: ' . $e->getMessage());
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر حفظ طلب الشحن حالياً. حاول مرة أخرى لاحقاً.'); return;
    }
    $text = njazTgScreen('✅', 'تم إرسال طلب الشحن',
        '<b>الطريقة</b>\n' . njazTgHtml($method['name'])
        . '\n\n<b>المبلغ المحتسب</b>\n' . njazTgMoney($usd)
        . '\n<b>الحالة</b>\n⏳ قيد المراجعة\n\nسيتم تحديث رصيدك بعد اعتماد الطلب.');
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
}

function njazTgNormalizeYemenPhone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
    if (str_starts_with($digits, '967')) $digits = substr($digits, 3);
    $digits = ltrim($digits, '0');
    return (preg_match('/^7[0-9]{8}$/', $digits)) ? '967' . $digits : '';
}

function njazTgFloosakStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || getSetting('floosak_enabled') !== '1' || trim((string)getSetting('floosak_merchant_key')) === '') { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الدفع المباشر عبر فلوسك غير متاح حالياً.'); return; }
    $user = getUser((int)$tgUser['user_id']);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'floosak_amount', ['phone' => njazTgNormalizeYemenPhone((string)($user['phone'] ?? ''))]);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>💳 شحن مباشر عبر فلوسك</b>\n\nأرسل المبلغ بالريال اليمني. الحد الأدنى <b>100 ريال</b>.\nسيصلك رمز تحقق على هاتفك في فلوسك.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
}

function njazTgFloosakInitiate(PDO $pdo, array $tgUser, array $data, string $amountText): void {
    $amount = (float)$amountText; $phone = njazTgNormalizeYemenPhone((string)($data['phone'] ?? ''));
    if ($amount < 100) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الحد الأدنى للشحن عبر فلوسك هو 100 ريال. أرسل المبلغ مرة أخرى.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    if ($phone === '') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'floosak_phone', ['amount' => $amount]); njazTgSend($pdo, (int)$tgUser['chat_id'], 'أرسل رقم هاتفك المحلي في فلوسك بدون مفتاح الدولة، مثال: <code>771234567</code>\nسيضيف النظام <code>967</code> تلقائياً.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/floosak_payment.php', ['action' => 'floosak_initiate', 'amount' => $amount, 'phone' => $phone]);
    if (!($result['status'] ?? false)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر بدء الدفع المباشر: ' . njazTgHtml($result['message'] ?? 'حاول لاحقاً'), [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    $data['amount'] = $amount; $data['phone'] = $phone; $data['purchase_id'] = (int)($result['purchase_id'] ?? 0);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'floosak_otp', $data);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '✅ ' . njazTgHtml($result['message'] ?? 'تم إرسال رمز التحقق') . '\n\nأرسل رمز OTP المكوّن من 6 أرقام خلال 10 دقائق.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
}

function njazTgFloosakConfirm(PDO $pdo, array $tgUser, array $data, string $otp): void {
    $otp = preg_replace('/\s+/', '', $otp);
    if (!preg_match('/^\d{6}$/', $otp)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'رمز OTP يجب أن يتكون من 6 أرقام. أرسله مرة أخرى.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/floosak_payment.php', ['action' => 'floosak_confirm', 'purchase_id' => (int)($data['purchase_id'] ?? 0), 'otp' => $otp]);
    if (!($result['status'] ?? false)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر تأكيد الدفع: ' . njazTgHtml($result['message'] ?? 'رمز غير صحيح أو منتهي'), [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    $user = getUser((int)$tgUser['user_id']);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    njazTgSend($pdo, (int)$tgUser['chat_id'], '✅ <b>تم الدفع المباشر بنجاح</b>\n' . njazTgHtml($result['message'] ?? '') . '\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? ($result['new_balance'] ?? 0)) . '</b>', [[['text' => '🧾 مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgPaymentStatus(string $type, string $status): array {
    if ($type === 'card') return ['label' => 'مشحون', 'icon' => '✅'];
    if ($type === 'binance') {
        return [
            'credited' => ['label' => 'مقبول ومضاف للرصيد', 'icon' => '✅'],
            'verified' => ['label' => 'تم التحقق', 'icon' => '🔎'],
            'pending' => ['label' => 'بانتظار التحقق', 'icon' => '⏳'],
            'verifying' => ['label' => 'جارٍ التحقق', 'icon' => '🔄'],
            'rejected' => ['label' => 'مرفوض', 'icon' => '❌'],
            'expired' => ['label' => 'منتهي', 'icon' => '🚫'],
            'error' => ['label' => 'تعذر التحقق', 'icon' => '⚠️'],
        ][$status] ?? ['label' => 'غير معروف', 'icon' => 'ℹ️'];
    }
    if ($type === 'usdt') {
        return [
            'completed' => ['label' => 'مقبول ومضاف للرصيد', 'icon' => '✅'],
            'pending' => ['label' => 'بانتظار التحويل والتحقق', 'icon' => '⏳'],
            'expired' => ['label' => 'منتهي', 'icon' => '🚫'],
            'rejected' => ['label' => 'مرفوض', 'icon' => '❌'],
        ][$status] ?? ['label' => 'غير معروف', 'icon' => 'ℹ️'];
    }
    if ($type === 'direct') {
        return [
            'completed' => ['label' => 'مكتملة', 'icon' => '✅'],
            'pending' => ['label' => 'انتظار', 'icon' => '⏳'],
            'failed' => ['label' => 'فشل', 'icon' => '❌'],
            'expired' => ['label' => 'ملغية', 'icon' => '🚫'],
        ][$status] ?? ['label' => 'غير معروف', 'icon' => 'ℹ️'];
    }
    return [
        'approved' => ['label' => 'مقبول', 'icon' => '✅'],
        'pending' => ['label' => 'قيد المراجعة', 'icon' => '⏳'],
        'rejected' => ['label' => 'مرفوض', 'icon' => '❌'],
    ][$status] ?? ['label' => 'غير معروف', 'icon' => 'ℹ️'];
}

function njazTgPayments(PDO $pdo, array $tgUser, ?int $messageId = null, string $filter = 'all'): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (function_exists('binancePayExpireUnsubmittedDeposits')) binancePayExpireUnsubmittedDeposits($pdo);
    if (!njazTgOperationEnabled($pdo, 'allow_topup') && !njazTgOperationEnabled($pdo, 'allow_balance')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'قسم مدفوعاتي غير متاح عبر البوت حالياً.'); return; }
    $uid = (int)$tgUser['user_id']; $payments = [];
    try {
        $s = $pdo->prepare("SELECT r.*,m.name method_name FROM topup_requests r LEFT JOIN payment_methods m ON m.id=r.method_id WHERE r.user_id=? ORDER BY r.created_at DESC LIMIT 50");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $payments[] = ['id' => (int)$row['id'], 'type' => 'manual', 'method' => $row['method_name'] ?: 'طريقة دفع', 'status' => (string)($row['status'] ?? 'pending'), 'amount_usd' => (float)($row['amount_usd'] ?? 0), 'sub' => number_format((float)($row['amount_sent'] ?? 0), 8, '.', '') . ' ' . ($row['currency_code'] ?? ''), 'date' => $row['created_at'] ?? '', 'ref' => 'طلب #' . (int)$row['id'], 'note' => (string)($row['admin_notes'] ?? $row['notes'] ?? ''), 'receipt' => !empty($row['receipt_image'])];
        }
    } catch (Throwable $e) { error_log('Telegram manual payments read failed: ' . $e->getMessage()); }
    try {
        $s = $pdo->prepare("SELECT * FROM floosak_topup_sessions WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $status = (string)($row['status'] ?? 'pending');
            if ($status === 'pending' && !empty($row['created_at']) && strtotime($row['created_at']) < time() - 600) $status = 'expired';
            $payments[] = ['id' => (int)$row['id'], 'type' => 'direct', 'method' => 'فلوسك 🔵', 'status' => $status, 'amount_usd' => (float)($row['amount_usd'] ?? 0), 'sub' => number_format((float)($row['amount_yer'] ?? 0), 2, '.', '') . ' ريال', 'date' => $row['created_at'] ?? '', 'ref' => (string)($row['reference_id'] ?? ''), 'note' => (string)($row['phone'] ?? ''), 'receipt' => false];
        }
    } catch (Throwable $e) { /* قد لا تكون خدمة فلوسك منشأة في كل التثبيتات */ }
    try {
        $s = $pdo->prepare("SELECT * FROM binance_pay_deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $payments[] = ['id' => (int)$row['id'], 'type' => 'binance', 'method' => '◈ Binance مباشر', 'status' => (string)($row['status'] ?? 'pending'), 'amount_usd' => (float)($row['credit_amount'] ?? $row['expected_amount'] ?? 0), 'sub' => number_format((float)($row['expected_amount'] ?? 0), 4, '.', '') . ' USDT', 'date' => $row['created_at'] ?? '', 'ref' => !empty($row['submitted_transaction_id']) ? (string)$row['submitted_transaction_id'] : 'طلب #' . (int)$row['id'], 'note' => (string)($row['safe_error_code'] ?? ''), 'receipt' => false];
        }
    } catch (Throwable $e) { /* قد لا تكون مهاجرة Binance موجودة في بعض التثبيتات */ }
    try {
        $s = $pdo->prepare("SELECT * FROM usdt_deposit_requests WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $status = (string)($row['status'] ?? 'pending');
            $amount = (string)($row['credited_amount'] ?? $row['unique_amount'] ?? $row['base_amount'] ?? '0');
            $txHash = trim((string)($row['tx_hash'] ?? ''));
            $payments[] = ['id' => (int)$row['id'], 'type' => 'usdt', 'method' => '₮ USDT — BEP20 مباشر', 'status' => $status, 'amount_usd' => (float)$amount, 'sub' => number_format((float)($row['unique_amount'] ?? $row['base_amount'] ?? 0), 8, '.', '') . ' USDT', 'date' => $row['created_at'] ?? '', 'ref' => $txHash !== '' ? $txHash : 'طلب #' . (int)$row['id'], 'note' => (string)($row['wallet_address'] ?? ''), 'receipt' => false];
        }
    } catch (Throwable $e) { /* قد لا تكون خدمة USDT مهيأة في بعض التثبيتات */ }
    try {
        $s = $pdo->prepare("SELECT * FROM recharge_cards WHERE used_by=? ORDER BY used_at DESC LIMIT 50");
        $s->execute([$uid]);
        foreach ($s->fetchAll() as $row) {
            $code = (string)($row['code'] ?? '');
            $masked = $code !== '' ? (mb_substr($code, 0, 3) . str_repeat('*', max(0, mb_strlen($code) - 6)) . mb_substr($code, -3)) : '';
            $payments[] = ['id' => (int)$row['id'], 'type' => 'card', 'method' => '🎫 بطاقة شحن', 'status' => 'approved', 'amount_usd' => (float)($row['amount'] ?? 0), 'sub' => 'كود: ' . $masked, 'date' => $row['used_at'] ?? $row['created_at'] ?? '', 'ref' => $masked, 'note' => !empty($row['batch_id']) ? 'دفعة: ' . $row['batch_id'] : '', 'receipt' => false];
        }
    } catch (Throwable $e) { /* API البطاقات ينشئ الجدول عند الحاجة */ }
    usort($payments, static function(array $a, array $b): int { return strtotime((string)$b['date']) <=> strtotime((string)$a['date']); });
    $allowedFilters = ['all','manual','direct','binance','usdt','card','approved','pending','rejected'];
    if (!in_array($filter, $allowedFilters, true)) $filter = 'all';
    $visible = array_values(array_filter($payments, static function(array $p) use ($filter): bool {
        if ($filter === 'all') return true;
        if (in_array($filter, ['manual','direct','binance','usdt','card'], true)) return $p['type'] === $filter;
        return $p['status'] === $filter || ($filter === 'approved' && $p['type'] === 'card');
    }));
    $text = njazTgScreen('🧾', 'مدفوعاتي', 'عدد العمليات المعروضة: <b>' . count($visible) . '</b>');
    if (!$visible) $text .= '\n\nلا توجد عمليات في هذا الفلتر.';
    $detailRows = [];
    foreach (array_slice($visible, 0, 15) as $p) {
        $st = njazTgPaymentStatus($p['type'], $p['status']);
        $date = strtotime((string)$p['date']) ? date('d/m/Y H:i', strtotime((string)$p['date'])) : (string)$p['date'];
        $text .= '\n\n<b>' . njazTgHtml($p['method']) . '</b>\n' . $st['icon'] . ' ' . njazTgHtml($st['label']) . ' — <b>' . njazTgMoney($p['amount_usd']) . '</b>\n📌 ' . njazTgHtml($p['sub']) . '\n🕐 ' . njazTgHtml($date);
        if ($p['ref'] !== '') $text .= '\n🔖 ' . njazTgHtml($p['ref']);
        if ($p['note'] !== '') $text .= '\n📝 ' . njazTgHtml(mb_substr($p['note'], 0, 120));
        if ($p['receipt']) $text .= '\n🖼 يوجد إيصال';
        $detailRows[] = [['text' => '🔎 تفاصيل ' . $p['method'] . ' #' . $p['id'], 'callback_data' => 'payment:view:' . $p['type'] . ':' . $p['id']]];
    }
    if (count($visible) > 15) $text .= '\n\nيتم عرض أحدث 15 عملية فقط.';
    $kb = array_merge($detailRows, [
        [['text' => 'الكل', 'callback_data' => 'payments:all'], ['text' => 'يدوي', 'callback_data' => 'payments:manual'], ['text' => 'فلوسك', 'callback_data' => 'payments:direct']],
        [['text' => '◈ Binance', 'callback_data' => 'payments:binance'], ['text' => '₮ USDT', 'callback_data' => 'payments:usdt'], ['text' => '🎫 بطاقة', 'callback_data' => 'payments:card']],
        [['text' => '✅ مقبول', 'callback_data' => 'payments:approved']],
        [['text' => '⏳ انتظار', 'callback_data' => 'payments:pending'], ['text' => '❌ مرفوض', 'callback_data' => 'payments:rejected']],
        [['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']],
    ]);
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'payments', ['filter' => $filter]);
}

function njazTgPaymentDetail(PDO $pdo, array $tgUser, string $type, int $paymentId): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (function_exists('binancePayExpireUnsubmittedDeposits')) binancePayExpireUnsubmittedDeposits($pdo);
    $uid = (int)$tgUser['user_id']; $chatId = (int)$tgUser['chat_id']; $row = null; $caption = '';
    try {
        if ($type === 'manual') {
            $s = $pdo->prepare("SELECT r.*, m.name method_name FROM topup_requests r LEFT JOIN payment_methods m ON m.id=r.method_id WHERE r.id=? AND r.user_id=? LIMIT 1");
            $s->execute([$paymentId, $uid]); $row = $s->fetch();
            if ($row) {
                $st = njazTgPaymentStatus('manual', (string)($row['status'] ?? 'pending'));
                $date = strtotime((string)($row['created_at'] ?? '')) ? date('d/m/Y H:i', strtotime($row['created_at'])) : (string)($row['created_at'] ?? '');
                $caption = '<b>🧾 تفاصيل طلب الشحن #' . (int)$row['id'] . '</b>\n\nالطريقة: ' . njazTgHtml($row['method_name'] ?: 'طريقة دفع') . '\nالحالة: ' . $st['icon'] . ' ' . njazTgHtml($st['label']) . '\nالمبلغ: <b>' . njazTgMoney((float)($row['amount_usd'] ?? 0)) . '</b>\nالمبلغ المرسل: <b>' . njazTgHtml(number_format((float)($row['amount_sent'] ?? 0), 8, '.', '') . ' ' . ($row['currency_code'] ?? '')) . '</b>\nالتاريخ: ' . njazTgHtml($date);
                $note = (string)($row['admin_notes'] ?? $row['notes'] ?? '');
                if ($note !== '') $caption .= '\nالملاحظة: ' . njazTgHtml(mb_substr($note, 0, 300));
            }
        } elseif ($type === 'direct') {
            $s = $pdo->prepare("SELECT * FROM floosak_topup_sessions WHERE id=? AND user_id=? LIMIT 1");
            $s->execute([$paymentId, $uid]); $row = $s->fetch();
            if ($row) {
                $status = (string)($row['status'] ?? 'pending');
                if ($status === 'pending' && !empty($row['created_at']) && strtotime($row['created_at']) < time() - 600) $status = 'expired';
                $st = njazTgPaymentStatus('direct', $status);
                $date = strtotime((string)($row['created_at'] ?? '')) ? date('d/m/Y H:i', strtotime($row['created_at'])) : (string)($row['created_at'] ?? '');
                $caption = '<b>💳 تفاصيل دفع فلوسك #' . (int)$row['id'] . '</b>\n\nالحالة: ' . $st['icon'] . ' ' . njazTgHtml($st['label']) . '\nالمبلغ: <b>' . njazTgMoney((float)($row['amount_usd'] ?? 0)) . '</b>\nالمبلغ بالريال: <b>' . njazTgHtml(number_format((float)($row['amount_yer'] ?? 0), 2, '.', '') . ' ريال') . '</b>\nالهاتف: <code>' . njazTgHtml((string)($row['phone'] ?? '')) . '</code>\nالمرجع: ' . njazTgHtml((string)($row['reference_id'] ?? '—')) . '\nالتاريخ: ' . njazTgHtml($date);
            }
        } elseif ($type === 'binance') {
            $s = $pdo->prepare("SELECT * FROM binance_pay_deposits WHERE id=? AND user_id=? LIMIT 1");
            $s->execute([$paymentId, $uid]); $row = $s->fetch();
            if ($row) {
                $st = njazTgPaymentStatus('binance', (string)($row['status'] ?? 'pending'));
                $dateRaw = $row['created_at'] ?? '';
                $date = strtotime((string)$dateRaw) ? date('d/m/Y H:i', strtotime($dateRaw)) : (string)$dateRaw;
                $submitted = (string)($row['submitted_transaction_id'] ?? '');
                $caption = '<b>◈ تفاصيل طلب Binance #' . (int)$row['id'] . '</b>\n\nالحالة: ' . $st['icon'] . ' ' . njazTgHtml($st['label']) . '\nالمبلغ المطلوب: <b>' . njazTgHtml((string)($row['expected_amount'] ?? '')) . ' USDT</b>\nالمعرّف المُرسل: <code>' . njazTgHtml($submitted !== '' ? $submitted : '—') . '</code>\nالسبب/المرجع: ' . njazTgHtml((string)($row['safe_error_code'] ?? '—')) . '\nالصلاحية: ' . njazTgHtml((string)($row['expires_at'] ?? '—')) . '\nالتاريخ: ' . njazTgHtml($date);
            }
        } elseif ($type === 'usdt') {
            $s = $pdo->prepare("SELECT * FROM usdt_deposit_requests WHERE id=? AND user_id=? LIMIT 1");
            $s->execute([$paymentId, $uid]); $row = $s->fetch();
            if ($row) {
                $st = njazTgPaymentStatus('usdt', (string)($row['status'] ?? 'pending'));
                $dateRaw = $row['created_at'] ?? '';
                $date = strtotime((string)$dateRaw) ? date('d/m/Y H:i', strtotime($dateRaw)) : (string)$dateRaw;
                $txHash = trim((string)($row['tx_hash'] ?? ''));
                $caption = '<b>₮ تفاصيل طلب USDT — BEP20 #' . (int)$row['id'] . '</b>\n\nالحالة: ' . $st['icon'] . ' ' . njazTgHtml($st['label']) . '\nالمبلغ الفريد: <b>' . njazTgHtml((string)($row['unique_amount'] ?? $row['base_amount'] ?? '')) . ' USDT</b>\nالمبلغ المعتمد: <b>' . njazTgHtml((string)($row['credited_amount'] ?? '—')) . ' USDT</b>\nTXID: <code>' . njazTgHtml($txHash !== '' ? $txHash : '—') . '</code>\nالصلاحية: ' . njazTgHtml((string)($row['expires_at'] ?? '—')) . '\nالتاريخ: ' . njazTgHtml($date);
            }
        } elseif ($type === 'card') {
            $s = $pdo->prepare("SELECT * FROM recharge_cards WHERE id=? AND used_by=? LIMIT 1");
            $s->execute([$paymentId, $uid]); $row = $s->fetch();
            if ($row) {
                $code = (string)($row['code'] ?? '');
                $masked = $code !== '' ? (mb_substr($code, 0, 3) . str_repeat('*', max(0, mb_strlen($code) - 6)) . mb_substr($code, -3)) : '—';
                $dateRaw = $row['used_at'] ?? $row['created_at'] ?? '';
                $date = strtotime((string)$dateRaw) ? date('d/m/Y H:i', strtotime($dateRaw)) : (string)$dateRaw;
                $caption = '<b>🎫 تفاصيل بطاقة الشحن #' . (int)$row['id'] . '</b>\n\nالحالة: ✅ مشحونة\nالقيمة: <b>' . njazTgMoney((float)($row['amount'] ?? 0)) . '</b>\nالكود: <code>' . njazTgHtml($masked) . '</code>\nالتاريخ: ' . njazTgHtml($date);
            }
        }
    } catch (Throwable $e) { error_log('Telegram payment detail failed: ' . $e->getMessage()); }
    if (!$row) { njazTgSend($pdo, $chatId, 'عملية الدفع غير موجودة أو لا تملك صلاحية عرضها.', [[['text' => '↩️ مدفوعاتي', 'callback_data' => 'menu:payments']]]); return; }
    $keyboard = [[['text' => '↩️ مدفوعاتي', 'callback_data' => 'menu:payments'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']]];
    if ($type === 'manual' && !empty($row['receipt_image'])) {
        njazTgSendPhoto($pdo, $chatId, (string)$row['receipt_image'], $caption, $keyboard);
    } else {
        njazTgSend($pdo, $chatId, $caption, $keyboard);
    }
}
