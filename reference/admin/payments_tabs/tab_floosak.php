<!-- ═══════════════ فلوسك — إعدادات الدفع المباشر ═══════════════ -->
<div id="tab-floosak" class="tab-pane" style="<?=$tab!=='floosak'?'display:none':''?>">

  <?php
  $fEnabled   = !empty($floosakCfg['floosak_enabled']) && $floosakCfg['floosak_enabled']==='1';
  $fHasKey    = !empty($floosakCfg['floosak_merchant_key']);
  $fPhone     = $floosakCfg['floosak_phone'] ?? '967777153381';
  $fShortCode = $floosakCfg['floosak_short_code'] ?? '990000';
  $fWalletId  = $floosakCfg['floosak_wallet_id'] ?? '';
  $fUpdated   = $floosakCfg['floosak_key_updated'] ?? '';
  $fPendingReq= $floosakCfg['floosak_pending_request_id'] ?? '';
  $fImage     = $floosakCfg['floosak_image'] ?? '';
  ?>

  <!-- بطاقة الحالة -->
  <div class="card" style="margin-bottom:1rem">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="width:56px;height:56px;border-radius:16px;background:<?=$fEnabled&&$fHasKey?'rgba(0,212,170,.15)':'rgba(255,68,85,.1)'?>;display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0;overflow:hidden">
        <?php if($fImage): ?>
        <img src="<?=SITE_URL?>/<?=htmlspecialchars($fImage)?>" style="width:100%;height:100%;object-fit:contain;padding:6px">
        <?php else: ?>
        <?=$fEnabled&&$fHasKey?'⚡':'🔌'?>
        <?php endif; ?>
      </div>
      <div style="flex:1">
        <div style="font-weight:900;font-size:1rem">بوابة فلوسك للدفع المباشر</div>
        <div style="font-size:.82rem;color:#8895a7;margin-top:3px">
          الحالة:
          <span style="color:<?=$fEnabled&&$fHasKey?'#00d4aa':'#ff4455'?>;font-weight:800">
            <?=$fEnabled&&$fHasKey?'✅ مفعّلة ومتصلة':'❌ غير مفعّلة'?>
          </span>
          <?php if($fHasKey && $fUpdated): ?>
          &nbsp;·&nbsp; آخر تحديث: <?=date('d/m/Y H:i', strtotime($fUpdated))?>
          <?php endif; ?>
          <?php if($fWalletId): ?>
          &nbsp;·&nbsp; Wallet ID: <strong style="color:#00d4ff"><?=$fWalletId?></strong>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">

    <!-- خطوة 1: بيانات الحساب + طلب OTP -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-key"></i> الخطوة 1 — تفعيل الحساب</div>
      </div>
      <div class="card-body">
        <div style="padding:10px 14px;background:rgba(0,212,255,.06);border:1px solid rgba(0,212,255,.15);border-radius:10px;margin-bottom:1rem;font-size:.82rem;color:#8895a7">
          <i class="fas fa-info-circle" style="color:#00d4ff"></i>
          أدخل رقم هاتف وشورت كود حساب فلوسك المرتشنت الخاص بك، ثم اضغط "طلب رمز التحقق".
          سيصلك SMS من فلوسك.
        </div>
        <form method="POST">
        <?= adminCsrfField() ?>
          <div class="form-group">
            <label>رقم هاتف فلوسك</label>
            <input type="text" name="floosak_phone" value="<?=htmlspecialchars($fPhone)?>" placeholder="967777153381" required>
          </div>
          <div class="form-group">
            <label>الشورت كود (Short Code)</label>
            <input type="text" name="floosak_short_code" value="<?=htmlspecialchars($fShortCode)?>" placeholder="990000" required>
          </div>
          <button type="submit" name="floosak_request_otp" class="btn btn-primary btn-block">
            <i class="fas fa-sms"></i> طلب رمز التحقق (OTP)
          </button>
        </form>
      </div>
    </div>

    <!-- خطوة 2: إدخال OTP واحفظ المفتاح -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-check-circle"></i> الخطوة 2 — تأكيد وحفظ المفتاح</div>
      </div>
      <div class="card-body">
        <?php if($fPendingReq): ?>
        <div style="padding:10px 14px;background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.2);border-radius:10px;margin-bottom:1rem;font-size:.82rem">
          <i class="fas fa-clock" style="color:#00d4aa"></i>
          انتظار الرمز... Request ID: <strong style="color:#00d4aa"><?=$fPendingReq?></strong>
        </div>
        <form method="POST">
        <?= adminCsrfField() ?>
          <div class="form-group">
            <label>رمز التحقق (OTP) المرسل لهاتفك</label>
            <input type="text" name="floosak_otp" placeholder="123456" maxlength="6" style="font-size:1.5rem;letter-spacing:8px;text-align:center" required>
          </div>
          <button type="submit" name="floosak_verify_otp" class="btn btn-success btn-block">
            <i class="fas fa-unlock"></i> تأكيد وحفظ المفتاح
          </button>
        </form>
        <?php else: ?>
        <div style="text-align:center;padding:2rem;color:#8895a7">
          <i class="fas fa-mobile-alt" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:.8rem"></i>
          أطلب رمز التحقق أولاً من الخطوة 1
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- إعدادات يدوية + تحديث -->
  <div class="card" style="margin-top:1rem">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-cog"></i> إعدادات متقدمة (يدوي)</div>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label>Merchant Key (Token) <small style="color:#8895a7">تُملأ تلقائياً</small></label>
            <textarea name="floosak_merchant_key" rows="3" style="font-family:monospace;font-size:.72rem;word-break:break-all"><?=htmlspecialchars($floosakCfg['floosak_merchant_key']??'')?></textarea>
          </div>
          <div>
            <div class="form-group">
              <label>Wallet ID <small style="color:#8895a7">تُملأ تلقائياً</small></label>
              <input type="number" name="floosak_wallet_id" value="<?=htmlspecialchars($fWalletId)?>" placeholder="144">
            </div>
            <div class="form-group">
              <label>رقم الهاتف</label>
              <input type="text" name="floosak_phone" value="<?=htmlspecialchars($fPhone)?>">
            </div>
            <div class="form-group">
              <label>الشورت كود</label>
              <input type="text" name="floosak_short_code" value="<?=htmlspecialchars($fShortCode)?>">
            </div>

            <!-- صورة / شعار فلوسك -->
            <div class="form-group">
              <label>شعار / صورة فلوسك <small style="color:#8895a7">PNG، JPG، SVG — حد 2MB</small></label>
              <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <?php if($fImage): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars($fImage)?>" id="floosakImgCurrent"
                     style="width:64px;height:64px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,.05);border:1px solid var(--border);padding:4px">
                <?php else: ?>
                <div id="floosakImgPlaceholder" style="width:64px;height:64px;border-radius:12px;background:var(--bg);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:1.4rem">
                  <i class="fas fa-image"></i>
                </div>
                <?php endif; ?>
                <div style="flex:1">
                  <input type="file" name="floosak_image" id="floosakImageInput" accept="image/*,.svg" style="display:none"
                         onchange="previewFloosakImg(this)">
                  <button type="button" onclick="document.getElementById('floosakImageInput').click()"
                          class="btn btn-sm btn-secondary"><i class="fas fa-upload"></i> اختر صورة</button>
                  <div style="font-size:.75rem;color:#8895a7;margin-top:4px">ستظهر للعميل عند اختيار فلوسك</div>
                </div>
              </div>
              <img id="floosakImgPreview" src="" style="display:none;width:80px;height:80px;border-radius:12px;object-fit:contain;margin-top:8px;border:1px solid var(--border);padding:4px;background:rgba(255,255,255,.05)">
            </div>

            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:12px">
              <input type="checkbox" name="floosak_enabled" value="1" <?=$fEnabled?'checked':''?>> تفعيل بوابة فلوسك للعملاء
            </label>
            <button type="submit" name="save_floosak" class="btn btn-warning btn-block"><i class="fas fa-save"></i> حفظ الإعدادات يدوياً</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- سجل عمليات فلوسك -->
  <div class="card" style="margin-top:1rem">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-history"></i> سجل عمليات الشحن عبر فلوسك</div>
    </div>
    <div class="card-body" style="padding:0">
      <?php if(empty($floosakSessions)): ?>
      <div style="text-align:center;padding:2.5rem;color:#8895a7">
        <i class="fas fa-receipt" style="font-size:2rem;opacity:.2;display:block;margin-bottom:.5rem"></i>
        لا توجد عمليات بعد
      </div>
      <?php else: ?>
      <div class="table-wrap">
      <table style="width:100%;border-collapse:collapse;font-size:.83rem">
        <thead>
          <tr style="background:var(--bg)">
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">#</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">العميل</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">الهاتف</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">المبلغ (YER)</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">المبلغ ($)</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">الحالة</th>
            <th style="padding:10px 14px;text-align:right;font-weight:700;color:#8895a7">التاريخ</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($floosakSessions as $s):
          $sColor = ['completed'=>'#00d4aa','pending'=>'#f5a623','failed'=>'#ff4455','expired'=>'#8895a7'][$s['status']]??'#8895a7';
          $sLabel = ['completed'=>'مكتملة','pending'=>'انتظار','failed'=>'فشلت','expired'=>'منتهية'][$s['status']]??$s['status'];
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 14px;color:#8895a7"><?=$s['purchase_id']?></td>
          <td style="padding:10px 14px;font-weight:700"><?=htmlspecialchars($s['username'])?></td>
          <td style="padding:10px 14px;direction:ltr;color:#00d4ff"><?=htmlspecialchars($s['phone']??'')?></td>
          <td style="padding:10px 14px;font-weight:800;color:#fff"><?=number_format($s['amount_yer'])?></td>
          <td style="padding:10px 14px;font-weight:800;color:#00d4aa"><?=$s['amount_usd']?'$'.number_format($s['amount_usd'],4):'—'?></td>
          <td style="padding:10px 14px"><span style="color:<?=$sColor?>;font-weight:800;font-size:.78rem"><?=$sLabel?></span></td>
          <td style="padding:10px 14px;color:#8895a7;font-size:.78rem"><?=date('d/m H:i',strtotime($s['created_at']))?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div><?php endif; ?>
    </div>
  </div>

</div>

