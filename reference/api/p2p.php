<?php
/**
 * api/p2p.php — P2P Marketplace API
 * Actions: services, my_services, add_service, edit_service, buy, order, orders, send_msg, messages, confirm, cancel, heartbeat
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/p2p_accounting.php';

// نظام كشف الاحتيال
$fraudFile = dirname(__DIR__) . '/includes/p2p_fraud.php';
if (file_exists($fraudFile)) require_once $fraudFile;
$fraud = class_exists('P2PFraudEngine') ? new P2PFraudEngine($pdo) : null;
header('Content-Type: application/json; charset=utf-8');

function j($ok, $d=[]) { echo json_encode(array_merge(['ok'=>$ok], $d), JSON_UNESCAPED_UNICODE); exit; }

// ── إنشاء الجداول تلقائياً ──
p2pAccountingEnsureTable($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_services` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `seller_id` INT NOT NULL, `title` VARCHAR(200) NOT NULL,
  `description` TEXT, `price` DECIMAL(12,4) NOT NULL, `quantity` INT DEFAULT 1, `sold_count` INT DEFAULT 0,
  `commission_rate` DECIMAL(5,2) DEFAULT 5.00, `commission_payer` ENUM('buyer','seller') DEFAULT 'buyer',
  `image` VARCHAR(500), `status` ENUM('active','paused','sold_out','suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_seller` (`seller_id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `service_id` INT NOT NULL, `buyer_id` INT NOT NULL, `seller_id` INT NOT NULL,
  `price` DECIMAL(12,4) NOT NULL, `commission` DECIMAL(12,4) DEFAULT 0, `commission_payer` ENUM('buyer','seller') DEFAULT 'buyer',
  `buyer_total` DECIMAL(12,4) NOT NULL, `seller_escrow` DECIMAL(12,4) NOT NULL, `seller_payout` DECIMAL(12,4) NOT NULL,
  `status` ENUM('pending','active','completed','cancelled','disputed') DEFAULT 'pending',
  `chat_expires_at` DATETIME, `completed_at` DATETIME, `cancelled_at` DATETIME,
  `cancelled_by` ENUM('buyer','seller','admin','timeout'), `cancel_reason` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_buyer` (`buyer_id`), KEY `idx_seller` (`seller_id`), KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_messages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `sender_id` INT NOT NULL,
  `message` TEXT NOT NULL, `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_online_status` (
  `user_id` INT PRIMARY KEY, `is_online` TINYINT(1) DEFAULT 0, `last_seen` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_REQUEST['action'] ?? '';

// ── فحص التوثيق (KYC) ──
function isUserVerified(PDO $pdo, int $userId): bool {
    try {
        $s = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1");
        $s->execute([$userId]);
        $r = $s->fetch();
        return $r && $r['status'] === 'approved';
    } catch(Exception $e) {
        // جدول kyc_requests غير موجود — نعتبر الجميع موثقين مؤقتاً
        return true;
    }
}

function requireVerified(PDO $pdo) {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول','code'=>'login']);
    if (!isUserVerified($pdo, $_SESSION['user_id'])) j(false, ['error'=>'يجب توثيق حسابك لاستخدام سوق P2P','code'=>'kyc']);
}

function getDefaultCommission(): float {
    return (float)(getSetting('p2p_default_commission') ?: 5);
}

// ══════════════════════════════════════════
// categories — جلب الأقسام
// ══════════════════════════════════════════
if ($action === 'categories') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_categories` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL, `icon` VARCHAR(50) DEFAULT 'category', `color` VARCHAR(20) DEFAULT '#3b82f6', `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE p2p_categories ADD COLUMN IF NOT EXISTS `image` VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
        $cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM p2p_services s WHERE s.category_id=c.id AND s.status='active' AND s.quantity>s.sold_count) as svc_count FROM p2p_categories c WHERE c.status=1 ORDER BY c.sort_order, c.id")->fetchAll();
    } catch(Exception $e) { $cats = []; }
    j(true, ['categories' => $cats]);
}

// ══════════════════════════════════════════
// services — عرض الخدمات المتاحة (للجميع)
// ══════════════════════════════════════════
if ($action === 'services') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page-1) * $limit;
    $search = trim($_GET['q'] ?? '');
    $catId = (int)($_GET['category_id'] ?? 0);

    $where = "s.status='active' AND s.quantity > s.sold_count";
    $params = [];
    if ($search) { $where .= " AND (s.title LIKE ? OR s.description LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($catId) { $where .= " AND s.category_id=?"; $params[] = $catId; }

    $total = $pdo->prepare("SELECT COUNT(*) FROM p2p_services s WHERE $where");
    $total->execute($params); $total = (int)$total->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name as seller_name, u.display_name, u.profile_avatar, u.google_avatar,
               u.id as seller_uid,
               COALESCE(pc.name,'') as cat_name, COALESCE(pc.icon,'category') as cat_icon, COALESCE(pc.color,'#3b82f6') as cat_color,
               (SELECT COUNT(*) FROM p2p_orders WHERE seller_id=s.seller_id AND status='completed') as seller_sales,
               COALESCE(os.is_online, 0) as seller_online,
               os.last_seen as seller_last_seen
        FROM p2p_services s
        JOIN users u ON u.id = s.seller_id
        LEFT JOIN p2p_categories pc ON pc.id = s.category_id
        LEFT JOIN p2p_online_status os ON os.user_id = s.seller_id
        WHERE $where
        ORDER BY s.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $services = $stmt->fetchAll();

    // حساب السعر النهائي مع العمولة (النسبة من الإدارة، من يتحمل من البائع)
    $adminCommRate = getDefaultCommission();
    foreach ($services as &$sv) {
        $comm = $sv['price'] * $adminCommRate / 100;
        $sv['buyer_price'] = $sv['commission_payer'] === 'buyer' ? round($sv['price'] + $comm, 4) : $sv['price'];
        $sv['commission_amount'] = round($comm, 4);
        // اسم البائع — أول كلمة فقط
        $sv['seller_display'] = explode(' ', $sv['display_name'] ?: $sv['seller_name'] ?: 'بائع')[0];
        $sv['seller_avatar'] = $sv['profile_avatar'] ?: $sv['google_avatar'] ?: '';
        $sv['remaining'] = $sv['quantity'] - $sv['sold_count'];
    }

    j(true, ['services'=>$services, 'total'=>$total, 'page'=>$page, 'pages'=>ceil($total/$limit)]);
}

// ══════════════════════════════════════════
// my_services — خدماتي (كبائع)
// ══════════════════════════════════════════
if ($action === 'my_services') {
    requireVerified($pdo);
    $uid = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM p2p_services WHERE seller_id=? ORDER BY created_at DESC");
    $stmt->execute([$uid]);
    j(true, ['services'=>$stmt->fetchAll()]);
}

// ══════════════════════════════════════════
// add_service — إضافة خدمة (تذهب للمراجعة أو تُقبل تلقائياً)
// ══════════════════════════════════════════
if ($action === 'add_service') {
    requireVerified($pdo);
    $uid = $_SESSION['user_id'];

    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $price    = (float)($_POST['price'] ?? 0);
    $qty      = max(1, (int)($_POST['quantity'] ?? 1));
    $catId    = (int)($_POST['category_id'] ?? 0);
    $commRate = (float)(getSetting('p2p_default_commission') ?: 5);
    $commPayer = in_array($_POST['commission_payer'] ?? '', ['buyer','seller']) ? $_POST['commission_payer'] : 'buyer';

    if (!$title || mb_strlen($title) < 3) j(false, ['error'=>'اسم الخدمة مطلوب (3 أحرف على الأقل)']);
    if ($price <= 0) j(false, ['error'=>'السعر يجب أن يكون أكبر من صفر']);

    $image = '';
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','gif']) && $_FILES['image']['size'] <= 3*1024*1024) {
            $fname = 'p2p_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $dir = dirname(__DIR__) . '/uploads/p2p/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dir . $fname)) $image = 'uploads/p2p/' . $fname;
        }
    }

    // هل القبول التلقائي مفعّل؟
    $autoApprove = getSetting('p2p_auto_approve_new') === '1';
    $status = $autoApprove ? 'active' : 'pending_review';

    try {
        // تأكد من وجود الأعمدة الجديدة
        try { $pdo->exec("ALTER TABLE p2p_services MODIFY COLUMN status ENUM('active','paused','sold_out','suspended','pending_review','rejected') DEFAULT 'pending_review'"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE p2p_services ADD COLUMN IF NOT EXISTS pending_changes TEXT DEFAULT NULL"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE p2p_services ADD COLUMN IF NOT EXISTS reject_reason VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
        try { $pdo->exec("ALTER TABLE p2p_services ADD COLUMN IF NOT EXISTS category_id INT DEFAULT NULL AFTER seller_id"); } catch(Exception $e) {}

        $pdo->prepare("INSERT INTO p2p_services (seller_id,category_id,title,description,price,quantity,commission_rate,commission_payer,image,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$uid, $catId?:null, $title, $desc, $price, $qty, $commRate, $commPayer, $image, $status]);
        $msg = $autoApprove ? 'تم إضافة الخدمة ونشرها مباشرة ✅' : 'تم إرسال الخدمة للمراجعة ⏳ ستُنشر بعد موافقة الإدارة';
        j(true, ['msg'=>$msg, 'id'=>$pdo->lastInsertId(), 'auto_approved'=>$autoApprove]);
    } catch(Exception $e) {
        j(false, ['error'=>'خطأ في حفظ الخدمة: ' . $e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// edit_service — تعديل خدمة (تعديلات معلقة أو مباشرة)
// ══════════════════════════════════════════
if ($action === 'edit_service') {
    requireVerified($pdo);
    $uid = $_SESSION['user_id'];
    $svcId = (int)($_POST['id'] ?? 0);

    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=? AND seller_id=?");
    $svc->execute([$svcId, $uid]);
    $svc = $svc->fetch();
    if (!$svc) j(false, ['error'=>'الخدمة غير موجودة']);

    $title  = trim($_POST['title'] ?? '') ?: $svc['title'];
    $desc   = trim($_POST['description'] ?? '');
    $price  = (float)($_POST['price'] ?? $svc['price']);
    $qty    = max(1, (int)($_POST['quantity'] ?? $svc['quantity']));
    $catId  = (int)($_POST['category_id'] ?? ($svc['category_id'] ?? 0));
    $commPayer = in_array($_POST['commission_payer'] ?? '', ['buyer','seller']) ? $_POST['commission_payer'] : $svc['commission_payer'];

    $autoApproveEdit = getSetting('p2p_auto_approve_edit') === '1';

    if ($autoApproveEdit) {
        // تطبيق التعديل مباشرة
        $pdo->prepare("UPDATE p2p_services SET title=?,description=?,price=?,quantity=?,category_id=?,commission_payer=?,pending_changes=NULL WHERE id=?")
            ->execute([$title, $desc, $price, $qty, $catId?:null, $commPayer, $svcId]);
        j(true, ['msg'=>'تم تعديل الخدمة بنجاح ✅']);
    } else {
        // حفظ التعديلات كمعلقة
        $changes = json_encode([
            'title'=>$title, 'description'=>$desc, 'price'=>$price,
            'quantity'=>$qty, 'category_id'=>$catId, 'commission_payer'=>$commPayer,
            'requested_at'=>date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE p2p_services SET pending_changes=? WHERE id=?")->execute([$changes, $svcId]);
        j(true, ['msg'=>'تم إرسال التعديلات للمراجعة ⏳']);
    }
}

// ══════════════════════════════════════════
// get_service — جلب خدمة واحدة للتعديل
// ══════════════════════════════════════════
if ($action === 'get_service') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $svcId = (int)($_GET['id'] ?? 0);
    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=? AND seller_id=?");
    $svc->execute([$svcId, $_SESSION['user_id']]);
    $svc = $svc->fetch();
    if (!$svc) j(false, ['error'=>'غير موجود']);
    j(true, ['service'=>$svc]);
}

// ══════════════════════════════════════════
// toggle_service — إيقاف/تفعيل خدمة بواسطة البائع
// ══════════════════════════════════════════
if ($action === 'toggle_service') {
    requireVerified($pdo);
    $svcId = (int)($_POST['id'] ?? 0);
    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=? AND seller_id=?");
    $svc->execute([$svcId, $_SESSION['user_id']]);
    $svc = $svc->fetch();
    if (!$svc) j(false, ['error'=>'غير موجود']);
    $newStatus = $svc['status'] === 'active' ? 'paused' : ($svc['status'] === 'paused' ? 'active' : $svc['status']);
    $pdo->prepare("UPDATE p2p_services SET status=? WHERE id=?")->execute([$newStatus, $svcId]);
    j(true, ['msg'=>$newStatus === 'active' ? 'تم تفعيل الخدمة' : 'تم إيقاف الخدمة', 'status'=>$newStatus]);
}

// ══════════════════════════════════════════
// admin_approve — موافقة الإدارة على خدمة/تعديل
// ══════════════════════════════════════════
if ($action === 'admin_approve') {
    if (!isAdmin()) j(false, ['error'=>'غير مصرح']);
    $svcId = (int)($_POST['id'] ?? 0);
    $type  = $_POST['type'] ?? 'new'; // new | edit

    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=?");
    $svc->execute([$svcId]); $svc = $svc->fetch();
    if (!$svc) j(false, ['error'=>'غير موجود']);

    if ($type === 'edit' && $svc['pending_changes']) {
        $changes = json_decode($svc['pending_changes'], true);
        if ($changes) {
            $pdo->prepare("UPDATE p2p_services SET title=?,description=?,price=?,quantity=?,category_id=?,commission_payer=?,pending_changes=NULL WHERE id=?")
                ->execute([$changes['title']??$svc['title'], $changes['description']??$svc['description'],
                           $changes['price']??$svc['price'], $changes['quantity']??$svc['quantity'],
                           $changes['category_id']??$svc['category_id'], $changes['commission_payer']??$svc['commission_payer'], $svcId]);
        }
        j(true, ['msg'=>'تم قبول التعديلات']);
    } else {
        $pdo->prepare("UPDATE p2p_services SET status='active', reject_reason=NULL WHERE id=?")->execute([$svcId]);
        j(true, ['msg'=>'تم الموافقة ونشر الخدمة']);
    }
}

// ══════════════════════════════════════════
// admin_reject — رفض خدمة/تعديل
// ══════════════════════════════════════════
if ($action === 'admin_reject') {
    if (!isAdmin()) j(false, ['error'=>'غير مصرح']);
    $svcId  = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $type   = $_POST['type'] ?? 'new';

    if ($type === 'edit') {
        $pdo->prepare("UPDATE p2p_services SET pending_changes=NULL WHERE id=?")->execute([$svcId]);
        j(true, ['msg'=>'تم رفض التعديلات']);
    } else {
        $pdo->prepare("UPDATE p2p_services SET status='rejected', reject_reason=? WHERE id=?")->execute([$reason, $svcId]);
        j(true, ['msg'=>'تم رفض الخدمة']);
    }
}

// ══════════════════════════════════════════
// buy — شراء خدمة
// ══════════════════════════════════════════
if ($action === 'buy') {
    requireVerified($pdo);
    $buyerId = $_SESSION['user_id'];
    $svcId   = (int)($_POST['service_id'] ?? 0);

    // فحص الاحتيال
    if ($fraud) {
        $canBuy = $fraud->canBuy($buyerId);
        if (!$canBuy['allowed']) j(false, ['error'=>$canBuy['reason']]);
        $fraud->logIP($buyerId, 'buy');
        $fraud->checkUser($buyerId);
    }

    // جلب الخدمة
    $svc = $pdo->prepare("SELECT * FROM p2p_services WHERE id=? AND status='active'");
    $svc->execute([$svcId]);
    $svc = $svc->fetch();
    if (!$svc) j(false, ['error'=>'الخدمة غير متاحة']);
    if ($svc['seller_id'] == $buyerId) j(false, ['error'=>'لا يمكنك شراء خدمتك الخاصة']);
    if ($svc['sold_count'] >= $svc['quantity']) j(false, ['error'=>'الخدمة نفدت']);

    $sellerId = $svc['seller_id'];
    $price    = (float)$svc['price'];
    $commRate = getDefaultCommission(); // النسبة من الإدارة
    $commPayer = $svc['commission_payer']; // من يتحمل: حسب اختيار البائع
    $commission = round($price * $commRate / 100, 4);

    // حساب المبالغ
    if ($commPayer === 'buyer') {
        $buyerTotal   = round($price + $commission, 4);
        $sellerPayout = $price;
    } else {
        $buyerTotal   = $price;
        $sellerPayout = round($price - $commission, 4);
    }
    $sellerEscrow = $price; // حجز نفس سعر الخدمة كضمان (افتراضي)

    // فحص هل القسم يتطلب ضمان من البائع
    $requireEscrow = true;
    try {
        $ce = $pdo->prepare("SELECT require_seller_escrow FROM p2p_categories WHERE id=?");
        $ce->execute([$svc['category_id'] ?? 0]); $cev = $ce->fetchColumn();
        if ($cev !== false) $requireEscrow = (bool)$cev;
    } catch(Exception $e) {}
    if (!$requireEscrow) $sellerEscrow = 0;

    // فحص رصيد المشتري
    $buyer = getUser($buyerId);
    if ($buyer['balance'] < $buyerTotal) j(false, ['error'=>'رصيدك غير كافٍ. المطلوب: ' . formatMoney($buyerTotal)]);

    // فحص رصيد البائع (الضمان) — فقط إذا مطلوب
    $seller = getUser($sellerId);
    if ($requireEscrow && $seller['balance'] < $sellerEscrow) j(false, ['error'=>'البائع لا يملك ضمان كافٍ حالياً. حاول لاحقاً']);

    $chatDuration = (int)(getSetting('p2p_chat_duration') ?: 30);
    $chatExpires  = date('Y-m-d H:i:s', strtotime("+{$chatDuration} minutes"));

    try {
        $pdo->beginTransaction();

        // 1. إنشاء الطلب
        $pdo->prepare("INSERT INTO p2p_orders (service_id,buyer_id,seller_id,price,commission,commission_payer,buyer_total,seller_escrow,seller_payout,status,chat_expires_at) VALUES (?,?,?,?,?,?,?,?,?,'active',?)")
            ->execute([$svcId, $buyerId, $sellerId, $price, $commission, $commPayer, $buyerTotal, $sellerEscrow, $sellerPayout, $chatExpires]);
        $orderId = $pdo->lastInsertId();

        // 2. خصم من المشتري
        $newBuyerBal = $buyer['balance'] - $buyerTotal;
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBuyerBal, $buyerId]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$buyerId, 'debit', $buyerTotal, $buyer['balance'], $newBuyerBal, 'شراء P2P #'.$orderId.' - '.$svc['title'], $orderId]);

        // 3. حجز ضمان من البائع (فقط إذا مطلوب)
        if ($sellerEscrow > 0) {
            $newSellerBal = $seller['balance'] - $sellerEscrow;
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newSellerBal, $sellerId]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$sellerId, 'debit', $sellerEscrow, $seller['balance'], $newSellerBal, 'ضمان P2P #'.$orderId, $orderId]);
        }

        // 4. تحديث الخدمة
        $pdo->prepare("UPDATE p2p_services SET sold_count=sold_count+1 WHERE id=?")->execute([$svcId]);

        // 5. رسالة ترحيبية تلقائية مع تفاصيل الطلب
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
            ->execute([$orderId, '📋 طلب جديد: ' . $svc['title'] . "\n💰 المبلغ: " . formatMoney($price) . "\n⏱ لديكم {$chatDuration} دقيقة للتواصل وإتمام الخدمة.\nعند الانتهاء، يضغط المشتري \"تم استلام الخدمة\"."]);

        $pdo->commit();

        j(true, [
            'msg'=>'تم الشراء بنجاح!',
            'order_id'=>$orderId,
            'new_balance'=>$newBuyerBal,
            'chat_expires'=>$chatExpires,
        ]);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        j(false, ['error'=>'خطأ في إتمام العملية: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// order — تفاصيل طلب واحد
// ══════════════════════════════════════════
if ($action === 'order') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_GET['id'] ?? 0);

    $ord = $pdo->prepare("
        SELECT o.*, s.title as svc_title, s.description as svc_desc, s.image as svc_image,
               buyer.full_name as buyer_name, buyer.display_name as buyer_display, buyer.profile_avatar as buyer_avatar, buyer.google_avatar as buyer_gavatar,
               seller.full_name as seller_name, seller.display_name as seller_display, seller.profile_avatar as seller_avatar, seller.google_avatar as seller_gavatar,
               seller.id as seller_uid, buyer.id as buyer_uid,
               COALESCE(os.is_online, 0) as seller_online, os.last_seen as seller_last_seen,
               COALESCE(ob.is_online, 0) as buyer_online, ob.last_seen as buyer_last_seen
        FROM p2p_orders o
        JOIN p2p_services s ON s.id = o.service_id
        JOIN users buyer ON buyer.id = o.buyer_id
        JOIN users seller ON seller.id = o.seller_id
        LEFT JOIN p2p_online_status os ON os.user_id = o.seller_id
        LEFT JOIN p2p_online_status ob ON ob.user_id = o.buyer_id
        WHERE o.id=? AND (o.buyer_id=? OR o.seller_id=?)
    ");
    $ord->execute([$orderId, $uid, $uid]);
    $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'الطلب غير موجود']);

    $order['is_buyer'] = $order['buyer_id'] == $uid;
    $order['is_seller'] = $order['seller_id'] == $uid;
    $order['other_name'] = $order['is_buyer']
        ? explode(' ', $order['seller_display'] ?: $order['seller_name'])[0]
        : explode(' ', $order['buyer_display'] ?: $order['buyer_name'])[0];
    $order['other_avatar'] = $order['is_buyer']
        ? ($order['seller_avatar'] ?: $order['seller_gavatar'] ?: '')
        : ($order['buyer_avatar'] ?: $order['buyer_gavatar'] ?: '');
    $order['other_online'] = $order['is_buyer'] ? $order['seller_online'] : $order['buyer_online'];
    $order['other_last_seen'] = $order['is_buyer'] ? $order['seller_last_seen'] : $order['buyer_last_seen'];
    $order['other_uid'] = $order['is_buyer'] ? $order['seller_uid'] : $order['buyer_uid'];

    j(true, ['order'=>$order]);
}

// ══════════════════════════════════════════
// orders — طلباتي (كمشتري أو بائع)
// ══════════════════════════════════════════
if ($action === 'orders') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $role = $_GET['role'] ?? 'all'; // buyer | seller | all
    $status = $_GET['status'] ?? '';

    $where = "(o.buyer_id=? OR o.seller_id=?)";
    $params = [$uid, $uid];
    if ($role === 'buyer') { $where = "o.buyer_id=?"; $params = [$uid]; }
    if ($role === 'seller') { $where = "o.seller_id=?"; $params = [$uid]; }
    if ($status && in_array($status, ['pending','active','completed','cancelled'])) {
        $where .= " AND o.status=?"; $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT o.*, s.title as svc_title, s.image as svc_image,
               buyer.display_name as buyer_display, buyer.full_name as buyer_fname,
               buyer.profile_avatar as buyer_avatar, buyer.google_avatar as buyer_gavatar,
               seller.display_name as seller_display, seller.full_name as seller_fname,
               seller.profile_avatar as seller_avatar, seller.google_avatar as seller_gavatar,
               (SELECT COUNT(*) FROM p2p_messages m WHERE m.order_id=o.id AND m.is_read=0 AND m.sender_id!=?) as unread
        FROM p2p_orders o
        JOIN p2p_services s ON s.id=o.service_id
        JOIN users buyer ON buyer.id=o.buyer_id
        JOIN users seller ON seller.id=o.seller_id
        WHERE $where
        ORDER BY o.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute(array_merge([$uid], $params));

    $orders = $stmt->fetchAll();
    foreach ($orders as &$o) {
        $o['is_buyer'] = $o['buyer_id'] == $uid;
        $o['other_name'] = $o['is_buyer']
            ? explode(' ', $o['seller_display'] ?: $o['seller_fname'])[0]
            : explode(' ', $o['buyer_display'] ?: $o['buyer_fname'])[0];
        $o['other_avatar'] = $o['is_buyer']
            ? ($o['seller_avatar'] ?: $o['seller_gavatar'] ?: '')
            : ($o['buyer_avatar'] ?: $o['buyer_gavatar'] ?: '');
    }

    j(true, ['orders'=>$orders]);
}

// ══════════════════════════════════════════
// messages — رسائل طلب
// ══════════════════════════════════════════
if ($action === 'messages') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_GET['order_id'] ?? 0);

    // تحقق الصلاحية
    $chk = $pdo->prepare("SELECT id FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?)");
    $chk->execute([$orderId, $uid, $uid]);
    if (!$chk->fetch()) j(false, ['error'=>'غير مصرح']);

    // قراءة الرسائل
    $pdo->prepare("UPDATE p2p_messages SET is_read=1 WHERE order_id=? AND sender_id!=?")->execute([$orderId, $uid]);

    $msgs = $pdo->prepare("SELECT m.*, u.display_name, u.full_name FROM p2p_messages m LEFT JOIN users u ON u.id=m.sender_id WHERE m.order_id=? ORDER BY m.created_at ASC");
    $msgs->execute([$orderId]);

    j(true, ['messages'=>$msgs->fetchAll()]);
}

// ══════════════════════════════════════════
// send_msg — إرسال رسالة
// ══════════════════════════════════════════
if ($action === 'send_msg') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (!$message) j(false, ['error'=>'الرسالة فارغة']);

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?) AND status='active'");
    $ord->execute([$orderId, $uid, $uid]);
    if (!$ord->fetch()) j(false, ['error'=>'الطلب غير متاح للمحادثة']);

    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,?,?)")
        ->execute([$orderId, $uid, $message]);

    j(true, ['msg_id'=>$pdo->lastInsertId()]);
}

// ══════════════════════════════════════════
// Helper: تسجيل حدث في سجل الطلب
// ══════════════════════════════════════════
function logOrderEvent(PDO $pdo, int $orderId, int $userId, string $action, string $details = '') {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    try { $pdo->prepare("INSERT INTO p2p_order_log (order_id,user_id,action,details,ip_address) VALUES (?,?,?,?,?)")
        ->execute([$orderId, $userId, $action, $details, $ip]); } catch(Exception $e) {}
}

// Helper: إتمام الطلب وتحويل الأموال
// ══ PARTIAL RELEASE: تحويل جزئي ══
function partialRelease(PDO $pdo, array $order, int $orderId) {
    $cs = getCatSettings($pdo, $orderId);
    $pct = $cs['partial_release_pct'];
    $holdHours = $cs['hold_hours'];
    $seller = getUser($order['seller_id']);
    $escrow = (float)$order['seller_escrow'];
    $payout = (float)$order['seller_payout'];
    $totalDue = $escrow + $payout;
    $partialAmt = round($totalDue * $pct / 100, 4);
    $holdAmt = round($totalDue - $partialAmt, 4);
    $releaseAt = date('Y-m-d H:i:s', strtotime("+{$holdHours} hours"));

    $newBal = $seller['balance'] + $partialAmt;
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $order['seller_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$order['seller_id'], 'credit', $partialAmt, $seller['balance'], $newBal, 'إيراد P2P #'.$orderId." ({$pct}% تحويل أولي)", $orderId]);

    $pdo->prepare("UPDATE p2p_orders SET status='completed', completed_at=NOW(), partial_released=1, partial_released_amount=?, remaining_hold_amount=?, hold_release_at=? WHERE id=?")
        ->execute([$partialAmt, $holdAmt, $releaseAt, $orderId]);
    recordP2PCommission($pdo, $order, $orderId, 'confirmed_partial');

    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
        ->execute([$orderId, "✅ تم تأكيد الاستلام!\n💰 تم تحويل {$pct}% ({$partialAmt}\$) للبائع.\n🔒 المبلغ المتبقي ({$holdAmt}\$) سيُحوّل بعد {$holdHours} ساعة.\n⚖️ يمكن فتح نزاع خلال هذه الفترة."]);
}

function fullRelease(PDO $pdo, array $order, int $orderId, string $source = 'auto') {
    $pdo->beginTransaction();
    $seller = getUser($order['seller_id']);
    $escrow = (float)$order['seller_escrow'];
    $payout = (float)$order['seller_payout'];
    $totalDue = $escrow + $payout;
    $newBal = $seller['balance'] + $totalDue;
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $order['seller_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$order['seller_id'], 'credit', $totalDue, $seller['balance'], $newBal, 'إيراد P2P #'.$orderId.' (كامل)', $orderId]);
    $pdo->prepare("UPDATE p2p_orders SET status='completed', completed_at=NOW() WHERE id=?")->execute([$orderId]);
    recordP2PCommission($pdo, $order, $orderId, $source);
    $msg = $source === 'auto' ? '⏰ تم إتمام الطلب تلقائياً. تم تحويل كامل المبلغ للبائع.' : '✅ تم تأكيد الاستلام! تم تحويل المبلغ للبائع.';
    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$orderId, $msg]);
    $pdo->commit();
}

function refundBothParties(PDO $pdo, array $order, int $orderId, string $desc = 'إلغاء') {
    $buyer = getUser($order['buyer_id']);
    $refund = (float)$order['buyer_total'];
    $newBB = $buyer['balance'] + $refund;
    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBB, $order['buyer_id']]);
    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$order['buyer_id'], 'credit', $refund, $buyer['balance'], $newBB, 'استرداد '.$desc.' P2P #'.$orderId, $orderId]);
    $escrow = (float)$order['seller_escrow'];
    if ($escrow > 0) {
        $seller = getUser($order['seller_id']);
        $newSB = $seller['balance'] + $escrow;
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newSB, $order['seller_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$order['seller_id'], 'credit', $escrow, $seller['balance'], $newSB, 'استرداد ضمان '.$desc.' P2P #'.$orderId, $orderId]);
    }
}

// ══════════════════════════════════════════
// mark_delivered — البائع يسلّم الخدمة
// ══════════════════════════════════════════
// Helper: جلب إعدادات القسم لطلب معين
function getCatSettings(PDO $pdo, int $orderId): array {
    $defaults = ['risk_level'=>'medium','auto_complete_hours'=>24,'partial_release_pct'=>100,'hold_hours'=>0,'require_security_confirm'=>0,'min_delivery_length'=>5];
    try {
        $r = $pdo->prepare("SELECT pc.* FROM p2p_orders o JOIN p2p_services s ON s.id=o.service_id LEFT JOIN p2p_categories pc ON pc.id=s.category_id WHERE o.id=?");
        $r->execute([$orderId]); $cat = $r->fetch();
        if ($cat && isset($cat['risk_level'])) {
            return [
                'risk_level'=>$cat['risk_level']??'medium',
                'auto_complete_hours'=>(int)($cat['auto_complete_hours']??24),
                'partial_release_pct'=>(int)($cat['partial_release_pct']??100),
                'hold_hours'=>(int)($cat['hold_hours']??0),
                'require_security_confirm'=>(int)($cat['require_security_confirm']??0),
                'min_delivery_length'=>(int)($cat['min_delivery_length']??5),
            ];
        }
    } catch(Exception $e) {}
    return $defaults;
}

if ($action === 'mark_delivered') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);
    $deliveryData = trim($_POST['delivery_data'] ?? '');
    $deliveryType = in_array($_POST['delivery_type']??'', ['account','code','text','other']) ? $_POST['delivery_type'] : 'text';

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND seller_id=? AND status='active'");
    $ord->execute([$orderId, $uid]); $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'الطلب غير موجود أو لا يمكن تسليمه']);

    $cs = getCatSettings($pdo, $orderId);
    $minLen = $cs['min_delivery_length'];
    if (mb_strlen($deliveryData) < $minLen) j(false, ['error'=>"بيانات التسليم قصيرة جداً (الحد الأدنى {$minLen} حرف)"]);

    $autoHours = $cs['auto_complete_hours'];
    $autoAt = date('Y-m-d H:i:s', strtotime("+{$autoHours} hours"));

    try {
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS delivered_at DATETIME DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS delivery_data TEXT DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS delivery_type ENUM('account','code','text','other') DEFAULT 'text'");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS auto_complete_at DATETIME DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS buyer_viewed_delivery TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS buyer_viewed_at DATETIME DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS buyer_viewed_ip VARCHAR(45) DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS buyer_viewed_ua VARCHAR(500) DEFAULT NULL");
    } catch(Exception $e) {}

    $pdo->prepare("UPDATE p2p_orders SET status='delivered', delivered_at=NOW(), delivery_data=?, delivery_type=?, auto_complete_at=? WHERE id=?")
        ->execute([$deliveryData, $deliveryType, $autoAt, $orderId]);

    $typeLabel = ['account'=>'حساب','code'=>'كود','text'=>'نص','other'=>'أخرى'][$deliveryType] ?? 'نص';
    $secMsg = $cs['require_security_confirm'] ? "\n⚠️ تأكد من تغيير جميع بيانات الحساب قبل التأكيد." : "";
    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
        ->execute([$orderId, "📦 تم تسليم الخدمة (نوع: {$typeLabel}).\n⏰ لديك {$autoHours} ساعة لتأكيد الاستلام أو فتح نزاع.{$secMsg}"]);

    logOrderEvent($pdo, $orderId, $uid, 'delivered', "نوع: {$deliveryType} | قسم: {$cs['risk_level']}");
    if ($fraud) { $fraud->logIP($uid, 'deliver', $orderId); $fraud->checkUser($uid); }
    j(true, ['msg'=>'تم تسليم الخدمة بنجاح ✅', 'auto_complete_at'=>$autoAt]);
}

// ══════════════════════════════════════════
// view_delivery — المشتري يشاهد بيانات التسليم (مع تسجيل كامل)
// ══════════════════════════════════════════
if ($action === 'view_delivery') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_GET['order_id'] ?? 0);

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND buyer_id=? AND status='delivered'");
    $ord->execute([$orderId, $uid]); $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'غير متاح']);

    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    try {
        $pdo->prepare("UPDATE p2p_orders SET buyer_viewed_delivery=1, buyer_viewed_at=COALESCE(buyer_viewed_at,NOW()), buyer_viewed_ip=?, buyer_viewed_ua=? WHERE id=?")
            ->execute([$ip, mb_substr($ua,0,500), $orderId]);
        logOrderEvent($pdo, $orderId, $uid, 'viewed_delivery', "IP: {$ip}");
    } catch(Exception $e) {}

    j(true, [
        'delivery_data'=>$order['delivery_data'] ?? '',
        'delivery_type'=>$order['delivery_type'] ?? 'text',
        'delivered_at'=>$order['delivered_at'] ?? '',
        'auto_complete_at'=>$order['auto_complete_at'] ?? '',
    ]);
}

// ══════════════════════════════════════════
// confirm — تأكيد استلام مع تحقق أمني + ضمان جزئي
// ══════════════════════════════════════════
if ($action === 'confirm') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    $ord = $pdo->prepare("SELECT o.* FROM p2p_orders o WHERE o.id=? AND o.buyer_id=? AND o.status='delivered'");
    $ord->execute([$orderId, $uid]); $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'يجب أن يسلّم البائع الخدمة أولاً']);

    $cs = getCatSettings($pdo, $orderId);
    $deliveryType = $order['delivery_type'] ?? 'text';

    // تحقق أمني — حسب إعدادات القسم
    if ($cs['require_security_confirm']) {
        $emailChanged = (int)($_POST['confirmed_email_change'] ?? 0);
        $passChanged  = (int)($_POST['confirmed_password_change'] ?? 0);
        $recoveryRemoved = (int)($_POST['confirmed_recovery_removed'] ?? 0);

        if (!$emailChanged || !$passChanged || !$recoveryRemoved) {
            j(false, ['error'=>'يجب تأكيد تغيير جميع بيانات الحساب (البريد + كلمة المرور + إزالة وسائل الاسترجاع) قبل التأكيد', 'require_security'=>true]);
        }

        try {
            $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS confirmed_email_change TINYINT(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS confirmed_password_change TINYINT(1) DEFAULT 0");
            $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS confirmed_recovery_removed TINYINT(1) DEFAULT 0");
            $pdo->prepare("UPDATE p2p_orders SET confirmed_email_change=1, confirmed_password_change=1, confirmed_recovery_removed=1 WHERE id=?")
                ->execute([$orderId]);
        } catch(Exception $e) {}
    }

    try {
        // ضمان جزئي — حسب إعدادات القسم
        $usePartial = $cs['partial_release_pct'] < 100 && $cs['hold_hours'] > 0;

        if ($usePartial) {
            $pdo->beginTransaction();
            partialRelease($pdo, $order, $orderId);
            $pdo->commit();
            logOrderEvent($pdo, $orderId, $uid, 'confirmed_partial', "ضمان جزئي {$cs['partial_release_pct']}%");
            j(true, ['msg'=>"تم تأكيد الاستلام! تم تحويل {$cs['partial_release_pct']}% للبائع. المبلغ المتبقي سيُحوّل بعد {$cs['hold_hours']} ساعة.", 'partial'=>true]);
        } else {
            fullRelease($pdo, $order, $orderId, 'buyer_confirm');
            logOrderEvent($pdo, $orderId, $uid, 'confirmed', 'تأكيد كامل');
            j(true, ['msg'=>'تم تأكيد الاستلام! تم تحويل المبلغ للبائع.']);
        }
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        j(false, ['error'=>'خطأ: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// cancel — إلغاء مشروط بالحالة
// ══════════════════════════════════════════
if ($action === 'cancel') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?)");
    $ord->execute([$orderId, $uid, $uid]);
    $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'الطلب غير موجود']);

    $isBuyer = $order['buyer_id'] == $uid;
    $status = $order['status'];

    // ❌ لا يمكن إلغاء بعد التسليم
    if ($status === 'delivered') j(false, ['error'=>'لا يمكن إلغاء الطلب بعد التسليم. يمكنك فتح نزاع بدلاً من ذلك.']);
    if ($status === 'completed') j(false, ['error'=>'الطلب مكتمل ولا يمكن إلغاؤه.']);
    if ($status === 'cancelled') j(false, ['error'=>'الطلب ملغي بالفعل.']);

    // المشتري: إلغاء مباشر فقط في pending
    if ($isBuyer && $status === 'active') j(false, ['error'=>'لا يمكن إلغاء الطلب بعد بدء التنفيذ. يمكنك طلب إلغاء من البائع.']);

    // البائع يمكنه الإلغاء في pending + active
    $cancelledBy = $isBuyer ? 'buyer' : 'seller';

    try {
        $pdo->beginTransaction();
        refundBothParties($pdo, $order, $orderId, 'إلغاء');
        $pdo->prepare("UPDATE p2p_orders SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancel_reason=? WHERE id=?")
            ->execute([$cancelledBy, $reason, $orderId]);
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
            ->execute([$orderId, '❌ تم إلغاء الطلب بواسطة '.($isBuyer?'المشتري':'البائع').'. تم إرجاع المبالغ للطرفين.']);
        $pdo->commit();
        logOrderEvent($pdo, $orderId, $uid, 'cancelled', $cancelledBy.' ألغى: '.$reason);
        if ($fraud) { $fraud->logIP($uid, 'cancel', $orderId); $fraud->checkUser($uid); }
        j(true, ['msg'=>'تم إلغاء الطلب واسترداد المبالغ']);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        j(false, ['error'=>'خطأ: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// request_cancel — طلب إلغاء (المشتري في active)
// ══════════════════════════════════════════
if ($action === 'request_cancel') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND buyer_id=? AND status='active'");
    $ord->execute([$orderId, $uid]);
    $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'لا يمكن طلب الإلغاء لهذا الطلب']);

    try {
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS cancel_requested_by ENUM('buyer','seller') DEFAULT NULL");
        $pdo->exec("ALTER TABLE p2p_orders ADD COLUMN IF NOT EXISTS cancel_request_at DATETIME DEFAULT NULL");
    } catch(Exception $e) {}

    $pdo->prepare("UPDATE p2p_orders SET cancel_requested_by='buyer', cancel_request_at=NOW() WHERE id=?")
        ->execute([$orderId]);
    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
        ->execute([$orderId, '⚠️ المشتري يطلب إلغاء الطلب'.($reason ? ': '.$reason : '')."\nالبائع يمكنه قبول أو رفض الطلب."]);

    logOrderEvent($pdo, $orderId, $uid, 'cancel_requested', $reason);
    j(true, ['msg'=>'تم إرسال طلب الإلغاء للبائع']);
}

// ══════════════════════════════════════════
// approve_cancel — البائع يوافق على طلب الإلغاء
// ══════════════════════════════════════════
if ($action === 'approve_cancel') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND seller_id=? AND status='active' AND cancel_requested_by='buyer'");
    $ord->execute([$orderId, $uid]);
    $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'لا يوجد طلب إلغاء معلق']);

    try {
        $pdo->beginTransaction();
        refundBothParties($pdo, $order, $orderId, 'إلغاء بموافقة البائع');
        $pdo->prepare("UPDATE p2p_orders SET status='cancelled', cancelled_at=NOW(), cancelled_by='buyer', cancel_reason='إلغاء بموافقة البائع' WHERE id=?")->execute([$orderId]);
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$orderId, '❌ تم الإلغاء بموافقة البائع. تم إرجاع المبالغ.']);
        $pdo->commit();
        logOrderEvent($pdo, $orderId, $uid, 'cancel_approved', 'البائع وافق على الإلغاء');
        j(true, ['msg'=>'تم الإلغاء واسترداد المبالغ']);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        j(false, ['error'=>'خطأ: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// reject_cancel — البائع يرفض طلب الإلغاء
// ══════════════════════════════════════════
if ($action === 'reject_cancel') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);

    $pdo->prepare("UPDATE p2p_orders SET cancel_requested_by=NULL, cancel_request_at=NULL WHERE id=? AND seller_id=?")->execute([$orderId, $uid]);
    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$orderId, '🔴 البائع رفض طلب الإلغاء. يمكنك فتح نزاع إذا كنت غير راضٍ.']);
    logOrderEvent($pdo, $orderId, $uid, 'cancel_rejected', 'البائع رفض الإلغاء');
    j(true, ['msg'=>'تم رفض طلب الإلغاء']);
}

// ══════════════════════════════════════════
// open_dispute — فتح نزاع
// ══════════════════════════════════════════
if ($action === 'open_dispute') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid = $_SESSION['user_id'];
    $orderId = (int)($_POST['order_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?) AND status IN ('active','delivered')");
    $ord->execute([$orderId, $uid, $uid]);
    $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'لا يمكن فتح نزاع لهذا الطلب']);

    $pdo->prepare("UPDATE p2p_orders SET status='disputed' WHERE id=?")->execute([$orderId]);
    $who = $order['buyer_id'] == $uid ? 'المشتري' : 'البائع';
    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
        ->execute([$orderId, "⚖️ تم فتح نزاع بواسطة {$who}.\nالسبب: {$reason}\n🔒 تم تجميد الأموال حتى مراجعة الإدارة."]);

    logOrderEvent($pdo, $orderId, $uid, 'disputed', $who.' فتح نزاع: '.$reason);
    if ($fraud) {
        $fraud->logIP($uid, 'dispute', $orderId);
        // كشف نزاع فوري بعد مشاهدة البيانات
        if ($order['buyer_viewed_at'] && $order['buyer_id'] == $uid) {
            $viewedMins = (time() - strtotime($order['buyer_viewed_at'])) / 60;
            if ($viewedMins < 60) {
                $fraud->logFraud($uid, 'INSTANT_DISPUTE', 'فتح نزاع بعد '.round($viewedMins).' دقيقة من مشاهدة البيانات', ['order_id'=>$orderId, 'minutes'=>round($viewedMins)], $orderId);
            }
        }
        $fraud->checkUser($uid);
    }
    j(true, ['msg'=>'تم فتح النزاع. ستقوم الإدارة بالمراجعة.']);
}

// ══════════════════════════════════════════
// auto_complete — إتمام تلقائي + تحويل مبالغ معلقة
// ══════════════════════════════════════════
if ($action === 'auto_complete') {
    try {
        $count = 0; $holdCount = 0;
        // 1) إتمام تلقائي
        try {
            $expired = $pdo->query("SELECT * FROM p2p_orders WHERE status='delivered' AND auto_complete_at IS NOT NULL AND auto_complete_at <= NOW()")->fetchAll();
            foreach ($expired as $order) {
                try { fullRelease($pdo, $order, $order['id'], 'auto'); logOrderEvent($pdo, $order['id'], 0, 'auto_completed', 'إتمام تلقائي'); $count++; } catch(Exception $e) {}
            }
        } catch(Exception $e) {}
        // 2) تحويل المبالغ المعلقة
        try {
            $holds = $pdo->query("SELECT * FROM p2p_orders WHERE status='completed' AND partial_released=1 AND remaining_hold_amount>0 AND hold_release_at IS NOT NULL AND hold_release_at <= NOW()")->fetchAll();
            foreach ($holds as $order) {
                try {
                    $seller = getUser($order['seller_id']); $h=(float)$order['remaining_hold_amount']; $nb=$seller['balance']+$h;
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$nb,$order['seller_id']]);
                    $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")->execute([$order['seller_id'],'credit',$h,$seller['balance'],$nb,'تحويل متبقي P2P #'.$order['id'],$order['id']]);
                    $pdo->prepare("UPDATE p2p_orders SET remaining_hold_amount=0 WHERE id=?")->execute([$order['id']]);
                    $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$order['id'],"💰 تم تحويل المبلغ المتبقي ({$h}\$) للبائع."]);
                    $pdo->commit(); logOrderEvent($pdo,$order['id'],0,'hold_released',"مبلغ: {$h}"); $holdCount++;
                } catch(Exception $e) { if($pdo->inTransaction())$pdo->rollBack(); }
            }
        } catch(Exception $e) {}
        j(true, ['msg'=>"إتمام: {$count} | معلق: {$holdCount}"]);
    } catch(Exception $e) { j(false, ['error'=>$e->getMessage()]); }
}

// ══════════════════════════════════════════
// rate_order — تقييم
// ══════════════════════════════════════════
if ($action === 'rate_order') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid=$_SESSION['user_id']; $orderId=(int)($_POST['order_id']??0); $rating=max(1,min(5,(int)($_POST['rating']??0))); $comment=trim($_POST['comment']??'');
    $ord=$pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?) AND status='completed'");
    $ord->execute([$orderId,$uid,$uid]); $order=$ord->fetch();
    if(!$order) j(false,['error'=>'غير متاح']); if(!$rating) j(false,['error'=>'اختر تقييم']);
    $ratedId=$order['buyer_id']==$uid?$order['seller_id']:$order['buyer_id'];
    try{
        $pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_ratings` (`id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `rater_id` INT NOT NULL, `rated_id` INT NOT NULL, `rating` TINYINT NOT NULL, `comment` TEXT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY `ur` (`order_id`,`rater_id`), KEY `ir` (`rated_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->prepare("INSERT INTO p2p_ratings (order_id,rater_id,rated_id,rating,comment) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment)")->execute([$orderId,$uid,$ratedId,$rating,$comment]);
        j(true,['msg'=>'تم التقييم ⭐']);
    } catch(Exception $e){j(false,['error'=>$e->getMessage()]);}
}

// ══════════════════════════════════════════
// user_rating — جلب تقييم مستخدم
// ══════════════════════════════════════════
if ($action === 'user_rating') {
    $userId=(int)($_GET['user_id']??0); if(!$userId) j(false,['error'=>'مطلوب user_id']);
    try{$avg=$pdo->prepare("SELECT AVG(rating) as a, COUNT(*) as t FROM p2p_ratings WHERE rated_id=?");$avg->execute([$userId]);$r=$avg->fetch();
    j(true,['avg_rating'=>round((float)($r['a']??0),1),'total_ratings'=>(int)($r['t']??0)]);
    }catch(Exception $e){j(true,['avg_rating'=>0,'total_ratings'=>0]);}
}

// ══════════════════════════════════════════
// seller_profile — الملف الشخصي للبائع
// ══════════════════════════════════════════
if ($action === 'seller_profile') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) j(false, ['error'=>'مطلوب user_id']);

    // بيانات المستخدم
    $user = $pdo->prepare("SELECT id, full_name, display_name, profile_avatar, google_avatar, created_at FROM users WHERE id=?");
    $user->execute([$userId]); $user = $user->fetch();
    if (!$user) j(false, ['error'=>'المستخدم غير موجود']);

    // إحصائيات البيع
    $sellCompleted = (int)$pdo->prepare("SELECT COUNT(*) FROM p2p_orders WHERE seller_id=? AND status='completed'")->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId} AND status='completed'")->fetchColumn() : 0;
    $sellTotal = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId}")->fetchColumn();

    // إحصائيات الشراء
    $buyCompleted = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id={$userId} AND status='completed'")->fetchColumn();
    $buyTotal = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id={$userId}")->fetchColumn();

    // عدد الخدمات النشطة
    $activeServices = (int)$pdo->query("SELECT COUNT(*) FROM p2p_services WHERE seller_id={$userId} AND status='active'")->fetchColumn();

    // التقييمات كبائع (المشترون يقيّمونه)
    $sellerRatings = [];
    try {
        $sr = $pdo->prepare("SELECT r.rating, r.comment, r.created_at, u.display_name, u.full_name, u.profile_avatar, u.google_avatar
            FROM p2p_ratings r JOIN users u ON u.id=r.rater_id
            WHERE r.rated_id=? AND r.rater_id IN (SELECT buyer_id FROM p2p_orders WHERE seller_id=?)
            ORDER BY r.created_at DESC LIMIT 20");
        $sr->execute([$userId, $userId]);
        $sellerRatings = $sr->fetchAll();
    } catch(Exception $e) {}

    // التقييمات كمشتري (البائعون يقيّمونه)
    $buyerRatings = [];
    try {
        $br = $pdo->prepare("SELECT r.rating, r.comment, r.created_at, u.display_name, u.full_name, u.profile_avatar, u.google_avatar
            FROM p2p_ratings r JOIN users u ON u.id=r.rater_id
            WHERE r.rated_id=? AND r.rater_id IN (SELECT seller_id FROM p2p_orders WHERE buyer_id=?)
            ORDER BY r.created_at DESC LIMIT 20");
        $br->execute([$userId, $userId]);
        $buyerRatings = $br->fetchAll();
    } catch(Exception $e) {}

    // متوسط التقييم العام
    $avgAll = 0; $totalRatings = 0;
    try {
        $ar = $pdo->prepare("SELECT AVG(rating) as a, COUNT(*) as t FROM p2p_ratings WHERE rated_id=?");
        $ar->execute([$userId]); $arR = $ar->fetch();
        $avgAll = round((float)($arR['a'] ?? 0), 1);
        $totalRatings = (int)($arR['t'] ?? 0);
    } catch(Exception $e) {}

    // متوسط تقييم البائع فقط
    $avgSeller = 0;
    try { $as = $pdo->query("SELECT AVG(r.rating) FROM p2p_ratings r WHERE r.rated_id={$userId} AND r.rater_id IN (SELECT buyer_id FROM p2p_orders WHERE seller_id={$userId})"); $avgSeller = round((float)$as->fetchColumn(), 1); } catch(Exception $e) {}

    // حالة الاتصال
    $online = false; $lastSeen = null;
    try { $os = $pdo->prepare("SELECT is_online, last_seen FROM p2p_online_status WHERE user_id=?"); $os->execute([$userId]); $osR = $os->fetch();
        if ($osR) { $online = (bool)$osR['is_online']; $lastSeen = $osR['last_seen']; }
    } catch(Exception $e) {}

    // KYC
    $verified = false;
    try { $ks = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? ORDER BY id DESC LIMIT 1"); $ks->execute([$userId]); $kr = $ks->fetch(); $verified = $kr && $kr['status'] === 'approved'; } catch(Exception $e) {}

    // نسبة الثقة (من 100%)
    $trust = 50; // أساس
    if ($verified) $trust += 15;
    if ($sellCompleted >= 5) $trust += 10;
    if ($sellCompleted >= 20) $trust += 5;
    if ($avgSeller >= 4.5 && $totalRatings >= 3) $trust += 15;
    elseif ($avgSeller >= 4.0 && $totalRatings >= 2) $trust += 10;
    elseif ($avgSeller >= 3.0) $trust += 5;
    $cancelledSell = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId} AND status='cancelled' AND cancelled_by='seller'")->fetchColumn();
    if ($cancelledSell > 0 && $sellTotal > 0) $trust -= min(20, (int)($cancelledSell / $sellTotal * 30));
    $disputed = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE seller_id={$userId} AND status='disputed'")->fetchColumn();
    if ($disputed > 0) $trust -= min(15, $disputed * 5);
    $memberDays = max(1, (int)((time() - strtotime($user['created_at'])) / 86400));
    if ($memberDays >= 90) $trust += 5;
    $trust = max(0, min(100, $trust));

    $name = explode(' ', $user['display_name'] ?: $user['full_name'] ?: 'مستخدم')[0];
    $avatar = $user['profile_avatar'] ?: $user['google_avatar'] ?: '';

    // خدمات البائع النشطة
    $sellerServices = [];
    try {
        $ss = $pdo->prepare("SELECT s.*, COALESCE(pc.name,'') as cat_name, COALESCE(pc.icon,'category') as cat_icon, COALESCE(pc.color,'#3b82f6') as cat_color FROM p2p_services s LEFT JOIN p2p_categories pc ON pc.id=s.category_id WHERE s.seller_id=? AND s.status='active' AND s.quantity>s.sold_count ORDER BY s.created_at DESC LIMIT 20");
        $ss->execute([$userId]); $sellerServices = $ss->fetchAll();
        $adminCommRate = getDefaultCommission();
        foreach ($sellerServices as &$sv) {
            $comm = $sv['price'] * $adminCommRate / 100;
            $sv['buyer_price'] = $sv['commission_payer'] === 'buyer' ? round($sv['price'] + $comm, 4) : $sv['price'];
            $sv['remaining'] = max(0, $sv['quantity'] - $sv['sold_count']);
        }
    } catch(Exception $e) {}

    j(true, [
        'user_id'        => $userId,
        'name'           => $name,
        'avatar'         => $avatar,
        'verified'       => $verified,
        'online'         => $online,
        'last_seen'      => $lastSeen,
        'member_since'   => $user['created_at'],
        'active_services'=> $activeServices,
        'sell_completed'  => $sellCompleted,
        'sell_total'      => $sellTotal,
        'buy_completed'   => $buyCompleted,
        'buy_total'       => $buyTotal,
        'avg_rating'      => $avgAll,
        'avg_seller_rating'=> $avgSeller,
        'total_ratings'   => $totalRatings,
        'trust_score'     => $trust,
        'seller_ratings'  => $sellerRatings,
        'buyer_ratings'   => $buyerRatings,
        'services'        => $sellerServices,
    ]);
}

// ══════════════════════════════════════════
// ADMIN: resolve_dispute — حل النزاع
// ══════════════════════════════════════════
if ($action === 'resolve_dispute') {
    if (!isAdmin()) j(false, ['error'=>'غير مصرح']);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $decision = $_POST['decision'] ?? ''; // buyer_wins | seller_wins | split
    $buyerPct = (float)($_POST['buyer_pct'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND status='disputed'");
    $ord->execute([$orderId]); $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'النزاع غير موجود']);

    try {
        $pdo->beginTransaction();
        $buyerTotal = (float)$order['buyer_total'];
        $sellerEscrow = (float)$order['seller_escrow'];
        $sellerPayout = (float)$order['seller_payout'];

        if ($decision === 'buyer_wins') {
            // استرجاع كامل للمشتري + ضمان البائع
            refundBothParties($pdo, $order, $orderId, 'نزاع — لصالح المشتري');
            $msg = "⚖️ حكم الإدارة: لصالح المشتري. تم استرجاع المبالغ.";
        } elseif ($decision === 'seller_wins') {
            // تحويل كامل للبائع
            $seller = getUser($order['seller_id']);
            $total = $sellerEscrow + $sellerPayout;
            $newBal = $seller['balance'] + $total;
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $order['seller_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order['seller_id'], 'credit', $total, $seller['balance'], $newBal, 'نزاع P2P #'.$orderId.' — لصالح البائع', $orderId]);
            $msg = "⚖️ حكم الإدارة: لصالح البائع. تم تحويل المبلغ.";
        } elseif ($decision === 'split') {
            // توزيع جزئي
            $buyerRefund = round($buyerTotal * $buyerPct / 100, 4);
            $sellerGet = round($buyerTotal - $buyerRefund + $sellerEscrow, 4);

            $buyer = getUser($order['buyer_id']);
            $newBB = $buyer['balance'] + $buyerRefund;
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBB, $order['buyer_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order['buyer_id'], 'credit', $buyerRefund, $buyer['balance'], $newBB, 'نزاع P2P #'.$orderId." — استرجاع {$buyerPct}%", $orderId]);

            $seller = getUser($order['seller_id']);
            $newSB = $seller['balance'] + $sellerGet;
            $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newSB, $order['seller_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$order['seller_id'], 'credit', $sellerGet, $seller['balance'], $newSB, 'نزاع P2P #'.$orderId.' — تسوية', $orderId]);

            $msg = "⚖️ حكم الإدارة: تقسيم — المشتري {$buyerPct}% والبائع ".(100-$buyerPct)."%";
        } else {
            $pdo->rollBack();
            j(false, ['error'=>'قرار غير صالح']);
        }

        $pdo->prepare("UPDATE p2p_orders SET status='completed', completed_at=NOW() WHERE id=?")->execute([$orderId]);
        if ($decision !== 'buyer_wins') {
            recordP2PCommission($pdo, $order, $orderId, 'dispute_'.$decision);
        }
        if ($note) $msg .= "\nملاحظة: ".$note;
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")->execute([$orderId, $msg]);
        logOrderEvent($pdo, $orderId, $_SESSION['user_id'], 'dispute_resolved', $decision.($note?" — $note":""));
        $pdo->commit();
        j(true, ['msg'=>'تم حل النزاع بنجاح']);
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        j(false, ['error'=>'خطأ: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════
// ADMIN: manage_hold — إدارة الضمان المعلق
// ══════════════════════════════════════════
if ($action === 'manage_hold') {
    if (!isAdmin()) j(false, ['error'=>'غير مصرح']);
    $orderId = (int)($_POST['order_id'] ?? 0);
    $holdAction = $_POST['hold_action'] ?? ''; // release_now | extend | cancel

    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND status='completed' AND remaining_hold_amount>0");
    $ord->execute([$orderId]); $order = $ord->fetch();
    if (!$order) j(false, ['error'=>'لا يوجد مبلغ معلق']);

    $holdAmt = (float)$order['remaining_hold_amount'];

    if ($holdAction === 'release_now') {
        $seller = getUser($order['seller_id']);
        $newBal = $seller['balance'] + $holdAmt;
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBal, $order['seller_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$order['seller_id'], 'credit', $holdAmt, $seller['balance'], $newBal, 'إفراج مبكر P2P #'.$orderId, $orderId]);
        $pdo->prepare("UPDATE p2p_orders SET remaining_hold_amount=0 WHERE id=?")->execute([$orderId]);
        $pdo->commit();
        logOrderEvent($pdo, $orderId, $_SESSION['user_id'], 'hold_released_early', "مبلغ: {$holdAmt}");
        j(true, ['msg'=>"تم تحويل المبلغ المعلق ({$holdAmt}\$) للبائع"]);
    } elseif ($holdAction === 'extend') {
        $hours = (int)($_POST['hours'] ?? 48);
        $newRelease = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));
        $pdo->prepare("UPDATE p2p_orders SET hold_release_at=? WHERE id=?")->execute([$newRelease, $orderId]);
        j(true, ['msg'=>"تم تمديد فترة الضمان {$hours} ساعة"]);
    } elseif ($holdAction === 'cancel') {
        // إرجاع المبلغ المعلق للمشتري
        $buyer = getUser($order['buyer_id']);
        $newBB = $buyer['balance'] + $holdAmt;
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$newBB, $order['buyer_id']]);
        $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$order['buyer_id'], 'credit', $holdAmt, $buyer['balance'], $newBB, 'إلغاء ضمان P2P #'.$orderId, $orderId]);
        $pdo->prepare("UPDATE p2p_orders SET remaining_hold_amount=0 WHERE id=?")->execute([$orderId]);
        $pdo->commit();
        j(true, ['msg'=>"تم إرجاع المبلغ المعلق ({$holdAmt}\$) للمشتري"]);
    } else {
        j(false, ['error'=>'إجراء غير صالح']);
    }
}

// ══════════════════════════════════════════
// ADMIN: order_log — سجل أحداث طلب
// ══════════════════════════════════════════
if ($action === 'order_log') {
    if (!isAdmin() && !isStaff()) j(false, ['error'=>'غير مصرح']);
    $orderId = (int)($_GET['order_id'] ?? 0);
    try {
        $logs = $pdo->prepare("SELECT l.*, u.full_name, u.username FROM p2p_order_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.order_id=? ORDER BY l.created_at ASC");
        $logs->execute([$orderId]);
        j(true, ['logs'=>$logs->fetchAll()]);
    } catch(Exception $e) { j(true, ['logs'=>[]]); }
}

// ══════════════════════════════════════════
// ADMIN: fraud_check — مؤشرات الاحتيال
// ══════════════════════════════════════════
if ($action === 'fraud_check') {
    if (!isAdmin()) j(false, ['error'=>'غير مصرح']);
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) j(false, ['error'=>'مطلوب user_id']);
    if ($fraud) $fraud->checkUser($userId);
    $disputes = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id={$userId} OR seller_id={$userId}) AND status='disputed'")->fetchColumn();
    $cancels = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id={$userId} OR seller_id={$userId}) AND status='cancelled'")->fetchColumn();
    $completed = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE (buyer_id={$userId} OR seller_id={$userId}) AND status='completed'")->fetchColumn();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE buyer_id={$userId} OR seller_id={$userId}")->fetchColumn();
    $reports = 0; try { $reports = (int)$pdo->query("SELECT COUNT(*) FROM p2p_reports WHERE order_id IN (SELECT id FROM p2p_orders WHERE seller_id={$userId})")->fetchColumn(); } catch(Exception $e) {}
    $successRate = $total > 0 ? round($completed / $total * 100, 1) : 0;
    $riskData = null; try { $r = $pdo->prepare("SELECT * FROM p2p_risk_scores WHERE user_id=?"); $r->execute([$userId]); $riskData = $r->fetch(); } catch(Exception $e) {}
    $fraudLogs = []; try { $fl = $pdo->prepare("SELECT * FROM p2p_fraud_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 20"); $fl->execute([$userId]); $fraudLogs = $fl->fetchAll(); } catch(Exception $e) {}
    $ips = []; try { $ip = $pdo->prepare("SELECT ip_address, COUNT(*) as cnt, MAX(created_at) as last_seen FROM p2p_ip_log WHERE user_id=? GROUP BY ip_address ORDER BY last_seen DESC LIMIT 10"); $ip->execute([$userId]); $ips = $ip->fetchAll(); } catch(Exception $e) {}
    j(true, ['disputes'=>$disputes,'cancels'=>$cancels,'completed'=>$completed,'total'=>$total,'reports'=>$reports,'success_rate'=>$successRate,
        'risk_score'=>$riskData['risk_score']??0,'risk_level'=>$riskData['risk_level']??'safe',
        'is_blocked'=>(bool)($riskData['is_blocked']??false),'sell_blocked'=>(bool)($riskData['sell_blocked']??false),'buy_blocked'=>(bool)($riskData['buy_blocked']??false),
        'fraud_logs'=>$fraudLogs,'ips'=>$ips]);
}

// ══════════════════════════════════════════
// user_badge — شارة المستخدم (للواجهة)
// ══════════════════════════════════════════
if ($action === 'user_badge') {
    $userId = (int)($_GET['user_id'] ?? 0);
    if (!$userId) j(false, ['error'=>'مطلوب user_id']);
    if ($fraud) { $badge = $fraud->getUserBadge($userId); j(true, $badge); }
    j(true, ['badge'=>'new','label'=>'مستخدم جديد','color'=>'#8895a7','icon'=>'person_add']);
}

// ══════════════════════════════════════════
// heartbeat — تحديث حالة الاتصال
// ══════════════════════════════════════════
if ($action === 'heartbeat') {
    if (!isLoggedIn()) j(false);
    $uid = $_SESSION['user_id'];
    $pdo->prepare("INSERT INTO p2p_online_status (user_id, is_online, last_seen) VALUES (?,1,NOW())
        ON DUPLICATE KEY UPDATE is_online=1, last_seen=NOW()")->execute([$uid]);
    // علّم الغير متصلين (أكثر من دقيقتين)
    $pdo->exec("UPDATE p2p_online_status SET is_online=0 WHERE last_seen < DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    j(true);
}

// ══════════════════════════════════════════
// report_reasons — أسباب الإبلاغ
// ══════════════════════════════════════════
if ($action === 'report_reasons') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_report_reasons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(200) NOT NULL,
        `freezes_seller` TINYINT(1) DEFAULT 0 COMMENT 'يجمد رصيد البائع فوراً',
        `sort_order` INT DEFAULT 0, `status` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // أسباب افتراضية
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM p2p_report_reasons")->fetchColumn();
    if ($cnt === 0) {
        $pdo->exec("INSERT INTO p2p_report_reasons (name,freezes_seller,sort_order) VALUES ('احتيال',1,1),('عدم تسليم الخدمة',1,2),('خدمة مخالفة',0,3),('سلوك غير لائق',0,4),('أخرى',0,99)");
    }
    $reasons = $pdo->query("SELECT * FROM p2p_report_reasons WHERE status=1 ORDER BY sort_order")->fetchAll();
    j(true, ['reasons'=>$reasons]);
}

// ══════════════════════════════════════════
// submit_report — تقديم بلاغ
// ══════════════════════════════════════════
if ($action === 'submit_report') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    $uid      = $_SESSION['user_id'];
    $orderId  = (int)($_POST['order_id'] ?? 0);
    $reasonId = (int)($_POST['reason_id'] ?? 0);
    $details  = trim($_POST['details'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    if (!$orderId || !$reasonId) j(false, ['error'=>'البيانات ناقصة']);

    // تحقق الطلب
    $ord = $pdo->prepare("SELECT * FROM p2p_orders WHERE id=? AND (buyer_id=? OR seller_id=?)");
    $ord->execute([$orderId, $uid, $uid]);
    if (!$ord->fetch()) j(false, ['error'=>'الطلب غير موجود']);

    // إنشاء جدول البلاغات
    $pdo->exec("CREATE TABLE IF NOT EXISTS `p2p_reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT NOT NULL, `reporter_id` INT NOT NULL,
        `reason_id` INT NOT NULL, `details` TEXT, `email` VARCHAR(200), `phone` VARCHAR(50),
        `evidence` TEXT COMMENT 'مسارات الملفات JSON', `status` ENUM('pending','reviewing','resolved','rejected') DEFAULT 'pending',
        `admin_reply` TEXT, `reviewed_by` INT, `reviewed_at` DATETIME,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // رفع الملفات
    $files = [];
    if (!empty($_FILES['evidence']['name'][0])) {
        $dir = dirname(__DIR__) . '/uploads/p2p_reports/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $allowed = ['jpg','jpeg','png','mp3','mp4','avi','rm','rmvb','mov','wmv'];
        $totalSize = 0;
        for ($i = 0; $i < min(5, count($_FILES['evidence']['name'])); $i++) {
            if ($_FILES['evidence']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['evidence']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $totalSize += $_FILES['evidence']['size'][$i];
            if ($totalSize > 50 * 1024 * 1024) break;
            $fname = 'rpt_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['evidence']['tmp_name'][$i], $dir . $fname)) {
                $files[] = 'uploads/p2p_reports/' . $fname;
            }
        }
    }

    $pdo->prepare("INSERT INTO p2p_reports (order_id,reporter_id,reason_id,details,email,phone,evidence) VALUES (?,?,?,?,?,?,?)")
        ->execute([$orderId, $uid, $reasonId, $details, $email, $phone, json_encode($files)]);

    // تجميد رصيد البائع إذا السبب يتطلب ذلك
    $reason = $pdo->prepare("SELECT * FROM p2p_report_reasons WHERE id=?"); $reason->execute([$reasonId]); $reason=$reason->fetch();
    if ($reason && $reason['freezes_seller']) {
        // أضف رسالة في المحادثة
        $pdo->prepare("INSERT INTO p2p_messages (order_id,sender_id,message) VALUES (?,0,?)")
            ->execute([$orderId, '🔴 تم تقديم بلاغ على هذا الطلب. قد يتم تجميد الأموال حتى مراجعة الإدارة.']);
    }

    j(true, ['msg'=>'تم إرسال البلاغ بنجاح. سيتم مراجعته من قبل الإدارة.']);
}

// ══════════════════════════════════════════
// my_reports — بلاغاتي
// ══════════════════════════════════════════
if ($action === 'my_reports') {
    if (!isLoggedIn()) j(false, ['error'=>'يجب تسجيل الدخول']);
    try {
        $stmt = $pdo->prepare("SELECT r.*, rr.name as reason_name FROM p2p_reports r LEFT JOIN p2p_report_reasons rr ON rr.id=r.reason_id WHERE r.reporter_id=? ORDER BY r.created_at DESC LIMIT 20");
        $stmt->execute([$_SESSION['user_id']]);
        j(true, ['reports'=>$stmt->fetchAll()]);
    } catch(Exception $e) { j(true, ['reports'=>[]]); }
}

j(false, ['error'=>'action غير صالح']);
