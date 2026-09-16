<?php

function njazTgNumbersLiveConfirm(PDO $pdo, array $tgUser, int $serviceId, array $service, ?int $messageId = null): void {
    require_once NJAZ_TG_INCLUDES . '/pricing_helper.php';
    $price = getUserServicePrice($pdo, (int)$tgUser['user_id'], $service);
    $amount = (float)($price['price'] ?? $service['price'] ?? 0);
    $user = getUser((int)$tgUser['user_id']);
    $serviceName = (string)($service['display_name'] ?? $service['name'] ?? 'رقم مباشر');
    $language = function_exists('njazTgLanguageCode') ? njazTgLanguageCode($pdo, $tgUser) : 'ar';
    $displayServiceName = function_exists('njazTgTranslateEntityText')
        ? njazTgTranslateEntityText($pdo, $serviceName, $language, 'entity:service:' . $serviceId . ':name')
        : $serviceName;
    $data = [
        'service_id' => $serviceId,
        'category_id' => (int)($service['category_id'] ?? 0),
        'service_name' => $serviceName,
        'service_image' => (string)($service['image'] ?? ''),
        'price' => $amount,
    ];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_confirm', $data);
    $text = njazTgScreen('📱', njazTgClipLabel($displayServiceName),
        '<b>الخدمة</b>\n' . njazTgHtml($displayServiceName)
        . '\n\n<b>السعر</b>\n💰 ' . njazTgMoney($amount)
        . '\n<b>رصيدك الحالي</b>\n' . njazTgMoney($user['balance'] ?? 0)
        . '\n\nاضغط «شراء الرقم» لشراء رقم مؤقت وانتظار كود التفعيل.');
    $kb = [
        [['text' => '📱 شراء الرقم', 'callback_data' => 'nl:buy:' . $serviceId, 'style' => 'success']],
        [['text' => '↩️ رجوع للخدمات', 'callback_data' => 'order:back'], ['text' => '✖️ إلغاء', 'callback_data' => 'order:cancel']],
    ];
    njazTgRenderSection($pdo, $tgUser, $text, $kb, $messageId, 'auto', $service['image'] ?? null);
}

function njazTgNumbersLiveBuy(PDO $pdo, array $tgUser, int $serviceId, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_orders') || !njazTgServiceEnabled($pdo, $serviceId)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'شراء الرقم غير متاح عبر البوت حالياً.');
        return;
    }
    $claimed = njazTgClaimStateAction($pdo, $tgUser, 'numbers_live_confirm', 'numbers_live_processing');
    if ($claimed === null) {
        $message = (string)($tgUser['state'] ?? '') === 'numbers_live_processing'
            ? '⏳ تتم معالجة شراء الرقم الآن. لن يتم خصم الرصيد أكثر من مرة.'
            : 'انتهت جلسة التأكيد أو تم تنفيذها مسبقاً. افتح طلباتك للتحقق من النتيجة.';
        njazTgSend($pdo, (int)$tgUser['chat_id'], $message, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    if ((int)($claimed['service_id'] ?? 0) !== $serviceId) {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'انتهت صلاحية شاشة الشراء. افتح الخدمة من جديد.', [[['text' => 'الخدمات', 'callback_data' => 'menu:services'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', [
        'action' => 'buy',
        'service_id' => $serviceId,
    ]);
    if (!empty($result['ok'])) {
        $claimed['order_id'] = (int)($result['order_id'] ?? 0);
        $claimed['number'] = (string)($result['number'] ?? '');
        $claimed['access_id'] = (string)($result['access_id'] ?? '');
        $claimed['bought_at'] = time();
        $claimed['price'] = (float)($result['price'] ?? ($claimed['price'] ?? 0));
        if ($messageId > 0) {
            njazTgApi($pdo, 'deleteMessage', ['chat_id' => (int)$tgUser['chat_id'], 'message_id' => $messageId]);
        }
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_waiting', $claimed);
        njazTgNumbersLiveWaiting($pdo, $tgUser, $claimed, null);
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_confirm', $claimed);
    $error = (string)($result['error'] ?? $result['message'] ?? 'تعذر شراء الرقم. حاول مرة أخرى.');
    $errorText = njazTgScreen('⚠️', 'تعذر الشراء', njazTgHtml($error));
    $errorKeyboard = [[['text' => '🔁 العودة للتأكيد', 'callback_data' => 'svc:' . $serviceId], ['text' => '↩️ رجوع للخدمات', 'callback_data' => 'order:back']]];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $errorText, $errorKeyboard); else njazTgSend($pdo, (int)$tgUser['chat_id'], $errorText, $errorKeyboard);
}

function njazTgNumbersLiveWaiting(PDO $pdo, array $tgUser, array $data, ?int $messageId = null, string $notice = ''): void {
    $orderId = (int)($data['order_id'] ?? 0);
    $number = (string)($data['number'] ?? '');
    $elapsed = max(0, time() - (int)($data['bought_at'] ?? time()));
    $remaining = max(0, 1200 - $elapsed);
    $mins = (int)floor($remaining / 60);
    $secs = $remaining % 60;
    $noticeHtml = $notice !== '' ? '\n\n⚠️ ' . njazTgHtml($notice) : '';
    $lockText = $remaining > 0
        ? '\n\n🔒 الإلغاء والاسترداد متاحان بعد انتهاء 20 دقيقة فقط، وبعد التأكد من عدم وصول الكود.'
        : '\n\nانتهت مدة الانتظار. اضغط «فحص الكود» لإتمام الإلغاء التلقائي الآمن.';
    $text = njazTgScreen('📱', 'انتظار كود التفعيل',
        '<b>تم شراء الرقم بنجاح</b>\n\n<b>الرقم المؤقت</b>\n<code>' . njazTgHtml($number) . '</code>'
        . '\n\n<b>الوقت المتبقي</b>\n⏱ ' . $mins . ':' . str_pad((string)$secs, 2, '0', STR_PAD_LEFT)
        . '\n\nأرسل الرقم إلى الخدمة المطلوبة وانتظر كود التفعيل. اضغط «فحص الكود» للتحديث.' . $lockText . $noticeHtml);
    $kb = [
        [['text' => '🔍 فحص الكود الآن', 'callback_data' => 'nl:check:' . $orderId, 'style' => 'primary']],
        [['text' => '📋 الأرقام المعلقة', 'callback_data' => 'nl:list', 'style' => 'secondary']],
        [['text' => '➕ شراء رقم جديد', 'callback_data' => 'menu:services', 'style' => 'success']]
    ];
    if ($remaining <= 0) $kb[] = [['text' => '⏳ إلغاء تلقائي بعد التحقق', 'callback_data' => 'nl:cancel:' . $orderId, 'style' => 'danger']];
    $sentId = 0;
    if ($messageId) {
        $edited = njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb);
        if (!empty($edited['ok'])) $sentId = $messageId;
    } else {
        $sent = njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
        $sentId = (int)($sent['result']['message_id'] ?? 0);
    }
    if ($sentId > 0) $data['flow_message_id'] = $sentId;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_waiting', $data);
}

function njazTgNumbersLivePendingList(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    $orders = njazTgNumbersLivePendingOrders($tgUser);
    if (!$orders) {
        $text = njazTgScreen('📋', 'الأرقام المعلقة', 'لا توجد أرقام مباشرة معلقة حالياً.');
        $kb = [[['text' => '↩️ الرئيسية', 'callback_data' => 'home']]];
        if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
        return;
    }
    $body = '<b>لديك ' . count($orders) . ' رقم/أرقام معلقة</b>\nاختر رقماً لعرضه وفحص كود الوصول:';
    $kb = [];
    foreach ($orders as $order) {
        $orderId = (int)($order['order_id'] ?? 0);
        if ($orderId <= 0) continue;
        $number = trim((string)($order['number'] ?? '')) ?: ('ID' . $orderId);
        $remaining = max(0, 1200 - max(0, time() - (int)($order['bought_at'] ?? time())));
        $mins = (int)floor($remaining / 60);
        $secs = str_pad((string)($remaining % 60), 2, '0', STR_PAD_LEFT);
        $label = '📱 ' . $number . ' — ' . ($remaining > 0 ? '⏱ ' . $mins . ':' . $secs : '⏳ انتهى الوقت');
        $kb[] = [['text' => $label, 'callback_data' => 'nl:resume:' . $orderId, 'style' => $remaining > 0 ? 'primary' : 'danger']];
    }
    $kb[] = [['text' => '➕ شراء رقم جديد', 'callback_data' => 'menu:services', 'style' => 'success']];
    $kb[] = [['text' => '↩️ الرئيسية', 'callback_data' => 'home']];
    $text = njazTgScreen('📋', 'الأرقام المعلقة', $body);
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgNumbersLiveDeleteFlowMessages(PDO $pdo, array $tgUser, array $data, ?int $messageId = null): void {
    $ids = [(int)($data['flow_message_id'] ?? 0), (int)$messageId];
    foreach (array_unique($ids) as $id) {
        if ($id > 0) njazTgApi($pdo, 'deleteMessage', ['chat_id' => (int)$tgUser['chat_id'], 'message_id' => $id]);
    }
}

function njazTgNumbersLiveClearAndNotify(PDO $pdo, array $tgUser, string $message, string $icon = '✅', ?int $orderId = null): void {
    $data = njazTgNumbersLivePendingData($tgUser, $orderId);
    njazTgNumbersLiveDeleteFlowMessages($pdo, $tgUser, $data);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    njazTgClearNumbersLivePending($pdo, (int)$tgUser['telegram_id'], $orderId);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen($icon, 'حالة الرقم', njazTgHtml($message)), [[['text' => '📋 الأرقام المعلقة', 'callback_data' => 'nl:list'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgNumbersLiveResume(PDO $pdo, array $tgUser, int $orderId): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $pending = njazTgNumbersLivePendingData($tgUser, $orderId);
    if ((int)($pending['order_id'] ?? 0) !== $orderId) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'لا يوجد طلب رقم مباشر معلق بهذا الرقم.', [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $result = njazTgInternalGet($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', ['action' => 'resume', 'order_id' => $orderId]);
    if (!empty($result['ok'])) {
        $status = (string)($result['status'] ?? 'waiting');
        if ($status === 'completed' && !empty($result['code'])) {
            njazTgNumbersLiveFinish($pdo, $tgUser, $orderId, (string)$result['code']);
            return;
        }
        if ($status === 'cancelled') {
            njazTgNumbersLiveClearAndNotify($pdo, $tgUser, 'تم إلغاء طلب الرقم من النظام.', '⚠️', $orderId);
            return;
        }
        $remaining = max(0, (int)($result['remaining'] ?? 0));
        $data = array_merge($pending, [
            'order_id' => $orderId,
            'number' => (string)($result['number'] ?? $pending['number'] ?? ''),
            'access_id' => (string)($result['access_id'] ?? $pending['access_id'] ?? ''),
            'bought_at' => time() - max(0, 1200 - $remaining),
        ]);
        njazTgNumbersLiveDeleteFlowMessages($pdo, $tgUser, $pending);
        njazTgNumbersLiveWaiting($pdo, $tgUser, $data, null);
        return;
    }
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgHtml((string)($result['error'] ?? 'تعذر استعادة طلب الرقم حالياً. حاول مرة أخرى.')), [[['text' => '🔄 إعادة المحاولة', 'callback_data' => 'nl:resume:' . $orderId], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgNumbersLiveFinish(PDO $pdo, array $tgUser, int $orderId, string $code): void {
    $pending = njazTgNumbersLivePendingData($tgUser, $orderId);
    njazTgNumbersLiveDeleteFlowMessages($pdo, $tgUser, $pending);
    $ref = '';
    try {
        $stmt = $pdo->prepare('SELECT id, ref_id FROM orders WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$orderId, (int)$tgUser['user_id']]);
        $order = $stmt->fetch();
        if ($order) $ref = njazTgOrderRef($pdo, $order);
    } catch (Throwable $e) {}
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    njazTgClearNumbersLivePending($pdo, (int)$tgUser['telegram_id'], $orderId);
    $body = '<b>كود التفعيل</b>\n<code>' . njazTgHtml($code) . '</code>';
    if ($ref !== '') $body .= '\n\n<b>رقم العملية</b>\n<code>' . njazTgHtml($ref) . '</code>';
    $body .= '\n\nانسخ الكود واستخدمه في الخدمة المطلوبة.';
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'وصل كود التفعيل!', $body), [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgNumbersLiveCheckCode(PDO $pdo, array $tgUser, int $orderId, ?int $messageId = null): void {
    $state = (string)($tgUser['state'] ?? '');
    $data = njazTgStateData($tgUser);
    if ($state !== 'numbers_live_waiting' || (int)($data['order_id'] ?? 0) !== $orderId) {
        $pending = njazTgNumbersLivePendingData($tgUser, $orderId);
        if ((int)($pending['order_id'] ?? 0) === $orderId) {
            njazTgNumbersLiveResume($pdo, $tgUser, $orderId);
            return;
        }
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'انتهت جلسة هذا الرقم أو تم التعامل معه مسبقاً.', [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $elapsed = max(0, time() - (int)($data['bought_at'] ?? time()));
    if ($elapsed >= 1200) {
        $auto = njazTgInternalGet($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', ['action' => 'auto_cancel', 'order_id' => $orderId]);
        if (!empty($auto['ok']) && ($auto['action_taken'] ?? '') === 'completed' && !empty($auto['code'])) {
            njazTgNumbersLiveFinish($pdo, $tgUser, $orderId, (string)$auto['code']);
            return;
        }
        if (!empty($auto['ok']) && ($auto['action_taken'] ?? '') === 'cancelled') {
            njazTgNumbersLiveDeleteFlowMessages($pdo, $tgUser, $data, $messageId);
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
            njazTgClearNumbersLivePending($pdo, (int)$tgUser['telegram_id'], $orderId);
            $refund = njazTgHtml((string)($auto['refund'] ?? ''));
            njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'انتهى الوقت وتم الاسترداد', 'لم يصل كود التفعيل خلال 20 دقيقة. تم إلغاء الطلب واسترداد <b>' . $refund . '</b> إلى رصيدك.'), [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
            return;
        }
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'انتهت مهلة الرقم، وتعذر تحديث حالته الآن. حاول الفحص مرة أخرى.', [[['text' => '🔍 إعادة الفحص', 'callback_data' => 'nl:check:' . $orderId], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $result = njazTgInternalGet($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', ['action' => 'check', 'order_id' => $orderId]);
    $status = (string)($result['status'] ?? 'waiting');
    if ($status === 'completed' && !empty($result['code'])) {
        njazTgNumbersLiveFinish($pdo, $tgUser, $orderId, (string)$result['code']);
        return;
    }
    if ($status === 'cancelled') {
        njazTgNumbersLiveClearAndNotify($pdo, $tgUser, 'تم إلغاء الطلب من النظام.', '⚠️', $orderId);
        return;
    }
    if (!empty($result['transport_error'])) {
        $data['last_checked_at'] = time();
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_waiting', $data);
        njazTgNumbersLiveWaiting($pdo, $tgUser, $data, $messageId, 'تعذر الاتصال بنظام نجاز. لم يتغير وضع الرقم، حاول الفحص بعد قليل.');
        return;
    }
    $data['last_checked_at'] = time();
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_waiting', $data);
    njazTgNumbersLiveWaiting($pdo, $tgUser, $data, $messageId);
}

function njazTgNumbersLiveCancelOrder(PDO $pdo, array $tgUser, int $orderId, ?int $messageId = null): void {
    $data = njazTgStateData($tgUser);
    if ((string)($tgUser['state'] ?? '') !== 'numbers_live_waiting' || (int)($data['order_id'] ?? 0) !== $orderId) {
        $pending = njazTgNumbersLivePendingData($tgUser, $orderId);
        if ((int)($pending['order_id'] ?? 0) === $orderId) {
            njazTgNumbersLiveResume($pdo, $tgUser, $orderId);
            return;
        }
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'انتهت جلسة هذا الرقم أو تم التعامل معه مسبقاً.', [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }

    // فحص الكود أولاً حتى لا يتحول طلب وصل كوده إلى طلب ملغى.
    $check = njazTgInternalGet($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', ['action' => 'check', 'order_id' => $orderId]);
    if (($check['status'] ?? '') === 'completed' && !empty($check['code'])) {
        njazTgNumbersLiveFinish($pdo, $tgUser, $orderId, (string)$check['code']);
        return;
    }
    if (($check['status'] ?? '') === 'cancelled') {
        njazTgNumbersLiveClearAndNotify($pdo, $tgUser, 'تم التعامل مع الطلب مسبقاً من النظام.', '⚠️', $orderId);
        return;
    }

    $elapsed = max(0, time() - (int)($data['bought_at'] ?? time()));
    if ($elapsed < 1200) {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'numbers_live_waiting', $data);
        njazTgNumbersLiveWaiting($pdo, $tgUser, $data, $messageId, 'لا يمكن الإلغاء قبل انتهاء 20 دقيقة. سيُفحص الكود قبل الاسترداد.');
        return;
    }

    // بعد انتهاء المهلة نستخدم auto_cancel فقط؛ فهو يتحقق من وصول الكود
    // مرة أخيرة داخل API الموقع قبل الإلغاء والاسترداد.
    $result = njazTgInternalGet($pdo, (int)$tgUser['user_id'], 'api/numbersapp_live.php', ['action' => 'auto_cancel', 'order_id' => $orderId]);
    if (!empty($result['ok']) && ($result['action_taken'] ?? '') === 'completed' && !empty($result['code'])) {
        njazTgNumbersLiveFinish($pdo, $tgUser, $orderId, (string)$result['code']);
        return;
    }
    if (!empty($result['ok']) && ($result['action_taken'] ?? '') === 'cancelled') {
        njazTgNumbersLiveDeleteFlowMessages($pdo, $tgUser, $data, $messageId);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        njazTgClearNumbersLivePending($pdo, (int)$tgUser['telegram_id'], $orderId);
        $refund = njazTgHtml((string)($result['refund'] ?? ''));
        $text = njazTgScreen('✅', 'انتهى الوقت وتم الاسترداد', 'لم يصل كود التفعيل خلال 20 دقيقة. تم إلغاء الطلب واسترداد <b>' . $refund . '</b> إلى رصيدك.');
        njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $error = (string)($result['error'] ?? $result['message'] ?? 'تعذر تنفيذ الإلغاء التلقائي حالياً.');
    njazTgNumbersLiveWaiting($pdo, $tgUser, $data, $messageId, $error);
}
