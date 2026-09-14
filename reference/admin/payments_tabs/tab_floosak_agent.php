<!-- ═══════════════ وكيل الشحن — Floosak Agent ═══════════════ -->
<div id="tab-floosak_agent" class="tab-pane" style="<?=$tab!=='floosak_agent'?'display:none':''?>">

<?php
$agCfg      = floosak_agent_get_config($pdo);
$agEnabled  = !empty($agCfg['floosak_agent_enabled']) && $agCfg['floosak_agent_enabled']==='1';
$agSandbox  = !empty($agCfg['floosak_agent_sandbox']) && $agCfg['floosak_agent_sandbox']==='1';
$agApiUrl   = $agCfg['floosak_agent_api_url'] ?? '';
$agPhone    = $agCfg['floosak_agent_phone']             ?? '';
$agAccountNumber = $agCfg['floosak_agent_account_number'] ?? '';
$agBalance  = (float)($agCfg['floosak_agent_wallet_balance'] ?? 0);
$agExpiry   = (int)($agCfg['floosak_agent_token_expiry']    ?? 0);
$agHasToken = !empty($agCfg['floosak_agent_token']) && $agExpiry > time();
$agSubtab   = $_GET['subtab'] ?? 'settings';

// جلب البيانات
$faMethods = [];
$faBunches = [];
try {
    $faMethods = $pdo->query("SELECT * FROM floosak_agent_methods ORDER BY sort_order,id")->fetchAll();
} catch(Exception $e){}

$filterMethod  = (int)($_GET['filter_method']  ?? 0);
$filterSection = trim($_GET['filter_section']  ?? '');
$filterPtype   = trim($_GET['filter_ptype']    ?? '');
$filterGroup   = trim($_GET['filter_group']    ?? '');
$filterSearch  = trim($_GET['filter_search']   ?? '');

try {
    $where  = [];
    $params = [];

    if ($filterMethod)  { $where[] = 'b.method_id=?';    $params[] = $filterMethod; }
    if ($filterSection) { $where[] = 'b.section=?';       $params[] = $filterSection; }
    if ($filterPtype)   { $where[] = 'b.payment_type=?';  $params[] = $filterPtype; }
    if ($filterGroup)   { $where[] = 'b.bundle_group=?';  $params[] = $filterGroup; }
    if ($filterSearch)  {
        $where[]  = '(b.bunch_name LIKE ? OR b.bunch_id LIKE ? OR b.unified_code LIKE ?)';
        $like = '%'.$filterSearch.'%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $whereStr = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $sql = "SELECT b.*,m.name_ar as method_name
            FROM floosak_agent_bunches b
            LEFT JOIN floosak_agent_methods m ON b.method_id=m.method_id
            {$whereStr}
            ORDER BY b.method_id,b.sort_order,b.id";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $faBunches = $q->fetchAll();

    // جلب المجموعات المتاحة للفلتر
    $allGroups = $pdo->query("SELECT DISTINCT bundle_group FROM floosak_agent_bunches WHERE bundle_group IS NOT NULL AND bundle_group != '' ORDER BY bundle_group")->fetchAll(PDO::FETCH_COLUMN);
} catch(Exception $e){ $faBunches=[]; $allGroups=[]; }
?>

<!-- شريط التبويبات الداخلية -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <?php foreach(['settings'=>['fas fa-cog','إعدادات الوكيل'],'methods'=>['fas fa-server','المزودون'],'bunches'=>['fas fa-list','الباقات والخدمات']] as $st=>[$ico,$lbl]): ?>
  <a href="?tab=floosak_agent&subtab=<?=$st?>" class="btn <?=$agSubtab===$st?'btn-primary':'btn-secondary'?>" style="text-decoration:none">
    <i class="<?=$ico?>"></i> <?=$lbl?>
    <?php if($st==='methods'): ?><span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:.75rem;margin-right:4px"><?=count($faMethods)?></span><?php endif; ?>
    <?php if($st==='bunches'): ?><span style="background:rgba(255,255,255,.2);border-radius:10px;padding:1px 7px;font-size:.75rem;margin-right:4px"><?=count($faBunches)?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══ إعدادات الوكيل ══════════════════════════════════════ -->
<?php if($agSubtab==='settings'): ?>

<!-- بطاقة الحالة -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header"><i class="fas fa-tachometer-alt" style="color:#a78bfa"></i> لوحة حالة الوكيل</div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الخدمة</div>
        <?php if($agEnabled): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-check-circle"></i> مفعّلة</span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-times-circle"></i> معطّلة</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">البيئة</div>
        <?php if($agSandbox): ?>
        <span style="background:#f5a62322;color:#f5a623;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-flask"></i> Sandbox</span>
        <?php else: ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-globe"></i> Production</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">التوكن</div>
        <?php if($agHasToken): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-key"></i> صالح <?=date('H:i',$agExpiry)?></span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-lock"></i> غير مسجّل</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">رصيد YER</div>
        <div style="font-size:1.25rem;font-weight:700;color:#e0e6ed"><?=number_format($agBalance)?></div>
        <div style="font-size:.7rem;color:#8895a7">ريال يمني</div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">رقم الحساب</div>
        <div style="font-size:1.15rem;font-weight:700;color:#a78bfa;font-family:monospace"><?=htmlspecialchars($agAccountNumber?:'—')?></div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">المزودون</div>
        <div style="font-size:1.25rem;font-weight:700;color:#a78bfa"><?=count($faMethods)?></div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الباقات</div>
        <?php try{ $bc=$pdo->query("SELECT COUNT(*) FROM floosak_agent_bunches WHERE status=1")->fetchColumn(); }catch(Exception $e){$bc=0;} ?>
        <div style="font-size:1.25rem;font-weight:700;color:#00d4aa"><?=$bc?></div>
      </div>
    </div>
  </div>
</div>

<!-- نموذج الإعدادات -->
<div class="card">
  <div class="card-header"><i class="fas fa-cog"></i> بيانات الوكيل</div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>

      <!-- رابط الـ API -->
      <div class="form-group" style="margin-bottom:1.2rem">
        <label><i class="fas fa-link" style="color:#1e6fff"></i> رابط الـ API (Base URL) <span style="color:#ff4455">*</span></label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="url" name="floosak_agent_api_url" id="agApiUrlInput"
                 value="<?=htmlspecialchars($agApiUrl)?>"
                 placeholder="https://api.example.com"
                 class="form-control"
                 style="font-family:monospace;font-size:.85rem">
          <button type="button" class="btn btn-sm"
                  style="background:rgba(245,166,35,.15);color:#f5a623;border:1px solid rgba(245,166,35,.3);white-space:nowrap;padding:8px 12px"
                  onclick="document.getElementById('agApiUrlInput').value='https://staging.fintech-expert.net'">
            <i class="fas fa-flask"></i> Sandbox
          </button>
        </div>
        <small style="color:#8895a7">رابط Production يُزوَّد من فلوسك — مثال: https://api.floosak.com</small>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
        <div class="form-group">
          <label>رقم هاتف الوكيل (اسم الدخول) <span style="color:#ff4455">*</span></label>
          <input type="text" name="floosak_agent_phone" value="<?=htmlspecialchars($agPhone)?>"
                 placeholder="9677XXXXXXXX" class="form-control" required>
          <small style="color:#8895a7">مع كود الدولة — أرقام فقط</small>
        </div>
        <div class="form-group">
          <label>كلمة مرور الوكيل</label>
          <input type="password" name="floosak_agent_password" value=""
                 placeholder="اتركه فارغاً للإبقاء على الحالي" class="form-control" autocomplete="new-password">
          <small style="color:#8895a7">6 أحرف على الأقل</small>
        </div>
        <div class="form-group">
          <label><i class="fas fa-id-badge" style="color:#a78bfa"></i> رقم الحساب في فلوسك <span style="color:#ff4455">*</span></label>
          <input type="text" name="floosak_agent_account_number"
                 value="<?=htmlspecialchars($agAccountNumber)?>"
                 placeholder="مثال: 4750" class="form-control">
          <small style="color:#8895a7">الرقم المُعطى من فلوسك لحساب الوكيل</small>
        </div>
      </div>
      <!-- التوكن ومعرف المحفظة -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
        <div class="form-group">
          <label><i class="fas fa-key" style="color:#f5a623"></i> التوكن
            <small style="color:#8895a7;font-weight:400"> — يُملأ تلقائياً</small>
          </label>
          <div style="position:relative">
            <input type="text" name="floosak_agent_token" id="agTokenInput"
                   value="<?=htmlspecialchars(substr($agCfg['floosak_agent_token']??'',0,80))?>"
                   class="form-control" style="font-family:monospace;font-size:.72rem"
                   placeholder="يُملأ تلقائياً بعد الاتصال الناجح" readonly>
          </div>
          <?php if($agHasToken): ?>
          <small style="color:#00d4aa"><i class="fas fa-clock"></i> صالح حتى <?=date('Y-m-d H:i',$agExpiry)?></small>
          <?php else: ?>
          <small style="color:#ff4455">اضغط "اختبار الاتصال" لتوليد التوكن</small>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label><i class="fas fa-wallet" style="color:#a78bfa"></i> معرّف المحفظة (YER)
            <small style="color:#8895a7;font-weight:400"> — يُملأ تلقائياً</small>
          </label>
          <input type="text" name="floosak_agent_wallet_id" id="agWalletInput"
                 value="<?=htmlspecialchars($agCfg['floosak_agent_wallet_id']??'')?>"
                 class="form-control" style="font-family:monospace"
                 placeholder="يُملأ تلقائياً بعد الاتصال الناجح" readonly>
        </div>
      </div>

      <div style="display:flex;gap:2rem;margin:1rem 0;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="floosak_agent_enabled" value="1" <?=$agEnabled?'checked':''?>>
          <span>تفعيل الخدمة</span>
        </label>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="floosak_agent_sandbox" value="1" <?=$agSandbox?'checked':''?>>
          <span>بيئة Sandbox (تجريبية)</span>
        </label>
      </div>
      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <button type="submit" name="save_floosak_agent" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
        <button type="submit" name="test_floosak_agent" class="btn btn-success"><i class="fas fa-plug"></i> اختبار الاتصال وتحديث الرصيد</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<!-- ══ المزودون ══════════════════════════════════════════════ -->
<?php if($agSubtab==='methods'): ?>

<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span><i class="fas fa-server" style="color:#a78bfa"></i> مزودو الخدمة (method_id)</span>
    <div style="display:flex;gap:6px">
      <a href="?tab=floosak_agent&subtab=methods&reseed_fields=1" class="btn btn-sm"
         style="background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3)"
         onclick="return confirm('سيُعيد تعيين حقول الكهرباء والماء. هل أنت متأكد؟')"
         title="إعادة ترحيل حقول الكهرباء والماء">
        <i class="fas fa-sync-alt"></i> ترحيل الكهرباء/الماء
      </a>
      <button class="btn btn-primary btn-sm" onclick="openMethodModal(0)"><i class="fas fa-plus"></i> إضافة مزود</button>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="overflow-x:auto">
    <table class="table" style="margin:0">
      <thead><tr>
        <th>method_id</th><th>الشعار</th><th>الاسم عربي</th><th>الاسم إنجليزي</th><th>النوع</th><th>الحالة</th><th>الترتيب</th><th>إجراء</th>
      </tr></thead>
      <tbody>
      <?php foreach($faMethods as $m): ?>
      <tr>
        <td><code style="background:rgba(167,139,250,.15);color:#a78bfa;padding:2px 8px;border-radius:5px"><?=(int)$m['method_id']?></code></td>
        <td>
          <?php if(!empty($m['logo'])): ?>
          <img src="<?=SITE_URL.'/'.htmlspecialchars($m['logo'])?>" style="width:36px;height:36px;border-radius:10px;object-fit:contain;background:#fff;padding:2px;border:1px solid var(--border)">
          <?php else: ?>
          <div style="width:36px;height:36px;border-radius:10px;background:<?=htmlspecialchars($m['color'])?>22;display:flex;align-items:center;justify-content:center;color:<?=htmlspecialchars($m['color'])?>"><i class="fas fa-<?=htmlspecialchars($m['icon'])?>"></i></div>
          <?php endif; ?>
        </td>
        <td><?=htmlspecialchars($m['name_ar'])?></td>
        <td style="color:#8895a7"><?=htmlspecialchars($m['name_en'])?></td>
        <td>
          <?php if($m['transaction_type']==='TOPUP'): ?>
          <span style="background:#00d4aa22;color:#00d4aa;padding:2px 8px;border-radius:5px;font-size:.78rem">TOPUP</span>
          <?php else: ?>
          <span style="background:#a78bfa22;color:#a78bfa;padding:2px 8px;border-radius:5px;font-size:.78rem">BILLPAY</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($m['status']): ?>
          <span style="color:#00d4aa;font-size:.8rem"><i class="fas fa-circle"></i> نشط</span>
          <?php else: ?>
          <span style="color:#ff4455;font-size:.8rem"><i class="fas fa-circle"></i> معطّل</span>
          <?php endif; ?>
        </td>
        <td><?=(int)$m['sort_order']?></td>
        <td>
          <button class="btn btn-warning btn-sm" onclick="openMethodModal(<?=$m['id']?>,<?=htmlspecialchars(json_encode($m),ENT_QUOTES)?>)" title="تعديل"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm" style="background:rgba(139,92,246,.2);color:#a78bfa;border:1px solid rgba(139,92,246,.3)"
                  onclick="openFieldsModal(<?=$m['id']?>, '<?=addslashes(htmlspecialchars($m['name_ar']))?>')" title="الحقول الديناميكية">
            <i class="fas fa-sliders-h"></i>
            <?php
              $fc=$pdo->prepare("SELECT COUNT(*) FROM fa_method_fields WHERE method_row_id=? AND is_enabled=1");
              $fc->execute([$m['id']]); $fcnt=(int)$fc->fetchColumn();
              if($fcnt) echo '<span style="background:#a78bfa;color:#fff;border-radius:10px;padding:1px 6px;font-size:.6rem;margin-right:2px">'.$fcnt.'</span>';
            ?>
          </button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا المزود وجميع باقاته؟')">
        <?= adminCsrfField() ?>
            <input type="hidden" name="fa_method_id_row" value="<?=$m['id']?>">
            <button type="submit" name="delete_fa_method" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($faMethods)): ?>
      <tr><td colspan="7" style="text-align:center;color:#8895a7;padding:2rem">لا يوجد مزودون — أضف مزوداً أو استورد من الـ SQL</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══ مودال الحقول الديناميكية ══ -->
<div class="modal-overlay" id="fieldsModal">
  <div class="modal" style="max-width:720px;max-height:90vh;overflow-y:auto">
    <div class="modal-header" style="background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(107,63,224,.1));border-bottom:1px solid rgba(139,92,246,.2)">
      <div class="modal-title" style="color:#a78bfa">
        <i class="fas fa-sliders-h"></i>
        الحقول الديناميكية — <span id="fieldsModalTitle">المزود</span>
      </div>
      <button class="modal-close" onclick="closeFieldsModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">

      <div style="font-size:.8rem;color:#8895a7;margin-bottom:1rem;padding:10px 14px;background:rgba(139,92,246,.06);border-radius:8px;border-right:3px solid #8b5cf6">
        💡 هذه الحقول ستظهر للعميل قبل تنفيذ الطلب. يمكنك إضافة رقم المشترك، المحافظة، المنطقة... إلخ
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <strong style="font-size:.9rem">الحقول (<span id="fieldsCount">0</span>)</strong>
        <button type="button" onclick="addFaFieldRow()" class="btn btn-primary btn-sm">
          <i class="fas fa-plus"></i> إضافة حقل
        </button>
      </div>

      <div id="faFieldsContainer" style="display:flex;flex-direction:column;gap:10px">
        <div id="faEmptyMsg" style="text-align:center;padding:2rem;color:#8895a7;border:2px dashed rgba(255,255,255,.07);border-radius:10px">
          <i class="fas fa-layer-group" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>
          لا توجد حقول — اضغط "إضافة حقل"
        </div>
      </div>

      <!-- معاينة -->
      <div style="margin-top:1.25rem">
        <div style="font-size:.78rem;font-weight:700;color:#8895a7;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em">
          <i class="fas fa-eye"></i> معاينة للعميل
        </div>
        <div id="faPreview" style="background:#080c1a;border-radius:12px;padding:18px;border:1px solid rgba(255,255,255,.06)">
          <p style="color:#8895a7;font-size:.8rem;text-align:center">أضف حقلاً لترى المعاينة</p>
        </div>
      </div>

      <div style="margin-top:1.25rem;display:flex;gap:8px">
        <button onclick="saveFaFields()" class="btn btn-success"><i class="fas fa-save"></i> حفظ الحقول</button>
        <button onclick="closeFieldsModal()" class="btn btn-secondary">إلغاء</button>
      </div>
    </div>
  </div>
</div>

<!-- مودال المزود -->
<div class="modal-overlay" id="methodModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div class="modal-title" id="methodModalTitle"><i class="fas fa-server"></i> مزود جديد</div>
      <button class="modal-close" onclick="document.getElementById('methodModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" id="methodForm" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="fa_method_id_row" id="fa_method_id_row" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>method_id <span style="color:#ff4455">*</span></label>
            <input type="number" name="fa_method_id" id="fa_method_id" class="form-control" required min="1">
            <small style="color:#8895a7">الرقم من توثيق Floosak</small>
          </div>
          <div class="form-group">
            <label>النوع</label>
            <select name="fa_type" id="fa_type" class="form-control">
              <option value="TOPUP">TOPUP — شحن رصيد</option>
              <option value="BILLPAY">BILLPAY — دفع فواتير</option>
            </select>
          </div>
          <div class="form-group">
            <label>الاسم عربي <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_name_ar" id="fa_name_ar" class="form-control" required>
          </div>
          <div class="form-group">
            <label>الاسم إنجليزي</label>
            <input type="text" name="fa_name_en" id="fa_name_en" class="form-control">
          </div>
          <div class="form-group">
            <label>الأيقونة (Font Awesome)</label>
            <input type="text" name="fa_icon" id="fa_icon" class="form-control" value="sim-card" placeholder="sim-card">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="fa_color" id="fa_color" class="form-control" value="#6c3fe0">
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label><i class="fas fa-image" style="color:#f5a623"></i> شعار الشبكة (صورة)</label>
            <div style="display:flex;align-items:center;gap:12px">
              <div id="fa_logo_preview" style="width:56px;height:56px;border-radius:14px;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                <i class="fas fa-image" style="color:var(--text3)"></i>
              </div>
              <div style="flex:1">
                <input type="file" name="fa_logo" id="fa_logo" accept="image/*,.svg" style="display:none" onchange="previewNetLogo(this)">
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('fa_logo').click()">
                  <i class="fas fa-upload"></i> رفع شعار
                </button>
                <small style="display:block;color:#8895a7;margin-top:4px">PNG/JPG/SVG — يُستخدم بدل الأيقونة في واجهة العميل</small>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="fa_sort" id="fa_sort" class="form-control" value="0" min="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.8rem">
            <input type="checkbox" name="fa_status" id="fa_status" value="1" checked>
            <label for="fa_status" style="margin:0">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_fa_method" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ══ الباقات والخدمات ═══════════════════════════════════════ -->
<?php if($agSubtab==='bunches'): ?>

<div class="card" style="margin-bottom:1rem">
  <div class="card-body" style="padding:1rem">
    <form method="GET" id="bunchFilterForm">
      <input type="hidden" name="tab" value="floosak_agent">
      <input type="hidden" name="subtab" value="bunches">

      <!-- صف البحث والمزود -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;align-items:center">
        <div style="flex:2;min-width:200px;position:relative">
          <i class="fas fa-search" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:#8895a7"></i>
          <input type="text" name="filter_search" class="form-control" style="padding-right:34px"
                 placeholder="ابحث باسم الباقة أو bunch_id أو unified_code..."
                 value="<?=htmlspecialchars($filterSearch)?>">
        </div>
        <div style="flex:1;min-width:180px">
          <select name="filter_method" class="form-control">
            <option value="">— جميع المزودين —</option>
            <?php foreach($faMethods as $m): ?>
            <option value="<?=(int)$m['method_id']?>" <?=$filterMethod==(int)$m['method_id']?'selected':''?>>
              <?=htmlspecialchars($m['name_ar'])?> (<?=(int)$m['method_id']?>)
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" class="btn btn-primary" onclick="openBunchModal(0)" style="white-space:nowrap">
          <i class="fas fa-plus"></i> إضافة باقة
        </button>
      </div>

      <!-- صف الفلاتر الإضافية -->
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <select name="filter_section" class="form-control" style="flex:1;min-width:150px">
          <option value="">— جميع الأقسام —</option>
          <option value="amount"          <?=$filterSection==='amount'?'selected':''?>>💰 رصيد حر</option>
          <option value="fees"            <?=$filterSection==='fees'?'selected':''?>>🏷️ فئات</option>
          <option value="bundles"         <?=$filterSection==='bundles'?'selected':''?>>📦 باقات</option>
          <option value="yemen4g_change"  <?=$filterSection==='yemen4g_change'?'selected':''?>>🔄 تغيير باقة يمن فورجي</option>
          <option value="yemen4g_credit"  <?=$filterSection==='yemen4g_credit'?'selected':''?>>📶 رصيد اتصال يمن فورجي</option>
          <option value="yemen4g_internet"<?=$filterSection==='yemen4g_internet'?'selected':''?>>🌐 إنترنت فقط</option>
          <option value="yemen4g_voice"   <?=$filterSection==='yemen4g_voice'?'selected':''?>>📞 صوت فقط</option>
        </select>

        <select name="filter_ptype" class="form-control" style="flex:1;min-width:140px">
          <option value="">— نوع الدفع —</option>
          <option value="prepaid"  <?=$filterPtype==='prepaid'?'selected':''?>>دفع مسبق</option>
          <option value="postpaid" <?=$filterPtype==='postpaid'?'selected':''?>>فوترة</option>
          <option value="both"     <?=$filterPtype==='both'?'selected':''?>>كلاهما</option>
        </select>

        <select name="filter_group" class="form-control" style="flex:1;min-width:150px">
          <option value="">— جميع المجموعات —</option>
          <?php foreach($allGroups??[] as $g): ?>
          <option value="<?=htmlspecialchars($g)?>" <?=$filterGroup===$g?'selected':''?>><?=htmlspecialchars($g)?></option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-success" style="white-space:nowrap">
          <i class="fas fa-filter"></i> بحث
        </button>
        <?php if($filterSearch||$filterMethod||$filterSection||$filterPtype||$filterGroup): ?>
        <a href="?tab=floosak_agent&subtab=bunches" class="btn btn-secondary" style="white-space:nowrap">
          <i class="fas fa-times"></i> مسح الفلاتر
        </a>
        <?php endif; ?>
      </div>

      <!-- ملخص النتائج -->
      <?php if($filterSearch||$filterMethod||$filterSection||$filterPtype||$filterGroup): ?>
      <div style="margin-top:.6rem;font-size:.78rem;color:#8895a7">
        <i class="fas fa-info-circle"></i> نتائج البحث:
        <strong style="color:#00d4aa"><?=count($faBunches)?></strong> باقة
        <?php if($filterSearch): ?> — بحث: "<span style="color:#f5a623"><?=htmlspecialchars($filterSearch)?></span>"<?php endif; ?>
        <?php if($filterMethod): ?> — مزود: <span style="color:#a78bfa"><?=htmlspecialchars(array_column($faMethods,'name_ar','method_id')[$filterMethod]??$filterMethod)?></span><?php endif; ?>
        <?php if($filterSection): ?> — قسم: <span style="color:#6ba3ff"><?=htmlspecialchars($filterSection)?></span><?php endif; ?>
        <?php if($filterPtype): ?> — نوع: <span style="color:#6ba3ff"><?=htmlspecialchars($filterPtype)?></span><?php endif; ?>
        <?php if($filterGroup): ?> — مجموعة: <span style="color:#6ba3ff"><?=htmlspecialchars($filterGroup)?></span><?php endif; ?>
      </div>
      <?php endif; ?>

    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <i class="fas fa-list" style="color:#00d4aa"></i>
    الباقات والخدمات
    <span style="background:rgba(0,212,170,.15);color:#00d4aa;padding:2px 10px;border-radius:10px;font-size:.8rem;margin-right:.5rem"><?=count($faBunches)?></span>
  </div>
  <div class="card-body" style="padding:0">
    <div style="overflow-x:auto;max-height:600px">
    <table class="table" style="margin:0;font-size:.85rem">
      <thead style="position:sticky;top:0;z-index:5"><tr>
        <th>ID</th>
        <?php if(!$filterMethod): ?><th>المزود</th><?php endif; ?>
        <th>bunch_id</th><th>اسم الباقة</th><th>المجموعة</th><th>القسم</th><th>نوع الدفع</th><th>السعر</th><th>المدة</th><th>الحالة</th><th>إجراء</th>
      </tr></thead>
      <tbody id="bunches-tbody">
      <?php foreach($faBunches as $b): ?>
      <tr id="bunch-row-<?=$b['id']?>">
        <td style="color:#8895a7;font-size:.75rem"><?=$b['id']?></td>
        <?php if(!$filterMethod): ?>
        <td><span style="background:rgba(167,139,250,.12);color:#a78bfa;padding:1px 7px;border-radius:5px;font-size:.75rem"><?=(int)$b['method_id']?><?=isset($b['method_name'])?' — '.htmlspecialchars($b['method_name']):''?></span></td>
        <?php endif; ?>
        <td><code style="font-size:.75rem;color:#f5a623"><?=$b['bunch_id']??'—'?></code></td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($b['bunch_name'])?>"><?=htmlspecialchars($b['bunch_name'])?></td>
        <td style="font-size:.72rem;color:#a78bfa;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($b['bundle_group']??'—')?></td>
        <td>
          <?php $sec=$b['section']??'bundles'; ?>
          <?php if($sec==='fees'): ?><span style="background:rgba(245,166,35,.15);color:#f5a623;padding:2px 7px;border-radius:5px;font-size:.72rem">فئات</span>
          <?php elseif($sec==='amount'): ?><span style="background:rgba(0,212,170,.1);color:#00d4aa;padding:2px 7px;border-radius:5px;font-size:.72rem">رصيد حر</span>
          <?php else: ?><span style="background:rgba(30,111,255,.1);color:#6ba3ff;padding:2px 7px;border-radius:5px;font-size:.72rem">باقات</span>
          <?php endif; ?>
        </td>
        <td>
          <?php $pt=$b['payment_type']??'both'; ?>
          <?php if($pt==='prepaid'): ?><span style="font-size:.7rem;color:#00d4aa">مسبق</span>
          <?php elseif($pt==='postpaid'): ?><span style="font-size:.7rem;color:#a78bfa">فوترة</span>
          <?php else: ?><span style="font-size:.7rem;color:#8895a7">كلاهما</span>
          <?php endif; ?>
        </td>
        <td style="font-weight:700;color:#f5a623"><?=$b['price']?number_format((float)$b['price']).' ر':'—'?></td>
        <td style="color:#8895a7;font-size:.78rem"><?=htmlspecialchars($b['validity']??'—')?></td>
        <td>
          <?php if($b['status']): ?>
          <span style="color:#00d4aa;font-size:.75rem"><i class="fas fa-circle"></i></span>
          <?php else: ?>
          <span style="color:#ff4455;font-size:.75rem"><i class="fas fa-circle"></i></span>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <button class="btn btn-warning btn-sm" style="padding:2px 7px" onclick="openBunchModal(<?=$b['id']?>,<?=htmlspecialchars(json_encode($b),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
          <button class="btn btn-danger btn-sm" style="padding:2px 7px" onclick="deleteBunchAjax(<?=$b['id']?>,<?=(int)$b['method_id']?>,this)"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($faBunches)): ?>
      <tr><td colspan="10" style="text-align:center;color:#8895a7;padding:2rem">لا توجد باقات — أضف باقة أو استورد ملف SQL</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- مودال الباقة -->
<div class="modal-overlay" id="bunchModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <div class="modal-title" id="bunchModalTitle"><i class="fas fa-box"></i> باقة جديدة</div>
      <button class="modal-close" onclick="document.getElementById('bunchModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" id="bunchForm">
        <?= adminCsrfField() ?>
        <input type="hidden" name="fa_bunch_row_id" id="fa_bunch_row_id" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group">
            <label>المزود <span style="color:#ff4455">*</span></label>
            <select name="fa_b_method_id" id="fa_b_method_id" class="form-control" required onchange="updateGroupSuggestions(this.value)">
              <?php foreach($faMethods as $m): ?>
              <option value="<?=(int)$m['method_id']?>" <?=$filterMethod==(int)$m['method_id']?'selected':''?>>
                <?=(int)$m['method_id']?> — <?=htmlspecialchars($m['name_ar'])?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>bunch_id (الرقم)</label>
            <input type="number" name="fa_b_bunch_id" id="fa_b_bunch_id" class="form-control" placeholder="مثال: 340">
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم الباقة <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_b_name" id="fa_b_name" class="form-control" required placeholder="مثال: رصيد يمن موبايل">
          </div>
          <div class="form-group">
            <label>code <span style="color:#ff4455">*</span></label>
            <input type="text" name="fa_b_code" id="fa_b_code" class="form-control" required placeholder="مثال: 340">
          </div>
          <div class="form-group">
            <label>unified_code <span style="color:#ff4455">*</span><small style="color:#8895a7;font-weight:400"> (يُرسَل لفلوسك)</small></label>
            <input type="text" name="fa_b_unified_code" id="fa_b_unified_code" class="form-control" required placeholder="مثال: 340 أو A69332">
          </div>
          <div class="form-group">
            <label><i class="fas fa-bolt" style="color:#f5a623"></i> Fore num / offerId
              <small style="color:#8895a7;font-weight:400"> — رقم الباقة في Fore Yemen (فارغ = يستخدم unified_code)</small>
            </label>
            <input type="text" name="fa_b_fore_num" id="fa_b_fore_num" class="form-control" placeholder="مثال: 40 أو A69332">
          </div>
          <div class="form-group">
            <label>السعر (ريال) <small style="color:#8895a7;font-weight:400">يظهر للعميل</small></label>
            <input type="number" name="fa_b_price" id="fa_b_price" class="form-control" step="1" placeholder="مثال: 1500">
          </div>
          <div class="form-group">
            <label>مدة الصلاحية</label>
            <select name="fa_b_validity" id="fa_b_validity" class="form-control">
              <option value="">—</option>
              <option value="يومي">يومي</option>
              <option value="يومين">يومين</option>
              <option value="أسبوعي">أسبوعي</option>
              <option value="10 أيام">10 أيام</option>
              <option value="شهري">شهري</option>
            </select>
          </div>
          <div class="form-group">
            <label>القسم <span style="color:#ff4455">*</span></label>
            <select name="fa_b_section" id="fa_b_section" class="form-control" onchange="toggleFreeAmount(this.value)">
              <option value="bundles">📦 باقات</option>
              <option value="fees">🏷️ فئات (سعر ثابت)</option>
              <option value="amount">💰 رصيد حر (مبلغ مفتوح)</option>
              <optgroup label="── يمن فورجي ──">
                <option value="yemen4g_change">🔄 تغيير باقة يمن فورجي (باقات)</option>
                <option value="yemen4g_credit">📶 رصيد يمن فورجي اتصال (مبلغ مفتوح)</option>
                <option value="yemen4g_internet">🌐 تغيير باقات يمن فورجي إنترنت فقط (باقات)</option>
                <option value="yemen4g_voice">📞 تغيير باقات يمن فورجي صوت فقط (باقات)</option>
              </optgroup>
            </select>
          </div>
          <div class="form-group">
            <label>نوع الدفع</label>
            <select name="fa_b_ptype" id="fa_b_ptype" class="form-control">
              <option value="both">كلاهما</option>
              <option value="prepaid">دفع مسبق فقط</option>
              <option value="postpaid">فوترة فقط</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم المجموعة <small style="color:#8895a7;font-weight:400">للباقات فقط — مثال: باقات مزايا فورجي</small></label>
            <input type="text" name="fa_b_group" id="fa_b_group" class="form-control" placeholder="مثال: باقات مزايا فورجي">
            <!-- اختصارات للمجموعات الشائعة -->
            <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px" id="group-suggestions"></div>
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="fa_b_sort" id="fa_b_sort" class="form-control" value="0" min="0">
          </div>
          <div class="form-group" style="display:flex;flex-direction:column;gap:.5rem;padding-top:.5rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
              <input type="checkbox" name="fa_b_status" id="fa_b_status" value="1" checked>
              <span>نشطة</span>
            </label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer" id="free-amount-row" style="display:none">
              <input type="checkbox" name="fa_b_is_free" id="fa_b_is_free" value="1">
              <span>رصيد حر (يكتب العميل المبلغ)</span>
            </label>
          </div>
        </div>
        <button type="button" class="btn btn-primary btn-block" style="margin-top:.5rem" onclick="saveBunchAjax()"><i class="fas fa-save"></i> حفظ الباقة</button>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

</div><!-- /tab-floosak_agent -->
