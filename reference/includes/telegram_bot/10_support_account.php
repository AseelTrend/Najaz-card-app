<?php

function njazTgSupportEnsureTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL, assigned_to INT DEFAULT NULL,
        status ENUM('open','closed','pending') DEFAULT 'pending',
        subject VARCHAR(255) DEFAULT NULL,
        unread_user INT DEFAULT 0, unread_staff INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id INT NOT NULL, sender_id INT NOT NULL,
        sender_type ENUM('user','staff','system') NOT NULL,
        message TEXT NOT NULL, is_read TINYINT(1) DEFAULT 0, is_auto TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE chat_messages MODIFY sender_type ENUM('user','staff','system') NOT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS chat_auto_replies (
        id INT AUTO_INCREMENT PRIMARY KEY, keywords VARCHAR(500) NOT NULL,
        reply TEXT NOT NULL, sort_order INT DEFAULT 0, status TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

function njazTgSupportStart(PDO $pdo, array $tgUser, ?int $orderId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if ((string)getSetting('chat_enabled') === '0') {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'المحادثة المباشرة غير متاحة حالياً. يمكنك مراجعة المساعدة وطلباتي، أو المحاولة لاحقاً.', [[['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
        return;
    }
    $data = [];
    if ($orderId && $orderId > 0) {
        $s = $pdo->prepare('SELECT id, ref_id FROM orders WHERE id=? AND user_id=? LIMIT 1');
        $s->execute([$orderId, (int)$tgUser['user_id']]); $order = $s->fetch();
        if (!$order) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'لا يمكن ربط الدعم بهذه العملية لأنها غير موجودة ضمن حسابك.'); return; }
        $data = ['order_id' => (int)$order['id'], 'order_ref' => (string)($order['ref_id'] ?: njazTgOrderRef($pdo, $order))];
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'support_message', $data);
        njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>🆘 دعم حول العملية ' . njazTgHtml($data['order_ref']) . '</b>\n\nاكتب المشكلة أو الاستفسار بالتفصيل، وسيظهر مباشرة في محادثات الدعم لدى فريق نجاز.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'support_ref', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], '<b>🆘 التواصل مع الدعم</b>\n\nأرسل رقم العملية الذي يبدأ بـ <code>ID</code> لربط رسالتك بها، أو أرسل كلمة <code>عام</code> لفتح استفسار عام.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
}

function njazTgSupportSubmit(PDO $pdo, array $tgUser, array $data, string $message): void {
    $message = trim($message);
    if (mb_strlen($message) < 5 || mb_strlen($message) > 3000) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'اكتب رسالتك بين 5 و3000 حرفاً حتى يتمكن فريق الدعم من مساعدتك.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
        return;
    }
    try {
        njazTgSupportEnsureTables($pdo);
        $orderRef = trim((string)($data['order_ref'] ?? ''));
        $subject = $orderRef !== '' ? 'دعم العملية ' . $orderRef : 'استفسار Telegram عام';
        $existing = $pdo->prepare("SELECT * FROM chats WHERE user_id=? AND status!='closed' ORDER BY updated_at DESC LIMIT 1");
        $existing->execute([(int)$tgUser['user_id']]); $chat = $existing->fetch();
        if (!$chat) {
            $pdo->prepare("INSERT INTO chats (user_id,subject,status) VALUES (?,?,'pending')")->execute([(int)$tgUser['user_id'], $subject]);
            $chatId = (int)$pdo->lastInsertId();
            $welcome = getSetting('chat_welcome') ?: 'مرحباً! 👋 شكراً لتواصلك معنا. سيرد عليك أحد موظفينا قريباً.';
            $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'staff',?,1)")->execute([$chatId, $welcome]);
        } else {
            $chatId = (int)$chat['id'];
            $pdo->prepare("UPDATE chats SET subject=?, status='open', updated_at=NOW() WHERE id=?")->execute([$subject, $chatId]);
        }
        $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message) VALUES (?,?,'user',?)")->execute([$chatId, (int)$tgUser['user_id'], $message]);
        $pdo->prepare("UPDATE chats SET status='open', unread_staff=unread_staff+1, updated_at=NOW() WHERE id=?")->execute([$chatId]);
        try {
            $autoReplies = $pdo->query("SELECT keywords,reply FROM chat_auto_replies WHERE status=1 ORDER BY sort_order")->fetchAll();
            foreach ($autoReplies as $ar) {
                foreach (array_map('trim', explode(',', (string)$ar['keywords'])) as $kw) {
                    if ($kw !== '' && mb_stripos($message, $kw) !== false) {
                        $pdo->prepare("INSERT INTO chat_messages (chat_id,sender_id,sender_type,message,is_auto) VALUES (?,0,'staff',?,1)")->execute([$chatId, $ar['reply']]);
                        $pdo->prepare("UPDATE chats SET unread_user=unread_user+1, updated_at=NOW() WHERE id=?")->execute([$chatId]);
                        break 2;
                    }
                }
            }
        } catch (Throwable $e) {}
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
        $label = $orderRef !== '' ? 'للعملية <code>' . njazTgHtml($orderRef) . '</code>' : 'لاستفسارك العام';
        njazTgSend($pdo, (int)$tgUser['chat_id'], '✅ تم إرسال رسالتك إلى فريق الدعم ' . $label . '.\n\nيمكنك متابعة الرد من نفس المحادثة أو إرسال رسالة جديدة من قسم المساعدة.', [[['text' => '🆘 المساعدة والدعم', 'callback_data' => 'menu:help'], ['text' => 'الرئيسية', 'callback_data' => 'home']]]);
    } catch (Throwable $e) {
        error_log('Telegram support submit failed: ' . $e->getMessage());
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'support_message', $data);
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر إرسال رسالتك حالياً. لم يتم تنفيذ أي عملية مالية. أرسل رسالتك مرة أخرى أو ألغِ المحادثة.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
    }
}

function njazTgHelp(PDO $pdo, array $tgUser): void {
    $body = '<b>كيف تستخدم البوت؟</b>\n\n<b>1.</b> اربط حسابك أو أنشئ حساباً جديداً.\n<b>2.</b> اختر الخدمة أو شحن الرصيد من القائمة.\n<b>3.</b> راجع الملخص قبل التأكيد.\n<b>4.</b> تابع النتيجة من طلباتي أو مدفوعاتي باستخدام رقم <code>ID</code>.\n\n<b>أوامر مفيدة</b>\n<code>/cancel</code> إلغاء الخطوة الحالية\n<code>/unlink</code> فصل الحساب\n<code>/notifications</code> تفضيلات الإشعارات\n<code>/favorites</code> الخدمات المفضلة\n\nلا ترسل كلمة المرور داخل محادثات الدعم أو لأي شخص آخر.';
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('📘', 'المساعدة والدعم', $body), [[['text' => '🆘 تواصل مع الدعم', 'callback_data' => 'support:start']], [['text' => '↩️ الرئيسية', 'callback_data' => 'home']]]);
}

function njazTgRegisterKeyboard(): array {
    return [[['text' => '✖️ إلغاء التسجيل', 'callback_data' => 'register:cancel']]];
}

function njazTgMaskEmail(string $email): string {
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) return '';
    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '') return '';
    $visible = mb_substr($local, 0, 2);
    return $visible . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
}

function njazTgPasswordChangeKeyboard(): array {
    return [
        [['text' => '✖️ إلغاء', 'callback_data' => 'profile:password_cancel']],
    ];
}

function njazTgPasswordChangeStart(PDO $pdo, array $tgUser): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if (!njazTgOperationEnabled($pdo, 'allow_profile')) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'إدارة الحساب غير متاحة عبر البوت حالياً.');
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'password_change_current', []);
    $body = 'لتغيير كلمة المرور، أرسل كلمة المرور الحالية أولاً.\n\n' .
        'بعدها ستُطلب كلمة المرور الجديدة وتأكيدها. لا تشارك كلمات المرور مع أي شخص؛ يتم حفظها مؤقتاً بشكل مشفّر أثناء العملية.';
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🔐', 'تغيير كلمة المرور', $body), njazTgPasswordChangeKeyboard());
}

function njazTgPasswordChangeSubmit(PDO $pdo, array $tgUser, string $state, array $data, string $text): void {
    $chatId = (int)$tgUser['chat_id'];
    $tgId = (int)$tgUser['telegram_id'];
    if (empty($tgUser['user_id'])) {
        njazTgState($pdo, $tgId, 'menu', []);
        njazTgSend($pdo, $chatId, 'يجب تسجيل الدخول أولاً لتغيير كلمة المرور.', njazTgNavKeyboard('home', 'الرئيسية'));
        return;
    }
    if (!njazTgRateLimit($pdo, $tgId, 'password_change', 5, 900)) {
        njazTgState($pdo, $tgId, 'menu', []);
        njazTgSend($pdo, $chatId, njazTgScreen('🛡️', 'حماية الحساب', 'تم إيقاف محاولات تغيير كلمة المرور مؤقتاً. حاول بعد 15 دقيقة.'), njazTgNavKeyboard('menu:profile', 'حسابي'));
        return;
    }
    $text = (string)$text;
    if ($state === 'password_change_current') {
        if ($text === '' || strlen($text) > 128) {
            njazTgSend($pdo, $chatId, 'كلمة المرور الحالية غير صالحة. أرسلها مرة أخرى أو اضغط إلغاء.', njazTgPasswordChangeKeyboard());
            return;
        }
        $data = ['current_password_enc' => njazTgEncryptSecret($pdo, $text)];
        njazTgState($pdo, $tgId, 'password_change_new', $data);
        njazTgSend($pdo, $chatId, njazTgScreen('🔐', 'كلمة المرور الجديدة', 'أرسل كلمة المرور الجديدة، على أن تكون بين 6 و128 حرفاً.'), njazTgPasswordChangeKeyboard());
        return;
    }
    if ($state === 'password_change_new') {
        if (strlen($text) < 6 || strlen($text) > 128) {
            njazTgSend($pdo, $chatId, 'كلمة المرور الجديدة يجب أن تكون بين 6 و128 حرفاً.', njazTgPasswordChangeKeyboard());
            return;
        }
        if (empty($data['current_password_enc'])) {
            njazTgState($pdo, $tgId, 'password_change_current', []);
            njazTgSend($pdo, $chatId, 'انتهت جلسة تغيير كلمة المرور. أرسل كلمة المرور الحالية من جديد.', njazTgPasswordChangeKeyboard());
            return;
        }
        $data['new_password_enc'] = njazTgEncryptSecret($pdo, $text);
        njazTgState($pdo, $tgId, 'password_change_confirm', $data);
        njazTgSend($pdo, $chatId, njazTgScreen('✅', 'تأكيد كلمة المرور', 'أعد إرسال كلمة المرور الجديدة نفسها للتأكيد.'), njazTgPasswordChangeKeyboard());
        return;
    }
    if ($state !== 'password_change_confirm') return;
    $currentPassword = njazTgDecryptSecret($pdo, (string)($data['current_password_enc'] ?? ''));
    $newPassword = njazTgDecryptSecret($pdo, (string)($data['new_password_enc'] ?? ''));
    if ($currentPassword === '' || $newPassword === '') {
        njazTgState($pdo, $tgId, 'password_change_current', []);
        njazTgSend($pdo, $chatId, 'انتهت جلسة تغيير كلمة المرور. أرسل كلمة المرور الحالية من جديد.', njazTgPasswordChangeKeyboard());
        return;
    }
    if (!hash_equals($newPassword, $text)) {
        unset($data['new_password_enc']);
        njazTgState($pdo, $tgId, 'password_change_new', $data);
        njazTgSend($pdo, $chatId, 'كلمتا المرور غير متطابقتين. أرسل كلمة المرور الجديدة مرة أخرى.', njazTgPasswordChangeKeyboard());
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id=? AND status=1 AND (is_deleted=0 OR is_deleted IS NULL) LIMIT 1');
        $stmt->execute([(int)$tgUser['user_id']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($currentPassword, (string)($user['password'] ?? ''))) {
            njazTgState($pdo, $tgId, 'password_change_current', []);
            njazTgSend($pdo, $chatId, 'كلمة المرور الحالية غير صحيحة. أرسلها مرة أخرى.', njazTgPasswordChangeKeyboard());
            return;
        }
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($hashed) || $hashed === '') throw new RuntimeException('Password hash failed');
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hashed, (int)$tgUser['user_id']]);
        njazTgState($pdo, $tgId, 'menu', []);
        njazTgSend($pdo, $chatId, njazTgScreen('✅', 'تم تغيير كلمة المرور', 'تم تحديث كلمة مرور حسابك بنجاح. استخدم كلمة المرور الجديدة عند تسجيل الدخول إلى الموقع أو البوت.'), [
            [['text' => '👤 حسابي', 'callback_data' => 'menu:profile'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
        ]);
    } catch (Throwable $e) {
        error_log('Telegram password change failed: ' . $e->getMessage());
        njazTgState($pdo, $tgId, 'password_change_current', []);
        njazTgSend($pdo, $chatId, 'تعذر تحديث كلمة المرور حالياً. لم يتم تغييرها؛ حاول مرة أخرى لاحقاً.', njazTgPasswordChangeKeyboard());
    }
}

function njazTgLoginKeyboard(): array {
    return [
        [['text' => '🔑 نسيت كلمة المرور', 'callback_data' => 'login:forgot']],
        [['text' => '📧 تأكيد الجهاز', 'callback_data' => 'login:device']],
        [['text' => '✖️ إلغاء تسجيل الدخول', 'callback_data' => 'login:cancel']]
    ];
}

function njazTgSyncUserChat(PDO $pdo, int $userId, int $chatId): void {
    try {
        $pdo->prepare('UPDATE users SET telegram_chat_id=? WHERE id=?')->execute([$chatId, $userId]);
    } catch (Throwable $e) {
        error_log('Telegram chat sync failed: ' . $e->getMessage());
    }
}

function njazTgLoginStart(PDO $pdo, array $tgUser): void {
    if (!empty($tgUser['user_id'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('👤', 'الحساب مرتبط', 'هذا الحساب مرتبط مسبقاً. استخدم القائمة الرئيسية للوصول إلى خدماتك.'), njazTgNavKeyboard('home', 'الرئيسية'));
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_email', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🔐', 'تسجيل الدخول', 'الخطوة 1 من 2\n\nأرسل البريد الإلكتروني أو اسم المستخدم المسجل في موقع نجاز كارد.'), njazTgLoginKeyboard());
}

function njazTgLoginForgotStart(PDO $pdo, array $tgUser): void {
    if (!empty($tgUser['user_id'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('👤', 'الحساب مرتبط', 'أنت مسجل الدخول بالفعل. استخدم القائمة الرئيسية للوصول إلى حسابك.'), njazTgNavKeyboard('home', 'الرئيسية'));
        return;
    }
    if ((string)getSetting('password_reset_enabled') === '0') {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'غير متاح', 'ميزة استعادة كلمة المرور معطّلة حالياً. تواصل مع الدعم.'), njazTgLoginKeyboard());
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_forgot_email', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🔑', 'استعادة كلمة المرور', "أرسل البريد الإلكتروني أو اسم المستخدم المسجل في موقع نجاز كارد، وسنرسل كلمة مرور جديدة إلى بريد الحساب."), njazTgLoginKeyboard());
}

function njazTgLoginForgotExecute(PDO $pdo, array $tgUser, string $identifier): void {
    $chatId = (int)$tgUser['chat_id'];
    $telegramId = (int)$tgUser['telegram_id'];
    if (!njazTgRateLimit($pdo, $telegramId, 'forgot', 3, 900)) {
        njazTgSend($pdo, $chatId, njazTgScreen('🛡️', 'حماية الحساب', 'تم إيقاف محاولات الاستعادة مؤقتاً. حاول بعد 15 دقيقة.'), njazTgLoginKeyboard());
        return;
    }

    $identifier = trim($identifier);
    $stmt = $pdo->prepare('SELECT id, username, email, full_name, last_password_reset_at FROM users WHERE (email=? OR username=?) AND status=1 AND (is_deleted=0 OR is_deleted IS NULL) LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'غير موجود', 'لم يتم العثور على حساب فعال بهذا البريد أو اسم المستخدم.'), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_forgot_email', []);
        return;
    }

    $cooldownHours = (int)(getSetting('password_reset_cooldown_hours') ?: 24);
    if ($cooldownHours < 1) $cooldownHours = 24;
    $lastReset = (int)($user['last_password_reset_at'] ?? 0);
    if ($lastReset > 0 && (time() - $lastReset) < ($cooldownHours * 3600)) {
        njazTgSend($pdo, $chatId, njazTgScreen('⏳', 'انتظر قليلاً', "تم إرسال كلمة مرور جديدة لهذا الحساب خلال آخر {$cooldownHours} ساعة. راجع بريدك الإلكتروني."), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_email', []);
        return;
    }

    $siteName = getSetting('site_name') ?: SITE_NAME;
    $displayName = (string)($user['full_name'] ?: $user['username']);
    $newPassword = generateStrongPassword(12);
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $subject = buildResetEmailSubject($siteName);
    $htmlBody = buildResetEmailHtml($siteName, $displayName, $newPassword, SITE_URL . '/login.php');
    $sent = false;
    $lastError = '';

    try {
        $mailer = getSmtpMailerForSection($pdo, 'reset');
        $sent = (bool)$mailer->send($user['email'], $displayName, $subject, $htmlBody);
        $lastError = (string)($mailer->lastError ?? '');
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
    }

    try {
        $pdo->prepare('INSERT INTO password_reset_logs (user_id, username, email, ip, status, error_message) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$user['id'], $user['username'], $user['email'], 'telegram', $sent ? 'sent' : 'failed', $sent ? null : mb_substr($lastError, 0, 490)]);
    } catch (Throwable $e) {}

    if ($sent) {
        $pdo->prepare('UPDATE users SET password=?, last_password_reset_at=? WHERE id=?')->execute([$hashed, time(), (int)$user['id']]);
        $hint = njazTgMaskEmail((string)$user['email']);
        njazTgSend($pdo, $chatId, njazTgScreen('✅', 'تم الإرسال', "تم إرسال كلمة مرور جديدة إلى <b>" . njazTgHtml($hint) . "</b>\n\nراجع صندوق الوارد وسجّل الدخول بالكلمة الجديدة."), njazTgLoginKeyboard());
    } else {
        njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'تعذر الإرسال', 'تعذّر إرسال البريد الإلكتروني حالياً. لم يتم تغيير كلمة المرور. حاول لاحقاً أو تواصل مع الدعم.'), njazTgLoginKeyboard());
    }
    njazTgState($pdo, $telegramId, 'login_email', []);
}

function njazTgLoginDeviceKeyboard(): array {
    return [
        [['text' => '📧 إعادة إرسال بريد التأكيد', 'callback_data' => 'login:device_resend']],
        [['text' => '✖️ إلغاء', 'callback_data' => 'login:cancel']]
    ];
}

function njazTgLoginDeviceSend(PDO $pdo, array $tgUser, string $identifier, bool $rateLimit = true): void {
    $chatId = (int)$tgUser['chat_id'];
    $telegramId = (int)$tgUser['telegram_id'];
    $identifier = trim($identifier);
    if ($rateLimit && !njazTgRateLimit($pdo, $telegramId, 'device_resend', 3, 900)) {
        njazTgSend($pdo, $chatId, njazTgScreen('🛡️', 'حماية الحساب', 'تم إيقاف إعادة إرسال رسائل تأكيد الجهاز مؤقتاً. حاول بعد 15 دقيقة.'), njazTgLoginKeyboard());
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE (email=? OR username=?) AND status=1 AND (is_deleted=0 OR is_deleted IS NULL) LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    if (!$user) {
        njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'انتهت الجلسة', 'لم يتم العثور على الحساب. أعد تسجيل الدخول ثم حاول مرة أخرى.'), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_email', []);
        return;
    }

    $deviceId = 'tg_' . substr(hash('sha256', (string)$telegramId), 0, 40);
    $devStmt = $pdo->prepare('SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=? LIMIT 1');
    $devStmt->execute([(int)$user['id'], $deviceId]);
    $deviceRow = $devStmt->fetch();
    if (!$deviceRow) {
        njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'لم يُعثر على الجهاز', 'لم يتم العثور على جلسة جهاز معلّقة. أعد تسجيل الدخول من البداية.'), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_email', []);
        return;
    }
    if ((string)$deviceRow['status'] === 'blocked') {
        njazTgSend($pdo, $chatId, njazTgScreen('🚫', 'الجهاز محظور', 'هذا الجهاز محظور من النظام. تواصل مع الإدارة للمساعدة.'), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_email', []);
        return;
    }
    if ((string)$deviceRow['status'] === 'approved') {
        njazTgSend($pdo, $chatId, njazTgScreen('✅', 'الجهاز معتمد', 'هذا الجهاز معتمد بالفعل. أعد تسجيل الدخول لإكمال ربط الحساب.'), njazTgLoginKeyboard());
        njazTgState($pdo, $telegramId, 'login_email', []);
        return;
    }

    $sent = false;
    try {
        $sent = sendDeviceConfirmEmail($pdo, $user, (int)$deviceRow['id'], [
            'device_name' => $deviceRow['device_name'] ?? 'Telegram',
            'browser' => $deviceRow['browser'] ?? 'Telegram',
            'os' => $deviceRow['os'] ?? '—',
            'ip_address' => $deviceRow['ip_address'] ?? '—',
        ]);
    } catch (Throwable $e) {
        error_log('Telegram device confirmation failed: ' . $e->getMessage());
    }

    $hint = njazTgMaskEmail((string)($user['email'] ?? ''));
    $body = $sent
        ? "📧 تم إرسال رابط تأكيد الجهاز إلى <b>" . njazTgHtml($hint) . "</b>\n\nافتح البريد واضغط «تصريح الجهاز — هذا أنا»، ثم أعد تسجيل الدخول من Telegram."
        : 'لم تتم إعادة الإرسال الآن؛ قد يكون بريد التأكيد أُرسل قبل دقائق أو تعذر الاتصال بالبريد. راجع صندوق الوارد، ثم حاول لاحقاً.';
    njazTgState($pdo, $telegramId, 'login_device_pending', ['identifier' => $identifier]);
    njazTgSend($pdo, $chatId, njazTgScreen($sent ? '📧' : '⚠️', 'تأكيد الجهاز', $body), njazTgLoginDeviceKeyboard());
}

function njazTgLoginDeviceStart(PDO $pdo, array $tgUser): void {
    if (!empty($tgUser['user_id'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('👤', 'الحساب مرتبط', 'أنت مسجل الدخول بالفعل. استخدم القائمة الرئيسية للوصول إلى حسابك.'), njazTgNavKeyboard('home', 'الرئيسية'));
        return;
    }
    $data = njazTgStateData($tgUser);
    $identifier = trim((string)($data['identifier'] ?? $data['email'] ?? ''));
    if ($identifier !== '') {
        njazTgLoginDeviceSend($pdo, $tgUser, $identifier, true);
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_device_pending', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('📧', 'تأكيد الجهاز', 'أرسل البريد الإلكتروني أو اسم المستخدم الذي حاولت الدخول به، وسنرسل رابط اعتماد جهاز Telegram إلى بريد الحساب.'), njazTgLoginKeyboard());
}

function njazTgLoginFinish(PDO $pdo, array $tgUser, array $data, string $totpCode = ''): void {
    if (!njazTgRateLimit($pdo, (int)$tgUser['telegram_id'], 'login', 8, 300)) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🛡️', 'حماية الحساب', 'تم إيقاف محاولات تسجيل الدخول مؤقتاً لحماية الحساب. حاول بعد بضع دقائق.'), njazTgLoginKeyboard());
        return;
    }
    // يقبل التدفق القديم المفتاح email للتوافق مع الحالات المحفوظة، والجديد identifier للبريد أو اسم المستخدم.
    $identifier = trim((string)($data['identifier'] ?? $data['email'] ?? ''));
    $password = njazTgDecryptSecret($pdo, (string)($data['password_enc'] ?? ''));
    if ($identifier === '' || mb_strlen($identifier) > 190 || $password === '') {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'بيانات تسجيل الدخول غير صحيحة. أعد المحاولة من البداية.', njazTgLoginKeyboard());
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_email');
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE (email=? OR username=?) AND status=1 AND (is_deleted=0 OR is_deleted IS NULL) LIMIT 1');
    $stmt->execute([$identifier, $identifier]); $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string)$user['password'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'تعذر تسجيل الدخول', 'البريد الإلكتروني أو اسم المستخدم مع كلمة المرور غير صحيحة.\n\nيمكنك المحاولة مرة أخرى أو الضغط على إلغاء للعودة.'), njazTgLoginKeyboard());
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_email');
        return;
    }
    if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        if ($totpCode === '') {
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_2fa', ['identifier' => $identifier, 'password_enc' => $data['password_enc']]);
            njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🛡️', 'تحقق إضافي', 'أدخل رمز المصادقة الثنائية من تطبيق Authenticator لإكمال تسجيل الدخول.'), njazTgLoginKeyboard());
            return;
        }
        require_once NJAZ_TG_INCLUDES . '/totp.php';
        if (!TOTP::verify($user['totp_secret'], $totpCode)) {
            njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'رمز غير صحيح', 'رمز المصادقة الثنائية غير صحيح أو منتهي الصلاحية. أرسل رمزاً جديداً.'), njazTgLoginKeyboard());
            return;
        }
    }
    $deviceId = 'tg_' . substr(hash('sha256', (string)$tgUser['telegram_id']), 0, 40);
    $autoApprove = (bool)getSetting('device_auto_approve');
    $deviceStatus = checkAndRegisterDevice($pdo, (int)$user['id'], $autoApprove, $deviceId);
    if ($deviceStatus === 'blocked') {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🚫', 'الجهاز محظور', 'هذا الجهاز محظور من النظام. تواصل مع الإدارة للمساعدة.'), njazTgLoginKeyboard()); return;
    }
    if ($deviceStatus === 'pending') {
        $emailSent = false;
        $devStmt = $pdo->prepare('SELECT * FROM user_devices WHERE user_id=? AND device_fingerprint=? LIMIT 1');
        $devStmt->execute([(int)$user['id'], $deviceId]);
        $deviceRow = $devStmt->fetch();
        if ($deviceRow) {
            try {
                $emailSent = sendDeviceConfirmEmail($pdo, $user, (int)$deviceRow['id'], [
                    'device_name' => $deviceRow['device_name'] ?? 'Telegram',
                    'browser' => $deviceRow['browser'] ?? 'Telegram',
                    'os' => $deviceRow['os'] ?? '—',
                    'ip_address' => $deviceRow['ip_address'] ?? '—',
                ]);
            } catch (Throwable $e) {
                error_log('Telegram device confirmation failed: ' . $e->getMessage());
            }
        }
        $emailHint = njazTgMaskEmail((string)($user['email'] ?? ''));
        $body = 'تم التحقق من كلمة المرور، لكن يلزم اعتماد جهاز Telegram قبل المتابعة.';
        if ($emailSent && $emailHint !== '') {
            $body .= "\n\n📧 تم إرسال رابط التأكيد إلى <b>" . njazTgHtml($emailHint) . "</b>\nافتح البريد واضغط «تصريح الجهاز — هذا أنا»، ثم أعد تسجيل الدخول.";
        } else {
            $body .= "\n\nراجع بريدك الوارد إن كان الرابط قد أُرسل مؤخراً، أو حاول إعادة الإرسال بعد قليل.";
        }
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_device_pending', ['identifier' => $identifier]);
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⏳', 'اعتماد الجهاز', $body), njazTgLoginDeviceKeyboard());
        return;
    }
    njazTgSyncUserChat($pdo, (int)$user['id'], (int)$tgUser['chat_id']);
    $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([(int)$user['id']]);
    $pdo->prepare("UPDATE telegram_users SET user_id=?,state='menu',state_data=NULL WHERE telegram_id=?")
        ->execute([(int)$user['id'], (int)$tgUser['telegram_id']]);
    try { sendNotification($pdo, (int)$user['id'], 'system', '🔐 تم تسجيل الدخول', 'تم تسجيل الدخول إلى حسابك عبر Telegram بنجاح.', 'sign-in-alt', '#6c3fe0'); } catch (Throwable $e) {}
    $name = $user['full_name'] ?: $user['username'];
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'مرحباً بعودتك ' . $name, 'تم تسجيل الدخول وربط حسابك بنجاح.\n\n💰 رصيدك الحالي: <b>' . njazTgMoney($user['balance'] ?? 0) . '</b>'), njazTgMainKeyboard($pdo));
}


function njazTgRegisterStart(PDO $pdo, array $tgUser): void {
    if (!empty($tgUser['user_id'])) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('👤', 'الحساب مرتبط', 'هذا الحساب مرتبط مسبقاً بحساب نجاز.\n\nلفصل الحساب الحالي وربط حساب آخر استخدم الأمر:\n<code>/unlink</code>'), njazTgNavKeyboard('home', 'الرئيسية'));
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_username', []);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🆕', 'إنشاء حساب جديد', 'سننشئ لك حساباً حقيقياً في نجاز كارد، وسيظهر مباشرة في الموقع بنفس المحفظة والطلبات.\n\n<b>الخطوة 1</b>\nأرسل اسم المستخدم باللغة الإنجليزية (3 أحرف على الأقل).'), njazTgRegisterKeyboard());
}

function njazTgRegisterPrompt(PDO $pdo, array $tgUser, string $state, string $text): void {
    njazTgState($pdo, (int)$tgUser['telegram_id'], $state, njazTgStateData($tgUser));
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('📝', 'بيانات الحساب', $text), njazTgRegisterKeyboard());
}

function njazTgRegisterFinish(PDO $pdo, array $tgUser, array $data): void {
    $username = trim((string)($data['username'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $email = trim((string)($data['email'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));
    $password = njazTgDecryptSecret($pdo, (string)($data['password_enc'] ?? ''));
    $referralCode = strtoupper(trim((string)($data['referral_code'] ?? '')));

    if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) { njazTgRegisterStart($pdo, $tgUser); return; }
    if (mb_strlen($fullName) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\+?[0-9]{6,20}$/', $phone) || strlen($password) < 6) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'بيانات غير مكتملة', 'بعض البيانات غير صحيحة. أعد التسجيل من البداية وسنراجع كل خطوة معك.'), njazTgRegisterKeyboard());
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_username');
        return;
    }
    $duplicate = $pdo->prepare('SELECT id FROM users WHERE username=? OR email=? LIMIT 1');
    $duplicate->execute([$username, $email]);
    if ($duplicate->fetch()) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'اسم المستخدم أو البريد الإلكتروني مستخدم مسبقاً. أرسل اسم مستخدم آخر للمتابعة.');
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_username', []);
        return;
    }

    try {
        if (function_exists('apiRateLimit')) apiRateLimit($pdo, 'telegram_register', 5, 300, false);
        $hasRegistrationSource = false;
        try {
            $cols = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
            if (!in_array('display_name', $cols, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `full_name`');
            }
            if (!in_array('registration_source', $cols, true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN `registration_source` VARCHAR(50) NULL DEFAULT NULL');
            }
            $hasRegistrationSource = true;
        } catch (Throwable $e) {}

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hasRegistrationSource) {
            $pdo->prepare('INSERT INTO users (username,email,password,full_name,display_name,phone,registration_source) VALUES (?,?,?,?,?,?,?)')
                ->execute([$username, $email, $hash, $fullName, $fullName, $phone, 'telegram']);
        } else {
            $pdo->prepare('INSERT INTO users (username,email,password,full_name,display_name,phone) VALUES (?,?,?,?,?,?)')
                ->execute([$username, $email, $hash, $fullName, $fullName, $phone]);
        }
        $newId = (int)$pdo->lastInsertId();
        $deviceId = 'tg_' . substr(hash('sha256', (string)$tgUser['telegram_id']), 0, 40);
        checkAndRegisterDevice($pdo, $newId, true, $deviceId);
        if (function_exists('generateReferralCode')) generateReferralCode($pdo, $newId);
        if ($referralCode && function_exists('applyReferralOnRegister')) applyReferralOnRegister($pdo, $newId, $referralCode);
        $pdo->prepare("UPDATE telegram_users SET user_id=?,state='menu',state_data=NULL WHERE telegram_id=?")
            ->execute([$newId, (int)$tgUser['telegram_id']]);
        njazTgSyncUserChat($pdo, $newId, (int)$tgUser['chat_id']);
        try {
            sendNotification($pdo, $newId, 'system', '🎉 مرحباً بك في ' . (getSetting('site_name') ?: SITE_NAME), 'يسعدنا انضمامك! يمكنك الآن تصفح الخدمات وطلبها بكل سهولة.', 'gift', '#6c3fe0');
            notifyAdminNewRegister($pdo, $username, $email);
        } catch (Throwable $e) { error_log('Telegram registration notification failed: ' . $e->getMessage()); }
        $fresh = array_merge($tgUser, ['user_id' => $newId]);
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'تم إنشاء الحساب', 'تم إنشاء حسابك وربطه تلقائياً بنجاح.\n\nاسم المستخدم: <code>' . njazTgHtml($username) . '</code>\nرقم الحساب: <code>' . str_pad((string)$newId, 6, '0', STR_PAD_LEFT) . '</code>\n\nيمكنك استخدام الحساب الآن من الموقع والبوت.'), njazTgMainKeyboard($pdo));
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu');
    } catch (Throwable $e) {
        error_log('Telegram registration failed: ' . $e->getMessage());
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'تعذر إنشاء الحساب', 'تعذر إنشاء الحساب حالياً. تحقق من البيانات وحاول مرة أخرى لاحقاً.'), njazTgRegisterKeyboard());
    } finally {
        $password = '';
    }
}
