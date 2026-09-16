<!-- ══ تبويب Fore Yemen ══════════════════════════════════════ -->
<div id="tab-fore" class="tab-pane" <?=$tab!=='fore'?'style="display:none"':''?>>

<?php
$foreDomain   = getSetting('fore_domain');
$foreUserid   = getSetting('fore_userid');
$foreUsername = getSetting('fore_username');
$foreEnabled  = getSetting('fore_enabled');
$foreBalance  = getSetting('fore_balance');
$foreLastTest = (int)getSetting('fore_last_test');
?>

<!-- بطاقة الحالة -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem">
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الحالة</div>
        <?php if($foreEnabled==='1'): ?>
        <span style="background:#00d4aa22;color:#00d4aa;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-check-circle"></i> مفعّل</span>
        <?php else: ?>
        <span style="background:#ff445522;color:#ff4455;padding:4px 14px;border-radius:20px;font-size:.82rem"><i class="fas fa-times-circle"></i> معطّل</span>
        <?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">رصيد الوكيل</div>
        <div style="font-size:1.25rem;font-weight:700;color:#e0e6ed"><?=htmlspecialchars($foreBalance ?: '—')?></div>
        <?php if($foreLastTest): ?><div style="font-size:.68rem;color:#8895a7"><?=date('H:i d/m',$foreLastTest)?></div><?php endif; ?>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">الدومين</div>
        <div style="font-size:.78rem;color:#a78bfa;word-break:break-all"><?=htmlspecialchars($foreDomain ?: '—')?></div>
      </div>
      <div style="background:rgba(255,255,255,.04);border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:.72rem;color:#8895a7;margin-bottom:.4rem">User ID</div>
        <div style="font-size:1.1rem;font-weight:700;color:#e0e6ed"><?=htmlspecialchars($foreUserid ?: '—')?></div>
      </div>
    </div>
  </div>
</div>

<!-- نموذج الإعدادات -->
<div class="card">
  <div class="card-header"><i class="fas fa-cog"></i> إعدادات Fore Yemen</div>
  <div class="card-body">
    <form method="POST">
        <?= adminCsrfField() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-globe"></i> رابط API (Domain)</label>
          <input type="url" name="fore_domain" class="form-control"
                 value="<?=htmlspecialchars($foreDomain)?>"
                 placeholder="https://fore.yemoney.net/api/yr">
        </div>

        <div class="form-group">
          <label><i class="fas fa-id-badge"></i> User ID</label>
          <input type="text" name="fore_userid" class="form-control"
                 value="<?=htmlspecialchars($foreUserid)?>"
                 placeholder="رقم المعرف">
        </div>

        <div class="form-group">
          <label><i class="fas fa-user"></i> Username</label>
          <input type="text" name="fore_username" class="form-control"
                 value="<?=htmlspecialchars($foreUsername)?>"
                 placeholder="اسم المستخدم">
        </div>

        <div class="form-group" style="grid-column:1/-1">
          <label><i class="fas fa-lock"></i> Password</label>
          <input type="password" name="fore_password" class="form-control"
                 placeholder="كلمة المرور (اتركها فارغة للإبقاء على الحالية)"
                 autocomplete="new-password">
          <small style="color:#8895a7">التوكن = md5(md5(password) + transid + username + mobile)</small>
        </div>

      </div>

      <div style="margin:1rem 0">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" name="fore_enabled" value="1" <?=$foreEnabled==='1'?'checked':''?>>
          <span>تفعيل Fore Yemen</span>
        </label>
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <button type="submit" name="save_fore" class="btn btn-primary">
          <i class="fas fa-save"></i> حفظ الإعدادات
        </button>
        <button type="submit" name="test_fore" class="btn btn-success">
          <i class="fas fa-plug"></i> اختبار الاتصال وتحديث الرصيد
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ربط الشبكات وأنواع العمليات بالمزود -->
<div class="card" style="margin-top:1.5rem">
  <div class="card-header"><i class="fas fa-network-wired"></i> مزود كل شبكة ونوع عملية</div>
  <div class="card-body">
    <p style="color:#8895a7;font-size:.82rem;margin-bottom:1rem">
      حدد لكل شبكة ولكل نوع عملية المزود المسؤول — أو اتركه "معطل" لمنع هذا النوع.
    </p>
    <form method="POST">
        <?= adminCsrfField() ?>
    <?php
    $networkProviders = [
        1  => ['يمن موبايل', '77/78', '#cc0000', true,  ['amount'=>'رصيد مفتوح','fees'=>'فئات','bundles'=>'باقات']],
        2  => ['سبأفون',     '71',    '#ff6600', true,  ['fees'=>'فئات/وحدات','bundles'=>'باقات']],
        3  => ['يو',         '73',    '#0066cc', true,  ['fees'=>'فئات','bundles'=>'باقات']],
        12 => ['واي',        '70',    '#800080', true,  ['fees'=>'فئات','bundles'=>'باقات']],
        16 => ['يمن فورجي',  '10',    '#e91e8c', true,  ['amount'=>'رصيد مفتوح','fees'=>'فئات']],
        4  => ['ADSL',       '01-09', '#00aaff', true,  ['amount'=>'رصيد مفتوح']],
        13 => ['الخط الثابت','01-09', '#6c757d', true,  ['amount'=>'رصيد مفتوح']],
        17 => ['عدن نت',     '79',    '#20c997', false, ['fees'=>'فئات','bundles'=>'باقات']],
    ];
    $opMeta = [
        'amount'  => ['💰','رصيد مفتوح','#00d4aa'],
        'fees'    => ['🏷️','فئات',      '#f5a623'],
        'bundles' => ['📦','باقات',     '#6ba3ff'],
    ];
    ?>
    <div style="display:flex;flex-direction:column;gap:14px">
    <?php foreach($networkProviders as $mid=>[$name,$prefix,$color,$foreOk,$ops]): ?>
    <div style="border-radius:12px;border:1px solid var(--border);overflow:hidden">
      <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:rgba(255,255,255,.04);border-bottom:1px solid var(--border)">
        <div style="width:34px;height:34px;border-radius:9px;background:<?=htmlspecialchars($color)?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;font-weight:700"><?=htmlspecialchars($prefix)?></div>
        <span style="font-weight:900"><?=htmlspecialchars($name)?></span>
        <?php if(!$foreOk): ?><span style="font-size:.68rem;color:#f5a623;background:rgba(245,166,35,.1);padding:2px 8px;border-radius:6px"><i class="fas fa-exclamation-triangle"></i> Fore غير مدعوم</span><?php endif; ?>
      </div>
      <div style="padding:8px 12px;display:flex;flex-direction:column;gap:5px">
      <?php foreach($ops as $opKey=>$opLabel): ?>
      <?php
        $sk  = "np_{$mid}_{$opKey}";
        $cur = getSetting($sk);
        [$opIc,$opNm,$opCl] = $opMeta[$opKey] ?? ['⚙️',$opLabel,'#8895a7'];
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 10px;background:rgba(255,255,255,.02);border-radius:8px">
        <div style="display:flex;align-items:center;gap:7px">
          <span><?=$opIc?></span>
          <span style="font-size:.82rem;font-weight:600;color:<?=htmlspecialchars($opCl)?>"><?=htmlspecialchars($opNm)?></span>
          <?php if($cur===''||$cur===null): ?><span style="font-size:.65rem;color:#ff4455;background:rgba(255,68,85,.1);padding:1px 6px;border-radius:4px">غير محدد</span><?php endif; ?>
        </div>
        <div style="display:flex;gap:5px;align-items:center">
          <?php if($foreOk): ?>
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer;padding:5px 10px;border-radius:7px;border:1px solid var(--border);font-size:.78rem;<?=$cur==='fore'?'background:rgba(0,212,170,.12);border-color:#00d4aa':''?>">
            <input type="radio" name="<?=$sk?>" value="fore" <?=$cur==='fore'?'checked':''?> style="accent-color:#00d4aa">
            <span style="color:#00d4aa"><i class="fas fa-bolt"></i> Fore</span>
          </label>
          <?php endif; ?>
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer;padding:5px 10px;border-radius:7px;border:1px solid var(--border);font-size:.78rem;<?=$cur==='floosak'?'background:rgba(108,63,224,.12);border-color:var(--primary)':''?>">
            <input type="radio" name="<?=$sk?>" value="floosak" <?=$cur==='floosak'||!$foreOk?'checked':''?> <?=!$foreOk?'disabled':''?> style="accent-color:var(--primary)">
            <span style="color:var(--primary)"><i class="fas fa-sim-card"></i> فلوسك</span>
          </label>
          <?php if($foreOk): ?>
          <label style="display:flex;align-items:center;gap:4px;cursor:pointer;padding:5px 10px;border-radius:7px;border:1px solid var(--border);font-size:.78rem;<?=($cur===''||$cur===null)?'background:rgba(255,68,85,.08);border-color:#ff455533':''?>">
            <input type="radio" name="<?=$sk?>" value="" <?=($cur===''||$cur===null)?'checked':''?> style="accent-color:#ff4455">
            <span style="color:#ff4455"><i class="fas fa-ban"></i> معطل</span>
          </label>
          <?php endif; ?>
          <?php if(!$foreOk): ?><input type="hidden" name="<?=$sk?>" value="floosak"><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:1rem">
      <button type="submit" name="save_network_providers" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
    </div>
    </form>
  </div>
</div>

</div><!-- /tab-fore -->
