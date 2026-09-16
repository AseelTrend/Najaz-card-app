<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'نظام الإحالة — ' . SITE_NAME;

// ── إنشاء الجداول المطلوبة ─────────────────────────────────────────────────
try {
    // نسبة مخصصة لمحيل محدد
    $pdo->exec("CREATE TABLE IF NOT EXISTS `referral_custom_rates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `referrer_id` INT NOT NULL,
        `custom_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
        `notes` VARCHAR(300) DEFAULT NULL,
        `created_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_referrer` (`referrer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // نسبة مخصصة لكل خدمة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `referral_service_rates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `service_id` INT NOT NULL,
        `custom_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_service` (`service_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// ── حفظ الإعدادات العامة ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_settings'])) {
    foreach (['referral_enabled','referral_reward_type','referral_fixed_reward','referral_percent','referral_welcome'] as $key) {
        $val = $_POST[$key] ?? '0';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$key,$val,$val]);
    }
    flashMessage('success','✅ تم حفظ إعدادات الإحالة');
    redirect(SITE_URL.'/admin/referrals.php');
}

// ── حفظ نسبة (محيل × خدمة) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_rate'])) {
    $referrerId = $_POST['referrer_id'] !== '' ? (int)$_POST['referrer_id'] : null;
    $serviceId  = $_POST['service_id']  !== '' ? (int)$_POST['service_id']  : null;
    $pct        = (float)$_POST['custom_percent'];
    $notes      = trim($_POST['notes'] ?? '');

    if ($pct <= 0) {
        // حذف
        if ($referrerId && $serviceId) {
            $pdo->prepare("DELETE FROM referral_rates WHERE referrer_id=? AND service_id=?")->execute([$referrerId,$serviceId]);
        } elseif ($referrerId) {
            $pdo->prepare("DELETE FROM referral_rates WHERE referrer_id=? AND service_id IS NULL")->execute([$referrerId]);
            $defaultPct = (float)(getSetting('referral_percent') ?: 0);
            $pdo->prepare("UPDATE referrals SET reward_percent=? WHERE referrer_id=?")->execute([$defaultPct,$referrerId]);
        } elseif ($serviceId) {
            $pdo->prepare("DELETE FROM referral_rates WHERE service_id=? AND referrer_id IS NULL")->execute([$serviceId]);
        }
        flashMessage('success','✅ تم الحذف — يرجع للنسبة الافتراضية');
    } else {
        $pdo->prepare("INSERT INTO referral_rates (referrer_id,service_id,custom_percent,notes,created_by)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE custom_percent=?,notes=?")
            ->execute([$referrerId,$serviceId,$pct,$notes,$_SESSION['user_id'],$pct,$notes]);
        // إذا كانت نسبة المحيل العامة (بدون خدمة محددة) → حدّث referrals أيضاً
        if ($referrerId && !$serviceId) {
            $pdo->prepare("UPDATE referrals SET reward_percent=? WHERE referrer_id=?")->execute([$pct,$referrerId]);
        }
        $typeLabel = $referrerId && $serviceId ? 'محيل + خدمة' : ($referrerId ? 'محيل' : 'خدمة');
        flashMessage('success',"✅ تم حفظ نسبة {$pct}% ({$typeLabel})");
    }
    redirect(SITE_URL.'/admin/referrals.php?tab=rates');
}

// ── حذف نسبة ────────────────────────────────────────────────────────────────
if (isset($_GET['del_rate'])) {
    $rid = (int)$_GET['del_rate'];
    $row = $pdo->prepare("SELECT * FROM referral_rates WHERE id=?");
    $row->execute([$rid]); $row = $row->fetch();
    if ($row) {
        $pdo->prepare("DELETE FROM referral_rates WHERE id=?")->execute([$rid]);
        if ($row['referrer_id'] && !$row['service_id']) {
            $defaultPct = (float)(getSetting('referral_percent') ?: 0);
            $pdo->prepare("UPDATE referrals SET reward_percent=? WHERE referrer_id=?")->execute([$defaultPct,$row['referrer_id']]);
        }
    }
    flashMessage('success','تم الحذف');
    redirect(SITE_URL.'/admin/referrals.php?tab=rates');
}

// ── بيانات ─────────────────────────────────────────────────────────────────
$stats = [
    'total_referrals'  => (int)$pdo->query("SELECT COUNT(*) FROM referrals")->fetchColumn(),
    'rewarded'         => (int)$pdo->query("SELECT COUNT(*) FROM referrals WHERE status='rewarded'")->fetchColumn(),
    'total_commission' => (float)$pdo->query("SELECT COALESCE(SUM(commission_paid),0) FROM referrals")->fetchColumn(),
    'total_welcome'    => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='referral_welcome'")->fetchColumn(),
];

$referrals = $pdo->query("
    SELECT r.*,
           ru.username as referrer_username, ru.full_name as referrer_name,
           rd.username as referred_username, rd.full_name as referred_name
    FROM referrals r
    JOIN users ru ON r.referrer_id=ru.id
    JOIN users rd ON r.referred_id=rd.id
    ORDER BY r.created_at DESC LIMIT 100
")->fetchAll();

$topReferrers = $pdo->query("
    SELECT u.id, u.username, u.full_name, COUNT(r.id) as cnt, SUM(r.commission_paid) as total,
           rcr.custom_percent
    FROM referrals r
    JOIN users u ON r.referrer_id=u.id
    LEFT JOIN referral_custom_rates rcr ON rcr.referrer_id=u.id
    GROUP BY r.referrer_id ORDER BY cnt DESC LIMIT 10
")->fetchAll();

// جميع النسب المخصصة (الجدول الموحد)
$allRates = [];
try {
    $allRates = $pdo->query("
        SELECT rr.*,
               u.username  as referrer_username, u.full_name as referrer_name,
               s.name      as service_name, s.price as service_price
        FROM referral_rates rr
        LEFT JOIN users    u ON u.id = rr.referrer_id
        LEFT JOIN services s ON s.id = rr.service_id
        ORDER BY rr.referrer_id IS NULL, rr.service_id IS NULL, rr.custom_percent DESC
    ")->fetchAll();
} catch(Exception $e) {}

// الخدمات للـ select
$services = $pdo->query("SELECT id,name,price FROM services WHERE status=1 ORDER BY name")->fetchAll();

// قائمة المحيلين (للـ select)
$allReferrers = $pdo->query("
    SELECT DISTINCT u.id, u.username, u.full_name,
           rcr.custom_percent as existing_rate
    FROM referrals r
    JOIN users u ON r.referrer_id=u.id
    LEFT JOIN referral_custom_rates rcr ON rcr.referrer_id=u.id
    ORDER BY u.username
")->fetchAll();

$activeTab = $_GET['tab'] ?? 'settings';

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,200,83,.15);color:#00c853"><i class="fas fa-users"></i></div>
      نظام الإحالة
    </div>
  </div>
</div>

<!-- إحصاءات -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
  <?php foreach([
    ['إجمالي الإحالات',$stats['total_referrals'],'users','#00d4ff'],
    ['مُكافَأة',$stats['rewarded'],'check-circle','#00e676'],
    ['عمولات دُفعت',number_format($stats['total_commission'],2).' $','dollar-sign','#f5a623'],
    ['رصيد ترحيبي',number_format($stats['total_welcome'],2).' $','gift','#7c3aed'],
  ] as [$lbl,$val,$icon,$color]): ?>
  <div class="card" style="text-align:center;padding:16px">
    <div style="font-size:1.8rem;font-weight:900;color:<?=$color?>"><?=$val?></div>
    <div style="font-size:.78rem;color:var(--text3);margin-top:4px"><i class="fas fa-<?=$icon?>"></i> <?=$lbl?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- تبويبات -->
<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach([
    ['settings','cog','الإعدادات العامة'],
    ['rates','sliders-h','النسب المخصصة'],
    ['log','list','سجل الإحالات'],
    ['top','crown','أكثر المُحيلين'],
  ] as [$t,$icon,$lbl]): ?>
  <a href="?tab=<?=$t?>" class="btn btn-sm <?=$activeTab===$t?'btn-primary':'btn-secondary'?>">
    <i class="fas fa-<?=$icon?>"></i> <?=$lbl?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ═══ تبويب الإعدادات العامة ═══ -->
<?php if($activeTab==='settings'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-cog"></i> إعدادات المكافآت العامة</div></div>
    <div class="card-body">
      <form method="POST">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_settings" value="1">
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:12px">
            <input type="checkbox" name="referral_enabled" value="1" <?=getSetting('referral_enabled')?'checked':''?> style="width:16px;height:16px">
            <span style="font-weight:700">تفعيل نظام الإحالة</span>
          </label>
        </div>
        <?php
          $rType = getSetting('referral_reward_type') ?: 'order_percent';
          $rFixed = getSetting('referral_fixed_reward') ?: '1';
          $rPct   = getSetting('referral_percent') ?: '5';
          $rWelc  = getSetting('referral_welcome') ?: '0.5';
        ?>
        <div class="form-group">
          <label><i class="fas fa-sliders-h" style="color:var(--primary)"></i> نوع عمولة المُحيل</label>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px">
            <?php foreach([
              ['first_topup_fixed','💰 مبلغ ثابت عند أول شحن','يحصل المُحيل على مبلغ ثابت مرة واحدة'],
              ['first_topup_percent','📊 نسبة % من أول شحن','نسبة % من مبلغ أول شحن — مرة واحدة'],
              ['order_percent','🔄 نسبة % من كل طلب خدمة','نسبة % من قيمة كل طلب — بلا حدود 🚀'],
            ] as [$val,$title,$sub]): ?>
            <label onclick="setRewardType('<?=$val?>')"
                   style="display:flex;align-items:flex-start;gap:10px;padding:12px;border:1.5px solid <?=$rType===$val?'var(--primary)':'var(--border)'?>;border-radius:10px;cursor:pointer;transition:.2s"
                   id="type_<?=$val?>">
              <input type="radio" name="referral_reward_type" value="<?=$val?>" <?=$rType===$val?'checked':''?> style="margin-top:3px;flex-shrink:0">
              <div>
                <div style="font-weight:700;font-size:.9rem"><?=$title?></div>
                <div style="font-size:.75rem;color:var(--text3);margin-top:3px"><?=$sub?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div id="field_fixed" style="display:<?=$rType==='first_topup_fixed'?'block':'none'?>">
          <div class="form-group">
            <label>المبلغ الثابت ($)</label>
            <input type="number" name="referral_fixed_reward" class="form-control" value="<?=$rFixed?>" min="0" step="0.01">
          </div>
        </div>
        <div id="field_percent" style="display:<?=in_array($rType,['first_topup_percent','order_percent'])?'block':'none'?>">
          <div class="form-group">
            <label>النسبة الافتراضية % <small style="color:var(--text3)">(تُطبَّق على من لا تعيين مخصص له)</small></label>
            <input type="number" name="referral_percent" class="form-control" value="<?=$rPct?>" min="0" max="100" step="0.1">
          </div>
        </div>
        <div class="form-group">
          <label><i class="fas fa-gift" style="color:#7c3aed"></i> رصيد ترحيبي للصديق الجديد ($)</label>
          <input type="number" name="referral_welcome" class="form-control" value="<?=$rWelc?>" min="0" step="0.01">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>

  <!-- شرح أولويات النسب -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-info-circle"></i> أولويات تطبيق النسب</div></div>
    <div class="card-body" style="font-size:.85rem;line-height:2">
      <div style="display:flex;flex-direction:column;gap:10px">
        <div style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);border-radius:10px;padding:12px">
          <div style="font-weight:800;color:#f5a623"><i class="fas fa-1"></i> الأعلى أولوية — نسبة الخدمة</div>
          <div style="color:var(--text3);font-size:.78rem;margin-top:4px">إذا كانت للخدمة المطلوبة نسبة مخصصة → تُطبَّق هذه النسبة</div>
        </div>
        <div style="background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.25);border-radius:10px;padding:12px">
          <div style="font-weight:800;color:#00d4ff"><i class="fas fa-2"></i> ثانياً — نسبة المحيل الشخصية</div>
          <div style="color:var(--text3);font-size:.78rem;margin-top:4px">إذا كان للمحيل نسبة مخصصة → تُطبَّق على كل طلبات مُحالِيه</div>
        </div>
        <div style="background:rgba(0,200,83,.08);border:1px solid rgba(0,200,83,.25);border-radius:10px;padding:12px">
          <div style="font-weight:800;color:#00c853"><i class="fas fa-3"></i> الافتراضي — النسبة العامة</div>
          <div style="color:var(--text3);font-size:.78rem;margin-top:4px">النسبة الافتراضية في الإعدادات تُطبَّق على الجميع</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══ تبويب النسب المخصصة (موحد) ═══ -->
<?php elseif($activeTab==='rates'): ?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:16px">

  <!-- ── فورم إضافة نسبة ── -->
  <div class="card" style="height:fit-content">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-plus-circle" style="color:#00d4aa"></i> إضافة / تعديل نسبة</div></div>
    <div class="card-body">
      <form method="POST" id="rateForm">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_rate" value="1">

        <!-- المحيل -->
        <div class="form-group">
          <label style="font-size:.82rem;font-weight:700">المحيل
            <small style="color:var(--text3);font-weight:400">(فارغ = جميع المحيلين)</small>
          </label>
          <select name="referrer_id" class="form-control" id="selReferrer" onchange="updatePreview()">
            <option value="">— جميع المحيلين —</option>
            <?php foreach($allReferrers as $rf): ?>
            <option value="<?=$rf['id']?>"><?=htmlspecialchars($rf['full_name']?:$rf['username'])?> (#<?=$rf['id']?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- الخدمة -->
        <div class="form-group">
          <label style="font-size:.82rem;font-weight:700">الخدمة
            <small style="color:var(--text3);font-weight:400">(اتركها فارغاً = جميع الخدمات)</small>
          </label>
          <select name="service_id" class="form-control" id="selService" onchange="updatePreview()">
            <option value="">— جميع الخدمات —</option>
            <?php foreach($services as $svc): ?>
            <option value="<?=$svc['id']?>"><?=htmlspecialchars($svc['name'])?> (<?=formatMoney($svc['price'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- النسبة -->
        <div class="form-group">
          <label style="font-size:.82rem;font-weight:700">نسبة العمولة %
            <small style="color:var(--text3);font-weight:400">(0 = حذف النسبة المخصصة)</small>
          </label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="number" name="custom_percent" id="inputPct" class="form-control"
                   min="0" max="100" step="0.1" placeholder="مثال: 5"
                   oninput="updatePreview()">
            <span style="font-size:1.3rem;font-weight:900;color:var(--primary)">%</span>
          </div>
        </div>

        <!-- ملاحظة -->
        <div class="form-group">
          <label style="font-size:.82rem;font-weight:700">ملاحظة (اختياري)</label>
          <input type="text" name="notes" class="form-control" placeholder="مثال: عميل VIP">
        </div>

        <!-- معاينة -->
        <div id="ratePreview" style="background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.2);border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:.8rem;display:none">
          <i class="fas fa-eye" style="color:#00d4aa"></i> <span id="previewText"></span>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fas fa-save"></i> حفظ النسبة
        </button>
      </form>

      <!-- شرح الأولويات -->
      <div style="margin-top:14px;padding:10px 12px;background:var(--bg2);border-radius:10px;font-size:.75rem;line-height:1.9;color:var(--text3)">
        <div style="font-weight:800;color:var(--text2);margin-bottom:6px"><i class="fas fa-sort-amount-down"></i> أولوية التطبيق:</div>
        <div>🥇 <strong style="color:#f5a623">محيل معين + خدمة معينة</strong> — الأعلى</div>
        <div>🥈 <strong style="color:#00d4ff">محيل معين + جميع الخدمات</strong></div>
        <div>🥉 <strong style="color:#00d4aa">جميع المحيلين + خدمة معينة</strong></div>
        <div>4️⃣ <strong style="color:#a78bfa">جميع المحيلين + جميع الخدمات</strong></div>
        <div>⬇️ <strong style="color:var(--text2)">النسبة الافتراضية</strong> — الأدنى</div>
      </div>
    </div>
  </div>

  <!-- ── جدول النسب الحالية ── -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-list"></i> النسب المخصصة الحالية (<?=count($allRates)?>)</div>
    </div>
    <?php if(empty($allRates)): ?>
    <div class="card-body" style="text-align:center;color:var(--text3);padding:3rem">
      <i class="fas fa-sliders-h" style="font-size:2.5rem;opacity:.1;display:block;margin-bottom:12px"></i>
      لا توجد نسب مخصصة — الجميع على النسبة الافتراضية (<?=$defaultPct?>%)
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>المحيل</th>
            <th>الخدمة</th>
            <th style="text-align:center">النسبة</th>
            <th style="text-align:center">الفرق</th>
            <th>ملاحظة</th>
            <th>التاريخ</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($allRates as $rate):
          $isReferrerSpecific = !empty($rate['referrer_id']);
          $isServiceSpecific  = !empty($rate['service_id']);
          // لون الصف حسب النوع
          $rowBg = $isReferrerSpecific && $isServiceSpecific
            ? 'rgba(245,166,35,.06)'   // محيل + خدمة
            : ($isReferrerSpecific
              ? 'rgba(0,212,255,.04)'  // محيل فقط
              : 'rgba(0,212,170,.04)'); // خدمة فقط
          $pct  = (float)$rate['custom_percent'];
          $diff = $pct - $defaultPct;
        ?>
        <tr style="background:<?=$rowBg?>">
          <td>
            <?php if($isReferrerSpecific): ?>
            <a href="customers.php?action=view&id=<?=$rate['referrer_id']?>" style="font-weight:700;color:var(--cyan);text-decoration:none">
              <?=htmlspecialchars($rate['referrer_name']?:$rate['referrer_username'])?>
            </a>
            <div style="font-size:.68rem;color:var(--text3)">#<?=$rate['referrer_id']?></div>
            <?php else: ?>
            <span style="color:var(--text3);font-size:.8rem"><i class="fas fa-users"></i> جميع المحيلين</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if($isServiceSpecific): ?>
            <span style="font-weight:700"><?=htmlspecialchars($rate['service_name'])?></span>
            <div style="font-size:.68rem;color:var(--text3)"><?=formatMoney($rate['service_price']??0)?></div>
            <?php else: ?>
            <span style="color:var(--text3);font-size:.8rem"><i class="fas fa-layer-group"></i> جميع الخدمات</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <span style="background:rgba(245,166,35,.15);color:#f5a623;padding:3px 12px;border-radius:20px;font-weight:900;font-size:.9rem">
              <?=number_format($pct,1)?>%
            </span>
          </td>
          <td style="text-align:center">
            <span style="font-size:.8rem;font-weight:700;color:<?=$diff>0?'#00e676':($diff<0?'#ff4455':'var(--text3)')?>">
              <?=$diff>0?'+':''?><?=number_format($diff,1)?>%
            </span>
          </td>
          <td style="font-size:.75rem;color:var(--text3)"><?=htmlspecialchars($rate['notes']??'')?></td>
          <td style="font-size:.72rem;color:var(--text3)"><?=date('d/m/Y',strtotime($rate['created_at']))?></td>
          <td>
            <a href="?tab=rates&del_rate=<?=$rate['id']?>"
               onclick="return confirm('حذف هذه النسبة؟')"
               class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ تبويب السجل ═══ -->
<?php elseif($activeTab==='log'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> سجل الإحالات (<?=count($referrals)?>)</div></div>
  <?php if(empty($referrals)): ?>
  <div class="card-body" style="text-align:center;color:var(--text3);padding:2rem">لا توجد إحالات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>المُحيل</th><th>الصديق</th><th>النسبة</th><th>الحالة</th><th>العمولة</th><th>التاريخ</th></tr></thead>
      <tbody>
      <?php foreach($referrals as $r): ?>
      <tr>
        <td>
          <a href="customers.php?action=view&id=<?=$r['referrer_id']?>" style="font-weight:700;color:var(--cyan);text-decoration:none">
            <?=htmlspecialchars($r['referrer_name']?:$r['referrer_username'])?>
          </a>
        </td>
        <td><?=htmlspecialchars($r['referred_name']?:$r['referred_username'])?></td>
        <td><span style="color:#f5a623;font-weight:700"><?=number_format($r['reward_percent'],1)?>%</span></td>
        <td><span class="badge" style="background:<?=$r['status']==='rewarded'?'rgba(0,230,118,.15)':'rgba(245,166,35,.15)'?>;color:<?=$r['status']==='rewarded'?'#00e676':'#f5a623'?>">
          <?=$r['status']==='rewarded'?'✅ مُكافَأ':'⏳ انتظار'?></span>
        </td>
        <td style="color:#f5a623;font-weight:700"><?=$r['commission_paid']>0?'+'.number_format($r['commission_paid'],4).'$':'—'?></td>
        <td style="font-size:11px;color:var(--text3)"><?=date('d/m/Y H:i',strtotime($r['created_at']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═══ تبويب أكثر المحيلين ═══ -->
<?php elseif($activeTab==='top'): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-crown" style="color:#f5a623"></i> أكثر المُحيلين نشاطاً</div></div>
  <?php if(empty($topReferrers)): ?>
  <div class="card-body" style="text-align:center;color:var(--text3);padding:2rem">لا توجد إحالات بعد</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>المُحيل</th><th>نسبته</th><th>الإحالات</th><th>إجمالي العمولة</th><th></th></tr></thead>
      <tbody>
      <?php foreach($topReferrers as $i=>$r): ?>
      <tr>
        <td><?=$i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1))?></td>
        <td><strong><?=htmlspecialchars($r['full_name']?:$r['username'])?></strong></td>
        <td>
          <?php if($r['custom_percent']>0): ?>
          <span style="background:rgba(245,166,35,.15);color:#f5a623;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:700">
            ⭐ <?=number_format($r['custom_percent'],1)?>%
          </span>
          <?php else: ?>
          <span style="color:var(--text3);font-size:.8rem"><?=$defaultPct?>%</span>
          <?php endif; ?>
        </td>
        <td><span style="color:var(--cyan);font-weight:700"><?=$r['cnt']?></span></td>
        <td style="color:#f5a623;font-weight:700"><?=number_format($r['total'],4)?>$</td>
        <td><a href="customers.php?action=view&id=<?=$r['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-eye"></i></a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
function setRewardType(type) {
  ['first_topup_fixed','first_topup_percent','order_percent'].forEach(t => {
    const el = document.getElementById('type_'+t);
    if (el) el.style.borderColor = t===type ? 'var(--primary)' : 'var(--border)';
  });
  document.getElementById('field_fixed').style.display   = type==='first_topup_fixed' ? 'block' : 'none';
  document.getElementById('field_percent').style.display = ['first_topup_percent','order_percent'].includes(type) ? 'block' : 'none';
}

function updatePreview() {
  const referrer = document.getElementById('selReferrer');
  const service  = document.getElementById('selService');
  const pct      = parseFloat(document.getElementById('inputPct')?.value || 0);
  const preview  = document.getElementById('ratePreview');
  const text     = document.getElementById('previewText');
  if (!preview || !text) return;

  const rName = referrer?.options[referrer.selectedIndex]?.text || '';
  const sName = service?.options[service.selectedIndex]?.text || '';
  const rVal  = referrer?.value;
  const sVal  = service?.value;

  if (!pct) { preview.style.display='none'; return; }

  let msg = '';
  if (rVal && sVal)       msg = `محيل <strong>${rName}</strong> + خدمة <strong>${sName}</strong>`;
  else if (rVal && !sVal) msg = `محيل <strong>${rName}</strong> على جميع الخدمات`;
  else if (!rVal && sVal) msg = `جميع المحيلين على خدمة <strong>${sName}</strong>`;
  else                    msg = `<strong style="color:#a78bfa">جميع المحيلين + جميع الخدمات</strong> — نسبة عامة`;

  text.innerHTML = `${msg} — نسبة <strong style="color:#f5a623">${pct}%</strong>`;
  preview.style.display = 'block';
}
</script>

<?php include 'footer.php'; ?>
