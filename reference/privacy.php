<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/site_pages.php';
$siteName = getSetting('site_name') ?: SITE_NAME;
$managedPage = sitePagesFindVisibleBySlug($pdo, 'privacy');
$pageTitle = (($managedPage['title'] ?? 'سياسة الخصوصية') . ' — ' . $siteName);
$content = $managedPage['content'] ?? (getSetting('page_privacy') ?: '<p>هذه الصفحة قيد التحديث.</p>');
$isLoggedIn = isLoggedIn();
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
:root{--bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;--border:rgba(255,255,255,.07);--primary:#1e6fff;--cyan:#00d4ff;--green:#00e676;--text:#fff;--text2:#8fa3bf;--text3:#4d6080;--font:'Cairo',sans-serif}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);direction:rtl;min-height:100vh}
a{text-decoration:none;color:inherit}
#app{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg)}
.top-header{background:linear-gradient(135deg,#0d1428,#0a1535);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:58px;position:sticky;top:0;z-index:100}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;box-shadow:0 4px 12px rgba(30,111,255,.4)}
.logo-text{font-size:17px;font-weight:900;background:linear-gradient(90deg,#fff,var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hbtn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:16px;text-decoration:none}
.hbtns{display:flex;gap:8px}
.page-hero{background:linear-gradient(135deg,#0a1f3d 0%,#080c1a 100%);padding:28px 20px 24px;text-align:center;border-bottom:1px solid var(--border);position:relative;overflow:hidden}
.page-hero::before{content:'';position:absolute;top:-40px;left:-40px;width:160px;height:160px;background:radial-gradient(circle,rgba(0,212,255,.12) 0%,transparent 70%);pointer-events:none}
.page-hero-icon{width:64px;height:64px;background:linear-gradient(135deg,rgba(0,212,255,.2),rgba(30,111,255,.1));border:2px solid rgba(0,212,255,.25);border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 14px;position:relative;z-index:1}
.page-hero-title{font-size:1.25rem;font-weight:900;position:relative;z-index:1;margin-bottom:6px}
.page-hero-sub{font-size:.82rem;color:var(--text2);position:relative;z-index:1}
.page-content{padding:20px 16px 40px}
.html-content{font-size:.9rem;line-height:1.9;color:var(--text2)}
.html-content h1,.html-content h2,.html-content h3{color:#fff;margin:18px 0 10px;font-weight:900}
.html-content h1{font-size:1.2rem}.html-content h2{font-size:1.05rem}.html-content h3{font-size:.95rem}
.html-content p{margin-bottom:12px}
.html-content ul,.html-content ol{padding-right:20px;margin-bottom:12px}
.html-content li{margin-bottom:6px}
.html-content strong,.html-content b{color:#fff;font-weight:800}
.html-content a{color:var(--primary);text-decoration:underline}
.html-content blockquote{border-right:3px solid var(--cyan);padding:10px 14px;background:rgba(0,212,255,.05);border-radius:0 8px 8px 0;margin:12px 0;color:var(--text2)}
.html-content hr{border:none;border-top:1px solid var(--border);margin:16px 0}
.html-content img{max-width:100%;border-radius:12px;margin:8px 0}
.html-content table{width:100%;border-collapse:collapse;margin-bottom:12px;font-size:.85rem}
.html-content table td,.html-content table th{padding:8px 10px;border:1px solid var(--border);text-align:right}
.html-content table th{background:var(--card2);color:#fff;font-weight:800}
</style>
</head>
<body>
<div id="app">
<div class="top-header">
  <div class="header-logo">
    <div class="logo-icon">🚀</div>
    <div class="logo-text"><?= htmlspecialchars($siteName) ?></div>
  </div>
  <div class="hbtns">
    <a href="<?= SITE_URL ?>/mobile.php" class="hbtn"><i class="fas fa-home"></i></a>
    <?php if($isLoggedIn): ?>
    <a href="<?= SITE_URL ?>/orders.php" class="hbtn"><i class="fas fa-receipt"></i></a>
    <?php endif; ?>
  </div>
</div>

<div class="page-hero">
  <div class="page-hero-icon">🔒</div>
  <div class="page-hero-title">سياسة الخصوصية</div>
  <div class="page-hero-sub"><?= htmlspecialchars($siteName) ?></div>
</div>

<div class="page-content">
  <div class="html-content"><?= $content ?></div>
</div>
</div>
</body>
</html>
