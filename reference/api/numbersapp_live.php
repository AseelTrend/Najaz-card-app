<?php
/**
 * api/numbersapp_live.php
 * API للتعامل مع خدمات NumbersApp المباشرة من واجهة العميل
 * Actions: countries, buy, check, cancel
 */
require_once dirname(__DIR__) . '/includes/config.php';
if (file_exists(dirname(__DIR__).'/includes/notifications.php'))
    require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/cooldown_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// منع Cloudflare من عرض challenge page بدل JSON
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    // نقبل الطلب لكن نتأكد من إرجاع JSON دائماً
    header('X-Content-Type-Options: nosniff');
}

function jsonOut($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) jsonOut(false, ['error' => 'يجب تسجيل الدخول']);

$action    = $_GET['action']  ?? $_POST['action'] ?? '';
$serviceId = (int)($_REQUEST['service_id'] ?? 0);

// ── جلب بيانات المزود NumbersApp المرتبط بالخدمة ──
function getNumbersAppProvider(PDO $pdo, int $serviceId): ?array {
    // 1) من service_providers مع فلتر numbersapp
    $stmt = $pdo->prepare("
        SELECT sp.provider_service_id, p.api_url, p.api_key, p.id as provider_id
        FROM service_providers sp
        JOIN providers p ON p.id = sp.provider_id
        WHERE sp.service_id = ? AND sp.is_active = 1 AND p.status = 1 AND p.provider_type = 'numbersapp'
        ORDER BY sp.priority ASC LIMIT 1
    ");
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();
    if ($row) return $row;

    // 2) fallback من services.provider_id
    $stmt2 = $pdo->prepare("
        SELECT s.provider_service_id, p.api_url, p.api_key, p.id as provider_id
        FROM services s
        JOIN providers p ON p.id = s.provider_id
        WHERE s.id = ? AND p.provider_type = 'numbersapp' AND p.status = 1
    ");
    $stmt2->execute([$serviceId]);
    $row2 = $stmt2->fetch();
    if ($row2) return $row2;

    // 3) fallback: خدمة نوعها numbers_live + أي مزود numbersapp
    $stmt3 = $pdo->prepare("
        SELECT sp.provider_service_id, p.api_url, p.api_key, p.id as provider_id
        FROM service_providers sp
        JOIN providers p ON p.id = sp.provider_id
        WHERE sp.service_id = ? AND sp.is_active = 1 AND p.status = 1
        ORDER BY sp.priority ASC LIMIT 1
    ");
    $stmt3->execute([$serviceId]);
    $row3 = $stmt3->fetch();
    if ($row3) {
        // تحقق أن الـ provider_service_id يبدأ بـ live:
        if (str_starts_with($row3['provider_service_id'] ?? '', 'live:')) return $row3;
    }

    return null;
}

function naRequest(string $baseUrl, string $apiKey, string $endpoint, array $params = []) {
    $params['api_key'] = $apiKey;
    $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/') . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($res, true) ?? ['error' => 'invalid_json'];
}

// ══════════════════════════════════════════
// ACTION: countries — جلب الدول المتاحة
// ══════════════════════════════════════════
if ($action === 'countries') {
    if (!$serviceId) jsonOut(false, ['error' => 'service_id مطلوب']);

    $na = getNumbersAppProvider($pdo, $serviceId);
    if (!$na) jsonOut(false, ['error' => 'لا يوجد مزود NumbersApp لهذه الخدمة']);

    // استخراج service code من provider_service_id (مثل live:wa_0)
    $parts = explode(':', $na['provider_service_id'], 2);
    $svcCode = $parts[1] ?? $parts[0];

    $data = naRequest($na['api_url'], $na['api_key'], 'getServiceCountries', ['service' => $svcCode]);

    if (isset($data['error'])) jsonOut(false, ['error' => $data['error']]);

    // تأكد أنها مصفوفة
    $countries = isset($data['Title']) ? [$data] : (is_array($data) ? $data : []);
    jsonOut(true, ['countries' => $countries, 'service_code' => $svcCode]);
}

// ══════════════════════════════════════════
// ACTION: buy — شراء رقم مباشر
// ══════════════════════════════════════════
if ($action === 'buy') {
    if (!$serviceId) jsonOut(false, ['error' => 'service_id مطلوب']);

    $na = getNumbersAppProvider($pdo, $serviceId);
    if (!$na) jsonOut(false, ['error' => 'لا يوجد مزود NumbersApp لهذه الخدمة']);

    // provider_service_id format: "live:SERVICE_CODE:COUNTRY_CODE:SERVER_NUMBER"
    // مثال: live:wa_0:11:0
    $psParts = explode(':', $na['provider_service_id']);
    $svcCode      = $psParts[1] ?? '';
    $countryCode  = $_REQUEST['country_code']  ?? ($psParts[2] ?? '');
    $serverNumber = $_REQUEST['server_number'] ?? ($psParts[3] ?? '0');

    if (!$svcCode) jsonOut(false, ['error' => 'رمز الخدمة غير محدد في إعداد المزود']);
    if (!$countryCode) jsonOut(false, ['error' => 'رمز الدولة غير محدد في إعداد المزود']);

    // جلب الخدمة لحساب السعر
    $svc = $pdo->prepare("SELECT * FROM services WHERE id=? AND status=1");
    $svc->execute([$serviceId]);
    $service = $svc->fetch();
    if (!$service) jsonOut(false, ['error' => 'الخدمة غير موجودة']);

    $user = getUser();
    $price = (float)$service['price'];

    if ($user['balance'] < $price)
        jsonOut(false, ['error' => 'رصيدك غير كافٍ. رصيدك: ' . formatMoney($user['balance']) . ' والمطلوب: ' . formatMoney($price)]);

    // ── فحص الـ Cooldown المركزي (موحّد مع الموقع والربط الخارجي) ──
    // يجب أن يتم قبل إرسال أي طلب فعلي للمزود الخارجي
    $cdFieldData = ['service' => $svcCode, 'country_code' => $countryCode, 'server_number' => $serverNumber];
    $cd = cooldownAcquire($pdo, $serviceId, $cdFieldData);
    if (!$cd['ok']) {
        jsonOut(false, ['error' => cooldownMessage($cd['remaining']), 'cooldown_remaining' => $cd['remaining']]);
    }

    // شراء الرقم من NumbersApp
    $buyRes = naRequest($na['api_url'], $na['api_key'], 'getLiveNumber', [
        'service'      => $svcCode,
        'countryCode'  => $countryCode,
        'serverNumber' => $serverNumber,
    ]);

    // الرد قد يكون بصيغتين:
    // 1) {"Id":"xxx","Number":"yyy"} — حسب التوثيق
    // 2) {"response":"ACCESS_NUMBER:ID:NUMBER"} — الرد الفعلي من السيرفر
    $accessId = $buyRes['Id'] ?? null;
    $phoneNumber = $buyRes['Number'] ?? null;

    // معالجة الصيغة الثانية: ACCESS_NUMBER:ID:NUMBER
    if (!$accessId && isset($buyRes['response'])) {
        $respStr = $buyRes['response'];
        if (strpos($respStr, 'ACCESS_NUMBER') !== false) {
            $parts = explode(':', $respStr);
            // ACCESS_NUMBER:551319109:84815593125
            if (count($parts) >= 3) {
                $accessId = $parts[1];
                $phoneNumber = $parts[2];
            } elseif (count($parts) == 2) {
                $accessId = $parts[1];
            }
        }
    }

    if (!$accessId) {
        cooldownRelease($pdo, $cd['lock_name']);
        jsonOut(false, ['error' => 'فشل شراء الرقم: ' . ($buyRes['error'] ?? json_encode($buyRes))]);
    }

    // إنشاء الطلب وخصم الرصيد
    // تأكد من وجود الأعمدة المطلوبة — خارج الـ transaction
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS numbersapp_data TEXT DEFAULT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS cooldown_key VARCHAR(32) DEFAULT NULL"); } catch(Exception $e) {}

    try {
        // ref_id
        $prefix = 'ID';
        do {
            $ref = strtolower($prefix . '_' . bin2hex(random_bytes(8)));
            $chk = $pdo->prepare("SELECT id FROM orders WHERE ref_id=?"); $chk->execute([$ref]);
        } while ($chk->fetch());

        $pdo->beginTransaction();

        $fieldData = json_encode([
            'service'      => $svcCode,
            'countryCode'  => $countryCode,
            'serverNumber' => $serverNumber,
        ], JSON_UNESCAPED_UNICODE);

        $naData = json_encode([
            'type'      => 'live',
            'number'    => $phoneNumber,
            'access_id' => $accessId,
            'service'   => $svcCode,
        ], JSON_UNESCAPED_UNICODE);

        // محاولة الإدراج مع numbersapp_data، وإذا فشل نحاول بدونها
        try {
            $pdo->prepare("
                INSERT INTO orders (user_id, service_id, quantity, unit_price, total_price, ref_id, status, field_data, provider_order_id, notes, numbersapp_data, cooldown_key)
                VALUES (?, ?, 1, ?, ?, ?, 'processing', ?, ?, ?, ?, ?)
            ")->execute([
                $_SESSION['user_id'], $serviceId, $price, $price, $ref, $fieldData,
                $accessId, 'NUMBERSAPP_LIVE: number=' . $phoneNumber, $naData, $cd['cooldown_key'],
            ]);
        } catch(\PDOException $e2) {
            // fallback بدون numbersapp_data و ref_id
            $pdo->prepare("
                INSERT INTO orders (user_id, service_id, quantity, unit_price, total_price, status, field_data, provider_order_id, notes, cooldown_key)
                VALUES (?, ?, 1, ?, ?, 'processing', ?, ?, ?, ?)
            ")->execute([
                $_SESSION['user_id'], $serviceId, $price, $price, $fieldData,
                $accessId, 'NUMBERSAPP_LIVE: number=' . $phoneNumber, $cd['cooldown_key'],
            ]);
        }
        $orderId = $pdo->lastInsertId();

        // حفظ numbersapp_data بعد الإدراج كـ UPDATE آمن
        try { $pdo->prepare("UPDATE orders SET numbersapp_data=? WHERE id=?")->execute([$naData, $orderId]); } catch(Exception $e3) {}

        $newBalance = $user['balance'] - $price;
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $_SESSION['user_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], 'debit', $price, $user['balance'], $newBalance,
                       'طلب رقم مباشر #' . $orderId . ' - ' . $service['name'], $orderId]);

        $pdo->commit();
        cooldownRelease($pdo, $cd['lock_name']);

        // إشعار
        try {
            if (function_exists('notifyNewOrder'))
                notifyNewOrder($pdo, ['id' => $orderId, 'total_price' => formatMoney($price)], $user, $service['name']);
        } catch(Exception $e) {}

        jsonOut(true, [
            'order_id'    => $orderId,
            'number'      => $phoneNumber,
            'access_id'   => $accessId,
            'new_balance' => $newBalance,
            'price'       => $price,
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cooldownRelease($pdo, $cd['lock_name']);
        // محاولة إلغاء الرقم من NumbersApp
        try { naRequest($na['api_url'], $na['api_key'], 'cancelRequest', ['id' => $accessId]); } catch(Exception $ex) {}
        jsonOut(false, ['error' => 'خطأ في إنشاء الطلب: ' . $e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// ACTION: check — فحص وصول الكود
// ══════════════════════════════════════════
if ($action === 'check') {
    $orderId = (int)($_REQUEST['order_id'] ?? 0);
    if (!$orderId) jsonOut(false, ['error' => 'order_id مطلوب']);

    $ord = $pdo->prepare("SELECT o.*, p.api_url, p.api_key FROM orders o
        LEFT JOIN service_providers sp ON sp.service_id = o.service_id AND sp.is_active = 1
        LEFT JOIN providers p ON p.id = sp.provider_id AND p.provider_type = 'numbersapp'
        WHERE o.id = ? AND o.user_id = ?");
    $ord->execute([$orderId, $_SESSION['user_id']]);
    $order = $ord->fetch();

    if (!$order) jsonOut(false, ['error' => 'الطلب غير موجود']);
    if ($order['status'] === 'completed') {
        $naData = json_decode($order['numbersapp_data'] ?? '{}', true);
        jsonOut(true, ['status' => 'completed', 'code' => $naData['code'] ?? '']);
    }
    if ($order['status'] === 'cancelled') jsonOut(true, ['status' => 'cancelled']);

    if (!$order['api_url'] || !$order['api_key']) {
        // fallback: جلب من provider_id
        $fb = $pdo->prepare("SELECT p.api_url, p.api_key FROM services s JOIN providers p ON p.id = s.provider_id WHERE s.id = ? AND p.provider_type='numbersapp'");
        $fb->execute([$order['service_id']]);
        $fbRow = $fb->fetch();
        if ($fbRow) { $order['api_url'] = $fbRow['api_url']; $order['api_key'] = $fbRow['api_key']; }
    }

    if (!$order['api_url']) jsonOut(false, ['error' => 'لا يوجد مزود مرتبط']);

    $accessId = $order['provider_order_id'];
    $res = naRequest($order['api_url'], $order['api_key'], 'checkForCode', ['id' => $accessId]);

    // الرد قد يكون:
    // 1) {"status":"STATUS_OK","code":"123456"} — حسب التوثيق
    // 2) {"response":"STATUS_OK:123456"} — صيغة بديلة
    // 3) {"response":"STATUS_WAIT_CODE"} — لم يصل بعد
    $codeReceived = null;

    if (($res['status'] ?? '') === 'STATUS_OK' && isset($res['code'])) {
        $codeReceived = $res['code'];
    } elseif (isset($res['response'])) {
        $respStr = $res['response'];
        if (strpos($respStr, 'STATUS_OK') !== false) {
            $parts = explode(':', $respStr);
            if (count($parts) >= 2) $codeReceived = $parts[1];
        }
    }

    if ($codeReceived) {
        // تم استلام الكود — تحديث الطلب
        $naData = json_decode($order['numbersapp_data'] ?? '{}', true);
        $naData['code'] = $codeReceived;
        $pdo->prepare("UPDATE orders SET status='completed', numbersapp_data=?, notes=? WHERE id=?")
            ->execute([json_encode($naData, JSON_UNESCAPED_UNICODE),
                       'NUMBERSAPP_LIVE: code=' . $codeReceived, $orderId]);
        // سجل الحالة
        try {
            $pdo->prepare("INSERT INTO order_status_log (order_id, status, message, created_at) VALUES (?, 'completed', ?, NOW())")
                ->execute([$orderId, 'تم استلام كود التفعيل: ' . $codeReceived]);
        } catch(Exception $e) {}

        jsonOut(true, ['status' => 'completed', 'code' => $codeReceived]);
    }

    jsonOut(true, ['status' => 'waiting']);
}

// ══════════════════════════════════════════
// ACTION: cancel — إلغاء واسترداد
// ══════════════════════════════════════════
if ($action === 'cancel') {
    $orderId = (int)($_REQUEST['order_id'] ?? 0);
    if (!$orderId) jsonOut(false, ['error' => 'order_id مطلوب']);

    $ord = $pdo->prepare("SELECT o.*, p.api_url, p.api_key, s.name as svc_name FROM orders o
        JOIN services s ON s.id = o.service_id
        LEFT JOIN service_providers sp ON sp.service_id = o.service_id AND sp.is_active = 1
        LEFT JOIN providers p ON p.id = sp.provider_id AND p.provider_type = 'numbersapp'
        WHERE o.id = ? AND o.user_id = ? AND o.status IN ('pending','processing')");
    $ord->execute([$orderId, $_SESSION['user_id']]);
    $order = $ord->fetch();

    if (!$order) jsonOut(false, ['error' => 'الطلب غير موجود أو لا يمكن إلغاؤه']);

    if (!$order['api_url'] || !$order['api_key']) {
        $fb = $pdo->prepare("SELECT p.api_url, p.api_key FROM services s JOIN providers p ON p.id = s.provider_id WHERE s.id = ? AND p.provider_type='numbersapp'");
        $fb->execute([$order['service_id']]);
        $fbRow = $fb->fetch();
        if ($fbRow) { $order['api_url'] = $fbRow['api_url']; $order['api_key'] = $fbRow['api_key']; }
    }

    $accessId = $order['provider_order_id'];
    $cancelRes = naRequest($order['api_url'], $order['api_key'], 'cancelRequest', ['id' => $accessId]);

    // استرداد الرصيد
    $user = getUser();
    $refundAmount = (float)$order['total_price'];
    $newBalance = $user['balance'] + $refundAmount;

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE orders SET status='cancelled', notes=? WHERE id=?")->execute([
        'NUMBERSAPP_CANCEL: ' . json_encode($cancelRes), $orderId
    ]);
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $_SESSION['user_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$_SESSION['user_id'], 'credit', $refundAmount, $user['balance'], $newBalance,
                   'استرداد إلغاء رقم #' . $orderId . ' - ' . $order['svc_name'], $orderId]);
    try {
        $pdo->prepare("INSERT INTO order_status_log (order_id, status, message, created_at) VALUES (?, 'cancelled', ?, NOW())")
            ->execute([$orderId, 'تم الإلغاء واسترداد ' . formatMoney($refundAmount)]);
    } catch(Exception $e) {}
    $pdo->commit();

    jsonOut(true, [
        'refund'      => formatMoney($refundAmount),
        'new_balance' => $newBalance,
    ]);
}

// ══════════════════════════════════════════
// ACTION: resume — استعادة طلب معلق
// ══════════════════════════════════════════
if ($action === 'resume') {
    $specificId = (int)($_REQUEST['order_id'] ?? 0);

    if ($specificId) {
        $stmt = $pdo->prepare("
            SELECT o.id, o.service_id, o.provider_order_id, o.status, o.created_at,
                   o.numbersapp_data, o.total_price,
                   s.name as svc_name, s.image as svc_image
            FROM orders o
            JOIN services s ON s.id = o.service_id
            WHERE o.user_id = ?
              AND o.id = ?
              AND o.status IN ('pending','processing','completed')
              AND s.service_type = 'numbers_live'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id'], $specificId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT o.id, o.service_id, o.provider_order_id, o.status, o.created_at,
                   o.numbersapp_data, o.total_price,
                   s.name as svc_name, s.image as svc_image
            FROM orders o
            JOIN services s ON s.id = o.service_id
            WHERE o.user_id = ?
              AND o.status IN ('pending','processing')
              AND s.service_type = 'numbers_live'
            ORDER BY o.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
    }

    $order = $stmt->fetch();
    if (!$order) jsonOut(false, ['error' => 'لا يوجد طلب']);

    $naData    = json_decode($order['numbersapp_data'] ?? '{}', true);
    $number    = $naData['number'] ?? '';
    $code      = $naData['code']   ?? '';
    $elapsed   = time() - strtotime($order['created_at']);
    $remaining = max(0, 1200 - $elapsed);

    jsonOut(true, [
        'order_id'   => (int)$order['id'],
        'service_id' => (int)$order['service_id'],
        'svc_name'   => $order['svc_name'],
        'svc_image'  => $order['svc_image'],
        'number'     => $number,
        'access_id'  => $order['provider_order_id'],
        'remaining'  => $remaining,
        'status'     => $order['status'],
        'code'       => $code,
    ]);
}

// ══════════════════════════════════════════
// ACTION: auto_cancel — إلغاء تلقائي بعد 20 دقيقة
// ══════════════════════════════════════════
if ($action === 'auto_cancel') {
    $orderId = (int)($_REQUEST['order_id'] ?? 0);

    $ord = $pdo->prepare("
        SELECT o.*, p.api_url, p.api_key, s.name as svc_name
        FROM orders o
        JOIN services s ON s.id = o.service_id
        LEFT JOIN service_providers sp ON sp.service_id = o.service_id AND sp.is_active = 1
        LEFT JOIN providers p ON p.id = sp.provider_id AND p.provider_type = 'numbersapp'
        WHERE o.id = ? AND o.user_id = ? AND o.status IN ('pending','processing')
          AND s.service_type = 'numbers_live'
    ");
    $ord->execute([$orderId, $_SESSION['user_id']]);
    $order = $ord->fetch();

    if (!$order) jsonOut(false, ['error' => 'الطلب غير موجود']);

    // تحقق أن 20 دقيقة مرت فعلاً
    $elapsed = time() - strtotime($order['created_at']);
    if ($elapsed < 1200) jsonOut(false, ['error' => 'لم ينته الوقت بعد']);

    // fallback للمزود
    if (!$order['api_url'] || !$order['api_key']) {
        $fb = $pdo->prepare("SELECT p.api_url, p.api_key FROM services s JOIN providers p ON p.id = s.provider_id WHERE s.id = ? AND p.provider_type='numbersapp'");
        $fb->execute([$order['service_id']]);
        $fbRow = $fb->fetch();
        if ($fbRow) { $order['api_url'] = $fbRow['api_url']; $order['api_key'] = $fbRow['api_key']; }
    }

    $accessId = $order['provider_order_id'];

    // فحص الكود أولاً — إن وصل نكمل بدل الإلغاء
    if ($order['api_url'] && $accessId) {
        $checkRes    = naRequest($order['api_url'], $order['api_key'], 'checkForCode', ['id' => $accessId]);
        $codeReceived = null;
        if (($checkRes['status'] ?? '') === 'STATUS_OK' && isset($checkRes['code'])) {
            $codeReceived = $checkRes['code'];
        } elseif (isset($checkRes['response']) && strpos($checkRes['response'], 'STATUS_OK') !== false) {
            $parts = explode(':', $checkRes['response']);
            if (count($parts) >= 2) $codeReceived = $parts[1];
        }

        if ($codeReceived) {
            $naData = json_decode($order['numbersapp_data'] ?? '{}', true);
            $naData['code'] = $codeReceived;
            $pdo->prepare("UPDATE orders SET status='completed', numbersapp_data=?, notes=? WHERE id=?")
                ->execute([json_encode($naData, JSON_UNESCAPED_UNICODE), 'NUMBERSAPP_LIVE: code='.$codeReceived, $orderId]);
            try {
                $pdo->prepare("INSERT INTO order_status_log (order_id,status,message,created_at) VALUES (?,'completed',?,NOW())")
                    ->execute([$orderId, 'كود التفعيل وصل قبيل الإلغاء التلقائي: '.$codeReceived]);
            } catch(Exception $e) {}
            jsonOut(true, ['action_taken' => 'completed', 'code' => $codeReceived]);
        }

        // الكود لم يصل — إلغاء عند المزود
        naRequest($order['api_url'], $order['api_key'], 'cancelRequest', ['id' => $accessId]);
    }

    // استرداد الرصيد
    $user         = getUser();
    $refundAmount = (float)$order['total_price'];
    $newBalance   = $user['balance'] + $refundAmount;

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE orders SET status='cancelled', notes=? WHERE id=?")->execute([
        'NUMBERSAPP_AUTO_CANCEL: انتهاء 20 دقيقة بدون كود', $orderId
    ]);
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBalance, $_SESSION['user_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$_SESSION['user_id'], 'credit', $refundAmount, $user['balance'], $newBalance,
                   'إلغاء تلقائي 20 دقيقة: #'.$orderId.' - '.$order['svc_name'], $orderId]);
    try {
        $pdo->prepare("INSERT INTO order_status_log (order_id,status,message,created_at) VALUES (?,'cancelled',?,NOW())")
            ->execute([$orderId, 'إلغاء تلقائي بعد 20 دقيقة بدون كود']);
    } catch(Exception $e) {}
    $pdo->commit();

    if (function_exists('sendPushNotification')) {
        try { sendPushNotification($order['user_id'], 'إلغاء تلقائي 🔔',
            'تم إلغاء طلب '.$order['svc_name'].' واسترداد '.formatMoney($refundAmount).' لعدم وصول الكود'); }
        catch(Exception $e) {}
    }

    jsonOut(true, ['action_taken'=>'cancelled', 'refund'=>formatMoney($refundAmount), 'new_balance'=>$newBalance]);
}

jsonOut(false, ['error' => 'action غير صالح']);
