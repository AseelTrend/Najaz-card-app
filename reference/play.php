<?php
$redirect_url = "https://play.google.com/store/apps/details?id=aseeltrend.yemoney";

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$is_bot = preg_match('/facebookexternalhit|Twitterbot|TelegramBot|WhatsApp|Slackbot|LinkedInBot|vkShare|W3C_Validator|curl|bot|crawler|spider/i', $ua);

if ($is_bot) {
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>تطبيق نجاز - Google Play</title>
    <meta property="og:title"        content="تطبيق نجاز | Njaz App" />
    <meta property="og:description"  content="حمّل تطبيق نجاز الآن من Google Play واستمتع بأفضل الخدمات." />
    <meta property="og:image"        content="https://njaz.net/og/play-store.jpg" />
    <meta property="og:image:width"  content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:url"          content="https://njaz.net/play" />
    <meta property="og:type"         content="website" />
    <meta name="twitter:card"        content="summary_large_image" />
    <meta name="twitter:title"       content="تطبيق نجاز على Google Play" />
    <meta name="twitter:description" content="حمّل التطبيق الآن." />
    <meta name="twitter:image"       content="https://njaz.net/og/play-store.jpg" />
</head>
<body></body>
</html>
    <?php
    exit();
}

header("Location: $redirect_url", true, 301);
exit();
