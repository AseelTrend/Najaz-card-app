<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<div id="app">
  <div class="toast" id="toast"></div>

  <!-- SIDEBAR OVERLAY -->
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <!-- SIDEBAR -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-top">
      <div class="sidebar-user">
        <?php if(isLoggedIn()): ?>
        <div class="sidebar-avatar" style="<?= $profileAvatar ? 'padding:0;overflow:hidden;' : '' ?>">
          <?php if($profileAvatar): ?>
          <img class="header-avatar-img" src="<?= htmlspecialchars($profileAvatar) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
          <span style="display:none"><?= mb_substr($userName ?: 'U', 0, 1) ?></span>
          <?php else: ?>
          <?= mb_substr($userName ?: 'U', 0, 1) ?>
          <?php endif; ?>
        </div>
        <div>
          <div class="sidebar-username"><?= htmlspecialchars($userName) ?></div>
          <div class="sidebar-balance"><i class="fas fa-coins"></i> <span id="sbBalVal" data-usd="<?= (float)$userBalance ?>"><?= formatMoney($userBalance) ?></span></div>
        </div>
        <?php else: ?>
        <div class="sidebar-avatar" style="background:var(--card2);color:#8895a7"><i class="fas fa-user"></i></div>
        <div>
          <div class="sidebar-username">زائر</div>
          <div class="sidebar-balance" onclick="closeSidebar();openAuth('login')" style="cursor:pointer;color:var(--primary)"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
        </div>
        <?php endif; ?>
      </div>
      <button onclick="closeSidebar()" class="sidebar-close"><i class="fas fa-times"></i></button>
    </div>

    <div class="sidebar-menu">
      <div class="sidebar-menu-label">القائمة</div>
      <div class="sidebar-item" onclick="closeSidebar();navTo('home')">
        <span class="sidebar-item-icon" style="background:rgba(30,111,255,.15);color:var(--primary)"><i class="fas fa-home"></i></span>
        الرئيسية
      </div>
      <?php if(isLoggedIn()): ?>
      <div class="sidebar-item" onclick="closeSidebar();navTo('wallet')">
        <span class="sidebar-item-icon" style="background:rgba(0,230,118,.15);color:var(--green)"><i class="fas fa-wallet"></i></span>
        <span data-i18n="sb_wallet">محفظتي</span>
        <span class="sidebar-item-badge"><?= formatMoney($userBalance) ?></span>
      </div>
      <div class="sidebar-item" onclick="closeSidebar();openTopupSheet()">
        <span class="sidebar-item-icon" style="background:rgba(30,111,255,.15);color:var(--primary)"><i class="fas fa-plus-circle"></i></span>
        <span data-i18n="sb_topup">شحن الرصيد</span>
      </div>
      <div class="sidebar-item" onclick="closeSidebar();navTo('orders')">
        <span class="sidebar-item-icon" style="background:rgba(0,212,255,.15);color:var(--cyan)"><i class="fas fa-receipt"></i></span>
        <span data-i18n="sb_orders">طلباتي</span>
      </div>
      <div class="sidebar-item" onclick="closeSidebar();navTo('payments')">
        <span class="sidebar-item-icon" style="background:rgba(0,200,83,.12);color:#00c853"><i class="fas fa-credit-card"></i></span>
        مدفوعاتي
      </div>
      <?php if(getSetting('referral_enabled')): ?>
      <div class="sidebar-item" onclick="closeSidebar();navTo('referral')">
        <span class="sidebar-item-icon" style="background:rgba(0,200,83,.12);color:#00c853"><i class="fas fa-users"></i></span>
        برنامج الإحالة
        <?php if($myRefStats['count']>0): ?>
        <span class="sidebar-item-badge"><?= $myRefStats['count'] ?> صديق</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="sidebar-item" onclick="closeSidebar();navTo('profile')">
        <span class="sidebar-item-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-user-circle"></i></span>
        <span data-i18n="sb_profile">حسابي</span>
      </div>
      <div class="sidebar-item" onclick="closeSidebar();navTo('settings')">
        <span class="sidebar-item-icon" style="background:rgba(108,63,224,.15);color:#a78bfa"><i class="fas fa-sliders-h"></i></span>
        <span data-i18n="sb_settings">الإعدادات</span>
      </div>
      <div class="sidebar-divider"></div>
      <!-- زر اختيار عملة العرض -->
      <div class="sidebar-item" onclick="openCurrencyPicker();closeSidebar()">
        <span class="sidebar-item-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-coins"></i></span>
        <span>عملة العرض</span>
        <span id="sbCurLabel" style="margin-right:auto;background:rgba(245,166,35,.15);color:#f5a623;padding:2px 10px;border-radius:20px;font-size:.75rem;font-weight:800"></span>
        <i class="fas fa-chevron-left" style="font-size:.7rem;color:var(--text3)"></i>
      </div>

      <!-- بيانات العملات مخفية للـ JS -->
      <div id="curData" style="display:none">
        <?php foreach($displayCurrencies as $_dc): ?>
        <span data-code="<?=htmlspecialchars($_dc['currency_code'])?>"
              data-sym="<?=htmlspecialchars($_dc['currency_symbol'])?>"
              data-rate="<?=(float)$_dc['rate_from_usd']?>"
              data-name="<?=htmlspecialchars($_dc['currency_name'])?>"></span>
        <?php endforeach; ?>
      </div>
      <div class="sidebar-item" onclick="closeSidebar();navTo('kyc')">
        <span class="sidebar-item-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-id-card"></i></span>
        تحقق الهوية
        <?php if($kycStatus === 'approved'): ?>
        <span class="sidebar-item-badge" style="background:rgba(0,230,118,.12);color:var(--green)">✓ موثّق</span>
        <?php elseif($kycStatus === 'pending'): ?>
        <span class="sidebar-item-badge" style="background:rgba(245,166,35,.12);color:#f5a623">⏳ قيد المراجعة</span>
        <?php elseif($kycStatus === 'rejected'): ?>
        <span class="sidebar-item-badge" style="background:rgba(255,71,87,.12);color:#ff4757">✗ مرفوض</span>
        <?php else: ?>
        <span class="sidebar-item-badge" style="background:rgba(0,212,255,.1);color:var(--cyan)">جديد</span>
        <?php endif; ?>
      </div>
      <?php if(isAdmin()): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">الإدارة</div>
      <a href="<?=SITE_URL?>/admin/" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(255,213,0,.15);color:#ffd500"><i class="fas fa-cog"></i></span>
        لوحة التحكم
      </a>
      <?php endif; ?>
      <?php
      $apiEnabled = false;
      try { $apiChk=$pdo->prepare("SELECT api_enabled FROM users WHERE id=?"); $apiChk->execute([$_SESSION["user_id"]]); $apiChk=$apiChk->fetch(); $apiEnabled=(bool)($apiChk["api_enabled"]??false); } catch(\PDOException $e){}
      if ($apiEnabled): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">المطورين</div>
      <a href="<?= SITE_URL ?>/api.php" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(124,58,237,.15);color:#a78bfa"><i class="fas fa-code"></i></span>
        API
        <span class="sidebar-item-badge" style="background:rgba(124,58,237,.1);color:#a78bfa">مُفعَّل</span>
      </a>
      <a href="<?= SITE_URL ?>/api-docs" target="_blank" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(0,212,255,.12);color:var(--cyan)"><i class="fas fa-book"></i></span>
        التوثيق
      </a>
      <?php endif; ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">معلومات</div>
      <div class="sidebar-item" onclick="closeSidebar();openQuiz()" style="cursor:pointer">
        <span class="sidebar-item-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-trophy"></i></span>
        <span data-i18n="sb_quiz">المسابقات</span>
        <?php
          $activeQuiz = null;
          try { $activeQuiz=$pdo->query("SELECT id FROM quizzes WHERE status=1 LIMIT 1")->fetch(); } catch(Exception $e) {}
          if ($activeQuiz):
        ?>
        <span class="sidebar-item-badge" style="background:rgba(245,166,35,.15);color:#f5a623;animation:pulse 2s infinite">🔴 نشطة</span>
        <?php endif; ?>
      </div>

      <a href="<?= SITE_URL ?>/faq.php" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(30,111,255,.12);color:var(--primary)"><i class="fas fa-question-circle"></i></span>
        الأسئلة الشائعة
      </a>
      <?php
      require_once __DIR__ . '/../includes/site_pages.php';
      $publicSidebarPages = sitePagesLoadVisible($pdo);
      foreach ($publicSidebarPages as $publicPage):
          $publicPageUrl = SITE_URL . '/page.php?slug=' . rawurlencode($publicPage['slug']);
          $publicPageColor = htmlspecialchars($publicPage['icon_color'] ?: '#00d4ff', ENT_QUOTES, 'UTF-8');
          $publicPageIcon = htmlspecialchars($publicPage['icon'] ?: 'file-alt', ENT_QUOTES, 'UTF-8');
      ?>
      <a href="<?= htmlspecialchars($publicPageUrl, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:<?= $publicPageColor ?>22;color:<?= $publicPageColor ?>"><i class="fas fa-<?= $publicPageIcon ?>"></i></span>
        <?= htmlspecialchars($publicPage['title'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endforeach; ?>
      <?php if(isLoggedIn()): ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">الأمان</div>
      <a href="<?= SITE_URL ?>/security.php" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(245,166,35,.12);color:#f5a623"><i class="fas fa-shield-alt"></i></span>
        احمِ حسابك
        <?php
          $has2fa = false;
          try { $twofa=$pdo->prepare("SELECT totp_enabled FROM users WHERE id=?"); $twofa->execute([$_SESSION['user_id']]); $twofa=$twofa->fetch(); $has2fa=(bool)($twofa['totp_enabled']??false); } catch(\PDOException $e){}
        ?>
        <?php if($has2fa): ?>
        <span class="sidebar-item-badge" style="background:rgba(0,230,118,.1);color:#00e676">✓ مفعّل</span>
        <?php else: ?>
        <span class="sidebar-item-badge" style="background:rgba(255,68,85,.1);color:#ff4455">غير مفعّل</span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
      <div class="sidebar-divider"></div>
      <div onclick="doLogout()" class="sidebar-item" style="cursor:pointer;color:#ff4455">
        <span class="sidebar-item-icon" style="background:rgba(255,68,85,.1);color:#ff4455"><i class="fas fa-sign-out-alt"></i></span>
        تسجيل الخروج
      </div>
      <?php else: ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">معلومات</div>
      <?php
      require_once __DIR__ . '/../includes/site_pages.php';
      $publicSidebarPages = sitePagesLoadVisible($pdo);
      foreach ($publicSidebarPages as $publicPage):
          $publicPageUrl = SITE_URL . '/page.php?slug=' . rawurlencode($publicPage['slug']);
          $publicPageColor = htmlspecialchars($publicPage['icon_color'] ?: '#00d4ff', ENT_QUOTES, 'UTF-8');
          $publicPageIcon = htmlspecialchars($publicPage['icon'] ?: 'file-alt', ENT_QUOTES, 'UTF-8');
      ?>
      <a href="<?= htmlspecialchars($publicPageUrl, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:<?= $publicPageColor ?>22;color:<?= $publicPageColor ?>"><i class="fas fa-<?= $publicPageIcon ?>"></i></span>
        <?= htmlspecialchars($publicPage['title'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <?php endforeach; ?>
      <div class="sidebar-divider"></div>
      <div class="sidebar-menu-label">معلومات</div>
      <a href="<?= SITE_URL ?>/faq.php" class="sidebar-item" style="text-decoration:none">
        <span class="sidebar-item-icon" style="background:rgba(30,111,255,.12);color:var(--primary)"><i class="fas fa-question-circle"></i></span>
        الأسئلة الشائعة
      </a>
      <div class="sidebar-divider"></div>
      <div class="sidebar-item" onclick="closeSidebar();openAuth('login')">
        <span class="sidebar-item-icon" style="background:rgba(30,111,255,.15);color:var(--primary)"><i class="fas fa-sign-in-alt"></i></span>
        دخول / تسجيل
      </div>
      <?php endif; ?>
    </div>
  </div>

