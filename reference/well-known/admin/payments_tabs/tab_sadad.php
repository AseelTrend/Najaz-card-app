<!-- ══ تبويب قائمة سداد ══════════════════════════════════════ -->
<div id="tab-sadad" class="tab-pane" <?=$tab!=='sadad'?'style="display:none"':''?>>

<?php
// جلب البيانات
$sadadCats = [];
$sadadSvcs = [];
try {
    $sadadCats = $pdo->query("SELECT * FROM sadad_categories ORDER BY parent_id IS NOT NULL, sort_order, id")->fetchAll();
    $sadadSvcs = $pdo->query("SELECT s.*, c.name_ar as cat_name FROM sadad_services s JOIN sadad_categories c ON s.category_id=c.id ORDER BY s.sort_order,s.id")->fetchAll();
    $sadadMethods = $pdo->query("SELECT method_id, name_ar FROM floosak_agent_methods WHERE status=1 ORDER BY sort_order")->fetchAll();
} catch(Exception $e) {}

$mainCats = array_filter($sadadCats, fn($c)=>!$c['parent_id']);
$subCats  = array_filter($sadadCats, fn($c)=> $c['parent_id']);
?>

<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
  <button class="btn btn-primary" onclick="openSadadCatModal(0)">
    <i class="fas fa-plus"></i> قسم جديد
  </button>
  <button class="btn btn-success" onclick="openSadadSvcModal(0)">
    <i class="fas fa-plus"></i> خدمة جديدة
  </button>
</div>

<!-- شجرة الأقسام والخدمات -->
<div style="display:flex;flex-direction:column;gap:12px">
<?php foreach($mainCats as $mc): ?>
  <?php $subList = array_filter($sadadCats, fn($c)=>$c['parent_id']==$mc['id']); ?>
  <div class="card" style="border-left:4px solid <?=htmlspecialchars($mc['color'])?>">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:32px;height:32px;border-radius:8px;background:<?=htmlspecialchars($mc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff">
          <i class="fas fa-<?=htmlspecialchars($mc['icon'])?>"></i>
        </div>
        <div>
          <strong><?=htmlspecialchars($mc['name_ar'])?></strong>
          <span style="font-size:.75rem;color:#8895a7;margin-right:6px">قسم رئيسي #<?=$mc['id']?></span>
          <?=$mc['status']?'<span style="color:#00d4aa;font-size:.72rem">● نشط</span>':'<span style="color:#ff4455;font-size:.72rem">● معطّل</span>'?>
        </div>
      </div>
      <div style="display:flex;gap:6px">
        <button class="btn btn-warning btn-sm" onclick="openSadadCatModal(<?=$mc['id']?>,<?=htmlspecialchars(json_encode($mc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
        <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا القسم وكل محتوياته؟')">
        <?= adminCsrfField() ?>
          <input type="hidden" name="sadad_cat_id" value="<?=$mc['id']?>">
          <button type="submit" name="delete_sadad_cat" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    </div>

    <!-- أقسام فرعية -->
    <?php if($subList): ?>
    <div class="card-body" style="padding:8px;display:flex;flex-direction:column;gap:8px">
      <?php foreach($subList as $sc): ?>
        <?php $svcs = array_filter($sadadSvcs, fn($s)=>$s['category_id']==$sc['id']); ?>
        <div style="background:rgba(255,255,255,.04);border-radius:10px;border:1px solid rgba(255,255,255,.08)">
          <div style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:26px;height:26px;border-radius:7px;background:<?=htmlspecialchars($sc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.8rem">
                <i class="fas fa-<?=htmlspecialchars($sc['icon'])?>"></i>
              </div>
              <span style="font-weight:700;font-size:.85rem"><?=htmlspecialchars($sc['name_ar'])?></span>
              <span style="font-size:.72rem;color:#8895a7">قسم فرعي</span>
            </div>
            <div style="display:flex;gap:5px">
              <button class="btn btn-success btn-sm" onclick="openSadadSvcModal(0,<?=$sc['id']?>)" title="إضافة خدمة"><i class="fas fa-plus"></i></button>
              <button class="btn btn-warning btn-sm" onclick="openSadadCatModal(<?=$sc['id']?>,<?=htmlspecialchars(json_encode($sc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
              <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
                <input type="hidden" name="sadad_cat_id" value="<?=$sc['id']?>">
                <button type="submit" name="delete_sadad_cat" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
          <!-- خدمات القسم الفرعي -->
          <?php if($svcs): ?>
          <div style="padding:0 14px 10px;display:flex;flex-direction:column;gap:5px">
            <?php foreach($svcs as $svc): ?>
            <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between">
              <div style="display:flex;align-items:center;gap:8px">
                <div style="width:22px;height:22px;border-radius:6px;background:<?=htmlspecialchars($svc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem">
                  <i class="fas fa-<?=htmlspecialchars($svc['icon'])?>"></i>
                </div>
                <span style="font-size:.8rem"><?=htmlspecialchars($svc['name_ar'])?></span>
                <?php if($svc['method_id']): ?><span style="font-size:.65rem;color:#a78bfa;background:rgba(167,139,250,.1);padding:1px 6px;border-radius:5px">method=<?=$svc['method_id']?></span><?php endif; ?>
                <span style="font-size:.65rem;color:#8895a7"><?=$svc['action']?></span>
              </div>
              <div style="display:flex;gap:4px">
                <button class="btn btn-warning btn-sm" style="padding:2px 6px" onclick="openSadadSvcModal(<?=$svc['id']?>,0,<?=htmlspecialchars(json_encode($svc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
                <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
                  <input type="hidden" name="sadad_svc_id" value="<?=$svc['id']?>">
                  <button type="submit" name="delete_sadad_svc" class="btn btn-danger btn-sm" style="padding:2px 6px"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- خدمات مباشرة في القسم الرئيسي -->
    <?php $directSvcs = array_filter($sadadSvcs, fn($s)=>$s['category_id']==$mc['id']); ?>
    <?php if($directSvcs): ?>
    <div class="card-body" style="padding:8px;display:flex;flex-direction:column;gap:5px">
      <?php foreach($directSvcs as $svc): ?>
      <div style="background:rgba(255,255,255,.03);border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between">
        <div style="display:flex;align-items:center;gap:8px">
          <div style="width:22px;height:22px;border-radius:6px;background:<?=htmlspecialchars($svc['color'])?>;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem">
            <i class="fas fa-<?=htmlspecialchars($svc['icon'])?>"></i>
          </div>
          <span style="font-size:.8rem"><?=htmlspecialchars($svc['name_ar'])?></span>
          <?php if($svc['method_id']): ?><span style="font-size:.65rem;color:#a78bfa;background:rgba(167,139,250,.1);padding:1px 6px;border-radius:5px">method=<?=$svc['method_id']?></span><?php endif; ?>
        </div>
        <div style="display:flex;gap:4px">
          <button class="btn btn-warning btn-sm" style="padding:2px 6px" onclick="openSadadSvcModal(<?=$svc['id']?>,0,<?=htmlspecialchars(json_encode($svc),ENT_QUOTES)?>)"><i class="fas fa-edit"></i></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف؟')">
        <?= adminCsrfField() ?>
            <input type="hidden" name="sadad_svc_id" value="<?=$svc['id']?>">
            <button type="submit" name="delete_sadad_svc" class="btn btn-danger btn-sm" style="padding:2px 6px"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card-body" style="padding:8px">
      <button class="btn btn-sm btn-success" onclick="openSadadCatModal(0,null,<?=$mc['id']?>)"><i class="fas fa-folder-plus"></i> قسم فرعي</button>
      <button class="btn btn-sm btn-primary" onclick="openSadadSvcModal(0,<?=$mc['id']?>)" style="margin-right:6px"><i class="fas fa-plus"></i> خدمة</button>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- ══ Modal قسم ══ -->
<div class="modal-overlay" id="sadadCatModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title" id="sadadCatModalTitle"><i class="fas fa-folder-plus"></i> قسم جديد</div>
      <button class="modal-close" onclick="document.getElementById('sadadCatModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="sadad_cat_id" id="sc_id" value="0">
        <input type="hidden" name="sadad_cat_clear_image" id="sc_clear_image" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم القسم *</label>
            <input type="text" name="sadad_cat_name" id="sc_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>نوع القسم</label>
            <select name="sadad_cat_type" id="sc_type" class="form-control">
              <option value="normal">📋 عادي — أقسام وخدمات</option>
              <option value="sadad">🧾 سداد — يفتح شاشة السداد مباشرة</option>
            </select>
          </div>
          <div class="form-group">
            <label>القسم الأب (اختياري)</label>
            <select name="sadad_cat_parent" id="sc_parent" class="form-control">
              <option value="">— قسم رئيسي —</option>
              <?php foreach($mainCats as $mc2): ?>
              <option value="<?=$mc2['id']?>"><?=htmlspecialchars($mc2['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الصورة (بدل الأيقونة)</label>
            <div style="display:flex;align-items:center;gap:10px">
              <label style="flex:1;padding:10px;border:2px dashed rgba(255,255,255,.15);border-radius:10px;cursor:pointer;text-align:center;font-size:.82rem;color:#8895a7" for="sc_image_inp">
                <i class="fas fa-image" style="display:block;font-size:1.5rem;margin-bottom:4px"></i>
                اختر صورة (PNG, JPG, SVG)
              </label>
              <input type="file" name="sadad_cat_image" id="sc_image_inp" accept="image/*" style="display:none" onchange="previewSadadImg(this,'sc_img_prev')">
              <div style="position:relative;display:inline-block">
                <img id="sc_img_prev" src="" style="width:50px;height:50px;border-radius:10px;object-fit:cover;display:none;border:1px solid var(--border)">
                <button type="button" onclick="document.getElementById('sc_img_prev').src='';document.getElementById('sc_img_prev').style.display='none';document.getElementById('sc_image_inp').value='';document.getElementById('sc_clear_image').value='1';this.style.display='none'"
                  id="sc_img_del" style="position:absolute;top:-6px;right:-6px;background:#ff4455;border:none;border-radius:50%;width:18px;height:18px;color:#fff;cursor:pointer;font-size:.7rem;display:none;padding:0;line-height:18px;text-align:center">✕</button>
              </div>
            </div>
            <small style="color:#8895a7">اتركه فارغاً لاستخدام الأيقونة أدناه</small>
          </div>
          <div class="form-group">
            <label>أيقونة (Font Awesome) — تظهر إن لم تُرفع صورة</label>
            <input type="text" name="sadad_cat_icon" id="sc_icon" class="form-control" placeholder="list">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="sadad_cat_color" id="sc_color" class="form-control" value="#6c3fe0" style="height:42px">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sadad_cat_sort" id="sc_sort" class="form-control" value="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
            <input type="checkbox" name="sadad_cat_status" id="sc_status" value="1" checked>
            <label for="sc_status">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_sadad_cat" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ Modal خدمة ══ -->
<div class="modal-overlay" id="sadadSvcModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div class="modal-title"><i class="fas fa-plus-circle"></i> خدمة سداد</div>
      <button class="modal-close" onclick="document.getElementById('sadadSvcModal').classList.remove('open')"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="sadad_svc_id" id="ss_id" value="0">
        <input type="hidden" name="sadad_svc_clear_image" id="ss_clear_image" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group" style="grid-column:1/-1">
            <label>اسم الخدمة *</label>
            <input type="text" name="sadad_svc_name" id="ss_name" class="form-control" required>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>القسم *</label>
            <select name="sadad_svc_cat" id="ss_cat" class="form-control" required>
              <?php foreach($sadadCats as $sc2): ?>
              <option value="<?=$sc2['id']?>"><?=str_repeat('— ',(int)!!$sc2['parent_id'])?><?=htmlspecialchars($sc2['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1">
            <label>الصورة (بدل الأيقونة)</label>
            <div style="display:flex;align-items:center;gap:10px">
              <label style="flex:1;padding:10px;border:2px dashed rgba(255,255,255,.15);border-radius:10px;cursor:pointer;text-align:center;font-size:.82rem;color:#8895a7" for="ss_image_inp">
                <i class="fas fa-image" style="display:block;font-size:1.5rem;margin-bottom:4px"></i>
                اختر صورة
              </label>
              <input type="file" name="sadad_svc_image" id="ss_image_inp" accept="image/*" style="display:none" onchange="previewSadadImg(this,'ss_img_prev')">
              <div style="position:relative;display:inline-block">
                <img id="ss_img_prev" src="" style="width:50px;height:50px;border-radius:10px;object-fit:cover;display:none;border:1px solid var(--border)">
                <button type="button" onclick="document.getElementById('ss_img_prev').src='';document.getElementById('ss_img_prev').style.display='none';document.getElementById('ss_image_inp').value='';document.getElementById('ss_clear_image').value='1';this.style.display='none'"
                  id="ss_img_del" style="position:absolute;top:-6px;right:-6px;background:#ff4455;border:none;border-radius:50%;width:18px;height:18px;color:#fff;cursor:pointer;font-size:.7rem;display:none;padding:0;line-height:18px;text-align:center">✕</button>
              </div>
            </div>
            <small style="color:#8895a7">اتركه فارغاً لاستخدام الأيقونة</small>
          </div>
          <div class="form-group">
            <label>أيقونة</label>
            <input type="text" name="sadad_svc_icon" id="ss_icon" class="form-control" placeholder="file-invoice">
          </div>
          <div class="form-group">
            <label>اللون</label>
            <input type="color" name="sadad_svc_color" id="ss_color" class="form-control" value="#f5a623" style="height:42px">
          </div>
          <div class="form-group">
            <label>المزود (method_id)</label>
            <select name="sadad_svc_method" id="ss_method" class="form-control">
              <option value="">— بدون مزود —</option>
              <?php foreach($sadadMethods??[] as $sm): ?>
              <option value="<?=$sm['method_id']?>"><?=$sm['method_id']?> — <?=htmlspecialchars($sm['name_ar'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>الإجراء</label>
            <select name="sadad_svc_action" id="ss_action" class="form-control" onchange="toggleSadadUrl(this.value)">
              <option value="bill">فاتورة (bill)</option>
              <option value="external">رابط خارجي</option>
              <option value="none">بدون إجراء</option>
            </select>
          </div>
          <div class="form-group" style="grid-column:1/-1" id="ss_url_wrap" style="display:none">
            <label>الرابط</label>
            <input type="url" name="sadad_svc_url" id="ss_url" class="form-control" placeholder="https://...">
          </div>
          <div class="form-group">
            <label>الترتيب</label>
            <input type="number" name="sadad_svc_sort" id="ss_sort" class="form-control" value="0">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.5rem">
            <input type="checkbox" name="sadad_svc_status" id="ss_status" value="1" checked>
            <label for="ss_status">نشط</label>
          </div>
        </div>
        <button type="submit" name="save_sadad_svc" class="btn btn-primary btn-block"><i class="fas fa-save"></i> حفظ</button>
      </form>
    </div>
  </div>
</div>

<script>
function openSadadCatModal(id, data, parentId) {
  document.getElementById('sadadCatModal').classList.add('open');
  document.getElementById('sadadCatModalTitle').innerHTML = id ? '<i class="fas fa-edit"></i> تعديل قسم' : '<i class="fas fa-folder-plus"></i> قسم جديد';
  document.getElementById('sc_id').value    = id || 0;
  document.getElementById('sc_parent').value = parentId || (data?.parent_id ?? '');
  document.getElementById('sc_name').value  = data?.name_ar   || '';
  document.getElementById('sc_icon').value  = data?.icon      || 'list';
  document.getElementById('sc_color').value = data?.color     || '#6c3fe0';
  document.getElementById('sc_type').value  = data?.type      || 'normal';
  document.getElementById('sc_sort').value  = data?.sort_order|| 0;
  document.getElementById('sc_status').checked = !data || data.status == 1;
  // معاينة الصورة الحالية
  const scPrev = document.getElementById('sc_img_prev');
  const scLabel = document.getElementById('sc_image_inp');
  if (scPrev) {
    if (data && data.image) {
      scPrev.src = '<?=SITE_URL?>/' + data.image;
      scPrev.style.display = 'block';
      const scDel = document.getElementById('sc_img_del'); if(scDel) scDel.style.display='block';
    } else {
      scPrev.src = ''; scPrev.style.display = 'none';
      const scDel2 = document.getElementById('sc_img_del'); if(scDel2) scDel2.style.display='none';
    }
  }
  if (scLabel) scLabel.value = '';
  const scClear = document.getElementById('sc_clear_image'); if(scClear) scClear.value='0';
}
function openSadadSvcModal(id, catId, data) {
  document.getElementById('sadadSvcModal').classList.add('open');
  document.getElementById('ss_id').value     = id || 0;
  document.getElementById('ss_cat').value    = catId || data?.category_id || '';
  document.getElementById('ss_name').value   = data?.name_ar      || '';
  document.getElementById('ss_icon').value   = data?.icon         || 'file-invoice';
  document.getElementById('ss_color').value  = data?.color        || '#f5a623';
  document.getElementById('ss_method').value = data?.method_id    || '';
  document.getElementById('ss_action').value = data?.action       || 'bill';
  toggleSadadUrl(data?.action || 'bill');
  document.getElementById('ss_url').value    = data?.action_value || '';
  document.getElementById('ss_sort').value   = data?.sort_order   || 0;
  document.getElementById('ss_status').checked = !data || data.status == 1;
  toggleSadadUrl(data?.action || 'bill');
  // معاينة الصورة الحالية
  const ssPrev = document.getElementById('ss_img_prev');
  const ssInp  = document.getElementById('ss_image_inp');
  if (ssPrev) {
    if (data && data.image) {
      ssPrev.src = '<?=SITE_URL?>/' + data.image;
      ssPrev.style.display = 'block';
      const ssDel = document.getElementById('ss_img_del'); if(ssDel) ssDel.style.display='block';
    } else {
      ssPrev.src = ''; ssPrev.style.display = 'none';
      const ssDel2 = document.getElementById('ss_img_del'); if(ssDel2) ssDel2.style.display='none';
    }
  }
  if (ssInp) ssInp.value = '';
  const ssClear = document.getElementById('ss_clear_image'); if(ssClear) ssClear.value='0';
}
function toggleSadadUrl(val) {
  const wrap = document.getElementById('ss_url_wrap');
  if (wrap) wrap.style.display = val === 'external' ? '' : 'none';
  const mw = document.getElementById('ss_method_wrap');

}
</script>

</div><!-- /tab-sadad -->
