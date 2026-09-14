<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>

<!-- ════ Splash Screen ════ -->
<div id="splash-screen">
  <div class="splash-stars" id="splashStars"></div>
  <div class="splash-logo-wrap">
    <div class="splash-icon<?= $siteLogoUrl ? ' splash-icon-logo' : '' ?>" style="<?= $siteLogoUrl ? 'background:linear-gradient(135deg,#0d1428,#1a2340);padding:10px;overflow:hidden;' : '' ?>">
      <?php if ($siteLogoUrl): ?>
        <img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>"
          style="width:100%;height:100%;object-fit:contain;border-radius:16px;">
      <?php else: ?>
        ⚡
      <?php endif; ?>
    </div>
    <div>
      <div class="splash-title"><?= htmlspecialchars($siteName) ?></div>
      <div class="splash-sub">شحن الألعاب والتطبيقات الرقمية</div>
    </div>
    <div class="splash-loader"><div class="splash-loader-bar"></div></div>
  </div>
</div>

<!-- ════ Offline / Online Banner ════ -->
<div id="offline-banner"></div>

<!-- ════ Install PWA Prompt ════ -->
<div id="install-prompt">
  <div class="install-prompt-inner">
    <div class="install-icon">📲</div>
    <div class="install-text">
      <div class="install-title">ثبّت التطبيق على جهازك!</div>
      <div class="install-sub">وصول أسرع بدون متصفح — مجاناً تماماً</div>
    </div>
  </div>
  <div class="install-actions">
    <button class="install-btn install-btn-yes" onclick="installApp()">📥 تثبيت الآن</button>
    <button class="install-btn install-btn-no" onclick="dismissInstall()">لاحقاً</button>
  </div>
</div>

