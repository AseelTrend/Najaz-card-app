<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_categories_view');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'إدارة الأقسام - ' . SITE_NAME;

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

function uploadCategoryImage($fileKey, $oldImage = '') {
    if (empty($_FILES[$fileKey]['name']) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return $oldImage;
    $file = $_FILES[$fileKey];
    $allowedExt = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) return $oldImage;
    if ($file['size'] > 3*1024*1024) return $oldImage;
    $filename = 'cat_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
    $dir = dirname(__DIR__).'/assets/uploads/categories/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $dir.$filename)) {
        if ($oldImage) { $p=dirname(__DIR__).'/'.$oldImage; if(file_exists($p)) @unlink($p); }
        return 'assets/uploads/categories/'.$filename;
    }
    return $oldImage;
}

function buildTree(array $flat, $parentId = null): array {
    $tree = [];
    foreach ($flat as $item) {
        $pid = $item['parent_id'] === null ? null : (int)$item['parent_id'];
        if ($pid === $parentId) {
            $item['children'] = buildTree($flat, (int)$item['id']);
            $tree[] = $item;
        }
    }
    usort($tree, fn($a,$b) => $a['sort_order'] <=> $b['sort_order']);
    return $tree;
}

function flattenTree(array $tree, int $level = 0): array {
    $result = [];
    foreach ($tree as $node) {
        $node['_level'] = $level;
        $children = $node['children'];
        unset($node['children']);
        $result[] = $node;
        if (!empty($children)) $result = array_merge($result, flattenTree($children, $level+1));
    }
    return $result;
}

function getDescendantIds($pdo, int $catId): array {
    $ids = []; $stack = [$catId];
    while ($stack) {
        $cur = array_pop($stack);
        $ch = $pdo->prepare("SELECT id FROM categories WHERE parent_id=?");
        $ch->execute([$cur]);
        foreach ($ch->fetchAll() as $c) { $ids[] = (int)$c['id']; $stack[] = (int)$c['id']; }
    }
    return $ids;
}

function getCategoryPath($pdo, int $catId): array {
    $path = []; $current = $catId; $visited = [];
    while ($current) {
        if (in_array($current,$visited)) break; $visited[] = $current;
        $s = $pdo->prepare("SELECT id,name,parent_id FROM categories WHERE id=?");
        $s->execute([$current]); $cat = $s->fetch();
        if (!$cat) break;
        array_unshift($path, $cat);
        $current = $cat['parent_id'] ? (int)$cat['parent_id'] : null;
    }
    return $path;
}

// ── حذف ──────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    if (!isAdmin() && !canAccess($pdo,'perm_categories_edit')) { flashMessage('danger','لا صلاحية'); redirect(SITE_URL.'/admin/categories.php'); }
    $toDelete = array_merge([$id], getDescendantIds($pdo,$id));
    foreach ($toDelete as $did) {
        $c = $pdo->prepare("SELECT image FROM categories WHERE id=?"); $c->execute([$did]); $c=$c->fetch();
        if ($c && $c['image']) @unlink(dirname(__DIR__).'/'.$c['image']);
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$did]);
    }
    flashMessage('success','تم الحذف ('.count($toDelete).' قسم)');
    $rp = (int)($_GET['rp']??0);
    redirect(SITE_URL.'/admin/categories.php'.($rp?'?browse='.$rp:''));
}

// ── تبديل ────────────────────────────────────────────────────────────────────
if ($action === 'toggle' && $id) {
    $pdo->prepare("UPDATE categories SET status=IF(status=1,0,1) WHERE id=?")->execute([$id]);
    $rp = (int)($_GET['rp']??0);
    redirect(SITE_URL.'/admin/categories.php'.($rp?'?browse='.$rp:''));
}

// ── حفظ ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!isAdmin() && !canAccess($pdo,'perm_categories_edit')) { flashMessage('danger','لا صلاحية'); redirect(SITE_URL.'/admin/categories.php'); }
    $name     = trim($_POST['name']);
    $icon     = trim($_POST['icon']??'folder');
    $parentId = (int)($_POST['parent_id']??0) ?: null;
    $sort     = (int)($_POST['sort_order']??0);
    $returnTo = (int)($_POST['return_to']??0);

    if ($id && $parentId) {
        $desc = getDescendantIds($pdo,$id);
        if ($parentId===$id || in_array($parentId,$desc)) { flashMessage('danger','لا يمكن جعل القسم تابعاً لنفسه أو لأحد أقسامه'); redirect(SITE_URL.'/admin/categories.php?action=edit&id='.$id); }
    }

    if ($id) {
        try { $old=$pdo->prepare("SELECT image FROM categories WHERE id=?"); $old->execute([$id]); $old=$old->fetch(); $img=uploadCategoryImage('image',$old['image']??''); $catType=in_array($_POST['category_type']??'',['default','telecom'])?$_POST['category_type']:'default'; $pdo->prepare("UPDATE categories SET name=?,icon=?,parent_id=?,sort_order=?,image=?,category_type=? WHERE id=?")->execute([$name,$icon,$parentId,$sort,$img,$catType,$id]); }
        catch(\PDOException $e) { $catType=in_array($_POST['category_type']??'',['default','telecom'])?$_POST['category_type']:'default'; try{$pdo->prepare("UPDATE categories SET name=?,icon=?,parent_id=?,sort_order=?,category_type=? WHERE id=?")->execute([$name,$icon,$parentId,$sort,$catType,$id]);}catch(\PDOException $e2){$pdo->prepare("UPDATE categories SET name=?,icon=?,parent_id=?,sort_order=? WHERE id=?")->execute([$name,$icon,$parentId,$sort,$id]);} }
        flashMessage('success','تم التحديث');
    } else {
        $catType=in_array($_POST['category_type']??'',['default','telecom'])?$_POST['category_type']:'default';
        try { $img=uploadCategoryImage('image',''); $pdo->prepare("INSERT INTO categories (name,icon,parent_id,sort_order,image,category_type) VALUES (?,?,?,?,?,?)")->execute([$name,$icon,$parentId,$sort,$img,$catType]); }
        catch(\PDOException $e) { try{$pdo->prepare("INSERT INTO categories (name,icon,parent_id,sort_order,category_type) VALUES (?,?,?,?,?)")->execute([$name,$icon,$parentId,$sort,$catType]);}catch(\PDOException $e2){$pdo->prepare("INSERT INTO categories (name,icon,parent_id,sort_order) VALUES (?,?,?,?)")->execute([$name,$icon,$parentId,$sort]);} }
        flashMessage('success','تمت الإضافة');
    }
    redirect(SITE_URL.'/admin/categories.php'.($returnTo?'?browse='.$returnTo:''));
}

// ── جلب البيانات ──────────────────────────────────────────────────────────────
$allCats = $pdo->query("SELECT c.*,p.name as parent_name FROM categories c LEFT JOIN categories p ON c.parent_id=p.id ORDER BY c.sort_order,c.id")->fetchAll();
$tree = buildTree($allCats);
$flat = flattenTree($tree);

$browseId   = (int)($_GET['browse']??0);
$browsePath = [];
$browseCat  = null;
$browseLevel= [];

if ($browseId) {
    $s=$pdo->prepare("SELECT * FROM categories WHERE id=?"); $s->execute([$browseId]); $browseCat=$s->fetch();
    $browsePath = getCategoryPath($pdo,$browseId);
    $ch=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM categories WHERE parent_id=c.id) as cc,(SELECT COUNT(*) FROM services WHERE category_id=c.id) as sc FROM categories c WHERE c.parent_id=? ORDER BY c.sort_order");
    $ch->execute([$browseId]); $browseLevel=$ch->fetchAll();
} else {
    $root=$pdo->query("SELECT c.*,(SELECT COUNT(*) FROM categories WHERE parent_id=c.id) as cc,(SELECT COUNT(*) FROM services WHERE category_id=c.id) as sc FROM categories c WHERE c.parent_id IS NULL ORDER BY c.sort_order")->fetchAll();
    $browseLevel=$root;
}

$editCat   = null;
$preParent = (int)($_GET['parent']??0);
if ($id && in_array($action,['edit','add'])) { $s=$pdo->prepare("SELECT * FROM categories WHERE id=?"); $s->execute([$id]); $editCat=$s->fetch(); }

include 'header.php';
?>
<style>
.cat-breadcrumb{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:1rem;font-size:.85rem;padding:10px 14px;background:var(--card2);border-radius:10px;border:1px solid var(--border)}
.crumb{color:#8895a7;cursor:pointer;padding:2px 6px;border-radius:6px;transition:.15s}
.crumb:hover{background:rgba(255,255,255,.06);color:#fff}
.crumb.active{color:#fff;font-weight:800}
.crumb-sep{color:var(--border)}
.lvl-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(108,63,224,.12);border:1px solid rgba(108,63,224,.25);border-radius:8px;padding:4px 12px;font-size:.75rem;color:#a78bfa;font-weight:700;margin-bottom:1rem}
.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.cat-card2{background:var(--card2);border:1.5px solid var(--border);border-radius:14px;overflow:hidden;transition:.2s;display:flex;flex-direction:column}
.cat-card2:hover{border-color:rgba(108,63,224,.5);box-shadow:0 4px 20px rgba(108,63,224,.12)}
.cat-card2-head{display:flex;align-items:center;gap:10px;padding:14px;cursor:pointer;flex:1}
.cat-card2-thumb{width:42px;height:42px;border-radius:12px;background:rgba(108,63,224,.15);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;overflow:hidden}
.cat-card2-thumb img{width:100%;height:100%;object-fit:cover}
.cat-card2-name{font-weight:800;font-size:.93rem}
.cat-card2-meta{margin-top:4px;display:flex;gap:6px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:8px;font-size:.68rem;font-weight:700}
.chip-blue{background:rgba(30,111,255,.15);color:#7eb3ff}
.chip-green{background:rgba(0,212,170,.15);color:#00d4aa}
.chip-gray{background:rgba(255,255,255,.05);color:#8895a7}
.cat-card2-footer{display:flex;align-items:center;gap:5px;padding:8px 10px;border-top:1px solid var(--border);background:rgba(0,0,0,.1)}

/* شجرة كاملة */
.tree-node{border-bottom:1px solid rgba(255,255,255,.03)}
.tree-node:last-child{border-bottom:none}
.tree-node-row{display:flex;align-items:center;gap:8px;padding:9px 12px;transition:.15s;cursor:default}
.tree-node-row:hover{background:rgba(255,255,255,.025)}
.img-preview-box{width:90px;height:70px;border-radius:10px;border:2px solid var(--border);background:var(--bg3);display:flex;align-items:center;justify-content:center;overflow:hidden}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-icon" style="background:rgba(108,63,224,.15)"><i class="fas fa-folder-open" style="color:var(--primary)"></i></div>
      إدارة الأقسام
    </div>
  </div>
  <div class="page-header-right">
    <button class="btn btn-secondary btn-sm" onclick="toggleView()" id="viewToggleBtn"><i class="fas fa-sitemap"></i> الشجرة</button>
    <?php if($IS_ADMIN||canAccess($pdo,'perm_categories_edit')): ?>
    <a href="?action=add<?=$browseId?'&parent='.$browseId:''?>" class="btn btn-primary">
      <i class="fas fa-folder-plus"></i> <?=$browseId?'إضافة فرعي':'قسم جديد'?>
    </a>
    <?php endif; ?>
  </div>
</div>

<?php /* ═══ فورم ═══ */ ?>
<?php if($action==='add'||$action==='edit'): ?>
<div class="card mb-2">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:8px">
    <h3 style="margin:0"><?=$action==='edit'?'تعديل: '.htmlspecialchars($editCat['name']??''):'إضافة قسم جديد'?></h3>
    <?php if($action==='edit'&&$editCat): $ep=getCategoryPath($pdo,$id); ?>
    <div style="display:flex;align-items:center;gap:5px;font-size:.8rem;color:#8895a7">
      <?php foreach($ep as $i=>$p): ?>
      <?php if($i>0): ?><i class="fas fa-chevron-left" style="font-size:.65rem"></i><?php endif; ?>
      <span style="<?=$p['id']==$id?'color:#fff;font-weight:800':''?>"><?=htmlspecialchars($p['name'])?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
    <input type="hidden" name="return_to" value="<?=$preParent?:($editCat['parent_id']??0)?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="form-group">
        <label>اسم القسم *</label>
        <input type="text" name="name" value="<?=htmlspecialchars($editCat['name']??'')?>" required>
      </div>
      <div class="form-group">
        <label>الأيقونة <a href="https://fontawesome.com/icons" target="_blank" style="font-size:.73rem;color:var(--primary)">FA Icons ↗</a></label>
        <div style="display:flex;gap:6px">
          <input type="text" name="icon" id="iconInp" value="<?=htmlspecialchars($editCat['icon']??'folder')?>" placeholder="gamepad" oninput="document.getElementById('iPrev').className='fas fa-'+this.value">
          <div style="width:42px;height:42px;border:1px solid var(--border);border-radius:8px;background:var(--bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?=htmlspecialchars($editCat['icon']??'folder')?>" id="iPrev" style="color:var(--primary)"></i>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label>القسم الأب (يحدد المستوى)</label>
        <select name="parent_id">
          <option value="">── قسم رئيسي (المستوى 1) ──</option>
          <?php foreach($flat as $fc):
            if($fc['id']===$id) continue;
            if($id&&in_array($fc['id'],getDescendantIds($pdo,$id))) continue;
            $prefix = str_repeat('　',$fc['_level']).($fc['_level']>0?'└ ':'');
            $sel = ($editCat['parent_id']??$preParent)==$fc['id']?'selected':'';
          ?>
          <option value="<?=$fc['id']?>" <?=$sel?>><?=$prefix?><?=htmlspecialchars($fc['name'])?> (م.<?=$fc['_level']+1?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>الترتيب</label>
        <input type="number" name="sort_order" value="<?=$editCat['sort_order']??0?>" min="0">
      </div>
      <div class="form-group">
        <label><i class="fas fa-layer-group"></i> نوع القسم</label>
        <select name="category_type">
          <option value="default" <?=($editCat['category_type']??'default')==='default'?'selected':''?>>عادي (خدمات)</option>
          <option value="telecom" <?=($editCat['category_type']??'')==='telecom'?'selected':''?>>📡 كبينة السداد (اتصالات)</option>
        </select>
        <small style="color:var(--text-muted);font-size:0.78rem">عند اختيار "كبينة السداد" يفتح القسم واجهة شحن الاتصالات مباشرة</small>
      </div>
    </div>
    <div class="form-group">
      <label><i class="fas fa-image"></i> صورة القسم</label>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <div class="img-preview-box">
          <?php if(!empty($editCat['image'])): ?>
          <img src="<?=SITE_URL?>/<?=htmlspecialchars($editCat['image'])?>" id="catImgEl" style="width:100%;height:100%;object-fit:cover">
          <i class="fas fa-image" id="catImgPlaceholder" style="font-size:2rem;color:var(--text3);display:none"></i>
          <?php else: ?>
          <i class="fas fa-image" id="catImgPlaceholder" style="font-size:2rem;color:var(--text3)"></i>
          <img src="" id="catImgEl" style="display:none;width:100%;height:100%;object-fit:cover">
          <?php endif; ?>
        </div>
        <div>
          <label style="display:inline-flex;align-items:center;gap:6px;background:var(--bg3);border:1px dashed var(--border);color:var(--text2);padding:8px 16px;border-radius:8px;cursor:pointer;font-size:.85rem" for="catImg">
            <i class="fas fa-cloud-upload-alt"></i> اختر صورة
          </label>
          <input type="file" name="image" id="catImg" accept="image/*" style="display:none" onchange="prevImg(this)">
          <div style="font-size:.75rem;color:#8895a7;margin-top:4px">PNG, JPG, WebP — حد أقصى 3MB</div>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ</button>
      <a href="categories.php<?=$preParent?'?browse='.$preParent:''?>" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php /* ═══ Breadcrumb ═══ */ ?>
<div class="cat-breadcrumb">
  <span class="crumb <?=!$browseId?'active':''?>" onclick="location='categories.php'"><i class="fas fa-home"></i> الجذر</span>
  <?php foreach($browsePath as $p): ?>
  <span class="crumb-sep"><i class="fas fa-chevron-left" style="font-size:.65rem"></i></span>
  <span class="crumb <?=$p['id']==$browseId?'active':''?>" onclick="location='categories.php?browse=<?=$p['id']?>'">
    <?=htmlspecialchars($p['name'])?>
  </span>
  <?php endforeach; ?>
</div>

<?php if($browseId): ?>
<div class="lvl-badge"><i class="fas fa-layer-group"></i> المستوى <?=count($browsePath)+1?> — داخل: <strong style="color:#fff"><?=htmlspecialchars($browseCat['name']??'')?></strong></div>
<?php endif; ?>

<!-- ═══ عرض الشجرة الكاملة (مخفي) ═══ -->
<div id="fullTreeView" style="display:none;margin-bottom:1rem">
<div class="card">
  <div style="padding:12px 16px 10px;border-bottom:1px solid var(--border);font-weight:800"><i class="fas fa-sitemap" style="color:var(--primary)"></i> الشجرة الكاملة</div>
  <?php
  function renderTreeRows(array $nodes, int $level=0) {
    foreach($nodes as $node):
      $hasChildren = !empty($node['children']);
  ?>
  <div class="tree-node">
    <div class="tree-node-row" style="padding-right:<?=12+$level*22?>px">
      <?php if($level>0): ?>
      <div style="width:12px;height:20px;border-bottom:1.5px solid var(--border);border-right:1.5px solid var(--border);border-radius:0 0 6px 0;flex-shrink:0;margin-left:4px;margin-right:-4px"></div>
      <?php endif; ?>
      <?php if(!empty($node['image'])): ?>
      <img src="<?=SITE_URL?>/<?=htmlspecialchars($node['image'])?>" style="width:28px;height:28px;border-radius:8px;object-fit:cover;flex-shrink:0">
      <?php else: ?>
      <div style="width:28px;height:28px;border-radius:8px;background:rgba(108,63,224,.15);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0"><i class="fas fa-<?=htmlspecialchars($node['icon']??'folder')?>"></i></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.87rem"><?=htmlspecialchars($node['name'])?></div>
      </div>
      <span style="font-size:.65rem;color:#8895a7;background:var(--card2);padding:2px 6px;border-radius:5px;flex-shrink:0">م.<?=$level+1?></span>
      <div style="display:flex;gap:4px;flex-shrink:0">
        <a href="?browse=<?=$node['id']?>" class="btn btn-xs btn-secondary"><i class="fas fa-folder-open"></i></a>
        <a href="?action=edit&id=<?=$node['id']?>" class="btn btn-xs btn-warning"><i class="fas fa-edit"></i></a>
        <?php if(!empty($node['children'])): ?>
        <span style="font-size:.68rem;color:var(--primary);padding:2px 6px;background:rgba(30,111,255,.1);border-radius:5px"><?=count($node['children'])?> فرعي</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if($hasChildren): renderTreeRows($node['children'],$level+1); endif; ?>
  </div>
  <?php endforeach; }
  renderTreeRows($tree); ?>
</div>
</div>

<!-- ═══ عرض البطاقات ═══ -->
<div id="cardView">
<?php if(empty($browseLevel)): ?>
<div class="card" style="text-align:center;padding:3rem;color:#8895a7">
  <i class="fas fa-folder-open" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
  <?php if($browseId): ?>
  لا توجد أقسام فرعية في هذا القسم
  <?php if($IS_ADMIN||canAccess($pdo,'perm_categories_edit')): ?>
  <div style="margin-top:1rem"><a href="?action=add&parent=<?=$browseId?>" class="btn btn-primary"><i class="fas fa-folder-plus"></i> إضافة قسم فرعي</a></div>
  <?php endif; ?>
  <?php else: ?>لا توجد أقسام بعد<?php endif; ?>
</div>
<?php else: ?>
<div class="cat-grid">
<?php foreach($browseLevel as $cat):
  $hasChildren = $cat['cc'] > 0;
  $hasServices = $cat['sc'] > 0;
?>
<div class="cat-card2">
  <div class="cat-card2-head" onclick="location='?browse=<?=$cat['id']?>'">
    <div class="cat-card2-thumb">
      <?php if(!empty($cat['image'])): ?>
      <img src="<?=SITE_URL?>/<?=htmlspecialchars($cat['image'])?>">
      <?php else: ?>
      <i class="fas fa-<?=htmlspecialchars($cat['icon']??'folder')?>"></i>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div class="cat-card2-name"><?=htmlspecialchars($cat['name'])?></div>
      <div class="cat-card2-meta">
        <?php if($hasChildren): ?><span class="chip chip-blue"><i class="fas fa-folder"></i> <?=$cat['cc']?> قسم فرعي</span><?php endif; ?>
        <?php if($hasServices): ?><span class="chip chip-green"><i class="fas fa-box"></i> <?=$cat['sc']?> خدمة</span><?php endif; ?>
        <?php if(!$hasChildren&&!$hasServices): ?><span class="chip chip-gray">فارغ</span><?php endif; ?>
      </div>
    </div>
    <?php if($hasChildren): ?><i class="fas fa-chevron-left" style="color:#8895a7;font-size:.75rem;flex-shrink:0"></i><?php endif; ?>
  </div>

  <div class="cat-card2-footer">
    <span class="badge <?=$cat['status']?'badge-success':'badge-danger'?>" style="font-size:.67rem"><?=$cat['status']?'نشط':'متوقف'?></span>
    <div style="margin-right:auto;display:flex;gap:4px">
      <?php if($IS_ADMIN||canAccess($pdo,'perm_categories_edit')): ?>
      <a href="?action=add&parent=<?=$cat['id']?>" class="btn btn-xs btn-primary" title="إضافة قسم فرعي داخله"><i class="fas fa-folder-plus"></i></a>
      <?php endif; ?>
      <a href="?action=edit&id=<?=$cat['id']?>" class="btn btn-xs btn-warning" title="تعديل"><i class="fas fa-edit"></i></a>
      <?php if($IS_ADMIN||canAccess($pdo,'perm_categories_edit')): ?>
      <a href="?action=toggle&id=<?=$cat['id']?>&rp=<?=$browseId?>" class="btn btn-xs btn-secondary"><i class="fas fa-<?=$cat['status']?'eye-slash':'eye'?>"></i></a>
      <a href="?action=delete&id=<?=$cat['id']?>&rp=<?=$browseId?>"
         class="btn btn-xs btn-danger"
         onclick="return confirm('حذف <?=addslashes(htmlspecialchars($cat['name']))?> وجميع أقسامه الفرعية (<?=$cat['cc']?> قسم فرعي)؟')">
        <i class="fas fa-trash"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<script>
// ── ضغط الصور إلى WebP / JPEG بحجم أقل من 150KB ────────────
const USE_WEBP_CAT = document.createElement('canvas')
                       .toDataURL('image/webp').startsWith('data:image/webp');
const CAT_FORMAT   = USE_WEBP_CAT ? 'image/webp' : 'image/jpeg';
const CAT_EXT      = USE_WEBP_CAT ? 'webp' : 'jpg';
const CAT_TARGET   = 150 * 1024;  // 150 KB

function compressCatImage(file) {
  return new Promise(resolve => {
    const reader = new FileReader();
    reader.onload = ev => {
      const img = new Image();
      img.onload = () => {
        const MAX = 1024;
        let w = img.width, h = img.height;
        if (w > MAX || h > MAX) {
          if (w >= h) { h = Math.round(h * MAX / w); w = MAX; }
          else        { w = Math.round(w * MAX / h); h = MAX; }
        }
        const canvas = document.createElement('canvas');
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        let quality = 0.80;
        const attempt = () => {
          canvas.toBlob(blob => {
            if (!blob) { resolve(null); return; }
            if (blob.size <= CAT_TARGET || quality <= 0.25) { resolve(blob); }
            else { quality = Math.max(0.25, quality - 0.10); attempt(); }
          }, CAT_FORMAT, quality);
        };
        attempt();
      };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  });
}

async function prevImg(input) {
  if(!input.files||!input.files[0]) return;
  const f = input.files[0];
  if (!f.type.startsWith('image/')) { alert('الملف يجب أن يكون صورة'); input.value=''; return; }

  // ضغط الصورة
  const blob = await compressCatImage(f);
  if (!blob) return;

  // استبدال الملف في الـ input بالنسخة المضغوطة
  const compressed = new File([blob], 'image.' + CAT_EXT, { type: CAT_FORMAT });
  const dt = new DataTransfer();
  dt.items.add(compressed);
  input.files = dt.files;

  // معاينة
  const url = URL.createObjectURL(blob);
  const img = document.getElementById('catImgEl');
  const ph  = document.getElementById('catImgPlaceholder');
  if(img){img.src=url;img.style.display='block';}
  if(ph){ph.style.display='none';}
}
let treeOn=false;
function toggleView(){
  treeOn=!treeOn;
  document.getElementById('fullTreeView').style.display=treeOn?'block':'none';
  document.getElementById('cardView').style.display=treeOn?'none':'block';
  document.getElementById('viewToggleBtn').innerHTML=treeOn?'<i class="fas fa-th-large"></i> البطاقات':'<i class="fas fa-sitemap"></i> الشجرة';
}
</script>
<?php include 'footer.php'; ?>
