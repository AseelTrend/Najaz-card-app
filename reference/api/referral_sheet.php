<?php
/**
 * api/referral_sheet.php
 * كشف عمولات المُحيل لمُحال محدد
 */
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'غير مسجّل دخول']);
    exit;
}

$myId       = (int)$_SESSION['user_id'];
$referredId = (int)($_POST['referred_id'] ?? 0);

if ($referredId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'معرّف غير صالح']);
    exit;
}

// التحقق أن هذا الشخص مُحال فعلاً من المستخدم الحالي
$check = $pdo->prepare("SELECT id, reward_percent FROM referrals WHERE referrer_id = ? AND referred_id = ?");
$check->execute([$myId, $referredId]);
$referralRow = $check->fetch();
if (!$referralRow) {
    echo json_encode(['ok' => false, 'error' => 'غير مصرح']);
    exit;
}

$rewardPercent = (float)($referralRow['reward_percent'] ?? 0);

try {
    // الاستعلام الصحيح:
    // orders ليس فيه service_name — يحتاج JOIN مع services
    // orders.total_price هو مبلغ الطلب
    $stmt = $pdo->prepare("
        SELECT
            wt.amount        AS commission,
            wt.created_at,
            wt.reference_id  AS order_id,
            o.ref_id,
            o.total_price    AS order_amount,
            s.name           AS service_name
        FROM wallet_transactions wt
        INNER JOIN orders o
            ON o.id = wt.reference_id
            AND o.user_id = ?
        LEFT JOIN services s
            ON s.id = o.service_id
        WHERE wt.user_id = ?
          AND wt.type = 'referral'
        ORDER BY wt.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$referredId, $myId]);
    $rows = $stmt->fetchAll();

    $total = 0;
    $out   = [];

    foreach ($rows as $row) {
        $commission = (float)$row['commission'];
        $total     += $commission;

        // رقم الطلب
        $orderNum = !empty($row['ref_id'])
            ? strtoupper($row['ref_id'])
            : 'ORD-' . str_pad($row['order_id'] ?? 0, 5, '0', STR_PAD_LEFT);

        // تمويه اسم الخدمة: أول حرفين + نجوم
        $rawName = $row['service_name'] ?? '---';
        $chars   = mb_str_split($rawName);
        $visible = implode('', array_slice($chars, 0, 2));
        $hidden  = count($chars) > 2 ? str_repeat('•', min(count($chars) - 2, 6)) : '';
        $masked  = $visible . $hidden;

        // النسبة الفعلية
        $orderAmt = (float)($row['order_amount'] ?? 0);
        $pct      = $rewardPercent;
        if ($orderAmt > 0 && $commission > 0) {
            $calcPct = round($commission / $orderAmt * 100, 2);
            if ($calcPct > 0) $pct = $calcPct;
        }

        $out[] = [
            'order_num'      => htmlspecialchars($orderNum),
            'service_masked' => htmlspecialchars($masked),
            'order_amount'   => number_format($orderAmt, 2),
            'percent'        => number_format($pct, 1),
            'commission'     => number_format($commission, 4),
            'date'           => date('d/m H:i', strtotime($row['created_at'])),
        ];
    }

    echo json_encode([
        'ok'    => true,
        'rows'  => $out,
        'total' => number_format($total, 4),
        'count' => count($out),
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
