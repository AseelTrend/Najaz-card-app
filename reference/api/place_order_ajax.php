<?php
/**
 * api/place_order_ajax.php
 * نسخة AJAX من place_order.php — ترجع JSON بدلاً من redirect
 */
require_once dirname(__DIR__) . '/includes/config.php';
if (file_exists(dirname(__DIR__).'/includes/referral.php')) require_once dirname(__DIR__).'/includes/referral.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/rate_limiter.php'; // [M-3 FIX]
require_once dirname(__DIR__) . '/includes/code_stock_helper.php';
require_once dirname(__DIR__) . '/includes/cooldown_helper.php';
require_once dirname(__DIR__) . '/includes/order_email_helper.php';
require_once dirname(__DIR__) . '/includes/pricing_helper.php';
require_once dirname(__DIR__) . '/includes/telegram_internal_auth.php';
// إسناد الطلب إلى صندوق ربطية المزود يعتمد على مساعد الحسابات.
require_once dirname(__DIR__) . '/includes/accounting_helper.php';
require_once dirname(__DIR__) . '/includes/cashbox_helper.php';

header('Content-Type: application/json; charset=utf-8');

function jsonOut($status, $message, $extra = []) {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    if (!telegramAuthorizeInternalRequest($pdo)) {
        jsonOut(false, 'يجب تسجيل الدخول أولاً');
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(false, 'طلب غير صالح');

// [M-3 FIX] Rate Limiting — 10 طلبات كل 60 ثانية لكل مستخدم
apiRateLimit($pdo, 'place_order', 10, 60, true);

$serviceId = (int)($_POST['service_id'] ?? 0);
$quantity  = (int)($_POST['quantity']   ?? 1);
$fieldData = $_POST['fields'] ?? [];

if (!$serviceId) jsonOut(false, 'خدمة غير محددة');

// جلب الخدمة
$stmt = $pdo->prepare("SELECT * FROM services WHERE id=? AND status=1");
$stmt->execute([$serviceId]);
$service = $stmt->fetch();

if (!$service) jsonOut(false, 'الخدمة غير موجودة أو متوقفة');

if ($quantity < $service['min_qty'] || $quantity > $service['max_qty'])
    jsonOut(false, "الكمية يجب بين {$service['min_qty']} و {$service['max_qty']}");

// التحقق من الحقول المطلوبة
$stmt2 = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? AND is_required=1");
$stmt2->execute([$serviceId]);
foreach ($stmt2->fetchAll() as $rf) {
    if (empty($fieldData[$rf['field_name']]))
        jsonOut(false, 'حقل "' . $rf['field_label'] . '" مطلوب');
}

$priceInfo  = getUserServicePrice($pdo, (int)$_SESSION['user_id'], $service);
$unitPrice   = (float)$priceInfo['price'];
$totalPrice  = $unitPrice * $quantity;
$couponCode  = strtoupper(trim($_POST['coupon_code'] ?? ''));
$couponDiscount = 0;
$couponId    = null;
$finalPrice  = $totalPrice;

// التحقق من الكوبون إذا أُدخل
if ($couponCode) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coupons` (`id` INT AUTO_INCREMENT PRIMARY KEY,`code` VARCHAR(50) NOT NULL UNIQUE,`name` VARCHAR(200) NOT NULL,`discount_type` ENUM('percent','fixed') DEFAULT 'percent',`discount_value` DECIMAL(10,4) DEFAULT 0,`min_order` DECIMAL(10,4) DEFAULT 0,`max_discount` DECIMAL(10,4) DEFAULT NULL,`applies_to` ENUM('all','category','service') DEFAULT 'all',`category_id` INT DEFAULT NULL,`service_id` INT DEFAULT NULL,`condition_type` ENUM('none','not_referred','referred') DEFAULT 'none',`usage_limit` INT DEFAULT NULL,`usage_per_user` INT DEFAULT 1,`used_count` INT DEFAULT 0,`starts_at` TIMESTAMP NULL,`expires_at` TIMESTAMP NULL,`status` TINYINT(1) DEFAULT 1,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `coupon_uses` (`id` INT AUTO_INCREMENT PRIMARY KEY,`coupon_id` INT NOT NULL,`user_id` INT NOT NULL,`order_id` INT DEFAULT NULL,`discount` DECIMAL(10,4) NOT NULL,`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Exception $e) {}

    $cp = $pdo->prepare("SELECT * FROM coupons WHERE code=? AND status=1"); $cp->execute([$couponCode]); $cp=$cp->fetch();
    if ($cp) {
        // فحص شامل مطابق لـ validate_coupon.php
        $now = time();
        $valid = true;
        if ($cp['starts_at'] && strtotime($cp['starts_at'])>$now) $valid=false;
        if ($cp['expires_at'] && strtotime($cp['expires_at'])<$now) $valid=false;
        if ($cp['usage_limit'] && $cp['used_count']>=$cp['usage_limit']) $valid=false;
        $uu=$pdo->prepare("SELECT COUNT(*) FROM coupon_uses WHERE coupon_id=? AND user_id=?");$uu->execute([$cp['id'],$_SESSION['user_id']]);
        if((int)$uu->fetchColumn()>=(int)$cp['usage_per_user']) $valid=false;
        if($cp['min_order']>0 && $totalPrice<$cp['min_order']) $valid=false;
        if($cp['applies_to']==='service' && $cp['service_id']!=$serviceId) $valid=false;
        if($cp['applies_to']==='category') {
            $sc=$pdo->query("SELECT category_id FROM services WHERE id=$serviceId")->fetchColumn();
            if($sc!=$cp['category_id']) $valid=false;
        }
        if($cp['condition_type']!=='none') {
            $rb=$pdo->prepare("SELECT referred_by FROM users WHERE id=?");$rb->execute([$_SESSION['user_id']]);$rb=$rb->fetchColumn();
            $isRef=!empty($rb);
            if($cp['condition_type']==='not_referred' && $isRef) $valid=false;
            if($cp['condition_type']==='referred' && !$isRef) $valid=false;
        }
        if ($valid) {
            if($cp['discount_type']==='percent') {
                $couponDiscount=round($totalPrice*$cp['discount_value']/100,4);
                if($cp['max_discount'] && $couponDiscount>$cp['max_discount']) $couponDiscount=(float)$cp['max_discount'];
            } else { $couponDiscount=min((float)$cp['discount_value'],$totalPrice); }
            $couponId   = $cp['id'];
            $finalPrice = max(0, $totalPrice - $couponDiscount);
        }
    }
}

$user = getUser();
if ($user['balance'] < $finalPrice)
    jsonOut(false, 'رصيدك غير كافٍ. رصيدك: ' . formatMoney($user['balance']) . ' والمطلوب: ' . formatMoney($finalPrice) . ($couponDiscount>0?' (بعد خصم '.formatMoney($couponDiscount).')':''));

// إضافة أعمدة خارج الـ transaction
try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_code VARCHAR(50) DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS coupon_discount DECIMAL(10,4) DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL UNIQUE"); } catch(Exception $e) {}

// توليد ref_id فريد
function generateOrderRef($pdo) {
    $prefix = 'ID';
    do {
        $ref = strtoupper($prefix . '_' . bin2hex(random_bytes(8)));
        $chk = $pdo->prepare("SELECT id FROM orders WHERE ref_id=?");
        $chk->execute([$ref]);
    } while ($chk->fetch());
    return $ref;
}
$orderRef = generateOrderRef($pdo);

// ── فحص الـ Cooldown المركزي (موحّد مع الربط الخارجي API) ──
// يجب أن يتم قبل أي خصم رصيد أو إنشاء طلب، ويمنع التكرار السريع لنفس بيانات الطلب
$cd = cooldownAcquire($pdo, $serviceId, $fieldData);
if (!$cd['ok']) {
    jsonOut(false, cooldownMessage($cd['remaining']), ['cooldown_remaining' => $cd['remaining']]);
}

// [مهم] إنشاء الجداول التي قد تتضمن DDL قبل بدء الـ Transaction.
codeStockEnsureTables($pdo);
try {
    if (function_exists('accountingEnsureSchema')) accountingEnsureSchema($pdo);
    if (function_exists('accountingEnsureProviderLinkSchema')) accountingEnsureProviderLinkSchema($pdo);
    if (function_exists('cashboxEnsureSchema')) cashboxEnsureSchema($pdo);
    if (function_exists('cashboxEnsureOperationalColumns')) cashboxEnsureOperationalColumns($pdo);
} catch (Throwable $e) {
    // التهيئة المحاسبية مساندة ولا تمنع إنشاء الطلب الأساسي.
    error_log('Accounting/cashbox bootstrap failed: ' . $e->getMessage());
}

try {
    $pdo->beginTransaction();

    $stmt3 = $pdo->prepare("
        INSERT INTO orders (user_id,service_id,quantity,unit_price,total_price,coupon_code,coupon_discount,ref_id,status,field_data,cooldown_key)
        VALUES (?,?,?,?,?,?,?,?,'pending',?,?)
    ");
    $stmt3->execute([$_SESSION['user_id'], $serviceId, $quantity, $unitPrice, $finalPrice,
                     $couponCode?:null, $couponDiscount, $orderRef,
                     json_encode($fieldData, JSON_UNESCAPED_UNICODE), $cd['cooldown_key']]);
    $orderId = $pdo->lastInsertId();

    $newBalance = $user['balance'] - $finalPrice;
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $_SESSION['user_id']]);
    $txDesc = 'طلب #'.$orderId.' - '.$service['name'].($couponCode?' [كوبون: '.$couponCode.']':'');
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$_SESSION['user_id'], 'debit', $finalPrice, $user['balance'], $newBalance, $txDesc, $orderId]);

    // تسجيل استخدام الكوبون
    if ($couponId && $couponDiscount > 0) {
        try {
            $pdo->prepare("INSERT INTO coupon_uses (coupon_id,user_id,order_id,discount) VALUES (?,?,?,?)")->execute([$couponId,$_SESSION['user_id'],$orderId,$couponDiscount]);
            $pdo->prepare("UPDATE coupons SET used_count=used_count+1 WHERE id=?")->execute([$couponId]);
        } catch(Exception $e) {}
    }

    // ── تسليم تلقائي من مخزون الأكواد (إن كانت الخدمة مفعّلة كخدمة أكواد) ──────
    $deliveredFromCodeStock = false;
    $deliveredCode          = null;
    try {
        if (codeStockIsEnabled($pdo, $serviceId)) {
            $deliveredCode = codeStockDeliver($pdo, $serviceId, $orderId, (int)$_SESSION['user_id']);
            if ($deliveredCode !== null) {
                $pdo->prepare("UPDATE orders SET status='completed', notes=? WHERE id=?")
                    ->execute(['تم تسليم الكود تلقائياً من المخزون', $orderId]);
                $deliveredFromCodeStock = true;
            } else {
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
        $spStmt = $pdo->prepare("
            SELECT sp.*, p.api_key as prov_key, p.api_url as prov_url, p.provider_type, p.status as prov_status
            FROM service_providers sp
            JOIN providers p ON sp.provider_id=p.id
            WHERE sp.service_id=? AND sp.is_active=1 AND p.status=1
            ORDER BY sp.priority ASC
        ");
        $spStmt->execute([$serviceId]);
        $svcProviders = $spStmt->fetchAll();
        $serviceHasProvider = !empty($svcProviders);
    } catch(Exception $e) {
        $svcProviders = [];
        if (!empty($service['provider_id'])) {
            $op = $pdo->prepare("SELECT * FROM providers WHERE id=? AND status=1");
            $op->execute([$service['provider_id']]);
            $op = $op->fetch();
            if ($op) { $svcProviders[] = array_merge($op, ['prov_key'=>$op['api_key'],'prov_url'=>$op['api_url'],'provider_service_id'=>$service['provider_service_id']]); $serviceHasProvider=true; }
        }
    }
    if ($serviceHasProvider) {
        $sent        = false;
        $attemptsLog = [];
        $cashboxFinalProviderId = 0;
        $cashboxFinalProviderLabel = '';

        foreach ($svcProviders as $sp) {
            if (!$sp['prov_status']) continue;

            if ($sp['provider_type'] === 'numbersapp') {
                // ── NumbersApp: تحديد نوع الخدمة من provider_service_id ──
                $svcId = $sp['provider_service_id'] ?? '';
                $naBaseUrl = rtrim($sp['prov_url'], '/');
                $naKey = $sp['prov_key'];
                $naParts = explode(':', $svcId, 2);
                $naType = $naParts[0] ?? '';
                $naId   = $naParts[1] ?? '';

                $naData = json_encode($fieldData, JSON_UNESCAPED_UNICODE);

                if ($naType === 'live') {
                    // خدمة مباشر: شراء رقم (service + countryCode + serverNumber من حقول العميل)
                    $naParams = [
                        'api_key'      => $naKey,
                        'service'      => $fieldData['service'] ?? $naId,
                        'countryCode'  => $fieldData['countryCode'] ?? $fieldData['country_code'] ?? '',
                        'serverNumber' => $fieldData['serverNumber'] ?? $fieldData['server_number'] ?? '0',
                    ];
                    $url = $naBaseUrl . '/getLiveNumber?' . http_build_query($naParams);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
                    $res = json_decode(curl_exec($ch), true); curl_close($ch);

                    if ($res && isset($res['Id']) && isset($res['Number'])) {
                        $provOrderId = $res['Id'];
                        $naJson = json_encode([
                            'type'   => 'live',
                            'number' => $res['Number'],
                            'access_id' => $res['Id'],
                            'service' => $naParams['service'],
                        ], JSON_UNESCAPED_UNICODE);
                        $pdo->prepare("UPDATE orders SET provider_order_id=?, used_provider_id=?, status='processing', notes=?, numbersapp_data=? WHERE id=?")
                            ->execute([$provOrderId, (int)$sp['provider_id'], 'NUMBERSAPP_LIVE: number='.$res['Number'], $naJson, $orderId]);
                        $cashboxFinalProviderId = (int)$sp['provider_id'];
                        $cashboxFinalProviderLabel = 'NUMBERSAPP_LIVE';
                        $sent = true; break;
                    } else {
                        $attemptsLog[] = 'NUMBERSAPP_LIVE: '.($res['error'] ?? json_encode($res) ?? 'no_response');
                    }

                } elseif ($naType === 'pack_fixed' || $naType === 'pack_count') {
                    // باقة: إرسال طلب شراء
                    $inputs = [];
                    foreach ($fieldData as $k => $v) {
                        if (!in_array($k, ['service','countryCode','serverNumber','country_code','server_number'])) {
                            $inputs[$k] = $v;
                        }
                    }
                    $naParams = [
                        'api_key' => $naKey,
                        'packId'  => $naId,
                        'inputs'  => json_encode($inputs, JSON_UNESCAPED_UNICODE),
                    ];
                    if ($naType === 'pack_count') $naParams['count'] = $quantity;

                    $url = $naBaseUrl . '/requestPack?' . http_build_query($naParams);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
                    $res = json_decode(curl_exec($ch), true); curl_close($ch);

                    if ($res && ($res['res'] ?? '') === 'Success' && isset($res['boughtItem']['Id'])) {
                        $provOrderId = $res['boughtItem']['Id'];
                        $naJson = json_encode([
                            'type'     => $naType,
                            'pack_id'  => $naId,
                            'order_id' => $provOrderId,
                            'state'    => $res['boughtItem']['State'] ?? 'Waiting',
                        ], JSON_UNESCAPED_UNICODE);
                        $ns = ($res['boughtItem']['State'] ?? '') === 'Done' ? 'completed' : 'processing';
                        $pdo->prepare("UPDATE orders SET provider_order_id=?, used_provider_id=?, status=?, notes=?, numbersapp_data=? WHERE id=?")
                            ->execute([$provOrderId, (int)$sp['provider_id'], $ns, 'NUMBERSAPP_PACK: '.$res['boughtItem']['State'], $naJson, $orderId]);
                        $cashboxFinalProviderId = (int)$sp['provider_id'];
                        $cashboxFinalProviderLabel = 'NUMBERSAPP_PACK';
                        $sent = true; break;
                    } else {
                        $attemptsLog[] = 'NUMBERSAPP_PACK: '.($res['res'] ?? json_encode($res) ?? 'no_response');
                    }

                } elseif ($naType === 'store') {
                    // مخزن: شراء منتج
                    $naParams = [
                        'api_key'   => $naKey,
                        'productId' => $naId,
                        'count'     => $quantity,
                    ];
                    $url = $naBaseUrl . '/buyStoredProduct?' . http_build_query($naParams);
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
                    $res = json_decode(curl_exec($ch), true); curl_close($ch);

                    if ($res && ($res['res'] ?? '') === 'Success' && isset($res['products'])) {
                        $products = is_array($res['products']) ? implode("\n", $res['products']) : $res['products'];
                        $naJson = json_encode([
                            'type'     => 'store',
                            'product_id' => $naId,
                            'products' => $res['products'],
                        ], JSON_UNESCAPED_UNICODE);
                        $pdo->prepare("UPDATE orders SET provider_order_id=?, used_provider_id=?, status='completed', notes=?, numbersapp_data=? WHERE id=?")
                            ->execute(['store_'.$orderId, (int)$sp['provider_id'], 'NUMBERSAPP_STORE: '.count($res['products']).' منتجات', $naJson, $orderId]);
                        $cashboxFinalProviderId = (int)$sp['provider_id'];
                        $cashboxFinalProviderLabel = 'NUMBERSAPP_STORE';
                        $sent = true; break;
                    } else {
                        $attemptsLog[] = 'NUMBERSAPP_STORE: '.($res['errorMsg'] ?? $res['res'] ?? 'no_response');
                    }
                } else {
                    $attemptsLog[] = 'NUMBERSAPP: unknown type='.$naType;
                }

            } elseif (in_array($sp['provider_type'], ['oranos','ap4stor'])) {
                $uuid   = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
                    mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
                    mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
                $params = array_merge($fieldData, ['qty'=>$quantity,'order_uuid'=>$uuid]);
                $url    = rtrim($sp['prov_url'],'/').'/client/api/newOrder/'
                        . $sp['provider_service_id'].'/params?'.http_build_query($params);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER     => ['api-token: '.$sp['prov_key'],'Accept: application/json'],
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                $res = json_decode(curl_exec($ch), true);
                curl_close($ch);

                // نجاح حقيقي فقط: وصل order_id + الحالة ليست reject
                if ($res && isset($res['data']['order_id'])
                    && ($res['data']['status'] ?? '') !== 'reject') {

                    $oStatus = $res['data']['status'] ?? 'wait';
                    $ns      = $oStatus === 'accept' ? 'processing' : 'pending';
                    $pdo->prepare("UPDATE orders SET provider_order_id=?,used_provider_id=?,status=?,notes=? WHERE id=?")
                        ->execute([$res['data']['order_id'], (int)$sp['provider_id'], $ns,
                            strtoupper($sp['provider_type']).': '.$oStatus, $orderId]);
                    $cashboxFinalProviderId = (int)$sp['provider_id'];
                    $cashboxFinalProviderLabel = strtoupper($sp['provider_type']);
                    $sent = true;
                    break;
                } else {
                    $attemptsLog[] = strtoupper($sp['provider_type']).': '.($res['data']['status'] ?? $res['error'] ?? 'no_response');
                }

            } else {
                $link = $fieldData['link'] ?? $fieldData['url']
                      ?? $fieldData['id'] ?? $fieldData['username']
                      ?? reset($fieldData);
                $data = ['key'=>$sp['prov_key'],'action'=>'add',
                         'service'=>$sp['provider_service_id'],'quantity'=>$quantity,'link'=>$link];
                $ch = curl_init($sp['prov_url']);
                curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($data),
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30]);
                $res = json_decode(curl_exec($ch), true);
                curl_close($ch);

                if ($res && isset($res['order'])) {
                    $pdo->prepare("UPDATE orders SET provider_order_id=?,used_provider_id=?,status='processing' WHERE id=?")
                        ->execute([$res['order'], (int)$sp['provider_id'], $orderId]);
                    $cashboxFinalProviderId = (int)$sp['provider_id'];
                    $cashboxFinalProviderLabel = 'SMM';
                    $sent = true;
                    break;
                } else {
                    $attemptsLog[] = 'SMM: '.($res['error'] ?? 'no_order_id');
                }
            }
        }

        if (!$sent && !empty($attemptsLog)) {
            $pdo->prepare("UPDATE orders SET notes=? WHERE id=?")
                ->execute(['فشل جميع المزودين: '.implode(' | ', $attemptsLog), $orderId]);
        }
        if ($sent && $cashboxFinalProviderId > 0) {
            $cashboxAssignment = cashboxAssignProviderOperation(
                $pdo,
                'orders',
                (int)$orderId,
                $cashboxFinalProviderId,
                null,
                'awaiting_cost',
                'provider_cost_not_confirmed'
            );
            if (!$cashboxAssignment['assigned']) {
                error_log('Order accepted but cashbox assignment failed for ID' . $orderId . ': ' . ($cashboxAssignment['reason'] ?? 'unknown'));
            }
        }
    }
    } // end if (!$deliveredFromCodeStock)

    $pdo->commit();
    cooldownRelease($pdo, $cd['lock_name']);

    // عمولة الإحالة تُمنح فقط عند اكتمال الطلب (في admin/orders.php)

    // إشعار
    try {
        $createdOrder = ['id'=>$orderId,'ref_id'=>$orderRef,'total_price'=>formatMoney($totalPrice)];
        notifyNewOrder($pdo, $createdOrder, $user, $service['name']);
    } catch (Exception $e) {}

    // بريد تأكيد الشراء — فقط للطلبات المُسلَّمة فوراً (لدينا بيانات التسليم جاهزة)
    if ($deliveredFromCodeStock) {
        try {
            sendPurchaseConfirmationEmail($pdo, $orderId);
        } catch (Exception $e) {}
    }

    // فحص فوري
    if ($serviceHasProvider && !$deliveredFromCodeStock) {
        $syncUrl = SITE_URL . '/sync_order.php?order_id=' . $orderId;
        $ch = curl_init($syncUrl);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>'order_id='.$orderId,
            CURLOPT_RETURNTRANSFER=>false, CURLOPT_TIMEOUT=>1, CURLOPT_SSL_VERIFYPEER=>false,
            CURLOPT_NOSIGNAL=>1, CURLOPT_COOKIE=>'PHPSESSID='.session_id(),
            CURLOPT_HTTPHEADER=>['Connection: close']]);
        curl_exec($ch); curl_close($ch);
    }

    jsonOut(true, $deliveredFromCodeStock ? 'تم إنشاء طلبك وتسليم الكود تلقائياً!' : 'تم إنشاء طلبك بنجاح!', [
        'order_id'    => $orderId,
        'order_ref'   => strtoupper($orderRef),
        'new_balance' => $newBalance,
        'service'     => $service['name'],
        'total'       => formatMoney($totalPrice),
        'delivered_code' => $deliveredCode,
        'status'      => $deliveredFromCodeStock ? 'completed' : 'pending',
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    cooldownRelease($pdo, $cd['lock_name']);
    jsonOut(false, 'حدث خطأ أثناء معالجة الطلب');
}
