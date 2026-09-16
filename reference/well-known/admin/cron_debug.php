<?php
/**
 * صفحة تشخيص الـ Cron — للمدير فقط
 * تعرض الطلبات التي يجب مزامنتها + تجري اختبار فوري
 */
require_once '../includes/config.php';
requireAdmin($pdo);

$pageTitle = 'تشخيص مزامنة الطلبات';

// ── اختبار فوري عند الطلب ──────────────────────────────
$testResult = null;
if (isset($_GET['run'])) {
    ob_start();
    $_SERVER['argv'] = []; // وهم CLI
    // تشغيل الكرون مباشرة
    define('CRON_MODE', true);
    $cronKey = getSetting('cron_key') ?: 'cron_secret_key_change_me';
    // تجاوز الحماية لأننا في admin
    ob_end_clean();
    $url = SITE_URL . '/cron_sync_orders.php?key=' . urlencode($cronKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $testResult = curl_exec($ch);
    curl_close($ch);
}

// ── جلب الطلبات المعلقة ─────────────────────────────────
$orders = $pdo->query("
    SELECT o.id, o.status, o.provider_order_id, o.used_provider_id,
           o.created_at, o.notes, o.status_message,
           s.name as service_name, s.provider_id as svc_provider_id,
           COALESCE(p_used.name, p_svc.name, sp_first.pname)         as prov_name,
           COALESCE(p_used.provider_type, p_svc.provider_type, sp_first.provider_type) as provider_type,
           COALESCE(p_used.api_url,  p_svc.api_url,  sp_first.api_url)  as api_url,
           COALESCE(p_used.status,   p_svc.status,   sp_first.pstatus)  as prov_status,
           u.username
    FROM orders o
    JOIN services s  ON o.service_id = s.id
    JOIN users    u  ON o.user_id    = u.id
    LEFT JOIN providers p_used ON p_used.id = o.used_provider_id
    LEFT JOIN providers p_svc  ON p_svc.id  = s.provider_id
    LEFT JOIN (
        SELECT sp.service_id, p.name as pname, p.api_url, p.provider_type, p.status as pstatus
        FROM service_providers sp
        JOIN providers p ON p.id = sp.provider_id
        WHERE sp.is_active = 1
        ORDER BY sp.priority ASC
    ) sp_first ON sp_first.service_id = o.service_id
    WHERE o.status IN ('pending','processing')
    ORDER BY o.created_at DESC
    LIMIT 50
")->fetchAll();

include 'header.php';
?>
<div class="page-header">
    <div class="page-header-left">
        <div class="page-header-title">🔍 تشخيص مزامنة الطلبات</div>
    </div>
    <a href="?run=1" class="btn btn-primary" onclick="return confirm('تشغيل المزامنة الآن؟')">
        <i class="fas fa-play"></i> تشغيل المزامنة الآن
    </a>
</div>

<?php if ($testResult !== null): ?>
<div class="card mb-2" style="border-color:rgba(0,212,170,.3)">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-terminal"></i> نتيجة التشغيل</div></div>
    <div class="card-body">
        <pre style="background:#0a0f1e;padding:14px;border-radius:8px;font-size:12px;color:#00d4aa;overflow-x:auto;white-space:pre-wrap"><?= htmlspecialchars($testResult ?: 'لا يوجد رد') ?></pre>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-header-title"><i class="fas fa-list"></i> الطلبات المعلقة (<?= count($orders) ?>)</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العميل</th>
                    <th>الخدمة</th>
                    <th>الحالة</th>
                    <th>provider_order_id</th>
                    <th>المزود</th>
                    <th>نوع المزود</th>
                    <th>حالة المزود</th>
                    <th>used_provider_id</th>
                    <th>الملاحظة</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= $o['id'] ?></td>
                <td><?= htmlspecialchars($o['username']) ?></td>
                <td><?= htmlspecialchars($o['service_name']) ?></td>
                <td><span class="badge" style="background:<?= $o['status']==='processing'?'rgba(0,212,255,.2)':'rgba(245,166,35,.2)' ?>"><?= $o['status'] ?></span></td>
                <td style="font-family:monospace;font-size:11px"><?= $o['provider_order_id'] ? htmlspecialchars($o['provider_order_id']) : '<span style="color:var(--red)">فارغ ❌</span>' ?></td>
                <td><?= $o['prov_name'] ? htmlspecialchars($o['prov_name']) : '<span style="color:var(--red)">لا يوجد ❌</span>' ?></td>
                <td><code><?= htmlspecialchars($o['provider_type'] ?? '—') ?></code></td>
                <td><?= $o['prov_status'] == 1 ? '<span style="color:var(--green)">✓ نشط</span>' : '<span style="color:var(--red)">✗ معطل</span>' ?></td>
                <td><?= $o['used_provider_id'] ?: '<span style="color:orange">NULL</span>' ?></td>
                <td style="max-width:150px;font-size:11px"><?= htmlspecialchars($o['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?>
            <tr><td colspan="10" style="text-align:center;color:var(--text3)">لا توجد طلبات معلقة</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'footer.php'; ?>
