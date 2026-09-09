<?php
require_once '../includes/config.php';
requireAdmin($pdo);
$pageTitle = 'صندوق واتساب الوارد — ' . SITE_NAME;

$tab = $_GET['tab'] ?? 'messages';

// تحديد الفلتر
$filterGroup  = $_GET['group']  ?? '';
$filterPhone  = $_GET['phone']  ?? '';
$filterType   = $_GET['type']   ?? '';
$filterUnread = isset($_GET['unread']);

// تحديد كمقروء
if (isset($_GET['mark_read'])) {
    $pdo->prepare("UPDATE wa_incoming_messages SET is_read=1 WHERE from_number=?")
        ->execute([$_GET['mark_read']]);
    header('Location: ?tab=messages');
    exit;
}
if (isset($_GET['mark_all_read'])) {
    $pdo->exec("UPDATE wa_incoming_messages SET is_read=1");
    header('Location: ?tab=messages');
    exit;
}

// ── جلب الإحصاءات ─────────────────────────────────────────
try {
    $totalMsgs   = (int)$pdo->query("SELECT COUNT(*) FROM wa_incoming_messages")->fetchColumn();
    $unreadMsgs  = (int)$pdo->query("SELECT COUNT(*) FROM wa_incoming_messages WHERE is_read=0")->fetchColumn();
    $groupMsgs   = (int)$pdo->query("SELECT COUNT(*) FROM wa_incoming_messages WHERE is_group=1")->fetchColumn();
    $totalGroups = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_groups")->fetchColumn();
    $totalEvents = (int)$pdo->query("SELECT COUNT(*) FROM wa_group_events")->fetchColumn();
    $totalLogs   = (int)$pdo->query("SELECT COUNT(*) FROM wa_webhook_log")->fetchColumn();
} catch (Exception $e) {
    $totalMsgs=$unreadMsgs=$groupMsgs=$totalGroups=$totalEvents=$totalLogs=0;
}

// ── جلب الرسائل ────────────────────────────────────────────
$messages = [];
try {
    $where = ['1=1']; $params = [];
    if ($filterGroup)  { $where[] = 'group_id=?';    $params[] = $filterGroup; }
    if ($filterPhone)  { $where[] = 'from_number LIKE ?'; $params[] = "%$filterPhone%"; }
    if ($filterType)   { $where[] = 'message_type=?'; $params[] = $filterType; }
    if ($filterUnread) { $where[] = 'is_read=0'; }
    $sql = "SELECT * FROM wa_incoming_messages WHERE " . implode(' AND ', $where) . " ORDER BY received_at DESC LIMIT 100";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $messages = $st->fetchAll();
} catch (Exception $e) { $messages = []; }

// ── جلب أحداث المجموعات ───────────────────────────────────
$groupEvents = [];
try {
    $groupEvents = $pdo->query("SELECT * FROM wa_group_events ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// ── جلب المجموعات ─────────────────────────────────────────
$groups = [];
try {
    $groups = $pdo->query("SELECT * FROM whatsapp_groups ORDER BY group_name ASC")->fetchAll();
} catch (Exception $e) {}

// ── جلب سجل Webhook ─────────────────────────────────────
$webhookLogs = [];
try {
    $webhookLogs = $pdo->query("SELECT * FROM wa_webhook_log ORDER BY created_at DESC LIMIT 80")->fetchAll();
} catch (Exception $e) {}

// ── جلب حالة الأجهزة ─────────────────────────────────────
$deviceStatus = [];
try {
    $deviceStatus = $pdo->query("SELECT * FROM wa_device_status ORDER BY logged_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {}

// ── قائمة المجموعات للفلتر ────────────────────────────────
$groupsList = [];
try {
    $groupsList = $pdo->query("SELECT DISTINCT group_id, group_name FROM wa_incoming_messages WHERE is_group=1 AND group_id IS NOT NULL ORDER BY group_name")->fetchAll();
} catch (Exception $e) {}

include 'header.php';
?>
<style>
.inbox-wrap{max-width:1200px;margin:0 auto;padding:20px 0}
.inbox-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap}
.inbox-tab{padding:9px 18px;border-radius:10px;font-size:.82rem;font-weight:700;background:var(--card);border:1px solid var(--border);color:var(--text2);text-decoration:none;display:flex;align-items:center;gap:7px;transition:all .2s}
.inbox-tab:hover,.inbox-tab.active{background:#25d366;border-color:#25d366;color:#fff}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat-box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center}
.stat-num{font-size:1.6rem;font-weight:900;line-height:1;margin-bottom:4px}
.stat-lbl{font-size:.72rem;color:var(--text3)}
.msg-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px;transition:border .15s}
.msg-card:hover{border-color:#25d36650}
.msg-card.unread{border-right:3px solid #25d366}
.msg-card.group-msg{border-right:3px solid #a78bfa}
.msg-header{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.msg-avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.msg-meta{flex:1}
.msg-name{font-weight:800;font-size:.9rem}
.msg-num{font-size:.75rem;color:var(--text3);direction:ltr}
.msg-body{font-size:.85rem;color:var(--text2);line-height:1.6;background:var(--bg);border-radius:8px;padding:10px}
.msg-footer{display:flex;align-items:center;justify-content:space-between;margin-top:8px;font-size:.75rem;color:var(--text3)}
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filter-input{padding:7px 12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--text1);font-size:.82rem}
.filter-input:focus{outline:none;border-color:#25d366}
.wa-table{width:100%;border-collapse:collapse;font-size:.82rem}
.wa-table th{padding:10px 12px;background:var(--bg);color:var(--text3);font-weight:700;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.wa-table td{padding:10px 12px;border-bottom:1px solid var(--border);color:var(--text1);vertical-align:middle}
.wa-table tr:hover td{background:rgba(37,211,102,.04)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.71rem;font-weight:700}
.badge-green{background:#25d36620;color:#25d366}
.badge-red{background:#ff445520;color:#ff4455}
.badge-blue{background:#3b82f620;color:#3b82f6}
.badge-gold{background:#f5a62320;color:#f5a623}
.badge-purple{background:#a78bfa20;color:#a78bfa}
.badge-gray{background:var(--border);color:var(--text3)}
.empty{text-align:center;padding:50px;color:var(--text3)}
.empty i{font-size:3rem;display:block;margin-bottom:10px;opacity:.3}
.inbox-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:16px}
.media-preview{max-width:200px;max-height:150px;border-radius:8px;margin-top:8px;object-fit:cover}
.log-row-ok{background:rgba(37,211,102,.04)}
.log-row-err{background:rgba(255,68,85,.04)}
.event-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
</style>

<div class="inbox-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="font-size:1.35rem;font-weight:900;display:flex;align-items:center;gap:10px;margin:0">
      <span style="width:38px;height:38px;background:#25d36620;border-radius:11px;display:flex;align-items:center;justify-content:center">
        <i class="fab fa-whatsapp" style="color:#25d366"></i>
      </span>
      صندوق واتساب الوارد
    </h2>
    <p style="color:var(--text3);font-size:.79rem;margin-top:3px">جميع الرسائل والمجموعات والأحداث الواردة</p>
  </div>
  <div style="display:flex;gap:8px">
    <a href="?tab=messages&mark_all_read=1" class="inbox-tab" style="font-size:.78rem">
      <i class="fas fa-check-double"></i> تحديد الكل مقروء
    </a>
    <a href="whatsapp.php" class="inbox-tab">
      <i class="fas fa-paper-plane"></i> إرسال رسائل
    </a>
  </div>
</div>

<!-- Stats -->
<div class="stats-row">
  <div class="stat-box">
    <div class="stat-num" style="color:#25d366"><?= number_format($totalMsgs) ?></div>
    <div class="stat-lbl">إجمالي الرسائل</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#ff4455"><?= number_format($unreadMsgs) ?></div>
    <div class="stat-lbl">غير مقروءة</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#a78bfa"><?= number_format($groupMsgs) ?></div>
    <div class="stat-lbl">رسائل المجموعات</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#3b82f6"><?= number_format($totalGroups) ?></div>
    <div class="stat-lbl">مجموعات مكتشفة</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:#f5a623"><?= number_format($totalEvents) ?></div>
    <div class="stat-lbl">أحداث المجموعات</div>
  </div>
  <div class="stat-box">
    <div class="stat-num" style="color:var(--text2)"><?= number_format($totalLogs) ?></div>
    <div class="stat-lbl">سجلات Webhook</div>
  </div>
</div>

<!-- Tabs -->
<div class="inbox-tabs">
  <a href="?tab=messages" class="inbox-tab <?= $tab==='messages'?'active':'' ?>">
    <i class="fas fa-inbox"></i> الرسائل
    <?php if($unreadMsgs>0): ?><span style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 7px;font-size:.7rem"><?= $unreadMsgs ?></span><?php endif; ?>
  </a>
  <a href="?tab=groups" class="inbox-tab <?= $tab==='groups'?'active':'' ?>">
    <i class="fas fa-users"></i> المجموعات المكتشفة
  </a>
  <a href="?tab=events" class="inbox-tab <?= $tab==='events'?'active':'' ?>">
    <i class="fas fa-bolt"></i> أحداث المجموعات
  </a>
  <a href="?tab=device" class="inbox-tab <?= $tab==='device'?'active':'' ?>">
    <i class="fas fa-mobile-alt"></i> حالة الجهاز
  </a>
  <a href="?tab=logs" class="inbox-tab <?= $tab==='logs'?'active':'' ?>">
    <i class="fas fa-code"></i> سجل Webhook الخام
  </a>
</div>

<?php /* ═══ MESSAGES ═══ */ if($tab==='messages'): ?>

<!-- فلاتر -->
<form method="GET">
  <input type="hidden" name="tab" value="messages">
  <div class="filter-bar">
    <i class="fas fa-filter" style="color:var(--text3)"></i>
    <input type="text" name="phone" class="filter-input" placeholder="🔍 بحث برقم..." value="<?= htmlspecialchars($filterPhone) ?>">
    <select name="group" class="filter-input">
      <option value="">— كل الرسائل —</option>
      <?php foreach($groupsList as $gl): ?>
      <option value="<?= htmlspecialchars($gl['group_id']) ?>" <?= $filterGroup===$gl['group_id']?'selected':'' ?>>
        <?= htmlspecialchars($gl['group_name']?:$gl['group_id']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="filter-input">
      <option value="">— كل الأنواع —</option>
      <?php foreach(['text','image','video','audio','document','location','contact'] as $t): ?>
      <option value="<?= $t ?>" <?= $filterType===$t?'selected':'' ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
    <label style="display:flex;align-items:center;gap:5px;font-size:.82rem;cursor:pointer">
      <input type="checkbox" name="unread" <?= $filterUnread?'checked':'' ?>> غير مقروء فقط
    </label>
    <button type="submit" class="inbox-tab" style="padding:7px 14px;font-size:.8rem;border:none;cursor:pointer">
      <i class="fas fa-search"></i> تطبيق
    </button>
    <a href="?tab=messages" class="inbox-tab" style="padding:7px 14px;font-size:.8rem">مسح</a>
  </div>
</form>

<?php if(empty($messages)): ?>
<div class="inbox-card"><div class="empty"><i class="fas fa-inbox"></i>لا توجد رسائل واردة</div></div>
<?php else: ?>
<?php foreach($messages as $m):
  $isGrp = $m['is_group'];
  $typeIcons = ['text'=>'💬','image'=>'🖼️','video'=>'🎥','audio'=>'🎵','document'=>'📄','location'=>'📍','contact'=>'👤','sticker'=>'🎭','poll'=>'📊','unknown'=>'❓'];
  $typeIcon = $typeIcons[$m['message_type']] ?? '❓';
  $avatarBg = $isGrp ? '#a78bfa20' : '#25d36620';
  $avatarColor = $isGrp ? '#a78bfa' : '#25d366';
?>
<div class="msg-card <?= !$m['is_read']?'unread':'' ?> <?= $isGrp?'group-msg':'' ?>">
  <div class="msg-header">
    <div class="msg-avatar" style="background:<?= $avatarBg ?>;color:<?= $avatarColor ?>">
      <?= $isGrp ? '👥' : '👤' ?>
    </div>
    <div class="msg-meta">
      <div class="msg-name">
        <?= htmlspecialchars($m['from_name'] ?: ($isGrp ? 'مجموعة' : 'مجهول')) ?>
        <?php if(!$m['is_read']): ?><span style="width:7px;height:7px;background:#25d366;border-radius:50%;display:inline-block;margin-right:4px"></span><?php endif; ?>
      </div>
      <div class="msg-num"><?= htmlspecialchars($m['from_number']) ?></div>
    </div>
    <div style="text-align:left;flex-shrink:0">
      <div style="font-size:.75rem;color:var(--text3)"><?= date('Y/m/d H:i', strtotime($m['received_at'])) ?></div>
      <div style="display:flex;gap:4px;margin-top:4px;justify-content:flex-end">
        <span class="badge <?= $isGrp?'badge-purple':'badge-green' ?>"><?= $isGrp?'مجموعة':'خاص' ?></span>
        <span class="badge badge-blue"><?= $typeIcon ?> <?= $m['message_type'] ?></span>
        <?php if(!$m['is_read']): ?><span class="badge badge-gold">جديد</span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if($isGrp && $m['group_name']): ?>
  <div style="font-size:.76rem;color:#a78bfa;margin-bottom:6px;display:flex;align-items:center;gap:5px">
    <i class="fas fa-users" style="font-size:.65rem"></i>
    <?= htmlspecialchars($m['group_name']) ?>
    <span style="color:var(--text3);font-size:.7rem"><?= htmlspecialchars($m['group_id']) ?></span>
  </div>
  <?php endif; ?>

  <?php if($m['message']): ?>
  <div class="msg-body"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
  <?php endif; ?>

  <?php if($m['caption'] && $m['caption'] !== $m['message']): ?>
  <div class="msg-body" style="margin-top:6px;border-right:2px solid #25d366">
    <span style="font-size:.72rem;color:var(--text3)">التعليق: </span><?= htmlspecialchars($m['caption']) ?>
  </div>
  <?php endif; ?>

  <?php if($m['media_url'] && str_starts_with($m['message_type'], 'image')): ?>
  <img src="<?= $m['media_url'] ?>" class="media-preview" alt="صورة">
  <?php elseif($m['media_filename']): ?>
  <div style="display:flex;align-items:center;gap:8px;margin-top:8px;background:var(--bg);border-radius:8px;padding:8px 12px;font-size:.8rem">
    <i class="fas fa-file" style="color:#3b82f6"></i>
    <?= htmlspecialchars($m['media_filename']) ?>
    <?php if($m['media_mime']): ?><span style="color:var(--text3)">(<?= $m['media_mime'] ?>)</span><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="msg-footer">
    <span><?= htmlspecialchars($m['from_number']) ?></span>
    <div style="display:flex;gap:8px">
      <?php if(!$m['is_read']): ?>
      <a href="?tab=messages&mark_read=<?= urlencode($m['from_number']) ?>" style="color:#25d366;font-size:.75rem;text-decoration:none">
        <i class="fas fa-check"></i> تحديد مقروء
      </a>
      <?php endif; ?>
      <a href="whatsapp.php?tab=single&phone=<?= urlencode($m['from_number']) ?>" style="color:#3b82f6;font-size:.75rem;text-decoration:none">
        <i class="fas fa-reply"></i> رد
      </a>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php /* ═══ GROUPS ═══ */ elseif($tab==='groups'): ?>

<div class="inbox-card">
  <div style="font-weight:800;font-size:1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-users" style="color:#a78bfa"></i> المجموعات المكتشفة تلقائياً
  </div>
  <?php if(empty($groups)): ?>
  <div class="empty"><i class="fas fa-users"></i>لم تُكتشف مجموعات بعد. في انتظار رسائل من المجموعات...</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="wa-table">
      <thead>
        <tr>
          <th>اسم المجموعة</th>
          <th>ID المجموعة</th>
          <th>المشتركون</th>
          <th>الحالة</th>
          <th>آخر مزامنة</th>
          <th>إجراء</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($groups as $g): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-size:1.1rem">👥</span>
              <span style="font-weight:700"><?= htmlspecialchars($g['group_name']) ?></span>
            </div>
          </td>
          <td>
            <code style="font-size:.75rem;background:var(--bg);padding:2px 8px;border-radius:6px;direction:ltr;display:inline-block">
              <?= htmlspecialchars($g['group_id']) ?>
            </code>
          </td>
          <td>
            <?php if($g['participants_count']>0): ?>
            <span class="badge badge-blue"><i class="fas fa-user" style="font-size:.6rem"></i> <?= $g['participants_count'] ?></span>
            <?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $g['is_active']?'badge-green':'badge-gray' ?>">
              <?= $g['is_active']?'نشطة':'معطلة' ?>
            </span>
          </td>
          <td style="color:var(--text3);font-size:.77rem"><?= date('Y/m/d H:i',strtotime($g['last_synced'])) ?></td>
          <td>
            <div style="display:flex;gap:6px">
              <a href="whatsapp.php?tab=single&phone=<?= urlencode($g['group_id']) ?>"
                 style="color:#25d366;font-size:.78rem;text-decoration:none;display:flex;align-items:center;gap:4px">
                <i class="fas fa-paper-plane"></i> إرسال
              </a>
              <button onclick="copyText('<?= htmlspecialchars($g['group_id']) ?>')"
                style="background:none;border:none;color:#3b82f6;cursor:pointer;font-size:.78rem">
                <i class="fas fa-copy"></i> نسخ ID
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ═══ EVENTS ═══ */ elseif($tab==='events'): ?>

<div class="inbox-card">
  <div style="font-weight:800;font-size:1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-bolt" style="color:#f5a623"></i> أحداث المجموعات
  </div>
  <?php if(empty($groupEvents)): ?>
  <div class="empty"><i class="fas fa-bolt"></i>لا توجد أحداث مجموعات بعد</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="wa-table">
      <thead>
        <tr><th>الحدث</th><th>المجموعة</th><th>المُنفّذ</th><th>الهدف</th><th>الوصف</th><th>التاريخ</th></tr>
      </thead>
      <tbody>
        <?php
        $evIcons=['create'=>'🆕','join'=>'➕','leave'=>'➖','update'=>'✏️','delete'=>'🗑️'];
        $evColors=['create'=>'badge-green','join'=>'badge-blue','leave'=>'badge-gold','update'=>'badge-purple','delete'=>'badge-red'];
        foreach($groupEvents as $ev):
        $ic=$evIcons[$ev['event_type']]??'📌';
        $bc=$evColors[$ev['event_type']]??'badge-gray';
        ?>
        <tr>
          <td><span class="badge <?= $bc ?>"><?= $ic ?> <?= $ev['event_type'] ?></span></td>
          <td>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($ev['group_name']?:'—') ?></div>
            <div style="font-size:.72rem;color:var(--text3);direction:ltr"><?= htmlspecialchars(mb_substr($ev['group_id'],0,30)) ?></div>
          </td>
          <td style="font-size:.8rem;direction:ltr"><?= htmlspecialchars($ev['actor']?:'—') ?></td>
          <td style="font-size:.8rem;direction:ltr"><?= htmlspecialchars($ev['target']?:'—') ?></td>
          <td style="font-size:.8rem;color:var(--text2)"><?= htmlspecialchars($ev['description']?:'') ?></td>
          <td style="color:var(--text3);font-size:.77rem;white-space:nowrap"><?= date('Y/m/d H:i',strtotime($ev['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ═══ DEVICE ═══ */ elseif($tab==='device'): ?>

<div class="inbox-card">
  <div style="font-weight:800;font-size:1rem;margin-bottom:16px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-mobile-alt" style="color:#3b82f6"></i> سجل حالة الجهاز
  </div>
  <?php if(empty($deviceStatus)): ?>
  <div class="empty"><i class="fas fa-mobile-alt"></i>لم تصل أي بيانات عن حالة الجهاز</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="wa-table">
      <thead><tr><th>الجهاز</th><th>الحالة</th><th>التفاصيل</th><th>التوقيت</th></tr></thead>
      <tbody>
        <?php
        $stColors=['connected'=>'badge-green','disconnected'=>'badge-red','qr_ready'=>'badge-gold','error'=>'badge-red'];
        $stIcons=['connected'=>'🟢','disconnected'=>'🔴','qr_ready'=>'🟡','error'=>'❌'];
        foreach($deviceStatus as $ds):
        $sc=$stColors[$ds['status']]??'badge-gray';
        $si=$stIcons[$ds['status']]??'⚪';
        $detail=json_decode($ds['detail'],true);
        ?>
        <tr>
          <td style="font-weight:600;direction:ltr"><?= htmlspecialchars($ds['device']) ?></td>
          <td><span class="badge <?= $sc ?>"><?= $si ?> <?= $ds['status'] ?></span></td>
          <td style="font-size:.78rem;color:var(--text3);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars(is_array($detail)?($detail['message']??json_encode($detail)):($ds['detail']?:'—')) ?>
          </td>
          <td style="color:var(--text3);font-size:.77rem;white-space:nowrap"><?= date('Y/m/d H:i',strtotime($ds['logged_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ═══ LOGS ═══ */ elseif($tab==='logs'): ?>

<div class="inbox-card">
  <div style="font-weight:800;font-size:1rem;margin-bottom:6px;display:flex;align-items:center;gap:8px">
    <i class="fas fa-code" style="color:var(--text3)"></i> سجل Webhook الخام (آخر 80)
  </div>
  <div style="font-size:.78rem;color:var(--text3);margin-bottom:16px">
    كل طلب يصل إلى <code style="background:var(--bg);padding:2px 7px;border-radius:5px">/register_webhook.php</code> يُسجَّل هنا للتشخيص.
  </div>
  <?php if(empty($webhookLogs)): ?>
  <div class="empty"><i class="fas fa-code"></i>لم تصل أي بيانات Webhook بعد<br><small style="font-size:.8rem">تأكد من ضبط رابط الـ Webhook في لوحة HetaCloud</small></div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table class="wa-table">
      <thead>
        <tr><th>#</th><th>الحدث</th><th>IP</th><th>المعالجة</th><th>خطأ</th><th>الحجم</th><th>التوقيت</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach($webhookLogs as $wl): ?>
        <tr class="<?= $wl['processed']?'log-row-ok':'' ?> <?= $wl['error']?'log-row-err':'' ?>">
          <td style="color:var(--text3)"><?= $wl['id'] ?></td>
          <td><span class="badge badge-blue"><?= htmlspecialchars($wl['event']) ?></span></td>
          <td style="font-size:.75rem;direction:ltr;color:var(--text3)"><?= htmlspecialchars($wl['ip']) ?></td>
          <td>
            <?php if($wl['processed']): ?>
              <span class="badge badge-green">✓ معالج</span>
            <?php else: ?>
              <span class="badge badge-gray">غير معالج</span>
            <?php endif; ?>
          </td>
          <td style="color:#ff4455;font-size:.74rem;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars(mb_substr($wl['error']??'',0,50)) ?>
          </td>
          <td style="color:var(--text3);font-size:.75rem"><?= number_format(strlen($wl['payload']??'')) ?> B</td>
          <td style="color:var(--text3);font-size:.77rem;white-space:nowrap"><?= date('Y/m/d H:i:s',strtotime($wl['created_at'])) ?></td>
          <td>
            <button onclick="showPayload(<?= $wl['id'] ?>,this.dataset.p)" data-p="<?= htmlspecialchars($wl['payload']??'') ?>"
              style="background:none;border:none;color:#3b82f6;cursor:pointer;font-size:.78rem">
              <i class="fas fa-eye"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Payload Modal -->
<div id="payloadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:10000;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#0d1117;border:1px solid #25d36640;border-radius:16px;padding:24px;width:90vw;max-width:700px;max-height:80vh;overflow:auto;direction:ltr">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <span style="font-weight:700;color:#25d366">Webhook Payload</span>
      <button onclick="document.getElementById('payloadModal').style.display='none'" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:1.1rem">✕</button>
    </div>
    <pre id="payloadContent" style="font-size:.78rem;color:#e2e8f0;white-space:pre-wrap;word-break:break-all;margin:0;font-family:monospace"></pre>
  </div>
</div>

<?php endif; ?>
</div>

<script>
function copyText(t){navigator.clipboard.writeText(t);const el=document.createElement('div');el.style.cssText='position:fixed;bottom:24px;left:24px;background:#25d366;color:#fff;padding:10px 18px;border-radius:10px;font-size:.85rem;font-weight:700;z-index:99999';el.textContent='✅ تم نسخ: '+t;document.body.appendChild(el);setTimeout(()=>el.remove(),2500);}

function showPayload(id,raw){
  const modal=document.getElementById('payloadModal');
  const pre=document.getElementById('payloadContent');
  try{pre.textContent=JSON.stringify(JSON.parse(raw),null,2);}
  catch(e){pre.textContent=raw;}
  modal.style.display='flex';
}
// تحديث تلقائي كل 30 ثانية للرسائل
<?php if($tab==='messages'): ?>
setTimeout(()=>location.reload(), 30000);
<?php endif; ?>
</script>
<?php include 'footer.php'; ?>
