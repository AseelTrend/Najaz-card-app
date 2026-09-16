<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<script>
// ══════════════════════════════════════
// DATA FROM PHP
// ══════════════════════════════════════
// شجرة الأقسام الكاملة (متسلسلة لا نهائية)
const CATS     = <?= json_encode(array_values($mainCats), JSON_UNESCAPED_UNICODE) ?>;
const SUB_CATS = <?= json_encode($subByParent, JSON_UNESCAPED_UNICODE) ?>;
const ALL_CATS = <?= json_encode($catsById, JSON_UNESCAPED_UNICODE) ?>;
const SERVICES = <?= json_encode($allServices, JSON_UNESCAPED_UNICODE) ?>;
const SVC_FIELDS = <?= json_encode($serviceFields, JSON_UNESCAPED_UNICODE) ?>;
const CURRENCY  = "<?= htmlspecialchars($currSymbol) ?>";
const SITE_LABEL = "<?= htmlspecialchars($siteName, ENT_QUOTES) ?>";

// ══ نظام عملة العرض ══════════════════════════════════════
var _CUR = (function(){
  try { var s = localStorage.getItem('_njaz_cur'); if(s) return JSON.parse(s); } catch(e){}
  return {code:'USD', sym:'$', rate:1, name:'دولار أمريكي'};
})();

function fmtPrice(val) {
  var n = parseFloat(val);
  if (isNaN(n) || n === 0) return '0.00';
  if (_CUR.code === 'USD') {
    if (n < 0.01 && n > 0) return parseFloat(n.toPrecision(4)).toString();
    return n.toFixed(2);
  }
  var d = (_CUR.rate > 0) ? n * _CUR.rate : n;
  if (d >= 1000) return d.toLocaleString('en-US', {maximumFractionDigits:0});
  if (d >= 1)    return d.toFixed(2);
  return d.toFixed(4);
}

function _ptag(usd) {
  var n = parseFloat(usd) || 0;
  if (_CUR.code === 'USD') return n.toFixed(2) + ' $';
  var d = n * _CUR.rate;
  var ds = d >= 1000 ? d.toLocaleString('en-US',{maximumFractionDigits:0}) : d >= 1 ? d.toFixed(2) : d.toFixed(4);
  return ds + ' ' + _CUR.sym + ' <span class="pkg-price-usd">(~' + n.toFixed(2) + '$)</span>';
}

// ══ نافذة اختيار العملة ══════════════════════════════════════
function openCurrencyPicker() {
  var ov   = document.getElementById('curPickerOv');
  var list = document.getElementById('curPickerList');
  if (!ov || !list) return;
  var spans = document.querySelectorAll('#curData span');
  list.innerHTML = '';
  spans.forEach(function(s) {
    var code = s.dataset.code, sym = s.dataset.sym;
    var rate = parseFloat(s.dataset.rate), name = s.dataset.name;
    var isActive = (_CUR.code === code);
    var ex = rate * 10;
    var exFmt = ex >= 1000 ? Math.round(ex).toLocaleString('en-US') : ex >= 1 ? ex.toFixed(2) : ex.toFixed(4);
    var div = document.createElement('div');
    div.className = 'cur-item' + (isActive ? ' active' : '');
    div.innerHTML = '<div style="display:flex;align-items:center;gap:12px">'
      + '<div style="width:40px;height:40px;border-radius:12px;background:'+(isActive?'rgba(30,111,255,.15)':'var(--card2)')+';display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.9rem;color:'+(isActive?'var(--primary)':'#f5a623')+'">' + sym + '</div>'
      + '<div><div style="font-weight:700">' + name + '</div><div style="font-size:.72rem;color:var(--text3)">' + code + '</div></div>'
      + '</div><div style="text-align:left">'
      + '<div style="font-size:.78rem;color:#00d4aa;font-weight:700">10$ ≈ ' + exFmt + '</div>'
      + (isActive ? '<div style="font-size:.65rem;color:var(--primary)">✓ مختارة</div>' : '')
      + '</div>';
    div.onclick = (function(c,sy,r,n){ return function(){ pickCurrency(c,sy,r,n); }; })(code,sym,rate,name);
    list.appendChild(div);
  });
  ov.classList.add('open');
}
function closeCurrencyPicker() {
  document.getElementById('curPickerOv').classList.remove('open');
}

function pickCurrency(code, sym, rate, name) {
  _CUR = {code: code, sym: sym, rate: parseFloat(rate), name: name};
  try { localStorage.setItem('_njaz_cur', JSON.stringify(_CUR)); } catch(e){}
  var lbl = document.getElementById('sbCurLabel');
  if (lbl) lbl.textContent = _CUR.sym + ' ' + _CUR.code;
  document.querySelectorAll('[data-usp]').forEach(function(el){ el.innerHTML = _ptag(parseFloat(el.dataset.usp)); });
  _updateBalDisplay();
  if (typeof currentSvc !== 'undefined' && currentSvc) updateTotal();
  if (typeof showToast === 'function') showToast('عملة العرض: ' + _CUR.name);
  closeCurrencyPicker();
}

function _updateBalDisplay() {
  // تحديث الرصيد حسب العملة المختارة
  document.querySelectorAll('[data-usd]').forEach(function(el) {
    var usd = parseFloat(el.dataset.usd) || 0;
    if (_CUR.code === 'USD') {
      el.textContent = usd.toFixed(2);
    } else {
      var d = usd * _CUR.rate;
      el.textContent = d >= 1000
        ? d.toLocaleString('en-US', {maximumFractionDigits:0})
        : d >= 1 ? d.toFixed(2) : d.toFixed(4);
    }
  });
  // تحديث رمز العملة
  var balCur = document.getElementById('balCur');
  if (balCur) balCur.textContent = _CUR.sym;
}

// ── دالة مركزية لتحديث الرصيد في كل عناصر الصفحة ────────────────────────
function updateAllBalances(newBalance) {
  var bal = parseFloat(newBalance);
  if (isNaN(bal)) return;

  // 1. تحديث الـ data-usd attribute (يستخدمه _updateBalDisplay)
  document.querySelectorAll('[data-usd]').forEach(function(el) {
    el.dataset.usd = bal;
  });

  // 2. تحديث balance-value في الهيدر
  document.querySelectorAll('.balance-value').forEach(function(el) {
    el.dataset.usd = bal;
  });

  // 3. تحديث wallet-balance-amount في صفحة المحفظة
  document.querySelectorAll('.wallet-balance-amount').forEach(function(el) {
    el.textContent = bal.toFixed(2);
  });

  // 4. تحديث العرض بالعملة الحالية
  _updateBalDisplay();

  // 5. أنيميشن flash على عنصر الرصيد الرئيسي
  var mainBal = document.querySelector('.balance-value');
  if (mainBal) {
    mainBal.style.transition = 'color .3s';
    mainBal.style.color = '#00e676';
    setTimeout(function() { mainBal.style.color = ''; }, 1200);
  }
}

function setCur(el) {
  _CUR = {code: el.dataset.code, sym: el.dataset.sym, rate: parseFloat(el.dataset.rate), name: el.dataset.name};
  try { localStorage.setItem('_njaz_cur', JSON.stringify(_CUR)); } catch(e){}
  // تحديث label السايدبار
  var lbl = document.getElementById('sbCurLabel');
  if (lbl) lbl.textContent = _CUR.sym + ' ' + _CUR.code;
  // تحديث الأسعار
  document.querySelectorAll('[data-usp]').forEach(function(el){ el.innerHTML = _ptag(parseFloat(el.dataset.usp)); });
  _updateBalDisplay();
  if (typeof currentSvc !== 'undefined' && currentSvc) updateTotal();
  if (typeof showToast === 'function') showToast('عملة العرض: ' + _CUR.name);
}

function openConfirmSheet() { submitOrderAjax(); }
const SITE_URL = "<?= SITE_URL ?>";
const USER_BALANCE = <?= (float)$userBalance ?>;
const IS_LOGGED   = <?= isLoggedIn() ? 'true' : 'false' ?>;
const KYC_STATUS  = "<?= $kycStatus ?? '' ?>";  // approved | pending | rejected | 

// ── فحص الطلبات المعلقة عند تحميل الصفحة ─────────────────────────────────
// (يُنفَّذ من نهاية الملف بعد تعريف _naRender)
if (IS_LOGGED) {
  window.addEventListener('load', function() {
    // الفحص التلقائي العادي فقط — الاستعادة الفورية تتم من آخر الملف
    var forcedOrderId = sessionStorage.getItem('resume_numbers_order');
    if (!forcedOrderId) {
      setTimeout(_checkPendingNumbersOrder, 1500);
    }
  });
}

async function _checkPendingNumbersOrder(specificOrderId) {
  try {
    var url = SITE_URL + '/api/numbersapp_live.php?action=resume';
    if (specificOrderId) url += '&order_id=' + specificOrderId;
    var r = await fetch(url, {credentials:'same-origin'});
    var d = await r.json();
    if (!d.ok) return; // لا يوجد طلب معلق

    // وُجد طلب معلق — استعادة الواجهة
    var svc = {
      id:    d.service_id,
      name:  d.svc_name,
      image: d.svc_image
    };

    // إعادة بناء _naLive بالبيانات المستعادة
    _naLive = {
      svc:               svc,
      orderId:           d.order_id,
      number:            d.number,
      accessId:          d.access_id,
      code:              '',
      timer:             d.remaining,
      timerInterval:     null,
      autoCheckInterval: null,
      autoCheck:         true,
      step:              'waiting',
      refund:            null,
      resumed:           true   // علامة أن هذه استعادة
    };

    _naRender();
    _naStartTimer();
    _naStartAutoCheck();

    // إذا انتهى الوقت فعلاً — لا نبدأ التايمر، نعرض رسالة مباشرة
    if (d.remaining <= 0) {
      clearInterval(_naLive.timerInterval);
      clearInterval(_naLive.autoCheckInterval);
      _naLive.autoCheck = false;
      showToast('انتهى وقت الانتظار — يمكنك الإلغاء', 'warning');
      _naRender();
    }

  } catch(e) { /* صامت */ }
}

// ── الإلغاء التلقائي بعد 20 دقيقة ─────────────────────────────────────────
async function _naAutoCancel(orderId) {
  try {
    var fd = new FormData();
    fd.append('action', 'auto_cancel');
    fd.append('order_id', orderId);
    var r = await fetch(SITE_URL + '/api/numbersapp_live.php', {method:'POST', body:fd, credentials:'same-origin'});
    var d = await r.json();

    if (!d.ok) return;

    if (d.action_taken === 'completed') {
      // وصل الكود في اللحظة الأخيرة
      _naLive.code = d.code;
      _naLive.step = 'code_received';
      _naLive.autoCheck = false;
      clearInterval(_naLive.timerInterval);
      clearInterval(_naLive.autoCheckInterval);
      _naRender();
      showToast('✅ وصل الكود قبيل انتهاء الوقت!', 'success');
    } else {
      // تم الإلغاء التلقائي
      _naLive.step   = 'cancelled';
      _naLive.refund = d.refund;
      _naLive.autoCheck = false;
      clearInterval(_naLive.timerInterval);
      clearInterval(_naLive.autoCheckInterval);
      updateAllBalances(d.new_balance);
      _naRender();
      showToast('تم الإلغاء التلقائي واسترداد الرصيد', 'warning');
    }
  } catch(e) {
    showToast('خطأ في الإلغاء التلقائي', 'error');
  }
}

// CAT STYLES
const CAT_STYLES = {
  'شحن الألعاب':      {icon:'🎮', grad:'linear-gradient(135deg,#1a237e,#0d47a1)'},
  'شحن التطبيقات':    {icon:'📲', grad:'linear-gradient(135deg,#004d40,#006064)'},
  'البطاقات الرقمية': {icon:'💳', grad:'linear-gradient(135deg,#4a148c,#6a1b9a)'},
};
const DEFAULT_STYLE = {icon:'⭐', grad:'linear-gradient(135deg,#37474f,#546e7a)'};

function getCatStyle(name){ return CAT_STYLES[name] || DEFAULT_STYLE; }

// ══════════════════════════════════════
// NAVIGATION
// ══════════════════════════════════════
let currentCatId = null;
let currentPkgCatId = null;


// ══════════════════════════════════════════════════════════
// إعدادات العميل — timezone + theme
// ══════════════════════════════════════════════════════════
const SETTINGS_KEY = 'user_settings';

function loadSettings() {
  try { return JSON.parse(localStorage.getItem(SETTINGS_KEY)) || {}; } catch(e) { return {}; }
}
function showSaveTick(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.opacity = '1';
  clearTimeout(el._t);
  el._t = setTimeout(() => el.style.opacity = '0', 2000);
}

function saveSetting(key, val) {
  const s = loadSettings();
  s[key] = val;
  localStorage.setItem(SETTINGS_KEY, JSON.stringify(s));
  if (key === 'timezone') applyTimezone(parseFloat(val));
  if (key === 'theme')    applyThemeFromSettings(val);
}

// ── Timezone ────────────────────────────────────────────────
// SERVER_OFFSET — يُحسب من PHP مباشرة
const SERVER_OFFSET = <?php echo (int)(date('Z') / 3600); ?>;
const SERVER_TIME_NOW = '<?php echo date('Y-m-d H:i:s'); ?>';
const SERVER_TIMEZONE = '<?php echo date('Z') / 3600; ?>';
console.log('Server time:', SERVER_TIME_NOW, 'Server offset UTC:', SERVER_TIMEZONE);

function realOffset(val) {
  // 2.1, 3.1, 3.2 → نزيل الجزء العشري الزائد ونعيد الرقم الحقيقي
  const f = parseFloat(val);
  // القيم الحقيقية ذات .5 و.75 تبقى — الباقي يُقرَّب
  if (f % 1 === 0.1 || f % 1 === 0.2) return Math.floor(f);
  return f;
}

function convertServerTime(raw, offsetHours) {
  if (!raw) return '—';
  try {
    const trueOffset = realOffset(offsetHours);
    const d = new Date(raw.replace(' ', 'T'));
    const diff = trueOffset - SERVER_OFFSET;
    // معالجة .75 (كاتماندو) و.5
    const diffMs = diff * 3600000;
    const adjusted = new Date(d.getTime() + diffMs);
    const dd = String(adjusted.getDate()).padStart(2,'0');
    const mm = String(adjusted.getMonth()+1).padStart(2,'0');
    const hh = String(adjusted.getHours()).padStart(2,'0');
    const mi = String(adjusted.getMinutes()).padStart(2,'0');
    return `${dd}/${mm} ${hh}:${mi}`;
  } catch(e) { return raw; }
}

function applyTimezone(offsetHours, container) {
  const root = container || document;
  root.querySelectorAll('.server-time[data-time]').forEach(el => {
    el.textContent = convertServerTime(el.dataset.time, offsetHours);
  });
  updateTimePreview(offsetHours);
}

function updateTimePreview(offsetHours) {
  const preview = document.getElementById('localTimePreview');
  if (!preview) return;
  const now = new Date();
  const serverMs = now.getTime() + (SERVER_OFFSET - (now.getTimezoneOffset()/-60)) * 3600000;
  const userMs   = serverMs + offsetHours * 3600000;
  const d = new Date(userMs);
  const hh = String(d.getUTCHours()).padStart(2,'0');
  const mi = String(d.getUTCMinutes()).padStart(2,'0');
  const dd = String(d.getUTCDate()).padStart(2,'0');
  const mo = String(d.getUTCMonth()+1).padStart(2,'0');
  preview.textContent = `${dd}/${mo} ${hh}:${mi}`;
}

// تحديث preview كل ثانية (بدون إعادة تحميل قائمة الأجهزة — كانت تسبب تقطّع التمرير)
setInterval(() => {
  const s = loadSettings();
  if (document.getElementById('page-settings')?.classList.contains('active')) {
    updateTimePreview(parseFloat(s.timezone ?? 3));
  }
}, 1000);

// ── Theme ────────────────────────────────────────────────────
function setTheme(t) {
  saveSetting('theme', t);
}
function applyThemeFromSettings(t) {
  if (t === 'light') {
    document.body.classList.add('light-mode');
    localStorage.setItem('theme', 'light');
  } else {
    document.body.classList.remove('light-mode');
    localStorage.setItem('theme', 'dark');
  }
  updateThemeBtn();
}
function updateThemeBtn() {
  const isLight = document.body.classList.contains('light-mode');
  const btnD = document.getElementById('btnDark');
  const btnL = document.getElementById('btnLight');
  if (btnD) btnD.style.borderColor = isLight ? 'var(--border2)' : 'var(--primary)';
  if (btnL) btnL.style.borderColor = isLight ? 'var(--primary)' : 'var(--border2)';
}

// ── تطبيق الإعدادات عند التحميل ─────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // تهيئة عملة العرض المحفوظة
  var _sbLbl = document.getElementById('sbCurLabel');
  if (_sbLbl) _sbLbl.textContent = _CUR.sym + ' ' + _CUR.code;
  _updateBalDisplay();

  const s = loadSettings();
  const tz = parseFloat(s.timezone ?? 3);

  // تطبيق اللغة المحفوظة
  initLang();

  // استقبال كود الإحالة من الرابط ?ref=CODE
  const urlRef = new URLSearchParams(window.location.search).get('ref');
  if (urlRef) {
    const refInput = document.getElementById('regReferral');
    if (refInput) refInput.value = urlRef.toUpperCase();
    // افتح نموذج التسجيل تلقائياً
    setTimeout(() => { openAuth('register'); }, 800);
    // احفظه في localStorage لو أغلق النافذة وفتحها لاحقاً
    localStorage.setItem('pending_ref_code', urlRef.toUpperCase());
  }
  // استعادة كود محفوظ
  const savedRef = localStorage.getItem('pending_ref_code');
  if (savedRef) {
    const refInput = document.getElementById('regReferral');
    if (refInput && !refInput.value) refInput.value = savedRef;
  }

  // استعادة الـ select — نبحث عن القيمة المحفوظة أو أقرب option
  const sel = document.getElementById('tzSelect');
  if (sel) {
    const saved = String(s.timezone ?? '3');
    // حاول مباشرة
    sel.value = saved;
    // إذا لم ينجح، ابحث عن option بنفس القيمة
    if (sel.value !== saved) {
      for (const opt of sel.options) {
        if (opt.value === saved) { sel.value = saved; break; }
      }
    }
  }

  // تطبيق timezone
  applyTimezone(tz);

  // تطبيق theme
  updateThemeBtn();
});


function navTo(id, pushToHistory){
  // ── أغلق كل الـ sheets والـ overlays المفتوحة أولاً ──
  closeSheet();
  closeSidebar();
  document.body.style.overflow = '';

  // ── انتقل للصفحة ──
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const pg = document.getElementById('page-'+id);
  if(pg) { pg.classList.add('active'); pg.scrollTop = 0; }
  const ni = document.getElementById('nav-'+id);
  if(ni) ni.classList.add('active');
  document.getElementById('mainContent').scrollTop = 0;
  if (id === 'notif') onNotifPageOpen();
  if (id === 'settings') setTimeout(function(){ if(typeof initPushToggle==='function') initPushToggle(); if(typeof loadUserDevices==='function') loadUserDevices(); }, 150);

  // ── history API ──
  if (pushToHistory !== false) {
    history.pushState({page: id, type: 'page'}, '', '');
  }
}

function goHome(){
  navTo('home');
}

// ══ نظام زر الرجوع (Back Button) ════════════════════════════════
// نضع state أولي عند تحميل الصفحة
history.replaceState({page:'home', type:'page'}, '', '');

window.addEventListener('popstate', function(e) {
  var state = e.state;

  // 1. إذا كان الـ sheet مفتوحاً — أغلقه فقط
  var sheet = document.getElementById('bottomSheet');
  if (sheet && sheet.classList.contains('open')) {
    closeSheet();
    history.pushState({page:'home', type:'page'}, '', '');
    return;
  }

  // 2. إذا كان السايدبار مفتوحاً — أغلقه
  var sb = document.getElementById('sidebar');
  if (sb && sb.classList.contains('open')) {
    closeSidebar();
    history.pushState({page:'home', type:'page'}, '', '');
    return;
  }

  // 3. إذا كانت نافذة تأكيد الطلب مفتوحة — أغلقها
  var confOv = document.getElementById('_confOv');
  if (confOv && confOv.classList.contains('open')) {
    confOv.classList.remove('open');
    history.pushState({page:'home', type:'page'}, '', '');
    return;
  }

  // 4. إذا كنا في صفحة الخدمات (packages) — ارجع للأقسام
  var pkgPage = document.getElementById('page-packages');
  if (pkgPage && pkgPage.classList.contains('active')) {
    backFromPackages();
    return;
  }

  // 5. إذا كنا في صفحة الأقسام الفرعية (cat) وفيها مستوى — ارجع مستوى
  var catPage = document.getElementById('page-cat');
  if (catPage && catPage.classList.contains('active')) {
    if (typeof catGoBack === 'function') catGoBack(true);
    return;
  }

  // 6. إذا كنا في أي صفحة غير الرئيسية — ارجع للرئيسية
  var activePage = document.querySelector('.page.active');
  var activeId = activePage ? activePage.id.replace('page-', '') : 'home';
  if (activeId !== 'home') {
    navTo('home', false);
    return;
  }

  // 7. إذا كنا في الرئيسية — اسمح للمتصفح بالخروج الطبيعي
  // (لا نفعل شيئاً — المتصفح يخرج)
});

function showPage(id){
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  document.getElementById('page-'+id).classList.add('active');
  document.getElementById('mainContent').scrollTop = 0;
  if (id === 'settings') setTimeout(() => { if(typeof initPushToggle==='function') initPushToggle(); }, 150);
}

// ══ زر التواصل العائم ══════════════════
let floatMenuOpen = false;
function toggleContactMenu() {
  floatMenuOpen = !floatMenuOpen;
  const btn      = document.getElementById('floatMainBtn');
  const items    = document.getElementById('floatItems');
  const overlay  = document.getElementById('floatOverlay');
  const icon     = document.getElementById('floatMainIcon');
  if (!btn) return;
  btn.classList.toggle('open', floatMenuOpen);
  items?.classList.toggle('open', floatMenuOpen);
  if (overlay) overlay.classList.toggle('open', floatMenuOpen);
  icon.className = floatMenuOpen ? 'fas fa-times' : 'fas fa-headset';
}
// إغلاق عند الضغط على Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && floatMenuOpen) toggleContactMenu();
});

// ══════════════════════════════════════
// OPEN CATEGORY — INFINITE NESTING
// ══════════════════════════════════════

// ── Stack التنقل — يتتبع المسار الكامل ──────────────────────────────────────
let catNavStack = []; // [{id, name}]

function getCatById(id) {
  return ALL_CATS[String(id)] || ALL_CATS[id] || CATS.find(c=>c.id==id) || null;
}

// ── فتح قسم من الرئيسية (يبدأ stack جديد) ──────────────────────────────────
function openCat(catId, catName){
  catNavStack = [{id: null, name: 'الرئيسية', isHome: true}];
  history.pushState({page:'cat', catId:catId, catName:catName, type:'cat'}, '', '');
  _enterCat(catId, catName);
}

// ── الدخول لقسم (يُضاف للـ stack) ───────────────────────────────────────────
function _enterCat(catId, catName) {
  // منع التكرار: لا تُضف نفس القسم مرتين متتاليتين
  const last = catNavStack[catNavStack.length - 1];
  if (last && last.id == catId) return;

  currentCatId = catId;
  catNavStack.push({id: catId, name: catName});
  _renderCatPage(catId); // هي من تقرر أي صفحة تعرض (cat أو packages)
  // scroll breadcrumb
  setTimeout(()=>{
    const sc = document.getElementById('catCrumbsScroll');
    if(sc) sc.scrollLeft = 0;
  }, 50);
}

// ── الرجوع خطوة واحدة ───────────────────────────────────────────────────────
function catGoBack(fromHistory) {
  if (!fromHistory) { history.back(); return; } // اترك popstate يتولى الباقي
  if (catNavStack.length <= 2) {
    catNavStack = [];
    navTo('home', false);
    return;
  }
  catNavStack.pop();
  const prev = catNavStack[catNavStack.length - 1];
  if (prev.isHome) {
    catNavStack = [];
    navTo('home', false);
  } else {
    currentCatId = prev.id;
    _renderCatPage(prev.id);
  }
}

// ── رسم صفحة قسم بناءً على ID ───────────────────────────────────────────────
function _renderCatPage(catId) {
  const cat   = getCatById(catId) || {};
  const style = getCatStyle(cat.name || '');
  const catImg = cat.image ? SITE_URL + '/' + cat.image : null;

  const headerImg  = document.getElementById('catHeaderImg');
  const headerIcon = document.getElementById('catHeaderIcon');
  if (catImg) {
    headerImg.style.backgroundImage = `url('${catImg}')`;
    headerImg.style.backgroundSize  = 'cover';
    headerImg.style.backgroundPosition = 'center';
    headerIcon.textContent = '';
  } else {
    headerImg.style.backgroundImage = '';
    headerImg.style.background = style.grad;
    headerIcon.textContent = style.icon;
  }
  document.getElementById('catHeaderTitle').textContent = tCategory(cat.id, cat.name || '');

  // الأقسام الفرعية المباشرة + الخدمات المباشرة — مدمجة ومرتبة بـ sort_order
  const subs       = SUB_CATS[String(catId)] || SUB_CATS[catId] || [];
  const directSvcs = SERVICES.filter(s => s.cat_id == catId);
  const allItems   = [];
  subs.forEach(s      => allItems.push({type:'subcat',  data:s, _order: s.sort_order  ?? 0}));
  directSvcs.forEach(s => allItems.push({type:'service', data:s, _order: s.sort_order ?? 0}));
  // ترتيب موحد: sort_order أولاً، ثم id كـ tiebreaker
  allItems.sort((a, b) => a._order - b._order || a.data.id - b.data.id);

  // إذا كان لا يوجد أقسام فرعية ولكن يوجد خدمات → عرض packages مباشرة
  if (subs.length === 0 && directSvcs.length > 0) {
    // هذا قسم نهائي — عرض صفحة الباقات
    const catStyle = getCatStyle(cat.name || '');
    currentPkgCatId = catId;
    const headerIconEl = document.getElementById('pkgHeaderIcon');
    const headerBg     = document.getElementById('pkgHeaderBg');
    const catImg2 = cat.image ? SITE_URL + '/' + cat.image : null;
    if (catImg2) {
      headerIconEl.innerHTML = `<img src="${catImg2}" style="width:80px;height:80px;object-fit:cover;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.5)">`;
    } else {
      headerIconEl.textContent = catStyle.icon;
    }
    if (headerBg) headerBg.style.background = catImg2 ? `url('${catImg2}') center/cover` : catStyle.grad;
    document.getElementById('pkgHeaderName').textContent = tCategory(cat.id, cat.name || '');
    document.getElementById('pkgHeaderSub').textContent  = directSvcs.length + ' باقة متاحة';
    renderPkgList(directSvcs, catStyle.icon, catImg2);
    // أضف للـ stack للرجوع الصحيح، لكن اعرض صفحة الباقات
    _updateCrumbs();
    showPage('packages');
    return;
  }

  document.getElementById('catHeaderSub').textContent = allItems.length + ' عنصر متاح';
  renderCatGrid(allItems, catId);
  _updateCrumbs();
  showPage('cat');
}

// ── تحديث شريط Breadcrumb ────────────────────────────────────────────────────
function _updateCrumbs() {
  const bar    = document.getElementById('catNavCrumbs');
  const scroll = document.getElementById('catCrumbsScroll');
  if (!bar || !scroll) return;

  // لا تظهر إذا كان المسار أقل من مستويين (قسم واحد مباشر)
  if (catNavStack.length <= 2) {
    bar.style.display = 'none';
    return;
  }
  bar.style.display = 'block';

  scroll.innerHTML = catNavStack.map((item, i) => {
    const isCurrent = i === catNavStack.length - 1;
    const sep = i < catNavStack.length - 1
      ? '<span class="cat-crumb-sep"><i class="fas fa-chevron-left"></i></span>' : '';
    const clickable = isCurrent
      ? `class="cat-crumb-btn current"`
      : `class="cat-crumb-btn" onclick="catJumpTo(${i})"`;
    const icon = i === 0 ? '<i class="fas fa-home" style="font-size:.65rem"></i> ' : '';
    return `<button ${clickable}>${icon}${tCategory(item.id, item.name)}</button>${sep}`;
  }).reverse().join(''); // عكس الترتيب لـ RTL
}

// ── القفز لمستوى معين في المسار ─────────────────────────────────────────────
function catJumpTo(stackIndex) {
  if (stackIndex === 0) { catNavStack = []; navTo('home'); return; }
  catNavStack = catNavStack.slice(0, stackIndex + 1);
  const target = catNavStack[catNavStack.length - 1];
  currentCatId = target.id;
  _renderCatPage(target.id);
}

function renderCatGrid(items, parentCatId){
  const grid = document.getElementById('catItemsGrid');
  if(items.length === 0){
    grid.innerHTML = '<div class="empty-state-app" style="grid-column:1/-1"><i class="fas fa-box-open"></i><p>لا توجد خدمات حالياً</p></div>';
    return;
  }
  grid.innerHTML = items.map(item => {
    if(item.type === 'subcat'){
      const sc   = item.data;
      const style= getCatStyle(sc.name);
      const scImg= sc.image ? SITE_URL + '/' + sc.image : null;
      const thumbBg = scImg ? 'transparent' : style.grad;
      const thumbContent = scImg
        ? `<img src="${scImg}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-sm)">`
        : `<div class="game-thumb-icon">${style.icon}</div>`;
      // عدد الأقسام الفرعية المباشرة
      const subChildCount = (SUB_CATS[String(sc.id)] || SUB_CATS[sc.id] || []).length;
      const svcCount      = SERVICES.filter(s=>s.cat_id==sc.id).length;
      const badge = subChildCount > 0
        ? `<div class="game-sub-badge"><i class="fas fa-folder"></i> ${subChildCount}</div>`
        : svcCount > 0
          ? `<div class="game-sub-badge svc"><i class="fas fa-box"></i> ${svcCount}</div>`
          : '';
      const scUnavail = sc.status == 0;
      return `<div class="game-item${scUnavail?' unavail':''}" onclick="${scUnavail?'showToast(\'القسم غير متاح في الوقت الحالي\',\'warning\')':"_enterCat("+sc.id+",'"+escJ(sc.name)+"')"}">
        <div class="game-thumb" style="background:${thumbBg}">
          ${scUnavail?'<div class="unavail-badge"><i class="fas fa-ban" style="font-size:7px;margin-left:2px"></i> غير متاح</div>':''}
          ${thumbContent}
          ${badge}
          <div class="game-thumb-overlay"><div class="game-thumb-brand">${SITE_LABEL}</div></div>
        </div>
        <div class="game-name" style="${scUnavail?'color:#888':''}"> ${tCategory(sc.id, sc.name)}</div>
      </div>`;
    } else {
      const svc  = item.data;
      const style= getCatStyle(svc.cat_name || '');
      return buildSvcCard(svc, style.icon, style.grad);
    }
  }).join('');
}

function buildSvcCard(svc, icon, grad){
  const unavail = svc.status == 0;
  const svcImg = svc.image ? SITE_URL + '/' + svc.image : null;
  const catImg = svc.cat_image ? SITE_URL + '/' + svc.cat_image : null;
  const useImg = svcImg || catImg;
  const thumbBg = useImg ? 'transparent' : grad;
  const thumbContent = useImg
    ? `<img src="${useImg}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-sm)">`
    : `<div class="game-thumb-icon">${icon}</div>`;
  return `<div class="game-item${unavail?' unavail':''}" onclick="${unavail?'showToast(\'المنتج غير متاح في الوقت الحالي\',\'warning\')':'openPkg('+svc.id+')'}">
    <div class="game-thumb" style="background:${thumbBg}">
      ${unavail?'<div class="unavail-badge"><i class="fas fa-ban" style="font-size:7px;margin-left:2px"></i> غير متاح</div>':''}
      ${thumbContent}
      <div class="game-thumb-overlay"><div class="game-thumb-brand">${SITE_LABEL}</div></div>
    </div>
    <div class="game-name" style="${unavail?'color:#888':''}"> ${tService(svc.id, svc.name)}</div>
  </div>`;
}

function filterItems(){
  const q = document.getElementById('catSearch').value.trim().toLowerCase();
  const items = document.querySelectorAll('#catItemsGrid .game-item');
  items.forEach(el=>{
    const name = el.querySelector('.game-name').textContent.toLowerCase();
    el.style.display = name.includes(q) ? '' : 'none';
  });
}

// ══════════════════════════════════════
// OPEN SUB-CAT → PACKAGES LIST
// ══════════════════════════════════════
// openSubCat: الآن _enterCat يتولى كل شيء (تحقق تلقائي من الأطفال)
// الدالة موجودة للتوافق مع الكود القديم
function openSubCat(subCatId, name, icon, grad, imgUrl){
  _enterCat(subCatId, name);
}

function openPkg(svcId){
  const svc = SERVICES.find(s=>s.id==svcId);
  if(!svc) return;
  // ── اعتراض: إذا كانت خدمة أرقام مباشرة → واجهة خاصة ──
  if (svc.service_type === 'numbers_live') {
    openNumbersAppLive(svc);
    return;
  }
  const style = getCatStyle(svc.cat_name||'');
  openServiceSheet(svc, style.icon);
}

function backFromPackages(){
  if (catNavStack.length > 1) {
    catNavStack.pop();
    const prev = catNavStack[catNavStack.length - 1];
    if (!prev || prev.isHome) { catNavStack = []; navTo('home', false); return; }
    currentCatId = prev.id;
    _renderCatPage(prev.id);
  } else {
    navTo('home', false);
  }
}

function renderPkgList(svcs, icon, imgUrl=''){
  const list = document.getElementById('pkgList');
  if(svcs.length===0){
    list.innerHTML='<div class="empty-state-app"><i class="fas fa-box-open"></i><p>لا توجد باقات</p></div>';
    return;
  }
  list.innerHTML = svcs.map(svc => {
    const unavail = svc.status == 0;
    const svcImg = svc.image ? SITE_URL + '/' + svc.image : null;
    const catImg = svc.cat_image ? SITE_URL + '/' + svc.cat_image : null;
    const useImg = svcImg || catImg || imgUrl;
    const iconContent = useImg
      ? `<img src="${useImg}" style="width:100%;height:100%;object-fit:cover;border-radius:12px">`
      : icon;
    return `<div class="pkg-card${unavail?' unavail':''}" onclick="${unavail?'showToast(\'المنتج غير متاح في الوقت الحالي\',\'warning\')':'openPkg('+svc.id+')'}">
      ${unavail?'<div class="pkg-unavail-ribbon"><i class="fas fa-ban" style="font-size:7px;margin-left:2px"></i> غير متاح</div>':''}
      <div class="pkg-icon" style="${useImg?'padding:0;overflow:hidden':''}">${iconContent}</div>
      <div class="pkg-info">
        <div class="pkg-cat">${tCategory(svc.cat_id, svc.cat_name || '')}</div>
        <div class="pkg-name">${tService(svc.id, svc.name)}</div>
        <div class="pkg-prices">
          <div class="pkg-price-main" data-usp="${svc.price}">${_ptag(svc.price)}</div>
        </div>
      </div>
    </div>`;
  }).join('');
}

// ══════════════════════════════════════
// BOTTOM SHEET
// ══════════════════════════════════════
let currentSvc = null;

window.refreshLanguageCatalog = function(){
  const catPage = document.getElementById('page-cat');
  const pkgPage = document.getElementById('page-packages');
  if (typeof currentCatId !== 'undefined' && (catPage?.classList.contains('active') || pkgPage?.classList.contains('active'))) {
    _renderCatPage(currentCatId);
  }
  if (currentSvc) {
    const title = document.getElementById('sheetTitle');
    const cat = document.getElementById('sheetCat');
    if (title) title.textContent = tService(currentSvc.id, currentSvc.name);
    if (cat) cat.textContent = tCategory(currentSvc.cat_id, currentSvc.cat_name || '');
    const desc = (currentSvc.description || '').trim();
    const descText = document.getElementById('sheetDescText');
    if (descText && desc) descText.textContent = tServiceDescription(currentSvc.id, desc);
    const fields = SVC_FIELDS[currentSvc.id] || [];
    document.querySelectorAll('#sheetDynFields .sheet-field').forEach((row, index) => {
      const field = fields[index]; if (!field) return;
      const label = row.querySelector('label');
      if (label) label.innerHTML = tField(field.id, field.field_label, 'label') + (field.is_required==1 ? ' <span style="color:var(--red)">*</span>' : '');
      const input = row.querySelector('.field-input');
      if (!input) return;
      if (field.field_type === 'select') {
        Array.from(input.options).forEach((option, oi) => {
          if (oi === 0) option.textContent = '-- ' + t('اختر') + ' --';
          else {
            const raw = (field.field_options || '').split('\\n').map(o=>o.trim()).filter(Boolean)[oi - 1] || '';
            option.textContent = tFieldOption(field.id, oi - 1, raw.split('|')[0].trim());
          }
        });
      } else input.placeholder = tField(field.id, field.field_label, 'label');
    });
  }
};

function openServiceSheet(svc, icon){
  currentSvc = svc;
  document.getElementById('sheetServiceId').value = svc.id;
  // فحص كوبونات — أظهر الحقل فقط إذا وُجد كوبون للخدمة
  resetCoupon();
  const _cs = document.getElementById('couponSection');
  if (_cs) _cs.style.display = 'none';
  fetch('<?= SITE_URL ?>/api/check_coupons.php?service_id=' + svc.id, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(d){ if (d.has_coupons && _cs) _cs.style.display = 'flex'; })
    .catch(function(){});
  document.getElementById('sheetTitle').textContent = tService(svc.id, svc.name);
  document.getElementById('sheetCat').textContent = tCategory(svc.cat_id, svc.cat_name || '');

  // وصف الخدمة
  const descBox  = document.getElementById('sheetDescBox');
  const descText = document.getElementById('sheetDescText');
  const desc = (svc.description || '').trim();
  const translatedDesc = desc ? tServiceDescription(svc.id, desc) : '';
  if (translatedDesc && descBox && descText) {
    descText.textContent = translatedDesc;
    descBox.style.display = 'block';
  } else if (descBox) {
    descBox.style.display = 'none';
  }
  // Update sheet icon/image
  const sheetIconEl = document.getElementById('sheetHeaderIcon');
  if (sheetIconEl) {
    const svcImg = svc.image ? SITE_URL + '/' + svc.image : null;
    const catImg = svc.cat_image ? SITE_URL + '/' + svc.cat_image : null;
    const useImg = svcImg || catImg;
    if (useImg) sheetIconEl.innerHTML = `<img src="${useImg}" style="width:52px;height:52px;object-fit:cover;border-radius:12px">`;
    else sheetIconEl.textContent = icon;
  }

  // Dynamic fields
  const fields = SVC_FIELDS[svc.id] || [];
  const container = document.getElementById('sheetDynFields');
  container.innerHTML = fields.map(f => {
    const req = f.is_required==1 ? 'required' : '';
    const star = f.is_required==1 ? '<span style="color:var(--red)">*</span>' : '';
    let inp;
    if(f.field_type==='select' && f.field_options){
      const opts = f.field_options.split('\n').map(o=>o.trim()).filter(Boolean);
      inp = `<select name="fields[${f.field_name}]" class="field-input" ${req}><option value="">-- ${t('اختر')} --</option>${opts.map((o,oi)=>{ const parts=o.split('|'); const value=parts.length>1?parts[1].trim():o; const label=parts[0].trim(); return `<option value="${value}">${tFieldOption(f.id, oi, label)}</option>`; }).join('')}</select>`;
    } else {
      const placeholderText = f.placeholder ? tFieldPlaceholder(f.id, f.placeholder) : tField(f.id, f.field_label, 'label');
      inp = `<input type="${f.field_type}" name="fields[${f.field_name}]" class="field-input has-icon" placeholder="${placeholderText}" ${req}>
             <i class="fas fa-${f.field_type==='email'?'envelope':'user'} field-icon"></i>`;
    }
    return `<div class="sheet-field"><label>${tField(f.id, f.field_label, 'label')} ${star}</label><div class="field-icon-wrap">${inp}</div></div>`;
  }).join('');

  // Quantity
  const showQty = (svc.max_qty > 1);
  document.getElementById('sheetQtyWrap').style.display = showQty ? 'block' : 'none';
  const qtyEl = document.getElementById('sheetQty');
  qtyEl.min = svc.min_qty; qtyEl.max = svc.max_qty; qtyEl.value = svc.min_qty;
  if(!showQty){
    // hidden quantity = 1
    let hq = document.getElementById('hiddenQty');
    if(!hq){ hq=document.createElement('input'); hq.type='hidden'; hq.name='quantity'; hq.id='hiddenQty'; document.getElementById('orderForm').appendChild(hq); }
    hq.value = 1;
  }

  updateTotal();

  document.getElementById('sheetOverlay').classList.add('show');
  document.getElementById('bottomSheet').classList.add('open');
}

function closeSheet(){
  var overlay = document.getElementById('sheetOverlay');
  var sheet   = document.getElementById('bottomSheet');
  if (overlay) overlay.classList.remove('show');
  if (sheet)   { sheet.classList.remove('open'); sheet.style.transform = ''; }
  // إعادة تعيين الفورم لأجل المرة القادمة
  currentSvc = null;
  var form = document.getElementById('orderForm');
  if (form) form.reset();
  var dynFields = document.getElementById('sheetDynFields');
  if (dynFields) dynFields.innerHTML = '';
  var si = document.getElementById('sheetServiceId');
  if (si) si.value = '';
  var couponMsg = document.getElementById('couponMsg');
  if (couponMsg) { couponMsg.style.display='none'; couponMsg.innerHTML=''; }
  var couponInput = document.getElementById('couponCodeInput');
  if (couponInput) couponInput.value = '';
  var couponSection = document.getElementById('couponSection');
  if (couponSection) couponSection.style.display = 'none';
  if (typeof resetCoupon === 'function') resetCoupon();
  // إعادة الزر لحالته الطبيعية
  var btn = document.getElementById('sheetBuyBtn');
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> شـراء الآن'; }
}

function changeQty(d){
  const el = document.getElementById('sheetQty');
  let v = parseInt(el.value)+d;
  v = Math.max(parseInt(el.min),Math.min(parseInt(el.max),v));
  el.value = v; updateTotal();
}

function updateTotal(){
  if(!currentSvc) return;
  const q = parseInt(document.getElementById('sheetQty').value)||1;
  const total = parseFloat(currentSvc.price)*q;
  var _tel=document.getElementById('sheetTotalPrice'); if(_tel) _tel.innerHTML=_ptag(total);
  const btn = document.getElementById('sheetBuyBtn');
  if(btn && IS_LOGGED){
    btn.disabled = USER_BALANCE < parseFloat(total);
    btn.innerHTML = USER_BALANCE < parseFloat(total)
      ? '<i class="fas fa-times-circle"></i> رصيد غير كافٍ'
      : '<i class="fas fa-bolt"></i> شـراء الآن';
  }
}

// ══════════════════════════════════════
// TOAST
// ══════════════════════════════════════
let tt;
function showToast(m){
  const t=document.getElementById('toast');
  t.textContent=m; t.classList.add('show');
  clearTimeout(tt); tt=setTimeout(()=>t.classList.remove('show'),2500);
}

// ══════════════════════════════════════
// ══════════════════════════════════════
// HERO SLIDER
// ══════════════════════════════════════
(function() {
  const track  = document.getElementById('sliderTrack');
  const dots   = document.querySelectorAll('.slider-dot');
  if (!track || !dots.length) return;

  const total  = dots.length;
  let current  = 0;
  let autoTimer;
  let startX   = 0;
  let dragging = false;

  function goToSlide(idx) {
    current = (idx + total) % total;
    track.style.transform = `translateX(${current * 100}%)`;
    dots.forEach((d,i) => d.classList.toggle('active', i === current));
  }

  window.goToSlide  = goToSlide;
  window.sliderStep = (dir) => { resetAuto(); goToSlide(current - dir); };

  function resetAuto() {
    clearInterval(autoTimer);
    if (total > 1) autoTimer = setInterval(() => goToSlide(current + 1), 4000);
  }

  // التمرير بالسحب (swipe)
  const wrap = document.getElementById('heroSlider');
  wrap.addEventListener('touchstart', e => { startX = e.touches[0].clientX; dragging = true; resetAuto(); }, {passive:true});
  wrap.addEventListener('touchend',   e => {
    if (!dragging) return;
    const diff = startX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 40) goToSlide(current + (diff > 0 ? 1 : -1));
    dragging = false;
  });

  resetAuto();
})();

// ── تنقل السلايدر للأقسام والخدمات ──
function sliderNav(type, id) {
  if (type === 'cat') {
    // انتقل لصفحة الخدمات وافتح القسم
    navTo('services');
    setTimeout(() => {
      const catEl = document.querySelector('[data-cat-id="'+id+'"]');
      if (catEl) catEl.click();
    }, 200);
  } else if (type === 'svc') {
    navTo('services');
    setTimeout(() => {
      const svcEl = document.querySelector('[data-svc-id="'+id+'"]');
      if (svcEl) svcEl.click();
    }, 200);
  }
}

// ══════════════════════════════════════
// HELPERS
// ══════════════════════════════════════
function escJ(s){ return (s+'').replace(/'/g,"\\'"); }

// ══════════════════════════════════════════════════════════
//  AUTH SYSTEM - نظام تسجيل الدخول والتسجيل
// ══════════════════════════════════════════════════════════
const AUTH_API = SITE_URL + '/api/auth.php';

// فتح نافذة Auth
function openAuth(tab = 'login') {
  clearAuthErrors();
  // Reset 2FA screen if open
  const f2 = document.getElementById('form2FA');
  if (f2) { f2.style.display='none'; f2.classList.remove('active'); }
  const fl = document.getElementById('formLogin');
  if (fl) { fl.style.display=''; }
  switchTab(tab);
  document.getElementById('authOverlay').classList.add('show');
  setTimeout(() => document.getElementById('authSheet').classList.add('open'), 10);
  document.getElementById('authSuccess').classList.remove('show');
  document.getElementById('formLogin').classList.add('active');
  document.getElementById('formRegister').classList.remove('active');
}

// إغلاق نافذة Auth
function closeAuth() {
  document.getElementById('authSheet').classList.remove('open');
  setTimeout(() => document.getElementById('authOverlay').classList.remove('show'), 350);
}

// إغلاق عند النقر على الخلفية
function closeAuthOnBg(e) {
  if (e.target === document.getElementById('authOverlay')) closeAuth();
}

// التبديل بين تبويبات Login/Register
function switchTab(tab) {
  clearAuthErrors();
  const isLogin = tab === 'login';
  document.getElementById('tabLogin').classList.toggle('active', isLogin);
  document.getElementById('tabRegister').classList.toggle('active', !isLogin);
  document.getElementById('formLogin').classList.toggle('active', isLogin);
  document.getElementById('formRegister').classList.toggle('active', !isLogin);
  document.getElementById('authHeaderTitle').textContent = isLogin ? 'مرحباً بك' : 'إنشاء حساب جديد';
  document.getElementById('authHeaderSub').textContent = isLogin ? 'سجل دخولك لبدء الشحن' : 'انضم لمنصتنا مجاناً';
}

// إظهار خطأ
function showAuthError(msg) {
  const el = document.getElementById('authError');
  document.getElementById('authErrorMsg').textContent = msg;
  el.classList.add('show');
  el.style.animation = 'none';
  el.offsetHeight; // reflow
  el.style.animation = 'shake .3s ease';
}
function clearAuthErrors() {
  document.getElementById('authError').classList.remove('show');
  document.querySelectorAll('.auth-input').forEach(i => i.classList.remove('error','success'));
}

// إظهار نجاح
function showAuthSuccess(title, sub) {
  document.getElementById('formLogin').classList.remove('active');
  document.getElementById('formRegister').classList.remove('active');
  document.getElementById('authError').classList.remove('show');
  const sc = document.getElementById('authSuccess');
  if (title && title.includes('نجاح')) { playSuccessSound(); triggerVibration([80, 40, 80]); }
  document.getElementById('successTitle').textContent = title;
  document.getElementById('successSub').textContent = sub;
  sc.classList.add('show');
}

// تبديل ظهور كلمة المرور
function togglePw(id, icon) {
  const inp = document.getElementById(id);
  const isText = inp.type === 'text';
  inp.type = isText ? 'password' : 'text';
  icon.className = isText ? 'fas fa-eye auth-pw-toggle' : 'fas fa-eye-slash auth-pw-toggle';
}

// قوة كلمة المرور
function checkPwStrength(val) {
  const bar = document.getElementById('pwBar');
  const lbl = document.getElementById('pwLabel');
  let score = 0;
  if (val.length >= 6) score++;
  if (val.length >= 10) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;
  const levels = [
    {w:'0%',  c:'var(--red)',   t:''},
    {w:'25%', c:'var(--red)',   t:'ضعيفة جداً'},
    {w:'50%', c:'var(--gold)',  t:'متوسطة'},
    {w:'75%', c:'#4fc3f7',     t:'جيدة'},
    {w:'100%',c:'var(--green)', t:'قوية جداً ✓'},
  ];
  const lvl = levels[Math.min(score, 4)];
  bar.style.width = lvl.w;
  bar.style.background = lvl.c;
  lbl.textContent = lvl.t;
  lbl.style.color = lvl.c;
}

// ── تسجيل الدخول ──
let _loginUser = '', _loginPass = '';
let totpTimerInterval = null;

// ── Device ID ─────────────────────────────────────────────────────────────
// يُولَّد مرة واحدة ويُخزَّن في localStorage
// يتغيّر فقط عند مسح بيانات المتصفح
const _DEVICE_KEY = '_njaz_did';
function getDeviceId() {
  let did = localStorage.getItem(_DEVICE_KEY);
  if (!did) {
    did = 'did_' + ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
      (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
    );
    localStorage.setItem(_DEVICE_KEY, did);
  }
  return did;
}

async function doLogin() {
  clearAuthErrors();
  const user = document.getElementById('loginUser').value.trim();
  const pass = document.getElementById('loginPass').value;
  if (!user) { document.getElementById('loginUser').classList.add('error'); showAuthError('أدخل اسم المستخدم أو البريد'); return; }
  if (!pass) { document.getElementById('loginPass').classList.add('error'); showAuthError('أدخل كلمة المرور'); return; }

  const btn = document.getElementById('loginBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action','login');
    fd.append('login', user);
    fd.append('password', pass);
    fd.append('device_id', getDeviceId());   // ← device_id من localStorage
    const r = await fetch(AUTH_API, {method:'POST', body:fd});
    const d = await r.json();

    if (d.need_2fa) {
      _loginUser = user;
      _loginPass = pass;
      btn.classList.remove('loading'); btn.disabled = false;
      show2FAScreen();
    } else if (d.device_pending) {
      showDevicePendingScreen(d.pending_user || user, d.pending_did || getDeviceId(), d.email_sent, d.email_hint);
    } else if (d.ok) {
      showAuthSuccess('مرحباً ' + d.name + ' 👋', 'جاري تحديث حسابك...');
      localStorage.removeItem('pending_ref_code');
      onAuthSuccess(d);
    } else {
      showAuthError(d.msg);
      document.getElementById('loginPass').classList.add('error');
    }
  } catch(e) { showAuthError('خطأ في الاتصال، حاول مجدداً'); }
  finally { btn.classList.remove('loading'); btn.disabled = false; }
}

// ── شاشة 2FA ─────────────────────────────────────────────────────────────────
function showDevicePendingScreen(pendingUser, pendingDid, emailSent, emailHint) {
  // إخفاء كل الـ forms
  ['formLogin','formRegister','form2FA'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.style.display='none'; el.classList.remove('active'); }
  });
  const tabs = document.getElementById('authTabs');
  if (tabs) tabs.style.display = 'none';
  document.getElementById('devicePendingScreen').style.display = 'block';

  // تحديث العنوان
  const title = document.getElementById('authHeaderTitle');
  const sub   = document.getElementById('authHeaderSub');
  if (title) title.textContent = 'جهاز غير مصرح';
  if (sub)   sub.textContent   = emailSent ? 'تحقق من بريدك الإلكتروني' : 'تواصل مع الدعم';

  // إظهار/إخفاء بلوك إشعار البريد
  const emailBox   = document.getElementById('devicePendingEmailBox');
  const noEmailBox = document.getElementById('devicePendingNoEmail');
  const hintEl     = document.getElementById('dpEmailHint');
  if (emailSent) {
    if (hintEl) hintEl.textContent = emailHint || '';
    if (emailBox) emailBox.style.display = 'block';
    if (noEmailBox) noEmailBox.style.display = 'none';
  } else {
    if (emailBox) emailBox.style.display = 'none';
    if (noEmailBox) noEmailBox.style.display = 'block';
  }

  // ملء بيانات الشاشة
  const siteName = SITE_LABEL || document.getElementById('dpSite')?.textContent || '';
  const uEl  = document.getElementById('dpUser');
  const dEl  = document.getElementById('dpDid');
  const waBtn = document.getElementById('deviceWaBtn');
  if (uEl)  uEl.textContent  = pendingUser || '—';
  if (dEl)  dEl.textContent  = pendingDid  || getDeviceId();

  // بناء رسالة واتساب
  const waMsg = encodeURIComponent(
    'مرحبا، أنا من موقع ' + siteName + '\n' +
    'اسم المستخدم: ' + (pendingUser || '-') + '\n' +
    'رقم الجهاز: ' + (pendingDid || getDeviceId()) + '\n' +
    'أرجو تصريح الجهاز.'
  );
  if (waBtn) waBtn.href = 'https://wa.me/967781225550?text=' + waMsg;
}

function closeAuthModal() {
  const overlay = document.getElementById('authOverlay');
  if (overlay) {
    overlay.classList.remove('open');
    setTimeout(() => { overlay.style.display='none'; }, 300);
  }
}

function show2FAScreen() {
  document.getElementById('formLogin').style.display = 'none';
  document.getElementById('formLogin').classList.remove('active');
  const f2 = document.getElementById('form2FA');
  f2.style.display = 'block';
  f2.classList.add('active');
  clearAuthErrors();
  // reset OTP digits
  document.querySelectorAll('#loginOtpRow .auth-2fa-digit').forEach(i => i.value = '');
  setTimeout(() => {
    const first = document.querySelector('#loginOtpRow .auth-2fa-digit');
    if (first) first.focus();
  }, 100);
  // init OTP auto-advance
  init2FAInputs();
  // start timer
  startTotpTimer();
}

function back2FA() {
  document.getElementById('form2FA').style.display = 'none';
  document.getElementById('form2FA').classList.remove('active');
  const fl = document.getElementById('formLogin');
  fl.style.display = '';
  fl.classList.add('active');
  clearAuthErrors();
  if (totpTimerInterval) { clearInterval(totpTimerInterval); totpTimerInterval = null; }
}

function init2FAInputs() {
  const inputs = document.querySelectorAll('#loginOtpRow .auth-2fa-digit');
  inputs.forEach((inp, idx) => {
    // remove old listeners by cloning
    const clone = inp.cloneNode(true);
    inp.parentNode.replaceChild(clone, inp);
  });
  const fresh = document.querySelectorAll('#loginOtpRow .auth-2fa-digit');
  fresh.forEach((inp, idx) => {
    inp.addEventListener('input', e => {
      const val = e.target.value.replace(/\D/,'');
      e.target.value = val;
      if (val && idx < fresh.length - 1) fresh[idx+1].focus();
      // auto-submit when all filled
      const code = Array.from(fresh).map(i=>i.value).join('');
      if (code.length === 6) doLogin2FA();
    });
    inp.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !inp.value && idx > 0) {
        fresh[idx-1].focus(); fresh[idx-1].value = '';
      }
    });
    inp.addEventListener('paste', e => {
      e.preventDefault();
      const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      p.split('').forEach((ch,i)=>{ if(fresh[i]) fresh[i].value=ch; });
      if(fresh[Math.min(p.length,5)]) fresh[Math.min(p.length,5)].focus();
      if (p.length === 6) setTimeout(doLogin2FA, 100);
    });
  });
}

function startTotpTimer() {
  if (totpTimerInterval) clearInterval(totpTimerInterval);
  function tick() {
    const sec = 30 - (Math.floor(Date.now()/1000) % 30);
    const el = document.getElementById('totpTimer');
    if (el) {
      el.textContent = sec + 's';
      el.style.color = sec <= 5 ? '#ff4455' : 'var(--cyan)';
    }
  }
  tick();
  totpTimerInterval = setInterval(tick, 1000);
}

async function doLogin2FA() {
  clearAuthErrors();
  const inputs = document.querySelectorAll('#loginOtpRow .auth-2fa-digit');
  const code   = Array.from(inputs).map(i=>i.value).join('');
  if (code.length !== 6) {
    inputs.forEach(i=>{ i.style.borderColor='#ff4455'; setTimeout(()=>i.style.borderColor='',700); });
    return;
  }

  const btn = document.getElementById('twoFaBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action','login');
    fd.append('login',    _loginUser);
    fd.append('password', _loginPass);
    fd.append('totp_code', code);
    const r = await fetch(AUTH_API, {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      if (totpTimerInterval) clearInterval(totpTimerInterval);
      showAuthSuccess('مرحباً ' + d.name + ' 👋', 'جاري تحديث حسابك...');
      onAuthSuccess(d);
    } else {
      showAuthError(d.msg || 'رمز غير صحيح');
      inputs.forEach(i=>{ i.value=''; i.style.borderColor='#ff4455'; setTimeout(()=>i.style.borderColor='',800); });
      const first = document.querySelector('#loginOtpRow .auth-2fa-digit');
      if (first) first.focus();
    }
  } catch(e) { showAuthError('خطأ في الاتصال'); }
  finally { btn.classList.remove('loading'); btn.disabled = false; }
}

// ── إنشاء حساب ──
async function doRegister() {
  clearAuthErrors();
  const user  = document.getElementById('regUser').value.trim();
  const email = document.getElementById('regEmail').value.trim();
  const name  = document.getElementById('regName').value.trim();
  const phone = document.getElementById('regPhone').value.trim();
  const pass  = document.getElementById('regPass').value;
  const pass2 = document.getElementById('regPass2').value;

  const dialCode = document.getElementById('regDialCodeVal')?.value || '+966';
  const fullPhone = phone ? dialCode + phone : '';

  if (user.length < 3)    { document.getElementById('regUser').classList.add('error');  showAuthError('اسم المستخدم 3 أحرف على الأقل'); return; }
  if (name.length < 2)    { document.getElementById('regName').classList.add('error');  showAuthError('الاسم الكامل مطلوب'); return; }
  if (!email.includes('@')){ document.getElementById('regEmail').classList.add('error'); showAuthError('أدخل بريد إلكتروني صحيح'); return; }
  if (phone.length < 6)   { document.getElementById('regPhone').classList.add('error'); showAuthError('رقم الهاتف مطلوب (6 أرقام على الأقل)'); return; }
  if (pass.length < 6)    { document.getElementById('regPass').classList.add('error');  showAuthError('كلمة المرور 6 أحرف على الأقل'); return; }
  if (pass !== pass2)     { document.getElementById('regPass2').classList.add('error'); showAuthError('كلمتا المرور غير متطابقتين'); return; }

  const btn = document.getElementById('registerBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action','register'); fd.append('username',user); fd.append('device_id', getDeviceId()); fd.append('email',email);
    fd.append('full_name',name); fd.append('phone',fullPhone);
    fd.append('password',pass); fd.append('password2',pass2);
    const refCode = (document.getElementById('regReferral')?.value || '').trim().toUpperCase();
    if (refCode) fd.append('referral_code', refCode);
    const r = await fetch(AUTH_API, {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) {
      showAuthSuccess('تم إنشاء حسابك 🎉', 'مرحباً ' + d.name + '! جاري التحديث...');
      onAuthSuccess(d);
    } else {
      showAuthError(d.msg);
    }
  } catch(e) { showAuthError('خطأ في الاتصال، حاول مجدداً'); }
  finally { btn.classList.remove('loading'); btn.disabled = false; }
}

// ── تحديث الواجهة بعد الدخول ──
function onAuthSuccess(d) {
  showToast('✅ ' + d.msg);
  setTimeout(() => {
    location.reload();
  }, 900);
}

// تحديث هيدر التطبيق
function updateHeaderForUser(d) {
  // استبدال زر الدخول بـ chip المستخدم
  const headerRight = document.querySelector('.header-right');
  const loginBtn = headerRight.querySelector('button.header-btn');
  if (loginBtn) {
    const initials = (d.name||'U').charAt(0).toUpperCase();
    const chip = document.createElement('div');
    chip.className = 'user-chip';
    chip.onclick = () => navTo('profile');
    chip.innerHTML = `<div class="user-avatar">${initials}</div><div class="user-name-chip">${d.name}</div>`;
    loginBtn.replaceWith(chip);
  }
  // إظهار زر الإدارة إذا كان Admin
  if (d.role === 'admin') {
    const adminBtn = document.createElement('a');
    adminBtn.href = SITE_URL + '/admin/';
    adminBtn.className = 'header-btn';
    adminBtn.style.color = 'var(--gold)';
    adminBtn.innerHTML = '<i class="fas fa-cog"></i>';
    headerRight.appendChild(adminBtn);
  }
}

// تحديث شريط الرصيد
function updateBalanceBar(balance) {
  updateAllBalances(balance);
  const reloadBtn = document.querySelector('.balance-reload');
  if (reloadBtn) {
    reloadBtn.onclick = () => navTo('wallet');
    reloadBtn.innerHTML = '<i class="fas fa-wallet"></i> محفظتي';
  }
}

// تحديث صفحة الملف الشخصي ديناميكياً
function updateProfilePage(d) {
  const profPage = document.getElementById('page-profile');
  if (!profPage) return;
  const gate = profPage.querySelector('.auth-gate-box');
  if (gate) {
    gate.outerHTML = `
    <div class="profile-header-card">
      <div class="profile-avatar">${(d.name||'U').charAt(0).toUpperCase()}</div>
      <div class="profile-name">${d.name}</div>
      <div class="profile-id">العميل رقم: ${d.uid}</div>
      <div class="profile-badge"><i class="fas fa-check-circle"></i> حساب نشط</div>
    </div>
    <div class="profile-menu-item" onclick="navTo('wallet')">
      <div class="pmi-icon" style="background:rgba(0,230,118,0.12);color:var(--green)"><i class="fas fa-wallet"></i></div>
      <div class="pmi-label">محفظتي</div>
      <div style="color:var(--cyan);font-size:13px;font-weight:700">${d.balance}</div>
      <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
    </div>
    <div class="profile-menu-item" onclick="navTo('orders')">
      <div class="pmi-icon" style="background:rgba(30,111,255,0.12);color:var(--primary)"><i class="fas fa-shopping-bag"></i></div>
      <div class="pmi-label">طلباتي</div>
      <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
    </div>
    ${d.role==='admin' ? `<a href="${SITE_URL}/admin/" class="profile-menu-item">
      <div class="pmi-icon" style="background:rgba(245,166,35,0.12);color:var(--gold)"><i class="fas fa-cog"></i></div>
      <div class="pmi-label">لوحة الإدارة</div>
      <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
    </a>` : ''}
    <div class="profile-menu-item" onclick="doLogout()">
      <div class="pmi-icon" style="background:rgba(255,23,68,0.12);color:var(--red)"><i class="fas fa-sign-out-alt"></i></div>
      <div class="pmi-label">تسجيل الخروج</div>
      <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
    </div>`;
  }
  // تحديث auth-gate-box في باقي الصفحات
  document.querySelectorAll('.auth-gate-box').forEach(g => {
    g.outerHTML = `<div class="empty-state-app"><i class="fas fa-sync-alt"></i><p>انقر هنا لتحديث الصفحة</p></div>`;
  });
  // تحديث زر الشراء في الـ sheet
  const buyLink = document.querySelector('.bottom-sheet .sheet-buy-btn[type="button"]');
 if (buyLink && buyLink.getAttribute('onclick') === "openAuth('login')") {
    buyLink.type = 'submit';
    buyLink.removeAttribute('onclick');
    buyLink.innerHTML = '<i class="fas fa-bolt"></i> شـراء الآن';
  }
}

// ── تسجيل الخروج ──
async function doLogout() {
  if (!confirm('هل تريد تسجيل الخروج؟')) return;
  try {
    const fd = new FormData(); fd.append('action','logout');
    await fetch(AUTH_API, {method:'POST', body:fd, credentials:'same-origin'});
  } catch(e) {}
  showToast('👋 تم تسجيل الخروج');
  setTimeout(() => {
    window.location.href = '<?= SITE_URL ?>/logout.php';
  }, 600);
}

// Enter key support in auth forms
document.addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  if (document.getElementById('authSheet').classList.contains('open')) {
    if (document.getElementById('formLogin').classList.contains('active')) doLogin();
    else if (document.getElementById('formRegister').classList.contains('active')) doRegister();
  }
});


// ══════════════════════════════════════
// SIDEBAR
// ══════════════════════════════════════
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('open');
}

// ══════════════════════════════════════
// TOPUP SYSTEM
// ══════════════════════════════════════
const EXCHANGE_RATES = <?= json_encode(array_column($exchangeRates, null, 'currency_code'), JSON_UNESCAPED_UNICODE) ?>;
const PAYMENT_METHODS_DATA = <?= json_encode(array_map(function($m) {
  $m['image'] = !empty($m['image']) ? SITE_URL . '/' . $m['image'] : '';
  return $m;
}, $paymentMethods), JSON_UNESCAPED_UNICODE) ?>;

let currentCurrencyRate   = 1.0;
let currentCurrencyCode   = 'USD';
let currentCurrencySymbol = '$';
let selectedMethodId      = 0;

function openTopupSheet() {
  if (!IS_LOGGED) { openAuth('login'); return; }

  closeSheet();
  closeSidebar();
  document.body.style.overflow = '';

  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  const pg = document.getElementById('page-topup');
  if (pg) { pg.classList.add('active'); pg.scrollTop = 0; }

  document.getElementById('topupStep1').style.display = 'block';
  document.getElementById('topupStep2').style.display = 'none';
  const step3 = document.getElementById('topupStep3');
  if (step3) step3.style.display = 'none';

  history.pushState({page:'topup', type:'page'}, '', '');
}
// ── إشعار توثيق الهوية ─────────────────────────────────────────────────────
function _showKycRequiredToast() {
  // أظهر رسالة جميلة
  const msg = document.createElement('div');
  msg.style.cssText = [
    'position:fixed','top:0','left:0','right:0','bottom:0',
    'background:rgba(0,0,0,.75)','z-index:99999',
    'display:flex','align-items:center','justify-content:center','padding:20px'
  ].join(';');
  msg.innerHTML = `
    <div style="background:var(--card);border:1.5px solid rgba(245,166,35,.35);border-radius:20px;padding:28px 22px;max-width:340px;width:100%;text-align:center">
      <div style="font-size:3rem;margin-bottom:10px">🔐</div>
      <div style="font-size:1.05rem;font-weight:900;color:#f5a623;margin-bottom:8px">يجب توثيق الحساب أولاً</div>
      <div style="font-size:.84rem;color:var(--text3);line-height:1.7;margin-bottom:20px">
        لاستخدام طرق الدفع وشحن الرصيد يجب إثبات هويتك أولاً.<br>
        اذهب إلى <strong style="color:#fff">تحقق الهوية</strong> من القائمة الجانبية.
      </div>
      <button id="_kycGoBtn"
        style="width:100%;padding:14px;background:linear-gradient(135deg,#f5a623,#e08c00);border:none;border-radius:14px;color:#000;font-family:var(--font);font-size:.95rem;font-weight:900;cursor:pointer;margin-bottom:10px">
        <i class="fas fa-id-card"></i> الذهاب إلى التوثيق
      </button>
      <button onclick="this.closest('div[style]').remove()"
        style="width:100%;padding:11px;background:var(--bg2);border:1px solid var(--border2);border-radius:14px;color:var(--text3);font-family:var(--font);font-size:.85rem;font-weight:700;cursor:pointer">
        إغلاق
      </button>
    </div>
  `;
  document.body.appendChild(msg);
  // زر الذهاب للتوثيق
  msg.querySelector('#_kycGoBtn').addEventListener('click', () => {
    msg.remove();
    navTo('kyc');
  });
  // إغلاق بالضغط خارج الكارد
  msg.addEventListener('click', e => { if (e.target === msg) msg.remove(); });
}

function closeTopupSheet() {
  const cur = document.querySelector('.page.active');
  if (cur && cur.id === 'page-topup') {
    cur.classList.remove('active');
    const pg = document.getElementById('page-wallet');
    if (pg) { pg.classList.add('active'); pg.scrollTop = 0; }
    history.pushState({page:'wallet', type:'page'}, '', '');
  }
}
function closeTopupIfBg(e) {
  // لا حاجة لهذه الدالة بعد الآن - الصفحة كاملة
}

function switchTopupMode(mode) {
  document.getElementById('methodsList-manual').style.display = mode==='manual' ? 'block' : 'none';
  document.getElementById('methodsList-auto').style.display   = mode==='auto'   ? 'block' : 'none';
  var cardEl = document.getElementById('methodsList-card');
  if (cardEl) cardEl.style.display = mode==='card' ? 'block' : 'none';
  var smsEl = document.getElementById('methodsList-sms');
  if (smsEl) smsEl.style.display = mode==='sms' ? 'block' : 'none';
  var usdtEl = document.getElementById('methodsList-usdt');
  // USDT يظهر كبطاقة زر داخل «مباشر»، وتُفتح التفاصيل بالضغط فقط.
  if (usdtEl) usdtEl.style.display = 'none';
  var binanceEl = document.getElementById('methodsList-binance');
  // Binance Pay يظهر كبطاقة داخل «مباشر»، وتُفتح التفاصيل بالضغط فقط.
  if (binanceEl) binanceEl.style.display = 'none';
  document.getElementById('mTabManual').classList.toggle('active', mode==='manual');
  document.getElementById('mTabAuto').classList.toggle('active',   mode==='auto');
  var cardTab = document.getElementById('mTabCard');
  if (cardTab) cardTab.classList.toggle('active', mode==='card');
  var smsTab = document.getElementById('mTabSms');
  if (smsTab) smsTab.classList.toggle('active', mode==='sms');
  const descs = {
    manual: 'أرسل الحوالة وارفع الإيصال — تُفعَّل بعد مراجعة الإدارة',
    auto:   'دفع فوري وآلي — يُضاف رصيدك مباشرة بعد الدفع ⚡',
    card:   'أدخل كود البطاقة — يُشحن رصيدك فوراً 🎫',
    sms:    'أرسل إيداعاً من حسابك إلى حسابنا ثم تحقق تلقائياً — يُشحن رصيدك فوراً ⚡'
  };
  const desc = document.getElementById('modeDescTxt');
  if (desc) desc.textContent = descs[mode] || '';
  if (mode !== 'card' && mode !== 'sms') {
    selectedMethodId = null;
    document.querySelectorAll('.topup-method-item').forEach(m => m.classList.remove('selected','active'));
  }
  if (mode === 'sms') { cancelSmsCheck(); }
  if (mode === 'auto' && typeof usdtOnTabOpen === 'function') { usdtOnTabOpen(); }
}

// ── Binance Pay كبطاقة داخل «مباشر» ───────────────────────────
function openBinanceDetails() {
  var list = document.getElementById('methodsList-binance');
  var auto = document.getElementById('methodsList-auto');
  if (!list) return;
  if (auto) auto.style.display = 'none';
  list.style.display = 'block';
  if (typeof binanceOnTabOpen === 'function') binanceOnTabOpen();
  var sheet = document.getElementById('topupSheet');
  if (sheet) sheet.scrollTop = 0;
}

function closeBinanceDetails() {
  var list = document.getElementById('methodsList-binance');
  var auto = document.getElementById('methodsList-auto');
  if (list) list.style.display = 'none';
  if (auto) auto.style.display = 'block';
  var sheet = document.getElementById('topupSheet');
  if (sheet) sheet.scrollTop = 0;
}

// ── USDT كبطاقة داخل «مباشر» ───────────────────────────────────
function openUsdtDetails() {
  var list = document.getElementById('methodsList-usdt');
  var auto = document.getElementById('methodsList-auto');
  if (!list) return;
  if (auto) auto.style.display = 'none';
  list.style.display = 'block';
  if (typeof usdtOnTabOpen === 'function') usdtOnTabOpen();
  var sheet = document.getElementById('topupSheet');
  if (sheet) sheet.scrollTop = 0;
}

function closeUsdtDetails() {
  var list = document.getElementById('methodsList-usdt');
  var auto = document.getElementById('methodsList-auto');
  if (list) list.style.display = 'none';
  if (auto) auto.style.display = 'block';
  var sheet = document.getElementById('topupSheet');
  if (sheet) sheet.scrollTop = 0;
}

function selectMethod(id, el) {
  // ── فحص توثيق الهوية ───────────────────────────────────────
  if (KYC_STATUS !== 'approved') {
    navTo('wallet');
    _showKycRequiredToast();
    return;
  }
  // ────────────────────────────────────────────────────────────
  selectedMethodId = id;
  document.getElementById('topupMethodId').value = id;

  const method = PAYMENT_METHODS_DATA.find(m => m.id == id);
  if (!method) return;

  // تحديث الهيدر
  document.getElementById('step2Title').textContent = method.name;
  document.getElementById('step2Sub').textContent = method.description || 'اتبع التعليمات أدناه';

  // تحديث أيقونة البطاقة — صورة أو أيقونة
  const icon = document.getElementById('topupAccountIcon');
  const title = document.getElementById('topupAccountTitle');
  icon.style.background = method.color + '22';
  icon.style.color = method.color;
  if (method.image) {
    icon.innerHTML = '<img src="' + method.image + '" style="width:100%;height:100%;object-fit:contain;border-radius:8px;padding:2px">';
  } else {
    icon.innerHTML = '<i class="fas fa-' + method.icon + '"></i>';
  }
  title.textContent = 'بيانات ' + method.name;

  // فلترة العملات حسب إعداد هذه الوسيلة من الإدارة.
  applyMethodCurrencyFilter(method);

  // عرض الحقول
  const container = document.getElementById('methodFields');
  container.innerHTML = '';
  if (method.fields_raw) {
    method.fields_raw.split(';;').forEach(fRaw => {
      const parts = fRaw.split('||');
      if (parts.length < 2) return;
      const [label, value, copyable] = parts;
      const copyBtn = parseInt(copyable) !== 0
        ? `<button class="topup-copy-chip" onclick="copyTopupField(this,'${escJ(value)}')" type="button"><i class="fas fa-copy"></i> نسخ</button>`
        : '';
      container.innerHTML += `
        <div class="topup-field-item">
          <div>
            <div class="topup-field-lbl">${label}</div>
            <div class="topup-field-val">${value}</div>
          </div>
          ${copyBtn}
        </div>`;
    });
  }

  document.getElementById('topupStep1').style.display = 'none';
  document.getElementById('topupStep2').style.display = 'block';
  calcTopupUSD();
}

function applyMethodCurrencyFilter(method) {
  const configured = Array.isArray(method.allowed_currency_codes)
    ? method.allowed_currency_codes.map(c => String(c).trim().toUpperCase()).filter(Boolean)
    : [];
  const chips = Array.from(document.querySelectorAll('#currencyOptions .topup-currency-chip'));
  let firstAllowed = null;
  chips.forEach(chip => {
    const codeEl = chip.querySelector('.topup-chip-code');
    const code = codeEl ? codeEl.textContent.trim().toUpperCase() : '';
    const allowed = !configured.length || configured.includes(code);
    chip.style.display = allowed ? '' : 'none';
    if (allowed && !firstAllowed) firstAllowed = chip;
  });

  const selectedChip = chips.find(chip => chip.classList.contains('active'));
  if (!selectedChip || selectedChip.style.display === 'none') {
    if (firstAllowed) firstAllowed.click();
  }
}

function backToStep1() {
  document.getElementById('topupStep1').style.display = 'block';
  document.getElementById('topupStep2').style.display = 'none';
  const s3 = document.getElementById('topupStep3');
  if (s3) s3.style.display = 'none';
}

function selectCurrency(code, symbol, rate, el) {
  const method = PAYMENT_METHODS_DATA.find(m => m.id == selectedMethodId);
  const allowed = method && Array.isArray(method.allowed_currency_codes) ? method.allowed_currency_codes.map(c => String(c).toUpperCase()) : [];
  if (allowed.length && !allowed.includes(String(code).toUpperCase())) {
    showToast('هذه العملة غير متاحة مع وسيلة الدفع المختارة');
    return;
  }
  currentCurrencyCode   = code;
  currentCurrencySymbol = symbol;
  currentCurrencyRate   = parseFloat(rate);
  document.getElementById('topupCurrencyCode').value  = code;
  document.getElementById('topupCurrencyLabel').textContent = code;
  document.querySelectorAll('.topup-currency-chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  calcTopupUSD();
}

function calcTopupUSD() {
  const amt = parseFloat(document.getElementById('topupAmountInput').value) || 0;
  const usd = amt * currentCurrencyRate;

  // عرض المبلغ
  let display;
  if (usd <= 0)       display = '$0.0000';
  else if (usd < 0.01) display = '$' + usd.toPrecision(4);
  else                display = '$' + usd.toFixed(4);
  document.getElementById('topupUSDAmount').textContent = display;
  document.getElementById('topupAmountHidden').value = amt;

  // شريط التقدم (رمزي)
  const bar = document.getElementById('topupResultBar');
  const pct = Math.min(usd / 100 * 100, 100);
  bar.style.width = (usd > 0 ? Math.max(pct, 8) : 0) + '%';

  // إضاءة البطاقة
  const card = document.getElementById('topupResultBox');
  card.style.opacity = usd > 0 ? '1' : '0.6';
}

function copyTopupField(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.innerHTML = '<i class="fas fa-check"></i> تم';
    btn.classList.add('done');
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> نسخ'; btn.classList.remove('done'); }, 1800);
  }).catch(() => {
    const el = document.createElement('textarea');
    el.value = text; document.body.appendChild(el);
    el.select(); document.execCommand('copy');
    document.body.removeChild(el);
    btn.innerHTML = '<i class="fas fa-check"></i> تم'; btn.classList.add('done');
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> نسخ'; btn.classList.remove('done'); }, 1800);
  });
}

function previewReceipt(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const label   = document.getElementById('receiptLabel');
  const preview = document.getElementById('receiptPreviewImg');
  label.querySelector('.topup-upload-text').textContent = file.name;
  label.querySelector('.topup-upload-hint').textContent = (file.size/1024).toFixed(0) + ' KB — تم الاختيار ✓';
  label.querySelector('.topup-upload-icon').innerHTML = '<i class="fas fa-check-circle" style="color:#00d4aa"></i>';
  label.style.borderColor = 'rgba(0,212,170,.4)';
  if (file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(file);
  }
}

function submitTopup() {
  const amt = parseFloat(document.getElementById('topupAmountInput').value) || 0;
  if (!selectedMethodId) { showToast('اختر طريقة الدفع أولاً'); return; }
  const method = PAYMENT_METHODS_DATA.find(m => m.id == selectedMethodId);
  const allowed = method && Array.isArray(method.allowed_currency_codes) ? method.allowed_currency_codes.map(c => String(c).toUpperCase()) : [];
  if (allowed.length && !allowed.includes(String(currentCurrencyCode).toUpperCase())) {
    showToast('اختر عملة متاحة لهذه الوسيلة');
    return;
  }
  if (amt <= 0) {
    showToast('أدخل المبلغ المُرسَل');
    document.getElementById('topupAmountInput').focus();
    return;
  }
  document.getElementById('topupNotesHidden').value = document.getElementById('topupNotes').value;
  onTopupSuccess(); setTimeout(() => document.getElementById('topupForm').submit(), 300);
}

// ══════════════════════════════════════
// NOTIFICATIONS PAGE
// ══════════════════════════════════════
function handleNotifCardClick(id, url) {
  // تعليم كمقروء
  fetch('<?= SITE_URL ?>/api/notifications.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_read&id=${id}`
  }).catch(()=>{});
  // انتقال
  if (url && url !== 'null' && url.trim() !== '') {
    setTimeout(() => { window.location.href = url; }, 100);
  }
}

function filterNotif(type) {
  document.getElementById('nfAll').classList.toggle('active', type === 'all');
  document.getElementById('nfUnread').classList.toggle('active', type === 'unread');
  const cards = document.querySelectorAll('#notifListWrap .notif-card');
  cards.forEach(c => {
    if (type === 'all') c.style.display = '';
    else c.style.display = c.dataset.read === '0' ? '' : 'none';
  });
}

// عند فتح صفحة الإشعارات: تعليم الكل كمقروء + إخفاء الشارة
function onNotifPageOpen() {
  <?php if (isLoggedIn()): ?>
  fetch('<?= SITE_URL ?>/api/notifications.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'action=mark_read&id=0'
  }).then(() => {
    const badge = document.getElementById('navNotifBadge');
    const hBadge = document.getElementById('notifHeaderDot');
    const pageBadge = document.getElementById('notifPageUnreadBadge');
    if (badge) badge.style.display = 'none';
    if (hBadge) hBadge.style.display = 'none';
    if (pageBadge) pageBadge.style.display = 'none';
    // إزالة النقاط الزرقاء من البطاقات
    document.querySelectorAll('.notif-card.unread').forEach(c => {
      c.classList.remove('unread');
      c.dataset.read = '1';
      const dot = c.querySelector('.notif-unread-dot');
      if (dot) dot.remove();
    });
  }).catch(()=>{});
  <?php endif; ?>
}

// تحديث شارة الإشعارات في النافبار
<?php if (isLoggedIn()): ?>
let _lastNotifId = 0; // آخر ID معروف — نبدأ بـ 0 حتى نجلب الـ ID الحالي أولاً

// عند أول تحميل: نجلب آخر ID موجود بدون تشغيل صوت (لتجنب الأصوات الخاطئة)
fetch('<?= SITE_URL ?>/api/notifications.php?action=check_new&after_id=0')
  .then(r => r.json())
  .then(d => {
    _lastNotifId = d.last_id || 0;
    // تحديث الشارة بالعدد الحالي فقط بدون صوت
    const badge = document.getElementById('navNotifBadge');
    const hDot  = document.getElementById('notifHeaderDot');
    if (badge) {
      if (d.unread_count > 0) { badge.textContent = d.unread_count > 9 ? '9+' : d.unread_count; badge.style.display = 'flex'; }
      else badge.style.display = 'none';
    }
    if (hDot) hDot.style.display = d.unread_count > 0 ? 'block' : 'none';
    // ابدأ الـ polling بعد تهيئة الـ ID
    setTimeout(pollNotifications, 15000);
  }).catch(() => { setTimeout(pollNotifications, 15000); });

function pollNotifications() {
  fetch('<?= SITE_URL ?>/api/notifications.php?action=check_new&after_id=' + _lastNotifId)
    .then(r => r.json())
    .then(d => {
      // تحديث الشارة
      const badge = document.getElementById('navNotifBadge');
      const hDot  = document.getElementById('notifHeaderDot');
      if (badge) {
        if (d.unread_count > 0) { badge.textContent = d.unread_count > 9 ? '9+' : d.unread_count; badge.style.display = 'flex'; }
        else badge.style.display = 'none';
      }
      if (hDot) hDot.style.display = d.unread_count > 0 ? 'block' : 'none';

      // تشغيل الصوت المناسب إذا وصل إشعار جديد
      if (d.new_count > 0 && d.sound) {
        if (d.sound === 'cancel') {
          playCancelSound();
          triggerVibration([100, 50, 100, 50, 200]);
        } else {
          playSuccessSound();
          triggerVibration([80, 40, 80]);
        }
        _lastNotifId = d.last_id;
      }
    }).catch(()=>{});
  setTimeout(pollNotifications, 15000);

  // ── فحص الـ popups أيضاً ─────────────────────────────────
  checkPopups();
}

// ══ نظام الـ Popup المنبثق ═══════════════════════════════════
let _popupQueue    = [];
let _currentPopup  = null;
let _popupActionUrl = null;

// مفتاح localStorage لتتبع الـ popups المعروضة على هذا الجهاز
const POPUP_SEEN_KEY = 'seen_popups_<?= isLoggedIn() ? $_SESSION["user_id"] : "guest" ?>';

function getSeenPopups() {
  try { return JSON.parse(localStorage.getItem(POPUP_SEEN_KEY)) || []; }
  catch(e) { return []; }
}
function clearSeenPopups() {
  localStorage.removeItem(POPUP_SEEN_KEY);
}
function addSeenPopup(id) {
  const seen = getSeenPopups();
  if (!seen.includes(id)) {
    seen.push(id);
    // احتفظ بآخر 50 فقط
    if (seen.length > 50) seen.shift();
    localStorage.setItem(POPUP_SEEN_KEY, JSON.stringify(seen));
  }
}

function checkPopups() {
  const overlay = document.getElementById('notifPopupOverlay');
  if (!overlay) return;
  fetch('<?= SITE_URL ?>/api/get_popups.php')
    .then(r => r.json())
    .then(d => {
      if (!d.popups || !d.popups.length) return;
      const seen = getSeenPopups();
      const unseen = d.popups.filter(p => !seen.map(String).includes(String(p.id)));
      if (unseen.length > 0) {
        _popupQueue = unseen;
        showNextPopup();
      }
    }).catch(() => {});
}

function showNextPopup() {
  if (_popupQueue.length === 0) return;
  const popup = _popupQueue.shift();
  _currentPopup   = popup;
  _popupActionUrl = popup.action_url || null;

  const overlay = document.getElementById('notifPopupOverlay');
  if (!overlay) return;

  document.getElementById('notifPopupTitle').textContent  = popup.title;
  document.getElementById('notifPopupMsg').textContent    = popup.message;
  const btnOk = document.getElementById('notifPopupBtnOk');
  btnOk.textContent  = popup.btn_label || 'موافق';
  const npColor = popup.color || '#6c3fe0';
  document.getElementById('notifPopupBox').style.setProperty('--np-color', npColor);
  const icon  = document.getElementById('notifPopupIcon');
  icon.style.background  = npColor + '22';
  icon.style.color       = npColor;
  document.getElementById('notifPopupIconI').className = 'fas fa-' + (popup.icon || 'bell');

  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  // تشغيل صوت
  try { if (typeof playSuccessSound === 'function') playSuccessSound(); } catch(e) {}

  // تسجيل في localStorage (هذا الجهاز)
  addSeenPopup(popup.id);
  // تسجيل في قاعدة البيانات (show_once)
  const fd = new FormData();
  fd.append('action', 'mark_popup_viewed');
  fd.append('broadcast_id', popup.id);
  fetch('<?= SITE_URL ?>/api/notifications.php', { method:'POST', body:fd }).catch(()=>{});
}

function notifPopupClose() {
  const overlay = document.getElementById('notifPopupOverlay');
  if (overlay) overlay.classList.remove('open');
  document.body.style.overflow = '';
  _currentPopup = null;
  if (_popupQueue.length > 0) setTimeout(showNextPopup, 500);
}

function notifPopupAction() {
  if (_popupActionUrl) window.open(_popupActionUrl, '_blank');
  notifPopupClose();
}

// ── تشغيل فوري عند تحميل الصفحة ─────────────────────────────
<?php if (isLoggedIn()): ?>
// شغّل فوراً بمجرد أن الـ DOM يكون جاهزاً
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(checkPopups, 800);
    setInterval(checkPopups, 600000);
  });
} else {
  // DOM جاهز بالفعل
  setTimeout(checkPopups, 800);
  setInterval(checkPopups, 600000);
}
<?php endif; ?>
<?php endif; ?>

// ══════════════════════════════════════════════════════
// فلوسك — منطق الدفع المباشر
// ══════════════════════════════════════════════════════
<?php if($floosakEnabled): ?>
let floosakPurchaseId = null;
let floosakTimerInterval = null;
let floosakYerRate = <?= (float)(getSetting('yer_to_usd_rate') ?: ($pdo->query("SELECT rate_to_usd FROM exchange_rates WHERE currency_code='YER' AND status=1")->fetchColumn() ?: 0.00059)) ?>;

function openFloosakStep() {
  document.getElementById('topupStep1').style.display = 'none';
  document.getElementById('topupStep2').style.display = 'none';
  document.getElementById('topupStep3').style.display = 'block';
  floosakReset();
}

function floosakReset() {
  document.getElementById('floosakPhaseA').style.display = 'block';
  document.getElementById('floosakPhaseB').style.display = 'none';
  document.getElementById('floosakPhaseC').style.display = 'none';
  document.getElementById('floosakStepSub').textContent = 'أدخل بيانات الدفع';
  document.getElementById('floosakOTP').value = '';
  if (floosakTimerInterval) clearInterval(floosakTimerInterval);
}

function calcFloosakUSD() {
  const yer = parseFloat(document.getElementById('floosakAmount').value) || 0;
  const usd = yer > 0 ? (yer * floosakYerRate).toFixed(4) : 0;
  document.getElementById('floosakUsdPreview').textContent = yer > 0 ? `≈ $${usd} سيُضاف لرصيدك` : '';
}

function setFloosakLoading(btnId, loading, text) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading
    ? '<i class="fas fa-spinner fa-spin"></i> جارٍ المعالجة...'
    : text;
}

function floosakInitiate() {
  let phone = document.getElementById('floosakPhone').value.trim().replace(/\D/g,'');
  const amount = parseFloat(document.getElementById('floosakAmount').value) || 0;

  // تصحيح الرقم تلقائياً — إضافة 967 إن لم تكن موجودة
  if (phone.startsWith('00967')) phone = phone.slice(2);       // 00967 → 967
  if (phone.startsWith('967') && phone.length === 12) {}       // صحيح بالفعل
  else if (phone.startsWith('7') && phone.length === 9) phone = '967' + phone;  // 7XXXXXXXX → 9677XXXXXXXX
  else if (phone.startsWith('7') && phone.length !== 9) { /* خطأ */ }

  // تحديث الحقل بالرقم المصحح
  document.getElementById('floosakPhone').value = phone;

  if (phone.length < 11) {
    document.getElementById('floosakPhone').style.borderColor = '#ff4455';
    document.getElementById('floosakPhone').focus(); return;
  }
  if (amount < 100) {
    document.getElementById('floosakAmount').style.borderColor = '#ff4455';
    document.getElementById('floosakAmount').focus(); return;
  }

  setFloosakLoading('floosakSendOtpBtn', true, '<i class="fas fa-paper-plane"></i> إرسال رمز التحقق');

  fetch('<?=SITE_URL?>/api/floosak_payment.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: `action=floosak_initiate&phone=${encodeURIComponent(phone)}&amount=${amount}`
  })
  .then(r => r.json())
  .then(d => {
    setFloosakLoading('floosakSendOtpBtn', false, '<i class="fas fa-paper-plane"></i> إرسال رمز التحقق');
    if (!d.status) {
      showToast(d.message || 'حدث خطأ', 'error'); return;
    }
    floosakPurchaseId = d.purchase_id;
    document.getElementById('floosakAmountDisplay').textContent = `${parseFloat(amount).toLocaleString()} ريال ≈ $${(amount * floosakYerRate).toFixed(4)}`;
    document.getElementById('floosakPhoneDisplay').textContent = `📱 ${phone}`;
    document.getElementById('floosakStepSub').textContent = 'أدخل رمز التحقق الواصل لهاتفك';
    document.getElementById('floosakPhaseA').style.display = 'none';
    document.getElementById('floosakPhaseB').style.display = 'block';
    document.getElementById('floosakOTP').focus();
    startFloosakTimer(600);
    showToast(d.message || 'تم إرسال رمز التحقق', 'success');
  })
  .catch(() => {
    setFloosakLoading('floosakSendOtpBtn', false, '<i class="fas fa-paper-plane"></i> إرسال رمز التحقق');
    showToast('خطأ في الاتصال', 'error');
  });
}

function floosakConfirm() {
  const otp = document.getElementById('floosakOTP').value.trim();
  if (otp.length !== 6) { showToast('أدخل رمزاً مكوناً من 6 أرقام', 'error'); return; }
  if (!floosakPurchaseId) { showToast('انتهت الجلسة، ابدأ من جديد', 'error'); floosakReset(); return; }

  setFloosakLoading('floosakConfirmBtn', true, '<i class="fas fa-check-circle"></i> تأكيد الدفع');

  fetch('<?=SITE_URL?>/api/floosak_payment.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: `action=floosak_confirm&purchase_id=${floosakPurchaseId}&otp=${encodeURIComponent(otp)}`
  })
  .then(r => r.json())
  .then(d => {
    setFloosakLoading('floosakConfirmBtn', false, '<i class="fas fa-check-circle"></i> تأكيد الدفع');
    if (floosakTimerInterval) clearInterval(floosakTimerInterval);

    document.getElementById('floosakPhaseB').style.display = 'none';
    document.getElementById('floosakPhaseC').style.display = 'block';

    if (d.status) {
      onTopupSuccess();
      document.getElementById('floosakResultIcon').textContent = '✅';
      document.getElementById('floosakResultTitle').textContent = 'تمت العملية بنجاح!';
      document.getElementById('floosakResultMsg').textContent = d.message || 'تم شحن رصيدك بنجاح';
      if (d.new_balance !== undefined) {
        document.getElementById('floosakNewBalance').style.display = 'block';
        document.getElementById('floosakNewBalanceVal').textContent = '$' + parseFloat(d.new_balance).toFixed(4);
        updateAllBalances(d.new_balance);
      }
    } else {
      document.getElementById('floosakResultIcon').textContent = '❌';
      document.getElementById('floosakResultTitle').textContent = 'فشلت العملية';
      document.getElementById('floosakResultMsg').textContent = d.message || 'تحقق من الرمز وحاول مجدداً';
    }
  })
  .catch(() => {
    setFloosakLoading('floosakConfirmBtn', false, '<i class="fas fa-check-circle"></i> تأكيد الدفع');
    showToast('خطأ في الاتصال', 'error');
  });
}

function startFloosakTimer(seconds) {
  if (floosakTimerInterval) clearInterval(floosakTimerInterval);
  let remaining = seconds;
  const el = document.getElementById('floosakOtpTimer');
  function tick() {
    if (remaining <= 0) {
      clearInterval(floosakTimerInterval);
      if (el) el.innerHTML = '<span style="color:#ff4455">⏰ انتهت مهلة الرمز — ابدأ من جديد</span>';
      return;
    }
    const m = Math.floor(remaining / 60), s = remaining % 60;
    if (el) el.textContent = `ينتهي الرمز خلال: ${m}:${s < 10 ? '0' : ''}${s}`;
    remaining--;
  }
  tick();
  floosakTimerInterval = setInterval(tick, 1000);
}
<?php endif; ?>





// ══ اختيار رمز الدولة في التسجيل ══
const REG_COUNTRIES = [
  {f:'🇸🇦',n:'المملكة العربية السعودية',d:'+966'},{f:'🇦🇪',n:'الإمارات العربية المتحدة',d:'+971'},{f:'🇰🇼',n:'الكويت',d:'+965'},{f:'🇶🇦',n:'قطر',d:'+974'},
  {f:'🇧🇭',n:'البحرين',d:'+973'},{f:'🇴🇲',n:'عُمان',d:'+968'},{f:'🇾🇲',n:'اليمن',d:'+967'},{f:'🇮🇶',n:'العراق',d:'+964'},
  {f:'🇸🇾',n:'سوريا',d:'+963'},{f:'🇯🇴',n:'الأردن',d:'+962'},{f:'🇱🇧',n:'لبنان',d:'+961'},{f:'🇵🇸',n:'فلسطين',d:'+970'},
  {f:'🇪🇬',n:'مصر',d:'+20'},{f:'🇱🇾',n:'ليبيا',d:'+218'},{f:'🇹🇳',n:'تونس',d:'+216'},{f:'🇩🇿',n:'الجزائر',d:'+213'},
  {f:'🇲🇦',n:'المغرب',d:'+212'},{f:'🇸🇩',n:'السودان',d:'+249'},{f:'🇸🇴',n:'الصومال',d:'+252'},{f:'🇩🇯',n:'جيبوتي',d:'+253'},
  {f:'🇰🇲',n:'جزر القمر',d:'+269'},{f:'🇲🇷',n:'موريتانيا',d:'+222'},{f:'🇹🇩',n:'تشاد',d:'+235'},{f:'🇪🇷',n:'إريتريا',d:'+291'},
  {f:'🇸🇸',n:'جنوب السودان',d:'+211'},{f:'🇹🇷',n:'تركيا',d:'+90'},{f:'🇮🇷',n:'إيران',d:'+98'},{f:'🇦🇫',n:'أفغانستان',d:'+93'},
  {f:'🇵🇰',n:'باكستان',d:'+92'},{f:'🇮🇳',n:'الهند',d:'+91'},{f:'🇧🇩',n:'بنغلاديش',d:'+880'},{f:'🇱🇰',n:'سريلانكا',d:'+94'},
  {f:'🇳🇵',n:'نيبال',d:'+977'},{f:'🇲🇲',n:'ميانمار',d:'+95'},{f:'🇹🇭',n:'تايلاند',d:'+66'},{f:'🇻🇳',n:'فيتنام',d:'+84'},
  {f:'🇮🇩',n:'إندونيسيا',d:'+62'},{f:'🇲🇾',n:'ماليزيا',d:'+60'},{f:'🇵🇭',n:'الفلبين',d:'+63'},{f:'🇸🇬',n:'سنغافورة',d:'+65'},
  {f:'🇰🇭',n:'كمبوديا',d:'+855'},{f:'🇲🇳',n:'منغوليا',d:'+976'},{f:'🇨🇳',n:'الصين',d:'+86'},{f:'🇯🇵',n:'اليابان',d:'+81'},
  {f:'🇰🇷',n:'كوريا الجنوبية',d:'+82'},{f:'🇹🇼',n:'تايوان',d:'+886'},{f:'🇰🇿',n:'كازاخستان',d:'+7'},{f:'🇺🇿',n:'أوزبكستان',d:'+998'},
  {f:'🇹🇲',n:'تركمانستان',d:'+993'},{f:'🇰🇬',n:'قيرغيزستان',d:'+996'},{f:'🇹🇯',n:'طاجيكستان',d:'+992'},{f:'🇦🇿',n:'أذربيجان',d:'+994'},
  {f:'🇦🇲',n:'أرمينيا',d:'+374'},{f:'🇬🇪',n:'جورجيا',d:'+995'},{f:'🇮🇱',n:'إسرائيل',d:'+972'},{f:'🇺🇸',n:'الولايات المتحدة',d:'+1'},
  {f:'🇨🇦',n:'كندا',d:'+1'},{f:'🇲🇽',n:'المكسيك',d:'+52'},{f:'🇬🇹',n:'غواتيمالا',d:'+502'},{f:'🇭🇳',n:'هندوراس',d:'+504'},
  {f:'🇸🇻',n:'السلفادور',d:'+503'},{f:'🇳🇮',n:'نيكاراغوا',d:'+505'},{f:'🇨🇷',n:'كوستاريكا',d:'+506'},{f:'🇵🇦',n:'بنما',d:'+507'},
  {f:'🇨🇺',n:'كوبا',d:'+53'},{f:'🇯🇲',n:'جامايكا',d:'+1876'},{f:'🇭🇹',n:'هايتي',d:'+509'},{f:'🇩🇴',n:'الدومينيكان',d:'+1809'},
  {f:'🇹🇹',n:'ترينيداد',d:'+1868'},{f:'🇧🇧',n:'باربادوس',d:'+1246'},{f:'🇧🇷',n:'البرازيل',d:'+55'},{f:'🇦🇷',n:'الأرجنتين',d:'+54'},
  {f:'🇨🇱',n:'تشيلي',d:'+56'},{f:'🇨🇴',n:'كولومبيا',d:'+57'},{f:'🇻🇪',n:'فنزويلا',d:'+58'},{f:'🇵🇪',n:'بيرو',d:'+51'},
  {f:'🇪🇨',n:'الإكوادور',d:'+593'},{f:'🇧🇴',n:'بوليفيا',d:'+591'},{f:'🇵🇾',n:'باراغواي',d:'+595'},{f:'🇺🇾',n:'أوروغواي',d:'+598'},
  {f:'🇬🇧',n:'المملكة المتحدة',d:'+44'},{f:'🇩🇪',n:'ألمانيا',d:'+49'},{f:'🇫🇷',n:'فرنسا',d:'+33'},{f:'🇮🇹',n:'إيطاليا',d:'+39'},
  {f:'🇪🇸',n:'إسبانيا',d:'+34'},{f:'🇵🇹',n:'البرتغال',d:'+351'},{f:'🇳🇱',n:'هولندا',d:'+31'},{f:'🇧🇪',n:'بلجيكا',d:'+32'},
  {f:'🇨🇭',n:'سويسرا',d:'+41'},{f:'🇦🇹',n:'النمسا',d:'+43'},{f:'🇸🇪',n:'السويد',d:'+46'},{f:'🇳🇴',n:'النرويج',d:'+47'},
  {f:'🇩🇰',n:'الدنمارك',d:'+45'},{f:'🇫🇮',n:'فنلندا',d:'+358'},{f:'🇮🇪',n:'أيرلندا',d:'+353'},{f:'🇬🇷',n:'اليونان',d:'+30'},
  {f:'🇨🇾',n:'قبرص',d:'+357'},{f:'🇵🇱',n:'بولندا',d:'+48'},{f:'🇨🇿',n:'التشيك',d:'+420'},{f:'🇭🇺',n:'هنغاريا',d:'+36'},
  {f:'🇷🇴',n:'رومانيا',d:'+40'},{f:'🇧🇬',n:'بلغاريا',d:'+359'},{f:'🇭🇷',n:'كرواتيا',d:'+385'},{f:'🇷🇸',n:'صربيا',d:'+381'},
  {f:'🇧🇦',n:'البوسنة',d:'+387'},{f:'🇦🇱',n:'ألبانيا',d:'+355'},{f:'🇱🇹',n:'ليتوانيا',d:'+370'},{f:'🇱🇻',n:'لاتفيا',d:'+371'},
  {f:'🇪🇪',n:'إستونيا',d:'+372'},{f:'🇧🇾',n:'بيلاروسيا',d:'+375'},{f:'🇺🇦',n:'أوكرانيا',d:'+380'},{f:'🇲🇩',n:'مولدوفا',d:'+373'},
  {f:'🇷🇺',n:'روسيا',d:'+7'},{f:'🇦🇺',n:'أستراليا',d:'+61'},{f:'🇳🇿',n:'نيوزيلندا',d:'+64'},{f:'🇫🇯',n:'فيجي',d:'+679'},
  {f:'🇵🇬',n:'بابوا غينيا الجديدة',d:'+675'},{f:'🇿🇦',n:'جنوب أفريقيا',d:'+27'},{f:'🇳🇬',n:'نيجيريا',d:'+234'},{f:'🇰🇪',n:'كينيا',d:'+254'},
  {f:'🇬🇭',n:'غانا',d:'+233'},{f:'🇪🇹',n:'إثيوبيا',d:'+251'},{f:'🇹🇿',n:'تنزانيا',d:'+255'},{f:'🇺🇬',n:'أوغندا',d:'+256'},
  {f:'🇷🇼',n:'رواندا',d:'+250'},{f:'🇨🇩',n:'الكونغو الديمقراطية',d:'+243'},{f:'🇨🇲',n:'الكاميرون',d:'+237'},{f:'🇸🇳',n:'السنغال',d:'+221'},
  {f:'🇨🇮',n:'ساحل العاج',d:'+225'},{f:'🇲🇱',n:'مالي',d:'+223'},{f:'🇧🇫',n:'بوركينا فاسو',d:'+226'},{f:'🇳🇪',n:'النيجر',d:'+227'},
  {f:'🇬🇳',n:'غينيا',d:'+224'},{f:'🇸🇱',n:'سيراليون',d:'+232'},{f:'🇱🇷',n:'ليبيريا',d:'+231'},{f:'🇬🇲',n:'غامبيا',d:'+220'},
  {f:'🇬🇦',n:'الغابون',d:'+241'},{f:'🇦🇴',n:'أنغولا',d:'+244'},{f:'🇿🇲',n:'زامبيا',d:'+260'},{f:'🇿🇼',n:'زيمبابوي',d:'+263'},
  {f:'🇲🇿',n:'موزمبيق',d:'+258'},{f:'🇲🇼',n:'مالاوي',d:'+265'},{f:'🇧🇼',n:'بوتسوانا',d:'+267'},{f:'🇳🇦',n:'ناميبيا',d:'+264'},
  {f:'🇲🇬',n:'مدغشقر',d:'+261'},{f:'🇲🇺',n:'موريشيوس',d:'+230'},{f:'🇧🇮',n:'بوروندي',d:'+257'},{f:'🇧🇯',n:'بنين',d:'+229'},
  {f:'🇹🇬',n:'توغو',d:'+228'}
];
let regDialOpen = false;

function buildRegDialList(filter = '') {
  const list = document.getElementById('regDialList');
  if (!list) return;
  const f = filter.toLowerCase();
  const items = filter ? REG_COUNTRIES.filter(c => c.n.includes(filter) || c.d.includes(filter)) : REG_COUNTRIES;
  list.innerHTML = items.map(c => `
    <div onclick="selectRegDial('${c.d}','${c.f}','${c.n}')"
      style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:13px;transition:.15s"
      onmouseover="this.style.background='rgba(30,111,255,.12)'" onmouseout="this.style.background=''">
      <span style="font-size:18px">${c.f}</span>
      <span style="flex:1;color:#fff">${c.n}</span>
      <span style="color:#8895a7;font-family:monospace;font-size:12px">${c.d}</span>
    </div>`).join('');
}

function selectRegDial(dial, flag, name) {
  document.getElementById('regDialFlag').textContent = flag;
  document.getElementById('regDialCode').textContent = dial;
  document.getElementById('regDialCodeVal').value = dial;
  closeRegDial();
  document.getElementById('regPhone')?.focus();
}

function toggleRegDial() {
  const dd = document.getElementById('regDialDropdown');
  if (!dd) return;
  regDialOpen = !regDialOpen;
  dd.style.display = regDialOpen ? 'block' : 'none';
  document.getElementById('regDialArrow').style.transform = regDialOpen ? 'rotate(180deg)' : '';
  if (regDialOpen) {
    buildRegDialList();
    setTimeout(() => document.getElementById('regDialSearch')?.focus(), 100);
  }
}

function closeRegDial() {
  regDialOpen = false;
  const dd = document.getElementById('regDialDropdown');
  if (dd) dd.style.display = 'none';
  const arr = document.getElementById('regDialArrow');
  if (arr) arr.style.transform = '';
}

function filterRegDial(q) { buildRegDialList(q); }

document.addEventListener('click', e => {
  const btn = document.getElementById('regDialBtn');
  const dd  = document.getElementById('regDialDropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) closeRegDial();
});

// ════════════════════════════════════════════════════
//  PROFILE — تعديل الاسم والصورة
// ════════════════════════════════════════════════════
function openEditName() {
  const modal = document.getElementById('editNameModal');
  const input = document.getElementById('editNameInput');
  input.value = document.getElementById('profileDisplayName')?.textContent.trim() || '';
  modal.style.display = 'flex';
  setTimeout(() => input.focus(), 300);
}
function closeEditName() {
  document.getElementById('editNameModal').style.display = 'none';
}
async function saveProfileName() {
  const input = document.getElementById('editNameInput');
  const name  = input.value.trim();
  if (name.length < 2) { showToast('الاسم قصير جداً', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'update_name');
  fd.append('full_name', name);

  try {
    const r = await fetch('<?= SITE_URL ?>/api/update_profile.php', { method:'POST', body:fd, credentials:'same-origin' });
    const d = await r.json();
    if (d.ok) {
      document.getElementById('profileDisplayName').textContent = name;
      // تحديث الاسم في الهيدر والسايدبار
      document.querySelectorAll('.sidebar-username').forEach(el => el.textContent = name);
      showToast(d.msg, 'success');
      closeEditName();
    } else {
      showToast(d.msg, 'error');
    }
  } catch(e) { showToast('خطأ في الاتصال', 'error'); }
}

// ── الصورة الشخصية ──
function openAvatarMenu() {
  document.getElementById('avatarMenuModal').style.display = 'flex';
}
function closeAvatarMenu() {
  document.getElementById('avatarMenuModal').style.display = 'none';
}
function avatarFromCamera()  { closeAvatarMenu(); document.getElementById('avatarFileCamera').click(); }
function avatarFromGallery() { closeAvatarMenu(); document.getElementById('avatarFileGallery').click(); }

async function uploadAvatar(input) {
  const file = input.files[0];
  if (!file) return;

  // معاينة فورية
  const reader = new FileReader();
  reader.onload = e => {
    const img   = document.getElementById('profileAvatarImg');
    const emoji = document.getElementById('profileAvatarEmoji');
    if (img)   { img.src = e.target.result; img.style.display = 'block'; }
    if (emoji) emoji.style.display = 'none';
    // تحديث الأفاتار في الهيدر إن وجد
    document.querySelectorAll('.header-avatar-img').forEach(el => { el.src = e.target.result; el.style.display='block'; });
  };
  reader.readAsDataURL(file);

  showToast('جاري الرفع...', 'info');

  const fd = new FormData();
  fd.append('action', 'upload_avatar');
  fd.append('avatar', file);

  try {
    const r = await fetch('<?= SITE_URL ?>/api/update_profile.php', { method:'POST', body:fd, credentials:'same-origin' });
    const d = await r.json();
    if (d.ok) {
      showToast(d.msg, 'success');
    } else {
      showToast(d.msg, 'error');
    }
  } catch(e) { showToast('خطأ في الرفع', 'error'); }
}

async function removeAvatar() {
  closeAvatarMenu();
  const fd = new FormData();
  fd.append('action', 'remove_avatar');
  try {
    const r = await fetch('<?= SITE_URL ?>/api/update_profile.php', { method:'POST', body:fd, credentials:'same-origin' });
    const d = await r.json();
    if (d.ok) {
      const img   = document.getElementById('profileAvatarImg');
      const emoji = document.getElementById('profileAvatarEmoji');
      if (img)   img.style.display = 'none';
      if (emoji) emoji.style.display = 'flex';
      showToast('تم حذف الصورة', 'success');
    }
  } catch(e) {}
}

// إغلاق المودالات عند الضغط خارجها
document.getElementById('editNameModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeEditName();
});
document.getElementById('avatarMenuModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeAvatarMenu();
});

// ════════════════════════════════════════════════════
//  KYC — تحقق الهوية
// ════════════════════════════════════════════════════
let kycCurrentStep = 1;
let kycSelectedType = 'national';

function onIdTypeChange(type) {
  kycSelectedType = type;
  // تحديث الكروت
  document.querySelectorAll('.kyc-type-card').forEach(c => {
    c.classList.toggle('active', c.dataset.type === type);
  });
  // تعديل label الرقم الوطني
  const labels = {
    national:   'رقم الهوية الوطنية',
    passport:   'رقم جواز السفر',
    family:     'رقم البطاقة العائلية',
    electronic: 'رقم البطاقة الإلكترونية',
  };
  const idLbl = document.getElementById('kycIdLabel');
  if (idLbl) idLbl.innerHTML = (labels[type] || 'رقم الهوية') + ' <span style="color:#ff4757">*</span>';
  // إخفاء صورة الخلف لجواز السفر
  const backWrap = document.getElementById('kycBackWrap');
  if (backWrap) backWrap.style.display = type === 'passport' ? 'none' : 'block';
}

function autoCalcExpiry(issueVal) {
  if (!issueVal) return;
  const d = new Date(issueVal);
  d.setFullYear(d.getFullYear() + 10);
  const expiry = d.toISOString().split('T')[0];
  const el = document.getElementById('kycExpiryDate');
  if (el) el.value = expiry;
}

function triggerKycUpload(inputId) {
  document.getElementById(inputId)?.click();
}
function kycOpenCamera(inputId) {
  // تصوير مباشر بالكاميرا
  const cam = document.getElementById(inputId + 'Camera');
  if (cam) { cam.click(); return; }
  // fallback
  const el = document.getElementById(inputId);
  if (el) { el.setAttribute('capture','environment'); el.click(); }
}
function kycOpenGallery(inputId) {
  // اختيار من المعرض/الاستوديو
  const el = document.getElementById(inputId);
  if (!el) return;
  el.removeAttribute('capture');
  el.click();
}
function syncKycCamera(cameraInput, targetId, previewId, dropId) {
  // نسخ الملف من input الكاميرا إلى input الفورم
  const file = cameraInput.files[0];
  if (!file) return;
  const dt = new DataTransfer();
  dt.items.add(file);
  const target = document.getElementById(targetId);
  if (target) {
    target.files = dt.files;
    previewKycImage(target, previewId, dropId);
  }
}

// مخزن الصور المضغوطة — يُملأ عند اختيار الصورة
const kycCompressedBlobs = {};

/**
 * يضغط صورة KYC إلى WebP بحجم أقل من 150KB
 * - يبدأ من أبعاد 1024px وجودة 0.80
 * - يخفض الجودة تدريجياً حتى يصل للهدف
 * - يدعم WebP مع fallback لـ JPEG في المتصفحات القديمة
 */
function compressKycImage(file, inputId) {
  const TARGET_BYTES = 150 * 1024;   // 150 KB
  const MAX_PX       = 1024;         // أقصى عرض/ارتفاع
  const USE_WEBP     = document.createElement('canvas')
                        .toDataURL('image/webp').startsWith('data:image/webp');
  const FORMAT       = USE_WEBP ? 'image/webp' : 'image/jpeg';
  const EXT          = USE_WEBP ? 'webp' : 'jpg';

  return new Promise(resolve => {
    const reader = new FileReader();
    reader.onload = ev => {
      const img = new Image();
      img.onload = () => {

        // ── حساب الأبعاد ──────────────────────────────
        let w = img.width, h = img.height;
        if (w > MAX_PX || h > MAX_PX) {
          if (w >= h) { h = Math.round(h * MAX_PX / w); w = MAX_PX; }
          else        { w = Math.round(w * MAX_PX / h); h = MAX_PX; }
        }

        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);

        // ── ضغط تكراري حتى يصل للهدف ─────────────────
        let quality = 0.80;
        const tryCompress = () => {
          canvas.toBlob(blob => {
            if (!blob) { resolve({ blob: null, dataUrl: '', ext: EXT }); return; }

            if (blob.size <= TARGET_BYTES || quality <= 0.25) {
              // وصلنا للهدف أو بلغنا الحد الأدنى للجودة
              kycCompressedBlobs[inputId] = { blob, ext: EXT };
              const url = URL.createObjectURL(blob);
              resolve({ blob, dataUrl: url, ext: EXT });
            } else {
              // نخفض الجودة 10% ونحاول مجدداً
              quality = Math.max(0.25, quality - 0.10);
              tryCompress();
            }
          }, FORMAT, quality);
        };
        tryCompress();
      };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });
}

async function previewKycImage(input, previewId, dropId) {
  const file = input.files[0];
  if (!file) return;

  // أظهر مؤشر التحميل أثناء الضغط
  const drop = document.getElementById(dropId);
  if (drop) drop.style.opacity = '0.5';

  const { dataUrl } = await compressKycImage(file, input.id);

  const preview = document.getElementById(previewId);
  if (preview && dataUrl) { preview.src = dataUrl; preview.style.display = 'block'; }
  if (drop) { drop.style.opacity = '1'; drop.classList.add('has-image'); }
}

function kycNextStep(from) {
  // تحقق من الحقول المطلوبة
  if (from === 1) {
    const req = ['full_name','national_id','birth_date','issue_date','expiry_date'];
    for (const name of req) {
      const el = document.querySelector(`#kycStep1 [name="${name}"]`);
      if (el && !el.value.trim()) {
        el.style.borderColor = '#ff4757';
        el.focus();
        showToast('يرجى ملء جميع الحقول المطلوبة', 'error');
        return;
      }
    }
  }
  if (from === 2) {
    const front = document.getElementById('kycFrontFile');
    if (!front?.files?.length) {
      showToast('يرجى رفع صورة الوجه الأمامي', 'error');
      return;
    }
    if (kycSelectedType !== 'passport') {
      const back = document.getElementById('kycBackFile');
      if (!back?.files?.length) {
        showToast('يرجى رفع صورة الوجه الخلفي', 'error');
        return;
      }
    }
    // بناء صفحة المراجعة
    buildKycReview();
  }
  document.getElementById(`kycStep${from}`).style.display = 'none';
  document.getElementById(`kycStep${from+1}`).style.display = 'block';
  kycCurrentStep = from + 1;
  updateKycProgress(kycCurrentStep);
}

function kycPrevStep(current) {
  document.getElementById(`kycStep${current}`).style.display = 'none';
  document.getElementById(`kycStep${current-1}`).style.display = 'block';
  kycCurrentStep = current - 1;
  updateKycProgress(kycCurrentStep);
}

function updateKycProgress(step) {
  for (let i = 1; i <= 3; i++) {
    const dot = document.getElementById(`kycStep${i}Dot`);
    if (dot) dot.style.background = i <= step ? 'var(--primary)' : 'var(--border)';
  }
  document.getElementById('page-kyc')?.scrollTo(0, 0);
  window.scrollTo(0, 0);
}

function buildKycReview() {
  const get = name => document.querySelector(`[name="${name}"]`)?.value || '—';
  const typeLabels = {national:'بطاقة شخصية',passport:'جواز سفر',family:'بطاقة عائلية',electronic:'بطاقة إلكترونية'};
  const rows = [
    ['نوع الهوية',    typeLabels[kycSelectedType] || kycSelectedType],
    ['الاسم',         get('full_name')],
    ['الرقم',         get('national_id')],
    ['تاريخ الميلاد', get('birth_date')],
    ['مكان الميلاد',  get('birth_place') || '—'],
    ['تاريخ الإصدار', get('issue_date')],
    ['تاريخ الانتهاء',get('expiry_date')],
  ];
  const box = document.getElementById('kycReviewBox');
  if (!box) return;
  box.innerHTML = rows.map(([label, val]) => `
    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px">
      <span style="color:#8895a7">${label}</span>
      <span style="color:#fff;font-weight:700">${val}</span>
    </div>
  `).join('') + `
    <div style="display:flex;gap:8px;margin-top:12px">
      ${document.getElementById('kycFrontPreview')?.src ? `<img src="${document.getElementById('kycFrontPreview').src}" style="flex:1;height:70px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">` : ''}
      ${document.getElementById('kycBackPreview')?.src && kycSelectedType !== 'passport' ? `<img src="${document.getElementById('kycBackPreview').src}" style="flex:1;height:70px;object-fit:cover;border-radius:10px;border:1px solid var(--border)">` : ''}
    </div>
  `;
}

async function submitKyc(e) {
  e.preventDefault();
  const btn = document.getElementById('kycSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';

  const form = document.getElementById('kycForm');
  const fd   = new FormData(form);

  // استبدال الصور الأصلية بالنسخ المضغوطة (WebP أو JPEG)
  const fieldMap = { 'image_front': 'kycFrontFile', 'image_back': 'kycBackFile' };
  for (const [field, inputId] of Object.entries(fieldMap)) {
    const entry = kycCompressedBlobs[inputId];
    if (entry?.blob) fd.set(field, entry.blob, 'kyc_img.' + entry.ext);
  }

  try {
    const r = await fetch('<?= SITE_URL ?>/api/kyc_submit.php', {
      method: 'POST', body: fd, credentials: 'same-origin'
    });
    const d = await r.json();
    if (d.ok) {
      // نجاح — أعد تحميل الصفحة لإظهار حالة "قيد المراجعة"
      showToast(d.msg, 'success');
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast(d.msg || 'حدث خطأ', 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-left:6px"></i>إرسال الطلب';
    }
  } catch(err) {
    showToast('خطأ في الاتصال', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane" style="margin-left:6px"></i>إرسال الطلب';
  }
}

// ════════════════════════════════════════════════════
//  AJAX Order Submission — صوت + confetti + modal
// ════════════════════════════════════════════════════
function submitOrderAjax() {
  const btn = document.getElementById('sheetBuyBtn');
  const form = document.getElementById('orderForm');

  // تجميع البيانات من الفورم
  const serviceId = document.getElementById('sheetServiceId').value;
  if (!serviceId) return;

  // التحقق من الحقول
  const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
  for (const inp of inputs) {
    if (!inp.value.trim()) {
      inp.style.borderColor = '#ff4757';
      inp.focus();
      showToast('يرجى ملء جميع الحقول المطلوبة', 'error');
      return;
    }
  }

  // ✅ شغّل الصوت هنا فوراً — داخل gesture المستخدم مباشرةً
  playSuccessSound();

  // بناء البيانات
  const formData = new FormData();
  formData.append('service_id', serviceId);

  // الكمية
  const qtyEl = document.getElementById('sheetQty') || document.getElementById('hiddenQty');
  formData.append('quantity', qtyEl ? qtyEl.value : 1);

  // الحقول الديناميكية
  form.querySelectorAll('[name^="fields["]').forEach(el => {
    const match = el.name.match(/fields\[(.+)\]/);
    if (match) formData.append('fields[' + match[1] + ']', el.value);
  });

  // إضافة كوبون إذا وُجد
  const couponInput = document.getElementById('couponCodeInput');
  const couponCode  = couponInput ? couponInput.value.trim().toUpperCase() : '';
  if (couponCode) formData.append('coupon_code', couponCode);

  // تعطيل الزر
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...';

  fetch('<?= SITE_URL ?>/api/place_order_ajax.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(d => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt"></i> شـراء الآن';

    if (d.status) {
      // ✅ نجاح — confetti + اهتزاز (الصوت شُغِّل مسبقاً عند الضغط)
      closeSheet();
      showOrderSuccessModal(d);
      triggerVibration([80, 40, 180, 40, 80]);
      launchConfetti();

      // تحديث الرصيد في كل عناصر الصفحة
      if (d.new_balance !== undefined) {
        updateAllBalances(d.new_balance);
      }
    } else {
      // ❌ فشل
      showToast(d.message || 'حدث خطأ', 'error');
      triggerVibration([100, 50, 100]);
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-bolt"></i> شـراء الآن';
    showToast('خطأ في الاتصال، حاول مجدداً', 'error');
  });
}

// ── كوبونات الخصم ─────────────────────────────────────────────
let _couponApplied = null;

async function applyCoupon() {
  const code = (document.getElementById('couponCodeInput')?.value||'').trim().toUpperCase();
  const msg  = document.getElementById('couponMsg');
  const btn  = document.getElementById('sheetBuyBtn');
  if (!code) return;
  if (!msg) return;

  const serviceId = document.getElementById('sheetServiceId')?.value || document.querySelector('[name="service_id"]')?.value;
  // احتساب السعر الحالي من الـ sheet
  const priceEl = document.querySelector('.sheet-total-price, .sheet-price-val, #sheetTotalPrice');
  const amount  = priceEl ? parseFloat(priceEl.textContent.replace(/[^0-9.]/g,'')) : 0;

  document.getElementById('couponBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  msg.style.display = 'none';

  try {
    const fd = new FormData();
    fd.append('code',       code);
    fd.append('service_id', serviceId||0);
    fd.append('amount',     amount||0);
    const r = await fetch('<?= SITE_URL ?>/api/validate_coupon.php', {method:'POST',body:fd,credentials:'same-origin'});
    const d = await r.json();

    if (d.ok) {
      _couponApplied = d;
      msg.style.cssText = 'padding:0 16px 8px;font-size:.78rem;display:block;color:#00d4aa;font-weight:700';
      msg.innerHTML = '✅ ' + d.message + ' — توفير <strong>' + parseFloat(d.discount).toFixed(4) + '$</strong>';
      document.getElementById('couponBtn').innerHTML = '✓';
      document.getElementById('couponBtn').style.background = 'rgba(0,230,118,.2)';
      document.getElementById('couponBtn').style.borderColor = 'rgba(0,230,118,.3)';
      document.getElementById('couponBtn').style.color = '#00e676';
    } else {
      _couponApplied = null;
      msg.style.cssText = 'padding:0 16px 8px;font-size:.78rem;display:block;color:#ff4455';
      msg.innerHTML = '❌ ' + (d.error||'كود غير صحيح');
      document.getElementById('couponBtn').innerHTML = 'تطبيق';
    }
  } catch(e) {
    document.getElementById('couponBtn').innerHTML = 'تطبيق';
  }
}

function resetCoupon() {
  _couponApplied = null;
  const msg = document.getElementById('couponMsg');
  if (msg) { msg.style.display='none'; msg.innerHTML=''; }
  const btn = document.getElementById('couponBtn');
  if (btn) { btn.innerHTML='تطبيق'; btn.style.background='rgba(0,212,170,.15)'; btn.style.borderColor='rgba(0,212,170,.3)'; btn.style.color='#00d4aa'; }
}

// ── مودال نجاح الطلب ──────────────────────────────
function showOrderSuccessModal(d) {
  const existing = document.getElementById('orderSuccessModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'orderSuccessModal';
  modal.style.cssText = `
    position:fixed;inset:0;z-index:999998;
    display:flex;align-items:flex-end;justify-content:center;
    background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);
    animation:fadeIn .25s ease;
  `;

  modal.innerHTML = `
    <div style="
      width:100%;max-width:430px;
      background:linear-gradient(180deg,#111827 0%,#0d1428 100%);
      border-radius:24px 24px 0 0;
      border-top:1px solid rgba(0,230,118,0.3);
      padding:28px 24px 40px;
      animation:slideUp .35s cubic-bezier(0.175,0.885,0.32,1.275);
      text-align:center;
    ">
      <div style="
        width:80px;height:80px;margin:0 auto 20px;
        background:linear-gradient(135deg,rgba(0,230,118,0.2),rgba(0,230,118,0.05));
        border:2px solid rgba(0,230,118,0.4);
        border-radius:50%;display:flex;align-items:center;justify-content:center;
        font-size:36px;animation:sp-bounce .5s cubic-bezier(0.175,0.885,0.32,1.275) both;
      ">✅</div>

      <div style="font-size:22px;font-weight:900;color:#fff;margin-bottom:8px">تم الطلب بنجاح! 🎉</div>
      <div style="font-size:14px;color:#8fa3bf;margin-bottom:20px;line-height:1.7">${d.service || ''}</div>

      <!-- تفاصيل الطلب -->
      <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:16px;margin-bottom:14px;text-align:right;">
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:13px">
          <span style="color:#8fa3bf">رقم الطلب</span>
          <span style="color:#fff;font-weight:700;font-family:monospace;font-size:.85rem">${d.ref_id || ('#'+String(d.order_id).padStart(6,'0'))}</span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:13px">
          <span style="color:#8fa3bf">المبلغ المدفوع</span>
          <span style="color:#ff4757;font-weight:700">${d.total}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span style="color:#8fa3bf">رصيدك الجديد</span>
          <span style="color:#00e676;font-weight:700">${parseFloat(d.new_balance).toFixed(4)}</span>
        </div>
      </div>

      <!-- ◆ مؤشر انتظار الكود -->
      <div id="orderCodeWaiting" style="
        display:flex;align-items:center;justify-content:center;gap:8px;
        background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);
        border-radius:12px;padding:12px;margin-bottom:14px;
        font-size:13px;color:#8fa3bf;
      ">
        <span id="orderCodeSpinner" style="animation:spin 1s linear infinite;display:inline-block;font-size:16px">⟳</span>
        <span id="orderCodeWaitTxt">جاري جلب الكود تلقائياً...</span>
      </div>

      <!-- ◆ بطاقة الكود — تظهر عند الوصول -->
      <div id="orderCodeBox" style="display:none;
        background:linear-gradient(135deg,rgba(0,212,170,0.12),rgba(30,111,255,0.08));
        border:1.5px solid rgba(0,212,170,0.4);border-radius:16px;
        padding:18px;margin-bottom:14px;
      ">
        <div style="font-size:.75rem;color:#8fa3bf;margin-bottom:10px;text-align:right">🎫 الكود / النتيجة</div>
        <div id="orderCodeVal" style="
          font-family:'Courier New',monospace;font-size:1.25rem;font-weight:900;
          color:#00d4aa;letter-spacing:2px;word-break:break-all;
          text-align:center;margin-bottom:14px;line-height:1.6;
        "></div>
        <button onclick="_copyOrderCode()" style="
          width:100%;padding:11px;background:rgba(0,212,170,0.15);
          border:1px solid rgba(0,212,170,0.35);border-radius:10px;
          color:#00d4aa;font-family:'Cairo',sans-serif;font-size:13px;font-weight:700;cursor:pointer;
        "><i class="fas fa-copy"></i> نسخ الكود</button>
      </div>

      <!-- أزرار -->
      <div style="display:flex;gap:10px">
        <button onclick="document.getElementById('orderSuccessModal').remove()"
          style="flex:1;padding:14px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:14px;color:#8fa3bf;font-family:'Cairo',sans-serif;font-size:14px;font-weight:700;cursor:pointer">
          متابعة التسوق
        </button>
        <button onclick="document.getElementById('orderSuccessModal').remove(); navTo('orders')"
          style="flex:1;padding:14px;background:linear-gradient(135deg,#00c853,#00e676);border:none;border-radius:14px;color:#fff;font-family:'Cairo',sans-serif;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 6px 20px rgba(0,200,83,0.35)">
          متابعة طلباتي
        </button>
      </div>
    </div>
  `;

  modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);

  // ── بدء polling الكود فوراً ──
  _pollOrderCode(d.order_id);
}

// ── Polling الكود بعد الطلب ──────────────────────────────
let _pollCodeInterval = null;
let _pollCodeAttempts = 0;
const _POLL_MAX = 20;      // حد أقصى 20 محاولة
const _POLL_DELAY = 2000;  // كل 2 ثانية

function _pollOrderCode(orderId) {
  _pollCodeAttempts = 0;
  clearInterval(_pollCodeInterval);
  _pollCodeInterval = setInterval(async () => {
    _pollCodeAttempts++;
    try {
      const r = await fetch('<?= SITE_URL ?>/api/order_detail.php?id=' + orderId, {credentials:'same-origin'});
      const d = await r.json();
      if (!d.ok) return;

      const order = d.order;
      const status = order.status;
      const result = (order.result || '').trim();

      if (status === 'completed' && result) {
        // ✅ وصل الكود
        clearInterval(_pollCodeInterval);
        _showOrderCode(result);
        return;
      }

      if (status === 'failed' || status === 'cancelled') {
        clearInterval(_pollCodeInterval);
        _hideCodeWaiting('❌ لم يتم تنفيذ الطلب — تواصل مع الدعم');
        return;
      }

      // بعد 20 محاولة (40 ثانية) نوقف التحقق
      if (_pollCodeAttempts >= _POLL_MAX) {
        clearInterval(_pollCodeInterval);
        _hideCodeWaiting('📋 يمكنك متابعة طلبك من صفحة الطلبات');
        return;
      }

    } catch(e) { /* صامت */ }
  }, _POLL_DELAY);
}

function _showOrderCode(code) {
  const waiting = document.getElementById('orderCodeWaiting');
  const box     = document.getElementById('orderCodeBox');
  const val     = document.getElementById('orderCodeVal');
  if (!box || !val) return;
  if (waiting) waiting.style.display = 'none';
  val.textContent = code;
  box.style.display = '';
  // إشعار المستخدم
  if (typeof showToast === 'function') showToast('✅ وصل الكود!', 'success');
  if (typeof triggerVibration === 'function') triggerVibration([100, 50, 200]);
}

function _hideCodeWaiting(msg) {
  const waiting  = document.getElementById('orderCodeWaiting');
  const spinner  = document.getElementById('orderCodeSpinner');
  const waitTxt  = document.getElementById('orderCodeWaitTxt');
  if (!waiting) return;
  if (spinner) spinner.style.display = 'none';
  if (waitTxt) waitTxt.textContent = msg;
}

function _copyOrderCode() {
  const val = document.getElementById('orderCodeVal');
  if (!val) return;
  const txt = val.textContent.trim();
  navigator.clipboard.writeText(txt)
    .then(() => { if (typeof showToast === 'function') showToast('✅ تم نسخ الكود'); })
    .catch(() => {
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      if (typeof showToast === 'function') showToast('✅ تم نسخ الكود');
    });
}

// ════════════════════════════════════════════════════
//  الوضع النهاري / الليلي
// ════════════════════════════════════════════════════
(function initTheme() {
  const saved = localStorage.getItem('njaz_theme');
  // الافتراضي: نهاري — إلا إذا اختار المستخدم الليلي صراحة من قبل
  if (saved !== 'dark') {
    document.body.classList.add('light-mode');
    updateThemeIcon(true);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.content = '#1e40af';
  }
})();

function toggleTheme() {
  // إضافة class الـ transition مؤقتاً لتأثير سلس
  document.body.classList.add('theme-transitioning');
  setTimeout(() => document.body.classList.remove('theme-transitioning'), 400);

  const isLight = document.body.classList.toggle('light-mode');
  localStorage.setItem('njaz_theme', isLight ? 'light' : 'dark');
  updateThemeIcon(isLight);

  // تحديث theme-color للمتصفح (شريط العنوان)
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = isLight ? '#1e40af' : '#080c1a';
}

function updateThemeIcon(isLight) {
  const icon = document.getElementById('themeIcon');
  const btn  = document.getElementById('themeToggleBtn');
  if (!icon) return;
  if (isLight) {
    icon.className = 'fas fa-sun';
    icon.style.color = '#f5a623';
    if (btn) btn.style.borderColor = 'rgba(245,166,35,0.4)';
  } else {
    icon.className = 'fas fa-moon';
    icon.style.color = '';
    if (btn) btn.style.borderColor = '';
  }
}

// ════════════════════════════════════════════════════
//  نظام صوت وإشعارات النجاح
// ════════════════════════════════════════════════════
const SOUND_CFG = {
  enabled:   <?= (getSetting('success_sound_enabled') !== '0') ? 'true' : 'false' ?>,
  vibrate:   <?= (getSetting('success_vibrate_enabled') !== '0') ? 'true' : 'false' ?>,
  animation: <?= (getSetting('success_animation_enabled') !== '0') ? 'true' : 'false' ?>,
  url:       '<?php
    $__sType = getSetting("success_sound_type") ?: "builtin";
    $__sBuiltin = getSetting("success_sound_builtin") ?: "default";
    $__sCustom = getSetting("success_sound_custom") ?: "";
    echo addslashes(($__sType === "custom" && $__sCustom)
      ? SITE_URL . "/" . $__sCustom
      : SITE_URL . "/uploads/sounds/success_" . $__sBuiltin . ".wav");
  ?>',
  cancelEnabled: <?= (getSetting('cancel_sound_enabled') !== '0') ? 'true' : 'false' ?>,
  cancelUrl:     '<?php
    $__cCustom = getSetting("cancel_sound_custom") ?: "";
    echo $__cCustom ? addslashes(SITE_URL . "/" . $__cCustom) : "";
  ?>'
};

// ── نغمة الإلغاء الافتراضية (Web Audio API) عندما لا يوجد ملف مخصص ──
function _playDefaultCancelSound() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    // نغمة هابطة قصيرة تدل على الإلغاء
    const sequence = [
      { freq: 523, start: 0,    dur: 0.15 },
      { freq: 392, start: 0.15, dur: 0.15 },
      { freq: 262, start: 0.30, dur: 0.25 },
    ];
    sequence.forEach(({ freq, start, dur }) => {
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, ctx.currentTime + start);
      gain.gain.setValueAtTime(0.45, ctx.currentTime + start);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + dur);
      osc.start(ctx.currentTime + start);
      osc.stop(ctx.currentTime + start + dur + 0.05);
    });
  } catch(e) {}
}

let _successAudio = null;
let _cancelAudio  = null;

// تحميل الصوت مسبقاً عند أول تفاعل من المستخدم
let _audioUnlocked = false;
function unlockAudio() {
  if (_audioUnlocked) return;
  _audioUnlocked = true;
  if (!_successAudio && SOUND_CFG.enabled) {
    _successAudio = new Audio(SOUND_CFG.url);
    _successAudio.volume = 0.75;
    _successAudio.load();
    const p = _successAudio.play();
    if (p) p.then(() => { _successAudio.pause(); _successAudio.currentTime = 0; }).catch(()=>{});
  }
  if (SOUND_CFG.cancelEnabled && SOUND_CFG.cancelUrl && !_cancelAudio) {
    _cancelAudio = new Audio(SOUND_CFG.cancelUrl);
    _cancelAudio.volume = 0.75;
    _cancelAudio.load();
    const p2 = _cancelAudio.play();
    if (p2) p2.then(() => { _cancelAudio.pause(); _cancelAudio.currentTime = 0; }).catch(()=>{});
  }
}
document.addEventListener('touchstart', unlockAudio, { once: true, passive: true });
document.addEventListener('click',      unlockAudio, { once: true });

function playSuccessSound() {
  if (!SOUND_CFG.enabled) return;
  try {
    if (!_successAudio) {
      _successAudio = new Audio(SOUND_CFG.url);
      _successAudio.volume = 0.75;
    }
    _successAudio.currentTime = 0;
    const p = _successAudio.play();
    if (p) p.catch(() => {
      _successAudio = new Audio(SOUND_CFG.url);
      _successAudio.volume = 0.75;
      _successAudio.play().catch(()=>{});
    });
  } catch(e) {}
}

function playCancelSound() {
  if (!SOUND_CFG.cancelEnabled) return;
  // إذا لا يوجد ملف مخصص — استخدم النغمة الافتراضية المدمجة
  if (!SOUND_CFG.cancelUrl) {
    _playDefaultCancelSound();
    return;
  }
  try {
    if (!_cancelAudio) {
      _cancelAudio = new Audio(SOUND_CFG.cancelUrl);
      _cancelAudio.volume = 0.75;
    }
    _cancelAudio.currentTime = 0;
    const p = _cancelAudio.play();
    if (p) p.catch(() => {
      // إذا فشل التشغيل — جرب الافتراضية
      _playDefaultCancelSound();
    });
  } catch(e) { _playDefaultCancelSound(); }
}

function triggerVibration(pattern) {
  if (!SOUND_CFG.vibrate) return;
  if ('vibrate' in navigator) {
    navigator.vibrate(pattern || [80, 40, 180, 40, 80]);
  }
}

function launchConfetti() {
  if (!SOUND_CFG.animation) return;
  const colors = ['#1e6fff','#00d4ff','#f5a623','#00e676','#ff4757','#ffffff'];
  const app = document.getElementById('app') || document.body;
  const rect = app.getBoundingClientRect();

  // إضافة CSS animation مرة واحدة
  if (!document.getElementById('confetti-style')) {
    const st = document.createElement('style');
    st.id = 'confetti-style';
    st.textContent = `
      .confetti-piece {
        position: fixed;
        pointer-events: none;
        z-index: 999999;
        will-change: transform, opacity;
      }
    `;
    document.head.appendChild(st);
  }

  const count = 80;
  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      const el = document.createElement('div');
      el.className = 'confetti-piece';
      const size = Math.random() * 9 + 4;
      const isCircle = Math.random() > 0.4;
      const color = colors[Math.floor(Math.random() * colors.length)];

      // نقطة البداية عشوائية من عرض التطبيق
      const startX = rect.left + Math.random() * rect.width;
      const startY = rect.top + rect.height * 0.4;
      const driftX = (Math.random() - 0.5) * 180;
      const driftY = -(Math.random() * 250 + 120);
      const rot    = Math.random() * 720 - 360;
      const dur    = Math.random() * 800 + 900; // ms

      el.style.cssText = `
        left:${startX}px; top:${startY}px;
        width:${size}px; height:${size}px;
        background:${color};
        border-radius:${isCircle ? '50%' : '3px'};
        opacity:1;
        transform:translate(0,0) rotate(0deg);
        transition: transform ${dur}ms cubic-bezier(0.25,0.46,0.45,0.94),
                    opacity   ${dur * 0.7}ms ease ${dur * 0.3}ms;
      `;

      document.body.appendChild(el);

      // trigger animation
      requestAnimationFrame(() => requestAnimationFrame(() => {
        el.style.transform = `translate(${driftX}px, ${driftY}px) rotate(${rot}deg)`;
        el.style.opacity = '0';
      }));

      setTimeout(() => el.remove(), dur + 100);
    }, Math.random() * 300); // stagger the launch
  }
}

// الدالة الرئيسية — تُستدعى عند أي نجاح
function onOrderSuccess() {
  playSuccessSound();
  triggerVibration([80, 40, 180, 40, 80]);
  launchConfetti();
}

// نجاح شحن الرصيد (فلوسك)
function onTopupSuccess() {
  playSuccessSound();
  triggerVibration([200, 60, 200]);
  launchConfetti();
}

// ─────────────────────────────────────────
// PWA: Service Worker + Splash + Offline
// ─────────────────────────────────────────
(function() {

  // 1. Splash Screen Stars
  const splash = document.getElementById('splash-screen');
  const splashStars = document.getElementById('splashStars');
  if (splashStars) {
    for (let i = 0; i < 60; i++) {
      const s = document.createElement('div');
      s.className = 'splash-star';
      const sz = (Math.random() * 2 + 0.5).toFixed(1);
      s.style.cssText = `width:${sz}px;height:${sz}px;top:${(Math.random()*100).toFixed(1)}%;left:${(Math.random()*100).toFixed(1)}%;--d:${(Math.random()*3+2).toFixed(1)}s;--delay:${(Math.random()*4).toFixed(1)}s;`;
      splashStars.appendChild(s);
    }
  }

  // Hide splash after load (min 1.5s for good UX)
  const splashStart = Date.now();
  function hideSplash() {
    const elapsed = Date.now() - splashStart;
    const remaining = Math.max(0, 1500 - elapsed);
    setTimeout(() => {
      if (splash) {
        splash.classList.add('hide');
        setTimeout(() => splash.remove(), 550);
      }
    }, remaining);
  }
  if (document.readyState === 'complete') { hideSplash(); }
  else { window.addEventListener('load', hideSplash); }

  // 2. Service Worker Registration
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(reg => {
          window._swReg = reg;
          reg.addEventListener('updatefound', () => {
            const nw = reg.installing;
            nw.addEventListener('statechange', () => {
              if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                showUpdateToast();
              }
            });
          });
          // عرض بانر الإشعارات بعد 3 ثوانٍ إذا لم يُسبق الإذن
          <?php if(isLoggedIn()): ?>
          setTimeout(() => showPushBanner(reg), 3000);
          <?php endif; ?>
        })
        .catch(err => console.warn('[PWA] SW failed:', err));
    });
  }

  // 3. Offline / Online Detection
  const offlineBanner = document.getElementById('offline-banner');
  let onlineTimeout;
  function showOfflineBanner() {
    if (!offlineBanner) return;
    clearTimeout(onlineTimeout);
    offlineBanner.className = 'offline-mode';
    offlineBanner.innerHTML = '\u{1F4F5}\u00A0\u00A0 \u0644\u0627 \u064A\u0648\u062C\u062F \u0627\u062A\u0635\u0627\u0644 \u0628\u0627\u0644\u0625\u0646\u062A\u0631\u0646\u062A \u2014 \u0628\u0639\u0636 \u0627\u0644\u0645\u064A\u0632\u0627\u062A \u0642\u062F \u0644\u0627 \u062A\u0639\u0645\u0644';
    offlineBanner.style.display = 'block';
  }
  function showOnlineBanner() {
    if (!offlineBanner) return;
    clearTimeout(onlineTimeout);
    offlineBanner.className = 'online-mode';
    offlineBanner.innerHTML = '\u2705\u00A0\u00A0 \u0639\u0627\u062F \u0627\u0644\u0627\u062A\u0635\u0627\u0644 \u0628\u0627\u0644\u0625\u0646\u062A\u0631\u0646\u062A!';
    offlineBanner.style.display = 'block';
    onlineTimeout = setTimeout(() => { offlineBanner.style.display = 'none'; }, 3000);
  }
  if (!navigator.onLine) showOfflineBanner();
  window.addEventListener('offline', showOfflineBanner);
  window.addEventListener('online', showOnlineBanner);

  // 4. Install Prompt
  let deferredPrompt = null;
  const installEl = document.getElementById('install-prompt');
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (localStorage.getItem('pwa-install-dismissed') === '1') return;
    setTimeout(() => { if (installEl) installEl.style.display = 'block'; }, 4000);
  });
  window.installApp = function() {
    if (!deferredPrompt) return;
    if (installEl) installEl.style.display = 'none';
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(c => { deferredPrompt = null; });
  };
  window.dismissInstall = function() {
    if (installEl) installEl.style.display = 'none';
    localStorage.setItem('pwa-install-dismissed', '1');
  };
  window.addEventListener('appinstalled', () => {
    if (installEl) installEl.style.display = 'none';
  });

  // 5. Update Toast
  function showUpdateToast() {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#1e6fff,#0d4fd4);color:white;font-family:Cairo,sans-serif;font-size:13px;font-weight:700;padding:12px 20px;border-radius:40px;box-shadow:0 8px 24px rgba(30,111,255,.4);display:flex;align-items:center;gap:10px;z-index:99990;white-space:nowrap;direction:rtl;';
    t.innerHTML = '\u{1F504}\u00A0\u00A0\u064A\u0648\u062C\u062F \u062A\u062D\u062F\u064A\u062B \u062C\u062F\u064A\u062F \u00A0<button onclick="location.reload()" style="background:rgba(255,255,255,.25);border:none;color:white;font-family:Cairo,sans-serif;font-size:12px;font-weight:700;padding:4px 12px;border-radius:20px;cursor:pointer;">\u062A\u062D\u062F\u064A\u062B</button>';
    document.body.appendChild(t);
  }

})();

</script>
