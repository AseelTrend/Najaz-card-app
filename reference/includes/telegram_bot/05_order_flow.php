<?php

function njazTgDeleteFlowMessage(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    njazTgClearThumbnailMessages($pdo, $tgUser);
    if ((int)$messageId > 0) {
        njazTgApi($pdo, 'deleteMessage', [
            'chat_id' => (int)$tgUser['chat_id'],
            'message_id' => (int)$messageId,
        ]);
    }
}

function njazTgCancelOrderFlow(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    njazTgDeleteFlowMessage($pdo, $tgUser, $messageId);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    njazTgHome($pdo, $tgUser, false, null);
}

function njazTgBackFromOrderFlow(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    $data = njazTgStateData($tgUser);
    $categoryId = (int)($data['category_id'] ?? 0);
    njazTgDeleteFlowMessage($pdo, $tgUser, $messageId);
    if ($categoryId > 0) {
        njazTgCategory($pdo, $tgUser, $categoryId, null);
    } else {
        njazTgCategories($pdo, $tgUser, null);
    }
}

function njazTgService(PDO $pdo, array $tgUser, int $serviceId, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_orders') || !njazTgServiceEnabled($pdo, $serviceId)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه العملية أو الخدمة غير متاحة عبر البوت حالياً.'); return; }
    $s = $pdo->prepare("SELECT s.*,c.name AS cat_name,COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name FROM services s JOIN categories c ON c.id=s.category_id LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id WHERE s.id=? AND s.status=1 LIMIT 1");
    $s->execute([$serviceId]); $service = $s->fetch();
    if (!$service) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الخدمة غير متاحة حالياً.'); return; }
    if ((string)($service['service_type'] ?? 'default') === 'numbers_live') {
        // يسمح بشراء رقم جديد مع إبقاء كل الأرقام السابقة محفوظة في قائمة المعلّقة.
        njazTgNumbersLiveConfirm($pdo, $tgUser, $serviceId, $service, $messageId);
        return;
    }
    $f = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order");
    $f->execute([$serviceId]); $fields = $f->fetchAll();
    $min = (int)($service['min_qty'] ?: 1); $max = (int)($service['max_qty'] ?: 1);
    $serviceSourceName = (string)($service['display_name'] ?? $service['name']);
    $serviceContext = 'entity:service:' . $serviceId . ':name';
    $serviceLabel = function_exists('njazTgLanguageCode')
        ? njazTgTranslateEntityText($pdo, $serviceSourceName, njazTgLanguageCode($pdo, $tgUser), $serviceContext)
        : $serviceSourceName;
    $data = ['service_id' => $serviceId, 'category_id' => (int)($service['category_id'] ?? 0), 'quantity' => $min, 'min_qty' => $min, 'max_qty' => $max, 'fields' => [], 'field_index' => 0, 'service_name' => $serviceSourceName, 'service_description' => (string)($service['description'] ?? ''), 'service_image' => (string)($service['image'] ?? ''), 'base_price' => (float)$service['price']];
    if ($max > $min) {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_quantity', $data);
        $descriptionSource = trim((string)($service['description'] ?? ''));
        $description = $descriptionSource !== '' && function_exists('njazTgTranslateEntityText')
            ? njazTgTranslateEntityText($pdo, $descriptionSource, njazTgLanguageCode($pdo, $tgUser), 'entity:service:' . $serviceId . ':description')
            : $descriptionSource;
        $quantityInstruction = ($description !== '' ? '<i>' . njazTgHtml($description) . '</i>\n\n' : '') . 'أرسل الكمية المطلوبة من <b>' . $min . '</b> إلى <b>' . $max . '</b>.';
        $prompt = njazTgScreen('🛒', njazTgClipLabel($serviceLabel), $quantityInstruction);
        $keyboard = [[
            ['text' => '↩️ رجوع للخدمات', 'callback_data' => 'order:back'],
            ['text' => '✖️ إلغاء', 'callback_data' => 'order:cancel'],
        ]];
        njazTgRenderSection($pdo, $tgUser, $prompt, $keyboard, $messageId, 'auto', $service['image'] ?? null);
    } elseif ($fields) {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'order_field', $data);
        njazTgAskField($pdo, $tgUser, $fields, 0, $data, $messageId);
    } else {
        njazTgReviewOrder($pdo, $tgUser, $data, $messageId);
    }
}
