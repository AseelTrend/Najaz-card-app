<?php
/**
 * includes/vapid.php
 * مكتبة VAPID خالصة بـ PHP — بدون Composer
 * تستخدم OpenSSL لتوليد JWT وتشفير الرسالة
 */

class VapidHelper
{
    /**
     * توليد JWT للمصادقة مع خوادم Push
     */
    public static function buildJWT(string $audience, string $subject, string $privateKeyPem): string
    {
        $header  = self::base64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::base64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 43200, // 12 ساعة
            'sub' => $subject,
        ]));

        $data = "$header.$payload";

        $pkey = openssl_pkey_get_private($privateKeyPem);
        if (!$pkey) {
            throw new Exception('مفتاح VAPID الخاص غير صالح');
        }

        openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256);

        // تحويل DER signature إلى R||S (64 bytes)
        $sig = self::derToRS($signature);

        return "$data." . self::base64url($sig);
    }

    /**
     * إرسال Web Push Notification عبر cURL
     */
    public static function sendNotification(
        array  $subscription,
        string $payload,
        string $vapidPublicKey,
        string $vapidPrivateKeyPem,
        string $subject
    ): array {
        $endpoint = $subscription['endpoint'];
        $p256dh   = $subscription['p256dh'];
        $auth     = $subscription['auth'];

        // استخراج origin للـ JWT
        $parsedUrl = parse_url($endpoint);
        $audience  = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        $jwt = self::buildJWT($audience, $subject, $vapidPrivateKeyPem);

        // تشفير الرسالة باستخدام aesgcm
        [$encryptedBody, $salt, $serverPublicKey] = self::encryptPayload(
            $payload, $p256dh, $auth
        );

        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aesgcm',
            'Authorization: vapid t=' . $jwt . ',k=' . $vapidPublicKey,
            'Encryption: salt=' . self::base64url($salt),
            'Crypto-Key: dh=' . self::base64url($serverPublicKey) . ';p256ecdsa=' . $vapidPublicKey,
            'TTL: 86400',
            'Content-Length: ' . strlen($encryptedBody),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encryptedBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'       => $httpCode >= 200 && $httpCode < 300,
            'code'     => $httpCode,
            'error'    => $curlError ?: ($httpCode >= 400 ? $response : null),
            'gone'     => $httpCode === 410, // الاشتراك منتهي
        ];
    }

    /**
     * تشفير الرسالة باستخدام Web Push Encryption (aesgcm)
     */
    private static function encryptPayload(string $payload, string $p256dhBase64, string $authBase64): array
    {
        $receiverPublicKey = self::base64urlDecode($p256dhBase64);
        $authSecret        = self::base64urlDecode($authBase64);

        // توليد مفتاح مؤقت للخادم
        $serverKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $serverKeyDetails = openssl_pkey_get_details($serverKey);

        // استخراج المفتاح العام بصيغة uncompressed (04 + x + y)
        $serverPublicKey = "\x04" . str_pad($serverKeyDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                                  . str_pad($serverKeyDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // ECDH مشترك
        $receiverPublicKeyResource = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        // استخدام openssl_dh_compute_key بديل
        $sharedSecret = self::ecdhCompute($serverKey, $receiverPublicKey);

        $salt = random_bytes(16);

        // HKDF لاشتقاق مفاتيح التشفير
        $prk = self::hkdf($authSecret, $sharedSecret,
            "Content-Encoding: auth\x00", 32);

        $context = "P-256\x00"
            . pack('n', strlen($receiverPublicKey)) . $receiverPublicKey
            . pack('n', strlen($serverPublicKey))   . $serverPublicKey;

        $cek = self::hkdf($salt, $prk, "Content-Encoding: aesgcm\x00" . $context, 16);
        $iv  = self::hkdf($salt, $prk, "Content-Encoding: nonce\x00"   . $context, 12);

        // Padding (2 bytes فارغة) + الرسالة
        $paddedPayload = "\x00\x00" . $payload;

        // AES-128-GCM
        $tag  = '';
        $encrypted = openssl_encrypt($paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return [$encrypted . $tag, $salt, $serverPublicKey];
    }

    /**
     * حساب ECDH باستخدام OpenSSL
     */
    private static function ecdhCompute($privateKey, string $publicKeyRaw): string
    {
        // تحويل المفتاح العام الخام إلى PEM
        // نستخدم phpseclib-style manual DER encoding
        $der = self::buildEcPublicKeyDer($publicKeyRaw);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        $pubKey = openssl_pkey_get_public($pem);
        if (!$pubKey) {
            throw new Exception('فشل في تحميل المفتاح العام للمستقبل');
        }

        // openssl_dh_compute_key لا يعمل مع EC في كل الإصدارات
        // نستخدم طريقة بديلة عبر CLI أو نحسبها يدوياً
        return self::ecdhViaOpenssl($privateKey, $pubKey);
    }

    /**
     * DER encoding للمفتاح العام EC
     */
    private static function buildEcPublicKeyDer(string $rawPublicKey): string
    {
        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm AlgorithmIdentifier, subjectPublicKey BIT STRING }
        // AlgorithmIdentifier for EC = OID 1.2.840.10045.2.1 + OID 1.2.840.10045.3.1.7 (prime256v1)
        $ecOid    = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $curveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $algoSeq  = self::asn1Seq($ecOid . $curveOid);
        $bitStr   = "\x03" . self::asn1Length(strlen($rawPublicKey) + 1) . "\x00" . $rawPublicKey;
        return self::asn1Seq($algoSeq . $bitStr);
    }

    private static function asn1Seq(string $content): string
    {
        return "\x30" . self::asn1Length(strlen($content)) . $content;
    }

    private static function asn1Length(int $len): string
    {
        if ($len < 128) return chr($len);
        if ($len < 256) return "\x81" . chr($len);
        return "\x82" . chr($len >> 8) . chr($len & 0xff);
    }

    /**
     * ECDH عبر openssl_pkey_derive (PHP 7.3+)
     */
    private static function ecdhViaOpenssl($privateKey, $publicKey): string
    {
        if (function_exists('openssl_pkey_derive')) {
            $shared = openssl_pkey_derive($publicKey, $privateKey);
            if ($shared !== false) return $shared;
        }
        throw new Exception('openssl_pkey_derive غير متوفر — يرجى ترقية PHP إلى 7.3+');
    }

    /**
     * HKDF — استخراج مفاتيح
     */
    private static function hkdf(string $salt, string $ikm, string $info, int $length): string
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $t   = '';
        $okm = '';
        for ($i = 1; strlen($okm) < $length; $i++) {
            $t    = hash_hmac('sha256', $t . $info . chr($i), $prk, true);
            $okm .= $t;
        }
        return substr($okm, 0, $length);
    }

    /**
     * تحويل DER signature إلى R||S
     */
    private static function derToRS(string $der): string
    {
        $offset = 2;
        if (ord($der[$offset]) !== 0x02) throw new Exception('Invalid DER signature');
        $rLen = ord($der[$offset + 1]);
        $r    = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        if (ord($der[$offset]) !== 0x02) throw new Exception('Invalid DER signature');
        $sLen = ord($der[$offset + 1]);
        $s    = substr($der, $offset + 2, $sLen);

        // إزالة البايت الزائد (leading zero للأعداد السالبة في DER)
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        // padding إلى 32 bytes
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    /**
     * تحويل المفتاح الخاص من base64url إلى PEM
     */
    public static function privateKeyToPem(string $privateKeyBase64, string $publicKeyBase64 = ''): string
    {
        $privateKeyRaw = self::base64urlDecode($privateKeyBase64);

        // بناء ECPrivateKey DER
        // ECPrivateKey ::= SEQUENCE { version INTEGER, privateKey OCTET STRING, parameters [0] OID, publicKey [1] BIT STRING }
        $version    = "\x02\x01\x01"; // version = 1
        $privOctet  = "\x04" . chr(strlen($privateKeyRaw)) . $privateKeyRaw;
        $curveOid   = "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // [0] prime256v1

        $ecKey = self::asn1Seq($version . $privOctet . $curveOid);
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($ecKey), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        return $pem;
    }
}
