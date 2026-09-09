<?php

function njazTgTelecomMethod(PDO $pdo, int $methodId): ?array {
    try {
        $s = $pdo->prepare("SELECT method_id,name_ar,name_en,icon,color,logo,transaction_type FROM floosak_agent_methods WHERE method_id=? AND status=1 LIMIT 1");
        $s->execute([$methodId]);
        $row = $s->fetch();
        if ($row) return $row;
    } catch (Throwable $e) {}
    try {
        $s = $pdo->prepare("SELECT id AS method_id,name AS name_ar,name AS name_en,icon,color,NULL AS logo,'TOPUP' AS transaction_type FROM telecom_networks WHERE id=? AND status=1 LIMIT 1");
        $s->execute([$methodId]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function njazTgTelecomNormalizePhone(string $phone, int $methodId): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (in_array($methodId, [1,2,3,12,16,17], true)) {
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        if (str_starts_with($digits, '967')) $digits = substr($digits, 3);
        if (str_starts_with($digits, '0') && strlen($digits) === 10) $digits = substr($digits, 1);
    }
    return $digits;
}

function njazTgTelecomSectionLabel(string $section): string {
    return [
        'amount' => '💰 مبلغ', 'fees' => '🏷️ فئات', 'bundles' => '📦 باقات',
        'yemen4g_change' => '🔄 تغيير باقة', 'yemen4g_credit' => '📶 رصيد اتصال',
        'yemen4g_internet' => '🌐 إنترنت فقط', 'yemen4g_voice' => '📞 صوت فقط',
    ][$section] ?? '📦 باقات';
}

function njazTgTelecomFetchBunches(PDO $pdo, array $tgUser, int $methodId): array {
    $r = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'telecom.php', [
        'ajax_action' => 'get_bunches', 'method_id' => $methodId,
    ]);
    return !empty($r['ok']) && is_array($r['bunches'] ?? null) ? $r['bunches'] : [];
}

function njazTgTelecomFilterBunches(array $bunches, string $ptype, string $section): array {
    $filtered = array_values(array_filter($bunches, static function (array $b) use ($ptype, $section): bool {
        $rowPtype = strtolower(trim((string)($b['payment_type'] ?? 'both'))) ?: 'both';
        $rowSection = strtolower(trim((string)($b['section'] ?? 'bundles'))) ?: 'bundles';
        $ptypeOk = $ptype === 'both' || $rowPtype === 'both' || $rowPtype === $ptype;
        if (!$ptypeOk) return false;
        if ($section === 'bundles') return in_array($rowSection, ['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice'], true);
        if ($section === 'amount') return in_array($rowSection, ['amount','yemen4g_credit'], true);
        return $rowSection === $section;
    }));
    return $filtered;
}

function njazTgTelecomAvailablePaymentTypes(array $bunches): array {
    $types = [];
    foreach ($bunches as $b) {
        $type = strtolower(trim((string)($b['payment_type'] ?? 'both'))) ?: 'both';
        if ($type === 'both') { $types = ['prepaid', 'postpaid']; break; }
        if (in_array($type, ['prepaid', 'postpaid'], true) && !in_array($type, $types, true)) $types[] = $type;
    }
    return $types;
}

function njazTgTelecomAvailableSections(array $bunches, string $ptype): array {
    $sections = [];
    foreach ($bunches as $b) {
        $rowPtype = strtolower(trim((string)($b['payment_type'] ?? 'both'))) ?: 'both';
        if ($ptype !== 'both' && $rowPtype !== 'both' && $rowPtype !== $ptype) continue;
        $section = strtolower(trim((string)($b['section'] ?? 'bundles'))) ?: 'bundles';
        if (!in_array($section, ['amount','fees','bundles','yemen4g_change','yemen4g_credit','yemen4g_internet','yemen4g_voice'], true)) $section = 'bundles';
        if (!in_array($section, $sections, true)) $sections[] = $section;
    }
    return $sections;
}

function njazTgTelecomCheckSummary(array $data): string {
    $check = $data['check'] ?? [];
    $text = '<b>🔍 نتيجة فحص الرقم</b>\n';
    $text .= 'الرصيد المتاح: <b>' . njazTgHtml((string)($check['balance'] ?? '—')) . '</b> ر.ي\n';
    $loan = (float)($check['loan'] ?? 0);
    $text .= 'السلفة: <b>' . ($loan > 0 ? njazTgHtml(number_format($loan, 2) . ' ر.ي') : 'لا توجد') . '</b>\n';
    $offers = is_array($check['offers'] ?? null) ? $check['offers'] : [];
    $text .= 'الباقات المشتركة: <b>' . count($offers) . '</b>';
    foreach (array_slice($offers, 0, 5) as $offer) {
        $name = $offer['offer_name'] ?? $offer['name'] ?? 'باقة';
        $id = $offer['offer_id'] ?? '';
        $text .= '\n• ' . njazTgHtml((string)$name) . ($id !== '' ? ' — <code>' . njazTgHtml((string)$id) . '</code>' : '');
    }
    return $text;
}

function njazTgTelecomNetwork(PDO $pdo, array $tgUser, int $methodId): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_telecom')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'قسم الاتصالات غير متاح عبر البوت حالياً.'); return; }
    $method = njazTgTelecomMethod($pdo, $methodId);
    if (!$method || strtoupper((string)($method['transaction_type'] ?? 'TOPUP')) !== 'TOPUP') { njazTgSend($pdo, (int)$tgUser['chat_id'], 'الشبكة غير متاحة حالياً.'); return; }
    $name = (string)($method['name_ar'] ?: $method['name_en'] ?: 'الشبكة');
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_phone', ['method_id' => $methodId, 'method_name' => $name]);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>كابينة السداد — ' . njazTgHtml($name) . '</b>\nأرسل رقم الهاتف المحلي الآن، وسيتم فحص الرقم وإظهار الرصيد والسلفة والباقات المتاحة.', [[['text' => 'إلغاء', 'callback_data' => 'home']]]);
}

function njazTgTelecomPhone(PDO $pdo, array $tgUser, array $data, string $phone): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_telecom')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'قسم الاتصالات غير متاح عبر البوت حالياً.'); return; }
    $methodId = (int)($data['method_id'] ?? 0);
    $phone = njazTgTelecomNormalizePhone($phone, $methodId);
    if (strlen($phone) < 7) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'رقم الهاتف غير صحيح. أرسل الرقم المحلي بدون مسافات أو رموز.', [[['text' => 'إلغاء', 'callback_data' => 'home']]]); return; }
    $check = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'telecom.php', [
        'ajax_action' => 'check_service', 'target_number' => $phone, 'method_id' => $methodId, 'bunch_id' => '340',
    ]);
    if (empty($check['ok']) || !is_array($check['data'] ?? null)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر فحص الرقم: ' . njazTgHtml($check['msg'] ?? 'تحقق من الرقم وحاول مرة أخرى.'), [[['text' => 'إعادة المحاولة', 'callback_data' => 'tnet:' . $methodId], ['text' => 'إلغاء', 'callback_data' => 'home']]]);
        return;
    }
    $methodName = (string)($data['method_name'] ?? 'الشبكة');
    $bunches = njazTgTelecomFetchBunches($pdo, $tgUser, $methodId);
    $types = njazTgTelecomAvailablePaymentTypes($bunches);
    $ptype = in_array('prepaid', $types, true) ? 'prepaid' : (count($types) === 1 ? $types[0] : 'both');
    $data = array_merge($data, ['phone' => $phone, 'check' => $check['data'], 'payment_type' => $ptype, 'section' => 'bundles', 'with_solfa' => 0]);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_choice', $data);
    njazTgTelecomChoice($pdo, $tgUser, $data, null);
}

function njazTgTelecomChoice(PDO $pdo, array $tgUser, array $data, ?int $messageId = null): void {
    $methodId = (int)($data['method_id'] ?? 0); $ptype = (string)($data['payment_type'] ?? 'both'); $section = (string)($data['section'] ?? 'bundles');
    $bunches = njazTgTelecomFetchBunches($pdo, $tgUser, $methodId);
    $available = njazTgTelecomAvailableSections($bunches, $ptype);
    if (!$available) $available = njazTgTelecomAvailableSections($bunches, 'both');
    if (!in_array($section, $available, true)) $section = $available[0] ?? 'bundles';
    $data['section'] = $section; njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_choice', $data);
    $text = '<b>كابينة السداد — ' . njazTgHtml((string)($data['method_name'] ?? '')) . '</b>\nرقم الهاتف: <code>' . njazTgHtml((string)$data['phone']) . '</code>\n\n' . njazTgTelecomCheckSummary($data) . '\n\n<b>اختر نوع العملية:</b>';
    $kb = [];
    $types = njazTgTelecomAvailablePaymentTypes($bunches);
    if (count($types) > 1) $kb[] = [['text' => ($ptype === 'prepaid' ? '✅ ' : '') . 'دفع مسبق', 'callback_data' => 'tptype:prepaid'], ['text' => ($ptype === 'postpaid' ? '✅ ' : '') . 'فوترة', 'callback_data' => 'tptype:postpaid']];
    $sectionButtons = [];
    foreach (['amount','fees','bundles','yemen4g_change','yemen4g_credit','yemen4g_internet','yemen4g_voice'] as $candidate) {
        if (!in_array($candidate, $available, true)) continue;
        $sectionButtons[] = ['text' => ($section === $candidate ? '✅ ' : '') . njazTgTelecomSectionLabel($candidate), 'callback_data' => 'tsec:' . $candidate];
        if (count($sectionButtons) === 2) { $kb[] = $sectionButtons; $sectionButtons = []; }
    }
    if ($sectionButtons) $kb[] = $sectionButtons;
    $items = njazTgTelecomFilterBunches($bunches, $ptype, $section);
    if (!$items && $section === 'bundles') $items = njazTgTelecomFilterBunches($bunches, 'both', $section);
    if ($section === 'amount') {
        $kb[] = [['text' => '✍️ إدخال مبلغ يدوي', 'callback_data' => 'tamt:open']];
        foreach (array_slice($items, 0, 8) as $row) {
            $label = (string)($row['bunch_name'] ?? 'مبلغ'); $price = (float)($row['price'] ?? 0);
            if ($price > 0) $kb[] = [['text' => $label . ' — ' . number_format($price, 0) . ' ر.ي', 'callback_data' => 'tbn:' . (int)$row['id']]];
        }
    } elseif ($section === 'fees') {
        foreach (array_slice($items, 0, 40) as $row) {
            $label = (string)($row['bunch_name'] ?? 'فئة'); $price = (float)($row['price'] ?? 0);
            $kb[] = [['text' => '🏷️ ' . $label . ($price > 0 ? ' — ' . number_format($price, 0) . ' ر.ي' : ''), 'callback_data' => 'tbn:' . (int)$row['id']]];
        }
    } else {
        $groups = [];
        foreach ($items as $row) { $group = trim((string)($row['bundle_group'] ?? '')) ?: 'أخرى'; if (!isset($groups[$group])) $groups[$group] = []; $groups[$group][] = $row; }
        $gi = 0; foreach ($groups as $group => $rows) { $kb[] = [['text' => '📦 ' . $group . ' (' . count($rows) . ')', 'callback_data' => 'tgrp:' . $gi]]; $gi++; }
    }
    if (!$items) $text .= '\n\nلا توجد عناصر متاحة لهذا النوع حالياً.';
    $kb[] = [['text' => '🔍 إعادة فحص الرقم', 'callback_data' => 'tcheck'], ['text' => 'إلغاء', 'callback_data' => 'home']];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgTelecomGroup(PDO $pdo, array $tgUser, array $data, int $groupIndex, ?int $messageId = null): void {
    $bunches = njazTgTelecomFetchBunches($pdo, $tgUser, (int)$data['method_id']);
    $items = njazTgTelecomFilterBunches($bunches, (string)($data['payment_type'] ?? 'both'), (string)($data['section'] ?? 'bundles'));
    $groups = [];
    foreach ($items as $row) { $group = trim((string)($row['bundle_group'] ?? '')) ?: 'أخرى'; if (!isset($groups[$group])) $groups[$group] = []; $groups[$group][] = $row; }
    $groups = array_values($groups); $rows = $groups[$groupIndex] ?? [];
    if (!$rows) { njazTgTelecomChoice($pdo, $tgUser, $data, $messageId); return; }
    $groupName = (string)($rows[0]['bundle_group'] ?? 'أخرى');
    $text = '<b>' . njazTgTelecomSectionLabel((string)($data['section'] ?? 'bundles')) . '</b>\n' . njazTgHtml($groupName) . '\nاختر الباقة:';
    $kb = [];
    foreach ($rows as $row) {
        $name = (string)($row['bunch_name'] ?? 'باقة'); $price = (float)($row['price'] ?? 0);
        $kb[] = [['text' => $name . ($price > 0 ? ' — ' . number_format($price, 0) . ' ر.ي' : ''), 'callback_data' => 'tbn:' . (int)$row['id']]];
    }
    $kb[] = [['text' => '↩️ الأقسام والباقات', 'callback_data' => 'tback'], ['text' => 'إلغاء', 'callback_data' => 'home']];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_group', $data);
}

function njazTgTelecomPickBunch(PDO $pdo, array $tgUser, array $data, int $rowId): void {
    $bunches = njazTgTelecomFetchBunches($pdo, $tgUser, (int)$data['method_id']); $row = null;
    foreach ($bunches as $candidate) if ((int)($candidate['id'] ?? 0) === $rowId) { $row = $candidate; break; }
    if (!$row) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه الفئة أو الباقة لم تعد متاحة.'); return; }
    $section = strtolower((string)($row['section'] ?? 'bundles')); $price = (float)($row['price'] ?? 0);
    $bunchId = (string)($row['unified_code'] ?: $row['code'] ?: $row['bunch_id'] ?: $rowId);
    $freeAmount = (int)($row['is_free_amount'] ?? 0) === 1 || in_array($section, ['amount','yemen4g_credit'], true) && $price <= 0;
    $data['bunch_id'] = $bunchId; $data['bunch_name'] = (string)($row['bunch_name'] ?? 'الخدمة'); $data['section'] = $section; $data['amount'] = $price; $data['with_solfa'] = 0;
    if ($freeAmount) {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_amount', $data);
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'اخترت: <b>' . njazTgHtml($data['bunch_name']) . '</b>\nأرسل المبلغ بالريال اليمني:', [[['text' => 'إلغاء', 'callback_data' => 'home']]]);
        return;
    }
    njazTgTelecomConfirm($pdo, $tgUser, $data);
}

function njazTgTelecomConfirm(PDO $pdo, array $tgUser, array $data): void {
    $amount = (float)($data['amount'] ?? 0);
    if ($amount <= 0 || empty($data['bunch_id'])) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'اختر الفئة أو الباقة أولاً.'); return; }
    $rate = 0;
    try {
        $rateStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='telecom_sadad_rate' LIMIT 1");
        $rateStmt->execute();
        $rate = (float)$rateStmt->fetchColumn();
    } catch (Throwable $e) {}
    if ($rate <= 0) $rate = (float)(getSetting('telecom_exchange_rate') ?: 1700);
    $costUsd = $rate > 0 ? round($amount / $rate, 6) : 0;
    $user = getUser((int)$tgUser['user_id']); $balance = (float)($user['balance'] ?? 0);
    $text = '<b>تأكيد عملية السداد</b>\nالشبكة: ' . njazTgHtml((string)$data['method_name']) . '\nرقم الهاتف: <code>' . njazTgHtml((string)$data['phone']) . '</code>\nالاختيار: <b>' . njazTgHtml((string)$data['bunch_name']) . '</b>\nالمبلغ: <b>' . number_format($amount, 2) . ' ر.ي</b>\nالتكلفة من رصيدك: <b>' . njazTgMoney($costUsd) . '</b>\nرصيدك الحالي: <b>' . njazTgMoney($balance) . '</b>';
    $loan = (float)($data['check']['loan'] ?? 0);
    if ($loan > 0) $text .= '\nالسلفة المكتشفة: <b>' . number_format($loan, 2) . ' ر.ي</b>\nيمكنك اختيار تنفيذ العملية مع السلفة أو بدونها.';
    $kb = [];
    if ($loan > 0) $kb[] = [['text' => empty($data['with_solfa']) ? '✅ بدون سلفة' : 'بدون سلفة', 'callback_data' => 'tsolfa:0'], ['text' => !empty($data['with_solfa']) ? '✅ مع السلفة' : 'مع السلفة', 'callback_data' => 'tsolfa:1']];
    $kb[] = [['text' => '✅ تأكيد وتنفيذ', 'callback_data' => 'tconfirm'], ['text' => 'إلغاء', 'callback_data' => 'home']];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_confirm', $data);
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgTelecomExecute(PDO $pdo, array $tgUser, array $data): void {
    // يمنع تكرار تنفيذ كابينة السداد عند الضغط المتتابع أو إعادة callback.
    $claimed = njazTgClaimStateAction($pdo, $tgUser, 'telecom_confirm', 'telecom_processing');
    if ($claimed === null) {
        $current = (string)($tgUser['state'] ?? 'menu');
        $message = $current === 'telecom_processing'
            ? '⏳ تتم معالجة عملية السداد الآن. لن يتم خصم الرصيد أكثر من مرة.'
            : 'انتهت جلسة التأكيد أو تم تنفيذها مسبقاً. افتح طلباتي للتحقق من النتيجة.';
        njazTgSend($pdo, (int)$tgUser['chat_id'], $message, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $data = $claimed;
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'telecom.php', [
        'ajax_action' => 'do_topup', 'target_number' => (string)$data['phone'], 'method_id' => (int)$data['method_id'],
        'bunch_id' => (string)$data['bunch_id'], 'amount' => (float)$data['amount'], 'with_solfa' => (int)($data['with_solfa'] ?? 0),
    ]);
    if (!empty($result['ok'])) {
        $newBalance = (float)($result['new_balance'] ?? (getUser((int)$tgUser['user_id'])['balance'] ?? 0));
        $requestRef = (string)($result['request_id'] ?? '—');
        $text = njazTgScreen('✅', 'تم تنفيذ عملية السداد',
            '<b>الخدمة</b>\n' . njazTgHtml((string)$data['bunch_name'])
            . '\n\n<b>رقم العملية</b>\n<code>' . njazTgHtml($requestRef) . '</code>'
            . '\n\n<b>الهاتف</b>\n<code>' . njazTgHtml((string)$data['phone']) . '</code>'
            . '\n\n<b>المبلغ</b>\n' . njazTgHtml(number_format((float)$data['amount'], 2) . ' ر.ي')
            . '\n<b>الرصيد المتبقي</b>\n' . njazTgMoney($newBalance));
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        njazTgSend($pdo, (int)$tgUser['chat_id'], $text, [[['text' => '📦 طلباتي', 'callback_data' => 'menu:orders'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
    } else {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_confirm', $data);
        $isTransport = !empty($result['transport_error']);
        $msg = $isTransport
            ? 'تعذر الاتصال بنظام نجاز. لم يتم تأكيد العملية من البوت.'
            : njazTgHtml($result['msg'] ?? $result['message'] ?? 'خطأ غير معروف');
        $failureText = njazTgScreen('⚠️', $isTransport ? 'تعذر الاتصال بالنظام' : 'تعذر تنفيذ عملية السداد',
            '<b>السبب</b>\n' . $msg . '\n\nتحقق من طلباتي قبل إعادة المحاولة، أو حاول تنفيذ العملية مرة أخرى.');
        njazTgSend($pdo, (int)$tgUser['chat_id'], $failureText, [[['text' => '🔁 العودة للتأكيد', 'callback_data' => 'tconfirm'], ['text' => '📦 طلباتي', 'callback_data' => 'menu:orders']], [['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
    }
}

function njazTgTelecomPay(PDO $pdo, array $tgUser, array $data, string $type, $value): void {
    if ($type === 'balance') { $data['amount'] = (float)$value; $data['bunch_id'] = $data['bunch_id'] ?? '340'; $data['bunch_name'] = $data['bunch_name'] ?? 'رصيد'; njazTgTelecomConfirm($pdo, $tgUser, $data); return; }
    njazTgTelecomPickBunch($pdo, $tgUser, $data, (int)$value);
}
