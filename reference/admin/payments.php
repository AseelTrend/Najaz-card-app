<?php
ob_start(); // منع أي output يمنع JSON headers
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/payment_method_currency_helper.php';
try {
    paymentMethodCurrencyEnsureSchema($pdo);
    paymentMethodCashboxEnsureSchema($pdo);
} catch (Throwable $e) {
    error_log('Payment method currency/cashbox schema bootstrap: ' . $e->getMessage());
}
if (file_exists(dirname(__DIR__).'/includes/referral.php')) require_once dirname(__DIR__).'/includes/referral.php';
require_once '../includes/notifications.php';
require_once '../includes/floosak_merchant.php';
require_once '../includes/floosak_agent.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
// تهيئة مخطط الحسابات والصناديق قبل أي معاملة اعتماد؛ لا نُدخل DDL داخل معاملة مالية.
// accountingEnsureSchema يزرع أيضاً حساب محافظ العملاء 1100/USD و1100-YER عند غيابه.
try {
    if (!accountingEnsureSchema($pdo)) throw new RuntimeException('accounting schema unavailable');
    if (!cashboxEnsureSchema($pdo) || !cashboxEnsureOperationalColumns($pdo)) throw new RuntimeException('cashbox schema unavailable');
} catch (Throwable $e) {
    error_log('Accounting/cashbox schema bootstrap in payments.php: ' . $e->getMessage());
}
// تهيئة صناديق كل عملة خارج معاملة اعتماد الإيداع؛ الاعتماد لا ينشئ صناديق ولا ينفذ DDL.
try {
    cashboxEnsureCurrencyDefaults($pdo);
} catch (Throwable $e) {
    error_log('Automatic currency cashbox provisioning in payments.php: ' . $e->getMessage());
}
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'طرق الدفع وشحن الرصيد - ' . SITE_NAME;

$tab = $_GET['tab'] ?? 'requests';
$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

// ══════════════════════════════════════════════════════════════
// ─── إعدادات فلوسك ─────────────────────────────────────────
// ══════════════════════════════════════════════════════════════

// حفظ إعدادات فلوسك
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_floosak'])) {
    $phone      = preg_replace('/[^0-9]/', '', $_POST['floosak_phone'] ?? '');
    $shortCode  = trim($_POST['floosak_short_code'] ?? '');
    $key        = trim($_POST['floosak_merchant_key'] ?? '');
    $walletId   = (int)($_POST['floosak_wallet_id'] ?? 0);
    $enabled    = isset($_POST['floosak_enabled']) ? '1' : '0';

    // رفع صورة فلوسك
    $imagePath = $floosakCfg['floosak_image'] ?? '';
    if (!empty($_FILES['floosak_image']['tmp_name'])) {
        $uploadDir = dirname(__DIR__) . '/uploads/payment_methods/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['floosak_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','svg']) && $_FILES['floosak_image']['size'] <= 2*1024*1024) {
            $fileName = 'floosak_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['floosak_image']['tmp_name'], $uploadDir . $fileName)) {
                // حذف الصورة القديمة
                if ($imagePath && file_exists(dirname(__DIR__) . '/' . $imagePath)) {
                    @unlink(dirname(__DIR__) . '/' . $imagePath);
                }
                $imagePath = 'uploads/payment_methods/' . $fileName;
            }
        }
    }

    floosak_save_config($pdo, [
        'floosak_phone'        => $phone,
        'floosak_short_code'   => $shortCode,
        'floosak_merchant_key' => $key,
        'floosak_wallet_id'    => $walletId,
        'floosak_enabled'      => $enabled,
        'floosak_image'        => $imagePath,
        'floosak_key_updated'  => date('Y-m-d H:i:s'),
    ]);
    flashMessage('success', '✅ تم حفظ إعدادات فلوسك');
    redirect(SITE_URL . '/admin/payments.php?tab=floosak');
}

// طلب OTP تفعيل جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['floosak_request_otp'])) {
    $phone     = preg_replace('/[^0-9]/', '', $_POST['floosak_phone'] ?? '');
    $shortCode = trim($_POST['floosak_short_code'] ?? '');
    $res = floosak_request_key($phone, $shortCode);
    if ($res['ok'] || !empty($res['raw']['request_id'])) {
        $reqId = $res['raw']['request_id'] ?? ($res['data']['request_id'] ?? '');
        floosak_save_config($pdo, ['floosak_pending_request_id' => $reqId, 'floosak_phone' => $phone, 'floosak_short_code' => $shortCode]);
        flashMessage('success', '📱 تم إرسال رمز التحقق إلى ' . $phone);
    } else {
        flashMessage('danger', 'فشل: ' . ($res['message'] ?: 'خطأ غير معروف'));
    }
    redirect(SITE_URL . '/admin/payments.php?tab=floosak');
}

// تحقق OTP واحفظ المفتاح
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['floosak_verify_otp'])) {
    $cfg   = floosak_get_config($pdo);
    $reqId = (int)($cfg['floosak_pending_request_id'] ?? 0);
    $otp   = trim($_POST['floosak_otp'] ?? '');
    $res   = floosak_verify_key($reqId, $otp);
    if ($res['ok'] && !empty($res['key'])) {
        floosak_save_config($pdo, [
            'floosak_merchant_key' => $res['key'],
            'floosak_wallet_id'    => $res['wallet_id'] ?? '',
            'floosak_enabled'      => '1',
            'floosak_key_updated'  => date('Y-m-d H:i:s'),
        ]);
        flashMessage('success', '🎉 تم تفعيل فلوسك بنجاح! رصيد المحفظة: ' . number_format($res['balance'] ?? 0) . ' ريال');
    } else {
        flashMessage('danger', 'فشل التحقق: ' . ($res['message'] ?: 'رمز خاطئ أو منتهي'));
    }
    redirect(SITE_URL . '/admin/payments.php?tab=floosak');
}

// ══════════════════════════════════════════════════════════════
// ─── Fore Yemen Settings ──────────────────────────────────────
// ══════════════════════════════════════════════════════════════

function foreSaveSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
        ->execute([$key, $value, $value]);
}

// حفظ إعدادات Fore
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_fore'])) {
    $keys = ['fore_domain','fore_userid','fore_username','fore_enabled'];
    foreach ($keys as $k) {
        $v = $k === 'fore_enabled' ? (isset($_POST[$k]) ? '1' : '0') : trim($_POST[$k] ?? '');
        foreSaveSetting($pdo, $k, $v);
    }
    // كلمة المرور — لا تمسح القديمة إذا تُركت فارغة
    $newPass = trim($_POST['fore_password'] ?? '');
    if ($newPass !== '') foreSaveSetting($pdo, 'fore_password', $newPass);
    flashMessage('success','تم حفظ إعدادات Fore Yemen ✓');
    redirect(SITE_URL.'/admin/payments.php?tab=fore');
}

// اختبار اتصال Fore
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['test_fore'])) {
    require_once __DIR__.'/../includes/fore_api.php';
    $domain   = getSetting('fore_domain');
    $userid   = getSetting('fore_userid');
    $username = getSetting('fore_username');
    $password = getSetting('fore_password');
    if (!$domain || !$userid || !$username || !$password) {
        flashMessage('danger','أدخل بيانات Fore Yemen أولاً');
    } else {
        $api = new ForeYemenAPI($domain, $userid, $username, $password);
        $res = $api->testConnection();
        if ($res['ok']) {
            foreSaveSetting($pdo, 'fore_balance', (string)($res['balance'] ?? ''));
            foreSaveSetting($pdo, 'fore_last_test', (string)time());
            flashMessage('success','✅ اتصال Fore Yemen ناجح — الرصيد: '.htmlspecialchars($res['balance'] ?? '—'));
        } else {
            flashMessage('danger','❌ فشل الاتصال بـ Fore Yemen: '.htmlspecialchars($res['msg'] ?? 'خطأ غير معروف'));
        }
    }
    redirect(SITE_URL.'/admin/payments.php?tab=fore');
}

// ══════════════════════════════════════════════════════════════
// ─── قائمة سداد ──────────────────────────────────────────────
// ══════════════════════════════════════════════════════════════

// إنشاء الجداول تلقائياً
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sadad_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `parent_id` INT DEFAULT NULL,
        `name_ar` VARCHAR(100) NOT NULL,
        `icon` VARCHAR(50) DEFAULT 'list',
        `color` VARCHAR(30) DEFAULT '#6c3fe0',
        `type` VARCHAR(30) DEFAULT 'normal',
        `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_parent` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE sadad_categories ADD COLUMN IF NOT EXISTS `type` VARCHAR(30) NOT NULL DEFAULT 'normal'"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE sadad_categories ADD COLUMN IF NOT EXISTS `image` VARCHAR(300) DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE sadad_services   ADD COLUMN IF NOT EXISTS `image` VARCHAR(300) DEFAULT NULL"); } catch(Exception $e){}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `sadad_services` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT NOT NULL,
        `name_ar` VARCHAR(100) NOT NULL,
        `icon` VARCHAR(50) DEFAULT 'file-invoice',
        `color` VARCHAR(30) DEFAULT '#f5a623',
        `method_id` INT DEFAULT NULL,
        `action` ENUM('bill','external','none') DEFAULT 'bill',
        `action_value` VARCHAR(500) DEFAULT NULL,
        `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_category` (`category_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// حفظ اختيارات مزودي الشبكات (Fore/فلوسك)
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_network_providers'])) {
    $networks = [1,2,3,12,17,16,4,13];
    $opKeys   = ['amount','fees','bundles'];
    foreach ($networks as $mid) {
        foreach ($opKeys as $opKey) {
            $sk = "np_{$mid}_{$opKey}";
            if (!isset($_POST[$sk])) continue;
            $v = $_POST[$sk];
            $v = in_array($v,['fore','floosak','']) ? $v : '';
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$sk, $v, $v]);
        }
    }
    flashMessage('success','تم حفظ إعدادات المزودين ✓');
    redirect(SITE_URL.'/admin/payments.php?tab=fore');
}

// حفظ قسم
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_sadad_cat'])) {
    $cid  = (int)($_POST['sadad_cat_id'] ?? 0);
    $data = [
        'parent_id'  => trim($_POST['sadad_cat_parent']??'')=='' ? null : (int)$_POST['sadad_cat_parent'],
        'name_ar'    => trim($_POST['sadad_cat_name']),
        'icon'       => trim($_POST['sadad_cat_icon']     ?: 'list'),
        'color'      => trim($_POST['sadad_cat_color']    ?: '#6c3fe0'),
        'type'       => in_array($_POST['sadad_cat_type']??'',['normal','sadad']) ? $_POST['sadad_cat_type'] : 'normal',
        'sort_order' => (int)($_POST['sadad_cat_sort']    ?? 0),
        'status'     => isset($_POST['sadad_cat_status']) ? 1 : 0,
    ];
    // حذف الصورة إذا طلب المستخدم ذلك
    if (($_POST['sadad_cat_clear_image'] ?? '0') === '1' && $cid) {
        $oldImg2 = $pdo->prepare("SELECT image FROM sadad_categories WHERE id=?"); $oldImg2->execute([$cid]);
        $oldPath2 = $oldImg2->fetchColumn();
        if ($oldPath2 && file_exists(__DIR__.'/../'.$oldPath2)) @unlink(__DIR__.'/../'.$oldPath2);
        $pdo->prepare("UPDATE sadad_categories SET image=NULL WHERE id=?")->execute([$cid]);
    }
    // رفع صورة القسم
    $imgPath = null;
    if (!empty($_FILES['sadad_cat_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['sadad_cat_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','svg','gif'])) {
            $dir = __DIR__.'/../uploads/sadad_icons/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'cat_'.($cid ?: 'new').'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['sadad_cat_image']['tmp_name'], $dir.$fname)) {
                $imgPath = 'uploads/sadad_icons/'.$fname;
                // احذف القديمة
                if ($cid) {
                    $oldImg = $pdo->prepare("SELECT image FROM sadad_categories WHERE id=?"); $oldImg->execute([$cid]);
                    $oldImgPath = $oldImg->fetchColumn();
                    if ($oldImgPath && file_exists(__DIR__.'/../'.$oldImgPath)) @unlink(__DIR__.'/../'.$oldImgPath);
                }
            }
        }
    }
    if ($cid) {
        if ($imgPath) {
            $pdo->prepare("UPDATE sadad_categories SET parent_id=?,name_ar=?,icon=?,color=?,type=?,sort_order=?,status=?,image=? WHERE id=?")
                ->execute([$data['parent_id'],$data['name_ar'],$data['icon'],$data['color'],$data['type'],$data['sort_order'],$data['status'],$imgPath,$cid]);
        } else {
            $pdo->prepare("UPDATE sadad_categories SET parent_id=?,name_ar=?,icon=?,color=?,type=?,sort_order=?,status=? WHERE id=?")
                ->execute([$data['parent_id'],$data['name_ar'],$data['icon'],$data['color'],$data['type'],$data['sort_order'],$data['status'],$cid]);
        }
    } else {
        $pdo->prepare("INSERT INTO sadad_categories (parent_id,name_ar,icon,color,type,sort_order,status,image) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$data['parent_id'],$data['name_ar'],$data['icon'],$data['color'],$data['type'],$data['sort_order'],$data['status'],$imgPath]);
    }
    flashMessage('success','تم حفظ القسم ✓');
    redirect(SITE_URL.'/admin/payments.php?tab=sadad');
}

// حذف قسم
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_sadad_cat'])) {
    $cid = (int)$_POST['sadad_cat_id'];
    if ($cid) {
        $pdo->prepare("DELETE FROM sadad_services WHERE category_id=?")->execute([$cid]);
        $pdo->prepare("UPDATE sadad_categories SET parent_id=NULL WHERE parent_id=?")->execute([$cid]);
        $pdo->prepare("DELETE FROM sadad_categories WHERE id=?")->execute([$cid]);
    }
    flashMessage('success','تم حذف القسم');
    redirect(SITE_URL.'/admin/payments.php?tab=sadad');
}

// حفظ خدمة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_sadad_svc'])) {
    $sid  = (int)($_POST['sadad_svc_id'] ?? 0);
    $data = [
        'category_id'  => (int)$_POST['sadad_svc_cat'],
        'name_ar'      => trim($_POST['sadad_svc_name']),
        'icon'         => trim($_POST['sadad_svc_icon']    ?: 'file-invoice'),
        'color'        => trim($_POST['sadad_svc_color']   ?: '#f5a623'),
        'method_id'    => trim($_POST['sadad_svc_method']??'')=='' ? null : (int)$_POST['sadad_svc_method'],
        'action'       => in_array($_POST['sadad_svc_action']??'',['bill','external','none']) ? $_POST['sadad_svc_action'] : 'bill',
        'action_value' => trim($_POST['sadad_svc_url'] ?? '') ?: null,
        'sort_order'   => (int)($_POST['sadad_svc_sort'] ?? 0),
        'status'       => isset($_POST['sadad_svc_status']) ? 1 : 0,
    ];
    // حذف الصورة إذا طلب المستخدم ذلك
    if (($_POST['sadad_svc_clear_image'] ?? '0') === '1' && $sid) {
        $oldImg3 = $pdo->prepare("SELECT image FROM sadad_services WHERE id=?"); $oldImg3->execute([$sid]);
        $oldPath3 = $oldImg3->fetchColumn();
        if ($oldPath3 && file_exists(__DIR__.'/../'.$oldPath3)) @unlink(__DIR__.'/../'.$oldPath3);
        $pdo->prepare("UPDATE sadad_services SET image=NULL WHERE id=?")->execute([$sid]);
    }
    // رفع صورة الخدمة
    $svcImgPath = null;
    if (!empty($_FILES['sadad_svc_image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['sadad_svc_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','svg','gif'])) {
            $dir = __DIR__.'/../uploads/sadad_icons/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'svc_'.($sid ?: 'new').'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['sadad_svc_image']['tmp_name'], $dir.$fname)) {
                $svcImgPath = 'uploads/sadad_icons/'.$fname;
                if ($sid) {
                    $oldImg = $pdo->prepare("SELECT image FROM sadad_services WHERE id=?"); $oldImg->execute([$sid]);
                    $oldImgPath = $oldImg->fetchColumn();
                    if ($oldImgPath && file_exists(__DIR__.'/../'.$oldImgPath)) @unlink(__DIR__.'/../'.$oldImgPath);
                }
            }
        }
    }
    if ($sid) {
        if ($svcImgPath) {
            $pdo->prepare("UPDATE sadad_services SET category_id=?,name_ar=?,icon=?,color=?,method_id=?,action=?,action_value=?,sort_order=?,status=?,image=? WHERE id=?")
                ->execute([$data['category_id'],$data['name_ar'],$data['icon'],$data['color'],$data['method_id'],$data['action'],$data['action_value'],$data['sort_order'],$data['status'],$svcImgPath,$sid]);
        } else {
            $pdo->prepare("UPDATE sadad_services SET category_id=?,name_ar=?,icon=?,color=?,method_id=?,action=?,action_value=?,sort_order=?,status=? WHERE id=?")
                ->execute([$data['category_id'],$data['name_ar'],$data['icon'],$data['color'],$data['method_id'],$data['action'],$data['action_value'],$data['sort_order'],$data['status'],$sid]);
        }
    } else {
        $pdo->prepare("INSERT INTO sadad_services (category_id,name_ar,icon,color,method_id,action,action_value,sort_order,status,image) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$data['category_id'],$data['name_ar'],$data['icon'],$data['color'],$data['method_id'],$data['action'],$data['action_value'],$data['sort_order'],$data['status'],$svcImgPath]);
    }
    flashMessage('success','تم حفظ الخدمة ✓');
    redirect(SITE_URL.'/admin/payments.php?tab=sadad');
}

// حذف خدمة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_sadad_svc'])) {
    $sid = (int)$_POST['sadad_svc_id'];
    if ($sid) $pdo->prepare("DELETE FROM sadad_services WHERE id=?")->execute([$sid]);
    flashMessage('success','تم حذف الخدمة');
    redirect(SITE_URL.'/admin/payments.php?tab=sadad');
}

// ══════════════════════════════════════════════════════════════
// ─── إعدادات Floosak Agent (وكيل الشحن) ─────────────────────
// ══════════════════════════════════════════════════════════════

// أضف الأعمدة الجديدة تلقائياً إذا لم تكن موجودة
try {
    $pdo->exec("ALTER TABLE `floosak_agent_bunches`
        ADD COLUMN IF NOT EXISTS `payment_type` ENUM('prepaid','postpaid','both') NOT NULL DEFAULT 'both',
        ADD COLUMN IF NOT EXISTS `section` ENUM('amount','fees','bundles') NOT NULL DEFAULT 'bundles',
        ADD COLUMN IF NOT EXISTS `bundle_group` VARCHAR(100) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS `is_free_amount` tinyint(1) NOT NULL DEFAULT 0");
} catch(Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_floosak_agent'])) {
    $agentPhone    = preg_replace('/[^0-9]/', '', $_POST['floosak_agent_phone'] ?? '');
    $agentPassword = trim($_POST['floosak_agent_password'] ?? '');
    $agentApiUrl   = trim($_POST['floosak_agent_api_url'] ?? '');
    $agentAccountNumber = trim($_POST['floosak_agent_account_number'] ?? '');
    $agentEnabled  = isset($_POST['floosak_agent_enabled']) ? '1' : '0';
    $agentSandbox  = isset($_POST['floosak_agent_sandbox'])  ? '1' : '0';
    floosak_agent_save_config($pdo, [
        'floosak_agent_phone'          => $agentPhone,
        'floosak_agent_password'       => $agentPassword,
        'floosak_agent_enabled'        => $agentEnabled,
        'floosak_agent_sandbox'        => $agentSandbox,
        'floosak_agent_api_url'        => $agentApiUrl,
        'floosak_agent_account_number' => $agentAccountNumber,
        // التوكن ومعرف المحفظة — احفظهم إذا أُرسلا (للتعديل اليدوي)
        'floosak_agent_token'         => (trim($_POST['floosak_agent_token'] ?? '') ?: ($agentCfg['floosak_agent_token'] ?? '')),
        'floosak_agent_wallet_id'     => (trim($_POST['floosak_agent_wallet_id'] ?? '') ?: ($agentCfg['floosak_agent_wallet_id'] ?? '')),
        'floosak_agent_token'         => '',
        'floosak_agent_token_expiry'  => '0',
        'floosak_agent_wallet_id'     => '',
        'floosak_agent_wallet_balance'=> '0',
    ]);
    flashMessage('success', 'تم حفظ إعدادات وكيل فلوسك ✓');
    redirect(SITE_URL . '/admin/payments.php?tab=floosak_agent');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_floosak_agent'])) {
    $agentCfg = floosak_agent_get_config($pdo);
    $sandbox  = ($agentCfg['floosak_agent_sandbox'] ?? '1') === '1';
    $phone    = $agentCfg['floosak_agent_phone']    ?? '';
    $password = $agentCfg['floosak_agent_password'] ?? '';
    if (!$phone || !$password) {
        flashMessage('danger', 'أدخل بيانات الوكيل أولاً');
    } else {
        $loginRes = floosak_agent_login($phone, $password, $sandbox);

        // اعتمد على access_token كمؤشر نجاح رئيسي
        if (!empty($loginRes['token'])) {
            floosak_agent_save_config($pdo, [
                'floosak_agent_token'          => $loginRes['token'],
                'floosak_agent_wallet_id'      => (string)($loginRes['yer_wallet_id'] ?? ''),
                'floosak_agent_wallet_balance' => $loginRes['yer_balance'] ?? 0,
                'floosak_agent_token_expiry'   => time() + (4 * 3600),
            ]);
            $bal = number_format((float)($loginRes['yer_balance'] ?? 0));
            $wid = $loginRes['yer_wallet_id'] ?? '—';
            flashMessage('success', "✅ اتصال ناجح — رصيد YER: {$bal} ريال | wallet_id: {$wid}");
        } else {
            // أظهر الرد الخام لمساعدة التشخيص
            $rawMsg  = $loginRes['message'] ?? '';
            $rawDump = is_array($rawMsg) ? json_encode($rawMsg, JSON_UNESCAPED_UNICODE) : $rawMsg;
            $http    = $loginRes['http_code'] ?? '—';
            $rawBody = is_array($loginRes['raw']) ? json_encode($loginRes['raw'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) : ($loginRes['raw'] ?? '');
            flashMessage('danger',
                "❌ فشل الاتصال (HTTP {$http})<br>"
                . "<small style='font-family:monospace;word-break:break-all'>"
                . htmlspecialchars(mb_substr($rawBody, 0, 500))
                . "</small>"
            );
        }
    }
    redirect(SITE_URL . '/admin/payments.php?tab=floosak_agent');
}


// ══════════════════════════════════════════════════════
// Floosak Agent — معالجات إدارة المزودين والباقات
// ══════════════════════════════════════════════════════

// إضافة عمود image لـ floosak_agent_methods إذا لم يكن موجوداً
try { $pdo->exec("ALTER TABLE floosak_agent_methods ADD COLUMN IF NOT EXISTS `logo` VARCHAR(300) DEFAULT NULL COMMENT 'مسار شعار الشبكة'"); } catch(Exception $e){}

// جدول الحقول الديناميكية للمزودين (وكيل الشحن)
$pdo->exec("CREATE TABLE IF NOT EXISTS `fa_method_fields` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `method_row_id` INT NOT NULL COMMENT 'id من floosak_agent_methods',
  `field_key`     VARCHAR(80)  NOT NULL COMMENT 'مفتاح يُرسل للـ API',
  `field_label`   VARCHAR(150) NOT NULL COMMENT 'تسمية للعميل',
  `field_type`    ENUM('text','number','select','textarea','checkbox','amount','counter') DEFAULT 'text',
  `field_options` TEXT DEFAULT NULL COMMENT 'سطر لكل خيار: تسمية|قيمة',
  `placeholder`   VARCHAR(200) DEFAULT NULL,
  `is_required`   TINYINT(1) DEFAULT 1,
  `is_enabled`    TINYINT(1) DEFAULT 1,
  `sort_order`    INT DEFAULT 0,
  KEY `idx_method` (`method_row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// أضف قيمة amount لـ ENUM إن لم تكن موجودة
try { $pdo->exec("ALTER TABLE `fa_method_fields` MODIFY `field_type` ENUM('text','number','select','textarea','checkbox','amount','counter') DEFAULT 'text'"); } catch(Exception $e) {}

// ── ترحيل بيانات الكهرباء والماء الثابتة → fa_method_fields ──
try {
    $elecRow   = $pdo->query("SELECT id FROM floosak_agent_methods WHERE method_id=5  LIMIT 1")->fetch();
    $waterRow  = $pdo->query("SELECT id FROM floosak_agent_methods WHERE method_id=11 LIMIT 1")->fetch();

    if ($elecRow) {
        $rid = (int)$elecRow['id'];
        $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM fa_method_fields WHERE method_row_id=?")->execute([$rid])
             ? (int)$pdo->query("SELECT COUNT(*) FROM fa_method_fields WHERE method_row_id=$rid")->fetchColumn() : 0;
        if ($cnt === 0) {
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,0)")
                ->execute([$rid,'subscriber_number','رقم المشترك','text','أدخل رقم العداد أو الحساب']);
            $elecOpts = "الامانة - المنطقة الأولى|225
الامانة - المنطقة الثانية|226
الامانة - المنطقة الثالثة|227
الامانة - المنطقة الرابعة|228
منطقة محافظة صنعاء|2266
منطقة تعز|4702
منطقة ذمار|2618
منطقة يريم|2454
منطقة إب|2824
فرع الحديدة|1824
منطقة حجة|2276
منطقة صعدة|7622
منطقة رداع|2787
منطقة المحويت|2378
منطقة عمران|4992";
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,1,1,1)")
                ->execute([$rid,'area_unified','المنطقة','select',$elecOpts,'اختر منطقتك']);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,2)")
                ->execute([$rid,'amount','مبلغ السداد','amount','أدخل المبلغ بالريال اليمني']);
        }
    }

    if ($waterRow) {
        $rid = (int)$waterRow['id'];
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM fa_method_fields WHERE method_row_id=$rid")->fetchColumn();
        if ($cnt === 0) {
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,0)")
                ->execute([$rid,'subscriber_number','رقم الاشتراك','text','أدخل رقم اشتراكك']);
            $waterOpts = "مؤسسة المياه - الامانة|0601
مؤسسة المياه - الامانة - الفروسية|0602
مؤسسة المياه - إب - المحافظة|0603
مؤسسة المياه - تعز - الحوبان|0604
مؤسسة المياه - ذمار - المحافظة|0605
مؤسسة المياه - الحديده - المحافظة|0606
مؤسسة المياه - الحديدة - الصليف|0607
مؤسسة المياه - الحديدة - المراوعه|0608
مؤسسة المياه - الحديدة - باجل|0609
مؤسسة المياه - المحويت|0609
مؤسسة المياه - شبام كوكبان|0611
مؤسسة المياه - المحويت - الظاهر|0612
مؤسسة المياه - إب - يريم|0656
مؤسسة المياه - الضالع - دمت|0660
مؤسسة المياه - حجه|0607
مؤسسة المياه - عمران|0608
مؤسسة المياه - الحديدة - زبيد|0610
مؤسسة المياه - بيت الفقيه|0614
مؤسسة المياه - المنصورية|0613";
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,1,1,1)")
                ->execute([$rid,'area_unified','المؤسسة','select',$waterOpts,'اختر مؤسستك']);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,2)")
                ->execute([$rid,'amount','مبلغ السداد','amount','أدخل المبلغ بالريال اليمني']);
        }
    }
} catch(Exception $e) {}

// ── AJAX: حفظ حقول مزود ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_fa_method_fields'])) {
    $rowId = (int)$_POST['fa_mf_row_id'];
    $keys   = $_POST['mf_key']         ?? [];
    $labels = $_POST['mf_label']       ?? [];
    $types  = $_POST['mf_type']        ?? [];
    $opts   = $_POST['mf_options']     ?? [];
    $plachs = $_POST['mf_placeholder'] ?? [];
    $reqs   = $_POST['mf_required']    ?? [];
    $enabls = $_POST['mf_enabled']     ?? [];
    $sorts  = $_POST['mf_sort']        ?? [];
    $ids    = $_POST['mf_id']          ?? [];

    $keepIds = array_filter(array_map('intval', $ids));
    if (!empty($keepIds)) {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM fa_method_fields WHERE method_row_id=? AND id NOT IN ($ph)")
            ->execute(array_merge([$rowId], $keepIds));
    } else {
        $pdo->prepare("DELETE FROM fa_method_fields WHERE method_row_id=?")->execute([$rowId]);
    }

    foreach ($keys as $i => $key) {
        $key = trim($key); $label = trim($labels[$i] ?? '');
        if (!$key || !$label) continue;
        $fid  = (int)($ids[$i] ?? 0);
        $type = $types[$i] ?? 'text';
        $opt  = trim($opts[$i] ?? '');
        $pl   = trim($plachs[$i] ?? '');
        $req  = ($reqs[$i] ?? '0') === '1' ? 1 : 0;
        $en   = ($enabls[$i] ?? '0') === '1' ? 1 : 0;
        $so   = (int)($sorts[$i]   ?? $i);
        if ($fid) {
            $pdo->prepare("UPDATE fa_method_fields SET field_key=?,field_label=?,field_type=?,field_options=?,placeholder=?,is_required=?,is_enabled=?,sort_order=? WHERE id=? AND method_row_id=?")
                ->execute([$key,$label,$type,$opt,$pl,$req,$en,$so,$fid,$rowId]);
        } else {
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$rowId,$key,$label,$type,$opt,$pl,$req,$en,$so]);
        }
    }
    // AJAX response
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'msg'=>'تم حفظ الحقول']);
    exit;
}

// إعادة ترحيل بيانات الكهرباء/الماء (تُحذف القديمة وتُضاف جديدة)
if (isset($_GET['reseed_fields'])) {
    try {
        $elecRow  = $pdo->query("SELECT id FROM floosak_agent_methods WHERE method_id=5  LIMIT 1")->fetch();
        $waterRow = $pdo->query("SELECT id FROM floosak_agent_methods WHERE method_id=11 LIMIT 1")->fetch();
        $msg = [];
        if ($elecRow) {
            $rid = (int)$elecRow['id'];
            $pdo->prepare("DELETE FROM fa_method_fields WHERE method_row_id=?")->execute([$rid]);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,0)")
                ->execute([$rid,'subscriber_number','رقم المشترك','text','أدخل رقم العداد أو الحساب']);
            $elecOpts = "الامانة - المنطقة الأولى|225
الامانة - المنطقة الثانية|226
الامانة - المنطقة الثالثة|227
الامانة - المنطقة الرابعة|228
منطقة محافظة صنعاء|2266
منطقة تعز|4702
منطقة ذمار|2618
منطقة يريم|2454
منطقة إب|2824
فرع الحديدة|1824
منطقة حجة|2276
منطقة صعدة|7622
منطقة رداع|2787
منطقة المحويت|2378
منطقة عمران|4992";
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,1,1,1)")
                ->execute([$rid,'area_unified','المنطقة','select',$elecOpts,'اختر منطقتك']);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,2)")
                ->execute([$rid,'amount','مبلغ السداد','amount','أدخل المبلغ بالريال اليمني']);
            $msg[] = 'الكهرباء: 3 حقول';
        }
        if ($waterRow) {
            $rid = (int)$waterRow['id'];
            $pdo->prepare("DELETE FROM fa_method_fields WHERE method_row_id=?")->execute([$rid]);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,0)")
                ->execute([$rid,'subscriber_number','رقم الاشتراك','text','أدخل رقم اشتراكك']);
            $waterOpts = "مؤسسة المياه - الامانة|0601
مؤسسة المياه - الامانة - الفروسية|0602
مؤسسة المياه - إب - المحافظة|0603
مؤسسة المياه - تعز - الحوبان|0604
مؤسسة المياه - ذمار - المحافظة|0605
مؤسسة المياه - الحديده - المحافظة|0606
مؤسسة المياه - الحديدة - الصليف|0607
مؤسسة المياه - الحديدة - المراوعه|0608
مؤسسة المياه - الحديدة - باجل|0609
مؤسسة المياه - المحويت|0609
مؤسسة المياه - شبام كوكبان|0611
مؤسسة المياه - المحويت - الظاهر|0612
مؤسسة المياه - إب - يريم|0656
مؤسسة المياه - الضالع - دمت|0660
مؤسسة المياه - حجه|0607
مؤسسة المياه - عمران|0608
مؤسسة المياه - الحديدة - زبيد|0610
مؤسسة المياه - بيت الفقيه|0614
مؤسسة المياه - المنصورية|0613";
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,field_options,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,?,1,1,1)")
                ->execute([$rid,'area_unified','المؤسسة','select',$waterOpts,'اختر مؤسستك']);
            $pdo->prepare("INSERT INTO fa_method_fields (method_row_id,field_key,field_label,field_type,placeholder,is_required,is_enabled,sort_order) VALUES (?,?,?,?,?,1,1,2)")
                ->execute([$rid,'amount','مبلغ السداد','amount','أدخل المبلغ بالريال اليمني']);
            $msg[] = 'المياه: 3 حقول';
        }
        flashMessage('success', '✅ تم الترحيل: ' . implode('، ', $msg));
    } catch(Exception $e) {
        flashMessage('danger', 'خطأ: ' . $e->getMessage());
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=methods');
}

// AJAX: جلب حقول مزود
if (isset($_GET['get_fa_fields']) && isset($_GET['row_id'])) {
    $rowId = (int)$_GET['row_id'];
    $st = $pdo->prepare("SELECT * FROM fa_method_fields WHERE method_row_id=? ORDER BY sort_order,id");
    $st->execute([$rowId]);
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'fields'=>$st->fetchAll()]);
    exit;
}

// حفظ/تعديل مزود
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_fa_method'])) {
    $mid   = (int)($_POST['fa_method_id_row'] ?? 0);
    $d     = $_POST;
    $data  = [
        'method_id'        => (int)$d['fa_method_id'],
        'name_ar'          => trim($d['fa_name_ar']),
        'name_en'          => trim($d['fa_name_en']),
        'transaction_type' => in_array($d['fa_type'],['TOPUP','BILLPAY'])?$d['fa_type']:'TOPUP',
        'icon'             => trim($d['fa_icon'] ?? 'sim-card'),
        'color'            => trim($d['fa_color'] ?? '#6c3fe0'),
        'status'           => isset($d['fa_status']) ? 1 : 0,
        'sort_order'       => (int)($d['fa_sort'] ?? 0),
    ];
    // رفع الشعار إذا وُجد
    $logoPath = null;
    if (!empty($_FILES['fa_logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['fa_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','svg','gif'])) {
            $dir = __DIR__.'/../uploads/network_logos/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'net_'.($data['method_id']).'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['fa_logo']['tmp_name'], $dir.$fname)) {
                $logoPath = 'uploads/network_logos/'.$fname;
                // احذف القديمة
                if ($mid) {
                    $old = $pdo->prepare("SELECT logo FROM floosak_agent_methods WHERE id=?");
                    $old->execute([$mid]);
                    $oldLogo = $old->fetchColumn();
                    if ($oldLogo && file_exists(__DIR__.'/../'.$oldLogo)) @unlink(__DIR__.'/../'.$oldLogo);
                }
            }
        }
    }

    if ($mid) {
        if ($logoPath) {
            $pdo->prepare("UPDATE floosak_agent_methods SET method_id=?,name_ar=?,name_en=?,transaction_type=?,icon=?,color=?,status=?,sort_order=?,logo=? WHERE id=?")
                ->execute([...array_values($data), $logoPath, $mid]);
        } else {
            $pdo->prepare("UPDATE floosak_agent_methods SET method_id=?,name_ar=?,name_en=?,transaction_type=?,icon=?,color=?,status=?,sort_order=? WHERE id=?")
                ->execute([...array_values($data), $mid]);
        }
        flashMessage('success','تم تحديث المزود ✓');
    } else {
        $pdo->prepare("INSERT INTO floosak_agent_methods (method_id,name_ar,name_en,transaction_type,icon,color,status,sort_order,logo) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([...array_values($data), $logoPath]);
        flashMessage('success','تمت إضافة المزود ✓');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=methods');
}

// حذف مزود
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_fa_method'])) {
    $mid = (int)($_POST['fa_method_id_row'] ?? 0);
    if ($mid) {
        $pdo->prepare("DELETE FROM floosak_agent_methods WHERE id=?")->execute([$mid]);
        $pdo->prepare("DELETE FROM floosak_agent_bunches WHERE method_id=(SELECT method_id FROM floosak_agent_methods WHERE id=?)")->execute([$mid]);
        flashMessage('success','تم الحذف');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=methods');
}

// حفظ/تعديل باقة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_fa_bunch'])) {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $bid = (int)($_POST['fa_bunch_row_id'] ?? 0);
    $d   = $_POST;
    $data = [
        'method_id'    => (int)$d['fa_b_method_id'],
        'bunch_id'     => trim($d['fa_b_bunch_id']) !== '' ? (int)$d['fa_b_bunch_id'] : null,
        'bunch_name'   => trim($d['fa_b_name']),
        'code'         => trim($d['fa_b_code']),
        'unified_code' => trim($d['fa_b_unified_code']),
        'fore_num'     => trim($d['fa_b_fore_num'] ?? '') ?: null,
        'price'        => trim($d['fa_b_price'])!=='' ? (float)$d['fa_b_price'] : null,
        'validity'     => trim($d['fa_b_validity']) ?: null,
        'section'      => in_array($d['fa_b_section']??'',['amount','fees','bundles','yemen4g_change','yemen4g_credit','yemen4g_internet','yemen4g_voice']) ? $d['fa_b_section'] : 'bundles',
        'payment_type' => in_array($d['fa_b_ptype']??'',['prepaid','postpaid','both']) ? $d['fa_b_ptype'] : 'both',
        'bundle_group' => trim($d['fa_b_group'] ?? '') ?: null,
        'is_free_amount' => isset($d['fa_b_is_free']) ? 1 : 0,
        'status'       => isset($d['fa_b_status']) ? 1 : 0,
        'sort_order'   => (int)($d['fa_b_sort'] ?? 0),
    ];
    try {
        if ($bid) {
            $pdo->prepare("UPDATE floosak_agent_bunches SET method_id=?,bunch_id=?,bunch_name=?,code=?,unified_code=?,fore_num=?,price=?,validity=?,section=?,payment_type=?,bundle_group=?,is_free_amount=?,status=?,sort_order=? WHERE id=?")
                ->execute([$data['method_id'],$data['bunch_id'],$data['bunch_name'],$data['code'],$data['unified_code'],$data['fore_num'],$data['price'],$data['validity'],$data['section'],$data['payment_type'],$data['bundle_group'],$data['is_free_amount'],$data['status'],$data['sort_order'],$bid]);
            $newId = $bid;
            $msg = 'تم تحديث الباقة ✓';
        } else {
            $pdo->prepare("INSERT INTO floosak_agent_bunches (method_id,bunch_id,bunch_name,code,unified_code,fore_num,price,validity,section,payment_type,bundle_group,is_free_amount,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$data['method_id'],$data['bunch_id'],$data['bunch_name'],$data['code'],$data['unified_code'],$data['fore_num'],$data['price'],$data['validity'],$data['section'],$data['payment_type'],$data['bundle_group'],$data['is_free_amount'],$data['status'],$data['sort_order']]);
            $newId = (int)$pdo->lastInsertId();
            $msg = 'تمت إضافة الباقة ✓';
        }
        // جلب الصف المحدث لإعادته
        $row = $pdo->prepare("SELECT b.*,m.name_ar as method_name FROM floosak_agent_bunches b LEFT JOIN floosak_agent_methods m ON b.method_id=m.method_id WHERE b.id=?");
        $row->execute([$newId]);
        $savedRow = $row->fetch(PDO::FETCH_ASSOC);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok'=>true,'msg'=>$msg,'id'=>$newId,'row'=>$savedRow]);
            exit;
        }
        flashMessage('success',$msg);
    } catch(Exception $e) {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }
        flashMessage('danger','خطأ: '.$e->getMessage());
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=bunches&filter_method='.$data['method_id']);
}

// حذف باقة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_fa_bunch'])) {
    $isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    $bid     = (int)($_POST['fa_bunch_row_id'] ?? 0);
    $fmethod = (int)($_POST['fa_b_method_id'] ?? 0);
    if ($bid) {
        $pdo->prepare("DELETE FROM floosak_agent_bunches WHERE id=?")->execute([$bid]);
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'msg'=>'تم الحذف']); exit; }
        flashMessage('success','تم حذف الباقة');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=bunches&filter_method='.$fmethod);
}

// ─── موافقة على طلب شحن ──────────────────────────────────────────────────────
if ($action === 'approve' && $id) {
    $req = $pdo->prepare("SELECT r.*, m.cashbox_id AS method_cashbox_id FROM topup_requests r JOIN payment_methods m ON m.id=r.method_id WHERE r.id=? AND r.status='pending'");
    $req->execute([$id]); $req = $req->fetch();
    if ($req) {
        // الصندوق لا يُؤخذ من POST؛ يحدده إعداد وسيلة الدفع حسب عملة الطلب.
        $currencyCode = strtoupper(trim((string)($req['currency_code'] ?? 'USD')));
        $cashboxAmount = (float)($req['amount_sent'] ?? 0);
        if ($cashboxAmount <= 0 && $currencyCode === 'USD') $cashboxAmount = (float)$req['amount_usd'];
        if ($cashboxAmount <= 0) {
            flashMessage('danger','تعذر اعتماد الإيداع: مبلغ القبض الفعلي غير صالح.');
            redirect(SITE_URL.'/admin/payments.php?tab=requests');
        }
        // Ensure mapping schema before opening the atomic approval transaction.
        // DDL inside the transaction can implicitly commit on MySQL/MariaDB.
        paymentMethodCashboxEnsureSchema($pdo);
        $cashboxResolution = paymentMethodCashboxResolve($pdo, (int)$req['method_id'], $currencyCode);
        $cashboxId = (int)($cashboxResolution['cashbox']['id'] ?? 0);
        if (empty($cashboxResolution['ok'])) {
            $setupMessages = [
                'cashbox_not_configured' => 'لا يوجد صندوق مربوط بعملة '.$currencyCode.' لهذه الوسيلة.',
                'cashbox_inactive' => 'الصندوق المربوط بعملة '.$currencyCode.' غير نشط.',
                'cashbox_currency_mismatch' => 'عملة الصندوق المربوط لا تطابق عملة الطلب.',
                'cashbox_account_invalid' => 'الصندوق المربوط بعملة '.$currencyCode.' غير مرتبط بحساب محاسبي نشط.',
                'cashbox_account_currency_mismatch' => 'عملة حساب الصندوق لا تطابق عملة الطلب.',
            ];
            flashMessage('danger','تعذر اعتماد الإيداع: '.($setupMessages[$cashboxResolution['reason'] ?? ''] ?? 'إعداد صندوق هذه العملة غير مكتمل.'));
            redirect(SITE_URL.'/admin/payments.php?tab=requests');
        }
        $post = null;
        $traceId = 'topup-' . $id . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $transactionCommitted = false;
        $pdo->beginTransaction();
        try {
            // قفل الطلب وإعادة فحص حالته يمنع اعتماداً متزامناً أو اعتماداً مكرراً.
            $lockReq = $pdo->prepare("SELECT * FROM topup_requests WHERE id=? FOR UPDATE");
            $lockReq->execute([$id]);
            $lockedReq = $lockReq->fetch(PDO::FETCH_ASSOC);
            if (!$lockedReq || ($lockedReq['status'] ?? '') !== 'pending') {
                throw new RuntimeException('topup_not_pending');
            }
            $req = $lockedReq;
            $currencyCode = strtoupper(trim((string)($req['currency_code'] ?? 'USD')));
            $cashboxAmount = (float)($req['amount_sent'] ?? 0);
            if ($cashboxAmount <= 0 && $currencyCode === 'USD') $cashboxAmount = (float)$req['amount_usd'];
            if ($cashboxAmount <= 0 || (float)$req['amount_usd'] <= 0) {
                throw new RuntimeException('invalid_deposit_amount');
            }
            $cashboxResolution = paymentMethodCashboxResolve($pdo, (int)$req['method_id'], $currencyCode);
            if (empty($cashboxResolution['ok'])) throw new RuntimeException('cashbox:' . (string)($cashboxResolution['reason'] ?? 'cashbox_not_configured'));
            $cashboxId = (int)$cashboxResolution['cashbox']['id'];

            // الترحيل المحاسبي يسبق الرصيد: أي فشل لاحق يعيد الحركة والقيد والرصيد معاً.
            $post = cashboxPostDeposit($pdo, $id, (int)$req['user_id'], $cashboxId, $cashboxAmount, $currencyCode, 'قبض إيداع — طلب #ID'.$id, (int)$_SESSION['user_id']);
            if (empty($post['posted'])) throw new RuntimeException('cashbox:' . (string)($post['reason'] ?? 'posting_failed'));

            // حارس إضافي للدفتر التشغيلي؛ المرجع يربط الشحن بالطلب نفسه.
            $duplicateCredit = $pdo->prepare("SELECT id FROM wallet_transactions WHERE user_id=? AND type='credit' AND reference_id=? AND sub_type='topup' LIMIT 1 FOR UPDATE");
            $duplicateCredit->execute([(int)$req['user_id'], $id]);
            if ($duplicateCredit->fetchColumn()) throw new RuntimeException('wallet_credit_already_exists');

            $approved = $pdo->prepare("UPDATE topup_requests SET status='approved',reviewed_by=?,reviewed_at=NOW(),admin_notes=? WHERE id=? AND status='pending'");
            $approved->execute([$_SESSION['user_id'], trim($_POST['admin_notes']??''), $id]);
            if ($approved->rowCount() !== 1) throw new RuntimeException('topup_status_update_failed');

            $u = $pdo->prepare("SELECT balance FROM users WHERE id=? FOR UPDATE");
            $u->execute([(int)$req['user_id']]);
            $u = $u->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new RuntimeException('user_missing');
            $oldBalance = (float)$u['balance'];
            $creditAmount = (float)$req['amount_usd'];
            $newBal = $oldBalance + $creditAmount;
            $balanceUpdate = $pdo->prepare("UPDATE users SET balance=? WHERE id=?");
            $balanceUpdate->execute([$newBal, (int)$req['user_id']]);
            if ($balanceUpdate->rowCount() !== 1) throw new RuntimeException('balance_update_failed');
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id,sub_type) VALUES (?,?,?,?,?,?,?,'topup')")
                ->execute([(int)$req['user_id'],'credit',$creditAmount,$oldBalance,$newBal,'شحن رصيد — طلب #'.$id,$id]);

            $pdo->commit();
            $transactionCommitted = true;
            try {
                logStaffAction($pdo,'approve_topup','topup',$id,'موافقة على شحن رصيد $'.$req['amount_usd'].' للعميل #'.$req['user_id'].' وترحيله للصندوق #'.$cashboxId);
            } catch (Throwable $postCommitError) {
                // The financial transaction is already committed; never report
                // a financial failure or attempt a rollback for audit logging.
                error_log('Topup post-commit audit log failed ['.$traceId.'] #'.$id.': '.$postCommitError->getMessage());
            }
        } catch (Throwable $e) {
            if ($transactionCommitted) {
                error_log('Topup post-commit exception ignored ['.$traceId.'] #'.$id.': '.$e->getMessage());
                flashMessage('success','✅ تمت الموافقة وإضافة $'.number_format((float)$req['amount_usd'],4).' لرصيد العميل');
                redirect(SITE_URL.'/admin/payments.php?tab=requests');
            }
            if ($pdo->inTransaction()) $pdo->rollBack();
            $technicalReason = $e->getMessage();
            $reasonCode = str_starts_with($technicalReason, 'cashbox:') ? substr($technicalReason, 8) : $technicalReason;
            $safeMessages = [
                'cashbox_not_configured' => 'لا يوجد صندوق مربوط بهذه العملة في إعدادات وسيلة الدفع.',
                'cashbox_inactive' => 'الصندوق المربوط بهذه العملة غير نشط.',
                'cashbox_account_invalid' => 'الصندوق غير مرتبط بحساب محاسبي نشط.',
                'cashbox_currency_mismatch' => 'عملة الصندوق لا تطابق عملة الإيداع.',
                'cashbox_account_currency_mismatch' => 'عملة حساب الصندوق لا تطابق عملة الإيداع.',
                'wallet_account_missing_for_currency' => 'لا يوجد حساب محافظ عملاء نشط بهذه العملة.',
                'deposit_already_posted_different_cashbox' => 'يوجد ترحيل سابق لهذا الطلب إلى صندوق آخر؛ لم يتكرر الشحن.',
                'existing_movement_without_journal' => 'توجد حركة سابقة بلا قيد محاسبي مكتمل؛ راجع سجل PHP.',
                'journal_missing' => 'تعذر إنشاء القيد المحاسبي؛ راجع سجل PHP.',
                'metadata_update_failed' => 'تعذر حفظ بيانات ترحيل الطلب؛ راجع سجل PHP.',
                'posting_failed' => 'تعذر إنشاء حركة الصندوق أو القيد المحاسبي؛ راجع سجل PHP.',
                'topup_not_pending' => 'الطلب لم يعد معلقاً؛ لم يتكرر اعتماد الإيداع.',
                'wallet_credit_already_exists' => 'يوجد شحن مسجل لهذا الطلب؛ لم يتكرر إضافة الرصيد.',
                'invalid_deposit_amount' => 'بيانات مبلغ الإيداع غير صالحة.',
                'topup_status_update_failed' => 'تعذر تحديث حالة الطلب؛ لم يكتمل الاعتماد.',
                'user_missing' => 'العميل غير موجود؛ لم يكتمل الاعتماد.',
                'balance_update_failed' => 'تعذر تحديث رصيد العميل؛ لم يكتمل الاعتماد.',
            ];
            $safeMessage = $safeMessages[$reasonCode] ?? 'تعذر إكمال الترحيل المحاسبي؛ لم يتم تغيير رصيد العميل. راجع سجل PHP.';
            try {
                $diag = $pdo->prepare("UPDATE topup_requests SET cashbox_posting_status='failed',cashbox_posting_reason=? WHERE id=? AND status='pending'");
                $diag->execute([substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $reasonCode), 0, 120), $id]);
            } catch (Throwable $diagError) {
                error_log('Topup diagnostic status update failed ['.$traceId.']: '.$diagError->getMessage());
            }
            error_log('Topup approval/cashbox posting failed ['.$traceId.'] #'.$id.' code='.$reasonCode.': '.$technicalReason);
            flashMessage('danger','تعذر اعتماد الإيداع: '.$safeMessage.' (مرجع '.$traceId.')');
            redirect(SITE_URL.'/admin/payments.php?tab=requests');
        }
        // ── إشعار الموافقة ──────────────────────────────────────────────────
        try { notifyTopupApproved($pdo, $req['user_id'], number_format($req['amount_usd'],2), 'USD'); } catch(Exception $e){}
        // ── عمولة الإحالة ───────────────────────────────────────────────────
        try { if (function_exists('applyReferralOnTopup')) applyReferralOnTopup($pdo, $req['user_id'], (float)$req['amount_usd']); } catch(Exception $e){}
        flashMessage('success','✅ تمت الموافقة وإضافة $'.number_format($req['amount_usd'],4).' لرصيد العميل');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=requests');
}

// ─── رفض طلب ─────────────────────────────────────────────────────────────────
if ($action === 'reject' && $id) {
    $rejReq = $pdo->prepare("SELECT * FROM topup_requests WHERE id=?"); $rejReq->execute([$id]); $rejReq = $rejReq->fetch();
    $rejNotes = trim($_POST['admin_notes']??'سبب غير محدد');
    $pdo->prepare("UPDATE topup_requests SET status='rejected',reviewed_by=?,reviewed_at=NOW(),admin_notes=? WHERE id=?")
        ->execute([$_SESSION['user_id'], $rejNotes, $id]);
    // ── إشعار الرفض ─────────────────────────────────────────────────────────
    if ($rejReq) { try { notifyTopupRejected($pdo, $rejReq['user_id'], number_format($rejReq['amount_usd'],2), 'USD', $rejNotes); } catch(Exception $e){} }
    flashMessage('danger','تم رفض الطلب');
    redirect(SITE_URL.'/admin/payments.php?tab=requests');
}

// ─── حفظ طريقة دفع ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_method'])) {
    $mid   = (int)($_POST['method_id'] ?? 0);
    $name  = trim($_POST['name']);
    $type  = $_POST['type'] ?? 'bank';
    $icon  = trim($_POST['icon'] ?? 'university');
    $color = trim($_POST['color'] ?? '#6c3fe0');
    $desc  = trim($_POST['description'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $status= isset($_POST['status']) ? 1 : 0;
    $mode  = in_array($_POST['payment_mode']??'manual', ['manual','auto']) ? $_POST['payment_mode'] : 'manual';
    // الربط الجديد: مفتاح العملة هو مصدر اختيار الصندوق عند اعتماد الطلب.
    $legacyCashboxId = (int)($_POST['cashbox_id'] ?? 0);
    $cashboxByCurrency = is_array($_POST['cashbox_by_currency'] ?? null) ? $_POST['cashbox_by_currency'] : [];
    $allowedCurrencies = is_array($_POST['allowed_currencies'] ?? null) ? $_POST['allowed_currencies'] : [];
    // يبقى الربط القديم محفوظاً للتوافق مع الوسائل التي لم تُرحّل إلى خريطة العملات بعد.
    $cashboxId = $legacyCashboxId;
    if ($legacyCashboxId > 0) {
        $legacyCheck = $pdo->prepare("SELECT id FROM accounting_cashboxes WHERE id=? AND status='active' LIMIT 1");
        $legacyCheck->execute([$legacyCashboxId]);
        if (!$legacyCheck->fetchColumn()) {
            flashMessage('danger', 'الصندوق القديم المرتبط غير موجود أو متوقف؛ اختر صندوقاً صالحاً لكل عملة قبل الحفظ.');
            redirect(SITE_URL.'/admin/payments.php?tab=methods'.($mid ? '&action=edit_method&id='.$mid : ''));
        }
    }

    // رفع الصورة
    $imagePath = null;
    if (!empty($_FILES['method_image']['tmp_name'])) {
        $uploadDir = dirname(__DIR__) . '/uploads/payment_methods/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['method_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','svg']) && $_FILES['method_image']['size'] <= 2*1024*1024) {
            $fileName = 'pm_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['method_image']['tmp_name'], $uploadDir . $fileName)) {
                $imagePath = 'uploads/payment_methods/' . $fileName;
                if ($mid) {
                    $oldImg = $pdo->prepare("SELECT image FROM payment_methods WHERE id=?");
                    $oldImg->execute([$mid]); $oldImg = $oldImg->fetchColumn();
                    if ($oldImg && file_exists(dirname(__DIR__) . '/' . $oldImg)) @unlink(dirname(__DIR__) . '/' . $oldImg);
                }
            }
        }
    }

    if ($mid) {
        if ($imagePath) {
            $pdo->prepare("UPDATE payment_methods SET name=?,type=?,icon=?,color=?,description=?,sort_order=?,status=?,image=?,payment_mode=?,cashbox_id=? WHERE id=?")
                ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$imagePath,$mode,$cashboxId ?: null,$mid]);
        } else {
            $pdo->prepare("UPDATE payment_methods SET name=?,type=?,icon=?,color=?,description=?,sort_order=?,status=?,payment_mode=?,cashbox_id=? WHERE id=?")
                ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$mode,$cashboxId ?: null,$mid]);
        }
    } else {
        $pdo->prepare("INSERT INTO payment_methods (name,type,icon,color,description,sort_order,status,image,payment_mode,cashbox_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$imagePath,$mode,$cashboxId ?: null]);
        $mid = $pdo->lastInsertId();
    }

    // حفظ العملات المسموحة وربط صندوق مستقل لكل عملة.
    try {
        $activeCurrencyRows = $pdo->query("SELECT currency_code FROM exchange_rates WHERE status=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        paymentMethodCurrencySave($pdo, (int)$mid, $allowedCurrencies, $activeCurrencyRows);
        paymentMethodCashboxSave($pdo, (int)$mid, $cashboxByCurrency, $activeCurrencyRows);
    } catch (Throwable $e) {
        error_log('Payment method currency/cashbox save failed: ' . $e->getMessage());
        flashMessage('danger', 'تم حفظ بيانات الطريقة لكن تعذر حفظ ربط العملات والصناديق: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        redirect(SITE_URL.'/admin/payments.php?tab=methods'.($mid ? '&action=edit_method&id='.$mid : ''));
    }

    // حفظ الحقول
    $pdo->prepare("DELETE FROM payment_method_fields WHERE method_id=?")->execute([$mid]);
    $labels   = $_POST['field_label']  ?? [];
    $values   = $_POST['field_value']  ?? [];
    $copyable = $_POST['field_copy']   ?? [];
    foreach ($labels as $i => $lbl) {
        if (empty($lbl) || empty($values[$i])) continue;
        $pdo->prepare("INSERT INTO payment_method_fields (method_id,field_label,field_value,copyable,sort_order) VALUES (?,?,?,?,?)")
            ->execute([$mid, $lbl, $values[$i], in_array($i,$copyable)?1:0, $i]);
    }
    flashMessage('success','تم حفظ طريقة الدفع');
    redirect(SITE_URL.'/admin/payments.php?tab=methods');
}

// ─── حذف طريقة دفع ───────────────────────────────────────────────────────────
if ($action === 'delete_method' && $id) {
    $pdo->prepare("DELETE FROM payment_method_fields WHERE method_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM payment_methods WHERE id=?")->execute([$id]);
    flashMessage('success','تم حذف طريقة الدفع');
    redirect(SITE_URL.'/admin/payments.php?tab=methods');
}

// ─── تبديل حالة طريقة دفع ───────────────────────────────────────────────────
if ($action === 'toggle_method' && $id) {
    $pdo->prepare("UPDATE payment_methods SET status=IF(status=1,0,1) WHERE id=?")->execute([$id]);
    redirect(SITE_URL.'/admin/payments.php?tab=methods');
}

// ─── حفظ أسعار الصرف ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_rates'])) {
    $codes        = (array)($_POST['code'] ?? []);
    $names        = (array)($_POST['cname'] ?? []);
    $symbols      = (array)($_POST['symbol'] ?? []);
    $rateValues   = (array)($_POST['rate'] ?? []);
    $statuses     = (array)($_POST['cstatus'] ?? []);
    $deletedIds   = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['deleted_rate_ids'] ?? [])))));
    $errors       = [];
    $seenIds      = [];
    $seenCodes    = [];

    try {
        $pdo->beginTransaction();
        $position = 0;
        foreach ($codes as $rowKey => $rawCode) {
            $code = strtoupper(trim((string)$rawCode));
            $name = trim((string)($names[$rowKey] ?? ''));
            $symbol = trim((string)($symbols[$rowKey] ?? ''));
            $rateToUsd = (float)($rateValues[$rowKey] ?? 0);
            $rowId = ctype_digit((string)$rowKey) ? (int)$rowKey : 0;

            if ($code === '' && $name === '' && $symbol === '' && $rateToUsd <= 0) continue;
            if ($code === '' || $name === '' || $symbol === '' || $rateToUsd <= 0) {
                throw new InvalidArgumentException('أكمل بيانات كل عملة أو احذف صفها قبل الحفظ.');
            }
            if (!preg_match('/^[\p{L}\p{N} _-]{1,10}$/u', $code)) {
                throw new InvalidArgumentException('كود العملة غير صالح: '.$code);
            }
            if (isset($seenCodes[$code])) {
                throw new InvalidArgumentException('كود العملة مكرر داخل النموذج: '.$code);
            }
            $seenCodes[$code] = true;

            if ($rowId > 0) {
                if (isset($seenIds[$rowId])) throw new InvalidArgumentException('صف العملة مكرر داخل النموذج.');
                $seenIds[$rowId] = true;
                $existing = $pdo->prepare('SELECT id,currency_code FROM exchange_rates WHERE id=? FOR UPDATE');
                $existing->execute([$rowId]);
                $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
                if (!$existingRow) throw new InvalidArgumentException('صف العملة المطلوب تعديله غير موجود.');
                if (strtoupper((string)$existingRow['currency_code']) === 'USD' && $code !== 'USD') {
                    throw new InvalidArgumentException('لا يمكن تغيير كود الدولار الأساسي.');
                }
                $stmt = $pdo->prepare('UPDATE exchange_rates SET currency_code=?,currency_name=?,currency_symbol=?,rate_to_usd=?,status=?,sort_order=? WHERE id=?');
                $stmt->execute([$code,$name,$symbol,$rateToUsd,isset($statuses[$rowKey]) ? 1 : 0,$position,$rowId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO exchange_rates (currency_code,currency_name,currency_symbol,rate_to_usd,status,sort_order) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$code,$name,$symbol,$rateToUsd,isset($statuses[$rowKey]) ? 1 : 0,$position]);
            }
            $position++;
        }

        foreach ($deletedIds as $deletedId) {
            if (isset($seenIds[$deletedId])) continue;
            $stmt = $pdo->prepare('SELECT currency_code FROM exchange_rates WHERE id=? FOR UPDATE');
            $stmt->execute([$deletedId]);
            $deletedCode = $stmt->fetchColumn();
            if ($deletedCode === false) continue;
            if (strtoupper((string)$deletedCode) === 'USD') {
                throw new InvalidArgumentException('لا يمكن حذف الدولار الأساسي.');
            }
            $pdo->prepare('DELETE FROM exchange_rates WHERE id=?')->execute([$deletedId]);
        }
        $pdo->commit();
        try {
            cashboxEnsureCurrencyDefaults($pdo);
            flashMessage('success','تم حفظ أسعار الصرف وتجهيز الصندوق العادي والتشغيلي لكل عملة وتطبيق التعديلات والحذف فعلياً');
        } catch (Throwable $provisioningError) {
            error_log('Currency cashbox provisioning after rates save failed: '.$provisioningError->getMessage());
            flashMessage('warning','تم حفظ أسعار الصرف، لكن تعذر تجهيز الصناديق التلقائية؛ راجع سجل PHP قبل اعتماد الإيداعات.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Exchange rates save failed: '.$e->getMessage());
        $safeMessage = $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر حفظ أسعار الصرف؛ لم يتم تطبيق أي تعديل.';
        flashMessage('danger', $safeMessage);
    }
    redirect(SITE_URL.'/admin/payments.php?tab=rates');
}

// ─── جلب البيانات ─────────────────────────────────────────────────────────────
try {
    $requests = $pdo->query("
        SELECT r.*,u.username,u.full_name,m.name as method_name,m.icon as method_icon,m.cashbox_id AS method_cashbox_id,
               e.currency_symbol,e.currency_name,
               req_cb.name AS request_cashbox_name,req_cb.code AS request_cashbox_code
        FROM topup_requests r
        JOIN users u ON r.user_id=u.id
        JOIN payment_methods m ON r.method_id=m.id
        JOIN exchange_rates e ON r.currency_code=e.currency_code
        LEFT JOIN payment_method_cashboxes req_pmc
          ON req_pmc.method_id=m.id AND UPPER(req_pmc.currency_code)=UPPER(r.currency_code)
        LEFT JOIN accounting_cashboxes req_cb ON req_cb.id=req_pmc.cashbox_id
        ORDER BY r.created_at DESC LIMIT 100
    ")->fetchAll();
} catch(\PDOException $e) { $requests = []; }

try {
    $methods = $pdo->query("SELECT m.*, COUNT(f.id) as field_count FROM payment_methods m LEFT JOIN payment_method_fields f ON m.id=f.method_id GROUP BY m.id ORDER BY m.sort_order")->fetchAll();
} catch(\PDOException $e) { $methods = []; }

$methodCurrencyMap = [];
try {
    $methodCurrencyMap = paymentMethodCurrencyMap($pdo, array_map(fn($m) => (int)$m['id'], $methods));
    foreach ($methods as &$methodRow) $methodRow['allowed_currency_codes'] = $methodCurrencyMap[(int)$methodRow['id']] ?? [];
    unset($methodRow);
} catch (Throwable $e) { error_log('Payment method currencies load failed: ' . $e->getMessage()); }

try {
    $cashboxes = $pdo->query("SELECT c.id,c.code,c.name,c.currency_code,u.full_name AS staff_name FROM accounting_cashboxes c LEFT JOIN users u ON u.id=c.staff_id WHERE c.status='active' ORDER BY c.name")->fetchAll();
} catch(\PDOException $e) { $cashboxes = []; }

try {
    $rates = $pdo->query("SELECT * FROM exchange_rates ORDER BY sort_order")->fetchAll();
} catch(\PDOException $e) { $rates = []; }

$methodCashboxMap = [];
try {
    $methodCashboxMap = paymentMethodCashboxMap($pdo, array_map(fn($m) => (int)$m['id'], $methods));
} catch (Throwable $e) { error_log('Payment method cashboxes load failed: ' . $e->getMessage()); }

// للتعديل
$editMethod = null; $editFields = [];
$editAllowedCurrencies = $methodCurrencyMap[$id] ?? [];
$editCashboxByCurrency = $methodCashboxMap[$id] ?? [];
if ($action === 'edit_method' && $id) {
    $s = $pdo->prepare("SELECT * FROM payment_methods WHERE id=?"); $s->execute([$id]); $editMethod=$s->fetch();
    $f = $pdo->prepare("SELECT * FROM payment_method_fields WHERE method_id=? ORDER BY sort_order"); $f->execute([$id]); $editFields=$f->fetchAll();
}

// إحصاء
$pendingCount = count(array_filter($requests, fn($r)=>$r['status']==='pending'));

// إعدادات فلوسك
$floosakCfg = floosak_get_config($pdo);
try {
    $floosakSessions = $pdo->query("SELECT s.*, u.username, u.full_name FROM floosak_topup_sessions s JOIN users u ON s.user_id=u.id ORDER BY s.created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) { $floosakSessions = []; }

include 'header.php';
?>
<style>
.pay-tabs{display:flex;gap:6px;margin-bottom:1.5rem;background:var(--card2);border-radius:12px;padding:5px}
.pay-tab{flex:1;padding:9px;border-radius:9px;cursor:pointer;font-weight:700;font-size:.85rem;color:var(--text2);text-align:center;transition:.2s;border:none;background:none;font-family:var(--font)}
.pay-tab.active{background:var(--primary);color:#fff;box-shadow:0 2px 12px rgba(108,63,224,.4)}
.req-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.req-amount{font-size:1.4rem;font-weight:900;color:#00d4aa}
.req-status{padding:3px 12px;border-radius:20px;font-size:11px;font-weight:800}
.req-status.pending{background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3)}
.req-status.approved{background:rgba(0,212,170,.15);color:#00d4aa;border:1px solid rgba(0,212,170,.3)}
.req-status.rejected{background:rgba(255,68,85,.15);color:#ff4455;border:1px solid rgba(255,68,85,.3)}
.method-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:14px}
.method-icon-box{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.rate-row{display:grid;grid-template-columns:100px 1fr 80px 120px 60px 40px;gap:8px;align-items:center;margin-bottom:8px;background:var(--card2);border-radius:10px;padding:10px 12px;border:1px solid var(--border)}
.field-row{display:grid;grid-template-columns:1fr 2fr 80px 30px;gap:8px;align-items:center;margin-bottom:6px;background:var(--bg);border-radius:8px;padding:8px 10px;border:1px solid var(--border2)}
.receipt-thumb{width:60px;height:50px;border-radius:8px;object-fit:cover;border:1px solid var(--border);cursor:pointer}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-money-bill-wave" style="color:#00d4aa"></i></div>
      طرق الدفع وشحن الرصيد
    </div>
  </div>
</div>

<div class="pay-tabs">
  <button class="pay-tab <?=$tab==='requests'?'active':''?>" onclick="switchTab('requests')">
    <i class="fas fa-inbox"></i> طلبات الشحن
    <?php if($pendingCount): ?><span style="background:#f5a623;color:#000;padding:1px 7px;border-radius:10px;font-size:10px;margin-right:4px"><?=$pendingCount?></span><?php endif; ?>
  </button>
  <button class="pay-tab <?=$tab==='methods'?'active':''?>" onclick="switchTab('methods')"><i class="fas fa-credit-card"></i> طرق الدفع</button>
  <button class="pay-tab <?=$tab==='rates'?'active':''?>" onclick="switchTab('rates')"><i class="fas fa-exchange-alt"></i> أسعار الصرف</button>
  <button class="pay-tab <?=$tab==='floosak'?'active':''?>" onclick="switchTab('floosak')">
    <i class="fas fa-bolt"></i> فلوسك
    <?php if(!empty($floosakCfg['floosak_enabled']) && $floosakCfg['floosak_enabled']==='1'): ?>
    <span style="background:#00d4aa;color:#000;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">✓</span>
    <?php else: ?>
    <span style="background:#ff4455;color:#fff;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">!</span>
    <?php endif; ?>
  </button>
  <button class="pay-tab <?=$tab==='sadad'?'active':''?>" onclick="switchTab('sadad')">
    <i class="fas fa-list-alt"></i> قائمة سداد
  </button>
  <button class="pay-tab <?=$tab==='fore'?'active':''?>" onclick="switchTab('fore')">
    <i class="fas fa-bolt"></i> Fore Yemen
    <?php
    $foreEnabled = getSetting('fore_enabled');
    if($foreEnabled === '1'):
    ?><span style="background:#00d4aa;color:#000;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">✓</span>
    <?php else: ?>
    <span style="background:#ff4455;color:#fff;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">!</span>
    <?php endif; ?>
  </button>
  <button class="pay-tab <?=$tab==='floosak_agent'?'active':''?>" onclick="switchTab('floosak_agent')">
    <i class="fas fa-sim-card"></i> وكيل الشحن
    <?php
    $agCfg = floosak_agent_get_config($pdo);
    if(!empty($agCfg['floosak_agent_enabled']) && $agCfg['floosak_agent_enabled']==='1'):
    ?>
    <span style="background:#00d4aa;color:#000;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">✓</span>
    <?php else: ?>
    <span style="background:#ff4455;color:#fff;padding:1px 6px;border-radius:10px;font-size:9px;margin-right:3px">!</span>
    <?php endif; ?>
  </button>
</div>

<?php include __DIR__ . '/payments_tabs/tab_requests.php'; ?>

<?php include __DIR__ . '/payments_tabs/tab_methods.php'; ?>

<?php include __DIR__ . '/payments_tabs/tab_rates.php'; ?>
<?php include __DIR__ . '/payments_tabs/tab_floosak.php'; ?>
<?php include __DIR__ . '/payments_tabs/tab_floosak_agent.php'; ?>

<?php include __DIR__ . '/payments_tabs/tab_sadad.php'; ?>

<?php include __DIR__ . '/payments_tabs/tab_fore.php'; ?>

<script>
// ══ الحقول الديناميكية للمزودين ══════════════════════════════
let currentFieldsRowId = 0;
let faFieldIndex = 0;

function openFieldsModal(rowId, name) {
  currentFieldsRowId = rowId;
  faFieldIndex = 0;
  document.getElementById('fieldsModalTitle').textContent = name;
  document.getElementById('faFieldsContainer').innerHTML = '';
  document.getElementById('faEmptyMsg') && document.getElementById('faEmptyMsg').remove();
  // أضف msg مؤقتة
  document.getElementById('faFieldsContainer').innerHTML =
    '<div style="text-align:center;padding:1.5rem;color:#8895a7"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</div>';
  document.getElementById('fieldsModal').classList.add('open');

  // جلب الحقول
  fetch(`<?=SITE_URL?>/admin/payments.php?get_fa_fields=1&row_id=${rowId}`)
    .then(r=>r.json()).then(d=>{
      document.getElementById('faFieldsContainer').innerHTML = '';
      if (d.ok && d.fields.length) {
        d.fields.forEach(f => addFaFieldRow(f));
      } else {
        document.getElementById('faFieldsContainer').innerHTML =
          '<div id="faEmptyMsg" style="text-align:center;padding:2rem;color:#8895a7;border:2px dashed rgba(255,255,255,.07);border-radius:10px"><i class="fas fa-layer-group" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>لا توجد حقول — اضغط "إضافة حقل"</div>';
      }
      updateFaPreview();
    });
}

function closeFieldsModal() {
  document.getElementById('fieldsModal').classList.remove('open');
}

function addFaFieldRow(data) {
  document.getElementById('faEmptyMsg') && document.getElementById('faEmptyMsg').remove();
  const i = faFieldIndex++;
  const f = data || {};
  const row = document.createElement('div');
  row.className = 'fa-field-row';
  row.dataset.i = i;
  row.style.cssText = 'background:#161b2e;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:16px;position:relative';

  const typeSel = Object.entries({text:'📝 نص',number:'🔢 رقم',select:'📋 قائمة',textarea:'📄 نص طويل',checkbox:'☑️ مربع تأكيد',amount:'💰 مبلغ السداد',counter:'🔢 عداد أشخاص (زكاة)'})
    .map(([v,l])=>`<option value="${v}" ${f.field_type===v?'selected':''}>${l}</option>`).join('');

  // بناء خيارات القائمة كنص
  let optsTxt = '';
  if (f.field_options) {
    try {
      const parsed = JSON.parse(f.field_options);
      if (Array.isArray(parsed)) optsTxt = parsed.map(o=>`${o.label||o.value}|${o.value}`).join('\n');
      else optsTxt = f.field_options;
    } catch(e) { optsTxt = f.field_options; }
  }

  row.innerHTML = `
    <input type="hidden" class="mf-id" value="${f.id||0}">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <span style="cursor:grab;color:#444;font-size:1.1rem">⠿</span>
      <strong class="fa-field-preview-label" style="flex:1;font-size:.88rem">${f.field_label||'حقل جديد'}</strong>
      <span style="font-size:.68rem;padding:2px 8px;border-radius:8px;background:${f.is_enabled!==false&&f.is_enabled!=0?'rgba(0,212,170,.12)':'rgba(255,68,85,.12)'};color:${f.is_enabled!==false&&f.is_enabled!=0?'#00d4aa':'#ff4455'}" class="fa-en-badge">
        ${f.is_enabled!==false&&f.is_enabled!=0?'مفعّل':'معطّل'}
      </span>
      <button type="button" onclick="this.closest('.fa-field-row').remove();updateFaPreview()"
              style="background:rgba(255,68,85,.12);border:1px solid rgba(255,68,85,.2);color:#ff4455;border-radius:7px;padding:3px 9px;cursor:pointer;font-size:.78rem">
        <i class="fas fa-trash"></i>
      </button>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
      <div>
        <label style="font-size:.7rem;color:#8895a7">المفتاح (key)*</label>
        <input type="text" class="mf-key form-control" value="${f.field_key||''}" placeholder="subscriber_number"
               style="font-family:monospace;font-size:.82rem" required>
      </div>
      <div>
        <label style="font-size:.7rem;color:#8895a7">التسمية للعميل*</label>
        <input type="text" class="mf-label form-control" value="${f.field_label||''}" placeholder="رقم المشترك"
               oninput="this.closest('.fa-field-row').querySelector('.fa-field-preview-label').textContent=this.value||'حقل جديد';updateFaPreview()" required>
      </div>
      <div>
        <label style="font-size:.7rem;color:#8895a7">نوع الحقل</label>
        <select class="mf-type form-control" onchange="onFaTypeChange(this)">${typeSel}</select>
      </div>
      <div>
        <label style="font-size:.7rem;color:#8895a7" class="fa-plach-label">Placeholder</label>
        <input type="text" class="mf-placeholder form-control" value="${f.placeholder||''}" placeholder="نص توضيحي"
               oninput="updateFaPreview()">
      </div>
      <div style="display:flex;align-items:center;gap:14px;padding-top:20px">
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.83rem">
          <input type="checkbox" class="mf-required" ${f.is_required||f.is_required===undefined?'checked':''}
                 onchange="updateFaPreview()"> إجباري
        </label>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.83rem">
          <input type="checkbox" class="mf-enabled" ${f.is_enabled!==false&&f.is_enabled!=0?'checked':''}
                 onchange="this.closest('.fa-field-row').querySelector('.fa-en-badge').textContent=this.checked?'مفعّل':'معطّل';this.closest('.fa-field-row').querySelector('.fa-en-badge').style.background=this.checked?'rgba(0,212,170,.12)':'rgba(255,68,85,.12)';this.closest('.fa-field-row').querySelector('.fa-en-badge').style.color=this.checked?'#00d4aa':'#ff4455';updateFaPreview()"> مفعّل
        </label>
      </div>
      <div>
        <label style="font-size:.7rem;color:#8895a7">الترتيب</label>
        <input type="number" class="mf-sort form-control" value="${f.sort_order||0}" min="0">
      </div>
    </div>
    <div class="fa-options-wrap" style="margin-top:10px;${(f.field_type||'text')==='select'?'':'display:none'}">
      <label style="font-size:.7rem;color:#8895a7">خيارات القائمة (سطر لكل خيار: تسمية|قيمة)</label>
      <textarea class="mf-options form-control" rows="4" placeholder="صنعاء|1&#10;عدن|2&#10;تعز|3"
                style="font-family:monospace;font-size:.78rem;resize:vertical"
                oninput="updateFaPreview()">${optsTxt}</textarea>
      <small style="color:#8895a7">مثال: <code>صنعاء|1</code> أو <code>صنعاء</code> (بدون قيمة)</small>
    </div>`;
  document.getElementById('faFieldsContainer').appendChild(row);
  document.getElementById('fieldsCount').textContent =
    document.querySelectorAll('.fa-field-row').length;
  updateFaPreview();
}

function onFaTypeChange(sel) {
  const row = sel.closest('.fa-field-row');
  row.querySelector('.fa-options-wrap').style.display = sel.value === 'select' ? '' : 'none';
  // counter: placeholder = سعر الشخص
  const plachLabel = row.querySelector('.fa-plach-label');
  if (plachLabel) {
    plachLabel.textContent = sel.value === 'counter' ? 'سعر الشخص الواحد (ريال)' : 'Placeholder';
  }
  const plachInp = row.querySelector('.mf-placeholder');
  if (plachInp) {
    plachInp.placeholder = sel.value === 'counter' ? '550' : 'نص توضيحي';
    plachInp.type = sel.value === 'counter' ? 'number' : 'text';
  }
  updateFaPreview();
}

function updateFaPreview() {
  const rows = document.querySelectorAll('.fa-field-row');
  const area = document.getElementById('faPreview');
  if (!rows.length) {
    area.innerHTML = '<p style="color:#8895a7;font-size:.8rem;text-align:center">أضف حقلاً لترى المعاينة</p>';
    return;
  }
  let html = '';
  rows.forEach(row => {
    const label   = row.querySelector('.mf-label')?.value || 'حقل';
    const type    = row.querySelector('.mf-type')?.value || 'text';
    const plach   = row.querySelector('.mf-placeholder')?.value || '';
    const req     = row.querySelector('.mf-required')?.checked;
    const enabled = row.querySelector('.mf-enabled')?.checked;
    if (!enabled) return;
    html += `<div style="margin-bottom:12px">
      <label style="display:block;font-size:.75rem;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:5px">
        ${label}${req?' <span style=color:#ef4444>*</span>':''}
      </label>`;
    if (type === 'select') {
      const opts = (row.querySelector('.mf-options')?.value||'').split('\n').filter(Boolean);
      html += `<select style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:9px;padding:9px 12px;font-size:.85rem" disabled>
        <option>— اختر —</option>
        ${opts.map(o=>{const[l]=o.split('|');return`<option>${(l||'').trim()}</option>`;}).join('')}
      </select>`;
    } else if (type === 'textarea') {
      html += `<textarea style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:9px;padding:9px 12px;font-size:.85rem" rows="3" placeholder="${plach}" disabled></textarea>`;
    } else if (type === 'checkbox') {
      html += `<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" disabled> ${plach||label}</label>`;
    } else if (type === 'amount') {
      html += `<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
        ${['500','1000','2000','5000','10000'].map(v=>`<span style="padding:5px 12px;border-radius:20px;border:1px solid rgba(255,255,255,.15);font-size:.75rem;cursor:pointer">${v}</span>`).join('')}
      </div>`;
      html += `<input type="number" style="width:100%;background:#1a1f35;border:2px solid #2563eb;color:#fff;border-radius:9px;padding:10px 12px;font-size:.95rem;font-weight:700;text-align:center" placeholder="${plach||'أدخل المبلغ'}" disabled>`;
    } else if (type === 'counter') {
      const pricePerPerson = parseFloat(plach) || 0;
      html += `<div style="display:flex;align-items:center;gap:12px;background:#1a1f35;border:1px solid rgba(255,255,255,.1);border-radius:9px;padding:10px 14px">
        <button style="width:36px;height:36px;border-radius:50%;background:rgba(255,68,85,.15);border:1px solid rgba(255,68,85,.3);color:#ff4455;font-size:1.2rem;cursor:pointer" disabled>-</button>
        <span style="font-size:1.4rem;font-weight:900;flex:1;text-align:center;color:#fff">1</span>
        <button style="width:36px;height:36px;border-radius:50%;background:rgba(0,212,170,.15);border:1px solid rgba(0,212,170,.3);color:#00d4aa;font-size:1.2rem;cursor:pointer" disabled>+</button>
      </div>`;
      if (pricePerPerson > 0) {
        html += `<div style="font-size:.75rem;color:#8895a7;margin-top:5px;text-align:center">سعر الشخص الواحد: ${pricePerPerson.toLocaleString()} ريال</div>`;
      }
    } else {
      html += `<input type="${type}" style="width:100%;background:#1a1f35;border:1px solid rgba(255,255,255,.1);color:#fff;border-radius:9px;padding:9px 12px;font-size:.85rem" placeholder="${plach}" disabled>`;
    }
    html += '</div>';
  });
  area.innerHTML = html || '<p style="color:#8895a7;font-size:.8rem;text-align:center">لا حقول مفعّلة</p>';
}

function saveFaFields() {
  const rows = document.querySelectorAll('.fa-field-row');
  const fd   = new FormData();
  fd.append('save_fa_method_fields', '1');
  fd.append('fa_mf_row_id', currentFieldsRowId);

  rows.forEach((row, i) => {
    const key   = row.querySelector('.mf-key')?.value?.trim();
    const label = row.querySelector('.mf-label')?.value?.trim();
    if (!key || !label) return; // تخطَّ الحقول الفارغة
    fd.append('mf_id[]',          row.querySelector('.mf-id')?.value || '0');
    fd.append('mf_key[]',         key);
    fd.append('mf_label[]',       label);
    fd.append('mf_type[]',        row.querySelector('.mf-type')?.value || 'text');
    fd.append('mf_options[]',     row.querySelector('.mf-options')?.value || '');
    fd.append('mf_placeholder[]', row.querySelector('.mf-placeholder')?.value || '');
    fd.append('mf_sort[]',        row.querySelector('.mf-sort')?.value || i);
    fd.append('mf_required[]',    row.querySelector('.mf-required')?.checked ? '1' : '0');
    fd.append('mf_enabled[]',     row.querySelector('.mf-enabled')?.checked  ? '1' : '0');
  });

  fetch('<?=SITE_URL?>/admin/payments.php', {method:'POST', body:fd})
    .then(r=>r.json()).then(d=>{
      if (d.ok) {
        closeFieldsModal();
        // حدّث badge العداد
        const badge = document.querySelector(`button[onclick*="openFieldsModal(${currentFieldsRowId},"] span`);
        const enabledCount = document.querySelectorAll('.fa-field-row .mf-enabled:checked').length;
        if (badge) badge.textContent = enabledCount;
        showToastMsg('✅ ' + (d.msg||'تم حفظ الحقول'), 'success');
      } else {
        showToastMsg('❌ ' + (d.msg||'خطأ'), 'error');
      }
    }).catch(()=>showToastMsg('خطأ في الاتصال','error'));
}

function showToastMsg(msg, type) {
  const t = document.createElement('div');
  t.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:${type==='success'?'rgba(0,212,170,.15)':'rgba(255,68,85,.15)'};border:1px solid ${type==='success'?'#00d4aa':'#ff4455'};color:${type==='success'?'#00d4aa':'#ff4455'};padding:10px 22px;border-radius:12px;font-weight:700;font-size:.88rem;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,.4)`;
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(()=>t.remove(), 3000);
}

function previewSadadImg(input, previewId) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById(previewId);
    if (img) { img.src = e.target.result; img.style.display = 'block'; }
  };
  reader.readAsDataURL(input.files[0]);
}

function previewNetLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('fa_logo_preview');
    prev.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:contain;border-radius:12px;padding:2px">`;
  };
  reader.readAsDataURL(input.files[0]);
}

function openMethodModal(id, data) {
  document.getElementById('methodModal').classList.add('open');
  document.getElementById('methodModalTitle').innerHTML = id ? '<i class="fas fa-edit"></i> تعديل مزود' : '<i class="fas fa-plus"></i> مزود جديد';
  document.getElementById('fa_method_id_row').value = id || 0;
  if (data) {
    document.getElementById('fa_method_id').value = data.method_id || '';
    document.getElementById('fa_name_ar').value   = data.name_ar   || '';
    document.getElementById('fa_name_en').value   = data.name_en   || '';
    document.getElementById('fa_type').value      = data.transaction_type || 'TOPUP';
    document.getElementById('fa_icon').value      = data.icon      || 'sim-card';
    document.getElementById('fa_color').value     = data.color     || '#6c3fe0';
    document.getElementById('fa_sort').value      = data.sort_order || 0;
    document.getElementById('fa_status').checked  = data.status == 1;
    const prev = document.getElementById('fa_logo_preview');
    if (data.logo) {
      const SURL = '<?=SITE_URL?>';
      prev.innerHTML = `<img src="${SURL}/${data.logo}" style="width:100%;height:100%;object-fit:contain;border-radius:12px;padding:2px">`;
    } else {
      prev.innerHTML = '<i class="fas fa-image" style="color:#4d6080"></i>';
    }
  } else {
    document.getElementById('methodForm').reset();
    document.getElementById('fa_method_id_row').value = 0;
    document.getElementById('fa_logo_preview').innerHTML = '<i class="fas fa-image" style="color:#4d6080"></i>';
  }
}
// مجموعات الباقات الشائعة لكل شركة
const BUNCH_GROUPS = {
  1: ['باقات مزايا فورجي','باقات نت فورجي','باقات فولتي فورجي','باقات مزايا ثريجي','باقات نت ثريجي','انترنت داتا (EVDO) - باقات مودم'],
  2: ['سوبر نت','يابلاش','تواصل وواتساب','انترنت فورجي','باقات رسائل','سبأنت'],
  3: ['باقات مكس','باقات سمارت','وفر فورجي','واتساب وتواصل','باقات تجوال'],
  12: ['باقات كرم','باقات الو'],
};

function toggleFreeAmount(sec) {
  const isAmount = (sec === 'amount' || sec === 'yemen4g_credit');
  const isBundles = (sec === 'bundles' || sec === 'yemen4g_change' || sec === 'yemen4g_internet' || sec === 'yemen4g_voice');
  document.getElementById('fa_b_is_free').checked = isAmount;
  const freeRow = document.getElementById('free-amount-row');
  if(freeRow) freeRow.style.display = isAmount ? 'flex' : 'none';
  document.getElementById('fa_b_group').closest('.form-group').style.display = isBundles ? '' : 'none';
}

function updateGroupSuggestions(methodId) {
  const sugg = document.getElementById('group-suggestions');
  if (!sugg) return;
  const groups = BUNCH_GROUPS[parseInt(methodId)] || [];
  sugg.innerHTML = groups.map(g =>
    `<span onclick="document.getElementById('fa_b_group').value='${g}'"
      style="background:rgba(167,139,250,.15);color:#a78bfa;padding:3px 10px;border-radius:8px;font-size:.75rem;cursor:pointer">${g}</span>`
  ).join('');
}

function openBunchModal(id, data) {
  document.getElementById('bunchModal').classList.add('open');
  document.getElementById('bunchModalTitle').innerHTML = id ? '<i class="fas fa-edit"></i> تعديل باقة' : '<i class="fas fa-plus"></i> باقة جديدة';
  document.getElementById('fa_bunch_row_id').value = id || 0;
  if (data) {
    document.getElementById('fa_b_method_id').value    = data.method_id    || '';
    document.getElementById('fa_b_bunch_id').value     = data.bunch_id     || '';
    document.getElementById('fa_b_name').value         = data.bunch_name   || '';
    document.getElementById('fa_b_code').value         = data.code         || '';
    if(document.getElementById('fa_b_fore_num')) document.getElementById('fa_b_fore_num').value = data.fore_num || '';
    document.getElementById('fa_b_unified_code').value = data.unified_code || '';
    document.getElementById('fa_b_price').value        = data.price        || '';
    document.getElementById('fa_b_validity').value     = data.validity     || '';
    document.getElementById('fa_b_section').value      = data.section      || 'bundles';
    document.getElementById('fa_b_ptype').value        = data.payment_type || 'both';
    document.getElementById('fa_b_group').value        = data.bundle_group || '';
    document.getElementById('fa_b_sort').value         = data.sort_order   || 0;
    document.getElementById('fa_b_status').checked     = data.status == 1;
    document.getElementById('fa_b_is_free').checked    = data.is_free_amount == 1;
    toggleFreeAmount(data.section || 'bundles');
    updateGroupSuggestions(data.method_id);
  } else {
    document.getElementById('bunchForm').reset();
    document.getElementById('fa_bunch_row_id').value = 0;
    document.getElementById('fa_b_status').checked = true;
    document.getElementById('fa_b_section').value = 'bundles';
    document.getElementById('fa_b_ptype').value = 'both';
    toggleFreeAmount('bundles');
    // اقتراحات مجموعات حسب المزود المختار
    const mid = document.getElementById('fa_b_method_id')?.value;
    if (mid) updateGroupSuggestions(mid);
  }
}
</script>


<!-- ─── مودال الموافقة ───────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="approveModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-check-circle" style="color:#00d4aa"></i> تأكيد الموافقة</div>
      <button class="modal-close" onclick="document.getElementById('approveModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div id="approveInfo" style="background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.2);border-radius:10px;padding:14px;margin-bottom:1rem;font-size:.9rem"></div>
      <form method="POST" action="?action=approve&id=0" id="approveForm">
        <?= adminCsrfField() ?>
        <div class="form-group">
          <label>صندوق القبض المحدد تلقائياً</label>
          <div id="approveCashboxInfo" style="background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:11px;color:#00d4aa;font-weight:700">سيحدده النظام حسب عملة الطلب وإعداد وسيلة الدفع.</div>
          <small style="display:block;color:#8895a7;margin-top:5px">لا يمكن تغيير الصندوق من شاشة الاعتماد؛ يختار النظام الصندوق المربوط بعملة الإيداع، ويرفض الاعتماد إذا لم يكن الربط مكتملًا.</small>
        </div>
        <div class="form-group">
          <label>ملاحظة للعميل (اختياري)</label>
          <input type="text" name="admin_notes" placeholder="مثال: تمت الإضافة بنجاح">
        </div>
        <button type="submit" class="btn btn-success btn-block"><i class="fas fa-check"></i> موافقة وإضافة الرصيد</button>
      </form>
    </div>
  </div>
</div>

<!-- ─── مودال الرفض ─────────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="rejectModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-times-circle" style="color:#ff4455"></i> رفض الطلب</div>
      <button class="modal-close" onclick="document.getElementById('rejectModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" action="?action=reject&id=0" id="rejectForm">
        <?= adminCsrfField() ?>
        <div class="form-group">
          <label>سبب الرفض *</label>
          <input type="text" name="admin_notes" placeholder="مثال: الإيصال غير واضح..." required>
        </div>
        <button type="submit" class="btn btn-danger btn-block"><i class="fas fa-times"></i> رفض الطلب</button>
      </form>
    </div>
  </div>
</div>

<!-- ─── مودال عرض الإيصال ────────────────────────────────────────────────────── -->
<div class="modal-overlay" id="receiptModal" onclick="this.classList.remove('open')">
  <div style="max-width:500px;margin:auto;padding:10px">
    <img id="receiptImg" src="" style="width:100%;border-radius:12px;box-shadow:0 0 40px rgba(0,0,0,.8)">
  </div>
</div>

<script>
let fieldCount = <?=max(count($editFields),1)?>;
let rateCount  = <?=count($rates)?>;

function switchTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
  document.getElementById('tab-'+name).style.display='block';
  document.querySelectorAll('.pay-tab').forEach(b=>b.classList.remove('active'));
  event.currentTarget.classList.add('active');
}

function addFieldRow() {
  const c = document.getElementById('fieldsContainer');
  const div = document.createElement('div');
  div.className = 'field-row';
  div.id = 'fieldRow_' + fieldCount;
  div.innerHTML = `
    <input type="text" name="field_label[]" placeholder="اسم الحقل" class="form-control" style="font-size:.83rem">
    <input type="text" name="field_value[]" placeholder="القيمة" class="form-control" style="font-size:.83rem">
    <div style="text-align:center"><input type="checkbox" name="field_copy[]" value="${fieldCount}" checked style="width:18px;height:18px;accent-color:var(--primary)"></div>
    <button type="button" onclick="this.closest('.field-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
  `;
  c.appendChild(div); fieldCount++;
}

function addRateRow() {
  const c = document.getElementById('ratesContainer');
  const key = 'new_' + rateCount++;
  const div = document.createElement('div');
  div.className = 'rate-row';
  div.dataset.rateKey = key;
  div.innerHTML = `
    <input type="text" name="code[${key}]" placeholder="AED" class="form-control" style="font-size:.85rem;text-transform:uppercase">
    <input type="text" name="cname[${key}]" placeholder="درهم إماراتي" class="form-control" style="font-size:.85rem">
    <input type="text" name="symbol[${key}]" placeholder="د.إ" class="form-control" style="font-size:.85rem">
    <input type="text" name="rate[${key}]" placeholder="0.2722800000" class="form-control" style="font-size:.85rem">
    <div style="text-align:center"><input type="checkbox" name="cstatus[${key}]" value="1" checked style="width:18px;height:18px;accent-color:var(--primary)"></div>
    <button type="button" onclick="this.closest('.rate-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
  `;
  c.appendChild(div);
}

function markRateDeleted(button, id) {
  const row = button.closest('.rate-row');
  if (!row || !id) return;
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'deleted_rate_ids[]';
  input.value = String(id);
  document.getElementById('deletedRateIds')?.appendChild(input);
  row.remove();
}

function openApprove(id, amount, user, method, requestCurrency = 'USD', requestCashboxLabel = '') {
  document.getElementById('approveForm').action = `?action=approve&id=${id}`;
  const currency = String(requestCurrency || 'USD').toUpperCase();
  const cashboxInfo = document.getElementById('approveCashboxInfo');
  const cashboxNote = requestCashboxLabel
    ? `سيُرحّل القبض إلى: <b>${requestCashboxLabel}</b> — بعملة ${currency}.`
    : `لا يوجد ربط ظاهر لصندوق بعملة ${currency}. راجع إعداد وسيلة الدفع قبل الاعتماد.`;
  if (cashboxInfo) {
    cashboxInfo.innerHTML = cashboxNote;
    cashboxInfo.style.color = requestCashboxLabel ? '#00d4aa' : '#f5a623';
  }
  document.getElementById('approveInfo').innerHTML = `
    <strong>${user}</strong> — ${method}<br>
    <span style="font-size:1.3rem;font-weight:900;color:#00d4aa">$${parseFloat(amount).toFixed(4)}</span>
    <span style="color:#8895a7;font-size:.85rem"> ستُضاف لرصيده</span><br>
    <span style="display:block;margin-top:8px;color:#f5c451;font-size:.82rem">عملة الإيداع: <b>${currency}</b></span>
    <span style="display:block;margin-top:4px;color:#8895a7;font-size:.78rem">${cashboxNote}</span>
  `;
  document.getElementById('approveModal').classList.add('open');
}
function openReject(id) {
  document.getElementById('rejectForm').action = `?action=reject&id=${id}`;
  document.getElementById('rejectModal').classList.add('open');
}
function viewReceipt(url) {
  document.getElementById('receiptImg').src = url;
  document.getElementById('receiptModal').classList.add('open');
}
function filterReqs(status) {
  document.querySelectorAll('.req-card').forEach(c=>{
    c.style.display = (status==='all' || c.dataset.status===status) ? '' : 'none';
  });
  document.querySelectorAll('.filter-req-btn').forEach(b=>{
    b.classList.toggle('btn-primary', b.dataset.filter===status);
    b.classList.toggle('btn-secondary', b.dataset.filter!==status);
  });
}

function highlightMode() {
  const manual = document.querySelector('[name=payment_mode][value=manual]').checked;
  document.getElementById('modeManualLbl').style.borderColor = manual ? 'var(--primary)' : 'var(--border)';
  document.getElementById('modeAutoLbl').style.borderColor   = !manual ? 'var(--primary)' : 'var(--border)';
}
highlightMode();

function previewMethodImg(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('methodImgPreview');
    const placeholder = document.getElementById('imgPlaceholder');
    const current = document.getElementById('currentMethodImg');
    prev.src = e.target.result;
    prev.style.display = 'block';
    if (placeholder) placeholder.style.display = 'none';
    if (current) current.style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}

function previewFloosakImg(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev        = document.getElementById('floosakImgPreview');
    const placeholder = document.getElementById('floosakImgPlaceholder');
    const current     = document.getElementById('floosakImgCurrent');
    prev.src = e.target.result;
    prev.style.display = 'block';
    if (placeholder) placeholder.style.display = 'none';
    if (current) current.style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}

// Close modals on overlay click
['approveModal','rejectModal'].forEach(id=>{
  const el = document.getElementById(id);
  el.addEventListener('click', e=>{ if(e.target===el) el.classList.remove('open'); });
});
</script>

<script>
// ══ AJAX Bunch Operations ════════════════════════════════════

function saveBunchAjax() {
  const form = document.getElementById('bunchForm');
  if(!form) return;
  const fd = new FormData(form);
  fd.append('save_fa_bunch','1');
  const btn = form.querySelector('button[onclick="saveBunchAjax()"]');
  if(btn){ btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...'; }
  fetch(location.href, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
  .then(r=>r.json()).then(d=>{
    if(btn){ btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> حفظ الباقة'; }
    if(!d.ok){ showAdminToast(d.msg||'خطأ','danger'); return; }
    showAdminToast(d.msg||'تم','success');
    document.getElementById('bunchModal').classList.remove('open');
    updateBunchRow(d.row, d.id);
  }).catch(()=>{
    if(btn){ btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> حفظ الباقة'; }
    showAdminToast('خطأ في الاتصال','danger');
  });
}

function deleteBunchAjax(id, methodId, btn) {
  if(!confirm('حذف هذه الباقة؟')) return;
  btn.disabled=true;
  const fd = new FormData();
  fd.append('delete_fa_bunch','1'); fd.append('fa_bunch_row_id',id); fd.append('fa_b_method_id',methodId);
  fetch(location.href, {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
  .then(r=>r.json()).then(d=>{
    if(!d.ok){ btn.disabled=false; showAdminToast(d.msg||'خطأ','danger'); return; }
    showAdminToast('تم الحذف','success');
    const row=document.getElementById('bunch-row-'+id);
    if(row){ row.style.transition='opacity .3s'; row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
  }).catch(()=>{ btn.disabled=false; showAdminToast('خطأ','danger'); });
}

function updateBunchRow(row, id) {
  if(!row){ location.reload(); return; }
  const secMap={fees:'فئات',amount:'رصيد حر',bundles:'باقات',
    yemen4g_change:'تغيير باقة',yemen4g_credit:'رصيد اتصال',
    yemen4g_internet:'إنترنت فقط',yemen4g_voice:'صوت فقط'};
  const ptMap={prepaid:'مسبق',postpaid:'فوترة',both:'كلاهما'};
  const secCol={fees:'#f5a623',amount:'#00d4aa',bundles:'#6ba3ff',
    yemen4g_change:'#e91e8c',yemen4g_credit:'#a78bfa',
    yemen4g_internet:'#17a2b8',yemen4g_voice:'#6c757d'};
  const s=row.section||'bundles', p=row.payment_type||'both';
  const html=`
    <td style="color:#8895a7;font-size:.75rem">${row.id}</td>
    <td><span style="background:rgba(167,139,250,.12);color:#a78bfa;padding:1px 7px;border-radius:5px;font-size:.75rem">${row.method_id}${row.method_name?' — '+esc(row.method_name):''}</span></td>
    <td><code style="font-size:.75rem;color:#f5a623">${esc(row.bunch_id||'—')}</code></td>
    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(row.bunch_name)}">${esc(row.bunch_name)}</td>
    <td style="font-size:.72rem;color:#a78bfa">${esc(row.bundle_group||'—')}</td>
    <td><span style="background:rgba(30,111,255,.1);color:${secCol[s]||'#6ba3ff'};padding:2px 7px;border-radius:5px;font-size:.72rem">${secMap[s]||s}</span></td>
    <td><span style="font-size:.7rem;color:#8895a7">${ptMap[p]||p}</span></td>
    <td style="font-weight:700;color:#f5a623">${row.price?Number(row.price).toLocaleString()+' ر':'—'}</td>
    <td style="color:#8895a7;font-size:.78rem">${esc(row.validity||'—')}</td>
    <td><span style="color:${row.status?'#00d4aa':'#ff4455'};font-size:.75rem"><i class="fas fa-circle"></i></span></td>
    <td style="white-space:nowrap">
      <button class="btn btn-warning btn-sm" style="padding:2px 7px" onclick='openBunchModal(${row.id},${JSON.stringify(row)})'><i class="fas fa-edit"></i></button>
      <button class="btn btn-danger btn-sm" style="padding:2px 7px" onclick="deleteBunchAjax(${row.id},${row.method_id},this)"><i class="fas fa-trash"></i></button>
    </td>`;
  const existing=document.getElementById('bunch-row-'+id);
  if(existing){
    existing.innerHTML=html;
    existing.style.transition='background .5s';
    existing.style.background='rgba(0,212,170,.1)';
    setTimeout(()=>existing.style.background='',1800);
  } else {
    const tbody=document.getElementById('bunches-tbody');
    if(tbody){
      const tr=document.createElement('tr'); tr.id='bunch-row-'+id; tr.innerHTML=html;
      tr.style.background='rgba(0,212,170,.1)';
      tbody.insertBefore(tr,tbody.firstChild);
      setTimeout(()=>tr.style.background='',1800);
    } else location.reload();
  }
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAdminToast(msg,type='success'){
  let t=document.getElementById('adminToast');
  if(!t){
    t=document.createElement('div'); t.id='adminToast';
    t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:9999;padding:10px 24px;border-radius:12px;font-size:.88rem;font-weight:700;transition:opacity .3s;pointer-events:none;box-shadow:0 4px 20px rgba(0,0,0,.3)';
    document.body.appendChild(t);
  }
  t.style.background=type==='success'?'#00d4aa':'#ff4455'; t.style.color='#fff';
  t.textContent=msg; t.style.opacity='1';
  clearTimeout(t._t); t._t=setTimeout(()=>t.style.opacity='0',3000);
}
</script>

<?php include 'footer.php'; ?>
