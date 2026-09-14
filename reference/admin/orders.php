<?php
require_once '../includes/config.php';
require_once '../includes/notifications.php';
require_once '../includes/code_stock_helper.php';
require_once '../includes/order_email_helper.php';
codeStockEnsureTables($pdo);
requireStaffOrAdmin($pdo, 'perm_orders_view');
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة الطلبات - ' . SITE_NAME;

// [M-4 FIX] Whitelist لفلتر الحالة — منع تمرير قيم وهمية
$_allowedStatusFilter = ['pending','processing','completed','cancelled','failed','all'];
$status  = in_array($_GET['status'] ?? '', $_allowedStatusFilter, true) ? $_GET['status'] : 'pending';
$orderId = (int)($_GET['id'] ?? 0);
$userId  = (int)($_GET['user'] ?? 0);

// ── مزامنة تلقائية عند فتح صفحة الطلبات فقط ─────────────────────────────
(function() {
    global $pdo;
    $lastRun = (int)getSetting('cron_last_run');
    if (time() - $lastRun < 30) return; // كل 30 ثانية كحد أقصى
    try {
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('cron_last_run',?)
                       ON DUPLICATE KEY UPDATE setting_value=?")->execute([time(), time()]);
    } catch (Exception $e) { return; }

    $cronKey = getSetting('cron_key') ?: 'cron_secret_key_change_me';
    $host    = parse_url(SITE_URL, PHP_URL_HOST);
    $path    = '/cron_sync_orders.php?key=' . urlencode($cronKey);

    $fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 1);
    if (!$fp) $fp = @fsockopen($host, 80, $errno, $errstr, 1);
    if ($fp) {
        stream_set_timeout($fp, 0, 500000);
        @fwrite($fp, "GET {$path} HTTP/1.1
Host: {$host}
Connection: close

");
        @fclose($fp);
    }
})();

// تحديث حالة طلب
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_status'])) {
  if (!canAccess($pdo,'perm_orders_process') && !canAccess($pdo,'perm_orders_cancel') && !canAccess($pdo,'perm_orders_complete') && !isAdmin()) {
    flashMessage('danger','ليس لديك صلاحية تعديل الطلبات');
    redirect(SITE_URL.'/admin/orders.php');
  }
  $oid       = (int)$_POST['order_id'];
  $newStatus = $_POST['new_status'];
  $adminNotes= trim($_POST['admin_notes'] ?? '');
  $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $stmt->execute([$oid]); $ord = $stmt->fetch();
  if ($ord && $ord['status']!=='cancelled' && in_array($newStatus,['cancelled','failed'])) {
    $u2 = $pdo->prepare("SELECT * FROM users WHERE id=?"); $u2->execute([$ord['user_id']]); $u2=$u2->fetch();
    if ($u2) {
      $nb = $u2['balance'] + $ord['total_price'];
      $pdo->prepare("UPDATE users SET balance=? WHERE id=?")->execute([$nb,$u2['id']]);
      $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description,reference_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$u2['id'],'credit',$ord['total_price'],$u2['balance'],$nb,'استرداد - إلغاء الطلب #'.$oid,$oid]);
    }
  }
  $pdo->prepare("UPDATE orders SET status=?,admin_notes=?,status_message=? WHERE id=?")->execute([$newStatus,$adminNotes,$adminNotes,$oid]);

  // ── عمولة الإحالة ────────────────────────────────────────────────────────
  try {
    $refPath = dirname(__DIR__).'/includes/referral.php';
    if (file_exists($refPath)) require_once $refPath;
    if ($newStatus === 'completed' && function_exists('applyReferralOnOrder')) {
        // أعطِ العمولة فقط عند الاكتمال
        $ordRef = $pdo->prepare("SELECT * FROM orders WHERE id=?"); $ordRef->execute([$oid]); $ordRef=$ordRef->fetch();
        if ($ordRef) applyReferralOnOrder($pdo, $ordRef['user_id'], (float)$ordRef['total_price'], $oid);
    } elseif (in_array($newStatus,['cancelled','failed']) && function_exists('revokeReferralOnOrder')) {
        // اسحب العمولة عند الإلغاء
        revokeReferralOnOrder($pdo, $oid);
    }
  } catch(Exception $e) {}

  // ── إرسال إشعار للعميل ───────────────────────────────────────────────────
  try {
    $ordForNotif = $pdo->prepare("SELECT o.*, s.name as service_name FROM orders o JOIN services s ON o.service_id=s.id WHERE o.id=?");
    $ordForNotif->execute([$oid]);
    $ordData = $ordForNotif->fetch();
    if ($ordData) notifyOrderStatusChange($pdo, $ordData, $newStatus, $adminNotes);
  } catch (Exception $e) {}

  // ── بريد تأكيد الشراء — عند اكتمال الطلب يدوياً من الأدمن ─────────────────
  if ($newStatus === 'completed') {
    try { sendPurchaseConfirmationEmail($pdo, $oid); } catch (Exception $e) {}
  }

  // ── بريد إلغاء الطلب — فقط عند الانتقال الفعلي لحالة ملغي/فاشل ────────────
  if ($ord && $ord['status'] !== 'cancelled' && in_array($newStatus, ['cancelled', 'failed'])) {
    try { sendOrderCancelledEmail($pdo, $oid, $adminNotes); } catch (Exception $e) {}
  }
  // ── تسجيل في سجل التطور ───────────────────────────────────────────────────
  try {
    $pdo->prepare("INSERT INTO order_status_log (order_id,status,message,source,created_by) VALUES (?,?,?,?,?)")
        ->execute([$oid, $newStatus, $adminNotes ?: null, 'admin', $_SESSION['user_id']]);
  } catch (\PDOException $e) {}
  logStaffAction($pdo,'update_order_status','order',$oid,'تغيير حالة الطلب #'.$oid.' إلى '.$newStatus);
  flashMessage('success','تم تحديث حالة الطلب بنجاح');
  redirect(SITE_URL.'/admin/orders.php'.($status?"?status=$status":''));
}

// فحص حالة طلب من Oranos
if (isset($_GET['check_oranos']) && $orderId) {
    $ord = $pdo->prepare("SELECT o.*, p.api_key as prov_key, p.api_url as prov_url, p.provider_type FROM orders o JOIN services s ON o.service_id=s.id LEFT JOIN providers p ON s.provider_id=p.id WHERE o.id=?");
    $ord->execute([$orderId]); $ord = $ord->fetch();
    if ($ord && $ord['provider_order_id'] && $ord['provider_type']==='oranos') {
        $url = 'https://api.oranosmarket.com/client/api/check?orders=['.urlencode($ord['provider_order_id']).']';
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['api-token: '.$ord['prov_key']],CURLOPT_SSL_VERIFYPEER=>false]);
        $res=curl_exec($ch); curl_close($ch);
        $data=json_decode($res,true);
        if($data && isset($data['data'][0]['status'])) {
            $os=$data['data'][0]['status'];
            $map=['accept'=>'processing','reject'=>'failed','wait'=>'pending'];
            $ns=$map[$os]??'pending';
            $msg = 'Oranos: '.$os.' (فُحص '.date('H:i').')';
            $pdo->prepare("UPDATE orders SET status=?,notes=?,status_message=? WHERE id=?")->execute([$ns,$msg,$msg,$orderId]);
            try { $pdo->prepare("INSERT INTO order_status_log (order_id,status,message,source) VALUES (?,?,?,?)")->execute([$orderId,$ns,$msg,'provider']); } catch(\PDOException $e) {}
            flashMessage('success','حالة Oranos: '.$os.' → تم تحديث الطلب');
        } else {
            flashMessage('danger','فشل فحص الحالة من Oranos');
        }
    }
    redirect(SITE_URL.'/admin/orders.php');
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to']   ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));
$customer = trim((string)($_GET['customer'] ?? ''));
$customerId = (int)($_GET['customer_id'] ?? 0);
$pricingGroupFilter = (int)($_GET['pricing_group_id'] ?? 0);
$dataSearch = trim((string)($_GET['data_search'] ?? ''));
$dataKey    = trim((string)($_GET['data_key'] ?? ''));
$serviceTypeFilter = in_array($_GET['service_type'] ?? '', ['', 'default', 'numbers_live', 'numbers_pack', 'numbers_store'], true) ? (string)($_GET['service_type'] ?? '') : '';
$referenceFilter = trim((string)($_GET['reference'] ?? ''));
$couponFilter = trim((string)($_GET['coupon'] ?? ''));
$couponState = in_array($_GET['coupon_state'] ?? '', ['', 'with', 'without'], true) ? (string)($_GET['coupon_state'] ?? '') : '';
$notesSearch = trim((string)($_GET['notes'] ?? ''));
$providerLinkState = in_array($_GET['provider_link'] ?? '', ['', 'with', 'without'], true) ? (string)($_GET['provider_link'] ?? '') : '';
$quantityMinRaw = trim((string)($_GET['quantity_min'] ?? ''));
$quantityMaxRaw = trim((string)($_GET['quantity_max'] ?? ''));
$quantityMin = $quantityMinRaw !== '' && ctype_digit($quantityMinRaw) ? (int)$quantityMinRaw : null;
$quantityMax = $quantityMaxRaw !== '' && ctype_digit($quantityMaxRaw) ? (int)$quantityMaxRaw : null;
$customerStatus = in_array($_GET['customer_status'] ?? '', ['', '1', '0'], true) ? (string)($_GET['customer_status'] ?? '') : '';
$serviceFilter  = (int)($_GET['service_id'] ?? 0);
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$providerFilter = (int)($_GET['provider_id'] ?? 0);
$sourceFilter   = in_array($_GET['source'] ?? '', ['', 'web', 'reseller_api'], true) ? (string)($_GET['source'] ?? '') : '';
$providerOrder  = trim((string)($_GET['provider_order_id'] ?? ''));
$amountMinRaw   = trim((string)($_GET['amount_min'] ?? ''));
$amountMaxRaw   = trim((string)($_GET['amount_max'] ?? ''));
$amountMin      = $amountMinRaw !== '' && is_numeric($amountMinRaw) ? (float)$amountMinRaw : null;
$amountMax      = $amountMaxRaw !== '' && is_numeric($amountMaxRaw) ? (float)$amountMaxRaw : null;
$cashboxFilter  = (int)($_GET['cashbox_id'] ?? 0);
$staffFilter    = (int)($_GET['staff_id'] ?? 0);
$postingFilter  = in_array($_GET['posting_status'] ?? '', ['', 'posted', 'awaiting_cost', 'skipped', 'failed'], true) ? (string)($_GET['posting_status'] ?? '') : '';
$limitOptions   = [50, 100, 300, 500];
$perPage        = (int)($_GET['per_page'] ?? 300);
if (!in_array($perPage, $limitOptions, true)) $perPage = 300;
$sortDirection  = strtolower((string)($_GET['sort'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

// القوائم المرجعية للفلاتر، مع حماية توافقية إذا لم تُثبت ترقية الصناديق بعد.
$filterCategories = $pdo->query("SELECT id,name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filterServices   = $pdo->query("SELECT id,name FROM services ORDER BY name ASC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
$filterProviders  = $pdo->query("SELECT id,name FROM providers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filterCustomers = $filterPricingGroups = [];
try {
    $filterCustomers = $pdo->query("SELECT id,username,full_name,email,phone FROM users WHERE role='customer' AND (is_deleted=0 OR is_deleted IS NULL) ORDER BY username ASC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);
    if ($customerId) {
        $knownCustomerIds = array_map('intval', array_column($filterCustomers, 'id'));
        if (!in_array($customerId, $knownCustomerIds, true)) {
            $customerStmt = $pdo->prepare("SELECT id,username,full_name,email,phone FROM users WHERE id=? LIMIT 1");
            $customerStmt->execute([$customerId]);
            if ($selectedCustomer = $customerStmt->fetch(PDO::FETCH_ASSOC)) array_unshift($filterCustomers, $selectedCustomer);
        }
    }
} catch (Throwable $e) {}
try { $filterPricingGroups = $pdo->query("SELECT id,name,status FROM pricing_groups ORDER BY status DESC,name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$filterCashboxes = $filterStaff = [];
try { $filterCashboxes = $pdo->query("SELECT id,name,currency_code,is_active FROM accounting_cashboxes ORDER BY is_active DESC,name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
try { $filterStaff = $pdo->query("SELECT id,username,full_name FROM users WHERE role='staff' AND (is_deleted=0 OR is_deleted IS NULL) ORDER BY full_name,username")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$advancedFilterActive = ($customer !== '' || $customerId || $pricingGroupFilter || $dataSearch !== '' || $dataKey !== '' || $serviceTypeFilter !== '' || $referenceFilter !== '' || $couponFilter !== '' || $couponState !== '' || $notesSearch !== '' || $providerLinkState !== '' || $quantityMin !== null || $quantityMax !== null || $customerStatus !== '' || $serviceFilter || $categoryFilter || $providerFilter || $sourceFilter !== '' || $providerOrder !== '' || $amountMin !== null || $amountMax !== null || $cashboxFilter || $staffFilter || $postingFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $orderId || $userId);
$globalSearchOnly = ($q !== '' && !$advancedFilterActive);
$where='WHERE 1=1'; $params=[];

if ($q !== '') {
    // البحث الشامل يدعم رقم نجاز، رقم API، رقم المزود، بيانات العميل والبيانات المطلوبة.
    $where .= " AND (o.ref_id LIKE ? OR o.api_order_id LIKE ? OR o.provider_order_id LIKE ? OR o.field_data LIKE ? OR o.id = ? OR u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR s.name LIKE ? )";
    $likeQ  = '%' . $q . '%';
    $numQ   = ctype_digit($q) ? (int)$q : -1;
    foreach ([$likeQ, $likeQ, $likeQ, $likeQ, $numQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ] as $searchParam) $params[] = $searchParam;
}

// عند استعمال q منفرداً يبقى البحث شاملاً لكل الحالات والتواريخ كما كان سابقاً.
if (!$globalSearchOnly) {
    if ($status && $status !== 'all') { $where .= " AND o.status=?"; $params[] = $status; }
    if ($userId)                  { $where .= " AND o.user_id=?"; $params[] = $userId; }
    if ($customerId)              { $where .= " AND o.user_id=?"; $params[] = $customerId; }
    if ($pricingGroupFilter)      { $where .= " AND u.group_id=?"; $params[] = $pricingGroupFilter; }
    if ($customerStatus !== '')   { $where .= " AND u.status=?"; $params[] = (int)$customerStatus; }
    if ($orderId)                 { $where .= " AND o.id=?"; $params[] = $orderId; }
    if ($dateFrom)                { $where .= " AND DATE(o.created_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo)                  { $where .= " AND DATE(o.created_at) <= ?"; $params[] = $dateTo; }
    if ($customer !== '') {
        $customerLike = '%' . $customer . '%';
        $where .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? )";
        array_push($params, $customerLike, $customerLike, $customerLike, $customerLike);
    }
    if ($dataSearch !== '') { $where .= " AND o.field_data LIKE ?"; $params[] = '%' . $dataSearch . '%'; }
    if ($dataKey !== '') { $where .= " AND o.field_data LIKE ?"; $params[] = '%' . $dataKey . '%'; }
    if ($serviceTypeFilter !== '') { $where .= " AND s.service_type=?"; $params[] = $serviceTypeFilter; }
    if ($referenceFilter !== '') {
        $referenceLike = '%' . $referenceFilter . '%';
        $where .= " AND (o.ref_id LIKE ? OR o.api_order_id LIKE ? OR o.api_order_uuid LIKE ? OR o.order_uuid LIKE ?)";
        array_push($params, $referenceLike, $referenceLike, $referenceLike, $referenceLike);
    }
    if ($couponFilter !== '') { $where .= " AND o.coupon_code LIKE ?"; $params[] = '%' . $couponFilter . '%'; }
    if ($couponState === 'with') { $where .= " AND o.coupon_code IS NOT NULL AND o.coupon_code<>''"; }
    if ($couponState === 'without') { $where .= " AND (o.coupon_code IS NULL OR o.coupon_code='')"; }
    if ($notesSearch !== '') {
        $notesLike = '%' . $notesSearch . '%';
        $where .= " AND (o.notes LIKE ? OR o.admin_notes LIKE ? OR o.status_message LIKE ?)";
        array_push($params, $notesLike, $notesLike, $notesLike);
    }
    if ($providerLinkState === 'with') { $where .= " AND o.provider_order_id IS NOT NULL AND o.provider_order_id<>''"; }
    if ($providerLinkState === 'without') { $where .= " AND (o.provider_order_id IS NULL OR o.provider_order_id='')"; }
    if ($quantityMin !== null) { $where .= " AND o.quantity >= ?"; $params[] = $quantityMin; }
    if ($quantityMax !== null) { $where .= " AND o.quantity <= ?"; $params[] = $quantityMax; }
    if ($serviceFilter)  { $where .= " AND o.service_id=?"; $params[] = $serviceFilter; }
    if ($categoryFilter) { $where .= " AND s.category_id=?"; $params[] = $categoryFilter; }
    if ($providerFilter) {
        $where .= " AND (o.used_provider_id=? OR p_note.id=? OR s.provider_id=?)";
        $params[] = $providerFilter; $params[] = $providerFilter; $params[] = $providerFilter;
    }
    if ($sourceFilter !== '') { $where .= " AND COALESCE(o.source,'web')=?"; $params[] = $sourceFilter; }
    if ($providerOrder !== '') { $where .= " AND o.provider_order_id LIKE ?"; $params[] = '%' . $providerOrder . '%'; }
    if ($amountMin !== null) { $where .= " AND o.total_price >= ?"; $params[] = $amountMin; }
    if ($amountMax !== null) { $where .= " AND o.total_price <= ?"; $params[] = $amountMax; }
    if ($cashboxFilter) { $where .= " AND o.cashbox_id=?"; $params[] = $cashboxFilter; }
    if ($staffFilter)   { $where .= " AND o.cashbox_staff_id=?"; $params[] = $staffFilter; }
    if ($postingFilter !== '') { $where .= " AND o.cashbox_posting_status=?"; $params[] = $postingFilter; }
}

$stmt=$pdo->prepare("SELECT o.*,u.username,u.full_name,u.is_deleted,
    COALESCE(s.name, s.deleted_name, CONCAT('[خدمة #',o.service_id,' محذوفة]')) as service_name,
    s.deleted_at as service_deleted_at,
    COALESCE(po.name, p_note.name, (
        SELECT p1.name
        FROM service_providers sp1
        JOIN providers p1 ON sp1.provider_id=p1.id
        WHERE sp1.service_id=o.service_id AND sp1.is_active=1 AND p1.status=1
        ORDER BY sp1.priority ASC, sp1.id ASC
        LIMIT 1
    ), ps.name) as provider_name,
    COALESCE(po.provider_type, p_note.provider_type, (
        SELECT p2.provider_type
        FROM service_providers sp2
        JOIN providers p2 ON sp2.provider_id=p2.id
        WHERE sp2.service_id=o.service_id AND sp2.is_active=1 AND p2.status=1
        ORDER BY sp2.priority ASC, sp2.id ASC
        LIMIT 1
    ), ps.provider_type) as provider_type,
    cs.code as delivered_code
FROM orders o
JOIN users u ON o.user_id=u.id
LEFT JOIN services s ON o.service_id=s.id
LEFT JOIN providers po ON o.used_provider_id=po.id
LEFT JOIN providers p_note ON p_note.status=1
    AND LOWER(p_note.provider_type) = LOWER(TRIM(SUBSTRING_INDEX(COALESCE(o.notes, ''), ':', 1)))
    LEFT JOIN code_stock cs ON cs.order_id=o.id AND cs.status='used'
LEFT JOIN providers ps ON s.provider_id=ps.id
$where ORDER BY o.created_at {$sortDirection} LIMIT {$perPage}");
$stmt->execute($params); $orders=$stmt->fetchAll();

$counts = [];
foreach(['','pending','processing','completed','cancelled','failed'] as $s) {
  $w = $s ? "WHERE status='$s'" : '';
  $counts[$s] = $pdo->query("SELECT COUNT(*) FROM orders $w")->fetchColumn();
}

$statusMap=['pending'=>['قيد الانتظار','warning','clock'],'processing'=>['قيد التنفيذ','info','sync'],'completed'=>['مكتمل','success','check-circle'],'cancelled'=>['ملغي','danger','times-circle'],'failed'=>['فشل','danger','exclamation-circle']];
include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(245,158,11,0.15)"><i class="fas fa-shopping-bag" style="color:var(--gold)"></i></div>
      إدارة الطلبات
    </div>
    <div class="page-header-sub">إجمالي <?= number_format($counts['']) ?> طلب</div>
  </div>
</div>

<!-- Status Tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">
  <?php
  $tabs=[''=> ['الكل','secondary'],  'pending'=>['معلقة','warning'], 'processing'=>['تنفيذ','info'],
         'completed'=>['مكتملة','success'],'cancelled'=>['ملغية','danger']];
  foreach ($tabs as $s=>[$lbl,$cls]):
    $active = $status===$s;
  ?>
  <a href="orders.php<?= $s?"?status=$s":'' ?>"
     class="btn btn-<?= $active?$cls:'secondary' ?> btn-sm"
     style="<?= $active?"box-shadow:0 4px 14px rgba(0,0,0,0.3)":'' ?>">
    <?= $lbl ?>
    <span style="background:rgba(0,0,0,0.2);padding:1px 7px;border-radius:20px;font-size:11px"><?= $counts[$s] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Table Card -->
<div class="card">
  <div class="filter-bar" style="display:block;padding:12px 14px">
    <form method="GET" id="ordersFilterForm">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <div class="filter-search" style="flex:1 1 280px;max-width:420px">
          <i class="fas fa-search filter-search-icon"></i>
          <input type="text" id="orderSearch" name="q" value="<?=htmlspecialchars($q)?>"
                 placeholder="بحث شامل: ID أو العميل أو الخدمة أو رقم المزود..." oninput="filterOrders()">
        </div>
        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleOrderFilters()" aria-expanded="<?= $advancedFilterActive ? 'true' : 'false' ?>">
          <i class="fas fa-sliders-h"></i> خيارات بحث متقدمة
          <?php if($advancedFilterActive): ?><span style="background:var(--primary);color:#fff;border-radius:12px;padding:1px 6px;font-size:10px">نشطة</span><?php endif; ?>
        </button>
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> تطبيق البحث</button>
        <a href="orders.php<?=$status?"?status=".urlencode($status):''?>" class="btn btn-secondary btn-sm" title="مسح جميع الفلاتر"><i class="fas fa-undo"></i> مسح</a>
        <div style="margin-right:auto;color:var(--text3);font-size:12px;white-space:nowrap"><?= count($orders) ?> نتيجة</div>
      </div>

      <?php if ($q !== '' && !$advancedFilterActive): ?>
      <div style="font-size:.75rem;color:var(--cyan);display:flex;align-items:center;gap:6px;margin-top:8px">
        <i class="fas fa-search"></i> نتائج البحث عن: "<?=htmlspecialchars($q)?>" — يشمل كل الحالات والتواريخ
      </div>
      <?php endif; ?>

      <div id="advancedOrderFilters" style="display:<?= $advancedFilterActive ? 'block' : 'none' ?>;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px">
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-user"></i> العميل</label>
            <input type="text" name="customer" value="<?=htmlspecialchars($customer)?>" class="form-control" placeholder="اسم المستخدم، الاسم، البريد أو الهاتف">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-user-check"></i> اختيار عميل محدد</label>
            <select name="customer_id" class="form-control">
              <option value="0">كل العملاء</option>
              <?php foreach($filterCustomers as $fc): ?><option value="<?=$fc['id']?>" <?= $customerId===(int)$fc['id'] ? 'selected' : '' ?>>#<?=$fc['id']?> — <?=htmlspecialchars($fc['username'])?><?=($fc['full_name'] ? ' — '.htmlspecialchars($fc['full_name']) : '')?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-tags"></i> مجموعة التسعير</label>
            <select name="pricing_group_id" class="form-control">
              <option value="0">كل مجموعات التسعير</option>
              <?php foreach($filterPricingGroups as $pg): ?><option value="<?=$pg['id']?>" <?= $pricingGroupFilter===(int)$pg['id'] ? 'selected' : '' ?>><?=htmlspecialchars($pg['name'])?><?=empty($pg['status']) ? ' (موقوفة)' : ''?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-user-shield"></i> حالة حساب العميل</label>
            <select name="customer_status" class="form-control">
              <option value="">كل الحالات</option>
              <option value="1" <?= $customerStatus==='1' ? 'selected' : '' ?>>نشط</option>
              <option value="0" <?= $customerStatus==='0' ? 'selected' : '' ?>>موقوف</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-database"></i> قيمة البيانات المطلوبة</label>
            <input type="text" name="data_search" value="<?=htmlspecialchars($dataSearch)?>" class="form-control" placeholder="رقم، رابط، اسم أو أي قيمة أدخلها العميل">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-tag"></i> اسم حقل البيانات</label>
            <input type="text" name="data_key" value="<?=htmlspecialchars($dataKey)?>" class="form-control" placeholder="مثال: player_id أو username">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-hashtag"></i> رقم الطلب الداخلي</label>
            <input type="number" name="id" value="<?= $orderId ?: '' ?>" class="form-control" min="1" placeholder="مثال: 1250">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-exchange-alt"></i> حالة الطلب</label>
            <select name="status" class="form-control">
              <?php foreach([''=>'كل الحالات','pending'=>'قيد الانتظار','processing'=>'قيد التنفيذ','completed'=>'مكتمل','cancelled'=>'ملغي','failed'=>'فشل'] as $sv=>$sl): ?>
                <option value="<?=htmlspecialchars($sv)?>" <?= $status===$sv ? 'selected' : '' ?>><?=htmlspecialchars($sl)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-globe"></i> مصدر الطلب</label>
            <select name="source" class="form-control">
              <option value="">كل المصادر</option>
              <option value="web" <?= $sourceFilter==='web' ? 'selected' : '' ?>>الموقع</option>
              <option value="reseller_api" <?= $sourceFilter==='reseller_api' ? 'selected' : '' ?>>ربط API</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-folder"></i> القسم</label>
            <select name="category_id" class="form-control">
              <option value="0">كل الأقسام</option>
              <?php foreach($filterCategories as $cat): ?><option value="<?=$cat['id']?>" <?= $categoryFilter===(int)$cat['id'] ? 'selected' : '' ?>><?=htmlspecialchars($cat['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-concierge-bell"></i> الخدمة</label>
            <select name="service_id" class="form-control">
              <option value="0">كل الخدمات</option>
              <?php foreach($filterServices as $svc): ?><option value="<?=$svc['id']?>" <?= $serviceFilter===(int)$svc['id'] ? 'selected' : '' ?>>#<?=$svc['id']?> — <?=htmlspecialchars($svc['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-layer-group"></i> نوع الخدمة</label>
            <select name="service_type" class="form-control">
              <option value="">كل أنواع الخدمات</option>
              <option value="default" <?= $serviceTypeFilter==='default' ? 'selected' : '' ?>>عادية</option>
              <option value="numbers_live" <?= $serviceTypeFilter==='numbers_live' ? 'selected' : '' ?>>أرقام مباشرة</option>
              <option value="numbers_pack" <?= $serviceTypeFilter==='numbers_pack' ? 'selected' : '' ?>>باقة أرقام</option>
              <option value="numbers_store" <?= $serviceTypeFilter==='numbers_store' ? 'selected' : '' ?>>مخزن أرقام</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-plug"></i> المزود</label>
            <select name="provider_id" class="form-control">
              <option value="0">كل المزودين</option>
              <?php foreach($filterProviders as $prov): ?><option value="<?=$prov['id']?>" <?= $providerFilter===(int)$prov['id'] ? 'selected' : '' ?>>#<?=$prov['id']?> — <?=htmlspecialchars($prov['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-key"></i> رقم عملية المزود</label>
            <input type="text" name="provider_order_id" value="<?=htmlspecialchars($providerOrder)?>" class="form-control" placeholder="رقم المزود الخارجي">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-link"></i> رقم المرجع أو API</label>
            <input type="text" name="reference" value="<?=htmlspecialchars($referenceFilter)?>" class="form-control" placeholder="ref_id أو API ID أو UUID">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-ticket-alt"></i> كود الكوبون</label>
            <input type="text" name="coupon" value="<?=htmlspecialchars($couponFilter)?>" class="form-control" placeholder="ابحث بكود الخصم">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-percent"></i> حالة الكوبون</label>
            <select name="coupon_state" class="form-control">
              <option value="">كل الطلبات</option>
              <option value="with" <?= $couponState==='with' ? 'selected' : '' ?>>بكوبون</option>
              <option value="without" <?= $couponState==='without' ? 'selected' : '' ?>>بدون كوبون</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-sticky-note"></i> الملاحظات</label>
            <input type="text" name="notes" value="<?=htmlspecialchars($notesSearch)?>" class="form-control" placeholder="ملاحظات الإدارة أو الحالة">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-plug"></i> حالة رقم المزود</label>
            <select name="provider_link" class="form-control">
              <option value="">كل الطلبات</option>
              <option value="with" <?= $providerLinkState==='with' ? 'selected' : '' ?>>لها رقم مزود</option>
              <option value="without" <?= $providerLinkState==='without' ? 'selected' : '' ?>>بدون رقم مزود</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-calendar-alt"></i> من تاريخ</label>
            <input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>" class="form-control">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-calendar-check"></i> إلى تاريخ</label>
            <input type="date" name="date_to" value="<?=htmlspecialchars($dateTo)?>" class="form-control">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-sort-numeric-up"></i> أقل كمية</label>
            <input type="number" name="quantity_min" value="<?=htmlspecialchars($quantityMinRaw)?>" class="form-control" min="1" step="1" placeholder="1">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-sort-numeric-down"></i> أعلى كمية</label>
            <input type="number" name="quantity_max" value="<?=htmlspecialchars($quantityMaxRaw)?>" class="form-control" min="1" step="1" placeholder="بدون حد">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-money-bill-wave"></i> أقل مبلغ</label>
            <input type="number" name="amount_min" value="<?=htmlspecialchars($amountMinRaw)?>" class="form-control" min="0" step="0.000001" placeholder="0">
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-money-bill-wave"></i> أعلى مبلغ</label>
            <input type="number" name="amount_max" value="<?=htmlspecialchars($amountMaxRaw)?>" class="form-control" min="0" step="0.000001" placeholder="بدون حد">
          </div>
          <?php if($filterCashboxes): ?><div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-wallet"></i> الصندوق</label>
            <select name="cashbox_id" class="form-control"><option value="0">كل الصناديق</option><?php foreach($filterCashboxes as $box): ?><option value="<?=$box['id']?>" <?= $cashboxFilter===(int)$box['id'] ? 'selected' : '' ?>><?=htmlspecialchars($box['name'])?> (<?=htmlspecialchars($box['currency_code'])?>)</option><?php endforeach; ?></select>
          </div><?php endif; ?>
          <?php if($filterStaff): ?><div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-user-tie"></i> الموظف المرتبط</label>
            <select name="staff_id" class="form-control"><option value="0">كل الموظفين</option><?php foreach($filterStaff as $emp): ?><option value="<?=$emp['id']?>" <?= $staffFilter===(int)$emp['id'] ? 'selected' : '' ?>><?=htmlspecialchars($emp['full_name'] ?: $emp['username'])?></option><?php endforeach; ?></select>
          </div><?php endif; ?>
          <?php if($filterCashboxes): ?><div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-receipt"></i> حالة الترحيل للصندوق</label>
            <select name="posting_status" class="form-control"><option value="">كل الحالات</option><option value="posted" <?= $postingFilter==='posted' ? 'selected' : '' ?>>مرحّل</option><option value="awaiting_cost" <?= $postingFilter==='awaiting_cost' ? 'selected' : '' ?>>بانتظار التكلفة</option><option value="skipped" <?= $postingFilter==='skipped' ? 'selected' : '' ?>>تم تخطيه</option><option value="failed" <?= $postingFilter==='failed' ? 'selected' : '' ?>>فشل الترحيل</option></select>
          </div><?php endif; ?>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-list-ol"></i> عدد النتائج</label>
            <select name="per_page" class="form-control"><?php foreach($limitOptions as $limit): ?><option value="<?=$limit?>" <?= $perPage===$limit ? 'selected' : '' ?>><?=$limit?> طلب</option><?php endforeach; ?></select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-size:.72rem"><i class="fas fa-sort-amount-down"></i> ترتيب التاريخ</label>
            <select name="sort" class="form-control"><option value="desc" <?= $sortDirection==='DESC' ? 'selected' : '' ?>>الأحدث أولاً</option><option value="asc" <?= $sortDirection==='ASC' ? 'selected' : '' ?>>الأقدم أولاً</option></select>
          </div>
        </div>
      </div>
    </form>
  </div>

  <div class="table-wrap">
    <table id="ordersTable">
      <thead>
        <tr>
          <th>#</th>
          <th>العميل</th>
          <th>الخدمة / المزود</th>
          <th>البيانات المطلوبة</th>
          <th>الكمية</th>
          <th>المبلغ</th>
          <th>المصدر</th>
          <th>الحالة</th>
          <th>التاريخ</th>
          <th>إجراءات</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($orders): foreach ($orders as $o):
          $st = $statusMap[$o['status']] ?? [$o['status'],'secondary','question'];
        ?>
        <?php
          // استخراج بيانات الطلب من field_data
          $fieldData = [];
          if (!empty($o['field_data'])) {
              $fd = json_decode($o['field_data'], true);
              if (is_array($fd)) $fieldData = $fd;
          }
          $uname = preg_replace('/__deleted__\d+$/', '', $o['username'] ?? '');
          $isDeleted = !empty($o['is_deleted']);
        ?>
        <tr data-search="<?= strtolower(htmlspecialchars($uname . ' ' . $o['service_name'] . ' ' . $o['id'] . ' ' . ($o['ref_id'] ?? '') . ' ' . ($o['api_order_id'] ?? ''))) ?>">
          <td class="td-id" style="font-family:monospace;font-size:.78rem">
            <?= htmlspecialchars(formatOrderId($o)) ?>
            <?php if (!empty($o['api_order_id']) && $o['api_order_id'] !== ($o['ref_id'] ?? '')): ?>
            <div class="td-muted" style="font-size:9px;margin-top:2px" title="معرّف API الخارجي">API: <?= htmlspecialchars($o['api_order_id']) ?></div>
            <?php endif; ?>
          </td>

          <!-- العميل -->
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:30px;height:30px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;flex-shrink:0"><?= mb_strtoupper(mb_substr($uname,0,1)) ?></div>
              <div>
                <div class="td-bold"><?= htmlspecialchars($uname) ?><?= $isDeleted?' <span style="color:#ff4455;font-size:10px">[محذوف]</span>':'' ?></div>
                <a href="customers.php?action=view&id=<?=$o['user_id']?>" style="font-size:10px;color:var(--text3)">#<?=$o['user_id']?></a>
              </div>
            </div>
          </td>

          <!-- الخدمة / المزود -->
          <td style="max-width:180px">
            <div class="td-bold truncate"><?= htmlspecialchars($o['service_name']) ?>
              <?php if(!empty($o['service_deleted_at'])): ?><span style="font-size:.65rem;color:#ff6b6b;background:rgba(255,68,85,.12);padding:1px 5px;border-radius:4px">محذوفة</span><?php endif; ?></div>
            <?php if (!empty($o['provider_name'])): ?>
            <div style="font-size:11px;margin-top:2px">
              <span style="background:rgba(0,212,170,.12);color:#00d4aa;padding:1px 7px;border-radius:10px;font-size:10px">
                <i class="fas fa-plug" style="font-size:9px"></i>
                <?= htmlspecialchars($o['provider_name']) ?>
              </span>
            </div>
            <?php else: ?>
            <div style="font-size:10px;color:var(--text3);margin-top:2px">
              <span style="background:rgba(245,166,35,.1);color:#f5a623;padding:1px 7px;border-radius:10px">يدوي</span>
            </div>
            <?php endif; ?>
            <?php if ($o['provider_order_id']): ?>
            <div class="td-muted code" style="font-size:10px;margin-top:2px"><?= htmlspecialchars($o['provider_order_id']) ?></div>
            <a href="?check_oranos=1&id=<?=$o['id']?>" class="btn btn-sm" style="font-size:10px;padding:2px 6px;background:rgba(0,212,170,0.15);color:#00d4aa;border:1px solid rgba(0,212,170,0.3)" title="فحص الحالة"><i class="fas fa-sync-alt"></i></a>
            <?php endif; ?>
            <?php if (!empty($o['delivered_code'])): ?>
            <div style="font-family:monospace;font-size:10px;margin-top:2px;background:rgba(0,200,83,.1);color:#00c853;border:1px solid rgba(0,200,83,.25);border-radius:5px;padding:2px 6px;display:inline-block" title="كود من المخزون"><i class="fas fa-key" style="font-size:9px"></i> <?= htmlspecialchars($o['delivered_code']) ?></div>
            <?php endif; ?>
          </td>

          <!-- البيانات المطلوبة من العميل -->
          <td style="max-width:160px">
            <?php if (!empty($fieldData)): ?>
            <div style="display:flex;flex-direction:column;gap:3px">
              <?php foreach($fieldData as $fKey => $fVal): if(empty($fVal)) continue; ?>
              <div style="font-size:11px">
                <span style="color:var(--text3)"><?= htmlspecialchars($fKey) ?>:</span>
                <span class="td-bold" style="font-size:12px;color:var(--cyan)"><?= htmlspecialchars($fVal) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span style="color:var(--text3);font-size:11px">—</span>
            <?php endif; ?>
          </td>

          <!-- الكمية -->
          <td>
            <span class="td-bold" style="font-size:13px"><?= number_format((float)$o['quantity']) ?></span>
            <div class="td-muted" style="font-size:10px">وحدة × <?= formatMoney($o['unit_price']) ?></div>
          </td>

          <!-- المبلغ -->
          <td><span style="color:var(--green);font-weight:900;font-size:15px"><?= formatMoney($o['total_price']) ?></span></td>

          <!-- المصدر -->
          <td>
            <?php if (($o['source'] ?? 'web') === 'reseller_api'): ?>
            <span style="background:rgba(124,58,237,.12);color:#a78bfa;padding:2px 8px;border-radius:10px;font-size:10px;white-space:nowrap">
              <i class="fas fa-plug" style="font-size:9px"></i> ربط API
            </span>
            <?php else: ?>
            <span style="background:rgba(0,150,255,.1);color:#4dabf7;padding:2px 8px;border-radius:10px;font-size:10px;white-space:nowrap">
              <i class="fas fa-globe" style="font-size:9px"></i> الموقع
            </span>
            <?php endif; ?>
          </td>

          <!-- الحالة -->
          <td>
            <span class="badge badge-<?= $st[1] ?>">
              <i class="fas fa-<?= $st[2] ?>"></i> <?= $st[0] ?>
            </span>
          </td>

          <!-- التاريخ -->
          <td class="td-muted" style="font-size:11px;white-space:nowrap"><?= date('d/m H:i', strtotime($o['created_at'])) ?></td>

          <!-- إجراءات -->
          <td>
            <button class="btn btn-secondary btn-xs" onclick="openOrderModal(<?= $o['id'] ?>,
              '<?= addslashes(htmlspecialchars($uname)) . ($isDeleted?' [محذوف]':'') ?>',
              '<?= addslashes(htmlspecialchars($o['service_name'])) ?>',
              '<?= $o['status'] ?>',
              '<?= addslashes(htmlspecialchars($o['admin_notes']??'')) ?>',
              '<?= addslashes(htmlspecialchars($o['provider_response']??'')) ?>',
              '<?= addslashes(htmlspecialchars($o['status_message']??'')) ?>',
              '<?= addslashes(htmlspecialchars($o['notes']??'')) ?>',
              '<?= addslashes(htmlspecialchars(formatOrderId($o))) ?>',
              '<?= number_format((float)$o['quantity']) ?>',
              '<?= addslashes(formatMoney($o['unit_price'])) ?> / <?= addslashes(formatMoney($o['total_price'])) ?>'
            )"><i class="fas fa-edit"></i> تعديل</button>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="10"><div class="empty-state"><span class="empty-state-icon">📭</span><div class="empty-state-title">لا توجد طلبات</div></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">عرض <?= count($orders) ?> طلب <?= $status ? '('.($statusMap[$status][0]??$status).')' : '' ?> — الإجمالي: <?= $counts[''] ?></div>
</div>

<!-- Update Order Modal -->
<div class="modal-overlay" id="orderModal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-edit" style="color:var(--primary)"></i> تحديث حالة الطلب <span id="modalOrderId"></span></div>
      <button class="modal-close" onclick="closeOrderModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;padding:14px;background:var(--card2);border-radius:var(--radius-sm)">
        <div><div class="text-xs text-muted mb-1">العميل</div><div class="td-bold" id="modalUser"></div></div>
        <div><div class="text-xs text-muted mb-1">الخدمة</div><div class="td-bold" id="modalSvc"></div></div>
        <div><div class="text-xs text-muted mb-1">الكمية</div><div class="td-bold" id="modalQty"></div></div>
        <div><div class="text-xs text-muted mb-1">السعر (وحدة / إجمالي)</div><div class="td-bold" id="modalPrice"></div></div>
        <div style="grid-column:1/-1"><div class="text-xs text-muted mb-1">رقم المرجع</div><div class="td-bold" id="modalRef" style="font-family:monospace"></div></div>
      </div>

      <!-- رد المزود -->
      <div id="modalProviderBlock" style="display:none;margin-bottom:14px;border-radius:10px;overflow:hidden;border:1px solid var(--border)">
        <div style="background:rgba(0,212,170,.08);padding:8px 12px;font-size:.78rem;font-weight:700;color:#00d4aa;border-bottom:1px solid var(--border)">
          <i class="fas fa-plug"></i> رد المزود
        </div>
        <div style="padding:10px 12px;display:flex;flex-direction:column;gap:8px">
          <div id="modalProviderResponse" style="display:none">
            <div style="font-size:.72rem;color:var(--text3);margin-bottom:4px">استجابة المزود (provider_response):</div>
            <div id="modalProviderResponseVal" style="background:var(--bg);border-radius:8px;padding:8px 10px;font-family:monospace;font-size:.75rem;color:#00d4aa;word-break:break-all;max-height:120px;overflow-y:auto"></div>
          </div>
          <div id="modalStatusMessage" style="display:none">
            <div style="font-size:.72rem;color:var(--text3);margin-bottom:4px">رسالة الحالة (status_message):</div>
            <div id="modalStatusMessageVal" style="background:var(--bg);border-radius:8px;padding:8px 10px;font-size:.82rem;color:#f5a623"></div>
          </div>
          <div id="modalNotes2" style="display:none">
            <div style="font-size:.72rem;color:var(--text3);margin-bottom:4px">ملاحظات (notes):</div>
            <div id="modalNotes2Val" style="background:var(--bg);border-radius:8px;padding:8px 10px;font-size:.82rem;color:var(--text2)"></div>
          </div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="order_id" id="modalOrderIdInput">
        <div class="form-group">
          <label><i class="fas fa-tag"></i> الحالة الجديدة</label>
          <select name="new_status" id="modalStatus" class="form-control">
            <option value="pending">⏳ قيد الانتظار</option>
            <?php if ($IS_ADMIN || canAccess($pdo,'perm_orders_process')): ?>
            <option value="processing">🔄 قيد التنفيذ</option>
            <?php endif; ?>
            <?php if ($IS_ADMIN || canAccess($pdo,'perm_orders_complete')): ?>
            <option value="completed">✅ مكتمل</option>
            <?php endif; ?>
            <?php if ($IS_ADMIN || canAccess($pdo,'perm_orders_cancel')): ?>
            <option value="cancelled">❌ ملغي (مع استرداد الرصيد)</option>
            <option value="failed">⚠️ فشل (مع استرداد الرصيد)</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="form-group">
          <label><i class="fas fa-comment-dots"></i> رسالة للعميل <span style="font-size:.72rem;color:#8895a7">(تظهر في تفاصيل الطلب)</span></label>
          <textarea name="admin_notes" id="modalNotes" class="form-control" rows="3" placeholder="مثال: جارٍ التجهيز، سيتم الإكمال خلال 30 دقيقة..."></textarea>
          <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
            <button type="button" class="btn btn-xs btn-secondary" onclick="setNote('جارٍ التجهيز، سيتم الإكمال قريباً')">قيد التجهيز</button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="setNote('تم تنفيذ الطلب بنجاح ✓')">تم التنفيذ</button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="setNote('حدث خطأ في الطلب، تم استرداد الرصيد')">خطأ + استرداد</button>
            <button type="button" class="btn btn-xs btn-secondary" onclick="setNote('الطلب قيد المراجعة من المزود')">مراجعة</button>
          </div>
        </div>
        <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2);border-radius:var(--radius-sm);padding:10px 14px;font-size:12px;color:var(--gold);margin-bottom:16px">
          <i class="fas fa-info-circle"></i> عند الإلغاء أو الفشل يتم إعادة المبلغ تلقائياً لرصيد العميل
        </div>
        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ التغييرات</button>
      </form>
    </div>
  </div>
</div>

<script>
function setNote(txt) {
  document.getElementById('modalNotes').value = txt;
}
function openOrderModal(id, user, svc, status, notes, provResp, statusMsg, notesRaw, refId, qty, priceStr) {
  document.getElementById('modalOrderId').textContent = refId ? refId.toUpperCase() : ('#'+String(id).padStart(5,'0'));
  document.getElementById('modalOrderIdInput').value = id;
  document.getElementById('modalUser').textContent = user;
  document.getElementById('modalSvc').textContent = svc;
  document.getElementById('modalQty').textContent = qty || '-';
  document.getElementById('modalPrice').textContent = priceStr || '-';
  document.getElementById('modalRef').textContent = refId || '-';
  document.getElementById('modalStatus').value = status;
  document.getElementById('modalNotes').value = notes;

  // رد المزود
  var hasProvider = provResp || statusMsg || notesRaw;
  var block = document.getElementById('modalProviderBlock');
  if (block) block.style.display = hasProvider ? 'block' : 'none';

  var pr = document.getElementById('modalProviderResponse');
  var prv = document.getElementById('modalProviderResponseVal');
  if (pr && prv) { pr.style.display = provResp ? 'block' : 'none'; prv.textContent = provResp || ''; }

  var sm = document.getElementById('modalStatusMessage');
  var smv = document.getElementById('modalStatusMessageVal');
  if (sm && smv) { sm.style.display = statusMsg ? 'block' : 'none'; smv.textContent = statusMsg || ''; }

  var n2 = document.getElementById('modalNotes2');
  var n2v = document.getElementById('modalNotes2Val');
  if (n2 && n2v) { n2.style.display = notesRaw ? 'block' : 'none'; n2v.textContent = notesRaw || ''; }

  document.getElementById('orderModal').classList.add('open');
}
function closeOrderModal() {
  document.getElementById('orderModal').classList.remove('open');
}
document.getElementById('orderModal').addEventListener('click', function(e) {
  if (e.target === this) closeOrderModal();
});
function toggleOrderFilters() {
  const panel = document.getElementById('advancedOrderFilters');
  const button = document.querySelector('[onclick="toggleOrderFilters()"]');
  if (!panel) return;
  const open = panel.style.display !== 'none';
  panel.style.display = open ? 'none' : 'block';
  if (button) button.setAttribute('aria-expanded', open ? 'false' : 'true');
}
function filterOrders() {
  const q = document.getElementById('orderSearch').value.toLowerCase();
  document.querySelectorAll('#ordersTable tbody tr[data-search]').forEach(r => {
    r.style.display = r.dataset.search.includes(q) ? '' : 'none';
  });
}
</script>
<?php include 'footer.php'; ?>

<?php
// توليد ref_id للطلبات القديمة التي ليس لها ref_id
if (isset($_GET['gen_refs']) && isAdmin()) {
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL");
        $old = $pdo->query("SELECT id FROM orders WHERE ref_id IS NULL OR ref_id=''")->fetchAll();
        $prefix = 'id';
        foreach ($old as $o) {
            $ref = $prefix . '_' . bin2hex(random_bytes(8));
            $pdo->prepare("UPDATE orders SET ref_id=? WHERE id=?")->execute([$ref, $o['id']]);
        }
        echo count($old) . ' orders updated'; exit;
    } catch(Exception $e) { echo $e->getMessage(); exit; }
}
?>
