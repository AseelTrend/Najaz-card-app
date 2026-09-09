<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#070b14">
<title><?= htmlspecialchars($pageTitle ?? 'لوحة الإدارة') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
    <?php
    // ── حساب كل الأرقام (Badges) مرة واحدة ──────────────────────────
    $badgeCounts = [];
    $badgeCounts['pending_orders']  = $pendingOrders;
    $badgeCounts['pending_devices'] = $pendingDevices;
    $badgeCounts['pending_topups']  = $pendingTopups;
    try { $badgeCounts['pending_objections'] = (int)$pdo->query("SELECT COUNT(*) FROM order_objections WHERE status='pending'")->fetchColumn(); } catch (Exception $e) { $badgeCounts['pending_objections']=0; }
    $p2pActive=0; $p2pAlert=0;
    try { $p2pActive=(int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status IN ('active','delivered')")->fetchColumn(); } catch(Exception $e){}
    try { $p2pAlert=(int)$pdo->query("SELECT COUNT(*) FROM p2p_orders WHERE status='disputed'")->fetchColumn(); } catch(Exception $e){}
    $badgeCounts['p2p'] = $p2pActive + $p2pAlert;
    $p2pColor = $p2pAlert ? '#ff4455' : ($p2pActive ? '#8b5cf6' : 'var(--gold)');
    try { $badgeCounts['kyc_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM kyc_requests WHERE status='pending'")->fetchColumn(); } catch(Exception $e){ $badgeCounts['kyc_pending']=0; }
    $codeStockLowCount = 0;
    try { require_once __DIR__.'/../includes/code_stock_helper.php'; $codeStockLowCount = count(codeStockLowStockAlerts($pdo)); } catch (Exception $e) {}
    $badgeCounts['code_stock_low'] = $codeStockLowCount;
    $badgeCounts['admin_telecom_pending'] = (int)($stats['pending'] ?? 0);
    $maintOn = getSetting('maintenance_mode') === '1';
    $badgeCounts['maintenance'] = $maintOn ? 1 : 0;

    // ── تسجيل صفحة Telegram Bot ضمن القائمة عند توفرها ────────────────
    try {
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('telegram_bot','النظام',7,30,'Telegram Bot','telegram','fab','telegram_bot.php',NULL,1,NULL,'var(--gold)',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('exchange_rates_center','النظام',7,6,'مركز سعر الصرف','globe','fas','exchange_rates.php','perm_settings',0,NULL,'var(--gold)',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('display_languages','النظام',7,7,'لغات العرض','language','fas','languages.php',NULL,1,NULL,'var(--gold)',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('verified_customers','العملاء',2,16,'العملاء الموثقون','user-check','fas','verified_customers.php','perm_customers_view',0,NULL,'#00d4aa',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('binance_pay','الشحن والدفع',3,9,'Binance Pay','wallet','fas','binance_pay.php','perm_settings',0,NULL,'#f6c11a',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_accounts','المحاسبة',6,1,'شجرة الحسابات','sitemap','fas','accounting_accounts.php','perm_accounting_view',0,NULL,'#00d4aa',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_journal','المحاسبة',6,2,'القيود والمطابقة','file-invoice-dollar','fas','accounting_journal.php','perm_accounting_view',0,NULL,'#f5a623',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_reconciliation','المحاسبة',6,3,'مطابقة العمليات','link','fas','accounting_reconciliation.php','perm_accounting_view',0,NULL,'#60a5fa',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_cashboxes','المحاسبة',6,4,'الصناديق التشغيلية','cash-register','fas','accounting_cashboxes.php','perm_accounting_view',0,NULL,'#00d4aa',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_cashbox_movements','المحاسبة',6,5,'يومية الصناديق','book','fas','accounting_cashbox_movements.php','perm_accounting_view',0,NULL,'#f5a623',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('accounting_telecom_linkages','المحاسبة',6,6,'ربطيات الاتصالات','signal','fas','accounting_telecom_linkages.php','perm_accounting_view',0,NULL,'#60a5fa',1,1)");
        $pdo->exec("INSERT IGNORE INTO admin_menu_items
            (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color,is_visible,is_system)
            VALUES ('site_pages','المحتوى',2,50,'الصفحات العامة','file-alt','fas','site_pages.php',NULL,1,NULL,'#00d4ff',1,1)");
    } catch (Exception $e) {}

    // ── جلب عناصر القائمة من قاعدة البيانات (إن وُجد الجدول) ─────────
    $menuRows = [];
    try {
        $menuRows = $pdo->query("SELECT * FROM admin_menu_items WHERE is_visible=1 ORDER BY section_order ASC, sort_order ASC")->fetchAll();
    } catch (Exception $e) { $menuRows = []; }

    $curTabQ = $_GET['tab'] ?? null;
    $lastSection = null;
    foreach ($menuRows as $mi) {
        // فحص الصلاحية
        $allowed = $IS_ADMIN;
        if (!$allowed && !$mi['admin_only'] && $mi['permission_key']) {
            $allowed = canAccess($pdo, $mi['permission_key']);
        }
        if (!$allowed) continue;

        if ($mi['section_name'] !== $lastSection) {
            echo '<div class="sidebar-section">' . htmlspecialchars($mi['section_name']) . '</div>';
            $lastSection = $mi['section_name'];
        }

        $urlParts = explode('?', $mi['url'], 2);
        $pageBase = $urlParts[0] !== '' ? $urlParts[0] : 'index.php';
        $itemTab  = null;
        if (isset($urlParts[1])) { parse_str($urlParts[1], $qs); $itemTab = $qs['tab'] ?? null; }

        $fullUrl = $mi['url'] === '' ? SITE_URL.'/admin/' : SITE_URL.'/admin/'.$mi['url'];
        $active  = ($curPage === $pageBase && $curTabQ === $itemTab) ? 'active' : '';

        // الشارة (Badge)
        $badgeHtml = '';
        if ($mi['badge_source'] === 'p2p') {
            if ($badgeCounts['p2p'] > 0) $badgeHtml = '<span class="nav-link-badge" style="background:'.$p2pColor.'">'.$badgeCounts['p2p'].'</span>';
        } elseif ($mi['badge_source'] === 'maintenance') {
            if ($maintOn) $badgeHtml = '<span class="nav-link-badge" style="background:#ff4455">ON</span>';
        } elseif ($mi['badge_source'] && !empty($badgeCounts[$mi['badge_source']])) {
            $badgeHtml = '<span class="nav-link-badge" style="background:'.htmlspecialchars($mi['badge_color']).'">'.$badgeCounts[$mi['badge_source']].'</span>';
        }

        $iconCls = htmlspecialchars($mi['icon_type']) . ' fa-' . htmlspecialchars($mi['icon']);
        echo '<a href="'.htmlspecialchars($fullUrl).'" class="nav-link '.$active.'">
          <div class="nav-link-icon"><i class="'.$iconCls.'"></i></div>
          <span class="nav-link-label">'.htmlspecialchars($mi['label']).'</span>'.$badgeHtml.'
        </a>';
    }
    ?>

    <?php if ($IS_ADMIN): ?>
    <div class="sidebar-section">إدارة اللوحة</div>
    <?php navLink(SITE_URL.'/admin/menu_manager.php','menu_manager.php',$curPage,'bars','ترتيب القائمة'); ?>
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
