<?php
require_once 'includes/config.php';
$siteName   = getSetting('site_name') ?: SITE_NAME;
$currSymbol = getSetting('currency_symbol') ?: '$';
$apiBase    = SITE_URL . '/api';
$siteUrl    = SITE_URL;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#080c1a">
<title><?= htmlspecialchars($siteName) ?> — API Docs</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#060b18;--bg2:#0b1120;--card:#0f1929;--card2:#162236;
  --border:rgba(255,255,255,0.06);--primary:#1e6fff;--cyan:#00d4ff;
  --green:#00e676;--red:#ff1744;--purple:#7c3aed;--gold:#f5a623;
  --text:#f0f4ff;--text2:#7a93b4;--text3:#3d5470;
  --radius:16px;--radius-sm:10px;
  --font:'Cairo',sans-serif;--mono:'JetBrains Mono',monospace;
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{min-height:100%;background:var(--bg);font-family:var(--font);color:var(--text);direction:rtl}
body::before{content:'';position:fixed;top:-200px;right:-200px;width:600px;height:600px;background:radial-gradient(circle,rgba(124,58,237,.08) 0%,transparent 65%);pointer-events:none;z-index:0}
body::after{content:'';position:fixed;bottom:-200px;left:-200px;width:500px;height:500px;background:radial-gradient(circle,rgba(0,212,255,.06) 0%,transparent 65%);pointer-events:none;z-index:0}

/* HEADER */
.site-header{position:sticky;top:0;z-index:100;background:rgba(6,11,24,.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 16px}
.header-inner{max-width:900px;margin:0 auto;height:56px;display:flex;align-items:center;justify-content:space-between}
.header-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-badge{width:36px;height:36px;background:linear-gradient(135deg,var(--purple),var(--cyan));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;box-shadow:0 0 16px rgba(124,58,237,.35)}
.logo-name{font-size:16px;font-weight:900;background:linear-gradient(90deg,#c4b5fd,var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.header-links{display:flex;align-items:center;gap:8px}
.hdr-btn{padding:7px 14px;border-radius:8px;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .2s}
.hdr-btn.outline{background:transparent;border:1px solid var(--border);color:var(--text2)}
.hdr-btn.outline:hover{border-color:var(--purple);color:var(--text)}
.hdr-btn.filled{background:linear-gradient(135deg,var(--purple),var(--primary));border:none;color:#fff;box-shadow:0 4px 14px rgba(124,58,237,.3)}
.version-badge{font-family:var(--mono);font-size:10px;color:var(--purple);background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.2);padding:2px 8px;border-radius:6px}

/* LAYOUT */
.layout{max-width:900px;margin:0 auto;padding:0 16px;position:relative;z-index:1}

/* HERO */
.hero{padding:44px 0 32px;text-align:center}
.hero-tag{display:inline-flex;align-items:center;gap:6px;background:rgba(124,58,237,.1);border:1px solid rgba(124,58,237,.2);border-radius:20px;padding:5px 14px;font-size:11px;color:#c4b5fd;font-weight:700;margin-bottom:18px}
.hero-tag .dot{width:6px;height:6px;border-radius:50%;background:var(--green);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.hero h1{font-size:28px;font-weight:900;margin-bottom:10px;line-height:1.25}
.hero h1 span{background:linear-gradient(90deg,var(--purple),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero p{font-size:14px;color:var(--text2);max-width:480px;margin:0 auto 24px;line-height:1.7}
.hero-base-url{display:inline-flex;align-items:center;gap:10px;background:var(--card);border:1px solid rgba(124,58,237,.2);border-radius:12px;padding:10px 16px;font-family:var(--mono);font-size:13px;color:var(--cyan);direction:ltr}
.copy-btn{background:rgba(124,58,237,.15);border:1px solid rgba(124,58,237,.2);border-radius:6px;padding:4px 10px;color:#c4b5fd;font-size:11px;cursor:pointer;font-family:var(--font);font-weight:700;transition:all .15s}
.copy-btn:active{background:rgba(124,58,237,.3)}

/* QUICK START */
.section{margin-bottom:32px}
.section-title{font-size:17px;font-weight:900;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title i{color:var(--purple)}
.section-title .num{width:24px;height:24px;border-radius:8px;background:rgba(124,58,237,.15);color:#c4b5fd;font-size:12px;display:flex;align-items:center;justify-content:center;font-weight:900;flex-shrink:0}

/* AUTH CARD */
.auth-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.auth-method{padding:14px 16px;border-bottom:1px solid var(--border)}
.auth-method:last-child{border:none}
.auth-method-title{font-size:13px;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.auth-method-title i{color:var(--gold)}

/* ENDPOINTS */
.ep-list{display:flex;flex-direction:column;gap:10px}
.ep-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border-color .2s}
.ep-card:hover{border-color:rgba(124,58,237,.25)}
.ep-head{display:flex;align-items:center;gap:10px;padding:14px 16px;cursor:pointer;user-select:none}
.method{font-family:var(--mono);font-size:11px;font-weight:700;padding:4px 10px;border-radius:6px;flex-shrink:0;min-width:46px;text-align:center}
.GET{background:rgba(0,230,118,.1);color:var(--green);border:1px solid rgba(0,230,118,.15)}
.POST{background:rgba(30,111,255,.1);color:#79b8ff;border:1px solid rgba(30,111,255,.15)}
.ep-path{font-family:var(--mono);font-size:12px;color:var(--text2);flex:1;direction:ltr;text-align:left;word-break:break-all}
.ep-summary{font-size:13px;font-weight:700;color:var(--text)}
.ep-arrow{color:var(--text3);font-size:12px;flex-shrink:0;transition:transform .25s}
.ep-body{display:none;border-top:1px solid var(--border)}
.ep-body.open{display:block}
.ep-arrow.open{transform:rotate(-90deg)}
.ep-section{padding:16px}
.ep-section+.ep-section{border-top:1px solid var(--border)}
.ep-section-title{font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.7px;font-weight:700;margin-bottom:10px}

/* CODE BLOCK */
.code-wrap{position:relative}
.code-block{background:#020609;border:1px solid rgba(255,255,255,.05);border-radius:10px;padding:14px;font-family:var(--mono);font-size:12px;line-height:1.9;overflow-x:auto;direction:ltr;text-align:left;white-space:pre}
.code-copy{position:absolute;top:8px;left:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);border-radius:6px;padding:3px 8px;color:var(--text3);font-size:10px;cursor:pointer;font-family:var(--font);font-weight:700;transition:all .15s}
.code-copy:hover{background:rgba(255,255,255,.1);color:var(--text)}
.k{color:#ff79c6}.s{color:#f1fa8c}.v{color:#bd93f9}.n{color:#8be9fd}.c{color:#44475a}.g{color:#50fa7b}.r{color:#ff5555}

/* PARAMS TABLE */
.params-table{width:100%;border-collapse:collapse}
.params-table th{font-size:10px;color:var(--text3);text-align:right;padding:6px 8px;border-bottom:1px solid var(--border);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.params-table td{font-size:12px;padding:8px;border-bottom:1px solid rgba(255,255,255,.03)}
.params-table tr:last-child td{border:none}
.param-name{font-family:var(--mono);color:var(--cyan)}
.param-type{font-family:var(--mono);font-size:10px;color:var(--text3)}
.req-badge{font-size:10px;padding:2px 6px;border-radius:4px;font-weight:700}
.req{background:rgba(255,23,68,.1);color:#ff6b8a;border:1px solid rgba(255,23,68,.15)}
.opt{background:rgba(255,255,255,.05);color:var(--text3);border:1px solid var(--border)}

/* STATUS CHIPS */
.status-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.chip{padding:4px 10px;border-radius:8px;font-size:11px;font-weight:700;font-family:var(--mono)}
.chip.pending{background:rgba(245,166,35,.1);color:var(--gold);border:1px solid rgba(245,166,35,.15)}
.chip.processing{background:rgba(0,212,255,.1);color:var(--cyan);border:1px solid rgba(0,212,255,.15)}
.chip.completed{background:rgba(0,230,118,.1);color:var(--green);border:1px solid rgba(0,230,118,.15)}
.chip.cancelled{background:rgba(255,23,68,.1);color:#ff6b8a;border:1px solid rgba(255,23,68,.15)}
.chip.failed{background:rgba(255,23,68,.1);color:#ff6b8a;border:1px solid rgba(255,23,68,.15)}

/* ERRORS TABLE */
.err-grid{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;align-items:start}
.err-code{font-family:var(--mono);font-size:13px;font-weight:700;padding:4px 0}
.err-400{color:#ff9f43}.err-401{color:#ff6b8a}.err-403{color:#ff6b8a}.err-404{color:#f8d347}.err-422{color:#a29bfe}.err-429{color:var(--gold)}.err-500{color:var(--red)}
.err-desc{font-size:12px;color:var(--text2);padding:4px 0}

/* RATE LIMIT */
.rl-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rl-card{background:var(--card2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;text-align:center}
.rl-num{font-size:22px;font-weight:900;color:var(--purple)}
.rl-lbl{font-size:11px;color:var(--text3);margin-top:3px}

/* FOOTER */
.docs-footer{text-align:center;padding:32px 16px;color:var(--text3);font-size:12px;border-top:1px solid var(--border);margin-top:16px}

/* TOAST */
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--purple);color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;font-family:var(--font)}
.toast.show{opacity:1}
</style>
</head>
<body>

<!-- HEADER -->
<header class="site-header">
  <div class="header-inner">
    <a class="header-logo" href="<?= $siteUrl ?>/">
      <div class="logo-badge">⚡</div>
      <div class="logo-name"><?= htmlspecialchars($siteName) ?> API</div>
    </a>
    <div class="header-links">
      <span class="version-badge">v1.0</span>
      <a href="<?= $siteUrl ?>/mobile.php" class="hdr-btn outline">الموقع</a>
      <a href="<?= $siteUrl ?>/api.php" class="hdr-btn filled">⚡ لوحة API</a>
    </div>
  </div>
</header>

<div class="layout">

  <!-- HERO -->
  <div class="hero">
    <div class="hero-tag"><span class="dot"></span> API متاح ومستقر</div>
    <h1>توثيق <span>واجهة برمجة التطبيقات</span></h1>
    <p>اربط تطبيقك أو موقعك بمنصتنا وابدأ بإنشاء طلبات الشحن آلياً في دقائق</p>
    <div class="hero-base-url">
      <span id="baseUrl"><?= htmlspecialchars($apiBase) ?></span>
      <button class="copy-btn" onclick="cp(document.getElementById('baseUrl').textContent,this)">نسخ</button>
    </div>
  </div>

  <!-- AUTH -->
  <div class="section">
    <div class="section-title"><span class="num">1</span><i class="fas fa-key"></i> المصادقة</div>
    <div class="auth-card">
      <div class="auth-method">
        <div class="auth-method-title"><i class="fas fa-star"></i> عبر Authorization Header (موصى به)</div>
        <div class="code-wrap">
          <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
          <div class="code-block"><span class="k">Authorization</span>: <span class="s">Bearer</span> <span class="v">YOUR_API_TOKEN</span>

<span class="c"># مثال مع curl</span>
curl -X GET "<?= htmlspecialchars($apiBase) ?>/services" \
  -H "<span class="k">Authorization</span>: <span class="s">Bearer YOUR_API_TOKEN</span>"</div>
        </div>
      </div>
      <div class="auth-method">
        <div class="auth-method-title"><i class="fas fa-link"></i> عبر Query Parameter (بديل)</div>
        <div class="code-wrap">
          <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
          <div class="code-block">GET <?= htmlspecialchars($apiBase) ?>/services?<span class="k">token</span>=<span class="v">YOUR_API_TOKEN</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ENDPOINTS -->
  <div class="section">
    <div class="section-title"><span class="num">2</span><i class="fas fa-plug"></i> الـ Endpoints</div>
    <div class="ep-list">

      <!-- GET /balance -->
      <div class="ep-card">
        <div class="ep-head" onclick="tog(this)">
          <span class="method GET">GET</span>
          <span class="ep-path">/api/balance</span>
          <span class="ep-summary">رصيد المحفظة</span>
          <i class="fas fa-chevron-left ep-arrow"></i>
        </div>
        <div class="ep-body">
          <div class="ep-section">
            <div class="ep-section-title">الوصف</div>
            <div style="font-size:13px;color:var(--text2)">جلب الرصيد الحالي لحساب العميل المرتبط بالـ Token</div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الطلب</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">curl -X GET "<?= htmlspecialchars($apiBase) ?>/balance" \
  -H "Authorization: Bearer YOUR_TOKEN"</div>
            </div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الرد</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">{
  <span class="k">"status"</span>:   <span class="g">"success"</span>,
  <span class="k">"balance"</span>:  <span class="s">"150.00"</span>,
  <span class="k">"currency"</span>: <span class="s">"<?= htmlspecialchars($currSymbol) ?>"</span>
}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- GET /services -->
      <div class="ep-card">
        <div class="ep-head" onclick="tog(this)">
          <span class="method GET">GET</span>
          <span class="ep-path">/api/services</span>
          <span class="ep-summary">قائمة الخدمات</span>
          <i class="fas fa-chevron-left ep-arrow"></i>
        </div>
        <div class="ep-body">
          <div class="ep-section">
            <div class="ep-section-title">الوصف</div>
            <div style="font-size:13px;color:var(--text2)">جلب كل الخدمات المتاحة مع أسعارها والحقول المطلوبة لكل خدمة</div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">Query Parameters (اختيارية)</div>
            <table class="params-table">
              <tr><th>الحقل</th><th>النوع</th><th>الوصف</th><th>إلزامي</th></tr>
              <tr><td class="param-name">category_id</td><td class="param-type">integer</td><td style="font-size:12px;color:var(--text2)">تصفية حسب قسم محدد</td><td><span class="req-badge opt">اختياري</span></td></tr>
              <tr><td class="param-name">search</td><td class="param-type">string</td><td style="font-size:12px;color:var(--text2)">بحث باسم الخدمة</td><td><span class="req-badge opt">اختياري</span></td></tr>
            </table>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الرد</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">{
  <span class="k">"status"</span>: <span class="g">"success"</span>,
  <span class="k">"data"</span>: [
    {
      <span class="k">"id"</span>:          <span class="n">1</span>,
      <span class="k">"name"</span>:        <span class="s">"شحن فورت نايت"</span>,
      <span class="k">"category"</span>:    <span class="s">"الألعاب"</span>,
      <span class="k">"price"</span>:       <span class="s">"5.00"</span>,
      <span class="k">"currency"</span>:    <span class="s">"<?= htmlspecialchars($currSymbol) ?>"</span>,
      <span class="k">"min_qty"</span>:     <span class="n">1</span>,
      <span class="k">"max_qty"</span>:     <span class="n">100</span>,
      <span class="k">"status"</span>:      <span class="n">1</span>,
      <span class="k">"fields"</span>: [
        {
          <span class="k">"name"</span>:     <span class="s">"player_id"</span>,
          <span class="k">"label"</span>:    <span class="s">"ID اللاعب"</span>,
          <span class="k">"type"</span>:     <span class="s">"text"</span>,
          <span class="k">"required"</span>: <span class="v">true</span>
        }
      ]
    }
  ]
}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- POST /order -->
      <div class="ep-card">
        <div class="ep-head" onclick="tog(this)">
          <span class="method POST">POST</span>
          <span class="ep-path">/api/order</span>
          <span class="ep-summary">إنشاء طلب جديد</span>
          <i class="fas fa-chevron-left ep-arrow"></i>
        </div>
        <div class="ep-body">
          <div class="ep-section">
            <div class="ep-section-title">الوصف</div>
            <div style="font-size:13px;color:var(--text2)">إنشاء طلب شحن جديد. يُخصم المبلغ من رصيد المحفظة تلقائياً عند نجاح الطلب</div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">Body Parameters (JSON)</div>
            <table class="params-table">
              <tr><th>الحقل</th><th>النوع</th><th>الوصف</th><th>إلزامي</th></tr>
              <tr><td class="param-name">service_id</td><td class="param-type">integer</td><td style="font-size:12px;color:var(--text2)">ID الخدمة من /api/services</td><td><span class="req-badge req">إلزامي</span></td></tr>
              <tr><td class="param-name">quantity</td><td class="param-type">integer</td><td style="font-size:12px;color:var(--text2)">الكمية (ضمن min_qty و max_qty)</td><td><span class="req-badge req">إلزامي</span></td></tr>
              <tr><td class="param-name">fields</td><td class="param-type">object</td><td style="font-size:12px;color:var(--text2)">حقول الخدمة (تختلف حسب كل خدمة)</td><td><span class="req-badge req">إلزامي</span></td></tr>
            </table>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الطلب</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">curl -X POST "<?= htmlspecialchars($apiBase) ?>/order" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    <span class="k">"service_id"</span>: <span class="n">1</span>,
    <span class="k">"quantity"</span>:   <span class="n">1</span>,
    <span class="k">"fields"</span>: {
      <span class="k">"player_id"</span>: <span class="s">"123456789"</span>
    }
  }'</div>
            </div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الرد — نجاح</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">{
  <span class="k">"status"</span>:   <span class="g">"success"</span>,
  <span class="k">"order_id"</span>: <span class="n">42</span>,
  <span class="k">"message"</span>:  <span class="s">"تم إنشاء الطلب بنجاح"</span>
}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- GET /order/{id} -->
      <div class="ep-card">
        <div class="ep-head" onclick="tog(this)">
          <span class="method GET">GET</span>
          <span class="ep-path">/api/order/{id}</span>
          <span class="ep-summary">تفاصيل طلب</span>
          <i class="fas fa-chevron-left ep-arrow"></i>
        </div>
        <div class="ep-body">
          <div class="ep-section">
            <div class="ep-section-title">الوصف</div>
            <div style="font-size:13px;color:var(--text2)">جلب تفاصيل وحالة طلب محدد. يمكنك الاستعلام دورياً لمعرفة التحديثات</div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">حالات الطلب المحتملة</div>
            <div class="status-chips">
              <span class="chip pending">pending — انتظار</span>
              <span class="chip processing">processing — تنفيذ</span>
              <span class="chip completed">completed — مكتمل</span>
              <span class="chip cancelled">cancelled — ملغي</span>
              <span class="chip failed">failed — فشل</span>
            </div>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الرد</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">{
  <span class="k">"status"</span>: <span class="g">"success"</span>,
  <span class="k">"data"</span>: {
    <span class="k">"id"</span>:           <span class="n">42</span>,
    <span class="k">"status"</span>:       <span class="s">"completed"</span>,
    <span class="k">"service_name"</span>: <span class="s">"شحن فورت نايت"</span>,
    <span class="k">"quantity"</span>:     <span class="n">1</span>,
    <span class="k">"total_price"</span>:  <span class="s">"5.00"</span>,
    <span class="k">"currency"</span>:     <span class="s">"<?= htmlspecialchars($currSymbol) ?>"</span>,
    <span class="k">"notes"</span>:        <span class="s">""</span>,
    <span class="k">"created_at"</span>:   <span class="s">"2026-01-01 12:00:00"</span>
  }
}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- GET /orders -->
      <div class="ep-card">
        <div class="ep-head" onclick="tog(this)">
          <span class="method GET">GET</span>
          <span class="ep-path">/api/orders</span>
          <span class="ep-summary">قائمة الطلبات</span>
          <i class="fas fa-chevron-left ep-arrow"></i>
        </div>
        <div class="ep-body">
          <div class="ep-section">
            <div class="ep-section-title">Query Parameters (اختيارية)</div>
            <table class="params-table">
              <tr><th>الحقل</th><th>النوع</th><th>الوصف</th><th>إلزامي</th></tr>
              <tr><td class="param-name">status</td><td class="param-type">string</td><td style="font-size:12px;color:var(--text2)">تصفية: pending | processing | completed | cancelled</td><td><span class="req-badge opt">اختياري</span></td></tr>
              <tr><td class="param-name">limit</td><td class="param-type">integer</td><td style="font-size:12px;color:var(--text2)">عدد النتائج (افتراضي: 20، أقصى: 100)</td><td><span class="req-badge opt">اختياري</span></td></tr>
              <tr><td class="param-name">page</td><td class="param-type">integer</td><td style="font-size:12px;color:var(--text2)">رقم الصفحة للتصفح</td><td><span class="req-badge opt">اختياري</span></td></tr>
            </table>
          </div>
          <div class="ep-section">
            <div class="ep-section-title">مثال الرد</div>
            <div class="code-wrap">
              <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
              <div class="code-block">{
  <span class="k">"status"</span>: <span class="g">"success"</span>,
  <span class="k">"total"</span>:  <span class="n">50</span>,
  <span class="k">"page"</span>:   <span class="n">1</span>,
  <span class="k">"data"</span>: [ <span class="c">/* مصفوفة طلبات */</span> ]
}</div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- end ep-list -->
  </div>

  <!-- ERRORS -->
  <div class="section">
    <div class="section-title"><span class="num">3</span><i class="fas fa-exclamation-triangle"></i> أكواد الأخطاء</div>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px">
      <div class="err-grid">
        <div class="err-code err-401">401</div><div class="err-desc">Token مفقود أو غير صحيح — تحقق من مفتاح الـ API</div>
        <div class="err-code err-403">403</div><div class="err-desc">IP غير مسموح له — أضف IP الخادم في لوحة API</div>
        <div class="err-code err-400">402</div><div class="err-desc">رصيد غير كافٍ — أعِد شحن المحفظة</div>
        <div class="err-code err-404">404</div><div class="err-desc">الخدمة أو الطلب غير موجود</div>
        <div class="err-code err-422">422</div><div class="err-desc">بيانات مفقودة أو غير صحيحة في الطلب</div>
        <div class="err-code err-429">429</div><div class="err-desc">تجاوزت حد الطلبات — انتظر قبل المحاولة</div>
        <div class="err-code" style="color:var(--red)">500</div><div class="err-desc">خطأ داخلي في السيرفر — تواصل مع الدعم</div>
      </div>
      <div class="code-wrap" style="margin-top:14px">
        <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
        <div class="code-block"><span class="c">// هيكل رد الخطأ دائماً:</span>
{
  <span class="k">"status"</span>:  <span class="r">"error"</span>,
  <span class="k">"code"</span>:    <span class="n">401</span>,
  <span class="k">"message"</span>: <span class="s">"Unauthorized: Invalid API token"</span>
}</div>
      </div>
    </div>
  </div>

  <!-- RATE LIMIT -->
  <div class="section">
    <div class="section-title"><span class="num">4</span><i class="fas fa-tachometer-alt"></i> حدود الاستخدام</div>
    <div class="rl-grid">
      <div class="rl-card">
        <div class="rl-num">60</div>
        <div class="rl-lbl">طلب / دقيقة</div>
      </div>
      <div class="rl-card">
        <div class="rl-num">1000</div>
        <div class="rl-lbl">طلب / يوم</div>
      </div>
    </div>
    <div style="background:rgba(245,166,35,.06);border:1px solid rgba(245,166,35,.15);border-radius:var(--radius-sm);padding:12px 14px;margin-top:10px;font-size:12px;color:var(--gold);display:flex;gap:8px;align-items:flex-start">
      <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0"></i>
      <div>عند تجاوز الحد ستصلك استجابة <code style="background:rgba(0,0,0,.3);padding:1px 6px;border-radius:4px;font-family:var(--mono)">429 Too Many Requests</code>. ننصح بإضافة تأخير بين الطلبات المتتالية.</div>
    </div>
  </div>

  <!-- QUICK CODE -->
  <div class="section">
    <div class="section-title"><span class="num">5</span><i class="fas fa-rocket"></i> مثال تطبيق كامل</div>
    <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
      <div style="display:flex;border-bottom:1px solid var(--border)">
        <button class="lang-tab active" onclick="switchLang('php',this)" style="flex:1;padding:10px;background:rgba(124,58,237,.1);border:none;color:#c4b5fd;font-family:var(--font);font-weight:700;font-size:12px;cursor:pointer">PHP</button>
        <button class="lang-tab" onclick="switchLang('python',this)" style="flex:1;padding:10px;background:transparent;border:none;color:var(--text3);font-family:var(--font);font-weight:700;font-size:12px;cursor:pointer;border-right:1px solid var(--border);border-left:1px solid var(--border)">Python</button>
        <button class="lang-tab" onclick="switchLang('js',this)" style="flex:1;padding:10px;background:transparent;border:none;color:var(--text3);font-family:var(--font);font-weight:700;font-size:12px;cursor:pointer">JavaScript</button>
      </div>
      <div id="lang-php" class="lang-block code-wrap">
        <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
        <div class="code-block"><?php
<span class="k">$token</span>   = <span class="s">'YOUR_API_TOKEN'</span>;
<span class="k">$apiBase</span> = <span class="s">'<?= htmlspecialchars($apiBase) ?>'</span>;

<span class="c">// جلب الخدمات</span>
<span class="k">$ch</span> = curl_init(<span class="k">$apiBase</span> . <span class="s">'/services'</span>);
curl_setopt_array(<span class="k">$ch</span>, [
    CURLOPT_RETURNTRANSFER => <span class="v">true</span>,
    CURLOPT_HTTPHEADER     => [<span class="s">"Authorization: Bearer $token"</span>],
]);
<span class="k">$res</span>  = json_decode(curl_exec(<span class="k">$ch</span>), <span class="v">true</span>);
curl_close(<span class="k">$ch</span>);

<span class="c">// إنشاء طلب</span>
<span class="k">$data</span> = json_encode([
    <span class="s">'service_id'</span> => <span class="n">1</span>,
    <span class="s">'quantity'</span>   => <span class="n">1</span>,
    <span class="s">'fields'</span>     => [<span class="s">'player_id'</span> => <span class="s">'123456789'</span>],
]);
<span class="k">$ch</span> = curl_init(<span class="k">$apiBase</span> . <span class="s">'/order'</span>);
curl_setopt_array(<span class="k">$ch</span>, [
    CURLOPT_POST           => <span class="v">true</span>,
    CURLOPT_POSTFIELDS     => <span class="k">$data</span>,
    CURLOPT_RETURNTRANSFER => <span class="v">true</span>,
    CURLOPT_HTTPHEADER     => [
        <span class="s">"Authorization: Bearer $token"</span>,
        <span class="s">"Content-Type: application/json"</span>,
    ],
]);
<span class="k">$order</span> = json_decode(curl_exec(<span class="k">$ch</span>), <span class="v">true</span>);
curl_close(<span class="k">$ch</span>);
echo <span class="s">"Order ID: "</span> . <span class="k">$order</span>[<span class="s">'order_id'</span>];</div>
      </div>
      <div id="lang-python" class="lang-block code-wrap" style="display:none">
        <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
        <div class="code-block">import requests

TOKEN   = <span class="s">"YOUR_API_TOKEN"</span>
API_BASE = <span class="s">"<?= htmlspecialchars($apiBase) ?>"</span>
HEADERS  = {<span class="s">"Authorization"</span>: <span class="g">f"Bearer {TOKEN}"</span>}

<span class="c"># جلب الخدمات</span>
services = requests.get(
    <span class="g">f"{API_BASE}/services"</span>,
    headers=HEADERS
).json()

<span class="c"># إنشاء طلب</span>
order = requests.post(
    <span class="g">f"{API_BASE}/order"</span>,
    headers=HEADERS,
    json={
        <span class="s">"service_id"</span>: <span class="n">1</span>,
        <span class="s">"quantity"</span>:   <span class="n">1</span>,
        <span class="s">"fields"</span>: {<span class="s">"player_id"</span>: <span class="s">"123456789"</span>}
    }
).json()

print(<span class="g">f"Order ID: {order['order_id']}"</span>)</div>
      </div>
      <div id="lang-js" class="lang-block code-wrap" style="display:none">
        <button class="code-copy" onclick="cpBlock(this)">نسخ</button>
        <div class="code-block"><span class="k">const</span> TOKEN   = <span class="s">"YOUR_API_TOKEN"</span>;
<span class="k">const</span> API_BASE = <span class="s">"<?= htmlspecialchars($apiBase) ?>"</span>;
<span class="k">const</span> headers  = { <span class="s">"Authorization"</span>: <span class="g">`Bearer ${TOKEN}`</span> };

<span class="c">// جلب الخدمات</span>
<span class="k">const</span> services = <span class="v">await</span> fetch(<span class="g">`${API_BASE}/services`</span>, { headers })
  .then(r => r.json());

<span class="c">// إنشاء طلب</span>
<span class="k">const</span> order = <span class="v">await</span> fetch(<span class="g">`${API_BASE}/order`</span>, {
  method:  <span class="s">"POST"</span>,
  headers: { ...headers, <span class="s">"Content-Type"</span>: <span class="s">"application/json"</span> },
  body: JSON.stringify({
    service_id: <span class="n">1</span>,
    quantity:   <span class="n">1</span>,
    fields: { player_id: <span class="s">"123456789"</span> }
  })
}).then(r => r.json());

console.log(<span class="s">"Order ID:"</span>, order.order_id);</div>
      </div>
    </div>
  </div>

  <div class="docs-footer">
    <div style="margin-bottom:6px">© <?= date('Y') ?> <?= htmlspecialchars($siteName) ?> — جميع الحقوق محفوظة</div>
    <div>للدعم الفني تواصل معنا عبر <a href="<?= $siteUrl ?>/mobile.php" style="color:var(--purple);text-decoration:none">الموقع</a></div>
  </div>
</div>

<div class="toast" id="toast">تم النسخ ✓</div>

<script>
function tog(headEl) {
  const arrow = headEl.querySelector('.ep-arrow');
  const body  = headEl.nextElementSibling;
  const open  = body.classList.toggle('open');
  if (arrow) arrow.classList.toggle('open', open);
}

function cp(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    if (btn) { const o = btn.textContent; btn.textContent = '✓'; setTimeout(() => btn.textContent = o, 1500); }
    showToast();
  });
}

function cpBlock(btn) {
  const block = btn.nextElementSibling;
  const text  = block ? block.textContent : '';
  navigator.clipboard.writeText(text.trim()).then(() => {
    const o = btn.textContent;
    btn.textContent = '✓ تم';
    btn.style.color = 'var(--green)';
    setTimeout(() => { btn.textContent = o; btn.style.color = ''; }, 1500);
    showToast();
  });
}

function showToast() {
  const t = document.getElementById('toast');
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 1800);
}

function switchLang(lang, btn) {
  document.querySelectorAll('.lang-block').forEach(b => b.style.display = 'none');
  document.querySelectorAll('.lang-tab').forEach(b => {
    b.style.background = 'transparent';
    b.style.color = 'var(--text3)';
  });
  document.getElementById('lang-' + lang).style.display = 'block';
  btn.style.background = 'rgba(124,58,237,.1)';
  btn.style.color = '#c4b5fd';
}
</script>
</body>
</html>