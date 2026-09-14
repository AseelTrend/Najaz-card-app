<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<!-- ══ نافذة المسابقة ══ -->
<div class="quiz-overlay" id="quizOverlay">
  <!-- شاشة التحميل -->
  <div id="quizLoading" style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px;color:rgba(255,255,255,.6)">
    <i class="fas fa-spinner fa-spin" style="font-size:2rem"></i>
    <div>جاري تحميل المسابقة...</div>
  </div>
  <!-- المحتوى الفعلي -->
  <div id="quizContent" style="display:none;flex-direction:column;height:100%"></div>
</div>

<!-- ══ Modal شحن بكود ══ -->
<div id="cardRedeemOverlay" onclick="closeCardRedeem()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:800;backdrop-filter:blur(4px)"></div>
<div id="cardRedeemSheet"
     style="position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(105%);width:100%;max-width:430px;background:var(--card);border-radius:24px 24px 0 0;z-index:801;padding:20px 16px 32px;transition:transform .3s cubic-bezier(.4,0,.2,1)">
  <div style="width:40px;height:4px;background:var(--border2);border-radius:2px;margin:0 auto 16px"></div>
  <div style="text-align:center;margin-bottom:16px">
    <div style="font-size:2rem;margin-bottom:6px">💳</div>
    <div style="font-weight:900;font-size:1.1rem">شحن بكود البطاقة</div>
    <div style="font-size:.8rem;color:var(--text3);margin-top:4px">أدخل كود البطاقة لشحن رصيدك فوراً</div>
  </div>
  <div style="margin-bottom:12px">
    <input type="text" id="cardCodeInput"
           placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX"
           style="width:100%;background:var(--card2);border:2px solid var(--border2);border-radius:14px;padding:14px 16px;color:var(--text);font-family:'Courier New',monospace;font-size:1rem;font-weight:900;letter-spacing:2px;text-align:center;outline:none;text-transform:uppercase;transition:.2s"
           oninput="formatCardCode(this)"
           onfocus="this.style.borderColor='var(--primary)'"
           onblur="this.style.borderColor='var(--border2)'"
           onkeydown="if(event.key==='Enter') redeemCard()">
  </div>
  <div id="cardRedeemMsg" style="text-align:center;font-size:.83rem;margin-bottom:10px;min-height:20px"></div>
  <button onclick="redeemCard()" id="cardRedeemBtn"
          style="width:100%;padding:14px;background:linear-gradient(135deg,#00c853,#00a844);border:none;border-radius:14px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(0,200,83,.3)">
    <i class="fas fa-bolt"></i> شحن الرصيد
  </button>
  <button onclick="closeCardRedeem()"
          style="width:100%;margin-top:8px;padding:11px;background:transparent;border:1.5px solid var(--border);border-radius:12px;color:var(--text2);font-family:var(--font);font-size:.9rem;cursor:pointer">
    إلغاء
  </button>
</div>

<!-- ══ نافذة المحادثة المباشرة ══ -->
<?php if (isLoggedIn() && getSetting('chat_enabled')): ?>
<div class="chat-overlay" id="chatOverlay" onclick="closeChat()"></div>
<div class="chat-window" id="chatWindow">
  <div class="chat-win-header">
    <div class="chat-win-avatar">💬</div>
    <div style="flex:1">
      <div class="chat-win-title"><?= htmlspecialchars(getSetting('site_name') ?: SITE_NAME) ?></div>
      <div class="chat-win-sub">
        <span class="chat-online-dot"></span> متصل الآن — نرد خلال دقائق
      </div>
    </div>
    <button onclick="showChatHistory()" title="المحادثات السابقة"
      style="background:rgba(255,255,255,.15);border:none;width:32px;height:32px;border-radius:50%;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0">
      <i class="fas fa-history"></i>
    </button>
    <button class="chat-win-close" onclick="closeChat()"><i class="fas fa-times"></i></button>
  </div>
  <!-- قائمة المحادثات السابقة -->
  <div id="chatHistoryPanel" style="display:none;position:absolute;top:64px;right:0;left:0;bottom:0;background:var(--bg);z-index:10;flex-direction:column;overflow:hidden">
    <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">
      <button onclick="hideChatHistory()" style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:5px 10px;color:var(--text);cursor:pointer;font-family:var(--font);font-size:12px">
        <i class="fas fa-arrow-right"></i> رجوع
      </button>
      <span style="font-weight:700;font-size:.9rem">المحادثات السابقة</span>
    </div>
    <div id="chatHistoryList" style="overflow-y:auto;flex:1;padding:10px"></div>
  </div>
  <div class="chat-win-messages" id="chatMessages">
    <div style="text-align:center;padding:20px;color:var(--text3)">
      <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;opacity:.3"></i>
    </div>
  </div>
  <div class="chat-win-input">
    <textarea class="chat-win-textarea" id="chatInput" placeholder="اكتب رسالتك..." rows="1"
      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendChatMsg()}"
      oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,100)+'px'"></textarea>
    <button class="chat-win-send" onclick="sendChatMsg()"><i class="fas fa-paper-plane"></i></button>
  </div>
</div>
<?php endif; ?>

<!-- ══ Popup الإشعار المنبثق ══ -->
<?php if (isLoggedIn()): ?>
<div class="notif-popup-overlay" id="notifPopupOverlay">
  <div class="notif-popup-box" id="notifPopupBox">
    <button class="notif-popup-close" onclick="notifPopupClose()" aria-label="إغلاق"><i class="fas fa-times"></i></button>
    <div class="notif-popup-icon" id="notifPopupIcon">
      <i class="fas fa-bell" id="notifPopupIconI"></i>
    </div>
    <div class="notif-popup-title" id="notifPopupTitle">عنوان الإشعار</div>
    <div class="notif-popup-msg"   id="notifPopupMsg">نص الإشعار</div>
    <div class="notif-popup-btns">
      <button class="notif-popup-btn-ok" id="notifPopupBtnOk"
              onclick="notifPopupAction()">موافق</button>
      <button class="notif-popup-btn-later"
              onclick="notifPopupClose()">لاحقاً</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($contactButtons) || getSetting('chat_enabled')): ?>
<!-- ══ زر التواصل العائم ══ -->
<div class="float-overlay" id="floatOverlay" onclick="toggleContactMenu()"></div>
<div class="float-contact-btn" id="floatContactBtn">
  <div class="float-contact-items" id="floatItems">
    <?php if (isLoggedIn() && getSetting('chat_enabled')): ?>
    <button onclick="toggleContactMenu();openChat()"
       class="float-contact-item"
       style="background:#00c853;box-shadow:0 3px 14px #00c85355;border:none;cursor:pointer;font-family:var(--font)">
      <i class="fas fa-comment-dots"></i>
      <span>محادثة مباشرة</span>
    </button>
    <?php endif; ?>
    <?php foreach ($contactButtons as $cb): ?>
    <a href="<?= htmlspecialchars($cb['url']) ?>" target="_blank" rel="noopener"
       class="float-contact-item"
       style="background:<?= htmlspecialchars($cb['color']) ?>;box-shadow:0 3px 14px <?= htmlspecialchars($cb['color']) ?>55"
       onclick="toggleContactMenu()">
      <i class="<?= htmlspecialchars($cb['icon']) ?>"></i>
      <span><?= htmlspecialchars($cb['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <button class="float-main-btn" id="floatMainBtn" onclick="toggleContactMenu()" title="تواصل معنا">
    <i class="fas fa-headset" id="floatMainIcon"></i>
  </button>
</div>
<?php endif; ?>

</div>

<!-- نافذة اختيار العملة -->
<div class="cur-picker-ov" id="curPickerOv" onclick="if(event.target===this)closeCurrencyPicker()">
  <div class="cur-picker-box">
    <div style="padding:10px 0 4px;text-align:center;flex-shrink:0">
      <div style="width:36px;height:4px;background:var(--border2);border-radius:4px;display:inline-block"></div>
    </div>
    <div style="padding:8px 16px 12px;font-size:1rem;font-weight:900;flex-shrink:0">
      <i class="fas fa-coins" style="color:#f5a623;margin-left:8px"></i> اختر عملة العرض
    </div>
    <div id="curPickerList" style="overflow-y:auto;flex:1"></div>
  </div>
</div>

<!-- نافذة تأكيد الطلب -->
<div id="_confOv" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9500;display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s" onclick="if(event.target===this)this.classList.remove('open')">
  <style>#_confOv.open{opacity:1;pointer-events:all} #_confOv .cb{transform:translateY(0)!important}</style>
  <div class="cb" style="background:var(--bg2);border-radius:24px 24px 0 0;padding:24px 20px 36px;width:100%;max-width:440px;transform:translateY(40px);transition:transform .3s">
    <div style="text-align:center;margin-bottom:16px">
      <div style="font-size:2rem">💳</div>
      <div style="font-weight:900;font-size:1.1rem;margin-top:6px;color:var(--text)">تأكيد الطلب</div>
    </div>
    <div style="background:var(--bg);border-radius:14px;padding:14px 16px;margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="color:var(--text3);font-size:.85rem">سيتم خصم</span>
        <strong id="_confUSD" style="color:#00d4aa;font-size:1rem"></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--border)">
        <span style="color:var(--text3);font-size:.85rem">ما يعادل</span>
        <span id="_confLocal" style="color:var(--text2);font-weight:700"></span>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button onclick="document.getElementById('_confOv').classList.remove('open')"
        style="flex:1;padding:12px;background:var(--card2);border:1px solid var(--border);border-radius:12px;color:var(--text2);font-family:var(--font);font-weight:700;cursor:pointer">إلغاء</button>
      <button onclick="document.getElementById('_confOv').classList.remove('open');submitOrderAjax()"
        style="flex:2;padding:12px;background:var(--primary);border:none;border-radius:12px;color:#fff;font-family:var(--font);font-weight:800;cursor:pointer">
        <i class="fas fa-bolt"></i> تأكيد الشراء</button>
    </div>
  </div>
</div>
