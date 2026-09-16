<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ REFERRAL PAGE ══ -->
    <div class="page" id="page-referral" style="overflow-y:auto">
      <?php if (!isLoggedIn()): ?>
      <div style="padding:40px 20px;text-align:center">
        <div class="auth-gate-box" onclick="openAuth('login')">
          <div class="auth-gate-icon">🎁</div>
          <div class="auth-gate-title">سجل دخولك لعرض برنامج الإحالة</div>
          <div class="auth-gate-btn"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
        </div>
      </div>
      <?php elseif(!getSetting('referral_enabled')): ?>
      <div style="padding:3rem;text-align:center;color:var(--text3)">
        <i class="fas fa-users" style="font-size:3rem;opacity:.1;display:block;margin-bottom:12px"></i>
        <div style="font-weight:700">برنامج الإحالة غير مفعّل حالياً</div>
      </div>
      <?php else: ?>

      <!-- هيدر بطاقة الكود -->
      <div style="margin:14px 14px 0;background:linear-gradient(135deg,#0d3b2e,#0a2a1f);border:1px solid rgba(0,200,83,.3);border-radius:20px;padding:20px;position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;background:radial-gradient(circle,rgba(0,200,83,.2),transparent);pointer-events:none"></div>
        <div style="font-size:.8rem;color:rgba(255,255,255,.6);margin-bottom:6px">كود الدعوة الخاص بك</div>
        <div style="font-size:2rem;font-weight:900;letter-spacing:4px;color:#00c853;margin-bottom:12px" id="myRefCode"><?= htmlspecialchars($myReferralCode) ?></div>
        <!-- debug -->
        <div id="refDebug" style="font-size:10px;color:rgba(255,255,255,.4);margin-bottom:6px">كود: <?= htmlspecialchars($myReferralCode) ?></div>
        <div style="display:flex;gap:8px">
          <?php
            // [SECURITY FIX] استخدام json_encode بدلاً من addslashes لتأمين الإخراج في JS
            $rCodeJs = json_encode($myReferralCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $rLinkJs = json_encode(SITE_URL.'/mobile.php?ref='.$myReferralCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            $rMsgJs  = json_encode('🎁 سجّل معنا وستحصل على رصيد ترحيبي! كود الدعوة: '.$myReferralCode.' - '.SITE_URL.'/mobile.php?ref='.$myReferralCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
          ?>
          <script>
            /* [SECURITY FIX] متغيرات آمنة عبر json_encode */
            var _refCode = <?= $rCodeJs ?>;
            var _refLink = <?= $rLinkJs ?>;
            var _refMsg  = <?= $rMsgJs ?>;
          </script>
          <button onclick="
            var t=document.createElement('textarea');
            t.value=_refCode;
            t.style='position:fixed;top:0;left:0;opacity:0';
            document.body.appendChild(t);
            t.select();
            try{document.execCommand('copy');showToast('✅ تم نسخ الكود: '+_refCode);}
            catch(e){showToast('الكود: '+_refCode);}
            document.body.removeChild(t);
          " style="flex:1;padding:10px;background:rgba(0,200,83,.2);border:1px solid rgba(0,200,83,.3);border-radius:10px;color:#00c853;font-family:var(--font);font-size:.85rem;font-weight:700;cursor:pointer">
            <i class="fas fa-copy"></i> نسخ الكود
          </button>
          <button onclick="
            var msg=_refMsg;
            var link=_refLink;
            if(navigator.share){navigator.share({title:'دعوة صديق',text:msg,url:link}).catch(function(){});}
            else{
              var t=document.createElement('textarea');
              t.value=msg;
              t.style='position:fixed;top:0;left:0;opacity:0';
              document.body.appendChild(t);
              t.select();
              try{document.execCommand('copy');}catch(e){}
              document.body.removeChild(t);
              showToast('✅ تم نسخ رابط الدعوة');
            }
          " style="flex:1;padding:10px;background:rgba(0,200,83,.15);border:1px solid rgba(0,200,83,.25);border-radius:10px;color:#00c853;font-family:var(--font);font-size:.85rem;font-weight:700;cursor:pointer">
            <i class="fas fa-share-alt"></i> مشاركة
          </button>
        </div>
      </div>

      <!-- إحصاءات -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 14px">
        <div class="qa-item" style="padding:12px;text-align:center;border-radius:12px">
          <div style="font-size:1.4rem;font-weight:900;color:#00d4ff"><?= $myRefStats['count'] ?></div>
          <div style="font-size:.68rem;color:var(--text3);margin-top:2px">مُحالون</div>
        </div>
        <div class="qa-item" style="padding:12px;text-align:center;border-radius:12px">
          <div style="font-size:1.4rem;font-weight:900;color:#00e676"><?= $myRefStats['rewarded'] ?></div>
          <div style="font-size:.68rem;color:var(--text3);margin-top:2px">مُكافَئون</div>
        </div>
        <div class="qa-item" style="padding:12px;text-align:center;border-radius:12px">
          <div style="font-size:1.4rem;font-weight:900;color:#f5a623"><?= number_format($myRefStats['earned'],2) ?>$</div>
          <div style="font-size:.68rem;color:var(--text3);margin-top:2px">كسبت</div>
        </div>
      </div>

      <!-- كيف يعمل -->
      <div style="margin:0 14px 12px;background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:14px">
        <div style="font-weight:800;font-size:.88rem;margin-bottom:12px"><i class="fas fa-info-circle" style="color:var(--primary)"></i> كيف يعمل برنامج الإحالة؟</div>
        <div style="display:flex;flex-direction:column;gap:10px">
          <?php
            $fixed   = (float)(getSetting('referral_fixed_reward') ?: 1);
            $percent = (float)(getSetting('referral_percent') ?: 5);
            $welcome = (float)(getSetting('referral_welcome') ?: 0.5);
          ?>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:rgba(0,212,255,.15);color:#00d4ff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:900;flex-shrink:0">1</div>
            <div style="font-size:.82rem;color:var(--text2)">شارك كودك مع أصدقائك ليسجلوا بـه</div>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:rgba(0,200,83,.15);color:#00c853;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:900;flex-shrink:0">2</div>
            <div style="font-size:.82rem;color:var(--text2)">يحصل صديقك على <strong style="color:#7c3aed"><?= number_format($welcome,2) ?>$</strong> رصيد ترحيبي فور التسجيل</div>
          </div>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:rgba(245,166,35,.15);color:#f5a623;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:900;flex-shrink:0">3</div>
            <?php
              $rType = getSetting('referral_reward_type') ?: 'order_percent';
              if ($rType === 'first_topup_fixed'):
            ?>
            <div style="font-size:.82rem;color:var(--text2)">عند <strong>أول شحن رصيد</strong> لصديقك تحصل على <strong style="color:#f5a623"><?= number_format($fixed,2) ?>$</strong> ثابتة مباشرة</div>
            <?php elseif ($rType === 'first_topup_percent'): ?>
            <div style="font-size:.82rem;color:var(--text2)">عند <strong>أول شحن رصيد</strong> لصديقك تحصل على <strong style="color:#00d4ff"><?= number_format($percent,1) ?>%</strong> من المبلغ المشحون</div>
            <?php else: ?>
            <div style="font-size:.82rem;color:var(--text2)">مع <strong>كل طلب خدمة</strong> يقوم به صديقك تحصل على <strong style="color:#00d4ff"><?= number_format($percent,1) ?>%</strong> من قيمة الطلب — بلا حدود! 🚀</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- قائمة المُحالين -->
      <div style="padding:0 14px 80px">
        <div style="font-weight:800;font-size:.88rem;margin-bottom:8px"><i class="fas fa-users" style="color:var(--primary)"></i> أصدقاؤك المُحالون</div>
        <?php if(empty($myReferrals)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text3)">
          <i class="fas fa-user-plus" style="font-size:2rem;opacity:.1;display:block;margin-bottom:10px"></i>
          لم تدعُ أحداً بعد — شارك كودك الآن!
        </div>
        <?php else: foreach($myReferrals as $ref):
          $rName = $ref['full_name'] ?: $ref['username'];
          $rDone = $ref['status']==='rewarded';
          // تمويه الاسم: أول حرفين + نجوم
          $rChars   = mb_str_split($rName);
          $rVisible = implode('', array_slice($rChars, 0, 2));
          $rHidden  = count($rChars) > 2 ? str_repeat('•', min(count($rChars)-2, 5)) : '';
          $rMasked  = $rVisible . $rHidden;
        ?>
        <div onclick="openRefSheet(<?= $ref['referred_id'] ?>, '<?= htmlspecialchars(addslashes($rMasked)) ?>', '<?= number_format($ref['commission_paid'],4) ?>')"
             style="background:var(--card2);border:1.5px solid <?= $rDone?'rgba(0,230,118,.2)':'var(--border)' ?>;border-radius:14px;padding:12px 13px;margin-bottom:8px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:.15s" 
             onmousedown="this.style.opacity='.7'" onmouseup="this.style.opacity='1'" ontouchstart="this.style.opacity='.7'" ontouchend="this.style.opacity='1'">
          <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,<?= $rDone?'#00c853,#00a844':'var(--primary),#7c3aed' ?>);display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;flex-shrink:0">
            <?= mb_substr($rName,0,1) ?>
          </div>
          <div style="flex:1">
            <div style="font-weight:700;font-size:.88rem"><?= htmlspecialchars($rMasked) ?></div>
            <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
              انضم <?= date('d/m/Y',strtotime($ref['created_at'])) ?>
            </div>
          </div>
          <div style="text-align:left;display:flex;align-items:center;gap:8px">
            <div>
              <?php if($rDone): ?>
              <div style="color:#00e676;font-weight:900;font-size:.9rem">+<?= number_format($ref['commission_paid'],2) ?>$</div>
              <div style="font-size:.65rem;color:#00e676;opacity:.8">✅ مُكافَأ</div>
              <?php else: ?>
              <div style="font-size:.72rem;color:#f5a623;font-weight:700">⏳ انتظار</div>
              <?php endif; ?>
            </div>
            <i class="fas fa-chevron-left" style="font-size:.7rem;color:var(--text3)"></i>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <div style="height:80px"></div>
      <?php endif; ?>
    </div>

    <!-- ══ REFERRAL SHEET ══ -->
    <div id="refSheet" style="position:fixed;inset:0;z-index:9000;pointer-events:none">
      <!-- Backdrop -->
      <div id="refSheetBg" onclick="closeRefSheet()"
           style="position:absolute;inset:0;background:rgba(0,0,0,.6);opacity:0;transition:opacity .3s"></div>
      <!-- Panel -->
      <div id="refSheetPanel"
           style="position:absolute;bottom:0;left:0;right:0;
                  background:var(--bg2);border-radius:24px 24px 0 0;
                  transform:translateY(100%);transition:transform .35s cubic-bezier(.32,1,.5,1);
                  max-height:85vh;display:flex;flex-direction:column;overflow:hidden">

        <!-- Handle -->
        <div style="flex-shrink:0;padding:12px 0 6px;text-align:center">
          <div style="width:40px;height:4px;background:var(--border2);border-radius:4px;display:inline-block"></div>
        </div>

        <!-- Header -->
        <div id="refSheetHeader" style="flex-shrink:0;padding:6px 16px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-weight:900;font-size:1rem" id="refSheetName"></div>
            <div style="font-size:.72rem;color:var(--text3);margin-top:2px">كشف عمولات الإحالة</div>
          </div>
          <div style="text-align:left">
            <div style="font-size:1.1rem;font-weight:900;color:#00e676" id="refSheetTotal"></div>
            <div style="font-size:.65rem;color:var(--text3)">إجمالي</div>
          </div>
        </div>

        <!-- Table header -->
        <div style="flex-shrink:0;display:grid;grid-template-columns:1.1fr 1.7fr 1fr 1fr;padding:8px 14px;background:rgba(255,255,255,.03);font-size:.65rem;color:var(--text3);font-weight:700;border-bottom:1px solid var(--border)">
          <div>رقم الطلب</div>
          <div>الخدمة</div>
          <div style="text-align:center">قيمة الطلب</div>
          <div style="text-align:left">عمولتك</div>
        </div>

        <!-- Body (scrollable) -->
        <div id="refSheetBody" style="flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch">
          <!-- يُملأ بـ JS -->
          <div id="refSheetLoading" style="padding:3rem;text-align:center;color:var(--text3)">
            <i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i>
          </div>
        </div>

        <!-- Footer total -->
        <div id="refSheetFooter" style="flex-shrink:0;display:none;padding:12px 16px;background:rgba(0,230,118,.06);border-top:1px solid rgba(0,230,118,.15);display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:.82rem;font-weight:800">الإجمالي</span>
          <span style="font-weight:900;color:#00e676" id="refSheetFooterTotal"></span>
        </div>
      </div>
    </div>

