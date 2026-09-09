<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#070b14">
<title><?= htmlspecialchars($pageTitle ?? 'لوحة الإدارة') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<!-- تم تحويلها من cdnjs.cloudflare.com (محجوب أحياناً بدون VPN عند بعض مزودي الإنترنت اليمنيين)
     إلى استضافة محلية على نفس السيرفر لضمان ظهور الأيقونات دائماً دون اعتماد على شبكة خارجية -->
<link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<?php
$curPage        = basename($_SERVER['PHP_SELF']);
$pendingOrders  = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();

// ── المزامنة تعمل فقط من صفحة الطلبات ──────────────────────────────────
$pendingDevices = 0;
$pendingTopups = 0;
try {
    $pt = $pdo->query("SELECT COUNT(*) FROM topup_requests WHERE status='pending'");
    $pendingTopups = (int)$pt->fetchColumn();
} catch(\PDOException $e) {}
try { $pendingDevices = (int)$pdo->query("SELECT COUNT(*) FROM user_devices WHERE status='pending'")->fetchColumn(); } catch(Exception $e){}
$adminUser    = getUser();
$adminName    = $adminUser ? ($adminUser['full_name'] ?: $adminUser['username']) : 'Admin';
$adminInitial = mb_strtoupper(mb_substr($adminName, 0, 1));
$adminRole    = $_SESSION['role'] ?? 'staff';
$siteName     = getSetting('site_name') ?: SITE_NAME;

// صلاحيات القائمة الجانبية
$IS_ADMIN = isAdmin();

// دالة مساعدة لعرض روابط الناف بار حسب الصلاحية
if (!function_exists('navLink')) {
function navLink($url, $page, $curPage, $icon, $label, $badgeCount=0, $badgeColor='var(--gold)') {
    $active = ($curPage === $page) ? 'active' : '';
    $badge  = $badgeCount > 0 ? "<span class=\"nav-link-badge\" style=\"background:$badgeColor\">$badgeCount</span>" : '';
    echo "<a href=\"$url\" class=\"nav-link $active\">
      <div class=\"nav-link-icon\"><i class=\"fas fa-$icon\"></i></div>
      <span class=\"nav-link-label\">$label</span>$badge
    </a>";
}
} // end function_exists

// رابط مع tab parameter
if (!function_exists('navLinkTab')) {
function navLinkTab($url, $page, $curPage, $curTab, $matchTab, $icon, $label, $badgeCount=0, $badgeColor='var(--gold)') {
    $active = ($curPage === $page && $curTab === $matchTab) ? 'active' : '';
    $badge  = $badgeCount > 0 ? "<span class=\"nav-link-badge\" style=\"background:$badgeColor\">$badgeCount</span>" : '';
    echo "<a href=\"$url\" class=\"nav-link $active\">
      <div class=\"nav-link-icon\"><i class=\"fas fa-$icon\"></i></div>
      <span class=\"nav-link-label\">$label</span>$badge
    </a>";
}
}
?>
<div class="admin-shell">

<aside class="admin-sidebar" id="adminSidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-icon">🚀</div>
    <div class="sidebar-logo-text">
      <div class="sidebar-logo-name"><?= htmlspecialchars($siteName) ?></div>
      <div class="sidebar-logo-sub"><?= $IS_ADMIN ? 'لوحة الإدارة' : 'لوحة الموظف' ?></div>
    </div>
  </div>

  <div class="sidebar-admin-badge">
    <div class="admin-avatar"><?= $adminInitial ?></div>
    <div class="admin-badge-info">
      <div class="admin-badge-name"><?= htmlspecialchars(mb_substr($adminName,0,18)) ?></div>
      <div class="admin-badge-role">
        <?php if($IS_ADMIN): ?>
        <i class="fas fa-shield-alt" style="color:#f5a623"></i> مدير النظام
        <?php else: ?>
        <i class="fas fa-user-tie" style="color:#a78bfa"></i> موظف
        <?php endif; ?>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">

    <!-- ── الرئيسية ─────────────────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_dashboard')): ?>
    <div class="sidebar-section">الرئيسية</div>
    <?php navLink(SITE_URL.'/admin/', 'index.php', $curPage, 'chart-line', 'لوحة التحكم'); ?>
    <?php endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/reports.php','reports.php',$curPage,'chart-bar','التقارير الشاملة'); endif; ?>

    <!-- ── المحتوى ──────────────────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_categories_view') || canAccess($pdo,'perm_services_view')): ?>
    <div class="sidebar-section">المحتوى</div>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_categories_view')): navLink(SITE_URL.'/admin/categories.php','categories.php',$curPage,'folder-open','الأقسام'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_services_view')): navLink(SITE_URL.'/admin/services.php','services.php',$curPage,'box','الخدمات'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/import_services.php','import_services.php',$curPage,'cloud-download-alt','استيراد خدمات'); endif; ?>
    <?php endif; ?>

    <!-- ── المستخدمون ───────────────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_customers_view') || canAccess($pdo,'perm_staff')): ?>
    <div class="sidebar-section">المستخدمون</div>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_customers_view')): navLink(SITE_URL.'/admin/customers.php','customers.php',$curPage,'users','العملاء',$pendingDevices,'#f5a623'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/pricing_groups.php','pricing_groups.php',$curPage,'tags','مجموعات التسعير'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_staff')): navLink(SITE_URL.'/admin/staff.php','staff.php',$curPage,'user-tie','الموظفون'); endif; ?>
    <?php endif; ?>

    <!-- ── المبيعات ──────────────────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_orders_view')): ?>
    <div class="sidebar-section">المبيعات</div>
    <?php navLink(SITE_URL.'/admin/orders.php','orders.php',$curPage,'shopping-bag','الطلبات',$pendingOrders,'var(--gold)'); ?>
    <?php
    $pendingObjections = 0;
    try { $pendingObjections = (int)$pdo->query("SELECT COUNT(*) FROM order_objections WHERE status='pending'")->fetchColumn(); } catch(Exception $e){}
    ?>
    <?php navLink(SITE_URL.'/admin/order_objections.php','order_objections.php',$curPage,'flag','اعتراضات العمليات',$pendingObjections,'#ff6677'); ?>
    <?php
    $p2pActive = 0; $p2pAlert = 0;
    try { $p2pActive = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status IN ('active','delivered')")->fetchColumn(); } catch(Exception $e){}
    try { $p2pAlert = (int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='disputed'")->fetchColumn(); } catch(Exception $e){}
    $p2pBadge = $p2pActive + $p2pAlert;
    $p2pColor = $p2pAlert ? '#ff4455' : ($p2pActive ? '#8b5cf6' : 'var(--gold)');
    ?>
    <?php navLink(SITE_URL.'/admin/p2p.php','p2p.php',$curPage,'handshake','سوق P2P',$p2pBadge,$p2pColor); ?>
    <?php endif; ?>

    <!-- ── وكلاء الشحن والخدمات ─────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): ?>
    <?php $curTab = $_GET['tab'] ?? ''; ?>
    <div class="sidebar-section">وكلاء الشحن والخدمات</div>
    <?php navLink(SITE_URL.'/admin/telecom_agents.php','telecom_agents.php',$curPage,'satellite-dish','وكلاء الشحن'); ?>
    <?php navLink(SITE_URL.'/admin/agent_distributions.php','agent_distributions.php',$curPage,'random','التوزيع برو'); ?>
    <?php navLink(SITE_URL.'/admin/sadad.php','sadad.php',$curPage,'list-alt','قائمة سداد'); ?>
    <?php navLink(SITE_URL.'/admin/yemen_robot.php','yemen_robot.php',$curPage,'robot','Yemen Robot'); ?>
    <?php navLink(SITE_URL.'/admin/floosak.php','floosak.php',$curPage,'sim-card','Floosak'); ?>
    <?php endif; ?>

    <!-- ── واتساب ────────────────────────────────────────── -->
    <?php if ($IS_ADMIN): ?>
    <div class="sidebar-section" style="display:flex;align-items:center;gap:6px">
      <i class="fab fa-whatsapp" style="color:#25d366;font-size:.8rem"></i> واتساب
    </div>
    <?php navLink(SITE_URL.'/admin/whatsapp.php','whatsapp.php',$curPage,'whatsapp','إدارة واتساب'); ?>
    <?php navLink(SITE_URL.'/admin/whatsapp_inbox.php','whatsapp_inbox.php',$curPage,'inbox','الصندوق الوارد'); ?>
    <?php navLink(SITE_URL.'/admin/whatsapp.php?tab=groups','whatsapp.php',$curPage,'users','المجموعات'); ?>
    <?php navLink(SITE_URL.'/admin/whatsapp.php?tab=single','whatsapp.php',$curPage,'comment','إرسال رسالة'); ?>
    <?php navLink(SITE_URL.'/admin/whatsapp.php?tab=bulk','whatsapp.php',$curPage,'bullhorn','رسائل جماعية'); ?>
    <?php endif; ?>

    <!-- ── النظام ────────────────────────────────────────── -->
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_providers_view') || canAccess($pdo,'perm_settings')): ?>
    <div class="sidebar-section">النظام</div>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_providers_view')): navLink(SITE_URL.'/admin/providers.php','providers.php',$curPage,'plug','مزودو API'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_customers_view')): navLink(SITE_URL.'/admin/kyc.php','kyc.php',$curPage,'id-card','تحقق الهوية', (int)($pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn()), '#00d4aa'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): navLink(SITE_URL.'/admin/banners.php','banners.php',$curPage,'images','السلايدر'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): navLink(SITE_URL.'/admin/settings.php','settings.php',$curPage,'sliders-h','الإعدادات'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): navLink(SITE_URL.'/admin/password_reset.php','password_reset.php',$curPage,'key','استعادة كلمة المرور'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): navLink(SITE_URL.'/admin/payments.php?tab=requests','payments.php',$curPage,'money-bill-wave','طرق الدفع', $pendingTopups, 'var(--green)'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/currencies.php','currencies.php',$curPage,'coins','عملات العرض'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/recharge_cards.php','recharge_cards.php',$curPage,'ticket-alt','بطاقات الشحن'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_settings')): navLink(SITE_URL.'/admin/wallet_ledger.php','wallet_ledger.php',$curPage,'wallet','سجل المعاملات'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/sms_topup.php','sms_topup.php',$curPage,'sms','شحن SMS'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/admin_telecom.php','admin_telecom.php',$curPage,'sim-card','كبينة السداد', $stats['pending']??0, '#00d4ff'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/admin_notifications.php','admin_notifications.php',$curPage,'paper-plane','الإشعارات'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/push_notifications.php','push_notifications.php',$curPage,'bell','Push Notifications'); endif; ?>
    <?php if ($IS_ADMIN): $maintOn = getSetting('maintenance_mode')==='1'; navLink(SITE_URL.'/admin/maintenance.php','maintenance.php',$curPage,'tools','الصيانة', $maintOn?'ON':'', $maintOn?'#ff4455':''); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/quizzes.php','quizzes.php',$curPage,'trophy','المسابقات'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/referrals.php','referrals.php',$curPage,'users','الإحالة'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/faq.php','faq.php',$curPage,'question-circle','الأسئلة الشائعة'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/coupons.php','coupons.php',$curPage,'ticket-alt','كوبونات الخصم'); endif; ?>
    <?php if ($IS_ADMIN || canAccess($pdo,'perm_customers_view')): navLink(SITE_URL.'/admin/chat.php','chat.php',$curPage,'comment-dots','المحادثات'); endif; ?>
    <?php if ($IS_ADMIN): navLink(SITE_URL.'/admin/contact_buttons.php','contact_buttons.php',$curPage,'comments','أزرار التواصل'); endif; ?>
    <?php endif; ?>

    <a href="<?= SITE_URL ?>/mobile.php" class="nav-link" target="_blank">
      <div class="nav-link-icon"><i class="fas fa-mobile-alt"></i></div>
      <span class="nav-link-label">عرض الموقع</span>
    </a>

  </nav>

  <div class="sidebar-footer">
    <a href="<?= SITE_URL ?>/logout.php" class="nav-link" onclick="return confirm('تسجيل الخروج؟')">
      <div class="nav-link-icon"><i class="fas fa-sign-out-alt"></i></div>
      <span class="nav-link-label">تسجيل الخروج</span>
    </a>
  </div>
</aside>

<div class="admin-main">
  <header class="admin-topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
      <div>
        <div class="topbar-page-title"><?= htmlspecialchars($pageTitle ?? '') ?></div>
        <div class="topbar-breadcrumb">
          <a href="<?= SITE_URL ?>/admin/" style="color:var(--text3)">الرئيسية</a>
          <?php if ($curPage !== 'index.php'): ?>
          <span style="margin:0 4px;color:var(--text3)">←</span>
          <span><?= htmlspecialchars($pageTitle ?? '') ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($pendingOrders > 0 && ($IS_ADMIN || canAccess($pdo,'perm_orders_view'))): ?>
      <a href="<?= SITE_URL ?>/admin/orders.php?status=pending" class="topbar-btn" data-tip="<?= $pendingOrders ?> طلب معلق">
        <i class="fas fa-shopping-bag"></i><span class="topbar-notif-dot"></span>
      </a>
      <?php endif; ?>

      <!-- 🔔 إشعارات الإدارة -->
      <?php
      $adminNotifCount = 0;
      try {
        $anc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $anc->execute([$_SESSION['user_id']]);
        $adminNotifCount = (int)$anc->fetchColumn();
      } catch(Exception $e) {}
      ?>
      <div style="position:relative" id="adminNotifWrap">
        <button class="topbar-btn" onclick="toggleAdminNotif(event)" style="position:relative" id="adminNotifBtn">
          <i class="fas fa-bell"></i>
          <?php if ($adminNotifCount > 0): ?>
          <span id="adminNotifBadge" style="position:absolute;top:-4px;left:-4px;background:#ff4455;color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #070b14"><?= $adminNotifCount > 9 ? '9+' : $adminNotifCount ?></span>
          <?php else: ?>
          <span id="adminNotifBadge" style="position:absolute;top:-4px;left:-4px;background:#ff4455;color:#fff;font-size:10px;font-weight:700;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px;border:2px solid #070b14;display:none"></span>
          <?php endif; ?>
        </button>
        <div id="adminNotifDD" style="display:none;position:absolute;top:calc(100% + 8px);left:50%;transform:translateX(-50%);width:300px;background:#111827;border:1px solid #1e2940;border-radius:14px;box-shadow:0 20px 50px rgba(0,0,0,.7);z-index:9999;overflow:hidden;direction:rtl">
          <div style="padding:12px 14px 8px;border-bottom:1px solid #1e2940;display:flex;align-items:center;justify-content:space-between">
            <span style="font-weight:700;font-size:.85rem;color:#fff">🔔 إشعاراتي</span>
            <button onclick="adminMarkAllRead()" style="font-size:.72rem;color:#a78bfa;background:none;border:none;cursor:pointer">تعليم الكل</button>
          </div>
          <div id="adminNotifList" style="max-height:300px;overflow-y:auto">
            <div style="padding:20px;text-align:center;color:#6b7280;font-size:.8rem">جاري التحميل...</div>
          </div>
          <div style="padding:8px 14px;text-align:center;border-top:1px solid #1e2940">
            <a href="<?= SITE_URL ?>/notifications.php" style="font-size:.78rem;color:#a78bfa;text-decoration:none">عرض الكل</a>
          </div>
        </div>
      </div>
      <script>
      let adminNotifOpen = false;
      const ADMIN_NOTIF_API = '<?= SITE_URL ?>/api/notifications.php';
      function toggleAdminNotif(e) {
          e.stopPropagation();
          adminNotifOpen = !adminNotifOpen;
          const dd = document.getElementById('adminNotifDD');
          dd.style.display = adminNotifOpen ? 'block' : 'none';
          if (adminNotifOpen) loadAdminNotifs();
      }
      function loadAdminNotifs() {
          const list = document.getElementById('adminNotifList');
          fetch(ADMIN_NOTIF_API + '?action=list&limit=6')
              .then(r => r.json()).then(d => {
                  if (!d.notifications || d.notifications.length === 0) {
                      list.innerHTML = '<div style="padding:20px;text-align:center;color:#6b7280;font-size:.8rem"><i class="fas fa-bell-slash" style="display:block;font-size:1.4rem;margin-bottom:6px;opacity:.3"></i>لا توجد إشعارات</div>';
                      return;
                  }
                  list.innerHTML = d.notifications.map(n => `
                      <div onclick="adminNotifClick('${n.reference_type}', ${n.reference_id||0})" style="padding:10px 14px;display:flex;gap:8px;align-items:flex-start;border-bottom:1px solid #1e2940;cursor:pointer;background:${n.is_read==0?'rgba(108,63,224,0.06)':'transparent'}">
                          <div style="width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0;background:${n.color}22;color:${n.color}"><i class="fas fa-${n.icon}"></i></div>
                          <div style="flex:1;min-width:0">
                              <div style="font-size:.78rem;font-weight:600;color:#e5e7eb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${n.title}</div>
                              <div style="font-size:.7rem;color:#6b7280">${n.time_ago}</div>
                          </div>
                      </div>
                  `).join('');
                  document.getElementById('adminNotifBadge').style.display = 'none';
              }).catch(() => {});
      }
      function adminNotifClick(refType, refId) {
          fetch(ADMIN_NOTIF_API, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=mark_read&id=0'});
          if (refType === 'order' && refId > 0) window.location.href = '<?= SITE_URL ?>/admin/orders.php';
          else window.location.href = '<?= SITE_URL ?>/notifications.php';
      }
      function adminMarkAllRead() {
          fetch(ADMIN_NOTIF_API, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=mark_read&id=0'})
              .then(() => { document.getElementById('adminNotifBadge').style.display='none'; loadAdminNotifs(); });
      }
      document.addEventListener('click', (e) => {
          if (adminNotifOpen && !document.getElementById('adminNotifWrap').contains(e.target)) {
              adminNotifOpen = false;
              document.getElementById('adminNotifDD').style.display = 'none';
          }
      });
      </script>
      <a href="<?= SITE_URL ?>/mobile.php" target="_blank" class="topbar-btn" data-tip="عرض الموقع"><i class="fas fa-external-link-alt"></i></a>
      <div class="topbar-admin-chip">
        <div class="topbar-chip-avatar"><?= $adminInitial ?></div>
        <div class="topbar-chip-name"><?= htmlspecialchars(mb_substr($adminName,0,14)) ?></div>
      </div>
    </div>
  </header>

  <?php $flash = getFlash(); if ($flash): ?>
  <div style="padding:16px 24px 0">
    <div class="alert alert-<?= $flash['type'] ?>">
      <i class="fas fa-<?= $flash['type']==='success'?'check-circle':($flash['type']==='warning'?'exclamation-triangle':'exclamation-circle') ?>"></i>
      <?= htmlspecialchars($flash['message']) ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="admin-content">
  <div class="toast-container" id="toastContainer"></div>

<script>
function toggleSidebar() {
  const sb = document.getElementById('adminSidebar');
  const ov = document.getElementById('sidebarOverlay');
  if (!sb) return;
  const isOpen = sb.classList.toggle('open');
  if (ov) ov.style.display = isOpen ? 'block' : 'none';
  document.body.style.overflow = isOpen ? 'hidden' : '';
}

function closeSidebarIfMobile() {
  if (window.innerWidth <= 900) {
    const sb = document.getElementById('adminSidebar');
    const ov = document.getElementById('sidebarOverlay');
    if (sb && sb.classList.contains('open')) {
      sb.classList.remove('open');
      if (ov) ov.style.display = 'none';
      document.body.style.overflow = '';
    }
  }
}

// إغلاق عند الضغط على أي رابط في السايدبار
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.admin-sidebar .nav-link').forEach(link => {
    link.addEventListener('click', closeSidebarIfMobile);
  });
});

// إغلاق بـ ESC
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const sb = document.getElementById('adminSidebar');
    if (sb && sb.classList.contains('open')) toggleSidebar();
  }
});
</script>
