<?php
require_once '../includes/config.php';
require_once '../includes/fore_api.php';
requireAdmin();

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$pageTitle = 'كبينة السداد - ' . SITE_NAME;

$tab = $_GET['tab'] ?? 'settings';
$msg = '';

// ── حفظ الإعدادات ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'settings') {
    $fields = ['telecom_provider','telecom_exchange_rate','telecom_sadad_rate','telecom_profit_pct','telecom_enabled',
               'fore_domain','fore_userid','fore_username','fore_password',
               'momaiz_username','momaiz_password','momaiz_account_number','momaiz_api_token'];
    foreach ($fields as $f) {
        $v = trim($_POST[$f] ?? '');
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$f,$v,$v]);
    }

    // توافق رجعي: الإعداد القديم 1$ = عدد الريالات يحدّث سجل YER المركزي أيضًا.
    $legacyYerPerUsd = (float)($_POST['telecom_exchange_rate'] ?? 0);
    if ($legacyYerPerUsd > 0) {
        $centralRateToUsd = 1 / $legacyYerPerUsd;
        $rateStmt = $pdo->prepare("SELECT id FROM exchange_rates WHERE currency_code='YER' ORDER BY id ASC LIMIT 1");
        $rateStmt->execute();
        $yerRateId = $rateStmt->fetchColumn();
        if ($yerRateId) {
            $pdo->prepare("UPDATE exchange_rates SET rate_to_usd=?, status=1 WHERE id=?")->execute([$centralRateToUsd, (int)$yerRateId]);
        } else {
            $pdo->prepare("INSERT INTO exchange_rates (currency_code,currency_name,currency_symbol,rate_to_usd,status,sort_order) VALUES ('YER','ريال يمني','ر.ي',?,1,0)")->execute([$centralRateToUsd]);
        }
    }
    $msg = 'success|تم حفظ الإعدادات ومزامنة سعر صرف YER ✓';
}

// ── اختبار الاتصال ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $api = getTelecomAPI($pdo);
    if (!$api) { $msg = 'danger|أدخل بيانات المزود أولاً'; }
    else {
        $r = $api->testConnection();
        $msg = $r['ok'] ? 'success|'.$r['msg'].' — رصيد المزود: '.($r['balance']??'—')
                        : 'danger|'.$r['msg'];
    }
}

// ── حفظ شبكة ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'networks') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_network') {
        $d = $_POST;
        $prefixes = array_values(array_filter(array_map('trim', explode(',', $d['prefixes'] ?? ''))));
        $prefixJson = json_encode($prefixes);
        if (isset($d['net_id']) && $d['net_id']) {
            $pdo->prepare("UPDATE telecom_networks SET
                name=?,name_en=?,icon=?,color=?,prefixes=?,
                supports_balance=?,supports_offers=?,supports_loan=?,supports_landline_type=?,
                amount_type=?,unit_price=?,min_amount=?,max_amount=?,
                fore_endpoint=?,fore_query_action=?,fore_bill_action=?,fore_offer_endpoint=?,fore_post_type=?,
                momaiz_net_number=?,momaiz_svc_balance=?,momaiz_svc_pay_balance=?,
                sort_order=?,status=? WHERE id=?")->execute([
                $d['name'],$d['name_en'],$d['icon'],$d['color'],$prefixJson,
                (int)($d['supports_balance']??0),(int)($d['supports_offers']??0),(int)($d['supports_loan']??0),(int)($d['supports_landline_type']??0),
                $d['amount_type'],(float)$d['unit_price'],(float)$d['min_amount'],(float)$d['max_amount'],
                $d['fore_endpoint'],$d['fore_query_action'],$d['fore_bill_action'],$d['fore_offer_endpoint'],$d['fore_post_type'],
                $d['momaiz_net_number']?:null,$d['momaiz_svc_balance']?:null,$d['momaiz_svc_pay_balance']?:null,
                (int)$d['sort_order'],(int)($d['net_status']??1),(int)$d['net_id']
            ]);
            $msg = 'success|تم تحديث الشبكة ✓';
        } else {
            $pdo->prepare("INSERT INTO telecom_networks
                (name,name_en,icon,color,prefixes,supports_balance,supports_offers,supports_loan,supports_landline_type,amount_type,unit_price,min_amount,max_amount,fore_endpoint,fore_query_action,fore_bill_action,fore_offer_endpoint,fore_post_type,momaiz_net_number,momaiz_svc_balance,momaiz_svc_pay_balance,sort_order,status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $d['name'],$d['name_en'],$d['icon'],$d['color'],$prefixJson,
                (int)($d['supports_balance']??0),(int)($d['supports_offers']??0),(int)($d['supports_loan']??0),(int)($d['supports_landline_type']??0),
                $d['amount_type'],(float)$d['unit_price'],(float)$d['min_amount'],(float)$d['max_amount'],
                $d['fore_endpoint'],$d['fore_query_action'],$d['fore_bill_action'],$d['fore_offer_endpoint'],$d['fore_post_type'],
                $d['momaiz_net_number']?:null,$d['momaiz_svc_balance']?:null,$d['momaiz_svc_pay_balance']?:null,
                (int)($d['sort_order']??0),(int)($d['net_status']??1)
            ]);
            $msg = 'success|تمت إضافة الشبكة ✓';
        }
    }
    if ($act === 'delete_network') {
        $pdo->prepare("DELETE FROM telecom_networks WHERE id=?")->execute([(int)$_POST['net_id']]);
        $msg = 'success|تم الحذف';
    }
    if ($act === 'save_group') {
        $gd = $_POST;
        if ($gd['group_id'] ?? '') {
            $pdo->prepare("UPDATE telecom_offer_groups SET name=?,icon=?,sort_order=?,status=? WHERE id=?")
                ->execute([$gd['gname'],$gd['gicon'],(int)$gd['gsort'],(int)($gd['gstatus']??1),(int)$gd['group_id']]);
        } else {
            $pdo->prepare("INSERT INTO telecom_offer_groups (network_id,name,icon,sort_order) VALUES (?,?,?,?)")
                ->execute([(int)$gd['gnet_id'],$gd['gname'],$gd['gicon'],(int)$gd['gsort']]);
        }
        $msg = 'success|تمت العملية ✓';
    }
    if ($act === 'delete_group') {
        $pdo->prepare("DELETE FROM telecom_offer_groups WHERE id=?")->execute([(int)$_POST['group_id']]);
        $msg = 'success|تم حذف المجموعة';
    }
    if ($act === 'save_quick') {
        $qd = $_POST;
        if ($qd['qa_id'] ?? '') {
            $pdo->prepare("UPDATE telecom_quick_amounts SET amount=?,fore_num=?,label=?,sort_order=? WHERE id=?")
                ->execute([(float)$qd['qa_amount'],$qd['qa_fore_num']?:null,$qd['qa_label'],(int)$qd['qa_sort'],(int)$qd['qa_id']]);
        } else {
            $pdo->prepare("INSERT INTO telecom_quick_amounts (network_id,amount,fore_num,label,sort_order) VALUES (?,?,?,?,?)")
                ->execute([(int)$qd['qa_net_id'],(float)$qd['qa_amount'],$qd['qa_fore_num']?:null,$qd['qa_label'],(int)($qd['qa_sort']??0)]);
        }
        $msg = 'success|تمت العملية ✓';
    }
    if ($act === 'delete_quick') {
        $pdo->prepare("DELETE FROM telecom_quick_amounts WHERE id=?")->execute([(int)$_POST['qa_id']]);
        $msg = 'success|تم الحذف';
    }
}

// ── حفظ باقة ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'offers') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save_offer') {
        $od = $_POST;
        $fields = [
            $od['offer_name'],$od['offer_desc']?:null,
            $od['fore_num']?:null,$od['fore_offer_id']?:null,$od['fore_method']?:'New',$od['momaiz_offer_code']?:null,
            (float)$od['price_yer'],$od['price_usd']?:null,
            $od['validity']?:null,(int)($od['data_mb']?:0)?:(null),(int)($od['minutes']?:0)?:(null),(int)($od['sms']?:0)?:(null),
            $od['badge']?:null,$od['badge_color']?:'#f5a623',
            (int)($od['offer_status']??1),(int)($od['offer_sort']??0)
        ];
        if ($od['offer_id'] ?? '') {
            $pdo->prepare("UPDATE telecom_offers SET
                name=?,description=?,fore_num=?,fore_offer_id=?,fore_method=?,momaiz_offer_code=?,
                price_yer=?,price_usd=?,validity=?,data_mb=?,minutes=?,sms=?,badge=?,badge_color=?,
                status=?,sort_order=?,group_id=? WHERE id=?")
                ->execute(array_merge($fields,[$od['group_id']?:null,(int)$od['offer_id']]));
        } else {
            $pdo->prepare("INSERT INTO telecom_offers
                (network_id,group_id,name,description,fore_num,fore_offer_id,fore_method,momaiz_offer_code,
                 price_yer,price_usd,validity,data_mb,minutes,sms,badge,badge_color,status,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(array_merge([(int)$od['offer_network_id'],$od['group_id']?:null],$fields));
        }
        $msg = 'success|تمت العملية ✓';
    }
    if ($act === 'delete_offer') {
        $pdo->prepare("DELETE FROM telecom_offers WHERE id=?")->execute([(int)$_POST['offer_id']]);
        $msg = 'success|تم الحذف';
    }
}

// ── جلب البيانات ─────────────────────────────────────────────────────────────
$networks = $pdo->query("SELECT * FROM telecom_networks ORDER BY sort_order")->fetchAll();
$settings_keys = ['telecom_provider','telecom_exchange_rate','telecom_sadad_rate','telecom_profit_pct','telecom_enabled',
                  'fore_domain','fore_userid','fore_username','fore_password',
                  'momaiz_username','momaiz_password','momaiz_account_number','momaiz_api_token'];
$cfg = [];
foreach ($settings_keys as $k) $cfg[$k] = getSetting($k) ?? '';
if ((float)$cfg['telecom_sadad_rate'] <= 0) {
    $cfg['telecom_sadad_rate'] = $cfg['telecom_exchange_rate'] ?: 1700;
}

[$msgType, $msgText] = $msg ? explode('|', $msg, 2) : ['', ''];

include '../admin/header.php';
?>
<style>
.tc-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.25rem}
.tc-tabs{display:flex;gap:0.5rem;margin-bottom:1.5rem;flex-wrap:wrap}
.tc-tab{padding:0.6rem 1.2rem;border-radius:var(--radius);background:var(--card);border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:0.9rem;cursor:pointer}
.tc-tab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
.net-card{background:var(--card);border:2px solid var(--border);border-radius:var(--radius);padding:1rem;cursor:pointer;transition:all .2s}
.net-card:hover,.net-card.selected{border-color:var(--primary);background:var(--card2)}
.offer-row{display:flex;align-items:center;gap:0.75rem;padding:0.65rem;background:var(--darker);border-radius:8px;margin-bottom:0.5rem;font-size:0.85rem}
.offer-row .oi{flex:1;min-width:0}
.badge-s{font-size:0.7rem;padding:2px 8px;border-radius:4px}
.cb-row{display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem}
</style>

<div class="page-header"><h2><i class="fas fa-sim-card"></i> كبينة السداد</h2></div>

<?php if ($msgType): ?>
<div class="alert alert-<?= $msgType ?>" style="margin-bottom:1rem">
    <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= htmlspecialchars($msgText) ?>
</div>
<?php endif; ?>

<!-- تبويبات -->
<div class="tc-tabs">
    <?php foreach ([
        'settings' => ['fas fa-cog','الإعدادات'],
        'networks' => ['fas fa-signal','الشبكات'],
        'offers'   => ['fas fa-box','الباقات'],
        'orders'   => ['fas fa-list','العمليات'],
    ] as $t => $info): ?>
    <a href="?tab=<?= $t ?>" class="tc-tab <?= $tab===$t?'active':'' ?>">
        <i class="<?= $info[0] ?>"></i> <?= $info[1] ?>
    </a>
    <?php endforeach; ?>
</div>

<?php /* ══ تبويب الإعدادات ══ */ if ($tab === 'settings'): ?>
<form method="POST">
        <?= adminCsrfField() ?>
<div class="tc-card">
    <h3 style="margin-bottom:1rem;font-size:1rem"><i class="fas fa-plug"></i> إعدادات عامة</h3>
    <div class="grid3">
        <div class="form-group">
            <label>المزود الافتراضي</label>
            <select name="telecom_provider" class="form-control">
                <option value="fore"   <?= $cfg['telecom_provider']==='fore'?'selected':'' ?>>Fore Yemen</option>
                <option value="momaiz" <?= $cfg['telecom_provider']==='momaiz'?'selected':'' ?>>Al Momaiz</option>
            </select>
        </div>
        <div class="form-group">
            <label><i class="fas fa-exchange-alt"></i> سعر الصرف (1$ = ؟ ر.ي)</label>
            <input type="number" name="telecom_exchange_rate" class="form-control" value="<?= $cfg['telecom_exchange_rate'] ?>" step="1">
            <small class="text-muted">إعداد قديم للتوافق مع سجل exchange_rates/YER؛ لا يغير سعر كابينة السداد المستقل.</small>
        </div>
        <div class="form-group">
            <label><i class="fas fa-coins"></i> سعر صرف كابينة السداد (1$ = ؟ ر.ي)</label>
            <input type="number" name="telecom_sadad_rate" class="form-control" value="<?= htmlspecialchars((string)$cfg['telecom_sadad_rate']) ?>" step="0.01" min="0.000001" required>
            <small class="text-muted">مستقل عن سعر شحن الرصيد. مثال: 530 يعني أن 530 ريالًا تخصم 1 دولار.</small>
        </div>
        <div class="form-group">
            <label><i class="fas fa-percentage"></i> نسبة الربح %</label>
            <input type="number" name="telecom_profit_pct" class="form-control" value="<?= $cfg['telecom_profit_pct'] ?>" step="0.1" min="0">
            <small class="text-muted">تُضاف على سعر الريال تلقائياً</small>
        </div>
    </div>
    <div class="form-group">
        <div class="cb-row">
            <input type="checkbox" name="telecom_enabled" value="1" id="tcEn" <?= $cfg['telecom_enabled']?'checked':'' ?>>
            <label for="tcEn">تفعيل كبينة السداد</label>
        </div>
    </div>
</div>

<div class="tc-card">
    <h3 style="margin-bottom:1rem;font-size:1rem"><i class="fas fa-server"></i> بيانات Fore Yemen API</h3>
    <div class="grid2">
        <div class="form-group">
            <label>رابط المزود (Domain)</label>
            <input type="text" name="fore_domain" class="form-control" value="<?= htmlspecialchars($cfg['fore_domain']) ?>" placeholder="https://fore.yemoney.net/api/yr">
        </div>
        <div class="form-group">
            <label>Userid</label>
            <input type="text" name="fore_userid" class="form-control" value="<?= htmlspecialchars($cfg['fore_userid']) ?>">
        </div>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="fore_username" class="form-control" value="<?= htmlspecialchars($cfg['fore_username']) ?>">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="fore_password" class="form-control" value="<?= htmlspecialchars($cfg['fore_password']) ?>" autocomplete="new-password">
        </div>
    </div>
    <button type="submit" name="test_connection" value="1" class="btn btn-secondary">
        <i class="fas fa-plug"></i> اختبار الاتصال
    </button>
</div>

<div class="tc-card">
    <h3 style="margin-bottom:1rem;font-size:1rem"><i class="fas fa-server"></i> بيانات Al Momaiz (بديل)</h3>
    <div class="grid2">
        <div class="form-group"><label>Username</label><input type="text" name="momaiz_username" class="form-control" value="<?= htmlspecialchars($cfg['momaiz_username']) ?>"></div>
        <div class="form-group"><label>Password</label><input type="password" name="momaiz_password" class="form-control" value="<?= htmlspecialchars($cfg['momaiz_password']) ?>" autocomplete="new-password"></div>
        <div class="form-group"><label>Account Number</label><input type="text" name="momaiz_account_number" class="form-control" value="<?= htmlspecialchars($cfg['momaiz_account_number']) ?>"></div>
        <div class="form-group"><label>API Token</label><input type="text" name="momaiz_api_token" class="form-control" value="<?= htmlspecialchars($cfg['momaiz_api_token']) ?>"></div>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
</form>

<?php /* ══ تبويب الشبكات ══ */ elseif ($tab === 'networks'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
    <h3 style="font-size:1rem"><i class="fas fa-signal"></i> شبكات الاتصال</h3>
    <button class="btn btn-primary btn-sm" onclick="showNetForm()"><i class="fas fa-plus"></i> إضافة شبكة</button>
</div>

<!-- قائمة الشبكات -->
<div id="netsList">
<?php foreach ($networks as $net):
    $pref = json_decode($net['prefixes'] ?? '[]', true) ?? [];
?>
<div class="tc-card" style="border-right:4px solid <?= htmlspecialchars($net['color']) ?>">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <div style="width:42px;height:42px;border-radius:10px;background:<?= htmlspecialchars($net['color']) ?>22;display:flex;align-items:center;justify-content:center;color:<?= htmlspecialchars($net['color']) ?>;font-size:1.2rem;flex-shrink:0">
            <i class="fas fa-<?= htmlspecialchars($net['icon']) ?>"></i>
        </div>
        <div style="flex:1">
            <div style="font-weight:700"><?= htmlspecialchars($net['name']) ?></div>
            <div style="font-size:0.78rem;color:var(--text-muted)">
                البادئات: <?= implode(', ', $pref) ?> &nbsp;|&nbsp;
                <?= $net['amount_type']==='units'?'شحن بالوحدات':'شحن بالريال' ?>
                <?php if ($net['amount_type']==='units'): ?> (1 وحدة = <?= $net['unit_price'] ?> ر.ي)<?php endif; ?>
            </div>
            <div style="font-size:0.78rem;color:var(--text-muted);margin-top:2px">
                Endpoint: <code><?= htmlspecialchars($net['fore_endpoint']??'—') ?></code> &nbsp;
                <?= $net['supports_balance']?'<span class="badge badge-success" style="font-size:0.65rem">رصيد</span>':'' ?>
                <?= $net['supports_offers']?'<span class="badge badge-info" style="font-size:0.65rem">باقات</span>':'' ?>
                <?= $net['supports_loan']?'<span class="badge badge-warning" style="font-size:0.65rem">سلفة</span>':'' ?>
            </div>
        </div>
        <div style="display:flex;gap:0.5rem">
            <button class="btn btn-secondary btn-sm" onclick='editNet(<?= json_encode($net,JSON_UNESCAPED_UNICODE) ?>)'>
                <i class="fas fa-edit"></i>
            </button>
            <form method="POST" onsubmit="return confirm('حذف الشبكة؟')">
        <?= adminCsrfField() ?>
                <input type="hidden" name="act" value="delete_network">
                <input type="hidden" name="net_id" value="<?= $net['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>

    <!-- الفئات السريعة والمجموعات -->
    <?php
    $qAmounts = $pdo->prepare("SELECT * FROM telecom_quick_amounts WHERE network_id=? ORDER BY sort_order");
    $qAmounts->execute([$net['id']]); $qAmounts = $qAmounts->fetchAll();
    $groups   = $pdo->prepare("SELECT * FROM telecom_offer_groups WHERE network_id=? AND status=1 ORDER BY sort_order");
    $groups->execute([$net['id']]); $groups = $groups->fetchAll();
    ?>
    <div style="margin-top:1rem;display:flex;gap:1.5rem;flex-wrap:wrap">
        <!-- فئات الشحن السريع -->
        <div style="flex:1;min-width:200px">
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.4rem">
                <i class="fas fa-bolt"></i> فئات الشحن السريع
                <button class="btn btn-sm" style="padding:1px 6px;font-size:0.7rem;margin-right:6px;background:var(--card2)"
                    onclick="showQuickForm(<?= $net['id'] ?>)">+ إضافة</button>
            </div>
            <?php foreach($qAmounts as $qa): ?>
            <div style="display:inline-flex;align-items:center;gap:4px;background:var(--darker);border-radius:6px;padding:3px 8px;margin:2px;font-size:0.8rem">
                <?= number_format($qa['amount'],0) ?> ر.ي
                <?php if($qa['fore_num']): ?><span style="color:var(--text-muted)">(<?= $qa['fore_num'] ?>)</span><?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
                    <input type="hidden" name="act" value="delete_quick">
                    <input type="hidden" name="qa_id" value="<?= $qa['id'] ?>">
                    <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:0.7rem">×</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- مجموعات الباقات -->
        <?php if ($net['supports_offers']): ?>
        <div style="flex:1;min-width:200px">
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.4rem">
                <i class="fas fa-layer-group"></i> مجموعات الباقات
                <button class="btn btn-sm" style="padding:1px 6px;font-size:0.7rem;margin-right:6px;background:var(--card2)"
                    onclick="showGroupForm(<?= $net['id'] ?>)">+ إضافة</button>
            </div>
            <?php foreach($groups as $g): ?>
            <div style="display:inline-flex;align-items:center;gap:4px;background:var(--darker);border-radius:6px;padding:3px 8px;margin:2px;font-size:0.8rem">
                <i class="fas fa-<?= htmlspecialchars($g['icon']) ?>"></i> <?= htmlspecialchars($g['name']) ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
                    <input type="hidden" name="act" value="delete_group">
                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                    <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:0.7rem">×</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- نموذج الشبكة (مخفي) -->
<div id="netModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;overflow-y:auto;padding:2rem">
<div style="max-width:820px;margin:auto;background:var(--card);border-radius:var(--radius);padding:1.5rem">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h3 style="font-size:1rem" id="netModalTitle"><i class="fas fa-signal"></i> إضافة شبكة</h3>
    <button onclick="document.getElementById('netModal').style.display='none'" style="background:none;border:none;color:var(--text);font-size:1.3rem;cursor:pointer">×</button>
</div>
<form method="POST">
        <?= adminCsrfField() ?>
<input type="hidden" name="act" value="save_network">
<input type="hidden" name="net_id" id="fNetId">
<div class="grid2">
    <div class="form-group"><label>الاسم العربي *</label><input type="text" name="name" id="fName" class="form-control" required></div>
    <div class="form-group"><label>الاسم الإنجليزي</label><input type="text" name="name_en" id="fNameEn" class="form-control"></div>
    <div class="form-group">
        <label>البادئات (مفصولة بفاصلة) *</label>
        <input type="text" name="prefixes" id="fPrefixes" class="form-control" placeholder="77,78" required>
        <small class="text-muted">أول رقمين من الرقم — مثال: 77,78</small>
    </div>
    <div class="form-group">
        <label>اللون</label>
        <input type="color" name="color" id="fColor" value="#6c3fe0" class="form-control" style="height:38px">
    </div>
    <div class="form-group"><label>أيقونة Font Awesome (بدون fas fa-)</label><input type="text" name="icon" id="fIcon" class="form-control" value="sim-card"></div>
    <div class="form-group"><label>الترتيب</label><input type="number" name="sort_order" id="fSort" class="form-control" value="0"></div>
</div>

<div class="tc-card" style="background:var(--darker)">
<h4 style="font-size:0.9rem;margin-bottom:0.75rem"><i class="fas fa-check-square"></i> الميزات المدعومة</h4>
<div style="display:flex;flex-wrap:wrap;gap:1rem">
    <div class="cb-row"><input type="checkbox" name="supports_balance" value="1" id="fSbal"><label for="fSbal">رصيد مفتوح</label></div>
    <div class="cb-row"><input type="checkbox" name="supports_offers"  value="1" id="fSoff"><label for="fSoff">باقات</label></div>
    <div class="cb-row"><input type="checkbox" name="supports_loan"    value="1" id="fSlon"><label for="fSlon">سلفة</label></div>
    <div class="cb-row"><input type="checkbox" name="supports_landline_type" value="1" id="fSll"><label for="fSll">خيار هاتف/انترنت</label></div>
</div>
</div>

<div class="tc-card" style="background:var(--darker)">
<h4 style="font-size:0.9rem;margin-bottom:0.75rem"><i class="fas fa-coins"></i> نوع الشحن</h4>
<div class="grid3">
    <div class="form-group">
        <label>النوع</label>
        <select name="amount_type" id="fAmtType" class="form-control">
            <option value="value">ريال يمني مباشرة</option>
            <option value="units">وحدات</option>
        </select>
    </div>
    <div class="form-group"><label>سعر الوحدة الواحدة (ر.ي)</label><input type="number" name="unit_price" id="fUnitPrice" class="form-control" value="1" step="0.01"></div>
    <div class="form-group"><label>الحد الأدنى (ر.ي)</label><input type="number" name="min_amount" id="fMin" class="form-control" value="100"></div>
    <div class="form-group"><label>الحد الأقصى (ر.ي)</label><input type="number" name="max_amount" id="fMax" class="form-control" value="50000"></div>
</div>
</div>

<div class="tc-card" style="background:var(--darker)">
<h4 style="font-size:0.9rem;margin-bottom:0.75rem"><i class="fas fa-plug"></i> ربط Fore Yemen</h4>
<div class="grid3">
    <div class="form-group">
        <label>Endpoint الرئيسي</label>
        <select name="fore_endpoint" id="fEp" class="form-control">
            <option value="">— لا يوجد —</option>
            <option value="yem">yem (يمن موبايل/يو)</option>
            <option value="post">post (هاتف/انترنت)</option>
            <option value="why">why (واي)</option>
            <option value="sabaphone">sabaphone (سبافون)</option>
            <option value="mtn">mtn (MTN)</option>
        </select>
    </div>
    <div class="form-group">
        <label>Endpoint الباقات</label>
        <select name="fore_offer_endpoint" id="fOEp" class="form-control">
            <option value="">— نفس الرئيسي —</option>
            <option value="yem">yem</option>
            <option value="sabaoffer">sabaoffer</option>
            <option value="mtnoffer">mtnoffer</option>
        </select>
    </div>
    <div class="form-group">
        <label>post type (للأرضي)</label>
        <select name="fore_post_type" id="fPostType" class="form-control">
            <option value="">— لا ينطبق —</option>
            <option value="line">line (هاتف ثابت)</option>
            <option value="adsl">adsl (انترنت)</option>
        </select>
    </div>
    <div class="form-group"><label>Query Action</label><input type="text" name="fore_query_action" id="fQA" class="form-control" value="query" placeholder="query"></div>
    <div class="form-group"><label>Bill Action</label><input type="text" name="fore_bill_action" id="fBA" class="form-control" value="bill" placeholder="bill"></div>
</div>
</div>

<div class="tc-card" style="background:var(--darker)">
<h4 style="font-size:0.9rem;margin-bottom:0.75rem"><i class="fas fa-server"></i> ربط Al Momaiz (اختياري)</h4>
<div class="grid3">
    <div class="form-group"><label>رقم الشبكة</label><input type="number" name="momaiz_net_number" id="fMN" class="form-control"></div>
    <div class="form-group"><label>خدمة رصيد</label><input type="number" name="momaiz_svc_balance" id="fMB" class="form-control"></div>
    <div class="form-group"><label>خدمة شحن</label><input type="number" name="momaiz_svc_pay_balance" id="fMP" class="form-control"></div>
</div>
</div>

<div class="form-group">
    <label>الحالة</label>
    <select name="net_status" id="fNSt" class="form-control" style="width:auto">
        <option value="1">مفعّل</option>
        <option value="0">معطّل</option>
    </select>
</div>
<div style="display:flex;gap:0.75rem">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
    <button type="button" onclick="document.getElementById('netModal').style.display='none'" class="btn btn-secondary">إلغاء</button>
</div>
</form>
</div>
</div>

<!-- نموذج الفئة السريعة -->
<div id="qaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;display:flex;align-items:center;justify-content:center">
<div style="width:400px;background:var(--card);border-radius:var(--radius);padding:1.5rem">
<h3 style="margin-bottom:1rem;font-size:0.95rem"><i class="fas fa-bolt"></i> إضافة فئة شحن سريع</h3>
<form method="POST">
        <?= adminCsrfField() ?>
<input type="hidden" name="act" value="save_quick">
<input type="hidden" name="qa_net_id" id="qaNid">
<div class="form-group"><label>المبلغ (ر.ي)</label><input type="number" name="qa_amount" class="form-control" required></div>
<div class="form-group"><label>التسمية (اختياري)</label><input type="text" name="qa_label" class="form-control" placeholder="مثال: 500 ر.ي"></div>
<div class="form-group"><label>رقم num في المزود (للشبكات بالوحدات)</label><input type="number" name="qa_fore_num" class="form-control"></div>
<div class="form-group"><label>الترتيب</label><input type="number" name="qa_sort" class="form-control" value="0"></div>
<div style="display:flex;gap:0.5rem">
    <button type="submit" class="btn btn-primary">حفظ</button>
    <button type="button" onclick="document.getElementById('qaModal').style.display='none'" class="btn btn-secondary">إلغاء</button>
</div>
</form>
</div>
</div>

<!-- نموذج المجموعة -->
<div id="grpModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;display:flex;align-items:center;justify-content:center">
<div style="width:400px;background:var(--card);border-radius:var(--radius);padding:1.5rem">
<h3 style="margin-bottom:1rem;font-size:0.95rem"><i class="fas fa-layer-group"></i> إضافة مجموعة باقات</h3>
<form method="POST">
        <?= adminCsrfField() ?>
<input type="hidden" name="act" value="save_group">
<input type="hidden" name="gnet_id" id="grpNid">
<div class="form-group"><label>اسم المجموعة</label><input type="text" name="gname" class="form-control" required placeholder="مثال: باقات 4G"></div>
<div class="form-group"><label>أيقونة</label><input type="text" name="gicon" class="form-control" value="box" placeholder="wifi / signal / phone"></div>
<div class="form-group"><label>الترتيب</label><input type="number" name="gsort" class="form-control" value="0"></div>
<div style="display:flex;gap:0.5rem">
    <button type="submit" class="btn btn-primary">حفظ</button>
    <button type="button" onclick="document.getElementById('grpModal').style.display='none'" class="btn btn-secondary">إلغاء</button>
</div>
</form>
</div>
</div>

<script>
function showNetForm(){
    document.getElementById('netModalTitle').innerHTML='<i class="fas fa-plus"></i> إضافة شبكة جديدة';
    document.getElementById('fNetId').value='';
    ['fName','fNameEn','fPrefixes','fIcon','fSort','fQA','fBA'].forEach(id=>{
        const el=document.getElementById(id); if(el)el.value=id==='fIcon'?'sim-card':id==='fSort'?'0':id==='fQA'?'query':id==='fBA'?'bill':'';
    });
    document.getElementById('fColor').value='#6c3fe0';
    document.getElementById('fMin').value='100'; document.getElementById('fMax').value='50000';
    document.getElementById('fUnitPrice').value='1';
    ['fSbal','fSoff','fSlon','fSll'].forEach(id=>{ const el=document.getElementById(id); if(el)el.checked=false; });
    document.getElementById('netModal').style.display='flex';
    document.getElementById('netModal').style.alignItems='flex-start';
}
function editNet(net){
    document.getElementById('netModalTitle').innerHTML='<i class="fas fa-edit"></i> تعديل الشبكة: '+net.name;
    document.getElementById('fNetId').value=net.id;
    document.getElementById('fName').value=net.name;
    document.getElementById('fNameEn').value=net.name_en||'';
    const pref=JSON.parse(net.prefixes||'[]');
    document.getElementById('fPrefixes').value=pref.join(',');
    document.getElementById('fColor').value=net.color||'#6c3fe0';
    document.getElementById('fIcon').value=net.icon||'sim-card';
    document.getElementById('fSort').value=net.sort_order||0;
    document.getElementById('fSbal').checked=net.supports_balance==1;
    document.getElementById('fSoff').checked=net.supports_offers==1;
    document.getElementById('fSlon').checked=net.supports_loan==1;
    document.getElementById('fSll').checked=net.supports_landline_type==1;
    document.getElementById('fAmtType').value=net.amount_type||'value';
    document.getElementById('fUnitPrice').value=net.unit_price||1;
    document.getElementById('fMin').value=net.min_amount||100;
    document.getElementById('fMax').value=net.max_amount||50000;
    document.getElementById('fEp').value=net.fore_endpoint||'';
    document.getElementById('fOEp').value=net.fore_offer_endpoint||'';
    document.getElementById('fPostType').value=net.fore_post_type||'';
    document.getElementById('fQA').value=net.fore_query_action||'query';
    document.getElementById('fBA').value=net.fore_bill_action||'bill';
    document.getElementById('fMN').value=net.momaiz_net_number||'';
    document.getElementById('fMB').value=net.momaiz_svc_balance||'';
    document.getElementById('fMP').value=net.momaiz_svc_pay_balance||'';
    document.getElementById('fNSt').value=net.status;
    document.getElementById('netModal').style.display='flex';
    document.getElementById('netModal').style.alignItems='flex-start';
}
function showQuickForm(netId){
    document.getElementById('qaNid').value=netId;
    document.getElementById('qaModal').style.display='flex';
}
function showGroupForm(netId){
    document.getElementById('grpNid').value=netId;
    document.getElementById('grpModal').style.display='flex';
}
// إغلاق بالضغط خارج النموذج
['netModal','qaModal','grpModal'].forEach(id=>{
    document.getElementById(id)?.addEventListener('click',e=>{if(e.target.id===id)e.target.style.display='none';});
});
</script>

<?php /* ══ تبويب الباقات ══ */ elseif ($tab === 'offers'): ?>

<?php
$networks2 = $pdo->query("SELECT * FROM telecom_networks WHERE status=1 ORDER BY sort_order")->fetchAll();
$selNet    = (int)($_GET['net'] ?? ($networks2[0]['id'] ?? 0));

$offerGroups= $pdo->prepare("SELECT * FROM telecom_offer_groups WHERE network_id=? ORDER BY sort_order");
$offerGroups->execute([$selNet]); $offerGroups=$offerGroups->fetchAll();

$offers = $pdo->prepare("SELECT o.*,g.name as gname FROM telecom_offers o LEFT JOIN telecom_offer_groups g ON o.group_id=g.id WHERE o.network_id=? ORDER BY o.group_id,o.sort_order,o.price_yer");
$offers->execute([$selNet]); $offers=$offers->fetchAll();
?>

<div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem">
    <label style="color:var(--text-muted);font-size:0.9rem">الشبكة:</label>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
    <?php foreach($networks2 as $n): ?>
    <a href="?tab=offers&net=<?= $n['id'] ?>" class="sub-cat-btn <?= $n['id']==$selNet?'active':'' ?>" style="font-size:0.85rem">
        <i class="fas fa-<?= htmlspecialchars($n['icon']) ?>"></i> <?= htmlspecialchars($n['name']) ?>
    </a>
    <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-sm" onclick="showOfferForm()" style="margin-right:auto">
        <i class="fas fa-plus"></i> إضافة باقة
    </button>
</div>

<?php if ($offers): ?>
<div style="overflow-x:auto">
<table class="table" style="font-size:0.83rem">
<thead><tr>
    <th>الاسم</th><th>المجموعة</th>
    <th>السعر (ر.ي)</th><th>fore_num / offer_id</th>
    <th>الصلاحية</th><th>بيانات</th><th>حالة</th><th>إجراء</th>
</tr></thead>
<tbody>
<?php foreach($offers as $o): ?>
<tr>
    <td><?= htmlspecialchars($o['name']) ?></td>
    <td><span style="font-size:0.75rem;color:var(--text-muted)"><?= htmlspecialchars($o['gname']??'—') ?></span></td>
    <td><strong style="color:var(--secondary)"><?= number_format($o['price_yer'],0) ?></strong></td>
    <td style="font-family:monospace;font-size:0.75rem"><?= $o['fore_num']?'num:'.$o['fore_num']:'' ?> <?= $o['fore_offer_id']?'id:'.$o['fore_offer_id']:'' ?></td>
    <td><?= htmlspecialchars($o['validity']??'—') ?></td>
    <td style="font-size:0.72rem;color:var(--text-muted)">
        <?= $o['data_mb']?($o['data_mb']>=1024?round($o['data_mb']/1024).'G':$o['data_mb'].'M').' انترنت':'' ?>
        <?= $o['minutes']?$o['minutes'].'د':'' ?>
    </td>
    <td><span class="badge badge-<?= $o['status']?'success':'danger' ?>"><?= $o['status']?'مفعّل':'معطّل' ?></span></td>
    <td>
        <button class="btn btn-secondary btn-sm" onclick='editOffer(<?= json_encode($o,JSON_UNESCAPED_UNICODE) ?>)'><i class="fas fa-edit"></i></button>
        <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
            <input type="hidden" name="act" value="delete_offer">
            <input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><i class="fas fa-box-open"></i><p>لا توجد باقات لهذه الشبكة</p></div>
<?php endif; ?>

<!-- نموذج الباقة -->
<div id="offerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;overflow-y:auto;padding:2rem">
<div style="max-width:700px;margin:auto;background:var(--card);border-radius:var(--radius);padding:1.5rem">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
    <h3 style="font-size:1rem" id="offerModalTitle"><i class="fas fa-box"></i> إضافة باقة</h3>
    <button onclick="document.getElementById('offerModal').style.display='none'" style="background:none;border:none;color:var(--text);font-size:1.3rem;cursor:pointer">×</button>
</div>
<form method="POST">
        <?= adminCsrfField() ?>
<input type="hidden" name="act" value="save_offer">
<input type="hidden" name="offer_id" id="oId">
<input type="hidden" name="offer_network_id" value="<?= $selNet ?>">
<div class="grid2">
    <div class="form-group">
        <label>اسم الباقة *</label>
        <input type="text" name="offer_name" id="oName" class="form-control" required>
    </div>
    <div class="form-group">
        <label>المجموعة</label>
        <select name="group_id" id="oGroup" class="form-control">
            <option value="">— بدون مجموعة —</option>
            <?php foreach($offerGroups as $g): ?>
            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>السعر للعميل (ر.ي) *</label>
        <input type="number" name="price_yer" id="oPriceYer" class="form-control" required step="0.01">
    </div>
    <div class="form-group">
        <label>التكلفة الفعلية ($ - اختياري)</label>
        <input type="number" name="price_usd" id="oPriceUsd" class="form-control" step="0.0001">
    </div>
    <div class="form-group">
        <label>fore_num (رقم الباقة في المزود)</label>
        <input type="number" name="fore_num" id="oFNum" class="form-control">
    </div>
    <div class="form-group">
        <label>fore_offer_id (لـ billoffer)</label>
        <input type="text" name="fore_offer_id" id="oFOId" class="form-control" placeholder="مثال: A69385">
    </div>
    <div class="form-group">
        <label>fore_method</label>
        <select name="fore_method" id="oFMethod" class="form-control">
            <option value="New">New</option>
            <option value="Renew">Renew</option>
            <option value="Remove">Remove</option>
        </select>
    </div>
    <div class="form-group">
        <label>Al Momaiz offer_code</label>
        <input type="text" name="momaiz_offer_code" id="oMCode" class="form-control">
    </div>
    <div class="form-group">
        <label>الصلاحية</label>
        <input type="text" name="validity" id="oValid" class="form-control" placeholder="شهر / 30 يوم">
    </div>
    <div class="form-group">
        <label>إنترنت (ميجا)</label>
        <input type="number" name="data_mb" id="oData" class="form-control">
    </div>
    <div class="form-group">
        <label>دقائق</label>
        <input type="number" name="minutes" id="oMin" class="form-control">
    </div>
    <div class="form-group">
        <label>رسائل SMS</label>
        <input type="number" name="sms" id="oSms" class="form-control">
    </div>
    <div class="form-group">
        <label>شارة (مثل: الأفضل)</label>
        <input type="text" name="badge" id="oBadge" class="form-control">
    </div>
    <div class="form-group">
        <label>لون الشارة</label>
        <input type="color" name="badge_color" id="oBadgeColor" value="#f5a623" class="form-control" style="height:38px">
    </div>
    <div class="form-group">
        <label>الترتيب</label>
        <input type="number" name="offer_sort" id="oSort" class="form-control" value="0">
    </div>
    <div class="form-group">
        <label>الحالة</label>
        <select name="offer_status" id="oSt" class="form-control">
            <option value="1">مفعّل</option>
            <option value="0">معطّل</option>
        </select>
    </div>
</div>
<div class="form-group">
    <label>وصف (اختياري)</label>
    <textarea name="offer_desc" id="oDesc" class="form-control" rows="2"></textarea>
</div>
<div style="display:flex;gap:0.75rem">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
    <button type="button" onclick="document.getElementById('offerModal').style.display='none'" class="btn btn-secondary">إلغاء</button>
</div>
</form>
</div>
</div>
<script>
function showOfferForm(){
    document.getElementById('offerModalTitle').innerHTML='<i class="fas fa-plus"></i> إضافة باقة جديدة';
    ['oId','oName','oFNum','oFOId','oMCode','oValid','oData','oMin','oSms','oBadge','oDesc','oPriceYer','oPriceUsd'].forEach(id=>{
        const el=document.getElementById(id); if(el)el.value='';
    });
    document.getElementById('oSort').value=0;
    document.getElementById('oGroup').value='';
    document.getElementById('oFMethod').value='New';
    document.getElementById('oBadgeColor').value='#f5a623';
    document.getElementById('oSt').value='1';
    document.getElementById('offerModal').style.display='flex';
    document.getElementById('offerModal').style.alignItems='flex-start';
}
function editOffer(o){
    document.getElementById('offerModalTitle').innerHTML='<i class="fas fa-edit"></i> تعديل: '+o.name;
    document.getElementById('oId').value=o.id;
    document.getElementById('oName').value=o.name||'';
    document.getElementById('oGroup').value=o.group_id||'';
    document.getElementById('oPriceYer').value=o.price_yer||'';
    document.getElementById('oPriceUsd').value=o.price_usd||'';
    document.getElementById('oFNum').value=o.fore_num||'';
    document.getElementById('oFOId').value=o.fore_offer_id||'';
    document.getElementById('oFMethod').value=o.fore_method||'New';
    document.getElementById('oMCode').value=o.momaiz_offer_code||'';
    document.getElementById('oValid').value=o.validity||'';
    document.getElementById('oData').value=o.data_mb||'';
    document.getElementById('oMin').value=o.minutes||'';
    document.getElementById('oSms').value=o.sms||'';
    document.getElementById('oBadge').value=o.badge||'';
    document.getElementById('oBadgeColor').value=o.badge_color||'#f5a623';
    document.getElementById('oSort').value=o.sort_order||0;
    document.getElementById('oDesc').value=o.description||'';
    document.getElementById('oSt').value=o.status;
    document.getElementById('offerModal').style.display='flex';
    document.getElementById('offerModal').style.alignItems='flex-start';
}
document.getElementById('offerModal')?.addEventListener('click',e=>{if(e.target.id==='offerModal')e.target.style.display='none';});
</script>

<?php /* ══ تبويب العمليات ══ */ elseif ($tab === 'orders'): ?>
<?php
$page = max(1,(int)($_GET['p']??1));
$pp   = 30;
$total= $pdo->query("SELECT COUNT(*) FROM telecom_orders")->fetchColumn();
$rows = $pdo->prepare("SELECT t.*,u.username,n.name as net_name,o.name as offer_name FROM telecom_orders t JOIN users u ON t.user_id=u.id JOIN telecom_networks n ON t.network_id=n.id LEFT JOIN telecom_offers o ON t.offer_id=o.id ORDER BY t.created_at DESC LIMIT $pp OFFSET ".(($page-1)*$pp));
$rows->execute(); $rows=$rows->fetchAll();
$stMap=['pending'=>['انتظار','warning'],'processing'=>['تنفيذ','info'],'completed'=>['مكتمل','success'],'failed'=>['فشل','danger'],'cancelled'=>['ملغي','secondary']];
?>
<div style="overflow-x:auto">
<table class="table" style="font-size:0.82rem">
<thead><tr><th>#</th><th>العميل</th><th>الشبكة</th><th>الرقم</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead>
<tbody>
<?php foreach($rows as $r):
    [$stL,$stC]=$stMap[$r['status']]??['—','secondary'];
?>
<tr>
    <td><?= $r['id'] ?></td>
    <td><?= htmlspecialchars($r['username']) ?></td>
    <td><?= htmlspecialchars($r['net_name']) ?></td>
    <td style="direction:ltr"><?= htmlspecialchars($r['mobile_number']) ?></td>
    <td><?= $r['order_type']==='offer'?'باقة: '.htmlspecialchars($r['offer_name']??'—'):'رصيد' ?></td>
    <td><strong><?= number_format($r['amount_yer']??0,0) ?></strong> ر.ي</td>
    <td><span class="badge badge-<?= $stC ?>"><?= $stL ?></span></td>
    <td style="font-size:0.75rem"><?= date('d/m H:i', strtotime($r['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
$pages = ceil($total/$pp);
if ($pages > 1): ?>
<div style="display:flex;gap:0.5rem;justify-content:center;margin-top:1rem">
<?php for($i=1;$i<=$pages;$i++): ?>
<a href="?tab=orders&p=<?= $i ?>" class="btn btn-sm <?= $i==$page?'btn-primary':'btn-secondary' ?>"><?= $i ?></a>
<?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include '../admin/footer.php'; ?>
