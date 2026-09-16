<!-- ═══════════════ أسعار الصرف ═══════════════ -->
<div id="tab-rates" class="tab-pane" style="<?=$tab!=='rates'?'display:none':''?>">
  <form method="POST">
        <?= adminCsrfField() ?>
  <input type="hidden" name="save_rates" value="1">
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-exchange-alt"></i> أسعار الصرف (بالنسبة للدولار $)</div>
      <button type="button" class="btn btn-sm btn-primary" onclick="addRateRow()"><i class="fas fa-plus"></i> إضافة عملة</button>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:100px 1fr 80px 160px 80px 36px;gap:8px;margin-bottom:8px;padding:0 4px">
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">الكود</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">اسم العملة</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">الرمز</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700">1 وحدة = ؟ دولار</div>
        <div style="font-size:.75rem;color:#8895a7;font-weight:700;text-align:center">تفعيل</div>
        <div></div>
      </div>
      <div id="ratesContainer">
      <input type="hidden" name="deleted_rate_ids[]" value="" disabled>
      <?php foreach($rates as $i=>$r): $rateId=(int)$r['id']; $rateKey=(string)$rateId; ?>
      <div class="rate-row" id="rateRow_<?=$rateId?>" data-rate-id="<?=$rateId?>">
        <input type="hidden" name="rate_row_ids[]" value="<?=$rateId?>">
        <input type="text" name="code[<?=$rateKey?>]" value="<?=htmlspecialchars($r['currency_code'])?>" class="form-control" style="font-size:.85rem;text-transform:uppercase" <?=$r['currency_code']==='USD'?'readonly style="opacity:.5;font-size:.85rem"':''?>>
        <input type="text" name="cname[<?=$rateKey?>]" value="<?=htmlspecialchars($r['currency_name'])?>" class="form-control" style="font-size:.85rem">
        <input type="text" name="symbol[<?=$rateKey?>]" value="<?=htmlspecialchars($r['currency_symbol'])?>" class="form-control" style="font-size:.85rem">
        <input type="text" name="rate[<?=$rateKey?>]" value="<?=rtrim(rtrim(number_format($r['rate_to_usd'],10),'0'),'.')?>" class="form-control" style="font-size:.85rem" placeholder="0.2666700000">
        <div style="text-align:center"><input type="checkbox" name="cstatus[<?=$rateKey?>]" value="1" <?=$r['status']?'checked':''?> style="width:18px;height:18px;accent-color:var(--primary)"></div>
        <?php if(strtoupper($r['currency_code'])==='USD'): ?>
        <div style="width:28px"></div>
        <?php else: ?>
        <button type="button" onclick="markRateDeleted(this, <?=$rateId?>)" style="background:rgba(255,68,85,.15);border:none;border-radius:6px;color:#ff4455;width:28px;height:28px;cursor:pointer">×</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <div id="deletedRateIds"></div>
      <div style="margin-top:1rem;padding:12px;background:rgba(0,212,170,.06);border-radius:8px;font-size:.82rem;color:#8895a7">
        <i class="fas fa-info-circle" style="color:#00d4aa"></i>
        مثال: الريال السعودي = <strong style="color:#fff">0.2666700000</strong> (أي 1 ريال = 0.267 دولار)
        &nbsp;|&nbsp; الريال اليمني = <strong style="color:#fff">0.0018600000</strong>
      </div>
    </div>
    <div style="padding:0 1rem 1rem">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ أسعار الصرف</button>
    </div>
  </div>
  </form>
</div>

