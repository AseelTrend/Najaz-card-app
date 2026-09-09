<?php
// ============================================
// api/mobile/topup_options.php
// يجمع كل بيانات صفحة "شحن الرصيد" في استدعاء واحد:
// طرق الدفع اليدوية والتلقائية، أسعار الصرف، مزودو SMS،
// إعدادات USDT وBinance Pay وفلوسك، ورصيد المستخدم.
// نفس البيانات المستخدمة في mobile.php لكن بصيغة JSON.
// لا يعدّل أي جدول أو منطق موجود بالموقع.
// ============================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/payment_method_currency_helper.php';

$userId = mobileAuthorizeRequest($pdo, true);

// ── طرق الدفع (يدوي + تلقائي) وحقولها ──────────────────────────
$paymentMethods = [];
$exchangeRates  = [];
try {
    $pm = $pdo->query("SELECT m.*, GROUP_CONCAT(CONCAT(f.field_label,'||',f.field_value,'||',f.copyable) ORDER BY f.sort_order SEPARATOR ';;') as fields_raw
                        FROM payment_methods m
                        LEFT JOIN payment_method_fields f ON m.id=f.method_id
                        WHERE m.status=1 GROUP BY m.id ORDER BY m.sort_order");
    $paymentMethods = $pm->fetchAll(PDO::FETCH_ASSOC);

    $er = $pdo->query("SELECT * FROM exchange_rates WHERE status=1 ORDER BY sort_order");
    $exchangeRates = $er->fetchAll(PDO::FETCH_ASSOC);

    $methodCurrencyMap = paymentMethodCurrencyMap($pdo, array_map(fn($m) => (int)$m['id'], $paymentMethods));

    foreach ($paymentMethods as &$m) {
        $m['allowed_currency_codes'] = $methodCurrencyMap[(int)$m['id']] ?? [];
        $fields = [];
        if (!empty($m['fields_raw'])) {
            foreach (explode(';;', $m['fields_raw']) as $fRaw) {
                $parts = explode('||', $fRaw);
                if (count($parts) < 2) continue;
                $fields[] = [
                    'label'    => $parts[0],
                    'value'    => $parts[1],
                    'copyable' => (int)($parts[2] ?? 1) !== 0,
                ];
            }
        }
        $m['fields'] = $fields;
        unset($m['fields_raw']);
        $m['image_url'] = !empty($m['image']) ? SITE_URL . '/' . ltrim($m['image'], '/') : null;
    }
    unset($m);
} catch (Exception $e) {}

// ── مزودو SMS (شمال / جنوب / بدون منطقة) ────────────────────────
$smsTopupEnabled = getSetting('sms_topup_enabled') === '1';
$smsProviders = [];
try {
    try {
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS provider_type ENUM('sms','usdt') NOT NULL DEFAULT 'sms' AFTER sort_order");
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS usdt_wallet_address VARCHAR(255) DEFAULT '' AFTER provider_type");
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS transfer_account VARCHAR(100) DEFAULT '' AFTER sort_order");
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS account_holder VARCHAR(100) DEFAULT '' AFTER transfer_account");
    } catch (Exception $e) {}
    $sp = $pdo->query("SELECT * FROM sms_providers WHERE status=1 ORDER BY sort_order");
    $smsProviders = $sp->fetchAll(PDO::FETCH_ASSOC);
    foreach ($smsProviders as &$p) {
        $p['logo_url'] = !empty($p['logo']) ? SITE_URL . '/' . ltrim($p['logo'], '/') : null;
    }
    unset($p);
} catch (Exception $e) {}

$smsNorth    = array_values(array_filter($smsProviders, fn($p) => ($p['region'] ?? '') === 'north'));
$smsSouth    = array_values(array_filter($smsProviders, fn($p) => ($p['region'] ?? '') === 'south'));
$smsNoRegion = array_values(array_filter($smsProviders, fn($p) => empty($p['region'])));

// ── فلوسك ────────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/floosak_merchant.php';
$floosakEnabled = getSetting('floosak_enabled') === '1' && !empty(getSetting('floosak_merchant_key'));
$floosakImage   = getSetting('floosak_image') ?: '';

// ── USDT BEP20 (نفس الجريان النشط بالموقع: usdt_deposit_request.php) ──
$usdtEnabled       = false;
$usdtWalletAddr    = '';
$usdtImageUrl      = '';
$usdtMinDep        = 1.00;
$usdtTtlMin        = 30;
$activeUsdtRequest = null;
try {
    if (file_exists(dirname(__DIR__, 2) . '/includes/usdt_deposit.php')) {
        require_once dirname(__DIR__, 2) . '/includes/usdt_deposit.php';
        $usdtEnabled    = getSetting('usdt_enabled') === '1' && !empty(getSetting('usdt_wallet_address'));
        $usdtWalletAddr = getSetting('usdt_wallet_address') ?: '';
        $usdtImagePath  = trim((string)(getSetting('usdt_image') ?: ''));
        $usdtImageUrl   = $usdtImagePath !== '' ? rtrim(SITE_URL, '/') . '/' . ltrim($usdtImagePath, '/') : '';
        $usdtMinDep     = (float)(getSetting('usdt_min_deposit') ?: '1.00');
        $usdtTtlMin     = (int)(getSetting('usdt_request_ttl') ?: 30);
        if ($usdtEnabled) {
            usdt_expire_old_requests($pdo);
            $req = usdt_get_active_request($pdo, $userId);
            if ($req) {
                $activeUsdtRequest = [
                    'id'             => (int)$req['id'],
                    'unique_amount'  => rtrim(rtrim((string)$req['unique_amount'], '0'), '.'),
                    'wallet_address' => $req['wallet_address'],
                    'network'        => 'BNB Smart Chain (BEP20)',
                    'expires_at'     => $req['expires_at'],
                    'status'         => $req['status'],
                ];
            }
        }
    }
} catch (Throwable $e) { $usdtEnabled = false; }

// ── Binance Pay ──────────────────────────────────────────────────
$binancePayEnabled  = false;
$binancePaySettings = [
    'minimum_amount'       => '1.0000',
    'request_ttl_minutes'  => 30,
    'receiver_identifier'  => '',
    'icon_image_path'      => '',
];
try {
    if (file_exists(dirname(__DIR__, 2) . '/includes/binance_pay.php')) {
        require_once dirname(__DIR__, 2) . '/includes/binance_pay.php';
        $s = binancePayPublicSettings($pdo);
        $binancePaySettings = [
            'minimum_amount'      => $s['minimum_amount'] ?? '1.0000',
            'request_ttl_minutes' => $s['request_ttl_minutes'] ?? 30,
            'receiver_identifier' => $s['receiver_identifier'] ?? '',
            'icon_image_path'     => $s['icon_image_path'] ?? '',
        ];
        $binancePayEnabled = binancePayCustomerVisible($pdo);
    }
} catch (Throwable $e) { $binancePayEnabled = false; }
$binancePayIconUrl = '';
if (!empty($binancePaySettings['icon_image_path'])) {
    $binancePayIconUrl = $binancePaySettings['icon_image_path'];
    if (str_starts_with($binancePayIconUrl, '/')) $binancePayIconUrl = rtrim(SITE_URL, '/') . $binancePayIconUrl;
}

// ── رصيد المستخدم وحالة توثيق الهوية ──────────────────────────────
$user = getUser($userId);
$userBalance = (float)($user['balance'] ?? 0);

$kycStatus = null;
try {
    $k = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $k->execute([$userId]);
    $kycStatus = $k->fetchColumn() ?: null;
} catch (Exception $e) {}

jsonOutMobile(true, 'topup options fetched', [
    'balance'             => $userBalance,
    'currency_symbol'     => getSetting('currency_symbol') ?: '$',
    'kyc_status'          => $kycStatus, // approved | pending | rejected | null
    'payment_methods'     => $paymentMethods,   // manual + auto, حسب payment_mode
    'exchange_rates'      => $exchangeRates,
    'sms_topup_enabled'   => $smsTopupEnabled,
    'sms_providers'       => [
        'north'     => $smsNorth,
        'south'     => $smsSouth,
        'no_region' => $smsNoRegion,
    ],
    'usdt' => [
        'enabled'          => $usdtEnabled,
        'image_url'        => $usdtImageUrl ?: null,
        'min_deposit'      => $usdtMinDep,
        'ttl_minutes'      => $usdtTtlMin,
        'wallet_address'   => $usdtWalletAddr,
        'active_request'   => $activeUsdtRequest,
    ],
    'binance' => [
        'enabled'             => $binancePayEnabled,
        'minimum_amount'      => (float)($binancePaySettings['minimum_amount'] ?? 1),
        'request_ttl_minutes' => (int)($binancePaySettings['request_ttl_minutes'] ?? 30),
        'receiver_identifier' => $binancePaySettings['receiver_identifier'] ?? '',
        'icon_url'            => $binancePayIconUrl ?: null,
    ],
    'floosak' => [
        'enabled'   => $floosakEnabled,
        'image_url' => $floosakImage ? (str_starts_with($floosakImage, 'http') ? $floosakImage : SITE_URL . '/' . ltrim($floosakImage, '/')) : null,
    ],
]);
