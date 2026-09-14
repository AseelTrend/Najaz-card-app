<?php
$redirect_url = "https://whatsapp.com/channel/0029Vb6JAbDAYlUB5YKEnp3c";

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$is_bot = preg_match('/facebookexternalhit|Twitterbot|TelegramBot|WhatsApp|Slackbot|LinkedInBot|vkShare|W3C_Validator|curl|bot|crawler|spider/i', $ua);

if ($is_bot) {
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>قناة نجاز على واتساب</title>
    <meta property="og:title"        content="قناة نجاز | Njaz Channel" />
    <meta property="og:description"  content="انضم إلى قناة نجاز على واتساب وكن أول من يعلم بآخر العروض والأخبار." />
    <meta property="og:image"        content="https://njaz.net/og/whatsapp-channel.jpg" />
    <meta property="og:image:width"  content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:url"          content="https://njaz.net/gwats" />
    <meta property="og:type"         content="website" />
    <meta name="twitter:card"        content="summary_large_image" />
    <meta name="twitter:title"       content="قناة نجاز على واتساب" />
    <meta name="twitter:description" content="انضم الآن وتابع أحدث العروض." />
    <meta name="twitter:image"       content="https://njaz.net/og/whatsapp-channel.jpg" />
</head>
<body></body>
</html>
    <?php
    exit();
}

header("Location: $redirect_url", true, 301);
exit();
