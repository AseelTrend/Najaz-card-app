<?php
/**
 * ══════════════════════════════════════════════════════════════
 *  Floosak Agent Top-Up API — مكتبة وكيل الشحن
 *  توثيق: Floosak Agent Top-Up API v1.0 (March 03, 2026)
 *  Base URL Sandbox : https://staging.fintech-expert.net
 *  Base URL Production: يُضبط من إعدادات قاعدة البيانات
 *  x-channel: agent
 * ══════════════════════════════════════════════════════════════
 */

// ─── ثوابت بيئة التشغيل ──────────────────────────────────────
define('FLOOSAK_AGENT_SANDBOX',    'https://staging.fintech-expert.net');
define('FLOOSAK_AGENT_PRODUCTION', 'https://floosak.ye'); // Production URL الرسمي

// ══════════════════════════════════════════════════════════════
//  HTTP Core — طلب HTTP مشترك
// ══════════════════════════════════════════════════════════════
/**
 * إرسال طلب POST إلى Floosak Agent API
 *
 * @param string $endpoint  المسار مثل /api/v1/auth/login
 * @param array  $body      جسم الطلب JSON
 * @param string $token     Bearer Token (اختياري للـ endpoints المحمية)
 * @param bool   $sandbox   true = Sandbox, false = Production
 * @return array ['ok', 'message', 'data', 'statusCode', 'raw', 'http_code']
 */
function floosak_agent_request(
    string $endpoint,
    array  $body    = [],
    string $token   = '',
    bool   $sandbox = true,
    string $customUrl = ''
): array {
    if ($customUrl) {
        $base = rtrim($customUrl, '/');
    } elseif ($sandbox) {
        $base = FLOOSAK_AGENT_SANDBOX;
    } else {
        // قرأ الرابط من الـ settings إذا أمكن
        global $pdo;
        try {
            $dbUrl = $pdo ? $pdo->query("SELECT setting_value FROM settings WHERE setting_key='floosak_agent_api_url' LIMIT 1")->fetchColumn() : '';
            $base  = ($dbUrl && filter_var($dbUrl, FILTER_VALIDATE_URL)) ? rtrim($dbUrl, '/') : FLOOSAK_AGENT_PRODUCTION;
        } catch (Exception $e) {
            $base = FLOOSAK_AGENT_PRODUCTION;
        }
    }
    $url = $base . '/' . ltrim($endpoint, '/');

    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Accept: application/json',
        'x-channel: agent',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    // خطأ اتصال
    if ($curlErr) {
        return [
            'ok'         => false,
            'message'    => 'خطأ في الاتصال: ' . $curlErr,
            'data'       => null,
            'statusCode' => null,
            'raw'        => ['_debug_url' => $url, '_curl_error' => $curlErr],
            'http_code'  => 0,
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return [
            'ok'         => false,
            'message'    => 'استجابة غير صالحة من الخادم',
            'data'       => null,
            'statusCode' => null,
            'raw'        => ['_debug_url' => $url, '_raw_body' => substr($raw, 0, 500)],
            'http_code'  => $httpCode,
        ];
    }
    // إضافة الـ URL للـ debug
    $json['_debug_url'] = $url;

    /*
     * قواعد تحديد النجاح حسب التوثيق:
     *  - is_success = true  → الطلب نجح
     *  - statusCode = null  → اعتمد على is_success فقط (login, inquiries...)
     *  - statusCode = 1     → مكتملة Completed
     *  - statusCode = 0     → Pending
     *  - statusCode = 2     → Failed
     *  - statusCode = 4     → Canceled
     *  - statusCode = 6     → Rejected
     */
    $isSuccess  = !empty($json['is_success']);
    $statusCode = $json['statusCode'] ?? null;

    // تحقق من النجاح الفعلي
    $ok = $isSuccess && ($statusCode === null || $statusCode === 1 || $statusCode === '1' || $statusCode === 0);

    return [
        'ok'         => $ok,
        'message'    => $json['message'] ?? '',
        'data'       => $json['data'] ?? null,
        'statusCode' => $statusCode,
        'raw'        => $json,
        'http_code'  => $httpCode,
    ];
}

// ══════════════════════════════════════════════════════════════
//  SECTION 1 — المصادقة Authentication
// ══════════════════════════════════════════════════════════════

/**
 * تسجيل دخول الوكيل — POST /api/v1/auth/login
 * يُرجع: access_token, token_type, wallets[], is_success
 *
 * ملاحظة: فقط المحافظ بعملة YER صالحة لعمليات الشحن
 *
 * @param string $phone    رقم هاتف الوكيل مع كود الدولة (أرقام فقط)
 * @param string $password كلمة المرور
 * @param bool   $sandbox
 * @return array ['ok', 'message', 'data', 'token', 'wallets', 'yer_wallet_id', 'yer_balance']
 */
function floosak_agent_login(string $phone, string $password, bool $sandbox = true): array {
    $res = floosak_agent_request('/api/v1/auth/login', [
        'phone'    => $phone,
        'password' => $password,
    ], '', $sandbox);

    // استخراج التوكن والمحافظ من الاستجابة الخام
    // الـ API يرجع: {"data":{"token":"..."}} أو {"access_token":"..."} أو {"token":"..."}
    $raw = $res['raw'] ?? [];
    $token = !empty($raw['access_token'])        ? $raw['access_token']
           : (!empty($raw['token'])               ? $raw['token']
           : (!empty($raw['data']['access_token'])? $raw['data']['access_token']
           : ($raw['data']['token'] ?? '')));

    if ($res['ok'] || !empty($token)) {
        $wallets     = $raw['wallets'] ?? $raw['data']['wallets'] ?? [];

        // ابحث عن محفظة YER
        $yerWalletId = null;
        $yerBalance  = 0;
        foreach ($wallets as $w) {
            if (strtoupper($w['currency'] ?? '') === 'YER') {
                $yerWalletId = $w['id'];
                $yerBalance  = (float)($w['balance'] ?? 0);
                break;
            }
        }

        $res['ok']            = true;
        $res['token']         = $token;
        $res['wallets']       = $wallets;
        $res['yer_wallet_id'] = $yerWalletId;
        $res['yer_balance']   = $yerBalance;
    }

    return $res;
}

/**
 * طلب إعادة تعيين كلمة المرور — POST /api/v1/auth/forget-password
 * يرسل OTP للوكيل إذا تطابق الهاتف مع رقم الهوية
 *
 * @param string $phone           رقم الهاتف
 * @param string $identity_number رقم الهوية الرسمية
 * @param bool   $sandbox
 */
function floosak_agent_forget_password(string $phone, string $identity_number, bool $sandbox = true): array {
    return floosak_agent_request('/api/v1/auth/forget-password', [
        'phone'           => $phone,
        'identity_number' => $identity_number,
    ], '', $sandbox);
}

/**
 * تأكيد تغيير كلمة المرور — POST /api/v1/auth/change-password
 * يتحقق من OTP ويضبط كلمة المرور الجديدة (6 أحرف على الأقل)
 *
 * @param string $phone        رقم الهاتف
 * @param string $otp          رمز OTP المُرسل عبر SMS
 * @param string $new_password كلمة المرور الجديدة (6+ أحرف)
 * @param bool   $sandbox
 */
function floosak_agent_change_password(string $phone, string $otp, string $new_password, bool $sandbox = true): array {
    return floosak_agent_request('/api/v1/auth/change-password', [
        'phone'        => $phone,
        'otp'          => $otp,
        'new_password' => $new_password,
    ], '', $sandbox);
}

// ══════════════════════════════════════════════════════════════
//  SECTION 2 — الشحن بنموذجين
//  الطريقة 1: Pending → Confirm / Reject (خطوتين)
//  الطريقة 2: doTransaction (تنفيذ فوري)
// ══════════════════════════════════════════════════════════════

/**
 * الطريقة 1 — إنشاء معاملة شحن معلقة — POST /api/v1/topup
 *
 * يُنشئ معاملة بحالة Pending ويُرجع id يُستخدم في Confirm أو Reject.
 * مناسب لسير عمل يتطلب موافقة صريحة قبل التنفيذ.
 *
 * @param string $token          Bearer Token
 * @param int    $source_wallet_id  معرف المحفظة (YER فقط)
 * @param string $request_id     UUID فريد من العميل (idempotency)
 * @param string $target_number  رقم العميل المراد شحنه
 * @param float  $amount         المبلغ بالريال
 * @param int    $method_id      معرف مزود الاتصالات (من جدول method_id)
 * @param int|string $bunch_id   unified_code للباقة (من جدول bunch_id)
 * @param int    $with_solfa     0 = بدون سلفة, 1 = بسلفة (إذا كانت مدعومة)
 * @param bool   $sandbox
 * @return array يحتوي على data.id الذي يُستخدم في Confirm/Reject
 */
function floosak_agent_send_topup(
    string     $token,
    int        $source_wallet_id,
    string     $request_id,
    string     $target_number,
    float      $amount,
    int        $method_id,
               $bunch_id,
    int        $with_solfa = 0,
    bool       $sandbox    = true
): array {
    return floosak_agent_request('/api/v1/topup', [
        'source_wallet_id' => $source_wallet_id,
        'request_id'       => $request_id,
        'target_number'    => $target_number,
        'amount'           => $amount,
        'method_id'        => $method_id,
        'bunch_id'         => $bunch_id,
        'with_solfa'       => $with_solfa,
    ], $token, $sandbox);
}

/**
 * الطريقة 1 — تأكيد المعاملة المعلقة — POST /api/v1/topup/confirm/{id}
 *
 * ينفّذ المعاملة ويغيّر حالتها إلى Completed.
 * يُسمح فقط إذا كانت الحالة Pending.
 *
 * @param string $token   Bearer Token
 * @param int    $id      معرف المعاملة المُرجع من send_topup
 * @param bool   $sandbox
 */
function floosak_agent_confirm_topup(string $token, int $id, bool $sandbox = true): array {
    return floosak_agent_request('/api/v1/topup/confirm/' . $id, [], $token, $sandbox);
}

/**
 * الطريقة 1 — رفض المعاملة المعلقة — POST /api/v1/topup/reject/{id}
 *
 * يلغي المعاملة ولا تُنفَّذ على المزود.
 *
 * @param string $token   Bearer Token
 * @param int    $id      معرف المعاملة المُرجع من send_topup
 * @param bool   $sandbox
 */
function floosak_agent_reject_topup(string $token, int $id, bool $sandbox = true): array {
    return floosak_agent_request('/api/v1/topup/reject/' . $id, [], $token, $sandbox);
}

/**
 * الطريقة 2 — تنفيذ شحن فوري — POST /api/v1/topup/doTransaction
 *
 * ينفّذ الشحن مباشرة في طلب واحد ويُرجع النتيجة النهائية.
 * لا يحتاج إلى Confirm/Reject.
 * مناسب لسير عمل بسيط بدون موافقة وسيطة.
 *
 * @param string $token          Bearer Token
 * @param int    $source_wallet_id
 * @param string $request_id     UUID فريد
 * @param string $target_number  رقم الهاتف المستهدف
 * @param float  $amount
 * @param int    $method_id
 * @param int|string $bunch_id
 * @param int    $with_solfa     اختياري (الافتراضي 0)
 * @param bool   $sandbox
 */
function floosak_agent_do_transaction(
    string     $token,
    int        $source_wallet_id,
    string     $request_id,
    string     $target_number,
    float      $amount,
    int        $method_id,
               $bunch_id,
    int        $with_solfa = 0,
    bool       $sandbox    = true
): array {
    return floosak_agent_request('/api/v1/topup/doTransaction', [
        'source_wallet_id' => $source_wallet_id,
        'request_id'       => $request_id,
        'target_number'    => $target_number,
        'amount'           => $amount,
        'method_id'        => $method_id,
        'bunch_id'         => $bunch_id,
        'with_solfa'       => $with_solfa,
    ], $token, $sandbox);
}

// ══════════════════════════════════════════════════════════════
//  SECTION 3 — إدارة الباقات والعروض
// ══════════════════════════════════════════════════════════════

/**
 * استعلام عن الباقات المتاحة — POST /api/v1/topup/getOffers
 *
 * يجلب العروض المتاحة للرقم المستهدف حسب المزود.
 *
 * @param string $token
 * @param int    $source_wallet_id
 * @param string $target_number
 * @param int    $method_id
 * @param bool   $sandbox
 * @return array data[] يحتوي على offerId, offerName, offerStartDate, offerEndDate
 */
function floosak_agent_get_offers(
    string $token,
    int    $source_wallet_id,
    string $target_number,
    int    $method_id,
    bool   $sandbox = true
): array {
    return floosak_agent_request('/api/v1/topup/getOffers', [
        'source_wallet_id' => $source_wallet_id,
        'target_number'    => $target_number,
        'method_id'        => $method_id,
    ], $token, $sandbox);
}

/**
 * الاشتراك في باقة جديدة — POST /api/v1/topup/addNew
 *
 * يفعّل باقة لأول مرة على الرقم المستهدف.
 * استخدم هذه الدالة إذا كانت الباقة غير مشترك بها بعد.
 *
 * @param string     $token
 * @param int        $source_wallet_id
 * @param string     $target_number
 * @param int        $method_id
 * @param int|string $bunch_id   unified_code من جدول bunch_id
 * @param bool       $sandbox
 */
function floosak_agent_add_new_offer(
    string $token,
    int    $source_wallet_id,
    string $target_number,
    int    $method_id,
           $bunch_id,
    bool   $sandbox = true
): array {
    return floosak_agent_request('/api/v1/topup/addNew', [
        'source_wallet_id' => $source_wallet_id,
        'target_number'    => $target_number,
        'method_id'        => $method_id,
        'bunch_id'         => $bunch_id,
    ], $token, $sandbox);
}

/**
 * تجديد باقة موجودة — POST /api/v1/topup/reNew
 *
 * يجدد باقة مشتركة وقابلة للتجديد.
 * استخدم هذه الدالة فقط إذا كانت الباقة موجودة ومؤهلة للتجديد.
 *
 * الفرق بين addNew و reNew:
 *   - addNew: للتفعيل الأول
 *   - reNew:  للتجديد على باقة موجودة
 *
 * @param string     $token
 * @param int        $source_wallet_id
 * @param string     $target_number
 * @param int        $method_id
 * @param int|string $bunch_id
 * @param bool       $sandbox
 */
function floosak_agent_renew_offer(
    string $token,
    int    $source_wallet_id,
    string $target_number,
    int    $method_id,
           $bunch_id,
    bool   $sandbox = true
): array {
    return floosak_agent_request('/api/v1/topup/reNew', [
        'source_wallet_id' => $source_wallet_id,
        'target_number'    => $target_number,
        'method_id'        => $method_id,
        'bunch_id'         => $bunch_id,
    ], $token, $sandbox);
}

/**
 * فحص رصيد وباقات العميل — POST /api/v1/topup/check-service
 *
 * يجلب رصيد العميل والقرض والباقات المشتركة حالياً.
 *
 * @param string     $token
 * @param string     $target_number
 * @param int        $method_id
 * @param int|string $bunch_id
 * @param bool       $sandbox
 * @return array data = ['balance', 'loan', 'offers' => [['offer_id','offer_name'],...]]
 */
function floosak_agent_check_service(
    string $token,
    string $target_number,
    int    $method_id,
           $bunch_id,
    bool   $sandbox = true
): array {
    return floosak_agent_request('/api/v1/topup/check-service', [
        'target_number' => $target_number,
        'method_id'     => $method_id,
        'bunch_id'      => $bunch_id,
    ], $token, $sandbox);
}

// ══════════════════════════════════════════════════════════════
//  SECTION 4 — فحص حالة المعاملة
// ══════════════════════════════════════════════════════════════

/**
 * فحص حالة معاملة سابقة — POST /api/v1/agent/check-status
 *
 * يُستخدم للتحقق من نتيجة معاملة بواسطة request_id.
 * للقراءة فقط — لا يُعدّل أي بيانات.
 *
 * @param string $token      Bearer Token
 * @param string $request_id الـ UUID المُستخدم عند إنشاء المعاملة
 * @param bool   $sandbox
 * @return array data يحتوي تفاصيل المعاملة أو رسالة خطأ
 */
function floosak_agent_check_status(string $token, string $request_id, bool $sandbox = true): array {
    return floosak_agent_request('/api/v1/agent/check-status', [
        'request_id' => $request_id,
    ], $token, $sandbox);
}

// ══════════════════════════════════════════════════════════════
//  SECTION 5 — مساعدات إدارة الإعدادات
// ══════════════════════════════════════════════════════════════

/**
 * جلب إعدادات Floosak Agent من قاعدة البيانات
 * المفاتيح المخزَّنة:
 *   floosak_agent_phone
 *   floosak_agent_password
 *   floosak_agent_token
 *   floosak_agent_wallet_id
 *   floosak_agent_wallet_balance
 *   floosak_agent_token_expiry
 *   floosak_agent_sandbox      (1 = Sandbox, 0 = Production)
 *   floosak_agent_enabled      (1 = مفعّل)
 */
function floosak_agent_get_config(PDO $pdo): array {
    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'floosak_agent_%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * حفظ إعدادات Floosak Agent في قاعدة البيانات
 */
function floosak_agent_save_config(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    foreach ($data as $k => $v) {
        $stmt->execute([$k, (string)$v]);
    }
}

/**
 * الحصول على توكن صالح (تسجيل دخول تلقائي إذا انتهت الصلاحية)
 *
 * تتحقق من انتهاء الصلاحية (4 ساعات كحد أقصى افتراضي).
 * إذا انتهى التوكن تُعيد تسجيل الدخول وتحفظ التوكن الجديد.
 *
 * @return array ['ok', 'token', 'wallet_id', 'balance', 'message']
 */
function floosak_agent_get_valid_token(PDO $pdo): array {
    $cfg = floosak_agent_get_config($pdo);

    // فحص إذا كانت الخدمة مفعّلة
    if (empty($cfg['floosak_agent_enabled']) || $cfg['floosak_agent_enabled'] != '1') {
        return ['ok' => false, 'message' => 'خدمة Floosak Agent غير مفعّلة'];
    }

    $token     = $cfg['floosak_agent_token']    ?? '';
    $expiry    = (int)($cfg['floosak_agent_token_expiry'] ?? 0);
    $walletId  = (int)($cfg['floosak_agent_wallet_id']   ?? 0);
    $sandbox   = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';

    // استخدم التوكن الموجود إذا لم ينتهِ (مع هامش أمان 5 دقائق)
    if ($token && $walletId && $expiry > (time() + 300)) {
        return [
            'ok'        => true,
            'token'     => $token,
            'wallet_id' => $walletId,
            'balance'   => (float)($cfg['floosak_agent_wallet_balance'] ?? 0),
            'message'   => 'توكن صالح',
        ];
    }

    // تسجيل دخول جديد
    $phone    = $cfg['floosak_agent_phone']    ?? '';
    $password = $cfg['floosak_agent_password'] ?? '';

    if (!$phone || !$password) {
        return ['ok' => false, 'message' => 'بيانات تسجيل دخول Floosak Agent غير مضبوطة'];
    }

    $res = floosak_agent_login($phone, $password, $sandbox);

    if (!$res['ok'] || empty($res['token'])) {
        return [
            'ok'      => false,
            'message' => 'فشل تسجيل الدخول إلى Floosak Agent: ' . ($res['message'] ?? 'خطأ غير معروف'),
        ];
    }

    // احفظ التوكن والبيانات (صالح 4 ساعات)
    floosak_agent_save_config($pdo, [
        'floosak_agent_token'          => $res['token'],
        'floosak_agent_wallet_id'      => $res['yer_wallet_id'] ?? '',
        'floosak_agent_wallet_balance' => $res['yer_balance']   ?? 0,
        'floosak_agent_token_expiry'   => time() + (4 * 3600),
    ]);

    return [
        'ok'        => true,
        'token'     => $res['token'],
        'wallet_id' => $res['yer_wallet_id'] ?? 0,
        'balance'   => $res['yer_balance']   ?? 0,
        'message'   => 'تسجيل دخول ناجح',
    ];
}

// ══════════════════════════════════════════════════════════════
//  SECTION 6 — توليد request_id (UUID v4)
// ══════════════════════════════════════════════════════════════

/**
 * توليد UUID v4 فريد يُستخدم كـ request_id
 * ضروري لضمان idempotency (منع تكرار المعاملات)
 */
function floosak_agent_generate_request_id(): string {
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ══════════════════════════════════════════════════════════════
//  SECTION 7 — جداول المرجع (method_id و bunch_id)
//  مستخرجة من التوثيق — يمكن توسيعها من قاعدة البيانات
// ══════════════════════════════════════════════════════════════

/**
 * جدول method_id — مزودو الخدمة
 * مفتاح: method_id => ['name_ar', 'name_en', 'type']
 */
function floosak_agent_method_table(): array {
    return [
        1  => ['name_ar' => 'يمن موبايل',       'name_en' => 'Yemen Mobile',   'type' => 'TOPUP'],
        2  => ['name_ar' => 'سبأفون',            'name_en' => 'Sabafon',        'type' => 'TOPUP'],
        3  => ['name_ar' => 'يو',                'name_en' => 'YOU',            'type' => 'TOPUP'],
        4  => ['name_ar' => 'إنترنت منزلي',      'name_en' => 'ADSL',           'type' => 'BILLPAY'],
        5  => ['name_ar' => 'الكهرباء',          'name_en' => 'Electricity',    'type' => 'BILLPAY'],
        11 => ['name_ar' => 'المياه',            'name_en' => 'Water',          'type' => 'BILLPAY'],
        12 => ['name_ar' => 'واي',               'name_en' => 'Y',              'type' => 'TOPUP'],
        13 => ['name_ar' => 'الخط الثابت',       'name_en' => 'Landline',       'type' => 'BILLPAY'],
        14 => ['name_ar' => 'الخط الدولي',       'name_en' => 'TeleYemen',      'type' => 'BILLPAY'],
        16 => ['name_ar' => 'يمن فورجي',         'name_en' => 'Yemen 4G',       'type' => 'BILLPAY'],
        17 => ['name_ar' => 'عدن نت',            'name_en' => 'Aden net',       'type' => 'BILLPAY'],
        19 => ['name_ar' => 'سبأفون عدن',        'name_en' => 'Sabafon Aden',   'type' => 'BILLPAY'],
    ];
}

/**
 * جلب اسم المزود من method_id
 */
function floosak_agent_method_name(int $method_id, string $lang = 'ar'): string {
    $table = floosak_agent_method_table();
    if (!isset($table[$method_id])) return 'مزود غير معروف';
    return $table[$method_id]['name_' . $lang] ?? $table[$method_id]['name_ar'];
}

// ══════════════════════════════════════════════════════════════
//  SECTION 8 — دالة شاملة للشحن عبر doTransaction
//  تجمع تسجيل الدخول + توليد request_id + الشحن + تسجيل العملية
// ══════════════════════════════════════════════════════════════

/**
 * شحن رصيد شامل (doTransaction) مع إدارة التوكن تلقائياً
 *
 * تقوم بـ:
 *   1. جلب توكن صالح (تسجيل دخول إذا لزم)
 *   2. توليد request_id فريد
 *   3. تنفيذ doTransaction
 *   4. إرجاع النتيجة الكاملة
 *
 * @param PDO        $pdo
 * @param string     $target_number  رقم العميل المستهدف
 * @param float      $amount         المبلغ
 * @param int        $method_id      معرف المزود
 * @param int|string $bunch_id       unified_code الباقة
 * @param int        $with_solfa     0 أو 1
 * @return array ['ok', 'message', 'data', 'request_id', 'statusCode']
 */
function floosak_agent_topup(
    PDO    $pdo,
    string $target_number,
    float  $amount,
    int    $method_id,
           $bunch_id,
    int    $with_solfa = 0
): array {
    // الخطوة 1: الحصول على توكن صالح
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message'], 'data' => null];
    }

    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';

    // الخطوة 2: توليد request_id
    $requestId = floosak_agent_generate_request_id();

    // الخطوة 3: تنفيذ الشحن
    $res = floosak_agent_do_transaction(
        $auth['token'],
        $auth['wallet_id'],
        $requestId,
        $target_number,
        $amount,
        $method_id,
        $bunch_id,
        $with_solfa,
        $sandbox
    );

    $res['request_id'] = $requestId;
    return $res;
}

/**
 * شحن باستخدام الطريقة ذات الخطوتين (send → confirm) مع إدارة التوكن
 *
 * @param PDO        $pdo
 * @param string     $target_number
 * @param float      $amount
 * @param int        $method_id
 * @param int|string $bunch_id
 * @param int        $with_solfa
 * @return array ['ok', 'message', 'data', 'request_id', 'transaction_id']
 *         transaction_id يُستخدم في floosak_agent_confirm_topup / floosak_agent_reject_topup
 */
function floosak_agent_topup_pending(
    PDO    $pdo,
    string $target_number,
    float  $amount,
    int    $method_id,
           $bunch_id,
    int    $with_solfa = 0
): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message'], 'data' => null];
    }

    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';

    $requestId = floosak_agent_generate_request_id();

    $res = floosak_agent_send_topup(
        $auth['token'],
        $auth['wallet_id'],
        $requestId,
        $target_number,
        $amount,
        $method_id,
        $bunch_id,
        $with_solfa,
        $sandbox
    );

    $res['request_id']     = $requestId;
    $res['transaction_id'] = $res['data']['id'] ?? null;
    $res['auth_token']     = $auth['token']; // احفظه لاستخدامه في Confirm/Reject
    return $res;
}

/**
 * تأكيد معاملة معلقة مع إدارة التوكن تلقائياً
 */
function floosak_agent_confirm(PDO $pdo, int $transaction_id): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message']];
    }
    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';
    return floosak_agent_confirm_topup($auth['token'], $transaction_id, $sandbox);
}

/**
 * رفض معاملة معلقة مع إدارة التوكن تلقائياً
 */
function floosak_agent_reject(PDO $pdo, int $transaction_id): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message']];
    }
    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';
    return floosak_agent_reject_topup($auth['token'], $transaction_id, $sandbox);
}

/**
 * فحص حالة معاملة مع إدارة التوكن تلقائياً
 */
function floosak_agent_status(PDO $pdo, string $request_id): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message']];
    }
    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';
    return floosak_agent_check_status($auth['token'], $request_id, $sandbox);
}

/**
 * جلب الباقات المتاحة مع إدارة التوكن تلقائياً
 */
function floosak_agent_offers(PDO $pdo, string $target_number, int $method_id): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message'], 'data' => []];
    }
    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';
    return floosak_agent_get_offers($auth['token'], $auth['wallet_id'], $target_number, $method_id, $sandbox);
}

/**
 * فحص خدمة العميل مع إدارة التوكن تلقائياً
 */
function floosak_agent_service_info(PDO $pdo, string $target_number, int $method_id, $bunch_id): array {
    $auth = floosak_agent_get_valid_token($pdo);
    if (!$auth['ok']) {
        return ['ok' => false, 'message' => $auth['message'], 'data' => null];
    }
    $cfg     = floosak_agent_get_config($pdo);
    $sandbox = ($cfg['floosak_agent_sandbox'] ?? '1') === '1';
    return floosak_agent_check_service($auth['token'], $target_number, $method_id, $bunch_id, $sandbox);
}

// ══════════════════════════════════════════════════════════════
//  SECTION 9 — تفسير statusCode
// ══════════════════════════════════════════════════════════════

/**
 * تفسير statusCode إلى نص مقروء
 */
function floosak_agent_status_label($statusCode): string {
    $labels = [
        0 => 'معلقة (Pending)',
        1 => 'مكتملة (Completed)',
        2 => 'فاشلة (Failed)',
        3 => 'قيد المعالجة (In Progress)',
        4 => 'ملغاة (Canceled)',
        5 => 'مُعكوسة (Reversed)',
        6 => 'مرفوضة (Rejected)',
    ];
    $code = is_string($statusCode) ? (int)$statusCode : $statusCode;
    return $labels[$code] ?? 'غير معروف (' . $statusCode . ')';
}
