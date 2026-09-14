<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ PAYMENTS PAGE ══ -->
    <div class="page" id="page-payments" style="overflow-y:auto">
      <?php if (!isLoggedIn()): ?>
      <div style="padding:40px 20px;text-align:center">
        <div class="auth-gate-box" onclick="openAuth('login')">
          <div class="auth-gate-icon">🔐</div>
          <div class="auth-gate-title">سجل دخولك لعرض مدفوعاتك</div>
          <div class="auth-gate-sub">انقر للدخول أو إنشاء حساب جديد</div>
          <div class="auth-gate-btn"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
        </div>
      </div>
      <?php else: ?>

      <!-- عنوان -->
      <div style="padding:14px 14px 10px;display:flex;align-items:center;justify-content:space-between">
        <div style="font-size:17px;font-weight:900;display:flex;align-items:center;gap:8px">
          <i class="fas fa-credit-card" style="color:var(--primary)"></i> مدفوعاتي
        </div>
        <div style="font-size:12px;color:var(--text3)"><?= count($myPayments) ?> عملية</div>
      </div>

      <!-- فلتر -->
      <div style="display:flex;gap:7px;overflow-x:auto;padding:0 14px 10px;scrollbar-width:none">
        <button onclick="filterPayments('all',this)"   class="pay-filter-btn active" data-f="all"   style="flex-shrink:0;background:var(--primary);border:1.5px solid var(--primary);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:#fff;font-family:var(--font);cursor:pointer">الكل</button>
        <button onclick="filterPayments('manual',this)" class="pay-filter-btn" data-f="manual" style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">يدوي</button>
        <button onclick="filterPayments('direct',this)" class="pay-filter-btn" data-f="direct" style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">مباشر</button>
        <button onclick="filterPayments('approved',this)" class="pay-filter-btn" data-f="approved" style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">✅ مقبول</button>
        <button onclick="filterPayments('card',this)" class="pay-filter-btn" data-f="card" style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">🎫 بطاقة</button>
        <button onclick="filterPayments('rejected',this)" class="pay-filter-btn" data-f="rejected" style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">❌ مرفوض</button>
        <button onclick="filterPayments('pending',this)"  class="pay-filter-btn" data-f="pending"  style="flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:700;color:var(--text2);font-family:var(--font);cursor:pointer">⏳ انتظار</button>
      </div>

      <!-- القائمة -->
      <div style="padding:0 12px 80px" id="paymentsList">
        <?php if (empty($myPayments)): ?>
        <div style="text-align:center;padding:3rem 1rem;color:var(--text3)">
          <i class="fas fa-credit-card" style="font-size:2.5rem;opacity:.1;display:block;margin-bottom:12px"></i>
          <div style="font-weight:700">لا توجد عمليات دفع بعد</div>
        </div>
        <?php else: foreach($myPayments as $pay):
          $isDirect  = ($pay['pay_type'] === 'direct');
          // تحديد الحالة
          $isCard = ($pay['pay_type'] === 'card');
          if ($isDirect) {
              $pStatus = $pay['display_status'] ?? $pay['status'];
              $pStatusMap = ['completed'=>['مكتملة','#00e676','check-circle'], 'pending'=>['انتظار','#f5a623','clock'], 'failed'=>['فشل','#ff4455','times-circle'], 'expired'=>['ملغية ⏱','#ff4455','ban']];
              $pSt = $pStatusMap[$pStatus] ?? ['غير معروف','#8895a7','circle'];
              $pAmount = number_format((float)$pay['amount_usd'], 4) . ' $';
              $pAmountSub = number_format((float)$pay['amount_yer'], 2) . ' ريال';
              $pMethod = 'فلوسك 🔵';
              $pNote   = $pay['phone'] ?? '';
              $pAdmin  = '';
              $filterClass = 'direct ' . $pStatus;
          } elseif ($isCard) {
              $pStatus = 'approved';
              $pSt = ['مشحون ✓', '#00e676', 'check-circle'];
              $pAmount = number_format((float)$pay['amount'], 2) . ' $';
              $pAmountSub = 'كود: ' . htmlspecialchars($pay['code'] ?? '');
              $pMethod = '🎫 بطاقة شحن';
              $pNote   = $pay['code'] ?? '';
              $pAdmin  = $pay['batch_id'] ? 'دفعة: ' . $pay['batch_id'] : '';
              $filterClass = 'card approved';
          } else {
              $pStatus = $pay['status']; // pending/approved/rejected
              $pStatusMap = ['approved'=>['مقبول','#00e676','check-circle'], 'pending'=>['قيد المراجعة','#f5a623','clock'], 'rejected'=>['مرفوض','#ff4455','times-circle']];
              $pSt = $pStatusMap[$pStatus] ?? ['غير معروف','#8895a7','circle'];
              $pAmount = number_format((float)$pay['amount_usd'], 4) . ' $';
              $pAmountSub = number_format((float)$pay['amount_sent'], 2) . ' ' . $pay['currency_code'];
              $pMethod = htmlspecialchars($pay['method_name'] ?? 'غير محدد');
              $pNote   = $pay['notes'] ?? '';
              $pAdmin  = $pay['admin_notes'] ?? '';
              $filterClass = 'manual ' . $pStatus;
          }
        ?>
        <div class="pay-card"
             data-filter="<?= $filterClass ?>"
             data-type="<?= $isDirect?'direct':($isCard?'card':'manual') ?>"
             data-id="<?= $pay['id'] ?>"
             data-method="<?= htmlspecialchars($pMethod) ?>"
             data-amount="<?= $pAmount ?>"
             data-amount-sub="<?= htmlspecialchars($pAmountSub) ?>"
             data-status="<?= $pStatus ?>"
             data-status-label="<?= $pSt[0] ?>"
             data-status-color="<?= $pSt[1] ?>"
             data-date="<?= $pay['created_at'] ?>"
             data-note="<?= htmlspecialchars($pNote) ?>"
             data-admin-note="<?= htmlspecialchars($pAdmin) ?>"
             data-receipt="<?= !$isDirect && ($pay['receipt_image'] ?? '') ? htmlspecialchars(SITE_URL.'/'.($pay['receipt_image'] ?? '')) : '' ?>"
             data-ref="<?= $isDirect ? htmlspecialchars($pay['reference_id']??'') : ($isCard ? htmlspecialchars($pay['code']??'') : htmlspecialchars($pay['id'])) ?>"
             data-phone="<?= $isDirect ? htmlspecialchars($pay['phone']??'') : '' ?>"
             data-amount-yer="<?= $isDirect ? number_format((float)$pay['amount_yer'],2) : '' ?>"
             onclick="openPayDetail(this)"
             style="background:var(--card2);border:1.5px solid var(--border);border-radius:16px;margin-bottom:9px;overflow:hidden;cursor:pointer;transition:.15s;-webkit-tap-highlight-color:transparent">
          <div style="display:flex;align-items:center;gap:12px;padding:12px 13px">
            <!-- أيقونة الطريقة -->
            <div style="width:46px;height:46px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:<?= $isDirect?'rgba(0,136,204,.15)':($isCard?'rgba(0,212,170,.12)':'rgba(30,111,255,.1)') ?>">
              <?= $isDirect ? '💳' : ($isCard ? '🎫' : '<i class="fas fa-university" style="color:var(--primary)"></i>') ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:.9rem"><?= $pMethod ?></div>
              <div style="font-size:.7rem;color:var(--text3);margin-top:2px;display:flex;gap:6px;align-items:center">
                <span><?= $isDirect?'دفع مباشر':($isCard?'شحن بكود':'يدوي') ?></span>
                <span>·</span>
                <span class="server-time" data-time="<?= $pay['created_at'] ?>"><?= date('d/m H:i',strtotime($pay['created_at'])) ?></span>
              </div>
              <?php if($pAmountSub): ?>
              <div style="font-size:.68rem;color:<?= $isCard?'var(--cyan)':'var(--text3)' ?>;margin-top:2px;font-family:<?= $isCard?'monospace':'inherit' ?>"><?= $pAmountSub ?></div>
              <?php endif; ?>
              <?php if($pAdmin): ?>
              <div style="font-size:.7rem;color:<?= $pStatus==='rejected'?'#ff4455':'#00e676' ?>;margin-top:3px">
                <i class="fas fa-comment-alt" style="font-size:.6rem"></i> <?= htmlspecialchars($pAdmin) ?>
              </div>
              <?php endif; ?>
            </div>
            <div style="text-align:left;flex-shrink:0">
              <div style="font-size:.95rem;font-weight:900;color:#00d4aa">+<?= $pAmount ?></div>
            </div>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 13px;border-top:1px solid var(--border);background:rgba(0,0,0,.1)">
            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:800;background:<?= $pSt[1] ?>18;color:<?= $pSt[1] ?>">
              <i class="fas fa-<?= $pSt[2] ?>" style="font-size:.6rem"></i> <?= $pSt[0] ?>
            </span>
            <?php if($isDirect && $pay['reference_id']): ?>
            <span style="font-size:.62rem;color:var(--text3);font-family:monospace"><?= htmlspecialchars(substr($pay['reference_id'],0,14)) ?></span>
            <?php elseif(!$isDirect && ($pay['receipt_image'] ?? '')): ?>
            <span style="font-size:.65rem;color:var(--cyan)"><i class="fas fa-image"></i> يوجد إيصال</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ Payment Detail Sheet ══ -->
    <div id="payDetailOverlay" onclick="closePayDetail()"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:600;backdrop-filter:blur(3px)"></div>
    <div id="payDetailSheet"
         style="position:fixed;bottom:0;left:50%;transform:translateX(-50%) translateY(105%);width:100%;max-width:430px;max-height:92vh;background:var(--card);border-radius:24px 24px 0 0;z-index:601;overflow-y:auto;transition:transform .3s cubic-bezier(.4,0,.2,1);scrollbar-width:none">
      <div style="width:40px;height:4px;background:var(--border2);border-radius:2px;margin:10px auto 0"></div>
      <div id="payDetailContent" style="padding:0 0 30px"></div>
    </div>

