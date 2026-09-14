<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_customers_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة العملاء - ' . SITE_NAME;

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── حذف ناعم للعميل (soft delete) ────────────────────────────────────────────
if ($action === 'delete' && $id && isAdmin()) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL"); } catch(Exception $e) {}
    $delUser = $pdo->prepare("SELECT username, role FROM users WHERE id=?");
    $delUser->execute([$id]); $delUser = $delUser->fetch();
    if ($delUser && $delUser['role'] === 'customer') {
        // soft delete — نضع علامة حذف فقط
        $pdo->prepare("UPDATE users SET is_deleted=1, deleted_at=NOW(), status=0, username=CONCAT(username,'__deleted__',id) WHERE id=?")
            ->execute([$id]);
        logStaffAction($pdo,'delete_customer','user',$id,'حذف العميل: '.$delUser['username']);
        flashMessage('success','✅ تم حذف العميل '.$delUser['username']);
    }
    redirect(SITE_URL.'/admin/customers.php');
}

// ─── تبديل حالة الحساب ─────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_edit')) { flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/customers.php'); }
    $pdo->prepare("UPDATE users SET status=IF(status=1,0,1) WHERE id=? AND role='customer'")->execute([$id]);
    flashMessage('success','تم تحديث حالة الحساب');
    redirect(SITE_URL.'/admin/customers.php');
}

// ─── تصريح / حظر جهاز ─────────────────────────────────────────────────────
if ($action === 'approve_device' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_devices')) { flashMessage('danger','ليس لديك صلاحية تصريح الأجهزة'); redirect(SITE_URL.'/admin/customers.php'); }
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("UPDATE user_devices SET status='approved',approved_by=?,approved_at=NOW() WHERE id=? AND user_id=?")
            ->execute([$_SESSION['user_id'], $did, $id]);
        logStaffAction($pdo,'approve_device','user',$id,'تصريح جهاز للعميل #'.$id);
    flashMessage('success','تم تصريح الجهاز بنجاح');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}
if ($action === 'block_device' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_devices')) { flashMessage('danger','ليس لديك صلاحية'); redirect(SITE_URL.'/admin/customers.php'); }
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$did, $id]);
        flashMessage('success','تم حظر الجهاز');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}
if ($action === 'delete_device' && $id) {
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $pdo->prepare("DELETE FROM user_devices WHERE id=? AND user_id=?")->execute([$did, $id]);
        flashMessage('success','تم حذف الجهاز');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تبديل API ──────────────────────────────────────────────────────────────
if ($action === 'toggle_api' && $id) {
    try {
        $u = $pdo->prepare("SELECT api_enabled,api_key FROM users WHERE id=?"); $u->execute([$id]); $u=$u->fetch();
        if ($u['api_enabled']) {
            $pdo->prepare("UPDATE users SET api_enabled=0 WHERE id=?")->execute([$id]);
            flashMessage('success','تم إيقاف API للعميل');
        } else {
            $key    = bin2hex(random_bytes(20));
            $secret = bin2hex(random_bytes(20));
            $pdo->prepare("UPDATE users SET api_enabled=1,api_key=COALESCE(api_key,?),api_secret=COALESCE(api_secret,?) WHERE id=?")->execute([$key,$secret,$id]);
            flashMessage('success','تم تفعيل API للعميل');
        }
    } catch (\PDOException $e) {
        flashMessage('danger','يجب تشغيل update_database.sql أولاً لإضافة أعمدة API');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تجديد مفاتيح API ──────────────────────────────────────────────────────
if ($action === 'regen_api' && $id) {
    $key    = bin2hex(random_bytes(20));
    $secret = bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE users SET api_key=?,api_secret=? WHERE id=?")->execute([$key,$secret,$id]);
    flashMessage('success','تم تجديد مفاتيح API');
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$id);
}

// ─── تعديل بيانات العميل ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_user'])) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_edit')) { flashMessage('danger','ليس لديك صلاحية تعديل بيانات العملاء'); redirect(SITE_URL.'/admin/customers.php'); }
    $uid         = (int)$_POST['user_id'];
    $fullName    = trim($_POST['full_name']);
    $displayName = trim($_POST['display_name'] ?? '');
    $email       = trim($_POST['email']);
    $phone       = trim($_POST['phone']);
    $newPw       = trim($_POST['new_password']);
    $groupId     = $_POST['group_id'] !== '' ? (int)$_POST['group_id'] : null;

    // أضف عمود display_name إن لم يكن موجوداً
    try {
        $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
        if (!in_array('display_name', $cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `display_name` VARCHAR(100) DEFAULT NULL AFTER `full_name`");
        }
    } catch(Exception $e) {}

    $pdo->prepare("UPDATE users SET full_name=?,display_name=?,email=?,phone=?,group_id=? WHERE id=? AND role='customer'")
        ->execute([$fullName, $displayName ?: null, $email, $phone, $groupId, $uid]);

    if ($newPw) {
        if (strlen($newPw) < 6) {
            flashMessage('danger','كلمة المرور يجب 6 أحرف على الأقل');
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPw,PASSWORD_DEFAULT),$uid]);
            flashMessage('success','تم تحديث بيانات العميل وكلمة المرور');
        }
    } else {
        logStaffAction($pdo,'edit_customer','user',$uid,'تعديل بيانات العميل #'.$uid);
    flashMessage('success','تم تحديث بيانات العميل');
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$uid);
}

// ─── تعديل رصيد ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_balance'])) {
    if (!isAdmin() && !canAccess($pdo,'perm_customers_balance')) { flashMessage('danger','ليس لديك صلاحية تعديل الرصيد'); redirect(SITE_URL.'/admin/customers.php'); }
    $uid      = (int)$_POST['user_id'];
    $amount   = (float)$_POST['amount'];
    $type     = $_POST['type'];
    $subType  = ($type === 'credit') ? (in_array($_POST['sub_type']??'', ['topup','refund','gift']) ? $_POST['sub_type'] : 'topup') : null;
    $desc     = trim($_POST['description']) ?: match($subType) { 'refund'=>'استرداد من الإدارة', 'gift'=>'هدية من الإدارة', default=>'تأمين من الإدارة' };
    // إنشاء عمود sub_type إن لم يكن موجوداً
    try { $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN sub_type VARCHAR(20) NULL DEFAULT NULL"); } catch(Exception $e) {}
    $u2 = $pdo->prepare("SELECT * FROM users WHERE id=?"); $u2->execute([$uid]); $u2=$u2->fetch();
    if ($u2 && $amount > 0) {
        $nb = $type==='credit' ? $u2['balance']+$amount : max(0,$u2['balance']-$amount);
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$nb,$uid]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,sub_type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?,?)")
            ->execute([$uid,$type,$subType,$amount,$u2['balance'],$nb,$desc]);
        logStaffAction($pdo,'update_balance','user',$uid,'تعديل رصيد العميل #'.$uid.' — '.$type.' '.$amount);
    flashMessage('success','تم تحديث الرصيد');
    }
    redirect(SITE_URL.'/admin/customers.php'.($id?"?action=view&id=$id":''));
}

// ─── تغيير المُحيل ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_referrer'])) {
    if (!isAdmin()) { flashMessage('danger','صلاحية أدمن فقط'); redirect(SITE_URL.'/admin/customers.php'); }
    $uid        = (int)$_POST['user_id'];
    $newRefCode = strtoupper(trim($_POST['new_referrer_code'] ?? ''));

    if (empty($newRefCode)) {
        // إزالة المُحيل نهائياً
        $pdo->prepare("UPDATE users SET referred_by=NULL WHERE id=?")->execute([$uid]);
        // حذف سجل الإحالة القديم
        $pdo->prepare("DELETE FROM referrals WHERE referred_id=?")->execute([$uid]);
        logStaffAction($pdo,'change_referrer','user',$uid,'إزالة المُحيل عن العميل #'.$uid);
        flashMessage('success','✅ تم إزالة المُحيل — العمليات المستقبلية لن تُحسب عمولة');
    } else {
        // البحث عن المُحيل الجديد بالكود
        $newRef = $pdo->prepare("SELECT id, username, full_name FROM users WHERE referral_code=? AND id != ? AND role='customer'");
        $newRef->execute([$newRefCode, $uid]);
        $newReferrer = $newRef->fetch();

        if (!$newReferrer) {
            flashMessage('danger','❌ كود الإحالة غير موجود أو غير صالح');
        } else {
            $newReferrerId = (int)$newReferrer['id'];
            $oldRefRow = $pdo->prepare("SELECT referred_by FROM users WHERE id=?");
            $oldRefRow->execute([$uid]); $oldRefRow = $oldRefRow->fetch();
            $oldReferrerId = (int)($oldRefRow['referred_by'] ?? 0);

            if ($newReferrerId === $oldReferrerId) {
                flashMessage('warning','⚠️ هذا هو نفس المُحيل الحالي');
            } else {
                // تحديث referred_by
                $pdo->prepare("UPDATE users SET referred_by=? WHERE id=?")->execute([$newReferrerId, $uid]);

                // حذف سجل الإحالة القديم
                if ($oldReferrerId) {
                    $pdo->prepare("DELETE FROM referrals WHERE referrer_id=? AND referred_id=?")->execute([$oldReferrerId, $uid]);
                }

                // إنشاء سجل إحالة جديد
                $rewardType    = getSetting('referral_reward_type') ?: 'order_percent';
                $percentReward = (float)(getSetting('referral_percent') ?: 0);
                $fixedReward   = (float)(getSetting('referral_fixed_reward') ?: 0);
                try {
                    $pdo->prepare("INSERT IGNORE INTO referrals (referrer_id,referred_id,status,trigger_type,reward_referrer,reward_percent,commission_paid) VALUES (?,?,'pending',?,?,?,0)")
                        ->execute([$newReferrerId, $uid, $rewardType, $fixedReward, $percentReward]);
                } catch(Exception $e) {
                    // إذا كان موجوداً بالفعل نحدّثه
                    $pdo->prepare("UPDATE referrals SET referrer_id=?,trigger_type=?,reward_percent=?,status='pending' WHERE referred_id=?")
                        ->execute([$newReferrerId, $rewardType, $percentReward, $uid]);
                }

                $newName = $newReferrer['full_name'] ?: $newReferrer['username'];
                logStaffAction($pdo,'change_referrer','user',$uid,"تغيير مُحيل العميل #$uid إلى: $newName (#$newReferrerId)");
                flashMessage('success',"✅ تم تغيير المُحيل إلى: $newName — العمولة تُحسب من الطلبات المستقبلية فقط");
            }
        }
    }
    redirect(SITE_URL.'/admin/customers.php?action=view&id='.$uid);
}

// ─── صفحة تفاصيل عميل ──────────────────────────────────────────────────────
$viewCustomer = null;
$viewDevices  = [];
$viewOrders   = [];
if ($action === 'view' && $id) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='customer'"); $s->execute([$id]); $viewCustomer=$s->fetch();
    if (!$viewCustomer) { flashMessage('danger','العميل غير موجود'); redirect(SITE_URL.'/admin/customers.php'); }

    $ds = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? ORDER BY created_at DESC"); $ds->execute([$id]); $viewDevices=$ds->fetchAll();
    $os = $pdo->prepare("SELECT o.*,s.name as svc_name FROM orders o JOIN services s ON o.service_id=s.id WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 10"); $os->execute([$id]); $viewOrders=$os->fetchAll();
    // KYC
    try {
        $kycStmt = $pdo->prepare("SELECT k.*, u2.username as reviewer FROM kyc_requests k LEFT JOIN users u2 ON k.reviewed_by=u2.id WHERE k.user_id=? ORDER BY k.id DESC LIMIT 1");
        $kycStmt->execute([$id]);
        $viewKyc = $kycStmt->fetch();
    } catch(Exception $e) { $viewKyc = null; }
}

// ─── قائمة العملاء ──────────────────────────────────────────────────────────
$custFilter = $_GET['filter'] ?? 'all'; // all | google | normal
$googleWhere = $custFilter === 'google' ? "AND u.google_id IS NOT NULL AND u.google_id != ''" :
               ($custFilter === 'normal' ? "AND (u.google_id IS NULL OR u.google_id = '')" : '');

// مصدر إنشاء الحساب: قيمة صريحة للحسابات الجديدة، مع استنتاج آمن للحسابات القديمة
$userColumns = [];
$hasRegistrationSource = false;
try {
    $userColumns = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
    if (!in_array('registration_source', $userColumns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN registration_source VARCHAR(50) NULL DEFAULT NULL");
        $userColumns[] = 'registration_source';
    }
    $hasRegistrationSource = in_array('registration_source', $userColumns, true);
} catch (Throwable $e) {}
$hasGoogleId = in_array('google_id', $userColumns, true);
$hasTelegramChatId = in_array('telegram_chat_id', $userColumns, true);
$hasIsDeleted = in_array('is_deleted', $userColumns, true);
$hasDisplayName = in_array('display_name', $userColumns, true);
$hasApiEnabled = in_array('api_enabled', $userColumns, true);
$hasGoogleAvatar = in_array('google_avatar', $userColumns, true);
$legacySourceCase = $hasGoogleId && $hasTelegramChatId
    ? "CASE WHEN u.google_id IS NOT NULL AND u.google_id != '' THEN 'google' WHEN u.telegram_chat_id IS NOT NULL AND u.telegram_chat_id != '' THEN 'telegram' ELSE 'website' END"
    : ($hasGoogleId ? "CASE WHEN u.google_id IS NOT NULL AND u.google_id != '' THEN 'google' ELSE 'website' END" : "'website'");
$registrationSourceExpr = $hasRegistrationSource
    ? "COALESCE(NULLIF(u.registration_source,''), {$legacySourceCase})"
    : $legacySourceCase;

// إنشاء جدول KYC إن لم يكن موجوداً
try { $pdo->exec("CREATE TABLE IF NOT EXISTS kyc_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    id_type ENUM('national','passport','family','electronic') DEFAULT 'national',
    full_name VARCHAR(255) DEFAULT '',
    national_id VARCHAR(100) DEFAULT '',
    birth_date DATE NULL,
    birth_place VARCHAR(255) DEFAULT '',
    issue_date DATE NULL,
    expiry_date DATE NULL,
    image_front VARCHAR(500) DEFAULT '',
    image_back VARCHAR(500) DEFAULT '',
    extra_fields JSON DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    admin_note TEXT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

$kycExists = false;
try { $pdo->query("SELECT 1 FROM kyc_requests LIMIT 1"); $kycExists = true; } catch(Exception $e) {}

$kycSubquery = $kycExists
    ? "(SELECT k.status FROM kyc_requests k WHERE k.user_id=u.id ORDER BY k.id DESC LIMIT 1) as kyc_status"
    : "NULL as kyc_status";

// ─── فلاتر العملاء المتقدمة ─────────────────────────────────────────────────
$filterQ          = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$filterUserId     = max(0, (int)($_GET['user_id'] ?? 0));
$filterGroupId    = max(0, (int)($_GET['group_id'] ?? 0));
$filterStatus     = in_array(($_GET['account_status'] ?? ''), ['active','blocked'], true) ? $_GET['account_status'] : '';
$filterSource     = trim((string)($_GET['registration_source_filter'] ?? ''));
$filterKyc        = in_array(($_GET['kyc_filter'] ?? ''), ['approved','pending','rejected','none'], true) ? $_GET['kyc_filter'] : '';
$filterReferrer   = in_array(($_GET['referrer_filter'] ?? ''), ['with','without'], true) ? $_GET['referrer_filter'] : '';
$filterOrders     = in_array(($_GET['orders_filter'] ?? ''), ['with','without'], true) ? $_GET['orders_filter'] : '';
$filterApi        = in_array(($_GET['api_filter'] ?? ''), ['enabled','disabled'], true) ? $_GET['api_filter'] : '';
$filterDevice     = in_array(($_GET['device_filter'] ?? ''), ['pending','approved','blocked','none'], true) ? $_GET['device_filter'] : '';
$filterMinBalance = ($_GET['min_balance'] ?? '') !== '' && is_numeric($_GET['min_balance']) ? (float)$_GET['min_balance'] : null;
$filterMaxBalance = ($_GET['max_balance'] ?? '') !== '' && is_numeric($_GET['max_balance']) ? (float)$_GET['max_balance'] : null;
$filterMinOrders  = ($_GET['min_orders'] ?? '') !== '' && ctype_digit((string)$_GET['min_orders']) ? (int)$_GET['min_orders'] : null;
$filterMaxOrders  = ($_GET['max_orders'] ?? '') !== '' && ctype_digit((string)$_GET['max_orders']) ? (int)$_GET['max_orders'] : null;
$filterFrom       = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)($_GET['created_from'] ?? '')) ? $_GET['created_from'] : '';
$filterTo         = preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)($_GET['created_to'] ?? '')) ? $_GET['created_to'] : '';
$filterSort       = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'ASC' : 'DESC';
$displayNameExpr   = $hasDisplayName ? "COALESCE(u.display_name,'')" : "''";
$referrerDeletedExpr = $hasIsDeleted ? 'ref.is_deleted' : '0';
$apiEnabledExpr    = $hasApiEnabled ? 'u.api_enabled' : '0';
$googleAvatarExpr  = $hasGoogleAvatar ? 'u.google_avatar' : 'NULL';

$where = ["u.role='customer'"];
$params = [];
if ($custFilter === 'google') {
    $where[] = $hasGoogleId ? "u.google_id IS NOT NULL AND u.google_id != ''" : '1=0';
} elseif ($custFilter === 'normal') {
    $where[] = $hasGoogleId ? "(u.google_id IS NULL OR u.google_id = '')" : '1=1';
}
if ($hasIsDeleted) $where[] = "(u.is_deleted IS NULL OR u.is_deleted=0)";
if ($filterQ !== '') {
    $like = '%' . $filterQ . '%';
    $where[] = "(CAST(u.id AS CHAR) LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.full_name LIKE ? OR {$displayNameExpr} LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filterUserId > 0) { $where[] = 'u.id=?'; $params[] = $filterUserId; }
if ($filterGroupId > 0) { $where[] = 'u.group_id=?'; $params[] = $filterGroupId; }
if ($filterStatus === 'active') { $where[] = 'u.status=1'; }
if ($filterStatus === 'blocked') { $where[] = '(u.status IS NULL OR u.status=0)'; }
if ($filterSource !== '') { $where[] = "($registrationSourceExpr)=?"; $params[] = $filterSource; }
if ($filterReferrer === 'with') { $where[] = 'u.referred_by IS NOT NULL AND u.referred_by > 0'; }
if ($filterReferrer === 'without') { $where[] = '(u.referred_by IS NULL OR u.referred_by=0)'; }
if ($filterMinBalance !== null) { $where[] = 'u.balance>=?'; $params[] = $filterMinBalance; }
if ($filterMaxBalance !== null) { $where[] = 'u.balance<=?'; $params[] = $filterMaxBalance; }
if ($filterFrom !== '') { $where[] = 'u.created_at>=?'; $params[] = $filterFrom . ' 00:00:00'; }
if ($filterTo !== '') { $where[] = 'u.created_at<=?'; $params[] = $filterTo . ' 23:59:59'; }
if ($filterApi !== '' && in_array('api_enabled', $userColumns, true)) {
    $where[] = $filterApi === 'enabled' ? 'u.api_enabled=1' : '(u.api_enabled IS NULL OR u.api_enabled=0)';
}
if ($filterOrders === 'with') { $where[] = 'EXISTS (SELECT 1 FROM orders ox WHERE ox.user_id=u.id)'; }
if ($filterOrders === 'without') { $where[] = 'NOT EXISTS (SELECT 1 FROM orders ox WHERE ox.user_id=u.id)'; }
if ($filterMinOrders !== null) { $where[] = '(SELECT COUNT(*) FROM orders ox WHERE ox.user_id=u.id)>=?'; $params[] = $filterMinOrders; }
if ($filterMaxOrders !== null) { $where[] = '(SELECT COUNT(*) FROM orders ox WHERE ox.user_id=u.id)<=?'; $params[] = $filterMaxOrders; }
if ($filterKyc !== '' && $kycExists) {
    if ($filterKyc === 'none') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM kyc_requests kf WHERE kf.user_id=u.id)';
    } else {
        $where[] = 'EXISTS (SELECT 1 FROM kyc_requests kf WHERE kf.user_id=u.id AND kf.status=? AND kf.id=(SELECT MAX(kf2.id) FROM kyc_requests kf2 WHERE kf2.user_id=u.id))';
        $params[] = $filterKyc;
    }
}
if ($filterDevice !== '') {
    if ($filterDevice === 'none') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM user_devices df WHERE df.user_id=u.id)';
    } else {
        $where[] = 'EXISTS (SELECT 1 FROM user_devices df WHERE df.user_id=u.id AND df.status=?)';
        $params[] = $filterDevice;
    }
}
$whereSql = implode(' AND ', $where);

$groupOptions = [];
try { $groupOptions = $pdo->query("SELECT id,name,default_value FROM pricing_groups WHERE status=1 ORDER BY name ASC")->fetchAll(); } catch (Throwable $e) {}
$customerOptions = [];
try {
    $deletedCondition = in_array('is_deleted', $userColumns, true) ? "AND (is_deleted IS NULL OR is_deleted=0)" : '';
    $customerOptions = $pdo->query("SELECT id,username,full_name,email,phone FROM users WHERE role='customer' {$deletedCondition} ORDER BY username ASC LIMIT 2000")->fetchAll();
} catch (Throwable $e) {}
$customerFilterCount = 0;
foreach ([$filterQ,$filterUserId,$filterGroupId,$filterStatus,$filterSource,$filterKyc,$filterReferrer,$filterOrders,$filterApi,$filterDevice,$filterMinBalance,$filterMaxBalance,$filterMinOrders,$filterMaxOrders,$filterFrom,$filterTo] as $filterValue) {
    if ($filterValue !== '' && $filterValue !== null && $filterValue !== 0) $customerFilterCount++;
}

try {
    $customerSql = "
        SELECT u.*,
            COUNT(DISTINCT o.id) as orders_count,
            COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price ELSE 0 END),0) as total_spent,
            COUNT(DISTINCT CASE WHEN d.status='pending' THEN d.id END) as pending_devices,
            $kycSubquery,
            $registrationSourceExpr as registration_source_display,
            $apiEnabledExpr as api_enabled,
            $googleAvatarExpr as google_avatar,
            ref.username as referrer_username, $referrerDeletedExpr as referrer_deleted,
            pg.name as group_name, pg.default_value as group_default_value
        FROM users u
        LEFT JOIN orders o ON u.id=o.user_id
        LEFT JOIN user_devices d ON u.id=d.user_id
        LEFT JOIN users ref ON u.referred_by = ref.id
        LEFT JOIN pricing_groups pg ON pg.id = u.group_id AND pg.status = 1
        WHERE $whereSql
        GROUP BY u.id ORDER BY u.created_at $filterSort";
    $customerStmt = $pdo->prepare($customerSql);
    $customerStmt->execute($params);
    $customers = $customerStmt->fetchAll();
} catch (\PDOException $e) {
    // توافق مع قواعد البيانات التي لا تحتوي بعد على جدول مجموعات التسعير أو أعمدة العرض الاختيارية.
    $customerSql = "
        SELECT u.*,
            COUNT(DISTINCT o.id) as orders_count,
            COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price ELSE 0 END),0) as total_spent,
            0 as pending_devices,
            NULL as kyc_status,
            $registrationSourceExpr as registration_source_display,
            $apiEnabledExpr as api_enabled,
            $googleAvatarExpr as google_avatar
        FROM users u
        LEFT JOIN orders o ON u.id=o.user_id
        WHERE $whereSql
        GROUP BY u.id ORDER BY u.created_at $filterSort";
    $customerStmt = $pdo->prepare($customerSql);
    $customerStmt->execute($params);
    $customers = $customerStmt->fetchAll();
}
$googleCount = 0;
try { $googleCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND google_id IS NOT NULL AND google_id != ''")->fetchColumn(); } catch(Exception $e){}

include 'header.php';

$statusColors = ['pending'=>'#f5a623','approved'=>'#00d4aa','blocked'=>'#ff4455'];
$statusLabels = ['pending'=>'انتظار','approved'=>'مصرح','blocked'=>'محظور'];
$registrationSourceLabels = [
    'website' => 'الموقع',
    'web' => 'الموقع',
    'telegram' => 'Telegram',
    'google' => 'Google',
    'admin' => 'الإدارة',
    'api' => 'API',
    'import' => 'استيراد',
];
$registrationSourceColors = [
    'website' => '#6c3fe0', 'web' => '#6c3fe0', 'telegram' => '#229ed9',
    'google' => '#4285f4', 'admin' => '#f5a623', 'api' => '#00d4aa', 'import' => '#8895a7',
];
$deviceTypes  = ['desktop'=>'🖥️','mobile'=>'📱','tablet'=>'📟','unknown'=>'🖥️'];
?>
<style>
.cust-tabs{display:flex;gap:6px;border-bottom:1px solid var(--border);margin-bottom:1.5rem;padding-bottom:0}
.cust-tab{padding:10px 20px;border-radius:10px 10px 0 0;cursor:pointer;font-weight:700;font-size:.87rem;color:var(--text2);border:1px solid transparent;border-bottom:none;transition:.2s;background:none;font-family:var(--font)}
.cust-tab.active{background:var(--card2);border-color:var(--border);color:#fff}
.cust-tab-pane{display:none}
.cust-tab-pane.active{display:block}
.device-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px;margin-bottom:10px}
.device-status-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.device-icon{font-size:2rem;width:48px;text-align:center;flex-shrink:0}
.device-info{flex:1;min-width:0}
.device-name{font-weight:800;font-size:.95rem}
.device-meta{font-size:.78rem;color:#8895a7;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.device-actions{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap}
.pending-pulse{animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.info-item{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:14px}
.info-item-label{font-size:.75rem;color:#8895a7;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.info-item-value{font-size:1.1rem;font-weight:800}
.api-key-box{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-family:monospace;font-size:.82rem;word-break:break-all;color:var(--text2);margin-top:6px;display:flex;align-items:center;gap:8px}
.api-key-box span{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.customer-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}
.customer-filter-grid .form-group{margin:0}
.customer-filter-grid label{display:block;font-size:.75rem;color:var(--text2);margin-bottom:5px;font-weight:700}
.customer-filter-grid input,.customer-filter-grid select{width:100%;min-height:40px}
.customer-filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}
.customer-filter-summary{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
.customer-filter-chip{font-size:.73rem;color:var(--cyan);background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);border-radius:20px;padding:4px 9px}
@media(max-width:900px){.customer-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.customer-filter-grid{grid-template-columns:1fr}}
</style>

<?php if ($action === 'view' && $viewCustomer): ?>

<!-- ─── رأس صفحة العميل ──────────────────────────────────────────────────── -->
<div class="page-header" style="margin-bottom:1rem">
  <div style="display:flex;align-items:center;gap:14px">
    <a href="customers.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a>
    <div style="width:52px;height:52px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;flex-shrink:0">
      <?= mb_strtoupper(mb_substr($viewCustomer['username'],0,1)) ?>
    </div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <?= htmlspecialchars($viewCustomer['username']) ?>
        <?php if($viewCustomer['full_name']): ?><small style="font-size:.7em;color:#8895a7"> — <?=htmlspecialchars($viewCustomer['full_name'])?></small><?php endif; ?>
        <?php if(($viewKyc['status']??'') === 'approved'): ?>
        <span style="background:rgba(0,200,83,.15);color:#00e676;border:1px solid rgba(0,200,83,.35);border-radius:20px;font-size:12px;font-weight:800;padding:2px 10px;display:inline-flex;align-items:center;gap:4px">
          <i class="fas fa-shield-check" style="font-size:10px"></i> موثّق
        </span>
        <?php elseif(($viewKyc['status']??'') === 'pending'): ?>
        <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);border-radius:20px;font-size:12px;font-weight:800;padding:2px 10px">
          ⏳ تحقق معلق
        </span>
        <?php endif; ?>
      </h2>
      <div style="font-size:.82rem;color:#8895a7;margin-top:2px">
        #<?=str_pad($viewCustomer['id'],6,'0',STR_PAD_LEFT)?> &nbsp;·&nbsp;
        <?=htmlspecialchars($viewCustomer['email'])?> &nbsp;·&nbsp;
        <?php if($viewCustomer['status']): ?>
        <span style="color:#00d4aa"><i class="fas fa-circle" style="font-size:7px"></i> حساب نشط</span>
        <?php else: ?>
        <span style="color:#ff4455"><i class="fas fa-circle" style="font-size:7px"></i> حساب موقوف</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="?action=toggle&id=<?=$viewCustomer['id']?>" class="btn btn-sm <?=$viewCustomer['status']?'btn-danger':'btn-success'?>"
       onclick="return confirm('<?=$viewCustomer['status']?'إيقاف':'تفعيل'?> هذا الحساب؟')">
      <i class="fas fa-<?=$viewCustomer['status']?'ban':'check'?>"></i>
      <?=$viewCustomer['status']?'إيقاف الحساب':'تفعيل الحساب'?>
    </a>
  </div>
</div>

<!-- ─── بطاقات الإحصاء ──────────────────────────────────────────────────── -->
<?php
$totalOrders  = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?"); $totalOrders->execute([$id]); $totalOrders=$totalOrders->fetchColumn();
$totalSpent   = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE user_id=? AND status='completed'"); $totalSpent->execute([$id]); $totalSpent=$totalSpent->fetchColumn();
$pendingDevs  = count(array_filter($viewDevices, fn($d)=>$d['status']==='pending'));
?>
<div class="info-grid" style="grid-template-columns:repeat(4,1fr)">
  <div class="info-item">
    <div class="info-item-label">الرصيد</div>
    <div class="info-item-value" style="color:#00d4aa"><?=formatMoney($viewCustomer['balance'])?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">إجمالي الطلبات</div>
    <div class="info-item-value"><?=$totalOrders?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">إجمالي المدفوع</div>
    <div class="info-item-value" style="color:#6c3fe0"><?=formatMoney($totalSpent)?></div>
  </div>
  <div class="info-item">
    <div class="info-item-label">الأجهزة <?=$pendingDevs>0?'<span class="pending-pulse" style="color:#f5a623;font-size:11px"> ('.$pendingDevs.' انتظار)</span>':''?></div>
    <div class="info-item-value"><?=count($viewDevices)?></div>
  </div>
</div>

<!-- ─── تبويبات ──────────────────────────────────────────────────────────── -->
<div class="card">
<div class="cust-tabs">
  <button class="cust-tab active" onclick="switchTab('devices',this)"><i class="fas fa-mobile-alt"></i> الأجهزة (<?=count($viewDevices)?>)</button>
  <button class="cust-tab" onclick="switchTab('kyc',this)" id="kycTabBtn">
    <i class="fas fa-id-card"></i> تحقق الهوية
    <?php if(($viewKyc['status']??'') === 'approved'): ?>
    <span style="background:rgba(0,200,83,.2);color:#00e676;border-radius:10px;padding:1px 7px;font-size:11px;margin-right:4px">✓</span>
    <?php elseif(($viewKyc['status']??'') === 'pending'): ?>
    <span style="background:rgba(245,166,35,.2);color:#f5a623;border-radius:10px;padding:1px 7px;font-size:11px;margin-right:4px">⏳</span>
    <?php endif; ?>
  </button>
  <button class="cust-tab" onclick="switchTab('edit',this)"><i class="fas fa-user-edit"></i> تعديل البيانات</button>
  <button class="cust-tab" onclick="switchTab('balance',this)"><i class="fas fa-wallet"></i> تعديل الرصيد</button>
  <button class="cust-tab" onclick="switchTab('api',this)"><i class="fas fa-code"></i> صلاحيات API</button>
  <button class="cust-tab" onclick="switchTab('orders',this)"><i class="fas fa-list"></i> آخر الطلبات</button>
  <button class="cust-tab" onclick="switchTab('referral',this)"><i class="fas fa-user-plus"></i> الإحالة</button>
</div>

<!-- ─── تبويب تحقق الهوية ───────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-kyc">
<?php
$kycTypeLabels = ['national'=>'بطاقة شخصية','passport'=>'جواز سفر','family'=>'بطاقة عائلية','electronic'=>'بطاقة إلكترونية'];
$kycStatusCfg  = [
    'pending'  => ['⏳','#f5a623','قيد المراجعة'],
    'approved' => ['✅','#00e676','موافق عليه'],
    'rejected' => ['✗' ,'#ff4757','مرفوض'],
];
if (!$viewKyc): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-id-card" style="font-size:3rem;display:block;margin-bottom:1rem;opacity:.3"></i>
    <div style="font-weight:700;margin-bottom:4px">لم يُقدّم هذا العميل طلب تحقق بعد</div>
    <div style="font-size:.85rem">سيظهر هنا بعد تقديم الطلب من التطبيق</div>
  </div>
<?php else:
  $sc = $kycStatusCfg[$viewKyc['status']] ?? $kycStatusCfg['pending'];
?>

  <!-- شارة الحالة -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:10px">
      <span style="font-size:28px"><?= $sc[0] ?></span>
      <div>
        <div style="font-size:16px;font-weight:900;color:<?= $sc[1] ?>"><?= $sc[2] ?></div>
        <div style="font-size:12px;color:#8895a7">
          <?= $viewKyc['reviewed_at'] ? 'تمت المراجعة: '.date('d/m/Y H:i',strtotime($viewKyc['reviewed_at'])) : 'أُرسل: '.date('d/m/Y H:i',strtotime($viewKyc['created_at'])) ?>
          <?php if($viewKyc['reviewer']): ?> · بواسطة: <?= htmlspecialchars($viewKyc['reviewer']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <a href="kyc.php?action=view&id=<?= $viewKyc['id'] ?>" class="btn btn-sm btn-primary">
      <i class="fas fa-external-link-alt"></i> فتح في صفحة التحقق
    </a>
  </div>

  <!-- ملاحظة الرفض -->
  <?php if($viewKyc['status'] === 'rejected' && $viewKyc['admin_note']): ?>
  <div style="background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.25);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#ff4757">
    <i class="fas fa-exclamation-circle" style="margin-left:6px"></i>سبب الرفض: <?= htmlspecialchars($viewKyc['admin_note']) ?>
  </div>
  <?php endif; ?>

  <!-- البيانات الشخصية -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
    <?php
    $kycRows = [
      ['fas fa-id-card','نوع الهوية',       $kycTypeLabels[$viewKyc['id_type']] ?? $viewKyc['id_type']],
      ['fas fa-user',   'الاسم كما في الهوية', $viewKyc['full_name']],
      ['fas fa-hashtag','رقم الهوية',        $viewKyc['national_id']],
      ['fas fa-birthday-cake','تاريخ الميلاد', $viewKyc['birth_date'] ? date('d/m/Y',strtotime($viewKyc['birth_date'])) : '—'],
      ['fas fa-map-marker-alt','مكان الميلاد', $viewKyc['birth_place'] ?: '—'],
      ['fas fa-calendar-plus','تاريخ الإصدار', $viewKyc['issue_date']  ? date('d/m/Y',strtotime($viewKyc['issue_date']))  : '—'],
      ['fas fa-calendar-times','تاريخ الانتهاء',$viewKyc['expiry_date'] ? date('d/m/Y',strtotime($viewKyc['expiry_date'])) : '—'],
      ['fas fa-clock','تاريخ الطلب',         date('d/m/Y H:i',strtotime($viewKyc['created_at']))],
    ];
    // حقول إضافية
    $extra = json_decode($viewKyc['extra_fields'] ?? '{}', true) ?: [];
    foreach($extra as $ek => $ev) $kycRows[] = ['fas fa-plus-circle', htmlspecialchars($ek), htmlspecialchars($ev)];
    foreach($kycRows as [$icon,$label,$val]):
    ?>
    <div style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px">
      <div style="font-size:11px;color:#8895a7;margin-bottom:6px;display:flex;align-items:center;gap:5px">
        <i class="<?= $icon ?>" style="color:var(--primary);width:14px;text-align:center"></i><?= $label ?>
      </div>
      <div style="font-size:15px;font-weight:800;color:#fff"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- صور الهوية -->
  <div style="margin-bottom:20px">
    <div style="font-size:13px;font-weight:800;color:#8895a7;margin-bottom:12px;display:flex;align-items:center;gap:6px">
      <i class="fas fa-images" style="color:var(--cyan)"></i> صور الهوية
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <?php if($viewKyc['image_front']): ?>
      <div>
        <div style="font-size:11px;color:#8895a7;margin-bottom:6px;font-weight:700">الوجه الأمامي</div>
        <a href="<?= SITE_URL.'/'.$viewKyc['image_front'] ?>" target="_blank" title="فتح بالحجم الكامل">
          <img src="<?= SITE_URL.'/'.$viewKyc['image_front'] ?>"
            style="width:220px;height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in;transition:.2s"
            onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform=''">
        </a>
      </div>
      <?php endif; ?>
      <?php if($viewKyc['image_back']): ?>
      <div>
        <div style="font-size:11px;color:#8895a7;margin-bottom:6px;font-weight:700">الوجه الخلفي</div>
        <a href="<?= SITE_URL.'/'.$viewKyc['image_back'] ?>" target="_blank" title="فتح بالحجم الكامل">
          <img src="<?= SITE_URL.'/'.$viewKyc['image_back'] ?>"
            style="width:220px;height:140px;object-fit:cover;border-radius:12px;border:1px solid var(--border);cursor:zoom-in;transition:.2s"
            onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform=''">
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<!-- ─── تبويب الأجهزة ──────────────────────────────────────────────────── -->
<div class="cust-tab-pane active" id="tab-devices">
  <?php if (empty($viewDevices)): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-mobile-alt" style="font-size:3rem;margin-bottom:1rem;display:block;opacity:.3"></i>
    لم يسجّل هذا العميل دخولاً من أي جهاز بعد
  </div>
  <?php else: ?>
  <?php foreach ($viewDevices as $dv): ?>
  <?php $col = $statusColors[$dv['status']]; $lbl = $statusLabels[$dv['status']]; $ico = $deviceTypes[$dv['device_type']] ?? '🖥️'; ?>
  <div class="device-card">
    <div class="device-status-dot" style="background:<?=$col?>;box-shadow:0 0 8px <?=$col?>80;<?=$dv['status']==='pending'?'animation:pulse 1.5s infinite':''?>"></div>
    <div class="device-icon"><?=$ico?></div>
    <div class="device-info">
      <div class="device-name">
        <?=htmlspecialchars($dv['device_name'] ?: 'جهاز غير معروف')?>
        <?php if($dv['is_first_device']): ?><span style="background:rgba(0,212,170,0.15);color:#00d4aa;font-size:10px;padding:2px 8px;border-radius:20px;margin-right:6px">أول جهاز</span><?php endif; ?>
      </div>
      <div class="device-meta">
        <?=htmlspecialchars($dv['browser']??'')?> · <?=htmlspecialchars($dv['os']??'')?>
        · IP: <?=htmlspecialchars($dv['ip_address']??'—')?>
        · آخر نشاط: <?=$dv['last_seen'] ? date('d/m H:i',strtotime($dv['last_seen'])) : '—'?>
      </div>
      <div style="margin-top:5px">
        <span style="background:<?=$col?>22;color:<?=$col?>;border:1px solid <?=$col?>55;font-size:10px;padding:2px 10px;border-radius:20px">
          <?=$lbl?>
        </span>
        <small style="color:#8895a7;margin-right:8px">مضاف: <?=date('d/m/Y',strtotime($dv['created_at']))?></small>
      </div>
    </div>
    <div class="device-actions">
      <?php if($dv['status'] !== 'approved'): ?>
      <a href="?action=approve_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-success" title="تصريح الجهاز">
        <i class="fas fa-check"></i> تصريح
      </a>
      <?php endif; ?>
      <?php if($dv['status'] !== 'blocked'): ?>
      <a href="?action=block_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-warning" title="حظر الجهاز" onclick="return confirm('حظر هذا الجهاز؟')">
        <i class="fas fa-ban"></i>
      </a>
      <?php endif; ?>
      <a href="?action=delete_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-danger" title="حذف الجهاز" onclick="return confirm('حذف سجل هذا الجهاز؟')">
        <i class="fas fa-trash"></i>
      </a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ─── تبويب تعديل البيانات ────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-edit">
  <?php
  // جلب المجموعات المتاحة
  $pricingGroups = [];
  try {
      $pricingGroups = $pdo->query("SELECT id,name,default_value FROM pricing_groups WHERE status=1 ORDER BY name")->fetchAll();
  } catch(Exception $e){}
  $customerGroupId = $viewCustomer['group_id'] ?? null;
  ?>
  <form method="POST">
        <?= adminCsrfField() ?>
    <input type="hidden" name="update_user" value="1">
    <input type="hidden" name="user_id" value="<?=$viewCustomer['id']?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>اسم المستخدم</label>
        <input type="text" value="<?=htmlspecialchars($viewCustomer['username'])?>" disabled style="opacity:.5">
      </div>
      <div class="form-group">
        <label>الاسم الكامل <small style="color:#8895a7">(الأدمن فقط)</small></label>
        <input type="text" name="full_name" value="<?=htmlspecialchars($viewCustomer['full_name']??'')?>">
      </div>
      <div class="form-group">
        <label>الاسم المعروض <small style="color:#8895a7">(يراه العميل)</small></label>
        <input type="text" name="display_name" value="<?=htmlspecialchars($viewCustomer['display_name']??'')?>"
               placeholder="اتركه فارغاً لاستخدام الاسم الكامل">
      </div>
      <div class="form-group">
        <label>البريد الإلكتروني *</label>
        <input type="email" name="email" value="<?=htmlspecialchars($viewCustomer['email']??'')?>" required>
      </div>
      <div class="form-group">
        <label>رقم الهاتف</label>
        <input type="tel" name="phone" value="<?=htmlspecialchars($viewCustomer['phone']??'')?>">
      </div>
      <div class="form-group">
        <label>كلمة المرور الجديدة <small style="color:#8895a7">(اتركها فارغة إذا لم تريد تغييرها)</small></label>
        <input type="text" name="new_password" placeholder="أدخل كلمة مرور جديدة..." autocomplete="off">
      </div>

      <!-- ── مجموعة التسعير ── -->
      <div class="form-group">
        <label><i class="fas fa-tags" style="color:#f5a623"></i> مجموعة التسعير</label>
        <select name="group_id" class="form-control">
          <option value="" <?=!$customerGroupId?'selected':''?>>— بدون مجموعة (السعر الأصلي) —</option>
          <?php foreach($pricingGroups as $pg):
            $pv = (float)$pg['default_value'];
            $badge = $pv>0 ? ' (+'.number_format($pv,1).'%)' : ($pv<0 ? ' ('.number_format($pv,1).'%)' : '');
          ?>
          <option value="<?=$pg['id']?>" <?=$customerGroupId==$pg['id']?'selected':''?>>
            <?=htmlspecialchars($pg['name'])?><?=$badge?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if($customerGroupId): ?>
        <?php $cg = array_filter($pricingGroups, fn($x)=>$x['id']==$customerGroupId); $cg=reset($cg); ?>
        <?php if($cg): $cv=(float)$cg['default_value']; ?>
        <div style="margin-top:5px;font-size:.72rem;background:rgba(245,166,35,.08);border-radius:7px;padding:5px 10px;color:#f5a623">
          <i class="fas fa-info-circle"></i>
          العميل في مجموعة <strong><?=htmlspecialchars($cg['name'])?></strong>
          — التسعير: <?=$cv>=0?'+':''?><?=number_format($cv,2)?>% على جميع الخدمات
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if(empty($pricingGroups)): ?>
        <div style="margin-top:5px;font-size:.72rem;color:#8895a7">
          <a href="pricing_groups.php" style="color:#6ba3ff">أنشئ مجموعة تسعير</a> أولاً لتفعيل هذه الميزة
        </div>
        <?php endif; ?>
      </div>

    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ التعديلات</button>
  </form>
</div>

<!-- ─── تبويب تعديل الرصيد ──────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-balance">
  <div style="background:var(--card2);border-radius:12px;padding:16px;margin-bottom:1.5rem;display:flex;align-items:center;gap:14px">
    <div style="font-size:2rem">💰</div>
    <div>
      <div style="font-size:.8rem;color:#8895a7">الرصيد الحالي</div>
      <div style="font-size:1.8rem;font-weight:900;color:#00d4aa"><?=formatMoney($viewCustomer['balance'])?></div>
    </div>
  </div>
  <form method="POST">
        <?= adminCsrfField() ?>
    <input type="hidden" name="update_balance" value="1">
    <input type="hidden" name="user_id" value="<?=$viewCustomer['id']?>">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>نوع العملية</label>
        <select name="type" id="balTypeSelect" onchange="toggleSubType(this.value)">
          <option value="credit">➕ إضافة رصيد</option>
          <option value="debit">➖ خصم رصيد</option>
        </select>
      </div>
      <div class="form-group" id="subTypeGroup">
        <label>تصنيف التأمين</label>
        <select name="sub_type" id="balSubType">
          <option value="topup">💰 تأمين عادي</option>
          <option value="refund">↩️ استرداد</option>
          <option value="gift">🎁 هدية</option>
        </select>
      </div>
      <div class="form-group">
        <label>المبلغ ($)</label>
        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
      </div>
      <div class="form-group">
        <label>السبب / ملاحظة</label>
        <input type="text" name="description" id="balDesc" placeholder="اختياري — يُملأ تلقائياً">
      </div>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-wallet"></i> تأكيد العملية</button>
    <script>
    function toggleSubType(t) {
      document.getElementById('subTypeGroup').style.display = t==='credit' ? '' : 'none';
    }
    document.getElementById('balSubType').addEventListener('change', function(){
      const map = {topup:'تأمين من الإدارة', refund:'استرداد من الإدارة', gift:'هدية من الإدارة'};
      const d = document.getElementById('balDesc');
      if (!d.value || Object.values(map).includes(d.value)) d.value = map[this.value] || '';
    });
    </script>
  </form>
</div>

<!-- ─── تبويب API ──────────────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-api">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:10px">
    <div>
      <div style="font-size:1rem;font-weight:800">صلاحية API للعميل</div>
      <div style="font-size:.83rem;color:#8895a7;margin-top:3px">السماح للعميل بالوصول للخدمات عبر API</div>
    </div>
    <a href="?action=toggle_api&id=<?=$id?>" class="btn btn-sm <?=$viewCustomer['api_enabled']?'btn-danger':'btn-success'?>"
       onclick="return confirm('<?=$viewCustomer['api_enabled']?'إيقاف':'تفعيل'?> صلاحية API؟')">
      <i class="fas fa-<?=$viewCustomer['api_enabled']?'times':'check'?>"></i>
      <?=$viewCustomer['api_enabled']?'إيقاف API':'تفعيل API'?>
    </a>
  </div>

  <?php if($viewCustomer['api_enabled'] && $viewCustomer['api_key']): ?>
  <div style="background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);border-radius:12px;padding:1.25rem;margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <span style="color:#00d4aa;font-weight:800"><i class="fas fa-check-circle"></i> API مفعّل</span>
      <a href="?action=regen_api&id=<?=$id?>" class="btn btn-sm btn-warning" onclick="return confirm('تجديد المفاتيح؟ المفاتيح القديمة ستتوقف عن العمل')">
        <i class="fas fa-sync-alt"></i> تجديد المفاتيح
      </a>
    </div>
    <div class="form-group">
      <label style="font-size:.8rem;color:#8895a7">API Key</label>
      <div class="api-key-box">
        <span><?=htmlspecialchars($viewCustomer['api_key'])?></span>
        <button type="button" class="btn btn-xs btn-secondary" onclick="copyText(this,'<?=htmlspecialchars($viewCustomer['api_key'])?>','key')"><i class="fas fa-copy"></i></button>
      </div>
    </div>
    <div class="form-group" style="margin:0">
      <label style="font-size:.8rem;color:#8895a7">API Secret</label>
      <div class="api-key-box">
        <span id="secretDisplay">••••••••••••••••••••••••••••••••••••••••</span>
        <button type="button" class="btn btn-xs btn-secondary" onclick="toggleSecret('<?=htmlspecialchars($viewCustomer['api_secret'])?>')"><i class="fas fa-eye" id="secretEyeIcon"></i></button>
        <button type="button" class="btn btn-xs btn-secondary" onclick="copyText(this,'<?=htmlspecialchars($viewCustomer['api_secret'])?>','secret')"><i class="fas fa-copy"></i></button>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div style="background:var(--card2);border-radius:12px;padding:2rem;text-align:center;color:#8895a7">
    <i class="fas fa-code" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:1rem"></i>
    <div>API غير مفعّل لهذا العميل</div>
    <div style="font-size:.82rem;margin-top:.5rem">اضغط "تفعيل API" لمنح صلاحية الوصول</div>
  </div>
  <?php endif; ?>
</div>

<!-- ─── تبويب الطلبات ────────────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-orders">
  <?php if (empty($viewOrders)): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">لا توجد طلبات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>الخدمة</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
      <tbody>
        <?php foreach($viewOrders as $o):
          $stMap=['pending'=>['قيد الانتظار','warning'],'processing'=>['تنفيذ','info'],'completed'=>['مكتمل','success'],'cancelled'=>['ملغي','danger'],'failed'=>['فشل','danger']];
          $st=$stMap[$o['status']]??['—','secondary'];
        ?>
        <tr>
          <td><code>#<?=$o['id']?></code></td>
          <td><?=htmlspecialchars($o['svc_name'])?></td>
          <td style="color:#00d4aa;font-weight:800"><?=formatMoney($o['total_price'])?></td>
          <td><span class="badge badge-<?=$st[1]?>"><?=$st[0]?></span></td>
          <td style="color:#8895a7;font-size:.82rem"><?=date('d/m H:i',strtotime($o['created_at']))?></td>
          <td><a href="orders.php?id=<?=$o['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div style="margin-top:1rem">
    <a href="orders.php?user=<?=$id?>" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> عرض كل الطلبات</a>
  </div>
</div>

<!-- ─── تبويب الإحالة ───────────────────────────────────────────────────── -->
<div class="cust-tab-pane" id="tab-referral">
<?php
// جلب بيانات المُحيل الحالي
$curReferrer = null;
$curReferral = null;
if (!empty($viewCustomer['referred_by'])) {
    $rStmt = $pdo->prepare("SELECT id,username,full_name,referral_code FROM users WHERE id=?");
    $rStmt->execute([$viewCustomer['referred_by']]);
    $curReferrer = $rStmt->fetch();
    $rRef = $pdo->prepare("SELECT * FROM referrals WHERE referrer_id=? AND referred_id=?");
    $rRef->execute([$viewCustomer['referred_by'], $viewCustomer['id']]);
    $curReferral = $rRef->fetch();
}
// جلب كود الإحالة للعميل الحالي
$myCode = $viewCustomer['referral_code'] ?? '';
// عدد من أحال هذا العميل
$myReferredCount = (int)$pdo->prepare("SELECT COUNT(*) FROM referrals WHERE referrer_id=?")->execute([$viewCustomer['id']]) ? $pdo->query("SELECT COUNT(*) FROM referrals WHERE referrer_id={$viewCustomer['id']}")->fetchColumn() : 0;
?>

<div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- المُحيل الحالي -->
  <div>
    <div style="font-weight:800;font-size:.88rem;margin-bottom:10px;color:var(--text2)">
      <i class="fas fa-user-plus" style="color:var(--cyan)"></i> المُحيل الحالي
    </div>
    <?php if($curReferrer): ?>
    <div style="background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.2);border-radius:12px;padding:12px">
      <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($curReferrer['full_name']?:$curReferrer['username'])?></div>
      <div style="font-size:.75rem;color:var(--text3);margin-top:3px">@<?=htmlspecialchars($curReferrer['username'])?> · #<?=$curReferrer['id']?></div>
      <div style="margin-top:6px;display:flex;gap:8px;align-items:center">
        <span style="font-family:monospace;background:var(--bg);padding:3px 8px;border-radius:6px;font-size:.78rem;color:var(--cyan)"><?=htmlspecialchars($curReferrer['referral_code']??'')?></span>
        <a href="?action=view&id=<?=$curReferrer['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-eye"></i></a>
      </div>
      <?php if($curReferral): ?>
      <div style="margin-top:8px;font-size:.75rem;color:var(--text3);display:flex;gap:12px">
        <span>نسبة العمولة: <strong style="color:#f5a623"><?=number_format($curReferral['reward_percent'],1)?>%</strong></span>
        <span>إجمالي العمولات: <strong style="color:#00e676"><?=number_format($curReferral['commission_paid'],4)?>$</strong></span>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:12px;color:var(--text3);font-size:.85rem">
      <i class="fas fa-user-slash"></i> لا يوجد مُحيل
    </div>
    <?php endif; ?>

    <?php if($myCode): ?>
    <div style="margin-top:10px;font-size:.78rem;color:var(--text3)">
      كود إحالته الخاص: <span style="font-family:monospace;color:var(--primary);font-weight:700"><?=htmlspecialchars($myCode)?></span>
      · أحال <strong><?=$myReferredCount?></strong> شخص
    </div>
    <?php endif; ?>
  </div>

  <!-- تغيير المُحيل -->
  <div>
    <div style="font-weight:800;font-size:.88rem;margin-bottom:10px;color:var(--text2)">
      <i class="fas fa-exchange-alt" style="color:#f5a623"></i> تغيير المُحيل
    </div>
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="user_id" value="<?=$viewCustomer['id']?>">
        <input type="hidden" name="change_referrer" value="1">

        <div class="form-group" style="margin-bottom:10px">
          <label style="font-size:.8rem;color:var(--text2);display:block;margin-bottom:6px">كود إحالة المُحيل الجديد</label>
          <input type="text" name="new_referrer_code" class="form-control"
                 placeholder="مثال: AB12CD34"
                 style="font-family:monospace;text-transform:uppercase;letter-spacing:2px;font-size:.9rem"
                 oninput="this.value=this.value.toUpperCase();lookupReferrer(this.value)">
          <div id="referrerLookup" style="margin-top:6px;font-size:.78rem;min-height:18px"></div>
        </div>

        <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:8px;padding:8px 10px;margin-bottom:12px;font-size:.75rem;color:#f5a623">
          <i class="fas fa-info-circle"></i>
          العمولة تُحسب من الطلبات المستقبلية فقط — الطلبات السابقة لا تتغير
        </div>

        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary btn-sm" style="flex:1"
                  onclick="return confirm('تأكيد تغيير المُحيل؟')">
            <i class="fas fa-save"></i> تطبيق التغيير
          </button>
          <?php if($curReferrer): ?>
          <button type="submit" class="btn btn-danger btn-sm"
                  onclick="document.querySelector('[name=new_referrer_code]').value='';return confirm('إزالة المُحيل نهائياً؟')">
            <i class="fas fa-unlink"></i> إزالة المُحيل
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

</div>
</div><!-- /tab-referral -->

</div><!-- end card -->

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.cust-tab-pane').forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.cust-tab').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+name).classList.add('active');
    btn.classList.add('active');
}

async function lookupReferrer(code) {
  const el = document.getElementById('referrerLookup');
  if (!el) return;
  if (code.length < 6) { el.innerHTML = ''; return; }
  el.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--text3)"></i>';
  try {
    const fd = new FormData();
    fd.append('code', code);
    const r = await fetch('<?= SITE_URL ?>/api/lookup_referrer.php', {method:'POST', body:fd, credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) {
      el.innerHTML = '<span style="color:#00e676"><i class="fas fa-check-circle"></i> ' + d.name + ' (#' + d.id + ')</span>';
    } else {
      el.innerHTML = code.length >= 8 ? '<span style="color:#ff4455"><i class="fas fa-times-circle"></i> كود غير موجود</span>' : '';
    }
  } catch(e) { el.innerHTML = ''; }
}
let secretVisible = false;
function toggleSecret(val) {
    secretVisible = !secretVisible;
    document.getElementById('secretDisplay').textContent = secretVisible ? val : '••••••••••••••••••••••••••••••••••••••••';
    document.getElementById('secretEyeIcon').className = secretVisible ? 'fas fa-eye-slash' : 'fas fa-eye';
}
function copyText(btn, text, type) {
    navigator.clipboard.writeText(text).then(()=>{
        const old = btn.innerHTML;
        btn.innerHTML='<i class="fas fa-check" style="color:#00d4aa"></i>';
        setTimeout(()=>btn.innerHTML=old, 1500);
    });
}
</script>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
  قائمة العملاء الرئيسية
════════════════════════════════════════════════════════════════════════════ -->

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(16,185,129,0.15)"><i class="fas fa-users" style="color:var(--green)"></i></div>
      إدارة العملاء
    </div>
    <div class="page-header-sub"><?=count($customers)?> عميل مسجل</div>
  </div>
</div>

<!-- فلتر نوع التسجيل -->
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
  <a href="?filter=all" class="btn btn-sm <?=$custFilter==='all'?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-users"></i> الكل <span style="opacity:.7">(<?=count($customers)?>)</span>
  </a>
  <a href="?filter=google" class="btn btn-sm <?=$custFilter==='google'?'btn-primary':'btn-secondary'?>" style="<?=$custFilter==='google'?'':'border-color:rgba(66,133,244,.3)'?>">
    <svg width="14" height="14" viewBox="0 0 48 48" style="margin-left:4px"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
    سجّلوا عبر Google <span style="opacity:.7">(<?=$googleCount?>)</span>
  </a>
  <a href="?filter=normal" class="btn btn-sm <?=$custFilter==='normal'?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-user"></i> تسجيل عادي
  </a>
</div>

<div class="card">
  <div class="filter-bar">
    <div class="filter-search" style="flex:1;min-width:220px">
      <i class="fas fa-search filter-search-icon"></i>
      <input type="search" id="custSearch" name="q" form="customerAdvancedFilters" placeholder="بحث سريع باسم أو بريد أو هاتف أو ID..." oninput="filterCustomers()" value="<?=htmlspecialchars($filterQ)?>">
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="customerAdvancedToggle" aria-expanded="true" onclick="toggleCustomerFilters()">
      <i class="fas fa-sliders-h"></i> خيارات بحث متقدمة
    </button>
    <div style="font-size:12px;color:var(--text3)"><?=count($customers)?> عميل</div>
  </div>

  <form method="get" id="customerAdvancedFilters" class="customer-filter-panel" style="margin-top:12px;padding:14px;background:var(--card2);border:1px solid var(--border);border-radius:12px">
    <input type="hidden" name="filter" value="<?=htmlspecialchars($custFilter)?>">
    <div class="customer-filter-grid">
      <div class="form-group">
        <label for="filter_user_id">عميل محدد</label>
        <select id="filter_user_id" name="user_id" class="form-control">
          <option value="">كل العملاء</option>
          <?php foreach($customerOptions as $co): ?>
          <option value="<?=$co['id']?>" <?=$filterUserId==(int)$co['id']?'selected':''?>>#<?=$co['id']?> — <?=htmlspecialchars($co['username'])?><?=!empty($co['full_name'])?' — '.htmlspecialchars($co['full_name']):''?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_group_id">مجموعة التسعير</label>
        <select id="filter_group_id" name="group_id" class="form-control">
          <option value="">كل المجموعات</option>
          <?php foreach($groupOptions as $go): ?>
          <option value="<?=$go['id']?>" <?=$filterGroupId==(int)$go['id']?'selected':''?>><?=htmlspecialchars($go['name'])?><?=isset($go['default_value'])?' — '.htmlspecialchars($go['default_value']):''?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_account_status">حالة الحساب</label>
        <select id="filter_account_status" name="account_status" class="form-control">
          <option value="">كل الحالات</option>
          <option value="active" <?=$filterStatus==='active'?'selected':''?>>نشط</option>
          <option value="blocked" <?=$filterStatus==='blocked'?'selected':''?>>موقوف / محظور</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_source">مصدر التسجيل</label>
        <select id="filter_source" name="registration_source_filter" class="form-control">
          <option value="">كل المصادر</option>
          <?php foreach($registrationSourceLabels as $sourceKey=>$sourceLabel): ?>
          <option value="<?=htmlspecialchars($sourceKey)?>" <?=$filterSource===$sourceKey?'selected':''?>><?=htmlspecialchars($sourceLabel)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_kyc">حالة التحقق</label>
        <select id="filter_kyc" name="kyc_filter" class="form-control">
          <option value="">كل الحالات</option>
          <option value="approved" <?=$filterKyc==='approved'?'selected':''?>>موثّق</option>
          <option value="pending" <?=$filterKyc==='pending'?'selected':''?>>معلق</option>
          <option value="rejected" <?=$filterKyc==='rejected'?'selected':''?>>مرفوض</option>
          <option value="none" <?=$filterKyc==='none'?'selected':''?>>بدون طلب تحقق</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_referrer">الإحالة</label>
        <select id="filter_referrer" name="referrer_filter" class="form-control">
          <option value="">الكل</option>
          <option value="with" <?=$filterReferrer==='with'?'selected':''?>>لديه مُحيل</option>
          <option value="without" <?=$filterReferrer==='without'?'selected':''?>>بدون مُحيل</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_orders">الطلبات</label>
        <select id="filter_orders" name="orders_filter" class="form-control">
          <option value="">الكل</option>
          <option value="with" <?=$filterOrders==='with'?'selected':''?>>لديه طلبات</option>
          <option value="without" <?=$filterOrders==='without'?'selected':''?>>بدون طلبات</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_api">حالة API</label>
        <select id="filter_api" name="api_filter" class="form-control">
          <option value="">الكل</option>
          <option value="enabled" <?=$filterApi==='enabled'?'selected':''?>>مفعّل</option>
          <option value="disabled" <?=$filterApi==='disabled'?'selected':''?>>غير مفعّل</option>
        </select>
      </div>
      <div class="form-group">
        <label for="filter_device">الأجهزة</label>
        <select id="filter_device" name="device_filter" class="form-control">
          <option value="">الكل</option>
          <option value="approved" <?=$filterDevice==='approved'?'selected':''?>>جهاز مصرح</option>
          <option value="pending" <?=$filterDevice==='pending'?'selected':''?>>جهاز معلق</option>
          <option value="blocked" <?=$filterDevice==='blocked'?'selected':''?>>جهاز محظور</option>
          <option value="none" <?=$filterDevice==='none'?'selected':''?>>بدون أجهزة</option>
        </select>
      </div>
      <div class="form-group"><label for="filter_min_balance">أقل رصيد</label><input id="filter_min_balance" name="min_balance" type="number" step="0.0001" class="form-control" value="<?=htmlspecialchars((string)($_GET['min_balance']??''))?>" placeholder="مثال: 0"></div>
      <div class="form-group"><label for="filter_max_balance">أعلى رصيد</label><input id="filter_max_balance" name="max_balance" type="number" step="0.0001" class="form-control" value="<?=htmlspecialchars((string)($_GET['max_balance']??''))?>" placeholder="مثال: 100"></div>
      <div class="form-group"><label for="filter_min_orders">أقل عدد طلبات</label><input id="filter_min_orders" name="min_orders" type="number" min="0" step="1" class="form-control" value="<?=htmlspecialchars((string)($_GET['min_orders']??''))?>"></div>
      <div class="form-group"><label for="filter_max_orders">أعلى عدد طلبات</label><input id="filter_max_orders" name="max_orders" type="number" min="0" step="1" class="form-control" value="<?=htmlspecialchars((string)($_GET['max_orders']??''))?>"></div>
      <div class="form-group"><label for="filter_created_from">التسجيل من</label><input id="filter_created_from" name="created_from" type="date" class="form-control" value="<?=htmlspecialchars($filterFrom)?>"></div>
      <div class="form-group"><label for="filter_created_to">التسجيل إلى</label><input id="filter_created_to" name="created_to" type="date" class="form-control" value="<?=htmlspecialchars($filterTo)?>"></div>
      <div class="form-group"><label for="filter_sort">الترتيب</label><select id="filter_sort" name="sort" class="form-control"><option value="newest" <?=$filterSort==='DESC'?'selected':''?>>الأحدث أولاً</option><option value="oldest" <?=$filterSort==='ASC'?'selected':''?>>الأقدم أولاً</option></select></div>
    </div>
    <div class="customer-filter-actions">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> تطبيق الفلاتر</button>
      <a href="customers.php?filter=<?=urlencode($custFilter)?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> مسح الفلاتر</a>
      <?php if($customerFilterCount): ?><span style="font-size:.78rem;color:var(--cyan)"><?=$customerFilterCount?> فلتر نشط</span><?php endif; ?>
    </div>
  </form>
  <div class="table-wrap">
    <table id="custTable">
      <thead>
        <tr><th>#</th><th>العميل</th><th>البريد</th><th>مصدر الحساب</th><th>الرصيد</th><th>الطلبات</th><th>دعاه</th><th>التحقق</th><th>الحالة</th><th>التسجيل</th><th>إجراءات</th></tr>
      </thead>
      <tbody>
      <?php if($customers): foreach($customers as $c): ?>
      <tr data-search="<?=strtolower(htmlspecialchars($c['username'].' '.($c['email']??'').' '.($c['full_name']??'')))?>">
        <td class="td-id"><?=str_pad($c['id'],6,'0',STR_PAD_LEFT)?></td>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <?php if(!empty($c['google_avatar'])): ?>
            <img src="<?=htmlspecialchars($c['google_avatar'])?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(66,133,244,.3)">
            <?php else: ?>
            <div style="width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:900;flex-shrink:0">
              <?=mb_strtoupper(mb_substr($c['username'],0,1))?>
            </div>
            <?php endif; ?>
            <div>
              <div class="td-bold">
                <?= htmlspecialchars(preg_replace('/__deleted__\d+$/','',$c['username'])) ?>
                <?php if(!empty($c['is_deleted'])): ?><span style="font-size:10px;color:#ff4455;font-weight:700"> [محذوف]</span><?php endif; ?>
                <?php if(!empty($c['google_id'])): ?>
                <svg width="12" height="12" viewBox="0 0 48 48" style="margin-right:4px;vertical-align:middle" title="سجّل عبر Google"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                <?php endif; ?>
              </div>
              <?php if($c['full_name']): ?><div class="td-muted" style="font-size:11px"><?=htmlspecialchars($c['full_name'])?></div><?php endif; ?>
              <?php if(!empty($c['group_name'])): ?>
              <?php $gv=(float)($c['group_default_value']??0); ?>
              <div style="margin-top:2px">
                <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(245,166,35,.12);color:#f5a623;border:1px solid rgba(245,166,35,.25);padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700">
                  <i class="fas fa-tag" style="font-size:8px"></i>
                  <?=htmlspecialchars($c['group_name'])?>
                  <span style="opacity:.7;font-weight:400"><?=$gv>=0?'+':''?><?=number_format($gv,1)?>%</span>
                </span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td class="td-muted text-sm"><?=htmlspecialchars($c['email']??'—')?></td>
        <?php
          $sourceKey = strtolower(trim((string)($c['registration_source_display'] ?? 'website')));
          if ($sourceKey === '') $sourceKey = 'website';
          $sourceLabel = $registrationSourceLabels[$sourceKey] ?? ('مصدر: ' . $sourceKey);
          $sourceColor = $registrationSourceColors[$sourceKey] ?? '#8895a7';
        ?>
        <td>
          <span style="display:inline-flex;align-items:center;gap:5px;background:<?=htmlspecialchars($sourceColor)?>1c;color:<?=htmlspecialchars($sourceColor)?>;border:1px solid <?=htmlspecialchars($sourceColor)?>55;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap" title="مصدر إنشاء الحساب: <?=htmlspecialchars($sourceLabel)?>">
            <?php if($sourceKey==='telegram'): ?><i class="fab fa-telegram-plane"></i><?php elseif($sourceKey==='google'): ?><i class="fab fa-google"></i><?php elseif(in_array($sourceKey,['website','web'],true)): ?><i class="fas fa-globe"></i><?php elseif($sourceKey==='admin'): ?><i class="fas fa-user-shield"></i><?php else: ?><i class="fas fa-plug"></i><?php endif; ?>
            <?=htmlspecialchars($sourceLabel)?>
          </span>
        </td>
        <td><span style="font-weight:900;color:<?=$c['balance']>0?'var(--green)':'var(--text2)'?>"><?=formatMoney($c['balance'])?></span></td>
        <td><span class="badge badge-primary"><?=$c['orders_count']?></span></td>
        <td>
          <?php if(!empty($c['referrer_username'])): ?>
          <a href="?action=view&id=<?=$c['referred_by']?>" style="color:<?=!empty($c['referrer_deleted'])?'#ff4455':'var(--cyan)'?>;font-size:12px;font-weight:700;text-decoration:none">
            <i class="fas fa-user-plus" style="font-size:10px"></i>
            <?=htmlspecialchars(preg_replace('/__deleted__\d+$/','',$c['referrer_username']))?>
            <?php if(!empty($c['referrer_deleted'])): ?><small>[محذوف]</small><?php endif; ?>
          </a>
          <?php else: ?>
          <span style="color:var(--text3);font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if(($c['kyc_status']??'') === 'approved'): ?>
          <span style="background:rgba(0,200,83,.15);color:#00e676;border:1px solid rgba(0,200,83,.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:800;display:inline-flex;align-items:center;gap:3px">
            <i class="fas fa-shield-check" style="font-size:9px"></i> موثّق
          </span>
          <?php elseif(($c['kyc_status']??'') === 'pending'): ?>
          <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">⏳ معلق</span>
          <?php elseif(($c['kyc_status']??'') === 'rejected'): ?>
          <span style="background:rgba(255,71,87,.1);color:#ff4757;border:1px solid rgba(255,71,87,.25);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">✗ مرفوض</span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['api_enabled']): ?>
          <span style="background:rgba(0,212,170,0.15);color:#00d4aa;border:1px solid rgba(0,212,170,0.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">✓ API</span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($c['pending_devices'] > 0): ?>
          <span style="background:rgba(245,166,35,0.2);color:#f5a623;border:1px solid rgba(245,166,35,0.3);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700" class="pending-pulse">
            ⏳ <?=$c['pending_devices']?> انتظار
          </span>
          <?php else: ?>
          <span style="color:#8895a7;font-size:11px">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?=$c['status']?'badge-success':'badge-danger'?>"><?=$c['status']?'✓ نشط':'✗ موقوف'?></span>
        </td>
        <td class="td-muted" style="font-size:11px"><?=date('d/m/Y',strtotime($c['created_at']))?></td>
        <td style="display:flex;gap:5px">
          <a href="?action=view&id=<?=$c['id']?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i> إدارة</a>
          <?php if(isAdmin()): ?>
          <a href="?action=delete&id=<?=$c['id']?>" class="btn btn-sm btn-danger"
             onclick="return confirm('حذف العميل <?=htmlspecialchars(addslashes($c['username']))?>؟ سيتم حذف جميع بياناته نهائياً!')">
            <i class="fas fa-trash"></i>
          </a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="9"><div class="empty-state"><span class="empty-state-icon">👥</span><div class="empty-state-title">لا يوجد عملاء بعد</div></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">إجمالي الأرصدة: <?=formatMoney(array_sum(array_column($customers,'balance')))?></div>
</div>

<script>
function toggleCustomerFilters() {
  const panel = document.getElementById('customerAdvancedFilters');
  const button = document.getElementById('customerAdvancedToggle');
  if (!panel || !button) return;
  const isHidden = panel.style.display === 'none';
  panel.style.display = isHidden ? '' : 'none';
  button.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
  button.innerHTML = isHidden
    ? '<i class="fas fa-sliders-h"></i> إخفاء خيارات البحث'
    : '<i class="fas fa-sliders-h"></i> خيارات بحث متقدمة';
}
function filterCustomers() {
  const input = document.getElementById('custSearch');
  const q = (input ? input.value : '').toLowerCase().trim();
  document.querySelectorAll('#custTable tbody tr[data-search]').forEach(r=>{
    r.style.display = r.dataset.search.includes(q) ? '' : 'none';
  });
}
</script>

<?php endif; ?>

<?php include 'footer.php'; ?>
