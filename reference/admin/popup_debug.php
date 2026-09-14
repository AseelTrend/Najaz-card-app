<?php
require_once '../includes/config.php';
require_once '../includes/notifications.php';
requireAdmin($pdo);
$pageTitle = 'تشخيص Popup';

// اختبار مباشر
$userId = (int)($_GET['uid'] ?? 1);
// تشخيص مباشر
$debugLog = [];

// خطوة 1: جلب كل النشطة
try {
    $now = date('Y-m-d H:i:s');
    $allActive = $pdo->query("SELECT * FROM notification_broadcasts WHERE is_active=1")->fetchAll();
    $debugLog[] = "كل النشطة: " . count($allActive);
    
    // خطوة 2: فحص end_at
    $notExpired = array_filter($allActive, fn($r) => !$r['end_at'] || $r['end_at'] > $now);
    $debugLog[] = "غير منتهية: " . count($notExpired);
    
    // خطوة 3: فحص broadcast_views
    foreach ($notExpired as $nb) {
        $v = $pdo->query("SELECT COUNT(*) FROM broadcast_views WHERE broadcast_id={$nb['id']} AND user_id=$userId")->fetchColumn();
        $debugLog[] = "Popup #{$nb['id']} '{$nb['title']}': viewed=$v, show_once={$nb['show_once']}";
    }
} catch(Exception $e) {
    $debugLog[] = "❌ خطأ: " . $e->getMessage();
}

$popups = getActiveBroadcastsForUser($pdo, $userId);
$debugLog[] = "getActiveBroadcastsForUser نتيجة: " . count($popups);

// اختبار مباشر بدون الدالة
try {
    $uid2 = (int)$userId;
    $now2 = date('Y-m-d H:i:s');
    $direct = $pdo->query("SELECT * FROM notification_broadcasts WHERE is_active=1 AND (end_at IS NULL OR end_at > '$now2') ORDER BY created_at DESC LIMIT 10")->fetchAll();
    $debugLog[] = "Query مباشر: " . count($direct) . " نتيجة";
    
    // إذا وجد نتائج مباشرة — المشكلة في الدالة القديمة على السيرفر
    if (count($direct) > 0 && count($popups) == 0) {
        $debugLog[] = "⚠️ الملف القديم لا يزال على السيرفر! يجب رفع notifications.php الجديد";
        // نستخدم النتائج المباشرة
        $popups = $direct;
    }
} catch(Exception $e) {
    $debugLog[] = "Direct query error: " . $e->getMessage();
}

// جلب كل البث
$all = $pdo->query("SELECT * FROM notification_broadcasts ORDER BY id DESC")->fetchAll();
$views = $pdo->query("SELECT * FROM broadcast_views ORDER BY id DESC LIMIT 20")->fetchAll();

// اختبار force show
if (isset($_GET['force']) && $_GET['force']) {
    // امسح broadcast_views لهذا المستخدم
    $pdo->prepare("DELETE FROM broadcast_views WHERE user_id=?")->execute([$userId]);
    header('Location: popup_debug.php?uid='.$userId.'&msg=cleared');
    exit;
}

include 'header.php';
?>

<div class="page-header">
  <div class="page-header-title">🔍 تشخيص Popup</div>
</div>

<?php if(isset($_GET['msg'])): ?>
<div class="alert alert-success">تم مسح سجل المشاهدات للمستخدم #<?= $userId ?></div>
<?php endif; ?>

<!-- اختيار مستخدم -->
<div class="card mb-2">
  <div class="card-body">
    <form method="GET">
      <div style="display:flex;gap:10px;align-items:center">
        <label>اختبر كمستخدم ID:</label>
        <input type="number" name="uid" value="<?= $userId ?>" class="form-control" style="width:100px">
        <button type="submit" class="btn btn-primary">فحص</button>
        <a href="?uid=<?= $userId ?>&force=1" class="btn btn-warning" onclick="return confirm('مسح سجل المشاهدات؟')">
          <i class="fas fa-trash"></i> مسح سجل المشاهدات (إعادة الظهور)
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Debug Log -->
<div class="card mb-2" style="border-color:rgba(245,166,35,.4)">
  <div class="card-header" style="background:rgba(245,166,35,.1)">
    <div class="card-header-title" style="color:#f5a623">🔬 تشخيص تفصيلي</div>
  </div>
  <div class="card-body">
    <?php foreach($debugLog as $log): ?>
    <div style="font-family:monospace;font-size:12px;padding:4px 0;border-bottom:1px solid var(--border)">
      <?= htmlspecialchars($log) ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- نتيجة API -->
<div class="card mb-2">
  <div class="card-header">
    <div class="card-header-title">
      <i class="fas fa-vial"></i>
      نتيجة getActiveBroadcastsForUser للمستخدم #<?= $userId ?>
      — وجد <strong style="color:<?= count($popups)?'var(--green)':'var(--red)' ?>"><?= count($popups) ?></strong> popup
    </div>
  </div>
  <div class="card-body">
    <?php if (empty($popups)): ?>
    <div style="color:var(--red);font-weight:700">❌ لا يوجد popup مناسب لهذا المستخدم</div>
    <?php else: ?>
    <?php foreach($popups as $p): ?>
    <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:8px">
      <strong style="color:var(--green)">✓ #<?= $p['id'] ?> — <?= htmlspecialchars($p['title']) ?></strong><br>
      <small style="color:var(--text3)">
        is_active=<?= $p['is_active'] ?> |
        show_once=<?= $p['show_once'] ?> |
        target=<?= $p['target'] ?> |
        end_at=<?= $p['end_at']??'NULL' ?>
      </small>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- جميع البث -->
<div class="card mb-2">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-list"></i> كل notification_broadcasts (<?= count($all) ?>)</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>العنوان</th><th>is_active</th><th>show_once</th><th>target</th><th>end_at</th><th>created_at</th></tr></thead>
      <tbody>
      <?php foreach($all as $b): ?>
      <tr>
        <td><?= $b['id'] ?></td>
        <td><?= htmlspecialchars($b['title']) ?></td>
        <td><span style="color:<?= $b['is_active']?'var(--green)':'var(--red)' ?>"><?= $b['is_active']?'✓ نشط':'✗ معطل' ?></span></td>
        <td><?= $b['show_once'] ?></td>
        <td><?= $b['target'] ?></td>
        <td style="font-size:11px"><?= $b['end_at']??'—' ?></td>
        <td style="font-size:11px"><?= $b['created_at'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- سجل المشاهدات -->
<div class="card mb-2">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-eye"></i> broadcast_views (آخر 20)</div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>broadcast_id</th><th>user_id</th><th>viewed_at</th></tr></thead>
      <tbody>
      <?php foreach($views as $v): ?>
      <tr style="<?= $v['user_id']==$userId?'background:rgba(30,111,255,.1)':'' ?>">
        <td><?= $v['id'] ?></td>
        <td><?= $v['broadcast_id'] ?></td>
        <td><?= $v['user_id'] ?> <?= $v['user_id']==$userId?'← هذا المستخدم':'' ?></td>
        <td style="font-size:11px"><?= $v['viewed_at']??$v['created_at']??'—' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($views)): ?>
      <tr><td colspan="4" style="text-align:center;color:var(--text3)">فارغ</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- اختبار API مباشر -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-terminal"></i> اختبار API مباشر</div>
  </div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text3);margin-bottom:10px">
      افتح هذا الرابط في المتصفح وأنت مسجل دخول كعميل:
    </p>
    <code style="background:var(--bg2);padding:8px 12px;border-radius:8px;display:block;font-size:12px;word-break:break-all">
      <?= SITE_URL ?>/api/notifications.php?action=get_popups
    </code>
    <div style="margin-top:12px">
      <button onclick="testAPI()" class="btn btn-primary">
        <i class="fas fa-play"></i> اختبر الآن (كمستخدم أدمن)
      </button>
      <pre id="apiResult" style="margin-top:12px;background:var(--bg2);padding:12px;border-radius:8px;font-size:12px;display:none"></pre>
    </div>
  </div>
</div>

<!-- اختبار عرض Popup مباشر -->
<div class="card" style="margin-top:12px">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-window-restore"></i> اختبار عرض Popup مباشرة</div>
  </div>
  <div class="card-body">
    <button onclick="testPopupDirect()" class="btn btn-success">
      <i class="fas fa-eye"></i> عرض Popup تجريبي الآن
    </button>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">يعرض popup بدون API</p>
  </div>
</div>

<!-- Popup تجريبي -->
<div id="testPopupOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;padding:20px">
  <div style="background:#111827;border-radius:20px;padding:28px 22px;max-width:340px;width:100%;text-align:center;border:1px solid #1e2940">
    <div style="width:64px;height:64px;border-radius:50%;background:#6c3fe022;color:#6c3fe0;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 16px">
      <i class="fas fa-bell"></i>
    </div>
    <div style="font-size:1.1rem;font-weight:900;margin-bottom:10px">عنوان تجريبي 🎉</div>
    <div style="font-size:.87rem;color:#9ca3af;margin-bottom:20px">هذا popup تجريبي للتأكد من أن الظهور يعمل</div>
    <div style="display:flex;gap:8px">
      <button onclick="document.getElementById('testPopupOverlay').style.display='none'" style="flex:1;padding:11px;border-radius:12px;border:none;background:#6c3fe0;color:#fff;font-weight:700;cursor:pointer">موافق</button>
      <button onclick="document.getElementById('testPopupOverlay').style.display='none'" style="padding:11px 16px;border-radius:12px;border:1px solid #374151;background:#1f2937;color:#9ca3af;cursor:pointer">لاحقاً</button>
    </div>
  </div>
</div>

<script>
async function testAPI() {
  const r = await fetch('<?= SITE_URL ?>/api/notifications.php?action=get_popups');
  const d = await r.json();
  const el = document.getElementById('apiResult');
  el.textContent = JSON.stringify(d, null, 2);
  el.style.display = 'block';
}
function testPopupDirect() {
  document.getElementById('testPopupOverlay').style.display = 'flex';
}
</script>

<?php include 'footer.php'; ?>
