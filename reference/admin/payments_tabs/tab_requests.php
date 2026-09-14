<!-- ═══════════════ طلبات الشحن ═══════════════ -->
<div id="tab-requests" class="tab-pane" style="<?=$tab!=='requests'?'display:none':''?>">

<?php if(empty($requests)): ?>
<div class="card"><div style="text-align:center;padding:3rem;color:#8895a7"><i class="fas fa-inbox" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>لا توجد طلبات بعد</div></div>
<?php else: ?>

<!-- فلتر -->
<div style="display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap">
  <?php foreach(['all'=>'الكل','pending'=>'انتظار','approved'=>'موافق','rejected'=>'مرفوض'] as $k=>$lbl): ?>
  <button onclick="filterReqs('<?=$k?>')" class="btn btn-sm btn-secondary filter-req-btn" data-filter="<?=$k?>"><?=$lbl?></button>
  <?php endforeach; ?>
</div>

<?php foreach($requests as $req):
  $statusLabel = ['pending'=>'انتظار','approved'=>'موافق عليه','rejected'=>'مرفوض'][$req['status']] ?? '';
?>
<div class="req-card" data-status="<?=$req['status']?>">
  <!-- أيقونة الطريقة -->
  <div style="width:44px;height:44px;border-radius:12px;background:rgba(108,63,224,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.3rem;color:var(--primary)">
    <i class="fas fa-<?=htmlspecialchars($req['method_icon'])?>"></i>
  </div>
  <!-- معلومات -->
  <div style="flex:1;min-width:180px">
    <div style="font-weight:800;font-size:.95rem"><?=htmlspecialchars($req['username'])?> <?=$req['full_name']?'<span style="color:#8895a7;font-size:.8rem"> — '.htmlspecialchars($req['full_name']).'</span>':''?></div>
    <div style="font-size:.8rem;color:#8895a7;margin-top:2px">
      <?=htmlspecialchars($req['method_name'])?> &nbsp;·&nbsp;
      <?=$req['currency_symbol']?> <?=number_format($req['amount_sent'],2)?> <?=$req['currency_name']?>
      &nbsp;·&nbsp; <?=date('d/m H:i',strtotime($req['created_at']))?>
    </div>
    <?php if($req['notes']): ?><div style="font-size:.78rem;color:#a78bfa;margin-top:3px">📝 <?=htmlspecialchars($req['notes'])?></div><?php endif; ?>
  </div>
  <!-- المبلغ بالدولار -->
  <div style="text-align:center;flex-shrink:0">
    <div class="req-amount">$<?=number_format($req['amount_usd'],4)?></div>
    <div style="font-size:.73rem;color:#8895a7">سيُضاف للرصيد</div>
  </div>
  <!-- إيصال -->
  <?php if($req['receipt_image']): ?>
  <img src="<?=SITE_URL?>/<?=htmlspecialchars($req['receipt_image'])?>" class="receipt-thumb" onclick="viewReceipt('<?=SITE_URL?>/<?=htmlspecialchars($req['receipt_image'])?>')">
  <?php else: ?>
  <div style="width:60px;height:50px;border-radius:8px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:#8895a7;font-size:.7rem;text-align:center">بدون<br>إيصال</div>
  <?php endif; ?>
  <!-- الحالة والأزرار -->
  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0">
    <span class="req-status <?=$req['status']?>"><?=$statusLabel?></span>
    <?php if($req['status']==='pending'): ?>
    <div style="display:flex;gap:5px">
      <?php
        $approveCurrency = strtoupper((string)($req['currency_code'] ?? 'USD'));
        $approveCashboxLabel = trim((string)($req['request_cashbox_name'] ?? '').(!empty($req['request_cashbox_code']) ? ' ('.$req['request_cashbox_code'].')' : ''));
        $approveJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
      ?>
      <button class="btn btn-sm btn-success" onclick='openApprove(<?=$req['id']?>,<?=json_encode((float)$req['amount_usd'])?>,<?=json_encode((string)$req['username'], $approveJsonFlags)?>,<?=json_encode((string)$req['method_name'], $approveJsonFlags)?>,<?=json_encode($approveCurrency, $approveJsonFlags)?>,<?=json_encode($approveCashboxLabel, $approveJsonFlags)?>)'>
        <i class="fas fa-check"></i> موافقة
      </button>
      <button class="btn btn-sm btn-danger" onclick="openReject(<?=$req['id']?>)">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <?php elseif($req['admin_notes']): ?>
    <div style="font-size:.73rem;color:#8895a7;max-width:160px;text-align:right"><?=htmlspecialchars($req['admin_notes'])?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
