<?php
/**
 * Telegram KYC flow.
 * يعيد استخدام api/kyc_submit.php وجدول kyc_requests دون إنشاء منطق تحقق مستقل.
 */

function njazTgKycCurrent(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function njazTgKycTypeLabel(string $type): string {
    return [
        'national' => 'بطاقة شخصية',
        'passport' => 'جواز سفر',
        'family' => 'بطاقة عائلية',
        'electronic' => 'بطاقة إلكترونية',
    ][$type] ?? $type;
}

function njazTgKycStatusLabel(string $status): string {
    return [
        'pending' => 'قيد المراجعة',
        'approved' => 'تم التحقق والموافقة',
        'rejected' => 'مرفوضة',
    ][$status] ?? 'غير معروفة';
}

function njazTgKycStatusKeyboard(?string $status): array {
    $rows = [];
    if ($status === 'rejected' || $status === null || $status === '') {
        $rows[] = [['text' => '🪪 بدء تحقق جديد', 'callback_data' => 'profile:kyc:start', 'style' => 'primary']];
    }
    $rows[] = [['text' => '↩️ حسابي', 'callback_data' => 'menu:profile'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']];
    return $rows;
}

function njazTgKycStatus(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $request = njazTgKycCurrent($pdo, (int)$tgUser['user_id']);
    if (!$request) {
        $text = njazTgScreen('🪪', 'تحقق الهوية', 'لم تقدم طلب تحقق هوية من قبل.\n\nأرسل بيانات هويتك وصورها إلى الموقع للمراجعة وفتح المميزات التي تتطلب التحقق.');
        njazTgRenderSection($pdo, $tgUser, $text, njazTgKycStatusKeyboard(null), $messageId);
        return;
    }
    $status = (string)($request['status'] ?? '');
    $body = '<b>الحالة:</b> ' . njazTgHtml(njazTgKycStatusLabel($status))
        . '\n<b>نوع الهوية:</b> ' . njazTgHtml(njazTgKycTypeLabel((string)($request['id_type'] ?? '')));
    if (!empty($request['created_at'])) $body .= '\n<b>تاريخ التقديم:</b> ' . njazTgHtml((string)$request['created_at']);
    if ($status === 'rejected' && !empty($request['admin_note'])) {
        $body .= '\n\n<b>ملاحظة الإدارة:</b>\n' . njazTgHtml((string)$request['admin_note']);
    }
    $text = njazTgScreen($status === 'approved' ? '✅' : ($status === 'rejected' ? '⚠️' : '⏳'), 'تحقق الهوية', $body);
    njazTgRenderSection($pdo, $tgUser, $text, njazTgKycStatusKeyboard($status), $messageId);
}

function njazTgKycStart(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $current = njazTgKycCurrent($pdo, (int)$tgUser['user_id']);
    if ($current && in_array((string)($current['status'] ?? ''), ['pending', 'approved'], true)) {
        njazTgKycStatus($pdo, $tgUser, $messageId);
        return;
    }
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'kyc_id_type', []);
    $text = njazTgScreen('🪪', 'بدء تحقق الهوية', 'اختر نوع الوثيقة التي سترسلها للمراجعة:');
    $kb = [
        [['text' => '🪪 بطاقة شخصية', 'callback_data' => 'profile:kyc:type:national'], ['text' => '🛂 جواز سفر', 'callback_data' => 'profile:kyc:type:passport']],
        [['text' => '👨‍👩‍👧 بطاقة عائلية', 'callback_data' => 'profile:kyc:type:family'], ['text' => '💳 بطاقة إلكترونية', 'callback_data' => 'profile:kyc:type:electronic']],
        [['text' => '↩️ رجوع', 'callback_data' => 'menu:profile'], ['text' => '✖️ إلغاء', 'callback_data' => 'home']],
    ];
    njazTgRenderSection($pdo, $tgUser, $text, $kb, $messageId);
}

function njazTgKycPrompt(PDO $pdo, array $tgUser, string $state, array $data, string $text): void {
    njazTgState($pdo, (int)$tgUser['telegram_id'], $state, $data);
    njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('🪪', 'تحقق الهوية', $text), [[['text' => '✖️ إلغاء', 'callback_data' => 'profile:kyc:cancel']]]);
}

function njazTgKycChooseType(PDO $pdo, array $tgUser, string $type): void {
    if (!in_array($type, ['national', 'passport', 'family', 'electronic'], true)) return;
    $data = ['id_type' => $type];
    njazTgKycPrompt($pdo, $tgUser, 'kyc_full_name', $data, 'أرسل الاسم الكامل كما هو مكتوب في الوثيقة.');
}

function njazTgKycSubmit(PDO $pdo, array $tgUser, array $data): void {
    $files = [];
    foreach (['image_front', 'image_back'] as $field) {
        $path = (string)($data[$field . '_path'] ?? '');
        if ($path !== '' && is_file($path)) $files[$field] = ['path' => $path, 'mime' => (string)($data[$field . '_mime'] ?? 'image/jpeg'), 'name' => $field . '.' . (string)($data[$field . '_ext'] ?? 'jpg')];
    }
    $fields = [
        'id_type' => (string)($data['id_type'] ?? ''),
        'full_name' => (string)($data['full_name'] ?? ''),
        'national_id' => (string)($data['national_id'] ?? ''),
        'birth_date' => (string)($data['birth_date'] ?? ''),
        'birth_place' => (string)($data['birth_place'] ?? ''),
        'issue_date' => (string)($data['issue_date'] ?? ''),
        'expiry_date' => (string)($data['expiry_date'] ?? ''),
    ];
    $result = njazTgInternalMultipartPost($pdo, (int)$tgUser['user_id'], 'api/kyc_submit.php', $fields, $files);
    if (!empty($result['ok'])) {
        // تُحذف الملفات المؤقتة فقط بعد تأكيد حفظ الطلب في الموقع؛ أما عند الفشل فتُحفظ لإعادة المحاولة.
        foreach ($files as $file) @unlink((string)$file['path']);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('✅', 'تم إرسال طلب التحقق', 'تم إرسال بياناتك وصور الهوية إلى الموقع بنجاح. ستتم مراجعتها من الإدارة، ويمكنك متابعة الحالة من قسم حسابي.'), [[['text' => '🪪 حالة تحقق الهوية', 'callback_data' => 'profile:kyc:status'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']]]);
    } else {
        $message = (string)($result['msg'] ?? $result['message'] ?? 'تعذر إرسال طلب تحقق الهوية.');
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'kyc_review', $data);
        njazTgSend($pdo, (int)$tgUser['chat_id'], njazTgScreen('⚠️', 'تعذر إرسال الطلب', njazTgHtml($message)), [[['text' => '🔄 إعادة المحاولة', 'callback_data' => 'profile:kyc:retry'], ['text' => '✖️ إلغاء', 'callback_data' => 'profile:kyc:cancel']]]);
    }
}

function njazTgKycDownloadPhoto(PDO $pdo, string $fileId, int $userId, string $side): ?array {
    if ($fileId === '') return null;
    $file = njazTgApi($pdo, 'getFile', ['file_id' => $fileId]);
    $filePath = (string)($file['result']['file_path'] ?? '');
    if ($filePath === '') return null;
    $settings = njazTgSettings($pdo); $token = trim((string)($settings['bot_token'] ?? ''));
    if ($token === '') return null;
    $raw = @file_get_contents('https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/'));
    if ($raw === false || strlen($raw) > 8 * 1024 * 1024) return null;
    $info = @getimagesizefromstring($raw);
    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic'], true)) return null;
    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
    $dir = dirname(NJAZ_TG_INCLUDES) . '/assets/uploads/kyc/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . 'tg_' . $userId . '_' . $side . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (@file_put_contents($path, $raw) === false) return null;
    return ['path' => $path, 'mime' => $mime, 'ext' => $ext];
}

function njazTgKycHandlePhoto(PDO $pdo, array $tgUser, array $message, array $data): void {
    $state = (string)($tgUser['state'] ?? '');
    $photos = $message['photo'] ?? [];
    $last = is_array($photos) && $photos ? end($photos) : [];
    $side = $state === 'kyc_image_front' ? 'front' : 'back';
    $download = njazTgKycDownloadPhoto($pdo, (string)($last['file_id'] ?? ''), (int)$tgUser['user_id'], $side);
    if (!$download) {
        njazTgSend($pdo, (int)$tgUser['chat_id'], 'تعذر قراءة الصورة. أرسل صورة واضحة بصيغة JPG أو PNG وبحجم لا يتجاوز 8MB.');
        return;
    }
    $data['image_' . $side . '_path'] = $download['path']; $data['image_' . $side . '_mime'] = $download['mime']; $data['image_' . $side . '_ext'] = $download['ext'];
    if ($side === 'front' && (string)($data['id_type'] ?? '') !== 'passport') {
        njazTgKycPrompt($pdo, $tgUser, 'kyc_image_back', $data, 'أرسل الآن صورة الجهة الخلفية للوثيقة.');
        return;
    }
    njazTgKycSubmit($pdo, $tgUser, $data);
}
