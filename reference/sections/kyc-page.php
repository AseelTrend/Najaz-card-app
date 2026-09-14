<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
  <div class="page" id="page-kyc" style="position:absolute;inset:0;overflow-y:auto;-webkit-overflow-scrolling:touch;background:var(--bg);">
    <div style="padding:16px 14px 120px">

      <!-- رأس الصفحة -->
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <button onclick="navTo('home')" style="width:36px;height:36px;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-arrow-right"></i>
        </button>
        <div>
          <div style="font-size:18px;font-weight:900;color:#fff">تحقق الهوية</div>
          <div style="font-size:12px;color:#8895a7">اثبت هويتك لفتح المزيد من المميزات</div>
        </div>
      </div>

      <?php if($kycStatus === 'approved'): ?>
      <!-- ✅ موثّق -->
      <div style="background:linear-gradient(135deg,rgba(0,200,83,.15),rgba(0,200,83,.05));border:1px solid rgba(0,200,83,.3);border-radius:20px;padding:32px 20px;text-align:center;margin-bottom:16px">
        <div style="font-size:56px;margin-bottom:12px">✅</div>
        <div style="font-size:20px;font-weight:900;color:#00e676;margin-bottom:6px">تم التحقق من هويتك</div>
        <div style="font-size:13px;color:#8895a7">حسابك موثق بالكامل — شكراً لك</div>
        <div style="margin-top:20px;background:rgba(255,255,255,.04);border-radius:14px;padding:14px;text-align:right">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:12px">
            <span style="color:#8895a7">الاسم</span>
            <span style="color:#fff;font-weight:700"><?= htmlspecialchars($kycData['full_name'] ?? '') ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:12px">
            <span style="color:#8895a7">نوع الهوية</span>
            <span style="color:#fff;font-weight:700"><?php
              $types=['national'=>'بطاقة شخصية','passport'=>'جواز سفر','family'=>'بطاقة عائلية','electronic'=>'بطاقة إلكترونية'];
              echo $types[$kycData['id_type']??''] ?? '—';
            ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:12px">
            <span style="color:#8895a7">تاريخ الموافقة</span>
            <span style="color:#fff;font-weight:700"><?= $kycData['reviewed_at'] ? date('d/m/Y', strtotime($kycData['reviewed_at'])) : '—' ?></span>
          </div>
        </div>
      </div>

      <?php elseif($kycStatus === 'pending'): ?>
      <!-- ⏳ قيد المراجعة -->
      <div style="background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.25);border-radius:20px;padding:28px 20px;text-align:center;margin-bottom:16px">
        <div style="font-size:48px;margin-bottom:12px">⏳</div>
        <div style="font-size:18px;font-weight:900;color:#f5a623;margin-bottom:6px">طلبك قيد المراجعة</div>
        <div style="font-size:12px;color:#8895a7">سيتم مراجعة طلبك خلال 24 ساعة وإشعارك بالنتيجة</div>
        <div style="margin-top:16px;font-size:11px;color:#8895a7">أُرسل في: <span><?= $kycData['created_at'] ? date('d/m/Y H:i', strtotime($kycData['created_at'])) : '—' ?></span></div>
      </div>

      <?php else: ?>
      <!-- النموذج (جديد أو مرفوض) -->

      <?php if($kycStatus === 'rejected'): ?>
      <div style="background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.25);border-radius:14px;padding:14px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start">
        <i class="fas fa-exclamation-circle" style="color:#ff4757;margin-top:2px;flex-shrink:0"></i>
        <div>
          <div style="font-size:13px;font-weight:700;color:#ff4757;margin-bottom:4px">تم رفض طلبك السابق</div>
          <div style="font-size:12px;color:#8895a7"><?= htmlspecialchars($kycData['admin_note'] ?? 'يرجى إعادة التقديم بمعلومات صحيحة') ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- شريط التقدم -->
      <div id="kycProgressBar" style="display:flex;gap:6px;margin-bottom:24px">
        <div id="kycStep1Dot" style="flex:1;height:4px;border-radius:2px;background:var(--primary);transition:.3s"></div>
        <div id="kycStep2Dot" style="flex:1;height:4px;border-radius:2px;background:var(--border);transition:.3s"></div>
        <div id="kycStep3Dot" style="flex:1;height:4px;border-radius:2px;background:var(--border);transition:.3s"></div>
      </div>

      <form id="kycForm" onsubmit="submitKyc(event)">

        <!-- الخطوة 1: المعلومات الشخصية -->
        <div id="kycStep1">
          <div style="font-size:14px;font-weight:800;color:#fff;margin-bottom:16px">
            <i class="fas fa-user" style="color:var(--primary);margin-left:6px"></i>المعلومات الشخصية
          </div>

          <!-- نوع الهوية -->
          <div style="margin-bottom:16px">
            <label class="kyc-label">نوع الهوية</label>
            <div id="kycTypeGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
              <?php foreach([
                'national'   => ['🪪','بطاقة شخصية'],
                'passport'   => ['🛂','جواز سفر'],
                'family'     => ['👨‍👩‍👧','بطاقة عائلية'],
                'electronic' => ['💳','بطاقة إلكترونية'],
              ] as $val => [$icon,$label]): ?>
              <label style="cursor:pointer">
                <input type="radio" name="id_type" value="<?=$val?>" <?=$val==='national'?'checked':''?> style="display:none" onchange="onIdTypeChange('<?=$val?>')">
                <div class="kyc-type-card <?=$val==='national'?'active':''?>" data-type="<?=$val?>">
                  <span style="font-size:22px"><?=$icon?></span>
                  <span style="font-size:12px;font-weight:700;color:#fff"><?=$label?></span>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- الاسم -->
          <div style="margin-bottom:14px">
            <label class="kyc-label">الاسم كما في الهوية <span style="color:#ff4757">*</span></label>
            <input type="text" name="full_name" class="kyc-input" placeholder="مثال: محمد عبدالله الأحمد" required
              value="<?= htmlspecialchars($kycData['full_name'] ?? '') ?>">
          </div>

          <!-- الرقم الوطني -->
          <div style="margin-bottom:14px">
            <label class="kyc-label" id="kycIdLabel">رقم الهوية الوطنية <span style="color:#ff4757">*</span></label>
            <input type="text" name="national_id" id="kycNationalId" class="kyc-input" placeholder="مثال: 1234567890" required
              value="<?= htmlspecialchars($kycData['national_id'] ?? '') ?>">
          </div>

          <!-- تاريخ الميلاد -->
          <div style="margin-bottom:14px">
            <label class="kyc-label">تاريخ الميلاد <span style="color:#ff4757">*</span></label>
            <input type="date" name="birth_date" class="kyc-input" required
              value="<?= htmlspecialchars($kycData['birth_date'] ?? '') ?>"
              max="<?= date('Y-m-d') ?>">
          </div>

          <!-- مكان الميلاد -->
          <div style="margin-bottom:14px">
            <label class="kyc-label">مكان الميلاد</label>
            <input type="text" name="birth_place" class="kyc-input" placeholder="مثال: الرياض"
              value="<?= htmlspecialchars($kycData['birth_place'] ?? '') ?>">
          </div>

          <!-- تاريخ الإصدار وتاريخ الانتهاء -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
            <div>
              <label class="kyc-label">تاريخ الإصدار <span style="color:#ff4757">*</span></label>
              <input type="date" name="issue_date" id="kycIssueDate" class="kyc-input" required
                value="<?= htmlspecialchars($kycData['issue_date'] ?? '') ?>"
                onchange="autoCalcExpiry(this.value)"
                max="<?= date('Y-m-d') ?>">
            </div>
            <div>
              <label class="kyc-label">تاريخ الانتهاء <span style="color:#ff4757">*</span></label>
              <input type="date" name="expiry_date" id="kycExpiryDate" class="kyc-input" required
                value="<?= htmlspecialchars($kycData['expiry_date'] ?? '') ?>"
                placeholder="يُحسب تلقائياً">
              <div style="font-size:10px;color:#8895a7;margin-top:3px">يُحسب تلقائياً (10 سنوات)</div>
            </div>
          </div>

          <!-- حقول إضافية مستقبلية -->
          <div id="kycExtraFields"></div>

          <button type="button" onclick="kycNextStep(1)" class="kyc-btn-primary" style="margin-top:8px">
            التالي — رفع صور الهوية <i class="fas fa-arrow-left" style="margin-right:6px"></i>
          </button>
        </div>

        <!-- الخطوة 2: رفع الصور -->
        <div id="kycStep2" style="display:none">
          <div style="font-size:14px;font-weight:800;color:#fff;margin-bottom:16px">
            <i class="fas fa-camera" style="color:var(--cyan);margin-left:6px"></i>صور الهوية
          </div>

          <!-- صورة الوجه الأمامي -->
          <div style="margin-bottom:16px">
            <label class="kyc-label">صورة الوجه الأمامي <span style="color:#ff4757">*</span></label>
            <div id="kycFrontDrop" class="kyc-upload-box" style="gap:0">
              <i class="fas fa-id-card" style="font-size:28px;color:var(--cyan);margin-bottom:8px"></i>
              <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:12px">الوجه الأمامي للهوية</div>
              <div style="display:flex;gap:8px;width:100%">
                <button type="button" onclick="kycOpenCamera('kycFrontFile')"
                  style="flex:1;padding:10px 6px;background:rgba(0,212,255,.12);border:1px solid rgba(0,212,255,.3);border-radius:10px;color:var(--cyan);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer">
                  <i class="fas fa-camera" style="display:block;font-size:18px;margin-bottom:4px"></i>تصوير
                </button>
                <button type="button" onclick="kycOpenGallery('kycFrontFile')"
                  style="flex:1;padding:10px 6px;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.3);border-radius:10px;color:#a78bfa;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer">
                  <i class="fas fa-images" style="display:block;font-size:18px;margin-bottom:4px"></i>المعرض
                </button>
              </div>
              <input type="file" id="kycFrontFile" name="image_front" accept="image/*"
                style="display:none" onchange="previewKycImage(this,'kycFrontPreview','kycFrontDrop')">
              <input type="file" id="kycFrontCamera" accept="image/*" capture="environment"
                style="display:none" onchange="syncKycCamera(this,'kycFrontFile','kycFrontPreview','kycFrontDrop')">
            </div>
            <img id="kycFrontPreview" style="display:none;width:100%;border-radius:12px;margin-top:8px;max-height:180px;object-fit:cover;border:1px solid var(--border)">
          </div>

          <!-- صورة الخلف (مخفية لجواز السفر) -->
          <div id="kycBackWrap" style="margin-bottom:16px">
            <label class="kyc-label">صورة الوجه الخلفي <span style="color:#ff4757">*</span></label>
            <div id="kycBackDrop" class="kyc-upload-box" style="gap:0">
              <i class="fas fa-id-card" style="font-size:28px;color:#a78bfa;margin-bottom:8px"></i>
              <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:12px">الوجه الخلفي للهوية</div>
              <div style="display:flex;gap:8px;width:100%">
                <button type="button" onclick="kycOpenCamera('kycBackFile')"
                  style="flex:1;padding:10px 6px;background:rgba(167,139,250,.12);border:1px solid rgba(167,139,250,.3);border-radius:10px;color:#a78bfa;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer">
                  <i class="fas fa-camera" style="display:block;font-size:18px;margin-bottom:4px"></i>تصوير
                </button>
                <button type="button" onclick="kycOpenGallery('kycBackFile')"
                  style="flex:1;padding:10px 6px;background:rgba(0,230,118,.12);border:1px solid rgba(0,230,118,.3);border-radius:10px;color:var(--green);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer">
                  <i class="fas fa-images" style="display:block;font-size:18px;margin-bottom:4px"></i>المعرض
                </button>
              </div>
              <input type="file" id="kycBackFile" name="image_back" accept="image/*"
                style="display:none" onchange="previewKycImage(this,'kycBackPreview','kycBackDrop')">
              <input type="file" id="kycBackCamera" accept="image/*" capture="environment"
                style="display:none" onchange="syncKycCamera(this,'kycBackFile','kycBackPreview','kycBackDrop')">
            </div>
            <img id="kycBackPreview" style="display:none;width:100%;border-radius:12px;margin-top:8px;max-height:180px;object-fit:cover;border:1px solid var(--border)">
          </div>

          <!-- حقول رفع إضافية مستقبلية -->
          <div id="kycExtraUploads"></div>

          <!-- تلميح -->
          <div style="background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.15);border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:11px;color:#8895a7;line-height:1.7">
            <i class="fas fa-info-circle" style="color:var(--cyan);margin-left:6px"></i>
            تأكد أن الصورة واضحة وغير منقوصة • يُقبل JPG/PNG/WEBP • الحجم الأقصى 8MB
          </div>

          <div style="display:flex;gap:10px">
            <button type="button" onclick="kycPrevStep(2)"
              style="flex:1;padding:14px;background:var(--card2);border:1px solid var(--border);border-radius:14px;color:var(--text);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer">
              <i class="fas fa-arrow-right"></i> السابق
            </button>
            <button type="button" onclick="kycNextStep(2)" class="kyc-btn-primary" style="flex:2">
              التالي — المراجعة <i class="fas fa-arrow-left" style="margin-right:6px"></i>
            </button>
          </div>
        </div>

        <!-- الخطوة 3: مراجعة وإرسال -->
        <div id="kycStep3" style="display:none">
          <div style="font-size:14px;font-weight:800;color:#fff;margin-bottom:16px">
            <i class="fas fa-check-double" style="color:var(--green);margin-left:6px"></i>مراجعة البيانات
          </div>
          <div id="kycReviewBox" style="background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:16px;padding:16px;margin-bottom:16px">
            <!-- يتم ملؤه بـ JS -->
          </div>
          <div style="background:rgba(245,166,35,.07);border:1px solid rgba(245,166,35,.2);border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:11px;color:#f5a623;line-height:1.7">
            <i class="fas fa-shield-alt" style="margin-left:6px"></i>
            بياناتك محمية ومشفرة ولن تُشارك مع أي طرف ثالث
          </div>
          <div style="display:flex;gap:10px">
            <button type="button" onclick="kycPrevStep(3)"
              style="flex:1;padding:14px;background:var(--card2);border:1px solid var(--border);border-radius:14px;color:var(--text);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer">
              <i class="fas fa-arrow-right"></i> السابق
            </button>
            <button type="submit" id="kycSubmitBtn" class="kyc-btn-primary" style="flex:2">
              <i class="fas fa-paper-plane" style="margin-left:6px"></i>إرسال الطلب
            </button>
          </div>
        </div>

      </form>
      <?php endif; ?>

    </div>
  </div><!-- /page-kyc -->

