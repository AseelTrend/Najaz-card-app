<?php
// api/telecom_webhook.php — استقبال تحديثات حالة طلبات كبينة السداد
require_once '../includes/config.php';

$operationStatus = (int)($_GET['OperationStatus'] ?? -1);
$webhookCode     = $_GET['WebHookCode'] ?? '';
$transactionId   = $_GET['TransactionID'] ?? '';
$referenceId     = $_GET['ReferenceID'] ?? '';
$price           = (float)($_GET['price'] ?? 0);
$message         = $_GET['message'] ?? '';

if (!$transactionId) { http_response_code(400); exit; }

try {
    // البحث عن الطلب
    $stmt = $pdo->prepare("SELECT * FROM telecom_orders WHERE provider_transaction_id=?");
    $stmt->execute([$transactionId]);
    $order = $stmt->fetch();

    if (!$order || $order['status'] === 'completed' || $order['status'] === 'cancelled') {
        echo 'OK'; exit;
    }

    $newStatus = 'processing';
    if ($operationStatus == 1)     $newStatus = 'completed';
    elseif ($operationStatus == 0) $newStatus = 'failed';

    $pdo->prepare("UPDATE telecom_orders SET status=?,provider_status=?,provider_message=?,provider_reference_id=?,updated_at=NOW() WHERE id=?")
        ->execute([$newStatus, $operationStatus, $message, $referenceId, $order['id']]);

    // إذا فشل — استرداد الرصيد
    if ($newStatus === 'failed' && $order['status'] !== 'failed') {
        $u = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $u->execute([$order['user_id']]); $u = $u->fetch();
        if ($u) {
            $newBal = $u['balance'] + $order['sale_price'];
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $order['user_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order['user_id'],'credit',$order['sale_price'],$u['balance'],$newBal,"استرداد webhook — كبينة #{$order['id']}",$order['id']]);
        }
    }

    // إشعار للعميل
    try {
        require_once '../includes/notifications.php';
        $net = $pdo->prepare("SELECT name FROM telecom_networks WHERE id=?");
        $net->execute([$order['network_id']]); $net = $net->fetch();
        $netName = $net['name'] ?? 'الاتصالات';
        if ($newStatus === 'completed') {
            sendNotification($pdo, $order['user_id'], 'order_update',
                "✅ تمت عملية كبينة السداد",
                "تم شحن {$order['mobile_number']} عبر {$netName} بنجاح",
                'check-circle', '#00e676', 'order', $order['id']);
        } elseif ($newStatus === 'failed') {
            sendNotification($pdo, $order['user_id'], 'order_update',
                "❌ فشلت عملية كبينة السداد",
                "فشل شحن {$order['mobile_number']} عبر {$netName}. تم استرداد رصيدك.",
                'times-circle', '#ff4455');
        }
    } catch (Exception $e) {}

    echo 'OK';
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error';
}
