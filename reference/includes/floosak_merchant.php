<?php
/**
 * Floosak Merchant API — مكتبة الدفع المباشر
 * Base URL: https://cmc.floosak.ye
 * x-channel: merchant
 */

define('FLOOSAK_MERCHANT_BASE', 'https://cmc.floosak.ye');

// ──────────────────────────────────────────────────────────────
// دالة HTTP مشتركة
// ──────────────────────────────────────────────────────────────
function floosak_merchant_request(string $endpoint, array $body, string $key = ''): array {
    $url = FLOOSAK_MERCHANT_BASE . $endpoint;
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-channel: merchant',
    ];
    if ($key) {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'message' => 'خطأ في الاتصال: ' . $curlErr, 'data' => null];
    }

    $json = json_decode($raw, true);
    if (!$json) {
        return ['ok' => false, 'message' => 'استجابة غير صالحة من فلوسك', 'data' => null, 'raw' => $raw];
    }

    return [
        'ok'      => !empty($json['is_success']) || ($httpCode >= 200 && $httpCode < 300 && isset($json['key'])),
        'message' => $json['message'] ?? ($json['msg'] ?? ''),
        'data'    => $json['data'] ?? $json,
        'raw'     => $json,
    ];
}

// ──────────────────────────────────────────────────────────────
// 1) طلب مفتاح التفعيل (يُرسل OTP للتاجر)
// ──────────────────────────────────────────────────────────────
function floosak_request_key(string $phone, string $short_code): array {
    return floosak_merchant_request('/api/v1/request/key', [
        'phone'      => $phone,
        'short_code' => $short_code,
    ]);
}

// ──────────────────────────────────────────────────────────────
// 2) تحقق من OTP واحصل على المفتاح
// ──────────────────────────────────────────────────────────────
function floosak_verify_key(int $request_id, string $otp): array {
    $res = floosak_merchant_request('/api/v1/verify/key', [
        'request_id' => $request_id,
        'otp'        => $otp,
    ]);

    // استخرج الـ key و wallet_id من الاستجابة
    if ($res['ok'] && isset($res['raw']['key'])) {
        $walletId = null;
        $wallets  = $res['raw']['account_detail']['account']['wallets'] ?? [];
        if (!empty($wallets)) {
            $walletId = $wallets[0]['id'];
        }
        $res['key']       = $res['raw']['key'];
        $res['wallet_id'] = $walletId;
        $res['balance']   = $wallets[0]['balance'] ?? 0;
    }
    return $res;
}

// ──────────────────────────────────────────────────────────────
// 3) طلب دفع (P2MCL) — يُرسل OTP للعميل
// ──────────────────────────────────────────────────────────────
function floosak_p2mcl(string $key, int $wallet_id, string $target_phone, float $amount, string $reference_id): array {
    return floosak_merchant_request('/api/v1/merchant/p2mcl', [
        'source_wallet_id' => $wallet_id,
        'request_id'       => $reference_id,
        'target_phone'     => $target_phone,
        'amount'           => $amount,
        'purpose'          => 'شحن محفظة njaz.buzz',
    ], $key);
}

// ──────────────────────────────────────────────────────────────
// 4) تأكيد الدفع بالـ OTP
// ──────────────────────────────────────────────────────────────
function floosak_confirm(string $key, int $purchase_id, string $otp): array {
    return floosak_merchant_request('/api/v1/merchant/p2mcl/confirm', [
        'purchase_id' => $purchase_id,
        'otp'         => $otp,
    ], $key);
}

// ──────────────────────────────────────────────────────────────
// مساعد: احصل على إعدادات فلوسك المحفوظة
// ──────────────────────────────────────────────────────────────
function floosak_get_config(PDO $pdo): array {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'floosak_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows ?: [];
}

function floosak_save_config(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($data as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}
