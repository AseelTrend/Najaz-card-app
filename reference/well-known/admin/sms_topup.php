<?php
// =====================================================
// admin/sms_topup.php — إدارة نظام الشحن عبر SMS
// =====================================================
require_once '../includes/config.php';
requireAdmin();

// ── مزامنة المنتهية في الرسائل الواردة (AJAX) ──
if (isset($_GET['sync_inbox_expired']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    try {
        // تأكد أن ENUM يقبل expired أولاً
        $enumRow = $pdo->query("SHOW COLUMNS FROM sms_inbox WHERE Field='status'")->fetch();
        if ($enumRow && strpos($enumRow['Type'], 'expired') === false) {
            $pdo->exec("ALTER TABLE sms_inbox MODIFY COLUMN status ENUM('pending','matched','used','rejected','expired') NOT NULL DEFAULT 'pending'");
        }
        $maxAge = (int)(getSetting('sms_max_age_minutes') ?: 60);
        // أيضاً الرسائل القديمة جداً بلا تحديد وقت — نعتمد 24 ساعة كحد أقصى
        $updated = $pdo->exec("
            UPDATE sms_inbox
            SET status = 'expired'
            WHERE status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL $maxAge MINUTE)
        ");
        echo json_encode(['ok' => true, 'updated' => (int)$updated]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'updated' => 0, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── مزامنة المنتهية (AJAX) ──
if (isset($_GET['sync_expired']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    try {
        $ttl = (int)(getSetting('sms_request_ttl') ?: 30);
        // طلبات بدون expires_at — نعتمد على created_at + ttl
        $updated = $pdo->exec("
            UPDATE sms_topup_requests
            SET status = 'expired'
            WHERE status = 'pending'
              AND (
                (expires_at IS NOT NULL AND expires_at < NOW())
                OR
                (expires_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL $ttl MINUTE))
              )
        ");
        echo json_encode(['ok' => true, 'updated' => $updated]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'updated' => 0]);
    }
    exit;
}

// ── تبديل سريع لتفعيل/إيقاف نظام SMS (AJAX) ──
if (isset($_GET['quick_toggle']) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    try {
        $current = getSetting('sms_topup_enabled');
        $newVal  = ($current === '1') ? '0' : '1';
        $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
            ->execute(['sms_topup_enabled', $newVal, $newVal]);
        echo json_encode(['ok' => true, 'enabled' => ($newVal === '1')]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'إدارة نظام SMS — ' . SITE_NAME;
$tab = $_GET['tab'] ?? 'requests';

// ── تهيئة الأعمدة الجديدة إن لم تكن موجودة ──
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM sms_topup_requests")->fetchAll(), 'Field');
    if (!in_array('admin_note', $cols))
        $pdo->exec("ALTER TABLE sms_topup_requests ADD COLUMN admin_note TEXT NULL AFTER status");
    if (!in_array('completed_at', $cols))
        $pdo->exec("ALTER TABLE sms_topup_requests ADD COLUMN completed_at DATETIME NULL AFTER admin_note");
    // sms_providers — إضافة provider_type و usdt_wallet_address
    $spcols = array_column($pdo->query("SHOW COLUMNS FROM sms_providers")->fetchAll(), 'Field');
    if (!in_array('provider_type', $spcols))
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN provider_type ENUM('sms','usdt') NOT NULL DEFAULT 'sms' AFTER sort_order");
    if (!in_array('usdt_wallet_address', $spcols))
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN usdt_wallet_address VARCHAR(255) DEFAULT '' AFTER provider_type");

    // sms_providers — إضافة wa_group_id لربط مجموعة واتساب
    if (!in_array('wa_group_id', $spcols))
        $pdo->exec("ALTER TABLE sms_providers ADD COLUMN wa_group_id VARCHAR(100) DEFAULT NULL AFTER usdt_wallet_address");

    // sms_inbox — إضافة admin_note وتوسيع ENUM ليشمل expired
    $icols = array_column($pdo->query("SHOW COLUMNS FROM sms_inbox")->fetchAll(), 'Field');
    if (!in_array('admin_note', $icols))
        $pdo->exec("ALTER TABLE sms_inbox ADD COLUMN admin_note TEXT NULL");
    // توسيع ENUM ليقبل 'expired'
    $enumRow = $pdo->query("SHOW COLUMNS FROM sms_inbox WHERE Field='status'")->fetch();
    if ($enumRow && strpos($enumRow['Type'], 'expired') === false) {
        $pdo->exec("ALTER TABLE sms_inbox MODIFY COLUMN status ENUM('pending','matched','used','rejected','expired') NOT NULL DEFAULT 'pending'");
    }
} catch (Exception $e) {}
$msg = '';
$msgType = 'success';

// ─── معالجة الحفظ ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── حفظ الإعدادات العامة ──
    if (isset($_POST['save_settings'])) {
        $fields = [
            'sms_topup_enabled'   => isset($_POST['sms_topup_enabled'])   ? '1' : '0',
            'sms_api_key'         => trim($_POST['sms_api_key']   ?? ''),
            'sms_device_id'       => trim($_POST['sms_device_id'] ?? ''),
            'sms_max_age_minutes' => (int)($_POST['sms_max_age_minutes'] ?? 60),
            'sms_request_ttl'     => (int)($_POST['sms_request_ttl']     ?? 30),
        ];
        foreach ($fields as $k => $v) {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$k, $v, $v]);
        }
        $msg = '✅ تم حفظ الإعدادات';
        $tab = 'settings';
    }

    // ── إضافة/تعديل مزود ──
    if (isset($_POST['save_provider'])) {
        $pid     = (int)($_POST['provider_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $sender  = trim($_POST['sender_match'] ?? '');
        $mtype   = in_array($_POST['match_type'] ?? '', ['exact','contains','regex'])
                   ? $_POST['match_type'] : 'contains';
        $pattern = trim($_POST['parse_pattern'] ?? '');
        $amtGrp  = max(1, (int)($_POST['amount_group'] ?? 1));
        $phGrp   = max(1, (int)($_POST['phone_group']  ?? 2));
        $curr    = strtoupper(trim($_POST['currency']   ?? 'YER'));
        $rate    = (float)($_POST['rate_to_usd'] ?? 0.002);
        $color   = trim($_POST['color']    ?? '#1e6fff');
        $icon    = trim($_POST['icon']     ?? 'mobile-alt');
        $status  = isset($_POST['status']) ? 1 : 0;
        $order   = (int)($_POST['sort_order'] ?? 0);
        $region  = in_array($_POST['region'] ?? '', ['north','south']) ? $_POST['region'] : '';
        $transferAccount = trim($_POST['transfer_account'] ?? '');
        $accountHolder   = trim($_POST['account_holder']   ?? '');
        $providerType    = in_array($_POST['provider_type'] ?? 'sms', ['sms','usdt']) ? ($_POST['provider_type']) : 'sms';
        $usdtWallet      = trim($_POST['usdt_wallet_address'] ?? '');
        $waGroupId       = trim($_POST['wa_group_id'] ?? '');

        if (empty($name) || empty($sender)) {
            $msg = '❌ يرجى ملء جميع الحقول المطلوبة';
            $msgType = 'danger';
        } else {
            // ── حفظ البيانات أولاً للحصول على الـ id ──
            if ($pid > 0) {
                $pdo->prepare("
                    UPDATE sms_providers SET
                        name=?, sender_match=?, match_type=?, parse_pattern=?,
                        amount_group=?, phone_group=?, currency=?, rate_to_usd=?,
                        color=?, icon=?, region=?, status=?, sort_order=?,
                        transfer_account=?, account_holder=?, provider_type=?, usdt_wallet_address=?,
                        wa_group_id=?
                    WHERE id=?
                ")->execute([$name,$sender,$mtype,$pattern,$amtGrp,$phGrp,$curr,$rate,$color,$icon,$region,$status,$order,$transferAccount,$accountHolder,$providerType,$usdtWallet,$waGroupId,$pid]);
            } else {
                $pdo->prepare("
                    INSERT INTO sms_providers
                        (name,sender_match,match_type,parse_pattern,amount_group,phone_group,currency,rate_to_usd,color,icon,region,status,sort_order,transfer_account,account_holder,provider_type,usdt_wallet_address,wa_group_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$name,$sender,$mtype,$pattern,$amtGrp,$phGrp,$curr,$rate,$color,$icon,$region,$status,$order,$transferAccount,$accountHolder,$providerType,$usdtWallet,$waGroupId]);
                $pid = (int)$pdo->lastInsertId();
            }

            // ── رفع شعار المزود (بعد الحصول على $pid الصحيح) ──
            if (!empty($_FILES['provider_logo']['tmp_name'])) {
                $file    = $_FILES['provider_logo'];
                $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
                $maxSize = 2 * 1024 * 1024;
                if (!in_array($file['type'], $allowed)) {
                    $msg = '⚠️ تم حفظ المزود لكن نوع الصورة غير مدعوم (jpg/png/webp/gif)';
                    $msgType = 'warning';
                } elseif ($file['size'] > $maxSize) {
                    $msg = '⚠️ تم حفظ المزود لكن حجم الصورة يتجاوز 2MB';
                    $msgType = 'warning';
                } else {
                    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $logoDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/sms_providers/';
                    if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);

                    // حذف الشعار القديم
                    $oldLogo = $pdo->query("SELECT logo FROM sms_providers WHERE id=$pid")->fetchColumn();
                    if ($oldLogo) {
                        $oldPath = rtrim($_SERVER['DOCUMENT_ROOT'],'/') . '/' . ltrim($oldLogo,'/');
                        if (file_exists($oldPath)) @unlink($oldPath);
                    }

                    // رفع الملف بـ id واضح
                    $fname = 'sms_logo_' . $pid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $logoDir . $fname)) {
                        $logoPath = 'uploads/sms_providers/' . $fname;
                        $pdo->prepare("UPDATE sms_providers SET logo=? WHERE id=?")
                            ->execute([$logoPath, $pid]);
                    }
                }
            }

            if (!isset($msg)) $msg = ($_POST['provider_id'] > 0) ? '✅ تم تحديث المزود' : '✅ تم إضافة المزود';
        }
        $tab = 'providers';
    }

    // ── حذف مزود ──
    if (isset($_POST['delete_provider'])) {
        $pid = (int)$_POST['provider_id'];
        if ($pid > 0) {
            $pdo->prepare("DELETE FROM sms_providers WHERE id=?")->execute([$pid]);
            $msg = '✅ تم حذف المزود';
        }
        $tab = 'providers';
    }

    // ── رفض رسالة ──
    if (isset($_POST['reject_sms'])) {
        $sid  = (int)$_POST['sms_id'];
        $note = trim($_POST['inbox_note'] ?? '');
        $pdo->prepare("UPDATE sms_inbox SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $sid]);
        $msg = '✅ تم رفض الرسالة';
        $tab = 'inbox';
    }

    // ── تجهيز رسالة (pending → ready) مع ملاحظة ──
    if (isset($_POST['ready_sms'])) {
        $sid  = (int)$_POST['sms_id'];
        $note = trim($_POST['inbox_note'] ?? '');
        $pdo->prepare("UPDATE sms_inbox SET status='used', admin_note=? WHERE id=? AND status='pending'")->execute([$note, $sid]);
        $msg = '✅ تم تجهيز الرسالة';
        $tab = 'inbox';
    }

    // ── إعادة فتح رسالة ──
    if (isset($_POST['reopen_sms'])) {
        $sid = (int)$_POST['sms_id'];
        $pdo->prepare("UPDATE sms_inbox SET status='pending', used_by=NULL, used_at=NULL, admin_note=NULL WHERE id=? AND status='rejected'")->execute([$sid]);
        $msg = '✅ تم إعادة فتح الرسالة';
        $tab = 'inbox';
    }

    // ── إكمال طلب (pending → credited) مع ملاحظة ──
    if (isset($_POST['complete_request'])) {
        $rid  = (int)$_POST['req_id'];
        $note = trim($_POST['admin_note'] ?? '');
        $pdo->prepare("UPDATE sms_topup_requests SET status='credited', admin_note=?, completed_at=NOW() WHERE id=?")
            ->execute([$note, $rid]);
        $msg = '✅ تم تحديث الحالة إلى مكتملة';
        $tab = 'requests';
    }

    // ── إلغاء طلب ──
    if (isset($_POST['cancel_request'])) {
        $rid  = (int)$_POST['req_id'];
        $note = trim($_POST['admin_note'] ?? '');
        $pdo->prepare("UPDATE sms_topup_requests SET status='cancelled', admin_note=?, completed_at=NOW() WHERE id=?")
            ->execute([$note, $rid]);
        $msg = '✅ تم إلغاء الطلب';
        $tab = 'requests';
    }
}

// ─── جلب البيانات ──────────────────────────────────
$settings = [];
try {
    $rows = $pdo->query("SELECT setting_key AS `key`, setting_value AS `value` FROM settings WHERE setting_key LIKE 'sms_%'")->fetchAll();
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];
} catch (Exception $e) {}

$providers = [];
try {
    $providers = $pdo->query("SELECT * FROM sms_providers ORDER BY sort_order,id")->fetchAll();
} catch (Exception $e) {}

// إحصائيات Dashboard
$stats = ['total'=>0,'pending'=>0,'used'=>0,'rejected'=>0,'topup_total'=>0,'topup_credited'=>0];
try {
    $st = $pdo->query("SELECT status, COUNT(*) cnt FROM sms_inbox GROUP BY status")->fetchAll();
    foreach ($st as $s) {
        $stats[$s['status']] = (int)$s['cnt'];
        $stats['total'] += (int)$s['cnt'];
    }
    $stats['topup_total']    = (int)$pdo->query("SELECT COUNT(*) FROM sms_topup_requests")->fetchColumn();
    $stats['topup_credited'] = (int)$pdo->query("SELECT COUNT(*) FROM sms_topup_requests WHERE status='credited'")->fetchColumn();
} catch (Exception $e) {}

// قائمة الرسائل — مع فلاتر
$inboxFilterStatus   = $_GET['inbox_status'] ?? '';
$inboxFilterProvider = (int)($_GET['inbox_provider'] ?? 0);
$inboxFilterFrom     = $_GET['inbox_from'] ?? '';
$inboxFilterTo       = $_GET['inbox_to']   ?? '';

$inboxMessages = [];
try {
    $iWhere  = ['1=1'];
    $iParams = [];
    if ($inboxFilterStatus && $inboxFilterStatus !== 'all') {
        $iWhere[]  = 'si.status = ?';
        $iParams[] = $inboxFilterStatus;
    }
    if ($inboxFilterProvider > 0) {
        $iWhere[]  = 'si.provider_id = ?';
        $iParams[] = $inboxFilterProvider;
    }
    if ($inboxFilterFrom) {
        $iWhere[]  = 'si.created_at >= ?';
        $iParams[] = $inboxFilterFrom . ' 00:00:00';
    }
    if ($inboxFilterTo) {
        $iWhere[]  = 'si.created_at <= ?';
        $iParams[] = $inboxFilterTo . ' 23:59:59';
    }
    $iWhereStr = implode(' AND ', $iWhere);
    $iStmt = $pdo->prepare("
        SELECT si.*, sp.name as provider_name, u.username as used_by_user
        FROM sms_inbox si
        LEFT JOIN sms_providers sp ON si.provider_id = sp.id
        LEFT JOIN users u ON si.used_by = u.id
        WHERE $iWhereStr
        ORDER BY si.created_at DESC
        LIMIT 200
    ");
    $iStmt->execute($iParams);
    $inboxMessages = $iStmt->fetchAll();
} catch (Exception $e) {}

// طلبات المستخدمين — مع فلاتر
$reqFilterStatus   = $_GET['req_status']   ?? 'pending';
$reqFilterProvider = (int)($_GET['req_provider'] ?? 0);
$reqFilterFrom     = $_GET['req_from'] ?? '';
$reqFilterTo       = $_GET['req_to']   ?? '';

$topupRequests = [];
try {
    // تحديث الطلبات المنتهية تلقائياً قبل الجلب
    $pdo->exec("UPDATE sms_topup_requests SET status='expired' WHERE status='pending' AND expires_at IS NOT NULL AND expires_at < NOW()");

    $where  = ['1=1'];
    $params = [];
    if ($reqFilterStatus && $reqFilterStatus !== 'all') {
        $where[]  = 'r.status = ?';
        $params[] = $reqFilterStatus;
    }
    if ($reqFilterProvider > 0) {
        $where[]  = 'r.provider_id = ?';
        $params[] = $reqFilterProvider;
    }
    if ($reqFilterFrom) {
        $where[]  = 'r.created_at >= ?';
        $params[] = $reqFilterFrom . ' 00:00:00';
    }
    if ($reqFilterTo) {
        $where[]  = 'r.created_at <= ?';
        $params[] = $reqFilterTo . ' 23:59:59';
    }
    $whereStr = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT r.*, u.username, u.full_name, sp.name as provider_name
        FROM sms_topup_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN sms_providers sp ON r.provider_id = sp.id
        WHERE $whereStr
        ORDER BY r.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $topupRequests = $stmt->fetchAll();
} catch (Exception $e) {}

// سجل الأمان
$secLogs = [];
try {
    $secLogs = $pdo->query("
        SELECT l.*, u.username
        FROM sms_security_log l
        LEFT JOIN users u ON l.user_id = u.id
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (Exception $e) {}

// مزود للتعديل
$editProvider = null;
if (!empty($_GET['edit_provider'])) {
    $editId = (int)$_GET['edit_provider'];
    foreach ($providers as $p) {
        if ((int)$p['id'] === $editId) { $editProvider = $p; break; }
    }
    $tab = 'providers';
}

$webhookUrl = SITE_URL . '/api/sms_webhook.php';
$apiKey     = $settings['sms_api_key'] ?? '';

// جلب مجموعات واتساب للربط
$waGroups = [];
try {
    $waGroups = $pdo->query("SELECT id, group_id, group_name FROM whatsapp_groups WHERE is_active=1 ORDER BY group_name")->fetchAll();
} catch (Exception $e) {}

require_once 'header.php';
?>
<style>
.sms-stat-card{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:18px;text-align:center}
.sms-stat-num{font-size:2rem;font-weight:900;margin-bottom:4px}
.sms-stat-lbl{font-size:.8rem;color:var(--text3)}
.sms-tab-btn{padding:8px 14px;border-radius:10px;border:1.5px solid var(--border);background:none;color:var(--text2);font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;transition:.2s}
.sms-tab-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
.badge-status{padding:3px 9px;border-radius:8px;font-size:.72rem;font-weight:700;display:inline-block}
.badge-pending{background:rgba(245,166,35,.15);color:#f5a623}
.badge-matched{background:rgba(0,200,83,.12);color:#00c853}
.badge-used{background:rgba(0,212,170,.12);color:#00d4aa}
.badge-rejected{background:rgba(255,68,85,.12);color:#ff4455}
.badge-credited{background:rgba(0,200,83,.12);color:#00c853}
.badge-expired{background:rgba(136,149,167,.12);color:#8895a7}
.badge-failed{background:rgba(255,68,85,.12);color:#ff4455}
.sms-inbox-row{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:8px}
.msg-preview{font-family:monospace;font-size:.78rem;background:var(--bg3);padding:8px 10px;border-radius:8px;word-break:break-all;color:var(--text2);margin-top:6px;max-height:60px;overflow:hidden}
.provider-card{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:10px}
.webhook-box{background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:14px;font-family:monospace;font-size:.82rem;word-break:break-all;color:#00d4aa;display:flex;align-items:center;gap:8px}
.key-box{background:var(--bg3);border:1px solid var(--border2);border-radius:12px;padding:10px 14px;font-family:monospace;font-size:.85rem;color:#f5a623;display:flex;align-items:center;gap:8px}
</style>

<div class="admin-content" style="max-width:1100px">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:1.5rem">
    <div style="width:42px;height:42px;background:linear-gradient(135deg,#1e6fff,#7c3aed);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff"><i class="fas fa-sms"></i></div>
    <div>
      <div style="font-size:1.1rem;font-weight:900">نظام شحن الرصيد عبر SMS</div>
      <div style="font-size:.78rem;color:var(--text3)">استقبال وتحليل رسائل SMS تلقائياً</div>
    </div>
    <div style="margin-right:auto;display:flex;align-items:center;gap:10px">
      <?php
      $isEnabled = !empty($settings['sms_topup_enabled']) && $settings['sms_topup_enabled'] === '1';
      ?>
      <!-- بادج الحالة -->
      <span id="smsStatusBadge" class="badge-status <?=$isEnabled?'badge-used':'badge-rejected'?>" style="font-size:.8rem;padding:5px 12px;transition:all .3s">
        <?=$isEnabled ? '🟢 مفعّل' : '🔴 معطّل'?>
      </span>
      <!-- زر التبديل السريع -->
      <button id="smsQuickToggleBtn"
              onclick="smsQuickToggle()"
              title="<?=$isEnabled ? 'إيقاف نظام SMS' : 'تفعيل نظام SMS'?>"
              style="
                display:flex;align-items:center;gap:7px;
                padding:7px 16px;border-radius:10px;border:none;cursor:pointer;
                font-family:var(--font);font-size:.85rem;font-weight:800;
                background:<?=$isEnabled ? 'rgba(255,68,85,.12)' : 'rgba(0,200,83,.12)'?>;
                color:<?=$isEnabled ? '#ff4455' : '#00c853'?>;
                transition:all .25s;white-space:nowrap
              ">
        <i id="smsToggleIcon" class="fas <?=$isEnabled ? 'fa-pause-circle' : 'fa-play-circle'?>"></i>
        <span id="smsToggleTxt"><?=$isEnabled ? 'إيقاف' : 'تفعيل'?></span>
      </button>
    </div>
  </div>

  <?php if($msg): ?>
  <div class="alert alert-<?=$msgType?>" style="margin-bottom:1rem"><?=$msg?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div style="display:flex;gap:8px;margin-bottom:1.5rem;flex-wrap:wrap">
    <button class="sms-tab-btn <?=$tab==='dashboard'?'active':''?>" onclick="switchSmsTab('dashboard')"><i class="fas fa-chart-bar"></i> لوحة القيادة</button>
    <button class="sms-tab-btn <?=$tab==='inbox'?'active':''?>" onclick="switchSmsTab('inbox')"><i class="fas fa-inbox"></i> الرسائل الواردة
      <?php if($stats['pending']>0): ?><span style="background:#f5a623;color:#000;padding:1px 6px;border-radius:8px;font-size:10px;margin-right:3px"><?=$stats['pending']?></span><?php endif; ?>
    </button>
    <button class="sms-tab-btn <?=$tab==='requests'?'active':''?>" onclick="switchSmsTab('requests')"><i class="fas fa-user-check"></i> طلبات المستخدمين</button>
    <button class="sms-tab-btn <?=$tab==='providers'?'active':''?>" onclick="switchSmsTab('providers')"><i class="fas fa-code-branch"></i> المزودون</button>
    <button class="sms-tab-btn <?=$tab==='settings'?'active':''?>" onclick="switchSmsTab('settings')"><i class="fas fa-cog"></i> الإعدادات</button>
    <button class="sms-tab-btn <?=$tab==='logs'?'active':''?>" onclick="switchSmsTab('logs')"><i class="fas fa-shield-alt"></i> سجل الأمان</button>
  </div>

  <!-- ══ Dashboard ══ -->
  <div id="stab-dashboard" class="stab-pane" style="<?=$tab!=='dashboard'?'display:none':''?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:1.5rem">
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#8895a7"><?=$stats['total']?></div><div class="sms-stat-lbl">إجمالي الرسائل</div></div>
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#f5a623"><?=$stats['pending']?></div><div class="sms-stat-lbl">في الانتظار</div></div>
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#00d4aa"><?=$stats['used']?></div><div class="sms-stat-lbl">مستخدمة</div></div>
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#ff4455"><?=$stats['rejected']?></div><div class="sms-stat-lbl">مرفوضة</div></div>
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#7c3aed"><?=$stats['topup_total']?></div><div class="sms-stat-lbl">طلبات المستخدمين</div></div>
      <div class="sms-stat-card"><div class="sms-stat-num" style="color:#00c853"><?=$stats['topup_credited']?></div><div class="sms-stat-lbl">عمليات ناجحة</div></div>
    </div>

    <!-- معلومات الـ Webhook -->
    <div class="card" style="margin-bottom:1rem">
      <div class="card-header"><i class="fas fa-plug" style="color:var(--primary)"></i> إعداد التطبيق (SMS Forwarder)</div>
      <div class="card-body">
        <div style="margin-bottom:12px">
          <div style="font-size:.8rem;color:var(--text3);margin-bottom:6px">Webhook URL</div>
          <div class="webhook-box">
            <i class="fas fa-link" style="color:#8895a7;flex-shrink:0"></i>
            <span id="webhookUrlTxt"><?=htmlspecialchars($webhookUrl)?></span>
            <button onclick="copyText('<?=htmlspecialchars($webhookUrl)?>')" style="margin-right:auto;padding:4px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);cursor:pointer;font-size:.75rem;font-family:var(--font)"><i class="fas fa-copy"></i> نسخ</button>
          </div>
        </div>
        <div style="margin-bottom:12px">
          <div style="font-size:.8rem;color:var(--text3);margin-bottom:6px">API Key (في Header: X-Api-Key)</div>
          <div class="key-box">
            <i class="fas fa-key" style="color:#8895a7;flex-shrink:0"></i>
            <span><?= $apiKey ? '••••••••' . substr($apiKey,-6) : '<em style="color:#ff4455">غير مضبوط — اذهب للإعدادات</em>' ?></span>
            <?php if($apiKey): ?>
            <button onclick="copyText('<?=htmlspecialchars($apiKey)?>')" style="margin-right:auto;padding:4px 10px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);cursor:pointer;font-size:.75rem;font-family:var(--font)"><i class="fas fa-copy"></i> نسخ</button>
            <?php endif; ?>
          </div>
        </div>
        <div style="background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.15);border-radius:10px;padding:12px;font-size:.82rem;line-height:1.7">
          <strong style="color:#00d4aa">JSON Payload Template:</strong><br>
          <code style="font-family:monospace;color:var(--text2)">{"from":"%from%","text":"%text%","sentStamp":%sentStamp%,"receivedStamp":%receivedStamp%,"sim":"%sim%"}</code><br><br>
          <strong style="color:#00d4aa">Headers:</strong>
          <code style="font-family:monospace;color:var(--text2)">{"X-Api-Key":"<?=htmlspecialchars($apiKey ?: 'YOUR_API_KEY')?>"}</code>
        </div>
      </div>
    </div>

    <!-- آخر الرسائل -->
    <div class="card">
      <div class="card-header"><i class="fas fa-clock" style="color:var(--primary)"></i> آخر الرسائل الواردة</div>
      <div class="card-body" style="padding:0">
        <?php if(empty($inboxMessages)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text3)"><i class="fas fa-inbox" style="font-size:2rem;opacity:.2;display:block;margin-bottom:8px"></i>لا توجد رسائل بعد</div>
        <?php else: foreach(array_slice($inboxMessages,0,5) as $sms): ?>
        <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px">
          <div style="font-size:.8rem;flex:1">
            <span class="badge-status badge-<?=$sms['status']?>"><?=htmlspecialchars($sms['status'])?></span>
            <strong style="margin-right:6px"><?=htmlspecialchars($sms['sender'])?></strong>
            — <?=htmlspecialchars(mb_substr($sms['message_text'],0,60))?><?= strlen($sms['message_text'])>60?'...':'' ?>
          </div>
          <?php if($sms['amount']): ?>
          <div style="font-weight:900;color:#00c853;white-space:nowrap"><?=number_format((float)$sms['amount'],0)?> <?=htmlspecialchars($sms['provider_name']??'')?></div>
          <?php endif; ?>
          <div style="font-size:.72rem;color:var(--text3);white-space:nowrap"><?=date('H:i',strtotime($sms['created_at']))?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ Inbox ══ -->
  <div id="stab-inbox" class="stab-pane" style="<?=$tab!=='inbox'?'display:none':''?>">

    <!-- فلاتر البحث -->
    <form method="GET" action="" style="background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px">
      <input type="hidden" name="tab" value="inbox">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;align-items:end">
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">من تاريخ</label>
          <input type="date" name="inbox_from" value="<?=htmlspecialchars($inboxFilterFrom)?>" class="form-control" style="font-size:.82rem">
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">إلى تاريخ</label>
          <input type="date" name="inbox_to" value="<?=htmlspecialchars($inboxFilterTo)?>" class="form-control" style="font-size:.82rem">
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">المزود</label>
          <select name="inbox_provider" class="form-control" style="font-size:.82rem">
            <option value="0">كل المزودين</option>
            <?php foreach($providers as $pv): ?>
            <option value="<?=$pv['id']?>" <?=$inboxFilterProvider==(int)$pv['id']?'selected':''?>><?=htmlspecialchars($pv['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">الحالة</label>
          <select name="inbox_status" class="form-control" style="font-size:.82rem">
            <option value="" <?=$inboxFilterStatus===''?'selected':''?>>الكل</option>
            <option value="pending" <?=$inboxFilterStatus==='pending'?'selected':''?>>⏳ في الانتظار</option>
            <option value="used" <?=$inboxFilterStatus==='used'?'selected':''?>>✅ مستخدمة</option>
            <option value="rejected" <?=$inboxFilterStatus==='rejected'?'selected':''?>>❌ مرفوضة</option>
            <option value="matched" <?=$inboxFilterStatus==='matched'?'selected':''?>>🔗 مطابقة</option>
            <option value="expired" <?=$inboxFilterStatus==='expired'?'selected':''?>>⌛ منتهية</option>
          </select>
        </div>
        <div style="display:flex;gap:6px">
          <button type="submit" class="btn btn-primary" style="flex:1;font-size:.82rem"><i class="fas fa-search"></i> بحث</button>
          <a href="?tab=inbox" class="btn btn-secondary" style="font-size:.82rem;display:flex;align-items:center;padding:8px 10px"><i class="fas fa-times"></i></a>
        </div>
      </div>
    </form>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div style="font-size:.8rem;color:var(--text3)"><?=count($inboxMessages)?> رسالة</div>
      <button id="syncInboxBtn" onclick="syncInboxExpired()" class="btn btn-sm" style="background:rgba(245,166,35,.12);color:#f5a623;border:1px solid rgba(245,166,35,.3);font-size:.78rem;display:flex;align-items:center;gap:6px">
        <i class="fas fa-sync-alt" id="syncInboxIcon"></i> مزامنة المنتهية
      </button>
    </div>

    <?php foreach($inboxMessages as $sms): ?>
    <div class="sms-inbox-row" data-status="<?=$sms['status']?>">
      <div style="display:flex;align-items:flex-start;gap:10px">
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
            <span class="badge-status badge-<?=$sms['status']?>"><?php
              $iLabels=['pending'=>'⏳ انتظار','used'=>'✅ مستخدمة','rejected'=>'❌ مرفوضة','matched'=>'🔗 مطابقة','expired'=>'⌛ منتهية'];
              echo $iLabels[$sms['status']] ?? $sms['status'];
            ?></span>
            <?php if($sms['provider_name']): ?>
            <span style="background:rgba(124,58,237,.15);color:#7c3aed;padding:2px 8px;border-radius:8px;font-size:.72rem;font-weight:700"><?=htmlspecialchars($sms['provider_name'])?></span>
            <?php endif; ?>
            <span style="font-weight:800;font-size:.9rem"><?=htmlspecialchars($sms['sender'])?></span>
            <span style="font-size:.72rem;color:var(--text3);margin-right:auto"><?=date('Y/m/d H:i',strtotime($sms['created_at']))?></span>
          </div>

          <div style="display:flex;gap:16px;font-size:.82rem;margin-bottom:6px;flex-wrap:wrap">
            <?php if($sms['amount']): ?>
            <div><i class="fas fa-money-bill-wave" style="color:#00c853"></i> <strong><?=number_format((float)$sms['amount'],0)?></strong> <?php
              $pr = null; foreach($providers as $p) if((int)$p['id']==(int)$sms['provider_id']){$pr=$p;break;}
              echo htmlspecialchars($pr['currency']??'');
            ?></div>
            <?php endif; ?>
            <?php if($sms['amount_usd']): ?>
            <div><i class="fas fa-dollar-sign" style="color:#f5a623"></i> <strong><?=number_format((float)$sms['amount_usd'],4)?></strong> $</div>
            <?php endif; ?>
            <?php if($sms['phone_number']): ?>
            <div><i class="fas fa-phone" style="color:#1e6fff"></i> <strong><?=htmlspecialchars($sms['phone_number'])?></strong></div>
            <?php endif; ?>
            <?php if($sms['used_by_user']): ?>
            <div><i class="fas fa-user" style="color:#7c3aed"></i> <?=htmlspecialchars($sms['used_by_user'])?></div>
            <?php endif; ?>
          </div>

          <div class="msg-preview"><?=htmlspecialchars($sms['message_text'])?></div>

          <?php if($sms['parse_notes']): ?>
          <div style="font-size:.72rem;color:var(--text3);margin-top:4px"><i class="fas fa-info-circle"></i> <?=htmlspecialchars($sms['parse_notes'])?></div>
          <?php endif; ?>

          <?php if(!empty($sms['admin_note'])): ?>
          <div style="margin-top:8px;background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.2);border-radius:8px;padding:7px 11px;font-size:.78rem;color:var(--text2)">
            <i class="fas fa-sticky-note" style="color:#00d4aa"></i> <strong>ملاحظة:</strong> <?=htmlspecialchars($sms['admin_note'])?>
          </div>
          <?php endif; ?>
        </div>

        <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;min-width:160px">
          <?php if($sms['status']==='pending'): ?>
          <form method="post">
            <input type="hidden" name="sms_id" value="<?=$sms['id']?>">
            <textarea name="inbox_note" placeholder="ملاحظة (اختياري)" rows="2" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:5px 8px;font-family:var(--font);font-size:.76rem;color:var(--text);resize:none;box-sizing:border-box;margin-bottom:4px"></textarea>
            <div style="display:flex;gap:5px">
              <button name="ready_sms" class="btn btn-sm" style="flex:1;background:rgba(0,200,83,.15);color:#00c853;border:1px solid rgba(0,200,83,.3);font-size:.72rem" onclick="return confirm('تجهيز هذه الرسالة؟')"><i class="fas fa-check"></i> تجهيز</button>
              <button name="reject_sms" class="btn btn-sm" style="flex:1;background:rgba(255,68,85,.12);color:#ff4455;border:1px solid rgba(255,68,85,.2);font-size:.72rem" onclick="return confirm('رفض هذه الرسالة؟')"><i class="fas fa-ban"></i> رفض</button>
            </div>
          </form>
          <?php elseif($sms['status']==='rejected'): ?>
          <form method="post">
            <input type="hidden" name="sms_id" value="<?=$sms['id']?>">
            <button name="reopen_sms" class="btn btn-sm btn-warning" style="width:100%"><i class="fas fa-undo"></i> إعادة فتح</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($inboxMessages)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text3)"><i class="fas fa-inbox" style="font-size:3rem;opacity:.15;display:block;margin-bottom:12px"></i>لا توجد رسائل</div>
    <?php endif; ?>
  </div>

  <!-- ══ Requests ══ -->
  <div id="stab-requests" class="stab-pane" style="<?=$tab!=='requests'?'display:none':''?>">

    <!-- فلاتر البحث -->
    <form method="GET" action="" style="background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px">
      <input type="hidden" name="tab" value="requests">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end">
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">من تاريخ</label>
          <input type="date" name="req_from" value="<?=htmlspecialchars($reqFilterFrom)?>" class="form-control" style="font-size:.82rem">
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">إلى تاريخ</label>
          <input type="date" name="req_to" value="<?=htmlspecialchars($reqFilterTo)?>" class="form-control" style="font-size:.82rem">
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">المزود</label>
          <select name="req_provider" class="form-control" style="font-size:.82rem">
            <option value="0">كل المزودين</option>
            <?php foreach($providers as $pv): ?>
            <option value="<?=$pv['id']?>" <?=$reqFilterProvider==(int)$pv['id']?'selected':''?>><?=htmlspecialchars($pv['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:.75rem;color:var(--text3);display:block;margin-bottom:4px">الحالة</label>
          <select name="req_status" class="form-control" style="font-size:.82rem">
            <option value="all" <?=$reqFilterStatus==='all'?'selected':''?>>كل الحالات</option>
            <option value="pending" <?=$reqFilterStatus==='pending'?'selected':''?>>⏳ في الانتظار</option>
            <option value="credited" <?=$reqFilterStatus==='credited'?'selected':''?>>✅ مكتملة</option>
            <option value="cancelled" <?=$reqFilterStatus==='cancelled'?'selected':''?>>❌ ملغية</option>
            <option value="expired" <?=$reqFilterStatus==='expired'?'selected':''?>>⌛ منتهية</option>
          </select>
        </div>
        <div style="display:flex;gap:6px">
          <button type="submit" class="btn btn-primary" style="flex:1;font-size:.82rem"><i class="fas fa-search"></i> بحث</button>
          <a href="?tab=requests" class="btn btn-secondary" style="font-size:.82rem;display:flex;align-items:center;padding:8px 10px"><i class="fas fa-times"></i></a>
        </div>
      </div>
    </form>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div style="font-size:.8rem;color:var(--text3)"><?=count($topupRequests)?> نتيجة</div>
      <button id="syncExpiredBtn" onclick="syncExpired()" class="btn btn-sm" style="background:rgba(245,166,35,.12);color:#f5a623;border:1px solid rgba(245,166,35,.3);font-size:.78rem;display:flex;align-items:center;gap:6px">
        <i class="fas fa-sync-alt" id="syncIcon"></i> مزامنة المنتهية
      </button>
    </div>

    <?php foreach($topupRequests as $req): ?>
    <div style="background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px">
      <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:200px">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">
            <span class="badge-status badge-<?=$req['status']?>"><?php
              $statusLabels=['pending'=>'⏳ انتظار','credited'=>'✅ مكتملة','cancelled'=>'❌ ملغية','expired'=>'⌛ منتهية','failed'=>'⚠️ فاشلة'];
              echo $statusLabels[$req['status']] ?? $req['status'];
            ?></span>
            <strong style="font-size:.95rem"><?=htmlspecialchars($req['full_name']?:$req['username'])?></strong>
            <span style="color:var(--text3);font-size:.75rem">@<?=htmlspecialchars($req['username'])?></span>
          </div>
          <div style="display:flex;gap:14px;font-size:.82rem;flex-wrap:wrap">
            <div><i class="fas fa-phone" style="color:#1e6fff"></i> <code><?=htmlspecialchars($req['phone_number'])?></code></div>
            <div><i class="fas fa-money-bill-wave" style="color:#00c853"></i> <strong><?=number_format((float)$req['amount'],0)?> <?=htmlspecialchars($req['currency'])?></strong></div>
            <?php if($req['amount_usd']): ?><div><i class="fas fa-dollar-sign" style="color:#f5a623"></i> <?=number_format((float)$req['amount_usd'],4)?> $</div><?php endif; ?>
            <div><i class="fas fa-code-branch" style="color:#7c3aed"></i> <?=htmlspecialchars($req['provider_name']??'—')?></div>
            <div style="color:var(--text3)"><i class="fas fa-clock"></i> <?=date('Y/m/d H:i',strtotime($req['created_at']))?></div>
          </div>
          <?php if(!empty($req['admin_note'])): ?>
          <div style="margin-top:8px;background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.2);border-radius:8px;padding:8px 12px;font-size:.8rem;color:var(--text2)">
            <i class="fas fa-sticky-note" style="color:#00d4aa"></i> <strong>ملاحظة:</strong> <?=htmlspecialchars($req['admin_note'])?>
          </div>
          <?php endif; ?>
        </div>
        <?php if($req['status']==='pending'): ?>
        <div style="display:flex;flex-direction:column;gap:6px;min-width:180px">
          <form method="POST" onsubmit="return confirmAction(this,'complete')">
            <input type="hidden" name="req_id" value="<?=$req['id']?>">
            <?php if($reqFilterStatus) echo '<input type="hidden" name="_back_status" value="'.htmlspecialchars($reqFilterStatus).'">'; ?>
            <textarea name="admin_note" placeholder="ملاحظة (لمن ضُفيت عليه...)" rows="2" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:6px 8px;font-family:var(--font);font-size:.78rem;color:var(--text);resize:none;box-sizing:border-box"></textarea>
            <div style="display:flex;gap:6px;margin-top:4px">
              <button name="complete_request" class="btn btn-sm" style="flex:1;background:rgba(0,200,83,.15);color:#00c853;border:1px solid rgba(0,200,83,.3);font-size:.75rem"><i class="fas fa-check"></i> إكمال</button>
              <button name="cancel_request" class="btn btn-sm" style="flex:1;background:rgba(255,68,85,.12);color:#ff4455;border:1px solid rgba(255,68,85,.2);font-size:.75rem"><i class="fas fa-times"></i> إلغاء</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($topupRequests)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text3)"><i class="fas fa-search" style="font-size:3rem;opacity:.15;display:block;margin-bottom:12px"></i>لا توجد نتائج</div>
    <?php endif; ?>
  </div>

  <!-- ══ Providers ══ -->
  <div id="stab-providers" class="stab-pane" style="<?=$tab!=='providers'?'display:none':''?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <strong>المزودون (<?=count($providers)?>)</strong>
      <button class="btn btn-primary btn-sm" onclick="showProviderForm()"><i class="fas fa-plus"></i> إضافة مزود</button>
    </div>

    <?php foreach($providers as $p): ?>
    <div class="provider-card">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:44px;height:44px;border-radius:12px;background:<?=htmlspecialchars($p['color'])?>20;color:<?=htmlspecialchars($p['color'])?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;overflow:hidden">
          <?php if(!empty($p['logo']) && file_exists($_SERVER['DOCUMENT_ROOT']. '/' . ltrim($p['logo'],'/'))): ?>
            <img src="<?=SITE_URL?>/<?=htmlspecialchars($p['logo'])?>" alt="<?=htmlspecialchars($p['name'])?>" style="width:100%;height:100%;object-fit:contain">
          <?php else: ?>
            <i class="fas fa-<?=htmlspecialchars($p['icon'])?>"></i>
          <?php endif; ?>
        </div>
        <div style="flex:1">
          <div style="font-weight:800"><?=htmlspecialchars($p['name'])?></div>
          <div style="font-size:.75rem;color:var(--text3)">مطابقة: <code><?=htmlspecialchars($p['sender_match'])?></code> (<?=$p['match_type']?>) | عملة: <?=$p['currency']?> | سعر: <?=$p['rate_to_usd']?>
            <?php if(!empty($p['wa_group_id'])): ?>
            | <i class="fab fa-whatsapp" style="color:#25d366"></i>
            <?php
              $wgName = '—';
              foreach($waGroups as $wg) { if($wg['group_id']===$p['wa_group_id']){$wgName=$wg['group_name'];break;} }
              echo htmlspecialchars($wgName);
            ?>
            <?php else: ?>
            | <span style="color:#8895a7"><i class="fab fa-whatsapp"></i> بدون إشعار</span>
            <?php endif; ?>
          </div>
          <div style="font-size:.7rem;color:var(--text3);margin-top:2px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:500px"><?=htmlspecialchars($p['parse_pattern'])?></div>
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <?php if(!empty($p['region'])): ?>
          <span style="background:<?=$p['region']==='north'?'rgba(0,200,83,.12)':'rgba(30,111,255,.12)'?>;color:<?=$p['region']==='north'?'#00c853':'#1e6fff'?>;padding:2px 8px;border-radius:8px;font-size:.72rem;font-weight:700">
            <?=$p['region']==='north'?'🟢 شمال':'🔵 جنوب'?>
          </span>
          <?php endif; ?>
          <span style="background:<?=$p['status']?'rgba(0,200,83,.12)':'rgba(255,68,85,.12)'?>;color:<?=$p['status']?'#00c853':'#ff4455'?>;padding:2px 8px;border-radius:8px;font-size:.72rem;font-weight:700"><?=$p['status']?'نشط':'معطّل'?></span>
          <button class="btn btn-sm btn-warning" onclick="editProvider(<?=htmlspecialchars(json_encode($p,JSON_UNESCAPED_UNICODE))?>)"><i class="fas fa-edit"></i></button>
          <form method="post" style="display:inline" onsubmit="return confirm('حذف المزود؟')">
            <input type="hidden" name="provider_id" value="<?=$p['id']?>">
            <button name="delete_provider" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- نموذج إضافة/تعديل مزود -->
    <div id="providerFormBox" style="display:none;margin-top:1.5rem">
      <div class="card">
        <div class="card-header" id="providerFormTitle"><i class="fas fa-plus"></i> إضافة مزود جديد</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="provider_id" id="pf_id" value="0">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
              <div>
                <label class="form-label">اسم المزود *</label>
                <input type="text" name="name" id="pf_name" class="form-control" placeholder="جيب" required>
              </div>
              <div>
                <label class="form-label">اسم المرسل (يطابق به) *</label>
                <input type="text" name="sender_match" id="pf_sender" class="form-control" placeholder="Jaib" required>
              </div>
              <div>
                <label class="form-label">نوع المطابقة</label>
                <select name="match_type" id="pf_match" class="form-control">
                  <option value="contains">contains — يحتوي على</option>
                  <option value="exact">exact — تطابق تام</option>
                  <option value="regex">regex — تعبير نمطي</option>
                </select>
              </div>
              <div>
                <label class="form-label">العملة</label>
                <input type="text" name="currency" id="pf_currency" class="form-control" value="YER" placeholder="YER">
              </div>
              <div>
                <label class="form-label">سعر التحويل لـ USD</label>
                <input type="number" name="rate_to_usd" id="pf_rate" class="form-control" value="0.002" step="0.0000001" min="0.0000001">
              </div>
              <div>
                <label class="form-label">رقم مجموعة المبلغ في Regex</label>
                <input type="number" name="amount_group" id="pf_amtgrp" class="form-control" value="1" min="1">
              </div>
              <div>
                <label class="form-label">رقم مجموعة الهاتف في Regex</label>
                <input type="number" name="phone_group" id="pf_phgrp" class="form-control" value="2" min="1">
              </div>
              <div>
                <label class="form-label">اللون</label>
                <input type="color" name="color" id="pf_color" class="form-control" value="#1e6fff" style="height:40px">
              </div>
              <div>
                <label class="form-label">الأيقونة (FontAwesome) <span style="color:var(--text3);font-size:.72rem">تُستخدم عند غياب الصورة</span></label>
                <input type="text" name="icon" id="pf_icon" class="form-control" value="mobile-alt">
              </div>
              <div>
                <label class="form-label">الترتيب</label>
                <input type="number" name="sort_order" id="pf_order" class="form-control" value="0">
              </div>
              <div>
                <label class="form-label">المنطقة الجغرافية</label>
                <select name="region" id="pf_region" class="form-control">
                  <option value="">— بدون تصنيف —</option>
                  <option value="north">🟢 شمال اليمن</option>
                  <option value="south">🔵 جنوب اليمن</option>
                </select>
              </div>
              <div>
                <label class="form-label">📲 رقم حساب التحويل <span style="color:var(--text3);font-size:.72rem">(يظهر للعميل عند اختيار المزود)</span></label>
                <input type="text" name="transfer_account" id="pf_transfer_account" class="form-control" placeholder="مثال: 770123456" dir="ltr">
              </div>
              <div class="form-group" style="margin-top:12px">
                <label style="font-size:.8rem;color:var(--text2);font-weight:700;margin-bottom:6px;display:block">نوع المزود</label>
                <select name="provider_type" id="pf_provider_type" class="form-control" onchange="toggleUsdtFields()">
                  <option value="sms">📱 SMS عادي</option>
                  <option value="usdt">₿ USDT كريبتو</option>
                </select>
              </div>
              <div class="form-group" id="usdtWalletField" style="display:none;margin-top:12px">
                <label style="font-size:.8rem;color:#f5a623;font-weight:700;margin-bottom:6px;display:block">عنوان محفظة USDT للاستقبال</label>
                <input type="text" name="usdt_wallet_address" id="pf_usdt_wallet" class="form-control" placeholder="0x..." dir="ltr">
                <div style="font-size:.72rem;color:var(--text3);margin-top:4px">هذا العنوان سيظهر للعميل لإرسال USDT إليه</div>
              <div style="display:none"><!-- close trick --></div>
              </div>
              <div>
                <label class="form-label">👤 اسم صاحب الحساب <span style="color:var(--text3);font-size:.72rem">(يظهر للعميل في البوب أب)</span></label>
                <input type="text" name="account_holder" id="pf_account_holder" class="form-control" placeholder="مثال: أحمد محمد">
              </div>
            </div>

            <!-- شعار المزود -->
            <div style="margin-bottom:14px">
              <label class="form-label">شعار / صورة المزود <span style="color:var(--text3);font-size:.72rem">(jpg/png/webp — حتى 2MB — يظهر للعميل بدل الأيقونة)</span></label>
              <div style="display:flex;align-items:center;gap:14px">
                <label for="pf_logo" id="pf_logo_label" style="display:flex;align-items:center;justify-content:center;width:72px;height:72px;border-radius:14px;border:2px dashed var(--border2);cursor:pointer;overflow:hidden;background:var(--bg3);flex-shrink:0;position:relative">
                  <img id="pf_logo_preview" src="" alt="" style="width:100%;height:100%;object-fit:contain;display:none;border-radius:12px">
                  <span id="pf_logo_placeholder"><i class="fas fa-image" style="font-size:1.5rem;color:var(--text3)"></i></span>
                </label>
                <div style="flex:1">
                  <input type="file" name="provider_logo" id="pf_logo" accept="image/*" style="display:none" onchange="previewProviderLogo(this)">
                  <button type="button" onclick="document.getElementById('pf_logo').click()" style="padding:8px 16px;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;color:var(--text2);cursor:pointer;font-family:var(--font);font-size:.82rem;display:flex;align-items:center;gap:6px"><i class="fas fa-upload"></i> اختر صورة</button>
                  <div id="pf_logo_name" style="font-size:.75rem;color:var(--text3);margin-top:5px">لم يتم اختيار ملف</div>
                  <div id="pf_logo_current" style="font-size:.72rem;color:#00c853;margin-top:3px;display:none"><i class="fas fa-check-circle"></i> يوجد شعار محفوظ — اتركه فارغاً للإبقاء عليه</div>
                </div>
              </div>
            </div>
            <div style="margin-bottom:12px">
              <label class="form-label">Regex لاستخراج البيانات <span style="color:var(--text3);font-size:.75rem">(PHP regex مع /u — اتركه فارغاً لإرسال الرسالة كاملة)</span></label>
              <input type="text" name="parse_pattern" id="pf_pattern" class="form-control" style="font-family:monospace" placeholder="اتركه فارغاً لإرسال الرسالة كاملة بدون استخراج">
              <div style="font-size:.75rem;color:var(--text3);margin-top:4px">مجموعة (1) = المبلغ، مجموعة (2) = الرقم (حسب الإعداد أعلاه)</div>
            </div>
            <!-- مجموعة واتساب للإشعارات -->
            <div style="margin-bottom:14px;grid-column:1/-1">
              <label class="form-label">
                <i class="fab fa-whatsapp" style="color:#25d366"></i>
                مجموعة واتساب للإشعارات
                <span style="color:var(--text3);font-size:.72rem;font-weight:400"> — الرسائل الواردة لهذا المزود تُرسل تلقائياً للمجموعة المحددة</span>
              </label>
              <select name="wa_group_id" id="pf_wa_group" class="form-control">
                <option value="">— لا ترسل لأي مجموعة —</option>
                <?php foreach($waGroups as $wg): ?>
                <option value="<?=htmlspecialchars($wg['group_id'])?>">
                  <?=htmlspecialchars($wg['group_name'])?> (<?=htmlspecialchars(substr($wg['group_id'],0,20))?>...)
                </option>
                <?php endforeach; ?>
                <?php if(empty($waGroups)): ?>
                <option disabled>لا توجد مجموعات — أضفها من قسم واتساب أولاً</option>
                <?php endif; ?>
              </select>
              <?php if(empty($waGroups)): ?>
              <div style="font-size:.73rem;color:#f5a623;margin-top:4px">
                <i class="fas fa-exclamation-triangle"></i>
                لا توجد مجموعات واتساب مضافة — <a href="whatsapp.php?tab=groups" style="color:#25d366">اذهب لإضافتها</a>
              </div>
              <?php endif; ?>
            </div>

            <div style="margin-bottom:12px">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="status" id="pf_status" value="1" checked style="width:18px;height:18px;accent-color:var(--primary)">
                <span>نشط</span>
              </label>
            </div>
            <div style="display:flex;gap:8px">
              <button name="save_provider" class="btn btn-primary"><i class="fas fa-save"></i> حفظ</button>
              <button type="button" class="btn btn-secondary" onclick="document.getElementById('providerFormBox').style.display='none'">إلغاء</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Settings ══ -->
  <div id="stab-settings" class="stab-pane" style="<?=$tab!=='settings'?'display:none':''?>">
    <form method="post">
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header"><i class="fas fa-cog"></i> الإعدادات العامة</div>
        <div class="card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <div>
              <label class="form-label">تفعيل نظام SMS</label>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:6px">
                <input type="checkbox" name="sms_topup_enabled" value="1" <?=$isEnabled?'checked':''?> style="width:20px;height:20px;accent-color:var(--primary)">
                <span style="font-weight:700">تفعيل الاستقبال والشحن التلقائي</span>
              </label>
            </div>
            <div>
              <label class="form-label">مفتاح API (أدخل مفتاحاً قوياً)</label>
              <div style="display:flex;gap:6px">
                <input type="text" name="sms_api_key" class="form-control" value="<?=htmlspecialchars($settings['sms_api_key']??'')?>" placeholder="أدخل مفتاحاً عشوائياً طويلاً" id="apiKeyInput">
                <button type="button" class="btn btn-secondary" onclick="generateKey()" style="white-space:nowrap"><i class="fas fa-dice"></i> توليد</button>
              </div>
            </div>
            <div>
              <label class="form-label">معرف الجهاز المصرح (اختياري)</label>
              <input type="text" name="sms_device_id" class="form-control" value="<?=htmlspecialchars($settings['sms_device_id']??'')?>" placeholder="اتركه فارغاً للسماح لأي جهاز">
              <div style="font-size:.75rem;color:var(--text3);margin-top:4px">إذا ملأته، فقط هذا الجهاز مسموح له بالإرسال</div>
            </div>
            <div>
              <label class="form-label">أقصى عمر للرسالة (دقائق)</label>
              <input type="number" name="sms_max_age_minutes" class="form-control" value="<?=htmlspecialchars($settings['sms_max_age_minutes']??60)?>" min="10" max="1440">
              <div style="font-size:.75rem;color:var(--text3);margin-top:4px">رسائل أقدم من هذا الوقت يتم رفضها</div>
            </div>
            <div>
              <label class="form-label">مهلة طلب التحقق (دقائق)</label>
              <input type="number" name="sms_request_ttl" class="form-control" value="<?=htmlspecialchars($settings['sms_request_ttl']??30)?>" min="5" max="60">
              <div style="font-size:.75rem;color:var(--text3);margin-top:4px">بعدها يتم إلغاء طلب المستخدم تلقائياً</div>
            </div>
          </div>
        </div>
      </div>
      <button name="save_settings" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الإعدادات</button>
    </form>
  </div>

  <!-- ══ Logs ══ -->
  <div id="stab-logs" class="stab-pane" style="<?=$tab!=='logs'?'display:none':''?>">
    <table class="table" style="font-size:.78rem">
      <thead><tr><th>النوع</th><th>المستخدم</th><th>IP</th><th>التفاصيل</th><th>نجاح</th><th>التاريخ</th></tr></thead>
      <tbody>
      <?php foreach($secLogs as $log): ?>
      <tr>
        <td><code style="font-size:.72rem"><?=htmlspecialchars($log['event_type'])?></code></td>
        <td><?=$log['username']?htmlspecialchars($log['username']):'—'?></td>
        <td style="font-family:monospace;font-size:.72rem"><?=htmlspecialchars($log['ip_address']??'')?></td>
        <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($log['details']??'')?>"><?=htmlspecialchars(mb_substr($log['details']??'',0,60))?></td>
        <td><span style="color:<?=$log['success']?'#00c853':'#ff4455'?>"><?=$log['success']?'✓':'✗'?></span></td>
        <td style="color:var(--text3)"><?=date('m/d H:i',strtotime($log['created_at']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function switchSmsTab(name) {
  document.querySelectorAll('.stab-pane').forEach(p=>p.style.display='none');
  document.getElementById('stab-'+name).style.display='';
  document.querySelectorAll('.sms-tab-btn').forEach(b=>b.classList.remove('active'));
  event.currentTarget.classList.add('active');
}

function copyText(txt) {
  navigator.clipboard.writeText(txt).then(()=>showToast('تم النسخ ✓','success'));
}

function generateKey() {
  const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let key='';
  for(let i=0;i<48;i++) key+=chars.charAt(Math.floor(Math.random()*chars.length));
  document.getElementById('apiKeyInput').value=key;
}

function filterInbox(status) {
  document.querySelectorAll('.sms-inbox-row').forEach(row=>{
    if(!status || row.dataset.status===status) row.style.display='';
    else row.style.display='none';
  });
}

function toggleUsdtFields() {
  var sel = document.getElementById('pf_provider_type');
  var box = document.getElementById('usdtWalletField');
  if (!sel || !box) return;
  box.style.display = sel.value === 'usdt' ? 'block' : 'none';
}

function confirmAction(form, type) {
  const note = form.querySelector('textarea[name=admin_note]').value.trim();
  const btn  = type === 'complete' ? form.querySelector('[name=complete_request]') : form.querySelector('[name=cancel_request]');
  if (event.submitter !== btn) return true;
  const label = type === 'complete' ? 'إكمال' : 'إلغاء';
  if (!confirm('تأكيد ' + label + ' الطلب؟')) return false;
  return true;
}

function syncInboxExpired() {
  const btn  = document.getElementById('syncInboxBtn');
  const icon = document.getElementById('syncInboxIcon');
  btn.disabled = true;
  icon.className = 'fas fa-sync-alt fa-spin';
  fetch('?sync_inbox_expired=1', { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json())
    .then(d => {
      icon.className = 'fas fa-sync-alt';
      btn.disabled = false;
      if (d.updated > 0) {
        showToast('تم تحديث ' + d.updated + ' رسالة إلى منتهية ✓', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('لا توجد رسائل معلقة منتهية الوقت', 'info');
      }
    })
    .catch(() => { icon.className = 'fas fa-sync-alt'; btn.disabled = false; showToast('خطأ في المزامنة', 'error'); });
}

function syncExpired() {
  const btn  = document.getElementById('syncExpiredBtn');
  const icon = document.getElementById('syncIcon');
  btn.disabled = true;
  icon.className = 'fas fa-sync-alt fa-spin';
  fetch('?sync_expired=1', { method: 'POST', headers: {'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json())
    .then(d => {
      icon.className = 'fas fa-sync-alt';
      btn.disabled = false;
      if (d.updated > 0) {
        showToast('تم تحديث ' + d.updated + ' عملية إلى منتهية ✓', 'success');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('لا توجد عمليات معلقة منتهية الوقت', 'info');
      }
    })
    .catch(() => { icon.className = 'fas fa-sync-alt'; btn.disabled = false; showToast('خطأ في المزامنة', 'error'); });
}

function _resetLogoPreview() {
  document.getElementById('pf_logo_preview').src = '';
  document.getElementById('pf_logo_preview').style.display = 'none';
  document.getElementById('pf_logo_placeholder').style.display = '';
  document.getElementById('pf_logo_name').textContent = 'لم يتم اختيار ملف';
  document.getElementById('pf_logo_current').style.display = 'none';
  // reset file input
  var fi = document.getElementById('pf_logo');
  fi.value = '';
}

function previewProviderLogo(input) {
  var file = input.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = document.getElementById('pf_logo_preview');
    img.src = e.target.result;
    img.style.display = 'block';
    document.getElementById('pf_logo_placeholder').style.display = 'none';
    document.getElementById('pf_logo_name').textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
  };
  reader.readAsDataURL(file);
}

function showProviderForm() {
  document.getElementById('providerFormBox').style.display='';
  document.getElementById('providerFormTitle').innerHTML='<i class="fas fa-plus"></i> إضافة مزود جديد';
  document.getElementById('pf_id').value='0';
  document.getElementById('pf_name').value='';
  document.getElementById('pf_sender').value='';
  document.getElementById('pf_pattern').value='';
  document.getElementById('pf_currency').value='YER';
  document.getElementById('pf_rate').value='0.002';
  document.getElementById('pf_amtgrp').value='1';
  document.getElementById('pf_phgrp').value='2';
  document.getElementById('pf_color').value='#1e6fff';
  document.getElementById('pf_icon').value='mobile-alt';
  document.getElementById('pf_order').value='0';
  document.getElementById('pf_status').checked=true;
  document.getElementById('pf_match').value='contains';
  document.getElementById('pf_region').value='';
  document.getElementById('pf_transfer_account').value='';
  document.getElementById('pf_account_holder').value='';
  document.getElementById('pf_provider_type').value='sms';
  document.getElementById('pf_usdt_wallet').value='';
  document.getElementById('usdtWalletField').style.display='none';
  document.getElementById('pf_wa_group').value='';
  _resetLogoPreview();
  document.getElementById('providerFormBox').scrollIntoView({behavior:'smooth'});
}

function editProvider(p) {
  document.getElementById('providerFormBox').style.display='';
  document.getElementById('providerFormTitle').innerHTML='<i class="fas fa-edit"></i> تعديل المزود';
  document.getElementById('pf_id').value=p.id;
  document.getElementById('pf_name').value=p.name;
  document.getElementById('pf_sender').value=p.sender_match;
  document.getElementById('pf_pattern').value=p.parse_pattern;
  document.getElementById('pf_currency').value=p.currency;
  document.getElementById('pf_rate').value=p.rate_to_usd;
  document.getElementById('pf_amtgrp').value=p.amount_group;
  document.getElementById('pf_phgrp').value=p.phone_group;
  document.getElementById('pf_color').value=p.color;
  document.getElementById('pf_icon').value=p.icon;
  document.getElementById('pf_order').value=p.sort_order;
  document.getElementById('pf_status').checked=p.status=='1';
  document.getElementById('pf_match').value=p.match_type;
  document.getElementById('pf_region').value=p.region||'';
  document.getElementById('pf_transfer_account').value=p.transfer_account||'';
  document.getElementById('pf_account_holder').value=p.account_holder||'';
  document.getElementById('pf_wa_group').value=p.wa_group_id||'';
  // عرض الشعار الحالي إن وجد
  _resetLogoPreview();
  if (p.logo) {
    var img = document.getElementById('pf_logo_preview');
    img.src = '<?=SITE_URL?>/' + p.logo + '?t=' + Date.now();
    img.style.display = 'block';
    document.getElementById('pf_logo_placeholder').style.display = 'none';
    document.getElementById('pf_logo_current').style.display = '';
  }
  document.getElementById('providerFormBox').scrollIntoView({behavior:'smooth'});
}

// ── تبديل سريع لنظام SMS ──
function smsQuickToggle() {
  var btn  = document.getElementById('smsQuickToggleBtn');
  var icon = document.getElementById('smsToggleIcon');
  var txt  = document.getElementById('smsToggleTxt');
  var badge = document.getElementById('smsStatusBadge');

  btn.disabled = true;
  btn.style.opacity = '0.6';

  fetch('sms_topup.php?quick_toggle=1', {
    headers: {'X-Requested-With': 'XMLHttpRequest'}
  })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) { showToast('حدث خطأ، حاول مرة أخرى', 'danger'); return; }

    var on = d.enabled;

    // تحديث البادج
    badge.textContent = on ? '🟢 مفعّل' : '🔴 معطّل';
    badge.className   = 'badge-status ' + (on ? 'badge-used' : 'badge-rejected');
    badge.style       = 'font-size:.8rem;padding:5px 12px;transition:all .3s';

    // تحديث الزر
    btn.style.background = on ? 'rgba(255,68,85,.12)' : 'rgba(0,200,83,.12)';
    btn.style.color      = on ? '#ff4455' : '#00c853';
    btn.title            = on ? 'إيقاف نظام SMS' : 'تفعيل نظام SMS';
    icon.className       = 'fas ' + (on ? 'fa-pause-circle' : 'fa-play-circle');
    txt.textContent      = on ? 'إيقاف' : 'تفعيل';

    showToast(on ? '✅ تم تفعيل نظام SMS — سيظهر للعملاء' : '⛔ تم إيقاف نظام SMS — اختفى من واجهة العملاء', on ? 'success' : 'warning');
  })
  .catch(() => showToast('تعذّر الاتصال بالخادم', 'danger'))
  .finally(() => { btn.disabled = false; btn.style.opacity = '1'; });
}
</script>

<?php require_once 'footer.php'; ?>
