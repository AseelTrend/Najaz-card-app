<?php
require_once __DIR__ . '/_common.php';

try {
    $stmt = $pdo->query(
        "SELECT id,title,subtitle,tag,bg_color,text_color,accent_color,image,link_type,link_value
         FROM banners WHERE status=1 ORDER BY sort_order ASC, id ASC"
    );
    $banners = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $banner) {
        $image = trim((string)($banner['image'] ?? ''));
        $banner['id'] = (int)$banner['id'];
        $banner['image_url'] = $image !== '' ? rtrim(SITE_URL, '/') . '/' . ltrim($image, '/') : '';
        $banner['link_value'] = (string)($banner['link_value'] ?? '');
        $banners[] = $banner;
    }
    jsonOutMobile(true, 'banners fetched', ['banners' => $banners]);
} catch (Throwable $e) {
    jsonOutMobile(false, 'تعذر تحميل البانرات', [], 500);
}
