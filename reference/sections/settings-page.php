<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
require_once dirname(__DIR__) . '/includes/language_catalog.php';
$settingsLanguages = [
  'ar' => ['code'=>'ar','native_name'=>'العربية','flag'=>'🇸🇦','direction'=>'rtl'],
  'en' => ['code'=>'en','native_name'=>'English','flag'=>'🇬🇧','direction'=>'ltr'],
  'tr' => ['code'=>'tr','native_name'=>'Türkçe','flag'=>'🇹🇷','direction'=>'ltr'],
];
try {
  if (isset($pdo) && $pdo instanceof PDO) {
    // القراءة فقط أثناء إقلاع التطبيق؛ التهيئة والمزامنة تتم من لوحة الإدارة فقط.
    $settingsLanguages = njazLanguageCatalog($pdo, true) ?: $settingsLanguages;
  }
} catch (Throwable $e) { /* fallback إلى اللغات المضمنة */ }
?>
  <!-- ══ NUMBERSAPP LIVE — واجهة شراء الأرقام المباشرة ══ -->
  <div id="naLiveOverlay" style="display:none;position:fixed;inset:0;z-index:99997;background:rgba(0,0,0,.75);backdrop-filter:blur(4px)"></div>
  <div id="naLiveSheet" style="display:none;position:fixed;left:50%;bottom:0;transform:translateX(-50%) translateY(105%);width:100%;max-width:430px;max-height:92vh;overflow-y:auto;z-index:99998;background:linear-gradient(180deg,#111827,#0a0e1a);border-radius:24px 24px 0 0;border-top:1px solid rgba(0,230,118,.25);transition:transform .35s cubic-bezier(.175,.885,.32,1.275);-webkit-overflow-scrolling:touch">
    <div style="padding:12px 0 4px;text-align:center"><div style="width:40px;height:4px;background:rgba(255,255,255,.15);border-radius:4px;margin:auto"></div></div>
    <div id="naLiveContent"></div>
  </div>

  <!-- ══════════════════════════════════════
       KYC — تحقق الهوية
  ══════════════════════════════════════ -->

    <!-- ══ SETTINGS PAGE ══ -->
    <div class="page" id="page-settings" style="position:absolute;inset:0;overflow-y:auto;-webkit-overflow-scrolling:touch;background:var(--bg);">
      <div class="page-inner" style="padding:16px">

        <div style="font-size:18px;font-weight:900;margin-bottom:18px;display:flex;align-items:center;gap:10px">
          <span style="width:38px;height:38px;background:rgba(108,63,224,.15);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#a78bfa"><i class="fas fa-sliders-h"></i></span>
          الإعدادات
        </div>

        <!-- ── التوقيت ── -->
        <div class="qa-item" style="margin-bottom:12px;padding:16px">
          <div style="font-size:13px;font-weight:800;margin-bottom:12px;color:var(--text);display:flex;align-items:center;gap:8px">
            <i class="fas fa-clock" style="color:var(--cyan)"></i> المنطقة الزمنية
          </div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:10px">اختر منطقتك الزمنية لعرض الأوقات بشكل صحيح</div>
          <select id="tzSelect" onchange="saveSetting('timezone', this.value); showSaveTick('tzSaved')"
                  style="width:100%;background:var(--bg2);border:1.5px solid var(--border2);border-radius:10px;padding:10px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none">
            <optgroup label="الشرق الأوسط والخليج">
              <option value="2">UTC+2 — بيروت، دمشق، عمّان، القدس</option>
              <option value="3">UTC+3 — الرياض، الكويت، قطر، البحرين، عدن، صنعاء، بغداد</option>
              <option value="3.5">UTC+3:30 — طهران</option>
              <option value="4">UTC+4 — دبي، أبوظبي، مسقط</option>
              <option value="4.5">UTC+4:30 — كابول</option>
            </optgroup>
            <optgroup label="أفريقيا">
              <option value="-1">UTC-1 — جزر الرأس الأخضر</option>
              <option value="0">UTC+0 — أكرا، أبيدجان، داكار</option>
              <option value="1">UTC+1 — لاغوس، الجزائر، تونس، الرباط</option>
              <option value="2.1">UTC+2 — القاهرة، طرابلس، هراري، جوهانسبرغ</option>
              <option value="3.1">UTC+3 — نيروبي، أديس أبابا، مقديشو</option>
            </optgroup>
            <optgroup label="أوروبا">
              <option value="0.1">UTC+0 — لندن، دبلن، لشبونة</option>
              <option value="1.1">UTC+1 — باريس، برلين، روما، مدريد</option>
              <option value="2.2">UTC+2 — أثينا، بوخارست، هلسنكي</option>
              <option value="3.2">UTC+3 — موسكو، إسطنبول، مينسك</option>
              <option value="4.1">UTC+4 — باكو، يريفان</option>
            </optgroup>
            <optgroup label="آسيا الوسطى وجنوب آسيا">
              <option value="5">UTC+5 — كراتشي، إسلام آباد، طشقند</option>
              <option value="5.5">UTC+5:30 — نيودلهي، مومباي، كولومبو</option>
              <option value="5.75">UTC+5:45 — كاتماندو</option>
              <option value="6">UTC+6 — داكا، ألماتي</option>
              <option value="6.5">UTC+6:30 — يانغون</option>
              <option value="7">UTC+7 — بانكوك، هانوي، جاكرتا</option>
            </optgroup>
            <optgroup label="آسيا الشرقية والمحيط الهادئ">
              <option value="8">UTC+8 — بيجين، سنغافورة، كوالالمبور</option>
              <option value="9">UTC+9 — طوكيو، سيول</option>
              <option value="9.5">UTC+9:30 — أديلايد، داروين</option>
              <option value="10">UTC+10 — سيدني، ملبورن، بريزبن</option>
              <option value="11">UTC+11 — نوميا، هونيارا</option>
              <option value="12">UTC+12 — أوكلاند، فيجي</option>
            </optgroup>
            <optgroup label="الأمريكتان">
              <option value="-3">UTC-3 — ساو باولو، بوينس آيرس</option>
              <option value="-4">UTC-4 — سانتياغو، كاراكاس</option>
              <option value="-5">UTC-5 — نيويورك، تورنتو</option>
              <option value="-6">UTC-6 — شيكاغو، مكسيكو سيتي</option>
              <option value="-7">UTC-7 — دنفر، فينيكس</option>
              <option value="-8">UTC-8 — لوس أنجلوس، سياتل</option>
              <option value="-9">UTC-9 — أنكوريج</option>
              <option value="-10">UTC-10 — هونولولو</option>
            </optgroup>
          </select>
          <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:11px;color:var(--text3)">
              الوقت الحالي: <span id="localTimePreview" style="color:var(--cyan);font-weight:700"></span>
            </div>
            <div id="tzSaved" style="font-size:11px;color:#00e676;font-weight:700;opacity:0;transition:opacity .3s;display:flex;align-items:center;gap:4px">
              <i class="fas fa-check-circle"></i> حُفظ
            </div>
          </div>
        </div>

        <!-- ── المظهر ── -->
        <div class="qa-item" style="margin-bottom:12px;padding:16px">
          <div style="font-size:13px;font-weight:800;margin-bottom:12px;color:var(--text);display:flex;align-items:center;gap:8px">
            <i class="fas fa-palette" style="color:#f5a623"></i> المظهر
          </div>
          <div style="display:flex;gap:10px">
            <button onclick="setTheme('dark')" id="btnDark"
                    style="flex:1;padding:10px;border-radius:10px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
              🌙 داكن
            </button>
            <button onclick="setTheme('light')" id="btnLight"
                    style="flex:1;padding:10px;border-radius:10px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer">
              ☀️ فاتح
            </button>
          </div>
        </div>

        <!-- ── اللغة ── -->
        <div class="qa-item" style="margin-bottom:12px;padding:16px">
          <div style="font-size:13px;font-weight:800;margin-bottom:12px;color:var(--text);display:flex;align-items:center;gap:8px">
            <i class="fas fa-language" style="color:#00d4aa"></i>
            <span data-i18n="settings_language">اللغة</span>
          </div>
          <div id="displayLanguageButtons" style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($settingsLanguages as $lang): $langCode=(string)($lang['code']??''); if ($langCode==='') continue; ?>
            <button class="lang-btn" data-lang="<?=htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8')?>" onclick="setLang('<?=htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8')?>')"
                    style="flex:1;min-width:92px;padding:10px 6px;border-radius:10px;border:1.5px solid var(--border2);background:var(--bg2);color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:.2s">
              <?=htmlspecialchars(trim((string)($lang['flag']??'').' '.(string)($lang['native_name']??$lang['name']??strtoupper($langCode))), ENT_QUOTES, 'UTF-8')?>
            </button>
            <?php endforeach; ?>
          </div>
          <div id="langSaved" style="margin-top:8px;font-size:11px;color:#00e676;font-weight:700;opacity:0;transition:opacity .3s;display:flex;align-items:center;gap:4px">
            <i class="fas fa-check-circle"></i> <span data-i18n="settings_saved">حُفظ</span>
          </div>
        </div>

        <!-- ── إشعارات Push ── -->
        <div class="qa-item" style="margin-bottom:12px;padding:16px">
          <div style="font-size:13px;font-weight:800;margin-bottom:4px;color:var(--text);display:flex;align-items:center;gap:8px">
            <i class="fas fa-bell" style="color:#f5a623"></i> الإشعارات الخلفية
          </div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:14px">استقبل إشعارات حتى عند إغلاق التطبيق</div>
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:12px;color:var(--text3)">
              <i class="fas fa-circle" style="font-size:8px;margin-left:5px" id="pushDot"></i>
              <span id="pushStatusText">جارٍ التحقق...</span>
            </div>
            <label style="position:relative;display:inline-block;width:48px;height:26px;cursor:pointer">
              <input type="checkbox" id="pushToggle" style="opacity:0;width:0;height:0" onchange="handlePushToggle(this.checked)">
              <span id="pushToggleTrack" style="position:absolute;inset:0;border-radius:13px;background:var(--border2);transition:.3s"></span>
              <span id="pushToggleThumb" style="position:absolute;top:3px;right:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:.3s;box-shadow:0 1px 4px rgba(0,0,0,.3)"></span>
            </label>
          </div>
          <div id="pushMsg" style="margin-top:10px;font-size:11px;display:none;padding:8px 10px;border-radius:8px"></div>
          <div id="pushDeniedMsg" style="display:none;margin-top:10px;background:rgba(255,68,85,.1);border:1px solid rgba(255,68,85,.3);border-radius:10px;padding:10px 12px;font-size:11px;color:#ff8898;line-height:1.7">
            <i class="fas fa-lock"></i> تم حظر الإشعارات من إعدادات المتصفح.<br>
            لتفعيلها: اضغط على أيقونة القفل 🔒 في شريط العنوان ← الإشعارات ← سماح
          </div>
        </div>

        <!-- ── الأجهزة المصرّحة ── -->
        <?php if(isLoggedIn()): ?>
        <div class="qa-item" style="margin-bottom:12px;padding:16px" id="devicesSection">
          <div style="font-size:13px;font-weight:800;margin-bottom:4px;color:var(--text);display:flex;align-items:center;gap:8px">
            <i class="fas fa-mobile-alt" style="color:#a78bfa"></i> الأجهزة المصرّحة
          </div>
          <div style="font-size:11px;color:var(--text3);margin-bottom:14px">إدارة الأجهزة والمتصفحات المسموح لها بالدخول لحسابك</div>

          <!-- قائمة الأجهزة -->
          <div id="devicesList" style="margin-bottom:14px">
            <div style="text-align:center;padding:16px;color:var(--text3);font-size:12px">
              <i class="fas fa-spinner fa-spin"></i> جاري التحميل...
            </div>
          </div>

          <!-- إضافة جهاز جديد -->
          <div style="background:rgba(108,63,224,.06);border:1px solid rgba(108,63,224,.2);border-radius:12px;padding:14px">
            <div style="font-size:12px;font-weight:700;margin-bottom:10px;color:#a78bfa">
              <i class="fas fa-plus-circle"></i> تصريح جهاز جديد
            </div>
            <div style="font-size:11px;color:var(--text3);margin-bottom:10px;line-height:1.6">
              ادخل رقم الجهاز الموجود في رسالة واتساب أو شاشة "جهاز غير مصرح"
            </div>
            <input type="text" id="newDeviceIdInput" placeholder="did_xxxxxxxx-xxxx-xxxx-xxxx"
                   style="width:100%;background:var(--bg2);border:1.5px solid var(--border2);border-radius:10px;padding:10px 12px;color:var(--text);font-family:monospace;font-size:11px;outline:none;box-sizing:border-box;margin-bottom:10px">
            <button onclick="approveNewDevice()"
                    style="width:100%;padding:11px;border-radius:10px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:800;cursor:pointer">
              <i class="fas fa-check-circle"></i> تصريح الجهاز
            </button>
          </div>
        </div>
        <?php endif; ?>

        <div style="height:30px"></div>
      </div>
    </div>

