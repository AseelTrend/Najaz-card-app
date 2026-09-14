<?php
require_once 'includes/config.php';
requireLogin();

$user = getUser();
if (!($user['api_enabled'] ?? 0)) {
    flashMessage('danger', 'ليس لديك صلاحية الوصول لـ API.');
    redirect(SITE_URL . '/mobile.php');
}

$siteName   = getSetting('site_name') ?: SITE_NAME;
$currSymbol = getSetting('currency_symbol') ?: '$';
$userId     = (int)$_SESSION['user_id'];
$myIp = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);

if (empty($user['api_key'])) {
    $token = bin2hex(random_bytes(24));
    $pdo->prepare("UPDATE users SET api_key=? WHERE id=?")->execute([$token, $userId]);
    $user['api_key'] = $token;
}

$allowedIps  = [];
$allowAllIps = false;
try {
    $ipRow = $pdo->prepare("SELECT api_ips, api_allow_all FROM users WHERE id=?");
    $ipRow->execute([$userId]);
    $ipRow = $ipRow->fetch();
    $allowedIps  = json_decode($ipRow['api_ips'] ?? '[]', true) ?: [];
    $allowAllIps = (bool)($ipRow['api_allow_all'] ?? false);
} catch (\PDOException $e) {}

$msg = ''; $msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_ip') {
        $newIp = trim($_POST['ip'] ?? '') ?: $myIp;
        if (filter_var($newIp, FILTER_VALIDATE_IP) || preg_match('/^[\d\.\/\*]+$/', $newIp)) {
            if (!in_array($newIp, $allowedIps)) {
                $allowedIps[] = $newIp;
                $pdo->prepare("UPDATE users SET api_ips=? WHERE id=?")->execute([json_encode($allowedIps), $userId]);
                $msg = "تمت إضافة IP: $newIp"; $msgType = 'success';
            } else { $msg = 'هذا الـ IP موجود مسبقاً'; $msgType = 'warning'; }
        } else { $msg = 'صيغة IP غير صحيحة'; $msgType = 'danger'; }
    }
    if ($action === 'remove_ip') {
        $rmIp = $_POST['ip'] ?? '';
        $allowedIps = array_values(array_filter($allowedIps, fn($ip) => $ip !== $rmIp));
        $pdo->prepare("UPDATE users SET api_ips=? WHERE id=?")->execute([json_encode($allowedIps), $userId]);
        $msg = "تم حذف IP: $rmIp"; $msgType = 'info';
    }
    if ($action === 'toggle_allow_all') {
        $allowAllIps = !$allowAllIps;
        $pdo->prepare("UPDATE users SET api_allow_all=? WHERE id=?")->execute([(int)$allowAllIps, $userId]);
        $msg = $allowAllIps ? 'تم السماح لكل الـ IPs' : 'تم تقييد الوصول بالـ IPs';
        $msgType = $allowAllIps ? 'warning' : 'success';
    }
    if ($action === 'regenerate_token') {
        $newToken = bin2hex(random_bytes(24));
        $pdo->prepare("UPDATE users SET api_key=? WHERE id=?")->execute([$newToken, $userId]);
        $user['api_key'] = $newToken;
        $msg = 'تم إنشاء Token جديد. احفظه!'; $msgType = 'success';
    }
}

$totalOrders = 0; $monthOrders = 0;
try {
    $totalOrders = (int)$pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?")->execute([$userId]) ? $pdo->query("SELECT COUNT(*) FROM orders WHERE user_id=$userId")->fetchColumn() : 0;
    $s1 = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?"); $s1->execute([$userId]); $totalOrders = (int)$s1->fetchColumn();
    $s2 = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"); $s2->execute([$userId]); $monthOrders = (int)$s2->fetchColumn();
} catch (\PDOException $e) {}

$apiBaseUrl = SITE_URL . '/api';
$token = htmlspecialchars($user['api_key']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#080c1a">
<title><?= htmlspecialchars($siteName) ?> — API</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;
  --border:rgba(255,255,255,0.07);--primary:#1e6fff;--primary2:#0d4fd4;
  --cyan:#00d4ff;--gold:#f5a623;--green:#00e676;--red:#ff1744;--purple:#7c3aed;
  --text:#ffffff;--text2:#8fa3bf;--text3:#4d6080;
  --radius:18px;--radius-sm:12px;--font:'Cairo',sans-serif;--mono:'JetBrains Mono',monospace;
  --nav-h:64px;--header-h:60px;
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{height:100%;overflow:hidden;background:var(--bg);font-family:var(--font);color:var(--text);direction:rtl}
#app{width:100%;max-width:430px;height:100dvh;margin:0 auto;display:flex;flex-direction:column;background:var(--bg);box-shadow:0 0 80px rgba(0,0,0,.8);overflow:hidden}
#mainContent{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch}
#mainContent::-webkit-scrollbar{display:none}
.top-header{height:var(--header-h);background:linear-gradient(135deg,#0d1428,#0a1535);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;flex-shrink:0}
.logo-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--purple),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 12px rgba(124,58,237,.4)}
.logo-text{font-size:18px;font-weight:900;background:linear-gradient(90deg,var(--purple),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.back-btn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:16px;border:none;font-family:var(--font)}
.bottom-nav{height:var(--nav-h);background:linear-gradient(180deg,rgba(13,20,40,.97),#080c1a);border-top:1px solid var(--border);display:flex;align-items:center;flex-shrink:0}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;padding:8px 0;transition:all .2s;position:relative}
.nav-item:active{transform:scale(.9)}
.nav-icon{font-size:20px;color:var(--text3)}
.nav-label{font-size:10px;color:var(--text3);font-weight:600}
.nav-item.active .nav-icon,.nav-item.active .nav-label{color:var(--purple)}
.nav-item.active::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:30px;height:2px;border-radius:0 0 3px 3px;background:var(--purple)}
.nav-center{flex:1;display:flex;justify-content:center}
.nav-center-btn{width:52px;height:52px;background:linear-gradient(135deg,var(--purple),#0a3fbe);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;cursor:pointer;box-shadow:0 4px 16px rgba(124,58,237,.5);margin-top:-14px;border:3px solid var(--bg)}
.api-wrap{padding:14px 12px 24px}
.token-card{background:linear-gradient(135deg,rgba(124,58,237,.18),rgba(0,212,255,.07));border:1px solid rgba(124,58,237,.25);border-radius:var(--radius);padding:20px;margin-bottom:14px;position:relative;overflow:hidden}
.token-card::before{content:'';position:absolute;top:-60px;left:-60px;width:180px;height:180px;background:radial-gradient(circle,rgba(124,58,237,.15),transparent 70%);pointer-events:none}
.token-label{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:8px}
.token-value{font-family:var(--mono);font-size:11.5px;background:rgba(0,0,0,.45);border:1px solid rgba(124,58,237,.18);border-radius:10px;padding:12px 14px;word-break:break-all;color:var(--cyan);line-height:1.7;direction:ltr}
.token-actions{display:flex;gap:8px;margin-top:12px}
.token-btn{flex:1;padding:10px;border-radius:10px;border:none;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s}
.token-btn.copy{background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.18);color:var(--cyan)}
.token-btn.regen{background:rgba(255,23,68,.08);border:1px solid rgba(255,23,68,.18);color:#ff6b8a}
.stats-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center}
.stat-num{font-size:28px;font-weight:900;color:var(--purple)}
.stat-lbl{font-size:11px;color:var(--text3);margin-top:2px}
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sec-title{font-size:14px;font-weight:800;display:flex;align-items:center;gap:6px}
.sec-title i{color:var(--purple)}
.ip-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:14px}
.ip-allow-all{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);cursor:pointer}
.toggle{width:44px;height:24px;background:var(--card2);border-radius:12px;border:2px solid var(--border);position:relative;flex-shrink:0;transition:all .25s}
.toggle.on{background:linear-gradient(135deg,var(--purple),var(--cyan));border-color:transparent;box-shadow:0 0 12px rgba(124,58,237,.4)}
.toggle::after{content:'';position:absolute;width:16px;height:16px;background:#fff;border-radius:50%;top:2px;right:2px;transition:transform .25s;box-shadow:0 1px 4px rgba(0,0,0,.3)}
.toggle.on::after{transform:translateX(-20px)}
.allow-all-warning{background:rgba(255,68,85,.06);border-top:1px solid rgba(255,68,85,.15);padding:10px 14px;font-size:11px;color:#ff8895;display:none;gap:8px}
.allow-all-warning.show{display:flex}
.ip-list{padding:4px 0}
.ip-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.03)}
.ip-item:last-child{border:none}
.ip-badge{font-family:var(--mono);font-size:12px;background:rgba(0,212,255,.07);border:1px solid rgba(0,212,255,.12);border-radius:8px;padding:5px 10px;color:var(--cyan);flex:1;direction:ltr}
.ip-del{width:30px;height:30px;background:rgba(255,23,68,.08);border:1px solid rgba(255,23,68,.12);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#ff4455;font-size:12px}
.ip-empty{padding:20px;text-align:center;color:var(--text3);font-size:12px}
.my-ip-strip{background:rgba(0,212,255,.04);border:1px solid rgba(0,212,255,.1);border-radius:10px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.my-ip-lbl{font-size:11px;color:var(--text3)}
.my-ip-val{font-family:var(--mono);font-size:13px;color:var(--cyan);direction:ltr}
.add-ip-form{display:flex;gap:8px;padding:10px 12px}
.add-ip-input{flex:1;background:var(--card2);border:1.5px solid var(--border);border-radius:10px;padding:10px 12px;color:var(--text);font-family:var(--mono);font-size:12px;outline:none;direction:ltr}
.add-ip-input:focus{border-color:rgba(124,58,237,.5)}
.add-ip-btn{background:linear-gradient(135deg,var(--purple),var(--primary));border:none;border-radius:10px;padding:10px 16px;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}
.docs-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:14px}
.doc-ep{border-bottom:1px solid var(--border)}
.doc-ep:last-child{border:none}
.doc-ep-head{display:flex;align-items:center;gap:8px;padding:14px 16px;cursor:pointer}
.method-badge{font-size:10px;font-weight:900;padding:3px 8px;border-radius:6px;font-family:var(--mono)}
.method-get{background:rgba(0,230,118,.12);color:var(--green)}
.method-post{background:rgba(30,111,255,.12);color:var(--primary)}
.ep-name{font-size:13px;font-weight:700;flex:1}
.ep-toggle-icon{color:var(--text3);font-size:12px;transition:transform .2s}
.doc-ep-body{display:none;padding:0 16px 14px}
.doc-ep-body.open{display:block}
.ep-url{font-family:var(--mono);font-size:10px;color:var(--text3);margin-bottom:8px;direction:ltr}
.ep-desc{font-size:12px;color:var(--text2);line-height:1.6;margin-bottom:6px}
.code-block{background:rgba(0,0,0,.5);border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:12px;font-family:var(--mono);font-size:11px;color:#a8ff78;line-height:1.8;overflow-x:auto;direction:ltr;text-align:left;white-space:pre}
.api-msg{padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:14px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.api-msg.success{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.2);color:var(--green)}
.api-msg.danger{background:rgba(255,23,68,.08);border:1px solid rgba(255,23,68,.2);color:#ff6b8a}
.api-msg.warning{background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);color:var(--gold)}
.api-msg.info{background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.2);color:var(--cyan)}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;max-width:430px;margin:0 auto}
.modal-overlay.show{display:flex}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:24px 20px;margin:16px;width:100%}
</style>
</head>
<body>
<div id="app">
  <div class="top-header">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="back-btn" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'"><i class="fas fa-arrow-right"></i></button>
      <div class="logo-icon">⚡</div>
      <div class="logo-text">API</div>
    </div>
    <div style="font-size:11px;color:#00e676;background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.2);padding:4px 10px;border-radius:8px;font-weight:700">مُفعَّل ✓</div>
  </div>

  <div id="mainContent">
    <div class="api-wrap">

      <?php if ($msg): ?>
      <div class="api-msg <?= $msgType ?>">
        <i class="fas fa-<?= $msgType==="success"?"check-circle":($msgType==="danger"?"times-circle":($msgType==="warning"?"exclamation-triangle":"info-circle")) ?>"></i>
        <?= htmlspecialchars($msg) ?>
      </div>
      <?php endif; ?>

      <div class="sec-hd" style="margin-bottom:8px">
        <div class="sec-title"><i class="fas fa-key"></i> API TOKEN</div>
      </div>
      <div class="token-card">
        <div class="token-label">مفتاح الوصول الخاص بك</div>
        <div class="token-value" id="tokenVal"><?= $token ?></div>
        <div class="token-actions">
          <button class="token-btn copy" onclick="copyToken()" id="copyBtn"><i class="fas fa-copy"></i> نسخ التوكن</button>
          <button class="token-btn regen" onclick="document.getElementById('regenModal').classList.add('show')"><i class="fas fa-redo"></i> توليد جديد</button>
        </div>
      </div>

      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-num"><?= $totalOrders ?></div>
          <div class="stat-lbl">إجمالي الطلبات</div>
        </div>
        <div class="stat-card">
          <div class="stat-num"><?= $monthOrders ?></div>
          <div class="stat-lbl">طلبات هذا الشهر</div>
        </div>
      </div>

      <div class="sec-hd"><div class="sec-title"><i class="fas fa-shield-alt"></i> الـ IPs المسموحة</div></div>
      <div class="ip-card">
        <form method="POST" id="toggleAllForm">
          <input type="hidden" name="action" value="toggle_allow_all">
          <div class="ip-allow-all" onclick="document.getElementById('toggleAllForm').submit()">
            <div>
              <div style="font-size:13px;font-weight:700">السماح لكل الـ IPs</div>
              <div style="font-size:11px;color:var(--text3);margin-top:2px">للاختبار فقط — غير آمن في الإنتاج</div>
            </div>
            <div class="toggle <?= $allowAllIps ? 'on' : '' ?>"></div>
          </div>
        </form>
        <div class="allow-all-warning <?= $allowAllIps ? 'show' : '' ?>">
          <i class="fas fa-exclamation-triangle" style="margin-top:1px;flex-shrink:0"></i>
          <div>تحذير: كل الـ IPs مسموح لها. للاختبار فقط — أوقفه في الإنتاج لحماية حسابك.</div>
        </div>
        <div style="padding:10px 12px 4px">
          <div class="my-ip-strip">
            <div>
              <div class="my-ip-lbl">IP جهازك الحالي</div>
              <div class="my-ip-val"><?= htmlspecialchars($myIp) ?></div>
            </div>
            <form method="POST" style="margin:0">
              <input type="hidden" name="action" value="add_ip">
              <input type="hidden" name="ip" value="<?= htmlspecialchars($myIp) ?>">
              <button type="submit" style="background:rgba(0,212,255,.12);border:1px solid rgba(0,212,255,.2);border-radius:8px;padding:7px 12px;color:var(--cyan);font-family:var(--font);font-size:11px;font-weight:700;cursor:pointer"><i class="fas fa-plus"></i> أضفه</button>
            </form>
          </div>
        </div>
        <div class="ip-list">
          <?php if (empty($allowedIps)): ?>
          <div class="ip-empty"><i class="fas fa-shield-alt" style="display:block;font-size:24px;margin-bottom:6px;opacity:.2"></i>لا توجد IPs محددة بعد</div>
          <?php else: ?>
          <?php foreach ($allowedIps as $ip): ?>
          <div class="ip-item">
            <div class="ip-badge"><?= htmlspecialchars($ip) ?></div>
            <form method="POST" style="margin:0">
              <input type="hidden" name="action" value="remove_ip">
              <input type="hidden" name="ip" value="<?= htmlspecialchars($ip) ?>">
              <button type="submit" class="ip-del"><i class="fas fa-times"></i></button>
            </form>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <form method="POST" class="add-ip-form">
          <input type="hidden" name="action" value="add_ip">
          <input type="text" name="ip" class="add-ip-input" placeholder="أدخل IP يدوياً" dir="ltr">
          <button type="submit" class="add-ip-btn"><i class="fas fa-plus"></i> أضف</button>
        </form>
      </div>

      <div class="sec-hd">
        <div class="sec-title"><i class="fas fa-book"></i> توثيق الـ API</div>
        <div style="font-size:10px;color:var(--text3);background:var(--card2);padding:3px 8px;border-radius:8px">v1.0</div>
      </div>

      <div style="background:rgba(124,58,237,.08);border:1px solid rgba(124,58,237,.2);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:12px;direction:ltr">
        <div style="font-size:10px;color:var(--text3);margin-bottom:4px;direction:rtl">رابط الـ API الأساسي</div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
          <span style="font-family:var(--mono);font-size:12px;color:var(--purple);word-break:break-all"><?= htmlspecialchars($apiBaseUrl) ?></span>
          <button onclick="copyText('<?= addslashes(htmlspecialchars($apiBaseUrl)) ?>',this)" style="background:rgba(124,58,237,.15);border:1px solid rgba(124,58,237,.2);border-radius:6px;padding:4px 10px;color:var(--purple);font-size:11px;font-weight:700;cursor:pointer;font-family:var(--font);flex-shrink:0">نسخ</button>
        </div>
      </div>

      <div class="docs-card">
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <div style="width:8px;height:8px;border-radius:50%;background:var(--gold);flex-shrink:0"></div>
            <div class="ep-name" style="color:var(--gold)">المصادقة</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="ep-desc">أرسل الـ Token في كل طلب عبر Header أو URL Parameter</div>
            <div class="code-block">// عبر Header (موصى به)
Authorization: Bearer YOUR_TOKEN

// أو عبر Query Parameter
GET /api/services?token=YOUR_TOKEN</div>
          </div>
        </div>
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <span class="method-badge method-get">GET</span>
            <div class="ep-name">قائمة الخدمات</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="ep-url"><?= htmlspecialchars($apiBaseUrl) ?>/services</div>
            <div class="ep-desc">جلب كل الخدمات المتاحة مع أسعارها وحقولها المطلوبة</div>
            <div class="code-block">{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name": "شحن فورت نايت",
      "price": "5.00",
      "min_qty": 1,
      "max_qty": 100,
      "fields": [
        { "name": "player_id", "label": "ID اللاعب", "required": true }
      ]
    }
  ]
}</div>
          </div>
        </div>
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <span class="method-badge method-post">POST</span>
            <div class="ep-name">إنشاء طلب</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="ep-url"><?= htmlspecialchars($apiBaseUrl) ?>/order</div>
            <div class="ep-desc">إنشاء طلب جديد — يُخصم من رصيد المحفظة تلقائياً</div>
            <div class="code-block">// Body (JSON)
{
  "service_id": 1,
  "quantity": 1,
  "fields": {
    "player_id": "123456789"
  }
}

// رد النجاح
{
  "status": "success",
  "order_id": 42,
  "message": "تم إنشاء الطلب"
}</div>
          </div>
        </div>
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <span class="method-badge method-get">GET</span>
            <div class="ep-name">حالة الطلب</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="ep-url"><?= htmlspecialchars($apiBaseUrl) ?>/order/{id}</div>
            <div class="ep-desc">جلب تفاصيل وحالة طلب محدد</div>
            <div class="code-block">{
  "status": "success",
  "data": {
    "id": 42,
    "status": "completed",
    "service": "شحن فورت نايت",
    "total_price": "5.00",
    "created_at": "2026-01-01 12:00:00"
  }
}</div>
          </div>
        </div>
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <span class="method-badge method-get">GET</span>
            <div class="ep-name">رصيد المحفظة</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="ep-url"><?= htmlspecialchars($apiBaseUrl) ?>/balance</div>
            <div class="ep-desc">جلب الرصيد الحالي للحساب</div>
            <div class="code-block">{
  "status": "success",
  "balance": "150.00",
  "currency": "<?= htmlspecialchars($currSymbol) ?>"
}</div>
          </div>
        </div>
        <div class="doc-ep">
          <div class="doc-ep-head" onclick="toggleEp(this)">
            <div style="width:8px;height:8px;border-radius:50%;background:var(--red);flex-shrink:0"></div>
            <div class="ep-name" style="color:#ff8895">أكواد الأخطاء</div>
            <i class="fas fa-chevron-left ep-toggle-icon"></i>
          </div>
          <div class="doc-ep-body">
            <div class="code-block">401 — Token غير صحيح أو مفقود
403 — IP غير مسموح له
402 — رصيد غير كافٍ
404 — الخدمة أو الطلب غير موجود
422 — بيانات مفقودة أو غير صحيحة
429 — تجاوزت حد الطلبات
500 — خطأ داخلي في السيرفر</div>
          </div>
        </div>
      </div>

      <a href="<?= SITE_URL ?>/api-docs" target="_blank" style="display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,rgba(124,58,237,.1),rgba(0,212,255,.05));border:1px solid rgba(124,58,237,.2);border-radius:var(--radius-sm);padding:14px 16px;text-decoration:none;margin-bottom:14px">
        <div style="width:36px;height:36px;background:rgba(124,58,237,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--purple)"><i class="fas fa-external-link-alt"></i></div>
        <div>
          <div style="font-size:13px;font-weight:800;color:var(--text)">التوثيق التفاعلي الكامل</div>
          <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars(SITE_URL) ?>/api-docs</div>
        </div>
        <i class="fas fa-chevron-left" style="margin-right:auto;color:var(--text3);font-size:12px"></i>
      </a>

      <div style="height:10px"></div>
    </div>
  </div>

  <div class="bottom-nav">
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'">
      <div class="nav-icon"><i class="fas fa-home"></i></div><div class="nav-label">الرئيسية</div>
    </div>
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/wallet.php'">
      <div class="nav-icon"><i class="fas fa-wallet"></i></div><div class="nav-label">محفظتي</div>
    </div>
    <div class="nav-center">
      <div class="nav-center-btn" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'">
        <i class="fas fa-th-large"></i>
      </div>
    </div>
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/orders.php'">
      <div class="nav-icon"><i class="fas fa-shopping-bag"></i></div><div class="nav-label">طلباتي</div>
    </div>
    <div class="nav-item active">
      <div class="nav-icon"><i class="fas fa-code"></i></div><div class="nav-label">API</div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="regenModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="modal-box">
    <div style="font-size:32px;text-align:center;margin-bottom:10px">⚠️</div>
    <div style="font-size:15px;font-weight:900;text-align:center;margin-bottom:8px">توليد Token جديد؟</div>
    <div style="font-size:12px;color:var(--text2);text-align:center;margin-bottom:20px;line-height:1.6">سيتم إلغاء الـ Token الحالي ولن تعمل أي تطبيقات تستخدمه. هل أنت متأكد؟</div>
    <div style="display:flex;gap:10px">
      <button onclick="document.getElementById('regenModal').classList.remove('show')" style="flex:1;padding:12px;background:var(--card2);border:1px solid var(--border);border-radius:10px;color:var(--text2);font-family:var(--font);font-weight:700;cursor:pointer">إلغاء</button>
      <form method="POST" style="flex:1">
        <input type="hidden" name="action" value="regenerate_token">
        <button type="submit" style="width:100%;padding:12px;background:linear-gradient(135deg,#ff1744,#c62828);border:none;border-radius:10px;color:#fff;font-family:var(--font);font-weight:700;cursor:pointer">نعم، أنشئ جديداً</button>
      </form>
    </div>
  </div>
</div>

<script>
function copyToken() {
  const val = document.getElementById('tokenVal').textContent.trim();
  navigator.clipboard.writeText(val).then(() => {
    const btn = document.getElementById('copyBtn');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ!';
    btn.style.cssText += 'background:rgba(0,230,118,.15);color:#00e676;';
    setTimeout(() => { btn.innerHTML = orig; btn.style.background = ''; btn.style.color = ''; }, 2000);
  });
}
function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    if (btn) { const o = btn.textContent; btn.textContent = '✓'; setTimeout(() => btn.textContent = o, 1500); }
  });
}
function toggleEp(headEl) {
  const body = headEl.nextElementSibling;
  if (!body) return;
  const open = body.classList.toggle('open');
  const icon = headEl.querySelector('.ep-toggle-icon');
  if (icon) icon.style.transform = open ? 'rotate(-90deg)' : '';
}
</script>
</body>
</html>