<?php
/**
 * ─────────────────────────────────────────────────────────────
 * usdt_deposit_ui.php
 * قسم USDT في صفحة المحفظة — يُضمَّن داخل wallet.php
 *
 * الاستخدام: أضف هذا داخل قائمة طرق الشحن في wallet.php
 * ─────────────────────────────────────────────────────────────
 *
 * في wallet.php، داخل div#topup-methods أضف:
 *
 *   <?php if (getSetting('usdt_enabled') === '1'): ?>
 *     <?php require_once __DIR__ . '/usdt_deposit_ui.php'; ?>
 *   <?php endif; ?>
 */

require_once __DIR__ . '/includes/usdt_deposit.php';

$usdtEnabled = getSetting('usdt_enabled') === '1';
if (!$usdtEnabled) return; // لا تعرض إذا مُعطَّل

$usdtWallet  = getSetting('usdt_wallet_address');
$usdtMinDep  = (float)(getSetting('usdt_min_deposit') ?: '1.00');
$usdtTtl     = (int)(getSetting('usdt_request_ttl') ?: 30);
$currSymbol  = getSetting('currency_symbol') ?: '$';

// جلب طلب نشط للمستخدم إن وجد
$activeUsdtRequest = null;
if (isLoggedIn()) {
    usdt_expire_old_requests($pdo);
    $activeUsdtRequest = usdt_get_active_request($pdo, (int)$_SESSION['user_id']);
}
?>

<!-- ════════════════════════════════════════════════════════════
     بطاقة USDT في قائمة طرق الشحن
════════════════════════════════════════════════════════════ -->
<div class="topup-method-item" onclick="openUsdtDeposit()">
  <div class="topup-method-thumb" style="--mc:80,200,120">
    <i class="fas fa-coins" style="color:#26a17b"></i>
  </div>
  <div class="topup-method-info">
    <div class="topup-method-name">USDT — BEP20</div>
    <div class="topup-method-desc">BNB Smart Chain · الحد الأدنى <?= $usdtMinDep ?> USDT</div>
  </div>
  <i class="fas fa-chevron-left topup-method-arrow"></i>
</div>

<!-- ════════════════════════════════════════════════════════════
     نافذة إيداع USDT
════════════════════════════════════════════════════════════ -->
<div id="usdt-overlay" class="topup-overlay" style="display:none">
  <div class="topup-drawer" id="usdt-drawer">
    <div class="topup-drag-bar"></div>

    <!-- Header -->
    <div class="topup-header">
      <div style="width:36px;height:36px;background:rgba(38,161,123,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#26a17b">
        <i class="fas fa-coins"></i>
      </div>
      <div>
        <div class="topup-header-title">شحن عبر USDT</div>
        <div class="topup-header-sub">BNB Smart Chain (BEP20)</div>
      </div>
      <button class="topup-close-btn" onclick="closeUsdtDeposit()"><i class="fas fa-times"></i></button>
    </div>

    <!-- ── الخطوة 1: إدخال المبلغ ── -->
    <div id="usdt-step-amount">
      <div style="padding:16px">
        <div style="background:rgba(38,161,123,.06);border:1px solid rgba(38,161,123,.2);border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:#8fa3bf;line-height:1.6">
          ⚠️ أرسل <strong style="color:#fff">المبلغ الدقيق</strong> المُعروض فقط — لكل طلب رقم عشري فريد يُستخدم للتعرف على العملية تلقائياً
        </div>
        <label style="font-size:13px;color:#8fa3bf;margin-bottom:6px;display:block">المبلغ المطلوب بالـ <?= htmlspecialchars($currSymbol) ?></label>
        <div style="display:flex;gap:8px;margin-bottom:12px">
          <input type="number" id="usdt-amount-input" placeholder="مثال: 50"
                 min="<?= $usdtMinDep ?>" step="0.01"
                 style="flex:1;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px 14px;color:#fff;font-family:inherit;font-size:15px;font-weight:700;direction:ltr;text-align:center">
          <button onclick="createUsdtRequest()" id="usdt-gen-btn"
                  style="padding:12px 20px;background:#26a17b;border:none;border-radius:12px;color:#fff;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap">
            إنشاء طلب
          </button>
        </div>
        <!-- أزرار مبالغ سريعة -->
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php foreach ([10,25,50,100,200] as $q): ?>
            <?php if ($q >= $usdtMinDep): ?>
            <button onclick="document.getElementById('usdt-amount-input').value=<?= $q ?>"
                    style="flex:1;min-width:50px;padding:8px 4px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;color:#8fa3bf;font-family:inherit;font-size:13px;cursor:pointer">
              <?= $q ?>
            </button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div id="usdt-amount-error" style="color:#ff4466;font-size:12px;margin-top:8px;display:none"></div>
      </div>
    </div>

    <!-- ── الخطوة 2: تفاصيل الإيداع ── -->
    <div id="usdt-step-details" style="display:none;padding:16px">

      <!-- عداد تنازلي -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
        <span style="font-size:13px;color:#8fa3bf">ينتهي خلال</span>
        <span id="usdt-countdown" style="font-size:16px;font-weight:800;color:#f5a623">--:--</span>
      </div>

      <!-- المبلغ الفريد -->
      <div style="background:linear-gradient(135deg,rgba(38,161,123,.15),rgba(38,161,123,.05));border:1.5px solid rgba(38,161,123,.3);border-radius:16px;padding:20px;text-align:center;margin-bottom:14px">
        <div style="font-size:12px;color:#8fa3bf;margin-bottom:4px">أرسل هذا المبلغ تحديداً</div>
        <div id="usdt-display-amount" style="font-size:28px;font-weight:900;color:#26a17b;font-family:monospace;letter-spacing:-0.5px">—</div>
        <div style="font-size:13px;color:#8fa3bf;margin-top:2px">USDT</div>
        <button onclick="copyText('usdt-display-amount','usdt-copy-amount')"
                style="margin-top:10px;padding:6px 18px;background:rgba(38,161,123,.15);border:1px solid rgba(38,161,123,.3);border-radius:8px;color:#26a17b;font-family:inherit;font-size:12px;cursor:pointer">
          <span id="usdt-copy-amount">📋 نسخ المبلغ</span>
        </button>
      </div>

      <!-- عنوان المحفظة -->
      <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin-bottom:14px">
        <div style="font-size:12px;color:#8fa3bf;margin-bottom:6px">عنوان المحفظة (BEP20 فقط)</div>
        <div id="usdt-display-wallet" style="font-size:12px;color:#fff;font-family:monospace;word-break:break-all;direction:ltr;line-height:1.6">—</div>
        <button onclick="copyText('usdt-display-wallet','usdt-copy-wallet')"
                style="margin-top:8px;padding:6px 18px;background:rgba(30,111,255,.1);border:1px solid rgba(30,111,255,.2);border-radius:8px;color:#1e6fff;font-family:inherit;font-size:12px;cursor:pointer">
          <span id="usdt-copy-wallet">📋 نسخ العنوان</span>
        </button>
      </div>

      <!-- QR Code (اختياري) -->
      <div style="text-align:center;margin-bottom:14px">
        <img id="usdt-qr" src="" alt="QR"
             style="width:140px;height:140px;border-radius:12px;background:#fff;padding:8px;display:none">
      </div>

      <!-- تحذيرات -->
      <div style="background:rgba(255,68,68,.06);border:1px solid rgba(255,68,68,.2);border-radius:10px;padding:12px;font-size:12px;color:#ff7788;line-height:1.7">
        <div>⚠️ أرسل <strong>المبلغ الدقيق</strong> المذكور فقط — أي مبلغ مختلف لن يُعالَج</div>
        <div>🔒 BEP20 فقط — لا تستخدم شبكة ERC20 أو TRC20</div>
        <div>⏱️ الطلب صالح <?= $usdtTtl ?> دقيقة فقط</div>
      </div>

      <!-- زر فحص الحالة -->
      <button onclick="checkUsdtStatus()" id="usdt-check-btn"
              style="width:100%;margin-top:14px;padding:14px;background:rgba(38,161,123,.12);border:1.5px solid rgba(38,161,123,.25);border-radius:14px;color:#26a17b;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer">
        🔄 فحص حالة الإيداع
      </button>

      <button onclick="resetUsdtForm()"
              style="width:100%;margin-top:8px;padding:10px;background:transparent;border:1px solid rgba(255,255,255,.1);border-radius:12px;color:#8fa3bf;font-family:inherit;font-size:13px;cursor:pointer">
        ← إلغاء وإنشاء طلب جديد
      </button>
    </div>

    <!-- ── نجاح الإيداع ── -->
    <div id="usdt-step-success" style="display:none;padding:30px 16px;text-align:center">
      <div style="font-size:64px;margin-bottom:16px">✅</div>
      <div style="font-size:20px;font-weight:800;color:#00e676;margin-bottom:8px">تم إيداع رصيدك!</div>
      <div id="usdt-success-msg" style="font-size:14px;color:#8fa3bf;line-height:1.6;margin-bottom:20px"></div>
      <button onclick="location.reload()"
              style="padding:12px 30px;background:#00e676;border:none;border-radius:12px;color:#000;font-family:inherit;font-weight:800;font-size:15px;cursor:pointer">
        عرض رصيدي
      </button>
    </div>

  </div><!-- /topup-drawer -->
</div><!-- /usdt-overlay -->

<!-- ════════════════════════════════════════════════════════════
     JavaScript
════════════════════════════════════════════════════════════ -->
<script>
(function() {
  'use strict';

  var SITE_URL = '<?= SITE_URL ?>';
  var activeRequest = <?= $activeUsdtRequest ? json_encode($activeUsdtRequest) : 'null' ?>;
  var countdownInterval = null;
  var checkInterval     = null;

  // ── فتح/إغلاق ──────────────────────────────────────────────
  window.openUsdtDeposit = function() {
    document.getElementById('usdt-overlay').style.display = 'block';
    requestAnimationFrame(function() {
      document.getElementById('usdt-overlay').classList.add('show');
    });
    if (activeRequest) {
      showUsdtDetails(activeRequest);
    }
  };

  window.closeUsdtDeposit = function() {
    var overlay = document.getElementById('usdt-overlay');
    overlay.classList.remove('show');
    setTimeout(function() { overlay.style.display = 'none'; }, 350);
    clearInterval(countdownInterval);
    clearInterval(checkInterval);
  };

  window.resetUsdtForm = function() {
    activeRequest = null;
    showStep('amount');
    clearInterval(countdownInterval);
    clearInterval(checkInterval);
  };

  // ── إنشاء طلب جديد ─────────────────────────────────────────
  window.createUsdtRequest = function() {
    var amount = parseFloat(document.getElementById('usdt-amount-input').value);
    var errEl  = document.getElementById('usdt-amount-error');
    errEl.style.display = 'none';

    if (!amount || amount <= 0) {
      errEl.textContent = 'أدخل مبلغاً صحيحاً';
      errEl.style.display = 'block';
      return;
    }

    var btn = document.getElementById('usdt-gen-btn');
    btn.disabled = true;
    btn.textContent = '...جاري';

    fetch(SITE_URL + '/usdt_deposit_request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ amount: amount })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.disabled = false;
      btn.textContent = 'إنشاء طلب';
      if (data.ok && data.request) {
        activeRequest = data.request;
        showUsdtDetails(data.request);
      } else {
        errEl.textContent = data.error || 'حدث خطأ';
        errEl.style.display = 'block';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = 'إنشاء طلب';
      errEl.textContent = 'خطأ في الاتصال — حاول مرة أخرى';
      errEl.style.display = 'block';
    });
  };

  // ── عرض تفاصيل الطلب ───────────────────────────────────────
  function showUsdtDetails(req) {
    document.getElementById('usdt-display-amount').textContent  = req.unique_amount;
    document.getElementById('usdt-display-wallet').textContent  = req.wallet_address;

    // QR Code (باستخدام Google Charts API)
    var qr = document.getElementById('usdt-qr');
    if (req.wallet_address) {
      qr.src = 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=' + encodeURIComponent(req.wallet_address);
      qr.style.display = 'block';
    }

    showStep('details');
    startCountdown(req.expires_at);
    startAutoCheck();
  }

  // ── عداد تنازلي ─────────────────────────────────────────────
  function startCountdown(expiresAt) {
    clearInterval(countdownInterval);
    var expTime = new Date(expiresAt.replace(' ', 'T')).getTime();
    function tick() {
      var remaining = Math.max(0, Math.floor((expTime - Date.now()) / 1000));
      var m = Math.floor(remaining / 60);
      var s = remaining % 60;
      var el = document.getElementById('usdt-countdown');
      el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
      el.style.color = remaining < 120 ? '#ff4466' : '#f5a623';
      if (remaining === 0) {
        clearInterval(countdownInterval);
        el.textContent = 'انتهت الصلاحية';
        el.style.color = '#ff4466';
      }
    }
    tick();
    countdownInterval = setInterval(tick, 1000);
  }

  // ── فحص الحالة التلقائي ─────────────────────────────────────
  function startAutoCheck() {
    clearInterval(checkInterval);
    checkInterval = setInterval(checkUsdtStatus, 30000); // كل 30 ثانية
  }

  window.checkUsdtStatus = function() {
    if (!activeRequest) return;
    var btn = document.getElementById('usdt-check-btn');
    btn.textContent = '⏳ جاري الفحص...';

    fetch(SITE_URL + '/usdt_deposit_status.php?id=' + activeRequest.id)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      btn.textContent = '🔄 فحص حالة الإيداع';
      if (data.status === 'completed') {
        clearInterval(countdownInterval);
        clearInterval(checkInterval);
        document.getElementById('usdt-success-msg').innerHTML =
          'تم إيداع <strong>' + data.amount + ' USDT</strong> في محفظتك بنجاح 🎉<br>' +
          '<small style="font-family:monospace;color:#4d6080">' + data.tx_hash + '</small>';
        showStep('success');
      }
    })
    .catch(function() {
      btn.textContent = '🔄 فحص حالة الإيداع';
    });
  };

  // ── أدوات مساعدة ────────────────────────────────────────────
  function showStep(step) {
    ['amount','details','success'].forEach(function(s) {
      document.getElementById('usdt-step-' + s).style.display = s === step ? 'block' : 'none';
    });
  }

  window.copyText = function(elId, btnId) {
    var text = document.getElementById(elId).textContent.trim();
    navigator.clipboard.writeText(text).then(function() {
      var btn = document.getElementById(btnId);
      var orig = btn.textContent;
      btn.textContent = '✅ تم النسخ';
      setTimeout(function() { btn.textContent = orig; }, 2000);
    });
  };

  // ── إغلاق بالنقر خارج النافذة ─────────────────────────────
  document.getElementById('usdt-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeUsdtDeposit();
  });

})();
</script>
