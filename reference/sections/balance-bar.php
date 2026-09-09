<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
  <!-- CONTENT -->
  <div class="content" id="mainContent">

    <!-- BALANCE BAR (داخل منطقة التمرير الآن، حتى يختفي مع التمرير للأسفل مثل باقي الأقسام) -->
    <div class="balance-bar">
      <div class="balance-info">
        <div class="balance-icon">💰</div>
        <div>
          <div class="balance-label">الرصيد الإجمالي</div>
          <div class="balance-value" id="balVal" data-usd="<?= (float)$userBalance ?>"><?= number_format($userBalance, 2) ?></div>
          <div class="balance-currency" id="balCur"><?= htmlspecialchars($currSymbol) ?></div>
        </div>
      </div>
      <div class="balance-right">
        <?php if (isLoggedIn()): ?>
        <a href="#" onclick="event.preventDefault();navTo('wallet')" class="balance-reload">
          <i class="fas fa-wallet"></i> محفظتي
        </a>
        <?php else: ?>
        <button class="balance-reload" onclick="openAuth('login')">
          <i class="fas fa-sign-in-alt"></i> دخول
        </button>
        <?php endif; ?>
      </div>
    </div>

