<?php
/**
 * جسر آمن لتشغيل كبينة السداد من تطبيق الموبايل.
 * يستخدم نفس نظام Floosak Agent الذي تعمل به صفحة telecom.php.
 */
require_once __DIR__ . '/_common.php';

// يتحقق من JWT ويملأ $_SESSION للمستخدم في هذا الطلب فقط.
mobileAuthorizeRequest($pdo, true);

header('Content-Type: application/json; charset=utf-8');

function mobileTelecomOk(array $data = []): void {
    echo json_encode(array_merge(['status' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function mobileTelecomErr(string $message, array $data = []): void {
    echo json_encode(array_merge(['status' => false, 'message' => $message], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_REQUEST['action'] ?? ''));

// كشف الشبكة بنفس خريطة PREFIX_MAP المستخدمة في صفحة telecom.php.
if ($action === 'detect_network') {
    $phone = preg_replace('/[^0-9]/', '', (string)($_GET['phone'] ?? ''));
    if (strlen($phone) < 8) mobileTelecomErr('رقم قصير');

    $prefixMap = [
        ['prefixes' => ['77', '78'], 'id' => 1,  'name' => 'يمن موبايل', 'color' => '#cc0000', 'icon' => 'mobile-alt', 'type' => 'TOPUP'],
        ['prefixes' => ['71'],        'id' => 2,  'name' => 'سبأفون',     'color' => '#ff6600', 'icon' => 'mobile-alt', 'type' => 'TOPUP'],
        ['prefixes' => ['73'],        'id' => 3,  'name' => 'يو',         'color' => '#0066cc', 'icon' => 'mobile-alt', 'type' => 'TOPUP'],
        ['prefixes' => ['70'],        'id' => 12, 'name' => 'واي',        'color' => '#800080', 'icon' => 'mobile-alt', 'type' => 'TOPUP'],
    ];

    $prefix = substr($phone, 0, 2);
    foreach ($prefixMap as $net) {
        if (in_array($prefix, $net['prefixes'], true)) {
            try {
                $st = $pdo->prepare("SELECT * FROM floosak_agent_methods WHERE method_id=? AND status=1 LIMIT 1");
                $st->execute([$net['id']]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) $net = array_merge($net, $row);
            } catch (Throwable $e) {}

            $net['id'] = (int)$net['id'];
            $net['method_id'] = (int)$net['id'];
            $net['supports_balance'] = true;
            mobileTelecomOk(['network' => $net]);
        }
    }

    mobileTelecomErr('لم يتم التعرف على الشبكة');
}

// مبالغ الشحن الحر من نفس جدول الباقات المستخدم في صفحة telecom.php.
if ($action === 'get_quick_amounts') {
    $methodId = (int)($_GET['network_id'] ?? 0);
    if (!$methodId) mobileTelecomOk(['amounts' => []]);

    try {
        $st = $pdo->prepare("SELECT id,bunch_id,unified_code,price,section,is_free_amount,payment_type FROM floosak_agent_bunches WHERE method_id=? AND status=1 AND (section='amount' OR is_free_amount=1) ORDER BY sort_order,id");
        $st->execute([$methodId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $amounts = [];
        foreach ($rows as $row) {
            $price = (float)($row['price'] ?? 0);
            if ($price > 0) {
                $amounts[] = [
                    'id' => (int)$row['id'],
                    'bunch_id' => (string)($row['unified_code'] ?: $row['bunch_id'] ?: $row['id']),
                    'amount_yer' => $price,
                    'amount' => $price,
                ];
            }
        }
        mobileTelecomOk(['amounts' => $amounts]);
    } catch (Throwable $e) {
        mobileTelecomOk(['amounts' => []]);
    }
}

// تنفيذ الشحن بنفس معالجة do_topup الموجودة في صفحة telecom.php.
if ($action === 'pay_balance') {
    $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
    $methodId = (int)($_POST['network_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);

    if (strlen($phone) < 7) mobileTelecomErr('رقم الهاتف غير صحيح');
    if (!$methodId) mobileTelecomErr('الشبكة غير معروفة');
    if ($amount <= 0) mobileTelecomErr('المبلغ غير صحيح');

    try {
        $st = $pdo->prepare("SELECT id,bunch_id,unified_code,section,is_free_amount FROM floosak_agent_bunches WHERE method_id=? AND status=1 AND (section='amount' OR is_free_amount=1) ORDER BY CASE WHEN is_free_amount=1 THEN 0 ELSE 1 END, sort_order,id LIMIT 1");
        $st->execute([$methodId]);
        $bunch = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $bunch = false;
    }

    if (!$bunch) mobileTelecomErr('لا توجد فئة شحن حر مهيأة لهذه الشبكة');

    $bunchId = (string)($bunch['unified_code'] ?: $bunch['bunch_id'] ?: $bunch['id']);

    $_POST['ajax_action'] = 'do_topup';
    $_POST['target_number'] = $phone;
    $_POST['method_id'] = $methodId;
    $_POST['bunch_id'] = $bunchId;
    $_POST['amount'] = $amount;
    $_POST['with_solfa'] = 0;
    $_POST['pay_from'] = 'balance';

    // استدعاء صفحة الإنتاج نفسها؛ وهي التي تنفذ الخصم والشحن والتسجيل.
    require dirname(__DIR__, 2) . '/telecom.php';
    exit;
}

mobileTelecomErr('إجراء غير صالح');
