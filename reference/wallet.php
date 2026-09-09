<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/payment_method_currency_helper.php';
try { paymentMethodCurrencyEnsureSchema($pdo); } catch (Throwable $e) { error_log('Payment method currency schema bootstrap in wallet.php: ' . $e->getMessage()); }
requireLogin();
$pageTitle = 'محفظتي - ' . SITE_NAME;

$user        = getUser();
$userBalance = $user['balance'];
$currSymbol  = getSetting('currency_symbol') ?: '$';
$siteName    = getSetting('site_name') ?: SITE_NAME;

// جلب المعاملات
$stmt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 60");
$stmt->execute([$_SESSION['user_id']]);
$transactions = $stmt->fetchAll();

// إحصائيات
$positiveTypesGlobal = ['credit','topup','refund','prize','referral','referral_welcome'];
$totalCredit = array_sum(array_column(array_filter($transactions, function($t) use ($positiveTypesGlobal) { return in_array($t['type'], $positiveTypesGlobal); }), 'amount'));
$totalDebit  = array_sum(array_column(array_filter($transactions, function($t) use ($positiveTypesGlobal) { return !in_array($t['type'], $positiveTypesGlobal); }), 'amount'));

// جلب الهدايا المرتبطة بالمعاملات
$giftsMap = [];
try {
    $gs = $pdo->prepare("
        SELECT g.*, 
               s.username as sender_username, s.full_name as sender_name,
               r.username as receiver_username, r.full_name as receiver_name
        FROM gifts g
        LEFT JOIN users s ON g.sender_id=s.id
        LEFT JOIN users r ON g.receiver_id=r.id
        WHERE g.sender_id=? OR g.receiver_id=?
        ORDER BY g.created_at DESC
    ");
    $gs->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    foreach ($gs->fetchAll() as $g) {
        $giftsMap[$g['id']] = $g;
    }
} catch(Exception $e){}

// طرق الدفع لشحن الرصيد
$paymentMethods = [];
$exchangeRates  = [];
try {
    $pm = $pdo->query("SELECT m.*, GROUP_CONCAT(CONCAT(f.field_label,'||',f.field_value,'||',f.copyable) ORDER BY f.sort_order SEPARATOR ';;') as fields_raw FROM payment_methods m LEFT JOIN payment_method_fields f ON m.id=f.method_id WHERE m.status=1 GROUP BY m.id ORDER BY m.sort_order");
    $paymentMethods = $pm->fetchAll();
    $er = $pdo->query("SELECT * FROM exchange_rates WHERE status=1 ORDER BY sort_order");
    $exchangeRates  = $er->fetchAll();
    $methodCurrencyMap = paymentMethodCurrencyMap($pdo, array_map(fn($m) => (int)$m['id'], $paymentMethods));
    foreach ($paymentMethods as &$methodRow) $methodRow['allowed_currency_codes'] = $methodCurrencyMap[(int)$methodRow['id'] ?? 0] ?? [];
    unset($methodRow);
} catch(\PDOException $e) {}

// إشعارات غير مقروءة
$unreadNotifCount = 0;
try {
    $ns = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $ns->execute([$_SESSION['user_id']]);
    $unreadNotifCount = (int)$ns->fetchColumn();
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#080c1a">
<title><?= htmlspecialchars($siteName) ?> — محفظتي</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;
  --border:rgba(255,255,255,0.07);--primary:#1e6fff;--primary2:#0d4fd4;
  --cyan:#00d4ff;--gold:#f5a623;--green:#00e676;--red:#ff1744;
  --text:#ffffff;--text2:#8fa3bf;--text3:#4d6080;
  --radius:18px;--radius-sm:12px;--shadow:0 8px 32px rgba(0,0,0,0.5);
  --font:'Cairo',sans-serif;--nav-h:64px;--header-h:60px;
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{height:100%;overflow:hidden;background:var(--bg);font-family:var(--font);color:var(--text);direction:rtl}

/* ══ APP SHELL ══ */
#app{width:100%;max-width:430px;height:100dvh;margin:0 auto;display:flex;flex-direction:column;position:relative;overflow:hidden;background:var(--bg);box-shadow:0 0 80px rgba(0,0,0,0.8)}
#mainContent{flex:1;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch}
#mainContent::-webkit-scrollbar{display:none}

/* ══ HEADER ══ */
.top-header{height:var(--header-h);background:linear-gradient(135deg,#0d1428 0%,#0a1535 100%);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;flex-shrink:0}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 12px rgba(30,111,255,0.4)}
.logo-text{font-size:18px;font-weight:900;background:linear-gradient(90deg,#fff 0%,var(--cyan) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.header-right{display:flex;align-items:center;gap:10px}
.header-btn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:16px;transition:all .2s;position:relative;text-decoration:none;border:none;font-family:var(--font)}
.header-btn:active{transform:scale(.92)}
.notif-dot{position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--red);border-radius:50%;border:1.5px solid var(--bg)}

/* ══ BOTTOM NAV ══ */
.bottom-nav{height:var(--nav-h);background:linear-gradient(180deg,rgba(13,20,40,0.97) 0%,#080c1a 100%);border-top:1px solid var(--border);display:flex;align-items:center;flex-shrink:0;position:relative;z-index:200}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;padding:8px 0;transition:all .2s;position:relative}
.nav-item:active{transform:scale(.9)}
.nav-icon{font-size:20px;color:var(--text3);transition:all .2s}
.nav-label{font-size:10px;color:var(--text3);font-weight:600;transition:all .2s}
.nav-item.active .nav-icon,.nav-item.active .nav-label{color:var(--primary)}
.nav-item.active::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:30px;height:2px;border-radius:0 0 3px 3px;background:var(--primary)}
.nav-center{flex:1;display:flex;justify-content:center}
.nav-center-btn{width:52px;height:52px;background:linear-gradient(135deg,var(--primary),#0a3fbe);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;cursor:pointer;box-shadow:0 4px 16px rgba(30,111,255,0.5);margin-top:-14px;transition:transform .2s;border:3px solid var(--bg)}
.nav-center-btn:active{transform:scale(.9)}

/* ══ WALLET PAGE ══ */
.wallet-wrap{padding:14px 12px}
.wallet-card{background:linear-gradient(135deg,var(--primary2) 0%,#0a1540 100%);border-radius:var(--radius);padding:24px 20px;margin-bottom:16px;position:relative;overflow:hidden}
.wallet-card::before{content:'';position:absolute;top:-40%;right:-20%;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(0,212,255,0.15) 0%,transparent 70%)}
.wallet-card::after{content:'💰';position:absolute;bottom:-10px;left:-10px;font-size:80px;opacity:.05;transform:rotate(-15deg)}
.wallet-balance-label{font-size:12px;color:rgba(255,255,255,0.6);margin-bottom:4px}
.wallet-balance-amount{font-size:42px;font-weight:900;line-height:1}
.wallet-balance-cur{font-size:14px;color:rgba(255,255,255,0.7);margin-right:4px}
.wallet-uid{font-size:11px;color:rgba(255,255,255,0.4);margin-top:6px}
.wallet-actions{display:flex;gap:10px;margin-top:18px}
.wallet-action-btn{flex:1;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.18);border-radius:12px;padding:11px 8px;color:#fff;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;text-decoration:none}
.wallet-action-btn:active{background:rgba(255,255,255,0.2);transform:scale(.97)}

/* ══ STATS ROW ══ */
.stats-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px}
.stat-label{font-size:11px;color:var(--text3);margin-bottom:4px;display:flex;align-items:center;gap:5px}
.stat-val{font-size:20px;font-weight:900}
.stat-val.green{color:var(--green)}
.stat-val.red{color:var(--red)}

/* ══ TRANSACTIONS ══ */
.sec-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.sec-title{font-size:14px;font-weight:800}
.tx-count{font-size:11px;color:var(--text3);background:var(--card2);padding:3px 8px;border-radius:20px}
.tx-list{display:flex;flex-direction:column;gap:2px}
.tx-item{display:flex;align-items:center;gap:12px;padding:13px 12px;background:var(--card);border-radius:var(--radius-sm);cursor:default;transition:background .15s}
.tx-item:active{background:var(--card2)}
.tx-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.tx-icon.credit{background:rgba(0,230,118,0.12);color:var(--green)}
.tx-icon.debit{background:rgba(255,23,68,0.12);color:var(--red)}
.tx-info{flex:1;min-width:0}
.tx-name{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tx-date{font-size:10px;color:var(--text3);margin-top:2px}
.tx-right{text-align:left;flex-shrink:0}
.tx-amount{font-size:15px;font-weight:900}
.tx-amount.credit{color:var(--green)}
.tx-amount.debit{color:var(--red)}
.tx-balance{font-size:10px;color:var(--text3);margin-top:1px}

/* فلتر */
.tx-filter{display:flex;gap:6px;margin-bottom:12px}
.tx-filter-btn{flex:1;padding:7px 0;background:var(--card);border:1px solid var(--border);border-radius:10px;color:var(--text2);font-size:11px;font-weight:700;cursor:pointer;text-align:center;font-family:var(--font);transition:all .2s}
.tx-filter-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}

.empty-state-app{text-align:center;padding:40px 20px;color:var(--text3)}
.empty-state-app i{font-size:40px;display:block;margin-bottom:10px;opacity:.25}
.empty-state-app p{font-size:13px}

/* ══ TOPUP SHEET ══ */
.topup-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:500;display:none;transition:background .3s;max-width:430px;margin:0 auto}
.topup-overlay.show{background:rgba(0,0,0,0.7);display:block}
.topup-drawer{position:absolute;bottom:0;left:0;right:0;background:var(--card);border-radius:24px 24px 0 0;max-height:92dvh;overflow-y:auto;transform:translateY(100%);transition:transform .35s cubic-bezier(.32,1.15,.7,1)}
.topup-drawer::-webkit-scrollbar{display:none}
.topup-overlay.show .topup-drawer{transform:translateY(0)}
.topup-drag-bar{width:40px;height:4px;background:var(--border);border-radius:2px;margin:12px auto 0}
.topup-header{padding:14px 16px 12px;display:flex;align-items:center;gap:10px}
.topup-header-title{font-size:1rem;font-weight:900}
.topup-header-sub{font-size:.75rem;color:#8895a7;margin-top:1px}
.topup-close-btn{width:34px;height:34px;background:var(--card2);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:14px;margin-right:auto;font-family:var(--font)}
.topup-back-btn{width:34px;height:34px;background:var(--card2);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:14px;font-family:var(--font)}
.topup-balance-strip{display:flex;justify-content:space-between;align-items:center;background:rgba(0,212,170,.06);border-top:1px solid rgba(0,212,170,.12);border-bottom:1px solid rgba(0,212,170,.12);padding:8px 16px;margin:0 0 4px}
.topup-method-item{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s}
.topup-method-item:active{background:var(--card2)}
.topup-method-thumb{width:42px;height:42px;border-radius:12px;background:rgba(var(--mc,30,111,255),0.15);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--primary);flex-shrink:0}
.topup-method-info{flex:1}
.topup-method-name{font-size:14px;font-weight:700}
.topup-method-desc{font-size:11px;color:var(--text2);margin-top:2px}
.topup-method-arrow{color:var(--text3);font-size:13px}
.topup-method-item.selected{background:rgba(30,111,255,0.08)}
.topup-mode-tabs{display:flex;gap:8px;padding:12px 16px 4px}
.topup-mode-tab{flex:1;padding:10px 8px;border-radius:12px;border:1.5px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:4px}
.topup-mode-tab.active{border-color:var(--primary);background:rgba(30,111,255,.1);color:var(--primary)}
.topup-mode-tab .tab-icon{font-size:20px}
.topup-mode-tab .tab-lbl{font-size:11px}
.topup-mode-desc{font-size:11px;color:var(--text3);padding:0 16px 10px;line-height:1.5;text-align:center}
.topup-empty{text-align:center;padding:30px 16px;color:var(--text3)}
.topup-empty i{font-size:2rem;margin-bottom:8px;display:block;opacity:.3}
.topup-empty p{font-size:13px;font-weight:700;margin-bottom:3px;color:var(--text2)}
.topup-empty span{font-size:11px}
.topup-account-card{background:var(--card2);border-radius:var(--radius-sm);margin:0 12px 4px;overflow:hidden}
.topup-account-header{padding:12px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02)}
.topup-account-icon{width:36px;height:36px;background:rgba(30,111,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--primary);flex-shrink:0}
.topup-account-title{font-size:13px;font-weight:800}
.topup-fields-list{padding:6px 0}
.topup-field-row{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border)}
.topup-field-row:last-child{border:none}
.topup-field-label{font-size:11px;color:var(--text2)}
.topup-field-value{font-size:13px;font-weight:700;display:flex;align-items:center;gap:6px;direction:ltr;unicode-bidi:embed}
.topup-copy-btn{background:rgba(30,111,255,0.15);border:none;border-radius:6px;padding:3px 8px;color:var(--primary);font-size:11px;cursor:pointer;font-family:var(--font)}
.topup-calc-section{padding:0 12px 4px}
.topup-section-label{font-size:.75rem;color:#8895a7;font-weight:800;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.topup-currency-row{display:flex;gap:8px;margin-bottom:12px;overflow-x:auto;padding-bottom:2px}
.topup-currency-row::-webkit-scrollbar{display:none}
.topup-currency-chip{display:flex;flex-direction:column;align-items:center;padding:8px 16px;background:var(--card2);border:1.5px solid var(--border);border-radius:12px;cursor:pointer;transition:all .2s;flex-shrink:0}
.topup-currency-chip.active{border-color:var(--primary);background:rgba(30,111,255,0.1)}
.topup-chip-symbol{font-size:16px;font-weight:900;color:var(--text)}
.topup-chip-code{font-size:10px;color:var(--text2);margin-top:1px}
.topup-amount-wrap{display:flex;align-items:center;background:var(--card2);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:10px}
.topup-amount-currency{padding:0 14px;font-size:15px;font-weight:900;color:var(--text2);border-left:1px solid var(--border)}
.topup-amount-field{flex:1;background:none;border:none;color:var(--text);font-family:var(--font);font-size:22px;font-weight:900;padding:14px 12px;outline:none;direction:ltr}
.topup-amount-field::placeholder{color:var(--text3)}
.topup-result-card{background:rgba(0,212,170,0.05);border:1px solid rgba(0,212,170,0.15);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:12px}
.topup-result-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.topup-result-lbl{font-size:12px;color:var(--text2)}
.topup-result-val{font-size:18px;font-weight:900;color:#00d4aa}
.topup-result-bar{height:4px;background:var(--border);border-radius:2px;overflow:hidden}
.topup-result-bar-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--primary),var(--cyan));transition:width .4s}
.topup-upload-section{padding:0 12px 12px}
.topup-upload-zone{display:flex;flex-direction:column;align-items:center;padding:18px;background:var(--card2);border:2px dashed var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:border-color .2s;text-align:center}
.topup-upload-zone:hover{border-color:var(--primary)}
.topup-upload-icon{font-size:24px;color:var(--primary);margin-bottom:6px}
.topup-upload-text{font-size:13px;font-weight:700;margin-bottom:2px}
.topup-upload-hint{font-size:11px;color:var(--text2)}
.topup-receipt-preview{width:100%;border-radius:12px;margin-top:8px;max-height:180px;object-fit:cover}
.topup-note-input{width:100%;background:var(--card2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:12px 14px;color:var(--text);font-family:var(--font);font-size:13px;outline:none}
.topup-submit-btn{width:100%;padding:16px;background:linear-gradient(135deg,var(--primary),var(--primary2));border:none;border-radius:var(--radius-sm);color:#fff;font-family:var(--font);font-size:15px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 16px rgba(30,111,255,0.4);transition:opacity .2s}
.topup-submit-btn:active{opacity:.85}
</style>
</head>
<body>
<div id="app">
  <!-- HEADER -->
  <div class="top-header">
    <div class="header-logo">
      <button class="header-btn" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'" style="background:none;border:none">
        <i class="fas fa-arrow-right"></i>
      </button>
      <div class="logo-icon">💰</div>
      <div class="logo-text">محفظتي</div>
    </div>
    <div class="header-right">
      <a href="<?= SITE_URL ?>/mobile.php" class="header-btn" title="الرئيسية">
        <i class="fas fa-home"></i>
      </a>
      <a href="<?= SITE_URL ?>/notifications.php" class="header-btn" style="position:relative" title="الإشعارات">
        <i class="fas fa-bell"></i>
        <?php if ($unreadNotifCount > 0): ?>
        <span class="notif-dot"></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <!-- MAIN CONTENT -->
  <div id="mainContent">
    <div class="wallet-wrap">

      <!-- بطاقة الرصيد -->
      <div class="wallet-card">
        <div class="wallet-balance-label">رصيدي الحالي</div>
        <div>
          <span class="wallet-balance-amount"><?= number_format($userBalance, 2) ?></span>
          <span class="wallet-balance-cur"><?= htmlspecialchars($currSymbol) ?></span>
        </div>
        <div class="wallet-uid"><i class="fas fa-user-circle"></i> <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="wallet-actions">
          <button class="wallet-action-btn" onclick="openTopupSheet()">
            <i class="fas fa-plus-circle"></i> شحن الرصيد
          </button>
          <a href="<?= SITE_URL ?>/orders.php" class="wallet-action-btn">
            <i class="fas fa-shopping-bag"></i> طلباتي
          </a>
        </div>
      </div>

      <!-- إحصائيات -->
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-arrow-down" style="color:var(--green)"></i> إجمالي الإيداعات</div>
          <div class="stat-val green">+<?= number_format($totalCredit, 2) ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label"><i class="fas fa-arrow-up" style="color:var(--red)"></i> إجمالي المصروف</div>
          <div class="stat-val red">-<?= number_format($totalDebit, 2) ?></div>
        </div>
      </div>

      <!-- سجل المعاملات -->
      <div class="sec-title-row">
        <div class="sec-title"><i class="fas fa-history" style="color:var(--primary)"></i> سجل المعاملات</div>
        <span class="tx-count"><?= count($transactions) ?> عملية</span>
      </div>

      <?php if (!empty($transactions)): ?>
      <!-- فلتر -->
      <div class="tx-filter">
        <button class="tx-filter-btn active" id="tfAll"    onclick="filterTx('all')">الكل</button>
        <button class="tx-filter-btn"        id="tfCredit" onclick="filterTx('credit')"><i class="fas fa-plus" style="color:var(--green)"></i> إيداع</button>
        <button class="tx-filter-btn"        id="tfDebit"  onclick="filterTx('debit')"><i class="fas fa-minus" style="color:var(--red)"></i> خصم</button>
      </div>

      <div class="tx-list" id="txList">
        <?php foreach ($transactions as $tx): ?>
        <?php
            $positiveTypes = ['credit','topup','refund','prize','referral','referral_welcome'];
            $txIsPlus = in_array($tx['type'], $positiveTypes);
        ?>
        <?php
          // كشف إذا كانت العملية هدية
          $desc = $tx['description'] ?? '';
          $isGiftTx = strpos($desc,'🎁') !== false || strpos($desc,'هدية') !== false;
          $giftData = null;
          if ($isGiftTx) {
              // ابحث عن هدية قريبة من وقت العملية
              foreach ($giftsMap as $g) {
                  $timeDiff = abs(strtotime($tx['created_at']) - strtotime($g['created_at']));
                  if ($timeDiff < 10 && abs((float)$g['amount'] - (float)$tx['amount']) < 0.01) {
                      $giftData = $g; break;
                  }
              }
              if (!$giftData) {
                  // مطابقة بالمبلغ فقط
                  foreach ($giftsMap as $g) {
                      $timeDiff = abs(strtotime($tx['created_at']) - strtotime($g['created_at']));
                      if ($timeDiff < 120 && abs((float)$g['amount'] - (float)$tx['amount']) < 0.01) {
                          $giftData = $g; break;
                      }
                  }
              }
          }
          $giftJson = $giftData ? htmlspecialchars(json_encode([
              'id'         => $giftData['id'],
              'amount'     => $giftData['amount'],
              'message'    => $giftData['message'] ?? '',
              'status'     => $giftData['status'],
              'created_at' => $giftData['created_at'],
              'sender_name'   => $giftData['sender_name']   ?: $giftData['sender_username'],
              'receiver_name' => $giftData['receiver_name'] ?: $giftData['receiver_username'],
              'is_sender'  => (int)$giftData['sender_id'] === (int)$_SESSION['user_id'],
          ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) : '';
        ?>
        <div class="tx-item" data-type="<?= $txIsPlus ? 'credit' : 'debit' ?>" data-original-type="<?= $tx['type'] ?>"
             <?= $isGiftTx ? 'onclick="showGiftDetail('.($giftJson ? "'".$giftJson."'" : 'null').','.json_encode($tx['description']).','.$tx['amount'].')" style="cursor:pointer"' : '' ?>>
          <?php
            $txIconMap = [
              'prize'           => 'trophy',
              'referral'        => 'users',
              'referral_welcome'=> 'gift',
              'referral_revoke' => 'undo',
              'refund'          => 'undo',
              'credit'          => 'arrow-down',
              'topup'           => 'arrow-down',
            ];
            $txIcon2 = $txIconMap[$tx['type']] ?? ($txIsPlus ? 'arrow-down' : 'arrow-up');
            $txColorMap = [
              'prize'           => '#f5a623',
              'referral'        => '#00c853',
              'referral_welcome'=> '#7c3aed',
              'referral_revoke' => '#ff4455',
              'refund'          => '#00e676',
              'credit'          => '#00e676',
              'topup'           => '#00e676',
            ];
            $txColor2 = $txColorMap[$tx['type']] ?? ($txIsPlus ? '#00e676' : '#ff4455');
          ?>
          <div class="tx-icon <?= $txIsPlus ? 'credit' : 'debit' ?>">
            <i class="fas fa-<?= $txIcon2 ?>"></i>
          </div>
          <div class="tx-info">
            <div class="tx-name"><?= htmlspecialchars($tx['description'] ?: ($txIsPlus ? 'إيداع رصيد' : 'خصم رصيد')) ?></div>
            <div class="tx-date"><i class="fas fa-clock"></i> <?= date('Y/m/d • h:i A', strtotime($tx['created_at'])) ?></div>
          </div>
          <div class="tx-right">
            <?php if($isGiftTx): ?>
            <div style="font-size:.65rem;background:rgba(255,107,157,.15);color:#ff6b9d;padding:2px 8px;border-radius:6px;margin-bottom:4px;text-align:center">🎁 هدية</div>
            <?php endif; ?>
            <div class="tx-amount <?= $txIsPlus ? 'credit' : 'debit' ?>">
              <?= $txIsPlus ? '+' : '-' ?><?php
                // عرض دقيق: إذا كانت قيمة صغيرة (عمولة إحالة) نعرضها بـ 4 أرقام
                $dispAmt = (float)$tx['amount'];
                // عمولات الإحالة تُعرض بـ 4 أرقام دائماً
                if (!empty($tx['type']) && strpos($tx['type'], 'referral') !== false) {
                    echo number_format($dispAmt, 4);
                } else {
                    echo number_format($dispAmt, 2);
                }
              ?>
            </div>
            <div class="tx-balance">الرصيد: <?php
                $dispBal = (float)$tx['balance_after'];
                echo number_format($dispBal, 2);
              ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state-app">
        <i class="fas fa-exchange-alt"></i>
        <p>لا توجد معاملات حتى الآن</p>
      </div>
      <?php endif; ?>

      <div style="height:20px"></div>
    </div>
  </div>

  <!-- BOTTOM NAV -->
  <div class="bottom-nav">
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'">
      <div class="nav-icon"><i class="fas fa-home"></i></div>
      <div class="nav-label">الرئيسية</div>
    </div>
    <div class="nav-item active">
      <div class="nav-icon"><i class="fas fa-wallet"></i></div>
      <div class="nav-label">محفظتي</div>
    </div>
    <div class="nav-center">
      <div class="nav-center-btn" onclick="window.location.href='<?= SITE_URL ?>/services.php'">
        <i class="fas fa-th-large"></i>
      </div>
    </div>
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/orders.php'">
      <div class="nav-icon"><i class="fas fa-shopping-bag"></i></div>
      <div class="nav-label">طلباتي</div>
    </div>
    <div class="nav-item" onclick="window.location.href='<?= SITE_URL ?>/mobile.php'">
      <div class="nav-icon"><i class="fas fa-user-circle"></i></div>
      <div class="nav-label">حسابي</div>
    </div>
  </div>

  <!-- TOPUP SHEET -->
  <div class="topup-overlay" id="topupSheetOverlay" onclick="closeTopupIfBg(event)">
    <div class="topup-drawer" id="topupSheet">

      <!-- Step 1 -->
      <div id="topupStep1">
        <div class="topup-drag-bar"></div>
        <div class="topup-header">
          <div>
            <div class="topup-header-title">شحن الرصيد</div>
            <div class="topup-header-sub">اختر طريقة الدفع</div>
          </div>
          <button class="topup-close-btn" onclick="closeTopupSheet()"><i class="fas fa-times"></i></button>
        </div>
        <div class="topup-balance-strip">
          <span style="color:#8895a7;font-size:.8rem">رصيدك الحالي</span>
          <span style="font-weight:900;color:#00d4aa;font-size:1rem"><?= number_format($userBalance, 2) ?> <?= htmlspecialchars($currSymbol) ?></span>
        </div>
        <?php
        $manualMethods = array_filter($paymentMethods, fn($m) => ($m['payment_mode']??'manual') === 'manual');
        $autoMethods   = array_filter($paymentMethods, fn($m) => ($m['payment_mode']??'manual') === 'auto');
        $hasAuto = !empty($autoMethods);
        $hasManual = !empty($manualMethods);
        ?>
        <!-- Tabs -->
        <div class="topup-mode-tabs">
          <button class="topup-mode-tab active" id="tabManualBtn" onclick="switchTopupMode('manual')">
            <span class="tab-icon">🏦</span>
            <span class="tab-lbl">دفع يدوي</span>
          </button>
          <button class="topup-mode-tab" id="tabAutoBtn" onclick="switchTopupMode('auto')">
            <span class="tab-icon">⚡</span>
            <span class="tab-lbl">دفع مباشر</span>
          </button>
        </div>

        <!-- وصف القسم -->
        <div class="topup-mode-desc" id="modeDesc">أرسل الحوالة وارفع الإيصال — تُفعَّل بعد مراجعة الإدارة</div>

        <!-- يدوي -->
        <div id="methodsList-manual">
          <?php if (empty($manualMethods)): ?>
          <div class="topup-empty"><i class="fas fa-credit-card"></i><p>لا توجد طرق دفع يدوية</p></div>
          <?php else: foreach ($manualMethods as $pm): ?>
          <div class="topup-method-item" onclick="selectMethod(<?= $pm['id'] ?>, this)" data-method-id="<?= $pm['id'] ?>" data-mode="manual">
            <div class="topup-method-thumb" style="--mc:<?= htmlspecialchars($pm['color']) ?>">
              <?php if (!empty($pm['image'])): ?>
              <img src="<?= SITE_URL ?>/<?= htmlspecialchars($pm['image']) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
              <?php else: ?>
              <i class="fas fa-<?= htmlspecialchars($pm['icon']) ?>" style="color:<?= htmlspecialchars($pm['color']) ?>"></i>
              <?php endif; ?>
            </div>
            <div class="topup-method-info">
              <div class="topup-method-name"><?= htmlspecialchars($pm['name']) ?></div>
              <?php if ($pm['description']): ?>
              <div class="topup-method-desc"><?= htmlspecialchars(mb_substr($pm['description'], 0, 50)) ?></div>
              <?php endif; ?>
            </div>
            <div class="topup-method-arrow"><i class="fas fa-chevron-left"></i></div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- مباشر -->
        <div id="methodsList-auto" style="display:none">
          <?php if (empty($autoMethods)): ?>
          <div class="topup-empty"><i class="fas fa-bolt"></i><p>لا توجد طرق دفع مباشرة</p></div>
          <?php else: foreach ($autoMethods as $pm): ?>
          <div class="topup-method-item" onclick="selectMethod(<?= $pm['id'] ?>, this)" data-method-id="<?= $pm['id'] ?>" data-mode="auto">
            <div class="topup-method-thumb" style="--mc:<?= htmlspecialchars($pm['color']) ?>">
              <?php if (!empty($pm['image'])): ?>
              <img src="<?= SITE_URL ?>/<?= htmlspecialchars($pm['image']) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:10px;padding:3px">
              <?php else: ?>
              <i class="fas fa-<?= htmlspecialchars($pm['icon']) ?>" style="color:<?= htmlspecialchars($pm['color']) ?>"></i>
              <?php endif; ?>
            </div>
            <div class="topup-method-info">
              <div class="topup-method-name"><?= htmlspecialchars($pm['name']) ?></div>
              <?php if ($pm['description']): ?>
              <div class="topup-method-desc"><?= htmlspecialchars(mb_substr($pm['description'], 0, 50)) ?></div>
              <?php endif; ?>
            </div>
            <div class="topup-method-arrow"><i class="fas fa-bolt" style="color:var(--cyan);font-size:11px"></i></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Step 2 -->
      <div id="topupStep2" style="display:none">
        <div class="topup-drag-bar"></div>
        <div class="topup-header">
          <button class="topup-back-btn" onclick="backToStep1()"><i class="fas fa-arrow-right"></i></button>
          <div style="flex:1;text-align:center">
            <div class="topup-header-title" id="step2Title">تفاصيل الإيداع</div>
            <div class="topup-header-sub" id="step2Sub">اتبع التعليمات أدناه</div>
          </div>
          <button class="topup-close-btn" onclick="closeTopupSheet()"><i class="fas fa-times"></i></button>
        </div>
        <div class="topup-account-card" id="topupAccountCard">
          <div class="topup-account-header">
            <div class="topup-account-icon" id="topupAccountIcon"><i class="fas fa-university"></i></div>
            <div class="topup-account-title" id="topupAccountTitle">بيانات الحساب</div>
          </div>
          <div id="methodFields" class="topup-fields-list"></div>
        </div>
        <div class="topup-calc-section" style="margin-top:10px">
          <div class="topup-section-label"><i class="fas fa-calculator"></i> المبلغ وحساب الرصيد</div>
          <div class="topup-currency-row" id="currencyOptions">
            <?php foreach ($exchangeRates as $er): ?>
            <div class="topup-currency-chip <?= $er['currency_code'] === 'USD' ? 'active' : '' ?>"
                 onclick="selectCurrency('<?= $er['currency_code'] ?>','<?= $er['currency_symbol'] ?>',<?= $er['rate_to_usd'] ?>,this)">
              <div class="topup-chip-symbol"><?= htmlspecialchars($er['currency_symbol']) ?></div>
              <div class="topup-chip-code"><?= htmlspecialchars($er['currency_code']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="topup-amount-wrap">
            <div class="topup-amount-currency" id="topupCurrencyLabel">USD</div>
            <input type="number" class="topup-amount-field" id="topupAmountInput"
                   placeholder="0.00" min="0.01" step="any" oninput="calcTopupUSD()">
          </div>
          <div class="topup-result-card" id="topupResultBox">
            <div class="topup-result-row">
              <span class="topup-result-lbl">سيُضاف لرصيدك</span>
              <div class="topup-result-val" id="topupUSDAmount">$0.0000</div>
            </div>
            <div class="topup-result-bar">
              <div class="topup-result-bar-fill" id="topupResultBar"></div>
            </div>
          </div>
        </div>
        <div class="topup-upload-section">
          <div class="topup-section-label"><i class="fas fa-receipt"></i> إيصال الدفع</div>
          <label class="topup-upload-zone" for="receiptFileInput" id="receiptLabel">
            <div class="topup-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
            <div class="topup-upload-text">اضغط لرفع الإيصال</div>
            <div class="topup-upload-hint">صورة أو PDF — حد أقصى 5MB</div>
          </label>
          <img id="receiptPreviewImg" class="topup-receipt-preview" src="" style="display:none">
        </div>
        <div style="padding:0 12px 6px">
          <input type="text" id="topupNotes" placeholder="ملاحظة اختيارية..." class="topup-note-input">
        </div>
        <form method="POST" action="<?= SITE_URL ?>/topup.php" enctype="multipart/form-data" id="topupForm">
          <input type="hidden" name="method_id"     id="topupMethodId">
          <input type="hidden" name="currency_code" id="topupCurrencyCode" value="USD">
          <input type="hidden" name="amount_sent"   id="topupAmountHidden">
          <input type="hidden" name="notes"         id="topupNotesHidden">
          <input type="file"   name="receipt"       id="receiptFileInput" accept="image/*,.pdf" style="display:none" onchange="previewReceipt(this)">
          <div style="padding:10px 12px 28px">
            <button type="button" class="topup-submit-btn" onclick="submitTopup()">
              <i class="fas fa-paper-plane"></i> إرسال طلب الشحن
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div><!-- end #app -->

<script>
// ══ فلتر المعاملات ══
function filterTx(type) {
  ['All','Credit','Debit'].forEach(t => {
    document.getElementById('tf'+t).classList.toggle('active', t.toLowerCase() === type || (type === 'all' && t === 'All'));
  });
  document.querySelectorAll('#txList .tx-item').forEach(el => {
    el.style.display = (type === 'all' || el.dataset.type === type) ? '' : 'none';
  });
}

// ══ TOPUP SHEET ══
let selectedMethodId = null;
let currentRate = 1;

function switchTopupMode(mode) {
  document.getElementById('methodsList-manual').style.display = mode === 'manual' ? 'block' : 'none';
  document.getElementById('methodsList-auto').style.display   = mode === 'auto'   ? 'block' : 'none';
  document.getElementById('tabManualBtn').classList.toggle('active', mode === 'manual');
  document.getElementById('tabAutoBtn').classList.toggle('active',   mode === 'auto');
  const desc = document.getElementById('modeDesc');
  if (mode === 'manual') desc.textContent = 'أرسل الحوالة وارفع الإيصال — تُفعَّل بعد مراجعة الإدارة';
  else desc.textContent = 'دفع فوري وآلي — يُضاف رصيدك مباشرة بعد الدفع';
  // إعادة تعيين الاختيار
  selectedMethodId = null;
  document.querySelectorAll('.topup-method-item').forEach(m => m.classList.remove('selected'));
}

function openTopupSheet() {
  const overlay = document.getElementById('topupSheetOverlay');
  overlay.style.display = 'block';
  requestAnimationFrame(() => overlay.classList.add('show'));
  document.getElementById('topupStep1').style.display = 'block';
  document.getElementById('topupStep2').style.display = 'none';
}

function closeTopupSheet() {
  const overlay = document.getElementById('topupSheetOverlay');
  overlay.classList.remove('show');
  setTimeout(() => { overlay.style.display = 'none'; }, 350);
  selectedMethodId = null;
  document.querySelectorAll('.topup-method-item').forEach(m => m.classList.remove('selected'));
}

function closeTopupIfBg(e) {
  if (e.target === document.getElementById('topupSheetOverlay')) closeTopupSheet();
}

const METHODS_DATA = <?= json_encode(array_map(function($m) {
    $fields = [];
    if ($m['fields_raw']) {
        foreach (explode(';;', $m['fields_raw']) as $f) {
            $parts = explode('||', $f);
            if (count($parts) >= 2) $fields[] = ['label' => $parts[0], 'value' => $parts[1], 'copyable' => $parts[2] ?? '0'];
        }
    }
    return ['id' => $m['id'], 'name' => $m['name'], 'icon' => $m['icon'], 'color' => $m['color'], 'description' => $m['description'], 'fields' => $fields, 'payment_mode' => $m['payment_mode'] ?? 'manual', 'image' => $m['image'] ?? '', 'allowed_currency_codes' => $m['allowed_currency_codes'] ?? []];
}, $paymentMethods)) ?>;

function selectMethod(id, el) {
  selectedMethodId = id;
  document.querySelectorAll('.topup-method-item').forEach(m => m.classList.remove('selected'));
  el.classList.add('selected');
  const m = METHODS_DATA.find(x => x.id == id);
  if (!m) return;
  document.getElementById('step2Title').textContent = m.name;
  document.getElementById('step2Sub').textContent   = m.description || 'اتبع التعليمات أدناه';
  document.getElementById('topupAccountIcon').innerHTML = `<i class="fas fa-${m.icon}" style="color:${m.color}"></i>`;
  document.getElementById('topupAccountTitle').textContent = `بيانات حساب ${m.name}`;
  document.getElementById('topupMethodId').value = id;
  let fieldsHtml = '';
  if (m.fields && m.fields.length) {
    m.fields.forEach(f => {
      fieldsHtml += `<div class="topup-field-row">
        <div class="topup-field-label">${esc(f.label)}</div>
        <div class="topup-field-value">
          ${esc(f.value)}
          ${f.copyable == '1' ? `<button class="topup-copy-btn" onclick="copyText('${esc(f.value)}',this)">نسخ</button>` : ''}
        </div>
      </div>`;
    });
  } else {
    fieldsHtml = '<div style="padding:12px 14px;font-size:12px;color:#8895a7">لا توجد بيانات إضافية</div>';
  }
  document.getElementById('methodFields').innerHTML = fieldsHtml;
  applyWalletMethodCurrencyFilter(m);
  document.getElementById('topupStep1').style.display = 'none';
  document.getElementById('topupStep2').style.display = 'block';
  document.getElementById('topupAmountInput').value = '';
  document.getElementById('topupUSDAmount').textContent = '$0.0000';
  document.getElementById('topupResultBar').style.width = '0%';
}

function applyWalletMethodCurrencyFilter(method) {
  const configured = Array.isArray(method.allowed_currency_codes)
    ? method.allowed_currency_codes.map(c => String(c).trim().toUpperCase()).filter(Boolean)
    : [];
  const chips = Array.from(document.querySelectorAll('#currencyOptions .topup-currency-chip'));
  let firstAllowed = null;
  chips.forEach(chip => {
    const codeEl = chip.querySelector('.topup-chip-code');
    const code = codeEl ? codeEl.textContent.trim().toUpperCase() : '';
    const allowed = !configured.length || configured.includes(code);
    chip.style.display = allowed ? '' : 'none';
    if (allowed && !firstAllowed) firstAllowed = chip;
  });
  const selectedChip = chips.find(chip => chip.classList.contains('active'));
  if (!selectedChip || selectedChip.style.display === 'none') {
    if (firstAllowed) firstAllowed.click();
  }
}

function backToStep1() {
  document.getElementById('topupStep2').style.display = 'none';
  document.getElementById('topupStep1').style.display = 'block';
}

function selectCurrency(code, symbol, rate, el) {
  const method = METHODS_DATA.find(x => x.id == selectedMethodId);
  const allowed = method && Array.isArray(method.allowed_currency_codes) ? method.allowed_currency_codes.map(c => String(c).toUpperCase()) : [];
  if (allowed.length && !allowed.includes(String(code).toUpperCase())) {
    showToast('هذه العملة غير متاحة مع وسيلة الدفع المختارة');
    return;
  }
  currentRate = parseFloat(rate) || 1;
  document.querySelectorAll('.topup-currency-chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('topupCurrencyLabel').textContent = code;
  document.getElementById('topupCurrencyCode').value = code;
  calcTopupUSD();
}

function calcTopupUSD() {
  const amt = parseFloat(document.getElementById('topupAmountInput').value) || 0;
  const usd = amt * currentRate;
  document.getElementById('topupUSDAmount').textContent = '$' + usd.toFixed(4);
  document.getElementById('topupAmountHidden').value = amt;
  const maxUSD = 500;
  document.getElementById('topupResultBar').style.width = Math.min(100, (usd / maxUSD) * 100) + '%';
}

function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.textContent;
    btn.textContent = '✓ تم';
    btn.style.background = 'rgba(0,230,118,0.2)';
    btn.style.color = '#00e676';
    setTimeout(() => { btn.textContent = orig; btn.style.background = ''; btn.style.color = ''; }, 1500);
  });
}

function submitTopup() {
  const amt = parseFloat(document.getElementById('topupAmountInput').value) || 0;
  if (!selectedMethodId) { showToast('اختر طريقة الدفع أولاً'); return; }
  const method = METHODS_DATA.find(x => x.id == selectedMethodId);
  const allowed = method && Array.isArray(method.allowed_currency_codes) ? method.allowed_currency_codes.map(c => String(c).toUpperCase()) : [];
  const selectedCode = String(document.getElementById('topupCurrencyCode').value || '').toUpperCase();
  if (allowed.length && !allowed.includes(selectedCode)) {
    showToast('اختر عملة متاحة لهذه الوسيلة');
    return;
  }
  if (amt <= 0) { showToast('أدخل المبلغ المُرسَل'); return; }
  document.getElementById('topupNotesHidden').value = document.getElementById('topupNotes').value;
  document.getElementById('topupForm').submit();
}

function previewReceipt(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  document.getElementById('receiptLabel').querySelector('.topup-upload-text').textContent = file.name;
  document.getElementById('receiptLabel').querySelector('.topup-upload-hint').textContent = (file.size/1024).toFixed(0) + ' KB — تم الاختيار ✓';
  document.getElementById('receiptLabel').querySelector('.topup-upload-icon').innerHTML = '<i class="fas fa-check-circle" style="color:#00d4aa"></i>';
  document.getElementById('receiptLabel').style.borderColor = 'rgba(0,212,170,.4)';
  if (file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.getElementById('receiptPreviewImg');
      img.src = e.target.result;
      img.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }
}

function showToast(msg) {
  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%);background:#1e6fff;color:#fff;padding:10px 20px;border-radius:12px;font-weight:700;z-index:9999;font-size:13px;font-family:var(--font);box-shadow:0 4px 20px rgba(0,0,0,.4)';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2500);
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>

<!-- ══ مودال تفاصيل الهدية ══════════════════════════════════ -->
<div id="giftDetailOverlay" style="
  position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;
  display:flex;align-items:flex-end;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .3s;backdrop-filter:blur(8px)
" onclick="if(event.target===this)closeGiftDetail()">
  <div id="giftDetailSheet" style="
    background:#151827;width:100%;max-width:480px;
    border-radius:24px 24px 0 0;padding:0 0 40px;
    transform:translateY(100%);transition:transform .35s cubic-bezier(.32,1.2,.72,1);
  ">
    <div style="width:40px;height:4px;background:rgba(255,255,255,.1);border-radius:4px;margin:12px auto 0"></div>

    <!-- بطاقة الهدية -->
    <div id="giftCard" style="margin:20px 20px 0;border-radius:20px;overflow:hidden;
      background:linear-gradient(135deg,#1a0533,#2d1060,#0d1a2d);
      border:1px solid rgba(108,63,224,.3);box-shadow:0 20px 60px rgba(0,0,0,.5)">
      <!-- نجوم خلفية -->
      <div style="position:absolute;inset:0;pointer-events:none;border-radius:20px;
        background-image:radial-gradient(1px 1px at 20% 30%,rgba(255,255,255,.6) 0%,transparent 100%),
          radial-gradient(1px 1px at 60% 10%,rgba(255,255,255,.4) 0%,transparent 100%),
          radial-gradient(1px 1px at 80% 60%,rgba(255,255,255,.5) 0%,transparent 100%)"></div>
      <div style="padding:24px;position:relative">
        <div style="font-size:2.5rem;margin-bottom:6px">🎁</div>
        <div id="giftFromLabel" style="font-size:.72rem;color:rgba(255,255,255,.5);margin-bottom:4px"></div>
        <div id="giftPersonName" style="font-size:1rem;font-weight:900;color:#fff;margin-bottom:16px"></div>
        <div style="background:rgba(255,255,255,.08);border-radius:14px;padding:12px 16px;
          display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <span style="font-size:.72rem;color:rgba(255,255,255,.5)">المبلغ</span>
          <span id="giftAmount" style="font-size:1.4rem;font-weight:900;color:#f5c842"></span>
        </div>
        <div id="giftMsgWrap" style="background:rgba(255,255,255,.05);border-radius:12px;
          padding:10px 14px;font-size:.8rem;color:rgba(255,255,255,.7);font-style:italic;
          border-right:3px solid #ff6b9d;display:none"></div>
      </div>
      <div style="padding:10px 24px;background:rgba(0,0,0,.2);
        display:flex;align-items:center;justify-content:space-between">
        <span id="giftDate" style="font-size:.68rem;color:rgba(255,255,255,.4)"></span>
        <span id="giftStatus" style="font-size:.68rem;padding:3px 10px;border-radius:20px;font-weight:700"></span>
      </div>
    </div>

    <!-- تفاصيل إضافية -->
    <div style="padding:16px 20px 0">
      <div id="giftGoBtn" style="display:none">
        <a href="gift.php" style="display:flex;align-items:center;justify-content:center;gap:8px;
          padding:13px;border-radius:14px;
          background:linear-gradient(135deg,#ff6b9d,#6c3fe0);
          color:#fff;text-decoration:none;font-weight:900;font-size:.9rem;margin-bottom:10px">
          <i class="fas fa-gift"></i> صفحة الهدايا
        </a>
      </div>
      <button onclick="closeGiftDetail()" style="width:100%;padding:12px;border-radius:12px;
        background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
        color:rgba(255,255,255,.5);font-family:inherit;font-size:.85rem;cursor:pointer">
        إغلاق
      </button>
    </div>
  </div>
</div>

<script>
function showGiftDetail(giftJson, desc, amount) {
  const overlay = document.getElementById('giftDetailOverlay');
  const sheet   = document.getElementById('giftDetailSheet');

  if (giftJson) {
    const g = typeof giftJson === 'string' ? JSON.parse(giftJson) : giftJson;
    const isSender  = g.is_sender;
    const otherName = isSender ? g.receiver_name : g.sender_name;

    document.getElementById('giftFromLabel').textContent  = isSender ? 'هدية إلى' : 'هدية من';
    document.getElementById('giftPersonName').textContent = otherName || '—';
    document.getElementById('giftAmount').textContent     = parseFloat(g.amount).toFixed(2) + ' <?=addslashes(getSetting('currency_symbol')?:'$')?>';

    const msgEl = document.getElementById('giftMsgWrap');
    if (g.message) { msgEl.textContent='"'+g.message+'"'; msgEl.style.display=''; }
    else            { msgEl.style.display='none'; }

    const statusMap = {pending:'⏳ لم تُفتح',opened:'✅ مفتوحة',rejected:'❌ مرفوضة'};
    const statusCol = {pending:'rgba(245,200,66,.15)',opened:'rgba(0,212,170,.12)',rejected:'rgba(255,68,85,.12)'};
    const statusTxt = {pending:'#f5c842',opened:'#00d4aa',rejected:'#ff4455'};
    const st = g.status||'pending';
    const statusEl = document.getElementById('giftStatus');
    statusEl.textContent = statusMap[st]||st;
    statusEl.style.background = statusCol[st]||'rgba(255,255,255,.08)';
    statusEl.style.color      = statusTxt[st]||'#fff';

    document.getElementById('giftDate').textContent = g.created_at ? g.created_at.substring(0,16).replace('T',' ') : '';
    document.getElementById('giftGoBtn').style.display = '';
  } else {
    // عملية هدية لكن بدون بيانات مفصلة
    document.getElementById('giftFromLabel').textContent  = '🎁';
    document.getElementById('giftPersonName').textContent = desc || 'هدية';
    document.getElementById('giftAmount').textContent     = parseFloat(amount).toFixed(2) + ' <?=addslashes(getSetting('currency_symbol')?:'$')?>';
    document.getElementById('giftMsgWrap').style.display = 'none';
    document.getElementById('giftStatus').textContent    = '';
    document.getElementById('giftDate').textContent      = '';
    document.getElementById('giftGoBtn').style.display   = '';
  }

  overlay.style.opacity = '1'; overlay.style.pointerEvents = 'all';
  requestAnimationFrame(()=>sheet.style.transform='translateY(0)');
}

function closeGiftDetail() {
  const overlay = document.getElementById('giftDetailOverlay');
  const sheet   = document.getElementById('giftDetailSheet');
  sheet.style.transform = 'translateY(100%)';
  setTimeout(()=>{ overlay.style.opacity='0'; overlay.style.pointerEvents='none'; }, 350);
}
</script>
</body>
</html>
