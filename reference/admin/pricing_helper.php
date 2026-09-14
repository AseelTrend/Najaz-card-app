<?php
/**
 * pricing_helper.php
 * ─────────────────────────────────────────────────────────────────
 * نظام مجموعات التسعير — دوال مساعدة
 * ضع هذا الملف في: includes/pricing_helper.php
 */

/**
 * جلب السعر النهائي للخدمة للمستخدم
 *
 * الأولوية:
 *  1. سعر ثابت مخصص للخدمة في مجموعة المستخدم  → يُستخدم مباشرة
 *  2. نسبة % مخصصة للخدمة في مجموعة المستخدم  → تُطبق على السعر الأصلي
 *  3. نسبة % الافتراضية للمجموعة               → تُطبق على السعر الأصلي
 *  4. لا مجموعة / مجموعة بدون تسعير             → السعر الأصلي
 *
 * @param PDO   $pdo
 * @param int   $userId
 * @param array $service  صف من جدول services
 * @return array ['price'=>float, 'original_price'=>float, 'group_id'=>int|null, 'group_name'=>string|null, 'rule_type'=>string|null]
 */
function getUserServicePrice(PDO $pdo, int $userId, array $service): array
{
    $originalPrice = (float)$service['price'];
    $base = [
        'price'          => $originalPrice,
        'original_price' => $originalPrice,
        'group_id'       => null,
        'group_name'     => null,
        'rule_type'      => null,
        'discount_pct'   => null,
    ];

    // جلب مجموعة المستخدم
    try {
        $stmt = $pdo->prepare("SELECT u.group_id, pg.name as group_name, pg.default_type, pg.default_value, pg.status
                               FROM users u
                               LEFT JOIN pricing_groups pg ON pg.id = u.group_id AND pg.status = 1
                               WHERE u.id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $base;
    }

    if (!$user || empty($user['group_id'])) {
        return $base;
    }

    $base['group_id']   = (int)$user['group_id'];
    $base['group_name'] = $user['group_name'];

    // هل هناك قاعدة مخصصة لهذه الخدمة بالذات؟
    try {
        $rule = $pdo->prepare("SELECT rule_type, rule_value
                               FROM pricing_group_rules
                               WHERE group_id = ? AND service_id = ?
                               LIMIT 1");
        $rule->execute([$user['group_id'], $service['id']]);
        $rule = $rule->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rule = null;
    }

    if ($rule) {
        // قاعدة مخصصة للخدمة
        if ($rule['rule_type'] === 'fixed') {
            // سعر ثابت مخصص
            $base['price']     = (float)$rule['rule_value'];
            $base['rule_type'] = 'fixed';
        } elseif ($rule['rule_type'] === 'percent') {
            // نسبة % مخصصة للخدمة (موجب = زيادة، سالب = خصم)
            $pct = (float)$rule['rule_value'];
            $base['price']       = $originalPrice * (1 + $pct / 100);
            $base['rule_type']   = 'percent';
            $base['discount_pct'] = $pct;
        }
    } elseif (!empty($user['default_type']) && $user['default_value'] !== null) {
        // النسبة الافتراضية للمجموعة
        if ($user['default_type'] === 'percent') {
            $pct = (float)$user['default_value'];
            $base['price']       = $originalPrice * (1 + $pct / 100);
            $base['rule_type']   = 'group_default';
            $base['discount_pct'] = $pct;
        }
    }

    // السعر لا يكون أقل من 0
    $base['price'] = max(0, round($base['price'], 10));

    return $base;
}

/**
 * دالة مختصرة — تعيد السعر النهائي فقط
 */
function getServicePrice(PDO $pdo, int $userId, array $service): float
{
    return getUserServicePrice($pdo, $userId, $service)['price'];
}
