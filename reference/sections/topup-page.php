<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
    <!-- ══ TOPUP PAGE ══ -->
    <div class="page" id="page-topup" style="overflow-y:auto">
      <div id="topupSheet" style="padding-bottom:20px">

        <!-- ── Step 1: اختيار طريقة الدفع ── -->
        <div id="topupStep1">
          <div class="topup-header" style="padding-top:16px">
            <button class="topup-back-btn" onclick="navTo('wallet')"><i class="fas fa-arrow-right"></i></button>
            <div>
              <div class="topup-header-title">شحن الرصيد</div>
              <div class="topup-header-sub">اختر طريقة الدفع</div>
            </div>
          </div>

          <!-- رصيدك الحالي -->
          <?php if(isLoggedIn()): ?>
          <div class="topup-balance-strip">
            <span style="color:#8895a7;font-size:.8rem">رصيدك الحالي</span>
            <span style="font-weight:900;color:#00d4aa;font-size:1rem"><?= formatMoney($userBalance) ?></span>
          </div>
          <?php endif; ?>

          <?php
          $manualMethods = array_filter($paymentMethods, function($m){ return ($m['payment_mode']??'manual')==='manual'; });
          $autoMethods   = array_filter($paymentMethods, function($m){ return ($m['payment_mode']??'manual')==='auto'; });
          ?>
          <!-- Tabs -->
          <div class="topup-mode-tabs">
            <button class="topup-mode-tab active" id="mTabManual" onclick="switchTopupMode('manual')">
              <span class="tab-icon">🏦</span><span>يدوي</span>
            </button>
            <button class="topup-mode-tab" id="mTabAuto" onclick="switchTopupMode('auto')">
              <span class="tab-icon">⚡</span><span>مباشر</span>
            </button>
            <button class="topup-mode-tab" id="mTabCard" onclick="switchTopupMode('card')">
              <span class="tab-icon">🎫</span><span>بكود</span>
            </button>
            <?php if(!empty($smsTopupEnabled)): ?>
            <button class="topup-mode-tab" id="mTabSms" onclick="switchTopupMode('sms')">
              <span class="tab-icon">⚡</span><span>مباشر 2</span>
            </button>
            <?php endif; ?>
          </div>
          <div class="topup-mode-desc" id="modeDescTxt">أرسل الحوالة وارفع الإيصال — تُفعَّل بعد مراجعة الإدارة</div>

          <!-- NJAZ_BINANCE_PAY_UI_GATE:<?= !empty($binancePayEnabled) ? '1' : '0' ?> -->
          <!-- ── Binance Pay مستقل عن USDT on-chain ── -->
          <?php if(!empty($binancePayEnabled)): ?>
          <div id="methodsList-binance" style="display:none;padding:0 16px 16px">
            <div style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:2px 0 10px;border-bottom:1px solid var(--border)">
              <button type="button" onclick="closeBinanceDetails()" style="width:36px;height:36px;border-radius:11px;border:1px solid var(--border);background:var(--card2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:var(--font)">
                <i class="fas fa-arrow-right"></i>
              </button>
              <div style="flex:1;text-align:right">
                <div style="font-size:.95rem;font-weight:900;color:#f6c11a">مباشر Binance</div>
                <div style="font-size:.72rem;color:var(--text3);margin-top:2px">تفاصيل الإيداع والتحقق</div>
              </div>
            </div>
            <div style="background:linear-gradient(135deg,rgba(246,193,26,.16),rgba(30,111,255,.10));border:1px solid rgba(246,193,26,.38);border-radius:18px;padding:16px;text-align:center;margin-bottom:14px">
              <div style="width:58px;height:58px;border-radius:17px;background:rgba(246,193,26,.16);border:1px solid rgba(246,193,26,.35);display:flex;align-items:center;justify-content:center;overflow:hidden;margin:0 auto 7px">
                <?php if (!empty($binancePayIconUrl)): ?><img src="<?=htmlspecialchars($binancePayIconUrl)?>" alt="Binance Pay" style="max-width:100%;max-height:100%;object-fit:contain"><?php else: ?><span style="font-size:2.1rem;color:#f6c11a">◈</span><?php endif; ?>
              </div>
              <div style="font-weight:900;font-size:1.05rem;color:#f6c11a">إيداع مباشر عبر Binance Pay</div>
              <div style="font-size:.78rem;color:var(--text2);line-height:1.8;margin-top:6px">أنشئ طلباً، حوّل المبلغ الموضح عبر Binance Pay، ثم أدخل transactionId أو orderId كما يظهر في تفاصيل التحويل للتحقق الآلي.</div>
            </div>
            <div id="binanceCreateBox">
              <label style="display:block;color:var(--text2);font-size:.82rem;font-weight:800;margin-bottom:6px">المبلغ المطلوب (USDT)</label>
              <input type="number" id="binanceAmountInput" min="<?=htmlspecialchars((string)$binancePaySettings['minimum_amount'])?>" step="0.01" placeholder="<?=htmlspecialchars((string)$binancePaySettings['minimum_amount'])?>"
                     style="width:100%;box-sizing:border-box;background:var(--bg3);border:2px solid var(--border2);border-radius:14px;padding:13px;color:var(--text);font-family:var(--font);font-size:1.1rem;font-weight:900;direction:ltr;text-align:center;outline:none">
              <button type="button" id="binanceCreateBtn" onclick="binanceCreateDeposit()" style="width:100%;margin-top:10px;padding:14px;background:linear-gradient(135deg,#f6c11a,#e59b00);border:0;border-radius:14px;color:#111;font-family:var(--font);font-size:1rem;font-weight:900;cursor:pointer">إنشاء طلب الإيداع</button>
            </div>
            <div id="binanceRequestBox" style="display:none">
              <div style="background:var(--bg3);border:1px solid var(--border2);border-radius:14px;padding:13px;margin-bottom:12px;line-height:2;text-align:right">
                <div style="font-size:.82rem;color:var(--text2)">حوّل بالضبط: <strong id="binanceExpectedAmount" style="color:#f6c11a"></strong> USDT</div>
                <div style="font-size:.82rem;color:var(--text2)">إلى حساب الموقع: <strong id="binanceReceiver" style="color:var(--text)"></strong></div>
                <div style="font-size:.74rem;color:var(--text3)">صلاحية الطلب: <?= (int)$binancePaySettings['request_ttl_minutes'] ?> دقيقة. لا تستخدم عنوان شبكة أو عقداً ذكياً؛ هذا تحويل Binance Pay فقط.</div>
              </div>
              <label style="display:block;color:var(--text2);font-size:.82rem;font-weight:800;margin-bottom:6px">معرّف العملية أو الطلب في Binance</label>
              <input type="text" id="binanceTransactionInput" maxlength="128" autocomplete="off" placeholder="أدخل transactionId أو orderId كما يظهر في تفاصيل التحويل"
                     style="width:100%;box-sizing:border-box;background:var(--bg3);border:2px solid var(--border2);border-radius:14px;padding:13px;color:var(--text);font-family:var(--font);font-size:1rem;direction:ltr;text-align:center;outline:none">
              <button type="button" id="binanceVerifyBtn" onclick="binanceVerifyDeposit()" style="width:100%;margin-top:10px;padding:14px;background:linear-gradient(135deg,#00c853,#009624);border:0;border-radius:14px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:900;cursor:pointer">تحقق من العملية وإضافة الرصيد</button>
              <button type="button" onclick="binanceResetDeposit()" style="width:100%;margin-top:8px;padding:10px;background:transparent;border:1px solid var(--border2);border-radius:12px;color:var(--text2);font-family:var(--font);cursor:pointer">إنشاء طلب آخر</button>
            </div>
            <div id="binanceMessage" style="min-height:22px;text-align:center;font-size:.82rem;margin-top:12px"></div>
            <div id="binanceHistory" style="margin-top:14px"></div>
          </div>
          <?php endif; ?>

          <!-- بكود -->
          <div id="methodsList-card" style="display:none;padding:16px">
            <div style="text-align:center;margin-bottom:16px">
              <div style="font-size:2.5rem;margin-bottom:8px">🎫</div>
              <div style="font-weight:800;font-size:1rem">شحن بكود البطاقة</div>
              <div style="font-size:.8rem;color:var(--text3);margin-top:4px">أدخل الكود لشحن رصيدك فوراً</div>
            </div>
            <input type="text" id="sheetCardCode"
                   placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX"
                   style="width:100%;background:var(--bg3);border:2px solid var(--border2);border-radius:14px;padding:14px;color:var(--text);font-family:'Courier New',monospace;font-size:1rem;font-weight:900;letter-spacing:2px;text-align:center;outline:none;text-transform:uppercase;margin-bottom:10px;box-sizing:border-box"
                   oninput="formatSheetCode(this)"
                   onfocus="this.style.borderColor='var(--primary)'"
                   onblur="this.style.borderColor='var(--border2)'"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();redeemSheetCard()}">
            <div id="sheetCardMsg" style="text-align:center;font-size:.83rem;margin-bottom:10px;min-height:18px"></div>
            <button onclick="redeemSheetCard()" id="sheetCardBtn"
                    style="width:100%;padding:14px;background:linear-gradient(135deg,#00c853,#00a844);border:none;border-radius:14px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(0,200,83,.25)">
              <i class="fas fa-bolt"></i> شحن الرصيد
            </button>
          </div>


          <!-- ── مباشر 2 شحن الرصيد ── -->
          <?php if(!empty($smsTopupEnabled)):
            // إضافة عمودي بيانات التحويل إن لم يكونا موجودين
            try {
              $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS transfer_account VARCHAR(100) DEFAULT '' AFTER sort_order");
              $pdo->exec("ALTER TABLE sms_providers ADD COLUMN IF NOT EXISTS account_holder VARCHAR(100) DEFAULT '' AFTER transfer_account");
            } catch(Exception $e) {}
          ?>
          <div id="methodsList-sms" style="display:none;padding:16px">

            <!-- اختيار المزود -->
            <div style="margin-bottom:14px">
              <?php if($hasRegions): ?>
              <!-- تبويبات المنطقة -->
              <div id="smsRegionTabs" style="display:flex;gap:6px;margin-bottom:12px">
                <?php if(!empty($smsNorth)): ?>
                <button id="smsTabNorth" onclick="switchSmsRegion('north')"
                        style="flex:1;padding:9px 6px;border-radius:12px;border:2px solid #00c853;background:#00c85318;color:#00c853;font-family:var(--font);font-size:.85rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s">
                  <span style="font-size:1rem">🟢</span> شمال اليمن
                </button>
                <?php endif; ?>
                <?php if(!empty($smsSouth)): ?>
                <button id="smsTabSouth" onclick="switchSmsRegion('south')"
                        style="flex:1;padding:9px 6px;border-radius:12px;border:2px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:.85rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s">
                  <span style="font-size:1rem">🔵</span> جنوب اليمن
                </button>
                <?php endif; ?>
              </div>

              <!-- مزودو الشمال -->
              <?php if(!empty($smsNorth)): ?>
              <div id="smsProviders-north" style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach($smsNorth as $idx_p => $sp):
                  $spColor   = htmlspecialchars($sp['color']);
                  $spIsFirst = $idx_p === 0;
                  $spHasLogo = !empty($sp['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($sp['logo'],'/'));
                ?>
                <button class="sms-provider-btn <?=$spIsFirst?'active':''?>"
                        data-id="<?=$sp['id']?>"
                        data-currency="<?=htmlspecialchars($sp['currency'])?>"
                        data-name="<?=htmlspecialchars($sp['name'])?>"
                        data-color="<?=$spColor?>"
                        data-account="<?=htmlspecialchars($sp['transfer_account']??'')?>"
                        data-account-name="<?=htmlspecialchars($sp['account_holder']??'')?>" data-ptype="<?=htmlspecialchars($sp['provider_type']??'sms')?>" data-wallet="<?=htmlspecialchars($sp['usdt_wallet_address']??'')?>"
                        data-rate="<?=htmlspecialchars($sp['rate_to_usd']??'0')?>"
                        onclick="selectSmsProvider(this)"
                        style="padding:6px 12px 6px 10px;border-radius:14px;border:2px solid <?=$spIsFirst?$spColor:'var(--border)'?>;background:<?=$spIsFirst?$spColor.'18':'transparent'?>;color:<?=$spIsFirst?$spColor:'var(--text2)'?>;font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s">
                  <?php if($spHasLogo): ?>
                    <img src="<?=SITE_URL?>/<?=htmlspecialchars($sp['logo'])?>" alt="<?=htmlspecialchars($sp['name'])?>" style="width:28px;height:28px;object-fit:contain;border-radius:6px;flex-shrink:0">
                  <?php else: ?>
                    <span style="width:28px;height:28px;border-radius:8px;background:<?=$spColor?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fas fa-<?=htmlspecialchars($sp['icon'])?>" style="color:<?=$spColor?>;font-size:.8rem"></i>
                    </span>
                  <?php endif; ?>
                  <?=htmlspecialchars($sp['name'])?>
                </button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- مزودو الجنوب -->
              <?php if(!empty($smsSouth)): ?>
              <div id="smsProviders-south" style="display:none;gap:8px;flex-wrap:wrap">
                <?php foreach($smsSouth as $idx_p => $sp):
                  $spColor   = htmlspecialchars($sp['color']);
                  $spIsFirst = $idx_p === 0;
                  $spHasLogo = !empty($sp['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($sp['logo'],'/'));
                ?>
                <button class="sms-provider-btn"
                        data-id="<?=$sp['id']?>"
                        data-currency="<?=htmlspecialchars($sp['currency'])?>"
                        data-name="<?=htmlspecialchars($sp['name'])?>"
                        data-color="<?=$spColor?>"
                        data-account="<?=htmlspecialchars($sp['transfer_account']??'')?>"
                        data-account-name="<?=htmlspecialchars($sp['account_holder']??'')?>" data-ptype="<?=htmlspecialchars($sp['provider_type']??'sms')?>" data-wallet="<?=htmlspecialchars($sp['usdt_wallet_address']??'')?>"
                        data-rate="<?=htmlspecialchars($sp['rate_to_usd']??'0')?>"
                        onclick="selectSmsProvider(this)"
                        style="padding:6px 12px 6px 10px;border-radius:14px;border:2px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s">
                  <?php if($spHasLogo): ?>
                    <img src="<?=SITE_URL?>/<?=htmlspecialchars($sp['logo'])?>" alt="<?=htmlspecialchars($sp['name'])?>" style="width:28px;height:28px;object-fit:contain;border-radius:6px;flex-shrink:0">
                  <?php else: ?>
                    <span style="width:28px;height:28px;border-radius:8px;background:<?=$spColor?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fas fa-<?=htmlspecialchars($sp['icon'])?>" style="color:<?=$spColor?>;font-size:.8rem"></i>
                    </span>
                  <?php endif; ?>
                  <?=htmlspecialchars($sp['name'])?>
                </button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- مزودون بدون منطقة -->
              <?php if(!empty($smsNoRegion)): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:<?=($hasRegions && (!empty($smsNorth)||!empty($smsSouth)))?'8px':'0'?>">
                <?php foreach($smsNoRegion as $idx_p => $sp):
                  $spColor   = htmlspecialchars($sp['color']);
                  $spHasLogo = !empty($sp['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($sp['logo'],'/'));
                ?>
                <button class="sms-provider-btn"
                        data-id="<?=$sp['id']?>"
                        data-currency="<?=htmlspecialchars($sp['currency'])?>"
                        data-name="<?=htmlspecialchars($sp['name'])?>"
                        data-color="<?=$spColor?>"
                        data-account="<?=htmlspecialchars($sp['transfer_account']??'')?>"
                        data-account-name="<?=htmlspecialchars($sp['account_holder']??'')?>" data-ptype="<?=htmlspecialchars($sp['provider_type']??'sms')?>" data-wallet="<?=htmlspecialchars($sp['usdt_wallet_address']??'')?>"
                        data-rate="<?=htmlspecialchars($sp['rate_to_usd']??'0')?>"
                        onclick="selectSmsProvider(this)"
                        style="padding:6px 12px 6px 10px;border-radius:14px;border:2px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s">
                  <?php if($spHasLogo): ?>
                    <img src="<?=SITE_URL?>/<?=htmlspecialchars($sp['logo'])?>" alt="<?=htmlspecialchars($sp['name'])?>" style="width:28px;height:28px;object-fit:contain;border-radius:6px;flex-shrink:0">
                  <?php else: ?>
                    <span style="width:28px;height:28px;border-radius:8px;background:<?=$spColor?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fas fa-<?=htmlspecialchars($sp['icon'])?>" style="color:<?=$spColor?>;font-size:.8rem"></i>
                    </span>
                  <?php endif; ?>
                  <?=htmlspecialchars($sp['name'])?>
                </button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <?php else: ?>
              <!-- لا توجد مناطق — عرض عادي -->
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach($smsProviders as $idx_p => $sp):
                  $spColor   = htmlspecialchars($sp['color']);
                  $spIsFirst = $idx_p === 0;
                  $spHasLogo = !empty($sp['logo']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($sp['logo'],'/'));
                ?>
                <button class="sms-provider-btn <?=$spIsFirst?'active':''?>"
                        data-id="<?=$sp['id']?>"
                        data-currency="<?=htmlspecialchars($sp['currency'])?>"
                        data-name="<?=htmlspecialchars($sp['name'])?>"
                        data-color="<?=$spColor?>"
                        data-account="<?=htmlspecialchars($sp['transfer_account']??'')?>"
                        data-account-name="<?=htmlspecialchars($sp['account_holder']??'')?>" data-ptype="<?=htmlspecialchars($sp['provider_type']??'sms')?>" data-wallet="<?=htmlspecialchars($sp['usdt_wallet_address']??'')?>"
                        data-rate="<?=htmlspecialchars($sp['rate_to_usd']??'0')?>"
                        onclick="selectSmsProvider(this)"
                        style="padding:6px 12px 6px 10px;border-radius:14px;border:2px solid <?=$spIsFirst?$spColor:'var(--border)'?>;background:<?=$spIsFirst?$spColor.'18':'transparent'?>;color:<?=$spIsFirst?$spColor:'var(--text2)'?>;font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;transition:all .2s">
                  <?php if($spHasLogo): ?>
                    <img src="<?=SITE_URL?>/<?=htmlspecialchars($sp['logo'])?>" alt="<?=htmlspecialchars($sp['name'])?>" style="width:28px;height:28px;object-fit:contain;border-radius:6px;flex-shrink:0">
                  <?php else: ?>
                    <span style="width:28px;height:28px;border-radius:8px;background:<?=$spColor?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                      <i class="fas fa-<?=htmlspecialchars($sp['icon'])?>" style="color:<?=$spColor?>;font-size:.8rem"></i>
                    </span>
                  <?php endif; ?>
                  <?=htmlspecialchars($sp['name'])?>
                </button>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </div>

            <!-- حقول الإدخال -->
            <div style="margin-bottom:12px">
              <label style="font-size:.8rem;color:var(--text2);font-weight:700;display:block;margin-bottom:8px">رقم الهاتف الذي حولت منه</label>
              <input type="tel" id="smsPhoneInput" placeholder="7XXXXXXXX"
                     style="width:100%;background:var(--card);border:2px solid var(--primary);border-radius:14px;padding:14px;color:var(--text);font-family:var(--font);font-size:1rem;font-weight:700;text-align:center;outline:none;box-sizing:border-box;direction:ltr;box-shadow:0 0 0 4px rgba(30,111,255,0.10)"
                     oninput="this.value=this.value.replace(/[^0-9]/,'')"
                     onfocus="this.style.borderColor='var(--cyan)';this.style.boxShadow='0 0 0 4px rgba(0,212,255,0.18)'"
                     onblur="this.style.borderColor='var(--primary)';this.style.boxShadow='0 0 0 4px rgba(30,111,255,0.10)'">
            </div>
            <div style="margin-bottom:14px">
              <label style="font-size:.8rem;color:var(--text2);font-weight:700;display:block;margin-bottom:8px">المبلغ المحوَّل <span id="smsCurrencyLabel" style="color:var(--primary)">YER</span></label>
              <input type="number" id="smsAmountInput" placeholder="5000"
                     style="width:100%;background:var(--card);border:2px solid var(--primary);border-radius:14px;padding:14px;color:var(--text);font-family:var(--font);font-size:1.1rem;font-weight:900;text-align:center;outline:none;box-sizing:border-box;box-shadow:0 0 0 4px rgba(30,111,255,0.10)"
                     min="1" step="1"
                     oninput="calcSmsCredit()"
                     onfocus="this.style.borderColor='var(--cyan)';this.style.boxShadow='0 0 0 4px rgba(0,212,255,0.18)'"
                     onblur="this.style.borderColor='var(--primary)';this.style.boxShadow='0 0 0 4px rgba(30,111,255,0.10)'">
            </div>

            <!-- حقل هاتف SMS — مخفي للـ USDT -->
            <!-- (موجود فوق في الكود) -->

            <!-- ── USDT: معلومات التحويل ── -->
            <div id="usdtTransferBox" style="display:none;margin-bottom:14px">
              <div style="background:rgba(245,166,35,.07);border:1px solid rgba(245,166,35,.25);border-radius:14px;padding:14px">
                <div style="font-size:.78rem;color:var(--text3);margin-bottom:10px;text-align:center">أرسل المبلغ التالي بالضبط إلى هذا العنوان</div>

                <!-- العنوان -->
                <div style="background:var(--bg3);border-radius:10px;padding:10px 12px;margin-bottom:10px;position:relative">
                  <div style="font-size:.7rem;color:var(--text3);margin-bottom:3px">عنوان المحفظة</div>
                  <div id="usdtWalletDisplay" style="font-size:.78rem;font-family:monospace;color:var(--text);word-break:break-all;direction:ltr">—</div>
                  <button onclick="copyUsdtWallet()" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text3);font-size:16px">⎘</button>
                </div>

                <!-- المبلغ الفريد -->
                <div style="display:flex;justify-content:space-between;align-items:center;background:var(--bg3);border-radius:10px;padding:10px 14px;margin-bottom:10px">
                  <div>
                    <div style="font-size:.7rem;color:var(--text3)">المبلغ المطلوب (بالضبط)</div>
                    <div id="usdtUniqueAmount" style="font-size:1.15rem;font-weight:900;color:#f5a623">—</div>
                  </div>
                  <button onclick="copyUsdtAmount()" style="background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);border-radius:8px;padding:6px 12px;color:#f5a623;cursor:pointer;font-size:.8rem;font-weight:700;font-family:var(--font)">نسخ</button>
                </div>

                <!-- المبلغ الذي سيُضاف -->
                <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 2px">
                  <div style="font-size:.78rem;color:var(--text3)">الذي سيُضاف لحسابك</div>
                  <div id="usdtCreditAmount" style="font-size:.9rem;font-weight:800;color:#00d4aa">—</div>
                </div>

                <!-- المؤقت -->
                <div style="margin-top:12px;text-align:center">
                  <div style="font-size:.75rem;color:var(--text3);margin-bottom:6px">ينتهي الطلب خلال</div>
                  <div id="usdtTimer" style="font-size:1.6rem;font-weight:900;color:var(--primary);font-variant-numeric:tabular-nums">20:00</div>
                </div>
              </div>

              <!-- حقل txID -->
              <div style="margin-top:12px">
                <label style="font-size:.8rem;color:var(--text2);font-weight:700;display:block;margin-bottom:6px">رقم العملية (txID) من محفظتك</label>
                <input type="text" id="usdtTxId" placeholder="0x..." dir="ltr"
                       style="width:100%;background:var(--card);border:2px solid var(--primary);border-radius:14px;padding:13px;color:var(--text);font-family:monospace;font-size:.9rem;outline:none;box-sizing:border-box"
                       onfocus="this.style.borderColor='var(--cyan)'"
                       onblur="this.style.borderColor='var(--primary)'">
              </div>
            </div>

            <!-- بطاقة معاينة الرصيد المضاف -->
            <div id="smsCreditPreviewBox" class="topup-result-card" style="display:none;margin-bottom:14px">
              <div class="topup-result-row">
                <span class="topup-result-lbl">سيُضاف لرصيدك</span>
                <div class="topup-result-val" id="smsCreditUSD">$0.0000</div>
              </div>
              <div class="topup-result-bar">
                <div class="topup-result-bar-fill" id="smsCreditBar" style="width:0%"></div>
              </div>
            </div>

            <div id="smsFormMsg" style="text-align:center;font-size:.83rem;margin-bottom:10px;min-height:18px"></div>

            <button onclick="submitSmsTopup()" id="smsSubmitBtn"
                    style="width:100%;padding:14px;background:linear-gradient(135deg,#1e6fff,#7c3aed);border:none;border-radius:14px;color:#fff;font-family:var(--font);font-size:1rem;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(30,111,255,.3)">
              <i class="fas fa-search-dollar" id="smsSubmitIcon"></i> <span id="smsSubmitLbl">تحقق وشحن الرصيد</span>
            </button>

            <div style="margin-top:14px;background:rgba(30,111,255,.06);border:1px solid rgba(30,111,255,.15);border-radius:12px;padding:12px;font-size:.78rem;color:var(--text3);line-height:1.7">
              <i class="fas fa-info-circle" style="color:var(--primary)"></i>
              أرسل تحويلاً أو إيداعاً من محفظتك أولاً، ثم أدخل الرقم والمبلغ وانقر تحقق.
              سيتم البحث عن العملية تلقائياً وإضافة رصيدك خلال ثوانٍ.
            </div>

            <!-- حالة التحقق -->
            <div id="smsCheckStatus" style="display:none;margin-top:14px">
              <div style="background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center">
                <div id="smsCheckIcon" style="font-size:2.5rem;margin-bottom:8px">⏳</div>
                <div id="smsCheckTitle" style="font-weight:800;font-size:1rem;margin-bottom:4px">جارٍ البحث عن العملية...</div>
                <div id="smsCheckSub" style="font-size:.8rem;color:var(--text3)">يتم البحث تلقائياً كل 5 ثوانٍ</div>
                <div id="smsCountdown" style="margin-top:10px;font-size:.75rem;color:var(--primary)"></div>
                <button onclick="cancelSmsCheck()" style="margin-top:12px;padding:7px 18px;border-radius:10px;border:1px solid var(--border);background:none;color:var(--text3);cursor:pointer;font-family:var(--font);font-size:.8rem">إلغاء</button>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- ╔══════════════════════════════════════════════════╗
               ║      USDT BEP20 — داخل تبويب مباشر             ║
               ╚══════════════════════════════════════════════════╝ -->
          <?php if(!empty($usdtEnabled)): ?>
          <div id="methodsList-usdt" style="display:none;padding:0 16px 16px">

            <!-- رأس تفاصيل USDT: لا تظهر التفاصيل إلا بعد الضغط على زر USDT -->
            <div style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:2px 0 10px;border-bottom:1px solid var(--border)">
              <button type="button" onclick="closeUsdtDetails()" style="width:36px;height:36px;border-radius:11px;border:1px solid var(--border);background:var(--card2);color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:var(--font)">
                <i class="fas fa-arrow-right"></i>
              </button>
              <div style="flex:1;text-align:right">
                <div style="font-size:.95rem;font-weight:900;color:#26a17b">USDT — BEP20</div>
                <div style="font-size:.72rem;color:var(--text3);margin-top:2px">تفاصيل الإيداع والتحقق</div>
              </div>
            </div>

            <!-- ── فورم إدخال المبلغ ── -->
            <div id="usdtFormBox">

              <!-- بانر USDT -->
              <div style="background:linear-gradient(135deg,rgba(38,161,123,.15),rgba(30,111,255,.08));border:1px solid rgba(38,161,123,.28);border-radius:18px;padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
                <div style="width:44px;height:44px;min-width:44px;background:rgba(38,161,123,.18);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;overflow:hidden">
                  <?php if (!empty($usdtImage)): ?>
                    <img src="<?= SITE_URL ?>/<?= htmlspecialchars(ltrim($usdtImage, '/')) ?>" alt="USDT" style="width:100%;height:100%;object-fit:contain;padding:5px">
                  <?php else: ?>
                    <i class="fas fa-<?= htmlspecialchars($usdtIcon) ?>" style="color:#26a17b"></i>
                  <?php endif; ?>
                </div>
                <div style="flex:1">
                  <div style="font-weight:800;font-size:.93rem;color:#26a17b">USDT — BEP20</div>
                  <div style="font-size:.72rem;color:var(--text3);margin-top:2px">BNB Smart Chain · شحن تلقائي فوري ⚡</div>
                </div>
                <div style="text-align:center;flex-shrink:0">
                  <div style="font-size:.65rem;color:var(--text3)">أدنى إيداع</div>
                  <div style="font-size:.88rem;font-weight:800;color:#26a17b"><?= $usdtMinDep ?>$</div>
                </div>
              </div>

              <!-- حقل المبلغ -->
              <div style="margin-bottom:12px">
                <label style="font-size:.78rem;color:var(--text2);font-weight:700;display:block;margin-bottom:7px">المبلغ المطلوب (بالدولار)</label>
                <div style="position:relative">
                  <input type="number" id="usdtAmtInput"
                         placeholder="<?= $usdtMinDep ?>"
                         min="<?= $usdtMinDep ?>" step="0.01"
                         style="width:100%;background:var(--card);border:2px solid rgba(38,161,123,.35);border-radius:14px;padding:13px 55px 13px 14px;color:var(--text);font-family:var(--font);font-size:1.05rem;font-weight:900;text-align:center;outline:none;box-sizing:border-box;direction:ltr"
                         onfocus="this.style.borderColor='#26a17b';this.style.boxShadow='0 0 0 3px rgba(38,161,123,.18)'"
                         onblur="this.style.borderColor='rgba(38,161,123,.35)';this.style.boxShadow='none'"
                         oninput="usdtCalcPreview()">
                  <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);font-weight:800;color:#26a17b;font-size:.85rem;pointer-events:none">USDT</span>
                </div>
              </div>

              <!-- أزرار سريعة -->
              <div style="display:flex;gap:6px;margin-bottom:12px">
                <?php foreach([10,25,50,100,200] as $q): if($q>=$usdtMinDep): ?>
                <button onclick="document.getElementById('usdtAmtInput').value=<?=$q?>;usdtCalcPreview()"
                        style="flex:1;padding:8px 4px;background:rgba(38,161,123,.07);border:1.5px solid rgba(38,161,123,.2);border-radius:11px;color:#26a17b;font-family:var(--font);font-size:.8rem;font-weight:800;cursor:pointer">
                  <?=$q?>$
                </button>
                <?php endif; endforeach; ?>
              </div>

              <!-- معاينة -->
              <div id="usdtPreviewBox" style="display:none;background:rgba(38,161,123,.06);border:1px solid rgba(38,161,123,.15);border-radius:12px;padding:11px 14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center">
                <span style="font-size:.78rem;color:var(--text3)">سيُضاف لرصيدك</span>
                <span id="usdtPreviewCredit" style="font-weight:900;color:#00d4aa;font-size:.95rem">—</span>
              </div>

              <!-- خطأ -->
              <div id="usdtFormError" style="display:none;background:rgba(255,68,68,.08);border:1px solid rgba(255,68,68,.22);border-radius:11px;padding:9px 13px;font-size:.8rem;color:#ff6688;margin-bottom:11px;text-align:center"></div>

              <!-- زر إنشاء طلب -->
              <button id="usdtCreateBtn" onclick="usdtCreateRequest()"
                      style="width:100%;padding:14px;background:linear-gradient(135deg,#26a17b,#1d8f6c);border:none;border-radius:15px;color:#fff;font-family:var(--font);font-size:.95rem;font-weight:800;cursor:pointer;box-shadow:0 5px 20px rgba(38,161,123,.32)">
                <i class="fas fa-paper-plane"></i> إنشاء طلب الإيداع
              </button>

              <div style="margin-top:11px;text-align:center;font-size:.72rem;color:var(--text3)">
                🔒 BEP20 فقط — لا ERC20 ولا TRC20 · رصيد يُضاف تلقائياً بعد التأكيد
              </div>
            </div><!-- /usdtFormBox -->

            <!-- ── الطلب النشط ── -->
            <div id="usdtActiveBox" style="display:none">

              <!-- عداد -->
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:13px">
                <span style="font-size:.78rem;color:var(--text3)">⏱ ينتهي خلال</span>
                <span id="usdtCountdown" style="font-size:1.1rem;font-weight:900;color:#f5a623;font-variant-numeric:tabular-nums">--:--</span>
              </div>

              <!-- المبلغ الفريد -->
              <div style="background:linear-gradient(135deg,rgba(38,161,123,.14),rgba(38,161,123,.05));border:2px solid rgba(38,161,123,.32);border-radius:18px;padding:18px;text-align:center;margin-bottom:13px">
                <div style="font-size:.72rem;color:var(--text3);margin-bottom:3px">⬇️ أرسل هذا المبلغ تحديداً</div>
                <div id="usdtDispAmount" style="font-size:2rem;font-weight:900;color:#26a17b;font-family:monospace;letter-spacing:-1px">—</div>
                <div style="font-size:.82rem;color:var(--text2);font-weight:700;margin-top:1px">USDT</div>
                <button onclick="usdtCopy('usdtDispAmount','usdtCopyAmtLbl')"
                        style="margin-top:10px;padding:7px 20px;background:rgba(38,161,123,.14);border:1px solid rgba(38,161,123,.28);border-radius:9px;color:#26a17b;font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer">
                  <span id="usdtCopyAmtLbl">⎘ نسخ المبلغ</span>
                </button>
              </div>

              <!-- المحفظة -->
              <div style="background:var(--card);border:1px solid var(--border);border-radius:14px;padding:13px;margin-bottom:13px">
                <div style="font-size:.7rem;color:var(--text3);margin-bottom:5px;font-weight:700">🏦 عنوان المحفظة (BEP20 فقط)</div>
                <div id="usdtDispWallet" style="font-size:.75rem;font-family:monospace;color:var(--text);word-break:break-all;direction:ltr;line-height:1.6;margin-bottom:9px"><?= htmlspecialchars($usdtWalletAddr) ?></div>
                <button onclick="usdtCopy('usdtDispWallet','usdtCopyWltLbl')"
                        style="width:100%;padding:8px;background:rgba(30,111,255,.07);border:1px solid rgba(30,111,255,.18);border-radius:9px;color:var(--primary);font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer">
                  <span id="usdtCopyWltLbl">⎘ نسخ العنوان</span>
                </button>
              </div>

              <!-- QR -->
              <?php if(!empty($usdtWalletAddr)): ?>
              <div style="text-align:center;margin-bottom:13px">
                <img src="https://chart.googleapis.com/chart?chs=170x170&cht=qr&chl=<?= urlencode($usdtWalletAddr) ?>"
                     style="width:120px;height:120px;border-radius:12px;background:#fff;padding:7px;box-shadow:0 3px 14px rgba(0,0,0,.28)">
              </div>
              <?php endif; ?>

              <!-- تفاصيل الشبكة -->
              <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:11px;padding:9px 12px;margin-bottom:12px;font-size:.72rem;color:var(--text3);line-height:1.9">
                <div>🔗 الشبكة: <span style="color:var(--text);font-weight:700">BNB Smart Chain (BEP20)</span></div>
                <div>✅ التأكيدات المطلوبة: <span style="color:#00d4aa;font-weight:700"><?= $usdtMinConf ?>+</span></div>
              </div>

              <!-- تحذيرات -->
              <div style="background:rgba(255,68,68,.05);border:1px solid rgba(255,68,68,.14);border-radius:11px;padding:9px 12px;margin-bottom:13px;font-size:.72rem;color:#ff8899;line-height:1.9">
                ⚠️ أرسل <strong>المبلغ الدقيق</strong> المذكور — مبلغ مختلف لن يُعالَج<br>
                🚫 لا ترسل من شبكة ERC20 أو TRC20<br>
                ⏳ الرصيد يُضاف تلقائياً بعد التأكيد
              </div>

              <!-- ── حقل txID ── -->
              <div style="margin-bottom:13px">
                <label style="font-size:.8rem;color:var(--text2);font-weight:700;display:block;margin-bottom:7px">
                  🔗 رقم العملية (txID) من محفظتك
                </label>
                <input type="text" id="usdtNewTxId" placeholder="0x..." dir="ltr"
                       style="width:100%;background:var(--card);border:2px solid rgba(38,161,123,.35);border-radius:14px;padding:13px;color:var(--text);font-family:monospace;font-size:.85rem;outline:none;box-sizing:border-box"
                       onfocus="this.style.borderColor='#26a17b';this.style.boxShadow='0 0 0 3px rgba(38,161,123,.18)'"
                       onblur="this.style.borderColor='rgba(38,161,123,.35)';this.style.boxShadow='none'"
                       oninput="document.getElementById('usdtTxError').style.display='none'">
                <div id="usdtTxError" style="display:none;margin-top:6px;padding:8px 12px;background:rgba(255,68,68,.08);border:1px solid rgba(255,68,68,.22);border-radius:10px;font-size:.78rem;color:#ff6688;text-align:center"></div>
              </div>

              <!-- زر إرسال txID والتحقق -->
              <button id="usdtSubmitTxBtn" onclick="usdtSubmitTx()"
                      style="width:100%;padding:14px;background:linear-gradient(135deg,#26a17b,#1d8f6c);border:none;border-radius:15px;color:#fff;font-family:var(--font);font-weight:800;font-size:.95rem;cursor:pointer;box-shadow:0 5px 20px rgba(38,161,123,.32);margin-bottom:10px">
                <i class="fas fa-check-circle"></i> تحقق وأضف رصيدي
              </button>

              <!-- نتيجة الفحص -->
              <div id="usdtStatusBox" style="display:none;text-align:center;padding:12px;background:rgba(0,214,170,.05);border:1px solid rgba(0,214,170,.18);border-radius:13px;margin-bottom:10px">
                <div id="usdtStatusIcon" style="font-size:1.6rem;margin-bottom:5px">⏳</div>
                <div id="usdtStatusMsg" style="font-size:.83rem;font-weight:700;color:var(--text)"></div>
              </div>

              <!-- إلغاء -->
              <button onclick="usdtResetForm()"
                      style="width:100%;padding:10px;background:transparent;border:1px solid var(--border);border-radius:13px;color:var(--text3);font-family:var(--font);font-size:.8rem;cursor:pointer">
                ← إلغاء وإنشاء طلب جديد
              </button>
            </div><!-- /usdtActiveBox -->

            <!-- ── نجاح الإيداع ── -->
            <div id="usdtSuccessBox" style="display:none;text-align:center;padding:24px 0">
              <div style="font-size:3.2rem;margin-bottom:10px">✅</div>
              <div style="font-size:1.1rem;font-weight:900;color:#00e676;margin-bottom:7px">تم إضافة رصيدك!</div>
              <div id="usdtSuccessMsg" style="font-size:.83rem;color:var(--text2);line-height:1.7;margin-bottom:18px"></div>
              <button onclick="location.reload()"
                      style="padding:12px 32px;background:linear-gradient(135deg,#00c853,#00a844);border:none;border-radius:15px;color:#fff;font-family:var(--font);font-weight:800;font-size:.93rem;cursor:pointer;box-shadow:0 5px 18px rgba(0,200,83,.28)">
                💰 عرض رصيدي
              </button>
            </div>

            <!-- بيانات للـ JS -->
            <script>
            var USDT_WALLET  = <?= json_encode($usdtWalletAddr) ?>;
            var USDT_MIN_DEP = <?= (float)$usdtMinDep ?>;
            var USDT_TTL_MIN = <?= (int)$usdtTtlMin ?>;
            var USDT_MIN_CONF= <?= (int)$usdtMinConf ?>;
            var USDT_ACTIVE  = <?= $activeUsdtRequest
              ? json_encode(['id'=>(int)$activeUsdtRequest['id'],'unique_amount'=>rtrim(rtrim((string)$activeUsdtRequest['unique_amount'],'0'),'.'),'expires_at'=>$activeUsdtRequest['expires_at'],'wallet_address'=>$usdtWalletAddr])
              : 'null' ?>;
            </script>

          </div><!-- /methodsList-usdt -->
          <?php endif; ?>

          <!-- يدوي -->
          <div class="topup-methods-list" id="methodsList-manual">
            <?php if(empty($manualMethods)): ?>
            <div class="topup-empty"><i class="fas fa-credit-card"></i><p>لا توجد طرق دفع يدوية</p></div>
            <?php else: foreach($manualMethods as $pm): ?>
            <div class="topup-method-item" onclick="selectMethod(<?=$pm['id']?>,this)" data-method-id="<?=$pm['id']?>" data-mode="manual">
              <div class="topup-method-thumb" style="--mc:<?=htmlspecialchars($pm['color'])?>">
                <?php if(!empty($pm['image'])): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars($pm['image'])?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
                <?php else: ?>
                <i class="fas fa-<?=htmlspecialchars($pm['icon'])?>" style="color:<?=htmlspecialchars($pm['color'])?>"></i>
                <?php endif; ?>
              </div>
              <div class="topup-method-info">
                <div class="topup-method-name"><?=htmlspecialchars($pm['name'])?></div>
                <?php if($pm['description']): ?>
                <div class="topup-method-desc"><?=htmlspecialchars(mb_substr($pm['description'],0,50))?></div>
                <?php endif; ?>
              </div>
              <div class="topup-method-arrow"><i class="fas fa-chevron-left"></i></div>
            </div>
            <?php endforeach; endif; ?>
          </div>

          <!-- مباشر (طرق auto + فلوسك + USDT BEP20) -->
          <div class="topup-methods-list" id="methodsList-auto" style="display:none">
            <?php if(empty($autoMethods) && !$floosakEnabled && empty($usdtEnabled) && empty($binancePayEnabled)): ?>
            <div class="topup-empty"><i class="fas fa-bolt"></i><p>لا توجد طرق دفع مباشرة</p></div>
            <?php else: ?>
            <?php foreach($autoMethods as $pm): ?>
            <div class="topup-method-item" onclick="selectMethod(<?=$pm['id']?>,this)" data-method-id="<?=$pm['id']?>" data-mode="auto">
              <div class="topup-method-thumb" style="--mc:<?=htmlspecialchars($pm['color'])?>">
                <?php if(!empty($pm['image'])): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars($pm['image'])?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
                <?php else: ?>
                <i class="fas fa-<?=htmlspecialchars($pm['icon'])?>" style="color:<?=htmlspecialchars($pm['color'])?>"></i>
                <?php endif; ?>
              </div>
              <div class="topup-method-info">
                <div class="topup-method-name"><?=htmlspecialchars($pm['name'])?></div>
                <?php if($pm['description']): ?>
                <div class="topup-method-desc"><?=htmlspecialchars(mb_substr($pm['description'],0,50))?></div>
                <?php endif; ?>
              </div>
              <div class="topup-method-arrow"><i class="fas fa-bolt" style="color:var(--cyan);font-size:11px"></i></div>
            </div>
            <?php endforeach; ?>
            <?php if($floosakEnabled): ?>
            <div class="topup-method-item" onclick="openFloosakStep()" style="background:linear-gradient(135deg,rgba(0,212,170,.08),rgba(30,111,255,.08));border-color:rgba(0,212,170,.3)">
              <div class="topup-method-thumb" style="--mc:#00d4aa;background:rgba(0,212,170,.15)">
                <?php if($floosakImage): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars($floosakImage)?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
                <?php else: ?>
                <span style="font-size:1.4rem">💳</span>
                <?php endif; ?>
              </div>
              <div class="topup-method-info">
                <div class="topup-method-name" style="color:#00d4aa">فلوسك</div>
                <div class="topup-method-desc">ادفع من محفظة فلوسك — فوري 100%</div>
              </div>
              <div class="topup-method-arrow"><i class="fas fa-chevron-left" style="color:#00d4aa"></i></div>
            </div>
            <?php endif; ?>
            <?php if(!empty($usdtEnabled)): ?>
            <div class="topup-method-item" onclick="openUsdtDetails()" style="background:linear-gradient(135deg,rgba(38,161,123,.09),rgba(30,111,255,.07));border-color:rgba(38,161,123,.32)">
              <div class="topup-method-thumb" style="--mc:#26a17b;background:rgba(38,161,123,.16);overflow:hidden">
                <?php if(!empty($usdtImage)): ?>
                <img src="<?=SITE_URL?>/<?=htmlspecialchars(ltrim($usdtImage,'/'))?>" alt="USDT" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
                <?php else: ?>
                <i class="fas fa-<?=htmlspecialchars($usdtIcon)?>" style="color:#26a17b"></i>
                <?php endif; ?>
              </div>
              <div class="topup-method-info">
                <div class="topup-method-name" style="color:#26a17b">USDT — BEP20</div>
                <div class="topup-method-desc">إيداع تلقائي عبر شبكة BNB Smart Chain</div>
              </div>
              <div class="topup-method-arrow"><i class="fas fa-chevron-left" style="color:#26a17b"></i></div>
            </div>
            <?php endif; ?>
            <?php if(!empty($binancePayEnabled)): ?>
            <div class="topup-method-item" onclick="openBinanceDetails()" style="background:linear-gradient(135deg,rgba(246,193,26,.11),rgba(30,111,255,.07));border-color:rgba(246,193,26,.34)">
              <div class="topup-method-thumb" style="--mc:#f6c11a;background:rgba(246,193,26,.16);overflow:hidden">
                <?php if (!empty($binancePayIconUrl)): ?>
                <img src="<?=htmlspecialchars($binancePayIconUrl)?>" alt="Binance Pay" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
                <?php else: ?>
                <span style="font-size:1.45rem;color:#f6c11a">◈</span>
                <?php endif; ?>
              </div>
              <div class="topup-method-info">
                <div class="topup-method-name" style="color:#f6c11a">مباشر Binance</div>
                <div class="topup-method-desc">إيداع USDT عبر Binance Pay</div>
              </div>
              <div class="topup-method-arrow"><i class="fas fa-chevron-left" style="color:#f6c11a"></i></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── Step 2: تفاصيل الدفع ── -->
        <div id="topupStep2" style="display:none">
          <div class="topup-header">
            <button class="topup-back-btn" onclick="backToStep1()"><i class="fas fa-arrow-right"></i></button>
            <div style="flex:1;text-align:center">
              <div class="topup-header-title" id="step2Title">تفاصيل الإيداع</div>
              <div class="topup-header-sub" id="step2Sub">اتبع التعليمات أدناه</div>
            </div>
          </div>

          <!-- بطاقة بيانات الحساب -->
          <div class="topup-account-card" id="topupAccountCard">
            <div class="topup-account-header">
              <div class="topup-account-icon" id="topupAccountIcon"><i class="fas fa-university"></i></div>
              <div class="topup-account-title" id="topupAccountTitle">بيانات الحساب</div>
            </div>
            <div id="methodFields" class="topup-fields-list"></div>
          </div>

          <!-- حساب المبلغ -->
          <div class="topup-calc-section">
            <div class="topup-section-label"><i class="fas fa-calculator"></i> المبلغ وحساب الرصيد</div>

            <!-- اختيار العملة -->
            <div class="topup-currency-row" id="currencyOptions">
              <?php foreach($exchangeRates as $er): ?>
              <div class="topup-currency-chip <?=$er['currency_code']==='USD'?'active':''?>"
                   onclick="selectCurrency('<?=$er['currency_code']?>','<?=$er['currency_symbol']?>',<?=$er['rate_to_usd']?>,this)">
                <div class="topup-chip-symbol"><?=htmlspecialchars($er['currency_symbol'])?></div>
                <div class="topup-chip-code"><?=htmlspecialchars($er['currency_code'])?></div>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- مدخل المبلغ -->
            <div class="topup-amount-wrap">
              <div class="topup-amount-currency" id="topupCurrencyLabel">USD</div>
              <input type="number" class="topup-amount-field" id="topupAmountInput"
                     placeholder="0.00" min="0.01" step="any" oninput="calcTopupUSD()">
            </div>

            <!-- بطاقة النتيجة -->
            <div class="topup-result-card" id="topupResultBox">
              <div class="topup-result-row">
                <span class="topup-result-lbl">سيُضاف لرصيدك</span>
                <div class="topup-result-val" id="topupUSDAmount">$0.0000</div>
              </div>
              <div class="topup-result-bar">
                <div class="topup-result-bar-fill" id="topupResultBar"></div>
              </div>
            </div>
          </div>

          <!-- رفع الإيصال -->
          <div class="topup-upload-section">
            <div class="topup-section-label"><i class="fas fa-receipt"></i> إيصال الدفع</div>
            <label class="topup-upload-zone" for="receiptFileInput" id="receiptLabel">
              <div class="topup-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
              <div class="topup-upload-text">اضغط لرفع الإيصال</div>
              <div class="topup-upload-hint">صورة أو PDF — حد أقصى 5MB</div>
            </label>
            <img id="receiptPreviewImg" class="topup-receipt-preview" src="" style="display:none">
          </div>

          <!-- ملاحظة وإرسال -->
          <div style="padding:0 16px 6px">
            <input type="text" id="topupNotes" placeholder="ملاحظة اختيارية (اسم المُرسِل...)"
                   class="topup-note-input">
          </div>

          <form method="POST" action="<?=SITE_URL?>/topup.php" enctype="multipart/form-data" id="topupForm">
            <input type="hidden" name="method_id"     id="topupMethodId">
            <input type="hidden" name="currency_code" id="topupCurrencyCode" value="USD">
            <input type="hidden" name="amount_sent"   id="topupAmountHidden">
            <input type="hidden" name="notes"         id="topupNotesHidden">
            <input type="file"   name="receipt"       id="receiptFileInput" accept="image/*,.pdf" style="display:none" onchange="previewReceipt(this)">
            <div style="padding:12px 16px 24px">
              <button type="button" class="topup-submit-btn" onclick="submitTopup()">
                <i class="fas fa-paper-plane"></i> إرسال طلب الشحن
              </button>
            </div>
          </form>
        </div>

        <!-- ── Step 3: فلوسك دفع مباشر ── -->
        <?php if($floosakEnabled): ?>
        <div id="topupStep3" style="display:none">
          <div class="topup-header">
            <button class="topup-back-btn" onclick="backToStep1()"><i class="fas fa-arrow-right"></i></button>
            <div style="flex:1;text-align:center">
              <div class="topup-header-title">💳 شحن عبر فلوسك</div>
              <div class="topup-header-sub" id="floosakStepSub">أدخل بيانات الدفع</div>
            </div>
          </div>

          <!-- مرحلة A: إدخال المبلغ والهاتف -->
      <div id="floosakPhaseA" style="padding:14px 16px">
        <!-- بطاقة فلوسك -->
        <div style="background:linear-gradient(135deg,#003d2e,#004d6e);border:1px solid rgba(0,212,170,.25);border-radius:18px;padding:16px;margin-bottom:14px;text-align:center">
          <?php if($floosakImage): ?>
          <img src="<?=SITE_URL?>/<?=htmlspecialchars($floosakImage)?>" style="height:48px;object-fit:contain;margin-bottom:6px">
          <?php else: ?>
          <div style="font-size:2rem;margin-bottom:4px">💳</div>
          <div style="font-weight:900;font-size:1rem;color:#00d4aa">فلوسك</div>
          <?php endif; ?>
          <div style="font-size:.78rem;color:rgba(255,255,255,.5);margin-top:3px">سيتم خصم المبلغ من محفظتك في فلوسك</div>
        </div>

        <!-- رقم الهاتف في فلوسك -->
        <div style="margin-bottom:12px">
          <div style="font-size:.8rem;color:#8895a7;font-weight:700;margin-bottom:6px"><i class="fas fa-mobile-alt"></i> رقم هاتفك المسجل في فلوسك</div>
          <input type="tel" id="floosakPhone" placeholder="770862965" maxlength="15"
                 style="width:100%;background:rgba(255,255,255,.05);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:#fff;font-family:var(--font);font-size:15px;outline:none;direction:ltr;text-align:right;box-sizing:border-box"
                 oninput="this.style.borderColor='var(--border)'">
          <div style="font-size:.73rem;color:#8895a7;margin-top:5px;text-align:center">
            <i class="fas fa-info-circle" style="color:#00d4aa"></i> أدخل رقمك بدون كود الدولة — سيُضاف 967 تلقائياً
          </div>
        </div>

        <!-- المبلغ -->
        <div style="margin-bottom:12px">
          <div style="font-size:.8rem;color:#8895a7;font-weight:700;margin-bottom:6px"><i class="fas fa-coins"></i> المبلغ (ريال يمني)</div>
          <input type="number" id="floosakAmount" placeholder="1000" min="100" step="100"
                 style="width:100%;background:rgba(255,255,255,.05);border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;color:#fff;font-family:var(--font);font-size:18px;font-weight:900;outline:none;direction:ltr;text-align:center;box-sizing:border-box"
                 oninput="calcFloosakUSD()">
          <div id="floosakUsdPreview" style="text-align:center;margin-top:6px;font-size:.83rem;color:#8895a7"></div>
        </div>

        <!-- مبالغ سريعة -->
        <div style="display:flex;gap:8px;margin-bottom:14px;overflow-x:auto;padding-bottom:2px">
          <?php foreach([500,1000,2000,5000,10000] as $qa): ?>
          <button onclick="document.getElementById('floosakAmount').value=<?=$qa?>;calcFloosakUSD()" type="button"
                  style="flex-shrink:0;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:6px 14px;color:#fff;font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap">
            <?=number_format($qa)?> ر.ي
          </button>
          <?php endforeach; ?>
        </div>

        <button type="button" id="floosakSendOtpBtn" onclick="floosakInitiate()"
                style="width:100%;background:linear-gradient(135deg,#00a884,#1e6fff);border:none;border-radius:14px;padding:15px;color:#fff;font-family:var(--font);font-size:15px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
          <i class="fas fa-paper-plane"></i> إرسال رمز التحقق
        </button>
      </div>

      <!-- مرحلة B: إدخال OTP -->
      <div id="floosakPhaseB" style="display:none;padding:14px 16px">
        <!-- معلومات العملية -->
        <div style="background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.2);border-radius:14px;padding:14px;margin-bottom:16px;text-align:center">
          <div style="font-size:.8rem;color:#8895a7;margin-bottom:4px">تم إرسال رمز التحقق إلى هاتفك</div>
          <div style="font-weight:900;color:#00d4aa;font-size:1.1rem" id="floosakAmountDisplay">—</div>
          <div style="font-size:.75rem;color:#8895a7;margin-top:3px" id="floosakPhoneDisplay"></div>
        </div>

        <!-- OTP input -->
        <div style="margin-bottom:16px">
          <div style="font-size:.8rem;color:#8895a7;font-weight:700;margin-bottom:8px;text-align:center">
            <i class="fas fa-shield-alt" style="color:#00d4aa"></i> أدخل رمز التحقق المكون من 6 أرقام
          </div>
          <input type="tel" id="floosakOTP" maxlength="6" placeholder="• • • • • •"
                 style="width:100%;background:rgba(255,255,255,.05);border:2px solid rgba(0,212,170,.4);border-radius:14px;padding:16px;color:#00d4aa;font-family:monospace;font-size:2rem;font-weight:900;outline:none;text-align:center;letter-spacing:12px;direction:ltr;box-sizing:border-box"
                 oninput="this.value=this.value.replace(/\D/g,'').slice(0,6); if(this.value.length===6) floosakConfirm()">
          <div style="font-size:.73rem;color:#8895a7;text-align:center;margin-top:6px">
            <span id="floosakOtpTimer"></span>
          </div>
        </div>

        <button type="button" id="floosakConfirmBtn" onclick="floosakConfirm()"
                style="width:100%;background:linear-gradient(135deg,#00a884,#1e6fff);border:none;border-radius:14px;padding:15px;color:#fff;font-family:var(--font);font-size:15px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:10px">
          <i class="fas fa-check-circle"></i> تأكيد الدفع
        </button>
        <button type="button" onclick="floosakReset()"
                style="width:100%;background:transparent;border:1px solid var(--border);border-radius:12px;padding:11px;color:#8895a7;font-family:var(--font);font-size:.85rem;cursor:pointer">
          ← تغيير البيانات
        </button>
      </div>

      <!-- مرحلة C: نتيجة -->
      <div id="floosakPhaseC" style="display:none;padding:24px 16px;text-align:center">
        <div id="floosakResultIcon" style="font-size:3.5rem;margin-bottom:12px">✅</div>
        <div id="floosakResultTitle" style="font-size:1.2rem;font-weight:900;margin-bottom:6px">تمت العملية بنجاح!</div>
        <div id="floosakResultMsg" style="font-size:.85rem;color:#8895a7;margin-bottom:20px"></div>
        <div id="floosakNewBalance" style="background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.2);border-radius:14px;padding:14px;margin-bottom:20px;display:none">
          <div style="font-size:.8rem;color:#8895a7">رصيدك الجديد</div>
          <div style="font-size:1.8rem;font-weight:900;color:#00d4aa" id="floosakNewBalanceVal"></div>
        </div>
        <button type="button" onclick="navTo('wallet');location.reload()"
                style="width:100%;background:linear-gradient(135deg,#00a884,#1e6fff);border:none;border-radius:14px;padding:14px;color:#fff;font-family:var(--font);font-size:15px;font-weight:900;cursor:pointer">
          حسناً
        </button>
      </div>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- /page-topup -->

