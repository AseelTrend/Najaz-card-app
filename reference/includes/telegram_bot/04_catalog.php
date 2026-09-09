<?php

function njazTgServiceButtonText(string $icon, $name, $price = null): string {
    $label = njazTgClipLabel($name);
    return $icon . ' ' . $label . ($price !== null ? ' · ' . njazTgMoney($price) : '');
}

/**
 * يقبل رمز Unicode المرئي فقط، ويرفض أسماء الأيقونات الداخلية أو أصناف CSS مثل
 * folder وfas fa-gamepad حتى لا تظهر للمستخدم داخل زر Telegram.
 */
function njazTgVisibleIcon($value): ?string {
    $value = trim((string)$value);
    // استخدم حدّاً بالبايت لتجنب الاعتماد على mbstring في استضافة البوت.
    if ($value === '' || strlen($value) > 32) return null;
    return preg_match('/[\\x{1F000}-\\x{1FAFF}\\x{2600}-\\x{27BF}\\x{2300}-\\x{23FF}]/u', $value) ? $value : null;
}

/**
 * رمز بصري موحد للقسم. يعتمد على بيانات القسم الأصلية ولا ينشئ تصنيفاً جديداً.
 */
function njazTgCategoryIcon(array $category): string {
    foreach (['icon', 'emoji', 'icon_emoji'] as $key) {
        $explicit = njazTgVisibleIcon($category[$key] ?? '');
        if ($explicit !== null) return $explicit;
    }

    $type = strtolower(trim((string)($category['category_type'] ?? '')));
    $name = strtolower(trim((string)($category['display_name'] ?? $category['name'] ?? '')));
    $rules = [
        '📡' => $type === 'telecom' || preg_match('~اتصالات|شبك|سداد|هاتف|موبايل|telecom|mobile|topup|sim|network~iu', $name),
        '🎮' => preg_match('~لعب|ألعاب|game|pubg|فري|روبلوكس|cod|free fire|playstation|xbox~iu', $name),
        '🎫' => preg_match('~بطاق|بطاقة|كود|قسيم|card|code|voucher|gift~iu', $name),
        '💳' => preg_match('~رصيد|محفظ|دفع|مالي|wallet|balance|payment|cash~iu', $name),
        '✨' => preg_match('~ذكاء|اشتراك|عضوية|premium|ai|subscription|membership~iu', $name),
        '⚡' => preg_match('~شحن|طاقة|كهرب|charge|energy|power~iu', $name),
        '📱' => preg_match('~تطبيق|برامج|منصة|app|software|platform~iu', $name),
        '🛡️' => preg_match('~حماية|أمان|vpn|security|privacy~iu', $name),
    ];
    foreach ($rules as $icon => $matched) {
        if ($matched) return $icon;
    }
    return '📁';
}

/** يختار أيقونة صغيرة للخدمة اعتماداً على اسمها أو نوعها، دون إنشاء بيانات موازية. */
function njazTgServiceIcon(array $service): string {
    foreach (['icon', 'emoji', 'icon_emoji'] as $key) {
        $explicit = njazTgVisibleIcon($service[$key] ?? '');
        if ($explicit !== null) return $explicit;
    }
    $type = strtolower(trim((string)($service['service_type'] ?? $service['type'] ?? '')));
    $name = strtolower(trim((string)($service['display_name'] ?? $service['name'] ?? $service['category_name'] ?? '')));
    if ($type === 'telecom' || preg_match('~شحن|اتصال|رقم|هاتف|موبايل|telecom|mobile|topup~iu', $name)) return '📡';
    if (preg_match('~لعب|ألعاب|game|pubg|فري|روبلوكس|cod|free fire|playstation|xbox~iu', $name)) return '🎮';
    if (preg_match('~بطاق|بطاقة|كود|قسيم|card|code|voucher|gift~iu', $name)) return '🎫';
    if (preg_match('~ذكاء|اشتراك|عضوية|premium|ai|subscription|membership~iu', $name)) return '✨';
    if (preg_match('~تطبيق|برامج|منصة|app|software|platform~iu', $name)) return '📱';
    if (preg_match('~حماية|أمان|vpn|security|privacy~iu', $name)) return '🛡️';
    return '🛒';
}

/**
 * نقطة توسعة اختيارية لـ Custom Emoji. لا تُرسل إلا إذا زُوّد المعرّف صراحةً.
 */
function njazTgCustomEmojiId(array $item): ?string {
    foreach (['icon_custom_emoji_id', 'custom_emoji_id', 'icon_emoji_id'] as $key) {
        $value = trim((string)($item[$key] ?? ''));
        if ($value !== '' && preg_match('/^\\d{5,64}$/', $value)) return $value;
    }
    return null;
}

function njazTgCategoryButtonStyle(array $category): string {
    $type = strtolower(trim((string)($category['category_type'] ?? '')));
    $name = strtolower(trim((string)($category['display_name'] ?? $category['name'] ?? '')));
    if ($type === 'telecom' || preg_match('/اتصالات|شبك|سداد|telecom|mobile|topup|رصيد|شحن/', $name)) return 'success';
    return 'primary';
}

/**
 * يضيف ألوان Telegram الرسمية إلى الأزرار دون تغيير callback_data أو ترتيبها.
 * القيم المدعومة من Bot API: primary (أزرق)، success (أخضر)، danger (أحمر).
 */
function njazTgButtonStyle(array $button): string {
    if (isset($button['style']) && in_array((string)$button['style'], ['primary', 'success', 'danger'], true)) {
        return (string)$button['style'];
    }
    $callback = strtolower(trim((string)($button['callback_data'] ?? '')));
    $text = trim((string)($button['text'] ?? ''));

    if (preg_match('/(^|:)(cancel|unlink|delete|remove|close)(:|$)/', $callback)
        || preg_match('/إلغاء|فصل الحساب|حذف|إزالة/', $text)) {
        return 'danger';
    }
    if (preg_match('/^(ord:confirm|tconfirm|topup:confirm|topup:approve|confirm)/', $callback)
        || preg_match('/تأكيد|تنفيذ|اعتماد|موافقة/', $text)) {
        return 'success';
    }
    return 'primary';
}

function njazTgStyledKeyboard(?array $keyboard): ?array {
    if ($keyboard === null) return null;
    $styled = [];
    // ui_scope وcustom_emoji_id_internal خصائص داخلية، بينما style وicon_custom_emoji_id مدعومان في Bot API المستخدم.
    // نرسل الأيقونة المخصصة مع النص حتى تظهر بجانب اسم الزر، ونمنع الخصائص الداخلية الأخرى.
    $allowed = ['text', 'url', 'callback_data', 'web_app', 'login_url', 'switch_inline_query', 'switch_inline_query_current_chat', 'callback_game', 'pay', 'copy_text', 'switch_inline_query_chosen_chat', 'style', 'icon_custom_emoji_id'];
    foreach ($keyboard as $row) {
        if (!is_array($row)) continue;
        $styledRow = [];
        foreach ($row as $button) {
            if (!is_array($button) || !isset($button['text'])) continue;
            $button['style'] = njazTgButtonStyle($button);
            if (!empty($button['icon_custom_emoji_id'])) {
                $button['text'] = njazTgStripFallbackEmoji((string)$button['text']);
            }
            $clean = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $button) && $button[$key] !== null && $button[$key] !== '') $clean[$key] = $button[$key];
            }
            if (isset($clean['text'])) $styledRow[] = $clean;
        }
        if ($styledRow) $styled[] = $styledRow;
    }
    return $styled;
}

function njazTgClearThumbnailMessages(PDO $pdo, array $tgUser): void {
    $data = njazTgStateData($tgUser);
    $ids = $data['thumbnail_message_ids'] ?? [];
    if (!is_array($ids)) return;
    foreach (array_unique(array_map('intval', $ids)) as $messageId) {
        if ($messageId > 0) {
            njazTgApi($pdo, 'deleteMessage', ['chat_id' => (int)$tgUser['chat_id'], 'message_id' => $messageId]);
        }
    }
}

/**
 * يعرض الأقسام أو الخدمات كرسالة نصية واحدة مضغوطة مع أيقونات صغيرة داخل الأزرار.
 * لا يرسل ألبومات أو صورة لكل عنصر؛ صور الموقع تبقى متاحة في الشاشات التفصيلية فقط.
 */
function njazTgRenderThumbnailCatalog(PDO $pdo, array $tgUser, array $items, string $title, string $instruction, string $state, array $stateData, string $pageCallbackPrefix, string $backCallback, int $page = 1, ?int $messageId = null): void {
    $chatId = (int)$tgUser['chat_id'];
    if ($messageId > 0) njazTgApi($pdo, 'deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    // تنظيف رسائل الألبوم القديم مرة واحدة عند الانتقال من الإصدار السابق.
    njazTgClearThumbnailMessages($pdo, $tgUser);

    $scopeKey = $state === 'categories' ? 'layout:categories' : ('layout:category:' . (int)($stateData['category_id'] ?? 0));
    $ui = njazTgUiSettings($pdo, $scopeKey);
    $pageSize = $ui['page_size'];
    $total = count($items);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($pages, $page));
    $pageItems = array_slice($items, ($page - 1) * $pageSize, $pageSize);
    $buttons = [];

    foreach ($pageItems as $item) {
        $kind = (string)($item['kind'] ?? 'service');
        $itemId = (int)($item['id'] ?? 0);
        $name = njazTgClipLabel($item['name'] ?? 'غير مسمى', 28);
        $customEmojiId = njazTgCustomEmojiId($item);
        $icon = trim((string)($item['icon'] ?? ''));
        if ($icon === '') $icon = $kind === 'category' ? '📁' : '🛒';
        // عند وجود Custom Emoji يستخدمه Telegram كأيقونة للزر، لذلك لا نكرر Unicode داخل النص.
        $label = ($customEmojiId !== null ? '' : $icon . ' ') . $name;
        if ($kind === 'service' && array_key_exists('price', $item)) {
            $label .= ' · ' . njazTgMoney($item['price']);
        }
        $itemScope = $kind === 'category' ? 'item:category:' . $itemId : 'item:service:' . $itemId;
        $button = [
            'text' => njazTgClipLabel($label, 42),
            'callback_data' => $kind === 'category' ? 'cat:' . $itemId : 'svc:' . $itemId,
            'style' => (string)($item['style'] ?? $ui['button_style']),
            'ui_scope' => $itemScope,
        ];
        if ($customEmojiId !== null) $button['icon_custom_emoji_id'] = $customEmojiId;
        $buttons[] = $button;
    }
    $displayTitle = $title;
    $catalogCategoryId = (int)($stateData['category_id'] ?? 0);
    if ($catalogCategoryId > 0 && function_exists('njazTgTranslateEntityText')) {
        $displayTitle = njazTgTranslateEntityText($pdo, (string)$title, njazTgLanguageCode($pdo, $tgUser), 'entity:category:' . $catalogCategoryId . ':name');
    }
    $text = njazTgScreen('🛍️', $displayTitle, $instruction . "\n\n<b>صفحة " . $page . ' من ' . $pages . '</b>');
    $keyboard = njazTgReflowKeyboard($buttons ? [$buttons] : [], $ui['columns_count']);
    $nav = [];
    if ($page > 1) $nav[] = ['text' => '⬅️ السابق', 'callback_data' => $pageCallbackPrefix . ($page - 1), 'style' => $ui['previous_style'], 'ui_scope' => $scopeKey];
    if ($page < $pages) $nav[] = ['text' => 'التالي ➡️', 'callback_data' => $pageCallbackPrefix . ($page + 1), 'style' => $ui['next_style'], 'ui_scope' => $scopeKey];
    if ($nav) $keyboard[] = $nav;
    $keyboard[] = [
        ['text' => '↩️ رجوع', 'callback_data' => $backCallback, 'style' => $ui['cancel_style'], 'ui_scope' => $scopeKey],
        ['text' => '🏠 الرئيسية', 'callback_data' => 'home', 'ui_scope' => 'home']
    ];

    $sent = njazTgSend($pdo, $chatId, $text, $keyboard);
    $stateData['thumbnail_message_ids'] = !empty($sent['result']['message_id']) ? [(int)$sent['result']['message_id']] : [];
    $stateData['thumbnail_page'] = $page;
    njazTgState($pdo, (int)$tgUser['telegram_id'], $state, $stateData);
}

function njazTgTwoColumnKeyboard(array $buttons): array {
    $rows = [];
    for ($i = 0, $count = count($buttons); $i < $count; $i += 2) {
        $row = [$buttons[$i]];
        if (isset($buttons[$i + 1])) $row[] = $buttons[$i + 1];
        $rows[] = $row;
    }
    return $rows;
}

function njazTgMainKeyboard(PDO $pdo): array {
    $ui = njazTgUiSettings($pdo, 'home');
    $buttons = [];
    $add = static function (array &$buttons, string $text, string $callback, string $style): void {
        $buttons[] = ['text' => $text, 'callback_data' => $callback, 'style' => $style];
    };
    if (njazTgOperationEnabled($pdo, 'allow_orders')) {
        $add($buttons, '🔎 بحث عن خدمة أو قسم', 'search:start', $ui['button_style']);
        $add($buttons, '🛒 الخدمات', 'menu:services', $ui['button_style']);
        $add($buttons, '⭐ المفضلة', 'menu:favorites', $ui['button_style']);
        $add($buttons, '🕘 الأخيرة', 'menu:recent', $ui['button_style']);
    }
    $add($buttons, '🏆 المسابقات', 'menu:quizzes', $ui['button_style']);
    if (njazTgOperationEnabled($pdo, 'allow_balance')) $add($buttons, '💎 رصيدي', 'menu:balance', $ui['button_style']);
    if (njazTgOperationEnabled($pdo, 'allow_orders_history')) $add($buttons, '📦 طلباتي', 'menu:orders', $ui['button_style']);
    if (njazTgOperationEnabled($pdo, 'allow_topup')) $add($buttons, '💳 شحن الرصيد', 'menu:topup', $ui['button_style']);
    if (njazTgOperationEnabled($pdo, 'allow_topup') || njazTgOperationEnabled($pdo, 'allow_balance')) $add($buttons, '🧾 مدفوعاتي', 'menu:payments', $ui['button_style']);
    if (njazTgOperationEnabled($pdo, 'allow_profile')) $add($buttons, '👤 حسابي', 'menu:profile', $ui['button_style']);
    $add($buttons, '🔔 إشعاراتي', 'menu:notifications', $ui['button_style']);
    $add($buttons, '🆘 المساعدة', 'menu:help', $ui['button_style']);
    return array_map(static fn(array $row): array => array_values($row), array_chunk($buttons, $ui['columns_count']));
}

function njazTgHome(PDO $pdo, array $tgUser, bool $edit = false, ?int $messageId = null): void {
    $settings = njazTgSettings($pdo);
    $linked = !empty($tgUser['user_id']);
    $displayName = trim((string)($tgUser['first_name'] ?? '')) ?: trim((string)($tgUser['username'] ?? '')) ?: 'عميلنا العزيز';
    if ($linked) {
        $account = getUser((int)$tgUser['user_id']);
        $body = 'أهلًا بك <b>' . njazTgHtml($displayName) . '</b>\n💰 رصيدك الحالي: <b>' . njazTgMoney($account['balance'] ?? 0) . '</b>\n\n<b>ماذا تريد أن تنجز اليوم؟</b>\nاختر خدمة من القائمة، ويمكنك الوصول سريعاً إلى المفضلة وآخر الخدمات.';
        $text = njazTgScreen('🏠', 'نجاز كارد', $body);
        $keyboard = njazTgMainKeyboard($pdo);
        $pendingNumbers = njazTgNumbersLivePendingOrders($tgUser);
        if ($pendingNumbers) {
            array_unshift($keyboard, [[
                'text' => '📋 الأرقام المعلقة (' . count($pendingNumbers) . ')',
                'callback_data' => 'nl:list',
                'style' => 'primary'
            ]]);
        }
    } else {
        $body = njazTgHtml($settings['welcome_text'] ?? 'مرحباً بك في نجاز كارد. اربط حسابك للبدء.') . '\n\n<b>ابدأ من هنا</b>\nسجّل الدخول أو أنشئ حساباً جديداً للوصول إلى الخدمات والمحفظة والطلبات.';
        $text = njazTgScreen('🏠', 'نجاز كارد', $body);
        $keyboard = [
            [['text' => '🔐 تسجيل الدخول', 'callback_data' => 'login:start']],
            [['text' => '🆕 إنشاء حساب جديد', 'callback_data' => 'register:start']],
            [['text' => '🏆 المسابقات', 'callback_data' => 'menu:quizzes']],
            [['text' => '📘 طريقة الاستخدام', 'callback_data' => 'menu:help']]
        ];
    }
    njazTgRender($pdo, $tgUser, $text, $keyboard, ($edit ? $messageId : null));
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
}

function njazTgRequireLinked(PDO $pdo, array $tgUser): bool {
    if (!empty($tgUser['user_id'])) return true;
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🔐', 'اربط حسابك أولاً', 'للاستفادة من الخدمات، سجّل الدخول إلى حساب نجاز المرتبط أو استخدم الأمر التالي:\n<code>/link اسم_المستخدم كلمة_المرور</code>'), [
        [['text' => '🔐 تسجيل الدخول', 'callback_data' => 'login:start'], ['text' => '🆕 إنشاء حساب', 'callback_data' => 'register:start']],
        [['text' => '↩️ الرئيسية', 'callback_data' => 'home']]
    ]);
    return false;
}

function njazTgRateLimit(PDO $pdo, int $telegramId, string $action, int $maxHits = 5, int $windowSec = 300): bool {
    $key = 'tg:' . $telegramId . ':' . $action;
    $now = time(); $windowStart = $now - $windowSec;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            rl_key VARCHAR(150) NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            window_start INT UNSIGNED NOT NULL,
            last_hit INT UNSIGNED NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_key (rl_key), INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->prepare('SELECT hits,window_start FROM api_rate_limits WHERE rl_key=? LIMIT 1');
        $stmt->execute([$key]); $row = $stmt->fetch();
        if (!$row || (int)$row['window_start'] < $windowStart) {
            $pdo->prepare("INSERT INTO api_rate_limits (rl_key,hits,window_start,last_hit) VALUES (?,1,?,?) ON DUPLICATE KEY UPDATE hits=1,window_start=VALUES(window_start),last_hit=VALUES(last_hit)")->execute([$key,$now,$now]);
            return true;
        }
        $hits = (int)$row['hits'] + 1;
        if ($hits > $maxHits) return false;
        $pdo->prepare('UPDATE api_rate_limits SET hits=?,last_hit=? WHERE rl_key=?')->execute([$hits,$now,$key]);
    } catch (Throwable $e) {
        error_log('Telegram rate limit failed: ' . $e->getMessage());
    }
    return true;
}

function njazTgLink(PDO $pdo, array $tgUser, string $username, string $password): void {
    if (!njazTgRateLimit($pdo, (int)$tgUser['telegram_id'], 'link', 5, 300)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تم إيقاف محاولات الربط مؤقتاً لحماية الحساب. حاول بعد بضع دقائق.');
        return;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=? AND status=1 AND (is_deleted=0 OR is_deleted IS NULL) LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string)$user['password'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'بيانات الربط غير صحيحة. تأكد من اسم المستخدم وكلمة المرور ثم أعد المحاولة.');
        return;
    }
    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذا الحساب محمي بالتحقق الثنائي ولا يمكن ربطه مباشرة عبر Telegram. استخدم مسار الربط الذي تعتمده الإدارة.');
        return;
    }
    $old = $pdo->prepare("SELECT telegram_id FROM telegram_users WHERE user_id=? AND telegram_id<>? LIMIT 1");
    $old->execute([(int)$user['id'], (int)$tgUser['telegram_id']]);
    if ($old->fetchColumn()) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذا الحساب مرتبط مسبقاً بحساب Telegram آخر. تواصل مع الإدارة إذا كان الربط غير صحيح.');
        return;
    }
    $pdo->prepare("UPDATE telegram_users SET user_id=?,state='menu',state_data=NULL WHERE telegram_id=?")
        ->execute([(int)$user['id'], (int)$tgUser['telegram_id']]);
    njazTgSyncUserChat($pdo, (int)$user['id'], (int)$tgUser['chat_id']);
    try { sendNotification($pdo, (int)$user['id'], 'system', '👋 أهلاً بك في Telegram', 'تم ربط حسابك بنجاح. ستصلك الآن إشعارات الطلبات وشحن الرصيد عبر Telegram.', 'telegram', '#6c3fe0'); } catch (Throwable $e) {}
    $tgUser['user_id'] = (int)$user['id'];
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'تم ربط الحساب بنجاح', 'ستصلك الآن إشعارات الطلبات وشحن الرصيد عبر Telegram.\n\nيمكنك البدء من القائمة الرئيسية.'), njazTgMainKeyboard($pdo));
}

function njazTgEnsureFavoritesTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_service_favorites (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_user_service (user_id, service_id),
        INDEX idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function njazTgIsFavorite(PDO $pdo, int $userId, int $serviceId): bool {
    try {
        njazTgEnsureFavoritesTable($pdo);
        $s = $pdo->prepare('SELECT 1 FROM telegram_service_favorites WHERE user_id=? AND service_id=? LIMIT 1');
        $s->execute([$userId, $serviceId]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function njazTgFavoriteToggle(PDO $pdo, array $tgUser, int $serviceId): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgServiceEnabled($pdo, $serviceId)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه الخدمة غير متاحة حالياً.'); return; }
    try {
        njazTgEnsureFavoritesTable($pdo); $uid = (int)$tgUser['user_id'];
        if (njazTgIsFavorite($pdo, $uid, $serviceId)) {
            $pdo->prepare('DELETE FROM telegram_service_favorites WHERE user_id=? AND service_id=?')->execute([$uid, $serviceId]);
            $message = 'تمت إزالة الخدمة من المفضلة.';
        } else {
            $pdo->prepare('INSERT IGNORE INTO telegram_service_favorites (user_id,service_id) VALUES (?,?)')->execute([$uid, $serviceId]);
            $message = '⭐ تمت إضافة الخدمة إلى المفضلة.';
        }
        njazTgSend($pdo, (int)$tgUser['chat_id'], $message, [[['text' => 'فتح الخدمة', 'callback_data' => 'svc:' . $serviceId], ['text' => 'المفضلة', 'callback_data' => 'menu:favorites']], [['text' => 'الرئيسية', 'callback_data' => 'home']]]);
    } catch (Throwable $e) {
        error_log('Telegram favorite toggle failed: ' . $e->getMessage());
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر تحديث المفضلة حالياً. حاول لاحقاً.');
    }
}

function njazTgQuickServices(PDO $pdo, array $tgUser, string $kind = 'recent', ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $uid = (int)$tgUser['user_id']; $rows = [];
    $uiScope = $kind === 'favorites' ? 'menu:favorites' : 'menu:recent';
    $ui = njazTgUiSettings($pdo, $uiScope);
    try {
        if ($kind === 'favorites') {
            njazTgEnsureFavoritesTable($pdo);
            $s = $pdo->prepare("SELECT s.id,s.name,s.price,COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name
                FROM telegram_service_favorites f JOIN services s ON s.id=f.service_id
                LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id
                WHERE f.user_id=? AND s.status=1 AND COALESCE(tbs.enabled,1)=1 ORDER BY f.created_at DESC LIMIT 30");
            $s->execute([$uid]); $rows = $s->fetchAll();
        } else {
            $s = $pdo->prepare("SELECT o.id AS order_id,o.field_data,o.quantity,s.id,s.name,s.price,COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name
                FROM orders o JOIN services s ON s.id=o.service_id LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id
                WHERE o.user_id=? AND s.status=1 AND COALESCE(tbs.enabled,1)=1 ORDER BY o.created_at DESC LIMIT 50");
            $s->execute([$uid]); $seen = [];
            foreach ($s->fetchAll() as $row) {
                if (isset($seen[(int)$row['id']])) continue;
                $seen[(int)$row['id']] = true; $rows[] = $row;
            }
        }
    } catch (Throwable $e) { error_log('Telegram quick services read failed: ' . $e->getMessage()); }
    $rows = array_slice($rows, 0, $ui['page_size']);
    $title = $kind === 'favorites' ? '⭐ خدماتي المفضلة' : '🕘 خدماتي الأخيرة'; $buttons = [];
    foreach ($rows as $row) {
        $sid = (int)$row['id'];
        $buttons[] = ['text' => ($kind === 'favorites' ? '⭐ ' : '🕘 ') . ($row['display_name'] ?? $row['name']) . ' — ' . njazTgMoney($row['price']), 'callback_data' => 'svc:' . $sid, 'style' => $ui['button_style']];
        if ($kind !== 'favorites' && !empty($row['order_id'])) $buttons[] = ['text' => '↻ إعادة الطلب', 'callback_data' => 'reorder:' . (int)$row['order_id'], 'style' => $ui['button_style']];
    }
    if (!$buttons) {
        $empty = $kind === 'favorites' ? 'لم تضف أي خدمة إلى المفضلة بعد.' : 'لا توجد خدمات سابقة مرتبطة بحسابك.';
        njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>' . $title . '</b>\n\n' . $empty, [[['text' => '🛒 الخدمات', 'callback_data' => 'menu:services'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $kb = njazTgReflowKeyboard([$buttons], $ui['columns_count']);
    $kb[] = [['text' => '↩️ الرئيسية', 'callback_data' => 'home', 'style' => $ui['cancel_style'], 'ui_scope' => $uiScope]];
    $text = '<b>' . $title . '</b>\nاختر خدمة لبدء طلب جديد.';
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $kb); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $kb);
}

function njazTgReorder(PDO $pdo, array $tgUser, int $orderId): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_orders')) return;
    $s = $pdo->prepare("SELECT o.id,o.service_id,o.quantity,o.field_data,s.name,s.price,s.min_qty,s.max_qty,
        COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name
        FROM orders o JOIN services s ON s.id=o.service_id LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id
        WHERE o.id=? AND o.user_id=? AND s.status=1 AND COALESCE(tbs.enabled,1)=1 LIMIT 1");
    $s->execute([$orderId, (int)$tgUser['user_id']]); $row = $s->fetch();
    if (!$row || !njazTgServiceEnabled($pdo, (int)$row['service_id'])) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'لا يمكن إعادة هذا الطلب؛ الخدمة غير متاحة أو العملية لا تخص حسابك.'); return; }
    $fields = json_decode((string)($row['field_data'] ?? ''), true); if (!is_array($fields)) $fields = [];
    $min = (int)($row['min_qty'] ?: 1); $max = (int)($row['max_qty'] ?: 1); $qty = max($min, min($max, (int)($row['quantity'] ?: $min)));
    $data = ['service_id'=>(int)$row['service_id'],'quantity'=>$qty,'min_qty'=>$min,'max_qty'=>$max,'fields'=>$fields,'field_index'=>0,'service_name'=>(string)($row['display_name'] ?? $row['name']),'base_price'=>(float)$row['price'],'reorder_from'=>$orderId];
    njazTgReviewOrder($pdo, $tgUser, $data);
}

function njazTgSearchNormalize(string $value): string {
    $value = trim(preg_replace('/\\s+/u', ' ', $value) ?? $value);
    if (function_exists('mb_strtolower')) $value = mb_strtolower($value, 'UTF-8'); else $value = strtolower($value);
    $value = preg_replace('/[\\x{064B}-\\x{065F}\\x{0670}]/u', '', $value) ?? $value;
    return strtr($value, ['أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي','ة'=>'ه']);
}

function njazTgSearchStart(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_orders')) return;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'catalog_search', []);
    $text = njazTgScreen('🔎', 'البحث في الخدمات', 'أرسل اسم القسم أو الخدمة، أو جزءاً منه، وسأعرض لك النتائج مباشرة.');
    $keyboard = [[['text' => '↩️ الرئيسية', 'callback_data' => 'home']]];
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $keyboard); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

function njazTgSearch(PDO $pdo, array $tgUser, string $term, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser) || !njazTgOperationEnabled($pdo, 'allow_orders')) return;
    $term = trim($term);
    if ($term === '') { njazTgSearchStart($pdo, $tgUser, $messageId); return; }
    $needle = njazTgSearchNormalize($term);
    $like = '%' . $needle . '%';
    $rawLike = '%' . (function_exists('mb_strtolower') ? mb_strtolower($term, 'UTF-8') : strtolower($term)) . '%';
    $categories = []; $services = [];
    try {
        $cat = $pdo->prepare("SELECT c.*,tbc.icon_custom_emoji_id,COALESCE(NULLIF(tbc.label_override,''),c.name) AS display_name
            FROM categories c LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id
            WHERE c.status=1 AND COALESCE(tbc.enabled,1)=1
              AND (LOWER(c.name) LIKE ? OR LOWER(c.name) LIKE ? OR LOWER(COALESCE(tbc.label_override,'')) LIKE ? OR LOWER(COALESCE(tbc.label_override,'')) LIKE ?)
            ORDER BY c.sort_order,c.id LIMIT 30");
        $cat->execute([$rawLike, $like, $rawLike, $like]); $categories = $cat->fetchAll();
        $svc = $pdo->prepare("SELECT s.*,c.name AS cat_name,tbs.icon_custom_emoji_id,COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name
            FROM services s JOIN categories c ON c.id=s.category_id
            LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id
            LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id
            WHERE s.status=1 AND COALESCE(tbs.enabled,1)=1 AND COALESCE(tbc.enabled,1)=1
              AND (LOWER(s.name) LIKE ? OR LOWER(s.name) LIKE ? OR LOWER(COALESCE(tbs.label_override,'')) LIKE ? OR LOWER(COALESCE(tbs.label_override,'')) LIKE ?)
            ORDER BY c.sort_order,s.sort_order,s.id LIMIT 50");
        $svc->execute([$rawLike, $like, $rawLike, $like]); $services = $svc->fetchAll();
    } catch (Throwable $e) { error_log('Telegram catalog search failed: ' . $e->getMessage()); }
    $ui = njazTgUiSettings($pdo, 'menu:search'); $buttons = [];
    foreach ($categories as $cat) {
        $label = njazTgClipLabel((string)($cat['display_name'] ?? $cat['name']), 32);
        $buttons[] = ['text' => njazTgServiceButtonText(njazTgCategoryIcon($cat), $label), 'callback_data' => 'cat:' . (int)$cat['id'], 'style' => njazTgCategoryButtonStyle($cat)];
    }
    foreach ($services as $svc) {
        $label = njazTgClipLabel((string)($svc['display_name'] ?? $svc['name']), 32);
        $buttons[] = ['text' => njazTgServiceButtonText(njazTgServiceIcon($svc), $label), 'callback_data' => 'svc:' . (int)$svc['id'], 'style' => $ui['button_style']];
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    if (!$buttons) {
        $text = njazTgScreen('🔎', 'نتيجة البحث', 'لم أجد قسماً أو خدمة مطابقة لعبارة: <b>' . njazTgHtml($term) . '</b>\nجرّب كلمة أقصر أو اكتب اسماً مختلفاً.');
        $keyboard = [[['text' => '🔎 بحث جديد', 'callback_data' => 'search:start', 'style' => $ui['button_style']]], [['text' => '↩️ الرئيسية', 'callback_data' => 'home', 'style' => $ui['cancel_style']]]];
    } else {
        $text = njazTgScreen('🔎', 'نتائج البحث', 'نتائج البحث عن: <b>' . njazTgHtml($term) . '</b>\nالأقسام: ' . count($categories) . ' — الخدمات: ' . count($services) . '\nاختر النتيجة للمتابعة.');
        $keyboard = njazTgReflowKeyboard([$buttons], $ui['columns_count']);
        $keyboard[] = [['text' => '🔎 بحث جديد', 'callback_data' => 'search:start', 'style' => $ui['button_style']], ['text' => '↩️ الرئيسية', 'callback_data' => 'home', 'style' => $ui['cancel_style']]];
    }
    if ($messageId) njazTgEdit($pdo, (int)$tgUser['chat_id'], $messageId, $text, $keyboard); else njazTgSend($pdo, (int)$tgUser['chat_id'], $text, $keyboard);
}

function njazTgCategories(PDO $pdo, array $tgUser, ?int $messageId = null, int $page = 1): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_orders')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'قسم الخدمات غير متاح عبر البوت حالياً.'); return; }
    $cats = $pdo->query("SELECT c.*,tbc.media_type,tbc.media_url,tbc.icon_custom_emoji_id,COALESCE(NULLIF(tbc.label_override,''),c.name) AS display_name FROM categories c LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id WHERE c.parent_id IS NULL AND c.status=1 AND COALESCE(tbc.enabled,1)=1 ORDER BY c.sort_order")->fetchAll();
    $items = [];
    foreach ($cats as $cat) {
        $items[] = [
            'kind' => 'category',
            'id' => (int)$cat['id'],
            'name' => (string)($cat['display_name'] ?? $cat['name']),
            'icon' => njazTgCategoryIcon($cat),
            'style' => njazTgCategoryButtonStyle($cat),
            'icon_custom_emoji_id' => (string)($cat['icon_custom_emoji_id'] ?? ''),
            'image' => (string)($cat['image'] ?? ($cat['media_url'] ?? '')),
        ];
    }
    njazTgRenderThumbnailCatalog($pdo, $tgUser, $items, 'أقسام الخدمات', 'اختر القسم المناسب للمتابعة.', 'categories', [], 'thumb:cats:', 'home', $page, $messageId);
}

function njazTgCategory(PDO $pdo, array $tgUser, int $categoryId, ?int $messageId = null, int $page = 1): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgCategoryEnabled($pdo, $categoryId)) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذا القسم غير متاح عبر البوت حالياً.'); return; }
    $stmt = $pdo->prepare("SELECT c.*,tbc.media_type,tbc.media_url,COALESCE(NULLIF(tbc.label_override,''),c.name) AS display_name FROM categories c LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id WHERE c.id=? AND c.status=1 LIMIT 1");
    $stmt->execute([$categoryId]); $cat = $stmt->fetch();
    if (!$cat) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'القسم غير متاح حالياً.'); return; }
    if (($cat['category_type'] ?? 'default') === 'telecom') {
        if (!njazTgOperationEnabled($pdo, 'allow_telecom')) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'قسم الاتصالات غير متاح عبر البوت حالياً.'); return; }
        $nets = [];
        try {
            $nets = $pdo->query("SELECT method_id,name_ar,name_en,icon,color,logo,transaction_type FROM floosak_agent_methods WHERE status=1 AND transaction_type='TOPUP' ORDER BY sort_order,id")->fetchAll();
        } catch (Throwable $e) {
            $fallback = $pdo->query("SELECT id AS method_id,name,icon,color,NULL AS logo,'TOPUP' AS transaction_type FROM telecom_networks WHERE status=1 ORDER BY sort_order")->fetchAll();
            foreach ($fallback as $row) $nets[] = ['method_id'=>$row['method_id'],'name_ar'=>$row['name'],'icon'=>$row['icon'],'color'=>$row['color'],'logo'=>$row['logo']];
        }
        $kb = [];
        foreach ($nets as $net) {
            $label = (string)($net['name_ar'] ?: $net['name_en'] ?: 'شبكة');
            $kb[] = [['text' => njazTgServiceButtonText('📶', $label), 'callback_data' => 'tnet:' . (int)$net['method_id']]];
        }
        $kb[] = [['text' => '↩️ رجوع للأقسام', 'callback_data' => 'menu:services']];
        $telecomTitle = (string)($cat['display_name'] ?? $cat['name']);
        if (function_exists('njazTgTranslateEntityText')) {
            $telecomTitle = njazTgTranslateEntityText($pdo, $telecomTitle, njazTgLanguageCode($pdo, $tgUser), 'entity:category:' . $categoryId . ':name');
        }
        $text = njazTgScreen('📡', njazTgClipLabel($telecomTitle), 'اختر الشبكة، ثم أرسل رقم الهاتف لفحصه وعرض الفئات والباقات والسلفة.');
        njazTgRenderSection($pdo, $tgUser, $text, $kb, $messageId, 'auto', !empty($cat['image']) ? $cat['image'] : ($cat['media_url'] ?? null));
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_network');
        return;
    }
    $sub = $pdo->prepare("SELECT c.*,tbc.icon_custom_emoji_id,COALESCE(NULLIF(tbc.label_override,''),c.name) AS display_name FROM categories c LEFT JOIN telegram_bot_categories tbc ON tbc.category_id=c.id WHERE c.parent_id=? AND c.status=1 AND COALESCE(tbc.enabled,1)=1 ORDER BY c.sort_order");
    $sub->execute([$categoryId]); $subCats = $sub->fetchAll();
    $catIds = array_merge([$categoryId], array_map('intval', array_column($subCats, 'id')));
    $in = implode(',', $catIds ?: [0]);
    $services = $pdo->query("SELECT s.*, c.name AS cat_name,tbs.icon_custom_emoji_id,COALESCE(NULLIF(tbs.label_override,''),s.name) AS display_name FROM services s JOIN categories c ON s.category_id=c.id LEFT JOIN telegram_bot_services tbs ON tbs.service_id=s.id WHERE s.category_id IN ($in) AND s.status=1 AND COALESCE(tbs.enabled,1)=1 ORDER BY c.sort_order,s.sort_order")->fetchAll();
    $items = [];
    foreach ($subCats as $sc) {
        $items[] = [
            'kind' => 'category',
            'id' => (int)$sc['id'],
            'name' => (string)($sc['display_name'] ?? $sc['name']),
            'icon' => njazTgCategoryIcon($sc),
            'style' => njazTgCategoryButtonStyle($sc),
            'icon_custom_emoji_id' => (string)($sc['icon_custom_emoji_id'] ?? ''),
            'image' => (string)($sc['image'] ?? ''),
        ];
    }
    foreach ($services as $svc) {
        $items[] = [
            'kind' => 'service',
            'id' => (int)$svc['id'],
            'name' => (string)($svc['display_name'] ?? $svc['name']),
            'price' => $svc['price'],
            'icon' => njazTgServiceIcon($svc),
            'style' => 'success',
            'icon_custom_emoji_id' => (string)($svc['icon_custom_emoji_id'] ?? ''),
            'image' => (string)($svc['image'] ?? ''),
        ];
    }
    njazTgRenderThumbnailCatalog(
        $pdo,
        $tgUser,
        $items,
        njazTgClipLabel($cat['display_name'] ?? $cat['name']),
        'اختر البطاقة المطلوبة. الأسعار الحالية من نظام نجاز.',
        'services',
        ['category_id' => $categoryId],
        'thumb:cat:' . $categoryId . ':',
        'menu:services',
        $page,
        $messageId
    );
}
