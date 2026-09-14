<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';

// تهيئة طبقة الحسابات دون التأثير على الصلاحيات أو السجلات الحالية.
accountingEnsureSchema($pdo);
requireStaffOrAdmin($pdo, 'perm_staff');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة الموظفين - ' . SITE_NAME;

// تثبيت الإجراء والمعرّف أيضاً من POST؛ بعض المتصفحات/الواجهات قد تسقط query string عند إرسال النموذج.
$action = $_GET['action'] ?? ($_POST['staff_action'] ?? 'list');
$id     = (int)($_GET['id'] ?? ($_POST['staff_id'] ?? 0));
$allPerms = getAllPermissions();

// ─── تغيير كلمة مرور الأدمن (الأدمن فقط لنفسه) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_admin_pw'])) {
    if (!isAdmin()) { flashMessage('danger','غير مصرح'); redirect(SITE_URL.'/admin/staff.php'); }
    $adminId  = (int)$_SESSION['user_id'];
    $oldPw    = $_POST['old_password'] ?? '';
    $newPw    = $_POST['new_password'] ?? '';
    $newPw2   = $_POST['new_password2'] ?? '';
    $adminRow = $pdo->prepare("SELECT password FROM users WHERE id=? AND role='admin'");
    $adminRow->execute([$adminId]); $adminRow = $adminRow->fetch();
    if (!$adminRow || !password_verify($oldPw, $adminRow['password'])) {
        flashMessage('danger','كلمة المرور الحالية غير صحيحة');
        redirect(SITE_URL.'/admin/staff.php?action=admin_profile');
    }
    if (strlen($newPw) < 6) { flashMessage('danger','كلمة المرور الجديدة 6 أحرف على الأقل'); redirect(SITE_URL.'/admin/staff.php?action=admin_profile'); }
    if ($newPw !== $newPw2) { flashMessage('danger','كلمتا المرور غير متطابقتين'); redirect(SITE_URL.'/admin/staff.php?action=admin_profile'); }
    $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPw,PASSWORD_DEFAULT),$adminId]);
    logStaffAction($pdo,'change_admin_password','user',$adminId,'تغيير كلمة مرور المدير');
    flashMessage('success','تم تغيير كلمة المرور بنجاح');
    redirect(SITE_URL.'/admin/staff.php?action=admin_profile');
}

// ─── تصريح / حظر / حذف جهاز المدير ──────────────────────────────────────────
if ($action === 'approve_admin_device' && isAdmin()) {
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $adminId = (int)$_SESSION['user_id'];
        $pdo->prepare("UPDATE user_devices SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND user_id=?")
            ->execute([$adminId, $did, $adminId]);
        logStaffAction($pdo,'approve_device','user',$adminId,'تصريح جهاز للمدير #'.$did);
        flashMessage('success','تم تصريح الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=admin_profile');
}
if ($action === 'block_admin_device' && isAdmin()) {
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $adminId = (int)$_SESSION['user_id'];
        $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$did,$adminId]);
        logStaffAction($pdo,'block_device','user',$adminId,'حظر جهاز للمدير #'.$did);
        flashMessage('success','تم حظر الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=admin_profile');
}
if ($action === 'delete_admin_device' && isAdmin()) {
    $did = (int)($_GET['did'] ?? 0);
    if ($did) {
        $adminId = (int)$_SESSION['user_id'];
        $pdo->prepare("DELETE FROM user_devices WHERE id=? AND user_id=?")->execute([$did,$adminId]);
        flashMessage('success','تم حذف الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=admin_profile');
}

// ─── تصريح جهاز موظف (موظف يصرح لنفسه أو أدمن يصرح لأي موظف) ────────────
if ($action === 'approve_device' && $id) {
    $did = (int)($_GET['did'] ?? 0);
    $canApprove = isAdmin() || (int)$_SESSION['user_id'] === $id;
    if ($canApprove && $did) {
        $pdo->prepare("UPDATE user_devices SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND user_id=?")
            ->execute([$_SESSION['user_id'], $did, $id]);
        logStaffAction($pdo,'approve_device','user',$id,'تصريح جهاز للموظف #'.$id);
        flashMessage('success','تم تصريح الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=view&id='.$id);
}
if ($action === 'block_device' && $id) {
    $did = (int)($_GET['did'] ?? 0);
    if (isAdmin() && $did) {
        $pdo->prepare("UPDATE user_devices SET status='blocked' WHERE id=? AND user_id=?")->execute([$did,$id]);
        flashMessage('success','تم حظر الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=view&id='.$id);
}
if ($action === 'delete_device' && $id) {
    $did = (int)($_GET['did'] ?? 0);
    if (isAdmin() && $did) {
        $pdo->prepare("DELETE FROM user_devices WHERE id=? AND user_id=?")->execute([$did,$id]);
        flashMessage('success','تم حذف الجهاز');
    }
    redirect(SITE_URL.'/admin/staff.php?action=view&id='.$id);
}

// ─── حذف موظف ──────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    if (!isAdmin()) {
        flashMessage('danger','غير مصرح بحذف الموظف');
        redirect(SITE_URL.'/admin/staff.php');
    }

    try {
        $pdo->beginTransaction();
        $staffStmt = $pdo->prepare("SELECT id, username, role, COALESCE(is_deleted,0) AS is_deleted FROM users WHERE id=? LIMIT 1");
        $staffStmt->execute([$id]);
        $staffRow = $staffStmt->fetch(PDO::FETCH_ASSOC);

        if (!$staffRow || $staffRow['role'] !== 'staff' || (int)$staffRow['is_deleted'] !== 0) {
            throw new RuntimeException('لا يمكن حذف هذا الحساب من شاشة الموظفين');
        }

        // لا يُحذف الموظف إذا كان له أي أثر تشغيلي أو مالي مرتبط بالحساب.
        // نتحقق من جداول قديمة/جديدة بشكل مستقل حتى تعمل النسخة مع ترقيات مختلفة.
        $dependencies = [];
        $dependencyChecks = [
            ['orders', 'user_id', 'طلبات الموقع'],
            ['telecom_orders', 'user_id', 'طلبات الاتصالات'],
            ['p2p_orders', 'user_id', 'طلبات P2P'],
            ['wallet_transactions', 'user_id', 'حركات المحفظة'],
            ['accounting_journal', 'staff_id', 'قيود محاسبية'],
        ];
        foreach ($dependencyChecks as [$table, $column, $label]) {
            try {
                $q = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
                $q->execute([$id]);
                $count = (int)$q->fetchColumn();
                if ($count > 0) $dependencies[] = $label.' ('.$count.')';
            } catch (Throwable $e) {
                // الجدول قد لا يكون موجوداً في نسخة قديمة؛ لا نوقف الحذف لأجله.
                error_log('Staff delete dependency check skipped for '.$table.': '.$e->getMessage());
            }
        }

        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM staff_activity_log WHERE user_id=? AND (target_type LIKE '%order%' OR target_type IN ('topup','payment','journal'))");
            $q->execute([$id]);
            $count = (int)$q->fetchColumn();
            if ($count > 0) $dependencies[] = 'عمليات مسجلة في سجل النشاط ('.$count.')';
        } catch (Throwable $e) {
            error_log('Staff activity dependency check skipped: '.$e->getMessage());
        }

        if ($dependencies) {
            $pdo->rollBack();
            flashMessage('danger','لا يمكن حذف الموظف لوجود ارتباطات محفوظة: '.implode('، ', $dependencies).'. عطّل الحساب بدلاً من حذفه للحفاظ على السجل.');
            redirect(SITE_URL.'/admin/staff.php');
        }

        // هذه روابط إعدادية وليست عمليات؛ تُفك قبل حذف الموظف حتى لا تبقى
        // ربطيات مزود تشير إلى مستخدم محذوف، مع إبقاء الحسابات والربطيات نفسها.
        try { $pdo->prepare("UPDATE accounting_provider_links SET staff_id=NULL WHERE staff_id=?")->execute([$id]); } catch (Throwable $e) { error_log('Staff linkage cleanup skipped: '.$e->getMessage()); }
        try { $pdo->prepare("UPDATE accounting_accounts SET staff_id=NULL WHERE staff_id=?")->execute([$id]); } catch (Throwable $e) { error_log('Staff account cleanup skipped: '.$e->getMessage()); }

        $deleteStmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='staff' AND COALESCE(is_deleted,0)=0");
        $deleteStmt->execute([$id]);
        if ($deleteStmt->rowCount() !== 1) throw new RuntimeException('تعذر حذف الموظف');
        $pdo->commit();

        flashMessage('success','تم حذف الموظف نهائياً لأنه غير مرتبط بأي طلب أو عملية');
        logStaffAction($pdo,'delete_staff','user',$id,'حذف موظف نهائياً #'.$id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Staff delete error: '.$e->getMessage());
        flashMessage('danger','تعذر حذف الموظف؛ لم يتم تغيير بياناته.');
    }
    redirect(SITE_URL.'/admin/staff.php');
}

// ─── تبديل حالة الموظف ──────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE users SET status=IF(status=1,0,1) WHERE id=? AND role='staff'")->execute([$id]);
    redirect(SITE_URL.'/admin/staff.php');
}

// ─── حفظ موظف جديد أو تعديل ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_staff'])) {
    $isNew     = !$id;
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $fullName  = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $accountId = (int)($_POST['account_id'] ?? 0);
    $perms     = $_POST['perms'] ?? [];

    if ($isNew) {
        $upgradeUserId = (int)($_POST['upgrade_user_id'] ?? 0);

        if ($upgradeUserId) {
            // ترقية عميل موجود إلى موظف
            $pdo->prepare("UPDATE users SET role='staff' WHERE id=? AND role='customer'")->execute([$upgradeUserId]);
            if ($password) $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$upgradeUserId]);
            $uid = $upgradeUserId;
            logStaffAction($pdo,'promote_to_staff','user',$uid,'ترقية عميل إلى موظف');
        } else {
            // إنشاء حساب موظف جديد
            if (strlen($username)<3) { flashMessage('danger','اسم المستخدم يجب 3 أحرف على الأقل'); redirect(SITE_URL.'/admin/staff.php?action=add'); }
            if (strlen($password)<6) { flashMessage('danger','كلمة المرور يجب 6 أحرف على الأقل'); redirect(SITE_URL.'/admin/staff.php?action=add'); }
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { flashMessage('danger','البريد غير صحيح'); redirect(SITE_URL.'/admin/staff.php?action=add'); }
            $check = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
            $check->execute([$username,$email]);
            if ($check->fetch()) { flashMessage('danger','اسم المستخدم أو البريد مستخدم مسبقاً'); redirect(SITE_URL.'/admin/staff.php?action=add'); }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username,email,password,full_name,phone,role,status) VALUES (?,?,?,?,?,'staff',1)")
                ->execute([$username,$email,$hash,$fullName,$phone]);
            $uid = $pdo->lastInsertId();
            logStaffAction($pdo,'create_staff','user',$uid,'إنشاء موظف جديد: '.$username);
        }
    } else {
        // تعديل بيانات موظف
        $uid = $id;
        // الموظفون القدامى قد يملكون username فارغاً بسبب سجل تاريخي ناقص.
        // نسمح بإكماله مرة واحدة فقط، ولا نغيّر الاسم الصحيح الموثق.
        $currentUserStmt = $pdo->prepare("SELECT username FROM users WHERE id=? AND role IN ('staff','admin') LIMIT 1");
        $currentUserStmt->execute([$uid]);
        $currentUsername = trim((string)$currentUserStmt->fetchColumn());
        if ($currentUsername === '') {
            if (strlen($username) < 3) {
                flashMessage('danger','يجب إدخال اسم مستخدم صحيح لهذا الموظف القديم (3 أحرف على الأقل)');
                redirect(SITE_URL.'/admin/staff.php?action=edit&id='.$id);
            }
            $duplicate = $pdo->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
            $duplicate->execute([$username, $uid]);
            if ($duplicate->fetchColumn()) {
                flashMessage('danger','اسم المستخدم مستخدم مسبقاً');
                redirect(SITE_URL.'/admin/staff.php?action=edit&id='.$id);
            }
            $pdo->prepare("UPDATE users SET username=?,full_name=?,email=?,phone=? WHERE id=? AND role IN ('staff','admin')")
                ->execute([$username,$fullName,$email,$phone,$uid]);
        } else {
            $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=? WHERE id=? AND role IN ('staff','admin')")
                ->execute([$fullName,$email,$phone,$uid]);
        }
        if ($password) {
            if (strlen($password)<6) { flashMessage('danger','كلمة المرور يجب 6 أحرف'); redirect(SITE_URL.'/admin/staff.php?action=edit&id='.$id); }
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
        }
        logStaffAction($pdo,'edit_staff','user',$uid,'تعديل بيانات موظف #'.$uid);
    }

    // ربط الموظف بحساب مالي اختياري؛ لا يغيّر staff_permissions.
    $validAccount = null;
    if ($accountId > 0) {
        $accountCheck = $pdo->prepare("SELECT id FROM accounting_accounts WHERE id=? AND status=1 LIMIT 1");
        $accountCheck->execute([$accountId]);
        $validAccount = $accountCheck->fetchColumn() ?: null;
    }
    $pdo->prepare("UPDATE users SET account_id=? WHERE id=? AND role IN ('staff','admin')")
        ->execute([$validAccount, $uid]);
    if ($validAccount) {
        accountingLinkEntity($pdo, (int)$validAccount, 'staff', (int)$uid, null, null, (int)($_SESSION['user_id'] ?? 0));
    }

    // حفظ الصلاحيات
    $pdo->prepare("DELETE FROM staff_permissions WHERE user_id=?")->execute([$uid]);
    $cols = array_map(fn($k)=>'perm_'.$k, array_keys($allPerms));
    $vals = array_map(fn($k)=>in_array($k,$perms)?1:0, array_keys($allPerms));
    $colStr = implode(',', $cols);
    $phStr  = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO staff_permissions (user_id,$colStr) VALUES (?,$phStr)")
        ->execute(array_merge([$uid], $vals));

    flashMessage('success', $isNew ? 'تم إنشاء حساب الموظف بنجاح' : 'تم تحديث بيانات الموظف');
    redirect(SITE_URL.'/admin/staff.php');
}

// ─── جلب بيانات ─────────────────────────────────────────────────────────────
// مهم: لا نستخدم sp.* هنا؛ لأن staff_permissions يحتوي على id خاص به،
// واستخدامه بعد u.* يستبدل users.id داخل الصف، فيؤدي إلى فتح/حذف موظف خاطئ.
$permissionSelect = [];
foreach (array_keys($allPerms) as $permissionKey) {
    $permissionColumn = 'perm_' . $permissionKey;
    $permissionSelect[] = 'sp.`' . $permissionColumn . '` AS `' . $permissionColumn . '`';
}
$permissionSelectSql = implode(",\n            ", $permissionSelect);
$staffQ = trim((string)($_GET['q'] ?? ''));
$staffRole = in_array((string)($_GET['role_filter'] ?? ''), ['staff','admin'], true) ? (string)$_GET['role_filter'] : '';
$staffStatus = in_array((string)($_GET['status_filter'] ?? ''), ['active','inactive'], true) ? (string)$_GET['status_filter'] : '';
$staffAccount = in_array((string)($_GET['account_filter'] ?? ''), ['linked','unlinked'], true) ? (string)$_GET['account_filter'] : '';
$staffActivity = in_array((string)($_GET['activity_filter'] ?? ''), ['with','without'], true) ? (string)$_GET['activity_filter'] : '';
$staffWhere = ["u.role IN ('staff','admin')", 'COALESCE(u.is_deleted,0)=0'];
$staffParams = [];
if ($staffQ !== '') {
    $staffLike = '%'.$staffQ.'%';
    $staffWhere[] = '(CAST(u.id AS CHAR) LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ?)';
    array_push($staffParams, $staffLike, $staffLike, $staffLike, $staffLike, $staffLike);
}
if ($staffRole !== '') { $staffWhere[] = 'u.role=?'; $staffParams[] = $staffRole; }
if ($staffStatus === 'active') $staffWhere[] = 'u.status=1';
if ($staffStatus === 'inactive') $staffWhere[] = '(u.status IS NULL OR u.status=0)';
if ($staffAccount === 'linked') {
    $staffWhere[] = "EXISTS (SELECT 1 FROM accounting_accounts af
        WHERE af.status=1 AND (
            af.id=u.account_id
            OR (af.staff_id=u.id AND NOT EXISTS (
                SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id
            ))
            OR (EXISTS (
                SELECT 1 FROM accounting_account_links afl
                WHERE afl.account_id=af.id AND afl.entity_type='staff'
                  AND afl.entity_id=u.id AND afl.status=1
            ) AND NOT EXISTS (
                SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id
            ))
        ))";
}
if ($staffAccount === 'unlinked') {
    $staffWhere[] = "NOT EXISTS (SELECT 1 FROM accounting_accounts af
        WHERE af.status=1 AND (
            af.id=u.account_id
            OR (af.staff_id=u.id AND NOT EXISTS (
                SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id
            ))
            OR (EXISTS (
                SELECT 1 FROM accounting_account_links afl
                WHERE afl.account_id=af.id AND afl.entity_type='staff'
                  AND afl.entity_id=u.id AND afl.status=1
            ) AND NOT EXISTS (
                SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id
            ))
        ))";
}
if ($staffActivity === 'with') $staffWhere[] = 'EXISTS (SELECT 1 FROM staff_activity_log sf WHERE sf.user_id=u.id)';
if ($staffActivity === 'without') $staffWhere[] = 'NOT EXISTS (SELECT 1 FROM staff_activity_log sf WHERE sf.user_id=u.id)';
$staffWhereSql = implode(' AND ', $staffWhere);
try {
    $staffStmt = $pdo->prepare("\n        SELECT u.*,\n            sp.user_id AS permissions_user_id,\n            {$permissionSelectSql},\n            MAX(sal.created_at) AS last_action,\n            COALESCE(aa.code,
                (SELECT af.code FROM accounting_accounts af
                    WHERE af.staff_id=u.id AND af.status=1
                      AND NOT EXISTS (SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id)
                    ORDER BY af.id ASC LIMIT 1),
                (SELECT al.code FROM accounting_accounts al INNER JOIN accounting_account_links afl ON afl.account_id=al.id
                    WHERE afl.entity_type='staff' AND afl.entity_id=u.id AND afl.status=1 AND al.status=1
                      AND NOT EXISTS (SELECT 1 FROM users owner_u WHERE owner_u.account_id=al.id AND owner_u.id<>u.id)
                    ORDER BY al.id ASC LIMIT 1)
            ) AS accounting_code,
            COALESCE(aa.name,
                (SELECT af.name FROM accounting_accounts af
                    WHERE af.staff_id=u.id AND af.status=1
                      AND NOT EXISTS (SELECT 1 FROM users owner_u WHERE owner_u.account_id=af.id AND owner_u.id<>u.id)
                    ORDER BY af.id ASC LIMIT 1),
                (SELECT al.name FROM accounting_accounts al INNER JOIN accounting_account_links afl ON afl.account_id=al.id
                    WHERE afl.entity_type='staff' AND afl.entity_id=u.id AND afl.status=1 AND al.status=1
                      AND NOT EXISTS (SELECT 1 FROM users owner_u WHERE owner_u.account_id=al.id AND owner_u.id<>u.id)
                    ORDER BY al.id ASC LIMIT 1)
            ) AS accounting_name
        FROM users u\n        LEFT JOIN staff_permissions sp ON u.id=sp.user_id\n        LEFT JOIN staff_activity_log sal ON u.id=sal.user_id\n        LEFT JOIN accounting_accounts aa ON aa.id=u.account_id\n        WHERE {$staffWhereSql}\n        GROUP BY u.id ORDER BY u.role ASC, u.created_at DESC\n    ");
    $staffStmt->execute($staffParams);
    $staffList = $staffStmt->fetchAll();
} catch (\PDOException $e) {
    $staffStmt = $pdo->prepare("SELECT u.* FROM users u WHERE {$staffWhereSql} ORDER BY u.role ASC, u.created_at DESC");
    $staffStmt->execute($staffParams);
    $staffList = $staffStmt->fetchAll();
}
$staffFilterCount = count(array_filter([$staffQ,$staffRole,$staffStatus,$staffAccount,$staffActivity], static fn($v) => $v !== '' && $v !== null));
$staffQuery = $_GET; unset($staffQuery['page']);
$staffQueryString = http_build_query($staffQuery);

$editStaff = null; $editAccount = null; $editAccountId = 0; $editPerms = []; $activityLog = [];
$adminDevices = []; $adminProfile = null;

// ─── صفحة ملف المدير ────────────────────────────────────────────────────────
if ($action === 'admin_profile' && isAdmin()) {
    $adminId = (int)$_SESSION['user_id'];
    $ap = $pdo->prepare("SELECT * FROM users WHERE id=? AND role='admin'"); $ap->execute([$adminId]); $adminProfile = $ap->fetch();
    try {
        $ad = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? ORDER BY last_seen DESC"); $ad->execute([$adminId]); $adminDevices = $ad->fetchAll();
    } catch(\PDOException $e) { $adminDevices = []; }
}

if ($id && in_array($action,['edit','view'])) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND role IN ('staff','admin')"); $s->execute([$id]); $editStaff=$s->fetch();
    if (!$editStaff) { flashMessage('danger','الموظف غير موجود'); redirect(SITE_URL.'/admin/staff.php'); }
    // الحساب قد يكون محفوظاً بالطريقة الجديدة (users.account_id)، أو بالطريقة
    // القديمة (accounting_accounts.staff_id)، أو في جدول الروابط العام.
    try {
        $directAccountId = (int)($editStaff['account_id'] ?? 0);
        $as = $pdo->prepare("SELECT aa.id,aa.code,aa.name,aa.account_type,aa.nature,aa.region,aa.currency_code,aa.status
            FROM accounting_accounts aa
            WHERE (aa.id=? AND aa.status=1)
               OR (aa.staff_id=? AND aa.status=1 AND NOT EXISTS (
                    SELECT 1 FROM users owner_u WHERE owner_u.account_id=aa.id AND owner_u.id<>?
               ))
               OR (EXISTS (
                    SELECT 1 FROM accounting_account_links aal
                    WHERE aal.account_id=aa.id AND aal.entity_type='staff'
                      AND aal.entity_id=? AND aal.status=1
               ) AND NOT EXISTS (
                    SELECT 1 FROM users owner_u WHERE owner_u.account_id=aa.id AND owner_u.id<>?
               ))
            ORDER BY CASE WHEN aa.id=? THEN 0 WHEN aa.staff_id=? THEN 1 ELSE 2 END, aa.id ASC
            LIMIT 1");
        $as->execute([$directAccountId, $id, $id, $id, $id, $directAccountId, $id]);
        $editAccount = $as->fetch(PDO::FETCH_ASSOC) ?: null;
        $editAccountId = (int)($editAccount['id'] ?? 0);
        // يوحّد قيمة النموذج مع الربط المسترجع، بما في ذلك الموظفين القدامى.
        if ($editAccountId > 0) $editStaff['account_id'] = $editAccountId;
    } catch (Throwable $e) {
        $editAccount = null;
        $editAccountId = 0;
    }
    $ep = $pdo->prepare("SELECT * FROM staff_permissions WHERE user_id=?"); $ep->execute([$id]); $ep=$ep->fetch();
    if ($ep) foreach (array_keys($allPerms) as $pk) $editPerms[$pk] = $ep['perm_'.$pk] ?? 0;
    // سجل النشاط
    $log = $pdo->prepare("SELECT * FROM staff_activity_log WHERE user_id=? ORDER BY created_at DESC LIMIT 20"); $log->execute([$id]); $activityLog=$log->fetchAll();
    // أجهزة الموظف
    try {
        $dv = $pdo->prepare("SELECT * FROM user_devices WHERE user_id=? ORDER BY last_seen DESC"); $dv->execute([$id]); $staffDevices = $dv->fetchAll();
    } catch(\PDOException $e) { $staffDevices = []; }
}

// قراءة من للعملاء لاختيار ترقيتهم موظفاً
$customers = $pdo->query("SELECT id,username,email,full_name FROM users WHERE role='customer' AND status=1 ORDER BY username LIMIT 200")->fetchAll();

// الحسابات المالية المتاحة للاختيار في نموذج الموظف.
$accountOptions = [];
try {
    $accountOptions = $pdo->query("SELECT id,code,name,account_type FROM accounting_accounts WHERE status=1 ORDER BY code ASC, name ASC")->fetchAll();
} catch (Throwable $e) { $accountOptions = []; }

// مجموعات الصلاحيات
$groups = [];
foreach ($allPerms as $key => $meta) {
    $groups[$meta['group']][$key] = $meta;
}

include 'header.php';
?>

<style>
.perm-group{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem}
.perm-group-title{font-size:.8rem;font-weight:900;color:#8895a7;text-transform:uppercase;letter-spacing:1px;margin-bottom:.75rem;display:flex;align-items:center;gap:6px}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.5rem}
.perm-item{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;transition:.15s}
.perm-item:hover{background:rgba(255,255,255,.04)}
.perm-item input[type=checkbox]{width:18px;height:18px;accent-color:#6c3fe0;cursor:pointer;flex-shrink:0}
.perm-item label{font-size:.85rem;cursor:pointer;display:flex;align-items:center;gap:8px}
.perm-item label i{width:16px;text-align:center;color:var(--primary);opacity:.8}
.staff-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1.25rem;display:flex;align-items:center;gap:14px;margin-bottom:10px;transition:.2s}
.staff-card:hover{border-color:rgba(108,63,224,.4);background:var(--card2)}
.staff-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;flex-shrink:0;background:linear-gradient(135deg,var(--purple),#9b59b6)}
.perm-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(108,63,224,.15);border:1px solid rgba(108,63,224,.3);color:#a78bfa;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;margin:2px}
.log-item{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)}
.log-item:last-child{border:none}
.log-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:5px}
.select-all-btn{font-size:.78rem;color:var(--primary);cursor:pointer;font-weight:700;margin-right:auto}
.select-all-btn:hover{text-decoration:underline}
</style>

<?php if ($action==='add' || $action==='edit'): ?>
<!-- ═══ نموذج إضافة / تعديل موظف ════════════════════════════════════════════ -->
<div class="page-header">
  <div style="display:flex;align-items:center;gap:10px">
    <a href="staff.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a>
    <h2><?= $action==='edit' ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد' ?></h2>
  </div>
</div>

<form method="POST" action="staff.php?action=<?=urlencode($action)?><?=($id?'&id='.(int)$id:'')?>">
        <?= adminCsrfField() ?>
<input type="hidden" name="save_staff" value="1">
<input type="hidden" name="staff_action" value="<?=htmlspecialchars($action, ENT_QUOTES, 'UTF-8')?>">
<input type="hidden" name="staff_id" value="<?=($action==='edit'?(int)$id:0)?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">

<!-- ─── بيانات الحساب ──────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-user-tie"></i> بيانات الحساب</div></div>
  <div class="card-body">

  <?php if ($action==='add'): ?>
  <div class="form-group">
    <label>ترقية عميل موجود <small style="color:#8895a7">(اختياري)</small></label>
    <select id="quickPickCustomer" onchange="fillFromCustomer(this)">
      <option value="">— أو أنشئ حساباً جديداً —</option>
      <?php foreach($customers as $c): ?>
      <option value="<?=$c['id']?>" data-u="<?=htmlspecialchars($c['username'])?>" data-e="<?=htmlspecialchars($c['email']??'')?>" data-n="<?=htmlspecialchars($c['full_name']??'')?>">
        <?=htmlspecialchars($c['username'])?> — <?=htmlspecialchars($c['email']??'')?>
      </option>
      <?php endforeach; ?>
    </select>
    <small class="text-muted">سيتم تغيير role العميل إلى موظف</small>
  </div>
  <hr style="border-color:var(--border);margin:1rem 0">
  <?php endif; ?>

  <div class="form-group">
    <label>اسم المستخدم *</label>
    <?php $hasUsername = trim((string)($editStaff['username'] ?? '')) !== ''; ?>
    <input type="text" name="username" id="fUsername" value="<?=htmlspecialchars($editStaff['username']??'')?>" <?=($action==='edit' && $hasUsername)?'disabled style="opacity:.5"':''?> required>
    <?php if ($action==='edit' && !$hasUsername): ?>
    <small class="text-muted" style="color:#f5a623!important">هذا الموظف قديم ولا يملك اسم مستخدم محفوظاً؛ أدخل اسماً جديداً لإكمال الحساب.</small>
    <?php endif; ?>
  </div>
  <div class="form-group">
    <label>الاسم الكامل</label>
    <input type="text" name="full_name" id="fFullName" value="<?=htmlspecialchars($editStaff['full_name']??'')?>">
  </div>
  <div class="form-group">
    <label>البريد الإلكتروني *</label>
    <input type="email" name="email" id="fEmail" value="<?=htmlspecialchars($editStaff['email']??'')?>" required>
  </div>
  <div class="form-group">
    <label>رقم الهاتف</label>
    <input type="tel" name="phone" id="fPhone" value="<?=htmlspecialchars($editStaff['phone']??'')?>">
  </div>
  <div class="form-group">
    <label>الحساب المالي <small style="color:#8895a7">(اختياري للمطابقة المحاسبية)</small></label>
    <select name="account_id">
      <option value="">— بدون حساب مالي —</option>
      <?php foreach($accountOptions as $a): ?>
      <option value="<?=$a['id']?>" <?=((int)($editAccountId ?: ($editStaff['account_id']??0))===(int)$a['id'])?'selected':''?>>
        <?=htmlspecialchars($a['code'].' — '.$a['name'].' ('.$a['account_type'].')')?>
      </option>
      <?php endforeach; ?>
    </select>
    <small class="text-muted">هذا الربط مستقل عن صلاحيات الموظف، ولا ينشئ خزنة.</small>
  </div>
  <div class="form-group">
    <label>كلمة المرور <?= $action==='edit'?'<small style="color:#8895a7">(اتركها فارغة للإبقاء)</small>':'' ?></label>
    <input type="text" name="password" placeholder="<?= $action==='add'?'6 أحرف على الأقل...':'أدخل كلمة مرور جديدة...' ?>" <?= $action==='add'?'required':'' ?>>
  </div>
  </div>
</div>

<!-- ─── الصلاحيات ─────────────────────────────────────────────────────── -->
<div>
  <div class="card" style="margin-bottom:0">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-key"></i> الصلاحيات</div>
      <span class="select-all-btn" onclick="toggleAllPerms(true)">تحديد الكل</span>
      <span class="select-all-btn" onclick="toggleAllPerms(false)" style="color:#ff4455">إلغاء الكل</span>
    </div>
    <div class="card-body">
      <!-- أزرار الباقات السريعة -->
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1rem">
        <button type="button" class="btn btn-sm" style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:#00d4aa" onclick="applyPreset('view_only')">
          <i class="fas fa-eye"></i> مشاهدة فقط
        </button>
        <button type="button" class="btn btn-sm" style="background:rgba(30,111,255,.1);border:1px solid rgba(30,111,255,.3);color:#1e6fff" onclick="applyPreset('orders_staff')">
          <i class="fas fa-shopping-bag"></i> موظف طلبات
        </button>
        <button type="button" class="btn btn-sm" style="background:rgba(108,63,224,.1);border:1px solid rgba(108,63,224,.3);color:#a78bfa" onclick="applyPreset('supervisor')">
          <i class="fas fa-user-shield"></i> مشرف
        </button>
        <button type="button" class="btn btn-sm" style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);color:#f5a623" onclick="applyPreset('full_access')">
          <i class="fas fa-shield-alt"></i> وصول كامل
        </button>
      </div>

      <?php foreach ($groups as $groupName => $groupPerms): ?>
      <div class="perm-group">
        <div class="perm-group-title">
          <i class="fas fa-circle" style="font-size:6px;color:var(--primary)"></i>
          <?= $groupName ?>
        </div>
        <div class="perm-grid">
          <?php foreach ($groupPerms as $key => $meta): ?>
          <div class="perm-item">
            <input type="checkbox" name="perms[]" value="<?=$key?>" id="perm_<?=$key?>"
                   <?= (!empty($editPerms[$key]) || (empty($editPerms) && false)) ? 'checked' : '' ?>>
            <label for="perm_<?=$key?>">
              <i class="fas fa-<?=$meta['icon']?>"></i>
              <?=$meta['label']?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div style="margin-top:12px;display:flex;gap:8px">
    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ الموظف</button>
    <a href="staff.php" class="btn btn-secondary">إلغاء</a>
  </div>
</div>

</div><!-- grid -->
</form>

<script>
<?php if ($action==='add'): ?>
// ID للترقية السريعة
let upgradeUserId = 0;
function fillFromCustomer(sel) {
    const opt = sel.options[sel.selectedIndex];
    upgradeUserId = opt.value || 0;
    if (!upgradeUserId) return;
    document.getElementById('fUsername').value  = opt.dataset.u || '';
    document.getElementById('fEmail').value     = opt.dataset.e || '';
    document.getElementById('fFullName').value  = opt.dataset.n || '';
    document.querySelector('[name=password]').required = false;
    document.querySelector('[name=password]').placeholder = 'اتركها فارغة للإبقاء على نفس كلمة المرور';
    // إضافة hidden input للـ user_id المراد ترقيته
    let h = document.getElementById('upgradeUserId');
    if (!h) { h = document.createElement('input'); h.type='hidden'; h.id='upgradeUserId'; h.name='upgrade_user_id'; document.querySelector('form').appendChild(h); }
    h.value = upgradeUserId;
}
<?php endif; ?>

const PRESETS = {
    view_only:    ['dashboard','orders_view','customers_view','services_view','categories_view'],
    orders_staff: ['dashboard','orders_view','orders_process','orders_complete','orders_cancel','customers_view'],
    supervisor:   ['dashboard','orders_view','orders_process','orders_complete','orders_cancel','customers_view','customers_edit','customers_balance','customers_devices','services_view','categories_view'],
    full_access:  <?= json_encode(array_keys($allPerms)) ?>,
};

function applyPreset(name) {
    toggleAllPerms(false);
    (PRESETS[name] || []).forEach(k => {
        const el = document.getElementById('perm_' + k);
        if (el) el.checked = true;
    });
}

function toggleAllPerms(val) {
    document.querySelectorAll('[name="perms[]"]').forEach(cb => cb.checked = val);
}
</script>

<?php elseif ($action==='view' && $editStaff): ?>
<!-- ═══ صفحة تفاصيل الموظف ══════════════════════════════════════════════════ -->
<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="staff.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a>
    <div class="staff-avatar"><?=mb_strtoupper(mb_substr($editStaff['username'],0,1))?></div>
    <div>
      <h2 style="margin:0"><?=htmlspecialchars($editStaff['username'])?>
        <span class="badge badge-<?=$editStaff['status']?'success':'danger'?>" style="font-size:.6em;vertical-align:middle"><?=$editStaff['status']?'نشط':'موقوف'?></span>
      </h2>
      <div style="font-size:.82rem;color:#8895a7"><?=htmlspecialchars($editStaff['email']??'')?></div>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?action=edit&id=<?=$id?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> تعديل</a>
    <a href="?action=toggle&id=<?=$id?>" class="btn btn-sm <?=$editStaff['status']?'btn-danger':'btn-success'?>" onclick="return confirm('تغيير الحالة؟')">
      <i class="fas fa-<?=$editStaff['status']?'ban':'check'?>"></i> <?=$editStaff['status']?'إيقاف':'تفعيل'?>
    </a>
    <a href="?action=delete&id=<?=$id?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف الموظف نهائياً إذا لم توجد عمليات مرتبطة؟')"><i class="fas fa-trash"></i></a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <!-- الحساب المالي المرتبط -->
  <div class="card" style="grid-column:1/-1">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-calculator" style="color:#00d4aa"></i> الحساب المالي</div></div>
    <div class="card-body">
      <?php if ($editAccount): ?>
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="background:rgba(0,212,170,.12);color:#00d4aa;border:1px solid rgba(0,212,170,.3);padding:6px 12px;border-radius:8px;font-weight:800">
          <i class="fas fa-link"></i> <?=htmlspecialchars($editAccount['code'].' — '.$editAccount['name'])?>
        </span>
        <span style="color:#8895a7;font-size:.82rem">النوع: <?=htmlspecialchars($editAccount['account_type']??'—')?> · الطبيعة: <?=htmlspecialchars($editAccount['nature']??'—')?> · العملة: <?=htmlspecialchars($editAccount['currency_code']??'—')?></span>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:1rem;color:#f5a623"><i class="fas fa-unlink"></i> لا يوجد حساب مالي مرتبط بهذا الموظف</div>
      <?php endif; ?>
    </div>
  </div>
  <!-- أجهزة الموظف -->
  <?php if(!empty($staffDevices)): ?>
  <div class="card" style="grid-column:1/-1">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-mobile-alt" style="color:#00d4aa"></i> أجهزة الموظف</div></div>
    <div class="card-body" style="padding:0">
      <?php
      $devIcons  = ['desktop'=>'🖥️','mobile'=>'📱','tablet'=>'📟','unknown'=>'🖥️'];
      $devColors = ['approved'=>'#00d4aa','pending'=>'#f5a623','blocked'=>'#ff4455'];
      $devLabels = ['approved'=>'مصرح','pending'=>'انتظار','blocked'=>'محظور'];
      foreach($staffDevices as $dv): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border)">
        <div style="font-size:1.5rem"><?=$devIcons[$dv['device_type']??'unknown']?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.87rem"><?=htmlspecialchars($dv['device_name']??'جهاز مجهول')?></div>
          <div style="font-size:.74rem;color:#8895a7">IP: <?=$dv['ip_address']??'—'?> · <?=isset($dv['last_seen'])?date('d/m H:i',strtotime($dv['last_seen'])):'—'?></div>
        </div>
        <span style="background:<?=$devColors[$dv['status']]?>22;color:<?=$devColors[$dv['status']]?>;border:1px solid <?=$devColors[$dv['status']]?>44;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700">
          <?=$devLabels[$dv['status']]?>
        </span>
        <?php if($dv['status']==='pending' && isAdmin()): ?>
        <a href="?action=approve_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> تصريح</a>
        <?php endif; ?>
        <?php if($dv['status']==='pending' && !isAdmin() && (int)$_SESSION['user_id']===$id): ?>
        <a href="?action=approve_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> تصريح لنفسي</a>
        <?php endif; ?>
        <?php if(isAdmin() && $dv['status']!=='blocked'): ?>
        <a href="?action=block_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حظر الجهاز؟')"><i class="fas fa-ban"></i></a>
        <?php endif; ?>
        <?php if(isAdmin()): ?>
        <a href="?action=delete_device&id=<?=$id?>&did=<?=$dv['id']?>" class="btn btn-sm btn-secondary" onclick="return confirm('حذف الجهاز؟')"><i class="fas fa-trash"></i></a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <!-- صلاحياته الحالية -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-key"></i> الصلاحيات الممنوحة</div></div>
    <div class="card-body">
      <?php if (empty($editPerms) || !array_filter($editPerms)): ?>
      <div style="text-align:center;padding:2rem;color:#8895a7"><i class="fas fa-ban" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem"></i>لا توجد صلاحيات ممنوحة</div>
      <?php else: ?>
        <?php foreach ($groups as $groupName => $groupPerms): ?>
        <?php $hasAny = array_filter(array_map(fn($k)=>$editPerms[$k]??0, array_keys($groupPerms))); ?>
        <?php if ($hasAny): ?>
        <div style="margin-bottom:1rem">
          <div style="font-size:.75rem;color:#8895a7;font-weight:900;letter-spacing:1px;margin-bottom:.5rem"><?=$groupName?></div>
          <div style="display:flex;flex-wrap:wrap;gap:4px">
            <?php foreach ($groupPerms as $key => $meta): ?>
            <?php if (!empty($editPerms[$key])): ?>
            <span class="perm-badge"><i class="fas fa-<?=$meta['icon']?>"></i> <?=$meta['label']?></span>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- سجل النشاط -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> سجل النشاط الأخير</div></div>
    <div class="card-body">
      <?php if (empty($activityLog)): ?>
      <div style="text-align:center;padding:2rem;color:#8895a7">لا يوجد نشاط مسجل</div>
      <?php else: ?>
        <?php
        $actionLabels = [
            'create_staff'=>'إنشاء موظف','edit_staff'=>'تعديل موظف','delete_staff'=>'حذف موظف',
            'update_order_status'=>'تحديث طلب','approve_device'=>'تصريح جهاز',
            'block_device'=>'حظر جهاز','edit_customer'=>'تعديل عميل',
            'update_balance'=>'تعديل رصيد',
        ];
        foreach ($activityLog as $log): ?>
        <div class="log-item">
          <div class="log-dot"></div>
          <div style="flex:1">
            <div style="font-size:.87rem;font-weight:700"><?=htmlspecialchars($actionLabels[$log['action']]??$log['action'])?></div>
            <div style="font-size:.78rem;color:#8895a7"><?=htmlspecialchars($log['description']??'')?> · <?=$log['ip_address']?></div>
          </div>
          <div style="font-size:.75rem;color:#8895a7;flex-shrink:0"><?=date('d/m H:i',strtotime($log['created_at']))?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif ($action === 'admin_profile' && isAdmin() && $adminProfile): ?>
<!-- ═══ ملف المدير ════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="staff.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-right"></i></a>
    <div class="staff-avatar" style="background:linear-gradient(135deg,#f5a623,#e67e22)"><?=mb_strtoupper(mb_substr($adminProfile['username'],0,1))?></div>
    <div>
      <h2 style="margin:0;display:flex;align-items:center;gap:8px">
        <?=htmlspecialchars($adminProfile['username'])?>
        <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);padding:2px 8px;border-radius:20px;font-size:12px;font-weight:800">👑 مدير</span>
      </h2>
      <div style="font-size:.82rem;color:#8895a7"><?=htmlspecialchars($adminProfile['email']??'')?></div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- تغيير كلمة المرور -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-lock" style="color:#f5a623"></i> تغيير كلمة المرور</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="change_admin_pw" value="1">
        <div class="form-group">
          <label>كلمة المرور الحالية *</label>
          <input type="password" name="old_password" class="form-control" required placeholder="أدخل كلمة المرور الحالية">
        </div>
        <div class="form-group">
          <label>كلمة المرور الجديدة *</label>
          <input type="password" name="new_password" class="form-control" required placeholder="6 أحرف على الأقل">
        </div>
        <div class="form-group">
          <label>تأكيد كلمة المرور الجديدة *</label>
          <input type="password" name="new_password2" class="form-control" required placeholder="أعد كتابة كلمة المرور">
        </div>
        <button type="submit" class="btn btn-warning"><i class="fas fa-save"></i> حفظ كلمة المرور</button>
      </form>
    </div>
  </div>

  <!-- أجهزتي -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-mobile-alt" style="color:#00d4aa"></i> أجهزتي المسجّلة</div></div>
    <div class="card-body" style="padding:0">
      <?php if(empty($adminDevices)): ?>
      <div style="text-align:center;padding:2rem;color:#8895a7">لا توجد أجهزة مسجلة</div>
      <?php else: ?>
      <?php
      $devIcons = ['desktop'=>'🖥️','mobile'=>'📱','tablet'=>'📟','unknown'=>'🖥️'];
      $devColors = ['approved'=>'#00d4aa','pending'=>'#f5a623','blocked'=>'#ff4455'];
      $devLabels = ['approved'=>'مصرح','pending'=>'انتظار','blocked'=>'محظور'];
      foreach($adminDevices as $dv): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:14px;border-bottom:1px solid var(--border)">
        <div style="font-size:1.6rem"><?=$devIcons[$dv['device_type']??'unknown']?></div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.88rem"><?=htmlspecialchars($dv['device_name']??'جهاز مجهول')?></div>
          <div style="font-size:.75rem;color:#8895a7">
            IP: <?=$dv['ip_address']??'—'?> ·
            <?=isset($dv['last_seen'])?date('d/m H:i',strtotime($dv['last_seen'])):'—'?>
            <?php if($dv['is_first_device']??0): ?> · <span style="color:#00d4aa">أول جهاز</span><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
          <span style="background:<?=$devColors[$dv['status']]?>22;color:<?=$devColors[$dv['status']]?>;border:1px solid <?=$devColors[$dv['status']]?>44;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700">
            <?=$devLabels[$dv['status']]?>
          </span>
          <?php if($dv['status']==='pending'): ?>
          <a href="?action=approve_admin_device&did=<?=$dv['id']?>" class="btn btn-sm btn-success" title="تصريح"><i class="fas fa-check"></i></a>
          <?php endif; ?>
          <?php if($dv['status']!=='blocked'): ?>
          <a href="?action=block_admin_device&did=<?=$dv['id']?>" class="btn btn-sm btn-danger" title="حظر" onclick="return confirm('حظر الجهاز؟')"><i class="fas fa-ban"></i></a>
          <?php endif; ?>
          <a href="?action=delete_admin_device&did=<?=$dv['id']?>" class="btn btn-sm btn-secondary" title="حذف" onclick="return confirm('حذف الجهاز؟')"><i class="fas fa-trash"></i></a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php else: ?>
<!-- ═══ قائمة الموظفين ═══════════════════════════════════════════════════════ -->
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(108,63,224,.15)"><i class="fas fa-user-tie" style="color:#a78bfa"></i></div>
      إدارة الموظفين
    </div>
    <div class="page-header-sub"><?=count($staffList)?> موظف</div>
  </div>
  <?php if (isAdmin()): ?>
  <div style="display:flex;gap:8px">
    <a href="?action=admin_profile" class="btn btn-secondary"><i class="fas fa-user-shield"></i> ملفي وأجهزتي</a>
    <a href="?action=add" class="btn btn-primary"><i class="fas fa-user-plus"></i> موظف جديد</a>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:1rem">
  <div class="card-body" style="padding:14px">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <input type="search" name="q" value="<?=htmlspecialchars($staffQ)?>" placeholder="بحث بالرقم أو اسم المستخدم أو الاسم أو البريد أو الهاتف" style="flex:1;min-width:240px">
      <select name="role_filter" aria-label="الدور"><option value="">كل الأدوار</option><option value="admin" <?=$staffRole==='admin'?'selected':''?>>مدير</option><option value="staff" <?=$staffRole==='staff'?'selected':''?>>موظف</option></select>
      <select name="status_filter" aria-label="الحالة"><option value="">كل الحالات</option><option value="active" <?=$staffStatus==='active'?'selected':''?>>نشط</option><option value="inactive" <?=$staffStatus==='inactive'?'selected':''?>>موقوف</option></select>
      <select name="account_filter" aria-label="الحساب المالي"><option value="">كل الربط المالي</option><option value="linked" <?=$staffAccount==='linked'?'selected':''?>>مرتبط بحساب</option><option value="unlinked" <?=$staffAccount==='unlinked'?'selected':''?>>غير مرتبط</option></select>
      <select name="activity_filter" aria-label="النشاط"><option value="">كل النشاط</option><option value="with" <?=$staffActivity==='with'?'selected':''?>>لديه نشاط</option><option value="without" <?=$staffActivity==='without'?'selected':''?>>بدون نشاط</option></select>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> تطبيق</button>
      <?php if($staffFilterCount): ?><a href="staff.php" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> مسح</a><span style="color:var(--cyan);font-size:.78rem"><?= $staffFilterCount ?> فلاتر نشطة</span><?php endif; ?>
    </form>
  </div>
</div>

<?php if (empty($staffList)): ?>
<div class="card">
  <div style="text-align:center;padding:4rem;color:#8895a7">
    <i class="fas fa-user-tie" style="font-size:4rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    <h3 style="margin-bottom:.5rem;color:#fff">لا يوجد موظفون بعد</h3>
    <p>اضغط "موظف جديد" لإضافة أول موظف</p>
    <a href="?action=add" class="btn btn-primary" style="margin-top:1rem"><i class="fas fa-user-plus"></i> إضافة موظف</a>
  </div>
</div>

<?php else: ?>
<div class="card">
<?php foreach ($staffList as $st):
    $granted = 0;
    foreach (array_keys($allPerms) as $pk) if (!empty($st['perm_'.$pk])) $granted++;
    $totalPerms = count($allPerms);
?>
<div class="staff-card">
  <div class="staff-avatar"><?=mb_strtoupper(mb_substr($st['username'],0,1))?></div>
  <div style="flex:1;min-width:0">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <strong style="font-size:1rem"><?=htmlspecialchars($st['username'])?></strong>
      <?php if($st['full_name']): ?><span style="color:#8895a7;font-size:.85rem"><?=htmlspecialchars($st['full_name'])?></span><?php endif; ?>
      <?php if($st['role']==='admin'): ?>
      <span style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:800">👑 مدير</span>
      <?php else: ?>
      <span style="background:rgba(124,58,237,.15);color:#a78bfa;border:1px solid rgba(124,58,237,.3);padding:2px 8px;border-radius:20px;font-size:11px;font-weight:800">🛡️ موظف</span>
      <?php endif; ?>
      <span class="badge badge-<?=$st['status']?'success':'danger'?>"><?=$st['status']?'نشط':'موقوف'?></span>
    </div>
    <div style="font-size:.8rem;color:#8895a7;margin-top:3px">
      <?=htmlspecialchars($st['email']??'')?> · انضم: <?=date('d/m/Y',strtotime($st['created_at']))?>
      <?php if(!empty($st['accounting_code'])): ?> · <span style="color:#00d4aa"><i class="fas fa-link"></i> حساب: <?=htmlspecialchars($st['accounting_code'].' — '.$st['accounting_name'])?></span><?php else: ?> · <span style="color:#f5a623">لا يوجد حساب مالي مرتبط</span><?php endif; ?>
      <?php if($st['last_action']): ?> · آخر نشاط: <?=date('d/m H:i',strtotime($st['last_action']))?><?php endif; ?>
    </div>
    <!-- شريط الصلاحيات -->
    <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
      <div style="flex:1;height:4px;background:var(--border);border-radius:2px;overflow:hidden">
        <div style="height:100%;width:<?=($totalPerms>0?round($granted/$totalPerms*100):0)?>%;background:linear-gradient(90deg,#6c3fe0,#a78bfa);transition:.3s;border-radius:2px"></div>
      </div>
      <span style="font-size:.75rem;color:#a78bfa;font-weight:700;flex-shrink:0"><?=$granted?>/<?=$totalPerms?> صلاحية</span>
    </div>
  </div>
  <div style="display:flex;gap:6px;flex-shrink:0">
    <a href="?action=view&id=<?=$st['id']?>" class="btn btn-sm btn-secondary"><i class="fas fa-eye"></i></a>
    <?php if($st['role']!=='admin'): ?>
    <a href="?action=edit&id=<?=$st['id']?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
    <a href="?action=toggle&id=<?=$st['id']?>" class="btn btn-sm <?=$st['status']?'btn-danger':'btn-success'?>" onclick="return confirm('تغيير حالة الموظف؟')">
      <i class="fas fa-<?=$st['status']?'ban':'check'?>"></i>
    </a>
    <?php if(isAdmin()): ?>
    <a href="?action=delete&id=<?=$st['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('هل تريد حذف الموظف نهائياً؟')"><i class="fas fa-trash"></i></a>
    <?php endif; ?>
    <?php else: ?>
    <span style="font-size:11px;color:#8895a7;padding:4px 8px">محمي</span>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- إحصائيات الصلاحيات -->
<div class="card mt-2">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-key"></i> جدول صلاحيات الموظفين</div></div>
  <div class="table-wrap" style="overflow-x:auto">
    <table style="min-width:900px">
      <thead>
        <tr>
          <th>الموظف</th>
          <?php foreach ($allPerms as $key=>$meta): ?>
          <th style="text-align:center;padding:8px 4px;font-size:10px;white-space:nowrap">
            <i class="fas fa-<?=$meta['icon']?>" style="display:block;margin-bottom:3px;font-size:14px;color:var(--primary)"></i>
            <?=$meta['label']?>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($staffList as $st): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;background:<?=$st['role']==='admin'?'linear-gradient(135deg,#f5a623,#e67e22)':'linear-gradient(135deg,#6c3fe0,#9b59b6)'?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900"><?=mb_strtoupper(mb_substr($st['username'],0,1))?></div>
              <?=htmlspecialchars($st['username'])?>
              <?php if($st['role']==='admin'): ?><span style="font-size:10px;color:#f5a623">👑</span><?php endif; ?>
            </div>
          </td>
          <?php foreach (array_keys($allPerms) as $pk): ?>
          <td style="text-align:center;padding:6px">
            <?php if(!empty($st['perm_'.$pk])): ?>
            <span style="color:#00d4aa;font-size:16px">✓</span>
            <?php else: ?>
            <span style="color:rgba(255,255,255,.1);font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
