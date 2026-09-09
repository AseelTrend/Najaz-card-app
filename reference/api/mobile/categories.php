<?php
// api/mobile/categories.php — قراءة فقط
// بدون parent_id: يرجع الأقسام الرئيسية فقط (parent_id فاضي)
// مع parent_id=X: يرجع الأقسام الفرعية تحت القسم X
require_once __DIR__ . '/_common.php';

$parentId = $_GET['parent_id'] ?? null;

if ($parentId !== null && $parentId !== '') {
    $stmt = $pdo->prepare("SELECT id, name, name_en, icon, image, parent_id, sort_order
                            FROM categories
                            WHERE status = 1 AND parent_id = ?
                            ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$parentId]);
} else {
    $stmt = $pdo->prepare("SELECT id, name, name_en, icon, image, parent_id, sort_order
                            FROM categories
                            WHERE status = 1 AND parent_id IS NULL
                            ORDER BY sort_order ASC, id ASC");
    $stmt->execute();
}

$rows = $stmt->fetchAll();

// تحويل صريح للأرقام (id / parent_id) — قاعدة البيانات ترجعها كنص أحياناً
// والتطبيق يحتاجها أرقام صريحة، وإلا ينهار عند فتح القسم
$categories = array_map(function ($c) {
    $c['id'] = (int)$c['id'];
    $c['parent_id'] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
    $c['sort_order'] = (int)($c['sort_order'] ?? 0);
    return $c;
}, $rows);

jsonOutMobile(true, 'categories fetched', ['categories' => $categories]);