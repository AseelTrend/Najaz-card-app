<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
  </div>

  </div><!-- /.main-column -->

  <!-- BOTTOM NAV -->
  <div class="bottom-nav">
    <div class="nav-item active" id="nav-home" onclick="navTo('home')">
      <div class="nav-icon"><i class="fas fa-home"></i></div>
      <div class="nav-label">الرئيسية</div>
    </div>
    <div class="nav-item" id="nav-wallet" onclick="navTo('wallet')">
      <div class="nav-icon"><i class="fas fa-wallet"></i></div>
      <div class="nav-label">محفظتي</div>
    </div>
    <div class="nav-center">
      <div class="nav-center-btn" onclick="goHome()"><i class="fas fa-th-large"></i></div>
    </div>
    <div class="nav-item" id="nav-orders" onclick="window.location.href='<?= SITE_URL ?>/orders.php'">
      <div class="nav-icon"><i class="fas fa-shopping-bag"></i></div>
      <div class="nav-label">طلباتي</div>
    </div>
    <div class="nav-item" id="nav-profile" onclick="navTo('profile')">
      <div class="nav-icon"><i class="fas fa-user-circle"></i></div>
      <div class="nav-label">حسابي</div>
    </div>
  </div>
  <!-- ══════════════════════════════════════
       AUTH MODAL
  ══════════════════════════════════════ -->
  <div class="auth-overlay" id="authOverlay" onclick="closeAuthOnBg(event)">
    <div class="auth-sheet" id="authSheet">
      <div class="auth-handle"></div>

      <!-- Header -->
      <div class="auth-header">
        <div class="auth-logo-big">🚀</div>
        <div class="auth-header-title" id="authHeaderTitle">مرحباً بك</div>
        <div class="auth-header-sub" id="authHeaderSub">سجل دخولك لبدء الشحن</div>
      </div>

      <!-- Tabs -->
      <div class="auth-tabs">
        <div class="auth-tab active" id="tabLogin" onclick="switchTab('login')">تسجيل الدخول</div>
        <div class="auth-tab" id="tabRegister" onclick="switchTab('register')">إنشاء حساب</div>
      </div>

      <!-- Error -->
      <div style="padding:0 20px">
        <div class="auth-error" id="authError"><i class="fas fa-exclamation-circle"></i> <span id="authErrorMsg"></span></div>
      </div>

      <!-- ── LOGIN FORM ── -->
      <div class="auth-form active" id="formLogin">
        <div class="auth-field">
          <div class="auth-field-label"><i class="fas fa-user"></i> اسم المستخدم أو البريد</div>
          <div class="auth-input-wrap">
            <input class="auth-input" type="text" id="loginUser" placeholder="أدخل اسم المستخدم أو البريد" autocomplete="username">
            <i class="fas fa-user auth-input-icon"></i>
          </div>
        </div>
        <div class="auth-field">
          <div class="auth-field-label"><i class="fas fa-lock"></i> كلمة المرور</div>
          <div class="auth-input-wrap">
            <input class="auth-input" type="password" id="loginPass" placeholder="أدخل كلمة المرور" autocomplete="current-password">
            <i class="fas fa-lock auth-input-icon"></i>
            <i class="fas fa-eye auth-pw-toggle" onclick="togglePw('loginPass',this)"></i>
          </div>
        </div>
        <button class="auth-submit" id="loginBtn" onclick="doLogin()">
          <i class="fas fa-spinner spin"></i>
          <span class="btn-text"><i class="fas fa-sign-in-alt"></i> تسجيل الدخول</span>
        </button>
        <div class="auth-divider">أو</div>
        <?php if(getSetting('google_login_enabled')): ?>
        <a href="<?= SITE_URL ?>/auth/google/redirect.php" class="auth-google-btn">
          <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> المتابعة عبر Google
        </a>
        <?php endif; ?>
        <div class="auth-switch">ليس لديك حساب؟ <span onclick="switchTab('register')">إنشاء حساب جديد</span></div>
        <div class="auth-switch" style="margin-top:6px">
          <a href="<?= SITE_URL ?>/forgot-password.php" style="color:var(--text3);text-decoration:none">نسيت كلمة المرور؟</a>
        </div>
      </div>

      <!-- ── 2FA SCREEN ── -->
      <div class="auth-form" id="form2FA" style="display:none">
        <div style="text-align:center;margin-bottom:16px">
          <div style="font-size:2.5rem;margin-bottom:8px">🔐</div>
          <div style="font-weight:900;font-size:1rem;margin-bottom:4px">رمز المصادقة الثنائية</div>
          <div style="font-size:.78rem;color:var(--text2)">افتح تطبيق Google Authenticator وأدخل الرمز</div>
        </div>
        <!-- OTP digits -->
        <div style="direction:ltr;margin-bottom:14px">
          <div style="display:flex;gap:8px;justify-content:center" id="loginOtpRow">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
            <input class="auth-2fa-digit" type="tel" maxlength="1" pattern="[0-9]" inputmode="numeric"
              style="width:44px;height:54px;background:var(--bg2);border:2px solid var(--border2);border-radius:10px;color:#fff;font-family:var(--font);font-size:1.3rem;font-weight:900;text-align:center;outline:none;transition:.2s">
          </div>
          <!-- Timer -->
          <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:10px;font-size:.72rem;color:var(--text3)">
            <i class="fas fa-clock"></i>
            <span>الرمز يتغير كل 30 ثانية</span>
            <span id="totpTimer" style="color:var(--cyan);font-weight:800;font-family:monospace"></span>
          </div>
        </div>
        <button class="auth-submit" id="twoFaBtn" onclick="doLogin2FA()">
          <i class="fas fa-spinner spin"></i>
          <span class="btn-text"><i class="fas fa-shield-alt"></i> تحقق ودخول</span>
        </button>
        <div style="text-align:center;margin-top:12px">
          <span style="font-size:.78rem;color:var(--text2);cursor:pointer" onclick="back2FA()">
            <i class="fas fa-arrow-right" style="font-size:.65rem"></i> العودة لتسجيل الدخول
          </span>
        </div>
      </div>

      <!-- ── REGISTER FORM ── -->
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
                style="height:100%;min-height:46px;padding:0 10px;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.12);border-radius:12px;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;min-width:80px">
                <span id="regDialFlag" style="font-size:18px">🇸🇦</span>
                <span id="regDialCode" style="font-family:monospace;font-size:12px">+966</span>
                <i class="fas fa-chevron-down" style="font-size:9px;color:#8895a7" id="regDialArrow"></i>
              </button>
              <!-- Dropdown -->
              <div id="regDialDropdown" style="display:none;position:absolute;top:calc(100% + 4px);right:0;width:260px;background:#0d1428;border:1px solid rgba(255,255,255,.12);border-radius:14px;z-index:9999;box-shadow:0 16px 48px rgba(0,0,0,.7);overflow:hidden">
                <input type="text" id="regDialSearch" placeholder="🔍 ابحث..." oninput="filterRegDial(this.value)"
                  style="width:100%;padding:10px 12px;background:#131d35;border:none;border-bottom:1px solid rgba(255,255,255,.07);color:#fff;font-family:var(--font);font-size:13px;outline:none">
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
          <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg> المتابعة عبر Google
        </a>
        <?php endif; ?>
        <div class="auth-switch">لديك حساب؟ <span onclick="switchTab('login')">تسجيل الدخول</span></div>
      </div>

      <!-- ── SUCCESS SCREEN ── -->
      <div class="auth-success" id="authSuccess">
        <div class="auth-success-icon">🎉</div>
        <div class="auth-success-title" id="successTitle">تم بنجاح!</div>
        <div class="auth-success-sub" id="successSub">جاري تحديث الصفحة...</div>
      </div>

      <!-- ── شاشة جهاز غير مصرح ── -->
      <div id="devicePendingScreen" style="display:none;padding:20px;text-align:center">
        <div style="font-size:3rem;margin-bottom:10px">🔒</div>
        <div style="font-size:1.1rem;font-weight:900;margin-bottom:6px;color:#f5a623">جهاز غير مصرح</div>
        <div style="font-size:.83rem;color:var(--text2);line-height:1.7;margin-bottom:16px">
          تم التحقق من بياناتك، لكن هذا المتصفح غير مسجّل في حسابك.
        </div>
        <!-- حالة إرسال بريد التأكيد -->
        <div id="devicePendingEmailBox" style="display:none;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:14px;padding:14px;margin-bottom:14px;text-align:right;font-size:.82rem;color:#8895a7;line-height:1.9">
          <div style="color:#22c55e;font-weight:800;margin-bottom:5px"><i class="fas fa-envelope-circle-check"></i> تم إرسال رابط تأكيد</div>
          <div>أرسلنا رابط تصريح فوري إلى <strong id="dpEmailHint" style="color:var(--text)">—</strong>. افتح البريد واضغط "تصريح الجهاز" لتسجيل الدخول مباشرة.</div>
        </div>
        <div id="devicePendingNoEmail" style="font-size:.83rem;color:var(--text2);line-height:1.7;margin-bottom:16px">
          تواصل مع الدعم الفني لتفعيله.
        </div>
        <!-- معلومات تُرسل للدعم -->
        <div id="devicePendingInfo" style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:14px;padding:14px;margin-bottom:18px;text-align:right;font-size:.8rem;color:#8895a7;line-height:2.1">
          <div style="color:#f5a623;font-weight:800;margin-bottom:5px"><i class="fas fa-info-circle"></i> المعلومات المرسلة تلقائياً</div>
          <div><span style="color:var(--text);font-weight:600">الموقع:</span> <span id="dpSite"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></span></div>
          <div><span style="color:var(--text);font-weight:600">المستخدم:</span> <span id="dpUser">—</span></div>
          <div><span style="color:var(--text);font-weight:600">رقم الجهاز:</span> <span id="dpDid" style="font-family:monospace;font-size:.72rem;word-break:break-all">—</span></div>
        </div>
        <!-- زر واتساب -->
        <a id="deviceWaBtn" href="#" target="_blank" rel="noopener"
           style="display:flex;align-items:center;justify-content:center;gap:10px;
                  background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;
                  border-radius:14px;padding:14px;text-decoration:none;
                  font-weight:800;font-size:.9rem;margin-bottom:12px;
                  box-shadow:0 4px 16px rgba(37,211,102,.3)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="white">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
            <path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.556 4.112 1.528 5.836L.057 24l6.305-1.654A11.954 11.954 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.894a9.875 9.875 0 0 1-5.012-1.367l-.36-.214-3.741.98 1.001-3.648-.236-.374A9.869 9.869 0 0 1 2.106 12C2.106 6.535 6.535 2.106 12 2.106S21.894 6.535 21.894 12 17.465 21.894 12 21.894z"/>
          </svg>
          تواصل مع الدعم عبر واتساب
        </a>
        <button onclick="closeAuthModal()" style="width:100%;padding:12px;border-radius:14px;background:var(--card2);border:1px solid var(--border);color:var(--text2);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
          <i class="fas fa-arrow-right"></i> العودة
        </button>
      </div>

      <div style="height:20px"></div>
    </div>
  </div>

</div>
