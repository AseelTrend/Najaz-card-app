<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
  <!-- MAIN COLUMN (desktop wrapper) -->
  <div class="main-column">

  <!-- HEADER -->
  <div class="top-header">
    <div class="header-logo">
      <button class="hamburger-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div class="logo-icon" style="<?= $siteLogoUrl ? 'background:none;padding:2px;overflow:hidden;' : '' ?>">
        <?php if ($siteLogoUrl): ?>
          <img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>"
            style="width:100%;height:100%;object-fit:contain;border-radius:10px;">
        <?php else: ?>
          🚀
        <?php endif; ?>
      </div>
      <div class="logo-text"><?= htmlspecialchars($siteName) ?></div>
    </div>
    <div class="header-right">
      <!-- زر الوضع النهاري/الليلي -->
      <button class="theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="تبديل الوضع">
        <i class="fas fa-moon" id="themeIcon"></i>
      </button>
      <?php if (isLoggedIn()): ?>
      <a href="<?= SITE_URL ?>/orders.php" class="header-btn"><i class="fas fa-receipt"></i></a>
      <button class="header-btn" onclick="navTo('notif')" id="notifHeaderBtn" title="الإشعارات" style="border:none;cursor:pointer">
        <i class="fas fa-bell"></i>
        <span class="notif-dot" id="notifHeaderDot" style="<?= $unreadNotifCount > 0 ? '' : 'display:none' ?>"></span>
      </button>
      <?php if (isAdmin()): ?>
      <a href="<?= SITE_URL ?>/admin/" class="header-btn" style="color:var(--gold)"><i class="fas fa-cog"></i></a>
      <?php endif; ?>
      <?php else: ?>
      <button class="header-btn" onclick="openAuth('login')"><i class="fas fa-sign-in-alt"></i></button>
      <?php endif; ?>
    </div>
  </div>

