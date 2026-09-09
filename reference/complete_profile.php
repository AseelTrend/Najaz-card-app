<?php
require_once __DIR__ . '/includes/config.php';

// يجب أن تكون هناك بيانات جوجل معلقة
if (empty($_SESSION['google_pending'])) {
    redirect(SITE_URL . '/login.php');
}

$gp       = $_SESSION['google_pending'];
$fullName = $gp['full_name'] ?? '';
$email    = $gp['email']    ?? '';
$avatar   = $gp['avatar']   ?? '';

// ── حفظ الملف الشخصي ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = trim($_POST['country']  ?? '');
    $phone   = trim($_POST['phone']    ?? '');
    $dialCode= trim($_POST['dial_code'] ?? '');

    if (empty($country)) {
        $error = 'يرجى اختيار الدولة';
    } elseif (empty($phone) || !preg_match('/^\d{6,15}$/', $phone)) {
        $error = 'رقم الهاتف غير صحيح';
    } else {
        $fullPhone = $dialCode . $phone;

        // التحقق أن الهاتف غير مستخدم
        $chk = $pdo->prepare("SELECT id FROM users WHERE phone=?");
        $chk->execute([$fullPhone]);
        if ($chk->fetch()) {
            $error = 'رقم الهاتف مستخدم بالفعل لحساب آخر';
        } else {
            require_once __DIR__ . '/includes/device_helper.php';
            require_once __DIR__ . '/includes/notifications.php';

            $googleId = $gp['google_id'];

            // توليد username: اسم جوجل + ID الحساب
            // نحتاج نعرف ID الحساب أولاً — نُنشئه ثم نحدث username
            $tempUsername = 'g_' . time() . '_' . rand(100,999);
            $pdo->prepare("INSERT INTO users (username, email, password, full_name, phone, google_id, google_avatar, status, role, created_at, last_login)
                           VALUES (?, ?, '', ?, ?, ?, ?, 1, 'customer', NOW(), NOW())")
                ->execute([$tempUsername, $email, $fullName, $fullPhone, $googleId, $avatar]);
            $newUserId = $pdo->lastInsertId();

            // username = اسم جوجل (حروف وأرقام) + ID الحساب
            $namePart = preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}]/u', '', $fullName);
            $namePart = mb_substr($namePart, 0, 20);
            if (mb_strlen($namePart) < 2) $namePart = 'user';
            $finalUsername = $namePart . $newUserId;

            // تأكد من عدم التكرار
            $uChk = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $uChk->execute([$finalUsername, $newUserId]);
            if ($uChk->fetch()) $finalUsername = $namePart . $newUserId . rand(10,99);

            $pdo->prepare("UPDATE users SET username=?, phone=? WHERE id=?")
                ->execute([$finalUsername, $fullPhone, $newUserId]);

            checkAndRegisterDevice($pdo, $newUserId, true);

            try {
                sendNotification($pdo, $newUserId, 'system',
                    '🎉 مرحباً بك في ' . (getSetting('site_name') ?: SITE_NAME),
                    'يسعدنا انضمامك! يمكنك الآن تصفح الخدمات وطلبها.',
                    'fas fa-star', '#4285f4');
                notifyAdminNewRegister($pdo, $finalUsername, $email);
            } catch (Exception $e) {}

            // تسجيل الدخول
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$newUserId]);
            $user = $stmt->fetch();

            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            unset($_SESSION['google_pending']);

            redirect(SITE_URL . '/mobile.php');
        }
    }
}

// قائمة الدول
$countries = [
    ['code'=>'SA','name'=>'المملكة العربية السعودية','dial'=>'+966','flag'=>'🇸🇦'],
    ['code'=>'AE','name'=>'الإمارات العربية المتحدة','dial'=>'+971','flag'=>'🇦🇪'],
    ['code'=>'KW','name'=>'الكويت','dial'=>'+965','flag'=>'🇰🇼'],
    ['code'=>'QA','name'=>'قطر','dial'=>'+974','flag'=>'🇶🇦'],
    ['code'=>'BH','name'=>'البحرين','dial'=>'+973','flag'=>'🇧🇭'],
    ['code'=>'OM','name'=>'عُمان','dial'=>'+968','flag'=>'🇴🇲'],
    ['code'=>'YE','name'=>'اليمن','dial'=>'+967','flag'=>'🇾🇲'],
    ['code'=>'IQ','name'=>'العراق','dial'=>'+964','flag'=>'🇮🇶'],
    ['code'=>'SY','name'=>'سوريا','dial'=>'+963','flag'=>'🇸🇾'],
    ['code'=>'JO','name'=>'الأردن','dial'=>'+962','flag'=>'🇯🇴'],
    ['code'=>'LB','name'=>'لبنان','dial'=>'+961','flag'=>'🇱🇧'],
    ['code'=>'PS','name'=>'فلسطين','dial'=>'+970','flag'=>'🇵🇸'],
    ['code'=>'EG','name'=>'مصر','dial'=>'+20','flag'=>'🇪🇬'],
    ['code'=>'LY','name'=>'ليبيا','dial'=>'+218','flag'=>'🇱🇾'],
    ['code'=>'TN','name'=>'تونس','dial'=>'+216','flag'=>'🇹🇳'],
    ['code'=>'DZ','name'=>'الجزائر','dial'=>'+213','flag'=>'🇩🇿'],
    ['code'=>'MA','name'=>'المغرب','dial'=>'+212','flag'=>'🇲🇦'],
    ['code'=>'SD','name'=>'السودان','dial'=>'+249','flag'=>'🇸🇩'],
    ['code'=>'TR','name'=>'تركيا','dial'=>'+90','flag'=>'🇹🇷'],
    ['code'=>'PK','name'=>'باكستان','dial'=>'+92','flag'=>'🇵🇰'],
    ['code'=>'IN','name'=>'الهند','dial'=>'+91','flag'=>'🇮🇳'],
    ['code'=>'US','name'=>'الولايات المتحدة','dial'=>'+1','flag'=>'🇺🇸'],
    ['code'=>'GB','name'=>'المملكة المتحدة','dial'=>'+44','flag'=>'🇬🇧'],
    ['code'=>'DE','name'=>'ألمانيا','dial'=>'+49','flag'=>'🇩🇪'],
    ['code'=>'FR','name'=>'فرنسا','dial'=>'+33','flag'=>'🇫🇷'],
    ['code'=>'CA','name'=>'كندا','dial'=>'+1','flag'=>'🇨🇦'],
    ['code'=>'AU','name'=>'أستراليا','dial'=>'+61','flag'=>'🇦🇺'],
];
$defaultCountry = $countries[0]; // السعودية افتراضياً
$siteName = getSetting('site_name') ?: SITE_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <title>إتمام التسجيل — <?= htmlspecialchars($siteName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Cairo',sans-serif;background:#080c1a;color:#fff;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;direction:rtl}
    .card{width:100%;max-width:420px;background:#0d1428;border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:32px 24px;box-shadow:0 20px 60px rgba(0,0,0,.6)}
    
    /* Header */
    .header{text-align:center;margin-bottom:28px}
    .avatar-wrap{width:80px;height:80px;border-radius:50%;overflow:hidden;margin:0 auto 14px;border:3px solid #1e6fff;box-shadow:0 4px 20px rgba(30,111,255,.4)}
    .avatar-wrap img{width:100%;height:100%;object-fit:cover}
    .avatar-placeholder{width:80px;height:80px;border-radius:50%;margin:0 auto 14px;background:linear-gradient(135deg,#1e6fff,#00d4ff);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:900;color:#fff;border:3px solid rgba(30,111,255,.5)}
    .greeting{font-size:20px;font-weight:900;color:#fff;margin-bottom:4px}
    .sub{font-size:13px;color:#8895a7}
    .email-chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:4px 12px;font-size:12px;color:#8895a7;margin-top:8px}

    /* Form */
    .label{font-size:12px;font-weight:700;color:#8895a7;margin-bottom:7px;display:block}
    .field{margin-bottom:18px}
    
    /* Country select */
    .country-select-btn{width:100%;background:#131d35;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:13px 16px;color:#fff;font-family:'Cairo',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:10px;transition:border .2s;text-align:right}
    .country-select-btn:hover,.country-select-btn:focus{border-color:#1e6fff;outline:none}
    .country-flag{font-size:20px;line-height:1}
    .country-name{flex:1}
    .country-dial{font-size:12px;color:#8895a7;font-family:monospace}

    /* Country dropdown */
    .country-dropdown{position:absolute;z-index:100;width:100%;background:#0d1428;border:1px solid rgba(255,255,255,.12);border-radius:14px;margin-top:4px;max-height:260px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.6);display:none}
    .country-dropdown.open{display:flex;flex-direction:column}
    .country-search{padding:10px 12px;background:#131d35;border:none;border-bottom:1px solid rgba(255,255,255,.06);color:#fff;font-family:'Cairo',sans-serif;font-size:13px;width:100%;outline:none}
    .country-list{overflow-y:auto;max-height:200px}
    .country-option{display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;transition:background .15s;font-size:13px}
    .country-option:hover{background:rgba(30,111,255,.1)}
    .country-option.active{background:rgba(30,111,255,.15);color:#1e6fff}

    /* Phone */
    .phone-wrap{display:flex;gap:8px}
    .dial-badge{background:#131d35;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:0 14px;display:flex;align-items:center;gap:6px;font-size:14px;font-weight:700;color:#fff;white-space:nowrap;min-width:82px;justify-content:center;font-family:monospace}
    .dial-flag{font-size:18px}
    .phone-input{flex:1;background:#131d35;border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:13px 14px;color:#fff;font-family:'Cairo',sans-serif;font-size:15px;outline:none;transition:border .2s;direction:ltr;text-align:left}
    .phone-input:focus{border-color:#1e6fff}
    .phone-input::placeholder{color:#4a5568;direction:rtl;text-align:right}

    /* Username preview */
    .username-preview{background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:12px;padding:12px 14px;display:flex;align-items:center;gap:10px;margin-bottom:18px}
    .username-preview i{color:#00d4ff;font-size:16px}
    .username-text{font-size:13px;color:#8895a7}
    .username-val{font-size:14px;font-weight:800;color:#00d4ff;direction:ltr}

    /* Error */
    .error-box{background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);border-radius:12px;padding:12px 14px;font-size:13px;color:#ff4757;margin-bottom:16px;display:flex;gap:8px;align-items:center}

    /* Button */
    .btn-submit{width:100%;padding:16px;background:linear-gradient(135deg,#1e6fff,#0a3fbe);border:none;border-radius:14px;color:#fff;font-family:'Cairo',sans-serif;font-size:16px;font-weight:900;cursor:pointer;transition:all .2s;box-shadow:0 6px 24px rgba(30,111,255,.4)}
    .btn-submit:hover{transform:translateY(-1px);box-shadow:0 8px 28px rgba(30,111,255,.5)}
    .btn-submit:active{transform:scale(.98)}
    .btn-submit:disabled{background:#2a3450;box-shadow:none;cursor:not-allowed}
    
    .wrapper{position:relative}
  </style>
</head>
<body>
<div class="card">

  <!-- الهيدر -->
  <div class="header">
    <?php if ($avatar): ?>
    <div class="avatar-wrap"><img src="<?= htmlspecialchars($avatar) ?>" alt=""></div>
    <?php else: ?>
    <div class="avatar-placeholder"><?= mb_substr($fullName ?: 'U', 0, 1) ?></div>
    <?php endif; ?>
    <div class="greeting">أهلاً، <?= htmlspecialchars(explode(' ', $fullName)[0]) ?>! 👋</div>
    <div class="sub">خطوة واحدة لإتمام حسابك</div>
    <div class="email-chip"><i class="fab fa-google" style="color:#4285f4"></i><?= htmlspecialchars($email) ?></div>
  </div>

  <?php if (!empty($error)): ?>
  <div class="error-box"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" id="profileForm">
    <input type="hidden" name="dial_code" id="dialCodeInput" value="<?= $defaultCountry['dial'] ?>">
    <input type="hidden" name="country"   id="countryInput"   value="">

    <!-- اسم المستخدم (عرض فقط) -->
    <div class="username-preview">
      <i class="fas fa-at"></i>
      <div style="flex:1">
        <div class="username-text">اسم المستخدم (يُحدد تلقائياً)</div>
        <div class="username-val" id="usernamePreview"><?= htmlspecialchars($fullName) ?><span style="color:#8895a7">####</span></div>
      </div>
      <i class="fas fa-lock" style="color:#4a5568;font-size:13px"></i>
    </div>

    <!-- اختيار الدولة -->
    <div class="field wrapper" id="countryWrapper">
      <label class="label"><i class="fas fa-globe" style="color:#1e6fff;margin-left:5px"></i>الدولة</label>
      <button type="button" class="country-select-btn" id="countryBtn" onclick="toggleCountryDropdown()">
        <span class="country-flag" id="selFlag"><?= $defaultCountry['flag'] ?></span>
        <span class="country-name" id="selName">اختر دولتك</span>
        <span class="country-dial" id="selDial"></span>
        <i class="fas fa-chevron-down" id="dropArrow" style="color:#8895a7;font-size:11px;transition:.2s"></i>
      </button>
      <div class="country-dropdown" id="countryDropdown">
        <input class="country-search" type="text" placeholder="🔍 ابحث عن دولة..." id="countrySearch" oninput="filterCountries(this.value)">
        <div class="country-list" id="countryList"></div>
      </div>
    </div>

    <!-- رقم الهاتف -->
    <div class="field">
      <label class="label"><i class="fas fa-phone" style="color:#00e676;margin-left:5px"></i>رقم الهاتف</label>
      <div class="phone-wrap">
        <div class="dial-badge" id="dialBadge">
          <span class="dial-flag" id="dialFlag"><?= $defaultCountry['flag'] ?></span>
          <span id="dialCode"><?= $defaultCountry['dial'] ?></span>
        </div>
        <input type="tel" name="phone" id="phoneInput" class="phone-input"
          placeholder="أدخل رقم هاتفك" required
          inputmode="numeric" pattern="[0-9]{6,15}"
          oninput="this.value=this.value.replace(/\D/g,'')">
      </div>
    </div>

    <button type="submit" class="btn-submit" id="submitBtn">
      <i class="fas fa-rocket" style="margin-left:8px"></i>إتمام التسجيل
    </button>
  </form>
</div>

<script>
// ── بيانات الدول ──
const COUNTRIES = <?= json_encode($countries, JSON_UNESCAPED_UNICODE) ?>;
let selectedCountry = null;

// بناء القائمة
function buildCountryList(filter = '') {
  const list = document.getElementById('countryList');
  const f = filter.toLowerCase();
  const filtered = COUNTRIES.filter(c =>
    c.name.includes(filter) || c.dial.includes(filter) || c.code.toLowerCase().includes(f)
  );
  list.innerHTML = filtered.map(c => `
    <div class="country-option ${selectedCountry?.code === c.code ? 'active' : ''}"
      onclick="selectCountry('${c.code}')">
      <span style="font-size:20px">${c.flag}</span>
      <span style="flex:1">${c.name}</span>
      <span style="font-size:12px;color:#8895a7;font-family:monospace">${c.dial}</span>
    </div>
  `).join('');
}

function selectCountry(code) {
  const c = COUNTRIES.find(x => x.code === code);
  if (!c) return;
  selectedCountry = c;

  // تحديث الزر
  document.getElementById('selFlag').textContent = c.flag;
  document.getElementById('selName').textContent = c.name;
  document.getElementById('selDial').textContent = c.dial;

  // تحديث حقل الهاتف
  document.getElementById('dialFlag').textContent = c.flag;
  document.getElementById('dialCode').textContent = c.dial;
  document.getElementById('dialCodeInput').value  = c.dial;
  document.getElementById('countryInput').value   = c.code;

  closeCountryDropdown();
  document.getElementById('phoneInput').focus();
  buildCountryList();
}

function toggleCountryDropdown() {
  const dd = document.getElementById('countryDropdown');
  const isOpen = dd.classList.contains('open');
  if (isOpen) { closeCountryDropdown(); }
  else {
    dd.classList.add('open');
    document.getElementById('dropArrow').style.transform = 'rotate(180deg)';
    document.getElementById('countrySearch').value = '';
    buildCountryList();
    setTimeout(() => document.getElementById('countrySearch').focus(), 100);
  }
}

function closeCountryDropdown() {
  document.getElementById('countryDropdown').classList.remove('open');
  document.getElementById('dropArrow').style.transform = 'rotate(0deg)';
}

function filterCountries(q) { buildCountryList(q); }

// إغلاق عند الضغط خارج
document.addEventListener('click', e => {
  if (!document.getElementById('countryWrapper').contains(e.target)) closeCountryDropdown();
});

// معاينة اسم المستخدم
const namePart = '<?= addslashes(preg_replace('/[^a-zA-Z0-9\x{0600}-\x{06FF}]/u', '', $fullName)) ?>';
document.getElementById('usernamePreview').innerHTML =
  `<span>${namePart || 'user'}</span><span style="color:#8895a7">####</span>`;

// التحقق قبل الإرسال
document.getElementById('profileForm').addEventListener('submit', function(e) {
  if (!selectedCountry) {
    e.preventDefault();
    document.getElementById('countryBtn').style.borderColor = '#ff4757';
    document.getElementById('countryBtn').scrollIntoView({behavior:'smooth'});
    return;
  }
  const phone = document.getElementById('phoneInput').value.trim();
  if (!phone || phone.length < 6) {
    e.preventDefault();
    document.getElementById('phoneInput').style.borderColor = '#ff4757';
    document.getElementById('phoneInput').focus();
    return;
  }
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-left:8px"></i>جاري الحفظ...';
});

buildCountryList();
</script>
</body>
</html>
