<?php
// صفحة عامة - لا تتطلب تسجيل دخول
// نمنع أي redirect تلقائي
define('PUBLIC_PAGE', true);
require_once 'includes/config.php';
$siteName  = getSetting('site_name') ?: SITE_NAME;
$pageTitle = 'الأسئلة الشائعة — ' . $siteName;
$pageDesc  = getSetting('faq_description') ?: 'إجابات على أكثر الأسئلة شيوعاً حول خدماتنا';

// إنشاء الجدول إذا لم يكن موجوداً
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `faq_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `question` TEXT NOT NULL,
        `answer` TEXT NOT NULL,
        `category` VARCHAR(100) DEFAULT NULL,
        `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e) {}

// جلب الأسئلة مجمّعة حسب التصنيف
$faqs = [];
try {
    $stmt = $pdo->query("SELECT * FROM faq_items WHERE status=1 ORDER BY category, sort_order, id");
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $cat = $row['category'] ?: 'عام';
        $faqs[$cat][] = $row;
    }
} catch(Exception $e) { $faqs = []; }

$isLoggedIn  = isLoggedIn();
$userBalance = 0;
if ($isLoggedIn) {
    $u = $pdo->prepare("SELECT balance FROM users WHERE id=?");
    $u->execute([$_SESSION['user_id']]); $u=$u->fetch();
    $userBalance = $u['balance'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#080c1a">
<meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
<meta name="robots" content="index, follow">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- Schema.org FAQPage لـ SEO -->
<?php if (!empty($faqs)): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    <?php
    $allFaqs = array_merge(...array_values($faqs));
    $schemaItems = [];
    foreach ($allFaqs as $f) {
        $schemaItems[] = '{
          "@type": "Question",
          "name": ' . json_encode($f['question'], JSON_UNESCAPED_UNICODE) . ',
          "acceptedAnswer": {
            "@type": "Answer",
            "text": ' . json_encode(strip_tags($f['answer']), JSON_UNESCAPED_UNICODE) . '
          }
        }';
    }
    echo implode(',', $schemaItems);
    ?>
  ]
}
</script>
<?php endif; ?>
<style>
:root{--bg:#080c1a;--bg2:#0d1428;--card:#111827;--card2:#1a2340;--border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.12);--primary:#1e6fff;--cyan:#00d4ff;--green:#00e676;--text:#fff;--text2:#8fa3bf;--text3:#4d6080;--font:'Cairo',sans-serif}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);direction:rtl;min-height:100vh}
a{text-decoration:none;color:inherit}
#app{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg)}
.top-header{background:linear-gradient(135deg,#0d1428,#0a1535);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 16px;height:58px;position:sticky;top:0;z-index:100}
.header-logo{display:flex;align-items:center;gap:8px}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--primary),var(--cyan));border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;box-shadow:0 4px 12px rgba(30,111,255,.4)}
.logo-text{font-size:17px;font-weight:900;background:linear-gradient(90deg,#fff,var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hbtn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:16px;text-decoration:none;transition:.2s}
/* Hero */
.faq-hero{background:linear-gradient(135deg,#0d1428 0%,#0a1a3a 100%);padding:24px 16px 20px;text-align:center;border-bottom:1px solid var(--border)}
.faq-hero-icon{font-size:2.5rem;margin-bottom:10px}
.faq-hero-title{font-size:1.3rem;font-weight:900;margin-bottom:6px}
.faq-hero-sub{font-size:.83rem;color:var(--text2);line-height:1.6}
/* بحث */
.faq-search-wrap{padding:14px 14px 0}
.faq-search{width:100%;background:var(--card2);border:1.5px solid var(--border2);border-radius:12px;padding:11px 14px 11px 40px;color:var(--text);font-family:var(--font);font-size:.88rem;outline:none;transition:.2s}
.faq-search:focus{border-color:var(--primary)}
.faq-search-icon{position:absolute;left:27px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:.85rem;pointer-events:none}
/* التصنيفات */
.faq-cats{display:flex;gap:8px;overflow-x:auto;padding:12px 14px;scrollbar-width:none}
.faq-cats::-webkit-scrollbar{display:none}
.faq-cat-btn{flex-shrink:0;padding:5px 14px;border-radius:20px;border:1.5px solid var(--border2);background:var(--card2);color:var(--text2);font-family:var(--font);font-size:.78rem;font-weight:700;cursor:pointer;transition:.2s;white-space:nowrap}
.faq-cat-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
/* التصنيف عنوان */
.faq-cat-title{padding:6px 14px 8px;font-size:.75rem;color:var(--text3);font-weight:800;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}
/* الأسئلة */
.faq-list{padding:0 12px 80px}
.faq-item{background:var(--card2);border:1px solid var(--border);border-radius:14px;margin-bottom:9px;overflow:hidden;transition:.2s}
.faq-item.open{border-color:rgba(30,111,255,.35);box-shadow:0 0 0 1px rgba(30,111,255,.1)}
.faq-question{display:flex;align-items:center;justify-content:space-between;padding:14px 14px;cursor:pointer;gap:10px;-webkit-tap-highlight-color:transparent}
.faq-q-text{font-size:.9rem;font-weight:700;flex:1;line-height:1.5}
.faq-q-icon{width:26px;height:26px;border-radius:50%;background:rgba(30,111,255,.1);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0;transition:transform .25s}
.faq-item.open .faq-q-icon{transform:rotate(180deg);background:var(--primary);color:#fff}
.faq-answer{max-height:0;overflow:hidden;transition:max-height .3s ease}
.faq-item.open .faq-answer{max-height:600px}
.faq-answer-inner{padding:0 14px 14px;font-size:.85rem;color:var(--text2);line-height:1.8;border-top:1px solid var(--border)}
.faq-answer-inner p{margin-bottom:8px}
.faq-answer-inner p:last-child{margin-bottom:0}
/* فارغ */
.faq-empty{text-align:center;padding:3rem 1rem;color:var(--text3)}
.faq-empty i{font-size:2.5rem;opacity:.1;display:block;margin-bottom:12px}
/* no results */
#noResults{display:none;text-align:center;padding:2rem;color:var(--text3)}
</style>
</head>
<body>
<div id="app">

<div class="top-header">
  <div class="header-logo">
    <div class="logo-icon">🚀</div>
    <div class="logo-text"><?= htmlspecialchars($siteName) ?></div>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= SITE_URL ?>/mobile.php" class="hbtn"><i class="fas fa-home"></i></a>
  </div>
</div>

<!-- Hero -->
<div class="faq-hero">
  <div class="faq-hero-icon">❓</div>
  <h1 class="faq-hero-title">الأسئلة الشائعة</h1>
  <div class="faq-hero-sub"><?= htmlspecialchars($pageDesc) ?></div>
</div>

<?php if (empty($faqs)): ?>
<div class="faq-empty">
  <i class="fas fa-question-circle"></i>
  <div style="font-weight:700">لا توجد أسئلة بعد</div>
  <div style="font-size:.8rem;margin-top:6px">سيتم إضافة الأسئلة الشائعة قريباً</div>
</div>

<?php else: ?>

<!-- بحث -->
<div class="faq-search-wrap" style="position:relative">
  <i class="fas fa-search faq-search-icon"></i>
  <input type="text" class="faq-search" id="faqSearch" placeholder="ابحث في الأسئلة..." oninput="searchFaq(this.value)">
</div>

<!-- تصنيفات -->
<?php if (count($faqs) > 1): ?>
<div class="faq-cats" id="faqCats">
  <button class="faq-cat-btn active" onclick="filterCat('all',this)">الكل</button>
  <?php foreach (array_keys($faqs) as $cat): ?>
  <button class="faq-cat-btn" onclick="filterCat('<?= htmlspecialchars($cat) ?>',this)"><?= htmlspecialchars($cat) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- الأسئلة -->
<div class="faq-list" id="faqList">
  <?php foreach ($faqs as $cat => $items): ?>
  <?php if (count($faqs) > 1): ?>
  <div class="faq-cat-title" data-cat="<?= htmlspecialchars($cat) ?>">
    <i class="fas fa-folder" style="color:var(--primary)"></i>
    <?= htmlspecialchars($cat) ?>
  </div>
  <?php endif; ?>
  <?php foreach ($items as $i => $faq): ?>
  <div class="faq-item" id="faq_<?= $faq['id'] ?>" data-cat="<?= htmlspecialchars($faq['category'] ?: 'عام') ?>" data-q="<?= htmlspecialchars(mb_strtolower($faq['question'])) ?>" data-a="<?= htmlspecialchars(mb_strtolower(strip_tags($faq['answer']))) ?>">
    <div class="faq-question" onclick="toggleFaq(<?= $faq['id'] ?>)">
      <div class="faq-q-text"><?= htmlspecialchars($faq['question']) ?></div>
      <div class="faq-q-icon"><i class="fas fa-chevron-down"></i></div>
    </div>
    <div class="faq-answer">
      <div class="faq-answer-inner"><?= nl2br(htmlspecialchars($faq['answer'])) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <div id="noResults"><i class="fas fa-search" style="opacity:.15;font-size:2rem;display:block;margin-bottom:10px"></i>لا توجد نتائج</div>
</div>

<?php endif; ?>

<!-- CTA -->
<?php if (isLoggedIn()): ?>
<div style="margin:0 12px 20px;background:linear-gradient(135deg,rgba(30,111,255,.1),rgba(0,212,255,.05));border:1px solid rgba(30,111,255,.2);border-radius:14px;padding:14px;text-align:center">
  <div style="font-size:.88rem;font-weight:700;margin-bottom:8px">لم تجد إجابة لسؤالك؟</div>
  <a href="<?= SITE_URL ?>/mobile.php" style="display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;padding:9px 20px;border-radius:10px;font-size:.82rem;font-weight:700">
    <i class="fas fa-comment-dots"></i> تواصل مع الدعم
  </a>
</div>
<?php else: ?>
<div style="margin:0 12px 20px;background:rgba(30,111,255,.06);border:1px solid rgba(30,111,255,.15);border-radius:14px;padding:14px;text-align:center">
  <div style="font-size:.88rem;font-weight:700;margin-bottom:8px">انضم إلينا اليوم</div>
  <a href="<?= SITE_URL ?>/mobile.php" style="display:inline-flex;align-items:center;gap:6px;background:var(--primary);color:#fff;padding:9px 20px;border-radius:10px;font-size:.82rem;font-weight:700">
    <i class="fas fa-sign-in-alt"></i> تسجيل مجاني
  </a>
</div>
<?php endif; ?>

</div><!-- #app -->

<script>
function toggleFaq(id) {
  var el = document.getElementById('faq_' + id);
  if (!el) return;
  var isOpen = el.classList.contains('open');
  // أغلق كل المفتوحة
  document.querySelectorAll('.faq-item.open').forEach(function(e){ e.classList.remove('open'); });
  if (!isOpen) el.classList.add('open');
}

function filterCat(cat, btn) {
  document.querySelectorAll('.faq-cat-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('.faq-item').forEach(function(el){
    el.style.display = (cat === 'all' || el.dataset.cat === cat) ? '' : 'none';
  });
  document.querySelectorAll('.faq-cat-title').forEach(function(el){
    el.style.display = (cat === 'all' || el.dataset.cat === cat) ? '' : 'none';
  });
  document.getElementById('noResults').style.display = 'none';
}

function searchFaq(q) {
  q = q.trim().toLowerCase();
  var count = 0;
  document.querySelectorAll('.faq-item').forEach(function(el){
    if (!q || el.dataset.q.includes(q) || el.dataset.a.includes(q)) {
      el.style.display = ''; count++;
      if (q) el.classList.add('open'); // افتح النتيجة تلقائياً
    } else {
      el.style.display = 'none';
    }
  });
  document.querySelectorAll('.faq-cat-title').forEach(function(el){
    el.style.display = q ? 'none' : '';
  });
  document.getElementById('noResults').style.display = count === 0 ? 'block' : 'none';
  // أعد تفعيل "الكل"
  if (!q) {
    document.querySelectorAll('.faq-cat-btn').forEach(function(b){ b.classList.remove('active'); });
    var allBtn = document.querySelector('.faq-cat-btn');
    if (allBtn) allBtn.classList.add('active');
  }
}
</script>
</body>
</html>
