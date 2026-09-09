<?php
require_once '../includes/config.php';
if (!isAdmin()) { header('Location: index.php'); exit; }
$pageTitle = 'إدارة قائمة لوحة الإدارة - ' . SITE_NAME;

/* ══════════════════════════════════════════════════════════════════
   1) إنشاء الجدول (آمن التكرار) + تعبئة كل الروابط الحالية أول مرة فقط
   ══════════════════════════════════════════════════════════════════ */
$pdo->exec("CREATE TABLE IF NOT EXISTS admin_menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_key VARCHAR(60) NOT NULL UNIQUE,
    section_name VARCHAR(60) NOT NULL,
    section_order INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    label VARCHAR(100) NOT NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'circle',
    icon_type VARCHAR(10) NOT NULL DEFAULT 'fas',
    url VARCHAR(255) NOT NULL,
    permission_key VARCHAR(60) DEFAULT NULL,
    admin_only TINYINT(1) NOT NULL DEFAULT 0,
    badge_source VARCHAR(40) DEFAULT NULL,
    badge_color VARCHAR(30) DEFAULT 'var(--gold)',
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function seedDefaultMenu(PDO $pdo) {
    // [key, section, section_order, sort, label, icon, icon_type, url, permission_key, admin_only, badge_source, badge_color]
    $items = [
        ['dashboard','الرئيسية',1,1,'لوحة التحكم','chart-line','fas','','perm_dashboard',0,null,'var(--gold)'],
        ['reports','الرئيسية',1,2,'التقارير الشاملة','chart-bar','fas','reports.php',null,1,null,'var(--gold)'],

        ['categories','المحتوى',2,1,'الأقسام','folder-open','fas','categories.php','perm_categories_view',0,null,'var(--gold)'],
        ['services','المحتوى',2,2,'الخدمات','box','fas','services.php','perm_services_view',0,null,'var(--gold)'],
        ['import_services','المحتوى',2,3,'استيراد خدمات','cloud-download-alt','fas','import_services.php',null,1,null,'var(--gold)'],

        ['customers','المستخدمون',3,1,'العملاء','users','fas','customers.php','perm_customers_view',0,'pending_devices','#f5a623'],
        ['pricing_groups','المستخدمون',3,2,'مجموعات التسعير','tags','fas','pricing_groups.php',null,1,null,'var(--gold)'],
        ['staff','المستخدمون',3,3,'الموظفون','user-tie','fas','staff.php','perm_staff',0,null,'var(--gold)'],

        ['orders','المبيعات',4,1,'الطلبات','shopping-bag','fas','orders.php','perm_orders_view',0,'pending_orders','var(--gold)'],
        ['order_objections','المبيعات',4,2,'اعتراضات العمليات','flag','fas','order_objections.php','perm_orders_view',0,'pending_objections','#ff6677'],
        ['p2p','المبيعات',4,3,'سوق P2P','handshake','fas','p2p.php','perm_orders_view',0,'p2p','var(--gold)'],

        ['telecom_agents','وكلاء الشحن والخدمات',5,1,'وكلاء الشحن','satellite-dish','fas','telecom_agents.php','perm_settings',0,null,'var(--gold)'],
        ['agent_distributions','وكلاء الشحن والخدمات',5,2,'التوزيع برو','random','fas','agent_distributions.php','perm_settings',0,null,'var(--gold)'],
        ['sadad','وكلاء الشحن والخدمات',5,3,'قائمة سداد','list-alt','fas','sadad.php','perm_settings',0,null,'var(--gold)'],
        ['yemen_robot','وكلاء الشحن والخدمات',5,4,'Yemen Robot','robot','fas','yemen_robot.php','perm_settings',0,null,'var(--gold)'],
        ['floosak','وكلاء الشحن والخدمات',5,5,'Floosak','sim-card','fas','floosak.php','perm_settings',0,null,'var(--gold)'],

        ['whatsapp_manage','واتساب',6,1,'إدارة واتساب','whatsapp','fab','whatsapp.php',null,1,null,'var(--gold)'],
        ['whatsapp_inbox','واتساب',6,2,'الصندوق الوارد','inbox','fas','whatsapp_inbox.php',null,1,null,'var(--gold)'],
        ['whatsapp_groups','واتساب',6,3,'المجموعات','users','fas','whatsapp.php?tab=groups',null,1,null,'var(--gold)'],
        ['whatsapp_monitor','واتساب',6,4,'مراقبة WhatsApp','heartbeat','fas','whatsapp_monitor.php',null,1,null,'var(--gold)'],
        ['whatsapp_single','واتساب',6,5,'إرسال رسالة','comment','fas','whatsapp.php?tab=single',null,1,null,'var(--gold)'],
        ['whatsapp_bulk','واتساب',6,6,'رسائل جماعية','bullhorn','fas','whatsapp.php?tab=bulk',null,1,null,'var(--gold)'],

        ['providers','النظام',7,1,'مزودو API','plug','fas','providers.php','perm_providers_view',0,null,'var(--gold)'],
        ['kyc','النظام',7,2,'تحقق الهوية','id-card','fas','kyc.php','perm_customers_view',0,'kyc_pending','#00d4aa'],
        ['banners','النظام',7,3,'السلايدر','images','fas','banners.php','perm_settings',0,null,'var(--gold)'],
        ['settings','النظام',7,4,'الإعدادات','sliders-h','fas','settings.php','perm_settings',0,null,'var(--gold)'],
        ['password_reset','النظام',7,5,'استعادة كلمة المرور','key','fas','password_reset.php','perm_settings',0,null,'var(--gold)'],
        ['payments','النظام',7,6,'طرق الدفع','money-bill-wave','fas','payments.php?tab=requests','perm_settings',0,'pending_topups','var(--green)'],
        ['currencies','النظام',7,7,'عملات العرض','coins','fas','currencies.php',null,1,null,'var(--gold)'],
        ['display_languages','النظام',7,8,'لغات العرض','language','fas','languages.php',null,1,null,'var(--gold)'],
        ['recharge_cards','النظام',7,9,'بطاقات الشحن','ticket-alt','fas','recharge_cards.php',null,1,null,'var(--gold)'],
        ['code_stock','النظام',7,10,'مخزون الأكواد','key','fas','code_stock.php','code_stock_view',0,'code_stock_low','#f5a623'],
        ['wallet_ledger','النظام',7,11,'سجل المعاملات','wallet','fas','wallet_ledger.php','perm_settings',0,null,'var(--gold)'],
        ['sms_topup','النظام',7,12,'شحن SMS','sms','fas','sms_topup.php',null,1,null,'var(--gold)'],
        ['admin_telecom','النظام',7,13,'كبينة السداد','sim-card','fas','admin_telecom.php',null,1,'admin_telecom_pending','#00d4ff'],
        ['admin_notifications','النظام',7,14,'الإشعارات','paper-plane','fas','admin_notifications.php',null,1,null,'var(--gold)'],
        ['push_notifications','النظام',7,15,'Push Notifications','bell','fas','push_notifications.php',null,1,null,'var(--gold)'],
        ['maintenance','النظام',7,16,'الصيانة','tools','fas','maintenance.php',null,1,'maintenance','#ff4455'],
        ['quizzes','النظام',7,17,'المسابقات','trophy','fas','quizzes.php',null,1,null,'var(--gold)'],
        ['referrals','النظام',7,18,'الإحالة','users','fas','referrals.php',null,1,null,'var(--gold)'],
        ['faq','النظام',7,19,'الأسئلة الشائعة','question-circle','fas','faq.php',null,1,null,'var(--gold)'],
        ['site_pages','المحتوى',2,50,'الصفحات العامة','file-alt','fas','site_pages.php',null,1,null,'#00d4ff'],
        ['coupons','النظام',7,20,'كوبونات الخصم','ticket-alt','fas','coupons.php',null,1,null,'var(--gold)'],
        ['chat','النظام',7,21,'المحادثات','comment-dots','fas','chat.php','perm_customers_view',0,null,'var(--gold)'],
        ['contact_buttons','النظام',7,22,'أزرار التواصل','comments','fas','contact_buttons.php',null,1,null,'var(--gold)'],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO admin_menu_items
        (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($items as $it) { $ins->execute($it); }
}
$itemCount = (int)$pdo->query("SELECT COUNT(*) FROM admin_menu_items")->fetchColumn();
if ($itemCount === 0) seedDefaultMenu($pdo);
// إضافة رابط المراقبة للمواقع التي سبقت تهيئة قائمة الإدارة.
$monitorMenu = $pdo->prepare("SELECT COUNT(*) FROM admin_menu_items WHERE item_key='whatsapp_monitor'");
$monitorMenu->execute();
if (!(int)$monitorMenu->fetchColumn()) {
    $pdo->prepare("INSERT INTO admin_menu_items
        (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,badge_source,badge_color)
        VALUES ('whatsapp_monitor','واتساب',6,4,'مراقبة WhatsApp','heartbeat','fas','whatsapp_monitor.php',NULL,1,NULL,'var(--gold)')")->execute();
}

/* ══════════════════════════════════════════════════════════════════
   2) معالجة الإجراءات (POST/GET)
   ══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // تبديل الإظهار/الإخفاء
    if (isset($_POST['toggle_visible'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE admin_menu_items SET is_visible = 1 - is_visible WHERE id=?")->execute([$id]);
    }

    // نقل عنصر لأعلى/أسفل داخل نفس القسم
    if (isset($_POST['move']) && in_array($_POST['move'], ['up','down'], true)) {
        $id = (int)$_POST['id'];
        $cur = $pdo->prepare("SELECT * FROM admin_menu_items WHERE id=?"); $cur->execute([$id]); $cur = $cur->fetch();
        if ($cur) {
            $dir = $_POST['move'] === 'up' ? '<' : '>';
            $ord = $_POST['move'] === 'up' ? 'DESC' : 'ASC';
            $nb = $pdo->prepare("SELECT * FROM admin_menu_items WHERE section_name=? AND sort_order $dir ? ORDER BY sort_order $ord LIMIT 1");
            $nb->execute([$cur['section_name'], $cur['sort_order']]);
            $nb = $nb->fetch();
            if ($nb) {
                $pdo->prepare("UPDATE admin_menu_items SET sort_order=? WHERE id=?")->execute([$nb['sort_order'], $cur['id']]);
                $pdo->prepare("UPDATE admin_menu_items SET sort_order=? WHERE id=?")->execute([$cur['sort_order'], $nb['id']]);
            }
        }
    }

    // نقل قسم كامل لأعلى/أسفل
    if (isset($_POST['move_section']) && in_array($_POST['move_section'], ['up','down'], true)) {
        $curOrder = (int)$_POST['section_order'];
        $dir = $_POST['move_section'] === 'up' ? '<' : '>';
        $ord = $_POST['move_section'] === 'up' ? 'DESC' : 'ASC';
        $nb = $pdo->prepare("SELECT DISTINCT section_order FROM admin_menu_items WHERE section_order $dir ? ORDER BY section_order $ord LIMIT 1");
        $nb->execute([$curOrder]);
        $nbOrder = $nb->fetchColumn();
        if ($nbOrder !== false) {
            $pdo->prepare("UPDATE admin_menu_items SET section_order=-1 WHERE section_order=?")->execute([$curOrder]);
            $pdo->prepare("UPDATE admin_menu_items SET section_order=? WHERE section_order=?")->execute([$curOrder, $nbOrder]);
            $pdo->prepare("UPDATE admin_menu_items SET section_order=? WHERE section_order=-1")->execute([$nbOrder]);
        }
    }

    // تعديل تسمية/أيقونة عنصر
    if (isset($_POST['edit_item'])) {
        $id    = (int)$_POST['id'];
        $label = trim($_POST['label'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        if ($label !== '' && $icon !== '') {
            $pdo->prepare("UPDATE admin_menu_items SET label=?, icon=? WHERE id=?")->execute([$label, $icon, $id]);
        }
    }

    // نقل عنصر لقسم آخر (قسم جديد أو موجود)
    if (isset($_POST['change_section'])) {
        $id  = (int)$_POST['id'];
        $sec = trim($_POST['new_section'] ?? '');
        if ($sec !== '') {
            $maxOrd = $pdo->query("SELECT COALESCE(MAX(section_order),0) FROM admin_menu_items")->fetchColumn();
            $existing = $pdo->prepare("SELECT section_order FROM admin_menu_items WHERE section_name=? LIMIT 1");
            $existing->execute([$sec]);
            $secOrder = $existing->fetchColumn();
            if ($secOrder === false) $secOrder = $maxOrd + 1;
            $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM admin_menu_items WHERE section_name=?");
            $maxSort->execute([$sec]);
            $newSort = (int)$maxSort->fetchColumn() + 1;
            $pdo->prepare("UPDATE admin_menu_items SET section_name=?, section_order=?, sort_order=? WHERE id=?")
                ->execute([$sec, $secOrder, $newSort, $id]);
        }
    }

    // حذف عنصر (المضاف يدوياً فقط، ليس عناصر النظام الأصلية)
    if (isset($_POST['delete_item'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM admin_menu_items WHERE id=? AND is_system=0")->execute([$id]);
    }

    // إعادة تسمية قسم كامل (يشمل كل عناصره)
    if (isset($_POST['rename_section'])) {
        $oldName = trim($_POST['old_section'] ?? '');
        $newName = trim($_POST['new_section_name'] ?? '');
        if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
            $existing = $pdo->prepare("SELECT section_order FROM admin_menu_items WHERE section_name=? LIMIT 1");
            $existing->execute([$newName]);
            $existOrder = $existing->fetchColumn();
            if ($existOrder !== false) {
                // لو الاسم الجديد يطابق قسم موجود مسبقاً، يتم الدمج معه
                $pdo->prepare("UPDATE admin_menu_items SET section_name=?, section_order=? WHERE section_name=?")->execute([$newName, $existOrder, $oldName]);
            } else {
                $pdo->prepare("UPDATE admin_menu_items SET section_name=? WHERE section_name=?")->execute([$newName, $oldName]);
            }
        }
    }

    // إضافة قسم جديد فارغ (بدون روابط بعد)
    if (isset($_POST['add_section'])) {
        $name = trim($_POST['section_name'] ?? '');
        if ($name !== '') {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM admin_menu_items WHERE section_name=?");
            $exists->execute([$name]);
            if (!$exists->fetchColumn()) {
                $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(section_order),0) FROM admin_menu_items")->fetchColumn();
                $key = 'placeholder_' . substr(md5($name . microtime()), 0, 10);
                $pdo->prepare("INSERT INTO admin_menu_items
                    (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,is_visible,is_system)
                    VALUES (?,?,?,1,?, 'ellipsis-h','fas','#',NULL,1,0,0)")
                    ->execute([$key, $name, $maxOrd + 1, '(قسم فارغ - أضف له روابط)']);
            }
        }
    }

    // إضافة صفحة مكتشَفة (غير مضافة سابقاً) للقائمة
    if (isset($_POST['add_discovered'])) {
        $file  = basename($_POST['file'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $sec   = trim($_POST['new_section'] ?? 'أخرى');
        $icon  = trim($_POST['icon'] ?? 'circle');
        if ($file && $label && preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $file)) {
            $key = 'custom_' . preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('.php','',$file)));
            $maxOrd = $pdo->query("SELECT COALESCE(MAX(section_order),0) FROM admin_menu_items")->fetchColumn();
            $existing = $pdo->prepare("SELECT section_order FROM admin_menu_items WHERE section_name=? LIMIT 1");
            $existing->execute([$sec]);
            $secOrder = $existing->fetchColumn();
            if ($secOrder === false) $secOrder = $maxOrd + 1;
            $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM admin_menu_items WHERE section_name=?");
            $maxSort->execute([$sec]);
            $newSort = (int)$maxSort->fetchColumn() + 1;
            $pdo->prepare("INSERT IGNORE INTO admin_menu_items
                (item_key,section_name,section_order,sort_order,label,icon,icon_type,url,permission_key,admin_only,is_visible,is_system)
                VALUES (?,?,?,?,?,?, 'fas', ?, NULL, 1, 1, 0)")
                ->execute([$key, $sec, $secOrder, $newSort, $label, $icon, $file]);
        }
    }

    header('Location: menu_manager.php'); exit;
}

/* ══════════════════════════════════════════════════════════════════
   3) اكتشاف صفحات موجودة على السيرفر لكن غير مضافة للقائمة
   ══════════════════════════════════════════════════════════════════ */
$knownUrls = [];
foreach ($pdo->query("SELECT url FROM admin_menu_items")->fetchAll(PDO::FETCH_COLUMN) as $u) {
    $knownUrls[basename(strtok($u, '?'))] = true;
}
// ملفات مساعدة/تقنية لا تُعرض كصفحات أبداً (استبعاد دائم)
$excludeAlways = [
    'index.php','header.php','footer.php','menu_manager.php','pricing_helper.php',
    'ajax_test_smtp.php','ajax_test_telegram.php','cron_debug.php','cron_sync_orders.php',
    'generate_vapid.php','migrate_display_name.php','popup_debug.php','smtp_debug.php',
    'telecom_agents_test.php','wa_relay_worker.php','wa_test.php','error_log',
    'cron_sync_prices.php',
];
$discovered = [];
foreach (glob(__DIR__ . '/*.php') as $f) {
    $name = basename($f);
    if (isset($knownUrls[$name]) || in_array($name, $excludeAlways, true)) continue;
    $discovered[] = $name;
}
sort($discovered);

/* ══════════════════════════════════════════════════════════════════
   4) جلب كل العناصر مرتّبة لعرضها
   ══════════════════════════════════════════════════════════════════ */
$all = $pdo->query("SELECT * FROM admin_menu_items ORDER BY section_order ASC, sort_order ASC")->fetchAll();
$sections = [];
foreach ($all as $row) {
    $sections[$row['section_name']]['order'] = $row['section_order'];
    $sections[$row['section_name']]['items'][] = $row;
}
uasort($sections, fn($a,$b) => $a['order'] <=> $b['order']);

include 'header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(124,58,237,.15)"><i class="fas fa-bars" style="color:#a78bfa"></i></div>
      إدارة قائمة لوحة الإدارة
    </div>
    <div class="page-header-sub">تحكم بترتيب الأقسام والصفحات، إخفاء أو إظهار أي رابط، وإضافة صفحات جديدة</div>
  </div>
</div>

<div class="card" style="margin-bottom:14px;padding:12px 16px">
  <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="add_section" value="1">
    <label class="td-bold" style="font-size:13px;white-space:nowrap"><i class="fas fa-plus-circle" style="color:var(--green)"></i> إضافة قسم جديد:</label>
    <input type="text" name="section_name" placeholder="اسم القسم الجديد" class="form-control" style="width:220px;padding:6px 10px;font-size:13px" required>
    <button class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> إنشاء القسم</button>
  </form>
</div>

<?php foreach ($sections as $secName => $secData): $items = $secData['items']; ?>
<div class="card" style="margin-bottom:14px">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border)">
    <div class="td-bold" style="font-size:14px"><i class="fas fa-layer-group" style="color:var(--gold);margin-left:6px"></i><?= htmlspecialchars($secName) ?></div>
    <div style="display:flex;gap:4px">
      <button class="btn btn-secondary btn-xs" title="إعادة تسمية القسم" onclick="openRename('<?= addslashes($secName) ?>')"><i class="fas fa-i-cursor"></i> إعادة تسمية</button>
      <form method="POST" style="display:inline">
        <input type="hidden" name="move_section" value="up">
        <input type="hidden" name="section_order" value="<?= $secData['order'] ?>">
        <button class="btn btn-secondary btn-xs" title="نقل القسم لأعلى"><i class="fas fa-arrow-up"></i></button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="move_section" value="down">
        <input type="hidden" name="section_order" value="<?= $secData['order'] ?>">
        <button class="btn btn-secondary btn-xs" title="نقل القسم لأسفل"><i class="fas fa-arrow-down"></i></button>
      </form>
    </div>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th style="width:60px">الترتيب</th><th>الأيقونة</th><th>الاسم</th><th>الرابط</th><th>الصلاحية</th><th style="width:70px">الحالة</th><th>إجراءات</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr style="<?= $it['is_visible'] ? '' : 'opacity:.45' ?>">
          <td>
            <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?=$it['id']?>"><input type="hidden" name="move" value="up"><button class="btn btn-secondary btn-xs" style="padding:2px 6px"><i class="fas fa-caret-up"></i></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="id" value="<?=$it['id']?>"><input type="hidden" name="move" value="down"><button class="btn btn-secondary btn-xs" style="padding:2px 6px"><i class="fas fa-caret-down"></i></button></form>
          </td>
          <td><i class="<?= $it['icon_type'] ?> fa-<?= htmlspecialchars($it['icon']) ?>" style="color:var(--gold);width:18px;text-align:center"></i></td>
          <td class="td-bold"><?= htmlspecialchars($it['label']) ?><?= $it['is_system'] ? '' : ' <span style="font-size:9px;color:#a78bfa;background:rgba(124,58,237,.12);padding:1px 6px;border-radius:8px">مخصّص</span>' ?></td>
          <td class="td-muted" style="font-size:11px;font-family:monospace"><?= htmlspecialchars($it['url'] ?: 'admin/') ?></td>
          <td class="td-muted" style="font-size:11px"><?= $it['admin_only'] ? 'المدير فقط' : ($it['permission_key'] ? htmlspecialchars($it['permission_key']) : 'الكل') ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="id" value="<?=$it['id']?>">
              <input type="hidden" name="toggle_visible" value="1">
              <button class="btn btn-xs <?= $it['is_visible'] ? 'btn-success' : 'btn-secondary' ?>" style="width:60px">
                <?= $it['is_visible'] ? '<i class="fas fa-eye"></i> ظاهر' : '<i class="fas fa-eye-slash"></i> مخفي' ?>
              </button>
            </form>
          </td>
          <td style="white-space:nowrap">
            <button class="btn btn-secondary btn-xs" onclick="openEdit(<?= $it['id'] ?>,'<?= addslashes($it['label']) ?>','<?= addslashes($it['icon']) ?>')"><i class="fas fa-pen"></i></button>
            <button class="btn btn-secondary btn-xs" onclick="openMove(<?= $it['id'] ?>,'<?= addslashes($secName) ?>')"><i class="fas fa-arrows-alt"></i></button>
            <?php if (!$it['is_system']): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا الرابط من القائمة؟')">
              <input type="hidden" name="id" value="<?=$it['id']?>"><input type="hidden" name="delete_item" value="1">
              <button class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<!-- صفحات موجودة على السيرفر لكن غير مضافة للقائمة -->
<div class="card" style="margin-bottom:14px">
  <div style="padding:12px 16px;border-bottom:1px solid var(--border)">
    <div class="td-bold" style="font-size:14px"><i class="fas fa-search" style="color:#4dabf7;margin-left:6px"></i>صفحات غير مضافة للقائمة (<?= count($discovered) ?>)</div>
    <div class="td-muted" style="font-size:11px;margin-top:4px">ملفات PHP موجودة فعليًا في مجلد admin/ لكن ما زالت غير ظاهرة في القائمة الجانبية</div>
  </div>
  <div style="padding:12px 16px">
    <?php if (!$discovered): ?>
      <div class="td-muted" style="font-size:12px">لا توجد صفحات جديدة غير مضافة 👍</div>
    <?php else: foreach ($discovered as $f): ?>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--border)">
        <span class="td-bold" style="font-family:monospace;font-size:12px;min-width:180px"><?= htmlspecialchars($f) ?></span>
        <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:1">
          <input type="hidden" name="file" value="<?= htmlspecialchars($f) ?>">
          <input type="hidden" name="add_discovered" value="1">
          <input type="text" name="label" placeholder="اسم الرابط بالعربي" class="form-control" style="width:160px;padding:5px 8px;font-size:12px" required>
          <input type="text" name="icon" placeholder="أيقونة (مثال: file)" value="file" class="form-control" style="width:120px;padding:5px 8px;font-size:12px">
          <select name="new_section" class="form-control" style="width:160px;padding:5px 8px;font-size:12px">
            <?php foreach (array_keys($sections) as $s): ?><option value="<?=htmlspecialchars($s)?>"><?=htmlspecialchars($s)?></option><?php endforeach; ?>
            <option value="أخرى">— قسم جديد: أخرى —</option>
          </select>
          <button class="btn btn-primary btn-xs"><i class="fas fa-plus"></i> إضافة للقائمة</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Modal تعديل -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">تعديل الرابط</div><button class="modal-close" onclick="closeEdit()"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="edit_item" value="1">
        <input type="hidden" name="id" id="editId">
        <div class="form-group"><label>الاسم الظاهر</label><input type="text" name="label" id="editLabel" class="form-control" required></div>
        <div class="form-group"><label>الأيقونة (Font Awesome، بدون fa-)</label><input type="text" name="icon" id="editIcon" class="form-control" required></div>
        <button type="submit" class="btn btn-primary btn-block">حفظ</button>
      </form>
    </div>
  </div>
</div>

<!-- Modal نقل لقسم آخر -->
<div class="modal-overlay" id="moveModal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">نقل لقسم آخر</div><button class="modal-close" onclick="closeMove()"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="change_section" value="1">
        <input type="hidden" name="id" id="moveId">
        <div class="form-group">
          <label>اختر قسم موجود أو اكتب اسم قسم جديد</label>
          <input type="text" name="new_section" id="moveSection" class="form-control" list="sectionsList" required>
          <datalist id="sectionsList"><?php foreach (array_keys($sections) as $s): ?><option value="<?=htmlspecialchars($s)?>"><?php endforeach; ?></datalist>
        </div>
        <button type="submit" class="btn btn-primary btn-block">نقل</button>
      </form>
    </div>
  </div>
</div>

<!-- Modal إعادة تسمية القسم -->
<div class="modal-overlay" id="renameModal">
  <div class="modal">
    <div class="modal-header"><div class="modal-title">إعادة تسمية القسم</div><button class="modal-close" onclick="closeRename()"><i class="fas fa-times"></i></button></div>
    <div class="modal-body">
      <form method="POST">
        <input type="hidden" name="rename_section" value="1">
        <input type="hidden" name="old_section" id="renameOld">
        <div class="form-group">
          <label>الاسم الجديد للقسم</label>
          <input type="text" name="new_section_name" id="renameNew" class="form-control" required>
          <div class="td-muted" style="font-size:11px;margin-top:6px">سينطبق على كل روابط هذا القسم دفعة واحدة. لو كتبت اسم قسم آخر موجود مسبقًا، سيتم دمج القسمين معًا.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">حفظ</button>
      </form>
    </div>
  </div>
</div>

<script>
function openEdit(id,label,icon){document.getElementById('editId').value=id;document.getElementById('editLabel').value=label;document.getElementById('editIcon').value=icon;document.getElementById('editModal').classList.add('open');}
function closeEdit(){document.getElementById('editModal').classList.remove('open');}
function openMove(id,cur){document.getElementById('moveId').value=id;document.getElementById('moveSection').value=cur;document.getElementById('moveModal').classList.add('open');}
function closeMove(){document.getElementById('moveModal').classList.remove('open');}
function openRename(cur){document.getElementById('renameOld').value=cur;document.getElementById('renameNew').value=cur;document.getElementById('renameModal').classList.add('open');}
function closeRename(){document.getElementById('renameModal').classList.remove('open');}
</script>

<?php include 'footer.php'; ?>
