<?php
require_once 'includes/config.php';
require_once 'includes/momaiz_api.php';
$pageTitle = 'الخدمات - ' . SITE_NAME;

$catId = isset($_GET['cat']) ? (int)$_GET['cat'] : null;
$mainCats = $pdo->query("SELECT * FROM categories WHERE parent_id IS NULL AND status=1 ORDER BY sort_order")->fetchAll();

// نوع القسم المختار
$currentCat   = null;
$isTelecomCat = false;
if ($catId) {
    $s = $pdo->prepare("SELECT * FROM categories WHERE id=? AND status=1");
    $s->execute([$catId]); $currentCat = $s->fetch();
    $isTelecomCat = $currentCat && ($currentCat['category_type'] ?? 'default') === 'telecom';
}

if ($isTelecomCat) {
    requireLogin();
    $telecomNetworks = $pdo->query("SELECT * FROM telecom_networks WHERE status=1 ORDER BY sort_order")->fetchAll();
    $telecomUser     = getUser();
}

// بيانات الخدمات العادية
$subCats = $services = $serviceFields = [];
if (!$isTelecomCat) {
    if ($catId) {
        $s = $pdo->prepare("SELECT * FROM categories WHERE parent_id=? AND status=1 ORDER BY sort_order");
        $s->execute([$catId]); $subCats = $s->fetchAll();
        $subCatIds = array_column($subCats,'id'); $subCatIds[] = $catId;
        $in = implode(',', $subCatIds);
        $services = $pdo->query("SELECT s.*,c.name as cat_name,c.id as cat_id FROM services s JOIN categories c ON s.category_id=c.id WHERE s.category_id IN ($in) AND s.status=1 ORDER BY c.sort_order,s.sort_order")->fetchAll();
    } else {
        $services = $pdo->query("SELECT s.*,c.name as cat_name,c.id as cat_id FROM services s JOIN categories c ON s.category_id=c.id WHERE s.status=1 ORDER BY c.sort_order,s.sort_order LIMIT 50")->fetchAll();
    }
    if ($services) {
        $sids = implode(',', array_column($services,'id'));
        $flds = $pdo->query("SELECT * FROM service_fields WHERE service_id IN ($sids) ORDER BY sort_order")->fetchAll();
        foreach ($flds as $f) $serviceFields[$f['service_id']][] = $f;
    }
}

include 'includes/header.php';
?>

<div class="page-header">
    <h2>
        <?php if ($isTelecomCat): ?>
        <i class="fas fa-sim-card"></i> كبينة السداد
        <?php else: ?>
        <i class="fas fa-th-large"></i> تصفح الخدمات
        <?php endif; ?>
    </h2>
</div>

<!-- أزرار الأقسام -->
<div class="sub-cats" style="margin-bottom:1rem">
    <button class="sub-cat-btn <?= !$catId?'active':'' ?>" onclick="location.href='services.php'">
        <i class="fas fa-th"></i> الكل
    </button>
    <?php foreach ($mainCats as $mc):
        $isTC = ($mc['category_type'] ?? 'default') === 'telecom';
    ?>
    <button class="sub-cat-btn <?= $catId==$mc['id']?'active':'' ?>"
            onclick="location.href='services.php?cat=<?= $mc['id'] ?>'">
        <i class="<?= htmlspecialchars($mc['icon']) ?>"></i>
        <?= htmlspecialchars($mc['name']) ?>
        <?php if ($isTC): ?>
        <span style="font-size:0.65rem;background:rgba(0,212,170,0.2);color:var(--secondary);padding:1px 6px;border-radius:4px;margin-right:3px">اتصالات</span>
        <?php endif; ?>
    </button>
    <?php endforeach; ?>
</div>

<?php if ($isTelecomCat): /* ══ كبينة السداد — كشف تلقائي من الرقم ══ */ ?>

<style>
.kc-wrap{max-width:820px;margin:0 auto}
.kc-phone-box{background:var(--card);border:2px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.25rem;transition:border-color .3s}
.kc-phone-box.detected{border-color:var(--secondary)}
.kc-phone-input{font-size:1.4rem;letter-spacing:2px;direction:ltr;text-align:center;background:transparent;border:none;color:var(--text);width:100%;outline:none;padding:0.4rem 0}
.kc-net-badge{display:flex;align-items:center;gap:0.6rem;padding:0.6rem 1rem;border-radius:8px;margin-top:0.75rem;font-size:0.88rem;font-weight:700;transition:all .3s}
.kc-tab-row{display:flex;gap:0.5rem;margin-bottom:1.25rem;border-bottom:2px solid var(--border);padding-bottom:0.5rem}
.kc-tab{padding:0.55rem 1.1rem;border-radius:8px 8px 0 0;border:none;background:transparent;color:var(--text-muted);font-size:0.9rem;cursor:pointer;font-weight:600;transition:all .2s}
.kc-tab.active{background:var(--primary);color:#fff}
.kc-amt-btn{padding:0.5rem 1rem;border-radius:8px;background:var(--card2);border:2px solid var(--border);color:var(--text);cursor:pointer;font-size:0.88rem;font-weight:600;transition:all .2s}
.kc-amt-btn:hover,.kc-amt-btn.active{border-color:var(--primary);background:rgba(108,63,224,.15);color:var(--primary)}
.kc-offer-card{background:var(--card);border:2px solid var(--border);border-radius:var(--radius);padding:1rem;cursor:pointer;transition:all .2s;position:relative}
.kc-offer-card:hover,.kc-offer-card.selected{border-color:var(--primary);background:var(--card2)}
.kc-offer-badge{position:absolute;top:8px;left:8px;font-size:0.65rem;padding:2px 7px;border-radius:4px;color:#000;font-weight:700}
.kc-stat{display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid var(--border);font-size:0.84rem}
.kc-stat:last-child{border:none}
.kc-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem}
.kc-sub-tab{padding:0.35rem 0.85rem;border-radius:6px;background:var(--card2);border:1px solid var(--border);color:var(--text);font-size:0.78rem;cursor:pointer}
.kc-sub-tab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
@media(max-width:600px){.kc-grid{grid-template-columns:1fr}}
</style>

<!-- رصيد العميل -->
<div class="balance-display" style="margin-bottom:1.5rem">
    <div class="balance-label"><i class="fas fa-sim-card"></i> كبينة السداد</div>
    <div class="balance-amount"><?= formatMoney($telecomUser['balance']) ?></div>
    <div style="color:rgba(255,255,255,0.65);font-size:0.85rem;margin-top:0.4rem">
        رصيدك لشحن الاتصالات اليمنية — العرض بالريال، الخصم بالدولار
    </div>
</div>

<div class="kc-wrap">

    <!-- ── حقل الرقم ─────────────────────────────────────────────────── -->
    <div class="kc-phone-box" id="phoneBox">
        <div style="text-align:center;color:var(--text-muted);font-size:0.8rem;margin-bottom:0.5rem">
            <i class="fas fa-sim-card"></i> أدخل رقم الهاتف — سيتم التعرف على الشبكة تلقائياً
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem">
            <input type="tel" id="phoneInput" class="kc-phone-input" placeholder="7X XXX XXXX"
                   maxlength="12" oninput="onPhoneInput(this.value)" autocomplete="tel">
            <button onclick="clearPhone()" style="background:none;border:none;color:var(--text-muted);font-size:1.2rem;cursor:pointer;padding:0.4rem;flex-shrink:0">
                <i class="fas fa-times-circle"></i>
            </button>
        </div>
        <div id="netBadge" style="display:none" class="kc-net-badge">
            <div id="netBadgeIcon" style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
                <i class="fas fa-signal"></i>
            </div>
            <div>
                <div id="netBadgeName" style="font-weight:700"></div>
                <div id="netBadgeSub" style="font-size:0.73rem;color:var(--text-muted);font-weight:400"></div>
            </div>
            <div id="netBadgeRight" style="margin-right:auto;font-size:0.75rem;color:var(--text-muted)"></div>
        </div>
        <div id="netUnknown" style="display:none;margin-top:0.6rem;font-size:0.8rem;color:var(--danger)">
            <i class="fas fa-exclamation-circle"></i> لم يتم التعرف على الشبكة من هذا الرقم
        </div>
    </div>

    <!-- ── لوحة العمل (تظهر بعد كشف الشبكة) ─────────────────────────── -->
    <div id="kcWork" style="display:none">

        <!-- تبويبات -->
        <div class="kc-tab-row">
            <button class="kc-tab active" id="tab_balance"  onclick="switchTab('balance')"><i class="fas fa-wallet"></i> شحن رصيد</button>
            <button class="kc-tab" id="tab_offers"          onclick="switchTab('offers')" style="display:none"><i class="fas fa-box"></i> الباقات</button>
            <button class="kc-tab" id="tab_inquiry"         onclick="switchTab('inquiry')"><i class="fas fa-search"></i> استعلام</button>
        </div>

        <div class="kc-grid">
            <!-- ── العمود الرئيسي ── -->
            <div>

                <!-- شحن رصيد -->
                <div id="sec_balance">
                    <div class="card" style="padding:1.25rem;margin-bottom:1rem">
                        <h4 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0.75rem">
                            <i class="fas fa-bolt" style="color:var(--warning)"></i> فئات سريعة
                        </h4>
                        <div id="quickAmounts" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem">
                            <span style="color:var(--text-muted);font-size:0.82rem"><i class="fas fa-spinner fa-spin"></i></span>
                        </div>

                        <!-- للهاتف الثابت: خيار نوع الخدمة -->
                        <div id="landlineTypeRow" style="display:none;margin-bottom:0.85rem">
                            <label style="font-size:0.82rem;color:var(--text-muted);display:block;margin-bottom:0.4rem">نوع الخدمة</label>
                            <div style="display:flex;gap:0.5rem">
                                <button class="kc-sub-tab active" id="typeLineBtn" onclick="setPostType('line',this)"><i class="fas fa-phone"></i> هاتف ثابت</button>
                                <button class="kc-sub-tab" id="typeAdslBtn" onclick="setPostType('adsl',this)"><i class="fas fa-wifi"></i> انترنت ADSL</button>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:0.75rem">
                            <label style="font-size:0.82rem"><i class="fas fa-coins"></i> المبلغ (ريال يمني)</label>
                            <input type="number" id="amountInput" class="form-control"
                                   placeholder="أدخل المبلغ" oninput="updateSummary()">
                            <!-- لسبافون: وحدات -->
                            <div id="unitsDisplay" style="display:none;margin-top:0.4rem;font-size:0.8rem;color:var(--secondary)">
                                <i class="fas fa-info-circle"></i> = <span id="unitsCount">0</span> وحدة
                                <span style="color:var(--text-muted)">(1 وحدة = <span id="unitPriceDisplay">1</span> ر.ي)</span>
                            </div>
                            <small id="amountHint" style="color:var(--text-muted);font-size:0.75rem"></small>
                        </div>

                        <div id="balanceSummary" style="display:none;background:var(--darker);border-radius:8px;padding:0.85rem;margin-bottom:0.85rem">
                            <div class="kc-stat"><span class="text-muted">المبلغ المشحون</span><strong id="sumAmt" style="color:var(--secondary)">—</strong></div>
                            <div class="kc-stat"><span class="text-muted">الخصم من رصيدك (مع الربح)</span><strong id="sumCharge">—</strong></div>
                            <div class="kc-stat"><span class="text-muted">رصيدك بعد العملية</span><strong id="sumAfter">—</strong></div>
                        </div>

                        <button class="btn btn-success btn-full" id="payBalBtn" disabled onclick="submitBalance()">
                            <i class="fas fa-paper-plane"></i> شحن الرصيد
                        </button>
                    </div>
                </div>

                <!-- الباقات -->
                <div id="sec_offers" style="display:none">
                    <div id="offersLoading" style="text-align:center;padding:2rem;color:var(--text-muted)">
                        <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;display:block;margin-bottom:0.4rem"></i>
                        جاري تحميل الباقات...
                    </div>
                    <div id="offersList"></div>
                    <button class="btn btn-primary btn-full" id="payOfferBtn" disabled onclick="submitOffer()" style="margin-top:1rem">
                        <i class="fas fa-rocket"></i> تفعيل الباقة المختارة
                    </button>
                </div>

                <!-- الاستعلام -->
                <div id="sec_inquiry" style="display:none">
                    <div class="card" style="padding:1.25rem">
                        <h4 style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0.75rem">اختر نوع الاستعلام</h4>
                        <div style="display:flex;flex-direction:column;gap:0.6rem">
                            <button class="btn btn-secondary btn-full" id="btnInqBal" onclick="doInquiry('balance')">
                                <i class="fas fa-wallet"></i> استعلام عن الرصيد
                            </button>
                            <button class="btn btn-secondary btn-full" id="btnInqLoan" onclick="doInquiry('loan')" style="display:none">
                                <i class="fas fa-hand-holding-usd"></i> استعلام عن السلفة
                            </button>
                            <button class="btn btn-secondary btn-full" id="btnInqOffers" onclick="doInquiry('offers')" style="display:none">
                                <i class="fas fa-box"></i> الباقات المشتركة الحالية
                            </button>
                        </div>
                        <div id="inquiryResult" style="display:none;margin-top:1rem;background:var(--darker);border-radius:var(--radius);padding:1rem"></div>
                    </div>
                </div>
            </div>

            <!-- ── العمود الجانبي ── -->
            <div>
                <!-- معلومات الشبكة -->
                <div class="card" id="netInfoCard" style="padding:1.25rem;margin-bottom:1rem">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.65rem">
                        <div id="netCardIcon" style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;background:rgba(108,63,224,.2);color:var(--primary)">
                            <i class="fas fa-signal"></i>
                        </div>
                        <div>
                            <div id="netCardName" style="font-weight:700;font-size:0.95rem">—</div>
                            <div style="font-size:0.75rem;color:var(--text-muted)">شبكة الاتصال</div>
                        </div>
                    </div>
                    <div id="netCardDetails" style="font-size:0.8rem;color:var(--text-muted)"></div>
                </div>

                <!-- آخر العمليات -->
                <?php
                try {
                    $recentOps = $pdo->prepare("SELECT t.*,n.name as net_name,n.icon as net_icon,o.name as offer_name
                        FROM telecom_orders t JOIN telecom_networks n ON t.network_id=n.id
                        LEFT JOIN telecom_offers o ON t.offer_id=o.id
                        WHERE t.user_id=? ORDER BY t.created_at DESC LIMIT 6");
                    $recentOps->execute([$_SESSION['user_id']]);
                    $recentOps = $recentOps->fetchAll();
                } catch(\Exception $e) { $recentOps = []; }
                $stMap = ['pending'=>['انتظار','warning'],'processing'=>['تنفيذ','info'],'completed'=>['مكتمل','success'],'failed'=>['فشل','danger'],'cancelled'=>['ملغي','secondary']];
                ?>
                <div class="card" style="padding:1.25rem">
                    <h4 style="font-size:0.85rem;margin-bottom:0.75rem"><i class="fas fa-history"></i> آخر العمليات</h4>
                    <?php if ($recentOps): ?>
                    <div style="display:flex;flex-direction:column;gap:0.45rem">
                    <?php foreach($recentOps as $op): [$stL,$stC]=$stMap[$op['status']]??['—','secondary']; ?>
                    <div style="display:flex;align-items:center;gap:0.6rem;padding:0.55rem 0.65rem;background:var(--darker);border-radius:8px;font-size:0.8rem">
                        <i class="fas fa-<?= htmlspecialchars($op['net_icon']) ?>" style="color:var(--primary);width:14px;text-align:center;flex-shrink:0"></i>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                <?= htmlspecialchars($op['net_name']) ?> — <span style="direction:ltr;unicode-bidi:embed"><?= htmlspecialchars($op['mobile_number']) ?></span>
                            </div>
                            <div style="color:var(--text-muted);font-size:0.72rem">
                                <?= $op['order_type']==='offer'?'باقة: '.htmlspecialchars($op['offer_name']??'—'):'رصيد: '.number_format($op['amount_yer']??0,0).' ر.ي' ?>
                            </div>
                        </div>
                        <span class="badge badge-<?= $stC ?>" style="font-size:0.7rem"><?= $stL ?></span>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:1rem;color:var(--text-muted);font-size:0.82rem">
                        <i class="fas fa-history" style="display:block;font-size:1.5rem;margin-bottom:0.4rem;opacity:.3"></i>
                        لا توجد عمليات سابقة
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- end kc-grid -->
    </div><!-- end kcWork -->

</div><!-- end kc-wrap -->

<script>
<?php
$sadadRate = (float)(getSetting('telecom_sadad_rate') ?: getSetting('telecom_exchange_rate') ?: 1700);
?>
const KC_URL    = '<?= SITE_URL ?>/api/telecom.php';
const USER_BAL  = <?= (float)$telecomUser['balance'] ?>;
const EXCH_RATE = <?= (float)$sadadRate ?>;
const PROFIT_PCT= <?= (float)(getSetting('telecom_profit_pct') ?: 5) ?>;

let curNet = null;      // الشبكة المكتشفة
let curTab = 'balance';
let selOffer = null;    // الباقة المختارة
let postType = 'line';  // للهاتف الثابت

// ── كشف الشبكة ──────────────────────────────────────────────────────────────
function onPhoneInput(val) {
    const clean = val.replace(/[^0-9]/g, '');
    if (clean.length >= 8) {
        detectNetwork(clean);
    } else if (clean.length < 2) {
        hideNetBadge();
    }
    if (curNet) updateSummary();
}

let detectTimer = null;
function detectNetwork(phone) {
    clearTimeout(detectTimer);
    detectTimer = setTimeout(() => {
        fetch(KC_URL + '?action=detect_network&phone=' + phone)
            .then(r => r.json())
            .then(d => {
                if (d.status && d.network) {
                    onNetDetected(d.network);
                } else {
                    showNetUnknown();
                }
            }).catch(() => showNetUnknown());
    }, 350);
}

function onNetDetected(net) {
    curNet = net;
    // بادج الشبكة
    const badge = document.getElementById('netBadge');
    badge.style.display = 'flex';
    badge.style.background = net.color + '22';
    badge.style.borderRadius = '8px';
    badge.style.border = '1px solid ' + net.color + '44';
    const icon = document.getElementById('netBadgeIcon');
    icon.style.background = net.color + '33'; icon.style.color = net.color;
    icon.innerHTML = `<i class="fas fa-${net.icon}"></i>`;
    document.getElementById('netBadgeName').textContent = net.name;
    document.getElementById('netBadgeSub').textContent =
        net.amount_type === 'units' ? 'شحن بالوحدات (1 وحدة = ' + net.unit_price + ' ر.ي)' : 'شحن بالريال اليمني';
    document.getElementById('netBadgeRight').textContent = net.supports_loan == 1 ? 'يدعم السلفة' : '';
    document.getElementById('netUnknown').style.display = 'none';
    document.getElementById('phoneBox').classList.add('detected');

    // بطاقة المعلومات
    document.getElementById('netCardIcon').style.background = net.color + '33';
    document.getElementById('netCardIcon').style.color = net.color;
    document.getElementById('netCardIcon').innerHTML = `<i class="fas fa-${net.icon}"></i>`;
    document.getElementById('netCardName').textContent = net.name;
    let det = '';
    if (net.supports_balance == 1) det += `<span style="color:var(--success)"><i class="fas fa-check"></i> رصيد مفتوح</span>  `;
    if (net.supports_offers == 1)  det += `<span><i class="fas fa-box"></i> باقات</span>  `;
    if (net.supports_loan == 1)    det += `<span><i class="fas fa-hand-holding-usd"></i> سلفة</span>`;
    if (net.amount_type === 'units') det += `<br><span style="color:var(--warning)"><i class="fas fa-info-circle"></i> 1 وحدة = ${net.unit_price} ر.ي</span>`;
    document.getElementById('netCardDetails').innerHTML = det;

    // إظهار لوحة العمل
    document.getElementById('kcWork').style.display = 'block';

    // تبويبات
    document.getElementById('tab_offers').style.display = net.supports_offers == 1 ? '' : 'none';
    document.getElementById('btnInqLoan').style.display   = net.supports_loan == 1 ? '' : 'none';
    document.getElementById('btnInqOffers').style.display = net.supports_offers == 1 ? '' : 'none';

    // خيار هاتف/انترنت
    document.getElementById('landlineTypeRow').style.display =
        net.supports_landline_type == 1 ? 'block' : 'none';

    // حد الشحن
    document.getElementById('amountHint').textContent =
        `الحد: ${nf(net.min_amount)} — ${nf(net.max_amount)} ر.ي`;

    // وحدات عرض
    document.getElementById('unitsDisplay').style.display =
        net.amount_type === 'units' ? 'block' : 'none';
    document.getElementById('unitPriceDisplay').textContent = net.unit_price;

    loadQuickAmounts(net.id);
    if (curTab === 'offers') loadOffers();
    updateSummary();
}

function hideNetBadge() {
    document.getElementById('netBadge').style.display = 'none';
    document.getElementById('netUnknown').style.display = 'none';
    document.getElementById('phoneBox').classList.remove('detected');
    document.getElementById('kcWork').style.display = 'none';
    curNet = null;
}
function showNetUnknown() {
    document.getElementById('netBadge').style.display = 'none';
    document.getElementById('netUnknown').style.display = 'block';
    document.getElementById('phoneBox').classList.remove('detected');
    document.getElementById('kcWork').style.display = 'none';
    curNet = null;
}
function clearPhone() {
    document.getElementById('phoneInput').value = '';
    hideNetBadge();
}

// ── فئات الشحن السريع ────────────────────────────────────────────────────────
function loadQuickAmounts(netId) {
    const g = document.getElementById('quickAmounts');
    g.innerHTML = '<span style="color:var(--text-muted);font-size:0.82rem"><i class="fas fa-spinner fa-spin"></i></span>';
    fetch(KC_URL + '?action=get_quick_amounts&network_id=' + netId)
        .then(r => r.json())
        .then(d => {
            if (!d.amounts || !d.amounts.length) { g.innerHTML = '<span style="color:var(--text-muted);font-size:0.82rem">لا توجد فئات سريعة</span>'; return; }
            g.innerHTML = d.amounts.map(a =>
                `<button class="kc-amt-btn" onclick="pickAmt(${a.amount},${a.fore_num||0},this)">${nf(a.amount)} ر.ي</button>`
            ).join('');
        }).catch(() => { g.innerHTML = ''; });
}

function pickAmt(amount, foreNum, el) {
    document.querySelectorAll('.kc-amt-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('amountInput').value = amount;
    updateSummary();
}

// ── تبويبات ──────────────────────────────────────────────────────────────────
function switchTab(tab) {
    curTab = tab;
    ['balance','offers','inquiry'].forEach(t => {
        document.getElementById('sec_' + t).style.display = t === tab ? 'block' : 'none';
        const btn = document.getElementById('tab_' + t);
        if (btn) btn.classList.toggle('active', t === tab);
    });
    document.getElementById('inquiryResult').style.display = 'none';
    if (tab === 'offers' && curNet) loadOffers();
}

// ── حساب الملخص ──────────────────────────────────────────────────────────────
function updateSummary() {
    const phone  = document.getElementById('phoneInput').value.replace(/[^0-9]/g,'');
    const amount = parseFloat(document.getElementById('amountInput').value) || 0;
    const valid  = phone.length >= 8 && amount > 0 && curNet;

    // عرض الوحدات لسبافون/واي
    if (curNet && curNet.amount_type === 'units' && amount > 0) {
        const units = Math.round(amount / (parseFloat(curNet.unit_price) || 1));
        document.getElementById('unitsCount').textContent = nf(units);
    }

    document.getElementById('payBalBtn').disabled = !valid;
    const sum = document.getElementById('balanceSummary');
    if (valid) {
        sum.style.display = 'block';
        const chargeYer = Math.round(amount * (1 + PROFIT_PCT / 100));
        const chargeUsd = chargeYer / EXCH_RATE;
        const afterUsd  = USER_BAL - chargeUsd;
        document.getElementById('sumAmt').textContent    = nf(amount) + ' ر.ي';
        document.getElementById('sumCharge').textContent = nf(chargeYer) + ' ر.ي (' + chargeUsd.toFixed(4) + ' $)';
        const ae = document.getElementById('sumAfter');
        ae.textContent = (afterUsd * EXCH_RATE).toFixed(0) + ' ر.ي';
        ae.style.color = afterUsd >= 0 ? 'var(--success)' : 'var(--danger)';
    } else { sum.style.display = 'none'; }
}

// ── الباقات ───────────────────────────────────────────────────────────────────
function loadOffers() {
    if (!curNet) return;
    document.getElementById('offersLoading').style.display = 'block';
    document.getElementById('offersList').innerHTML = '';
    selOffer = null;
    document.getElementById('payOfferBtn').disabled = true;

    fetch(KC_URL + '?action=get_offers&network_id=' + curNet.id)
        .then(r => r.json())
        .then(d => {
            document.getElementById('offersLoading').style.display = 'none';
            let html = '';
            // مجموعات
            if (d.groups && d.groups.length) {
                d.groups.forEach(g => {
                    html += `<div style="margin-bottom:1rem">
                        <div style="font-size:0.8rem;font-weight:700;color:var(--text-muted);padding:0.4rem 0;border-bottom:1px solid var(--border);margin-bottom:0.75rem">
                            <i class="fas fa-${g.icon||'box'}"></i> ${esc(g.name)}
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem">`;
                    g.offers.forEach(o => { html += offerCard(o); });
                    html += `</div></div>`;
                });
            }
            // بدون مجموعة
            if (d.ungrouped && d.ungrouped.length) {
                html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0.6rem">`;
                d.ungrouped.forEach(o => { html += offerCard(o); });
                html += `</div>`;
            }
            if (!html) html = '<div class="empty-state" style="padding:1.5rem"><i class="fas fa-box-open"></i><p>لا توجد باقات</p></div>';
            document.getElementById('offersList').innerHTML = html;
        }).catch(e => {
            document.getElementById('offersLoading').style.display = 'none';
            document.getElementById('offersList').innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> فشل تحميل الباقات</div>';
        });
}

function offerCard(o) {
    const chargeYer = Math.round(o.price_yer * (1 + PROFIT_PCT / 100));
    return `<div class="kc-offer-card" id="offer_${o.id}" onclick="pickOffer(${o.id})">
        ${o.badge ? `<div class="kc-offer-badge" style="background:${o.badge_color||'#f5a623'}">${esc(o.badge)}</div>` : ''}
        <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.3rem">${esc(o.name)}</div>
        <div style="color:var(--secondary);font-size:1.1rem;font-weight:700">${nf(chargeYer)} <span style="font-size:0.7rem;color:var(--text-muted)">ر.ي</span></div>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.35rem;display:flex;flex-wrap:wrap;gap:0.3rem 0.6rem">
            ${o.validity ? `<span><i class="fas fa-clock"></i> ${esc(o.validity)}</span>` : ''}
            ${o.data_mb  ? `<span><i class="fas fa-wifi"></i> ${o.data_mb >= 1024 ? (o.data_mb/1024).toFixed(0)+'G' : o.data_mb+'M'}</span>` : ''}
            ${o.minutes  ? `<span><i class="fas fa-phone"></i> ${nf(o.minutes)}د</span>` : ''}
        </div>
        ${o.description ? `<div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.3rem">${esc(o.description)}</div>` : ''}
    </div>`;
}

function pickOffer(id) {
    selOffer = id;
    document.querySelectorAll('.kc-offer-card').forEach(c => c.classList.remove('selected'));
    const el = document.getElementById('offer_' + id);
    if (el) el.classList.add('selected');
    document.getElementById('payOfferBtn').disabled =
        document.getElementById('phoneInput').value.replace(/[^0-9]/g,'').length < 8;
}

// ── الاستعلام ─────────────────────────────────────────────────────────────────
function doInquiry(type) {
    const phone = document.getElementById('phoneInput').value.replace(/[^0-9]/g,'');
    if (phone.length < 8) { alert('أدخل رقم الهاتف أولاً'); return; }
    const box = document.getElementById('inquiryResult');
    box.style.display = 'block';
    box.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> جاري الاستعلام...</div>';
    const fd = new FormData();
    fd.append('action','inquiry'); fd.append('type',type);
    fd.append('phone',phone); fd.append('network_id',curNet.id);
    fetch(KC_URL, {method:'POST', body: fd})
        .then(r => r.json()).then(d => {
            if (!d.status) { box.innerHTML = err(d.message||'فشل'); return; }
            let h = '';
            if (type === 'balance') {
                if (d.balance !== undefined) h += row('الرصيد',d.balance,'var(--secondary)');
                if (d.mobileType !== undefined) h += row('نوع الخط', d.mobileType=='0'?'دفع مسبق':'فوترة');
                if (d.remainAmount !== undefined) h += row('الرصيد المتبقي (unit)',d.remainAmount);
            } else if (type === 'loan') {
                h += row('حالة السلفة',d.status=='1'?'متسلّف':'غير متسلّف', d.status=='1'?'var(--danger)':'var(--success)');
                if (d.loan_amount) h += row('مبلغ السلفة',d.loan_amount);
                if (d.loan_time)   h += row('وقت السلفة',d.loan_time);
            } else {
                if (d.offers && d.offers.length)
                    h = d.offers.map(o => `<div style="padding:0.45rem 0;border-bottom:1px solid var(--border)">
                        <div style="font-weight:600;font-size:0.83rem">${esc(o.offerName)}</div>
                        ${o.offerEndDate?`<div style="font-size:0.73rem;color:var(--text-muted)"><i class="fas fa-clock"></i> ${o.offerEndDate}</div>`:''}
                    </div>`).join('');
                else h = '<div style="color:var(--text-muted);text-align:center;padding:0.5rem">لا توجد باقات نشطة</div>';
            }
            box.innerHTML = `<div style="font-size:0.84rem">${h || '<span style="color:var(--text-muted)">لا بيانات</span>'}</div>`;
        }).catch(() => { box.innerHTML = err('خطأ في الاتصال'); });
}

function row(k,v,c=''){return`<div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--border)"><span style="color:var(--text-muted)">${k}</span><strong ${c?`style="color:${c}"`:''}>${v}</strong></div>`;}
function err(msg){return`<div class="alert alert-danger" style="margin:0"><i class="fas fa-times-circle"></i> ${esc(msg)}</div>`;}

// ── إرسال الشحن ───────────────────────────────────────────────────────────────
function submitBalance() {
    const phone  = document.getElementById('phoneInput').value.replace(/[^0-9]/g,'');
    const amount = parseFloat(document.getElementById('amountInput').value);
    if (!phone || !amount || !curNet) return;

    const chargeYer = Math.round(amount * (1 + PROFIT_PCT / 100));
    const chargeUsd = chargeYer / EXCH_RATE;
    if (chargeUsd > USER_BAL) { alert('رصيدك غير كافٍ'); return; }
    if (!confirm(`شحن ${nf(amount)} ر.ي للرقم ${phone}؟\nيُخصم من رصيدك: ${nf(chargeYer)} ر.ي`)) return;

    const btn = document.getElementById('payBalBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التنفيذ...';

    const fd = new FormData();
    fd.append('action','pay_balance'); fd.append('phone',phone);
    fd.append('amount',amount); fd.append('network_id',curNet.id);
    fd.append('post_type', postType);

    fetch(KC_URL, {method:'POST', body:fd})
        .then(r => r.json()).then(d => {
            if (d.status) { toast(d.message||'✓ تمت العملية','success'); setTimeout(()=>location.reload(),2200); }
            else { alert('❌ ' + (d.message||'فشلت')); btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> شحن الرصيد'; }
        }).catch(()=>{ alert('خطأ'); btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> شحن الرصيد'; });
}

function submitOffer() {
    const phone = document.getElementById('phoneInput').value.replace(/[^0-9]/g,'');
    if (!phone || !selOffer || !curNet) return;
    if (!confirm(`تأكيد تفعيل الباقة للرقم ${phone}؟`)) return;

    const btn = document.getElementById('payOfferBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري التفعيل...';

    const fd = new FormData();
    fd.append('action','pay_offer'); fd.append('phone',phone);
    fd.append('offer_id',selOffer); fd.append('network_id',curNet.id);

    fetch(KC_URL, {method:'POST', body:fd})
        .then(r => r.json()).then(d => {
            if (d.status) { toast(d.message||'✓ تم التفعيل','success'); setTimeout(()=>location.reload(),2200); }
            else { alert('❌ ' + (d.message||'فشل')); btn.disabled=false; btn.innerHTML='<i class="fas fa-rocket"></i> تفعيل الباقة المختارة'; }
        }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fas fa-rocket"></i> تفعيل الباقة المختارة'; });
}

// ── مساعدات ──────────────────────────────────────────────────────────────────
function setPostType(t, el) {
    postType = t;
    document.querySelectorAll('.kc-sub-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
}
function nf(n) { return new Intl.NumberFormat('ar-YE').format(Math.round(n)); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function toast(msg, type) {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;top:70px;left:50%;transform:translateX(-50%);background:var(--${type==='success'?'success':'danger'});color:#000;padding:0.75rem 1.5rem;border-radius:var(--radius);font-weight:700;z-index:9999;font-size:0.9rem;box-shadow:var(--shadow);min-width:200px;text-align:center`;
    t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 3000);
}
</script>
<?php endif; /* end isTelecomCat else */ ?>

<?php include 'includes/footer.php'; ?>
