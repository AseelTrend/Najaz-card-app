<?php
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$path = trim($_GET['__path'] ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
$route = $segments[0] ?? '';

$resellerUser = resellerAuth($pdo);

// خريطة الأقسام (للاستخدام في تنسيق المنتجات ومسار content)
$catStmt = $pdo->query("SELECT id, name, image, parent_id FROM categories WHERE status=1");
$categoryMap = [];
foreach ($catStmt->fetchAll() as $c) $categoryMap[$c['id']] = $c;

// ══════════════════════════════════════════════════════════════
//  GET /client/api/profile
// ══════════════════════════════════════════════════════════════
if ($route === 'profile') {
    echo json_encode([
        'balance' => number_format((float)$resellerUser['balance'], 3, '.', ''),
        'email'   => $resellerUser['email'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  GET /client/api/products
//  GET /client/api/products?products_id=id1,id2,id3
//  GET /client/api/products?base=1
// ══════════════════════════════════════════════════════════════
if ($route === 'products') {
    $baseOnly = ($_GET['base'] ?? '') === '1';
    $sql = "SELECT * FROM services WHERE status=1 AND deleted_at IS NULL";
    $params = [];

    if (!empty($_GET['products_id'])) {
        $ids = array_filter(array_map('intval', explode(',', $_GET['products_id'])));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql .= " AND id IN ($in)";
            $params = $ids;
        }
    }
    $sql .= " ORDER BY sort_order ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $svc) {
        $out[] = resellerFormatProduct($pdo, $svc, $categoryMap, $resellerUser, $baseOnly);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  GET /client/api/content/{parent_id}
// ══════════════════════════════════════════════════════════════
if ($route === 'content') {
    $parentId = (int)($segments[1] ?? 0);

    $childCats = array_values(array_filter($categoryMap, fn($c) => (int)($c['parent_id'] ?? 0) === $parentId));
    $catsOut = array_map(fn($c) => [
        'id'    => (int)$c['id'],
        'name'  => $c['name'],
        'image' => !empty($c['image']) ? (SITE_URL . '/' . ltrim($c['image'], '/')) : '',
    ], $childCats);

    $svcStmt = $pdo->prepare("SELECT * FROM services WHERE category_id=? AND status=1 AND deleted_at IS NULL ORDER BY sort_order ASC");
    $svcStmt->execute([$parentId]);
    $prodsOut = [];
    foreach ($svcStmt->fetchAll() as $svc) {
        $prodsOut[] = resellerFormatProduct($pdo, $svc, $categoryMap, $resellerUser);
    }

    echo json_encode(['categories' => array_values($catsOut), 'products' => $prodsOut], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  GET /client/api/newOrder/{product_id}/params?qty=&order_uuid=&...
// ══════════════════════════════════════════════════════════════
if ($route === 'newOrder') {
    $productId = (int)($segments[1] ?? 0);
    $qty       = max(1, (int)($_GET['qty'] ?? 1));
    $orderUuid = trim($_GET['order_uuid'] ?? '');

    if ($orderUuid === '' || !preg_match('/^[0-9a-f-]{20,64}$/i', $orderUuid)) {
        resellerFail(114, 'order_uuid is required and must be a valid UUIDv4', 'حقل order_uuid مطلوب ويجب أن يكون بصيغة UUIDv4 صحيحة');
    }

    // ── تكرار الطلب (Idempotency) — إن كان uuid مُستخدَماً سابقاً، أعِد نفس النتيجة ──
    $existing = $pdo->prepare("SELECT * FROM orders WHERE api_order_uuid=? LIMIT 1");
    $existing->execute([$orderUuid]);
    if ($existing = $existing->fetch()) {
        echo json_encode(['status' => 'OK', 'data' => resellerOrderResponseShape($existing, null, $pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $svcStmt = $pdo->prepare("SELECT * FROM services WHERE id=? AND deleted_at IS NULL LIMIT 1");
    $svcStmt->execute([$productId]);
    $service = $svcStmt->fetch();
    if (!$service) resellerFail(109);
    if ((int)$service['status'] !== 1) resellerFail(110);

    // التحقق من الكمية
    if ((int)$service['min_qty'] === 1 && (int)$service['max_qty'] === 1) {
        if ($qty !== 1) resellerFail(106, 'This product only accepts quantity = 1', 'هذا المنتج يقبل فقط كمية = 1');
    } else {
        if ($qty < (int)$service['min_qty']) resellerFail(112);
        if ($qty > (int)$service['max_qty']) resellerFail(113);
    }

    // جمع الحقول الديناميكية (playerId وأي مفتاح آخر تم إرساله)
    $fieldsStmt = $pdo->prepare("SELECT field_name, field_label, is_required FROM service_fields WHERE service_id=?");
    $fieldsStmt->execute([$productId]);
    $svcFields = $fieldsStmt->fetchAll();

    $fieldData = [];
    foreach ($svcFields as $f) {
        $val = $_GET[$f['field_name']] ?? $_GET['playerId'] ?? null; // دعم playerId كاسم عام شائع أيضاً
        if ($val === null && !empty($f['is_required'])) {
            resellerFail(114, "Missing required parameter: {$f['field_name']}", "الحقل المطلوب غير موجود: {$f['field_name']}");
        }
        if ($val !== null) $fieldData[$f['field_name']] = $val;
    }
    // أي معاملات إضافية أرسلها المطوّر (anyKey=anyVal) تُحفَظ أيضاً كما في مثال التوثيق
    foreach ($_GET as $k => $v) {
        if (in_array($k, ['qty', 'order_uuid', '__path'], true)) continue;
        if (!isset($fieldData[$k])) $fieldData[$k] = $v;
    }

    // السعر والرصيد
    $priceInfo = getUserServicePrice($pdo, (int)$resellerUser['id'], $service);
    $unitPrice = (float)$priceInfo['price'];
    $totalPrice = $unitPrice * $qty;

    if ((float)$resellerUser['balance'] < $totalPrice) resellerFail(100);

    // ── فحص الـ Cooldown المركزي (موحّد مع طلبات الموقع) ──
    // يجب أن يتم قبل أي خصم رصيد أو إرسال طلب للمزود الخارجي
    $cd = cooldownAcquire($pdo, $productId, $fieldData);
    if (!$cd['ok']) {
        resellerFail(111, cooldownMessageEn($cd['remaining']), cooldownMessage($cd['remaining'])); // 111 = Try again after 1 minute (موثّق أصلاً في الـ API)
    }

    $apiOrderId = 'ID_' . bin2hex(random_bytes(8));

    try {
        $pdo->beginTransaction();

        // خصم الرصيد
        $upd = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id=? AND balance >= ?");
        $upd->execute([$totalPrice, $resellerUser['id'], $totalPrice]);
        if ($upd->rowCount() === 0) { $pdo->rollBack(); cooldownRelease($pdo, $cd['lock_name']); resellerFail(100); }

        $ins = $pdo->prepare("
            INSERT INTO orders (user_id, service_id, quantity, unit_price, total_price, status, field_data, api_order_id, api_order_uuid, ref_id, source, cooldown_key)
            VALUES (?,?,?,?,?, 'pending', ?, ?, ?, ?, 'reseller_api', ?)
        ");
        $ins->execute([$resellerUser['id'], $productId, $qty, $unitPrice, $totalPrice, json_encode($fieldData, JSON_UNESCAPED_UNICODE), $apiOrderId, $orderUuid, $apiOrderId, $cd['cooldown_key']]);
        $orderId = (int)$pdo->lastInsertId();

        $pdo->commit();
        cooldownRelease($pdo, $cd['lock_name']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cooldownRelease($pdo, $cd['lock_name']);
        error_log('reseller_api newOrder error: ' . $e->getMessage());
        resellerFail(500);
    }

    // ── محاولة تسليم فوري من مخزون الأكواد (إن كانت الخدمة مفعّلة كخدمة أكواد) ──
    $finalStatus = 'wait';
    $deliveredFromCodeStock = false;
    try {
        if (codeStockIsEnabled($pdo, $productId)) {
            $code = codeStockDeliver($pdo, $productId, $orderId, (int)$resellerUser['id']);
            if ($code !== null) {
                $pdo->prepare("UPDATE orders SET status='completed', notes=? WHERE id=?")
                    ->execute(['تم تسليم الكود تلقائياً من المخزون (API الموزّعين)', $orderId]);
                $finalStatus = 'accept';
                $deliveredFromCodeStock = true;
            } else {
                $pdo->prepare("UPDATE orders SET admin_notes=? WHERE id=?")
                    ->execute(['⚠️ نفاد مخزون الأكواد — طلب عبر API الموزّعين يحتاج مراجعة يدوية', $orderId]);
            }
        }
    } catch (Exception $e) {
        error_log('reseller_api code_stock error: ' . $e->getMessage());
    }

    // ── إرسال فوري للمزود الخارجي — نفس منطق place_order.php بالضبط (أولوية متعددة المزودين) ──
    if (!$deliveredFromCodeStock) {
        try {
            $spList = $pdo->prepare("
                SELECT sp.*, p.api_key as prov_key, p.api_url as prov_url, p.provider_type, p.status as prov_status
                FROM service_providers sp
                JOIN providers p ON sp.provider_id = p.id
                WHERE sp.service_id=? AND sp.is_active=1 AND p.status=1
                ORDER BY sp.priority ASC
            ");
            $spList->execute([$productId]);
            $svcProviders = $spList->fetchAll();
        } catch (Exception $e) {
            $svcProviders = [];
        }

        // توافقية مع الإصدار القديم (عمود provider_id مباشر في جدول services) إن لم يوجد service_providers
        if (!$svcProviders && !empty($service['provider_id'])) {
            $oldProv = $pdo->prepare("SELECT * FROM providers WHERE id=? AND status=1");
            $oldProv->execute([$service['provider_id']]);
            $op = $oldProv->fetch();
            if ($op) {
                $svcProviders[] = [
                    'provider_id'         => $op['id'],
                    'prov_key'            => $op['api_key'],
                    'prov_url'            => $op['api_url'],
                    'provider_type'       => $op['provider_type'],
                    'prov_status'         => $op['status'],
                    'provider_service_id' => $service['provider_service_id'],
                ];
            }
        }

        $sent = false;
        foreach ($svcProviders as $sp) {
            if (empty($sp['prov_status'])) continue;
            if (!in_array($sp['provider_type'], ['oranos', 'ap4stor'], true)) continue; // هذا الإصدار يدعم فقط هذا النوع

            $provResult = resellerSendHeaderApiOrder(
                $sp['prov_url'], $sp['prov_key'],
                $sp['provider_service_id'], $qty,
                $fieldData, $orderId, $sp['provider_type']
            );

            if ($provResult && isset($provResult['data']['order_id'])
                && ($provResult['data']['status'] ?? '') !== 'reject') {

                $provOrderId = $provResult['data']['order_id'];
                $oStatus     = $provResult['data']['status'] ?? 'wait';
                $newStatus   = $oStatus === 'accept' ? 'processing' : 'pending';

                $pdo->prepare("UPDATE orders SET provider_order_id=?,status=?,notes=?,used_provider_id=? WHERE id=?")
                    ->execute([$provOrderId, $newStatus,
                        strtoupper($sp['provider_type']) . ': ' . $oStatus,
                        $sp['provider_id'], $orderId]);

                $finalStatus = ($oStatus === 'accept') ? 'accept' : 'wait';
                $sent = true;
                break; // نتوقف فقط عند نجاح حقيقي (نفس سلوك place_order.php)
            }
            // فشل → يكمل تلقائياً للمزود التالي بالأولوية (لا break)
        }

        if (!$sent && $svcProviders) {
            $pdo->prepare("UPDATE orders SET notes=? WHERE id=?")
                ->execute(['فشل جميع المزودين عبر API الموزّعين — يحتاج مراجعة يدوية', $orderId]);
        }
    }

    $orderRow = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $orderRow->execute([$orderId]);
    $orderRow = $orderRow->fetch();

    echo json_encode(['status' => 'OK', 'data' => resellerOrderResponseShape($orderRow, $finalStatus, $pdo)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  GET /client/api/check?orders=[id1,id2]        (بمعرّف API الطلب)
//  GET /client/api/check?orders=[uuid1]&uuid=1    (بمعرّف order_uuid)
// ══════════════════════════════════════════════════════════════
if ($route === 'check') {
    $raw = trim($_GET['orders'] ?? '', '[] ');
    $ids = array_filter(array_map('trim', explode(',', $raw)));
    $byUuid = ($_GET['uuid'] ?? '') === '1';

    if (!$ids) { echo json_encode(['status' => 'OK', 'data' => []]); exit; }

    $col = $byUuid ? 'api_order_uuid' : 'api_order_id';
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT o.*, s.name AS service_name FROM orders o
                            LEFT JOIN services s ON o.service_id=s.id
                            WHERE o.user_id=? AND o.$col IN ($in)");
    $stmt->execute(array_merge([$resellerUser['id']], array_values($ids)));

    $out = [];
    foreach ($stmt->fetchAll() as $o) {
        [$replay, $message] = resellerBuildReplayAndMessage($pdo, $o);
        $out[] = [
            'order_id'     => $o['api_order_id'],
            'quantity'     => (int)$o['quantity'],
            'data'         => json_decode($o['field_data'] ?? '{}', true) ?: (object)[],
            'created_at'   => $o['created_at'],
            'product_name' => $o['service_name'],
            'price'        => number_format((float)$o['total_price'], 10, '.', ''),
            'status'       => resellerMapStatus($o['status']),
            'replay_api'   => $replay,
            'message'      => $message, // حقل إضافي: تفاصيل التجهيز / سبب الإلغاء
        ];
    }
    echo json_encode(['status' => 'OK', 'data' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

resellerFail(114, 'Unknown endpoint', 'نقطة النهاية غير معروفة');

// ══════════════════════════════════════════════════════════════
//  دوال مساعدة محلية للراوتر
// ══════════════════════════════════════════════════════════════
function resellerMapStatus(string $s): string {
    return match ($s) {
        'completed'                     => 'accept',
        'cancelled', 'rejected', 'failed' => 'reject',
        default                          => 'wait', // pending / processing / needs_review
    };
}

function resellerBuildReplayAndMessage(PDO $pdo, array $order): array {
    // 1) الأولوية للكود المسلَّم فعلياً من مخزون الأكواد (يظهر في replay_api كما في توثيق ap4stor)
    $replay = null;
    try {
        $codeRow = codeStockGetByOrder($pdo, (int)$order['id']);
        if ($codeRow && !empty($codeRow['code'])) {
            $replay = [['replay' => [$codeRow['code']]]];
        }
    } catch (Exception $e) {}

    // 2) رسالة الحالة (تشمل: تفاصيل التجهيز، سبب الإلغاء، ملاحظات المزود...)
    $message = $order['status_message'] ?? $order['notes'] ?? $order['admin_notes'] ?? null;

    return [$replay, $message];
}

function resellerOrderResponseShape(array $order, ?string $statusOverride = null, ?PDO $pdo = null): array {
    $replay = null;
    $message = null;
    if ($pdo) {
        [$replay, $message] = resellerBuildReplayAndMessage($pdo, $order);
    }
    return [
        'order_id'   => $order['api_order_id'],
        'status'     => $statusOverride ?? resellerMapStatus($order['status']),
        'price'      => (float)$order['total_price'],
        'data'       => json_decode($order['field_data'] ?? '{}', true) ?: (object)[],
        'replay_api' => $replay,
        'message'    => $message, // حقل إضافي (خارج توثيق ap4stor الرسمي) لعرض تفاصيل التجهيز/سبب الإلغاء
    ];
}

// ─── Oranos / ap4stor: إرسال طلب فعلي للمزود (نفس دالة place_order.php حرفياً) ───
function resellerSendHeaderApiOrder($baseUrl, $apiKey, $productId, $quantity, $fieldData, $orderId, $type = 'oranos') {
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );

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
    return json_decode($res, true) ?? ['error' => 'invalid_json', 'raw' => substr($res, 0, 500)];
}
