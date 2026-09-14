<?php
/**
 * Cron Job - تحديث حالة الطلبات من المزودين
 * يتم تشغيله كل 5 دقائق من cPanel
 * أو تشغيله يدوياً من لوحة التحكم
 */

require_once '../includes/config.php';
require_once dirname(__DIR__) . '/includes/wallet_ledger_helper.php';

// حماية بسيطة
$secret = $_GET['secret'] ?? '';
if ($secret !== 'admin123' && PHP_SAPI !== 'cli') {
    die('Unauthorized');
}

$results = [];

// جلب الطلبات المعلقة المرتبطة بمزودين
$orders = $pdo->query("
    SELECT o.*, p.api_url, p.api_key
    FROM orders o
    JOIN services s ON o.service_id=s.id
    JOIN providers p ON s.provider_id=p.id
    WHERE o.status IN ('pending','processing') 
    AND o.provider_order_id IS NOT NULL
    AND o.provider_order_id != ''
    LIMIT 50
")->fetchAll();

foreach ($orders as $order) {
    $data = [
        'key'    => $order['api_key'],
        'action' => 'status',
        'order'  => $order['provider_order_id']
    ];

    $ch = curl_init($order['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($result && isset($result['status'])) {
        $providerStatus = strtolower($result['status']);
        
        // تحويل حالة المزود إلى حالة النظام
        $newStatus = 'processing';
        if (in_array($providerStatus, ['completed','complete','delivered'])) {
            $newStatus = 'completed';
        } elseif (in_array($providerStatus, ['cancelled','canceled','refunded'])) {
            $newStatus = 'cancelled';
        } elseif (in_array($providerStatus, ['partial'])) {
            $newStatus = 'completed';
        } elseif (in_array($providerStatus, ['failed','error'])) {
            $newStatus = 'failed';
        }

        if ($newStatus !== $order['status']) {
            $pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $order['id']]);
            
            // في حالة الإلغاء، أعد الرصيد
            if ($newStatus === 'cancelled' || $newStatus === 'failed') {
                $userStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $userStmt->execute([$order['user_id']]);
                $u = $userStmt->fetch();
                if ($u) {
                    $newBal = $u['balance'] + $order['total_price'];
                    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $u['id']]);
                    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$u['id'],'credit',$order['total_price'],$u['balance'],$newBal,'استرداد تلقائي - الطلب #'.$order['id'],$order['id']]);
                }
            }

            $results[] = "طلب #{$order['id']}: {$order['status']} → {$newStatus}";
        }
    }
}

echo json_encode([
    'success' => true,
    'processed' => count($orders),
    'updated' => count($results),
    'details' => $results,
    'time' => date('Y-m-d H:i:s')
]);

// ── فحص طلبات الاتصالات المعلقة ──────────────────────────────────────────────
try {
    require_once dirname(__DIR__) . '/includes/fore_api.php';
    require_once dirname(__DIR__) . '/includes/cashbox_helper.php';
    $pendingTC = $pdo->query("SELECT t.*,n.fore_endpoint FROM telecom_orders t 
        JOIN telecom_networks n ON t.network_id=n.id
        WHERE t.is_pending=1 AND t.status='processing' AND t.provider_tx_id IS NOT NULL
        ORDER BY t.created_at LIMIT 20")->fetchAll();
    
    if ($pendingTC) {
        $api = getTelecomAPI($pdo);
        if ($api) {
            foreach ($pendingTC as $order) {
                $r = $api->operationStatus($order['provider_tx_id']);
                if (isset($r['isDone'])) {
                    $done = $r['isDone'] == '1';
                    $ban  = $r['isBan']  == '1';
                    $st   = $done ? 'completed' : ($ban ? 'failed' : 'processing');
                    $pdo->prepare("UPDATE telecom_orders SET status=?,is_pending=?,provider_raw=? WHERE id=?")
                        ->execute([$st, $done||$ban?0:1, json_encode($r), $order['id']]);
                    if ($done && !$ban) {
                        $cashboxPost = cashboxPostTelecomOperation(
                            $pdo,
                            (int)$order['id'],
                            (string)($order['provider'] ?: (getSetting('telecom_provider') ?: 'fore')),
                            !empty($order['network_id']) ? (int)$order['network_id'] : null,
                            (float)$order['amount_yer'],
                            'تأكيد دفع اتصالات للمزود — ID' . (int)$order['id'],
                            !empty($order['user_id']) ? (int)$order['user_id'] : null,
                            null
                        );
                        if (!$cashboxPost['posted']) {
                            error_log('Telecom cron success but cashbox was not posted for ID' . (int)$order['id'] . ': ' . ($cashboxPost['reason'] ?? 'unknown'));
                        }
                    }
                    if ($ban) {
                        // استرجاع رصيد العميل عند الحظر مع قيد دفتر محفظة مطابق.
                        $refundAmount = (float)$order['cost_yer'] / ((float)($order['exchange_rate'] ?: 1700));
                        $refundDescription = 'استرداد حظر شحن اتصالات #'.$order['id'];
                        try {
                            walletCreditWithLedger(
                                $pdo,
                                (int)$order['user_id'],
                                $refundAmount,
                                $refundDescription,
                                (int)$order['id'],
                                $refundDescription
                            );
                        } catch (Throwable $refundError) {
                            error_log('Telecom cron refund ledger failed for order '.$order['id'].': '.$refundError->getMessage());
                        }
                    }
                }
            }
        }
    }
} catch (\Exception $e) {
    // تجاهل أخطاء الاتصالات في الكرون
}
