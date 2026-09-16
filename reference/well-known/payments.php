<?php
require_once '../includes/config.php';
if (file_exists(dirname(__DIR__).'/includes/referral.php')) require_once dirname(__DIR__).'/includes/referral.php';
require_once '../includes/notifications.php';
require_once '../includes/floosak_merchant.php';
require_once '../includes/floosak_agent.php';
requireStaffOrAdmin($pdo, 'perm_settings');
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
        `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_parent` (`parent_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    foreach ($networks as $mid) {
        $v = $_POST["np_{$mid}"] ?? 'fore';
        $v = in_array($v,['fore','floosak']) ? $v : 'fore';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute(["network_{$mid}_provider", $v, $v]);
    }
    flashMessage('success','تم حفظ اختيارات المزودين ✓');
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
        'sort_order' => (int)($_POST['sadad_cat_sort']    ?? 0),
        'status'     => isset($_POST['sadad_cat_status']) ? 1 : 0,
    ];
    if ($cid) {
        $pdo->prepare("UPDATE sadad_categories SET parent_id=?,name_ar=?,icon=?,color=?,sort_order=?,status=? WHERE id=?")
            ->execute([$data['parent_id'],$data['name_ar'],$data['icon'],$data['color'],$data['sort_order'],$data['status'],$cid]);
    } else {
        $pdo->prepare("INSERT INTO sadad_categories (parent_id,name_ar,icon,color,sort_order,status) VALUES (?,?,?,?,?,?)")
            ->execute([$data['parent_id'],$data['name_ar'],$data['icon'],$data['color'],$data['sort_order'],$data['status']]);
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
    if ($sid) {
        $pdo->prepare("UPDATE sadad_services SET category_id=?,name_ar=?,icon=?,color=?,method_id=?,action=?,action_value=?,sort_order=?,status=? WHERE id=?")
            ->execute([$data['category_id'],$data['name_ar'],$data['icon'],$data['color'],$data['method_id'],$data['action'],$data['action_value'],$data['sort_order'],$data['status'],$sid]);
    } else {
        $pdo->prepare("INSERT INTO sadad_services (category_id,name_ar,icon,color,method_id,action,action_value,sort_order,status) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$data['category_id'],$data['name_ar'],$data['icon'],$data['color'],$data['method_id'],$data['action'],$data['action_value'],$data['sort_order'],$data['status']]);
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
    $agentEnabled  = isset($_POST['floosak_agent_enabled']) ? '1' : '0';
    $agentSandbox  = isset($_POST['floosak_agent_sandbox'])  ? '1' : '0';
    floosak_agent_save_config($pdo, [
        'floosak_agent_phone'         => $agentPhone,
        'floosak_agent_password'      => $agentPassword,
        'floosak_agent_enabled'       => $agentEnabled,
        'floosak_agent_sandbox'       => $agentSandbox,
        'floosak_agent_api_url'       => $agentApiUrl,
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
    if ($mid) {
        $pdo->prepare("UPDATE floosak_agent_methods SET method_id=?,name_ar=?,name_en=?,transaction_type=?,icon=?,color=?,status=?,sort_order=? WHERE id=?")
            ->execute([...array_values($data), $mid]);
        flashMessage('success','تم تحديث المزود ✓');
    } else {
        $pdo->prepare("INSERT INTO floosak_agent_methods (method_id,name_ar,name_en,transaction_type,icon,color,status,sort_order) VALUES (?,?,?,?,?,?,?,?)")
            ->execute(array_values($data));
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
    if ($bid) {
        $pdo->prepare("UPDATE floosak_agent_bunches SET method_id=?,bunch_id=?,bunch_name=?,code=?,unified_code=?,fore_num=?,price=?,validity=?,section=?,payment_type=?,bundle_group=?,is_free_amount=?,status=?,sort_order=? WHERE id=?")
            ->execute([$data['method_id'],$data['bunch_id'],$data['bunch_name'],$data['code'],$data['unified_code'],$data['fore_num'],$data['price'],$data['validity'],$data['section'],$data['payment_type'],$data['bundle_group'],$data['is_free_amount'],$data['status'],$data['sort_order'],$bid]);
        flashMessage('success','تم تحديث الباقة ✓');
    } else {
        $pdo->prepare("INSERT INTO floosak_agent_bunches (method_id,bunch_id,bunch_name,code,unified_code,fore_num,price,validity,section,payment_type,bundle_group,is_free_amount,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$data['method_id'],$data['bunch_id'],$data['bunch_name'],$data['code'],$data['unified_code'],$data['fore_num'],$data['price'],$data['validity'],$data['section'],$data['payment_type'],$data['bundle_group'],$data['is_free_amount'],$data['status'],$data['sort_order']]);
        flashMessage('success','تمت إضافة الباقة ✓');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=bunches&filter_method='.$data['method_id']);
}

// حذف باقة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_fa_bunch'])) {
    $bid = (int)($_POST['fa_bunch_row_id'] ?? 0);
    $fmethod = (int)($_POST['fa_b_method_id'] ?? 0);
    if ($bid) {
        $pdo->prepare("DELETE FROM floosak_agent_bunches WHERE id=?")->execute([$bid]);
        flashMessage('success','تم حذف الباقة');
    }
    redirect(SITE_URL.'/admin/payments.php?tab=floosak_agent&subtab=bunches&filter_method='.$fmethod);
}

// ─── موافقة على طلب شحن ──────────────────────────────────────────────────────
if ($action === 'approve' && $id) {
    $req = $pdo->prepare("SELECT * FROM topup_requests WHERE id=? AND status='pending'");
    $req->execute([$id]); $req = $req->fetch();
    if ($req) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE topup_requests SET status='approved',reviewed_by=?,reviewed_at=NOW(),admin_notes=? WHERE id=?")
            ->execute([$_SESSION['user_id'], trim($_POST['admin_notes']??''), $id]);
        $u = $pdo->prepare("SELECT balance FROM users WHERE id=?"); $u->execute([$req['user_id']]); $u=$u->fetch();
        $newBal = $u['balance'] + $req['amount_usd'];
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $req['user_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?)")
            ->execute([$req['user_id'],'credit',$req['amount_usd'],$u['balance'],$newBal,'شحن رصيد — طلب #'.$id]);
        $pdo->commit();
        logStaffAction($pdo,'approve_topup','topup',$id,'موافقة على شحن رصيد $'.$req['amount_usd'].' للعميل #'.$req['user_id']);
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
            $pdo->prepare("UPDATE payment_methods SET name=?,type=?,icon=?,color=?,description=?,sort_order=?,status=?,image=?,payment_mode=? WHERE id=?")
                ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$imagePath,$mode,$mid]);
        } else {
            $pdo->prepare("UPDATE payment_methods SET name=?,type=?,icon=?,color=?,description=?,sort_order=?,status=?,payment_mode=? WHERE id=?")
                ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$mode,$mid]);
        }
    } else {
        $pdo->prepare("INSERT INTO payment_methods (name,type,icon,color,description,sort_order,status,image,payment_mode) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$name,$type,$icon,$color,$desc,$sort,$status,$imagePath,$mode]);
        $mid = $pdo->lastInsertId();
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
    $codes   = $_POST['code']   ?? [];
    $names   = $_POST['cname']  ?? [];
    $symbols = $_POST['symbol'] ?? [];
    $rates   = $_POST['rate']   ?? [];
    $statuses= $_POST['cstatus']?? [];

    foreach ($codes as $i => $code) {
        if (empty($code)) continue;
        $code = strtoupper(trim($code));
        $pdo->prepare("INSERT INTO exchange_rates (currency_code,currency_name,currency_symbol,rate_to_usd,status,sort_order) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE currency_name=?,currency_symbol=?,rate_to_usd=?,status=?,sort_order=?")
            ->execute([$code,$names[$i],$symbols[$i],(float)$rates[$i],in_array($i,array_keys($statuses))?1:0,$i,
                       $names[$i],$symbols[$i],(float)$rates[$i],in_array($i,array_keys($statuses))?1:0,$i]);
    }
    flashMessage('success','تم حفظ أسعار الصرف');
    redirect(SITE_URL.'/admin/payments.php?tab=rates');
}

// ─── جلب البيانات ─────────────────────────────────────────────────────────────
try {
    $requests = $pdo->query("
        SELECT r.*,u.username,u.full_name,m.name as method_name,m.icon as method_icon,
               e.currency_symbol,e.currency_name
        FROM topup_requests r
        JOIN users u ON r.user_id=u.id
        JOIN payment_methods m ON r.method_id=m.id
        JOIN exchange_rates e ON r.currency_code=e.currency_code
        ORDER BY r.created_at DESC LIMIT 100
    ")->fetchAll();
} catch(\PDOException $e) { $requests = []; }

try {
    $methods = $pdo->query("SELECT m.*, COUNT(f.id) as field_count FROM payment_methods m LEFT JOIN payment_method_fields f ON m.id=f.method_id GROUP BY m.id ORDER BY m.sort_order")->fetchAll();
} catch(\PDOException $e) { $methods = []; }

try {
    $rates = $pdo->query("SELECT * FROM exchange_rates ORDER BY sort_order")->fetchAll();
} catch(\PDOException $e) { $rates = []; }

// للتعديل
$editMethod = null; $editFields = [];
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

<!-- ═══════════════ طلبات الشحن ═══════════════ -->
<div id="tab-requests" class="tab-pane" style="<?=$tab!=='requests'?'display:none':''?>">

<?php if(empty($requests)): ?>
<div class="card"><div style="text-align:center;padding:3rem;color:#8895a7"><i class="fas fa-inbox" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>لا توجد طلبات بعد</div></div>
<?php else: ?>

<!-- فلتر -->
<div style="display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap">
  <?php foreach(['all'=>'الكل','pending'=>'انتظار','approved'=>'موافق','rejected'=>'مرفوض'] as $k=>$lbl): ?>
  <button onclick="filterReqs('<?=$k?>')" class="btn btn-sm btn-secondary filter-req-btn" data-filter="<?=$k?>"><?=$lbl?></button>
  <?php endforeach; ?>
</div>

<?php foreach($requests as $req):
  $statusLabel = ['pending'=>'انتظار','approved'=>'موافق عليه','rejected'=>'مرفوض'][$req['status']] ?? '';
?>
<div class="req-card" data-status="<?=$req['status']?>">
  <!-- أيقونة الطريقة -->
  <div style="width:44px;height:44px;border-radius:12px;background:rgba(108,63,224,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;color:var(--primary)">
    <i class="fas fa-<?=htmlspecialchars($req['method_icon'])?>"></i>
  </div>
  <!-- معلومات -->
  <div style="flex:1;min-width:180px">
    <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($req['username'])?> <?=$req['full_name']?'<span style="color:#8895a7;font-size:.8rem"> — '.htmlspecialchars($req['full_name']).'</span>':''?></div>
    <div style="font-size:.8rem;color:#8895a7;margin-top:2px">
      <?=htmlspecialchars($req['method_name'])?> &nbsp;·&nbsp;
      <?=$req['currency_symbol']?> <?=number_format($req['amount_sent'],2)?> <?=$req['currency_name']?>
      &nbsp;·&nbsp; <?=date('d/m H:i',strtotime($req['created_at']))?>
    </div>
    <?php if($req['notes']): ?><div style="font-size:.78rem;color:#a78bfa;margin-top:3px">📝 <?=htmlspecialchars($req['notes'])?></div><?php endif; ?>
  </div>
  <!-- المبلغ بالدولار -->
  <div style="text-align:center;flex-shrink:0">
    <div class="req-amount">$<?=number_format($req['amount_usd'],4)?></div>
    <div style="font-size:.73rem;color:#8895a7">سيُضاف للرصيد</div>
  </div>
  <!-- إيصال -->
  <?php if($req['receipt_image']): ?>
  <img src="<?=SITE_URL?>/<?=htmlspecialchars($req['receipt_image'])?>" class="receipt-thumb" onclick="viewReceipt('<?=SITE_URL?>/<?=htmlspecialchars($req['receipt_image'])?>')">
  <?php else: ?>
  <div style="width:60px;height:50px;border-radius:8px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:.7rem;text-align:center">بدون<br>إيصال</div>
  <?php endif; ?>
  <!-- الحالة والأزرار -->
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
    <span class="req-status <?=$req['status']?>"><?=$statusLabel?></span>
    <?php if($req['status']==='pending'): ?>
    <div style="display:flex;gap:5px">
      <button class="btn btn-sm btn-success" onclick="openApprove(<?=$req['id']?>,<?=$req['amount_usd']?>,'<?=addslashes(htmlspecialchars($req['username']))?>','<?=addslashes(htmlspecialchars($req['method_name']))?>')">
        <i class="fas fa-check"></i> موافقة
      </button>
      <button class="btn btn-sm btn-danger" onclick="openReject(<?=$req['id']?>)">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <?php elseif($req['admin_notes']): ?>
    <div style="font-size:.73rem;color:#8895a7;max-width:160px;text-align:right"><?=htmlspecialchars($req['admin_notes'])?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ═══════════════ طرق الدفع ═══════════════ -->
<div id="tab-methods" class="tab-pane" style="<?=$tab!=='methods'?'display:none':''?>">

  <?php if($action==='edit_method' && $editMethod || $action==='add_method'): ?>
  <!-- فورم إضافة / تعديل -->
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="save_method" value="1">
    <input type="hidden" name="method_id" value="<?=$editMethod['id']??0?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-credit-card"></i> بيانات طريقة الدفع</div></div>
      <div class="card-body">
        <div class="form-group">
          <label>اسم طريقة الدفع *</label>
          <input type="text" name="name" value="<?=htmlspecialchars($editMethod['name']??'')?>" placeholder="مثال: حوالة بنكية — بنك الراجحي" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>النوع</label>
            <select name="type">
              <?php foreach(['bank'=>'🏦 بنك','ewallet'=>'📱 محفظة إلكترونية','crypto'=>'₿ كريبتو','other'=>'🔗 أخرى'] as $v=>$l): ?>
              <option value="<?=$v?>" <?=($editMethod['type']??'bank')===$v?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الأيقونة <small><a href="https://fontawesome.com/icons" target="_blank" style="color:var(--primary);font-size:.75rem">FA Icons</a></small></label>
            <div style="display:flex;gap:6px">
              <input type="text" name="icon" id="iconInput" value="<?=htmlspecialchars($editMethod['icon']??'university')?>" placeholder="university" oninput="document.getElementById('iconPreview').className='fas fa-'+this.value">
              <div style="width:42px;height:42px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-<?=$editMethod['icon']??'university'?>" id="iconPreview" style="font-size:1.2rem;color:var(--primary)"></i>
              </div>
            </div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="color" value="<?=htmlspecialchars($editMethod['color']??'#6c3fe0')?>" style="height:42px;width:100%;border-radius:8px;cursor:pointer">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" value="<?=$editMethod['sort_order']??0?>" min="0">
          </div>
        </div>
        <div class="form-group">
          <label>نوع الدفع</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px">
            <label style="display:flex;align-items:center;gap:10px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:.15s" id="modeManualLbl">
              <input type="radio" name="payment_mode" value="manual" <?=($editMethod['payment_mode']??'manual')==='manual'?'checked':''?> onchange="highlightMode()" style="accent-color:var(--primary)">
              <div>
                <div style="font-weight:800;font-size:.88rem">🏦 يدوي</div>
                <div style="font-size:.73rem;color:#8895a7;margin-top:2px">العميل يرفع إيصال وينتظر الموافقة</div>
              </div>
            </label>
            <label style="display:flex;align-items:center;gap:10px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:.15s" id="modeAutoLbl">
              <input type="radio" name="payment_mode" value="auto" <?=($editMethod['payment_mode']??'')==='auto'?'checked':''?> onchange="highlightMode()" style="accent-color:var(--primary)">
              <div>
                <div style="font-weight:800;font-size:.88rem">⚡ مباشر</div>
                <div style="font-size:.73rem;color:#8895a7;margin-top:2px">يُشحن تلقائياً عبر API</div>
              </div>
            </label>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        </div>
        <div class="form-group">
          <label>تعليمات / ملاحظات للعميل</label>
          <textarea name="description" rows="3" placeholder="مثال: أرسل الحوالة ثم أرفق الإيصال..."><?=htmlspecialchars($editMethod['description']??'')?></textarea>
        </div>
        <div class="form-group">
          <label>صورة / شعار طريقة الدفع <small style="color:#8895a7">(اختياري — PNG, JPG, SVG، حد 2MB)</small></label>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <?php if(!empty($editMethod['image'])): ?>
            <img src="<?=SITE_URL?>/<?=htmlspecialchars($editMethod['image'])?>" style="width:64px;height:64px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,.05);border:1px solid var(--border);padding:4px" id="currentMethodImg">
            <?php else: ?>
            <div style="width:64px;height:64px;border-radius:12px;background:var(--bg);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:1.4rem" id="imgPlaceholder"><i class="fas fa-image"></i></div>
            <?php endif; ?>
            <div style="flex:1">
              <input type="file" name="method_image" id="methodImageInput" accept="image/*,.svg" style="display:none" onchange="previewMethodImg(this)">
              <button type="button" onclick="document.getElementById('methodImageInput').click()" class="btn btn-sm btn-secondary"><i class="fas fa-upload"></i> اختر صورة</button>
              <div style="font-size:.75rem;color:#8895a7;margin-top:4px">ستظهر للعميل عند اختيار طريقة الدفع</div>
            </div>
          </div>
          <img id="methodImgPreview" src="" style="display:none;width:80px;height:80px;border-radius:12px;object-fit:contain;margin-top:8px;border:1px solid var(--border);padding:4px;background:rgba(255,255,255,.05)">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem">
          <input type="checkbox" name="status" value="1" <?=($editMethod['status']??1)?'checked':''?>> تفعيل طريقة الدفع
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-list"></i> الحقول القابلة للنسخ</div>
        <button type="button" class="btn btn-sm btn-primary" onclick="addFieldRow()"><i class="fas fa-plus"></i> حقل</button>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 2fr 70px 30px;gap:6px;margin-bottom:8px">
          <div style="font-size:.75rem;color:#8895a7;font-weight:700">التسمية</div>
          <div style="font-size:.75rem;color:#8895a7;font-weight:700">القيمة</div>
          <div style="font-size:.75rem;color:#8895a7;font-weight:700;text-align:center">نسخ</div>
          <div></div>
        </div>
        <div id="fieldsContainer">
          <?php foreach($editFields as $i=>$f): ?>
          <div class="field-row" id="fieldRow_<?=$i?>">
            <input type="text" name="field_label[]" value="<?=htmlspecialchars($f['field_label'])?>" placeholder="اسم الحقل" class="form-control" style="font-size:.83rem">
            <input type="text" name="field_value[]" value="<?=htmlspecialchars($f['field_value'])?>" placeholder="القيمة" class="form-control" style="font-size:.83rem">
            <div style="text-align:center">
              <input type="checkbox" name="field_copy[]" value="<?=$i?>" <?=$f['copyable']?'checked':''?> title="قابل للنسخ" style="width:18px;height:18px;accent-color:var(--primary)">
            </div>
            <button type="button" onclick="this.closest('.field-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
          </div>
          <?php endforeach; ?>
          <?php if(empty($editFields)): ?>
          <div class="field-row" id="fieldRow_0">
            <input type="text" name="field_label[]" placeholder="مثال: اسم المستلم" class="form-control" style="font-size:.83rem">
            <input type="text" name="field_value[]" placeholder="أحمد محمد" class="form-control" style="font-size:.83rem">
            <div style="text-align:center"><input type="checkbox" name="field_copy[]" value="0" checked style="width:18px;height:18px;accent-color:var(--primary)"></div>
            <button type="button" onclick="this.closest('.field-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top:1rem;padding:12px;background:rgba(108,63,224,.08);border-radius:8px;font-size:.8rem;color:#8895a7">
          <i class="fas fa-info-circle" style="color:var(--primary)"></i>
          أمثلة: اسم المستلم، رقم الجوال، رقم الآيبان، عنوان المحفظة...
        </div>
      </div>
    </div>

    </div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ طريقة الدفع</button>
      <a href="?tab=methods" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
  <?php else: ?>

  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
    <a href="?action=add_method&tab=methods" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة طريقة دفع</a>
  </div>

  <?php if(empty($methods)): ?>
  <div class="card"><div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-credit-card" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    لا توجد طرق دفع بعد — أضف أولى طرق الدفع
  </div></div>
  <?php else: ?>
  <?php foreach($methods as $m): ?>
  <div class="method-card">
    <div class="method-icon-box" style="background:<?=htmlspecialchars($m['color'])?>22;color:<?=htmlspecialchars($m['color'])?>">
      <?php if(!empty($m['image'])): ?>
      <img src="<?=SITE_URL?>/<?=htmlspecialchars($m['image'])?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:4px">
      <?php else: ?>
      <i class="fas fa-<?=htmlspecialchars($m['icon'])?>"></i>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:800"><?=htmlspecialchars($m['name'])?></div>
      <div style="font-size:.8rem;color:#8895a7;margin-top:3px">
        <?=$m['field_count']?> حقل &nbsp;·&nbsp;
        <?=['bank'=>'بنك','ewallet'=>'محفظة إلكترونية','crypto'=>'كريبتو','other'=>'أخرى'][$m['type']]??$m['type']?>
        &nbsp;·&nbsp;
        <?php if(($m['payment_mode']??'manual')==='auto'): ?>
        <span style="color:#00d4ff;font-size:.72rem">⚡ مباشر</span>
        <?php else: ?>
        <span style="color:#f5a623;font-size:.72rem">🏦 يدوي</span>
        <?php endif; ?>
      </div>
      <?php if($m['description']): ?><div style="font-size:.78rem;color:#a78bfa;margin-top:3px"><?=htmlspecialchars(mb_substr($m['description'],0,60))?><?=mb_strlen($m['description'])>60?'...':''?></div><?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
      <span class="badge <?=$m['status']?'badge-success':'badge-danger'?>"><?=$m['status']?'نشط':'متوقف'?></span>
      <a href="?action=edit_method&id=<?=$m['id']?>&tab=methods" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
      <a href="?action=toggle_method&id=<?=$m['id']?>" class="btn btn-sm btn-secondary"><i class="fas fa-<?=$m['status']?'eye-slash':'eye'?>"></i></a>
      <a href="?action=delete_method&id=<?=$m['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف هذه الطريقة؟')"><i class="fas fa-trash"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ═══════════════ أسعار الصرف ═══════════════ -->
<div id="tab-rates" class="tab-pane" style="<?=$tab!=='rates'?'display:none':''?>">
  <form method="POST">
  <input type="hidden" name="save_rates" value="1">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-exchange-alt"></i> أسعار الصرف (بالنسبة للدولار $)</div>
      <button type="button" class="btn btn-sm btn-primary" onclick="addRateRow()"><i class="fas fa-plus"></i> إضافة عملة</button>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:100px 1fr 80px 160px 80px 36px;gap:8px;margin-bottom:8px;padding:0 4px">
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">الكود</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">اسم العملة</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">الرمز</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">1 وحدة = ؟ دولار</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700;text-align:center">تفعيل</div>
        <div></div>
      </div>
      <div id="ratesContainer">
      <?php foreach($rates as $i=>$r): ?>
      <div class="rate-row" id="rateRow_<?=$i?>">
        <input type="text" name="code[]" value="<?=htmlspecialchars($r['currency_code'])?>" class="form-control" style="font-size:.85rem;text-transform:uppercase" <?=$r['currency_code']==='USD'?'readonly style="opacity:.5;font-size:.85rem"':''?>>
        <input type="text" name="cname[]" value="<?=htmlspecialchars($r['currency_name'])?>" class="form-control" style="font-size:.85rem">
        <input type="text" name="symbol[]" value="<?=htmlspecialchars($r['currency_symbol'])?>" class="form-control" style="font-size:.85rem">
        <input type="text" name="rate[]" value="<?=rtrim(rtrim(number_format($r['rate_to_usd'],10),'0'),'.')?>" class="form-control" style="font-size:.85rem" placeholder="0.2666700000">
        <div style="text-align:center"><input type="checkbox" name="cstatus[<?=$i?>]" value="1" <?=$r['status']?'checked':''?> style="width:18px;height:18px;accent-color:var(--primary)"></div>
        <?php if($r['currency_code']==='USD'): ?>
        <div style="width:28px"></div>
        <?php else: ?>
        <button type="button" onclick="this.closest('.rate-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <div style="margin-top:1rem;padding:12px;background:rgba(0,212,170,.06);border-radius:8px;font-size:.82rem;color:#8895a7">
        <i class="fas fa-info-circle" style="color:#00d4aa"></i>
        مثال: الريال السعودي = <strong style="color:#fff">0.2666700000</strong> (أي 1 ريال = 0.267 دولار)
        &nbsp;|&nbsp; الريال اليمني = <strong style="color:#fff">0.0018600000</strong>
      </div>
    </div>
    <div style="padding:0 1rem 1rem">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ أسعار الصرف</button>
    </div>
  </div>
  </form>
</div>

<!-- ═══════════════ فلوسك — إعدادات الدفع المباشر ═══════════════ -->
<div id="tab-floosak" class="tab-pane" style="<?=$tab!=='floosak'?'display:none':''?>">

  <?php
  $fEnabled   = !empty($floosakCfg['floosak_enabled']) && $floosakCfg['floosak_enabled']==='1';
  $fHasKey    = !empty($floosakCfg['floosak_merchant_key']);
  $fPhone     = $floosakCfg['floosak_phone'] ?? '967777153381';
  $fShortCode = $floosakCfg['floosak_short_code'] ?? '990000';
  $fWalletId  = $floosakCfg['floosak_wallet_id'] ?? '';
  $fUpdated   = $floosakCfg['floosak_key_updated'] ?? '';
  $fPendingReq= $floosakCfg['floosak_pending_request_id'] ?? '';
  $fImage     = $floosakCfg['floosak_image'] ?? '';
  ?>

  <!-- بطاقة الحالة -->
  <div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="width:56px;height:56px;border-radius:16px;background:<?=$fEnabled&&$fHasKey?'rgba(0,212,170,.15)':'rgba(255,68,85,.1)'?>;display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0;overflow:hidden">
        <?php if($fImage): ?>
        <img src="<?=SITE_URL?>/<?=htmlspecialchars($fImage)?>" style="width:100%;height:100%;object-fit:contain;padding:6px">
        <?php else: ?>
        <?=$fEnabled&&$fHasKey?'⚡':'🔌'?>
        <?php endif; ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:900;font-size:1rem">بوابة فلوسك للدفع المباشر</div>
        <div style="font-size:.82rem;color:#8895a7;margin-top:3px">
          الحالة:
          <span style="color:<?=$fEnabled&&$fHasKey?'#00d4aa':'#ff4455'?>;font-weight:800">
            <?=$fEnabled&&$fHasKey?'✅ مفعّلة ومتصلة':'❌ غير مفعّلة'?>
          </span>
          <?php if($fHasKey && $fUpdated): ?>
          &nbsp;·&nbsp; آخر تحديث: <?=date('d/m/Y H:i', strtotime($fUpdated))?>
          <?php endif; ?>
          <?php if($fWalletId): ?>
          &nbsp;·&nbsp; Wallet ID: <strong style="color:#00d4ff"><?=$fWalletId?></strong>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">

    <!-- خطوة 1: بيانات الحساب + طلب OTP -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-key"></i> الخطوة 1 — تفعيل الحساب</div>
      </div>
      <div class="card-body">
        <div style="padding:10px 14px;background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:10px;margin-bottom:1rem;font-size:.82rem;color:#8895a7">
          <i class="fas fa-info-circle" style="color:#00d4ff"></i>
          أدخل رقم هاتف وشورت كود حساب فلوسك المرتشنت الخاص بك، ثم اضغط "طلب رمز التحقق".
          سيصلك SMS من فلوسك.
        </div>
        <form method="POST">
          <div class="form-group">
            <label>رقم هاتف فلوسك</label>
            <input type="text" name="floosak_phone" value="<?=htmlspecialchars($fPhone)?>" placeholder="967777153381" required>
          </div>
          <div class="form-group">
            <label>الشورت كود (Short Code)</label>
            <input type="text" name="floosak_short_code" value="<?=htmlspecialchars($fShortCode)?>" placeholder="990000" required>
          </div>
          <button type="submit" name="floosak_request_otp" class="btn btn-primary btn-block">
            <i class="fas fa-sms"></i> طلب رمز التحقق (OTP)
          </button>
        </form>
      </div>
    </div>

    <!-- خطوة 2: إدخال OTP واحفظ المفتاح -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-check-circle"></i> الخطوة 2 — تأكيد وحفظ المفتاح</div>
      </div>
      <div class="card-body">
        <?php if($fPendingReq): ?>
        <div style="padding:10px 14px;background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.2);border-radius:10px;margin-bottom:1rem;font-size:.82rem">
          <i class="fas fa-clock" style="color:#00d4aa"></i>
          انتظار الرمز... Request ID: <strong style="color:#00d4aa"><?=$fPendingReq?></strong>
        </div>
        <form method="POST">
          <div class="form-group">
            <label>رمز التحقق (OTP) المرسل لهاتفك</label>
            <input type="text" name="floosak_otp" placeholder="123456" maxlength="6" style="font-size:1.5rem;letter-spacing:8px;text-align:center" required>
          </div>
          <button type="submit" name="floosak_verify_otp" class="btn btn-success btn-block">
            <i class="fas fa-unlock"></i> تأكيد وحفظ المفتاح
          </button>
        </form>
        <?php else: ?>
        <div style="text-align:center;padding:2rem;color:#8895a7">
          <i class="fas fa-mobile-alt" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.8rem"></i>
          أطلب رمز التحقق أولاً من الخطوة 1
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- إعدادات يدوية + تحديث -->
  <div class="card" style="margin-top:1rem">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-cog"></i> إعدادات متقدمة (يدوي)</div>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label>Merchant Key (Token) <small style="color:#8895a7">تُملأ تلقائياً</small></label>
            <textarea name="floosak_merchant_key" rows="3" style="font-family:monospace;font-size:.72rem;word-break:break-all"><?=htmlspecialchars($floosakCfg['floosak_merchant_key']??'')?></textarea>
          </div>
          <div>
            <div class="form-group">
              <label>Wallet ID <small style="color:#8895a7">تُملأ تلقائياً</small></label>
              <input type="number" name="floosak_wallet_id" value="<?=htmlspecialchars($fWalletId)?>" placeholder="144">
            </div>
            <div class="form-group">
              <label>رقم الهاتف</label>
              <input type="text" name="floosak_phone" value="<?=htmlspecialchars($fPhone)?>">
            </div>
            <div class="form-group">
              <label>الشورت كود</label>
              <input type="text" name="floosak_short_code" value="<?=htmlspecialchars($fShortCode)?>">
            </div>

            <!-- صورة / شعار فلوسك -->
            <div class="form-group">
              <label>شعار / صورة فلوسك <small style="color:#8895a7">PNG، JPG، SVG — حد 2MB</small></label>
              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <?php if($fImage): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars($fImage)?>" id="floosakImgCurrent"
                     style="width:64px;height:64px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,.05);border:1px solid var(--border);padding:4px">
                <?php else: ?>
                <div id="floosakImgPlaceholder" style="width:64px;height:64px;border-radius:12px;background:var(--bg);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:1.4rem">
                  <i class="fas fa-image"></i>
                </div>
                <?php endif; ?>
                <div style="flex:1">
                  <input type="file" name="floosak_image" id="floosakImageInput" accept="image/*,.svg" style="display:none"
                         onchange="previewFloosakImg(this)">
                  <button type="button" onclick="document.getElementById('floosakImageInput').click()"
                          class="btn btn-sm btn-secondary"><i class="fas fa-upload"></i> اختر صورة</button>
                  <div style="font-size:.75rem;color:#8895a7;margin-top:4px">ستظهر للعميل عند اختيار فلوسك</div>
                </div>
              </div>
              <img id="floosakImgPreview" src="" style="display:none;width:80px;height:80px;border-radius:12px;object-fit:contain;margin-top:8px;border:1px solid var(--border);padding:4px;background:rgba(255,255,255,.05)">
            </div>

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px">
              <input type="checkbox" name="floosak_enabled" value="1" <?=$fEnabled?'checked':''?>> تفعيل بوابة فلوسك للعملاء
            </label>
            <button type="submit" name="save_floosak" class="btn btn-warning btn-block"><i class="fas fa-save"></i> حفظ الإعدادات يدوياً</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- سجل عمليات فلوسك -->
  <div class="card" style="margin-top:1rem">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-history"></i> سجل عمليات الشحن عبر فلوسك</div>
    </div>
    <div class="card-body" style="padding:0">
      <?php if(empty($floosakSessions)): ?>
      <div style="text-align:center;padding:2.5rem;color:#8895a7">
        <i class="fas fa-receipt" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
        لا توجد عمليات بعد
      </div>
      <?php else: ?>
      <div class="table-wrap">
      <table style="width:100%;border-collapse:collapse;font-size:.83rem">
        <thead>
          <tr style="background:var(--bg)">
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">#</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">العميل</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">الهاتف</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">المبلغ (YER)</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">المبلغ ($)</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">الحالة</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">التاريخ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($floosakSessions as $s):
          $sColor = ['completed'=>'#00d4aa','pending'=>'#f5a623','failed'=>'#ff4455','expired'=>'#8895a7'][$s['status']]??'#8895a7';
          $sLabel = ['completed'=>'مكتملة','pending'=>'انتظار','failed'=>'فشلت','expired'=>'منتهية'][$s['status']]??$s['status'];
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 14px;color:#8895a7"><?=$s['purchase_id']?></td>
          <td style="padding:10px 14px;font-weight:700"><?=htmlspecialchars($s['username'])?></td>
          <td style="padding:10px 14px;direction:ltr;color:#00d4ff"><?=htmlspecialchars($s['phone']??'')?></td>
          <td style="padding:10px 14px;font-weight:800;color:#fff"><?=number_format($s['amount_yer'])?></td>
          <td style="padding:10px 14px;font-weight:800;color:#00d4aa"><?=$s['amount_usd']?'$'.number_format($s['amount_usd'],4):'—'?></td>
          <td style="padding:10px 14px"><span style="color:<?=$sColor?>;font-weight:800;font-size:.78rem"><?=$sLabel?></span></td>
          <td style="padding:10px 14px;color:#8895a7;font-size:.78rem"><?=date('d/m H:i',strtotime($s['created_at']))?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div><?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══════════════ وكيل الشحن — Floosak Agent ═══════════════ -->
<div id="tab-floosak_agent" class="tab-pane" style="<?=$tab!=='floosak_agent'?'display:none':''?>">

<?php
$agCfg      = floosak_agent_get_config($pdo);
$agEnabled  = !empty($agCfg['floosak_agent_enabled']) && $agCfg['floosak_agent_enabled']==='1';
$agSandbox  = !empty($agCfg['floosak_agent_sandbox']) && $agCfg['floosak_agent_sandbox']==='1';
$agApiUrl   = $agCfg['floosak_agent_api_url'] ?? '';
$agPhone    = $agCfg['floosak_agent_phone']             ?? '';
$agBalance  = (float)($agCfg['floosak_agent_wallet_balance'] ?? 0);
$agExpiry   = (int)($agCfg['floosak_agent_token_expiry']    ?? 0);
$agHasToken = !empty($agCfg['floosak_agent_token']) && $agExpiry > time();
$agSubtab   = $_GET['subtab'] ?? 'settings';

// جلب البيانات
$faMethods = [];
$faBunches = [];
try {
    $faMethods = $pdo->query("SELECT * FROM floosak_agent_methods ORDER BY sort_order,id")->fetchAll();
} catch(Exception $e){}

$filterMethod = (int)($_GET['filter_method'] ?? 0);
try {
    $q = $filterMethod
        ? $pdo->prepare("SELECT * FROM floosak_agent_bunches WHERE method_id=? ORDER BY sort_order,id")
        : $pdo->query("SELECT b.*,m.name_ar as method_name FROM floosak_agent_bunches b LEFT JOIN floosak_agent_methods m ON b.method_id=m.method_id ORDER BY b.method_id,b.sort_order,b.id");
    if($filterMethod) $q->execute([$filterMethod]);
    $faBunches = $q->fetchAll();
} catch(Exception $e){}
?>

<!-- شريط التبويبات الداخلية -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <?php foreach(['settings'=>['fas fa-cog','إعدادات الوكيل'],'methods'=>['fas fa-server','المزودون'],'bunches'=>['fas fa-list','الباقات والخدمات']] as $st=>[$ico,$lbl]): ?>
  <a href="?tab=floosak_agent&subtab=<?=$st?>" class="btn <?=$agSubtab===$st?'btn-primary':'btn-secondary'?>" style="text-decoration:none">
    <i class="<?=$ico?>"></i> <?=$lbl?>
    <?php if($st==='methods'): ?><span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:.75rem;margin-right:4px"><?=count($faMethods)?></span><?php endif; ?>
    <?php if($st==='bunches'): ?><span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:.75rem;margin-right:4px"><?=count($faBunches)?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══ إعدادات الوكيل ══════════════════════════════════════ -->
<?php if($agSubtab==='settings'): ?>

<!-- بطاقة الحالة -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><i class="fas fa-tachometer-alt" style="color:#a78bfa"></i> لوحة حالة الوكيل</div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الخدمة</div>
        <?php if($agEnabled): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-check-circle"></i> مفعّلة</span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-times-circle"></i> معطّلة</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">البيئة</div>
        <?php if($agSandbox): ?>
        <span style="background:#f5a62322;color:#f5a623;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-flask"></i> Sandbox</span>
        <?php else: ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-globe"></i> Production</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">التوكن</div>
        <?php if($agHasToken): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-key"></i> صالح <?=date('H:i',$agExpiry)?></span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-lock"></i> غير مسجّل</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">رصيد YER</div>
        <div style="font-size:1.25rem;font-weight:700;color:#e0e6ed"><?=number_format($agBalance)?></div>
        <div style="font-size:.7rem;color:#8895a7">ريال يمني</div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">المزودون</div>
        <div style="font-size:1.25rem;font-weight:700;color:#a78bfa"><?=count($faMethods)?></div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الباقات</div>
        <?php try{ $bc=$pdo->query("SELECT COUNT(*) FROM floosak_agent_bunches WHERE status=1")->fetchColumn(); }catch(Exception $e){$bc=0;} ?>
        <div style="font-size:1.25rem;font-weight:700;color:#00d4aa"><?=$bc?></div>
      </div>
    </div>
  </div>
</div>

<!-- نموذج الإعدادات -->
<div class="card">
  <div class="card-header"><i class="fas fa-cog"></i> بيانات الوكيل</div>
  <div class="card-body">
    <form method="POST">

      <!-- رابط الـ API -->
      <div class="form-group" style="margin-bottom:1.2rem">
        <label><i class="fas fa-link" style="color:#1e6fff"></i> رابط الـ API (Base URL) <span style="color:#ff4455">*</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="url" name="floosak_agent_api_url" id="agApiUrlInput"
                 value="<?=htmlspecialchars($agApiUrl)?>"
                 placeholder="https://api.example.com"
                 class="form-control"
                 style="font-family:monospace;font-size:.85rem">
          <button type="button" class="btn btn-sm"
                  style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);white-space:nowrap;padding:8px 12px"
                  onclick="document.getElementById('agApiUrlInput').value='https://staging.fintech-expert.net'">
            <i class="fas fa-flask"></i> Sandbox
          </button>
        </div>
        <small style="color:#8895a7">رابط Production يُزوَّد من فلوسك — مثال: https://api.floosak.com</small>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="form-group">
          <label>رقم هاتف الوكيل <span style="color:#ff4455">*</span></label>
          <input type="text" name="floosak_agent_phone" value="<?=htmlspecialchars($agPhone)?>"
                 placeholder="9677XXXXXXXX" class="form-control" required>
          <small style="color:#8895a7">مع كود الدولة — أرقام فقط</small>
        </div>
        <div class="form-group">
          <label>كلمة مرور الوكيل</label>
          <input type="password" name="floosak_agent_password" value=""
                 placeholder="اتركه فارغاً للإبقاء على الحالي" class="form-control" autocomplete="new-password">
          <small style="color:#8895a7">6 أحرف على الأقل</small>
        </div>
      </div>
      <!-- التوكن ومعرف المحفظة -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
        <div class="form-group">
          <label><i class="fas fa-key" style="color:#f5a623"></i> التوكن
            <small style="color:#8895a7;font-weight:400"> — يُملأ تلقائياً</small>
          </label>
          <div style="position:relative">
            <input type="text" name="floosak_agent_token" id="agTokenInput"
                   value="<?=htmlspecialchars(substr($agCfg['floosak_agent_token']??'',0,80))?>"
                   class="form-control" style="font-family:monospace;font-size:.72rem"
                   placeholder="يُملأ تلقائياً بعد الاتصال الناجح" readonly>
          </div>
          <?php if($agHasToken): ?>
          <small style="color:#00d4aa"><i class="fas fa-clock"></i> صالح حتى <?=date('Y-m-d H:i',$agExpiry)?></small>
          <?php else: ?>
          <small style="color:#ff4455">اضغط "اختبار الاتصال" لتوليد التوكن</small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label><i class="fas fa-wallet" style="color:#a78bfa"></i> معرّف المحفظة (YER)
            <small style="color:#8895a7;font-weight:400"> — يُملأ تلقائياً</small>
          </label>
          <input type="text" name="floosak_agent_wallet_id" id="agWalletInput"
                 value="<?=htmlspecialchars($agCfg['floosak_agent_wallet_id']??'')?>"
                 class="form-control" style="font-family:monospace"
                 placeholder="يُملأ تلقائياً بعد الاتصال الناجح" readonly>
        </div>
      </div>

      <div style="display:flex;gap:2rem;margin:1rem 0;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="floosak_agent_enabled" value="1" <?=$agEnabled?'checked':''?>>
          <span>تفعيل الخدمة</span>
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="floosak_agent_sandbox" value="1" <?=$agSandbox?'checked':''?>>
          <span>بيئة Sandbox (تجريبية)</span>
        </label>
      </div>
      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <button type="submit" name="save_floosak_agent" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
        <button type="submit" name="test_floosak_agent" class="btn btn-success"><i class="fas fa-plug"></i> اختبار الاتصال وتحديث الرصيد</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<!-- ══ المزودون ══════════════════════════════════════════════ -->
<?php if($agSubtab==='methods'): ?>

<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-server" style="color:#a78bfa"></i> مزودو الخدمة (method_id)</span>
    <button class="btn btn-primary btn-sm" onclick="openMethodModal(0)"><i class="fas fa-plus"></i> إضافة مزود</button>
  </div>
  <div class="card-body" style="padding:0">
    <div style="overflow-x:auto">
    <table class="table" style="margin:0">
      <thead><tr>
        <th>method_id</th><th>الاسم عربي</th><th>الاسم إنجليزي</th><th>النوع</th><th>الحالة</th><th>الترتيب</th><th>إجراء</th>
      </tr></thead>
      <tbody>
      <?php foreach($faMethods as $m): ?>
      <tr>
        <td><code style="background:rgba(167,139,250,.15);color:#a78bfa;padding:2px 8px;border-radius:5px"><?=(int)$m['method_id']?></code></td>
        <td><?=htmlspecialchars($m['name_ar'])?></td>
        <td style="color:#8895a7"><?=htmlspecialchars($m['name_en'])?></td>
        <td>
          <?php if($m['transaction_type']==='TOPUP'): ?>
          <span style="background:#00d4aa22;color:#00d4aa;padding:2px 8px;border-radius:5px;font-size:.78rem">TOPUP</span>
          <?php else: ?>
          <span style="background:#a78bfa22;color:#a78bfa;padding:2px 8px;border-radius:5px;font-size:.78rem">BILLPAY</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($m['status']): ?>
          <span style="color:#00d4aa;font-size:.8rem"><i class="fas fa-circle"></i> نشط</span>
          <?php else: ?>
          <span style="color:#ff4455;font-size:.8rem"><i class="fas fa-circle"></i> معطّل</span>
          <?php endif; ?>
        </td>
        <td><?=(int)$m['sort_order']?></td>
        <td>
          <button class="btn btn-warning btn-sm" onclick="openMethodModal(<?=$m['id']?>,<?=htmlspecialchars(json_encode($m),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا المزود وجميع باقاته؟')">
            <input type="hidden" name="fa_method_id_row" value="<?=$m['id']?>">
            <button type="submit" name="delete_fa_method" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($faMethods)): ?>
      <tr><td colspan="7" style="text-align:center;color:#8895a7;padding:2rem">لا يوجد مزودون — أضف مزوداً أو استورد من الـ SQL</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- مودال المزود -->
<div class="modal-overlay" id="methodModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title" id="methodModalTitle"><i class="fas fa-server"></i> مزود جديد</div>
      <button class="modal-close" onclick="document.getElementById('methodModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" id="methodForm">
        <input type="hidden" name="fa_method_id_row" id="fa_method_id_row" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>method_id <span style="color:#ff4455">*</span></label>
            <input type="number" name="fa_method_id" id="fa_method_id" class="form-control" required min="1">
            <small style="color:#8895a7">الرقم من توثيق Floosak</small>
          </div>
          <div class="form-group">
            <label>النوع</label>
            <select name="fa_type" id="fa_type" class="form-control">
              <option value="TOPUP">TOPUP — شحن رصيد</option>
              <option value="BILLPAY">BILLPAY — دفع فواتير</option>
            </select>
          </div>
          <div class="form-group">
            <label>الاسم عربي <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_name_ar" id="fa_name_ar" class="form-control" required>
          </div>
          <div class="form-group">
            <label>الاسم إنجليزي</label>
            <input type="text" name="fa_name_en" id="fa_name_en" class="form-control">
          </div>
          <div class="form-group">
            <label>الأيقونة (Font Awesome)</label>
            <input type="text" name="fa_icon" id="fa_icon" class="form-control" value="sim-card" placeholder="sim-card">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="fa_color" id="fa_color" class="form-control" value="#6c3fe0">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="fa_sort" id="fa_sort" class="form-control" value="0" min="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.8rem">
            <input type="checkbox" name="fa_status" id="fa_status" value="1" checked>
            <label for="fa_status" style="margin:0">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_fa_method" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ══ الباقات والخدمات ═══════════════════════════════════════ -->
<?php if($agSubtab==='bunches'): ?>

<div class="card" style="margin-bottom:1rem">
  <div class="card-body" style="padding:.75rem 1rem">
    <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="tab" value="floosak_agent">
      <input type="hidden" name="subtab" value="bunches">
      <label style="margin:0;white-space:nowrap">تصفية بالمزود:</label>
      <select name="filter_method" class="form-control" style="width:220px" onchange="this.form.submit()">
        <option value="0">جميع المزودين</option>
        <?php foreach($faMethods as $m): ?>
        <option value="<?=(int)$m['method_id']?>" <?=$filterMethod==(int)$m['method_id']?'selected':''?>>
          <?=htmlspecialchars($m['name_ar'])?> (<?=(int)$m['method_id']?>)
        </option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-primary btn-sm" onclick="openBunchModal(0)" style="margin-right:auto">
        <i class="fas fa-plus"></i> إضافة باقة
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <i class="fas fa-list" style="color:#00d4aa"></i>
    الباقات والخدمات
    <span style="background:rgba(0,212,170,.15);color:#00d4aa;padding:2px 10px;border-radius:10px;font-size:.8rem;margin-right:.5rem"><?=count($faBunches)?></span>
  </div>
  <div class="card-body" style="padding:0">
    <div style="overflow-x:auto;max-height:600px">
    <table class="table" style="margin:0;font-size:.85rem">
      <thead style="position:sticky;top:0;z-index:5"><tr>
        <th>ID</th>
        <?php if(!$filterMethod): ?><th>المزود</th><?php endif; ?>
        <th>bunch_id</th><th>اسم الباقة</th><th>المجموعة</th><th>القسم</th><th>نوع الدفع</th><th>السعر</th><th>المدة</th><th>الحالة</th><th>إجراء</th>
      </tr></thead>
      <tbody>
      <?php foreach($faBunches as $b): ?>
      <tr>
        <td style="color:#8895a7;font-size:.75rem"><?=$b['id']?></td>
        <?php if(!$filterMethod): ?>
        <td><span style="background:rgba(167,139,250,.12);color:#a78bfa;padding:1px 7px;border-radius:5px;font-size:.75rem"><?=(int)$b['method_id']?><?=isset($b['method_name'])?' — '.htmlspecialchars($b['method_name']):''?></span></td>
        <?php endif; ?>
        <td><code style="font-size:.75rem;color:#f5a623"><?=$b['bunch_id']??'—'?></code></td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($b['bunch_name'])?>"><?=htmlspecialchars($b['bunch_name'])?></td>
        <td style="font-size:.72rem;color:#a78bfa;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($b['bundle_group']??'—')?></td>
        <td>
          <?php $sec=$b['section']??'bundles'; ?>
          <?php if($sec==='fees'): ?><span style="background:rgba(245,166,35,.15);color:#f5a623;padding:2px 7px;border-radius:5px;font-size:.72rem">فئات</span>
          <?php elseif($sec==='amount'): ?><span style="background:rgba(0,212,170,.1);color:#00d4aa;padding:2px 7px;border-radius:5px;font-size:.72rem">رصيد حر</span>
          <?php else: ?><span style="background:rgba(30,111,255,.1);color:#6ba3ff;padding:2px 7px;border-radius:5px;font-size:.72rem">باقات</span>
          <?php endif; ?>
        </td>
        <td>
          <?php $pt=$b['payment_type']??'both'; ?>
          <?php if($pt==='prepaid'): ?><span style="font-size:.7rem;color:#00d4aa">مسبق</span>
          <?php elseif($pt==='postpaid'): ?><span style="font-size:.7rem;color:#a78bfa">فوترة</span>
          <?php else: ?><span style="font-size:.7rem;color:#8895a7">كلاهما</span>
          <?php endif; ?>
        </td>
        <td style="font-weight:700;color:#f5a623"><?=$b['price']?number_format((float)$b['price']).' ر':'—'?></td>
        <td style="color:#8895a7;font-size:.78rem"><?=htmlspecialchars($b['validity']??'—')?></td>
        <td>
          <?php if($b['status']): ?>
          <span style="color:#00d4aa;font-size:.75rem"><i class="fas fa-circle"></i></span>
          <?php else: ?>
          <span style="color:#ff4455;font-size:.75rem"><i class="fas fa-circle"></i></span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <button class="btn btn-warning btn-sm" style="padding:2px 7px" onclick="openBunchModal(<?=$b['id']?>,<?=htmlspecialchars(json_encode($b),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذه الباقة؟')">
            <input type="hidden" name="fa_bunch_row_id" value="<?=$b['id']?>">
            <input type="hidden" name="fa_b_method_id" value="<?=(int)$b['method_id']?>">
            <button type="submit" name="delete_fa_bunch" class="btn btn-danger btn-sm" style="padding:2px 7px"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($faBunches)): ?>
      <tr><td colspan="10" style="text-align:center;color:#8895a7;padding:2rem">لا توجد باقات — أضف باقة أو استورد ملف SQL</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- مودال الباقة -->
<div class="modal-overlay" id="bunchModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <div class="modal-title" id="bunchModalTitle"><i class="fas fa-box"></i> باقة جديدة</div>
      <button class="modal-close" onclick="document.getElementById('bunchModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" id="bunchForm">
        <input type="hidden" name="fa_bunch_row_id" id="fa_bunch_row_id" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>المزود <span style="color:#ff4455">*</span></label>
            <select name="fa_b_method_id" id="fa_b_method_id" class="form-control" required onchange="updateGroupSuggestions(this.value)">
              <?php foreach($faMethods as $m): ?>
              <option value="<?=(int)$m['method_id']?>" <?=$filterMethod==(int)$m['method_id']?'selected':''?>>
                <?=(int)$m['method_id']?> — <?=htmlspecialchars($m['name_ar'])?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>bunch_id (الرقم)</label>
            <input type="number" name="fa_b_bunch_id" id="fa_b_bunch_id" class="form-control" placeholder="مثال: 340">
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم الباقة <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_b_name" id="fa_b_name" class="form-control" required placeholder="مثال: رصيد يمن موبايل">
          </div>
          <div class="form-group">
            <label>code <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_b_code" id="fa_b_code" class="form-control" required placeholder="مثال: 340">
          </div>
          <div class="form-group">
            <label>unified_code <span style="color:#ff4455">*</span><small style="color:#8895a7;font-weight:400"> (يُرسَل لفلوسك)</small></label>
            <input type="text" name="fa_b_unified_code" id="fa_b_unified_code" class="form-control" required placeholder="مثال: 340 أو A69332">
          </div>
          <div class="form-group">
            <label><i class="fas fa-bolt" style="color:#f5a623"></i> Fore num / offerId
              <small style="color:#8895a7;font-weight:400"> — رقم الباقة في Fore Yemen (فارغ = يستخدم unified_code)</small>
            </label>
            <input type="text" name="fa_b_fore_num" id="fa_b_fore_num" class="form-control" placeholder="مثال: 40 أو A69332">
          </div>
          <div class="form-group">
            <label>السعر (ريال) <small style="color:#8895a7;font-weight:400">يظهر للعميل</small></label>
            <input type="number" name="fa_b_price" id="fa_b_price" class="form-control" step="1" placeholder="مثال: 1500">
          </div>
          <div class="form-group">
            <label>مدة الصلاحية</label>
            <select name="fa_b_validity" id="fa_b_validity" class="form-control">
              <option value="">—</option>
              <option value="يومي">يومي</option>
              <option value="يومين">يومين</option>
              <option value="أسبوعي">أسبوعي</option>
              <option value="10 أيام">10 أيام</option>
              <option value="شهري">شهري</option>
            </select>
          </div>
          <div class="form-group">
            <label>القسم <span style="color:#ff4455">*</span></label>
            <select name="fa_b_section" id="fa_b_section" class="form-control" onchange="toggleFreeAmount(this.value)">
              <option value="bundles">📦 باقات</option>
              <option value="fees">🏷️ فئات (سعر ثابت)</option>
              <option value="amount">💰 رصيد حر (مبلغ مفتوح)</option>
              <optgroup label="── يمن فورجي ──">
                <option value="yemen4g_change">🔄 تغيير باقة يمن فورجي (باقات)</option>
                <option value="yemen4g_credit">📶 رصيد يمن فورجي اتصال (مبلغ مفتوح)</option>
                <option value="yemen4g_internet">🌐 تغيير باقات يمن فورجي إنترنت فقط (باقات)</option>
                <option value="yemen4g_voice">📞 تغيير باقات يمن فورجي صوت فقط (باقات)</option>
              </optgroup>
            </select>
          </div>
          <div class="form-group">
            <label>نوع الدفع</label>
            <select name="fa_b_ptype" id="fa_b_ptype" class="form-control">
              <option value="both">كلاهما</option>
              <option value="prepaid">دفع مسبق فقط</option>
              <option value="postpaid">فوترة فقط</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم المجموعة <small style="color:#8895a7;font-weight:400">للباقات فقط — مثال: باقات مزايا فورجي</small></label>
            <input type="text" name="fa_b_group" id="fa_b_group" class="form-control" placeholder="مثال: باقات مزايا فورجي">
            <!-- اختصارات للمجموعات الشائعة -->
            <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px" id="group-suggestions"></div>
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="fa_b_sort" id="fa_b_sort" class="form-control" value="0" min="0">
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;gap:.5rem;padding-top:.5rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
              <input type="checkbox" name="fa_b_status" id="fa_b_status" value="1" checked>
              <span>نشطة</span>
            </label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer" id="free-amount-row" style="display:none">
              <input type="checkbox" name="fa_b_is_free" id="fa_b_is_free" value="1">
              <span>رصيد حر (يكتب العميل المبلغ)</span>
            </label>
          </div>
        </div>
        <button type="submit" name="save_fa_bunch" class="btn btn-primary btn-block" style="margin-top:.5rem"><i class="fas fa-save"></i> حفظ الباقة</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

</div><!-- /tab-floosak_agent -->

<!-- ══ تبويب قائمة سداد ══════════════════════════════════════ -->
<div id="tab-sadad" class="pay-tab-content" <?=$tab!=='sadad'?'style="display:none"':''?>>

<?php
// جلب البيانات
$sadadCats = [];
$sadadSvcs = [];
try {
    $sadadCats = $pdo->query("SELECT * FROM sadad_categories ORDER BY parent_id IS NOT NULL, sort_order, id")->fetchAll();
    $sadadSvcs = $pdo->query("SELECT s.*, c.name_ar as cat_name FROM sadad_services s JOIN sadad_categories c ON s.category_id=c.id ORDER BY s.sort_order,s.id")->fetchAll();
    $sadadMethods = $pdo->query("SELECT method_id, name_ar FROM floosak_agent_methods WHERE status=1 ORDER BY sort_order")->fetchAll();
} catch(Exception $e) {}

$mainCats = array_filter($sadadCats, fn($c)=>!$c['parent_id']);
$subCats  = array_filter($sadadCats, fn($c)=> $c['parent_id']);
?>

<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
  <button class="btn btn-primary" onclick="openSadadCatModal(0)">
    <i class="fas fa-plus"></i> قسم جديد
  </button>
  <button class="btn btn-success" onclick="openSadadSvcModal(0)">
    <i class="fas fa-plus"></i> خدمة جديدة
  </button>
</div>

<!-- شجرة الأقسام والخدمات -->
<div style="display:flex;flex-direction:column;gap:12px">
<?php foreach($mainCats as $mc): ?>
  <?php $subList = array_filter($sadadCats, fn($c)=>$c['parent_id']==$mc['id']); ?>
  <div class="card" style="border-left:4px solid <?=htmlspecialchars($mc['color'])?>">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;border-radius:8px;background:<?=htmlspecialchars($mc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff">
          <i class="fas fa-<?=htmlspecialchars($mc['icon'])?>"></i>
        </div>
        <div>
          <strong><?=htmlspecialchars($mc['name_ar'])?></strong>
          <span style="font-size:.75rem;color:#8895a7;margin-right:6px">قسم رئيسي #<?=$mc['id']?></span>
          <?=$mc['status']?'<span style="color:#00d4aa;font-size:.72rem">● نشط</span>':'<span style="color:#ff4455;font-size:.72rem">● معطّل</span>'?>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn btn-warning btn-sm" onclick="openSadadCatModal(<?=$mc['id']?>,<?=htmlspecialchars(json_encode($mc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
        <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا القسم وكل محتوياته؟')">
          <input type="hidden" name="sadad_cat_id" value="<?=$mc['id']?>">
          <button type="submit" name="delete_sadad_cat" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    </div>

    <!-- أقسام فرعية -->
    <?php if($subList): ?>
    <div class="card-body" style="padding:8px;display:flex;flex-direction:column;gap:8px">
      <?php foreach($subList as $sc): ?>
        <?php $svcs = array_filter($sadadSvcs, fn($s)=>$s['category_id']==$sc['id']); ?>
        <div style="background:rgba(255,255,255,.04);border-radius:10px;border:1px solid rgba(255,255,255,.08)">
          <div style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:26px;height:26px;border-radius:7px;background:<?=htmlspecialchars($sc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem">
                <i class="fas fa-<?=htmlspecialchars($sc['icon'])?>"></i>
              </div>
              <span style="font-weight:700;font-size:.85rem"><?=htmlspecialchars($sc['name_ar'])?></span>
              <span style="font-size:.72rem;color:#8895a7">قسم فرعي</span>
            </div>
            <div style="display:flex;gap:5px">
              <button class="btn btn-success btn-sm" onclick="openSadadSvcModal(0,<?=$sc['id']?>)" title="إضافة خدمة"><i class="fas fa-plus"></i></button>
              <button class="btn btn-warning btn-sm" onclick="openSadadCatModal(<?=$sc['id']?>,<?=htmlspecialchars(json_encode($sc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
              <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
                <input type="hidden" name="sadad_cat_id" value="<?=$sc['id']?>">
                <button type="submit" name="delete_sadad_cat" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
          <!-- خدمات القسم الفرعي -->
          <?php if($svcs): ?>
          <div style="padding:0 14px 10px;display:flex;flex-direction:column;gap:5px">
            <?php foreach($svcs as $svc): ?>
            <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between">
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:22px;height:22px;border-radius:6px;background:<?=htmlspecialchars($svc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem">
                  <i class="fas fa-<?=htmlspecialchars($svc['icon'])?>"></i>
                </div>
                <span style="font-size:.8rem"><?=htmlspecialchars($svc['name_ar'])?></span>
                <?php if($svc['method_id']): ?><span style="font-size:.65rem;color:#a78bfa;background:rgba(167,139,250,.1);padding:1px 6px;border-radius:5px">method=<?=$svc['method_id']?></span><?php endif; ?>
                <span style="font-size:.65rem;color:#8895a7"><?=$svc['action']?></span>
              </div>
              <div style="display:flex;gap:4px">
                <button class="btn btn-warning btn-sm" style="padding:2px 6px" onclick="openSadadSvcModal(<?=$svc['id']?>,0,<?=htmlspecialchars(json_encode($svc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
                  <input type="hidden" name="sadad_svc_id" value="<?=$svc['id']?>">
                  <button type="submit" name="delete_sadad_svc" class="btn btn-danger btn-sm" style="padding:2px 6px"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- خدمات مباشرة في القسم الرئيسي -->
    <?php $directSvcs = array_filter($sadadSvcs, fn($s)=>$s['category_id']==$mc['id']); ?>
    <?php if($directSvcs): ?>
    <div class="card-body" style="padding:8px;display:flex;flex-direction:column;gap:5px">
      <?php foreach($directSvcs as $svc): ?>
      <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:22px;height:22px;border-radius:6px;background:<?=htmlspecialchars($svc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem">
            <i class="fas fa-<?=htmlspecialchars($svc['icon'])?>"></i>
          </div>
          <span style="font-size:.8rem"><?=htmlspecialchars($svc['name_ar'])?></span>
          <?php if($svc['method_id']): ?><span style="font-size:.65rem;color:#a78bfa;background:rgba(167,139,250,.1);padding:1px 6px;border-radius:5px">method=<?=$svc['method_id']?></span><?php endif; ?>
        </div>
        <div style="display:flex;gap:4px">
          <button class="btn btn-warning btn-sm" style="padding:2px 6px" onclick="openSadadSvcModal(<?=$svc['id']?>,0,<?=htmlspecialchars(json_encode($svc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
            <input type="hidden" name="sadad_svc_id" value="<?=$svc['id']?>">
            <button type="submit" name="delete_sadad_svc" class="btn btn-danger btn-sm" style="padding:2px 6px"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card-body" style="padding:8px">
      <button class="btn btn-sm btn-success" onclick="openSadadCatModal(0,null,<?=$mc['id']?>)"><i class="fas fa-folder-plus"></i> قسم فرعي</button>
      <button class="btn btn-sm btn-primary" onclick="openSadadSvcModal(0,<?=$mc['id']?>)" style="margin-right:6px"><i class="fas fa-plus"></i> خدمة</button>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- ══ Modal قسم ══ -->
<div class="modal-overlay" id="sadadCatModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title" id="sadadCatModalTitle"><i class="fas fa-folder-plus"></i> قسم جديد</div>
      <button class="modal-close" onclick="document.getElementById('sadadCatModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="sadad_cat_id" id="sc_id" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم القسم *</label>
            <input type="text" name="sadad_cat_name" id="sc_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>القسم الأب (اختياري)</label>
            <select name="sadad_cat_parent" id="sc_parent" class="form-control">
              <option value="">— قسم رئيسي —</option>
              <?php foreach($mainCats as $mc2): ?>
              <option value="<?=$mc2['id']?>"><?=htmlspecialchars($mc2['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>أيقونة (Font Awesome)</label>
            <input type="text" name="sadad_cat_icon" id="sc_icon" class="form-control" placeholder="list">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="sadad_cat_color" id="sc_color" class="form-control" value="#6c3fe0" style="height:42px">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sadad_cat_sort" id="sc_sort" class="form-control" value="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
            <input type="checkbox" name="sadad_cat_status" id="sc_status" value="1" checked>
            <label for="sc_status">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_sadad_cat" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ Modal خدمة ══ -->
<div class="modal-overlay" id="sadadSvcModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-plus-circle"></i> خدمة سداد</div>
      <button class="modal-close" onclick="document.getElementById('sadadSvcModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="sadad_svc_id" id="ss_id" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم الخدمة *</label>
            <input type="text" name="sadad_svc_name" id="ss_name" class="form-control" required>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>القسم *</label>
            <select name="sadad_svc_cat" id="ss_cat" class="form-control" required>
              <?php foreach($sadadCats as $sc2): ?>
              <option value="<?=$sc2['id']?>"><?=str_repeat('— ',(int)!!$sc2['parent_id'])?><?=htmlspecialchars($sc2['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>أيقونة</label>
            <input type="text" name="sadad_svc_icon" id="ss_icon" class="form-control" placeholder="file-invoice">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="sadad_svc_color" id="ss_color" class="form-control" value="#f5a623" style="height:42px">
          </div>
          <div class="form-group">
            <label>المزود (method_id)</label>
            <select name="sadad_svc_method" id="ss_method" class="form-control">
              <option value="">— بدون مزود —</option>
              <?php foreach($sadadMethods??[] as $sm): ?>
              <option value="<?=$sm['method_id']?>"><?=$sm['method_id']?> — <?=htmlspecialchars($sm['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الإجراء</label>
            <select name="sadad_svc_action" id="ss_action" class="form-control" onchange="toggleSadadUrl(this.value)">
              <option value="bill">فاتورة (bill)</option>
              <option value="external">رابط خارجي</option>
              <option value="none">بدون إجراء</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1" id="ss_url_wrap" style="display:none">
            <label>الرابط</label>
            <input type="url" name="sadad_svc_url" id="ss_url" class="form-control" placeholder="https://...">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sadad_svc_sort" id="ss_sort" class="form-control" value="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
            <input type="checkbox" name="sadad_svc_status" id="ss_status" value="1" checked>
            <label for="ss_status">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_sadad_svc" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<script>
function openSadadCatModal(id, data, parentId) {
  document.getElementById('sadadCatModal').classList.add('open');
  document.getElementById('sadadCatModalTitle').innerHTML = id ? '<i class="fas fa-edit"></i> تعديل قسم' : '<i class="fas fa-folder-plus"></i> قسم جديد';
  document.getElementById('sc_id').value    = id || 0;
  document.getElementById('sc_parent').value = parentId || (data?.parent_id ?? '');
  document.getElementById('sc_name').value  = data?.name_ar   || '';
  document.getElementById('sc_icon').value  = data?.icon      || 'list';
  document.getElementById('sc_color').value = data?.color     || '#6c3fe0';
  document.getElementById('sc_sort').value  = data?.sort_order|| 0;
  document.getElementById('sc_status').checked = !data || data.status == 1;
}
function openSadadSvcModal(id, catId, data) {
  document.getElementById('sadadSvcModal').classList.add('open');
  document.getElementById('ss_id').value     = id || 0;
  document.getElementById('ss_cat').value    = catId || data?.category_id || '';
  document.getElementById('ss_name').value   = data?.name_ar      || '';
  document.getElementById('ss_icon').value   = data?.icon         || 'file-invoice';
  document.getElementById('ss_color').value  = data?.color        || '#f5a623';
  document.getElementById('ss_method').value = data?.method_id    || '';
  document.getElementById('ss_action').value = data?.action       || 'bill';
  document.getElementById('ss_url').value    = data?.action_value || '';
  document.getElementById('ss_sort').value   = data?.sort_order   || 0;
  document.getElementById('ss_status').checked = !data || data.status == 1;
  toggleSadadUrl(data?.action || 'bill');
}
function toggleSadadUrl(val) {
  const wrap = document.getElementById('ss_url_wrap');
  if (wrap) wrap.style.display = val === 'external' ? '' : 'none';
}
</script>

</div><!-- /tab-sadad -->

<!-- ══ تبويب Fore Yemen ══════════════════════════════════════ -->
<div id="tab-fore" class="pay-tab-content" <?=$tab!=='fore'?'style="display:none"':''?>>

<?php
$foreDomain   = getSetting('fore_domain');
$foreUserid   = getSetting('fore_userid');
$foreUsername = getSetting('fore_username');
$foreEnabled  = getSetting('fore_enabled');
$foreBalance  = getSetting('fore_balance');
$foreLastTest = (int)getSetting('fore_last_test');
?>

<!-- بطاقة الحالة -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem">
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الحالة</div>
        <?php if($foreEnabled==='1'): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-check-circle"></i> مفعّل</span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-times-circle"></i> معطّل</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">رصيد الوكيل</div>
        <div style="font-size:1.25rem;font-weight:700;color:#e0e6ed"><?=htmlspecialchars($foreBalance ?: '—')?></div>
        <?php if($foreLastTest): ?><div style="font-size:.68rem;color:#8895a7"><?=date('H:i d/m',$foreLastTest)?></div><?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الدومين</div>
        <div style="font-size:.78rem;color:#a78bfa;word-break:break-all"><?=htmlspecialchars($foreDomain ?: '—')?></div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">User ID</div>
        <div style="font-size:1.1rem;font-weight:700;color:#e0e6ed"><?=htmlspecialchars($foreUserid ?: '—')?></div>
      </div>
    </div>
  </div>
</div>

<!-- نموذج الإعدادات -->
<div class="card">
  <div class="card-header"><i class="fas fa-cog"></i> إعدادات Fore Yemen</div>
  <div class="card-body">
    <form method="POST">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-globe"></i> رابط API (Domain)</label>
          <input type="url" name="fore_domain" class="form-control"
                 value="<?=htmlspecialchars($foreDomain)?>"
                 placeholder="https://fore.yemoney.net/api/yr">
        </div>

        <div class="form-group">
          <label><i class="fas fa-id-badge"></i> User ID</label>
          <input type="text" name="fore_userid" class="form-control"
                 value="<?=htmlspecialchars($foreUserid)?>"
                 placeholder="رقم المعرف">
        </div>

        <div class="form-group">
          <label><i class="fas fa-user"></i> Username</label>
          <input type="text" name="fore_username" class="form-control"
                 value="<?=htmlspecialchars($foreUsername)?>"
                 placeholder="اسم المستخدم">
        </div>

        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-lock"></i> Password</label>
          <input type="password" name="fore_password" class="form-control"
                 placeholder="كلمة المرور (اتركها فارغة للإبقاء على الحالية)"
                 autocomplete="new-password">
          <small style="color:#8895a7">التوكن = md5(md5(password) + transid + username + mobile)</small>
        </div>

      </div>

      <div style="margin:1rem 0">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="fore_enabled" value="1" <?=$foreEnabled==='1'?'checked':''?>>
          <span>تفعيل Fore Yemen</span>
        </label>
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <button type="submit" name="save_fore" class="btn btn-primary">
          <i class="fas fa-save"></i> حفظ الإعدادات
        </button>
        <button type="submit" name="test_fore" class="btn btn-success">
          <i class="fas fa-plug"></i> اختبار الاتصال وتحديث الرصيد
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ربط الشبكات بـ Fore أو فلوسك -->
<div class="card" style="margin-top:1.5rem">
  <div class="card-header"><i class="fas fa-network-wired"></i> مزود كل شبكة</div>
  <div class="card-body">
    <p style="color:#8895a7;font-size:.82rem;margin-bottom:1rem">
      اختر لكل شبكة المزود الذي ينفّذ عمليات الشحن — Fore Yemen أو فلوسك Agent.
    </p>
    <form method="POST">
    <?php
    $networkProviders = [
        1  => ['يمن موبايل', '77/78', '#cc0000'],
        2  => ['سبأفون',      '71',    '#ff6600'],
        3  => ['يو',          '73',    '#0066cc'],
        12 => ['واي',         '70',    '#800080'],
        17 => ['عدن نت',      '79',    '#20c997'],
        16 => ['يمن فورجي',   '10',    '#e91e8c'],
        4  => ['ADSL',        '01-09', '#00aaff'],
        13 => ['الخط الثابت', '01-09', '#6c757d'],
    ];
    ?>
    <div style="display:flex;flex-direction:column;gap:8px">
    <?php foreach($networkProviders as $mid => [$name, $prefix, $color]): ?>
    <?php $settingKey = "network_{$mid}_provider"; $current = getSetting($settingKey) ?: 'fore'; ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:rgba(255,255,255,.03);border-radius:10px;border:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;border-radius:8px;background:<?=htmlspecialchars($color)?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;font-weight:700">
          <?=htmlspecialchars($prefix)?>
        </div>
        <span style="font-weight:700"><?=htmlspecialchars($name)?></span>
      </div>
      <div style="display:flex;gap:8px">
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border-radius:8px;border:1px solid var(--border);<?=$current==='fore'?'background:rgba(0,212,170,.1);border-color:#00d4aa':''?>">
          <input type="radio" name="np_<?=$mid?>" value="fore" <?=$current==='fore'?'checked':''?> style="accent-color:#00d4aa">
          <span style="font-size:.8rem;color:#00d4aa"><i class="fas fa-bolt"></i> Fore</span>
        </label>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border-radius:8px;border:1px solid var(--border);<?=$current==='floosak'?'background:rgba(108,63,224,.1);border-color:var(--primary)':''?>">
          <input type="radio" name="np_<?=$mid?>" value="floosak" <?=$current==='floosak'?'checked':''?> style="accent-color:var(--primary)">
          <span style="font-size:.8rem;color:var(--primary)"><i class="fas fa-sim-card"></i> فلوسك</span>
        </label>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:1rem">
      <button type="submit" name="save_network_providers" class="btn btn-primary">
        <i class="fas fa-save"></i> حفظ اختيارات المزودين
      </button>
    </div>
    </form>
  </div>
</div>

</div><!-- /tab-fore -->

<script>
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
  } else {
    document.getElementById('methodForm').reset();
    document.getElementById('fa_method_id_row').value = 0;
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
  const div = document.createElement('div');
  div.className = 'rate-row';
  div.innerHTML = `
    <input type="text" name="code[]" placeholder="AED" class="form-control" style="font-size:.85rem;text-transform:uppercase">
    <input type="text" name="cname[]" placeholder="درهم إماراتي" class="form-control" style="font-size:.85rem">
    <input type="text" name="symbol[]" placeholder="د.إ" class="form-control" style="font-size:.85rem">
    <input type="text" name="rate[]" placeholder="0.2722800000" class="form-control" style="font-size:.85rem">
    <div style="text-align:center"><input type="checkbox" name="cstatus[${rateCount}]" value="1" checked style="width:18px;height:18px;accent-color:var(--primary)"></div>
    <button type="button" onclick="this.closest('.rate-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
  `;
  c.appendChild(div); rateCount++;
}

function openApprove(id, amount, user, method) {
  document.getElementById('approveForm').action = `?action=approve&id=${id}`;
  document.getElementById('approveInfo').innerHTML = `
    <strong>${user}</strong> — ${method}<br>
    <span style="font-size:1.3rem;font-weight:900;color:#00d4aa">$${parseFloat(amount).toFixed(4)}</span>
    <span style="color:#8895a7;font-size:.85rem"> ستُضاف لرصيده</span>
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

<?php include 'footer.php'; ?>
