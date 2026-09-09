<?php

function njazTgOptionItems($raw): array {
    $raw = trim((string)$raw); if ($raw === '') return [];
    $json = json_decode($raw, true);
    $items = is_array($json) ? array_values($json) : preg_split('/\r?\n/', $raw);
    $out = [];
    foreach ($items as $item) {
        if (is_array($item)) {
            $label = trim((string)($item['label'] ?? $item['name'] ?? $item['text'] ?? ''));
            $value = (string)($item['value'] ?? $label);
        } else {
            $line = trim((string)$item); if ($line === '') continue;
            $parts = explode('|', $line, 2); $label = trim($parts[0]); $value = trim((string)($parts[1] ?? $label));
        }
        if ($label !== '') $out[] = ['label' => $label, 'value' => $value];
    }
    return $out;
}
function njazTgOptions($raw): array {
    return array_values(array_map(static fn($item) => (string)$item['label'], njazTgOptionItems($raw)));
}
function njazTgOptionValue($raw, int $index): string {
    $items = njazTgOptionItems($raw);
    return (string)($items[$index]['value'] ?? '');
}

function njazTgAskField(PDO $pdo, array $tgUser, array $fields, int $index, array $data, ?int $messageId = null): void {
    if (!isset($fields[$index])) { njazTgReviewOrder($pdo, $tgUser, $data, $messageId); return; }
    $field = $fields[$index]; $data['field_index'] = $index;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_field', $data);
    $options = njazTgOptions($field['field_options'] ?? '');
    $language = function_exists('njazTgLanguageCode') ? njazTgLanguageCode($pdo, $tgUser) : 'ar';
    $fieldId = (int)($field['id'] ?? 0);
    $fieldLabel = (string)($field['field_label'] ?? $field['field_name'] ?? '');
    $fieldPlaceholder = trim((string)($field['placeholder'] ?? ''));
    if ($fieldId > 0 && function_exists('njazTgTranslateEntityText')) {
        $fieldLabel = njazTgTranslateEntityText($pdo, $fieldLabel, $language, 'entity:field:' . $fieldId . ':label');
        if ($fieldPlaceholder !== '') $fieldPlaceholder = njazTgTranslateEntityText($pdo, $fieldPlaceholder, $language, 'entity:field:' . $fieldId . ':placeholder');
    }
    $kb = [];
    foreach ($options as $i => $option) {
        $optionLabel = (string)$option;
        if ($fieldId > 0 && function_exists('njazTgTranslateEntityText')) {
            $optionLabel = njazTgTranslateEntityText($pdo, $optionLabel, $language, 'entity:field:' . $fieldId . ':option:' . (int)$i);
        }
        $kb[] = [['text' => $optionLabel, 'callback_data' => 'fo:' . $index . ':' . $i]];
    }
    $kb[] = [
        ['text' => '↩️ رجوع للخدمات', 'callback_data' => 'order:back'],
        ['text' => '✖️ إلغاء', 'callback_data' => 'order:cancel'],
    ];
    $progress = ($index + 1) . '/' . count($fields);
    $prompt = njazTgScreen('🧾', 'بيانات الطلب', '<b>' . $progress . '</b> — ' . njazTgHtml($fieldLabel));
    $prompt .= (($field['field_type'] ?? '') === 'select' && $options) ? '\nاختر من الخيارات التالية:' : '\nأرسل القيمة في رسالة واحدة.';
    if ($fieldPlaceholder !== '') $prompt .= '\n<i>' . njazTgHtml($fieldPlaceholder) . '</i>';
    njazTgRenderSection($pdo, $tgUser, $prompt, $kb ?: null, $messageId, 'auto', $data['service_image'] ?? null);
}

function njazTgReviewOrder(PDO $pdo, array $tgUser, array $data, ?int $messageId = null): void {
    $serviceId = (int)($data['service_id'] ?? 0); $uid = (int)$tgUser['user_id'];
    $s = $pdo->prepare("SELECT * FROM services WHERE id=? AND status=1 LIMIT 1"); $s->execute([$serviceId]); $service = $s->fetch();
    if (!$service) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الخدمة غير متاحة حالياً.'); return; }
    $language = function_exists('njazTgLanguageCode') ? njazTgLanguageCode($pdo, $tgUser) : 'ar';
    $serviceSourceName = (string)($data['service_name'] ?? $service['name']);
    $serviceLabel = function_exists('njazTgTranslateEntityText')
        ? njazTgTranslateEntityText($pdo, $serviceSourceName, $language, 'entity:service:' . $serviceId . ':name')
        : $serviceSourceName;
    require_once NJAZ_TG_INCLUDES . '/pricing_helper.php';
    $price = getUserServicePrice($pdo, $uid, $service); $qty = max(1, (int)($data['quantity'] ?? 1));
    $data['unit_price'] = (float)$price['price']; $data['total'] = $data['unit_price'] * $qty;
    $data['service_image'] = (string)($service['image'] ?? ($data['service_image'] ?? ''));
    $body = 'راجع التفاصيل قبل التأكيد:';
    $body .= '\n\n<b>الخدمة</b>\n' . njazTgHtml($serviceLabel);
    $body .= '\n\n<b>الكمية</b>\n' . $qty;
    $body .= '\n<b>الإجمالي</b>\n💰 <b>' . njazTgMoney($data['total']) . '</b>';
    if (!empty($data['fields'])) {
        $body .= '\n\n<b>البيانات المدخلة</b>';
        $fieldLabels = [];
        try {
            $labelStmt = $pdo->prepare('SELECT id,field_name,field_label FROM service_fields WHERE service_id=? ORDER BY sort_order');
            $labelStmt->execute([$serviceId]);
            foreach ($labelStmt->fetchAll() as $fieldRow) {
                $label = (string)($fieldRow['field_label'] ?? $fieldRow['field_name'] ?? '');
                $fieldId = (int)($fieldRow['id'] ?? 0);
                if ($fieldId > 0 && function_exists('njazTgTranslateEntityText')) {
                    $label = njazTgTranslateEntityText($pdo, $label, $language, 'entity:field:' . $fieldId . ':label');
                }
                $fieldLabels[(string)($fieldRow['field_name'] ?? '')] = $label;
            }
        } catch (Throwable $e) { error_log('Telegram translated field labels failed: ' . $e->getMessage()); }
        foreach ($data['fields'] as $key => $value) $body .= '\n• ' . njazTgHtml($fieldLabels[(string)$key] ?? $key) . ': ' . njazTgHtml($value);
    }
    $text = njazTgScreen('🧾', 'مراجعة الطلب', $body);
    $favLabel = njazTgIsFavorite($pdo, $uid, $serviceId) ? '☆ إزالة من المفضلة' : '⭐ حفظ الخدمة';
    $kb = [
        [['text' => '✅ تأكيد وخصم', 'callback_data' => 'ord:confirm']],
        [['text' => '↩️ رجوع للخدمات', 'callback_data' => 'order:back'], ['text' => '✖️ إلغاء', 'callback_data' => 'order:cancel']],
        [['text' => $favLabel, 'callback_data' => 'fav:toggle:' . $serviceId]],
    ];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_review', $data);
    njazTgRenderSection($pdo, $tgUser, $text, $kb, $messageId, 'auto', $service['image'] ?? ($data['service_image'] ?? null));
}

function njazTgLegacySessionCookie(PDO $pdo, int $userId): string {
    // numbersapp_live.php يعتمد على isLoggedIn() ولا يقرأ بوابة HMAC القديمة،
    // لذلك نمرر له جلسة PHP مؤقتة للحساب المرتبط من دون تعديل ملف الموقع.
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    $sessionId = session_id();
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'user';
    $_SESSION['telegram_internal'] = true;
    @session_write_close();
    return $sessionId !== '' ? 'PHPSESSID=' . $sessionId : '';
}

function njazTgNeedsLegacySession(string $path): bool {
    return in_array(basename(parse_url($path, PHP_URL_PATH) ?: $path), ['numbersapp_live.php', 'kyc_submit.php', 'quiz.php'], true);
}

function njazTgInternalPost(PDO $pdo, int $userId, string $path, array $fields): array {
    $req = telegramBuildInternalRequest($pdo, $userId, $fields);
    $url = rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
    $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($req['fields']), CURLOPT_HTTPHEADER => $req['headers'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true];
    if (njazTgNeedsLegacySession($path)) {
        $cookie = njazTgLegacySessionCookie($pdo, $userId);
        if ($cookie !== '') $options[CURLOPT_COOKIE] = $cookie;
    }
    $ch = curl_init($url); curl_setopt_array($ch, $options);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['status' => false, 'transport_error' => true, 'message' => $err];
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : ['status' => false, 'transport_error' => true, 'message' => 'استجابة غير صالحة'];
}

function njazTgInternalMultipartPost(PDO $pdo, int $userId, string $path, array $fields, array $files): array {
    $req = telegramBuildInternalRequest($pdo, $userId, $fields);
    $multipart = $req['fields'];
    foreach ($files as $name => $file) {
        if (!is_array($file) || empty($file['path']) || !is_file($file['path'])) continue;
        $multipart[$name] = new CURLFile((string)$file['path'], (string)($file['mime'] ?? 'application/octet-stream'), (string)($file['name'] ?? basename($file['path'])));
    }
    $url = rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
    // عند وجود CURLFile يجب ترك cURL ينشئ Content-Type multipart/form-data مع boundary الصحيح.
    $multipartHeaders = array_values(array_filter($req['headers'], static function ($header): bool {
        return stripos((string)$header, 'Content-Type:') !== 0;
    }));
    $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart, CURLOPT_HTTPHEADER => $multipartHeaders, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => true];
    // kyc_submit.php يعتمد على isLoggedIn() و$_SESSION، لذلك يجب تمرير جلسة الحساب المرتبط مع multipart أيضاً.
    if (njazTgNeedsLegacySession($path)) {
        $cookie = njazTgLegacySessionCookie($pdo, $userId);
        if ($cookie !== '') $options[CURLOPT_COOKIE] = $cookie;
    }
    $ch = curl_init($url); curl_setopt_array($ch, $options);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['status' => false, 'transport_error' => true, 'message' => $err];
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : ['status' => false, 'transport_error' => true, 'message' => 'استجابة غير صالحة'];
}

function njazTgInternalGet(PDO $pdo, int $userId, string $path, array $query): array {
    $req = telegramBuildInternalRequest($pdo, $userId, $query);
    $url = rtrim(SITE_URL, '/') . '/' . ltrim($path, '/') . '?' . http_build_query($req['fields']);
    $options = [CURLOPT_HTTPHEADER => $req['headers'], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true];
    if (njazTgNeedsLegacySession($path)) {
        $cookie = njazTgLegacySessionCookie($pdo, $userId);
        if ($cookie !== '') $options[CURLOPT_COOKIE] = $cookie;
    }
    $ch = curl_init($url); curl_setopt_array($ch, $options);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err) return ['status' => false, 'transport_error' => true, 'message' => $err];
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : ['status' => false, 'transport_error' => true, 'message' => 'استجابة غير صالحة'];
}

function njazTgConfirmOrder(PDO $pdo, array $tgUser, array $data): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_orders') || !njazTgServiceEnabled($pdo, (int)($data['service_id'] ?? 0))) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'تنفيذ الطلب غير متاح عبر البوت حالياً.'); return; }

    // حجز ذري لحالة التأكيد: أول callback فقط يصل إلى API نجاز المركزي.
    $claimed = njazTgClaimStateAction($pdo, $tgUser, 'order_review', 'order_processing');
    if ($claimed === null) {
        $current = (string)($tgUser['state'] ?? 'menu');
        $message = $current === 'order_processing'
            ? '⏳ تتم معالجة طلبك الآن. لن يتم خصم الرصيد أكثر من مرة.'
            : 'انتهت جلسة التأكيد أو تم تنفيذها مسبقاً. افتح الخدمات أو طلباتي للتحقق من النتيجة.';
        njazTgSend($pdo, (int)$tgUser['chat_id'], $message, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الخدمات', 'callback_data' => 'menu:services']]]);
        return;
    }
    $data = $claimed;
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/place_order_ajax.php', [
        'service_id' => (int)$data['service_id'], 'quantity' => (int)($data['quantity'] ?? 1), 'fields' => $data['fields'] ?? [],
    ]);
    if (!empty($result['status'])) {
        $orderRef = (string)($result['order_ref'] ?? '');
        if ($orderRef === '' && !empty($result['order_id'])) {
            $lookup = $pdo->prepare('SELECT * FROM orders WHERE id=? AND user_id=? LIMIT 1');
            $lookup->execute([(int)$result['order_id'], (int)$tgUser['user_id']]);
            $created = $lookup->fetch() ?: ['id' => (int)$result['order_id']];
            $orderRef = njazTgOrderRef($pdo, $created);
        }
        $totalValue = $result['total'] ?? null;
        $totalText = is_numeric($totalValue) ? njazTgMoney($totalValue) : njazTgHtml($totalValue ?? '—');
        $text = njazTgScreen('✅', 'تم إنشاء الطلب بنجاح',
            '<b>الخدمة</b>\n' . njazTgHtml($data['service_name'] ?? 'الخدمة')
            . '\n\n<b>رقم العملية</b>\n<code>' . njazTgHtml($orderRef !== '' ? $orderRef : '—') . '</code>'
            . '\n\n<b>الإجمالي</b>\n' . $totalText
            . '\n<b>الرصيد المتبقي</b>\n' . njazTgMoney($result['new_balance'] ?? 0));
        if (!empty($result['delivered_code'])) $text .= '\n\n<b>الكود:</b>\n<code>' . njazTgHtml($result['delivered_code']) . '</code>';
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
    } else {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_review', $data);
        $isTransport = !empty($result['transport_error']);
        $msg = $isTransport
            ? 'تعذر الاتصال بنظام نجاز لإكمال الطلب. لم يتم تأكيد الخصم من البوت.'
            : njazTgHtml($result['message'] ?? 'خطأ غير معروف');
        $failureBody = '<b>السبب</b>\n' . $msg . '\n\nيمكنك المحاولة مرة أخرى أو فتح طلباتي للتحقق من الحالة.';
        $failureText = njazTgScreen('⚠️', $isTransport ? 'تعذر الاتصال بالنظام' : 'تعذر تنفيذ الطلب', $failureBody);
        njazTgSend($pdo, (int)$tgUser['chat_id'], $failureText, [[['text' => '🔁 العودة للتأكيد', 'callback_data' => 'ord:retry'], ['text' => '📦 طلباتي', 'callback_data' => 'menu:orders']], [['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
    }
}

function njazTgBalance(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_balance')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'عرض الرصيد غير متاح عبر البوت حالياً.'); return; }
    $user = getUser((int)$tgUser['user_id']);
    $text = '<b>المحفظة</b>\nرصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>';
    $kb = [[['text' => 'شحن الرصيد', 'callback_data' => 'menu:topup'], ['text' => 'الرئيسية', 'callback_data' => 'home']]];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgNotificationPrefs(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $uid = (int)$tgUser['user_id'];
    $orderEnabled = true; $topupEnabled = true; $globalEnabled = true;
    try {
        $s = $pdo->prepare('SELECT notif_order_update, notif_topup_update FROM users WHERE id=? LIMIT 1');
        $s->execute([$uid]); $row = $s->fetch() ?: [];
        $orderEnabled = (int)($row['notif_order_update'] ?? 1) === 1;
        $topupEnabled = (int)($row['notif_topup_update'] ?? 1) === 1;
    } catch (Throwable $e) {
        // التثبيتات القديمة قد لا تحتوي أعمدة التفضيلات؛ نُبقيها مفعلة افتراضيًا.
    }
    try {
        $v = $pdo->query("SELECT notify_customer_updates FROM telegram_bot_settings WHERE id=1 LIMIT 1")->fetchColumn();
        if ($v !== false) $globalEnabled = (int)$v === 1;
    } catch (Throwable $e) {}
    $text = '<b>🔔 تفضيلات الإشعارات</b>\n\n'
        . 'إشعارات الطلبات: <b>' . ($orderEnabled ? 'مفعلة ✅' : 'متوقفة ⛔') . '</b>\n'
        . 'إشعارات الشحن: <b>' . ($topupEnabled ? 'مفعلة ✅' : 'متوقفة ⛔') . '</b>\n'
        . 'حالة الإرسال من الإدارة: <b>' . ($globalEnabled ? 'مسموح' : 'متوقف مؤقتًا') . '</b>\n\n'
        . 'يمكنك تغيير تفضيلاتك الشخصية، بينما قد توقف الإدارة الإرسال العام مؤقتًا عند الحاجة.';
    $kb = [
        [['text' => ($orderEnabled ? '⛔ إيقاف إشعارات الطلبات' : '✅ تشغيل إشعارات الطلبات'), 'callback_data' => 'notif:order']],
        [['text' => ($topupEnabled ? '⛔ إيقاف إشعارات الشحن' : '✅ تشغيل إشعارات الشحن'), 'callback_data' => 'notif:topup']],
        [['text' => '🔄 تحديث', 'callback_data' => 'notif:refresh'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']],
    ];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgNotificationToggle(PDO $pdo, array $tgUser, string $kind, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $column = $kind === 'topup' ? 'notif_topup_update' : ($kind === 'order' ? 'notif_order_update' : '');
    if ($column === '') { njazTgNotificationPrefs($pdo, $tgUser, $messageId); return; }
    try {
        $s = $pdo->prepare("SELECT {$column} FROM users WHERE id=? LIMIT 1");
        $s->execute([(int)$tgUser['user_id']]);
        $current = $s->fetchColumn();
        $next = ((int)$current === 1) ? 0 : 1;
        $u = $pdo->prepare("UPDATE users SET {$column}=? WHERE id=?");
        $u->execute([$next, (int)$tgUser['user_id']]);
    } catch (Throwable $e) {
        error_log('Telegram notification preference failed: ' . $e->getMessage());
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر تحديث التفضيل حالياً. حاول لاحقاً.');
        return;
    }
    njazTgNotificationPrefs($pdo, $tgUser, $messageId);
}

function njazTgOrderRef(PDO $pdo, array $order): string {
    $ref = strtoupper(trim((string)($order['ref_id'] ?? '')));
    if ($ref !== '') return $ref;
    $id = (int)($order['id'] ?? 0);
    if ($id <= 0) return '';
    // الطلبات القديمة التي لا تملك ref_id تحصل على معرف رسمي دائم يبدأ بـ ID.
    try {
        $candidate = 'ID_' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare("UPDATE orders SET ref_id=? WHERE id=? AND (ref_id IS NULL OR ref_id='')");
        $stmt->execute([$candidate, $id]);
        $stmt = $pdo->prepare('SELECT ref_id FROM orders WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $ref = strtoupper(trim((string)$stmt->fetchColumn()));
        if ($ref !== '') return $ref;
    } catch (Throwable $e) { error_log('Telegram order ref sync failed: ' . $e->getMessage()); }
    return 'ID_' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
}

function njazTgOrderStatus(string $status): array {
    return [
        'pending' => ['label' => 'معلقة', 'icon' => '⏳'],
        'processing' => ['label' => 'قيد التنفيذ', 'icon' => '🔄'],
        'completed' => ['label' => 'جاهزة', 'icon' => '✅'],
        'cancelled' => ['label' => 'ملغية', 'icon' => '❌'],
        'failed' => ['label' => 'ملغية / فاشلة', 'icon' => '⚠️'],
    ][$status] ?? ['label' => 'غير معروفة', 'icon' => 'ℹ️'];
}

function njazTgOrders(PDO $pdo, array $tgUser, ?int $messageId = null, ?string $bucket = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_orders_history')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'سجل الطلبات غير متاح عبر البوت حالياً.'); return; }

    $where = '';
    $params = [(int)$tgUser['user_id']];
    if ($bucket === 'pending') $where = " AND o.status IN ('pending','processing')";
    elseif ($bucket === 'cancelled') $where = " AND o.status IN ('cancelled','failed')";
    elseif ($bucket === 'completed') $where = " AND o.status='completed'";

    $s = $pdo->prepare("SELECT o.*, s.name service_name FROM orders o LEFT JOIN services s ON s.id=o.service_id WHERE o.user_id=?{$where} ORDER BY o.created_at DESC, o.id DESC LIMIT 30");
    $s->execute($params); $orders = $s->fetchAll();
    $bucketTitle = [
        'pending' => '⏳ الطلبات المعلقة',
        'cancelled' => '❌ الطلبات الملغية',
        'completed' => '✅ الطلبات الجاهزة',
    ][$bucket] ?? '📦 طلباتي';

    $text = njazTgScreen('📦', $bucketTitle);
    $detailButtons = [];
    if (!$orders) $text .= '\n\nلا توجد عمليات في هذا القسم. يمكنك استخدام «فحص عملية» إذا كان لديك رقم ID.';
    foreach ($orders as $o) {
        $orderRef = njazTgOrderRef($pdo, $o);
        $state = njazTgOrderStatus((string)$o['status']);
        $text .= '\n\n<b>' . njazTgHtml($orderRef) . '</b>'
            . '\n📦 ' . njazTgHtml($o['service_name'] ?? 'الخدمة')
            . '\n' . $state['icon'] . ' الحالة: ' . njazTgHtml($state['label'])
            . '\n💰 المبلغ: ' . njazTgMoney($o['total_price'] ?? 0)
            . '\n🕐 ' . njazTgHtml((string)($o['created_at'] ?? ''));
        $detailButtons[] = ['text' => '🔎 ' . $orderRef, 'callback_data' => 'order:view:' . (int)$o['id']];
    }

    $kb = array_chunk($detailButtons, 2);
    $kb[] = [
        ['text' => '⏳ معلقة', 'callback_data' => 'orders:status:pending'],
        ['text' => '❌ ملغية', 'callback_data' => 'orders:status:cancelled']
    ];
    $kb[] = [
        ['text' => '✅ جاهزة', 'callback_data' => 'orders:status:completed'],
        ['text' => '🔎 فحص عملية', 'callback_data' => 'orders:lookup']
    ];
    $kb[] = [['text' => '↩️ الرئيسية', 'callback_data' => 'home']];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgOrderLookupStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_lookup');
    njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>🔎 فحص عملية</b>\n\nأرسل رقم العملية الرسمي الذي يبدأ بـ <code>ID</code>، مثل:\n<code>ID_A1B2C3D4</code>\n\nيمكنك إرسال الرقم كما يظهر في الموقع.', [[['text' => 'إلغاء', 'callback_data' => 'orders:cancel']]]);
} 

function njazTgOrderDetail(PDO $pdo, array $tgUser, int $orderId, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if ($orderId <= 0) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'رقم العملية غير صالح.'); return; }
    $s = $pdo->prepare("SELECT o.*, s.name service_name, c.name cat_name FROM orders o LEFT JOIN services s ON s.id=o.service_id LEFT JOIN categories c ON c.id=s.category_id WHERE o.id=? AND o.user_id=? LIMIT 1");
    $s->execute([$orderId, (int)$tgUser['user_id']]);
    $order = $s->fetch();
    if (!$order) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'لم يتم العثور على العملية ضمن حسابك.');
        return;
    }
    $state = njazTgOrderStatus((string)$order['status']);
    $text = njazTgScreen('🔎', 'تفاصيل العملية',
        '<b>رقم العملية</b>\n<code>' . njazTgHtml(njazTgOrderRef($pdo, $order)) . '</code>'
        . '\n\n<b>القسم</b>\n' . njazTgHtml($order['cat_name'] ?? '—')
        . '\n<b>الخدمة</b>\n' . njazTgHtml($order['service_name'] ?? 'الخدمة')
        . '\n\n' . $state['icon'] . ' <b>الحالة:</b> ' . njazTgHtml($state['label'])
        . '\n💰 <b>المبلغ:</b> ' . njazTgMoney($order['total_price'] ?? 0)
        . '\n🔢 <b>الكمية:</b> ' . (int)($order['quantity'] ?? 1)
        . '\n🕐 <b>الإنشاء:</b> ' . njazTgHtml((string)($order['created_at'] ?? '')));
    try {
        $ls = $pdo->prepare("SELECT status,created_at FROM order_status_log WHERE order_id=? ORDER BY created_at DESC LIMIT 5");
        $ls->execute([$orderId]); $logs = $ls->fetchAll();
        if ($logs) {
            $text .= '\n\n<b>آخر التحديثات</b>';
            foreach ($logs as $log) {
                $logState = njazTgOrderStatus((string)$log['status']);
                $text .= '\n' . $logState['icon'] . ' ' . njazTgHtml($logState['label']) . ' — ' . njazTgHtml((string)$log['created_at']);
            }
        }
    } catch (Throwable $e) {}
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    $kb = [
        [['text' => '🔄 تحديث الحالة', 'callback_data' => 'order:view:' . $orderId]],
        [['text' => '🆘 دعم حول الطلب', 'callback_data' => 'support:order:' . $orderId]],
        [['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']],
    ];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgOrderLookup(PDO $pdo, array $tgUser, string $query): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $query = strtoupper(trim($query));
    $query = preg_replace('/\s+/', '', $query);
    if (!preg_match('/^ID(?:[_-])[A-Z0-9]+$/', $query)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'رقم العملية غير صحيح. يجب أن يبدأ بـ <code>ID</code>، مثل <code>ID_A1B2C3D4</code>.', [[['text' => 'إلغاء', 'callback_data' => 'orders:cancel']]]);
        return;
    }
    $s = $pdo->prepare("SELECT id FROM orders WHERE user_id=? AND UPPER(ref_id)=? LIMIT 1");
    $s->execute([(int)$tgUser['user_id'], $query]); $orderId = (int)$s->fetchColumn();
    if ($orderId <= 0) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'لم يتم العثور على عملية بهذا الرقم ضمن حسابك. تأكد من رقم ID وأعد المحاولة.', [[['text' => '🔎 محاولة أخرى', 'callback_data' => 'orders:lookup'], ['text' => 'طلباتي', 'callback_data' => 'menu:orders']]]);
        return;
    }
    njazTgOrderDetail($pdo, $tgUser, $orderId);
}
