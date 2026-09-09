<?php
require_once 'includes/config.php';
require_once 'includes/notifications.php';
require_once 'includes/pricing_helper.php';
require_once 'includes/code_stock_helper.php';
require_once 'includes/cooldown_helper.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(SITE_URL . '/services.php');
}

// [C-3 FIX] CSRF verification لحماية عملية إنشاء الطلب
$_csrfSubmitted = $_POST['_csrf_token'] ?? '';
$_csrfStored    = $_SESSION['_csrf_token'] ?? '';
if (!$_csrfSubmitted || !$_csrfStored || !hash_equals($_csrfStored, $_csrfSubmitted)) {
    flashMessage('danger', 'طلب غير صالح. يرجى إعادة المحاولة.');
    redirect(SITE_URL . '/services.php');
}
// تجديد التوكن بعد الاستخدام
$_SESSION['_csrf_token'] = bin2hex(random_bytes(32));

$serviceId  = (int)($_POST['service_id'] ?? 0);
$quantity   = (int)($_POST['quantity'] ?? 1);
$fieldData  = $_POST['fields'] ?? [];

// جلب الخدمة
$stmt = $pdo->prepare("SELECT * FROM services WHERE id=? AND status=1");
$stmt->execute([$serviceId]);
$service = $stmt->fetch();

if (!$service) {
    flashMessage('danger', 'الخدمة غير موجودة أو متوقفة');
    redirect(SITE_URL . '/services.php');
}

if ($quantity < $service['min_qty'] || $quantity > $service['max_qty']) {
    flashMessage('danger', "الكمية يجب بين {$service['min_qty']} و {$service['max_qty']}");
    redirect(SITE_URL . '/services.php');
}

$stmt2 = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? AND is_required=1");
$stmt2->execute([$serviceId]);
foreach ($stmt2->fetchAll() as $rf) {
    if (empty($fieldData[$rf['field_name']])) {
        flashMessage('danger', 'حقل "' . $rf['field_label'] . '" مطلوب');
        redirect(SITE_URL . '/services.php');
    }
}

$user = getUser();

// ── تسعير المجموعة ──────────────────────────────────────────────
$priceInfo  = getUserServicePrice($pdo, (int)$_SESSION['user_id'], $service);
$unitPrice  = $priceInfo['price'];   // السعر المخصص للعميل
$totalPrice = $unitPrice * $quantity;

if ($user['balance'] < $totalPrice) {
    flashMessage('danger', 'رصيدك غير كافٍ. رصيدك: ' . formatMoney($user['balance']) . ' والمطلوب: ' . formatMoney($totalPrice));
    redirect(SITE_URL . '/services.php');
}

// [مهم] يجب إنشاء جداول مخزون الأكواد قبل بدء الـ Transaction — أي CREATE TABLE
// داخل transaction يسبب Implicit Commit في MySQL/InnoDB ويكسر الطلب لاحقاً.
codeStockEnsureTables($pdo);

// توليد ref_id موحّد (نفس الصيغة المستخدمة في كل نقاط إنشاء الطلب بالموقع)
try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL UNIQUE"); } catch (Exception $e) {}
function generateOrderRef($pdo) {
    do {
        $ref = strtolower('ID_' . bin2hex(random_bytes(8)));
        $chk = $pdo->prepare("SELECT id FROM orders WHERE ref_id=?");
        $chk->execute([$ref]);
    } while ($chk->fetch());
    return $ref;
}
$orderRef = generateOrderRef($pdo);

// ── فحص الـ Cooldown المركزي (موحّد مع كل قنوات إنشاء الطلبات) ──
$cd = cooldownAcquire($pdo, $serviceId, $fieldData);
if (!$cd['ok']) {
    flashMessage('danger', cooldownMessage($cd['remaining']));
    redirect(SITE_URL . '/services.php');
}

try {
    $pdo->beginTransaction();

    $stmt3 = $pdo->prepare("
        INSERT INTO orders (user_id,service_id,quantity,unit_price,total_price,status,field_data,ref_id,cooldown_key)
        VALUES (?,?,?,?,?,'pending',?,?,?)
    ");
    $stmt3->execute([$_SESSION['user_id'], $serviceId, $quantity, $unitPrice, $totalPrice, json_encode($fieldData, JSON_UNESCAPED_UNICODE), $orderRef, $cd['cooldown_key']]);
    $orderId = $pdo->lastInsertId();

    $newBalance = $user['balance'] - $totalPrice;
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $_SESSION['user_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$_SESSION['user_id'], 'debit', $totalPrice, $user['balance'], $newBalance, 'طلب #'.$orderId.' - '.$service['name'], $orderId]);

    // ── تسليم تلقائي من مخزون الأكواد (إن كانت الخدمة مفعّلة كخدمة أكواد) ──────
    $deliveredFromCodeStock = false;
    try {
        if (codeStockIsEnabled($pdo, $serviceId)) {
            $deliveredCode = codeStockDeliver($pdo, $serviceId, $orderId, (int)$_SESSION['user_id']);
            if ($deliveredCode !== null) {
                $pdo->prepare("UPDATE orders SET status='completed', notes=? WHERE id=?")
                    ->execute(['تم تسليم الكود تلقائياً من المخزون', $orderId]);
                $deliveredFromCodeStock = true;
            } else {
                // لا يوجد مخزون متاح — يبقى الطلب "pending" لمعالجة يدوية من الإدارة
                $pdo->prepare("UPDATE orders SET admin_notes=? WHERE id=?")
                    ->execute(['⚠️ نفاد مخزون الأكواد لهذه الخدمة — يتطلب تدخل يدوي', $orderId]);
            }
        }
    } catch (Exception $e) {
        error_log('code_stock delivery error: ' . $e->getMessage());
    }

    // إرسال للمزود — متعدد المزودين بالأولوية (يُتجاوز إذا تم التسليم من المخزون)
    $serviceHasProvider = false;
    if (!$deliveredFromCodeStock) {
    try {
        $spList = $pdo->prepare("
            SELECT sp.*, p.api_key as prov_key, p.api_url as prov_url, p.provider_type, p.status as prov_status
            FROM service_providers sp
            JOIN providers p ON sp.provider_id = p.id
            WHERE sp.service_id=? AND sp.is_active=1 AND p.status=1
            ORDER BY sp.priority ASC
        ");
        $spList->execute([$serviceId]);
        $svcProviders = $spList->fetchAll();
        $serviceHasProvider = !empty($svcProviders);
    } catch(Exception $e) {
        // جدول service_providers غير موجود — استخدام المزود القديم
        $svcProviders = [];
        if (!empty($service['provider_id'])) {
            $oldProv = $pdo->prepare("SELECT * FROM providers WHERE id=? AND status=1");
            $oldProv->execute([$service['provider_id']]);
            $op = $oldProv->fetch();
            if ($op) {
                $svcProviders[] = [
                    'prov_key' => $op['api_key'], 'prov_url' => $op['api_url'],
                    'provider_type' => $op['provider_type'], 'prov_status' => $op['status'],
                    'provider_service_id' => $service['provider_service_id']
                ];
                $serviceHasProvider = true;
            }
        }
    }

    if ($serviceHasProvider && !empty($svcProviders)) {
        $sent        = false;  // true = تم الإرسال بنجاح حقيقي (لن نجرب مزوداً آخر)
        $attemptsLog = [];

        foreach ($svcProviders as $sp) {
            if (!$sp['prov_status']) continue;

            // ════════════════════════════════════════════════════
            // مزودات Oranos / ap4stor
            // ════════════════════════════════════════════════════
            if (in_array($sp['provider_type'], ['oranos','ap4stor'])) {
                $provResult = sendHeaderApiOrder(
                    $sp['prov_url'], $sp['prov_key'],
                    $sp['provider_service_id'], $quantity,
                    $fieldData, $orderId, $sp['provider_type']
                );

                // نجاح حقيقي = وصل order_id + الحالة ليست reject
                if ($provResult && isset($provResult['data']['order_id'])
                    && ($provResult['data']['status'] ?? '') !== 'reject') {

                    $provOrderId = $provResult['data']['order_id'];
                    $oStatus     = $provResult['data']['status'] ?? 'wait';
                    $newStatus   = $oStatus === 'accept' ? 'processing' : 'pending';

                    $pdo->prepare("UPDATE orders SET provider_order_id=?,status=?,notes=?,used_provider_id=? WHERE id=?")
                        ->execute([$provOrderId, $newStatus,
                            strtoupper($sp['provider_type']) . ': ' . $oStatus,
                            $sp['provider_id'], $orderId]);

                    $sent = true;
                    break; // ← نوقف فقط عند نجاح حقيقي

                } else {
                    // فشل (reject أو خطأ أو لا يوجد order_id) → جرب التالي
                    $reason = $provResult['data']['status']
                        ?? $provResult['error']
                        ?? 'no_response';
                    $attemptsLog[] = strtoupper($sp['provider_type']) . ': ' . $reason;
                    // لا break — يكمل للمزود التالي
                }

            // ════════════════════════════════════════════════════
            // SMM Panel القياسي
            // ════════════════════════════════════════════════════
            } else {
                $link = $fieldData['link'] ?? $fieldData['url']
                      ?? $fieldData['id'] ?? $fieldData['username']
                      ?? reset($fieldData);

                $provResult = sendSmmOrder(
                    $sp['prov_url'], $sp['prov_key'],
                    $sp['provider_service_id'], $quantity, $link
                );

                // نجاح = وصل رقم الطلب من المزود
                if ($provResult && isset($provResult['order'])) {
                    $pdo->prepare("UPDATE orders SET provider_order_id=?,status='processing',used_provider_id=? WHERE id=?")
                        ->execute([$provResult['order'], $sp['provider_id'], $orderId]);

                    $sent = true;
                    break; // ← نوقف فقط عند نجاح حقيقي

                } else {
                    // فشل → جرب التالي
                    $reason = $provResult['error'] ?? 'no_order_id';
                    $attemptsLog[] = 'SMM: ' . $reason;
                    // لا break — يكمل للمزود التالي
                }
            }
        } // end foreach

        // إذا فشلت جميع المزودين — سجّل السبب في الملاحظات
        if (!$sent && !empty($attemptsLog)) {
            $pdo->prepare("UPDATE orders SET notes=? WHERE id=?")
                ->execute(['فشل جميع المزودين: ' . implode(' | ', $attemptsLog), $orderId]);
        }
    }
    } // end if (!$deliveredFromCodeStock)

    $pdo->commit();
    cooldownRelease($pdo, $cd['lock_name']);
    // ── إشعار الطلب الجديد ────────────────────────────────────────────────────
    try {
        $orderForNotif = ['id' => $orderId, 'total_price' => formatMoney($totalPrice)];
        notifyNewOrder($pdo, $orderForNotif, $user, $service['name']);
    } catch (Exception $e) {}
    // ── فحص فوري بعد الإرسال للمزودين الفوريين (لا حاجة له إن تم التسليم من المخزون)
    if ($serviceHasProvider && !$deliveredFromCodeStock) {
        syncOrderInBackground($orderId, SITE_URL);
    }
    flashMessage('success', 'تم إنشاء طلبك بنجاح! رقم الطلب: #' . $orderId);
    redirect(SITE_URL . '/orders.php');

} catch (Exception $e) {
    $pdo->rollBack();
    cooldownRelease($pdo, $cd['lock_name']);
    flashMessage('danger', 'حدث خطأ أثناء معالجة الطلب');
    redirect(SITE_URL . '/services.php');
}

// ─── Oranos / ap4stor: إرسال طلب (Header-based API) ──────────────────────────
function sendHeaderApiOrder($baseUrl, $apiKey, $productId, $quantity, $fieldData, $orderId, $type = 'oranos') {
    // توليد UUID فريد للطلب
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );

    // بناء query params
    $params = array_merge($fieldData, [
        'qty'        => $quantity,
        'order_uuid' => $uuid,
    ]);

    $url = rtrim($baseUrl, '/') . '/client/api/newOrder/' . $productId . '/params?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['api-token: ' . $apiKey, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($res, true) ?? ['error' => 'invalid_json', 'raw' => substr($res,0,500)];
}

// ─── SMM Panel القياسي: إرسال طلب ────────────────────────────────────────────
function sendSmmOrder($apiUrl, $apiKey, $serviceId, $quantity, $link) {
    $data = ['key'=>$apiKey, 'action'=>'add', 'service'=>$serviceId, 'quantity'=>$quantity, 'link'=>$link];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($data), CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

// ─── فحص فوري للطلب في الخلفية بعد الإرسال ──────────────────────────────────
function syncOrderInBackground(int $orderId, string $siteUrl): void {
    $url = $siteUrl . '/sync_order.php?order_id=' . $orderId;
    // fire-and-forget: timeout=0 لا ننتظر الرد
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'order_id=' . $orderId,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 1,    // ثانية واحدة فقط — لا ننتظر
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_NOSIGNAL       => 1,
        CURLOPT_COOKIE         => 'PHPSESSID=' . session_id(),
        CURLOPT_HTTPHEADER     => ['Connection: close'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}
