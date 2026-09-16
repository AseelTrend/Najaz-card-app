<?php

function njazTgHandleMessage(PDO $pdo, array $message): void {
    $chatId = (int)($message['chat']['id'] ?? 0); $from = $message['from'] ?? []; $tgUser = njazTgUser($pdo, $from, $chatId); if (!empty($tgUser['is_blocked'])) return; njazTgDisplayCurrencySetContext($pdo, $tgUser); $text = trim((string)($message['text'] ?? ''));
    // بوابة الاشتراك تُفحص قبل أي أمر أو رسالة أو عملية داخل البوت.
    if (!njazTgSubscriptionAllowed($pdo, $tgUser, true)) return;
    if (isset($message['photo']) && is_array($message['photo'])) {
        if (in_array((string)($tgUser['state'] ?? ''), ['kyc_image_front', 'kyc_image_back'], true)) {
            njazTgKycHandlePhoto($pdo, $tgUser, $message, njazTgStateData($tgUser));
        } elseif (($tgUser['state'] ?? '') === 'topup_receipt') {
            $data = njazTgStateData($tgUser); $last = end($message['photo']); $path = njazTgDownloadReceipt($pdo, (string)($last['file_id'] ?? ''), (int)$tgUser['user_id']);
            if (!$path) { njazTgSend($pdo, $chatId, 'تعذر حفظ الإيصال أو حجمه أكبر من 5MB. أعد الإرسال كصورة.'); return; }
            njazTgCreateTopup($pdo, $tgUser, $data, $path, (string)($message['caption'] ?? ''));
        } elseif (($tgUser['state'] ?? '') === 'topup_card') {
            njazTgSend($pdo, $chatId, 'هذا المسار مخصص لكود البطاقة. أرسل الكود كنص، وليس كصورة.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        } elseif (($tgUser['state'] ?? '') === 'usdt_tx') {
            njazTgSend($pdo, $chatId, 'هذا المسار يحتاج TXID كنص يبدأ بـ <code>0x</code>، وليس صورة.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]);
        } else njazTgSend($pdo, $chatId, 'أرسل صورة الإيصال بعد اختيار طريقة الدفع والعملة والمبلغ.');
        return;
    }
    if ($text === '/start' || $text === '/menu') { njazTgHome($pdo, $tgUser); return; }
    if ($text === '/notifications') { njazTgNotificationPrefs($pdo, $tgUser); return; }
    if ($text === '/favorites') { njazTgQuickServices($pdo, $tgUser, 'favorites'); return; }
    if ($text === '/recent') { njazTgQuickServices($pdo, $tgUser, 'recent'); return; }
    if (preg_match('/^\\/search(?:\\s+(.+))?$/u', $text, $searchMatch)) {
        if (!empty($searchMatch[1])) njazTgSearch($pdo, $tgUser, $searchMatch[1]); else njazTgSearchStart($pdo, $tgUser);
        return;
    }
    if ($text === '/unlink') {
        if (empty($tgUser['user_id'])) {
            njazTgSend($pdo, $chatId, 'لا يوجد حساب نجاز مرتبط بهذه المحادثة.');
        } else {
            njazTgSend($pdo, $chatId, '<b>تأكيد فصل الحساب</b>\n\nسيتم إيقاف إشعارات الموقع لهذه المحادثة، ولن يتم حذف حسابك أو طلباتك أو رصيدك.', [[['text' => '✅ نعم، افصل الحساب', 'callback_data' => 'unlink:confirm'], ['text' => 'إلغاء', 'callback_data' => 'unlink:cancel']]]);
        }
        return;
    }
    if (preg_match('/^\/link\s+(\S+)\s+(\S+)$/u', $text, $m)) { njazTgLink($pdo, $tgUser, $m[1], $m[2]); return; }
    $state = $tgUser['state'] ?? 'menu'; $data = njazTgStateData($tgUser);
    if ($text === '/cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu'); njazTgHome($pdo, $tgUser); return; }
    if ($state === 'kyc_full_name') {
        if (mb_strlen($text) < 2 || mb_strlen($text) > 150) { njazTgSend($pdo, $chatId, 'أرسل الاسم الكامل كما هو في الوثيقة، بين حرفين و150 حرفاً.'); return; }
        $data['full_name'] = $text; njazTgKycPrompt($pdo, $tgUser, 'kyc_national_id', $data, 'أرسل رقم الهوية أو الرقم الموجود في الوثيقة.'); return;
    }
    if ($state === 'kyc_national_id') {
        if (mb_strlen($text) < 3 || mb_strlen($text) > 100) { njazTgSend($pdo, $chatId, 'رقم الهوية غير صحيح. أرسله كما هو مكتوب في الوثيقة.'); return; }
        $data['national_id'] = $text; njazTgKycPrompt($pdo, $tgUser, 'kyc_birth_date', $data, 'أرسل تاريخ الميلاد بالصيغة <code>YYYY-MM-DD</code>، مثل <code>1995-08-20</code>.'); return;
    }
    if ($state === 'kyc_birth_date') {
        $date = trim($text); $valid = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) === 1;
        if ($valid) { [$y, $m, $d] = array_map('intval', explode('-', $date)); $valid = checkdate($m, $d, $y); }
        if (!$valid) { njazTgSend($pdo, $chatId, 'التاريخ غير صحيح. أرسله بالصيغة <code>YYYY-MM-DD</code>.'); return; }
        $data['birth_date'] = $date; njazTgKycPrompt($pdo, $tgUser, 'kyc_birth_place', $data, 'أرسل مكان الميلاد، أو أرسل <code>/skip</code> إذا لم ترغب بإضافته.'); return;
    }
    if ($state === 'kyc_birth_place') {
        $data['birth_place'] = $text === '/skip' ? '' : $text; njazTgKycPrompt($pdo, $tgUser, 'kyc_issue_date', $data, 'أرسل تاريخ إصدار الوثيقة بالصيغة <code>YYYY-MM-DD</code>.'); return;
    }
    if ($state === 'kyc_issue_date') {
        $date = trim($text); $valid = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) === 1;
        if ($valid) { [$y, $m, $d] = array_map('intval', explode('-', $date)); $valid = checkdate($m, $d, $y); }
        if (!$valid) { njazTgSend($pdo, $chatId, 'تاريخ الإصدار غير صحيح. أرسله بالصيغة <code>YYYY-MM-DD</code>.'); return; }
        $data['issue_date'] = $date; njazTgKycPrompt($pdo, $tgUser, 'kyc_expiry_date', $data, 'أرسل تاريخ انتهاء الوثيقة بالصيغة <code>YYYY-MM-DD</code>.'); return;
    }
    if ($state === 'kyc_expiry_date') {
        $date = trim($text); $valid = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) === 1;
        if ($valid) { [$y, $m, $d] = array_map('intval', explode('-', $date)); $valid = checkdate($m, $d, $y); }
        if (!$valid) { njazTgSend($pdo, $chatId, 'تاريخ الانتهاء غير صحيح. أرسله بالصيغة <code>YYYY-MM-DD</code>.'); return; }
        $data['expiry_date'] = $date; njazTgKycPrompt($pdo, $tgUser, 'kyc_image_front', $data, 'أرسل صورة واضحة للوجه الأمامي للوثيقة.'); return;
    }
    if (in_array($state, ['kyc_id_type', 'kyc_image_front', 'kyc_image_back', 'kyc_review'], true)) {
        njazTgSend($pdo, $chatId, 'استخدم الأزرار الظاهرة أو أرسل الصورة المطلوبة للمتابعة.', [[['text' => '✖️ إلغاء', 'callback_data' => 'profile:kyc:cancel']]]); return;
    }
    if ($state === 'login_email') {
        $identifier = trim($text);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $isUsername = preg_match('/^[a-zA-Z0-9_]{3,64}$/', $identifier) === 1;
        if ($identifier === '' || mb_strlen($identifier) > 190 || (!$isEmail && !$isUsername)) {
            njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'بيانات الدخول', 'أرسل البريد الإلكتروني أو اسم المستخدم المسجل في موقع نجاز كارد.'), njazTgLoginKeyboard()); return;
        }
        $data['identifier'] = $identifier; unset($data['email']);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_password', $data);
        njazTgSend($pdo, $chatId, njazTgScreen('🔐', 'تسجيل الدخول', 'الخطوة 2 من 2\n\nأرسل كلمة المرور الخاصة بحساب الموقع.'), njazTgLoginKeyboard()); return;
    }
    if ($state === 'login_password') {
        if ($text === '') { njazTgSend($pdo, $chatId, njazTgScreen('🔐', 'كلمة المرور', 'أرسل كلمة المرور للمتابعة.'), njazTgLoginKeyboard()); return; }
        $data['password_enc'] = njazTgEncryptSecret($pdo, $text); njazTgLoginFinish($pdo, $tgUser, $data); return;
    }
    if ($state === 'login_2fa') {
        $code = preg_replace('/\s+/', '', $text);
        if (!preg_match('/^\d{6}$/', $code)) { njazTgSend($pdo, $chatId, 'أرسل رمز المصادقة الثنائية المكوّن من 6 أرقام:', njazTgLoginKeyboard()); return; }
        njazTgLoginFinish($pdo, $tgUser, $data, $code); return;
    }
    if ($state === 'login_forgot_email') {
        $identifier = trim($text);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $isUsername = preg_match('/^[a-zA-Z0-9_]{3,64}$/', $identifier) === 1;
        if ($identifier === '' || mb_strlen($identifier) > 190 || (!$isEmail && !$isUsername)) {
            njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'بيانات غير صحيحة', 'أرسل البريد الإلكتروني أو اسم المستخدم المسجل في موقع نجاز كارد.'), njazTgLoginKeyboard());
            return;
        }
        njazTgLoginForgotExecute($pdo, $tgUser, $identifier);
        return;
    }
    if ($state === 'login_device_pending') {
        $identifier = trim($text);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        $isUsername = preg_match('/^[a-zA-Z0-9_]{3,64}$/', $identifier) === 1;
        if ($identifier === '' || mb_strlen($identifier) > 190 || (!$isEmail && !$isUsername)) {
            njazTgSend($pdo, $chatId, njazTgScreen('⚠️', 'بيانات غير صحيحة', 'أرسل البريد الإلكتروني أو اسم المستخدم الذي حاولت الدخول به.'), njazTgLoginKeyboard());
            return;
        }
        njazTgLoginDeviceSend($pdo, $tgUser, $identifier, true);
        return;
    }
    if (in_array($state, ['password_change_current', 'password_change_new', 'password_change_confirm'], true)) {
        njazTgPasswordChangeSubmit($pdo, $tgUser, (string)$state, $data, $text);
        return;
    }
    if ($state === 'register_username') {
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $text)) { njazTgSend($pdo, $chatId, 'اسم المستخدم يجب أن يكون من 3 إلى 32 حرفاً، وبأحرف إنجليزية أو أرقام أو _ فقط.', njazTgRegisterKeyboard()); return; }
        $check = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1'); $check->execute([$text]);
        if ($check->fetch()) { njazTgSend($pdo, $chatId, 'اسم المستخدم مستخدم مسبقاً. أرسل اسماً آخر.', njazTgRegisterKeyboard()); return; }
        $data['username'] = $text; njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_full_name', $data);
        njazTgSend($pdo, $chatId, 'أرسل اسمك الكامل:', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_full_name') {
        if (mb_strlen($text) < 2 || mb_strlen($text) > 100) { njazTgSend($pdo, $chatId, 'الاسم الكامل يجب أن يكون بين حرفين و100 حرف.', njazTgRegisterKeyboard()); return; }
        $data['full_name'] = $text; njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_email', $data);
        njazTgSend($pdo, $chatId, 'أرسل بريدك الإلكتروني:', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_email') {
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) { njazTgSend($pdo, $chatId, 'البريد الإلكتروني غير صحيح. أرسله بصيغة مثل: name@example.com', njazTgRegisterKeyboard()); return; }
        $check = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $check->execute([$text]);
        if ($check->fetch()) { njazTgSend($pdo, $chatId, 'البريد الإلكتروني مستخدم مسبقاً. أرسل بريداً آخر.', njazTgRegisterKeyboard()); return; }
        $data['email'] = $text; njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_phone', $data);
        njazTgSend($pdo, $chatId, 'أرسل رقم الهاتف مع مفتاح الدولة، مثل: <code>+9665XXXXXXXX</code>', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_phone') {
        $phone = preg_replace('/[\s\-().]/', '', $text);
        if (!preg_match('/^\+?[0-9]{6,20}$/', $phone)) { njazTgSend($pdo, $chatId, 'رقم الهاتف غير صحيح. أرسله مع مفتاح الدولة، مثل: <code>+9665XXXXXXXX</code>', njazTgRegisterKeyboard()); return; }
        $data['phone'] = $phone; njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_password', $data);
        njazTgSend($pdo, $chatId, 'أرسل كلمة مرور لا تقل عن 6 أحرف. لن يتم حفظها كنص مكشوف داخل النظام.', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_password') {
        if (strlen($text) < 6 || strlen($text) > 128) { njazTgSend($pdo, $chatId, 'كلمة المرور يجب أن تكون بين 6 و128 حرفاً.', njazTgRegisterKeyboard()); return; }
        $data['password_enc'] = njazTgEncryptSecret($pdo, $text); njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_password_confirm', $data);
        njazTgSend($pdo, $chatId, 'أعد إرسال كلمة المرور للتأكيد:', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_password_confirm') {
        $password = njazTgDecryptSecret($pdo, (string)($data['password_enc'] ?? ''));
        if ($password === '' || !hash_equals($password, $text)) { njazTgSend($pdo, $chatId, 'كلمتا المرور غير متطابقتين. أرسل كلمة المرور الجديدة مرة أخرى.', njazTgRegisterKeyboard()); unset($data['password_enc']); njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_password', $data); return; }
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'register_referral', $data);
        njazTgSend($pdo, $chatId, 'أرسل كود الإحالة إن وجد، أو أرسل <code>/skip</code> للتخطي:', njazTgRegisterKeyboard()); return;
    }
    if ($state === 'register_referral') {
        $data['referral_code'] = $text === '/skip' ? '' : strtoupper($text);
        njazTgRegisterFinish($pdo, $tgUser, $data); return;
    }
    if ($text === '/help') { njazTgHelp($pdo, $tgUser); return; }
    if ($state === 'catalog_search' && $text !== '') { njazTgSearch($pdo, $tgUser, $text); return; }
    if ($state === 'support_ref' && $text !== '') {
        $cleanRef = strtoupper(preg_replace('/\s+/', '', $text));
        if ($cleanRef === 'عام' || strtolower($cleanRef) === 'عام' || $cleanRef === 'GENERAL') {
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'support_message', []);
            njazTgSend($pdo, $chatId, 'اكتب رسالتك للدعم بالتفصيل:', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
            return;
        }
        if (!preg_match('/^ID[A-Z0-9_-]+$/', $cleanRef)) { njazTgSend($pdo, $chatId, 'أرسل رقم عملية صحيحاً يبدأ بـ <code>ID</code>، أو أرسل <code>عام</code>.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]); return; }
        $os = $pdo->prepare('SELECT id,ref_id FROM orders WHERE user_id=? AND UPPER(ref_id)=? LIMIT 1');
        $os->execute([(int)$tgUser['user_id'], $cleanRef]); $order = $os->fetch();
        if (!$order) { njazTgSend($pdo, $chatId, 'لم يتم العثور على هذه العملية ضمن حسابك. أرسل رقم ID صحيحاً أو اختر استفساراً عاماً.', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]); return; }
        $data = ['order_id' => (int)$order['id'], 'order_ref' => (string)$order['ref_id']];
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'support_message', $data);
        njazTgSend($pdo, $chatId, 'اكتب المشكلة أو الاستفسار المرتبط بالعملية <code>' . njazTgHtml($data['order_ref']) . '</code>:', [[['text' => 'إلغاء', 'callback_data' => 'support:cancel']]]);
        return;
    }
    if ($state === 'support_message' && $text !== '') { njazTgSupportSubmit($pdo, $tgUser, $data, $text); return; }
    if ($state === 'order_lookup' && $text !== '') { njazTgOrderLookup($pdo, $tgUser, $text); return; }
    if ($state === 'order_field' && $text !== '') {
        $fieldsStmt = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order"); $fieldsStmt->execute([(int)$data['service_id']]); $fields = $fieldsStmt->fetchAll(); $idx = (int)($data['field_index'] ?? 0);
        if (!isset($fields[$idx])) { njazTgReviewOrder($pdo, $tgUser, $data); return; }
        $data['fields'][$fields[$idx]['field_name']] = $text; $next = $idx + 1;
        if (isset($fields[$next])) njazTgAskField($pdo, $tgUser, $fields, $next, $data); else njazTgReviewOrder($pdo, $tgUser, $data);
        return;
    }
    if ($state === 'order_quantity' && ctype_digit($text)) {
        $qty = (int)$text; $min = (int)($data['min_qty'] ?? 1); $max = (int)($data['max_qty'] ?? $min);
        if ($qty < $min || $qty > $max) { njazTgSend($pdo, $tgUser['chat_id'], 'الكمية يجب أن تكون بين ' . $min . ' و ' . $max . '.'); return; }
        $data['quantity'] = $qty;
        $fieldsStmt = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order"); $fieldsStmt->execute([(int)$data['service_id']]); $fields = $fieldsStmt->fetchAll();
        if ($fields) njazTgAskField($pdo, $tgUser, $fields, 0, $data); else njazTgReviewOrder($pdo, $tgUser, $data);
        return;
    }
    if ($state === 'topup_amount') {
        if (!is_numeric($text) || (float)$text <= 0) { njazTgSend($pdo, $chatId, 'أرسل مبلغاً صحيحاً أكبر من صفر، مثل: <code>100</code>.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
        $data['amount'] = (float)$text; njazTgTopupReview($pdo, $tgUser, $data); return;
    }
    if ($state === 'binance_amount') { njazTgBinancePayCreate($pdo, $tgUser, $text); return; }
    if ($state === 'binance_transaction' || $state === 'binance_confirm') {
        // التحقق الفوري عند إرسال المعرّف، دون رسالة وسيطة أو اعتماد خارج الخدمة المركزية.
        $data['transaction_id'] = $text;
        njazTgBinancePayVerify($pdo, $tgUser, $data, $text);
        return;
    }
    if ($state === 'usdt_amount') { njazTgUsdtCreate($pdo, $tgUser, $text); return; }
    if ($state === 'usdt_tx') { njazTgUsdtVerify($pdo, $tgUser, $data, $text); return; }
    if ($state === 'floosak_amount') { njazTgFloosakInitiate($pdo, $tgUser, $data, $text); return; }
    if ($state === 'floosak_phone') {
        $phone = njazTgNormalizeYemenPhone($text);
        if ($phone === '') { njazTgSend($pdo, $chatId, 'رقم الهاتف غير صحيح. أرسل الرقم المحلي من 9 أرقام مثل <code>771234567</code>، بدون مفتاح الدولة.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
        $data['phone'] = $phone; njazTgFloosakInitiate($pdo, $tgUser, $data, (string)($data['amount'] ?? 0)); return;
    }
    if ($state === 'floosak_otp') { njazTgFloosakConfirm($pdo, $tgUser, $data, $text); return; }
    if ($state === 'topup_currency') { njazTgSend($pdo, $chatId, 'اختر العملة من الأزرار الظاهرة أمامك.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    if ($state === 'topup_receipt' && ($text === '/skip' || $text === 'تخطي')) { njazTgCreateTopup($pdo, $tgUser, $data, null, 'Telegram بدون إيصال'); return; }
    if ($state === 'topup_receipt' && $text !== '') { njazTgSend($pdo, $chatId, 'أرسل صورة الإيصال، أو أرسل <code>/skip</code> للمتابعة بدون إيصال.', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return; }
    if ($state === 'topup_card' && $text !== '') { njazTgRedeemCard($pdo, $tgUser, $text); return; }
    if ($state === 'payments') { njazTgSend($pdo, $chatId, 'استخدم أزرار الفلترة الظاهرة لعرض مدفوعاتك.'); return; }
    if ($state === 'telecom_phone' && $text !== '') { njazTgTelecomPhone($pdo, $tgUser, $data, $text); return; }
    if ($state === 'telecom_amount') {
        $amountText = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $text));
        if ($amountText === '' || !is_numeric($amountText) || (float)$amountText <= 0) {
            njazTgSend($pdo, $chatId, 'أرسل مبلغاً صحيحاً أكبر من صفر بالريال اليمني، مثل: <code>1000</code>.', [[['text' => 'إلغاء', 'callback_data' => 'home']]]);
            return;
        }
        $data['amount'] = (float)$amountText;
        $data['bunch_id'] = (string)($data['bunch_id'] ?? '340');
        $data['bunch_name'] = (string)($data['bunch_name'] ?? 'رصيد مفتوح');
        njazTgTelecomConfirm($pdo, $tgUser, $data);
        return;
    }
    if ($state === 'telecom_choice' || $state === 'telecom_group' || $state === 'telecom_confirm') { njazTgSend($pdo, $chatId, 'اختر أحد الأزرار الظاهرة أمامك.'); return; }
    if ($state === 'numbers_live_confirm' || $state === 'numbers_live_waiting' || $state === 'numbers_live_processing') {
        njazTgSend($pdo, $chatId, 'استخدم الأزرار الظاهرة للمتابعة، أو أرسل /cancel لإلغاء المسار.');
        return;
    }
    njazTgHome($pdo, $tgUser);
}

function njazTgHandleCallback(PDO $pdo, array $callback): void {
    $from = $callback['from'] ?? []; $message = $callback['message'] ?? []; $chatId = (int)($message['chat']['id'] ?? 0); $tgUser = njazTgUser($pdo, $from, $chatId); if (!empty($tgUser['is_blocked'])) return; njazTgDisplayCurrencySetContext($pdo, $tgUser); $dataCb = (string)($callback['data'] ?? ''); $messageId = (int)($message['message_id'] ?? 0); $callbackId = (string)($callback['id'] ?? '');
    // زر التحقق هو الاستثناء الوحيد الذي يعمل قبل اكتمال الاشتراك.
    if ($dataCb === 'subscription:verify') {
        $check = njazTgSubscriptionCheck($pdo, (int)($tgUser['telegram_id'] ?? 0));
        if (!empty($check['allowed'])) {
            njazTgAnswer($pdo, $callbackId, 'تم التحقق بنجاح. أهلاً بك.');
            njazTgHome($pdo, $tgUser, true, $messageId);
        } else {
            njazTgAnswer($pdo, $callbackId, 'لم يكتمل الاشتراك في جميع القنوات أو المجموعات.');
            njazTgShowSubscriptionGate($pdo, $tgUser, true, $messageId);
        }
        return;
    }
    if (!njazTgSubscriptionAllowed($pdo, $tgUser, true, true, $messageId)) {
        njazTgAnswer($pdo, $callbackId, 'اشترك أولاً ثم اضغط تحقق من الاشتراك.');
        return;
    }
    njazTgAnswer($pdo, $callbackId);
    if ($dataCb === 'home') { njazTgClearThumbnailMessages($pdo, $tgUser); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'unlink:cancel') { njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'unlink:confirm') {
        if (!empty($tgUser['user_id'])) {
            $clear = $pdo->prepare('UPDATE users SET telegram_chat_id=NULL WHERE id=? AND telegram_chat_id=?');
            $clear->execute([(int)$tgUser['user_id'], (string)$chatId]);
            $pdo->prepare("UPDATE telegram_users SET user_id=NULL,state='menu',state_data=NULL WHERE telegram_id=?")->execute([(int)$tgUser['telegram_id']]);
            njazTgSend($pdo, $chatId, '✅ تم فصل الحساب وإيقاف إشعارات الموقع إلى هذه المحادثة. لم يتم حذف حسابك أو طلباتك أو رصيدك.');
            njazTgHome($pdo, array_merge($tgUser, ['user_id' => null]), false, null);
        } else {
            njazTgHome($pdo, $tgUser, true, $messageId);
        }
        return;
    }
    if ($dataCb === 'register:start') { njazTgRegisterStart($pdo, $tgUser); return; }
    if ($dataCb === 'register:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu'); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'login:start') { njazTgLoginStart($pdo, $tgUser); return; }
    if ($dataCb === 'login:forgot') { njazTgLoginForgotStart($pdo, $tgUser); return; }
    if ($dataCb === 'login:device') { njazTgLoginDeviceStart($pdo, $tgUser); return; }
    if ($dataCb === 'login:device_resend') {
        $stData = njazTgStateData($tgUser);
        $identifier = trim((string)($stData['identifier'] ?? $stData['email'] ?? ''));
        if ($identifier === '') {
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'login_device_pending', []);
            njazTgSend($pdo, $chatId, njazTgScreen('📧', 'تأكيد الجهاز', 'أرسل البريد الإلكتروني أو اسم المستخدم الذي حاولت الدخول به لإعادة إرسال رابط التأكيد.'), njazTgLoginKeyboard());
            return;
        }
        njazTgLoginDeviceSend($pdo, $tgUser, $identifier, true);
        return;
    }
    if ($dataCb === 'login:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu'); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'profile:password') { njazTgPasswordChangeStart($pdo, $tgUser); return; }
    if ($dataCb === 'profile:password_cancel') {
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        njazTgProfileScreen($pdo, $tgUser, $messageId);
        return;
    }
    if ($dataCb === 'profile:currency') { njazTgDisplayCurrencyPicker($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^profile:currency:([A-Z0-9_-]{1,10})$/', $dataCb, $m)) { njazTgDisplayCurrencySelect($pdo, $tgUser, (string)$m[1], $messageId); return; }
    if ($dataCb === 'profile:language') { njazTgLanguagePicker($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^profile:language:([a-zA-Z][a-zA-Z0-9_-]{0,15})$/', $dataCb, $m)) { njazTgLanguageSelect($pdo, $tgUser, (string)$m[1], $messageId); return; }
    if ($dataCb === 'profile:kyc:status') { njazTgKycStatus($pdo, $tgUser, $messageId); return; }
    if ($dataCb === 'profile:kyc:start') { njazTgKycStart($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^profile:kyc:type:(national|passport|family|electronic)$/', $dataCb, $m)) { njazTgKycChooseType($pdo, $tgUser, (string)$m[1]); return; }
    if ($dataCb === 'profile:kyc:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'profile:kyc:retry') { njazTgKycSubmit($pdo, $tgUser, njazTgStateData($tgUser)); return; }
    if ($dataCb === 'quiz:next') { njazTgQuizNext($pdo, $tgUser, $messageId); return; }
    if ($dataCb === 'quiz:prev') { njazTgQuizPrev($pdo, $tgUser, $messageId); return; }
    if ($dataCb === 'quiz:cancel') { njazTgQuizCancel($pdo, $tgUser, $messageId); return; }
    if ($dataCb === 'quiz:resume') { njazTgQuizResume($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^quiz:start:(\\d+)$/', $dataCb, $m)) { njazTgQuizStart($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if (preg_match('/^quiz:pick:(\\d+):([abcd])$/', $dataCb, $m)) { njazTgQuizPick($pdo, $tgUser, (int)$m[1], (string)$m[2], $messageId); return; }
    if (str_starts_with($dataCb, 'menu:')) {
        $action = substr($dataCb, 5);
        if ($action === 'services') njazTgCategories($pdo, $tgUser, $messageId);
        elseif ($action === 'favorites') njazTgQuickServices($pdo, $tgUser, 'favorites', $messageId);
        elseif ($action === 'recent') njazTgQuickServices($pdo, $tgUser, 'recent', $messageId);
        elseif ($action === 'balance') njazTgBalance($pdo, $tgUser, $messageId);
        elseif ($action === 'orders') njazTgOrders($pdo, $tgUser, $messageId);
        elseif ($action === 'topup') njazTgTopupMethods($pdo, $tgUser, $messageId);
        elseif ($action === 'payments') njazTgPayments($pdo, $tgUser, $messageId, 'all');
        elseif ($action === 'quizzes') njazTgQuizShow($pdo, $tgUser, $messageId);
        elseif ($action === 'notifications') njazTgNotificationPrefs($pdo, $tgUser, $messageId);
        elseif ($action === 'help') njazTgHelp($pdo, $tgUser);
        elseif ($action === 'profile') njazTgProfileScreen($pdo, $tgUser, $messageId);
        return;
    }
    if (preg_match('/^thumb:cats:(\d+)$/', $dataCb, $m)) { njazTgCategories($pdo, $tgUser, $messageId, (int)$m[1]); return; }
    if ($dataCb === 'search:start') { njazTgSearchStart($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^thumb:cat:(\d+):(\d+)$/', $dataCb, $m)) { njazTgCategory($pdo, $tgUser, (int)$m[1], $messageId, (int)$m[2]); return; }
    if (preg_match('/^cat:(\d+)$/', $dataCb, $m)) { njazTgCategory($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if (preg_match('/^svc:(\d+)$/', $dataCb, $m)) { njazTgService($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if (preg_match('/^nl:buy:(\d+)$/', $dataCb, $m)) { njazTgNumbersLiveBuy($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if ($dataCb === 'nl:list') { njazTgNumbersLivePendingList($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^nl:resume:(\d+)$/', $dataCb, $m)) { njazTgNumbersLiveResume($pdo, $tgUser, (int)$m[1]); return; }
    if (preg_match('/^nl:check:(\d+)$/', $dataCb, $m)) { njazTgNumbersLiveCheckCode($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if (preg_match('/^nl:cancel:(\d+)$/', $dataCb, $m)) { njazTgNumbersLiveCancelOrder($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if ($dataCb === 'order:back' && in_array((string)($tgUser['state'] ?? ''), ['order_quantity', 'order_field', 'order_review', 'numbers_live_confirm'], true)) {
        njazTgBackFromOrderFlow($pdo, $tgUser, $messageId);
        return;
    }
    if ($dataCb === 'order:cancel' && in_array((string)($tgUser['state'] ?? ''), ['order_quantity', 'order_field', 'order_review', 'numbers_live_confirm'], true)) {
        njazTgCancelOrderFlow($pdo, $tgUser, $messageId);
        return;
    }
    if (preg_match('/^fav:toggle:(\d+)$/', $dataCb, $m)) { njazTgFavoriteToggle($pdo, $tgUser, (int)$m[1]); return; }
    if ($dataCb === 'notif:refresh') { njazTgNotificationPrefs($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^notif:(order|topup)$/', $dataCb, $m)) { njazTgNotificationToggle($pdo, $tgUser, $m[1], $messageId); return; }
    if (preg_match('/^reorder:(\d+)$/', $dataCb, $m)) { njazTgReorder($pdo, $tgUser, (int)$m[1]); return; }
    if (preg_match('/^fo:(\d+):(\d+)$/', $dataCb, $m)) {
        $st = njazTgStateData($tgUser); $fs = $pdo->prepare("SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order"); $fs->execute([(int)$st['service_id']]); $fields = $fs->fetchAll(); $idx=(int)$m[1]; $opts=njazTgOptions($fields[$idx]['field_options']??''); if (isset($opts[(int)$m[2]])) { $st['fields'][$fields[$idx]['field_name']]=function_exists('njazTgOptionValue') ? njazTgOptionValue($fields[$idx]['field_options'] ?? '', (int)$m[2]) : $opts[(int)$m[2]]; $next=$idx+1; if(isset($fields[$next])) njazTgAskField($pdo,$tgUser,$fields,$next,$st,$messageId); else njazTgReviewOrder($pdo,$tgUser,$st,$messageId); } return;
    }
    if ($dataCb === 'ord:confirm') { njazTgConfirmOrder($pdo, $tgUser, njazTgStateData($tgUser)); return; }
    if ($dataCb === 'ord:retry' && (string)($tgUser['state'] ?? '') === 'order_review') { njazTgConfirmOrder($pdo, $tgUser, njazTgStateData($tgUser)); return; }
    if ($dataCb === 'orders:lookup') { njazTgOrderLookupStart($pdo, $tgUser); return; }
    if ($dataCb === 'support:start') { njazTgSupportStart($pdo, $tgUser); return; }
    if (preg_match('/^support:order:(\d+)$/', $dataCb, $m)) { njazTgSupportStart($pdo, $tgUser, (int)$m[1]); return; }
    if ($dataCb === 'support:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu'); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'orders:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu'); njazTgOrders($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^orders:status:(pending|cancelled|completed)$/', $dataCb, $m)) { njazTgOrders($pdo, $tgUser, $messageId, $m[1]); return; }
    if (preg_match('/^order:view:(\d+)$/', $dataCb, $m)) { njazTgOrderDetail($pdo, $tgUser, (int)$m[1], $messageId); return; }
    if ($dataCb === 'topup:cancel') { njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []); njazTgHome($pdo, $tgUser, true, $messageId); return; }
    if ($dataCb === 'topup:binance') { njazTgBinancePayStart($pdo, $tgUser); return; }
    if ($dataCb === 'topup:usdt') { njazTgUsdtStart($pdo, $tgUser); return; }
    if ($dataCb === 'binance:verify') {
        $st = njazTgStateData($tgUser);
        if ((string)($tgUser['state'] ?? '') !== 'binance_confirm' || empty($st['request_id']) || empty($st['transaction_id'])) {
            $receiverIdentifier = trim((string)($st['receiver_identifier'] ?? ''));
            if ($receiverIdentifier === '' && function_exists('binancePayPublicSettings')) {
                $binanceSettings = binancePayPublicSettings($pdo);
                $receiverIdentifier = trim((string)($binanceSettings['receiver_identifier'] ?? ''));
            }
            $verifyAmount = njazTgHtml((string)($st['expected_amount'] ?? ''));
            $verifyIdentifier = njazTgHtml($receiverIdentifier);
            $verifyExpiresAt = njazTgHtml((string)($st['expires_at'] ?? ''));
            $verifyMessage = '<b>◈ تعليمات إيداع Binance</b>\n\n'
                . '<b>ابدأ أولاً بالإيداع إلى حسابنا Binance</b>\n\n'
                . '<b>المبلغ المطلوب</b>\n'
                . '<code>' . $verifyAmount . ' USDT</code>\n\n'
                . '<b>حساب الإيداع</b>\n'
                . 'Binance ID: <code>' . $verifyIdentifier . '</code>\n'
                . '<i>(اضغط على الرقم لنسخ الـ ID)</i>\n\n'
                . '<b>بعد التحويل</b>\n'
                . 'أرسل لنا معرّف المعاملة/الطلب الموجود في تفاصيل العملية في Binance لديكم.\n\n'
                . '<b>الصلاحية حتى</b>\n'
                . '<code>' . $verifyExpiresAt . '</code>';
            njazTgSend($pdo, (int)$tgUser['chat_id'], $verifyMessage, njazTgBinancePayKeyboard($receiverIdentifier));
            return;
        }
        njazTgBinancePayVerify($pdo, $tgUser, $st, (string)$st['transaction_id']);
        return;
    }
    if ($dataCb === 'topup:card') { njazTgTopupCardStart($pdo, $tgUser); return; }
    if ($dataCb === 'topup:floosak') { njazTgFloosakStart($pdo, $tgUser); return; }
    if ($dataCb === 'topup:manual' || $dataCb === 'topup:auto') { njazTgTopupMethods($pdo, $tgUser, $messageId); return; }
    if (preg_match('/^pm:(\d+)$/', $dataCb, $m)) { njazTgPaymentDetails($pdo, $tgUser, (int)$m[1]); return; }
    if (preg_match('/^cur:([A-Za-z0-9_-]+)$/', $dataCb, $m)) {
        $st = njazTgStateData($tgUser); $currency = strtoupper($m[1]);
        $methodId = (int)($st['method_id'] ?? 0);
        if ($methodId > 0 && !paymentMethodCurrencyAllowed($pdo, $methodId, $currency)) {
            njazTgSend($pdo, (int)$tgUser['chat_id'], 'هذه العملة غير مفعّلة لوسيلة الدفع المختارة. اختر إحدى العملات الظاهرة فقط.');
            return;
        }
        $rateStmt = $pdo->prepare("SELECT currency_symbol FROM exchange_rates WHERE currency_code=? AND status=1 LIMIT 1"); $rateStmt->execute([$currency]);
        if (!$rateStmt->fetch()) { njazTgSend($pdo, (int)$tgUser['chat_id'], 'العملة غير متاحة حالياً.'); return; }
        $st['currency'] = $currency; njazTgState($pdo, (int)$tgUser['telegram_id'], 'topup_amount', $st);
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'اخترت العملة: <b>' . njazTgHtml($currency) . '</b>\nأرسل الآن المبلغ الذي أودعته بهذه العملة:', [[['text' => 'إلغاء', 'callback_data' => 'topup:cancel']]]); return;
    }
    if (preg_match('/^payment:view:(manual|direct|binance|usdt|card):(\d+)$/', $dataCb, $m)) { njazTgPaymentDetail($pdo, $tgUser, $m[1], (int)$m[2]); return; }
    if (preg_match('/^payments:(all|manual|direct|binance|usdt|card|approved|pending|rejected)$/', $dataCb, $m)) { njazTgPayments($pdo, $tgUser, $messageId, $m[1]); return; }
    if (preg_match('/^tnet:(\d+)$/', $dataCb, $m)) { njazTgTelecomNetwork($pdo, $tgUser, (int)$m[1]); return; }
    if ($dataCb === 'tcheck') {
        $st = njazTgStateData($tgUser);
        if (empty($st['method_id']) || empty($st['phone'])) { njazTgSend($pdo, $chatId, 'ابدأ باختيار الشبكة وإرسال الرقم أولاً.'); return; }
        njazTgTelecomPhone($pdo, $tgUser, $st, (string)$st['phone']);
        return;
    }
    if ($dataCb === 'tback') { njazTgTelecomChoice($pdo, $tgUser, njazTgStateData($tgUser), $messageId); return; }
    if (preg_match('/^tptype:(prepaid|postpaid)$/', $dataCb, $m)) {
        $st = njazTgStateData($tgUser); $st['payment_type'] = $m[1]; $st['section'] = 'bundles';
        njazTgTelecomChoice($pdo, $tgUser, $st, $messageId); return;
    }
    if (preg_match('/^tsec:(amount|fees|bundles|yemen4g_change|yemen4g_credit|yemen4g_internet|yemen4g_voice)$/', $dataCb, $m)) {
        $st = njazTgStateData($tgUser); $bunches = njazTgTelecomFetchBunches($pdo, $tgUser, (int)($st['method_id'] ?? 0));
        $available = njazTgTelecomAvailableSections($bunches, (string)($st['payment_type'] ?? 'both'));
        if (!in_array($m[1], $available, true)) { njazTgSend($pdo, $chatId, 'هذا القسم غير متاح لهذه الشبكة أو نوع العملية.'); return; }
        $st['section'] = $m[1]; njazTgTelecomChoice($pdo, $tgUser, $st, $messageId); return;
    }
    if ($dataCb === 'tamt:open') {
        $st = njazTgStateData($tgUser); $st['section'] = 'amount'; $st['bunch_id'] = (string)($st['bunch_id'] ?? '340'); $st['bunch_name'] = 'رصيد مفتوح';
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'telecom_amount', $st);
        njazTgSend($pdo, $chatId, '<b>إدخال مبلغ الشحن</b>\nأرسل المبلغ بالريال اليمني، مثل: <code>1000</code>.', [[['text' => '↩️ الأقسام', 'callback_data' => 'tback'], ['text' => 'إلغاء', 'callback_data' => 'home']]]);
        return;
    }
    if (preg_match('/^tgrp:(\d+)$/', $dataCb, $m)) { njazTgTelecomGroup($pdo, $tgUser, njazTgStateData($tgUser), (int)$m[1], $messageId); return; }
    if (preg_match('/^tbn:(\d+)$/', $dataCb, $m)) { njazTgTelecomPickBunch($pdo, $tgUser, njazTgStateData($tgUser), (int)$m[1]); return; }
    if (preg_match('/^tsolfa:([01])$/', $dataCb, $m)) {
        $st = njazTgStateData($tgUser); $st['with_solfa'] = (int)$m[1]; njazTgTelecomConfirm($pdo, $tgUser, $st); return;
    }
    if ($dataCb === 'tconfirm') { njazTgTelecomExecute($pdo, $tgUser, njazTgStateData($tgUser)); return; }
    if (preg_match('/^tamt:([0-9.]+)$/', $dataCb, $m)) { njazTgTelecomPay($pdo, $tgUser, njazTgStateData($tgUser), 'balance', (float)$m[1]); return; }
    if (preg_match('/^toffer:(\d+)$/', $dataCb, $m)) { njazTgTelecomPay($pdo, $tgUser, njazTgStateData($tgUser), 'offer', (int)$m[1]); return; }
}

function njazTgProcessUpdate(PDO $pdo, array $update): void {
    $updateId = (int)($update['update_id'] ?? 0);
    $telegramId = (int)($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 0);
    if (!njazTgClaimUpdate($pdo, $updateId, $telegramId)) return;
    $logUpdate = $update;
    if (isset($logUpdate['message']['text']) && preg_match('/^\/link\s+\S+\s+\S+$/u', (string)$logUpdate['message']['text'])) {
        $logUpdate['message']['text'] = '/link [redacted] [redacted]';
    }
    njazTgLog($pdo, 'in', 'update', $logUpdate, (int)($update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? 0), (int)($update['update_id'] ?? 0));
    if (isset($update['callback_query'])) njazTgHandleCallback($pdo, $update['callback_query']); elseif (isset($update['message'])) njazTgHandleMessage($pdo, $update['message']);
}
