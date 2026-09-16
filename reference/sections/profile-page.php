<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <div class="page" id="page-profile">
      <div class="profile-page">
        <div style="font-size:18px;font-weight:900;margin-bottom:14px;padding-top:4px"><i class="fas fa-user-circle" style="color:var(--cyan)"></i> حسابي</div>
        <?php if (!isLoggedIn()): ?>
        <div class="auth-gate-box" onclick="openAuth('login')" style="margin-bottom:14px">
          <div class="auth-gate-icon">👤</div>
          <div class="auth-gate-title">سجل دخولك للوصول لحسابك</div>
          <div class="auth-gate-sub">أنشئ حساباً مجانياً الآن</div>
          <div style="display:flex;gap:10px;margin-top:12px;justify-content:center">
            <div class="auth-gate-btn" onclick="event.stopPropagation();openAuth('login')"><i class="fas fa-sign-in-alt"></i> دخول</div>
            <div class="auth-gate-btn" style="background:rgba(0,212,255,0.15);border-color:rgba(0,212,255,0.3);color:var(--cyan)" onclick="event.stopPropagation();openAuth('register')"><i class="fas fa-user-plus"></i> تسجيل</div>
          </div>
        </div>
        <?php else: ?>

        <!-- ── بطاقة البروفايل ── -->
        <div class="profile-header-card" style="position:relative;padding-bottom:20px">

          <!-- الصورة الشخصية -->
          <div style="position:relative;width:90px;margin:0 auto 12px">
            <div id="profileAvatarWrap" style="width:90px;height:90px;border-radius:50%;overflow:hidden;border:3px solid var(--primary);box-shadow:0 4px 20px rgba(30,111,255,0.4);background:linear-gradient(135deg,var(--primary),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:36px;cursor:pointer" onclick="openAvatarMenu()">
              <?php if ($profileAvatar): ?>
              <img id="profileAvatarImg" src="<?= htmlspecialchars($profileAvatar) ?>" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display='none';document.getElementById('profileAvatarEmoji').style.display='flex'">
              <span id="profileAvatarEmoji" style="display:none;font-size:36px">👤</span>
              <?php else: ?>
              <img id="profileAvatarImg" style="display:none">
              <span id="profileAvatarEmoji" style="font-size:36px">👤</span>
              <?php endif; ?>
            </div>
            <!-- زر الكاميرا -->
            <button onclick="openAvatarMenu()" style="position:absolute;bottom:2px;right:2px;width:28px;height:28px;background:var(--primary);border:2px solid var(--bg);border-radius:50%;color:#fff;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0">
              <i class="fas fa-camera"></i>
            </button>
          </div>

          <!-- الاسم — قابل للتعديل -->
          <div style="position:relative;display:inline-flex;align-items:center;gap:8px;margin-bottom:4px">
            <span id="profileDisplayName" style="font-size:18px;font-weight:900"><?= htmlspecialchars($userName) ?></span>
            <button onclick="openEditName()" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:8px;padding:4px 8px;color:#8895a7;font-size:11px;cursor:pointer">
              <i class="fas fa-pen"></i>
            </button>
          </div>

          <div class="profile-id" style="margin-bottom:6px">العميل رقم: <?= $userId_display ?></div>

          <!-- شارة التحقق -->
          <?php if($kycStatus === 'approved'): ?>
          <div style="display:inline-flex;align-items:center;gap:4px;background:rgba(0,200,83,.15);border:1px solid rgba(0,200,83,.3);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;color:#00e676;margin-bottom:4px">
            <i class="fas fa-check-circle"></i> موثّق
          </div>
          <?php else: ?>
          <div class="profile-badge"><i class="fas fa-check-circle"></i> حساب نشط</div>
          <?php endif; ?>
        </div>

        <!-- قائمة الحساب -->
        <a href="#" onclick="event.preventDefault();navTo('wallet')" class="profile-menu-item">
          <div class="pmi-icon" style="background:rgba(0,230,118,0.12);color:var(--green)"><i class="fas fa-wallet"></i></div>
          <div class="pmi-label">محفظتي</div>
          <div style="color:var(--cyan);font-size:13px;font-weight:700"><?= formatMoney($userBalance) ?></div>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </a>
        <a href="<?= SITE_URL ?>/gift.php" class="profile-menu-item">
          <div class="pmi-icon" style="background:rgba(255,95,143,.12);color:#ff5f8f"><i class="fas fa-gift"></i></div>
          <div class="pmi-label">الهدايا</div>
          <?php
          try {
            $pgc = $pdo->prepare("SELECT COUNT(*) FROM gifts WHERE receiver_id=? AND status='pending'");
            $pgc->execute([$_SESSION['user_id']??0]);
            $pgiftCount = (int)$pgc->fetchColumn();
          } catch(Exception $e){ $pgiftCount=0; }
          if($pgiftCount>0): ?>
          <span style="background:#ff5f8f;color:#fff;font-size:.65rem;font-weight:900;padding:2px 8px;border-radius:20px"><?=$pgiftCount?> جديدة 🎁</span>
          <?php endif; ?>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </a>
        <a href="<?= SITE_URL ?>/orders.php" class="profile-menu-item">
          <div class="pmi-icon" style="background:rgba(30,111,255,0.12);color:var(--primary)"><i class="fas fa-shopping-bag"></i></div>
          <div class="pmi-label">طلباتي</div>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </a>
        <div class="profile-menu-item" onclick="closeSidebar();navTo('kyc')">
          <div class="pmi-icon" style="background:rgba(0,212,170,.12);color:#00d4aa"><i class="fas fa-id-card"></i></div>
          <div class="pmi-label">تحقق الهوية</div>
          <?php if($kycStatus==='approved'): ?>
          <span style="font-size:11px;color:#00e676;font-weight:700">✓ موثّق</span>
          <?php elseif($kycStatus==='pending'): ?>
          <span style="font-size:11px;color:#f5a623;font-weight:700">⏳ قيد المراجعة</span>
          <?php endif; ?>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </div>
        <?php if (isAdmin()): ?>
        <a href="<?= SITE_URL ?>/admin/" class="profile-menu-item">
          <div class="pmi-icon" style="background:rgba(245,166,35,0.12);color:var(--gold)"><i class="fas fa-cog"></i></div>
          <div class="pmi-label">لوحة الإدارة</div>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </a>
        <?php endif; ?>
        <div class="profile-menu-item" onclick="doLogout()">
          <div class="pmi-icon" style="background:rgba(255,23,68,0.12);color:var(--red)"><i class="fas fa-sign-out-alt"></i></div>
          <div class="pmi-label">تسجيل الخروج</div>
          <div class="pmi-arrow"><i class="fas fa-chevron-left"></i></div>
        </div>
        <?php endif; ?>
        <div style="height:20px"></div>
      </div>
    </div>

    <!-- ── مودال تغيير الاسم ── -->
    <div id="editNameModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:flex-end;justify-content:center">
      <div style="width:100%;max-width:430px;background:var(--card);border-radius:24px 24px 0 0;border-top:1px solid var(--border);padding:24px 20px 40px;animation:slideUp .3s ease">
        <div style="font-size:16px;font-weight:900;margin-bottom:18px;display:flex;align-items:center;gap:8px">
          <i class="fas fa-pen" style="color:var(--primary)"></i> تغيير الاسم
        </div>
        <input type="text" id="editNameInput" placeholder="أدخل اسمك الكامل"
          style="width:100%;background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:13px 14px;color:#fff;font-family:var(--font);font-size:15px;outline:none;margin-bottom:14px;box-sizing:border-box"
          maxlength="80">
        <div style="display:flex;gap:10px">
          <button onclick="closeEditName()"
            style="flex:1;padding:13px;background:var(--card2);border:1px solid var(--border);border-radius:12px;color:var(--text2);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer">
            إلغاء
          </button>
          <button onclick="saveProfileName()"
            style="flex:2;padding:13px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border:none;border-radius:12px;color:#fff;font-family:var(--font);font-size:14px;font-weight:800;cursor:pointer">
            <i class="fas fa-save" style="margin-left:6px"></i>حفظ
          </button>
        </div>
      </div>
    </div>

    <!-- ── مودال تغيير الصورة ── -->
    <div id="avatarMenuModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);align-items:flex-end;justify-content:center">
      <div style="width:100%;max-width:430px;background:var(--card);border-radius:24px 24px 0 0;border-top:1px solid var(--border);padding:20px 16px 36px">
        <div style="font-size:16px;font-weight:900;margin-bottom:16px;text-align:center">
          <i class="fas fa-camera" style="color:var(--cyan)"></i> الصورة الشخصية
        </div>
        <div style="display:flex;gap:10px;margin-bottom:12px">
          <button onclick="avatarFromCamera()"
            style="flex:1;padding:16px 8px;background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.25);border-radius:14px;color:var(--cyan);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
            <i class="fas fa-camera" style="display:block;font-size:22px;margin-bottom:6px"></i>تصوير
          </button>
          <button onclick="avatarFromGallery()"
            style="flex:1;padding:16px 8px;background:rgba(167,139,250,.1);border:1px solid rgba(167,139,250,.25);border-radius:14px;color:#a78bfa;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
            <i class="fas fa-images" style="display:block;font-size:22px;margin-bottom:6px"></i>المعرض
          </button>
          <button onclick="removeAvatar()"
            style="flex:1;padding:16px 8px;background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.25);border-radius:14px;color:#ff4757;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
            <i class="fas fa-trash" style="display:block;font-size:22px;margin-bottom:6px"></i>حذف
          </button>
        </div>
        <button onclick="closeAvatarMenu()"
          style="width:100%;padding:12px;background:var(--card2);border:1px solid var(--border);border-radius:12px;color:var(--text2);font-family:var(--font);font-size:14px;cursor:pointer">
          إلغاء
        </button>
        <!-- inputs مخفية -->
        <input type="file" id="avatarFileGallery" accept="image/*" style="display:none" onchange="uploadAvatar(this)">
        <input type="file" id="avatarFileCamera"  accept="image/*" capture="environment" style="display:none" onchange="uploadAvatar(this)">
      </div>
    </div>

  </div><!-- /content -->

  <!-- ORDER BOTTOM SHEET -->
  <div class="sheet-overlay" id="sheetOverlay" onclick="closeSheet()"></div>
  <div class="bottom-sheet" id="bottomSheet">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
      <div>
        <div class="sheet-title" id="sheetTitle">اسم الخدمة</div>
        <div class="sheet-pkg" id="sheetCat">القسم</div>
      </div>
      <button class="sheet-close" onclick="closeSheet()"><i class="fas fa-times"></i></button>
    </div>
    <!-- وصف الخدمة -->
    <div id="sheetDescBox" style="display:none;margin:0 16px 12px;background:rgba(30,111,255,.06);border:1px solid rgba(30,111,255,.18);border-radius:14px;padding:13px 14px">
      <div style="font-size:.72rem;color:var(--primary);font-weight:800;margin-bottom:6px;display:flex;align-items:center;gap:5px">
        <i class="fas fa-info-circle"></i> وصف الخدمة
      </div>
      <div id="sheetDescText" style="font-size:.82rem;color:var(--text2);line-height:1.75;white-space:pre-line"></div>
    </div>

    <div class="sheet-warning">
      <i class="fas fa-exclamation-triangle"></i>
      <p>تأكد من صحة جميع البيانات قبل الإرسال — لا يمكن التراجع بعد الإرسال</p>
    </div>
    <form id="orderForm">
      <input type="hidden" name="service_id" id="sheetServiceId">
      <div class="sheet-fields" id="sheetDynFields">
        <!-- dynamic fields injected by JS -->
      </div>
      <!-- Quantity if needed -->
      <div class="sheet-fields" id="sheetQtyWrap" style="display:none">
        <div class="sheet-field">
          <label>الكمية</label>
          <div style="display:flex;align-items:center;gap:8px">
            <button type="button" onclick="changeQty(-1)" style="width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:10px;color:#fff;font-size:18px;cursor:pointer;flex-shrink:0">−</button>
            <input class="field-input" type="number" name="quantity" id="sheetQty" value="1" min="1" max="9999" style="text-align:center" oninput="updateTotal()">
            <button type="button" onclick="changeQty(1)" style="width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:10px;color:#fff;font-size:18px;cursor:pointer;flex-shrink:0">+</button>
          </div>
        </div>
      </div>
      <div class="sheet-total">
        <div class="sheet-total-row">
          <span class="sheet-total-label">إجمالي السعر</span>
          <span class="sheet-total-value" id="sheetTotalPrice">0 <?= htmlspecialchars($currSymbol) ?></span>
        </div>
        <div class="sheet-balance-row">
          <span class="sheet-total-label">رصيدك</span>
          <span style="font-size:13px;font-weight:700;color:<?= $userBalance > 0 ? 'var(--green)' : 'var(--red)' ?>"><?= formatMoney($userBalance) ?></span>
        </div>
      </div>
      <?php if (isLoggedIn()): ?>
      <!-- حقل الكوبون -->
      <div id="couponSection" style="padding:0 16px 8px;display:none;gap:8px">
        <input type="text" id="couponCodeInput" placeholder="🎟️ كود الخصم (اختياري)"
               style="flex:1;background:var(--card2);border:1.5px solid var(--border2);border-radius:10px;padding:9px 12px;color:var(--text);font-family:var(--font);font-size:.85rem;outline:none;text-transform:uppercase"
               oninput="this.value=this.value.toUpperCase();resetCoupon()"
               onkeydown="if(event.key==='Enter'){event.preventDefault();applyCoupon()}">
        <button type="button" onclick="applyCoupon()" id="couponBtn"
                style="padding:9px 14px;background:rgba(0,212,170,.15);border:1.5px solid rgba(0,212,170,.3);border-radius:10px;color:#00d4aa;font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap">
          تطبيق
        </button>
      </div>
      <div id="couponMsg" style="padding:0 16px 8px;font-size:.78rem;display:none"></div>
      <button type="button" class="sheet-buy-btn" id="sheetBuyBtn" onclick="submitOrderAjax()"><i class="fas fa-bolt"></i> شـراء الآن</button>
      <?php else: ?>
      <button type="button" class="sheet-buy-btn" onclick="openAuth('login')"><i class="fas fa-sign-in-alt"></i> سجل دخولك للشراء</button>
      <?php endif; ?>
    </form>
  </div>

