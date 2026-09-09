<!-- ═══════════════ طرق الدفع ═══════════════ -->
<div id="tab-methods" class="tab-pane" style="<?=$tab!=='methods'?'display:none':''?>">

  <?php if($action==='edit_method' && $editMethod || $action==='add_method'): ?>
  <!-- فورم إضافة / تعديل -->
  <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_method" value="1">
    <input type="hidden" name="method_id" value="<?=$editMethod['id']??0?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

    <div class="card">
      <div class="card-header"><div class="card-header-title"><i class="fas fa-credit-card"></i> بيانات طريقة الدفع</div></div>
      <div class="card-body">
        <div class="form-group">
          <label>اسم طريقة الدفع *</label>
          <input type="text" name="name" value="<?=htmlspecialchars($editMethod['name']??'')?>" placeholder="مثال: حوالة بنكية — بنك الراجحي" required>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>النوع</label>
            <select name="type">
              <?php foreach(['bank'=>'🏦 بنك','ewallet'=>'📱 محفظة إلكترونية','crypto'=>'₿ كريبتو','other'=>'🔗 أخرى'] as $v=>$l): ?>
              <option value="<?=$v?>" <?=($editMethod['type']??'bank')===$v?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الأيقونة <small><a href="https://fontawesome.com/icons" target="_blank" style="color:var(--primary);font-size:.75rem">FA Icons</a></small></label>
            <div style="display:flex;gap:6px">
              <input type="text" name="icon" id="iconInput" value="<?=htmlspecialchars($editMethod['icon']??'university')?>" placeholder="university" oninput="document.getElementById('iconPreview').className='fas fa-'+this.value">
              <div style="width:42px;height:42px;border-radius:8px;background:var(--bg);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-<?=$editMethod['icon']??'university'?>" id="iconPreview" style="font-size:1.2rem;color:var(--primary)"></i>
              </div>
            </div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="color" value="<?=htmlspecialchars($editMethod['color']??'#6c3fe0')?>" style="height:42px;width:100%;border-radius:8px;cursor:pointer">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sort_order" value="<?=$editMethod['sort_order']??0?>" min="0">
          </div>
        </div>
        <div class="form-group">
          <label>نوع الدفع</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px">
            <label style="display:flex;align-items:center;gap:10px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:.15s" id="modeManualLbl">
              <input type="radio" name="payment_mode" value="manual" <?=($editMethod['payment_mode']??'manual')==='manual'?'checked':''?> onchange="highlightMode()" style="accent-color:var(--primary)">
              <div>
                <div style="font-weight:800;font-size:.88rem">🏦 يدوي</div>
                <div style="font-size:.73rem;color:#8895a7;margin-top:2px">العميل يرفع إيصال وينتظر الموافقة</div>
              </div>
            </label>
            <label style="display:flex;align-items:center;gap:10px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer;transition:.15s" id="modeAutoLbl">
              <input type="radio" name="payment_mode" value="auto" <?=($editMethod['payment_mode']??'')==='auto'?'checked':''?> onchange="highlightMode()" style="accent-color:var(--primary)">
              <div>
                <div style="font-weight:800;font-size:.88rem">⚡ مباشر</div>
                <div style="font-size:.73rem;color:#8895a7;margin-top:2px">يُشحن تلقائياً عبر API</div>
              </div>
            </label>
          </div>
        </div>
        <div class="form-group">
          <label>صناديق القبض حسب العملة</label>
          <div style="display:grid;gap:9px;margin-top:8px">
            <?php foreach (($rates ?? []) as $currency):
              if ((int)($currency['status'] ?? 0) !== 1) continue;
              $currencyCode = strtoupper((string)$currency['currency_code']);
              $mappedBoxId = (int)($editCashboxByCurrency[$currencyCode]['cashbox_id'] ?? 0);
            ?>
            <div style="display:grid;grid-template-columns:95px 1fr;gap:10px;align-items:center;background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:9px 10px">
              <div><strong><?=htmlspecialchars($currencyCode)?></strong><small style="display:block;color:#8895a7"><?=htmlspecialchars($currency['currency_name'] ?? '')?></small></div>
              <select name="cashbox_by_currency[<?=htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8')?>]" class="form-control">
                <option value="0">— لا يوجد صندوق مربوط —</option>
                <?php foreach(($cashboxes ?? []) as $box):
                  if (strtoupper((string)($box['currency_code'] ?? '')) !== $currencyCode) continue;
                ?>
                <option value="<?= (int)$box['id'] ?>" <?=((int)$mappedBoxId === (int)$box['id'])?'selected':''?>>
                  <?=htmlspecialchars($box['name'].' ('.$box['code'].')'.(!empty($box['staff_name'])?' — '.$box['staff_name']:''))?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endforeach; ?>
          </div>
          <small style="display:block;color:#8895a7;margin-top:7px">يختار النظام الصندوق تلقائياً بناءً على عملة طلب العميل. يجب ربط صندوق مستقل بكل عملة تريد قبولها، ولا يمكن ربط صندوق بعملة مختلفة.</small>
          <input type="hidden" name="cashbox_id" value="<?= (int)($editMethod['cashbox_id'] ?? 0) ?>">
        </div>
        <div class="form-group">
          <label>العملات المتاحة لهذه الوسيلة</label>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:8px">
            <?php foreach (($rates ?? []) as $currency):
              if ((int)($currency['status'] ?? 0) !== 1) continue;
              $currencyCode = strtoupper($currency['currency_code']);
              $currencyChecked = empty($editAllowedCurrencies) || in_array($currencyCode, $editAllowedCurrencies, true);
            ?>
            <label style="display:flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px;cursor:pointer">
              <input type="checkbox" name="allowed_currencies[]" value="<?=htmlspecialchars($currencyCode)?>" <?=$currencyChecked?'checked':''?> style="width:17px;height:17px;accent-color:var(--primary)">
              <span><strong><?=htmlspecialchars($currencyCode)?></strong><small style="display:block;color:#8895a7"><?=htmlspecialchars($currency['currency_name'] ?? '')?></small></span>
            </label>
            <?php endforeach; ?>
          </div>
          <small style="display:block;color:#8895a7;margin-top:6px">يطبق هذا القيد على وسائل الدفع اليدوية عند رفع الإيصال. إذا لم تحدد أي عملة، ستبقى الوسيلة متاحة لكل العملات النشطة حفاظاً على التوافق السابق.</small>
        </div>
        <div class="form-group">
          <label>تعليمات / ملاحظات للعميل</label>
          <textarea name="description" rows="3" placeholder="مثال: أرسل الحوالة ثم أرفق الإيصال..."><?=htmlspecialchars($editMethod['description']??'')?></textarea>
        </div>
        <div class="form-group">
          <label>صورة / شعار طريقة الدفع <small style="color:#8895a7">(اختياري — PNG, JPG, SVG، حد 2MB)</small></label>
          <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <?php if(!empty($editMethod['image'])): ?>
            <img src="<?=SITE_URL?>/<?=htmlspecialchars($editMethod['image'])?>" style="width:64px;height:64px;border-radius:12px;object-fit:contain;background:rgba(255,255,255,.05);border:1px solid var(--border);padding:4px" id="currentMethodImg">
            <?php else: ?>
            <div style="width:64px;height:64px;border-radius:12px;background:var(--bg);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:1.4rem" id="imgPlaceholder"><i class="fas fa-image"></i></div>
            <?php endif; ?>
            <div style="flex:1">
              <input type="file" name="method_image" id="methodImageInput" accept="image/*,.svg" style="display:none" onchange="previewMethodImg(this)">
              <button type="button" onclick="document.getElementById('methodImageInput').click()" class="btn btn-sm btn-secondary"><i class="fas fa-upload"></i> اختر صورة</button>
              <div style="font-size:.75rem;color:#8895a7;margin-top:4px">ستظهر للعميل عند اختيار طريقة الدفع</div>
            </div>
          </div>
          <img id="methodImgPreview" src="" style="display:none;width:80px;height:80px;border-radius:12px;object-fit:contain;margin-top:8px;border:1px solid var(--border);padding:4px;background:rgba(255,255,255,.05)">
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9rem">
          <input type="checkbox" name="status" value="1" <?=($editMethod['status']??1)?'checked':''?>> تفعيل طريقة الدفع
        </label>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-list"></i> الحقول القابلة للنسخ</div>
        <button type="button" class="btn btn-sm btn-primary" onclick="addFieldRow()"><i class="fas fa-plus"></i> حقل</button>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 2fr 70px 30px;gap:6px;margin-bottom:8px">
          <div style="font-size:.75rem;color:#8895a7;font-weight:700">التسمية</div>
          <div style="font-size:.75rem;color:#8895a7;font-weight:700">القيمة</div>
          <div style="font-size:.75rem;color:#8895a7;font-weight:700;text-align:center">نسخ</div>
          <div></div>
        </div>
        <div id="fieldsContainer">
          <?php foreach($editFields as $i=>$f): ?>
          <div class="field-row" id="fieldRow_<?=$i?>">
            <input type="text" name="field_label[]" value="<?=htmlspecialchars($f['field_label'])?>" placeholder="اسم الحقل" class="form-control" style="font-size:.83rem">
            <input type="text" name="field_value[]" value="<?=htmlspecialchars($f['field_value'])?>" placeholder="القيمة" class="form-control" style="font-size:.83rem">
            <div style="text-align:center">
              <input type="checkbox" name="field_copy[]" value="<?=$i?>" <?=$f['copyable']?'checked':''?> title="قابل للنسخ" style="width:18px;height:18px;accent-color:var(--primary)">
            </div>
            <button type="button" onclick="this.closest('.field-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
          </div>
          <?php endforeach; ?>
          <?php if(empty($editFields)): ?>
          <div class="field-row" id="fieldRow_0">
            <input type="text" name="field_label[]" placeholder="مثال: اسم المستلم" class="form-control" style="font-size:.83rem">
            <input type="text" name="field_value[]" placeholder="أحمد محمد" class="form-control" style="font-size:.83rem">
            <div style="text-align:center"><input type="checkbox" name="field_copy[]" value="0" checked style="width:18px;height:18px;accent-color:var(--primary)"></div>
            <button type="button" onclick="this.closest('.field-row').remove()" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top:1rem;padding:12px;background:rgba(108,63,224,.08);border-radius:8px;font-size:.8rem;color:#8895a7">
          <i class="fas fa-info-circle" style="color:var(--primary)"></i>
          أمثلة: اسم المستلم، رقم الجوال، رقم الآيبان، عنوان المحفظة...
        </div>
      </div>
    </div>

    </div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ طريقة الدفع</button>
      <a href="?tab=methods" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
  <?php else: ?>

  <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
    <a href="?action=add_method&tab=methods" class="btn btn-primary"><i class="fas fa-plus"></i> إضافة طريقة دفع</a>
  </div>

  <?php if(empty($methods)): ?>
  <div class="card"><div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-credit-card" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    لا توجد طرق دفع بعد — أضف أولى طرق الدفع
  </div></div>
  <?php else: ?>
  <?php foreach($methods as $m): ?>
  <div class="method-card">
    <div class="method-icon-box" style="background:<?=htmlspecialchars($m['color'])?>22;color:<?=htmlspecialchars($m['color'])?>">
      <?php if(!empty($m['image'])): ?>
      <img src="<?=SITE_URL?>/<?=htmlspecialchars($m['image'])?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:4px">
      <?php else: ?>
      <i class="fas fa-<?=htmlspecialchars($m['icon'])?>"></i>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:800"><?=htmlspecialchars($m['name'])?></div>
      <div style="font-size:.8rem;color:#8895a7;margin-top:3px">
        <?=$m['field_count']?> حقل &nbsp;·&nbsp;
        <?=['bank'=>'بنك','ewallet'=>'محفظة إلكترونية','crypto'=>'كريبتو','other'=>'أخرى'][$m['type']]??$m['type']?>
        &nbsp;·&nbsp;
        <?php $methodBoxes = $methodCashboxMap[(int)$m['id']] ?? []; ?>
        <?php if(!empty($methodBoxes)): ?>
        <span style="color:#00d4aa;font-size:.72rem"><i class="fas fa-vault"></i> صناديق:
          <?=htmlspecialchars(implode('، ', array_map(fn($code,$box) => $code.' → '.($box['cashbox_name'] ?? $box['cashbox_code'] ?? '#'.$box['cashbox_id']), array_keys($methodBoxes), $methodBoxes)))?>
        </span>
        <?php elseif(!empty($m['cashbox_id'])): ?>
        <span style="color:#f5a623;font-size:.72rem"><i class="fas fa-vault"></i> صندوق قديم مرتبط</span>
        <?php else: ?>
        <span style="color:#8895a7;font-size:.72rem">بدون صندوق</span>
        <?php endif; ?>
        &nbsp;·&nbsp;
        <span style="color:#a78bfa;font-size:.72rem"><i class="fas fa-coins"></i>
          <?php if (!empty($m['allowed_currency_codes'])): ?><?=htmlspecialchars(implode('، ', $m['allowed_currency_codes']))?><?php else: ?>كل العملات<?php endif; ?>
        </span>
        &nbsp;·&nbsp;
        <?php if(($m['payment_mode']??'manual')==='auto'): ?>
        <span style="color:#00d4ff;font-size:.72rem">⚡ مباشر</span>
        <?php else: ?>
        <span style="color:#f5a623;font-size:.72rem">🏦 يدوي</span>
        <?php endif; ?>
      </div>
      <?php if($m['description']): ?><div style="font-size:.78rem;color:#a78bfa;margin-top:3px"><?=htmlspecialchars(mb_substr($m['description'],0,60))?><?=mb_strlen($m['description'])>60?'...':''?></div><?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
      <span class="badge <?=$m['status']?'badge-success':'badge-danger'?>"><?=$m['status']?'نشط':'متوقف'?></span>
      <a href="?action=edit_method&id=<?=$m['id']?>&tab=methods" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
      <a href="?action=toggle_method&id=<?=$m['id']?>" class="btn btn-sm btn-secondary"><i class="fas fa-<?=$m['status']?'eye-slash':'eye'?>"></i></a>
      <a href="?action=delete_method&id=<?=$m['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف هذه الطريقة؟')"><i class="fas fa-trash"></i></a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  <?php endif; ?>
</div>
