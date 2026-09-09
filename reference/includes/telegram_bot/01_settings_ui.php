<?php

function njazTgSettings(PDO $pdo): array {
    njazTgEnsureTables($pdo);
    return $pdo->query("SELECT * FROM telegram_bot_settings WHERE id=1 LIMIT 1")->fetch() ?: [];
}

function njazTgSubscriptionSettings(PDO $pdo): array {
    njazTgEnsureTables($pdo);
    $row = $pdo->query("SELECT * FROM telegram_bot_subscription_settings WHERE id=1 LIMIT 1")->fetch();
    return is_array($row) ? $row : ['id' => 1, 'enabled' => 0, 'message' => '🔒 <b>الاشتراك الإجباري</b>\\n\\nللاستمرار في استخدام البوت، يرجى الاشتراك في القنوات أو المجموعات التالية ثم الضغط على زر التحقق.', 'button_style' => 'primary', 'verify_style' => 'success'];
}

function njazTgSubscriptionTargets(PDO $pdo, bool $enabledOnly = true): array {
    njazTgEnsureTables($pdo);
    $sql = "SELECT * FROM telegram_bot_subscription_targets" . ($enabledOnly ? " WHERE enabled=1" : "") . " ORDER BY sort_order ASC,id ASC";
    try { return $pdo->query($sql)->fetchAll() ?: []; } catch (Throwable $e) { return []; }
}

function njazTgSubscriptionTargetUrl(array $target): string {
    $url = trim((string)($target['invite_url'] ?? ''));
    return preg_match('~^https://(?:t\.me|telegram\.me)/[^\s]+$~i', $url) ? $url : '';
}

function njazTgSubscriptionTargetChatId(array $target): string {
    $chatId = trim((string)($target['chat_id'] ?? ''));
    if ($chatId === '') return '';
    if (preg_match('/^-?\\d{5,20}$/', $chatId)) return $chatId;
    return preg_match('/^@[A-Za-z0-9_]{4,}$/', $chatId) ? $chatId : '';
}

function njazTgSubscriptionMemberStatus(PDO $pdo, string $chatId, int $telegramId): array {
    if ($chatId === '' || $telegramId <= 0) return ['member' => false, 'error' => 'بيانات المجموعة غير مكتملة.'];
    $result = njazTgApi($pdo, 'getChatMember', ['chat_id' => $chatId, 'user_id' => $telegramId]);
    if (empty($result['ok'])) return ['member' => false, 'error' => (string)($result['description'] ?? 'تعذر التحقق من عضوية المستخدم.')];
    $member = is_array($result['result'] ?? null) ? $result['result'] : [];
    $status = (string)($member['status'] ?? '');
    $isMember = in_array($status, ['creator', 'administrator', 'member'], true) || ($status === 'restricted' && !empty($member['is_member']));
    return ['member' => $isMember, 'status' => $status, 'error' => ''];
}

function njazTgSubscriptionCheck(PDO $pdo, int $telegramId): array {
    $settings = njazTgSubscriptionSettings($pdo);
    if (empty($settings['enabled'])) return ['required' => false, 'allowed' => true, 'settings' => $settings, 'targets' => [], 'missing' => [], 'errors' => []];
    $missing = []; $errors = [];
    foreach (njazTgSubscriptionTargets($pdo, true) as $target) {
        $chatId = njazTgSubscriptionTargetChatId($target);
        $url = njazTgSubscriptionTargetUrl($target);
        if ($chatId === '' || $url === '') { $errors[] = 'يوجد هدف اشتراك بإعدادات غير مكتملة.'; $missing[] = $target; continue; }
        $status = njazTgSubscriptionMemberStatus($pdo, $chatId, $telegramId);
        if (!$status['member']) {
            $missing[] = $target;
            if ($status['error'] !== '') $errors[] = $status['error'];
        }
    }
    return ['required' => true, 'allowed' => count($missing) === 0 && count($errors) === 0, 'settings' => $settings, 'targets' => njazTgSubscriptionTargets($pdo, true), 'missing' => $missing, 'errors' => array_values(array_unique($errors))];
}

function njazTgSubscriptionKeyboard(PDO $pdo, array $check): array {
    $settings = $check['settings'] ?? [];
    $buttonStyle = njazTgUiStyle($settings['button_style'] ?? 'primary');
    $verifyStyle = njazTgUiStyle($settings['verify_style'] ?? 'success');
    $rows = [];
    foreach (($check['missing'] ?? []) as $target) {
        $url = njazTgSubscriptionTargetUrl($target);
        if ($url === '') continue;
        $type = (string)($target['target_type'] ?? 'channel') === 'group' ? 'المجموعة' : 'القناة';
        $label = trim((string)($target['button_text'] ?? ''));
        if ($label === '') $label = '📢 انضم إلى ' . $type . (trim((string)($target['title'] ?? '')) !== '' ? ': ' . trim((string)$target['title']) : '');
        $rows[] = [['text' => $label, 'url' => $url, 'style' => $buttonStyle]];
    }
    $rows[] = [['text' => '✅ تحقق من الاشتراك', 'callback_data' => 'subscription:verify', 'style' => $verifyStyle]];
    return $rows;
}

function njazTgSubscriptionGateText(array $check): string {
    $settings = $check['settings'] ?? [];
    $text = trim((string)($settings['message'] ?? ''));
    if ($text === '') $text = '🔒 <b>الاشتراك الإجباري</b>\\n\\nيرجى الاشتراك في القنوات أو المجموعات التالية ثم الضغط على زر التحقق.';
    if (!empty($check['errors'])) $text .= "\\n\\n⚠️ تعذر التحقق من بعض الأهداف حالياً. تأكد أن البوت مشرف في القنوات أو المجموعات ثم أعد المحاولة.";
    return $text;
}

function njazTgShowSubscriptionGate(PDO $pdo, array $tgUser, bool $edit = false, ?int $messageId = null): void {
    $check = njazTgSubscriptionCheck($pdo, (int)($tgUser['telegram_id'] ?? 0));
    $text = njazTgSubscriptionGateText($check);
    $keyboard = njazTgSubscriptionKeyboard($pdo, $check);
    if ($edit && $messageId) {
        $result = njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $keyboard);
        if (!empty($result['ok'])) return;
    }
    njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

function njazTgSubscriptionAllowed(PDO $pdo, array $tgUser, bool $showGate = true, bool $edit = false, ?int $messageId = null): bool {
    $check = njazTgSubscriptionCheck($pdo, (int)($tgUser['telegram_id'] ?? 0));
    if (!empty($check['allowed'])) return true;
    if ($showGate) njazTgShowSubscriptionGate($pdo, $tgUser, $edit, $messageId);
    return false;
}

function njazTgMenuDefinitions(): array {
    return [
        ['key'=>'home','group'=>'الرئيسية','label'=>'الرئيسية','icon'=>'🏠'],
        ['key'=>'menu:search','group'=>'الرئيسية','label'=>'البحث عن خدمة أو قسم','icon'=>'🔎'],
        ['key'=>'menu:services','group'=>'الرئيسية','label'=>'الخدمات','icon'=>'🛒'],
        ['key'=>'menu:quizzes','group'=>'الرئيسية','label'=>'المسابقات','icon'=>'🏆'],
        ['key'=>'menu:favorites','group'=>'الرئيسية','label'=>'المفضلة','icon'=>'⭐'],
        ['key'=>'menu:recent','group'=>'الرئيسية','label'=>'الأخيرة','icon'=>'🕘'],
        ['key'=>'menu:balance','group'=>'الرئيسية','label'=>'رصيدي','icon'=>'💎'],
        ['key'=>'menu:orders','group'=>'الرئيسية','label'=>'طلباتي','icon'=>'📦'],
        ['key'=>'menu:topup','group'=>'الرئيسية','label'=>'شحن الرصيد','icon'=>'💳'],
        ['key'=>'menu:payments','group'=>'الرئيسية','label'=>'مدفوعاتي','icon'=>'🧾'],
        ['key'=>'menu:profile','group'=>'الرئيسية','label'=>'حسابي','icon'=>'👤'],
        ['key'=>'menu:notifications','group'=>'الرئيسية','label'=>'إشعاراتي','icon'=>'🔔'],
        ['key'=>'menu:help','group'=>'الرئيسية','label'=>'المساعدة','icon'=>'🆘'],
        ['key'=>'login:start','group'=>'الدخول والتسجيل','label'=>'تسجيل الدخول','icon'=>'🔐'],
        ['key'=>'login:cancel','group'=>'الدخول والتسجيل','label'=>'إلغاء تسجيل الدخول','icon'=>'❌'],
        ['key'=>'register:start','group'=>'الدخول والتسجيل','label'=>'إنشاء حساب','icon'=>'🆕'],
        ['key'=>'register:cancel','group'=>'الدخول والتسجيل','label'=>'إلغاء إنشاء الحساب','icon'=>'❌'],
        ['key'=>'nav:back','group'=>'التنقل','label'=>'رجوع','icon'=>'↩️'],
        ['key'=>'nav:home','group'=>'التنقل','label'=>'الرئيسية','icon'=>'🏠'],
        ['key'=>'nav:previous','group'=>'التنقل','label'=>'السابق','icon'=>'⬅️'],
        ['key'=>'nav:next','group'=>'التنقل','label'=>'التالي','icon'=>'➡️'],
        ['key'=>'nav:help','group'=>'التنقل','label'=>'مساعدة','icon'=>'🆘'],
        ['key'=>'orders:all','group'=>'الطلبات','label'=>'كل الطلبات','icon'=>'📦'],
        ['key'=>'orders:pending','group'=>'الطلبات','label'=>'الطلبات المعلقة','icon'=>'⏳'],
        ['key'=>'orders:completed','group'=>'الطلبات','label'=>'الطلبات الجاهزة','icon'=>'✅'],
        ['key'=>'orders:cancelled','group'=>'الطلبات','label'=>'الطلبات الملغاة','icon'=>'❌'],
        ['key'=>'orders:lookup','group'=>'الطلبات','label'=>'استعلام عن عملية','icon'=>'🔎'],
        ['key'=>'orders:cancel','group'=>'الطلبات','label'=>'إلغاء الطلب','icon'=>'❌'],
        ['key'=>'order:view','group'=>'الطلبات','label'=>'عرض الطلب','icon'=>'🔎'],
        ['key'=>'order:reorder','group'=>'الطلبات','label'=>'إعادة الطلب','icon'=>'🔁'],
        ['key'=>'payments:all','group'=>'المدفوعات','label'=>'كل المدفوعات','icon'=>'🧾'],
        ['key'=>'payments:approved','group'=>'المدفوعات','label'=>'المدفوعات المعتمدة','icon'=>'✅'],
        ['key'=>'payments:pending','group'=>'المدفوعات','label'=>'المدفوعات المعلقة','icon'=>'⏳'],
        ['key'=>'payments:rejected','group'=>'المدفوعات','label'=>'المدفوعات المرفوضة','icon'=>'❌'],
        ['key'=>'payments:card','group'=>'المدفوعات','label'=>'بطاقات الشحن','icon'=>'🎫'],
        ['key'=>'payments:binance','group'=>'المدفوعات','label'=>'مدفوعات Binance','icon'=>'◈'],
        ['key'=>'payments:usdt','group'=>'المدفوعات','label'=>'مدفوعات USDT — BEP20','icon'=>'₮'],
        ['key'=>'payments:direct','group'=>'المدفوعات','label'=>'الدفع المباشر','icon'=>'💳'],
        ['key'=>'payments:manual','group'=>'المدفوعات','label'=>'الدفع اليدوي','icon'=>'📝'],
        ['key'=>'payment:view','group'=>'المدفوعات','label'=>'عرض الدفعة','icon'=>'🔎'],
        ['key'=>'topup:auto','group'=>'شحن الرصيد','label'=>'الشحن التلقائي','icon'=>'⚡'],
        ['key'=>'topup:card','group'=>'شحن الرصيد','label'=>'بطاقة شحن','icon'=>'🎫'],
        ['key'=>'topup:binance','group'=>'شحن الرصيد','label'=>'بينانس مباشر','icon'=>'◈'],
        ['key'=>'topup:usdt','group'=>'شحن الرصيد','label'=>'USDT — BEP20 مباشر','icon'=>'₮'],
        ['key'=>'topup:floosak','group'=>'شحن الرصيد','label'=>'فلوسك','icon'=>'💳'],
        ['key'=>'topup:manual','group'=>'شحن الرصيد','label'=>'شحن يدوي','icon'=>'📝'],
        ['key'=>'topup:cancel','group'=>'شحن الرصيد','label'=>'إلغاء الشحن','icon'=>'❌'],
        ['key'=>'notifications:order','group'=>'الإشعارات','label'=>'إشعارات الطلبات','icon'=>'📦'],
        ['key'=>'notifications:topup','group'=>'الإشعارات','label'=>'إشعارات الشحن','icon'=>'💰'],
        ['key'=>'notifications:refresh','group'=>'الإشعارات','label'=>'تحديث الإشعارات','icon'=>'🔄'],
        ['key'=>'support:start','group'=>'الدعم','label'=>'بدء الدعم','icon'=>'🆘'],
        ['key'=>'support:cancel','group'=>'الدعم','label'=>'إلغاء الدعم','icon'=>'❌'],
        ['key'=>'support:order','group'=>'الدعم','label'=>'دعم الطلب','icon'=>'🎧'],
        ['key'=>'favorite:toggle','group'=>'الخدمات','label'=>'تبديل المفضلة','icon'=>'⭐'],
        ['key'=>'telecom:check','group'=>'كابينة السداد','label'=>'فحص الرقم','icon'=>'🔎'],
        ['key'=>'telecom:confirm','group'=>'كابينة السداد','label'=>'تأكيد العملية','icon'=>'✅'],
        ['key'=>'telecom:cancel','group'=>'كابينة السداد','label'=>'إلغاء العملية','icon'=>'❌'],
        ['key'=>'telecom:network','group'=>'كابينة السداد','label'=>'اختيار الشبكة','icon'=>'📡'],
        ['key'=>'telecom:group','group'=>'كابينة السداد','label'=>'اختيار الفئة','icon'=>'📂'],
        ['key'=>'telecom:amount','group'=>'كابينة السداد','label'=>'اختيار المبلغ','icon'=>'💰'],
        ['key'=>'telecom:back','group'=>'كابينة السداد','label'=>'رجوع في الكابينة','icon'=>'↩️'],
        ['key'=>'telecom:type','group'=>'كابينة السداد','label'=>'نوع الدفع','icon'=>'💵'],
        ['key'=>'telecom:section','group'=>'كابينة السداد','label'=>'قسم الشحن','icon'=>'📂'],
        ['key'=>'telecom:bundle','group'=>'كابينة السداد','label'=>'الباقة أو الفئة','icon'=>'📦'],
        ['key'=>'telecom:solfa','group'=>'كابينة السداد','label'=>'السلفة','icon'=>'💰'],
        ['key'=>'account:currency','group'=>'الحساب','label'=>'عملة العرض','icon'=>'💱'],
        ['key'=>'account:language','group'=>'الحساب','label'=>'لغة العرض','icon'=>'🌐'],
        ['key'=>'account:password','group'=>'الحساب','label'=>'تغيير كلمة المرور','icon'=>'🔐'],
        ['key'=>'account:unlink','group'=>'الحساب','label'=>'فصل الحساب','icon'=>'🔗'],
        ['key'=>'account:confirm','group'=>'الحساب','label'=>'تأكيد فصل الحساب','icon'=>'✅'],
        ['key'=>'account:cancel','group'=>'الحساب','label'=>'إلغاء فصل الحساب','icon'=>'❌'],
    ];
}

function njazTgMenuDefinitionMap(): array {
    $map = [];
    foreach (njazTgMenuDefinitions() as $item) $map[(string)$item['key']] = $item;
    return $map;
}

function njazTgMenuCustomEmojiId(PDO $pdo, string $menuKey): ?string {
    static $cache = [];
    $menuKey = trim($menuKey);
    if ($menuKey === '' || !array_key_exists($menuKey, $cache)) {
        if ($menuKey === '') return null;
        try {
            njazTgEnsureTables($pdo);
            $stmt = $pdo->prepare("SELECT icon_custom_emoji_id FROM telegram_bot_menu_items WHERE menu_key=? LIMIT 1");
            $stmt->execute([$menuKey]);
            $value = trim((string)($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) { $value = ''; }
        $cache[$menuKey] = preg_match('/^\\d{5,64}$/', $value) ? $value : null;
    }
    return $cache[$menuKey] ?? null;
}

function njazTgUiStyle($value, string $fallback = 'primary'): string {
    $value = strtolower(trim((string)$value));
    return in_array($value, ['primary', 'success', 'danger'], true) ? $value : $fallback;
}
function njazTgUiSettings(PDO $pdo, string $scopeKey, array $defaults = []): array {
    $base = [
        'button_style' => 'primary', 'page_size' => 8, 'columns_count' => 2,
        'next_style' => 'primary', 'previous_style' => 'primary', 'cancel_style' => 'danger'
    ];
    foreach ($defaults as $key => $value) if (array_key_exists($key, $base)) $base[$key] = $value;
    $scopeKey = trim($scopeKey);
    if ($scopeKey !== '') {
        try {
            njazTgEnsureTables($pdo);
            $stmt = $pdo->prepare('SELECT button_style,page_size,columns_count,next_style,previous_style,cancel_style FROM telegram_bot_ui_settings WHERE scope_key=? LIMIT 1');
            $stmt->execute([$scopeKey]);
            $row = $stmt->fetch();
            if ($row) $base = array_merge($base, $row);
        } catch (Throwable $e) { error_log('Telegram UI settings read failed: ' . $e->getMessage()); }
    }
    $base['button_style'] = njazTgUiStyle($base['button_style'], (string)($defaults['button_style'] ?? 'primary'));
    $base['next_style'] = njazTgUiStyle($base['next_style'], 'primary');
    $base['previous_style'] = njazTgUiStyle($base['previous_style'], 'primary');
    $base['cancel_style'] = njazTgUiStyle($base['cancel_style'], 'danger');
    $base['page_size'] = max(1, min(50, (int)$base['page_size']));
    $base['columns_count'] = max(1, min(4, (int)$base['columns_count']));
    return $base;
}
function njazTgReflowKeyboard(array $rows, int $columns = 2): array {
    $columns = max(1, min(4, $columns)); $result = []; $pool = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !$row) continue;
        if (count($row) <= 1) { if ($pool) { foreach (array_chunk($pool, $columns) as $chunk) $result[] = $chunk; $pool = []; } $result[] = array_values($row); continue; }
        foreach ($row as $button) if (is_array($button)) $pool[] = $button;
    }
    if ($pool) foreach (array_chunk($pool, $columns) as $chunk) $result[] = $chunk;
    return $result;
}
function njazTgMenuKeyForButton(array $button) {
    $callback = trim((string)($button['callback_data'] ?? ''));
    $text = trim((string)($button['text'] ?? ''));
    $exact = [
        'home'=>'home','search:start'=>'menu:search','menu:services'=>'menu:services','menu:quizzes'=>'menu:quizzes','menu:favorites'=>'menu:favorites','menu:recent'=>'menu:recent',
        'menu:balance'=>'menu:balance','menu:orders'=>'menu:orders','menu:topup'=>'menu:topup','menu:payments'=>'menu:payments',
        'menu:profile'=>'menu:profile','menu:notifications'=>'menu:notifications','menu:help'=>'menu:help',
        'login:start'=>'login:start','login:cancel'=>'login:cancel','register:start'=>'register:start','register:cancel'=>'register:cancel',
        'orders:lookup'=>'orders:lookup','orders:cancel'=>'orders:cancel','orders:status:pending'=>'orders:pending',
        'orders:status:completed'=>'orders:completed','orders:status:cancelled'=>'orders:cancelled','payments:all'=>'payments:all',
        'payments:approved'=>'payments:approved','payments:pending'=>'payments:pending','payments:rejected'=>'payments:rejected',
        'payments:card'=>'payments:card','payments:binance'=>'payments:binance','payments:usdt'=>'payments:usdt','payments:direct'=>'payments:direct','payments:manual'=>'payments:manual',
        'topup:auto'=>'topup:auto','topup:card'=>'topup:card','topup:binance'=>'topup:binance','topup:usdt'=>'topup:usdt','topup:floosak'=>'topup:floosak','topup:manual'=>'topup:manual',
        'topup:cancel'=>'topup:cancel','binance:verify'=>'topup:binance','notif:order'=>'notifications:order','notif:topup'=>'notifications:topup',
        'notif:refresh'=>'notifications:refresh','support:start'=>'support:start','support:cancel'=>'support:cancel',
        'favorite:toggle'=>'favorite:toggle','tcheck'=>'telecom:check','tconfirm'=>'telecom:confirm','tback'=>'telecom:back',
        'unlink:confirm'=>'account:confirm','unlink:cancel'=>'account:cancel','profile:currency'=>'account:currency','profile:language'=>'account:language','profile:password'=>'account:password','login:cancel'=>'login:cancel','register:cancel'=>'register:cancel'
    ];
    if (isset($exact[$callback])) return $exact[$callback];
    if (preg_match('/^cat:\\d+$/', $callback)) return 'item:category:' . (int)substr($callback, 4);
    if (preg_match('/^svc:\\d+$/', $callback)) return 'item:service:' . (int)substr($callback, 4);
    if (preg_match('/^pm:(\\d+)$/', $callback, $match)) return 'payment:method:' . (int)$match[1];
    if (str_starts_with($callback, 'profile:currency:')) return 'account:currency';
    if (str_starts_with($callback, 'profile:language:')) return 'account:language';
    if (str_starts_with($callback, 'order:view:')) return 'order:view';
    if (str_starts_with($callback, 'reorder:')) return 'order:reorder';
    if (str_starts_with($callback, 'payment:view:')) return 'payment:view';
    if (str_starts_with($callback, 'support:order:')) return 'support:order';
    if (str_starts_with($callback, 'fav:toggle:')) return 'favorite:toggle';
    if (str_starts_with($callback, 'tnet:')) return 'telecom:network';
    if (str_starts_with($callback, 'tgrp:')) return 'telecom:group';
    if (str_starts_with($callback, 'tamt:')) return 'telecom:amount';
    if (str_starts_with($callback, 'tbn:')) return 'telecom:bundle';
    if (str_starts_with($callback, 'tptype:')) return 'telecom:type';
    if (str_starts_with($callback, 'tsec:')) return 'telecom:section';
    if (str_starts_with($callback, 'tsolfa:')) return 'telecom:solfa';
    if (str_starts_with($callback, 'thumb:')) {
        if (preg_match('/التالي|next|➡/iu', $text)) return 'nav:next';
        return 'nav:previous';
    }
    if (preg_match('/الرئيسية|home/iu', $callback . ' ' . $text)) return 'nav:home';
    if (preg_match('/رجوع|back|cancel|إلغاء/iu', $callback . ' ' . $text)) return 'nav:back';
    if (preg_match('/مساعدة|help/iu', $callback . ' ' . $text)) return 'nav:help';
    return null;
}

function njazTgStripLeadingEmoji(string $text): string {
    $text = trim($text);
    if (preg_match('/^\\s*(\\X)\\s+/u', $text, $m) && preg_match('/[\\x{1F000}-\\x{1FAFF}\\x{1F1E6}-\\x{1F1FF}\\x{2300}-\\x{23FF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}]/u', $m[1])) {
        return trim(substr($text, strlen($m[0])));
    }
    return $text;
}

/**
 * يحذف رمز Unicode الاحتياطي المفرد من بداية أو نهاية الزر عند وجود Custom Emoji.
 * لا يلمس الرموز الموجودة داخل اسم الزر ولا يغيّر callback_data.
 */
function njazTgStripFallbackEmoji(string $text): string {
    $text = trim($text);
    $emoji = '[\\x{1F000}-\\x{1FAFF}\\x{1F1E6}-\\x{1F1FF}\\x{2300}-\\x{23FF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}]';
    if (preg_match('/^\\s*(\\X)\\s+/u', $text, $m) && preg_match('/' . $emoji . '/u', $m[1])) {
        $text = trim(substr($text, strlen($m[0])));
    }
    if (preg_match('/\\s+(\\X)\\s*$/u', $text, $m) && preg_match('/' . $emoji . '/u', $m[1])) {
        $text = trim(substr($text, 0, -strlen($m[0])));
    }
    return $text;
}

function njazTgApplyMenuIcons(PDO $pdo, array $keyboard): array {
    foreach ($keyboard as $rowIndex => $row) {
        if (!is_array($row)) continue;
        foreach ($row as $buttonIndex => $button) {
            if (!is_array($button)) continue;
            $uiScope = trim((string)($button['ui_scope'] ?? ''));
            unset($button['ui_scope']);
            $key = njazTgMenuKeyForButton($button);
            $styleKey = $uiScope !== '' ? $uiScope : $key;
            $custom = $key ? njazTgMenuCustomEmojiId($pdo, $key) : null;
            if ($custom !== null) {
                // يضيف Telegram الأيقونة المخصصة بجانب نص الزر عند تمرير المعرّف الصحيح.
                $button['icon_custom_emoji_id'] = $custom;
            }
            unset($button['custom_emoji_id_internal']);
            if ($styleKey) {
                $ui = njazTgUiSettings($pdo, $styleKey, ['button_style' => njazTgButtonStyle($button)]);
                $button['style'] = $ui['button_style'];
            }
            $keyboard[$rowIndex][$buttonIndex] = $button;
        }
    }
    return $keyboard;
}

function njazTgOperationEnabled(PDO $pdo, string $operation): bool {
    $allowed = ['allow_orders','allow_topup','allow_balance','allow_orders_history','allow_profile','allow_telecom'];
    if (!in_array($operation, $allowed, true)) return false;
    $settings = njazTgSettings($pdo);
    return (int)($settings[$operation] ?? 1) === 1;
}

function njazTgCategoryEnabled(PDO $pdo, int $categoryId): bool {
    njazTgEnsureTables($pdo);
    $s = $pdo->prepare("SELECT enabled FROM telegram_bot_categories WHERE category_id=? LIMIT 1"); $s->execute([$categoryId]);
    $v = $s->fetchColumn(); return $v === false ? true : (int)$v === 1;
}

function njazTgServiceEnabled(PDO $pdo, int $serviceId): bool {
    njazTgEnsureTables($pdo);
    $s = $pdo->prepare("SELECT enabled FROM telegram_bot_services WHERE service_id=? LIMIT 1"); $s->execute([$serviceId]);
    $v = $s->fetchColumn(); return $v === false ? true : (int)$v === 1;
}

function njazTgLog(PDO $pdo, string $direction, string $method, $payload, ?int $telegramId = null, ?int $updateId = null): void {
    try {
        $pdo->prepare("INSERT INTO telegram_bot_logs (update_id,telegram_id,direction,method,payload) VALUES (?,?,?,?,?)")
            ->execute([$updateId, $telegramId, $direction, $method, is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('Telegram log error: ' . $e->getMessage()); }
}

function njazTgCustomEmojiEnsure(PDO $pdo, string $customEmojiId): bool {
    $id = trim($customEmojiId);
    if (!preg_match('/^\\d{5,64}$/', $id)) return false;
    njazTgEnsureTables($pdo);
    try {
        $pdo->prepare("INSERT IGNORE INTO telegram_bot_custom_emojis (custom_emoji_id) VALUES (?)")->execute([$id]);
        return true;
    } catch (Throwable $e) {
        error_log('Telegram custom emoji catalog insert failed: ' . $e->getMessage());
        return false;
    }
}

function njazTgCustomEmojiIdsFromLogs(PDO $pdo): array {
    njazTgEnsureTables($pdo);
    $ids = [];
    try {
        $rows = $pdo->query("SELECT payload FROM telegram_bot_logs WHERE direction='in' AND method='update' AND payload LIKE '%custom_emoji_id%' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $payload) {
            if (preg_match_all('/\\"custom_emoji_id\\"\\s*:\\s*\\"(\\d{5,64})\\"/', (string)$payload, $matches)) {
                foreach ($matches[1] as $id) $ids[$id] = true;
            }
        }
    } catch (Throwable $e) { error_log('Telegram custom emoji log scan failed: ' . $e->getMessage()); }
    return array_keys($ids);
}

function njazTgCustomEmojiSync(PDO $pdo): array {
    njazTgEnsureTables($pdo);
    foreach (njazTgCustomEmojiIdsFromLogs($pdo) as $id) njazTgCustomEmojiEnsure($pdo, $id);
    try {
        $menuIds = $pdo->query("SELECT icon_custom_emoji_id FROM telegram_bot_menu_items WHERE icon_custom_emoji_id IS NOT NULL AND icon_custom_emoji_id<>''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($menuIds as $id) njazTgCustomEmojiEnsure($pdo, (string)$id);
    } catch (Throwable $e) {}
    try { $rows = $pdo->query("SELECT custom_emoji_id FROM telegram_bot_custom_emojis ORDER BY updated_at DESC, custom_emoji_id DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { return []; }
    $ids = array_values(array_filter(array_map('strval', $rows), static fn($id) => preg_match('/^\\d{5,64}$/', $id)));
    foreach (array_chunk($ids, 200) as $chunk) {
        $result = njazTgApi($pdo, 'getCustomEmojiStickers', ['custom_emoji_ids' => json_encode($chunk, JSON_UNESCAPED_UNICODE)]);
        if (empty($result['ok']) || !is_array($result['result'] ?? null)) {
            try { $pdo->prepare("UPDATE telegram_bot_custom_emojis SET last_error=?,updated_at=NOW() WHERE custom_emoji_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")")->execute(array_merge([(string)($result['description'] ?? 'تعذر جلب بيانات الأيقونة')], $chunk)); } catch (Throwable $e) {}
            continue;
        }
        foreach ($result['result'] as $sticker) {
            $id = trim((string)($sticker['custom_emoji_id'] ?? ''));
            if (!preg_match('/^\\d{5,64}$/', $id)) continue;
            try {
                $pdo->prepare("UPDATE telegram_bot_custom_emojis SET file_id=?,thumbnail_file_id=?,is_animated=?,is_video=?,last_error=NULL,updated_at=NOW() WHERE custom_emoji_id=?")
                    ->execute([(string)($sticker['file_id'] ?? ''), (string)($sticker['thumbnail']['file_id'] ?? ''), !empty($sticker['is_animated']) ? 1 : 0, !empty($sticker['is_video']) ? 1 : 0, $id]);
            } catch (Throwable $e) {}
        }
    }
    try { return $pdo->query("SELECT * FROM telegram_bot_custom_emojis ORDER BY updated_at DESC, custom_emoji_id DESC LIMIT 200")->fetchAll(); } catch (Throwable $e) { return []; }
}
