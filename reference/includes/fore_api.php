<?php
/**
 * Fore Yemen API Library
 * https://fore.yemoney.net/api/yr/
 * 
 * Token: md5( md5(Password) + transid + Username + mobile )
 */
class ForeYemenAPI {

    private string $domain;
    private string $userid;
    private string $username;
    private string $password;
    private string $hashPassword;

    public function __construct(string $domain, string $userid, string $username, string $password) {
        $this->domain       = rtrim($domain, '/');
        $this->userid       = $userid;
        $this->username     = $username;
        $this->password     = $password;
        $this->hashPassword = md5($password);
    }

    // ── توليد Token ─────────────────────────────────────────────────────────
    private function token(string $transid, string $mobile): string {
        return md5($this->hashPassword . $transid . $this->username . $mobile);
    }

    // ── transid فريد ────────────────────────────────────────────────────────
    public static function genTransid(): string {
        // microtime + random لضمان التفرد الكامل
        $micro = str_replace('.', '', sprintf('%.6f', microtime(true)));
        $rand  = str_pad((string)mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        return $micro . $rand;
    }

    // ── طلب GET عام ─────────────────────────────────────────────────────────
    private function get(string $endpoint, array $params): array {
        $url = $this->domain . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
        $ctx = stream_context_create(['http' => [
            'timeout'       => 8,
            'ignore_errors' => true,
            'user_agent'    => 'ShippingPro/2.0',
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return ['resultCode' => 'NETWORK_ERROR', 'resultDesc' => 'انتهت مهلة الاتصال بالمزود (8 ثواني) — حاول مجدداً'];

        // محاولة JSON
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;

        // الرد نصي: CODE/x/x/DESC#VAL1#VAL2#...
        // مثال: 0/0/1/Arabic: تمت العملية بنجاح#500#0#0
        $raw2 = trim($raw);
        if (preg_match('/^(\d+)\/[^\/]*\/[^\/]*\/([^#\n]*)(?:#(.*))?$/', $raw2, $m)) {
            $code  = $m[1];
            $desc  = trim($m[2]);
            $parts = isset($m[3]) ? explode('#', $m[3]) : [];
            return [
                'resultCode'  => $code,
                'resultDesc'  => $code === '0' ? 'success' : $desc,
                'loan_amount' => isset($parts[0]) && is_numeric($parts[0]) ? (float)$parts[0] : 0,
                '_raw'        => $raw2,
            ];
        }

        return ['resultCode' => 'PARSE_ERROR', 'resultDesc' => 'رد غير صالح', '_raw' => $raw];
    }

    // ── فحص النجاح ─────────────────────────────────────────────────────────
    public static function isSuccess(array $r): bool {
        return isset($r['resultCode']) && $r['resultCode'] === '0';
    }

    public static function isPending(array $r): bool {
        return isset($r['resultCode']) && $r['resultCode'] === '0'
            && isset($r['resultDesc']) && stripos($r['resultDesc'], 'under process') !== false;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // يمن موبايل — /yem
    // ══════════════════════════════════════════════════════════════════════════

    /** استعلام رصيد يمن موبايل */
    public function yemenMobileQuery(string $mobile): array {
        $tid = self::genTransid();
        return $this->get('yem', [
            'action'  => 'query',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
        ]);
    }

    /** شحن رصيد يمن موبايل */
    public function yemenMobileBill(string $mobile, float $amount): array {
        $tid = self::genTransid();
        return $this->get('yem', [
            'action'  => 'bill',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'amount'  => $amount,
        ]);
    }

    /** استعلام الباقات المشتركة */
    public function yemenMobileQueryOffers(string $mobile): array {
        $tid = self::genTransid();
        return $this->get('yem', [
            'action'  => 'queryoffer',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
        ]);
    }

    /** تفعيل باقة يمن موبايل */
    public function yemenMobileBillOffer(string $mobile, string $offerId, string $method = 'New'): array {
        $tid = self::genTransid();
        return $this->get('yem', [
            'action'  => 'billoffer',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'offerid' => $offerId,
            'method'  => $method,
        ]);
    }

    /** فحص السلفة (يمن موبايل) */
    public function yemenMobileLoan(string $mobile): array {
        $tid = self::genTransid();
        return $this->get('yem', [
            'action'  => 'solfa',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // الهاتف الثابت / الانترنت الأرضي — /post
    // ══════════════════════════════════════════════════════════════════════════

    /** استعلام رصيد */
    public function postQuery(string $mobile): array {
        $tid = self::genTransid();
        return $this->get('post', [
            'action'  => 'query',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
        ]);
    }

    /** شحن (type = adsl | line) */
    public function postBill(string $mobile, float $amount, string $type = 'line'): array {
        $tid = self::genTransid();
        return $this->get('post', [
            'action'  => 'bill',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'amount'  => $amount,
            'type'    => $type,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // واي — /why  (بالرقم num)
    // ══════════════════════════════════════════════════════════════════════════

    public function whyBill(string $mobile, int $num): array {
        $tid = self::genTransid();
        return $this->get('why', [
            'action'  => 'bill',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'num'     => $num,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // سبافون — /sabaphone  (بالرقم num)
    // ══════════════════════════════════════════════════════════════════════════

    public function sabaphoneBill(string $mobile, int $num): array {
        $tid = self::genTransid();
        return $this->get('sabaphone', [
            'action'  => 'bill',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'num'     => $num,
        ]);
    }

    public function sabaphoneOffer(string $mobile, int $num): array {
        $tid = self::genTransid();
        return $this->get('sabaoffer', [
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'num'     => $num,
        ]);
    }

    public function sabaphoneUnits(string $mobile, int $num): array {
        $tid = self::genTransid();
        return $this->get('sabaunits', [
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'num'     => $num,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MTN — /mtn  (بالرقم num + type)
    // ══════════════════════════════════════════════════════════════════════════

    public function mtnBill(string $mobile, int $num, string $type = 'prepaid'): array {
        $tid = self::genTransid();
        return $this->get('mtn', [
            'action'  => 'bill',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $mobile),
            'num'     => $num,
            'type'    => $type,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // معلومات الحساب / حالة العملية
    // ══════════════════════════════════════════════════════════════════════════

    /** رصيد الوكيل */
    public function agentBalance(string $anyMobile = '700000000'): array {
        $tid = self::genTransid();
        return $this->get('info', [
            'action'  => 'balance',
            'userid'  => $this->userid,
            'mobile'  => $anyMobile,
            'transid' => $tid,
            'token'   => $this->token($tid, $anyMobile),
        ]);
    }

    /** حالة عملية سابقة */
    public function operationStatus(string $transid, string $mobile = '700000000'): array {
        return $this->get('info', [
            'action'  => 'staus',
            'userid'  => $this->userid,
            'mobile'  => $mobile,
            'transid' => $transid,
            'token'   => $this->token($transid, $mobile),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // دالة موحدة لشحن الرصيد (تختار الـ endpoint تلقائياً)
    // ══════════════════════════════════════════════════════════════════════════
    /**
     * شحن رصيد حسب إعدادات الشبكة
     * $network : صف من telecom_networks
     * $amount  : قيمة الشحن (ريال أو وحدات)
     * $postType: 'adsl' | 'line' (للهاتف الثابت فقط)
     */
    public function billBalance(array $network, string $mobile, float $amount, string $postType = 'line'): array {
        $ep = $network['fore_endpoint'];
        switch ($ep) {
            case 'yem':
                return $this->yemenMobileBill($mobile, $amount);
            case 'post':
                return $this->postBill($mobile, $amount, $postType);
            case 'why':
                return $this->whyBill($mobile, (int)$amount);
            case 'sabaphone':
                return $this->sabaphoneBill($mobile, (int)$amount);
            case 'mtn':
                return $this->mtnBill($mobile, (int)$amount);
            default:
                return ['resultCode' => 'CFG_ERROR', 'resultDesc' => 'مزود غير مدعوم: ' . $ep];
        }
    }

    /**
     * تفعيل باقة حسب إعدادات الشبكة
     * $offer : صف من telecom_offers
     */
    public function billOffer(array $network, array $offer, string $mobile): array {
        $ep = $network['fore_offer_endpoint'] ?? $network['fore_endpoint'];
        switch ($ep) {
            case 'yem':
                return $this->yemenMobileBillOffer($mobile, $offer['fore_offer_id'], $offer['fore_method'] ?? 'New');
            case 'sabaoffer':
                return $this->sabaphoneOffer($mobile, (int)$offer['fore_num']);
            case 'why':
                return $this->whyBill($mobile, (int)$offer['fore_num']);
            case 'mtn':
            case 'mtnoffer':
                $tid = self::genTransid();
                return $this->get('mtnoffer', [
                    'userid' => $this->userid, 'mobile' => $mobile,
                    'transid' => $tid, 'token' => $this->token($tid, $mobile),
                    'num' => (int)$offer['fore_num'],
                ]);
            default:
                return ['resultCode' => 'CFG_ERROR', 'resultDesc' => 'مزود غير مدعوم'];
        }
    }

    /** اختبار الاتصال */
    public function testConnection(): array {
        $r = $this->agentBalance();
        if (self::isSuccess($r)) {
            return ['ok' => true, 'balance' => $r['balance'] ?? '—', 'msg' => 'الاتصال ناجح'];
        }
        return ['ok' => false, 'msg' => $r['resultDesc'] ?? 'فشل الاتصال'];
    }
}

/**
 * تهيئة API من إعدادات قاعدة البيانات
 */
function getTelecomAPI(PDO $pdo): ?ForeYemenAPI {
    $provider = getSetting('telecom_provider') ?: 'fore';
    if ($provider !== 'fore') return null; // Al Momaiz لاحقاً
    $domain   = getSetting('fore_domain');
    $userid   = getSetting('fore_userid');
    $username = getSetting('fore_username');
    $password = getSetting('fore_password');
    if (!$domain || !$userid) return null;
    return new ForeYemenAPI($domain, $userid, $username, $password);
}

/**
 * كشف الشبكة من أول رقمين
 * يُرجع صف من telecom_networks أو null
 */
function detectTelecomNetwork(PDO $pdo, string $phone): ?array {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean) < 8) return null;
    $prefix2 = substr($clean, 0, 2);

    $nets = $pdo->query("SELECT * FROM telecom_networks WHERE status=1 ORDER BY sort_order")->fetchAll();
    foreach ($nets as $net) {
        $prefixes = json_decode($net['prefixes'], true) ?: [];
        if (in_array($prefix2, $prefixes, true)) return $net;
    }
    return null;
}

/**
 * حساب السعر بالريال للعميل
 * $costUsd  : تكلفة الدولار
 * $profitPct: نسبة الربح %
 * $rate     : سعر الصرف
 */
function calcPriceYer(float $costUsd, float $profitPct, float $rate): float {
    $base = $costUsd * $rate;
    return round($base * (1 + $profitPct / 100));
}
