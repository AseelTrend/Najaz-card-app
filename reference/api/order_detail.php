<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL"); } catch(Exception $e) {}

// جداول الاعتراضات
$pdo->exec("CREATE TABLE IF NOT EXISTS `order_objections` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `order_id`   INT NOT NULL,
  `user_id`    INT NOT NULL,
  `reason`     TEXT NOT NULL,
  `status`     ENUM('pending','reviewing','resolved','rejected') DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_order` (`order_id`),
  KEY `idx_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$id = (int)($_GET['id'] ?? 0);
$source = strtolower(trim((string)($_GET['source'] ?? 'standard')));
if (!$id) { echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }

// الطلبات غير العامة لها جداولها الأصلية؛ نرجعها بنفس البنية التي تستخدمها واجهة التفاصيل.
if ($source === 'telecom') {
    try {
        $stmt = $pdo->prepare("SELECT t.*, n.name AS network_name, o.name AS offer_name
            FROM telecom_orders t
            JOIN telecom_networks n ON n.id=t.network_id
            LEFT JOIN telecom_offers o ON o.id=t.offer_id
            WHERE t.id=? AND t.user_id=?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        $t = $stmt->fetch();
        if (!$t) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        $rawStatus = strtolower((string)($t['status'] ?? 'pending'));
        $status = in_array($rawStatus, ['pending','processing'], true) ? $rawStatus :
            (in_array($rawStatus, ['completed','failed','cancelled'], true) ? $rawStatus : 'processing');
        $rate = (float)($t['exchange_rate'] ?? 0);
        $chargeYer = (float)($t['cost_yer'] ?? 0);
        if ($chargeYer <= 0) $chargeYer = (float)($t['amount_yer'] ?? 0);
        $totalUsd = ($rate > 0 && $chargeYer > 0) ? round($chargeYer / $rate, 4) : 0;
        $order = [
            'id'=>$id, 'ref_id'=>'ID_TEL_'.$id, 'source_type'=>'telecom',
            'service_name'=>(($t['order_type'] ?? '') === 'offer' && !empty($t['offer_name']) ? 'اتصالات — '.$t['offer_name'] : 'اتصالات — شحن رصيد'),
            'service_image'=>'', 'cat_name'=>'الاتصالات', 'cat_icon'=>'mobile-alt',
            'status'=>$status, 'status_message'=>$t['provider_msg'] ?: 'طلب اتصالات',
            'total_price'=>$totalUsd, 'quantity'=>1, 'field_data'=>json_encode([
                'الشبكة'=>$t['network_name'] ?? '', 'الهاتف'=>$t['mobile_number'] ?? '',
                'المبلغ بالريال'=>$t['amount_yer'] ?? '',
                'النوع'=>(($t['order_type'] ?? '') === 'offer' ? 'باقة' : 'شحن رصيد')
            ], JSON_UNESCAPED_UNICODE), 'provider_order_id'=>$t['provider_ref_id'] ?? null,
            'created_at'=>$t['created_at'], 'updated_at'=>$t['updated_at'] ?? $t['created_at']
        ];
        echo json_encode(['ok'=>true,'order'=>$order,'log'=>[],'response_seconds'=>null,'objection'=>null,'numbersapp'=>null], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'تعذر تحميل تفاصيل طلب الاتصالات'], JSON_UNESCAPED_UNICODE); exit;
    }
}

if ($source === 'p2p') {
    try {
        $stmt = $pdo->prepare("SELECT p.*, s.title AS service_title, s.image AS service_image,
                COALESCE(pc.name,'P2P') AS category_name
            FROM p2p_orders p
            JOIN p2p_services s ON s.id=p.service_id
            LEFT JOIN p2p_categories pc ON pc.id=s.category_id
            WHERE p.id=? AND (p.buyer_id=? OR p.seller_id=?)");
        $stmt->execute([$id, $_SESSION['user_id'], $_SESSION['user_id']]);
        $p = $stmt->fetch();
        if (!$p) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        $rawStatus = strtolower((string)($p['status'] ?? 'pending'));
        $status = in_array($rawStatus, ['active','disputed'], true) ? 'processing' :
            (in_array($rawStatus, ['pending','completed','cancelled'], true) ? $rawStatus : 'processing');
        $role = ((int)$p['buyer_id'] === (int)$_SESSION['user_id']) ? 'مشتري' : 'بائع';
        $order = [
            'id'=>$id, 'ref_id'=>'ID_P2P_'.$id, 'source_type'=>'p2p',
            'service_name'=>'P2P — '.($p['service_title'] ?: 'طلب سوق'), 'service_image'=>$p['service_image'] ?? '',
            'cat_name'=>$p['category_name'] ?: 'P2P', 'cat_icon'=>'exchange-alt',
            'status'=>$status, 'status_message'=>$rawStatus === 'disputed' ? 'طلب P2P قيد النزاع' : 'طلب P2P — '.$role,
            'total_price'=>(float)($p['buyer_total'] ?? $p['price'] ?? 0), 'quantity'=>1,
            'field_data'=>json_encode(['الدور'=>$role,'سعر الخدمة'=>$p['price'] ?? 0,'العمولة'=>$p['commission'] ?? 0], JSON_UNESCAPED_UNICODE),
            'provider_order_id'=>null, 'created_at'=>$p['created_at'], 'updated_at'=>$p['updated_at'] ?? $p['created_at']
        ];
        echo json_encode(['ok'=>true,'order'=>$order,'log'=>[],'response_seconds'=>null,'objection'=>null,'numbersapp'=>null], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'تعذر تحميل تفاصيل طلب P2P'], JSON_UNESCAPED_UNICODE); exit;
    }
}

$stmt = $pdo->prepare("
    SELECT o.*, s.name as service_name, s.image as service_image,
           c.name as cat_name, c.icon as cat_icon
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.id=? AND o.user_id=?
");
$stmt->execute([$id, $_SESSION['user_id']]);
$order = $stmt->fetch();

if (!$order) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
$order['ref_id'] = !empty($order['ref_id']) ? strtoupper($order['ref_id']) : 'ID_ORD_'.$id;
$order['source_type'] = 'standard';

// حساب مدة الاستجابة (من created_at لأول status=completed في السجل)
$responseSeconds = null;
$log = [];
try {
    $ls = $pdo->prepare("SELECT * FROM order_status_log WHERE order_id=? ORDER BY created_at ASC");
    $ls->execute([$id]);
    $log = $ls->fetchAll();

    foreach ($log as $lg) {
        if ($lg['status'] === 'completed') {
            $start = strtotime($order['created_at']);
            $end   = strtotime($lg['created_at']);
            if ($start && $end && $end >= $start) {
                $responseSeconds = $end - $start;
            }
            break;
        }
    }
    // إذا الطلب مكتمل ولم يكن في السجل — احسب من updated_at
    if ($responseSeconds === null && $order['status'] === 'completed' && !empty($order['updated_at'])) {
        $start = strtotime($order['created_at']);
        $end   = strtotime($order['updated_at']);
        if ($start && $end && $end > $start) {
            $responseSeconds = $end - $start;
        }
    }
} catch (Exception $e) {}

// هل يوجد اعتراض مسبق؟
$existingObjection = null;
try {
    $ob = $pdo->prepare("SELECT id, status, reason, admin_note FROM order_objections WHERE order_id=? AND user_id=? LIMIT 1");
    $ob->execute([$id, $_SESSION['user_id']]);
    $existingObjection = $ob->fetch() ?: null;
} catch (Exception $e) {}

// تقديم اعتراض جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_objection'])) {
    $reason = trim($_POST['reason'] ?? '');
    if (mb_strlen($reason) < 5) { echo json_encode(['ok'=>false,'error'=>'الرجاء كتابة سبب الاعتراض (5 أحرف على الأقل)']); exit; }
    if ($existingObjection) { echo json_encode(['ok'=>false,'error'=>'لديك اعتراض مقدم مسبقاً على هذا الطلب']); exit; }
    if ($order['status'] !== 'completed') { echo json_encode(['ok'=>false,'error'=>'الاعتراض متاح فقط للطلبات المكتملة']); exit; }

    $pdo->prepare("INSERT INTO order_objections (order_id,user_id,reason) VALUES (?,?,?)")
        ->execute([$id, $_SESSION['user_id'], $reason]);
    echo json_encode(['ok'=>true,'msg'=>'✅ تم تقديم اعتراضك بنجاح، سيتم مراجعته قريباً']);
    exit;
}

// بيانات NumbersApp الإضافية (رقم، كود، منتجات مخزن)
$numbersappInfo = null;
try {
    $naCol = $pdo->prepare("SELECT numbersapp_data FROM orders WHERE id=?");
    $naCol->execute([$id]);
    $naRaw = $naCol->fetchColumn();
    if ($naRaw) $numbersappInfo = json_decode($naRaw, true);
} catch(Exception $e) {}

echo json_encode([
    'ok'               => true,
    'order'            => $order,
    'log'              => $log,
    'response_seconds' => $responseSeconds,
    'objection'        => $existingObjection,
    'numbersapp'       => $numbersappInfo,
], JSON_UNESCAPED_UNICODE);
