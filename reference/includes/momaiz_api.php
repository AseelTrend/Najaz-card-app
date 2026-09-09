<?php
// includes/momaiz_api.php — مكتبة Al Momaiz Card API
// خاصة بقسم كبينة السداد (الاتصالات اليمنية)

class MomaizAPI {

    private $pdo;
    private $baseUrl = 'https://almomaizcard.com/rest-api/';
    private $username;
    private $password;
    private $accountNumber;
    private $apiToken;
    private $accessToken;

    public function __construct($pdo) {
        $this->pdo           = $pdo;
        $this->username      = getSetting('momaiz_username');
        $this->password      = getSetting('momaiz_password');
        $this->accountNumber = getSetting('momaiz_account_number');
        $this->apiToken      = getSetting('momaiz_api_token');
        $this->accessToken   = getSetting('momaiz_access_token');
    }

    // ─── المصادقة ──────────────────────────────────────────────────────────────

    public function generateToken() {
        return md5($this->username . $this->accountNumber . md5($this->password) . $this->apiToken);
    }

    public function login() {
        // تحقق من صلاحية التوكن المحفوظ أولاً
        $expires = getSetting('momaiz_token_expires');
        if ($this->accessToken && $expires && strtotime($expires) > time() + 60) {
            return true;
        }

        $token = $this->generateToken();
        $res = $this->post($this->baseUrl . 'login', [
            'AccountNumber' => $this->accountNumber,
            'UserName'      => $this->username,
            'Token'         => $token,
        ], false);

        if ($res && !empty($res['status']) && !empty($res['access_token'])) {
            $this->accessToken = $res['access_token'];
            // حفظ في قاعدة البيانات
            $this->pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute(['momaiz_access_token', $res['access_token'], $res['access_token']]);
            $this->pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute(['momaiz_token_expires', $res['token_expires'], $res['token_expires']]);
            return true;
        }
        return false;
    }

    // ─── استعلامات ─────────────────────────────────────────────────────────────

    /**
     * استعلام عن رصيد وبيانات مشترك يمن موبايل
     * ServiceNumber=101
     */
    public function getMobileBalance($networkNumber, $serviceNumber, $mobileNumber) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول للـ API'];
        return $this->post($this->baseUrl, [
            'NetworkNumber' => $networkNumber,
            'ServiceNumber' => $serviceNumber,
            'MobileNumber'  => $mobileNumber,
            'TransactionID' => $this->generateTxId(),
        ]);
    }

    /**
     * استعلام حالة السلفة - يمن موبايل فقط
     * ServiceNumber=102
     */
    public function getLoanStatus($mobileNumber) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول'];
        return $this->post($this->baseUrl, [
            'NetworkNumber' => 1,
            'ServiceNumber' => 102,
            'MobileNumber'  => $mobileNumber,
            'TransactionID' => $this->generateTxId(),
        ]);
    }

    /**
     * استعلام الباقات - يمن موبايل
     * ServiceNumber=107
     */
    public function getMobileOffers($mobileNumber) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول'];
        return $this->post($this->baseUrl, [
            'NetworkNumber' => 1,
            'ServiceNumber' => 107,
            'MobileNumber'  => $mobileNumber,
            'TransactionID' => $this->generateTxId(),
        ]);
    }

    /**
     * استعلام رصيد المزود (رصيد الكبينة)
     */
    public function getAgentBalance() {
        if (!$this->login()) return null;
        $res = $this->post($this->baseUrl, [
            'NetworkNumber' => 0,
            'ServiceNumber' => 1,
        ]);
        return $res && $res['status'] ? $res['agentBalance'] : null;
    }

    /**
     * فحص حالة عملية سابقة
     */
    public function getOperationStatus($transactionId) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول'];
        return $this->post($this->baseUrl, [
            'NetworkNumber' => 0,
            'ServiceNumber' => 2,
            'TransactionID' => $transactionId,
        ]);
    }

    // ─── عمليات الدفع ──────────────────────────────────────────────────────────

    /**
     * شحن رصيد (مبلغ حر)
     */
    public function payBalance($networkNumber, $serviceNumber, $mobileNumber, $amount, $txId, $webhookUrl = null) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول'];
        $data = [
            'NetworkNumber' => $networkNumber,
            'ServiceNumber' => $serviceNumber,
            'MobileNumber'  => $mobileNumber,
            'Amount'        => $amount,
            'TransactionID' => $txId,
        ];
        if ($webhookUrl) {
            $data['WebHookURL']  = $webhookUrl;
            $data['WebHookCode'] = 'KC_' . $txId;
        }
        return $this->post($this->baseUrl, $data);
    }

    /**
     * تفعيل باقة (عرض)
     */
    public function payOffer($networkNumber, $serviceNumber, $mobileNumber, $offerCode, $txId, $webhookUrl = null) {
        if (!$this->login()) return ['status' => false, 'message' => 'فشل تسجيل الدخول'];
        $data = [
            'NetworkNumber' => $networkNumber,
            'ServiceNumber' => $serviceNumber,
            'MobileNumber'  => $mobileNumber,
            'OfferCode'     => $offerCode,
            'TransactionID' => $txId,
        ];
        if ($webhookUrl) {
            $data['WebHookURL']  = $webhookUrl;
            $data['WebHookCode'] = 'KO_' . $txId;
        }
        return $this->post($this->baseUrl, $data);
    }

    // ─── تحديد الشبكة من الرقم ────────────────────────────────────────────────

    /**
     * تعرّف على شركة الاتصال من رقم الهاتف
     */
    public function detectNetwork($pdo, $mobileNumber) {
        $mobile = preg_replace('/[^0-9]/', '', $mobileNumber);
        $networks = $pdo->query("SELECT * FROM telecom_networks WHERE status=1 ORDER BY sort_order")->fetchAll();
        foreach ($networks as $net) {
            if ($net['prefix_pattern'] && preg_match('/' . $net['prefix_pattern'] . '/', $mobile)) {
                return $net;
            }
        }
        return null;
    }

    // ─── مساعدات ────────────────────────────────────────────────────────────────

    private function generateTxId() {
        return 'KC' . time() . rand(100, 999);
    }

    private function post($url, $data, $withAuth = true) {
        $headers = ['Content-Type: application/json'];
        if ($withAuth && $this->accessToken) {
            $headers[] = 'api-token: ' . $this->accessToken;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) return ['status' => false, 'message' => 'خطأ في الاتصال: ' . $err];
        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) return ['status' => false, 'message' => 'خطأ في استجابة الـ API'];
        return $decoded;
    }

    /**
     * تنسيق معلومات الرصيد حسب نوع الشبكة
     */
    public static function formatBalanceResponse($network, $apiResponse) {
        if (!$apiResponse || !$apiResponse['status']) return null;
        $info = [];
        switch ((int)$network['network_number']) {
            case 1: // يمن موبايل
                $info['balance']       = $apiResponse['mobileBalance'] ?? null;
                $info['type']          = $apiResponse['mobileTypeName'] ?? null;
                $info['available_credit'] = $apiResponse['availableCredit'] ?? null;
                break;
            case 5: // ADSL
                $info['balance']      = $apiResponse['mobileBalance'] ?? null;
                $info['expiry']       = $apiResponse['expiredDate'] ?? null;
                $info['offer_amount'] = $apiResponse['offerAmount'] ?? null;
                $info['min_amount']   = $apiResponse['minAmount'] ?? null;
                break;
            case 6: // هاتف أرضي
                $info['balance'] = $apiResponse['mobileBalance'] ?? null;
                break;
            case 7: // يمن 4G
                $info['balance']      = $apiResponse['mobileBalance'] ?? null;
                $info['expiry']       = $apiResponse['expiredDate'] ?? null;
                $info['offer_amount'] = $apiResponse['offerAmount'] ?? null;
                $info['call_balance'] = $apiResponse['callBalance'] ?? null;
                break;
            default:
                $info['balance'] = $apiResponse['mobileBalance'] ?? null;
        }
        return $info;
    }
}
