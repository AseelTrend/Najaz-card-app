<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
try {
    accountingEnsureSchema($pdo);
    cashboxEnsureSchema($pdo);
    cashboxEnsureOperationalColumns($pdo);
} catch (Throwable $e) {
    error_log('Exchange rates accounting/cashbox schema bootstrap: '.$e->getMessage());
}
requireStaffOrAdmin($pdo, 'perm_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adminCsrfVerify();
}

$pageTitle = 'مركز سعر الصرف والتسعير — ' . SITE_NAME;
$errors = [];

$upsertSetting = static function (PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rates'])) {
    $ids          = (array)($_POST['id'] ?? []);
    $codes        = (array)($_POST['code'] ?? []);
    $names        = (array)($_POST['cname'] ?? []);
    $symbols      = (array)($_POST['symbol'] ?? []);
    $values       = (array)($_POST['rate'] ?? []);
    $statuses     = (array)($_POST['cstatus'] ?? []);
    $deletedIds   = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['deleted_rate_ids'] ?? [])))));
    $seenIds      = [];
    $seenCodes    = [];

    try {
        $pdo->beginTransaction();
        $count = max(count($ids), count($codes));
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(trim((string)($codes[$i] ?? '')));
            $name = trim((string)($names[$i] ?? ''));
            $symbol = trim((string)($symbols[$i] ?? ''));
            $rate = (float)($values[$i] ?? 0);
            $id = (int)($ids[$i] ?? 0);
            $active = isset($statuses[$i]) ? 1 : 0;

            if ($code === '' && $name === '' && $rate <= 0) continue;
            if (!preg_match('/^[\p{L}\p{N} _-]{1,10}$/u', $code)) {
                throw new InvalidArgumentException('رمز العملة في السطر '.($i + 1).' غير صالح.');
            }
            if ($name === '') throw new InvalidArgumentException('اسم العملة في السطر '.($i + 1).' مطلوب.');
            if ($rate <= 0) throw new InvalidArgumentException('سعر العملة '.$code.' يجب أن يكون أكبر من صفر.');
            if (isset($seenCodes[$code])) throw new InvalidArgumentException('كود العملة مكرر: '.$code);
            $seenCodes[$code] = true;

            if ($id > 0) {
                if (isset($seenIds[$id])) throw new InvalidArgumentException('صف العملة مكرر داخل النموذج.');
                $seenIds[$id] = true;
                $lock = $pdo->prepare('SELECT id,currency_code FROM exchange_rates WHERE id=? FOR UPDATE');
                $lock->execute([$id]);
                $existing = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$existing) throw new InvalidArgumentException('صف العملة المطلوب تعديله غير موجود.');
                if (strtoupper((string)$existing['currency_code']) === 'USD' && $code !== 'USD') {
                    throw new InvalidArgumentException('لا يمكن تغيير كود الدولار الأساسي.');
                }
                $stmt = $pdo->prepare('UPDATE exchange_rates SET currency_code=?,currency_name=?,currency_symbol=?,rate_to_usd=?,status=?,sort_order=? WHERE id=?');
                $stmt->execute([$code,$name,$symbol,$rate,$active,$i,$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO exchange_rates (currency_code,currency_name,currency_symbol,rate_to_usd,status,sort_order) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$code,$name,$symbol,$rate,$active,$i]);
            }
        }
        foreach ($deletedIds as $deletedId) {
            if (isset($seenIds[$deletedId])) continue;
            $lock = $pdo->prepare('SELECT currency_code FROM exchange_rates WHERE id=? FOR UPDATE');
            $lock->execute([$deletedId]);
            $deletedCode = $lock->fetchColumn();
            if ($deletedCode === false) continue;
            if (strtoupper((string)$deletedCode) === 'USD') throw new InvalidArgumentException('لا يمكن حذف الدولار الأساسي.');
            $pdo->prepare('DELETE FROM exchange_rates WHERE id=?')->execute([$deletedId]);
        }

        // telecom.php القديم في services.php يستخدم الريال لكل دولار؛ نحافظ على توافقه
        $yer = $pdo->query("SELECT rate_to_usd FROM exchange_rates WHERE currency_code='YER' LIMIT 1")->fetchColumn();
        if ($yer !== false && (float)$yer > 0) {
            $upsertSetting($pdo, 'telecom_exchange_rate', (string)round(1 / (float)$yer, 8));
        }
        $sadadRate = (float)($_POST['telecom_sadad_rate'] ?? getSetting('telecom_sadad_rate') ?? 0);
        if ($sadadRate <= 0) $sadadRate = (float)(getSetting('telecom_exchange_rate') ?: 1700);
        if ($sadadRate <= 0) throw new InvalidArgumentException('سعر صرف كابينة السداد يجب أن يكون أكبر من صفر.');
        $upsertSetting($pdo, 'telecom_sadad_rate', rtrim(rtrim(number_format($sadadRate, 8, '.', ''), '0'), '.'));
        $profit = max(0, min(100, (float)($_POST['telecom_profit_pct'] ?? getSetting('telecom_profit_pct') ?? 5)));
        $upsertSetting($pdo, 'telecom_profit_pct', rtrim(rtrim(number_format($profit, 4, '.', ''), '0'), '.'));
        $upsertSetting($pdo, 'exchange_rates_updated_at', date('Y-m-d H:i:s'));
        $pdo->commit();
        try {
            cashboxEnsureCurrencyDefaults($pdo);
            flashMessage('success', 'تم حفظ أسعار الصرف وتجهيز الصندوق العادي والتشغيلي لكل عملة وتطبيق التعديلات والحذف فعلياً.');
        } catch (Throwable $provisioningError) {
            error_log('Currency cashbox provisioning after standalone rates save failed: '.$provisioningError->getMessage());
            flashMessage('warning', 'تم حفظ أسعار الصرف، لكن تعذر تجهيز الصناديق التلقائية؛ راجع سجل PHP قبل اعتماد الإيداعات.');
        }
        redirect(SITE_URL . '/admin/exchange_rates.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Exchange rates save failed: '.$e->getMessage());
        $errors[] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'تعذر حفظ أسعار الصرف؛ لم يتم تطبيق أي تعديل.';
    }
}

$rates = $pdo->query("SELECT * FROM exchange_rates ORDER BY sort_order ASC, id ASC")->fetchAll();
$yerRate = 0.0;
foreach ($rates as $rateRow) {
    if (strtoupper((string)$rateRow['currency_code']) === 'YER') {
        $yerRate = (float)$rateRow['rate_to_usd'];
        break;
    }
}
$legacyTelecomRate = (float)(getSetting('telecom_exchange_rate') ?: ($yerRate > 0 ? 1 / $yerRate : 1700));
$sadadTelecomRate = (float)(getSetting('telecom_sadad_rate') ?: $legacyTelecomRate ?: 1700);
$telecomProfit = (float)(getSetting('telecom_profit_pct') ?: 5);
$updatedAt = getSetting('exchange_rates_updated_at');

$serviceCount = 0;
$categoryCount = 0;
try { $serviceCount = (int)$pdo->query("SELECT COUNT(*) FROM services")->fetchColumn(); } catch (Throwable $e) {}
try { $categoryCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(); } catch (Throwable $e) {}

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15);color:#00d4aa"><i class="fas fa-globe"></i></div>
      مركز سعر الصرف والتسعير
    </div>
    <div class="page-header-sub">مصدر مركزي لأسعار العملات المستخدمة في الشحن وكابينة السداد، مع توضيح مصدر أسعار الخدمات والألعاب.</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger" style="margin-bottom:16px">
  <?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" style="margin-bottom:16px"><?= htmlspecialchars($flash['message']) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
  <div class="card"><div class="card-body"><div class="td-muted">العملات المعرفة</div><div style="font-size:25px;font-weight:800;color:#00d4aa;margin-top:5px"><?= count($rates) ?></div></div></div>
  <div class="card"><div class="card-body"><div class="td-muted">سعر YER بالدولار</div><div style="font-size:21px;font-weight:800;color:#fff;margin-top:7px"><?= $yerRate > 0 ? htmlspecialchars(rtrim(rtrim(number_format($yerRate, 10, '.', ''), '0'), '.')) : 'غير معرف' ?></div></div></div>
  <div class="card"><div class="card-body"><div class="td-muted">ريال لكل دولار - توافق قديم</div><div style="font-size:21px;font-weight:800;color:#fff;margin-top:7px"><?= number_format($legacyTelecomRate, 4) ?></div></div></div>
  <div class="card"><div class="card-body"><div class="td-muted">الخدمات / الأقسام</div><div style="font-size:21px;font-weight:800;color:#fff;margin-top:7px"><?= $serviceCount ?> / <?= $categoryCount ?></div></div></div>
</div>

<div class="card" style="margin-bottom:16px;border:1px solid rgba(0,212,170,.25)">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-info-circle" style="color:#00d4aa"></i> كيف يؤثر هذا المركز على الموقع؟</div></div>
  <div class="card-body" style="line-height:1.9;font-size:13px;color:#aab5c5">
    <p style="margin:0 0 8px"><strong style="color:#fff">الشحن:</strong> يستخدم العملة الفعالة من جدول <code>exchange_rates</code>. أما كابينة السداد فلها سعر مستقل محفوظ في <code>settings.telecom_sadad_rate</code> بوحدة ريال لكل دولار.</p>
    <p style="margin:0 0 8px"><strong style="color:#fff">الخدمات والألعاب:</strong> أسعارها الأساسية بالدولار في جدول <code>services</code>، ثم يطبق النظام سعر مجموعة العميل عبر قواعد التسعير. لا نضرب أسعار الخدمات بالدولار في سعر YER حتى لا يتضاعف الخصم خطأً.</p>
    <p style="margin:0"><strong style="color:#fff">التوافق:</strong> عند حفظ سعر YER، تتم مزامنة الإعداد القديم <code>telecom_exchange_rate</code> إلى القيمة العكسية. ويُستخدم <code>telecom_sadad_rate</code> فقط لكابينة السداد، مع استخدام السعر القديم كاحتياط عند عدم ضبطه.</p>
  </div>
</div>

<form method="POST">
  <?= adminCsrfField() ?>
  <input type="hidden" name="save_rates" value="1">
  <div id="deletedRateIds"></div>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div class="card-header-title"><i class="fas fa-exchange-alt"></i> أسعار العملات بالنسبة للدولار</div>
      <button type="button" class="btn btn-sm btn-primary" onclick="addRateRow()"><i class="fas fa-plus"></i> إضافة عملة</button>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table>
          <thead><tr><th>الكود</th><th>اسم العملة</th><th>الرمز</th><th>1 وحدة = دولار</th><th>الحالة</th><th></th></tr></thead>
          <tbody id="ratesContainer">
          <?php foreach ($rates as $i => $rate): $rateId=(int)$rate['id']; ?>
            <tr class="rate-row" data-rate-id="<?=$rateId?>">
              <td><input type="hidden" name="id[]" value="<?=$rateId?>"><input type="text" name="code[]" value="<?= htmlspecialchars($rate['currency_code']) ?>" class="form-control" maxlength="10" <?= strtoupper($rate['currency_code']) === 'USD' ? 'readonly' : '' ?>></td>
              <td><input type="text" name="cname[]" value="<?= htmlspecialchars($rate['currency_name']) ?>" class="form-control"></td>
              <td><input type="text" name="symbol[]" value="<?= htmlspecialchars($rate['currency_symbol']) ?>" class="form-control" maxlength="10"></td>
              <td><input type="number" name="rate[]" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$rate['rate_to_usd'], 10, '.', ''), '0'), '.')) ?>" class="form-control" min="0.00000001" step="0.00000001" required></td>
              <td style="text-align:center"><input type="checkbox" name="cstatus[<?= $i ?>]" value="1" <?= !empty($rate['status']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)"></td>
              <td><?= strtoupper($rate['currency_code']) === 'USD' ? '' : '<button type="button" class="btn btn-sm btn-danger" onclick="markRateDeleted(this, '.$rateId.')">×</button>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:12px;padding:11px;background:rgba(0,212,170,.06);border-radius:8px;color:#8895a7;font-size:12px;line-height:1.7">
        مثال: إذا كان 1 ريال يمني = 0.000588 دولار، فهذا يعادل تقريبًا 1700 ريال لكل دولار. يجب إدخال القيمة بصيغة عشرية كاملة، وليس 1700 في حقل «بالدولار».
      </div>
    </div>
  </div>

  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-sliders-h"></i> إعدادات كابينة السداد</div></div>
    <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <div class="form-group" style="margin:0">
        <label>سعر صرف كابينة السداد (1$ = ؟ ر.ي)</label>
        <input class="form-control" type="number" name="telecom_sadad_rate" value="<?= htmlspecialchars((string)$sadadTelecomRate) ?>" min="0.000001" step="0.01" required>
        <div class="td-muted" style="font-size:11px;margin-top:5px">هذا السعر مستقل عن شحن الرصيد وبقية الأقسام. مثال: 530 يعني أن 530 ريالًا تخصم 1 دولار.</div>
      </div>
      <div class="form-group" style="margin:0">
        <label>هامش كابينة الاتصالات (%)</label>
        <input class="form-control" type="number" name="telecom_profit_pct" value="<?= htmlspecialchars((string)$telecomProfit) ?>" min="0" max="100" step="0.01">
        <div class="td-muted" style="font-size:11px;margin-top:5px">يضاف إلى تكلفة الشحن والباقات في كابينة الاتصالات، ولا يغير أسعار الخدمات والألعاب العادية.</div>
      </div>
      <div class="form-group" style="margin:0">
        <label>الحالة الحالية</label>
        <div style="padding:10px 12px;background:var(--bg2);border-radius:8px;color:#aab5c5;font-size:12px;line-height:1.7">
          سعر الكابينة المستقل: <strong style="color:#fff"><?= number_format($sadadTelecomRate, 4) ?> ريال/دولار</strong><br>
          مصدر شحن الرصيد: <strong style="color:#fff">exchange_rates / YER</strong><br>
          آخر حفظ: <strong style="color:#fff"><?= $updatedAt ? htmlspecialchars($updatedAt) : 'لم يسجل بعد' ?></strong>
        </div>
      </div>
    </div>
    <div style="padding:0 16px 16px"><button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ وتطبيق الإعدادات</button></div>
  </div>
</form>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-route"></i> روابط التحكم المتخصصة</div></div>
  <div class="card-body" style="display:flex;flex-wrap:wrap;gap:8px">
    <a class="btn btn-secondary" href="<?= SITE_URL ?>/admin/payments.php?tab=rates"><i class="fas fa-coins"></i> تبويب المدفوعات القديم</a>
    <a class="btn btn-secondary" href="<?= SITE_URL ?>/admin/price_sync.php"><i class="fas fa-sync-alt"></i> مزامنة أسعار الخدمات</a>
    <a class="btn btn-secondary" href="<?= SITE_URL ?>/admin/services.php"><i class="fas fa-list"></i> أسعار الخدمات الأساسية</a>
    <a class="btn btn-secondary" href="<?= SITE_URL ?>/admin/pricing_groups.php"><i class="fas fa-users-cog"></i> مجموعات التسعير</a>
  </div>
</div>

<script>
function addRateRow() {
  const tbody = document.getElementById('ratesContainer');
  const i = tbody.querySelectorAll('.rate-row').length + 1000;
  const tr = document.createElement('tr');
  tr.className = 'rate-row';
  tr.innerHTML = `<td><input type="hidden" name="id[]" value="0"><input type="text" name="code[]" class="form-control" maxlength="10" placeholder="YER" required></td>
    <td><input type="text" name="cname[]" class="form-control" placeholder="اسم العملة" required></td>
    <td><input type="text" name="symbol[]" class="form-control" maxlength="10" placeholder="ر.ي"></td>
    <td><input type="number" name="rate[]" class="form-control" min="0.00000001" step="0.00000001" placeholder="0.000588" required></td>
    <td style="text-align:center"><input type="checkbox" name="cstatus[${i}]" value="1" checked style="width:18px;height:18px;accent-color:var(--primary)"></td>
    <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove()">×</button></td>`;
  tbody.appendChild(tr);
}

function markRateDeleted(button, id) {
  const row = button.closest('.rate-row');
  if (!row || !id) return;
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'deleted_rate_ids[]';
  input.value = String(id);
  document.getElementById('deletedRateIds')?.appendChild(input);
  row.remove();
}
</script>

<?php include 'footer.php'; ?>

<?php
// لا نحتاج إلى إغلاق PHP صراحةً في ملفات الإدارة، لكن نتركه هنا لتوافق القالب.
?>
