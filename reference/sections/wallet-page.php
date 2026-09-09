<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ WALLET PAGE ══ -->
    <div class="page" id="page-wallet" style="overflow-y:auto">
      <div style="padding:14px 12px">
        <?php if (!isLoggedIn()): ?>
        <div class="auth-gate-box" onclick="openAuth('login')">
          <div class="auth-gate-icon">💰</div>
          <div class="auth-gate-title">سجل دخولك لعرض محفظتك</div>
          <div class="auth-gate-sub">انقر للدخول أو إنشاء حساب جديد</div>
          <div class="auth-gate-btn"><i class="fas fa-sign-in-alt"></i> دخول / تسجيل</div>
        </div>
        <?php else: ?>

        <!-- بطاقة الرصيد -->
        <div class="wallet-card" style="margin-bottom:16px">
          <div class="wallet-balance-label">رصيدي الحالي</div>
          <div>
            <span class="wallet-balance-amount"><?= number_format($userBalance, 2) ?></span>
            <span class="wallet-balance-cur"> <?= htmlspecialchars($currSymbol) ?></span>
          </div>
          <div style="font-size:11px;color:rgba(255,255,255,.4);margin-top:5px">
            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($userName) ?>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px">
            <button class="wallet-action-btn" onclick="openTopupSheet()">
              <i class="fas fa-plus-circle"></i> شحن الرصيد
            </button>
            <button class="wallet-action-btn" onclick="openCardRedeem()"
                    style="background:rgba(0,212,170,.12);border:1px solid rgba(0,212,170,.25);color:#00d4aa">
              <i class="fas fa-ticket-alt"></i> شحن بكود
            </button>
          </div>
        </div>

        <!-- إحصائيات -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
          <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px">
            <div style="font-size:11px;color:var(--text3);margin-bottom:4px;display:flex;align-items:center;gap:5px">
              <i class="fas fa-arrow-down" style="color:var(--green)"></i> إجمالي الإيداعات
            </div>
            <div style="font-size:20px;font-weight:900;color:var(--green)">+<?= number_format($walletTotalCredit, 2) ?></div>
          </div>
          <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px">
            <div style="font-size:11px;color:var(--text3);margin-bottom:4px;display:flex;align-items:center;gap:5px">
              <i class="fas fa-arrow-up" style="color:var(--red)"></i> إجمالي المصروف
            </div>
            <div style="font-size:20px;font-weight:900;color:var(--red)">-<?= number_format($walletTotalDebit, 2) ?></div>
          </div>
        </div>

        <!-- سجل المعاملات -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div style="font-size:14px;font-weight:800"><i class="fas fa-history" style="color:var(--primary)"></i> سجل المعاملات</div>
          <span style="font-size:11px;color:var(--text3);background:var(--card2);padding:3px 8px;border-radius:20px"><?= count($walletTransactions) ?> عملية</span>
        </div>

        <?php if (!empty($walletTransactions)): ?>
        <!-- فلتر -->
        <div style="display:flex;gap:6px;margin-bottom:12px" id="walletTxFilter">
          <button class="wallet-filter-btn active" onclick="filterWalletTx('all',this)">الكل</button>
          <button class="wallet-filter-btn" onclick="filterWalletTx('credit',this)"><i class="fas fa-plus" style="color:var(--green)"></i> إيداع</button>
          <button class="wallet-filter-btn" onclick="filterWalletTx('debit',this)"><i class="fas fa-minus" style="color:var(--red)"></i> خصم</button>
        </div>

        <div id="walletTxList" style="display:flex;flex-direction:column;gap:2px">
          <?php foreach ($walletTransactions as $wt):
            $positiveTypes = ['credit','topup','refund','prize','referral','referral_welcome'];
            $wtPlus = in_array($wt['type'], $positiveTypes) && $wt['type'] !== 'referral_revoke';
            $wtIconMap = ['prize'=>'trophy','referral'=>'users','referral_welcome'=>'gift','referral_revoke'=>'undo','refund'=>'undo','credit'=>'arrow-down','topup'=>'arrow-down'];
            $wtIcon = $wtIconMap[$wt['type']] ?? ($wtPlus ? 'arrow-down' : 'arrow-up');
            $wtColorMap = ['prize'=>'#f5a623','referral'=>'#00c853','referral_welcome'=>'#7c3aed','referral_revoke'=>'#ff4455','refund'=>'#00e676','credit'=>'#00e676','topup'=>'#00e676'];
            $wtColor = $wtColorMap[$wt['type']] ?? ($wtPlus ? '#00e676' : '#ff4455');
            $wtDesc = $wt['description'] ?? '';
            $isGiftWt = strpos($wtDesc,'🎁') !== false || strpos($wtDesc,'هدية') !== false;
            $giftDataWt = null;
            if ($isGiftWt) {
                foreach ($giftsMap as $g) {
                    if (abs(strtotime($wt['created_at']) - strtotime($g['created_at'])) < 120 && abs((float)$g['amount'] - (float)$wt['amount']) < 0.01) {
                        $giftDataWt = $g; break;
                    }
                }
            }
            $giftJsonWt = $giftDataWt ? htmlspecialchars(json_encode([
                'id'=>$giftDataWt['id'],'amount'=>$giftDataWt['amount'],'message'=>$giftDataWt['message']??'',
                'status'=>$giftDataWt['status'],'created_at'=>$giftDataWt['created_at'],
                'sender_name'=>$giftDataWt['sender_name']?:$giftDataWt['sender_username'],
                'receiver_name'=>$giftDataWt['receiver_name']?:$giftDataWt['receiver_username'],
                'is_sender'=>(int)$giftDataWt['sender_id']===(int)$_SESSION['user_id'],
            ],JSON_UNESCAPED_UNICODE),ENT_QUOTES) : '';
          ?>
          <div class="wallet-tx-item" data-type="<?= $wtPlus?'credit':'debit' ?>"
               <?= $isGiftWt ? 'onclick="showWalletGiftDetail('.($giftJsonWt?"'".$giftJsonWt."'":'null').','.json_encode($wtDesc).','.(float)$wt['amount'].')" style="cursor:pointer"' : '' ?>>
            <div style="width:42px;height:42px;border-radius:12px;background:<?=$wtColor?>18;color:<?=$wtColor?>;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">
              <i class="fas fa-<?=$wtIcon?>"></i>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($wtDesc ?: ($wtPlus?'إيداع رصيد':'خصم رصيد')) ?>
              </div>
              <div style="font-size:10px;color:var(--text3);margin-top:2px">
                <i class="fas fa-clock"></i>
                <?= date('Y/m/d • h:i A', strtotime($wt['created_at'])) ?>
              </div>
            </div>
            <div style="text-align:left;flex-shrink:0">
              <?php if($isGiftWt): ?>
              <div style="font-size:.65rem;background:rgba(255,107,157,.15);color:#ff6b9d;padding:2px 8px;border-radius:6px;margin-bottom:4px;text-align:center">🎁 هدية</div>
              <?php endif; ?>
              <div style="font-size:15px;font-weight:900;color:<?=$wtColor?>">
                <?= $wtPlus?'+':'-' ?><?php
                  $dAmt = (float)$wt['amount'];
                  echo strpos($wt['type'],'referral')!==false ? number_format($dAmt,4) : number_format($dAmt,2);
                ?>
              </div>
              <div style="font-size:10px;color:var(--text3);margin-top:1px">الرصيد: <?= number_format((float)$wt['balance_after'],2) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state-app"><i class="fas fa-exchange-alt"></i><p>لا توجد معاملات حتى الآن</p></div>
        <?php endif; ?>

        <?php endif; ?>
        <div style="height:24px"></div>
      </div>
    </div>

