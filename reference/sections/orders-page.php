<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ ORDERS PAGE ══ -->
    <div class="page" id="page-orders" style="overflow-y:auto">
      <?php if (!isLoggedIn()): ?>
      <div style="padding:40px 20px;text-align:center">
        <div class="auth-gate-box" onclick="openAuth('login')">
          <div class="auth-gate-icon">🔐</div>
          <div class="auth-gate-title">سجل دخولك لعرض طلباتك</div>
          <div class="auth-gate-sub">انقر للدخول أو إنشاء حساب جديد</div>
          <div class="auth-gate-btn"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
        </div>
      </div>
      <?php else: ?>

      <!-- عنوان -->
      <div style="padding:14px 14px 0;display:flex;align-items:center;justify-content:space-between">
        <div style="font-size:17px;font-weight:900;display:flex;align-items:center;gap:8px">
          <i class="fas fa-receipt" style="color:var(--primary)"></i> طلباتي
        </div>
        <div style="font-size:12px;color:var(--text3)"><?= $orderStats['total'] ?> طلب</div>
      </div>

      <!-- إحصاءات -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:7px;padding:10px 14px">
        <div class="qa-item" style="padding:10px 6px;text-align:center;border-radius:12px">
          <div style="font-size:1.2rem;font-weight:900"><?= $orderStats['total'] ?></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px">الكل</div>
        </div>
        <div class="qa-item" style="padding:10px 6px;text-align:center;border-radius:12px">
          <div style="font-size:1.2rem;font-weight:900;color:#00e676"><?= $orderStats['completed'] ?></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px">مكتمل</div>
        </div>
        <div class="qa-item" style="padding:10px 6px;text-align:center;border-radius:12px">
          <div style="font-size:1.2rem;font-weight:900;color:#00d4ff"><?= $orderStats['processing'] ?></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px">جارٍ</div>
        </div>
        <div class="qa-item" style="padding:10px 6px;text-align:center;border-radius:12px">
          <div style="font-size:1.2rem;font-weight:900;color:#ff4455"><?= $orderStats['cancelled'] ?></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px">ملغي</div>
        </div>
      </div>

      <!-- فلتر -->
      <div style="display:flex;gap:7px;overflow-x:auto;padding:0 14px 10px;scrollbar-width:none">
        <?php foreach([''=>'الكل','pending'=>'انتظار','processing'=>'تنفيذ','completed'=>'مكتمل','cancelled'=>'ملغي'] as $fk=>$fl): ?>
        <button onclick="filterOrders('<?= $fk ?>')" data-filter="<?= $fk ?>"
                style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer;white-space:nowrap;transition:.2s"
                class="order-filter-btn <?= $fk===''?'active':'' ?>">
          <?= $fl ?>
        </button>
        <?php endforeach; ?>
      </div>

      <!-- قائمة -->
      <div style="padding:0 12px 80px" id="ordersList">
        <?php if (empty($allOrders)): ?>
        <div style="text-align:center;padding:3rem 1rem;color:var(--text3)">
          <i class="fas fa-receipt" style="font-size:2.5rem;opacity:.1;display:block;margin-bottom:12px"></i>
          <div style="font-weight:700">لا توجد طلبات بعد</div>
        </div>
        <?php else: foreach ($allOrders as $ord):
          $ost = $osMap[$ord['status']] ?? ['label'=>$ord['status'],'color'=>'#8895a7','icon'=>'circle'];
          $rawR = trim($ord['status_message'] ?? '');
          $oReason = preg_replace('/^(Oranos|SMM|AP4STOR|SMM_STANDARD|CUSTOM)\s*:\s*\S+\s*/iu', '', $rawR);
          $oReason = trim($oReason);
          $oMsgMap = ['reject'=>'رُفض الطلب من المزود','rejected'=>'رُفض الطلب من المزود','cancel'=>'تم إلغاء الطلب','cancelled'=>'تم إلغاء الطلب','failed'=>'فشل تنفيذ الطلب','completed'=>'تم تنفيذ الطلب بنجاح','processing'=>'جاري تنفيذ الطلب','wait'=>'في انتظار التنفيذ'];
          if (empty($oReason) || preg_match('/^[a-zA-Z_]+$/', $oReason)) $oReason = $oMsgMap[strtolower($oReason)] ?? ($oMsgMap[$ord['status']] ?? '');
          $oFields = json_decode($ord['field_data'], true) ?? [];
          $oFkeys  = array_keys($oFields);
        ?>
        <div class="order-card-full" data-status="<?= htmlspecialchars($ord['status'], ENT_QUOTES, 'UTF-8') ?>" data-source="<?= htmlspecialchars($ord['source_type'] ?? 'standard', ENT_QUOTES, 'UTF-8') ?>" onclick="openOrderDetail(<?= (int)$ord['detail_id'] ?>, '<?= htmlspecialchars($ord['source_type'] ?? 'standard', ENT_QUOTES, 'UTF-8') ?>')"
             style="background:var(--card2);border:1.5px solid var(--border);border-radius:16px;margin-bottom:9px;overflow:hidden;cursor:pointer;transition:.15s;-webkit-tap-highlight-color:transparent">
          <div style="display:flex;align-items:center;gap:12px;padding:12px 13px">
            <div style="width:46px;height:46px;border-radius:12px;overflow:hidden;flex-shrink:0;background:rgba(30,111,255,.1);display:flex;align-items:center;justify-content:center">
              <?php if(!empty($ord['service_image'])): ?>
              <img src="<?= SITE_URL ?>/<?= htmlspecialchars($ord['service_image']) ?>" style="width:100%;height:100%;object-fit:cover">
              <?php else: ?><i class="fas fa-<?= htmlspecialchars($ord['cat_icon'] ?? 'box', ENT_QUOTES, 'UTF-8') ?>" style="color:var(--primary);font-size:1.1rem"></i>
              <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($ord['service_name']) ?></div>
              <div style="font-size:.7rem;color:var(--text3);margin-top:2px;display:flex;gap:6px;align-items:center">
                <span style="font-family:monospace"><?= htmlspecialchars(!empty($ord['ref_id']) ? strtoupper($ord['ref_id']) : ('ID_ORD_'.(int)$ord['id'])) ?></span><span>·</span>
                <span class="server-time" data-time="<?= $ord['created_at'] ?>"><?= date('d/m H:i', strtotime($ord['created_at'])) ?></span>
              </div>
              <?php if(!empty($oFkeys[0])): ?>
              <div style="font-size:.68rem;color:var(--cyan);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($oFkeys[0]) ?>: <strong><?= htmlspecialchars($oFields[$oFkeys[0]]) ?></strong>
              </div>
              <?php endif; ?>
              <?php if($oReason): ?>
              <div style="font-size:.68rem;color:<?= $ost['color'] ?>;margin-top:2px;opacity:.9"><?= htmlspecialchars($oReason) ?></div>
              <?php endif; ?>
            </div>
            <div style="text-align:left;flex-shrink:0">
              <div style="font-size:.95rem;font-weight:900;color:#00d4aa"><?= formatMoney($ord['total_price']) ?></div>
              <div style="font-size:.65rem;color:var(--text3);margin-top:2px">× <?= number_format($ord['quantity']) ?></div>
            </div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 13px;border-top:1px solid var(--border);background:rgba(0,0,0,.1)">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:800;background:<?= $ost['color'] ?>18;color:<?= $ost['color'] ?>">
              <i class="fas fa-<?= $ost['icon'] ?> <?= $ord['status']==='processing'?'fa-spin':'' ?>" style="font-size:.6rem"></i>
              <?= $ost['label'] ?>
            </span>
            <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.6rem"></i>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ ORDER DETAIL SHEET ══ -->
    <div id="orderDetailOverlay" onclick="closeOrderDetail()"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:600;backdrop-filter:blur(3px)"></div>
    <div id="orderDetailSheet"
         style="position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(105%);width:100%;max-width:430px;max-height:92vh;background:var(--card);border-radius:24px 24px 0 0;z-index:601;overflow-y:auto;transition:transform .3s cubic-bezier(.4,0,.2,1);scrollbar-width:none">
      <div style="width:40px;height:4px;background:var(--border2);border-radius:2px;margin:10px auto 0"></div>
      <div id="orderDetailContent" style="padding:0 0 30px"></div>
    </div>

