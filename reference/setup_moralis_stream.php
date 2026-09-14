#!/usr/bin/env php
<?php
/**
 * ─────────────────────────────────────────────────────────────
 * setup_moralis_stream.php
 * سكريبت CLI لإنشاء Moralis Stream تلقائياً
 *
 * الاستخدام (من سطر الأوامر):
 *   php setup_moralis_stream.php
 * ─────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/usdt_deposit.php';

// ── اقرأ الإعدادات ────────────────────────────────────────────────────────
$apiKey    = getSetting('usdt_moralis_api_key');
$wallet    = getSetting('usdt_wallet_address');
$webhookUrl = SITE_URL . '/usdt_webhook.php';
$secret    = getSetting('usdt_webhook_secret');

echo "══════════════════════════════════════════════\n";
echo "   إعداد Moralis Stream لـ USDT BEP20\n";
echo "══════════════════════════════════════════════\n\n";

if (empty($apiKey)) {
    die("❌ لم يتم ضبط usdt_moralis_api_key في إعدادات النظام\n");
}
if (empty($wallet)) {
    die("❌ لم يتم ضبط usdt_wallet_address في إعدادات النظام\n");
}
if (empty($secret)) {
    die("❌ لم يتم ضبط usdt_webhook_secret في إعدادات النظام\n");
}

echo "📌 المحفظة    : {$wallet}\n";
echo "📌 Webhook   : {$webhookUrl}\n";
echo "📌 Contract  : " . USDT_OFFICIAL_CONTRACT . "\n\n";

// ── إنشاء الـ Stream ──────────────────────────────────────────────────────
$streamBody = json_encode([
    'webhookUrl'    => $webhookUrl,
    'description'   => 'USDT BEP20 Deposits Monitor',
    'tag'           => 'usdt_deposits',
    'topic0'        => ['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'], // Transfer event
    'allAddresses'  => false,
    'includeNativeTxs' => false,
    'includeContractLogs' => true,
    'includeInternalTxs' => false,
    'abi'           => [],
    'advancedOptions' => [],
    'chainIds'      => [BSC_CHAIN_ID],  // '0x38'
    'filter'        => [
        // فقط Transfer إلى محفظتنا من عقد USDT الرسمي
        'to'         => strtolower($wallet),
        'contract'   => strtolower(USDT_OFFICIAL_CONTRACT),
    ],
    'nativeBalances' => [],
    'triggers'      => [],
    // لا نريد Unconfirmed — confirmed فقط
    'confirmedBlocks' => (int)(getSetting('usdt_min_confirmations') ?: 3),
]);

$ch = curl_init('https://api.moralis-streams.com/beta/streams/evm');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $streamBody,
    CURLOPT_HTTPHEADER     => [
        'X-API-Key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    die("❌ cURL Error: {$curlErr}\n");
}

$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && isset($result['id'])) {
    $streamId = $result['id'];
    echo "✅ تم إنشاء الـ Stream بنجاح!\n";
    echo "   Stream ID: {$streamId}\n\n";

    // حفظ Stream ID في قاعدة البيانات
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('usdt_moralis_stream_id',?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$streamId, $streamId]);
    echo "✅ تم حفظ Stream ID في قاعدة البيانات\n\n";

    // ── إضافة عنوان المحفظة للـ Stream ──────────────────────────────────
    echo "⏳ إضافة عنوان المحفظة للـ Stream...\n";

    $ch2 = curl_init("https://api.moralis-streams.com/beta/streams/evm/{$streamId}/address");
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['address' => $wallet]),
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $r2 = json_decode(curl_exec($ch2), true);
    curl_close($ch2);

    echo isset($r2['address'])
        ? "✅ تم إضافة المحفظة: {$r2['address']}\n\n"
        : "⚠️ تعذّر إضافة المحفظة: " . json_encode($r2) . "\n\n";

    echo "══════════════════════════════════════════════\n";
    echo "   ✅ اكتمل الإعداد — النظام جاهز\n";
    echo "══════════════════════════════════════════════\n";

} else {
    echo "❌ فشل إنشاء الـ Stream (HTTP {$httpCode})\n";
    echo "الرد: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
