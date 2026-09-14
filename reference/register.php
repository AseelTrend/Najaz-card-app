<?php
require_once 'includes/config.php';
require_once 'includes/device_helper.php';
require_once 'includes/notifications.php';
$pageTitle = 'إنشاء حساب - ' . SITE_NAME;

if (isLoggedIn()) redirect(SITE_URL . '/index.php');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="theme-color" content="#080c1a" id="themeColorMeta">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/style.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap');

/* ══════════════════════════════════════
   متغيرات نفس ألوان تطبيق الجوال (mobile.php)
══════════════════════════════════════ */
#regPageWrap{
  --bg2:      #090d1a;
  --card:     #0e1525;
  --card2:    #151f35;
  --border:   rgba(255,255,255,0.07);
  --border2:  rgba(255,255,255,0.12);
  --primary:  #3b82f6;
  --cyan:     #22d3ee;
  --gold:     #fbbf24;
  --green:    #34d399;
  --red:      #f87171;
  --text:     #e2e8f5;
  --text2:    #7c93b5;
  --text3:    #3d526e;
  --font:     'Cairo',sans-serif;
  --card-grad-a: #0f1929;
  --card-grad-b: #080c1a;
  --input-bg:  rgba(255,255,255,.05);
  --dropdown-bg: #0d1428;
  --dropdown-search-bg: #131d35;
}

/* ══════════════════════════════════════
   الوضع النهاري ☀️ — نفس منطق mobile.php (body.light-mode)
══════════════════════════════════════ */
body.light-mode #regPageWrap{
  --bg2:      #e4ecf7;
  --card:     #ffffff;
  --card2:    #f5f8ff;
  --border:   rgba(15,23,42,0.1);
  --border2:  rgba(15,23,42,0.18);
  --primary:  #2563eb;
  --cyan:     #0891b2;
  --gold:     #d97706;
  --green:    #059669;
  --red:      #dc2626;
  --text:     #0f172a;
  --text2:    #475569;
  --text3:    #94a3b8;
  --card-grad-a: #ffffff;
  --card-grad-b: #f4f7fc;
  --input-bg:  #f8faff;
  --dropdown-bg: #ffffff;
  --dropdown-search-bg: #f1f5fb;
}
body.light-mode{ background:#f0f4fa; color:#0f172a; }
body.theme-transitioning *{transition:background .25s,background-color .25s,color .2s,border-color .2s,box-shadow .25s !important}
body.light-mode .auth-card{
  border-color: rgba(15,23,42,0.08);
  box-shadow: 0 20px 60px rgba(15,23,42,0.12);
}

#regPageWrap{
  min-height:80vh;
  display:flex;align-items:center;justify-content:center;
  padding:30px 14px;
  font-family:var(--font);
}
#regPageWrap *{box-sizing:border-box}

.auth-card{
  width:100%;max-width:440px;
  position:relative;
  background:linear-gradient(180deg,var(--card-grad-a) 0%,var(--card-grad-b) 100%);
  border:1px solid rgba(30,111,255,0.2);
  border-radius:28px;
  box-shadow:0 20px 60px rgba(0,0,0,0.5);
  overflow:hidden;
  transition:background .3s ease;
}

/* زر تبديل الوضع الليلي/النهاري */
.theme-toggle-btn{
  position:absolute;top:16px;left:16px;z-index:5;
  width:38px;height:38px;background:var(--card2);border:1px solid var(--border);
  border-radius:12px;display:flex;align-items:center;justify-content:center;
  cursor:pointer;color:var(--text2);font-size:16px;transition:all .2s;
}
.theme-toggle-btn:active{transform:scale(.92)}

/* Header */
.auth-header{padding:30px 20px 0;text-align:center}
.auth-logo-big{
  width:64px;height:64px;
  background:linear-gradient(135deg,var(--primary),var(--cyan));
  border-radius:20px;margin:0 auto 14px;
  display:flex;align-items:center;justify-content:center;
  font-size:28px;color:#fff;
  box-shadow:0 8px 24px rgba(30,111,255,0.4);
  animation:pulse-glow 2s ease-in-out infinite;
}
@keyframes pulse-glow{
  0%,100%{box-shadow:0 8px 24px rgba(30,111,255,0.4)}
  50%{box-shadow:0 8px 40px rgba(0,212,255,0.6)}
}
.auth-header-title{font-size:22px;font-weight:900;margin-bottom:4px;color:var(--text)}
.auth-header-sub{font-size:12px;color:var(--text2);padding-bottom:6px}

/* Form */
.auth-form{padding:20px}
.auth-field{margin-bottom:14px;position:relative}
.auth-field-label{
  font-size:11px;color:var(--text2);font-weight:700;
  margin-bottom:6px;display:flex;align-items:center;gap:6px;
}
.auth-field-label i{color:var(--primary);font-size:12px}
.auth-input-wrap{position:relative}
.auth-input{
  width:100%;
  background:var(--input-bg);
  border:1.5px solid var(--border);
  border-radius:14px;
  padding:14px 14px 14px 44px;
  color:var(--text);font-family:var(--font);font-size:15px;
  outline:none;transition:all .25s;
  direction:rtl;
}
.auth-input::placeholder{color:var(--text3)}
.auth-input:focus{border-color:var(--primary);background:rgba(30,111,255,0.06);box-shadow:0 0 0 3px rgba(30,111,255,0.12)}
.auth-input.error{border-color:var(--red);background:rgba(255,23,68,0.05)}
.auth-input.success{border-color:var(--green)}
.auth-input-icon{
  position:absolute;top:50%;left:14px;transform:translateY(-50%);
  color:var(--text3);font-size:16px;pointer-events:none;transition:color .2s;
}
.auth-input:focus ~ .auth-input-icon{color:var(--primary)}

.auth-pw-toggle{
  position:absolute;top:50%;right:14px;transform:translateY(-50%);
  color:var(--text3);font-size:15px;cursor:pointer;padding:4px;
}

.auth-error{
  background:rgba(255,23,68,0.1);
  border:1px solid rgba(255,23,68,0.3);
  border-radius:10px;padding:10px 14px;
  font-size:12px;color:var(--red);
  margin-bottom:14px;display:none;
  animation:shake .3s ease;
  align-items:center;gap:8px;
}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
.auth-error.show{display:flex}

.auth-submit{
  width:100%;background:linear-gradient(135deg,var(--primary),#0a3fbe);
  border:none;border-radius:14px;padding:16px;
  color:#fff;font-family:var(--font);font-size:16px;font-weight:900;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  box-shadow:0 6px 20px rgba(30,111,255,0.4);transition:all .2s;
  margin-top:4px;
}
.auth-submit:active{transform:scale(.97)}
.auth-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}
.auth-submit.loading{pointer-events:none}
.auth-submit .spin{animation:spin .7s linear infinite;display:none}
.auth-submit.loading .spin{display:inline-block}
.auth-submit.loading .btn-text{display:none}
@keyframes spin{to{transform:rotate(360deg)}}

.auth-google-btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:13px;border-radius:14px;border:1.5px solid rgba(66,133,244,.3);background:rgba(66,133,244,.06);color:var(--text);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s;margin-bottom:4px}
.auth-google-btn:hover,.auth-google-btn:active{background:rgba(66,133,244,.12);border-color:rgba(66,133,244,.5)}
.auth-divider{display:flex;align-items:center;gap:12px;margin:16px 0;color:var(--text3);font-size:11px}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:var(--border)}

.auth-switch{text-align:center;font-size:12px;color:var(--text2);margin-top:14px;padding-bottom:20px}
.auth-switch a{color:var(--cyan);font-weight:800;cursor:pointer;text-decoration:none}
.auth-switch a:active{opacity:.7}

.auth-success{
  text-align:center;padding:40px 20px;display:none;
  animation:fadeIn .4s ease;
}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.auth-success.show{display:block}
.auth-success-icon{font-size:64px;margin-bottom:16px;animation:pop .4s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{from{transform:scale(0)}to{transform:scale(1)}}
.auth-success-title{font-size:20px;font-weight:900;color:var(--green);margin-bottom:8px}
.auth-success-sub{font-size:13px;color:var(--text2)}

.pw-strength{height:3px;border-radius:2px;background:var(--border);margin-top:6px;overflow:hidden}
.pw-strength-bar{height:100%;width:0;border-radius:2px;transition:all .3s}
.pw-strength-label{font-size:10px;margin-top:4px;font-weight:700}
</style>
</head>
<body>

<div id="regPageWrap">
  <div class="auth-card">
    <!-- زر الوضع الليلي/النهاري -->
    <button type="button" class="theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="تبديل الوضع الليلي/النهاري">
      <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    <!-- Header -->
    <div class="auth-header">
      <div class="auth-logo-big"><i class="fas fa-user-plus"></i></div>
      <div class="auth-header-title">إنشاء حساب جديد</div>
      <div class="auth-header-sub">انضم لمنصتنا مجاناً وابدأ الشحن فوراً</div>
    </div>

    <!-- Error -->
    <div style="padding:16px 20px 0">
      <div class="auth-error" id="authError"><i class="fas fa-exclamation-circle"></i> <span id="authErrorMsg"></span></div>
    </div>

    <!-- Register Form -->
    <div class="auth-form" id="formRegister">
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-user"></i> اسم المستخدم <span style="color:var(--red)">*</span></div>
        <div class="auth-input-wrap">
          <input class="auth-input" type="text" id="regUser" placeholder="3 أحرف على الأقل" autocomplete="username">
          <i class="fas fa-at auth-input-icon"></i>
        </div>
      </div>
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-id-card"></i> الاسم الكامل <span style="color:var(--red)">*</span></div>
        <div class="auth-input-wrap">
          <input class="auth-input" type="text" id="regName" placeholder="اسمك الكامل" autocomplete="name">
          <i class="fas fa-id-card auth-input-icon"></i>
        </div>
      </div>
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-envelope"></i> البريد الإلكتروني <span style="color:var(--red)">*</span></div>
        <div class="auth-input-wrap">
          <input class="auth-input" type="email" id="regEmail" placeholder="example@email.com" autocomplete="email">
          <i class="fas fa-envelope auth-input-icon"></i>
        </div>
      </div>
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-phone"></i> رقم الهاتف <span style="color:var(--red)">*</span></div>
        <div style="display:flex;gap:8px;align-items:stretch">
          <!-- زر اختيار الدولة -->
          <div style="position:relative">
            <button type="button" id="regDialBtn" onclick="toggleRegDial()"
              style="height:100%;min-height:46px;padding:0 10px;background:var(--input-bg);border:1.5px solid var(--border2);border-radius:12px;color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;min-width:80px">
              <span id="regDialFlag" style="font-size:18px">🇸🇦</span>
              <span id="regDialCode" style="font-family:monospace;font-size:12px">+966</span>
              <i class="fas fa-chevron-down" style="font-size:9px;color:var(--text3)" id="regDialArrow"></i>
            </button>
            <!-- Dropdown -->
            <div id="regDialDropdown" style="display:none;position:absolute;top:calc(100% + 4px);right:0;width:260px;background:var(--dropdown-bg);border:1px solid var(--border2);border-radius:14px;z-index:9999;box-shadow:0 16px 48px rgba(0,0,0,.35);overflow:hidden">
              <input type="text" id="regDialSearch" placeholder="🔍 ابحث..." oninput="filterRegDial(this.value)"
                style="width:100%;padding:10px 12px;background:var(--dropdown-search-bg);border:none;border-bottom:1px solid var(--border);color:var(--text);font-family:var(--font);font-size:13px;outline:none">
              <div id="regDialList" style="max-height:200px;overflow-y:auto"></div>
            </div>
          </div>
          <!-- حقل الرقم -->
          <div class="auth-input-wrap" style="flex:1;margin:0">
            <input class="auth-input" type="tel" id="regPhone" placeholder="5XXXXXXXX"
              autocomplete="tel" inputmode="numeric"
              oninput="this.value=this.value.replace(/\D/g,'')">
            <i class="fas fa-phone auth-input-icon"></i>
          </div>
        </div>
        <input type="hidden" id="regDialCodeVal" value="+966">
        <input type="hidden" id="regDialCountry" value="SA">
      </div>
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-lock"></i> كلمة المرور <span style="color:var(--red)">*</span></div>
        <div class="auth-input-wrap">
          <input class="auth-input" type="password" id="regPass" placeholder="6 أحرف على الأقل" autocomplete="new-password" oninput="checkPwStrength(this.value)">
          <i class="fas fa-lock auth-input-icon"></i>
          <i class="fas fa-eye auth-pw-toggle" onclick="togglePw('regPass',this)"></i>
        </div>
        <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
        <div class="pw-strength-label" id="pwLabel" style="color:var(--text3)"></div>
      </div>
      <div class="auth-field">
        <div class="auth-field-label"><i class="fas fa-lock"></i> تأكيد كلمة المرور <span style="color:var(--red)">*</span></div>
        <div class="auth-input-wrap">
          <input class="auth-input" type="password" id="regPass2" placeholder="أعد كتابة كلمة المرور" autocomplete="new-password">
          <i class="fas fa-lock auth-input-icon"></i>
          <i class="fas fa-eye auth-pw-toggle" onclick="togglePw('regPass2',this)"></i>
        </div>
      </div>
      <!-- كود الإحالة -->
      <div class="auth-field" style="margin-bottom:12px">
        <div class="auth-field-label" style="font-size:.8rem;color:var(--text3)">
          <i class="fas fa-gift" style="color:#00c853"></i> كود الإحالة (اختياري)
        </div>
        <input class="auth-input" type="text" id="regReferral"
               placeholder="أدخل كود صديقك للحصول على رصيد ترحيبي 🎁"
               style="text-transform:uppercase;letter-spacing:2px"
               autocomplete="off" oninput="this.value=this.value.toUpperCase()">
      </div>
      <button class="auth-submit" id="registerBtn" onclick="doRegister()">
        <i class="fas fa-spinner spin"></i>
        <span class="btn-text"><i class="fas fa-user-plus"></i> إنشاء الحساب</span>
      </button>
      <div class="auth-divider">أو</div>
      <?php if(getSetting('google_login_enabled')): ?>
      <a href="<?= SITE_URL ?>/auth/google/redirect.php" class="auth-google-btn">
        <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
        التسجيل عبر Google
      </a>
      <?php endif; ?>
      <div class="auth-switch">لديك حساب بالفعل؟ <a href="login.php">تسجيل الدخول</a></div>
    </div>

    <!-- Success -->
    <div class="auth-success" id="authSuccess">
      <div class="auth-success-icon">🎉</div>
      <div class="auth-success-title" id="successTitle">تم بنجاح!</div>
      <div class="auth-success-sub" id="successSub">جاري تحديث الصفحة...</div>
    </div>
  </div>
</div>

<script>
// ════════════════════════════════════════════════════
//  الوضع الليلي / النهاري (نفس مفتاح localStorage المستخدم في mobile.php)
// ════════════════════════════════════════════════════
(function initTheme() {
  const saved = localStorage.getItem('njaz_theme');
  if (saved === 'light') {
    document.body.classList.add('light-mode');
    updateThemeIcon(true);
  }
})();

function toggleTheme() {
  document.body.classList.add('theme-transitioning');
  setTimeout(() => document.body.classList.remove('theme-transitioning'), 400);

  const isLight = document.body.classList.toggle('light-mode');
  localStorage.setItem('njaz_theme', isLight ? 'light' : 'dark');
  updateThemeIcon(isLight);

  const meta = document.getElementById('themeColorMeta');
  if (meta) meta.content = isLight ? '#f0f4fa' : '#080c1a';
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

const SITE_URL_JS = '<?= SITE_URL ?>';
const AUTH_API = SITE_URL_JS + '/api/auth.php';

// ── Device ID (نفس آلية mobile.php) ──
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

// إظهار/إخفاء كلمة المرور
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

// أخطاء / نجاح
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
function showAuthSuccess(title, sub) {
  document.getElementById('formRegister').style.display = 'none';
  document.getElementById('authError').classList.remove('show');
  document.getElementById('successTitle').textContent = title;
  document.getElementById('successSub').textContent = sub;
  document.getElementById('authSuccess').classList.add('show');
}

// ── إنشاء حساب (نفس منطق mobile.php) ──
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

  if (user.length < 3)     { document.getElementById('regUser').classList.add('error');   showAuthError('اسم المستخدم 3 أحرف على الأقل'); return; }
  if (name.length < 2)     { document.getElementById('regName').classList.add('error');   showAuthError('الاسم الكامل مطلوب'); return; }
  if (!email.includes('@')){ document.getElementById('regEmail').classList.add('error');  showAuthError('أدخل بريد إلكتروني صحيح'); return; }
  if (phone.length < 6)    { document.getElementById('regPhone').classList.add('error');  showAuthError('رقم الهاتف مطلوب (6 أرقام على الأقل)'); return; }
  if (pass.length < 6)     { document.getElementById('regPass').classList.add('error');   showAuthError('كلمة المرور 6 أحرف على الأقل'); return; }
  if (pass !== pass2)      { document.getElementById('regPass2').classList.add('error');  showAuthError('كلمتا المرور غير متطابقتين'); return; }

  const btn = document.getElementById('registerBtn');
  btn.classList.add('loading'); btn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action','register'); fd.append('username',user); fd.append('device_id', getDeviceId()); fd.append('email',email);
    fd.append('full_name',name); fd.append('phone',fullPhone);
    fd.append('password',pass); fd.append('password2',pass2);
    const refCode = (document.getElementById('regReferral')?.value || '').trim().toUpperCase();
    if (refCode) fd.append('referral_code', refCode);
    const r = await fetch(AUTH_API, {method:'POST', body:fd, credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) {
      showAuthSuccess('تم إنشاء حسابك 🎉', 'مرحباً ' + d.name + '! جاري التحويل...');
      setTimeout(() => { window.location.href = SITE_URL_JS + '/index.php'; }, 1200);
    } else {
      showAuthError(d.msg);
    }
  } catch(e) { showAuthError('خطأ في الاتصال، حاول مجدداً'); }
  finally { btn.classList.remove('loading'); btn.disabled = false; }
}

// Enter لتأكيد النموذج
document.getElementById('formRegister').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.id !== 'regDialSearch') {
    e.preventDefault();
    doRegister();
  }
});

// ══ اختيار رمز الدولة (نفس قائمة mobile.php) ══
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
  const items = filter ? REG_COUNTRIES.filter(c => c.n.includes(filter) || c.d.includes(filter)) : REG_COUNTRIES;
  list.innerHTML = items.map(c => `
    <div onclick="selectRegDial('${c.d}','${c.f}','${c.n}')"
      style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-size:13px;transition:.15s"
      onmouseover="this.style.background='rgba(30,111,255,.12)'" onmouseout="this.style.background=''">
      <span style="font-size:18px">${c.f}</span>
      <span style="flex:1;color:var(--text)">${c.n}</span>
      <span style="color:var(--text3);font-family:monospace;font-size:12px">${c.d}</span>
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
</script>

</body>
</html>
