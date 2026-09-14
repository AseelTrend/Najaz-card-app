<?php
require_once '../includes/config.php';
require_once '../includes/binance_pay.php';
require_once '../includes/binance_pay_deposit_service.php';
requireStaffOrAdmin($pdo, 'perm_settings');
binancePayEnsureVisualColumns($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') adminCsrfVerify();

$pageTitle = 'Binance Pay - ' . SITE_NAME;
$settings = binancePayPublicSettings($pdo);
$notice = null;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$readonlyTest = $_SESSION['binance_pay_readonly_test'] ?? null;
unset($_SESSION['binance_pay_readonly_test']);
$normalizeReceiverType = static function (string $raw): string {
    $lookup = strtolower((string)(preg_replace('/[^a-zA-Z]/', '', trim($raw)) ?? ''));
    return [
        'binanceid' => 'binanceId',
        'accountid' => 'accountId',
        'email' => 'email',
        'phone' => 'phoneNumber',
        'phonenumber' => 'phoneNumber',
    ][$lookup] ?? '';
};
$maskReceiverValue = static function (string $value): string {
    $value = trim($value);
    $length = strlen($value);
    if ($length <= 4) return str_repeat('*', $length);
    return substr($value, 0, 2) . str_repeat('*', max(2, $length - 4)) . substr($value, -2);
};
$testErrorLabels = [
    'credentials_unavailable' => 'بيانات الاعتماد غير متاحة أو تعذر فك تشفيرها.',
    'invalid_time_window' => 'نافذة الفحص غير صحيحة.',
    'transport_error' => 'تعذر الاتصال بخدمة Binance Pay.',
    'invalid_api_response' => 'استجابة Binance غير صالحة.',
    'binance_api_error' => 'رفضت Binance الطلب أو أعادت خطأ API.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_migration') {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية تهيئة Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    $result = binancePayEnsureSchema($pdo);
    if (!empty($result['ok'])) {
        logStaffAction($pdo, 'binance_pay_schema_installed', 'binance_pay', 1, 'تمت تهيئة جداول Binance Pay المستقلة');
        flashMessage('success', 'تمت تهيئة Binance Pay بنجاح. يمكنك الآن حفظ المفاتيح.');
    } else {
        flashMessage('danger', 'تعذر تهيئة Binance Pay تلقائياً. ارفع ملف binance_pay_migration.sql إلى جذر الموقع ثم أعد المحاولة.');
    }
    redirect(SITE_URL . '/admin/binance_pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_visual') {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل صورة Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    $oldSettings = binancePayPublicSettings($pdo);
    $oldPath = trim((string)($oldSettings['icon_image_path'] ?? ''));
    $newPath = $oldPath;
    $removeImage = !empty($_POST['remove_icon_image']);
    $upload = $_FILES['binance_icon_image'] ?? null;
    $uploadError = is_array($upload) ? (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    try {
        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('تعذر قراءة الصورة المرفوعة.');
            if ((int)($upload['size'] ?? 0) > 3 * 1024 * 1024) throw new RuntimeException('حجم الصورة يجب ألا يتجاوز 3 ميجابايت.');
            $tmp = (string)($upload['tmp_name'] ?? '');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) throw new RuntimeException('نوع الصورة غير مسموح. استخدم PNG أو JPG أو WEBP أو GIF.');
            $dir = dirname(__DIR__) . '/uploads/binance';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('تعذر إنشاء مجلد صور Binance.');
            $filename = 'binance-pay-' . bin2hex(random_bytes(10)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($tmp, $dir . '/' . $filename)) throw new RuntimeException('تعذر حفظ الصورة المرفوعة.');
            $newPath = '/uploads/binance/' . $filename;
            $removeImage = false;
        } elseif ($removeImage) {
            $newPath = '';
        }
        $stmt = $pdo->prepare('UPDATE binance_pay_settings SET icon_image_path = ?, updated_by = ? WHERE id = 1');
        $stmt->execute([$newPath !== '' ? $newPath : null, $currentUserId]);
        if ($oldPath !== '' && $oldPath !== $newPath && strpos($oldPath, '/uploads/binance/') === 0) {
            $oldFile = dirname(__DIR__) . $oldPath;
            if (is_file($oldFile)) @unlink($oldFile);
        }
        logStaffAction($pdo, 'binance_pay_visual_updated', 'binance_pay', 1, $newPath !== '' ? 'تم تحديث صورة زر Binance Pay' : 'تم حذف صورة زر Binance Pay');
        flashMessage('success', $newPath !== '' ? 'تم حفظ صورة زر Binance Pay.' : 'تم حذف صورة زر Binance Pay، وسيظهر الرمز الافتراضي.');
    } catch (Throwable $e) {
        error_log('Binance Pay icon upload failed: ' . $e->getMessage());
        flashMessage('danger', $e instanceof RuntimeException ? $e->getMessage() : 'تعذر حفظ صورة Binance Pay.');
    }
    redirect(SITE_URL . '/admin/binance_pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_credentials') {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل مفاتيح Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    $existing = binancePayDatabaseCredentials($pdo);
    $apiKey = trim((string)($_POST['api_key'] ?? ''));
    $apiSecret = trim((string)($_POST['api_secret'] ?? ''));
    if ($apiKey === '' && !empty($existing['api_key'])) $apiKey = $existing['api_key'];
    if ($apiSecret === '' && !empty($existing['api_secret'])) $apiSecret = $existing['api_secret'];
    if ($apiKey === '' || $apiSecret === '' || preg_match('/\s/', $apiKey . $apiSecret)) {
        flashMessage('danger', 'أدخل API key وAPI secret صالحين دون مسافات. اترك الحقول فارغة فقط للاحتفاظ بالقيم المحفوظة سابقاً.');
    } else {
        try {
            $saved = binancePaySaveDatabaseCredentials($pdo, $apiKey, $apiSecret, $currentUserId);
            if ($saved) {
                logStaffAction($pdo, 'binance_pay_credentials_updated', 'binance_pay', 1, 'تم تحديث بيانات اعتماد Binance Pay المشفّرة');
                flashMessage('success', 'تم حفظ مفاتيح Binance Pay بشكل مشفّر. لن يظهر المفتاح السري مرة أخرى.');
            } else {
                flashMessage('danger', 'تعذر حفظ المفاتيح. تأكد من تشغيل مهاجرة Binance Pay ووجود OpenSSL.');
            }
        } catch (Throwable $e) {
            error_log('Binance credentials save failed: ' . $e->getMessage());
            flashMessage('danger', 'تعذر حفظ المفاتيح. تأكد من تشغيل مهاجرة Binance Pay أولاً.');
        }
    }
    redirect(SITE_URL . '/admin/binance_pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_credentials') {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية حذف مفاتيح Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    binancePayClearDatabaseCredentials($pdo);
    $pdo->exec("UPDATE binance_pay_settings SET enabled=0, updated_by=" . $currentUserId . " WHERE id=1");
    logStaffAction($pdo, 'binance_pay_credentials_cleared', 'binance_pay', 1, 'تم حذف بيانات اعتماد Binance Pay وتعطيل التكامل');
    flashMessage('success', 'تم حذف الاعتمادات المشفّرة وتعطيل Binance Pay.');
    redirect(SITE_URL . '/admin/binance_pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['test_readonly', 'test_and_enable'], true)) {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية فحص Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    $testAction = (string)$_POST['action'];
    $testSettings = binancePayPublicSettings($pdo);
    $testCredentials = binancePayCredentialStatus($pdo);
    $configuredType = $normalizeReceiverType((string)($testSettings['receiver_identifier_type'] ?? ''));
    $configuredReceiver = trim((string)($testSettings['receiver_identifier'] ?? ''));
    $configuredReceiverComplete = $configuredType !== '' && $configuredReceiver !== '' && strlen($configuredReceiver) <= 190;
    $testSeconds = max(60, min(86400, (int)($testSettings['search_window_seconds'] ?? 1800)));
    $endMs = (int)round(microtime(true) * 1000) + 5000;
    $startMs = $endMs - ($testSeconds * 1000);
    if (!$testCredentials['ready']) {
        flashMessage('danger', 'لا يمكن إجراء الفحص؛ احفظ API key وAPI secret أولاً.');
    } else {
        $apiResult = binancePayFetchTransactions($pdo, $startMs, $endMs, 100);
        if (empty($apiResult['ok'])) {
            $_SESSION['binance_pay_readonly_test'] = [
                'ok' => false,
                'error' => $testErrorLabels[$apiResult['error_code'] ?? ''] ?? 'تعذر إجراء الفحص القراءة فقط.',
                'rows' => [],
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            flashMessage('danger', 'فشل فحص Binance Pay القراءة فقط. راجع نتيجة الفحص الآمن أدناه.');
        } else {
            $safeRows = [];
            $qualifiedCount = 0;
            $matchCount = 0;
            foreach (array_slice((array)($apiResult['transactions'] ?? []), 0, 100) as $transaction) {
                $identifiers = is_array($transaction['receiver_identifiers'] ?? null) ? $transaction['receiver_identifiers'] : [];
                $qualified = !empty($transaction['success'])
                    && strtoupper((string)($transaction['currency'] ?? '')) === 'USDT'
                    && strtoupper((string)($transaction['order_type'] ?? '')) === 'C2C'
                    && (float)($transaction['amount'] ?? 0) > 0;
                if ($qualified) $qualifiedCount++;
                $matchesConfigured = $configuredReceiverComplete && $qualified && (($identifiers[$configuredType] ?? '') === $configuredReceiver);
                if ($matchesConfigured) $matchCount++;
                $safeIdentifiers = [];
                foreach ($identifiers as $identifierType => $identifierValue) {
                    $safeIdentifiers[] = $identifierType . ': ' . $maskReceiverValue((string)$identifierValue);
                }
                $safeRows[] = [
                    'transaction_id' => $maskReceiverValue((string)($transaction['transaction_id'] ?? '')),
                    'amount' => (string)($transaction['amount'] ?? ''),
                    'currency' => (string)($transaction['currency'] ?? ''),
                    'order_type' => (string)($transaction['order_type'] ?? ''),
                    'success' => !empty($transaction['success']),
                    'qualified' => $qualified,
                    'receiver_match' => $matchesConfigured,
                    'receiver_identifiers' => $safeIdentifiers,
                    'time' => !empty($transaction['transaction_time_ms']) ? date('Y-m-d H:i:s', (int)floor(((int)$transaction['transaction_time_ms']) / 1000)) : '—',
                ];
            }
            $_SESSION['binance_pay_readonly_test'] = [
                'ok' => true,
                'error' => null,
                'rows' => $safeRows,
                'total_count' => count((array)($apiResult['transactions'] ?? [])),
                'qualified_count' => $qualifiedCount,
                'match_count' => $matchCount,
                'configured_type' => $configuredType,
                'configured_receiver' => $configuredReceiverComplete ? $maskReceiverValue($configuredReceiver) : '',
                'window_seconds' => $testSeconds,
                'ran_at' => date('Y-m-d H:i:s'),
            ];
            if ($testAction === 'test_and_enable' && $configuredReceiverComplete && $matchCount > 0) {
                $update = $pdo->prepare("UPDATE binance_pay_settings SET enabled=1, accepted_currency='USDT', expected_order_type='C2C', updated_by=? WHERE id=1");
                $update->execute([$currentUserId]);
                logStaffAction($pdo, 'binance_pay_enabled_after_readonly_match', 'binance_pay', 1, 'تم تفعيل Binance Pay بعد مطابقة معاملة C2C واردة للقراءة فقط');
                flashMessage('success', 'تمت مطابقة معاملة C2C واردة وتفعيل Binance Pay. لم يُنفذ أي تحويل أو اعتماد رصيد تجريبي.');
            } elseif ($testAction === 'test_and_enable') {
                flashMessage('warning', 'اكتمل الفحص لكن لم توجد مطابقة صريحة للمستلم؛ بقي Binance Pay متوقفاً.');
            } else {
                flashMessage('success', 'اكتمل فحص Binance Pay للقراءة فقط دون تغيير حالة التفعيل.');
            }
            if ($matchCount > 0 && $configuredReceiverComplete) {
                $_SESSION['binance_pay_receiver_verified'] = ['type' => $configuredType, 'value' => $configuredReceiver, 'at' => time()];
            } else {
                unset($_SESSION['binance_pay_receiver_verified']);
            }
        }
    }
    redirect(SITE_URL . '/admin/binance_pay.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    if (!isAdmin() && !canAccess($pdo, 'perm_settings')) {
        flashMessage('danger', 'ليس لديك صلاحية تعديل إعدادات Binance Pay');
        redirect(SITE_URL . '/admin/binance_pay.php');
    }
    $wantsEnabled = !empty($_POST['enabled']);
    $enabled = $wantsEnabled ? 1 : 0;
    $credentialStatusBefore = binancePayCredentialStatus($pdo);
    $currency = strtoupper(trim((string)($_POST['accepted_currency'] ?? 'USDT')));
    $minimum = trim((string)($_POST['minimum_amount'] ?? '1'));
    $ttl = max(5, min(1440, (int)($_POST['request_ttl_minutes'] ?? 30)));
    $window = max(60, min(86400, (int)($_POST['search_window_seconds'] ?? 1800)));
    $tolerance = max(30, min(3600, (int)($_POST['time_tolerance_seconds'] ?? 300)));
    $receiverType = $normalizeReceiverType((string)($_POST['receiver_identifier_type'] ?? ''));
    $receiver = trim((string)($_POST['receiver_identifier'] ?? ''));
    $receiver = preg_replace('/\\x{00A0}/u', ' ', $receiver) ?? $receiver;
    $receiver = trim($receiver);
    $cashboxId = (int)($_POST['cashbox_id'] ?? 0);
    // MySQL stores four decimal places, لذلك نقبل 1.0000 ونطبع الأصفار النهائية،
    // مع إبقاء القيمة الفعلية قابلة للتمثيل بدقة رصيد الموقع (منزلتان عشريتان).
    $validAmount = false;
    if (preg_match('/^(0|[1-9][0-9]*)(?:\\.([0-9]{1,4}))?$/', $minimum, $amountParts)) {
        $integerPart = (string)$amountParts[1];
        $fraction = rtrim((string)($amountParts[2] ?? ''), '0');
        if (strlen($fraction) <= 2 && (float)$minimum > 0) {
            $minimum = ($fraction === '') ? (string)(int)$integerPart : $integerPart . '.' . $fraction;
            $validAmount = true;
        }
    }
    $validReceiverType = $receiverType !== '';
    $receiverComplete = $validReceiverType && $receiver !== '' && strlen($receiver) <= 190;
    $baseSettingsValid = $currency === 'USDT' && $validAmount;
    if (!$baseSettingsValid) {
        flashMessage('danger', 'الحد الأدنى غير صحيح. استخدم مبلغاً موجباً؛ يمكن كتابة الأصفار النهائية مثل 1.0000، لكن لا تتجاوز القيمة الفعلية منزلتين عشريتين. العملة المقبولة هي USDT.');
    } else {
        // إعدادات المدة والحد الأدنى يمكن حفظها والتكامل متوقف. لا نسمح بالتفعيل قبل اكتمال الربط.
        $verified = $_SESSION['binance_pay_receiver_verified'] ?? null;
        $verifiedForThisReceiver = is_array($verified)
            && ($verified['type'] ?? '') === $receiverType
            && hash_equals((string)($verified['value'] ?? ''), $receiver)
            && (int)($verified['at'] ?? 0) >= (time() - 600);
        if ($wantsEnabled && !$receiverComplete) {
            $enabled = 0;
            flashMessage('warning', 'تم حفظ الإعدادات، لكن بقي Binance Pay متوقفاً. أدخل نوع وقيمة معرّف المستلم من receiverInfo ثم أجرِ فحص القراءة فقط.');
        } elseif ($wantsEnabled && !$credentialStatusBefore['ready']) {
            $enabled = 0;
            flashMessage('warning', 'تم حفظ الإعدادات، لكن بقي Binance Pay متوقفاً حتى تحفظ مفاتيح API من قسم بيانات الربط.');
        } elseif ($wantsEnabled && !$verifiedForThisReceiver) {
            $enabled = 0;
            flashMessage('warning', 'تم حفظ الإعدادات لكن بقي Binance Pay متوقفاً. أجرِ فحص القراءة فقط وحقّق وجود معاملة C2C واردة تطابق المستلم قبل التفعيل.');
        } elseif (!$wantsEnabled && !$receiverComplete) {
            // السماح بحفظ إعدادات التشغيل مع ترك حقول المستلم فارغة عند التفعيل المتوقف.
            $receiverType = '';
            $receiver = '';
        }
        $sql = "INSERT INTO binance_pay_settings
          (id, enabled, accepted_currency, minimum_amount, request_ttl_minutes, search_window_seconds, time_tolerance_seconds, expected_order_type, receiver_identifier_type, receiver_identifier, cashbox_id, api_base_url, updated_by)
          VALUES (1,?,?,?,?,?,?, 'C2C',?,?,?, 'https://api.binance.com',?)
          ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), accepted_currency=VALUES(accepted_currency), minimum_amount=VALUES(minimum_amount), request_ttl_minutes=VALUES(request_ttl_minutes), search_window_seconds=VALUES(search_window_seconds), time_tolerance_seconds=VALUES(time_tolerance_seconds), expected_order_type='C2C', receiver_identifier_type=VALUES(receiver_identifier_type), receiver_identifier=VALUES(receiver_identifier), cashbox_id=VALUES(cashbox_id), api_base_url='https://api.binance.com', updated_by=VALUES(updated_by)";
        $pdo->prepare($sql)->execute([$enabled, $currency, $minimum, $ttl, $window, $tolerance, $receiverType, $receiver, $cashboxId > 0 ? $cashboxId : null, $currentUserId]);
        logStaffAction($pdo, 'binance_pay_settings_updated', 'binance_pay', 1, 'تم تحديث إعدادات Binance Pay والتفعيل=' . $enabled);
        if ($enabled) unset($_SESSION['binance_pay_receiver_verified']);
        if (!$wantsEnabled) {
            flashMessage('success', 'تم حفظ الإعدادات التشغيلية وبقي Binance Pay متوقفاً.');
        } elseif ($enabled) {
            flashMessage('success', 'تم حفظ إعدادات Binance Pay وتفعيل التكامل.');
        }
    }
    redirect(SITE_URL . '/admin/binance_pay.php');
}

$settings = binancePayPublicSettings($pdo);
$credentialStatus = binancePayCredentialStatus($pdo);
$credentialConfig = binancePayLocalConfig($pdo);
$credentialReady = $credentialStatus['ready'] && binancePayBaseUrl($credentialConfig['base_url']) !== null;
$visibilityDiagnostics = binancePayCustomerVisibilityDiagnostics($pdo);
$rows = [];
$schemaReady = true;
$requiredBinanceTables = ['binance_pay_settings', 'binance_pay_credentials', 'binance_pay_deposits'];
try {
    $tableCheck = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    foreach ($requiredBinanceTables as $requiredTable) {
        $tableCheck->execute([$requiredTable]);
        if (!$tableCheck->fetchColumn()) $schemaReady = false;
    }
} catch (Throwable $e) {
    $schemaReady = false;
}
$expiredUnsubmittedCount = 0;
if ($schemaReady) {
    // Close stale requests before displaying the administrative list. This only
    // touches pending rows with no submitted transaction identifier.
    $expiredUnsubmittedCount = binancePayExpireUnsubmittedDeposits($pdo);
    try {
        $stmt = $pdo->query("SELECT d.*, u.username, u.full_name FROM binance_pay_deposits d LEFT JOIN users u ON u.id=d.user_id ORDER BY d.id DESC LIMIT 100");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $schemaReady = false;
    }
}
$cashboxes = [];
try {
    $cashboxes = $pdo->query("SELECT id, name, currency_code, status FROM accounting_cashboxes WHERE status <> 'closed' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
include 'header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title"><div class="page-header-title-icon" style="background:rgba(246,193,26,.15)"><i class="fas fa-wallet" style="color:#f6c11a"></i></div><div><div>Binance Pay</div><small style="color:var(--text3);font-weight:600">إيداعات Pay الواردة فقط — مستقل عن USDT on-chain</small></div></div>
  </div>
</div>
<?php if(!$schemaReady): ?>
<div class="card" style="margin-bottom:1rem;border-color:rgba(245,166,35,.45)">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-database"></i> تهيئة قاعدة البيانات</div></div>
  <div class="card-body">
    <div class="alert alert-warning"><i class="fas fa-info-circle"></i> لم تُنشأ جداول Binance Pay بعد. اضغط الزر مرة واحدة فقط؛ العملية تنشئ جداول Binance المستقلة ولا تعدّل جداول USDT.</div>
    <form method="post" onsubmit="return confirm('سيتم إنشاء جداول Binance Pay المستقلة فقط. هل تريد المتابعة؟')">
      <?=adminCsrfField()?>
      <input type="hidden" name="action" value="install_migration">
      <button class="btn btn-primary" type="submit"><i class="fas fa-database"></i> تهيئة Binance Pay الآن</button>
    </form>
  </div>
</div>
<?php endif; ?>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-shield-alt"></i> حالة الربط</div></div>
  <div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap">
    <span class="badge" style="background:<?=$credentialReady?'rgba(0,200,83,.14)':'rgba(255,68,85,.14)'?>;color:<?=$credentialReady?'#00c853':'#ff4455'?>"><i class="fas fa-key"></i> <?= $credentialReady ? 'الاعتمادات محفوظة ومقروءة' : 'بيانات الاعتماد غير مهيأة' ?></span>
    <span class="badge" style="background:<?=!empty($settings['enabled'])?'rgba(0,200,83,.14)':'rgba(245,166,35,.14)'?>;color:<?=!empty($settings['enabled'])?'#00c853':'#f5a623'?>"><i class="fas fa-toggle-<?=$settings['enabled']?'on':'off'?>"></i> <?=!empty($settings['enabled'])?'مفعّل':'متوقف'?></span>
    <span class="badge" style="background:rgba(96,165,250,.14);color:#60a5fa"><i class="fas fa-coins"></i> العملة: USDT فقط</span>
    <?php if ($credentialStatus['api_key_masked'] !== ''): ?><span class="badge" style="background:rgba(167,139,250,.14);color:#a78bfa"><i class="fas fa-fingerprint"></i> API key: <?=htmlspecialchars($credentialStatus['api_key_masked'])?></span><?php endif; ?>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-stethoscope"></i> تشخيص ظهور الخيار للعميل</div><span class="cb-muted">قيم منطق العرض فقط — دون أسرار</span></div>
  <div class="card-body">
    <?php $diagnosticItems = [
      'enabled' => 'التفعيل',
      'currency_ok' => 'العملة USDT',
      'minimum_ok' => 'الحد الأدنى موجب',
      'order_type_ok' => 'نوع السجل C2C',
      'receiver_type_present' => 'نوع المستلم محفوظ',
      'receiver_value_present' => 'قيمة المستلم محفوظة',
      'customer_visible' => 'يظهر للعميل',
      'credentials_ready' => 'الاعتمادات جاهزة',
      'feature_ready' => 'التكامل جاهز',
    ]; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach ($diagnosticItems as $diagnosticKey => $diagnosticLabel): $diagnosticOk = !empty($visibilityDiagnostics[$diagnosticKey]); ?>
        <span class="badge" style="background:<?=$diagnosticOk?'rgba(0,200,83,.14)':'rgba(255,68,85,.14)'?>;color:<?=$diagnosticOk?'#00c853':'#ff4455'?>"><i class="fas fa-<?=$diagnosticOk?'check':'times'?>-circle"></i> <?=htmlspecialchars($diagnosticLabel)?>: <?=$diagnosticOk?'نعم':'لا'?></span>
      <?php endforeach; ?>
      <span class="badge" style="background:rgba(96,165,250,.14);color:#60a5fa"><i class="fas fa-code"></i> order_type: <?=htmlspecialchars($visibilityDiagnostics['order_type'] ?: 'فارغ')?></span>
    </div>
    <?php if (!empty($visibilityDiagnostics['reasons'])): ?><div class="cb-muted" style="margin-top:10px">سبب عدم الظهور إن وُجد: <?=htmlspecialchars(implode('، ', $visibilityDiagnostics['reasons']))?></div><?php else: ?><div style="margin-top:10px;color:#00c853;font-weight:800">كل شروط طباعة خيار Binance Pay للعميل متحققة في هذا السياق.</div><?php endif; ?>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-key"></i> بيانات الربط والتفعيل</div></div>
  <div class="card-body">
    <div class="alert alert-warning" style="margin-bottom:1rem"><i class="fas fa-lock"></i> تُشفّر المفاتيح تلقائياً قبل حفظها في قاعدة البيانات باستخدام إعدادات الموقع الداخلية. لا تحتاج إلى تعريف متغير خادم. لا تُرسل المفاتيح إلى العميل، ولن يظهر API secret بعد الحفظ.</div>
    <form method="post" autocomplete="off">
      <?=adminCsrfField()?>
      <input type="hidden" name="action" value="save_credentials">
      <div class="form-grid">
        <div class="form-group"><label>مفتاح API</label><input type="text" name="api_key" dir="ltr" autocomplete="new-password" placeholder="أدخل المفتاح أو اتركه فارغاً للاحتفاظ بالمحفوظ"><small class="cb-muted">الحالة الحالية: <?=htmlspecialchars($credentialStatus['api_key_masked'] ?: 'غير محفوظ')?></small></div>
        <div class="form-group"><label>المفتاح السري</label><input type="password" name="api_secret" dir="ltr" autocomplete="new-password" placeholder="أدخل السر أو اتركه فارغاً للاحتفاظ بالمحفوظ"><small class="cb-muted">يُحفظ مشفّراً ولا يُعاد عرضه.</small></div>
      </div>
      <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ بيانات الربط</button>
    </form>
    <?php if ($credentialStatus['database_saved']): ?>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('سيتم حذف مفاتيح Binance Pay وتعطيل التكامل. هل تريد المتابعة؟')">
      <?=adminCsrfField()?>
      <input type="hidden" name="action" value="clear_credentials">
      <button class="btn btn-danger" type="submit"><i class="fas fa-trash"></i> حذف المفاتيح وتعطيل التكامل</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-image"></i> صورة زر «مباشر Binance»</div></div>
  <div class="card-body">
    <?php $visualPath = trim((string)($settings['icon_image_path'] ?? '')); $visualUrl = $visualPath; if ($visualUrl !== '' && substr($visualUrl, 0, 1) === '/') $visualUrl = rtrim(SITE_URL, '/') . $visualUrl; ?>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
      <div style="width:76px;height:76px;border-radius:20px;background:rgba(246,193,26,.14);border:1px solid rgba(246,193,26,.35);display:flex;align-items:center;justify-content:center;overflow:hidden">
        <?php if ($visualUrl !== ''): ?><img src="<?=htmlspecialchars($visualUrl)?>" alt="Binance Pay" style="max-width:100%;max-height:100%;object-fit:contain"><?php else: ?><span style="font-size:2rem;color:#f6c11a">◈</span><?php endif; ?>
      </div>
      <div style="flex:1;min-width:220px"><strong>الصورة التي ستظهر على زر Binance داخل ⚡ مباشر</strong><div class="cb-muted" style="margin-top:5px">PNG أو JPG أو WEBP أو GIF — الحد الأقصى 3 ميجابايت. إذا لم ترفع صورة سيظهر الرمز الافتراضي.</div></div>
    </div>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <?=adminCsrfField()?>
      <input type="hidden" name="action" value="save_visual">
      <input type="file" name="binance_icon_image" accept="image/png,image/jpeg,image/webp,image/gif" style="width:100%;margin-bottom:10px">
      <?php if ($visualPath !== ''): ?><label style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><input type="checkbox" name="remove_icon_image" value="1"> حذف الصورة الحالية واستخدام الرمز الافتراضي</label><?php endif; ?>
      <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> حفظ صورة الزر</button>
    </form>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> الإعدادات التشغيلية</div></div>
  <div class="card-body">
    <form method="post">
      <?=adminCsrfField()?>
      <input type="hidden" name="action" value="save_settings">
      <div class="form-grid">
        <div class="form-group"><label>تفعيل Binance Pay</label><label class="switch"><input type="checkbox" name="enabled" value="1" <?=$settings['enabled']?'checked':''?>><span class="slider"></span></label><small class="cb-muted">احفظ الإعدادات أولاً والتكامل متوقف، ثم فعّل فقط بعد اكتمال المفاتيح ومعرّف المستلم.</small></div>
        <div class="form-group"><label>العملة المقبولة</label><input value="USDT" disabled><input type="hidden" name="accepted_currency" value="USDT"><small class="cb-muted">لا يوجد تحويل شبكة في هذا المسار.</small></div>
        <div class="form-group"><label>الحد الأدنى (USDT)</label><input type="text" name="minimum_amount" inputmode="decimal" value="<?=htmlspecialchars((string)$settings['minimum_amount'])?>" maxlength="12"></div>
        <div class="form-group"><label>صلاحية الطلب بالدقائق</label><input type="number" name="request_ttl_minutes" min="5" max="1440" value="<?= (int)$settings['request_ttl_minutes'] ?>"></div>
        <div class="form-group"><label>نافذة البحث الاحتياطية بالثواني</label><input type="number" name="search_window_seconds" min="60" max="86400" value="<?= (int)$settings['search_window_seconds'] ?>"><small class="cb-muted">تُستخدم للبحث المحدود؛ لا يوجد مسح عام.</small></div>
        <div class="form-group"><label>سماح فرق وقت العملية بالثواني</label><input type="number" name="time_tolerance_seconds" min="30" max="3600" value="<?= (int)$settings['time_tolerance_seconds'] ?>"></div>
        <div class="form-group"><label>نوع معرّف حساب الاستلام في Binance Pay</label><select name="receiver_identifier_type"><option value="">اختر</option><?php foreach(['binanceId'=>'Binance ID','accountId'=>'Account ID','email'=>'Email','phoneNumber'=>'Phone'] as $value=>$label): ?><option value="<?=$value?>" <?=$settings['receiver_identifier_type']===$value?'selected':''?>><?=$label?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>قيمة معرّف حساب الاستلام</label><input type="text" name="receiver_identifier" value="<?=htmlspecialchars((string)$settings['receiver_identifier'])?>" maxlength="190" dir="ltr"><small class="cb-muted">اكتب القيمة كما تظهر في receiverInfo داخل سجل Binance Pay. إذا ظهر UID داخل receiverInfo.binanceId، اختر Binance ID وأدخل الرقم كما هو.</small></div>
        <div class="form-group" style="grid-column:1/-1"><div class="alert alert-info"><i class="fas fa-info-circle"></i> لا تستخدم عنوان USDT أو رقم المعاملة. لا يكفي ظهور UID في صفحة الحساب وحدها؛ التفعيل يعتمد على تطابق receiverInfo في معاملة C2C واردة فعلية. إذا لم تكن متأكداً، اترك التفعيل متوقفاً واستخدم فحص القراءة فقط أدناه.</div></div>
        <div class="form-group"><label>الصندوق المحاسبي الاختياري</label><select name="cashbox_id"><option value="0">بدون ترحيل صندوق في الإصدار الأول</option><?php foreach($cashboxes as $cb): ?><option value="<?=$cb['id']?>" <?=$settings['cashbox_id']==$cb['id']?'selected':''?>><?=htmlspecialchars($cb['name'].' — '.$cb['currency_code'])?></option><?php endforeach; ?></select><small class="cb-muted">لا يُرحّل الصندوق تلقائياً حتى تفعيل منطق cashbox المخصص.</small></div>
      </div>
      <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> حفظ الإعدادات والتفعيل</button>
    </form>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-search"></i> فحص Binance Pay — قراءة فقط</div><span class="cb-muted">لا تحويل، لا سحب، ولا إضافة رصيد</span></div>
  <div class="card-body">
    <div class="alert alert-info"><i class="fas fa-shield-alt"></i> الفحص يجلب سجل Pay ضمن نافذة البحث المحددة، ويعرض بيانات مقنّعة فقط. عند اختيار «فحص ثم تفعيل» لن يتم التفعيل إلا إذا وُجدت معاملة ناجحة من نوع C2C وبعملة USDT تطابق نوع وقيمة المستلم المحفوظين حرفياً. يُقبل في هذا المسار C2C الوارد فقط.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post"><?=adminCsrfField()?><input type="hidden" name="action" value="test_readonly"><button class="btn btn-secondary" type="submit"><i class="fas fa-search"></i> فحص قراءة فقط</button></form>
      <form method="post" onsubmit="return confirm('سيتم إجراء فحص قراءة فقط، ثم تفعيل Binance Pay فقط عند وجود مطابقة صريحة. لن يُنفذ تحويل أو اعتماد رصيد. هل تريد المتابعة؟')"><?=adminCsrfField()?><input type="hidden" name="action" value="test_and_enable"><button class="btn btn-success" type="submit"><i class="fas fa-check-circle"></i> فحص ثم تفعيل عند المطابقة</button></form>
    </div>
    <?php if (is_array($readonlyTest)): ?>
      <div style="margin-top:14px" class="alert alert-<?=!empty($readonlyTest['ok'])?'success':'danger'?>"><i class="fas fa-info-circle"></i> <?=htmlspecialchars(!empty($readonlyTest['ok']) ? ('آخر فحص: '.$readonlyTest['ran_at'].' — النتائج: '.(int)($readonlyTest['total_count'] ?? 0).'، المؤهلة: '.(int)($readonlyTest['qualified_count'] ?? 0).'، المطابقة: '.(int)($readonlyTest['match_count'] ?? 0)) : ('آخر فحص فشل: '.($readonlyTest['error'] ?? 'خطأ غير معروف'))) ?></div>
      <?php if (!empty($readonlyTest['ok']) && !empty($readonlyTest['rows'])): ?>
      <div class="table-responsive"><table class="table"><thead><tr><th>المعاملة</th><th>المبلغ</th><th>النوع/العملة</th><th>النجاح</th><th>receiverInfo مقنّع</th><th>المطابقة</th><th>الوقت</th></tr></thead><tbody>
      <?php foreach ($readonlyTest['rows'] as $testRow): ?><tr><td dir="ltr" style="font-family:monospace"><?=htmlspecialchars($testRow['transaction_id'])?></td><td dir="ltr"><?=htmlspecialchars($testRow['amount'].' '.$testRow['currency'])?></td><td><?=htmlspecialchars($testRow['order_type'])?></td><td><?=!empty($testRow['success'])?'نعم':'لا'?></td><td dir="ltr"><?=htmlspecialchars(implode(' | ', (array)$testRow['receiver_identifiers']) ?: '—')?></td><td><?=!empty($testRow['receiver_match'])?'<span class="badge badge-success">مطابقة</span>':(!empty($testRow['qualified'])?'مؤهلة بلا مطابقة':'غير مؤهلة')?></td><td dir="ltr"><?=htmlspecialchars($testRow['time'])?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php elseif (!empty($readonlyTest['ok'])): ?><div class="cb-muted" style="margin-top:12px">لم تُرجع Binance معاملات ضمن نافذة البحث الحالية.</div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> سجل إيداعات Binance Pay</div><span class="cb-muted">آخر 100 طلب — دون payload خام</span></div>
  <div class="table-responsive"><table class="table"><thead><tr><th>#</th><th>العميل</th><th>المبلغ</th><th>المعرّف المدخل من العميل</th><th>الحالة</th><th>المحاولات</th><th>التاريخ</th><th>الخطأ الآمن</th></tr></thead><tbody>
  <?php if(!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--text3);padding:30px">لا توجد طلبات بعد.</td></tr><?php endif; ?>
  <?php foreach($rows as $row): $labels=['pending'=>'قيد الانتظار','verifying'=>'جارٍ التحقق','verified'=>'تم التحقق','credited'=>'تمت الإضافة','rejected'=>'مرفوض','expired'=>'منتهي','error'=>'خطأ']; $label=$labels[$row['status']]??$row['status']; ?>
  <tr><td><?= (int)$row['id'] ?></td><td><?=htmlspecialchars(($row['full_name']?:$row['username']?:('ID '.$row['user_id'])))?></td><td><?=htmlspecialchars($row['expected_amount'])?> USDT</td><td dir="ltr" style="font-family:monospace;max-width:180px;word-break:break-all"><?=htmlspecialchars($row['submitted_transaction_id']?:'—')?></td><td><span class="badge badge-<?=htmlspecialchars($row['status'])?>"><?=htmlspecialchars($label)?></span></td><td><?= (int)$row['verify_attempts'] ?></td><td><?=htmlspecialchars($row['created_at'])?></td><td><?=htmlspecialchars($row['safe_error_code']?:'—')?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php include 'footer.php'; ?>
