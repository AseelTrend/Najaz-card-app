<?php
/**
 * TOTP (Google Authenticator) — Pure PHP, no Composer
 * RFC 6238 compliant
 */
class TOTP {

    // توليد secret عشوائي (Base32)
    public static function generateSecret(int $length = 16): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $bytes  = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    // Base32 decode
    private static function base32Decode(string $b32): string {
        $b32    = strtoupper(rtrim($b32, '='));
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bufLen = 0;
        $output = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $val = strpos($chars, $b32[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bufLen += 5;
            if ($bufLen >= 8) {
                $bufLen -= 8;
                $output .= chr(($buffer >> $bufLen) & 0xFF);
            }
        }
        return $output;
    }

    // حساب TOTP code
    public static function getCode(string $secret, int $timeSlot = null): string {
        if ($timeSlot === null) $timeSlot = (int)floor(time() / 30);
        $key     = self::base32Decode($secret);
        $time    = pack('N*', 0) . pack('N*', $timeSlot);
        $hash    = hash_hmac('sha1', $time, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $code    = (
            ((ord($hash[$offset])   & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) << 8 ) |
            ( ord($hash[$offset+3]) & 0xFF)
        ) % 1000000;
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    // التحقق من الكود (نافذة ±1 للتسامح مع اختلاف الوقت)
    public static function verify(string $secret, string $code, int $window = 1): bool {
        $code = preg_replace('/\s/', '', $code);
        if (strlen($code) !== 6 || !ctype_digit($code)) return false;
        $slot = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::getCode($secret, $slot + $i), $code)) return true;
        }
        return false;
    }

    // بناء otpauth URI لـ QR Code
    public static function getUri(string $secret, string $account, string $issuer): string {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    // توليد QR Code URL عبر Google Charts API (لا يحتاج مكتبة)
    public static function getQrUrl(string $secret, string $account, string $issuer): string {
        $uri  = self::getUri($secret, $account, $issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);
    }
}
