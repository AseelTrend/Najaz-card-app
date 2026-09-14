<?php
// ══════════════════════════════════════════════════════════════
// دوال نظام الإحالة
// ══════════════════════════════════════════════════════════════

/**
 * توليد كود إحالة فريد للمستخدم
 */
function generateReferralCode($pdo, $userId) {
    $existing = $pdo->prepare("SELECT referral_code FROM users WHERE id=?");
    $existing->execute([$userId]); $row = $existing->fetch();
    if ($row && $row['referral_code']) return $row['referral_code'];

    // توليد كود فريد
    do {
        $code = strtoupper(substr(base_convert(sha1(uniqid(mt_rand(), true)), 16, 36), 0, 8));
        $check = $pdo->prepare("SELECT id FROM users WHERE referral_code=?");
        $check->execute([$code]);
    } while ($check->fetch());

    $pdo->prepare("UPDATE users SET referral_code=? WHERE id=?")->execute([$code, $userId]);
    return $code;
}

/**
 * ربط مستخدم جديد بالمُحيل عند التسجيل
 */
function applyReferralOnRegister($pdo, $newUserId, $referralCode) {
    if (!$referralCode) return false;

    // تأكد من وجود الأعمدة والجداول
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(20) DEFAULT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS referred_by INT DEFAULT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `referrals` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `referrer_id` INT NOT NULL,
        `referred_id` INT NOT NULL,
        `status` ENUM('pending','rewarded') DEFAULT 'pending',
        `trigger_type` VARCHAR(50) DEFAULT 'order_percent',
        `reward_referrer` DECIMAL(10,4) DEFAULT 0,
        `reward_percent` DECIMAL(5,2) DEFAULT 0,
        `reward_referred` DECIMAL(10,4) DEFAULT 0,
        `topup_amount` DECIMAL(10,4) DEFAULT 0,
        `commission_paid` DECIMAL(10,4) DEFAULT 0,
        `rewarded_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_referral` (`referrer_id`,`referred_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

    // البحث عن المُحيل بالكود
    $ref = $pdo->prepare("SELECT id FROM users WHERE referral_code=? AND id != ?");
    $ref->execute([strtoupper(trim($referralCode)), $newUserId]);
    $referrer = $ref->fetch();
    if (!$referrer) return false;

    $referrerId = (int)$referrer['id'];

    // ربط المستخدم بالمُحيل
    try {
        $pdo->prepare("UPDATE users SET referred_by=? WHERE id=?")->execute([$referrerId, $newUserId]);
    } catch(Exception $e) { return false; }

    // رصيد ترحيبي للصديق الجديد
    $welcomeReward = (float)getSetting('referral_welcome');
    if ($welcomeReward > 0) {
        $ub = $pdo->prepare("SELECT balance FROM users WHERE id=?"); $ub->execute([$newUserId]); $ub=$ub->fetch();
        $balBefore = (float)($ub['balance'] ?? 0);
        $balAfter  = $balBefore + $welcomeReward;
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$balAfter, $newUserId]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?)")
            ->execute([$newUserId,'referral_welcome',$welcomeReward,$balBefore,$balAfter,"🎁 رصيد ترحيبي — دعوة صديق"]);
        // إشعار للصديق الجديد
        try { sendNotification($pdo,$newUserId,'admin','🎁 رصيد ترحيبي!', "تم إضافة ".number_format($welcomeReward,2)."$ كرصيد ترحيبي لانضمامك عبر دعوة صديق",'gift','#00c853'); } catch(Exception $e) {}
    }

    // تسجيل الإحالة مع نوع العمولة
    $rewardType   = getSetting('referral_reward_type') ?: 'order_percent';
    $fixedReward  = (float)getSetting('referral_fixed_reward');
    $percentReward= (float)getSetting('referral_percent');
    try {
        $pdo->prepare("INSERT IGNORE INTO referrals (referrer_id,referred_id,status,trigger_type,reward_referrer,reward_percent,reward_referred) VALUES (?,?,'pending',?,?,?,?)")
            ->execute([$referrerId,$newUserId,$rewardType,$fixedReward,$percentReward,$welcomeReward]);
    } catch(Exception $e) {}

    return true;
}

/**
 * تطبيق العمولة — يُستدعى عند كل طلب خدمة
 * يتصرف حسب نوع العمولة المحدد في الإعدادات
 */
function applyReferralOnOrder($pdo, $userId, $orderAmount, $orderId) {
    if (!getSetting('referral_enabled')) return;

    $u = $pdo->prepare("SELECT referred_by FROM users WHERE id=?");
    $u->execute([$userId]); $u = $u->fetch();
    if (!$u || !$u['referred_by']) return;

    $referrerId = (int)$u['referred_by'];

    $r = $pdo->prepare("SELECT * FROM referrals WHERE referrer_id=? AND referred_id=?");
    $r->execute([$referrerId, $userId]); $referral = $r->fetch();
    if (!$referral) return;

    $rewardType = $referral['trigger_type'] ?: 'order_percent';

    // نوع: عمولة على كل طلب فقط
    if ($rewardType !== 'order_percent') return;

    // منع التكرار — هل دُفعت عمولة لهذا الطلب مسبقاً؟
    $dupCheck = $pdo->prepare("SELECT id FROM wallet_transactions WHERE user_id=? AND type='referral' AND reference_id=? LIMIT 1");
    $dupCheck->execute([$referrerId, $orderId]);
    if ($dupCheck->fetch()) return; // مدفوعة مسبقاً

    // ── أولويات النسبة — المخصص يُلغي العام تماماً ──
    $finalPercent      = 0;
    $serviceId         = 0;
    $referrerHasCustom = false; // هل للمحيل تعيين مخصص؟
    $serviceHasCustom  = false; // هل للخدمة تعيين مخصص؟

    // جلب service_id من الطلب
    try {
        $svcRow = $pdo->prepare("SELECT service_id FROM orders WHERE id=?");
        $svcRow->execute([$orderId]); $svcRow = $svcRow->fetch();
        $serviceId = (int)($svcRow['service_id'] ?? 0);
    } catch(Exception $e) {}

    try {
        // ── فحص هل للمحيل أي تعيين مخصص (1 أو 2) ──
        if ($referrerId) {
            $chkRef = $pdo->prepare("SELECT id FROM referral_rates WHERE referrer_id=? AND custom_percent>0 LIMIT 1");
            $chkRef->execute([$referrerId]); 
            $referrerHasCustom = (bool)$chkRef->fetch();
        }

        // ── فحص هل للخدمة تعيين مخصص (3) ──
        if ($serviceId) {
            $chkSvc = $pdo->prepare("SELECT id FROM referral_rates WHERE referrer_id IS NULL AND service_id=? AND custom_percent>0 LIMIT 1");
            $chkSvc->execute([$serviceId]);
            $serviceHasCustom = (bool)$chkSvc->fetch();
        }

        if ($referrerHasCustom) {
            // ── المحيل له تعيين مخصص → لا يُطبَّق عليه إلا أولوياته (1، 2) ──

            // 1. محيل معين + خدمة معينة
            if ($serviceId) {
                $r1 = $pdo->prepare("SELECT custom_percent FROM referral_rates WHERE referrer_id=? AND service_id=? AND custom_percent>0");
                $r1->execute([$referrerId, $serviceId]); $r1 = $r1->fetch();
                if ($r1) $finalPercent = (float)$r1['custom_percent'];
            }
            // 2. محيل معين + جميع الخدمات (fallback داخل نطاق المحيل فقط)
            if ($finalPercent <= 0) {
                $r2 = $pdo->prepare("SELECT custom_percent FROM referral_rates WHERE referrer_id=? AND service_id IS NULL AND custom_percent>0");
                $r2->execute([$referrerId]); $r2 = $r2->fetch();
                if ($r2) $finalPercent = (float)$r2['custom_percent'];
            }

        } elseif ($serviceHasCustom) {
            // ── الخدمة لها تعيين مخصص والمحيل ليس له → طبّق نسبة الخدمة فقط ──
            // 3. جميع المحيلين + خدمة معينة
            $r3 = $pdo->prepare("SELECT custom_percent FROM referral_rates WHERE referrer_id IS NULL AND service_id=? AND custom_percent>0");
            $r3->execute([$serviceId]); $r3 = $r3->fetch();
            if ($r3) $finalPercent = (float)$r3['custom_percent'];

        } else {
            // ── لا يوجد أي تعيين مخصص → طبّق النسبة العامة أو الافتراضية ──

            // 4. جميع المحيلين + جميع الخدمات
            $r4 = $pdo->prepare("SELECT custom_percent FROM referral_rates WHERE referrer_id IS NULL AND service_id IS NULL AND custom_percent>0 LIMIT 1");
            $r4->execute(); $r4 = $r4->fetch();
            if ($r4) $finalPercent = (float)$r4['custom_percent'];
        }

    } catch(Exception $e) {}

    // 5. الافتراضي — فقط إذا لم يُحدَّد شيء من الأعلى
    if ($finalPercent <= 0) {
        $finalPercent = (float)$referral['reward_percent'];
    }

    if ($finalPercent <= 0) return;

    $commission = round($orderAmount * $finalPercent / 100, 4);
    if ($commission <= 0) return;

    _payReferralCommission($pdo, $referrerId, $userId, $commission,
        "🤝 عمولة إحالة — طلب #{$orderId} (".number_format($finalPercent,1)."% من ".number_format($orderAmount,4)."$)",
        $orderId);
}

/**
 * تطبيق العمولة عند أول شحن رصيد
 */
function applyReferralOnTopup($pdo, $userId, $topupAmountUSD) {
    if (!getSetting('referral_enabled')) return;

    $u = $pdo->prepare("SELECT referred_by FROM users WHERE id=?");
    $u->execute([$userId]); $u = $u->fetch();
    if (!$u || !$u['referred_by']) return;

    $referrerId = (int)$u['referred_by'];

    $r = $pdo->prepare("SELECT * FROM referrals WHERE referrer_id=? AND referred_id=?");
    $r->execute([$referrerId, $userId]); $referral = $r->fetch();
    if (!$referral || $referral['status'] === 'rewarded') return;

    $rewardType = $referral['trigger_type'] ?: 'order_percent';

    // نوع: مبلغ ثابت عند أول شحن
    if ($rewardType === 'first_topup_fixed') {
        $commission = round((float)$referral['reward_referrer'], 4);
        if ($commission <= 0) return;
        _payReferralCommission($pdo, $referrerId, $userId, $commission,
            "🤝 عمولة إحالة — أول شحن للصديق #$userId (مبلغ ثابت: ".number_format($commission,2)."$)",
            $userId);
        $pdo->prepare("UPDATE referrals SET status='rewarded',topup_amount=?,rewarded_at=NOW() WHERE referrer_id=? AND referred_id=?")
            ->execute([$topupAmountUSD,$referrerId,$userId]);
    }
    // نوع: نسبة % من أول شحن
    elseif ($rewardType === 'first_topup_percent') {
        $pct = (float)$referral['reward_percent'];
        if ($pct <= 0) return;
        $commission = round($topupAmountUSD * $pct / 100, 4);
        if ($commission <= 0) return;
        _payReferralCommission($pdo, $referrerId, $userId, $commission,
            "🤝 عمولة إحالة — أول شحن للصديق #$userId (".number_format($pct,1)."% من ".number_format($topupAmountUSD,2)."$)",
            $userId);
        $pdo->prepare("UPDATE referrals SET status='rewarded',topup_amount=?,rewarded_at=NOW() WHERE referrer_id=? AND referred_id=?")
            ->execute([$topupAmountUSD,$referrerId,$userId]);
    }
}

/**
 * سحب عمولة الإحالة عند إلغاء/فشل الطلب
 */
function revokeReferralOnOrder($pdo, $orderId) {
    try {
        // هل توجد معاملة عمولة لهذا الطلب؟
        $tx = $pdo->prepare("SELECT * FROM wallet_transactions WHERE type='referral' AND reference_id=? LIMIT 1");
        $tx->execute([$orderId]); $tx = $tx->fetch();
        if (!$tx) return; // لا توجد عمولة لهذا الطلب

        $referrerId = $tx['user_id'];
        $commission = (float)$tx['amount'];

        // خصم من رصيد المُحيل
        $rb = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $rb->execute([$referrerId]); $rb = $rb->fetch();
        $balBefore = (float)($rb['balance'] ?? 0);
        $balAfter  = max(0, $balBefore - $commission); // لا يصبح سالباً

        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$balAfter, $referrerId]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$referrerId, 'referral_revoke', $commission, $balBefore, $balAfter,
                "↩️ استرداد عمولة إحالة — طلب #$orderId تم إلغاؤه", $orderId]);

        // تحديث سجل الإحالة
        $pdo->prepare("UPDATE referrals SET commission_paid=GREATEST(0,commission_paid-?) WHERE referrer_id=?")
            ->execute([$commission, $referrerId]);

        // إشعار للمُحيل
        try {
            sendNotification($pdo, $referrerId, 'admin',
                '↩️ تم خصم عمولة إحالة',
                'تم إلغاء طلب مُحال (#'.$orderId.') وخصم '.number_format($commission,4).'$ من رصيدك',
                'undo', '#ff4455');
        } catch(Exception $e) {}
    } catch(Exception $e) {}
}

/**
 * دفع العمولة للمُحيل وتحديث السجلات (مشترك)
 */
function _payReferralCommission($pdo, $referrerId, $userId, $commission, $desc, $refId) {
    $rb = $pdo->prepare("SELECT balance FROM users WHERE id=?");
    $rb->execute([$referrerId]); $rb = $rb->fetch();
    $balBefore = (float)($rb['balance'] ?? 0);
    $balAfter  = $balBefore + $commission;

    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$balAfter, $referrerId]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$referrerId,'referral',$commission,$balBefore,$balAfter,$desc,$refId]);
    $pdo->prepare("UPDATE referrals SET commission_paid=commission_paid+?, rewarded_at=NOW() WHERE referrer_id=? AND referred_id=?")
        ->execute([$commission,$referrerId,$userId]);

    try {
        sendNotification($pdo,$referrerId,'admin',
            '🤝 عمولة إحالة +'.number_format($commission,4).'$',
            $desc,'users','#00c853');
    } catch(Exception $e) {}
}
