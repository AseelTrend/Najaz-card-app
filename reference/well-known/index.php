<?php
require_once 'includes/config.php';
// توجيه للواجهة الجديدة
header('Location: ' . SITE_URL . '/mobile.php');
exit;
$pageTitle = 'الرئيسية - ' . SITE_NAME;

// جلب الأقسام الرئيسية
$cats = $pdo->query("SELECT * FROM categories WHERE parent_id IS NULL AND status=1 ORDER BY sort_order")->fetchAll();

// جلب عدد الخدمات
$totalServices = $pdo->query("SELECT COUNT(*) FROM services WHERE status=1")->fetchColumn();
$totalOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();

include 'includes/header.php';
?>

<!-- Hero -->
<div class="hero">
    <h1><i class="fas fa-rocket"></i> مرحباً بك في <span><?= getSetting('site_name') ?: SITE_NAME ?></span></h1>
    <p>منصتك الموثوقة لشحن الألعاب والتطبيقات والبطاقات الرقمية بأسرع وقت وأفضل سعر</p>
    <a href="services.php" class="btn btn-success"><i class="fas fa-th-large"></i> تصفح الخدمات</a>
    &nbsp;
    <?php if (!isLoggedIn()): ?>
    <a href="register.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> إنشاء حساب</a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="color:#6c3fe0"><i class="fas fa-gamepad"></i></div>
        <div class="stat-value"><?= number_format($totalServices) ?>+</div>
        <div class="stat-label">خدمة متاحة</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:#00d4aa"><i class="fas fa-check-circle"></i></div>
        <div class="stat-value"><?= number_format($totalOrders) ?>+</div>
        <div class="stat-label">طلب مكتمل</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:#ffa502"><i class="fas fa-bolt"></i></div>
        <div class="stat-value">فوري</div>
        <div class="stat-label">تنفيذ الطلبات</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:#3498db"><i class="fas fa-headset"></i></div>
        <div class="stat-value">24/7</div>
        <div class="stat-label">دعم فني</div>
    </div>
</div>

<!-- Categories -->
<h2 class="section-title"><i class="fas fa-th-large"></i> أقسام <span>الخدمات</span></h2>
<div class="categories-grid">
    <?php foreach ($cats as $cat): ?>
    <a href="services.php?cat=<?= $cat['id'] ?>" class="cat-card">
        <i class="<?= htmlspecialchars($cat['icon']) ?>"></i>
        <h3><?= htmlspecialchars($cat['name']) ?></h3>
    </a>
    <?php endforeach; ?>
</div>

<!-- Features -->
<h2 class="section-title"><i class="fas fa-star"></i> لماذا <span>نحن؟</span></h2>
<div class="categories-grid">
    <div class="cat-card">
        <i class="fas fa-shield-alt" style="color:#00d4aa"></i>
        <h3>أمان تام</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-top:0.5rem">معاملاتك محمية بالكامل</p>
    </div>
    <div class="cat-card">
        <i class="fas fa-bolt" style="color:#ffa502"></i>
        <h3>تنفيذ فوري</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-top:0.5rem">تُنفذ الطلبات بشكل تلقائي</p>
    </div>
    <div class="cat-card">
        <i class="fas fa-tags" style="color:#6c3fe0"></i>
        <h3>أسعار تنافسية</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-top:0.5rem">أفضل الأسعار في السوق</p>
    </div>
    <div class="cat-card">
        <i class="fas fa-mobile-alt" style="color:#3498db"></i>
        <h3>سهل الاستخدام</h3>
        <p class="text-muted" style="font-size:0.85rem;margin-top:0.5rem">واجهة بسيطة ومريحة</p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
