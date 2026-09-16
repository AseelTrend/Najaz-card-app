<?php
/**
 * AJAX Endpoint — كبينة السداد
 * كل العمليات تمر من هنا
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/wallet_ledger_helper.php';
require_once dirname(__DIR__) . '/includes/cashbox_helper.php';
require_once dirname(__DIR__) . '/includes/fore_api.php';
require_once dirname(__DIR__) . '/includes/telegram_internal_auth.php';
if (!isLoggedIn() && !telegramAuthorizeInternalRequest($pdo)) requireLogin();

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$user   = getUser();

// ── دوال مساعدة ─────────────────────────────────────────────────────────────
function jr(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jrErr(string $msg): void { jr(['status' => false, 'message' => $msg]); }
function jrOk(array $d = []): void { jr(array_merge(['status' => true], $d)); }

function cleanPhone(string $p): string { return preg_replace('/[^0-9]/', '', $p); }

function getNet(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM telecom_networks WHERE id=? AND status=1");
    $s->execute([$id]); return $s->fetch() ?: null;
}

function getExchangeRate(): float {
    return (float)(getSetting('telecom_sadad_rate') ?: getSetting('telecom_exchange_rate') ?: 1700);
}
function getProfitPct():   float { return (float)(getSetting('telecom_profit_pct')   ?: 5); }

// حساب تكلفة الريال بالدولار
function yerToUsd(float $yer): float {
    $rate = getExchangeRate();
    return $rate > 0 ? round($yer / $rate, 6) : 0;
}

// ── كشف الشبكة من الرقم ─────────────────────────────────────────────────────
if ($action === 'detect_network') {
    $phone = cleanPhone($_GET['phone'] ?? '');
    if (strlen($phone) < 8) jrErr('رقم قصير');
    $net = detectTelecomNetwork($pdo, $phone);
    if (!$net) jrErr('لم يتم التعرف على الشبكة');
    jrOk(['network' => $net]);
}

// ── فئات الشحن السريع ────────────────────────────────────────────────────────
if ($action === 'get_quick_amounts') {
    $netId = (int)($_GET['network_id'] ?? 0);
    $rows  = $pdo->prepare("SELECT * FROM telecom_quick_amounts WHERE network_id=? ORDER BY sort_order");
    $rows->execute([$netId]);
    jrOk(['amounts' => $rows->fetchAll()]);
}

// ── باقات الشبكة ─────────────────────────────────────────────────────────────
if ($action === 'get_offers') {
    $netId = (int)($_GET['network_id'] ?? 0);
    // جلب المجموعات مع الباقات
    $groups = $pdo->prepare("SELECT * FROM telecom_offer_groups WHERE network_id=? AND status=1 ORDER BY sort_order");
    $groups->execute([$netId]); $groups = $groups->fetchAll();

    $offers = $pdo->prepare("SELECT * FROM telecom_offers WHERE network_id=? AND status=1 ORDER BY group_id,sort_order,price_yer");
    $offers->execute([$netId]); $offers = $offers->fetchAll();

    // تجميع الباقات داخل مجموعاتها
    $grouped = [];
    foreach ($groups as $g) {
        $g['offers'] = array_filter($offers, fn($o) => $o['group_id'] == $g['id']);
        $g['offers'] = array_values($g['offers']);
        if ($g['offers']) $grouped[] = $g;
    }
    // باقات بدون مجموعة
    $ungrouped = array_values(array_filter($offers, fn($o) => !$o['group_id']));

    jrOk(['groups' => $grouped, 'ungrouped' => $ungrouped, 'all' => $offers]);
}

// ── وحدات السعر لسبافون ───────────────────────────────────────────────────────
if ($action === 'get_unit_price') {
    $netId = (int)($_GET['network_id'] ?? 0);
    $net   = getNet($pdo, $netId);
    if (!$net) jrErr('شبكة غير موجودة');
    jrOk(['unit_price' => $net['unit_price'], 'amount_type' => $net['amount_type']]);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST Actions
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jrErr('طلب غير صالح');
}

// ── استعلام (رصيد / سلفة / باقات) ──────────────────────────────────────────
if ($action === 'inquiry') {
    $phone  = cleanPhone($_POST['phone'] ?? '');
    $netId  = (int)($_POST['network_id'] ?? 0);
    $type   = $_POST['type'] ?? 'balance'; // balance|loan|offers

    if (strlen($phone) < 8) jrErr('أدخل رقم الهاتف بشكل صحيح');
    $net = getNet($pdo, $netId);
    if (!$net) jrErr('شبكة غير معروفة');

    $api = getTelecomAPI($pdo);
    if (!$api) jrErr('المزود غير مهيأ — اضبط بيانات الربط من الإدارة');

    $ep = $net['fore_endpoint'];
    if ($type === 'balance') {
        if ($ep === 'post')       $r = $api->postQuery($phone);
        elseif ($ep === 'yem')    $r = $api->yemenMobileQuery($phone);
        else                      jrErr('الاستعلام غير مدعوم لهذه الشبكة');
    } elseif ($type === 'loan') {
        if ($ep === 'yem')        $r = $api->yemenMobileLoan($phone);
        else                      jrErr('السلفة غير مدعومة');
    } elseif ($type === 'offers') {
        if ($ep === 'yem')        $r = $api->yemenMobileQueryOffers($phone);
        else                      jrErr('استعلام الباقات غير مدعوم');
    } else { jrErr('نوع استعلام غير معروف'); }

    if (!ForeYemenAPI::isSuccess($r)) jrErr($r['resultDesc'] ?? 'فشل الاستعلام');

    $r['status'] = true;
    jr($r);
}

// ── شحن رصيد حر ─────────────────────────────────────────────────────────────
if ($action === 'pay_balance') {
    $phone    = cleanPhone($_POST['phone'] ?? '');
    $netId    = (int)($_POST['network_id'] ?? 0);
    $amountYer= (float)($_POST['amount'] ?? 0);
    $postType = $_POST['post_type'] ?? 'line'; // line | adsl

    if (strlen($phone) < 8)  jrErr('رقم الهاتف غير صحيح');
    if ($amountYer <= 0)      jrErr('المبلغ غير صحيح');

    $net = getNet($pdo, $netId);
    if (!$net) jrErr('شبكة غير معروفة');
    if (!$net['supports_balance']) jrErr('هذه الشبكة لا تدعم الشحن الحر');

    // تحقق من الرصيد (رصيد العميل بالدولار)
    $amountUsd = yerToUsd($amountYer);
    $profitPct = getProfitPct();
    // الخصم من رصيد العميل = المبلغ بالريال + نسبة الربح
    $chargeYer = round($amountYer * (1 + $profitPct / 100), 2);
    $chargeUsd = yerToUsd($chargeYer);

    // رصيد المستخدم بالدولار
    $userBal = (float)$user['balance'];
    if ($chargeUsd > $userBal) jrErr('رصيدك غير كافٍ — تحتاج ' . number_format($chargeUsd,4) . ' $');

    // التحقق من الحدود
    if ($amountYer < $net['min_amount']) jrErr('الحد الأدنى للشحن ' . number_format($net['min_amount'],0) . ' ر.ي');
    if ($amountYer > $net['max_amount']) jrErr('الحد الأقصى للشحن ' . number_format($net['max_amount'],0) . ' ر.ي');

    $api = getTelecomAPI($pdo);
    if (!$api) jrErr('المزود غير مهيأ');

    // خصم الرصيد أولاً
    $pdo->beginTransaction();
    try {
        // تسجيل الطلب أولاً للحصول على مرجع محاسبي ثابت.
        $pdo->prepare("INSERT INTO telecom_orders
            (user_id,network_id,order_type,mobile_number,amount_yer,exchange_rate,cost_yer,profit_yer,provider,status)
            VALUES (?,?,?,?,?,?,?,?,?,'processing')")->execute([
            $user['id'], $netId, 'balance', $phone,
            $amountYer, getExchangeRate(),
            $chargeYer, $chargeYer - $amountYer,
            getSetting('telecom_provider') ?: 'fore'
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$chargeUsd, $user['id']]);
        // قيد الخصم المقابل للطلب حتى يظهر في دفتر المحفظة والتقرير المحاسبي.
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id)
            VALUES (?,?,?,?,?,?,?)")->execute([
            $user['id'], 'debit', $chargeUsd, $user['balance'], $user['balance'] - $chargeUsd,
            'شحن اتصالات — خصم الطلب #'.$orderId, $orderId
        ]);

        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        jrErr('خطأ داخلي: ' . $e->getMessage());
    }

    // إرسال الطلب للمزود
    // للهاتف الثابت: amount هو القيمة بالريال مباشرة
    // لسبافون/واي: amount هو عدد الوحدات
    $apiAmount = ($net['amount_type'] === 'units')
        ? round($amountYer / ($net['unit_price'] ?: 1))
        : $amountYer;

    $r = $api->billBalance($net, $phone, (float)$apiAmount, $postType);

    // تحديث الطلب بنتيجة API
    $isPending  = ForeYemenAPI::isPending($r);
    $isSuccess  = ForeYemenAPI::isSuccess($r) && !$isPending;
    $isFailed   = !ForeYemenAPI::isSuccess($r);

    $newStatus  = $isSuccess ? 'completed' : ($isPending ? 'processing' : 'failed');
    $refId      = $r['sequenceId'] ?? null;

    $pdo->prepare("UPDATE telecom_orders SET status=?,provider_ref_id=?,provider_msg=?,provider_raw=?,is_pending=? WHERE id=?")
        ->execute([$newStatus, $refId, $r['resultDesc']??null, json_encode($r), $isPending?1:0, $orderId]);

    if ($isSuccess) {
        $cashboxPost = cashboxPostTelecomOperation(
            $pdo,
            $orderId,
            (string)(getSetting('telecom_provider') ?: 'fore'),
            $netId,
            $amountYer,
            'دفع اتصالات للمزود — ID' . $orderId,
            (int)$user['id'],
            !empty($user['id']) ? (int)$user['id'] : null
        );
        if (!$cashboxPost['posted']) {
            error_log('Telecom balance succeeded but cashbox was not posted for ID' . $orderId . ': ' . ($cashboxPost['reason'] ?? 'unknown'));
        }
    }

    if ($isFailed) {
        // استرجاع الرصيد مع قيد دائن مطابق، وبمفتاح وصفي يمنع تكرار الاسترداد.
        $refundDescription = 'استرداد فشل شحن اتصالات #'.$orderId;
        try {
            walletCreditWithLedger($pdo, (int)$user['id'], $chargeUsd, $refundDescription, $orderId, $refundDescription);
        } catch (Throwable $e) {
            error_log('Telecom balance refund ledger failed for order '.$orderId.': '.$e->getMessage());
            jrErr('تعذر تسجيل استرداد العملية، يرجى مراجعة الإدارة');
        }
        jrErr($r['resultDesc'] ?? 'فشل الشحن من المزود');
    }

    $msg = $isSuccess ? 'تم الشحن بنجاح ✓' : 'الطلب قيد التنفيذ، ستصلك إشعار عند الإتمام';

    // إشعار داخلي
    try {
        $pdo->prepare("INSERT INTO notifications (user_id,title,message,icon,color,type)
            VALUES (?,?,?,?,?,?)")->execute([
            $user['id'],
            $isSuccess ? 'تم شحن الرصيد ✓' : 'طلب شحن قيد التنفيذ',
            ($net['name']).' — '.$phone.' — '.number_format($amountYer,0).' ر.ي',
            $isSuccess ? 'check-circle' : 'clock',
            $isSuccess ? '#00d4aa' : '#ffa502',
            'telecom'
        ]);
    } catch (\Exception $e) { /* جدول الإشعارات غير موجود */ }

    jrOk(['message' => $msg, 'order_id' => $orderId, 'pending' => $isPending]);
}

// ── تفعيل باقة ───────────────────────────────────────────────────────────────
if ($action === 'pay_offer') {
    $phone   = cleanPhone($_POST['phone'] ?? '');
    $netId   = (int)($_POST['network_id'] ?? 0);
    $offerId = (int)($_POST['offer_id'] ?? 0);

    if (strlen($phone) < 8) jrErr('رقم غير صحيح');
    if (!$offerId) jrErr('اختر باقة');

    $net = getNet($pdo, $netId);
    if (!$net) jrErr('شبكة غير معروفة');

    $offerRow = $pdo->prepare("SELECT * FROM telecom_offers WHERE id=? AND network_id=? AND status=1");
    $offerRow->execute([$offerId, $netId]); $offerRow = $offerRow->fetch();
    if (!$offerRow) jrErr('الباقة غير موجودة');

    $priceYer = (float)$offerRow['price_yer'];
    $profitPct= getProfitPct();
    $chargeYer= round($priceYer * (1 + $profitPct / 100), 2);
    $chargeUsd= yerToUsd($chargeYer);

    $userBal = (float)$user['balance'];
    if ($chargeUsd > $userBal) jrErr('رصيدك غير كافٍ — تحتاج ' . number_format($chargeUsd,4) . ' $');

    $api = getTelecomAPI($pdo);
    if (!$api) jrErr('المزود غير مهيأ');

    // خصم وتسجيل
    $pdo->beginTransaction();
    try {
        // تسجيل الطلب أولاً للحصول على مرجع محاسبي ثابت.
        $pdo->prepare("INSERT INTO telecom_orders
            (user_id,network_id,order_type,mobile_number,offer_id,amount_yer,exchange_rate,cost_yer,profit_yer,provider,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,'processing')")->execute([
            $user['id'],$netId,'offer',$phone,$offerId,
            $priceYer, getExchangeRate(), $chargeYer, $chargeYer-$priceYer,
            getSetting('telecom_provider')?:'fore'
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE users SET balance=balance-? WHERE id=?")->execute([$chargeUsd, $user['id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id)
            VALUES (?,?,?,?,?,?,?)")->execute([
            $user['id'], 'debit', $chargeUsd, $user['balance'], $user['balance'] - $chargeUsd,
            'تفعيل باقة اتصالات — خصم الطلب #'.$orderId, $orderId
        ]);
        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        jrErr('خطأ داخلي');
    }

    $r = $api->billOffer($net, $offerRow, $phone);

    $isPending = ForeYemenAPI::isPending($r);
    $isSuccess = ForeYemenAPI::isSuccess($r) && !$isPending;
    $isFailed  = !ForeYemenAPI::isSuccess($r);
    $newStatus = $isSuccess ? 'completed' : ($isPending ? 'processing' : 'failed');

    $pdo->prepare("UPDATE telecom_orders SET status=?,provider_msg=?,provider_raw=?,is_pending=? WHERE id=?")
        ->execute([$newStatus, $r['resultDesc']??null, json_encode($r), $isPending?1:0, $orderId]);

    if ($isSuccess) {
        $cashboxPost = cashboxPostTelecomOperation(
            $pdo,
            $orderId,
            (string)(getSetting('telecom_provider') ?: 'fore'),
            $netId,
            $priceYer,
            'تفعيل باقة اتصالات للمزود — ID' . $orderId,
            (int)$user['id'],
            !empty($user['id']) ? (int)$user['id'] : null
        );
        if (!$cashboxPost['posted']) {
            error_log('Telecom offer succeeded but cashbox was not posted for ID' . $orderId . ': ' . ($cashboxPost['reason'] ?? 'unknown'));
        }
    }

    if ($isFailed) {
        $refundDescription = 'استرداد فشل تفعيل باقة #'.$orderId;
        try {
            walletCreditWithLedger($pdo, (int)$user['id'], $chargeUsd, $refundDescription, $orderId, $refundDescription);
        } catch (Throwable $e) {
            error_log('Telecom offer refund ledger failed for order '.$orderId.': '.$e->getMessage());
            jrErr('تعذر تسجيل استرداد العملية، يرجى مراجعة الإدارة');
        }
        jrErr($r['resultDesc'] ?? 'فشل التفعيل');
    }

    try {
        $pdo->prepare("INSERT INTO notifications (user_id,title,message,icon,color,type) VALUES (?,?,?,?,?,?)")
            ->execute([$user['id'],
                $isSuccess ? 'تم تفعيل الباقة ✓' : 'طلب تفعيل قيد التنفيذ',
                $offerRow['name'].' — '.$phone,
                $isSuccess ? 'check-circle' : 'clock',
                $isSuccess ? '#00d4aa' : '#ffa502', 'telecom'
            ]);
    } catch (\Exception $e) { }

    $msg = $isSuccess ? 'تم تفعيل الباقة بنجاح ✓' : 'الطلب قيد التنفيذ';
    jrOk(['message' => $msg, 'order_id' => $orderId, 'pending' => $isPending]);
}

// ── فحص حالة طلب معلق ────────────────────────────────────────────────────────
if ($action === 'check_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM telecom_orders WHERE id=? AND user_id=?");
    $s->execute([$orderId, $user['id']]); $order = $s->fetch();
    if (!$order) jrErr('الطلب غير موجود');
    jrOk(['order' => $order]);
}

jrErr('إجراء غير معروف: ' . $action);
