<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_dashboard');
$IS_ADMIN = isAdmin();
if ($IS_ADMIN) {
    $staffCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetchColumn();
    $activeStaff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='staff' AND status=1")->fetchColumn();
    try {
        $pendingDevsList = $pdo->query("SELECT d.*,u.username FROM user_devices d JOIN users u ON d.user_id=u.id WHERE d.status='pending' ORDER BY d.created_at DESC LIMIT 5")->fetchAll();
    } catch(Exception $e){ $pendingDevsList = []; }
}
$pageTitle = 'لوحة التحكم - ' . SITE_NAME;

$stats = [
  'customers' => $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn(),
  'orders'    => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
  'pending'   => $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
  'processing'=> $pdo->query("SELECT COUNT(*) FROM orders WHERE status='processing'")->fetchColumn(),
  'completed' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn(),
  'revenue'   => $pdo->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status='completed'")->fetchColumn(),
  'services'  => $pdo->query("SELECT COUNT(*) FROM services WHERE status=1")->fetchColumn(),
  'balance'   => $pdo->query("SELECT COALESCE(SUM(balance),0) FROM users WHERE role='customer'")->fetchColumn(),
  'today'     => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn(),
  'revenue_today' => $pdo->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status='completed' AND DATE(created_at)=CURDATE()")->fetchColumn(),
];

// آخر 7 أيام للرسم البياني
$chartData = $pdo->query("
  SELECT DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(total_price),0) as rev
  FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  GROUP BY DATE(created_at) ORDER BY d ASC
")->fetchAll();
// Fill missing days
$chart = [];
for ($i=6; $i>=0; $i--) {
  $day = date('Y-m-d', strtotime("-$i days"));
  $label = date('d/m', strtotime($day));
  $found = array_filter($chartData, fn($r) => $r['d'] === $day);
  $found = $found ? array_values($found)[0] : ['cnt'=>0,'rev'=>0];
  $chart[] = ['label'=>$label, 'cnt'=>(int)$found['cnt'], 'rev'=>(float)$found['rev']];
}
$maxCnt = max(1, max(array_column($chart,'cnt')));
$maxRev = max(1, max(array_column($chart,'rev')));

$lastOrders = $pdo->query("
  SELECT o.*, u.username, s.name as service_name
  FROM orders o JOIN users u ON o.user_id=u.id JOIN services s ON o.service_id=s.id
  ORDER BY o.created_at DESC LIMIT 8
")->fetchAll();

$statusMap = [
  'pending'    => ['قيد الانتظار','warning'],
  'processing' => ['قيد التنفيذ','info'],
  'completed'  => ['مكتمل','success'],
  'cancelled'  => ['ملغي','danger'],
  'failed'     => ['فشل','danger'],
];

include 'header.php';

// إشعار الأجهزة المعلقة
$pendingDevsList = $pdo->query("SELECT d.*,u.username FROM user_devices d JOIN users u ON d.user_id=u.id WHERE d.status='pending' ORDER BY d.created_at DESC LIMIT 5")->fetchAll();
?>
<?php if (!empty($pendingDevsList)): ?>
<div style="background:rgba(245,166,35,0.1);border:1px solid rgba(245,166,35,0.3);border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:14px">
  <div style="font-size:2rem">⏳</div>
  <div style="flex:1">
    <div style="font-weight:800;color:#f5a623;margin-bottom:4px"><?= count($pendingDevsList) ?> جهاز ينتظر التصريح</div>
    <div style="font-size:.82rem;color:#8895a7"><?= implode(' · ', array_map(fn($d)=>htmlspecialchars($d['username']).' ('.$d['device_name'].')', $pendingDevsList)) ?></div>
  </div>
  <a href="<?= SITE_URL ?>/admin/customers.php" class="btn btn-sm btn-warning"><i class="fas fa-mobile-alt"></i> إدارة الأجهزة</a>
</div>
<?php endif; ?>



<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon stat-icon-blue" style="background:rgba(37,99,235,0.15)"><i class="fas fa-chart-line" style="color:var(--primary)"></i></div>
      لوحة التحكم
    </div>
    <div class="page-header-sub">مرحباً <?= htmlspecialchars($adminName ?? 'Admin') ?> — آخر تحديث <?= date('H:i') ?></div>
  </div>
  <div class="page-header-actions">
    <a href="services.php?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> خدمة جديدة</a>
    <a href="orders.php?status=pending" class="btn btn-warning btn-sm"><i class="fas fa-clock"></i> الطلبات المعلقة <?php if($stats['pending']>0): ?><span style="background:rgba(0,0,0,0.2);padding:1px 7px;border-radius:20px;font-size:11px"><?= $stats['pending'] ?></span><?php endif; ?></a>
  </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
  <div class="stat-card stat-card-blue">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-blue"><i class="fas fa-users"></i></div>
      <div class="stat-trend stat-trend-up"><i class="fas fa-arrow-up"></i> نشط</div>
    </div>
    <div class="stat-value"><?= number_format($stats['customers']) ?></div>
    <div class="stat-label">إجمالي العملاء</div>
    <div class="stat-footer"><i class="fas fa-wallet" style="color:var(--primary)"></i> أرصدتهم: <?= number_format($stats['balance'],0) ?></div>
  </div>

  <div class="stat-card stat-card-green">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-green"><i class="fas fa-money-bill-wave"></i></div>
      <div class="stat-trend stat-trend-up"><i class="fas fa-arrow-up"></i> إجمالي</div>
    </div>
    <div class="stat-value"><?= number_format($stats['revenue'],0) ?></div>
    <div class="stat-label">الإيرادات الكلية</div>
    <div class="stat-footer"><i class="fas fa-calendar-day" style="color:var(--green)"></i> اليوم: <?= number_format($stats['revenue_today'],2) ?></div>
  </div>

  <div class="stat-card stat-card-gold">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-gold"><i class="fas fa-shopping-bag"></i></div>
      <?php if($stats['pending']>0): ?>
      <div class="stat-trend stat-trend-down"><i class="fas fa-clock"></i> تحتاج مراجعة</div>
      <?php else: ?>
      <div class="stat-trend stat-trend-up"><i class="fas fa-check"></i> جيد</div>
      <?php endif; ?>
    </div>
    <div class="stat-value"><?= number_format($stats['orders']) ?></div>
    <div class="stat-label">إجمالي الطلبات</div>
    <div class="stat-footer"><i class="fas fa-calendar-day" style="color:var(--gold)"></i> اليوم: <?= $stats['today'] ?> طلب</div>
  </div>

  <div class="stat-card stat-card-red">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-red"><i class="fas fa-hourglass-half"></i></div>
      <?php if($stats['pending']>0): ?><div class="stat-trend stat-trend-down"><i class="fas fa-exclamation"></i> تنتظر</div><?php else: ?><div class="stat-trend stat-trend-up"><i class="fas fa-check"></i> صفر</div><?php endif; ?>
    </div>
    <div class="stat-value"><?= number_format($stats['pending']) ?></div>
    <div class="stat-label">طلبات معلقة</div>
    <div class="stat-footer"><i class="fas fa-sync" style="color:var(--red)"></i> قيد التنفيذ: <?= $stats['processing'] ?></div>
  </div>

  <div class="stat-card stat-card-purple">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-purple"><i class="fas fa-box"></i></div>
      <div class="stat-trend stat-trend-up"><i class="fas fa-star"></i> نشطة</div>
    </div>
    <div class="stat-value"><?= number_format($stats['services']) ?></div>
    <div class="stat-label">الخدمات النشطة</div>
    <div class="stat-footer"><a href="services.php?action=add" style="color:var(--purple)"><i class="fas fa-plus"></i> إضافة خدمة</a></div>
  </div>

  <div class="stat-card stat-card-cyan">
    <div class="stat-card-header">
      <div class="stat-icon stat-icon-cyan"><i class="fas fa-check-circle"></i></div>
      <div class="stat-trend stat-trend-up"><i class="fas fa-arrow-up"></i> مكتملة</div>
    </div>
    <div class="stat-value"><?= number_format($stats['completed']) ?></div>
    <div class="stat-label">طلبات مكتملة</div>
    <div class="stat-footer">
      <?php $rate = $stats['orders']>0 ? round($stats['completed']/$stats['orders']*100) : 0; ?>
      <i class="fas fa-percent" style="color:var(--cyan)"></i> معدل الإنجاز: <?= $rate ?>%
    </div>
  </div>
</div>

<!-- Charts + Quick Actions Row -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;margin-bottom:20px">

  <!-- Bar Chart -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-chart-bar"></i> الطلبات — آخر 7 أيام</div>
      <div style="display:flex;gap:12px;font-size:12px;color:var(--text3)">
        <span><i class="fas fa-square" style="color:var(--primary)"></i> الطلبات</span>
      </div>
    </div>
    <div class="chart-container" style="padding:20px 20px 0;height:180px">
      <?php foreach ($chart as $c): ?>
      <div class="chart-bar" data-val="<?= $c['cnt'] ?> طلب"
           style="height:<?= $maxCnt > 0 ? round($c['cnt']/$maxCnt*100) : 0 ?>%;background:<?= $c['cnt'] > 0 ? 'linear-gradient(180deg,var(--primary),rgba(37,99,235,0.3))' : 'var(--card2)' ?>"
           title="<?= $c['label'] ?>: <?= $c['cnt'] ?> طلبات"></div>
      <?php endforeach; ?>
    </div>
    <div class="chart-labels">
      <?php foreach ($chart as $c): ?>
      <div class="chart-label"><?= $c['label'] ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-title"><i class="fas fa-bolt"></i> إجراءات سريعة</div>
    </div>
    <div class="card-body" style="padding:12px">
      <a href="services.php?action=add" class="qa-card mb-1">
        <div class="qa-card-icon" style="background:rgba(37,99,235,0.15);color:var(--primary)"><i class="fas fa-plus"></i></div>
        <div class="qa-card-text"><div class="qa-card-label">إضافة خدمة</div><div class="qa-card-sub">أضف خدمة جديدة</div></div>
      </a>
      <a href="categories.php?action=add" class="qa-card mb-1">
        <div class="qa-card-icon" style="background:rgba(6,182,212,0.15);color:var(--cyan)"><i class="fas fa-folder-plus"></i></div>
        <div class="qa-card-text"><div class="qa-card-label">إضافة قسم</div><div class="qa-card-sub">قسم رئيسي أو فرعي</div></div>
      </a>
      <a href="customers.php" class="qa-card mb-1">
        <div class="qa-card-icon" style="background:rgba(16,185,129,0.15);color:var(--green)"><i class="fas fa-user-plus"></i></div>
        <div class="qa-card-text"><div class="qa-card-label">إدارة العملاء</div><div class="qa-card-sub">رصيد وحالة الحسابات</div></div>
      </a>
      <a href="orders.php?status=pending" class="qa-card">
        <div class="qa-card-icon" style="background:rgba(245,158,11,0.15);color:var(--gold)"><i class="fas fa-clock"></i></div>
        <div class="qa-card-text"><div class="qa-card-label">الطلبات المعلقة</div><div class="qa-card-sub"><?= $stats['pending'] ?> طلب ينتظر</div></div>
      </a>
    </div>
  </div>
</div>

<!-- Last Orders -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-history"></i> آخر الطلبات</div>
    <a href="orders.php" class="btn btn-secondary btn-sm"><i class="fas fa-list"></i> عرض الكل</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>العميل</th>
          <th>الخدمة</th>
          <th>المبلغ</th>
          <th>الحالة</th>
          <th>التاريخ</th>
          <th>إجراء</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($lastOrders): foreach ($lastOrders as $o):
          $st = $statusMap[$o['status']] ?? [$o['status'],'secondary'];
        ?>
        <tr>
          <td class="td-id">#<?= str_pad($o['id'],5,'0',STR_PAD_LEFT) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;background:linear-gradient(135deg,var(--primary),var(--purple));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;flex-shrink:0"><?= mb_strtoupper(mb_substr($o['username'],0,1)) ?></div>
              <span class="td-bold"><?= htmlspecialchars($o['username']) ?></span>
            </div>
          </td>
          <td class="truncate" style="max-width:160px"><?= htmlspecialchars($o['service_name']) ?></td>
          <td><span style="color:var(--green);font-weight:900"><?= formatMoney($o['total_price']) ?></span></td>
          <td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td>
          <td class="td-muted"><?= date('d/m H:i', strtotime($o['created_at'])) ?></td>
          <td>
            <a href="orders.php?id=<?= $o['id'] ?>" class="btn btn-secondary btn-xs"><i class="fas fa-eye"></i></a>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="7"><div class="empty-state" style="padding:30px"><span class="empty-state-icon">📭</span><div class="empty-state-title">لا توجد طلبات بعد</div></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer">إجمالي الطلبات: <?= number_format($stats['orders']) ?> &nbsp;|&nbsp; المكتمل: <?= number_format($stats['completed']) ?></div>
</div>

<?php include 'footer.php'; ?>
