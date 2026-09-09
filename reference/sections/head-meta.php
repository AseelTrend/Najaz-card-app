<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#080c1a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($siteName) ?>">
<meta name="description" content="منصتك الموثوقة لشحن الألعاب والتطبيقات والبطاقات الرقمية بأسرع وقت وأفضل سعر">
<title><?= htmlspecialchars($siteName) ?></title>

<!-- PWA Manifest (dynamic) -->
<link rel="manifest" href="<?= SITE_URL ?>/manifest.php">

<!-- Apple Touch Icons + Dynamic Favicon -->
<?php if ($siteLogoUrl): ?>
<link rel="apple-touch-icon" href="<?= htmlspecialchars($siteLogoUrl) ?>">
<link rel="icon" href="<?= htmlspecialchars($siteLogoUrl) ?>" type="image/png">
<link rel="shortcut icon" href="<?= htmlspecialchars($siteLogoUrl) ?>" type="image/png">
<?php else: ?>
<link rel="apple-touch-icon" sizes="192x192" href="/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512" href="/icons/icon-512.png">
<link rel="icon" href="/icons/icon-96.png" type="image/png">
<link rel="shortcut icon" href="/icons/icon-96.png" type="image/png">
<?php endif; ?>

<!-- Apple Splash Screens (portrait) -->
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap"></noscript>
<link rel="preload" as="style" href="/assets/fontawesome/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="/assets/fontawesome/css/all.min.css"></noscript>
