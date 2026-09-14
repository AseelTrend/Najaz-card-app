<?php
require_once __DIR__ . '/includes/config.php';

session_destroy();

// إذا جاء الطلب من mobile.php أو أي صفحة أخرى — ارجع لـ mobile.php دائماً
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($ref, '/admin') !== false) {
    header('Location: ' . SITE_URL . '/admin/');
} else {
    header('Location: ' . SITE_URL . '/mobile.php');
}
exit;
