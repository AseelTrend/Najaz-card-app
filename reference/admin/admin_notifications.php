<?php
require_once '../includes/config.php';
require_once '../includes/notifications.php';
requireAdmin();

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); } // الإدارة فقط
$pageTitle = 'إدارة الإشعارات - ' . SITE_NAME;

$tab    = $_GET['tab'] ?? 'send';
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);

// ─── حذف إشعار منبثق ─────────────────────────────────────────────────────────
if ($action === 'delete_broadcast' && $id) {
    $pdo->prepare("DELETE FROM notification_broadcasts WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم حذف الإشعار المنبثق');
    redirect(SITE_URL . '/admin/admin_notifications.php?tab=popups');
}

// ─── تبديل تفعيل إشعار منبثق ─────────────────────────────────────────────────
if ($action === 'toggle_broadcast' && $id) {
    $pdo->prepare("UPDATE notification_broadcasts SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$id]);
    flashMessage('success', 'تم تحديث حالة الإشعار');
    redirect(SITE_URL . '/admin/admin_notifications.php?tab=popups');
}

// ─── إرسال إشعار مباشر (Bell) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_direct'])) {
    $target    = $_POST['target'] ?? 'all';
    $title     = trim($_POST['title'] ?? '');
    $message   = trim($_POST['message'] ?? '');
    $icon      = $_POST['icon'] ?? 'bell';
    $color     = $_POST['color'] ?? '#6c3fe0';
    $actionType = $_POST['action_type'] ?? 'none';
    $actionUrl  = trim($_POST['action_url'] ?? '');
    $specificId = (int)($_POST['specific_user_id'] ?? 0);

    if ($title && $message) {
        // بناء الرابط بناءً على النوع
        $finalUrl = null;
        if ($actionType === 'url') $finalUrl = $actionUrl;
        elseif ($actionType === 'service' && $_POST['ref_service_id']) $finalUrl = SITE_URL . '/services.php?service=' . (int)$_POST['ref_service_id'];
        elseif ($actionType === 'category' && $_POST['ref_cat_id']) $finalUrl = SITE_URL . '/services.php?cat=' . (int)$_POST['ref_cat_id'];
        elseif ($actionType === 'page') $finalUrl = SITE_URL . '/' . ltrim($actionUrl, '/');

        if ($target === 'specific' && $specificId) {
            // إرسال لعميل واحد
            sendNotificationWithAction($pdo, $specificId, 'admin', $title, $message, $icon, $color, $actionType, $finalUrl);
            $sent = 1;
        } else {
            // إرسال لكل العملاء
            $users = $pdo->query("SELECT id FROM users WHERE role='customer' AND status=1")->fetchAll();
            $sent  = 0;
            foreach ($users as $u) {
                sendNotificationWithAction($pdo, $u['id'], 'admin', $title, $message, $icon, $color, $actionType, $finalUrl);
                $sent++;
            }
        }

        // تليغرام أيضاً للعميل المحدد
        if ($target === 'specific' && $specificId) {
            $u = $pdo->prepare("SELECT telegram_chat_id, whatsapp_number FROM users WHERE id=?");
            $u->execute([$specificId]); $u = $u->fetch();
            if ($u && !empty($u['telegram_chat_id'])) {
                sendTelegram($pdo, $u['telegram_chat_id'], "📢 " . getSetting('site_name') . "\n{$title}\n{$message}" . ($finalUrl ? "\n🔗 {$finalUrl}" : ''));
            }
        }

        logStaffAction($pdo, 'send_notification', 'notification', null, "إرسال إشعار مباشر لـ {$sent} مستخدم: {$title}");
        flashMessage('success', "✅ تم إرسال الإشعار لـ {$sent} " . ($sent === 1 ? 'عميل' : 'عملاء'));
        redirect(SITE_URL . '/admin/admin_notifications.php?tab=send');
    } else {
        flashMessage('danger', 'العنوان والرسالة مطلوبان');
    }
}

// ─── حفظ إشعار منبثق ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_popup'])) {
    $title      = trim($_POST['title'] ?? '');
    $message    = trim($_POST['message'] ?? '');
    $icon       = $_POST['icon'] ?? 'bell';
    $color      = $_POST['color'] ?? '#6c3fe0';
    $btnLabel   = trim($_POST['btn_label'] ?? 'موافق');
    $actionType = $_POST['action_type'] ?? 'none';
    $actionUrl  = trim($_POST['action_url'] ?? '');
    $refId      = (int)($_POST['ref_id'] ?? 0);
    $target     = $_POST['target'] ?? 'all';
    $showOnce   = isset($_POST['show_once']) ? 1 : 0;
    $endAt      = !empty($_POST['end_at']) ? $_POST['end_at'] : null;
    $specificIds = [];
    if ($target === 'specific' && !empty($_POST['specific_user_ids'])) {
        foreach (explode(',', $_POST['specific_user_ids']) as $uid) {
            $uid = (int)trim($uid); if ($uid > 0) $specificIds[] = $uid;
        }
    }

    // بناء الرابط النهائي
    $finalUrl = null;
    if ($actionType === 'url') $finalUrl = $actionUrl;
    elseif ($actionType === 'service' && $refId) $finalUrl = SITE_URL . '/services.php?service=' . $refId;
    elseif ($actionType === 'category' && $refId) $finalUrl = SITE_URL . '/services.php?cat=' . $refId;
    elseif ($actionType === 'page') $finalUrl = SITE_URL . '/' . ltrim($actionUrl, '/');
    elseif ($actionType === 'none') $finalUrl = null;

    if ($title && $message) {
        $existId = (int)($_POST['edit_id'] ?? 0);
        if ($existId) {
            $pdo->prepare("UPDATE notification_broadcasts SET title=?,message=?,icon=?,color=?,btn_label=?,action_type=?,action_url=?,action_ref_id=?,target=?,target_user_ids=?,show_once=?,end_at=? WHERE id=?")
                ->execute([$title,$message,$icon,$color,$btnLabel,$actionType,$finalUrl,$refId?:null,$target,$specificIds?json_encode($specificIds):null,$showOnce,$endAt,$existId]);
        } else {
            $pdo->prepare("INSERT INTO notification_broadcasts (title,message,icon,color,btn_label,action_type,action_url,action_ref_id,target,target_user_ids,show_once,end_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$title,$message,$icon,$color,$btnLabel,$actionType,$finalUrl,$refId?:null,$target,$specificIds?json_encode($specificIds):null,$showOnce,$endAt,$_SESSION['user_id']]);
            // مسح سجل المشاهدات القديمة عند إعادة الإنشاء
            $newId = $pdo->lastInsertId();
        }
        logStaffAction($pdo, 'save_popup_notification', 'notification', null, "حفظ إشعار منبثق: {$title}");
        flashMessage('success', '✅ تم حفظ الإشعار المنبثق بنجاح');
        redirect(SITE_URL . '/admin/admin_notifications.php?tab=popups');
    } else {
        flashMessage('danger', 'العنوان والرسالة مطلوبان');
    }
}

// ─── جلب البيانات ─────────────────────────────────────────────────────────────
$customers = $pdo->query("SELECT id, username, full_name FROM users WHERE role='customer' AND status=1 ORDER BY username")->fetchAll();
$services  = $pdo->query("SELECT id, name FROM services WHERE status=1 ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort_order")->fetchAll();
$popups    = $pdo->query("SELECT nb.*, u.username as creator FROM notification_broadcasts nb LEFT JOIN users u ON nb.created_by=u.id ORDER BY nb.created_at DESC")->fetchAll();
$editPopup = null;
if ($action === 'edit_popup' && $id) {
    $ep = $pdo->prepare("SELECT * FROM notification_broadcasts WHERE id=?"); $ep->execute([$id]); $editPopup = $ep->fetch();
}

// إحصاءات
$totalSent   = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='admin'")->fetchColumn();
$totalPopups = count($popups);
$activePopups = count(array_filter($popups, fn($p) => $p['is_active']));

include 'header.php';
?>
<style>
.notif-tabs { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
.notif-tab-btn {
    padding: 8px 20px; border-radius: 10px; border: 1px solid var(--border);
    background: var(--card); color: var(--text2); cursor: pointer;
    font-size: .85rem; font-weight: 600; transition: all .2s; text-decoration: none;
    display: inline-flex; align-items: center; gap: 7px;
}
.notif-tab-btn.active, .notif-tab-btn:hover {
    background: rgba(108,63,224,0.15); border-color: rgba(108,63,224,0.4); color: #a78bfa;
}
.icon-grid {
    display: grid; grid-template-columns: repeat(8,1fr); gap: 6px; margin-top: 8px;
}

/* ══ استجابة للجوال ══ */
@media (max-width: 768px) {
    .an-2col { grid-template-columns: 1fr !important; }
    .icon-grid { grid-template-columns: repeat(5,1fr) !important; }
    .an-stats { grid-template-columns: 1fr 1fr !important; }
    .an-stats .stat-mini:last-child { grid-column: 1 / -1; }
    .preview-popup { padding: 20px 14px !important; }
    .popup-row { flex-wrap: wrap; }
    .popup-row > div:last-child { width: 100%; justify-content: flex-end; }
}
.icon-opt {
    width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center; cursor: pointer;
    font-size: .85rem; color: var(--text2); transition: all .15s;
}
.icon-opt:hover, .icon-opt.selected { background: rgba(108,63,224,0.2); border-color: #a78bfa; color: #a78bfa; }
.color-grid { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.color-swatch {
    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    border: 2px solid transparent; transition: border-color .15s; flex-shrink: 0;
}
.color-swatch.selected { border-color: #fff; box-shadow: 0 0 0 2px rgba(255,255,255,.3); }
.target-card {
    padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border);
    cursor: pointer; transition: all .2s;
}
.target-card:hover, .target-card.selected {
    border-color: rgba(108,63,224,0.5); background: rgba(108,63,224,0.08);
}
.preview-popup {
    background: linear-gradient(135deg, #111827, #1a2035);
    border: 1px solid var(--border2); border-radius: 16px;
    padding: 24px 20px; text-align: center; position: relative;
}
.preview-popup-icon {
    width: 56px; height: 56px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; margin: 0 auto 14px;
}
.preview-popup-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; }
.preview-popup-msg { font-size: .85rem; color: #9ca3af; margin-bottom: 16px; line-height: 1.6; }
.preview-popup-btn {
    display: inline-block; padding: 9px 28px; border-radius: 10px;
    font-size: .85rem; font-weight: 600; color: #fff; text-decoration: none;
    cursor: pointer; border: none;
}
.popup-row {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 14px 16px; display: flex; gap: 12px; align-items: center;
    transition: border-color .2s;
}
.popup-row:hover { border-color: rgba(108,63,224,0.3); }
.stat-mini {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 14px 18px; text-align: center;
}
.stat-mini-val { font-size: 1.6rem; font-weight: 800; margin-bottom: 4px; }
.stat-mini-lbl { font-size: .75rem; color: var(--text3); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(108,63,224,0.15)"><i class="fas fa-paper-plane" style="color:#a78bfa"></i></div>
      إدارة الإشعارات
    </div>
    <div class="page-header-sub">إرسال الإشعارات والرسائل المنبثقة للعملاء</div>
  </div>
</div>

<!-- إحصاءات مصغرة -->
<div class="an-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px">
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:#a78bfa"><?= number_format($totalSent) ?></div>
    <div class="stat-mini-lbl">إشعار أُرسل</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:#00e676"><?= $activePopups ?></div>
    <div class="stat-mini-lbl">منبثق نشط</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:#00d4ff"><?= count($customers) ?></div>
    <div class="stat-mini-lbl">عميل مسجل</div>
  </div>
</div>

<?php
// مصفوفة الأيقونات — مشتركة بين تبويب الإرسال المباشر وتبويب الإشعارات المنبثقة
$icons = ['bell','gift','star','fire','bolt','tag','percent','trophy','rocket','crown','diamond','gem','heart','thumbs-up','megaphone','bullhorn','info-circle','exclamation-circle','check-circle','tools'];
?>

<!-- التبويبات -->
<div class="notif-tabs">
  <a href="?tab=send" class="notif-tab-btn <?= $tab==='send'?'active':'' ?>"><i class="fas fa-paper-plane"></i> إرسال إشعار مباشر</a>
  <a href="?tab=popups" class="notif-tab-btn <?= $tab==='popups'?'active':'' ?>"><i class="fas fa-window-restore"></i> الإشعارات المنبثقة <span style="background:#ff4455;color:#fff;border-radius:6px;padding:1px 7px;font-size:.7rem"><?= $activePopups ?></span></a>
</div>

<!-- ════════════════════════════════════════════════════
     التبويب 1: إرسال إشعار مباشر (Bell)
════════════════════════════════════════════════════ -->
<?php if ($tab === 'send'): ?>
<div class="an-2col" style="display:grid;grid-template-columns:1.3fr 1fr;gap:18px">

  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-paper-plane" style="color:#a78bfa"></i> إرسال إشعار للمستخدمين</div>
    </div>
    <div class="card-body">
      <form method="POST" id="sendDirectForm">
        <?= adminCsrfField() ?>
        <input type="hidden" name="send_direct" value="1">

        <!-- الاستهداف -->
        <div class="form-group">
          <label style="font-weight:600;margin-bottom:8px;display:block">👥 الاستهداف</label>
          <div class="an-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <label class="target-card selected" id="targetAllCard" onclick="selectTarget('all')">
              <input type="radio" name="target" value="all" checked style="display:none">
              <div style="font-weight:600;font-size:.9rem;margin-bottom:4px"><i class="fas fa-users" style="color:#00d4ff"></i> كل العملاء</div>
              <div style="font-size:.75rem;color:var(--text3)"><?= count($customers) ?> عميل نشط</div>
            </label>
            <label class="target-card" id="targetOneCard" onclick="selectTarget('specific')">
              <input type="radio" name="target" value="specific" style="display:none">
              <div style="font-weight:600;font-size:.9rem;margin-bottom:4px"><i class="fas fa-user" style="color:#f5a623"></i> عميل محدد</div>
              <div style="font-size:.75rem;color:var(--text3)">اختر من القائمة</div>
            </label>
          </div>
        </div>

        <!-- اختيار عميل محدد -->
        <div id="specificUserWrap" style="display:none" class="form-group">
          <label><i class="fas fa-search"></i> ابحث عن العميل</label>
          <select name="specific_user_id" class="form-control" id="specificUserSelect">
            <option value="">-- اختر العميل --</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['username']) ?><?= $c['full_name'] ? ' — ' . htmlspecialchars($c['full_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- الأيقونة والألوان -->
        <div class="an-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label><i class="fas fa-icons"></i> الأيقونة</label>
            <input type="hidden" name="icon" id="selectedIcon" value="bell">
            <div class="icon-grid" id="iconGrid">
              <?php foreach ($icons as $ic): ?>
              <div class="icon-opt <?= $ic==='bell'?'selected':'' ?>" onclick="selectIcon('<?= $ic ?>')" title="<?= $ic ?>">
                <i class="fas fa-<?= $ic ?>"></i>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label><i class="fas fa-palette"></i> اللون</label>
            <input type="hidden" name="color" id="selectedColor" value="#6c3fe0">
            <div class="color-grid" id="colorGrid">
              <?php
              $colors = ['#6c3fe0','#f5a623','#00e676','#ff4455','#00d4ff','#ff6b35','#e91e8c','#00bcd4','#8bc34a','#9c27b0','#ff5722','#607d8b'];
              foreach ($colors as $clr):
              ?>
              <div class="color-swatch <?= $clr==='#6c3fe0'?'selected':'' ?>" style="background:<?= $clr ?>" onclick="selectColor('<?= $clr ?>')" title="<?= $clr ?>"></div>
              <?php endforeach; ?>
            </div>
            <input type="color" id="customColorPick" style="margin-top:8px;width:40px;height:28px;border:none;background:none;cursor:pointer;border-radius:6px" title="لون مخصص" onchange="selectColor(this.value)">
          </div>
        </div>

        <!-- العنوان والرسالة -->
        <div class="form-group">
          <label><i class="fas fa-heading"></i> العنوان <span style="color:#ff4455">*</span></label>
          <input type="text" name="title" class="form-control" placeholder="مثال: عرض خاص لك 🎁" maxlength="200" oninput="updatePreview()" id="directTitle">
        </div>
        <div class="form-group">
          <label><i class="fas fa-align-right"></i> الرسالة <span style="color:#ff4455">*</span></label>
          <textarea name="message" class="form-control" rows="3" placeholder="اكتب تفاصيل الإشعار هنا..." oninput="updatePreview()" id="directMsg" style="resize:vertical"></textarea>
        </div>

        <!-- الإجراء عند الضغط -->
        <div class="form-group">
          <label><i class="fas fa-mouse-pointer"></i> الإجراء عند الضغط على الإشعار</label>
          <select name="action_type" class="form-control" id="actionTypeSelect" onchange="toggleActionFields(this.value, 'direct')">
            <option value="none">لا يوجد توجيه</option>
            <option value="url">رابط خارجي</option>
            <option value="service">خدمة محددة</option>
            <option value="category">قسم محدد</option>
            <option value="page">صفحة داخلية</option>
          </select>
        </div>
        <div id="direct_url_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-link"></i> الرابط الخارجي</label>
          <input type="text" name="action_url" class="form-control" placeholder="https://..." id="directActionUrl">
        </div>
        <div id="direct_service_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-box"></i> اختر الخدمة</label>
          <select name="ref_service_id" class="form-control">
            <option value="">-- اختر --</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="direct_category_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-folder"></i> اختر القسم</label>
          <select name="ref_cat_id" class="form-control">
            <option value="">-- اختر --</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="direct_page_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-file"></i> مسار الصفحة</label>
          <input type="text" name="action_url" class="form-control" placeholder="wallet.php أو orders.php">
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px">
          <i class="fas fa-paper-plane"></i> إرسال الإشعار الآن
        </button>
      </form>
    </div>
  </div>

  <!-- معاينة مباشرة -->
  <div>
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-eye"></i> معاينة الإشعار</div>
      </div>
      <div class="card-body">
        <p style="font-size:.8rem;color:var(--text3);margin-bottom:12px">يظهر هكذا في قائمة الجرس 🔔</p>
        <div style="background:var(--bg);border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;border:1px solid var(--border)">
          <div id="previewIcon" style="width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;background:#6c3fe022;color:#6c3fe0">
            <i class="fas fa-bell"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div id="previewTitle" style="font-size:.83rem;font-weight:600;color:#e5e7eb;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">عنوان الإشعار</div>
            <div id="previewMsg" style="font-size:.75rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">نص الرسالة يظهر هنا</div>
            <div style="font-size:.68rem;color:#6b7280;margin-top:4px"><i class="fas fa-clock"></i> الآن</div>
          </div>
        </div>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
          <p style="font-size:.8rem;color:var(--text3);margin-bottom:10px">سيُرسَل إلى:</p>
          <div id="targetPreviewAll" style="display:flex;align-items:center;gap:8px;font-size:.85rem">
            <i class="fas fa-users" style="color:#00d4ff"></i>
            <span>جميع العملاء النشطين <strong style="color:#00d4ff">(<?= count($customers) ?>)</strong></span>
          </div>
          <div id="targetPreviewOne" style="display:none;align-items:center;gap:8px;font-size:.85rem">
            <i class="fas fa-user" style="color:#f5a623"></i>
            <span>عميل واحد محدد</span>
          </div>
        </div>
      </div>
    </div>

    <!-- نصائح -->
    <div class="card" style="margin-top:12px">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-lightbulb" style="color:#f5a623"></i> نصائح</div>
      </div>
      <div class="card-body" style="font-size:.8rem;color:var(--text2);line-height:2">
        💡 الإشعار المباشر يظهر في <strong>زر الجرس</strong> للعميل فوراً<br>
        💡 لإضافة إشعار <strong>منبثق (Popup)</strong> استخدم تبويب "الإشعارات المنبثقة"<br>
        💡 يمكنك توجيه العميل لأي خدمة أو قسم عند الضغط<br>
        💡 الرسالة لعميل واحد ترسل أيضاً على <strong>تليغرام</strong> إن ربط حسابه
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     التبويب 2: الإشعارات المنبثقة
════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'popups'): ?>
<div class="an-2col" style="display:grid;grid-template-columns:1.2fr 1fr;gap:18px">

  <!-- نموذج الإضافة/التعديل -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title">
        <i class="fas fa-<?= $editPopup ? 'edit' : 'plus-circle' ?>" style="color:#a78bfa"></i>
        <?= $editPopup ? 'تعديل إشعار منبثق' : 'إنشاء إشعار منبثق جديد' ?>
      </div>
      <?php if ($editPopup): ?>
      <a href="?tab=popups" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> جديد</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" id="popupForm">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_popup" value="1">
        <input type="hidden" name="edit_id" value="<?= $editPopup['id'] ?? 0 ?>">

        <!-- الأيقونة واللون -->
        <div class="an-2col" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label><i class="fas fa-icons"></i> الأيقونة</label>
            <input type="hidden" name="icon" id="popupSelectedIcon" value="<?= htmlspecialchars($editPopup['icon'] ?? 'bell') ?>">
            <div class="icon-grid" id="popupIconGrid">
              <?php foreach ($icons as $ic): ?>
              <div class="icon-opt <?= ($editPopup['icon']??'bell')===$ic?'selected':'' ?>" onclick="selectPopupIcon('<?= $ic ?>')" title="<?= $ic ?>">
                <i class="fas fa-<?= $ic ?>"></i>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label><i class="fas fa-palette"></i> اللون</label>
            <input type="hidden" name="color" id="popupSelectedColor" value="<?= htmlspecialchars($editPopup['color'] ?? '#6c3fe0') ?>">
            <div class="color-grid" id="popupColorGrid">
              <?php foreach ($colors as $clr): ?>
              <div class="color-swatch <?= ($editPopup['color']??'#6c3fe0')===$clr?'selected':'' ?>" style="background:<?= $clr ?>" onclick="selectPopupColor('<?= $clr ?>')"></div>
              <?php endforeach; ?>
            </div>
            <input type="color" id="popupCustomColor" style="margin-top:8px;width:40px;height:28px;border:none;background:none;cursor:pointer;border-radius:6px" onchange="selectPopupColor(this.value)">
          </div>
        </div>

        <div class="form-group">
          <label><i class="fas fa-heading"></i> العنوان <span style="color:#ff4455">*</span></label>
          <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($editPopup['title'] ?? '') ?>" placeholder="مثال: 🎉 عرض خاص اليوم فقط!" maxlength="200" oninput="updatePopupPreview()" id="popupTitle">
        </div>
        <div class="form-group">
          <label><i class="fas fa-align-right"></i> الرسالة <span style="color:#ff4455">*</span></label>
          <textarea name="message" class="form-control" rows="3" oninput="updatePopupPreview()" id="popupMsg" style="resize:vertical" placeholder="اكتب نص الإشعار..."><?= htmlspecialchars($editPopup['message'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label><i class="fas fa-check"></i> نص زر الموافقة</label>
          <input type="text" name="btn_label" class="form-control" value="<?= htmlspecialchars($editPopup['btn_label'] ?? 'موافق') ?>" placeholder="موافق / اشتري الآن / تفاصيل" oninput="updatePopupPreview()" id="popupBtnLabel">
        </div>

        <!-- الإجراء -->
        <div class="form-group">
          <label><i class="fas fa-mouse-pointer"></i> الإجراء عند الضغط على الزر</label>
          <select name="action_type" class="form-control" id="popupActionType" onchange="toggleActionFields(this.value,'popup'); updatePopupPreview()">
            <option value="none" <?= ($editPopup['action_type']??'')==='none'?'selected':'' ?>>لا يوجد توجيه (إغلاق فقط)</option>
            <option value="url"  <?= ($editPopup['action_type']??'')==='url'?'selected':'' ?>>رابط خارجي</option>
            <option value="service" <?= ($editPopup['action_type']??'')==='service'?'selected':'' ?>>خدمة محددة</option>
            <option value="category" <?= ($editPopup['action_type']??'')==='category'?'selected':'' ?>>قسم محدد</option>
            <option value="page" <?= ($editPopup['action_type']??'')==='page'?'selected':'' ?>>صفحة داخلية</option>
          </select>
        </div>
        <div id="popup_url_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-link"></i> الرابط</label>
          <input type="text" name="action_url" class="form-control" value="<?= htmlspecialchars($editPopup['action_url'] ?? '') ?>" placeholder="https://...">
        </div>
        <div id="popup_service_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-box"></i> الخدمة</label>
          <select name="ref_id" class="form-control">
            <option value="">-- اختر --</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($editPopup['action_ref_id']??0)==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="popup_category_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-folder"></i> القسم</label>
          <select name="ref_id" class="form-control">
            <option value="">-- اختر --</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= ($editPopup['action_ref_id']??0)==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="popup_page_wrap" style="display:none" class="form-group">
          <label><i class="fas fa-file"></i> مسار الصفحة</label>
          <input type="text" name="action_url" class="form-control" value="<?= ($editPopup['action_type']??'')==='page'?htmlspecialchars(str_replace(SITE_URL.'/','',$editPopup['action_url']??'')):'' ?>" placeholder="wallet.php">
        </div>

        <!-- الاستهداف -->
        <div class="form-group">
          <label><i class="fas fa-users"></i> الاستهداف</label>
          <select name="target" class="form-control" id="popupTarget" onchange="togglePopupTarget(this.value)">
            <option value="all" <?= ($editPopup['target']??'all')==='all'?'selected':'' ?>>كل العملاء</option>
            <option value="specific" <?= ($editPopup['target']??'')==='specific'?'selected':'' ?>>عملاء محددون</option>
          </select>
        </div>
        <div id="popupSpecificWrap" style="display:none" class="form-group">
          <label>IDs العملاء (مفصولة بفاصلة)</label>
          <input type="text" name="specific_user_ids" class="form-control" value="<?= htmlspecialchars($editPopup['target_user_ids'] ? implode(',', json_decode($editPopup['target_user_ids'],true)) : '') ?>" placeholder="1,5,12,23">
          <small style="color:var(--text3);font-size:.75rem">مثال: 1,5,12 — يمكن نسخ ID العميل من صفحة العملاء</small>
        </div>

        <!-- خيارات إضافية -->
        <div class="an-2col form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;padding:10px 12px;border:1px solid var(--border);border-radius:8px">
            <input type="checkbox" name="show_once" value="1" <?= ($editPopup['show_once']??1)?'checked':'' ?> style="width:15px;height:15px">
            عرض مرة واحدة فقط
          </label>
          <div>
            <label style="font-size:.82rem;color:var(--text2);display:block;margin-bottom:4px">تاريخ الانتهاء (اختياري)</label>
            <input type="datetime-local" name="end_at" class="form-control" value="<?= $editPopup && $editPopup['end_at'] ? date('Y-m-d\TH:i', strtotime($editPopup['end_at'])) : '' ?>" style="font-size:.8rem">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="fas fa-save"></i> <?= $editPopup ? 'حفظ التعديلات' : 'إنشاء الإشعار المنبثق' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- معاينة + قائمة -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- معاينة الـ popup -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-eye"></i> معاينة الـ Popup</div>
      </div>
      <div class="card-body">
        <div style="background:rgba(0,0,0,0.3);border-radius:12px;padding:12px;position:relative">
          <div class="preview-popup" id="popupPreviewBox">
            <div class="preview-popup-icon" id="ppIcon" style="background:#6c3fe022;color:#6c3fe0">
              <i class="fas fa-bell" id="ppIconI"></i>
            </div>
            <div class="preview-popup-title" id="ppTitle">عنوان الإشعار</div>
            <div class="preview-popup-msg" id="ppMsg">نص الرسالة يظهر هنا للعميل</div>
            <div style="display:flex;gap:8px;justify-content:center">
              <button class="preview-popup-btn" id="ppBtn" style="background:#6c3fe0">موافق</button>
              <button class="preview-popup-btn" style="background:rgba(255,255,255,0.1)">لاحقاً</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- قائمة الـ popups -->
    <div class="card" style="flex:1">
      <div class="card-header">
        <div class="card-header-title"><i class="fas fa-list"></i> الإشعارات المنبثقة (<?= count($popups) ?>)</div>
      </div>
      <div class="card-body" style="padding:8px">
        <?php if (empty($popups)): ?>
        <div style="text-align:center;padding:30px;color:var(--text3);font-size:.85rem">
          <i class="fas fa-window-restore" style="display:block;font-size:2rem;margin-bottom:10px;opacity:.3"></i>
          لا توجد إشعارات منبثقة بعد
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($popups as $p):
          $isExpired = $p['end_at'] && strtotime($p['end_at']) < time();
          $viewCount = (int)$pdo->prepare("SELECT COUNT(*) FROM broadcast_views WHERE broadcast_id=?")->execute([$p['id']]) ? $pdo->query("SELECT COUNT(*) FROM broadcast_views WHERE broadcast_id={$p['id']}")->fetchColumn() : 0;
        ?>
        <div class="popup-row">
          <div style="width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;background:<?= htmlspecialchars($p['color']) ?>22;color:<?= htmlspecialchars($p['color']) ?>">
            <i class="fas fa-<?= htmlspecialchars($p['icon']) ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.83rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= htmlspecialchars($p['title']) ?>
            </div>
            <div style="font-size:.72rem;color:var(--text3);display:flex;gap:8px;margin-top:2px;flex-wrap:wrap">
              <span><?= $p['target']==='all' ? '👥 للكل' : '👤 محدد' ?></span>
              <span>👁️ <?= $viewCount ?> مشاهدة</span>
              <?php if ($isExpired): ?><span style="color:#ff4455">⏰ منتهي</span><?php endif; ?>
              <?php if ($p['action_type']!=='none'): ?><span style="color:#a78bfa">🔗 يحتوي رابط</span><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:5px;flex-shrink:0">
            <a href="?tab=popups&action=toggle_broadcast&id=<?= $p['id'] ?>" class="btn btn-<?= $p['is_active']?'success':'secondary' ?> btn-sm" title="<?= $p['is_active']?'إيقاف':'تفعيل' ?>">
              <i class="fas fa-<?= $p['is_active']?'eye':'eye-slash' ?>"></i>
            </a>
            <a href="?tab=popups&action=edit_popup&id=<?= $p['id'] ?>" class="btn btn-primary btn-sm" title="تعديل">
              <i class="fas fa-edit"></i>
            </a>
            <a href="?tab=popups&action=delete_broadcast&id=<?= $p['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('حذف هذا الإشعار؟')" title="حذف">
              <i class="fas fa-trash"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// ─── أيقونات وألوان (إشعار مباشر) ────────────────────────────────────────────
function selectIcon(ic) {
    document.getElementById('selectedIcon').value = ic;
    document.querySelectorAll('#iconGrid .icon-opt').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    updatePreview();
}
function selectColor(clr) {
    document.getElementById('selectedColor').value = clr;
    document.querySelectorAll('#colorGrid .color-swatch').forEach(el => el.classList.remove('selected'));
    event.currentTarget && event.currentTarget.classList && event.currentTarget.classList.add('selected');
    updatePreview();
}
function selectTarget(t) {
    ['all','specific'].forEach(v => {
        document.getElementById('target'+v.charAt(0).toUpperCase()+v.slice(1)+'Card').classList.toggle('selected', v===t);
    });
    document.querySelector('[name="target"][value="'+t+'"]').checked = true;
    document.getElementById('specificUserWrap').style.display = t==='specific' ? 'block' : 'none';
    document.getElementById('targetPreviewAll').style.display = t==='all' ? 'flex' : 'none';
    document.getElementById('targetPreviewOne').style.display = t==='specific' ? 'flex' : 'none';
}
function updatePreview() {
    const title = document.getElementById('directTitle').value || 'عنوان الإشعار';
    const msg   = document.getElementById('directMsg').value || 'نص الرسالة يظهر هنا';
    const icon  = document.getElementById('selectedIcon').value || 'bell';
    const color = document.getElementById('selectedColor').value || '#6c3fe0';
    document.getElementById('previewTitle').textContent = title;
    document.getElementById('previewMsg').textContent   = msg;
    document.getElementById('previewIcon').style.background = color + '22';
    document.getElementById('previewIcon').style.color = color;
    document.getElementById('previewIcon').innerHTML = `<i class="fas fa-${icon}"></i>`;
}

// ─── أيقونات وألوان (Popup) ───────────────────────────────────────────────────
function selectPopupIcon(ic) {
    document.getElementById('popupSelectedIcon').value = ic;
    document.querySelectorAll('#popupIconGrid .icon-opt').forEach(el => el.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
    updatePopupPreview();
}
function selectPopupColor(clr) {
    document.getElementById('popupSelectedColor').value = clr;
    document.querySelectorAll('#popupColorGrid .color-swatch').forEach(el => el.classList.remove('selected'));
    if (event.currentTarget && event.currentTarget.classList) event.currentTarget.classList.add('selected');
    updatePopupPreview();
}
function updatePopupPreview() {
    const title = document.getElementById('popupTitle').value || 'عنوان الإشعار';
    const msg   = document.getElementById('popupMsg').value || 'نص الرسالة يظهر هنا للعميل';
    const btn   = document.getElementById('popupBtnLabel').value || 'موافق';
    const icon  = document.getElementById('popupSelectedIcon').value || 'bell';
    const color = document.getElementById('popupSelectedColor').value || '#6c3fe0';
    document.getElementById('ppTitle').textContent = title;
    document.getElementById('ppMsg').textContent   = msg;
    document.getElementById('ppBtn').textContent   = btn;
    document.getElementById('ppBtn').style.background = color;
    document.getElementById('ppIcon').style.background = color + '22';
    document.getElementById('ppIcon').style.color = color;
    document.getElementById('ppIconI').className = `fas fa-${icon}`;
}

// ─── حقول الإجراء ─────────────────────────────────────────────────────────────
function toggleActionFields(val, prefix) {
    ['url','service','category','page'].forEach(t => {
        const el = document.getElementById(prefix + '_' + t + '_wrap');
        if (el) el.style.display = val===t ? 'block' : 'none';
    });
}
function togglePopupTarget(val) {
    document.getElementById('popupSpecificWrap').style.display = val==='specific' ? 'block' : 'none';
}

// ─── init ─────────────────────────────────────────────────────────────────────
<?php if ($editPopup): ?>
toggleActionFields('<?= $editPopup['action_type'] ?>', 'popup');
togglePopupTarget('<?= $editPopup['target'] ?>');
updatePopupPreview();
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
