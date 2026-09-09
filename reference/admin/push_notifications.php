<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'إشعارات Push — ' . SITE_NAME;

// إنشاء الجدول
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `push_subscriptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `endpoint` TEXT NOT NULL,
    `p256dh` VARCHAR(500) NOT NULL,
    `auth` VARCHAR(200) NOT NULL,
    `user_agent` VARCHAR(300) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_used` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e) {}

// التحقق من مفاتيح VAPID
$vapidPublicKey  = getSetting('vapid_public_key') ?: 'BEJacYbEinWKvQ4UG2hUmCY_ldzr3yAr25RVspPYSP3FfHqMPK44K7aBMurSsR-fadwGjMixbiKTHnw6MWnWw6o';
$vapidConfigured = !empty(getSetting('vapid_private_key'));

// إحصاءات
$totalSubs = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
$totalUsers = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions")->fetchColumn();

// سجل الإشعارات
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `push_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(200) NOT NULL,
        `body` TEXT,
        `url` VARCHAR(500),
        `target` ENUM('all','user') DEFAULT 'all',
        `target_user_id` INT DEFAULT NULL,
        `sent_count` INT DEFAULT 0,
        `failed_count` INT DEFAULT 0,
        `sent_by` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

$logs = $pdo->query("SELECT pl.*, u.username as sender FROM push_log pl LEFT JOIN users u ON pl.sent_by=u.id ORDER BY pl.created_at DESC LIMIT 20")->fetchAll();

// العملاء المشتركون
$subscribers = $pdo->query("
    SELECT ps.user_id, u.username, u.full_name, COUNT(ps.id) as devices,
           MAX(ps.created_at) as subscribed_at, MAX(ps.last_used) as last_used
    FROM push_subscriptions ps
    JOIN users u ON ps.user_id=u.id
    GROUP BY ps.user_id, u.username, u.full_name
    ORDER BY subscribed_at DESC
    LIMIT 50
")->fetchAll();

include 'header.php';
?>
<style>
.push-stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
.push-stat-num{font-size:2rem;font-weight:900}
.push-stat-lbl{font-size:.75rem;color:var(--text3);margin-top:4px}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(245,166,35,.15);color:#f5a623"><i class="fas fa-bell"></i></div>
      إشعارات Push
    </div>
  </div>
  <div class="page-header-actions">
    <a href="<?=SITE_URL?>/admin/generate_vapid.php" class="btn btn-secondary btn-sm">
      <i class="fas fa-key"></i> إعداد VAPID
    </a>
  </div>
</div>

<?php if(!$vapidConfigured): ?>
<div class="alert alert-warning" style="margin-bottom:16px">
  <i class="fas fa-exclamation-triangle"></i>
  <strong>مفاتيح VAPID غير مضبوطة!</strong> —
  الإشعارات لن تعمل حتى تضبط المفاتيح.
  <a href="<?=SITE_URL?>/admin/generate_vapid.php" style="color:inherit;font-weight:bold;text-decoration:underline">اضبطها الآن →</a>
</div>
<?php else: ?>
<div class="alert alert-success" style="margin-bottom:16px;padding:10px 14px;font-size:.82rem">
  <i class="fas fa-check-circle"></i> مفاتيح VAPID مضبوطة — النظام جاهز للإرسال ✅
</div>
<?php endif; ?>

<!-- إحصاءات -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
  <div class="push-stat">
    <div class="push-stat-num" style="color:var(--primary)"><?=$totalUsers?></div>
    <div class="push-stat-lbl">📱 عملاء مشتركون</div>
  </div>
  <div class="push-stat">
    <div class="push-stat-num" style="color:#00d4aa"><?=$totalSubs?></div>
    <div class="push-stat-lbl">🔔 أجهزة مسجّلة</div>
  </div>
  <div class="push-stat">
    <div class="push-stat-num" style="color:#f5a623"><?=count($logs)?></div>
    <div class="push-stat-lbl">📤 إشعارات مُرسَلة</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:16px">

<!-- فورم الإرسال -->
<div>
  <div class="card mb-2">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-paper-plane"></i> إرسال إشعار</div></div>
    <div class="card-body">
      <div class="form-group">
        <label>الهدف</label>
        <select id="pushTarget" class="form-control" onchange="toggleUserSelect()">
          <option value="all">📢 جميع المشتركين (<?=$totalUsers?>)</option>
          <option value="user">👤 مستخدم محدد</option>
        </select>
      </div>
      <div id="userSelectWrap" class="form-group" style="display:none">
        <label>اختر المستخدم</label>
        <select id="pushUserId" class="form-control">
          <option value="">— اختر —</option>
          <?php foreach($subscribers as $s): ?>
          <option value="<?=$s['user_id']?>"><?=htmlspecialchars($s['full_name']?:$s['username'])?> (<?=$s['devices']?> جهاز)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>العنوان *</label>
        <input type="text" id="pushTitle" class="form-control" placeholder="مثال: 🎉 عرض خاص لك!">
      </div>
      <div class="form-group">
        <label>نص الإشعار *</label>
        <textarea id="pushBody" class="form-control" rows="3" placeholder="مثال: خصم 20% على جميع الخدمات اليوم فقط..."></textarea>
      </div>
      <div class="form-group">
        <label>الرابط عند الضغط</label>
        <input type="text" id="pushUrl" class="form-control" value="/mobile.php" placeholder="/mobile.php">
      </div>

      <!-- معاينة -->
      <div id="pushPreview" style="background:rgba(0,0,0,.2);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;display:none">
        <div style="font-size:.72rem;color:var(--text3);margin-bottom:6px">معاينة الإشعار:</div>
        <div style="display:flex;gap:10px;align-items:flex-start">
          <img src="<?=SITE_URL?>/icons/icon-96.png" style="width:40px;height:40px;border-radius:8px">
          <div>
            <div id="prevTitle" style="font-weight:800;font-size:.85rem"></div>
            <div id="prevBody" style="font-size:.78rem;color:var(--text2);margin-top:2px"></div>
          </div>
        </div>
      </div>

      <button onclick="sendPush()" id="sendBtn" class="btn btn-primary" style="width:100%">
        <i class="fas fa-paper-plane"></i> إرسال الإشعار
      </button>
      <div id="sendResult" style="margin-top:10px;text-align:center;font-size:.85rem"></div>
    </div>
  </div>

  <!-- القوالب السريعة -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-bolt"></i> قوالب سريعة</div></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
      <?php foreach([
        ['🎉 عرض خاص!',   'خصم حصري على جميع الخدمات — لفترة محدودة!', '/mobile.php'],
        ['✅ طلبك اكتمل', 'تم تنفيذ طلبك بنجاح. شكراً لثقتك بنا!', '/orders.php'],
        ['💰 شحّن رصيدك', 'رصيدك قارب على النفاد — شحّنه الآن واستمر!', '/mobile.php?page=wallet'],
        ['🎫 كود هدية!',  'تم إضافة كود هدية لحسابك — استخدمه الآن', '/mobile.php?page=wallet'],
        ['🏆 مسابقة جديدة!','مسابقة جديدة بجوائز رائعة — شارك الآن!','/mobile.php'],
      ] as [$t,$b,$u]): ?>
      <button onclick="fillTemplate(<?=json_encode($t,JSON_UNESCAPED_UNICODE)?>,<?=json_encode($b,JSON_UNESCAPED_UNICODE)?>,<?=json_encode($u)?>)"
              class="btn btn-secondary" style="text-align:right">
        <?=$t?>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- يمين: السجل والمشتركون -->
<div>
  <!-- سجل الإشعارات -->
  <div class="card mb-2">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-history"></i> سجل الإرسال</div></div>
    <?php if(empty($logs)): ?>
    <div class="card-body" style="text-align:center;color:var(--text3)">لا توجد إشعارات مُرسَلة بعد</div>
    <?php else: ?>
    <div style="max-height:250px;overflow-y:auto">
    <?php foreach($logs as $log): ?>
    <div style="padding:10px 14px;border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <strong style="font-size:.85rem"><?=htmlspecialchars($log['title'])?></strong>
        <span style="font-size:10px;color:var(--text3)"><?=date('d/m H:i',strtotime($log['created_at']))?></span>
      </div>
      <div style="font-size:.75rem;color:var(--text3);margin-bottom:4px"><?=htmlspecialchars(mb_substr($log['body'],0,60))?></div>
      <div style="font-size:.7rem;display:flex;gap:10px">
        <span style="color:#00e676"><i class="fas fa-check"></i> <?=$log['sent_count']?> وصل</span>
        <?php if($log['failed_count']): ?><span style="color:#ff4455"><i class="fas fa-times"></i> <?=$log['failed_count']?> فشل</span><?php endif; ?>
        <span style="color:var(--text3)"><?=$log['target']==='all'?'الجميع':'مستخدم #'.$log['target_user_id']?></span>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- المشتركون -->
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-users"></i> المشتركون (<?=count($subscribers)?>)</div></div>
    <div class="table-wrap" style="max-height:300px;overflow-y:auto">
      <table>
        <thead><tr><th>المستخدم</th><th>الأجهزة</th><th>آخر نشاط</th><th></th></tr></thead>
        <tbody>
        <?php foreach($subscribers as $s): ?>
        <tr>
          <td><strong style="font-size:.85rem"><?=htmlspecialchars($s['full_name']?:$s['username'])?></strong></td>
          <td><span class="badge badge-primary"><?=$s['devices']?></span></td>
          <td style="font-size:11px;color:var(--text3)"><?=$s['last_used']?date('d/m H:i',strtotime($s['last_used'])):'—'?></td>
          <td>
            <button onclick="fillTemplate('','',' ',<?=$s['user_id']?>)" class="btn btn-sm btn-secondary" title="إرسال لهذا المستخدم">
              <i class="fas fa-paper-plane"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>

<script>
function toggleUserSelect() {
  document.getElementById('userSelectWrap').style.display =
    document.getElementById('pushTarget').value === 'user' ? 'block' : 'none';
}

function fillTemplate(title, body, url, userId) {
  if (title) document.getElementById('pushTitle').value = title;
  if (body)  document.getElementById('pushBody').value  = body;
  if (url)   document.getElementById('pushUrl').value   = url;
  if (userId) {
    document.getElementById('pushTarget').value = 'user';
    document.getElementById('pushUserId').value = userId;
    toggleUserSelect();
  }
  updatePreview();
}

function updatePreview() {
  const t = document.getElementById('pushTitle').value;
  const b = document.getElementById('pushBody').value;
  const prev = document.getElementById('pushPreview');
  if (t || b) {
    prev.style.display = 'block';
    document.getElementById('prevTitle').textContent = t;
    document.getElementById('prevBody').textContent  = b;
  } else { prev.style.display = 'none'; }
}

document.getElementById('pushTitle').addEventListener('input', updatePreview);
document.getElementById('pushBody').addEventListener('input', updatePreview);

async function sendPush() {
  const title  = document.getElementById('pushTitle').value.trim();
  const body   = document.getElementById('pushBody').value.trim();
  const url    = document.getElementById('pushUrl').value.trim() || '/mobile.php';
  const target = document.getElementById('pushTarget').value;
  const userId = document.getElementById('pushUserId')?.value || '';
  const result = document.getElementById('sendResult');
  const btn    = document.getElementById('sendBtn');

  if (!title || !body) { result.style.color='#ff4455'; result.textContent='❌ العنوان والنص مطلوبان'; return; }
  if (target==='user' && !userId) { result.style.color='#ff4455'; result.textContent='❌ اختر مستخدماً'; return; }

  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الإرسال...';
  result.textContent = '';

  const fd = new FormData();
  fd.append('action','send'); fd.append('title',title); fd.append('body',body); fd.append('url',url);
  fd.append('target_user', target==='user' ? userId : '');

  try {
    const r = await fetch('<?=SITE_URL?>/api/push_send.php', {method:'POST',body:fd,credentials:'same-origin'});
    const d = await r.json();
    if (d.ok) {
      result.style.color='#00e676';
      result.textContent = `✅ أُرسل لـ ${d.sent} جهاز${d.failed?' (فشل '+d.failed+')':''}`;
    } else { result.style.color='#ff4455'; result.textContent='❌ '+(d.error||'خطأ'); }
  } catch(e) { result.style.color='#ff4455'; result.textContent='❌ خطأ في الاتصال'; }
  finally { btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> إرسال الإشعار'; }
}
</script>

<?php include 'footer.php'; ?>
