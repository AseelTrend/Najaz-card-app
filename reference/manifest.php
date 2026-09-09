<?php
/**
 * manifest.php — PWA Manifest ديناميكي
 * يقرأ الشعار واسم الموقع من إعدادات قاعدة البيانات
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$siteName   = getSetting('site_name')  ?: SITE_NAME;
$siteLogo   = getSetting('site_logo')  ?: '';
$logoUrl    = $siteLogo ? SITE_URL . '/' . $siteLogo : '';

// بناء قائمة الأيقونات
if ($logoUrl) {
    // استخدام شعار الموقع كأيقونة للتطبيق بكل الأحجام
    $icons = [];
    foreach ([72, 96, 128, 144, 192, 512] as $size) {
        $icons[] = [
            'src'     => $logoUrl,
            'sizes'   => "{$size}x{$size}",
            'type'    => 'image/png',
            'purpose' => 'maskable any',
        ];
    }
} else {
    // أيقونات افتراضية
    $icons = [];
    foreach ([72, 96, 128, 144, 192, 512] as $size) {
        $icons[] = [
            'src'     => SITE_URL . "/icons/icon-{$size}.png",
            'sizes'   => "{$size}x{$size}",
            'type'    => 'image/png',
            'purpose' => 'maskable any',
        ];
    }
}

$manifest = [
    'name'             => $siteName,
    'short_name'       => mb_substr($siteName, 0, 12),
    'description'      => 'منصتك الموثوقة لشحن الألعاب والتطبيقات والبطاقات الرقمية',
    'start_url'        => SITE_URL . '/mobile.php',
    'scope'            => SITE_URL . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#080c1a',
    'theme_color'      => '#080c1a',
    'lang'             => 'ar',
    'dir'              => 'rtl',
    'categories'       => ['shopping', 'finance', 'games'],
    'icons'            => $icons,
    'screenshots'      => [
        [
            'src'         => $logoUrl ?: SITE_URL . '/icons/icon-512.png',
            'sizes'       => '512x512',
            'type'        => 'image/png',
            'form_factor' => 'narrow',
            'label'       => 'الشاشة الرئيسية',
        ]
    ],
    'shortcuts' => [
        [
            'name'       => 'الخدمات',
            'short_name' => 'خدمات',
            'url'        => SITE_URL . '/mobile.php#services',
            'icons'      => [['src' => $logoUrl ?: SITE_URL . '/icons/icon-96.png', 'sizes' => '96x96']],
        ],
        [
            'name'       => 'طلباتي',
            'short_name' => 'طلباتي',
            'url'        => SITE_URL . '/mobile.php#orders',
            'icons'      => [['src' => $logoUrl ?: SITE_URL . '/icons/icon-96.png', 'sizes' => '96x96']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
