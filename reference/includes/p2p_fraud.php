<?php
/**
 * includes/p2p_fraud.php — نظام كشف الاحتيال P2P
 * يُستدعى من api/p2p.php عند كل عملية حساسة
 */

class P2PFraudEngine {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ══ تسجيل IP ══
    public function logIP(int $userId, string $action, int $orderId = 0): void {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        try {
            $this->pdo->prepare("INSERT INTO p2p_ip_log (user_id,ip_address,action,user_agent) VALUES (?,?,?,?)")
                ->execute([$userId, $ip, $action, mb_substr($ua, 0, 500)]);
        } catch(\Exception $e) {}
    }

    // ══ تسجيل حدث مشبوه ══
    public function logFraud(int $userId, string $ruleCode, string $desc, array $details = [], ?int $orderId = null, ?string $autoAction = null): void {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $rule = $this->getRule($ruleCode);
        $severity = $rule['severity'] ?? 'medium';

        try {
            $this->pdo->prepare("INSERT INTO p2p_fraud_logs (user_id,rule_code,severity,description,details,ip_address,order_id,auto_action) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$userId, $ruleCode, $severity, $desc, json_encode($details, JSON_UNESCAPED_UNICODE), $ip, $orderId, $autoAction]);
        } catch(\Exception $e) {}

        // إضافة النقاط
        if ($rule) {
            $this->addScore($userId, (int)$rule['score_add']);
            if ($rule['auto_action'] && $rule['auto_action'] !== 'none') {
                $this->executeAutoAction($userId, $rule['auto_action'], $ruleCode);
            }
        }
    }

    // ══ فحص شامل للمستخدم ══
    public function checkUser(int $userId): array {
        if (!$this->isEnabled()) return ['risk_score' => 0, 'risk_level' => 'safe', 'alerts' => []];

        $alerts = [];
        $scoreAdd = 0;

        // جلب القواعد المفعّلة
        $rules = $this->pdo->query("SELECT * FROM p2p_fraud_rules WHERE enabled=1 ORDER BY sort_order")->fetchAll();

        foreach ($rules as $rule) {
            $triggered = $this->evaluateRule($userId, $rule);
            if ($triggered) {
                $alerts[] = ['code' => $rule['code'], 'name' => $rule['name'], 'severity' => $rule['severity'], 'action' => $rule['auto_action']];
                $scoreAdd += (int)$rule['score_add'];
            }
        }

        // حساب النتيجة النهائية
        $currentScore = $this->getScore($userId);
        $newScore = min(100, $currentScore + $scoreAdd);
        $level = $newScore <= 30 ? 'safe' : ($newScore <= 70 ? 'suspicious' : 'danger');

        // تحديث
        $this->pdo->prepare("INSERT INTO p2p_risk_scores (user_id,risk_score,risk_level,last_calculated) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE risk_score=?, risk_level=?, last_calculated=NOW()")
            ->execute([$userId, $newScore, $level, $newScore, $level]);

        return ['risk_score' => $newScore, 'risk_level' => $level, 'alerts' => $alerts];
    }

    // ══ فحص قبل عملية (buy/sell) ══
    public function canBuy(int $userId): array {
        $risk = $this->getRiskData($userId);
        if ($risk && $risk['is_blocked']) return ['allowed' => false, 'reason' => 'حسابك محظور: ' . ($risk['block_reason'] ?: 'تم حظرك من P2P')];
        if ($risk && $risk['buy_blocked']) return ['allowed' => false, 'reason' => 'تم تقييد صلاحية الشراء مؤقتاً بسبب نشاط مشبوه'];
        return ['allowed' => true];
    }

    public function canSell(int $userId): array {
        $risk = $this->getRiskData($userId);
        if ($risk && $risk['is_blocked']) return ['allowed' => false, 'reason' => 'حسابك محظور'];
        if ($risk && $risk['sell_blocked']) return ['allowed' => false, 'reason' => 'تم تقييد صلاحية البيع مؤقتاً بسبب نشاط مشبوه'];
        return ['allowed' => true];
    }

    // ══ تقييم القواعد ══
    private function evaluateRule(int $userId, array $rule): bool {
        $code = $rule['code'];
        $threshold = (int)$rule['threshold'];
        $window = (int)$rule['time_window']; // بالساعات

        switch ($code) {
            case 'MULTI_DISPUTE_24H':
                $cnt = $this->countInWindow("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id=? OR seller_id=?) AND status='disputed'", $userId, $window);
                return $cnt >= $threshold;

            case 'MULTI_CANCEL_24H':
                $cnt = $this->countInWindow("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id=? OR seller_id=?) AND status='cancelled' AND cancelled_at>=DATE_SUB(NOW(),INTERVAL ? HOUR)", $userId, $window);
                return $cnt >= $threshold;

            case 'INSTANT_DISPUTE':
                // فتح نزاع خلال ساعة من مشاهدة البيانات
                try {
                    $cnt = (int)$this->pdo->prepare("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id=? AND status='disputed' AND buyer_viewed_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, buyer_viewed_at, updated_at) < 60")->execute([$userId]) ? $this->pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id={$userId} AND status='disputed' AND buyer_viewed_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, buyer_viewed_at, updated_at) < 60")->fetchColumn() : 0;
                    return $cnt >= $threshold;
                } catch(\Exception $e) { return false; }

            case 'MULTI_IP_ACCOUNTS':
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
                if (!$ip) return false;
                try {
                    $cnt = (int)$this->pdo->query("SELECT COUNT(DISTINCT user_id) FROM p2p_ip_log WHERE ip_address='{$ip}'")->fetchColumn();
                    return $cnt >= $threshold;
                } catch(\Exception $e) { return false; }

            case 'HIGH_FAIL_RATE':
                try {
                    $total = (int)$this->pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId}")->fetchColumn();
                    if ($total < $threshold) return false;
                    $completed = (int)$this->pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId} AND status='completed'")->fetchColumn();
                    return $total > 0 && ($completed / $total * 100) < 40;
                } catch(\Exception $e) { return false; }

            case 'NEW_ACCOUNT_HIGH_VALUE':
                try {
                    $daysSince = (int)$this->pdo->query("SELECT DATEDIFF(NOW(), created_at) FROM users WHERE id={$userId}")->fetchColumn();
                    if ($daysSince > 7) return false;
                    $total = (float)$this->pdo->query("SELECT COALESCE(SUM(price),0) FROM p2p_orders WHERE buyer_id={$userId} AND created_at>=DATE_SUB(NOW(), INTERVAL {$window} HOUR)")->fetchColumn();
                    return $total > 100; // أكثر من 100$ في أول أسبوع
                } catch(\Exception $e) { return false; }

            case 'RAPID_PURCHASES':
                $cnt = $this->countInWindow("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL ? HOUR)", $userId, $window ?: 1);
                return $cnt >= $threshold;

            default:
                return false;
        }
    }

    // ══ مساعدات ══
    private function countInWindow(string $sql, int $userId, int $hours): int {
        try {
            // بسيط — استبدل ? بالقيم
            $sql2 = str_replace('?', $userId, $sql);
            $sql2 = preg_replace('/INTERVAL \? HOUR/', "INTERVAL {$hours} HOUR", $sql2);
            // fallback: استخدم userId فقط
            $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id={$userId} OR seller_id={$userId}) AND status IN ('disputed','cancelled') AND created_at>=DATE_SUB(NOW(), INTERVAL {$hours} HOUR)")->fetchColumn();
            return $cnt;
        } catch(\Exception $e) { return 0; }
    }

    private function getRule(string $code): ?array {
        try {
            $r = $this->pdo->prepare("SELECT * FROM p2p_fraud_rules WHERE code=? AND enabled=1");
            $r->execute([$code]); return $r->fetch() ?: null;
        } catch(\Exception $e) { return null; }
    }

    private function getScore(int $userId): int {
        try {
            $r = $this->pdo->prepare("SELECT risk_score FROM p2p_risk_scores WHERE user_id=?");
            $r->execute([$userId]); $v = $r->fetchColumn();
            return $v !== false ? (int)$v : 0;
        } catch(\Exception $e) { return 0; }
    }

    private function addScore(int $userId, int $points): void {
        try {
            $current = $this->getScore($userId);
            $new = min(100, $current + $points);
            $level = $new <= 30 ? 'safe' : ($new <= 70 ? 'suspicious' : 'danger');
            $this->pdo->prepare("INSERT INTO p2p_risk_scores (user_id,risk_score,risk_level,last_calculated) VALUES (?,?,?,NOW())
                ON DUPLICATE KEY UPDATE risk_score=?, risk_level=?, last_calculated=NOW()")
                ->execute([$userId, $new, $level, $new, $level]);
        } catch(\Exception $e) {}
    }

    private function getRiskData(int $userId): ?array {
        try {
            $r = $this->pdo->prepare("SELECT * FROM p2p_risk_scores WHERE user_id=?");
            $r->execute([$userId]); return $r->fetch() ?: null;
        } catch(\Exception $e) { return null; }
    }

    private function executeAutoAction(int $userId, string $action, string $ruleCode): void {
        try {
            switch ($action) {
                case 'warn':
                    // فقط تسجيل — التحذير يظهر من الـ risk_level
                    break;
                case 'block_sell':
                    $this->pdo->prepare("INSERT INTO p2p_risk_scores (user_id,sell_blocked) VALUES (?,1) ON DUPLICATE KEY UPDATE sell_blocked=1")->execute([$userId]);
                    break;
                case 'block_buy':
                    $this->pdo->prepare("INSERT INTO p2p_risk_scores (user_id,buy_blocked) VALUES (?,1) ON DUPLICATE KEY UPDATE buy_blocked=1")->execute([$userId]);
                    break;
                case 'block_all':
                    $this->pdo->prepare("INSERT INTO p2p_risk_scores (user_id,is_blocked,block_reason) VALUES (?,1,?) ON DUPLICATE KEY UPDATE is_blocked=1, block_reason=?")
                        ->execute([$userId, "حظر تلقائي: {$ruleCode}", "حظر تلقائي: {$ruleCode}"]);
                    break;
            }
        } catch(\Exception $e) {}
    }

    public function getUserBadge(int $userId): array {
        $risk = $this->getRiskData($userId);
        if (!$risk) return ['badge' => 'new', 'label' => 'مستخدم جديد', 'color' => '#8895a7', 'icon' => 'person_add'];
        $score = (int)$risk['risk_score'];
        if ($risk['is_blocked']) return ['badge' => 'blocked', 'label' => 'محظور', 'color' => '#ff4455', 'icon' => 'block'];
        if ($score <= 30) return ['badge' => 'safe', 'label' => 'موثوق', 'color' => '#00e676', 'icon' => 'verified_user'];
        if ($score <= 70) return ['badge' => 'suspicious', 'label' => 'مشبوه', 'color' => '#f5a623', 'icon' => 'warning'];
        return ['badge' => 'danger', 'label' => 'خطر', 'color' => '#ff4455', 'icon' => 'dangerous'];
    }

    private function isEnabled(): bool {
        try {
            return getSetting('p2p_fraud_enabled') !== '0';
        } catch(\Exception $e) { return true; }
    }
}
