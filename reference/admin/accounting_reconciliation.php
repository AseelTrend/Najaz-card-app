<?php
require_once '../includes/config.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
accountingEnsureSchema($pdo);
accountingEnsureProviderLinkSchema($pdo);
cashboxEnsureSchema($pdo);
cashboxEnsureProviderColumn($pdo);
requireStaffOrAdmin($pdo, 'perm_accounting_view');
$pageTitle = 'مطابقة العمليات — ' . SITE_NAME;
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$source = $_GET['source'] ?? 'all';
if (!in_array($source, ['all','orders','telecom','p2p'], true)) $source = 'all';
$cashboxFilter = (int)($_GET['cashbox_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$pp = 30;
$offset = ($page - 1) * $pp;

$parts = [];
if ($source === 'all' || $source === 'orders') {
    $parts[] = "SELECT CONVERT('orders' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        o.id AS operation_id, CONVERT(CONCAT('ID', o.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS operation_ref,
        o.created_at AS operation_date, CONVERT(o.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status, o.total_price AS amount,
        CONVERT('USD' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency_code,
        CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS customer_name,
        CONVERT(COALESCE(po.name, sp.provider_name, lp.name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS provider_name,
        COALESCE(o.used_provider_id, sp.provider_id, s.provider_id) AS provider_id,
        o.service_id, o.user_id,
        (SELECT CASE WHEN COUNT(*)=1 THEN MAX(l.cashbox_id) ELSE NULL END FROM accounting_provider_links l WHERE l.provider_id=COALESCE(o.used_provider_id, sp.provider_id, s.provider_id) AND l.is_active=1) AS cashbox_id,
        CONVERT('order' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_type_expected
      FROM orders o
      LEFT JOIN users u ON u.id=o.user_id
      LEFT JOIN services s ON s.id=o.service_id
      LEFT JOIN providers po ON po.id=o.used_provider_id
      LEFT JOIN (
        SELECT x.service_id, x.provider_id, p.name AS provider_name
        FROM service_providers x
        JOIN providers p ON p.id=x.provider_id
        WHERE x.is_active=1
          AND x.id=(SELECT y.id FROM service_providers y WHERE y.service_id=x.service_id AND y.is_active=1 ORDER BY y.priority ASC, y.id ASC LIMIT 1)
      ) sp ON sp.service_id=o.service_id
      LEFT JOIN providers lp ON lp.id=s.provider_id";
}
if ($source === 'all' || $source === 'telecom') {
    $parts[] = "SELECT CONVERT('telecom' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        t.id AS operation_id, CONVERT(CONCAT('ID', t.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS operation_ref,
        t.created_at AS operation_date, CONVERT(t.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status,
        COALESCE(t.sale_price,t.amount,t.cost_price) AS amount,
        CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS currency_code,
        CONVERT(CONCAT(COALESCE(n.name,''), CASE WHEN offer_row.name IS NOT NULL THEN CONCAT(' / ',offer_row.name) ELSE '' END) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        CONVERT(u.username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS customer_name,
        CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS provider_name, NULL AS provider_id,
        NULL AS service_id, t.user_id, NULL AS cashbox_id,
        CONVERT('telecom' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_type_expected
      FROM telecom_orders t
      LEFT JOIN users u ON u.id=t.user_id
      LEFT JOIN telecom_networks n ON n.id=t.network_id
      LEFT JOIN telecom_offers offer_row ON offer_row.id=t.offer_id";
}
if ($source === 'all' || $source === 'p2p') {
    $parts[] = "SELECT CONVERT('p2p' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source_type,
        p.id AS operation_id, CONVERT(CONCAT('ID', p.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS operation_ref,
        p.created_at AS operation_date, CONVERT(p.status USING utf8mb4) COLLATE utf8mb4_unicode_ci AS status, p.buyer_total AS amount,
        CONVERT('USD' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS currency_code,
        CONVERT(s.title USING utf8mb4) COLLATE utf8mb4_unicode_ci AS service_name,
        CONVERT(b.username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS customer_name,
        CONVERT(sl.username USING utf8mb4) COLLATE utf8mb4_unicode_ci AS provider_name, NULL AS provider_id,
        p.service_id, p.buyer_id AS user_id, NULL AS cashbox_id,
        CONVERT('p2p' USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference_type_expected
      FROM p2p_orders p
      LEFT JOIN p2p_services s ON s.id=p.service_id
      LEFT JOIN users b ON b.id=p.buyer_id
      LEFT JOIN users sl ON sl.id=p.seller_id";
}
$union = implode(' UNION ALL ', $parts);
$where = ['1=1'];
$params = [];
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'x.operation_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'x.operation_date <= ?'; $params[] = $to . ' 23:59:59'; }
if ($cashboxFilter > 0) { $where[] = 'x.cashbox_id = ?'; $params[] = $cashboxFilter; }
if ($q !== '') {
    $where[] = '(CONVERT(x.operation_ref USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        OR CONVERT(CAST(x.operation_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        OR CONVERT(x.service_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        OR CONVERT(x.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        OR CONVERT(x.provider_name USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci
        OR CONVERT(x.status USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$matchExpr = "EXISTS (SELECT 1 FROM accounting_journal aj
    WHERE CONVERT(aj.status USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
              CONVERT('posted' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
              CONVERT('reversed' USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )
      AND CONVERT(aj.reference_id USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
              CONVERT(x.operation_ref USING utf8mb4) COLLATE utf8mb4_unicode_ci,
              CONVERT(CAST(x.operation_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci
          )
      AND (aj.reference_type IS NULL
           OR CONVERT(aj.reference_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(x.reference_type_expected USING utf8mb4) COLLATE utf8mb4_unicode_ci
           OR (CONVERT(x.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT('orders' USING utf8mb4) COLLATE utf8mb4_unicode_ci AND CONVERT(aj.reference_type USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
                  CONVERT('order' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                  CONVERT('orders' USING utf8mb4) COLLATE utf8mb4_unicode_ci
              ))
           OR (CONVERT(x.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT('telecom' USING utf8mb4) COLLATE utf8mb4_unicode_ci AND CONVERT(aj.reference_type USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
                  CONVERT('telecom' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                  CONVERT('telecom_order' USING utf8mb4) COLLATE utf8mb4_unicode_ci
              ))
           OR (CONVERT(x.source_type USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT('p2p' USING utf8mb4) COLLATE utf8mb4_unicode_ci AND CONVERT(aj.reference_type USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
                  CONVERT('p2p' USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                  CONVERT('p2p_order' USING utf8mb4) COLLATE utf8mb4_unicode_ci
              ))))";
$base = ' FROM (' . $union . ') x LEFT JOIN accounting_cashboxes cb ON cb.id=x.cashbox_id WHERE ' . implode(' AND ', $where);
try {
    $count = $pdo->prepare('SELECT COUNT(*)' . $base);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $sql = 'SELECT x.*, cb.name AS cashbox_name, cb.code AS cashbox_code, ' . $matchExpr . ' AS matched' . $base . ' ORDER BY x.operation_date DESC, x.operation_id DESC LIMIT ' . $pp . ' OFFSET ' . $offset;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $queryError = null;
} catch (Throwable $e) {
    error_log('Accounting reconciliation query error: ' . $e->getMessage());
    $rows = [];
    $total = 0;
    $queryError = 'تعذر تحميل بيانات المطابقة. تحقق من تحديث جداول الحسابات ثم أعد المحاولة.';
}
$pages = max(1, (int)ceil($total / $pp));
$cashboxes = $pdo->query("SELECT id,code,name FROM accounting_cashboxes WHERE status<>'closed' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$flash = getFlash();
include 'header.php';
?>
<style>.r-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.r-toolbar .form-group{margin:0;min-width:150px;flex:1}.r-toolbar .wide{min-width:260px}.r-table{min-width:1120px}.r-table td,.r-table th{white-space:nowrap}.r-amount{font-family:monospace;color:#f5a623;font-weight:800}.r-ok{color:#00d4aa}.r-miss{color:#ffadad}.r-note{font-size:.76rem;color:var(--text3);line-height:1.8}</style>
<?php if ($flash): ?><div class="alert alert-<?=htmlspecialchars($flash['type'])?>"><?=htmlspecialchars($flash['message'])?></div><?php endif; ?>
<?php if ($queryError): ?><div class="alert alert-danger"><?=htmlspecialchars($queryError)?></div><?php endif; ?>
<div class="page-header"><div><h2><i class="fas fa-link" style="color:var(--cyan)"></i> مطابقة العمليات</h2><p>مقارنة مباشرة مع الجداول التشغيلية؛ لا تُنشئ قيوداً تلقائياً ولا تعتبر عدم المطابقة ربحاً أو خسارة</p></div><a class="btn btn-secondary" href="accounting_journal.php"><i class="fas fa-file-invoice-dollar"></i> القيود</a></div>
<div class="card"><div class="card-body"><form class="r-toolbar" method="get"><div class="form-group wide"><label>بحث</label><input name="q" value="<?=htmlspecialchars($q)?>" placeholder="ID123، رقم العملية، العميل، الخدمة أو الحالة"></div><div class="form-group"><label>المصدر</label><select name="source"><option value="all">كل المصادر</option><option value="orders" <?=$source==='orders'?'selected':''?>>طلبات الخدمات</option><option value="telecom" <?=$source==='telecom'?'selected':''?>>الاتصالات</option><option value="p2p" <?=$source==='p2p'?'selected':''?>>P2P</option></select></div><div class="form-group"><label>الصندوق</label><select name="cashbox_id"><option value="0">كل الصناديق</option><?php foreach($cashboxes as $cb): ?><option value="<?=$cb['id']?>" <?=$cashboxFilter===(int)$cb['id']?'selected':''?>><?=htmlspecialchars($cb['name'].' — '.$cb['code'])?></option><?php endforeach; ?></select></div><div class="form-group"><label>من</label><input type="date" name="from" value="<?=htmlspecialchars($from)?>"></div><div class="form-group"><label>إلى</label><input type="date" name="to" value="<?=htmlspecialchars($to)?>"></div><button class="btn btn-secondary"><i class="fas fa-search"></i> بحث</button><?php if ($q || $from || $to || $source !== 'all' || $cashboxFilter): ?><a class="btn btn-secondary" href="accounting_reconciliation.php">مسح</a><?php endif; ?></form><div class="r-note" style="margin-top:12px">تستخدم المطابقة رقم العملية القياسي من الشكل <b>ID123</b>، وتعرض المزود الفعلي للطلبات عند توفره وفق ترتيب نجاز الحالي. لا يُنسب الصندوق إلا عند وجود ربطية نشطة وحيدة غير ملتبسة؛ العمليات غير المرحّلة تظهر بلا قيد، دون إعادة احتساب أو تقدير.</div></div></div>
<div class="card"><div class="card-body"><div class="table-wrap"><table class="r-table"><thead><tr><th>المصدر</th><th>رقم العملية</th><th>التاريخ</th><th>الحالة</th><th>القيمة المسجلة</th><th>العملة</th><th>الخدمة</th><th>العميل</th><th>المزود/البائع</th><th>الصندوق</th><th>المطابقة</th><th>إجراء</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?=htmlspecialchars($r['source_type']==='orders'?'خدمة':($r['source_type']==='telecom'?'اتصالات':'P2P'))?></td><td style="color:var(--cyan);font-weight:800"><?=htmlspecialchars($r['operation_ref'])?></td><td><?=htmlspecialchars($r['operation_date'] ?? '')?></td><td><?=htmlspecialchars($r['status'] ?? '')?></td><td class="r-amount"><?=number_format((float)$r['amount'],8,'.',',')?></td><td><?=htmlspecialchars($r['currency_code'] ?: 'من سجل العملية')?></td><td><?=htmlspecialchars($r['service_name'] ?: '—')?></td><td><?=htmlspecialchars($r['customer_name'] ?: '—')?></td><td><?=htmlspecialchars($r['provider_name'] ?: '—')?></td><td><?=htmlspecialchars(($r['cashbox_name'] ?? '') ? (($r['cashbox_name'] ?? '').' — '.($r['cashbox_code'] ?? '')) : '—')?></td><td><?=$r['matched']?'<span class="r-ok"><i class="fas fa-check-circle"></i> مرتبطة</span>':'<span class="r-miss"><i class="fas fa-exclamation-circle"></i> بلا قيد</span>'?></td><td><a class="btn btn-sm btn-secondary" href="accounting_journal.php?q=<?=urlencode($r['operation_ref'])?>"><i class="fas fa-search"></i> عرض</a></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="12" style="text-align:center;color:var(--text3);padding:30px">لا توجد عمليات مطابقة للفلاتر</td></tr><?php endif; ?></tbody></table></div><?php if ($pages > 1): ?><div style="display:flex;justify-content:center;gap:6px;margin-top:14px;flex-wrap:wrap"><?php for ($i=1; $i <= $pages; $i++): ?><a class="btn btn-sm <?=$i===$page?'btn-primary':'btn-secondary'?>" href="?page=<?=$i?>&q=<?=urlencode($q)?>&source=<?=urlencode($source)?>&cashbox_id=<?=$cashboxFilter?>&from=<?=urlencode($from)?>&to=<?=urlencode($to)?>"><?=$i?></a><?php endfor; ?></div><?php endif; ?></div></div>
<?php include 'footer.php'; ?>
