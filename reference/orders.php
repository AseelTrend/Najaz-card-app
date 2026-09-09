<?php
require_once 'includes/config.php';
require_once 'includes/code_stock_helper.php';
try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS ref_id VARCHAR(32) DEFAULT NULL"); } catch(Exception $e) {}
requireLogin();

// ── Proxy للـ NumbersApp API (يتجاوز Cloudflare) ──────────────────────────
if (isset($_GET['na_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    // تمرير الطلب لـ numbersapp_live.php داخلياً
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    ob_start();
    include __DIR__ . '/api/numbersapp_live.php';
    $output = ob_get_clean();
    echo $output;
    exit;
}

$siteName  = getSetting('site_name') ?: SITE_NAME;
$pageTitle = 'طلباتي — ' . $siteName;

$status  = $_GET['status'] ?? '';
$orderId = (int)($_GET['order'] ?? 0);
$dateFrom = trim($_GET['from'] ?? '');
$dateTo   = trim($_GET['to']   ?? '');
// الافتراضي: اليوم فقط
$isToday  = (!$dateFrom && !$dateTo);
if ($isToday) {
    $dateFrom = date('Y-m-d');
    $dateTo   = date('Y-m-d');
}

// ── جلب الطلبات ───────────────────────────────────────────────────────────────
$where  = $status ? "AND o.status=?" : "";
$params = [$_SESSION['user_id']];
if ($status) $params[] = $status;

// فلتر التاريخ
$where  .= " AND DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?";
$params[] = $dateFrom;
$params[] = $dateTo;

$stmt = $pdo->prepare("
    SELECT o.*, s.name as service_name, s.image as service_image,
           s.service_type,
           c.name as cat_name, c.icon as cat_icon
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.user_id=? $where
    ORDER BY o.created_at DESC LIMIT 200
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// إحصاءات الفترة المحددة
$periodStats = ['total'=>count($orders),'completed'=>0,'processing'=>0,'cancelled'=>0,'total_spent'=>0];
foreach($orders as $o) {
    if ($o['status']==='completed')  { $periodStats['completed']++; $periodStats['total_spent']+=$o['total_price']; }
    if (in_array($o['status'],['processing','pending'])) $periodStats['processing']++;
    if (in_array($o['status'],['cancelled','failed'])) $periodStats['cancelled']++;
}

// ── تفاصيل طلب محدد ──────────────────────────────────────────────────────────
$selectedOrder = null;
$statusLog     = [];
if ($orderId) {
    $s = $pdo->prepare("
        SELECT o.*, s.name as service_name, s.image as service_image,
               s.service_type,
               c.name as cat_name, c.icon as cat_icon
        FROM orders o
        JOIN services s ON o.service_id=s.id
        JOIN categories c ON s.category_id=c.id
        WHERE o.id=? AND o.user_id=?
    ");
    $s->execute([$orderId, $_SESSION['user_id']]);
    $selectedOrder = $s->fetch();
    if ($selectedOrder) {
        try {
            $sl = $pdo->prepare("SELECT * FROM order_status_log WHERE order_id=? ORDER BY created_at ASC");
            $sl->execute([$orderId]);
            $statusLog = $sl->fetchAll();
        } catch (\PDOException $e) { $statusLog = []; }
    }
}

$userBalance = 0;
$u = $pdo->prepare("SELECT balance FROM users WHERE id=?");
$u->execute([$_SESSION['user_id']]); $u=$u->fetch();
$userBalance = $u['balance'] ?? 0;

// ── حساب مدة الاستجابة للطلب المحدد ────────────────────────────────────────
$responseSeconds = null;
if ($selectedOrder && $selectedOrder['status'] === 'completed') {
    foreach ($statusLog as $lg) {
        if ($lg['status'] === 'completed') {
            $s = strtotime($selectedOrder['created_at']);
            $e = strtotime($lg['created_at']);
            if ($s && $e && $e >= $s) $responseSeconds = $e - $s;
            break;
        }
    }
    if ($responseSeconds === null && !empty($selectedOrder['updated_at'])) {
        $s = strtotime($selectedOrder['created_at']);
        $e = strtotime($selectedOrder['updated_at']);
        if ($s && $e && $e > $s) $responseSeconds = $e - $s;
    }
}

// ── هل يوجد اعتراض مسبق؟ ────────────────────────────────────────────────────
$existingObjection = null;
if ($selectedOrder) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `order_objections` (
          `id`         INT AUTO_INCREMENT PRIMARY KEY,
          `order_id`   INT NOT NULL,
          `user_id`    INT NOT NULL,
          `reason`     TEXT NOT NULL,
          `status`     ENUM('pending','reviewing','resolved','rejected') DEFAULT 'pending',
          `admin_note` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_order` (`order_id`), KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ob = $pdo->prepare("SELECT id,status,reason,admin_note FROM order_objections WHERE order_id=? AND user_id=? LIMIT 1");
        $ob->execute([$orderId, $_SESSION['user_id']]);
        $existingObjection = $ob->fetch() ?: null;
    } catch (\PDOException $e) {}
}

// ── معالجة تقديم الاعتراض (POST) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_objection']) && $selectedOrder) {
    $reason = trim($_POST['reason'] ?? '');
    $resp = ['ok' => false, 'error' => ''];
    if (mb_strlen($reason) < 5)                          $resp['error'] = 'الرجاء كتابة سبب الاعتراض (5 أحرف على الأقل)';
    elseif ($existingObjection)                          $resp['error'] = 'لديك اعتراض مقدم مسبقاً على هذا الطلب';
    elseif ($selectedOrder['status'] !== 'completed')    $resp['error'] = 'الاعتراض متاح فقط للطلبات المكتملة';
    else {
        $pdo->prepare("INSERT INTO order_objections (order_id,user_id,reason) VALUES (?,?,?)")
            ->execute([$orderId, $_SESSION['user_id'], $reason]);
        $resp = ['ok' => true, 'msg' => '✅ تم تقديم اعتراضك بنجاح، سيتم مراجعته قريباً'];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

$statusMap = [
    'pending'    => ['label'=>'انتظار',    'color'=>'#f5a623','icon'=>'clock',              'grad'=>'linear-gradient(135deg,#7c4f00,#2a1800)'],
    'processing' => ['label'=>'تنفيذ',     'color'=>'#00d4ff','icon'=>'spinner',            'grad'=>'linear-gradient(135deg,#003d5c,#001a2e)'],
    'completed'  => ['label'=>'مكتمل',     'color'=>'#00e676','icon'=>'check-circle',       'grad'=>'linear-gradient(135deg,#003d1a,#001a0a)'],
    'cancelled'  => ['label'=>'ملغي',      'color'=>'#ff4455','icon'=>'times-circle',       'grad'=>'linear-gradient(135deg,#3d0008,#1a0004)'],
    'failed'     => ['label'=>'فشل',       'color'=>'#ff4455','icon'=>'exclamation-circle', 'grad'=>'linear-gradient(135deg,#3d0008,#1a0004)'],
];
$steps = ['pending','processing','completed'];

// إحصاءات
$allStats = $pdo->prepare("SELECT status,COUNT(*) c FROM orders WHERE user_id=? GROUP BY status");
$allStats->execute([$_SESSION['user_id']]); $allStats=$allStats->fetchAll(PDO::FETCH_KEY_PAIR);
$totalCount = array_sum($allStats);

// ── الطلبات المعلقة المرتبطة بمزود (للفحص التلقائي) ────────────────────────
$pendingProviderOrders = [];
try {
    $pps = $pdo->prepare("SELECT o.id FROM orders o JOIN services s ON o.service_id=s.id JOIN providers p ON s.provider_id=p.id WHERE o.user_id=? AND o.status IN ('pending','processing') AND o.provider_order_id IS NOT NULL AND o.provider_order_id != '' AND p.status=1 ORDER BY o.created_at DESC LIMIT 20");
    $pps->execute([$_SESSION['user_id']]);
    $pendingProviderOrders = $pps->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) { $pendingProviderOrders = []; }

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#080c1a">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);
  --primary:#1e6fff;--primary2:#0d4fd4;
  --cyan:#00d4ff;--gold:#f5a623;--green:#00e676;--red:#ff4455;
  --text:#fff;--text2:#8fa3bf;--text3:#4d6080;
  --font:'Cairo',sans-serif;
  --r:18px;--rsm:12px;
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);direction:rtl;min-height:100vh;overflow-x:hidden}
a{text-decoration:none;color:inherit}

#app{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg);position:relative}

/* HEADER */
.top-header{background:linear-gradient(135deg,#0d1428,#0a1535);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:58px;position:sticky;top:0;z-index:100}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;box-shadow:0 4px 12px rgba(30,111,255,.4)}
.logo-text{font-size:17px;font-weight:900;background:linear-gradient(90deg,#fff,var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hbtn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:16px;text-decoration:none;transition:.2s}
.hbtns{display:flex;gap:8px}

/* BALANCE BAR */
.bal-bar{background:linear-gradient(135deg,var(--primary2),#0a0d2e);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
.bal-info{display:flex;align-items:center;gap:10px}
.bal-icon{width:34px;height:34px;background:rgba(255,255,255,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px}
.bal-label{font-size:11px;color:rgba(255,255,255,.6)}
.bal-val{font-size:18px;font-weight:900;line-height:1}
.bal-cur{font-size:11px;color:rgba(255,255,255,.6)}
.bal-action{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:6px 14px;color:#fff;font-family:var(--font);font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px}

/* FLASH */
.flash{margin:10px 12px;border-radius:12px;padding:10px 14px;font-size:.86rem;font-weight:700;display:flex;align-items:center;gap:8px}
.flash.success{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.25);color:#00e676}
.flash.danger{background:rgba(255,68,85,.1);border:1px solid rgba(255,68,85,.25);color:#ff4455}

/* TITLE */
.ptitle-bar{padding:14px 16px 6px;display:flex;align-items:center;justify-content:space-between}
.ptitle{font-size:1.05rem;font-weight:900;display:flex;align-items:center;gap:8px}
.new-btn{background:linear-gradient(135deg,var(--primary),#7c3aed);border:none;border-radius:12px;padding:8px 16px;color:#fff;font-family:var(--font);font-size:.8rem;font-weight:800;display:flex;align-items:center;gap:6px;box-shadow:0 4px 14px rgba(30,111,255,.3);text-decoration:none}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;padding:8px 12px 10px}
.sbox{background:var(--card2);border:1px solid var(--border);border-radius:12px;padding:10px 6px;text-align:center}
.snum{font-size:1.25rem;font-weight:900}
.slbl{font-size:.62rem;color:var(--text2);margin-top:2px}

/* FILTER */
.filter-strip{display:flex;gap:7px;overflow-x:auto;padding:2px 12px 12px;scrollbar-width:none}
.filter-strip::-webkit-scrollbar{display:none}
.fchip{flex-shrink:0;background:var(--card2);border:1.5px solid var(--border);border-radius:20px;padding:5px 14px;font-size:.76rem;font-weight:700;color:var(--text2);text-decoration:none;transition:.2s;white-space:nowrap}
.date-filter-bar{padding:8px 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:rgba(0,0,0,.1);border-bottom:1px solid var(--border)}
.date-inp{background:var(--card2);border:1.5px solid var(--border2);border-radius:10px;padding:6px 10px;color:var(--text);font-family:var(--font);font-size:.8rem;outline:none;flex:1;min-width:120px}
.date-inp:focus{border-color:var(--primary)}
.date-apply-btn{padding:7px 14px;background:var(--primary);border:none;border-radius:10px;color:#fff;font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer;flex-shrink:0}
.date-today-btn{padding:7px 12px;background:var(--card2);border:1.5px solid var(--border2);border-radius:10px;color:var(--text2);font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer;flex-shrink:0;text-decoration:none}
.period-badge{font-size:.72rem;color:var(--text3);padding:4px 12px;white-space:nowrap}
.fchip.on{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 3px 12px rgba(30,111,255,.3)}

/* ORDER CARDS */
.olist{padding:0 12px;display:flex;flex-direction:column;gap:9px;padding-bottom:24px}
.ocard{background:var(--card2);border:1.5px solid var(--border);border-radius:16px;overflow:hidden;cursor:pointer;transition:.15s}
.ocard:active{transform:scale(.99);border-color:rgba(30,111,255,.4)}
.ocard.sel{border-color:var(--primary);box-shadow:0 0 0 2px rgba(30,111,255,.15)}
.ocardtop{display:flex;align-items:center;gap:12px;padding:13px 14px}
.othumb{width:48px;height:48px;border-radius:13px;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:linear-gradient(135deg,rgba(30,111,255,.15),rgba(0,212,255,.08))}
.othumb img{width:100%;height:100%;object-fit:cover}
.oinfo{flex:1;min-width:0}
.osvc{font-weight:800;font-size:.9rem;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ometa{font-size:.7rem;color:var(--text3);display:flex;gap:6px;align-items:center;margin-top:1px}
.oprice{font-size:.98rem;font-weight:900;color:#00d4aa;flex-shrink:0;text-align:left}
.odate-sm{font-size:.65rem;color:var(--text3);text-align:left}
.ofoot{display:flex;align-items:center;justify-content:space-between;padding:7px 14px;border-top:1px solid var(--border);background:rgba(0,0,0,.12)}
.sbadge{display:inline-flex;align-items:center;gap:5px;padding:3px 11px;border-radius:20px;font-size:.7rem;font-weight:800}

/* Empty */
.empty-box{text-align:center;padding:3rem 2rem;color:var(--text2)}
.empty-box i{font-size:3rem;opacity:.12;display:block;margin-bottom:1rem}

/* ════════════════════ DETAIL OVERLAY ════════════════════ */
.doverlay{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:300;display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(4px)}
.doverlay.open{display:flex}
.dpanel{background:var(--card);border-radius:24px 24px 0 0;width:100%;max-width:430px;max-height:90vh;overflow-y:auto;animation:suD .32s cubic-bezier(.4,0,.2,1);scrollbar-width:none}
.dpanel::-webkit-scrollbar{display:none}
@keyframes suD{from{transform:translateY(100%);opacity:.4}to{transform:translateY(0);opacity:1}}
.ddrag{width:40px;height:4px;background:var(--border2);border-radius:2px;margin:10px auto 0}

/* Detail: Hero */
.dhero{padding:18px 16px 14px;text-align:center;position:relative;overflow:hidden}
.dhero-bg{position:absolute;inset:0;opacity:.09}
.dthumb{width:70px;height:70px;border-radius:18px;margin:0 auto 10px;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:1.8rem;position:relative;z-index:1;background:linear-gradient(135deg,rgba(30,111,255,.2),rgba(0,212,255,.1))}
.dthumb img{width:100%;height:100%;object-fit:cover}
.dsvc{font-size:1.05rem;font-weight:900;position:relative;z-index:1}
.dcat{font-size:.75rem;color:var(--text2);margin-top:3px;position:relative;z-index:1}

/* Detail: Status Banner */
.dstatus-banner{margin:0 14px 12px;border-radius:14px;padding:13px 14px;display:flex;align-items:center;gap:12px}
.dstatus-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}

/* Detail: Progress */
.dprogress{margin:0 14px 12px;background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:14px}
.dpsection-lbl{font-size:.7rem;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:5px}
.dpstep{display:flex;gap:10px;position:relative}
.dpstep:not(:last-child)::after{content:'';position:absolute;right:14px;top:30px;width:2px;height:calc(100% - 6px);background:var(--border)}
.dpsdot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0;position:relative;z-index:1;border:2px solid;transition:.3s}
.dpsdot.done{background:rgba(0,230,118,.15);border-color:#00e676;color:#00e676}
.dpsdot.active{background:rgba(30,111,255,.15);border-color:var(--primary);color:var(--primary);animation:pdot 1.6s infinite}
.dpsdot.idle{background:var(--bg2);border-color:var(--border);color:var(--text3)}
@keyframes pdot{0%,100%{box-shadow:0 0 0 0 rgba(30,111,255,.4)}55%{box-shadow:0 0 0 7px rgba(30,111,255,0)}}
.dpstep-body{flex:1;padding:3px 0 14px}
.dpstep-title{font-size:.84rem;font-weight:800}
.dpstep-msg{font-size:.76rem;color:var(--text2);margin-top:3px;line-height:1.55}
.dpstep-time{font-size:.66rem;color:var(--text3);margin-top:3px}

/* Detail: Log */
.dlog{margin:0 14px 12px}
.dlog-lbl{font-size:.7rem;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:9px;display:flex;align-items:center;gap:5px}
.dlog-item{display:flex;gap:9px;margin-bottom:9px}
.dlog-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.dlog-lstat{font-size:.8rem;font-weight:800}
.dlog-msg{font-size:.76rem;color:var(--text2);margin-top:2px;line-height:1.5}
.dlog-time{font-size:.65rem;color:var(--text3);margin-top:2px}

/* Detail: Grid */
.dgrid{margin:0 14px 12px;display:grid;grid-template-columns:1fr 1fr;gap:7px}
.dgrid-item{background:var(--card2);border:1px solid var(--border);border-radius:11px;padding:9px 11px}
.dgrid-lbl{font-size:.66rem;color:var(--text2);font-weight:700;margin-bottom:2px}
.dgrid-val{font-size:.85rem;font-weight:800;word-break:break-all}

/* Detail: Fields */
.dfields{margin:0 14px 12px;background:var(--card2);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.dfields-hd{padding:9px 13px;border-bottom:1px solid var(--border);font-size:.7rem;color:var(--text2);font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.dfield-row{display:flex;justify-content:space-between;align-items:center;padding:9px 13px;border-bottom:1px solid rgba(255,255,255,.03)}
.dfield-row:last-child{border-bottom:none}
.dfield-k{font-size:.76rem;color:var(--text2)}
.dfield-v{font-size:.83rem;font-weight:800}

/* Detail: Provider */
.dprov{margin:0 14px 20px;background:rgba(30,111,255,.06);border:1px solid rgba(30,111,255,.18);border-radius:12px;padding:11px 13px}
.dprov-title{font-size:.7rem;color:var(--primary);font-weight:800;margin-bottom:5px;display:flex;align-items:center;gap:5px}
.dprov-body{font-size:.78rem;color:var(--text2);line-height:1.6;word-break:break-word}

/* Close btn */
.dclose{position:absolute;top:12px;left:12px;width:32px;height:32px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.1);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#fff;font-size:.85rem;z-index:5}

/* Detail: Action Buttons */
.dactions{margin:0 14px 14px;display:flex;gap:8px}
.daction-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border-radius:12px;font-family:var(--font);font-size:.76rem;font-weight:700;cursor:pointer;border:1px solid;transition:.15s;background:transparent}
.daction-btn:active{transform:scale(.97)}
.daction-copy{color:#6ba3ff;border-color:rgba(107,163,255,.3);background:rgba(107,163,255,.08)}
.daction-print{color:#a78bfa;border-color:rgba(167,139,250,.3);background:rgba(167,139,250,.08)}
.daction-share{color:#34d399;border-color:rgba(52,211,153,.3);background:rgba(52,211,153,.08)}
.daction-obj{color:#ff6677;border-color:rgba(255,100,119,.3);background:rgba(255,100,119,.08)}
.daction-obj-status{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 8px;border-radius:12px;font-size:.74rem;font-weight:700}

/* Response time badge */
.dresponse-time{margin:0 14px 12px;display:flex;align-items:center;gap:10px;background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.18);border-radius:12px;padding:10px 14px}

/* Objection form */
.dobjection-form{margin:0 14px 16px;background:rgba(255,100,100,.06);border:1px solid rgba(255,100,100,.2);border-radius:14px;padding:14px;display:none}
.dobjection-form.open{display:block}
.dobjection-textarea{width:100%;min-height:90px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px;color:var(--text);font-family:var(--font);font-size:.8rem;resize:none;box-sizing:border-box;outline:none}
.dobjection-textarea:focus{border-color:rgba(255,100,100,.4)}

/* ╔══════════════════════════════════════════════════════════╗
   ║   ☀️ LIGHT MODE — نفس نظام الألوان النهاري بالصفحة الرئيسية   ║
   ╚══════════════════════════════════════════════════════════╝ */
body.light-mode {
  --bg:        #f0f4fa;
  --bg2:       #e4ecf7;
  --card:      #ffffff;
  --card2:     #f5f8ff;
  --border:    rgba(30,64,175,0.1);
  --border2:   rgba(30,64,175,0.18);
  --primary:   #2563eb;
  --primary2:  #1e40af;
  --cyan:      #0891b2;
  --gold:      #d97706;
  --green:     #059669;
  --red:       #dc2626;
  --text:      #0f172a;
  --text2:     #475569;
  --text3:     #94a3b8;
}
body.light-mode, body.light-mode #app { background:var(--bg) !important; color:var(--text) !important; }

/* الهيدر */
body.light-mode .top-header{background:linear-gradient(135deg,#1e3a8a 0%,#1e40af 100%) !important;border-bottom:1px solid rgba(255,255,255,.12) !important}
body.light-mode .logo-text{background:linear-gradient(90deg,#fff,#bfdbfe) !important;-webkit-background-clip:text !important;-webkit-text-fill-color:transparent !important;background-clip:text !important}
body.light-mode .hbtn{background:rgba(255,255,255,.18) !important;border-color:rgba(255,255,255,.25) !important;color:#fff !important}

/* شريط الرصيد */
body.light-mode .bal-bar{background:linear-gradient(135deg,#1e40af 0%,#1d4ed8 100%) !important;border-bottom:1px solid rgba(255,255,255,.1) !important}

/* زر الإغلاق فوق تفاصيل الطلب (كان أبيض على أبيض) */
body.light-mode .dclose{background:rgba(15,23,42,.08) !important;border-color:rgba(15,23,42,.1) !important;color:#0f172a !important}

/* حقل نص الاعتراض */
body.light-mode .dobjection-textarea{background:rgba(15,23,42,.03) !important;border-color:rgba(15,23,42,.12) !important}
body.light-mode .dobjection-textarea::placeholder{color:var(--text3) !important}
body.theme-transitioning * { transition: background-color .3s ease, color .25s ease, border-color .25s ease !important; }
</style>
</head>
<body>
<script>
// نفس منطق الصفحة الرئيسية: الافتراضي نهاري إلا إذا اختار المستخدم الليلي صراحة من قبل
if (localStorage.getItem('njaz_theme') !== 'dark') {
  document.body.classList.add('light-mode');
}
</script>
<div id="app">

<!-- HEADER -->
<div class="top-header">
  <div class="header-logo">
    <div class="logo-icon">🚀</div>
    <div class="logo-text"><?= htmlspecialchars($siteName) ?></div>
  </div>
  <div class="hbtns">
    <button class="hbtn theme-toggle-btn" id="themeToggleBtn" onclick="toggleTheme()" title="تبديل الوضع" style="cursor:pointer">
      <i class="fas fa-moon" id="themeIcon"></i>
    </button>
    <a href="<?= SITE_URL ?>/mobile.php" class="hbtn"><i class="fas fa-home"></i></a>
    <a href="<?= SITE_URL ?>/wallet.php" class="hbtn"><i class="fas fa-wallet"></i></a>
  </div>
</div>

<!-- BALANCE BAR -->
<div class="bal-bar">
  <div class="bal-info">
    <div class="bal-icon">💰</div>
    <div>
      <div class="bal-label">رصيدي</div>
      <div class="bal-val"><?= number_format($userBalance,2) ?></div>
      <div class="bal-cur">$</div>
    </div>
  </div>
  <a href="<?= SITE_URL ?>/mobile.php" class="bal-action">
    <i class="fas fa-plus-circle"></i> شحن رصيد
  </a>
</div>

<?php if($flash): ?>
<div class="flash <?= $flash['type'] ?>">
  <i class="fas fa-<?= $flash['type']==='success'?'check-circle':'exclamation-circle' ?>"></i>
  <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- TITLE + NEW ORDER -->
<div class="ptitle-bar">
  <div class="ptitle"><i class="fas fa-receipt" style="color:var(--primary)"></i> طلباتي</div>
  <a href="<?= SITE_URL ?>/mobile.php" class="new-btn"><i class="fas fa-plus"></i> طلب جديد</a>
</div>

<!-- فلتر التاريخ -->
<div class="date-filter-bar">
  <span style="font-size:.78rem;font-weight:700;color:var(--text3);flex-shrink:0"><i class="fas fa-calendar-alt" style="color:var(--primary)"></i> الفترة:</span>
  <form method="GET" style="display:flex;gap:8px;align-items:center;flex:1;flex-wrap:wrap" id="dateForm">
    <?php if($status): ?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"> <?php endif; ?>
    <input type="date" name="from" class="date-inp" value="<?=htmlspecialchars($dateFrom)?>" max="<?=date('Y-m-d')?>" id="dateFrom">
    <span style="color:var(--text3);font-size:.8rem;flex-shrink:0">—</span>
    <input type="date" name="to"   class="date-inp" value="<?=htmlspecialchars($dateTo)?>"   max="<?=date('Y-m-d')?>" id="dateTo">
    <button type="submit" class="date-apply-btn"><i class="fas fa-search"></i> بحث</button>
  </form>
  <?php if(!$isToday): ?>
  <a href="orders.php<?=$status?'?status='.htmlspecialchars($status):''?>" class="date-today-btn"><i class="fas fa-calendar-day"></i> اليوم</a>
  <?php endif; ?>
</div>

<!-- STATS الفترة -->
<div class="stats-row">
  <div class="sbox">
    <div class="snum"><?= $periodStats['total'] ?></div>
    <div class="slbl"><?= $isToday?'اليوم':'الفترة'?></div>
  </div>
  <div class="sbox"><div class="snum" style="color:#00e676"><?= $periodStats['completed'] ?></div><div class="slbl">مكتمل</div></div>
  <div class="sbox"><div class="snum" style="color:var(--cyan)"><?= $periodStats['processing'] ?></div><div class="slbl">جارٍ</div></div>
  <div class="sbox"><div class="snum" style="color:#f5a623"><?= formatMoney($periodStats['total_spent']) ?></div><div class="slbl">المدفوع</div></div>
</div>

<!-- عنوان الفترة -->
<div style="padding:4px 14px 6px;display:flex;align-items:center;justify-content:space-between">
  <div style="font-size:.75rem;color:var(--text3)">
    <?php if($isToday): ?>
    <i class="fas fa-circle" style="color:#00e676;font-size:.5rem"></i> عمليات اليوم — <?= date('d/m/Y') ?>
    <?php elseif($dateFrom===$dateTo): ?>
    <i class="fas fa-calendar"></i> <?= date('d/m/Y',strtotime($dateFrom)) ?>
    <?php else: ?>
    <i class="fas fa-calendar-week"></i> <?= date('d/m',strtotime($dateFrom)) ?> — <?= date('d/m/Y',strtotime($dateTo)) ?>
    <?php endif; ?>
  </div>
  <div style="font-size:.72rem;color:var(--text3)"><?= count($orders) ?> طلب</div>
</div>

<!-- FILTER CHIPS -->
<div class="filter-strip">
  <?php
    $qs = $dateFrom&&$dateTo&&!$isToday ? '&from='.urlencode($dateFrom).'&to='.urlencode($dateTo) : '';
    foreach([''=>'الكل','pending'=>'⏳ انتظار','processing'=>'⚙️ تنفيذ','completed'=>'✅ مكتمل','cancelled'=>'❌ ملغي','failed'=>'⚠️ فشل'] as $k=>$lbl):
  ?>
  <a href="orders.php<?= $k?('?status='.$k.$qs):($qs?('?'.ltrim($qs,'&')):'') ?>" class="fchip <?= $status===$k?'on':'' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<!-- ORDERS LIST -->
<div class="olist">
<?php if(empty($orders)): ?>
<div class="empty-box">
  <i class="fas fa-receipt"></i>
  <p>لا توجد طلبات<?= $status?' بهذه الحالة':'' ?></p>
  <span style="font-size:.85rem;opacity:.7">ابدأ بطلب خدمتك الأولى</span><br><br>
  <a href="<?= SITE_URL ?>/mobile.php" style="background:var(--primary);color:#fff;padding:10px 24px;border-radius:12px;font-weight:700;display:inline-block;font-size:.88rem">
    <i class="fas fa-shopping-bag"></i> تصفح الخدمات
  </a>
</div>
<?php else: foreach($orders as $order):
  $st = $statusMap[$order['status']] ?? ['label'=>$order['status'],'color'=>'#8895a7','icon'=>'circle'];
  $fields = json_decode($order['field_data'],true) ?? [];
?>
<div class="ocard <?= $orderId==$order['id']?'sel':'' ?>" onclick="openD(<?= $order['id'] ?>)">
  <div class="ocardtop">
    <div class="othumb">
      <?php if(!empty($order['service_image'])): ?><img src="<?= SITE_URL ?>/<?= htmlspecialchars($order['service_image']) ?>">
      <?php else: ?><i class="<?= htmlspecialchars($order['cat_icon']??'fas fa-box') ?>" style="color:var(--primary)"></i>
      <?php endif; ?>
    </div>
    <div class="oinfo">
      <div class="osvc"><?= htmlspecialchars($order['service_name']) ?></div>
      <div class="ometa">
        <span><i class="fas fa-folder" style="font-size:.55rem"></i> <?= htmlspecialchars($order['cat_name']) ?></span>
        <span>·</span><span>× <?= number_format($order['quantity']) ?></span>
      </div>
      <?php $fkeys = array_keys($fields); if(!empty($fkeys[0])): ?>
      <div style="font-size:.68rem;color:var(--cyan);margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <i class="fas fa-tag" style="font-size:.55rem"></i> <?= htmlspecialchars($fkeys[0]) ?>: <strong><?= htmlspecialchars($fields[$fkeys[0]]) ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <div>
      <div class="oprice"><?= formatMoney($order['total_price']) ?></div>
      <div class="odate-sm"><?= date('d/m', strtotime($order['created_at'])) ?></div>
    </div>
  </div>
  <div class="ofoot">
    <span class="sbadge" style="background:<?= $st['color'] ?>18;color:<?= $st['color'] ?>;border:1px solid <?= $st['color'] ?>22">
      <i class="fas fa-<?= $st['icon'] ?> <?= $order['status']==='processing'?'fa-spin':'' ?>"></i><?= $st['label'] ?>
    </span>
    <div style="display:flex;align-items:center;gap:7px">
      <span style="font-size:.63rem;color:var(--text3);font-family:monospace">
        <?= htmlspecialchars(!empty($order['ref_id']) ? strtoupper($order['ref_id']) : ('ORD-'.str_pad($order['id'],6,'0',STR_PAD_LEFT))) ?>
      </span>
      <span style="font-size:.65rem;color:var(--text3)"><?= date('H:i', strtotime($order['created_at'])) ?></span>
      <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.6rem"></i>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- ═══════════════ DETAIL PANEL ═══════════════ -->
<div class="doverlay" id="doverlay" onclick="closeDIfBg(event)">
<div class="dpanel" id="dpanel">
  <div class="ddrag"></div>

  <?php if($selectedOrder):
    $st  = $statusMap[$selectedOrder['status']] ?? ['label'=>$selectedOrder['status'],'color'=>'#8895a7','icon'=>'circle','grad'=>''];
    $fds = json_decode($selectedOrder['field_data'],true) ?? [];
    $csi = array_search($selectedOrder['status'], $steps);
    if($csi===false) $csi = -1;
  ?>

  <!-- Hero -->
  <div class="dhero">
    <div class="dhero-bg" style="background:<?= $st['grad'] ?>"></div>
    <div class="dclose" onclick="closeD()" style="position:absolute;top:12px;left:12px;width:32px;height:32px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:.85rem;z-index:5"><i class="fas fa-times"></i></div>
    <div class="dthumb">
      <?php if(!empty($selectedOrder['service_image'])): ?><img src="<?= SITE_URL ?>/<?= htmlspecialchars($selectedOrder['service_image']) ?>">
      <?php else: ?><i class="<?= htmlspecialchars($selectedOrder['cat_icon']??'fas fa-box') ?>" style="color:var(--primary)"></i>
      <?php endif; ?>
    </div>
    <div class="dsvc"><?= htmlspecialchars($selectedOrder['service_name']) ?></div>
    <div class="dcat"><i class="fas fa-folder" style="font-size:.6rem"></i> <?= htmlspecialchars($selectedOrder['cat_name']) ?></div>
  </div>

  <!-- Status Banner -->
  <div class="dstatus-banner" style="background:<?= $st['color'] ?>14;border:1px solid <?= $st['color'] ?>22;margin:0 14px 12px;border-radius:14px">
    <div class="dstatus-icon" style="background:<?= $st['color'] ?>1e;color:<?= $st['color'] ?>">
      <i class="fas fa-<?= $st['icon'] ?> <?= $selectedOrder['status']==='processing'?'fa-spin':'' ?>"></i>
    </div>
    <div style="flex:1">
      <div style="font-weight:900;font-size:.98rem;color:<?= $st['color'] ?>"><?= $st['label'] ?></div>
      <?php
        $statusMsg = $selectedOrder['status_message'] ?? $selectedOrder['admin_notes'] ?? '';
        if($statusMsg): ?>
      <div style="font-size:.78rem;color:var(--text2);margin-top:3px"><?= htmlspecialchars($statusMsg) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:left;flex-shrink:0">
      <div style="font-weight:900;color:#00d4aa;font-size:1.05rem"><?= formatMoney($selectedOrder['total_price']) ?></div>
      <div style="font-size:.65rem;color:var(--text3)">× <?= number_format($selectedOrder['quantity']) ?></div>
    </div>
  </div>

  <!-- Progress Steps -->
  <?php if(!in_array($selectedOrder['status'],['cancelled','failed'])): ?>
  <div class="dprogress">
    <div class="dpsection-lbl"><i class="fas fa-route"></i> مراحل الطلب</div>
    <?php
    $stepDefs = [
      'pending'    => ['t'=>'تم الاستلام',   's'=>'استُلم طلبك بنجاح'],
      'processing' => ['t'=>'قيد التنفيذ',   's'=>'يُعالَج طلبك الآن'],
      'completed'  => ['t'=>'مكتمل',          's'=>'نُفِّذ طلبك بنجاح ✓'],
    ];
    foreach($stepDefs as $sk=>$sdef):
      $si = array_search($sk,$steps);
      if($si < $csi)      $cls='done';
      elseif($si===$csi)  $cls='active';
      else                $cls='idle';
      $stepLog=null;
      foreach($statusLog as $lg) { if($lg['status']===$sk){$stepLog=$lg;break;} }
    ?>
    <div class="dpstep">
      <div class="dpsdot <?= $cls ?>">
        <?php if($cls==='done'): ?><i class="fas fa-check" style="font-size:.62rem"></i>
        <?php elseif($cls==='active'): ?><i class="fas fa-circle" style="font-size:.38rem"></i>
        <?php else: ?><i class="far fa-circle" style="font-size:.62rem"></i>
        <?php endif; ?>
      </div>
      <div class="dpstep-body">
        <div class="dpstep-title" style="color:<?= $cls==='idle'?'var(--text3)':($cls==='active'?'var(--primary)':'#fff') ?>"><?= $sdef['t'] ?></div>
        <?php if($stepLog && $stepLog['message']): ?>
        <div class="dpstep-msg"><?= htmlspecialchars($stepLog['message']) ?></div>
        <?php elseif($cls!=='idle'): ?>
        <div class="dpstep-msg" style="color:var(--text3)"><?= $sdef['s'] ?></div>
        <?php endif; ?>
        <?php if($stepLog): ?>
        <div class="dpstep-time"><i class="far fa-clock" style="font-size:.58rem"></i> <?= date('d/m/Y H:i',strtotime($stepLog['created_at'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Status Log -->
  <?php if(!empty($statusLog)): ?>
  <div class="dlog">
    <div class="dlog-lbl"><i class="fas fa-history"></i> سجل التطورات</div>
    <?php foreach(array_reverse($statusLog) as $lg):
      $ls = $statusMap[$lg['status']] ?? ['color'=>'#8895a7','label'=>$lg['status']];
    ?>
    <div class="dlog-item">
      <div class="dlog-dot" style="background:<?= $ls['color'] ?>"></div>
      <div>
        <div class="dlog-lstat" style="color:<?= $ls['color'] ?>"><?= $ls['label'] ?></div>
        <?php if($lg['message']): ?><div class="dlog-msg"><?= htmlspecialchars($lg['message']) ?></div><?php endif; ?>
        <div class="dlog-time"><i class="far fa-clock" style="font-size:.57rem"></i> <?= date('d/m/Y — H:i',strtotime($lg['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Data Grid -->
  <div class="dgrid">
    <div class="dgrid-item" style="grid-column:1/-1"><div class="dgrid-lbl">رقم الطلب</div>
      <div class="dgrid-val" style="color:var(--primary);font-family:monospace;font-size:.8rem;word-break:break-all">
        <?= htmlspecialchars(!empty($selectedOrder['ref_id']) ? strtoupper($selectedOrder['ref_id']) : ('ORD-'.str_pad($selectedOrder['id'],6,'0',STR_PAD_LEFT))) ?>
      </div>
    </div>
    <div class="dgrid-item"><div class="dgrid-lbl">الكمية</div><div class="dgrid-val"><?= number_format($selectedOrder['quantity']) ?></div></div>
    <div class="dgrid-item"><div class="dgrid-lbl">سعر الوحدة</div><div class="dgrid-val" style="color:#00d4aa"><?= formatMoney($selectedOrder['unit_price']) ?></div></div>
    <div class="dgrid-item"><div class="dgrid-lbl">المجموع</div><div class="dgrid-val" style="color:#00d4aa"><?= formatMoney($selectedOrder['total_price']) ?></div></div>
    <div class="dgrid-item"><div class="dgrid-lbl">التاريخ</div><div class="dgrid-val" style="font-size:.75rem"><?= date('d/m/Y H:i',strtotime($selectedOrder['created_at'])) ?></div></div>
    <?php if($selectedOrder['provider_order_id']): ?>
    <div class="dgrid-item"><div class="dgrid-lbl">رقم المزود</div><div class="dgrid-val" style="font-size:.73rem;color:var(--cyan);font-family:monospace"><?= htmlspecialchars($selectedOrder['provider_order_id']) ?></div></div>
    <?php endif; ?>
  </div>

  <!-- Field Data -->
  <?php if(!empty($fds)): ?>
  <div class="dfields">
    <div class="dfields-hd"><i class="fas fa-info-circle"></i> بيانات الطلب المُدخَلة</div>
    <?php foreach($fds as $k=>$v): ?>
    <div class="dfield-row"><div class="dfield-k"><?= htmlspecialchars($k) ?></div><div class="dfield-v"><?= htmlspecialchars($v) ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Provider Response -->
  <?php if(!empty($selectedOrder['provider_response'])): ?>
  <div class="dprov">
    <div class="dprov-title"><i class="fas fa-server"></i> رد نظام المزود</div>
    <div class="dprov-body"><?= htmlspecialchars($selectedOrder['provider_response']) ?></div>
  </div>
  <?php endif; ?>

  <!-- مدة الاستجابة -->
  <?php if($responseSeconds !== null): ?>
  <?php
    $mins = floor($responseSeconds / 60);
    $secs = $responseSeconds % 60;
    $timeStr = $mins > 0
      ? $mins . ' دقيقة' . ($secs > 0 ? ' و' . $secs . ' ثانية' : '')
      : $secs . ' ثانية';
  ?>
  <div class="dresponse-time">
    <i class="fas fa-bolt" style="color:#00d4aa;font-size:.95rem"></i>
    <div>
      <div style="font-size:.67rem;color:var(--text2);margin-bottom:2px">مدة الاستجابة</div>
      <div style="font-size:.88rem;font-weight:800;color:#00d4aa"><?= $timeStr ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- أزرار الإجراءات -->
  <?php
    $orderRef = !empty($selectedOrder['ref_id']) ? strtoupper($selectedOrder['ref_id']) : ('ORD-'.str_pad($selectedOrder['id'],6,'0',STR_PAD_LEFT));
    $objStatusMap = ['pending'=>'قيد المراجعة','reviewing'=>'جاري المراجعة','resolved'=>'تم الحل','rejected'=>'مرفوض'];
    $objColorMap  = ['pending'=>'#f5a623','reviewing'=>'#00d4ff','resolved'=>'#00e676','rejected'=>'#ff4455'];
  ?>
  <div class="dactions" id="dActionRow">
    <!-- نسخ رقم الطلب -->
    <button class="daction-btn daction-copy" onclick="copyOrderRef('<?= htmlspecialchars($orderRef) ?>', this)" title="نسخ رقم الطلب">
      <i class="fas fa-copy"></i> نسخ
    </button>
    <!-- طباعة -->
    <button class="daction-btn daction-print" onclick="printOrderDetail()" title="طباعة الطلب">
      <i class="fas fa-print"></i> طباعة
    </button>
    <!-- مشاركة -->
    <button class="daction-btn daction-share" onclick="shareOrder('<?= htmlspecialchars($orderRef) ?>', '<?= htmlspecialchars($selectedOrder['service_name']) ?>', '<?= htmlspecialchars(formatMoney($selectedOrder['total_price'])) ?>')" title="مشاركة">
      <i class="fas fa-share-alt"></i> مشاركة
    </button>
    <!-- اعتراض (للطلبات المكتملة فقط) -->
    <?php if($selectedOrder['status'] === 'completed'): ?>
      <?php if($existingObjection): ?>
      <div class="daction-obj-status" style="color:<?= $objColorMap[$existingObjection['status']]??'#8895a7' ?>;border:1px solid <?= $objColorMap[$existingObjection['status']]??'#8895a7' ?>33;background:<?= $objColorMap[$existingObjection['status']]??'#8895a7' ?>11">
        <i class="fas fa-flag"></i> <?= $objStatusMap[$existingObjection['status']]??$existingObjection['status'] ?>
      </div>
      <?php else: ?>
      <button class="daction-btn daction-obj" onclick="toggleObjectionForm()" id="btnObjection">
        <i class="fas fa-flag"></i> اعتراض
      </button>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- زر استعادة واجهة الأرقام (للطلبات numbers_live قيد التنفيذ فقط) -->
  <?php
    $isNumbersLivePending = (
      ($selectedOrder['service_type'] ?? '') === 'numbers_live' &&
      in_array($selectedOrder['status'], ['pending','processing','completed'])
    );
    $naButtonLabel = in_array($selectedOrder['status'], ['pending','processing'])
      ? 'فتح واجهة الرقم ومتابعة الكود'
      : 'عرض الرقم والكود';

    // تجهيز البيانات مباشرة بدون fetch
    if ($isNumbersLivePending) {
      $naOrderData = json_decode($selectedOrder['numbersapp_data'] ?? '{}', true);
      $naElapsed   = time() - strtotime($selectedOrder['created_at']);
      $naRemaining = max(0, 1200 - $naElapsed);
      $naPayload   = json_encode([
        'ok'        => true,
        'order_id'  => (int)$selectedOrder['id'],
        'svc_name'  => $selectedOrder['service_name'],
        'number'    => $naOrderData['number']     ?? '',
        'access_id' => $selectedOrder['provider_order_id'] ?? '',
        'remaining' => $naRemaining,
        'status'    => $selectedOrder['status'],
        'code'      => $naOrderData['code'] ?? '',
      ], JSON_UNESCAPED_UNICODE);
    }
  ?>
  <?php if($isNumbersLivePending): ?>
  <script>var _naEmbedData = <?= $naPayload ?>;</script>
  <div style="padding:0 0 10px">
    <button id="naOpenBtn"
       style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:14px;border-radius:14px;border:none;background:linear-gradient(135deg,#00c853,#00e676);color:#0a0e1a;font-family:inherit;font-size:.9rem;font-weight:900;cursor:pointer;box-shadow:0 6px 20px rgba(0,230,118,.3);box-sizing:border-box">
      <i class="fas fa-phone-alt" style="font-size:1rem"></i>
      <?= $naButtonLabel ?>
      <i class="fas fa-arrow-left"></i>
    </button>
  </div>
  <?php endif; ?>

  <!-- الكود المسلَّم تلقائياً من مخزون الأكواد -->
  <?php $deliveredCodeRow = codeStockGetByOrder($pdo, (int)$selectedOrder['id']); ?>
  <?php if($deliveredCodeRow): ?>
  <div style="margin:0 16px 16px;padding:14px;border-radius:14px;background:rgba(0,200,83,.06);border:1px solid rgba(0,200,83,.2)">
    <div style="font-size:.8rem;font-weight:800;color:#00c853;margin-bottom:8px"><i class="fas fa-key"></i> الكود المسلَّم</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="font-family:'Courier New',monospace;font-size:1rem;font-weight:900;letter-spacing:1.5px;color:#00c853;background:rgba(0,200,83,.1);border:1px solid rgba(0,200,83,.25);border-radius:8px;padding:8px 14px;flex:1;word-break:break-all"><?= htmlspecialchars($deliveredCodeRow['code']) ?></span>
      <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($deliveredCodeRow['code'])) ?>');this.innerHTML='<i class=\'fas fa-check\'></i>'" style="border:none;background:rgba(0,200,83,.15);color:#00c853;padding:8px 12px;border-radius:8px;cursor:pointer"><i class="fas fa-copy"></i></button>
    </div>
    <div style="font-size:.7rem;color:var(--text3);margin-top:6px">
      تم تسليمه تلقائياً بتاريخ <?= date('Y-m-d H:i', strtotime($deliveredCodeRow['used_at'])) ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- نموذج الاعتراض -->
  <?php if($selectedOrder['status'] === 'completed' && !$existingObjection): ?>
  <div class="dobjection-form" id="objectionForm">
    <div style="font-size:.8rem;font-weight:800;color:#ff6677;margin-bottom:8px"><i class="fas fa-flag"></i> تقديم اعتراض</div>
    <textarea id="objectionReason" class="dobjection-textarea" placeholder="اكتب سبب اعتراضك بالتفصيل..."></textarea>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button onclick="submitOrderObjection(<?= $selectedOrder['id'] ?>)" id="btnSubmitObj"
        style="flex:1;padding:9px;border-radius:10px;border:none;background:#ff6677;color:#fff;font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer">
        <i class="fas fa-paper-plane"></i> إرسال الاعتراض
      </button>
      <button onclick="toggleObjectionForm()"
        style="padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);cursor:pointer">
        إلغاء
      </button>
    </div>
  </div>
  <?php endif; ?>

  <div style="height:24px"></div>

  <?php else: ?>
  <div style="padding:2.5rem;text-align:center;color:var(--text2)">
    <i class="fas fa-search" style="font-size:2rem;opacity:.15;display:block;margin-bottom:1rem"></i>
    لم يتم العثور على الطلب
  </div>
  <?php endif; ?>
</div><!-- dpanel -->
</div><!-- doverlay -->

</div><!-- #app -->

<script>
const BASE = '<?= SITE_URL ?>';
const SSTATUS = '<?= htmlspecialchars($status) ?>';

// ── نسخ رقم الطلب ────────────────────────────────────────────────────────────
function copyOrderRef(ref, btn) {
  navigator.clipboard.writeText(ref).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ';
    btn.style.color = '#00e676';
    btn.style.borderColor = 'rgba(0,230,118,.3)';
    btn.style.background = 'rgba(0,230,118,.08)';
    setTimeout(() => {
      btn.innerHTML = orig;
      btn.style.color = '';
      btn.style.borderColor = '';
      btn.style.background = '';
    }, 2000);
  }).catch(() => {
    // fallback
    const ta = document.createElement('textarea');
    ta.value = ref; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btn.innerHTML = '<i class="fas fa-check"></i> تم النسخ';
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> نسخ'; }, 2000);
  });
}

// ── طباعة الطلب — وثيقة نظيفة ──────────────────────────────────────────────
function printOrderDetail() {
  <?php if($selectedOrder):
    $orderRef   = !empty($selectedOrder['ref_id']) ? strtoupper($selectedOrder['ref_id']) : ('ORD-'.str_pad($selectedOrder['id'],6,'0',STR_PAD_LEFT));
    $stLabel    = $statusMap[$selectedOrder['status']]['label'] ?? $selectedOrder['status'];
    $stColor    = $statusMap[$selectedOrder['status']]['color'] ?? '#888';
    $orderDate  = date('Y-m-d H:i:s', strtotime($selectedOrder['created_at']));
    $logoUrl    = getSetting('site_logo') ? SITE_URL.'/'.getSetting('site_logo') : '';
    $fdsHtml    = '';
    foreach($fds as $k=>$v) {
      $fdsHtml .= '<tr><td>'.htmlspecialchars($k).'</td><td>'.htmlspecialchars($v).'</td></tr>';
    }
    $responseHtml = '';
    if($responseSeconds !== null) {
      $rm = floor($responseSeconds/60); $rs = $responseSeconds%60;
      $rt = $rm>0 ? $rm.' دقيقة'.($rs>0?' و'.$rs.' ثانية':'') : $rs.' ثانية';
      $responseHtml = '<tr><td>مدة الاستجابة</td><td style="color:#006633;font-weight:700">'.$rt.'</td></tr>';
    }
    $logoHtml = $logoUrl ? '<img src="'.$logoUrl.'" style="max-height:55px;max-width:160px;margin-bottom:6px">' : '';
  ?>

  const siteName  = <?= json_encode(getSetting('site_name') ?: SITE_NAME) ?>;
  const siteUrl   = <?= json_encode(SITE_URL) ?>;
  const logoHtml  = <?= json_encode($logoHtml) ?>;
  const ref       = <?= json_encode($orderRef) ?>;
  const orderDate = <?= json_encode($orderDate) ?>;
  const svcName   = <?= json_encode($selectedOrder['service_name']) ?>;
  const qty       = <?= json_encode(number_format($selectedOrder['quantity'])) ?>;
  const total     = <?= json_encode(formatMoney($selectedOrder['total_price'])) ?>;
  const stLabel   = <?= json_encode($stLabel) ?>;
  const stColor   = <?= json_encode($stColor) ?>;
  const fdsHtml   = <?= json_encode($fdsHtml) ?>;
  const respHtml  = <?= json_encode($responseHtml) ?>;

  <?php else: ?>
  return;
  <?php endif; ?>

  const win = window.open('', '_blank', 'width=440,height=680');
  win.document.write(`<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>وصل طلب ${ref}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #fff;
    color: #111;
    padding: 28px 24px;
    max-width: 400px;
    margin: 0 auto;
    font-size: 13px;
    direction: rtl;
  }
  .header { text-align: center; margin-bottom: 18px; }
  .site-name { font-size: 20px; font-weight: 900; letter-spacing: 1px; margin-bottom: 2px; }
  .site-url  { font-size: 11px; color: #888; }
  hr { border: none; border-top: 1px dashed #bbb; margin: 14px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 6px 2px; vertical-align: top; }
  td:first-child { color: #666; width: 45%; }
  td:last-child  { font-weight: 700; text-align: left; }
  .svc-row td { font-size: 15px; font-weight: 900; padding: 8px 2px; }
  .status-row td:last-child { font-weight: 900; }
  .footer { text-align: center; font-size: 11px; color: #aaa; margin-top: 16px; }
  .print-btn {
    display: block; width: 100%; margin-top: 20px; padding: 11px;
    background: #111; color: #fff; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit;
    direction: rtl;
  }
  @media print {
    .print-btn { display: none; }
    body { padding: 10px; }
  }
</style>
</head>
<body>

<div class="header">
  ${logoHtml}
  <div class="site-name">${siteName}</div>
  <div class="site-url">${siteUrl}</div>
</div>

<hr>

<table>
  <tr><td>رقم الطلب</td><td style="font-family:monospace;font-size:12px">${ref}</td></tr>
  <tr><td>التاريخ</td><td>${orderDate}</td></tr>
</table>

<hr>

<table>
  <tr class="svc-row">
    <td>${svcName}</td>
    <td>× ${qty}</td>
  </tr>
  ${fdsHtml ? `<tr><td colspan="2"><table style="margin-top:4px;width:100%">${fdsHtml}</table></td></tr>` : ''}
</table>

<hr>

<table>
  <tr><td>المبلغ الإجمالي</td><td style="color:#006633;font-size:15px">${total}</td></tr>
  ${respHtml}
  <tr class="status-row"><td>الحالة</td><td style="color:${stColor}">${stLabel}</td></tr>
</table>

<hr>

<div class="footer">وثيقة إلكترونية صادرة من ${siteName}</div>

<button class="print-btn" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>

<script>
  window.onload = function(){ setTimeout(function(){ window.print(); }, 500); };
<\/script>
</body></html>`);
  win.document.close();
}

// ── مشاركة الطلب ────────────────────────────────────────────────────────────
function shareOrder(ref, serviceName, price) {
  const text = `📦 طلبي: ${ref}\n🛠 الخدمة: ${serviceName}\n💰 المبلغ: ${price}$\n🔗 ${window.location.href}`;
  if (navigator.share) {
    navigator.share({ title: 'تفاصيل الطلب', text: text }).catch(() => {});
  } else {
    navigator.clipboard.writeText(text).then(() => {
      showToast('✅ تم نسخ تفاصيل الطلب للمشاركة', '#00e676');
    }).catch(() => {
      showToast('⚠️ المشاركة غير مدعومة في هذا المتصفح', '#f5a623');
    });
  }
}

// ── نموذج الاعتراض ──────────────────────────────────────────────────────────
function toggleObjectionForm() {
  const form = document.getElementById('objectionForm');
  if (!form) return;
  form.classList.toggle('open');
  if (form.classList.contains('open')) {
    form.scrollIntoView({ behavior: 'smooth', block: 'end' });
    document.getElementById('objectionReason')?.focus();
  }
}

async function submitOrderObjection(orderId) {
  const reason = document.getElementById('objectionReason')?.value.trim();
  if (!reason || reason.length < 5) { showToast('⚠️ اكتب سبب الاعتراض (5 أحرف على الأقل)', '#f5a623'); return; }

  const btn = document.getElementById('btnSubmitObj');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جارٍ الإرسال...'; }

  const u = new URL(window.location.href);
  u.searchParams.set('order', orderId);

  const fd = new FormData();
  fd.append('submit_objection', '1');
  fd.append('reason', reason);

  try {
    const res  = await fetch(u.toString(), { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('objectionForm')?.remove();
      // استبدل زر الاعتراض بشارة قيد المراجعة
      const btnObj = document.getElementById('btnObjection');
      if (btnObj) {
        btnObj.outerHTML = `<div class="daction-obj-status" style="color:#f5a623;border:1px solid #f5a62333;background:#f5a62311"><i class="fas fa-flag"></i> قيد المراجعة</div>`;
      }
      showToast(data.msg, '#00e676');
    } else {
      showToast('❌ ' + (data.error || 'حدث خطأ'), '#ff4455');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الاعتراض'; }
    }
  } catch(e) {
    showToast('❌ فشل الاتصال بالسيرفر', '#ff4455');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الاعتراض'; }
  }
}

// ── toast إشعار خفيف ────────────────────────────────────────────────────────
function showToast(msg, color) {
  color = color || '#00e676';
  const t = document.createElement('div');
  t.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
    background:${color === '#00e676' ? 'rgba(0,40,20,.97)' : color === '#ff4455' ? 'rgba(40,0,10,.97)' : 'rgba(20,20,0,.97)'};
    border:1px solid ${color};border-radius:14px;padding:12px 20px;
    color:#fff;font-family:Cairo,sans-serif;font-size:.85rem;font-weight:700;
    direction:rtl;z-index:9999;white-space:nowrap;
    box-shadow:0 8px 24px rgba(0,0,0,.5);
    animation:toastIn .25s ease`;
  t.textContent = msg;
  if (!document.getElementById('toastStyle')) {
    const s = document.createElement('style');
    s.id = 'toastStyle';
    s.textContent = '@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(12px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}';
    document.head.appendChild(s);
  }
  document.body.appendChild(t);
  setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .4s'; setTimeout(()=>t.remove(),400); }, 3200);
}

function openD(id) {
  const url = 'orders.php' + (SSTATUS ? '?status='+SSTATUS+'&order='+id : '?order='+id);
  window.location.href = url;
}
function closeD() {
  document.getElementById('doverlay').classList.remove('open');
  const u = new URL(window.location);
  u.searchParams.delete('order');
  window.history.replaceState({}, '', u);
}
function closeDIfBg(e) {
  if (e.target === document.getElementById('doverlay')) closeD();
}

// ════════════════════════════════════════════════════
//  الوضع النهاري / الليلي (نفس منطق الصفحة الرئيسية)
// ════════════════════════════════════════════════════
function toggleTheme() {
  document.body.classList.add('theme-transitioning');
  setTimeout(() => document.body.classList.remove('theme-transitioning'), 400);

  const isLight = document.body.classList.toggle('light-mode');
  localStorage.setItem('njaz_theme', isLight ? 'light' : 'dark');
  updateThemeIcon(isLight);

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = isLight ? '#1e40af' : '#080c1a';
}
function updateThemeIcon(isLight) {
  const icon = document.getElementById('themeIcon');
  if (!icon) return;
  icon.className = isLight ? 'fas fa-sun' : 'fas fa-moon';
}
document.addEventListener('DOMContentLoaded', () => {
  updateThemeIcon(document.body.classList.contains('light-mode'));
});

<?php if($selectedOrder): ?>
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('doverlay').classList.add('open');
  document.getElementById('dpanel').scrollTop = 0;
  // scroll to selected card
  const sel = document.querySelector('.ocard.sel');
  if (sel) sel.scrollIntoView({block:'center',behavior:'smooth'});
});
<?php endif; ?>
</script>

<!-- ══ واجهة الأرقام المباشرة داخل orders.php ══ -->
<div id="naLiveOverlay" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.82);backdrop-filter:blur(6px);align-items:flex-end;justify-content:center;overflow-y:auto" onclick="if(event.target===this&&(_naLive.step==='cancelled'||_naLive.step==='code_received'||_naLive.step==='error'))_naClose()">
  <div id="naLiveSheet" style="background:#0d1117;width:100%;max-width:500px;border-radius:24px 24px 0 0;min-height:300px;margin:0 auto">
    <div id="naLiveContent"></div>
  </div>
</div>

<script>
const NA_API = location.pathname + '?na_action=1';
const NA_HEADERS = {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'};
var _naLive = { step:'idle', orderId:0, number:'', accessId:'', code:'', timer:1200,
                timerInterval:null, autoCheckInterval:null, autoCheck:false, refund:null, svcName:'' };

function _naClose() {
  document.getElementById('naLiveOverlay').style.display = 'none';
  clearInterval(_naLive.timerInterval);
  clearInterval(_naLive.autoCheckInterval);
  if (_naLive.step === 'cancelled' || _naLive.step === 'code_received') {
    setTimeout(() => location.reload(), 600);
  }
}

// ── فتح الواجهة بالبيانات المضمنة مباشرة (بدون fetch) ─────────────────────
function openNumbersLiveLocal(d) {
  if (typeof d === 'string') { try { d = JSON.parse(d); } catch(e) { return; } }

  var overlay = document.getElementById('naLiveOverlay');
  overlay.style.display = 'flex';

  if (d.status === 'completed' && d.code) {
    _naLive = { step:'code_received', orderId:d.order_id, number:d.number,
                accessId:d.access_id, code:d.code, timer:0,
                timerInterval:null, autoCheckInterval:null,
                autoCheck:false, refund:null, svcName:d.svc_name };
    _naRenderOrders();
    return;
  }

  _naLive = {
    step:'waiting', orderId:d.order_id, number:d.number,
    accessId:d.access_id, code:'', timer:d.remaining,
    timerInterval:null, autoCheckInterval:null,
    autoCheck:true, refund:null, svcName:d.svc_name, resumed:true
  };
  _naRenderOrders();
  if (d.remaining > 0) { _naStartTimerOrders(); _naStartAutoCheckOrders(); }
  else { _naAutoCancel(d.order_id); }
}

// ربط زر الفتح — البيانات محفوظة في _naEmbedData
(function(){
  var btn = document.getElementById('naOpenBtn');
  if (btn && typeof _naEmbedData !== 'undefined') {
    btn.addEventListener('click', function(){ openNumbersLiveLocal(_naEmbedData); });
  }
})();

// openNumbersLiveOverlay removed - using openNumbersLiveLocal instead

function _naRenderOrders() {
  var c=document.getElementById('naLiveContent'); if(!c) return;
  var na=_naLive, G='#00e676', C='#00d4ff', R='#ff4757';
  var escH=function(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');};

  if (na.step==='waiting') {
    var timerBase=1200, pct=na.timer/timerBase, circ=2*Math.PI*52, off=circ*(1-pct);
    var tc=na.timer>120?G:na.timer>60?'#f5a623':R;
    var m=Math.floor(na.timer/60), s=na.timer%60;
    var resumeBadge='<div style="margin:0 16px 10px;background:rgba(245,166,35,.08);border:1px solid rgba(245,166,35,.2);border-radius:10px;padding:8px 14px;font-size:11px;color:#f5a623;font-weight:700"><i class="fas fa-history" style="margin-left:5px"></i>استعادة طلب معلق — الوقت يعدّ من لحظة الشراء</div>';
    c.innerHTML=''
      +'<div style="padding:14px 20px 6px;display:flex;align-items:center;justify-content:space-between">'
        +'<div style="font-size:14px;font-weight:900"><i class="fas fa-phone-alt" style="color:'+G+';margin-left:6px"></i> '+escH(na.svcName||'تم شراء الرقم')+'</div>'
        +'<button onclick="_naClose()" style="width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.06);border:none;color:#8fa3bf;cursor:pointer"><i class="fas fa-times" style="font-size:10px"></i></button>'
      +'</div>'
      +resumeBadge
      // الرقم
      +'<div style="margin:0 16px 12px;background:linear-gradient(135deg,#111827,#161d2e);border:1px solid '+G+'33;border-radius:18px;padding:16px;text-align:center;position:relative;overflow:hidden">'
        +'<div style="position:absolute;top:-40px;left:50%;transform:translateX(-50%);width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,'+G+'12,transparent 70%);pointer-events:none"></div>'
        +'<div style="font-size:10px;color:#8fa3bf;margin-bottom:5px;font-weight:700"><i class="fas fa-sim-card" style="margin-left:4px"></i> الرقم</div>'
        +'<div style="font-size:24px;font-weight:900;letter-spacing:2px;color:'+G+';direction:ltr;font-family:monospace;margin-bottom:8px">'+escH(na.number)+'</div>'
        +'<button id="naCopyNumBtn" data-num="'+na.number.replace(/\s/g,'')+'" onclick="_naCopyNum(this)" style="padding:6px 20px;border-radius:10px;border:1px solid '+G+'44;background:rgba(255,255,255,.04);color:#8fa3bf;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fas fa-copy" style="margin-left:4px"></i>نسخ</button>'
      +'</div>'
      // المؤقت
      +'<div style="text-align:center;margin-bottom:14px">'
        +'<div style="position:relative;width:100px;height:100px;margin:0 auto">'
          +'<svg width="100" height="100" viewBox="0 0 120 120" style="transform:rotate(-90deg)">'
            +'<circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="6"/>'
            +'<circle id="naTimerCircle" cx="60" cy="60" r="52" fill="none" stroke="'+tc+'" stroke-width="6" stroke-linecap="round" stroke-dasharray="'+circ+'" stroke-dashoffset="'+off+'" style="transition:stroke-dashoffset 1s linear,stroke .5s ease"/>'
          +'</svg>'
          +'<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">'
            +'<div id="naTimerText" style="font-size:22px;font-weight:900;font-family:monospace;color:'+(na.timer>120?'#fff':tc)+'">'+m+':'+String(s).padStart(2,'0')+'</div>'
            +'<div style="font-size:8px;color:#5a6880">متبقي</div>'
          +'</div>'
        +'</div>'
      +'</div>'
      // كود التفعيل
      +'<div style="margin:0 16px 12px;background:#111827;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:14px">'
        +'<div style="font-size:11px;color:#8fa3bf;font-weight:700;margin-bottom:8px"><i class="fas fa-key" style="margin-left:4px"></i> كود التفعيل</div>'
        +'<div style="background:#0a0e1a;border:2px dashed '+C+'33;border-radius:12px;padding:16px;text-align:center;margin-bottom:10px;min-height:50px;display:flex;align-items:center;justify-content:center">'
          +(na.autoCheck?'<div style="display:flex;align-items:center;gap:8px;color:#5a6880"><i class="fas fa-circle-notch fa-spin" style="color:'+C+'"></i><span style="font-size:12px;font-weight:700">بانتظار الكود...</span></div>':'<div style="color:#5a6880;font-size:12px">اضغط فحص الكود</div>')
        +'</div>'
        +'<div id="naWaitMsg" style="display:none;text-align:center;padding:5px;margin-bottom:6px;border-radius:8px;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.2);font-size:11px;color:#f5a623;font-weight:700"></div>'
        +'<div style="display:flex;gap:8px">'
          +'<button id="naCheckBtn" onclick="_naCheckCodeOrders(false)" style="width:100%;padding:11px;border-radius:12px;border:none;background:linear-gradient(135deg,'+C+',#0066ff);color:#fff;font-size:13px;font-weight:800;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;box-shadow:0 4px 14px '+C+'30"><i class="fas fa-search"></i> فحص الكود</button>'
        +'</div>'
        +(na.autoCheck?'<div style="margin-top:6px;padding:4px;border-radius:8px;background:'+G+'0d;border:1px solid '+G+'22;font-size:9px;color:'+G+';font-weight:700;text-align:center"><i class="fas fa-sync fa-spin" style="font-size:8px;margin-left:3px"></i> فحص تلقائي كل 5 ثوانٍ</div>':'')
      +'</div>';
    // زر الإلغاء
    var canCancel=(na.timer<=0);
    var cancelBtn=canCancel
      ?'<button id="naCancelBtn" onclick="_naCancelOrders()" style="width:100%;padding:12px;border-radius:14px;border:1.5px solid '+R+'33;background:'+R+'10;color:'+R+';font-size:12px;font-weight:800;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px"><i class="fas fa-times-circle"></i> إلغاء واسترداد</button>'
      :'<div style="width:100%;padding:12px;border-radius:14px;border:1.5px solid rgba(255,255,255,.07);background:rgba(255,255,255,.02);color:#5a6880;font-size:11px;font-weight:700;text-align:center;box-sizing:border-box"><i class="fas fa-lock" style="margin-left:5px"></i> الإلغاء متاح بعد انتهاء الوقت بدون استلام كود</div>';
    c.innerHTML+='<div style="padding:0 16px 18px">'+cancelBtn+'</div>';

  } else if (na.step==='code_received') {
    c.innerHTML='<div style="padding:18px 16px;text-align:center">'
      +'<div style="width:80px;height:80px;margin:0 auto 14px;background:linear-gradient(135deg,'+G+'25,'+G+'08);border:2px solid '+G+'50;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-check-circle" style="font-size:38px;color:'+G+'"></i></div>'
      +'<div style="font-size:20px;font-weight:900;margin-bottom:4px">تم استلام الكود! 🎉</div>'
      +'<div style="font-size:11px;color:#8fa3bf;margin-bottom:16px">'+escH(na.svcName||'')+'</div>'
      +'<div style="background:#111827;border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:10px 14px;margin-bottom:8px;display:flex;align-items:center">'
        +'<div style="flex:1;text-align:right"><div style="font-size:8px;color:#5a6880">الرقم</div><div style="font-size:13px;font-weight:800;direction:ltr;color:#8fa3bf;font-family:monospace">'+escH(na.number)+'</div></div>'
        +'<button id="naCopyNum2" data-num="'+na.number.replace(/\s/g,'')+'" onclick="_naCopyNum(this)" style="padding:4px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.07);background:rgba(255,255,255,.04);color:#8fa3bf;font-size:10px;cursor:pointer;font-family:inherit"><i class="fas fa-copy"></i></button>'
      +'</div>'
      +'<div style="background:linear-gradient(135deg,'+G+'12,'+C+'08);border:2px solid '+G+'44;border-radius:18px;padding:20px 16px;margin-bottom:14px">'
        +'<div style="font-size:10px;color:'+G+';font-weight:700;margin-bottom:6px"><i class="fas fa-key" style="margin-left:4px"></i> كود التفعيل</div>'
        +'<div style="font-size:38px;font-weight:900;letter-spacing:10px;color:#fff;font-family:monospace;direction:ltr;text-shadow:0 0 30px '+G+'40;margin-bottom:10px">'+escH(na.code)+'</div>'
        +'<button id="naCopyCode" data-code="'+escH(na.code)+'" onclick="_naCopyCode(this)" style="padding:10px 26px;border-radius:12px;border:none;background:linear-gradient(135deg,'+G+',#00c853);color:#fff;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;box-shadow:0 4px 16px '+G+'40"><i class="fas fa-copy" style="margin-left:6px"></i> نسخ الكود</button>'
      +'</div>'
      +'<button onclick="_naClose()" style="width:100%;padding:13px;border-radius:14px;border:1px solid rgba(255,255,255,.07);background:#111827;color:#8fa3bf;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer"><i class="fas fa-check" style="margin-left:6px"></i> تم</button>'
    +'</div>';

  } else if (na.step==='cancelled') {
    c.innerHTML='<div style="padding:18px 16px;text-align:center">'
      +'<div style="width:75px;height:75px;margin:14px auto;background:'+R+'15;border:2px solid '+R+'33;border-radius:50%;display:flex;align-items:center;justify-content:center"><i class="fas fa-undo" style="font-size:30px;color:'+R+'"></i></div>'
      +'<div style="font-size:18px;font-weight:900;margin-bottom:6px">تم إلغاء الطلب</div>'
      +(na.refund?'<div style="background:'+G+'12;border:1px solid '+G+'33;border-radius:14px;padding:12px 16px;margin:12px 0;display:flex;align-items:center;justify-content:center;gap:10px"><i class="fas fa-wallet" style="color:'+G+';font-size:16px"></i><div><div style="font-size:10px;color:#8fa3bf">تم استرداد</div><div style="font-size:18px;font-weight:900;color:'+G+'">'+na.refund+'</div></div></div>':'')
      +'<button onclick="_naClose()" style="width:100%;padding:13px;border-radius:14px;border:none;background:linear-gradient(135deg,'+C+',#0066ff);color:#fff;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;margin-top:6px;box-shadow:0 6px 20px '+C+'30"><i class="fas fa-times" style="margin-left:6px"></i> إغلاق</button>'
    +'</div>';
  }
}

function _naStartTimerOrders() {
  clearInterval(_naLive.timerInterval);
  _naLive.timerInterval = setInterval(function() {
    _naLive.timer--;
    var el=document.getElementById('naTimerText'), circle=document.getElementById('naTimerCircle');
    var G='#00e676', R='#ff4757';
    if (el) { var m=Math.floor(_naLive.timer/60),s=_naLive.timer%60; el.textContent=m+':'+String(s).padStart(2,'0'); el.style.color=_naLive.timer>120?'#fff':_naLive.timer>60?'#f5a623':R; }
    if (circle) { var circ=2*Math.PI*52; circle.style.strokeDashoffset=circ*(1-_naLive.timer/1200); circle.style.stroke=_naLive.timer>120?G:_naLive.timer>60?'#f5a623':R; }
    if (_naLive.timer<=0) { clearInterval(_naLive.timerInterval); clearInterval(_naLive.autoCheckInterval); _naLive.autoCheck=false; _naAutoCancel(_naLive.orderId); }
  }, 1000);
}

// ── دوال مساعدة للنسخ ─────────────────────────────────────────────────────
function _naCopyNum(btn) {
  var num = btn.dataset.num;
  navigator.clipboard.writeText(num).then(function() {
    btn.innerHTML = '<i class="fas fa-check"></i> تم';
    setTimeout(function() { btn.innerHTML = '<i class="fas fa-copy" style="margin-left:4px"></i>نسخ'; }, 1500);
  }).catch(function() { btn.textContent = '✓'; });
}
function _naCopyCode(btn) {
  var code = btn.dataset.code;
  navigator.clipboard.writeText(code).then(function() {
    btn.textContent = '✓ تم النسخ';
    btn.style.background = 'linear-gradient(135deg,#00c853,#00e676)';
  }).catch(function() { btn.textContent = '✓'; });
}

function _naStartAutoCheckOrders() {
  clearInterval(_naLive.autoCheckInterval);
  if (!_naLive.autoCheck) return;
  _naLive.autoCheckInterval = setInterval(function(){ _naCheckCodeOrders(true); }, 5000);
}

async function _naCheckCodeOrders(silent) {
  if (_naLive.step!=='waiting') return;
  var btn=document.getElementById('naCheckBtn');
  if (btn&&!silent) btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جاري الفحص...';
  try {
    var r=await fetch(NA_API+'?action=check&order_id='+_naLive.orderId,{credentials:'same-origin',headers:NA_HEADERS});
    if (r.status===404) {
      clearInterval(_naLive.autoCheckInterval); _naLive.autoCheck=false;
      if (!silent&&btn) btn.innerHTML='<i class="fas fa-search"></i> فحص الكود';
      return;
    }
    var d=await r.json();
    if (d.ok&&d.status==='completed'&&d.code) {
      _naLive.code=d.code; _naLive.step='code_received'; _naLive.autoCheck=false;
      clearInterval(_naLive.timerInterval); clearInterval(_naLive.autoCheckInterval);
      _naRenderOrders(); return;
    }
    if (!silent){var msg=document.getElementById('naWaitMsg');if(msg){msg.style.display='block';msg.textContent='⏳ لم يصل الكود بعد...';setTimeout(()=>{msg.style.display='none';},2500);}}
  } catch(e){}
  if (!silent&&btn) btn.innerHTML='<i class="fas fa-search"></i> فحص الكود';
}

async function _naCancelOrders() {
  var btn=document.getElementById('naCancelBtn');
  if(btn) btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جاري الإلغاء...';
  try {
    var fd=new FormData();
    fd.append('action','cancel');
    fd.append('order_id',_naLive.orderId);
    var r=await fetch(NA_API,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    var d=await r.json();
    if(d.ok){
      _naLive.step='cancelled';
      _naLive.refund=d.refund;
      _naLive.autoCheck=false;
      clearInterval(_naLive.timerInterval);
      clearInterval(_naLive.autoCheckInterval);
      _naRenderOrders();
    }
  } catch(e){}
}

async function _naAutoCancel(orderId) {
  try {
    var fd=new FormData(); fd.append('action','auto_cancel'); fd.append('order_id',orderId);
    var r=await fetch(NA_API,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    var d=await r.json();
    if(!d.ok) return;
    if(d.action_taken==='completed'){_naLive.code=d.code;_naLive.step='code_received';}
    else{_naLive.step='cancelled';_naLive.refund=d.refund;}
    _naLive.autoCheck=false;
    clearInterval(_naLive.timerInterval); clearInterval(_naLive.autoCheckInterval);
    _naRenderOrders();
  } catch(e){}
}
</script>

</body>
</html>
