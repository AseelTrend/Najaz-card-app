<?php
require_once '../includes/config.php';
require_once dirname(__DIR__) . '/includes/p2p_accounting.php';
requireAdmin($pdo);
$pageTitle = 'التقارير والإحصاءات — ' . SITE_NAME;

// ── الفترة الزمنية ──────────────────────────────────────────
$period   = $_GET['period'] ?? '30';
$tab      = $_GET['tab']    ?? 'overview';
$search   = trim($_GET['q'] ?? '');
$userId   = (int)($_GET['user_id'] ?? 0);
$fromParam = trim((string)($_GET['from'] ?? ''));
$toParam   = trim((string)($_GET['to'] ?? ''));

// كشف الحساب يبدأ تلقائياً من تاريخ إنشاء العميل، ما لم يحدد المشرف تاريخ البداية يدوياً.
$accountCreatedDate = null;
$hasExplicitPeriod = array_key_exists('period', $_GET) && trim((string)$_GET['period']) !== '';
if ($tab === 'statement' && $userId && $fromParam === '' && !$hasExplicitPeriod) {
    try {
        $accountStmt = $pdo->prepare("SELECT created_at FROM users WHERE id=? LIMIT 1");
        $accountStmt->execute([$userId]);
        $accountCreatedAt = $accountStmt->fetchColumn();
        if ($accountCreatedAt) {
            $accountCreatedDate = date('Y-m-d', strtotime($accountCreatedAt));
        }
    } catch (Throwable $e) {
        // fallback إلى الفترة الافتراضية العامة إذا تعذر قراءة تاريخ إنشاء الحساب.
    }
}

$dateFrom = $fromParam !== '' ? $fromParam : ($accountCreatedDate ?: date('Y-m-d', strtotime("-{$period} days")));
$dateTo   = $toParam !== '' ? $toParam : date('Y-m-d');

$df = $dateFrom . ' 00:00:00';
$dt = $dateTo   . ' 23:59:59';

// توافق مع قواعد البيانات التي أُنشئت قبل إضافة خصم الكوبون.
$couponDiscountExpr = '0';
try {
    if ($pdo->query("SHOW COLUMNS FROM orders LIKE 'coupon_discount'")->fetch()) {
        $couponDiscountExpr = 'o.coupon_discount';
    }
} catch (Throwable $e) {
    // يبقى الخصم صفراً إذا تعذر فحص بنية الجدول.
}

// ══════════════════════════════════════════════════════════════
// 1. الإحصاءات العامة
// ══════════════════════════════════════════════════════════════
$overview = $pdo->query("
    SELECT
        COUNT(CASE WHEN status='completed' THEN 1 END)  as completed,
        COUNT(CASE WHEN status='pending' OR status='processing' THEN 1 END) as pending,
        COUNT(CASE WHEN status='cancelled' OR status='failed' THEN 1 END) as cancelled,
        COUNT(*) as total,
        COALESCE(SUM(CASE WHEN status='completed' THEN total_price END),0) as revenue,
        COALESCE(SUM(CASE WHEN status='completed' THEN {$couponDiscountExpr} END),0) as discounts,
        COUNT(DISTINCT user_id) as unique_customers
    FROM orders o
    WHERE created_at BETWEEN '$df' AND '$dt'
")->fetch();

// مقارنة بالفترة السابقة
$prevFrom = date('Y-m-d', strtotime($dateFrom . " -" . (strtotime($dateTo)-strtotime($dateFrom)+86400) . " seconds")) . ' 00:00:00';
$prevTo   = date('Y-m-d H:i:s', strtotime($dateFrom . " -1 day") + 86399);
$prevRev  = (float)$pdo->query("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE status='completed' AND created_at BETWEEN '$prevFrom' AND '$prevTo'")->fetchColumn();
$revChange = $prevRev > 0 ? round((($overview['revenue'] - $prevRev) / $prevRev) * 100, 1) : 0;

// ══════════════════════════════════════════════════════════════
// 2. مبيعات يومية (للرسم البياني)
// ══════════════════════════════════════════════════════════════
$dailySales = $pdo->query("
    SELECT DATE(created_at) as day,
           COUNT(CASE WHEN status='completed' THEN 1 END) as cnt,
           COALESCE(SUM(CASE WHEN status='completed' THEN total_price END),0) as rev
    FROM orders
    WHERE created_at BETWEEN '$df' AND '$dt'
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetchAll();

// ══════════════════════════════════════════════════════════════
// 3. أكثر الخدمات مبيعاً مع تكلفة المزود والأرباح
// ══════════════════════════════════════════════════════════════
$topServices = $pdo->query("
    SELECT s.id, s.name, s.price as current_price,
           cat.name as cat_name,
           COUNT(o.id) as orders_count,
           SUM(o.quantity) as total_qty,
           COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price END),0) as revenue,
           COALESCE(AVG(o.unit_price),0) as avg_sell_price,
           p.name as provider_name,
           p.provider_type
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories cat ON s.category_id = cat.id
    LEFT JOIN providers p ON o.used_provider_id = p.id
    WHERE o.created_at BETWEEN '$df' AND '$dt'
      AND o.status = 'completed'
    GROUP BY s.id, s.name, s.price, cat.name, p.name, p.provider_type
    ORDER BY revenue DESC
    LIMIT 20
")->fetchAll();

// ══════════════════════════════════════════════════════════════
// 4. أكثر العملاء إنفاقاً
// ══════════════════════════════════════════════════════════════
$topCustomers = $pdo->query("
    SELECT u.id, u.username, u.full_name, u.email, u.balance,
           COUNT(o.id) as orders_count,
           COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price END),0) as total_spent,
           COALESCE(SUM(CASE WHEN o.status='completed' THEN {$couponDiscountExpr} END),0) as saved,
           MAX(o.created_at) as last_order
    FROM users u
    JOIN orders o ON u.id = o.user_id
    WHERE o.created_at BETWEEN '$df' AND '$dt'
    GROUP BY u.id, u.username, u.full_name, u.email, u.balance
    ORDER BY total_spent DESC
    LIMIT 20
")->fetchAll();

// ══════════════════════════════════════════════════════════════
// 5. كشف حساب عميل محدد
// ══════════════════════════════════════════════════════════════
$customerStatement = [];
$customerInfo      = null;
if ($userId) {
    $customerStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $customerStmt->execute([$userId]);
    $customerInfo = $customerStmt->fetch();

    // بعض قواعد البيانات القديمة لا تحتوي على ref_id في orders.
    // نستخدم رقم الطلب كبديل آمن حتى لا تتوقف صفحة التقرير بالكامل.
    $orderRef = "CONCAT('ID', o.id)";
    try {
        $refColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'ref_id'")->fetch();
        if ($refColumn) {
            $orderRef = "COALESCE(NULLIF(o.ref_id, ''), CONCAT('ID', o.id))";
        }
    } catch (Throwable $e) {
        // يبقى البديل الرقمي في حال تعذر فحص البنية.
    }

        if ($customerInfo) {
        // تنفيذ الطلبات والمحفظة بشكل منفصل يعزل اختلافات الجداول ويمنع فشل UNION من إسقاط الصفحة.
        try {
            $ordersStmt = $pdo->prepare("
                SELECT 'order' AS entry_type,
                       {$orderRef} AS ref,
                       s.name AS description,
                       o.total_price AS debit,
                       0 AS credit,
                       o.status,
                       o.created_at,
                       o.quantity,
                       o.unit_price,
                       {$couponDiscountExpr} AS coupon_discount
                FROM orders o
                JOIN services s ON o.service_id = s.id
                WHERE o.user_id = ? AND o.created_at BETWEEN ? AND ?
            ");
            $ordersStmt->execute([$userId, $df, $dt]);
            $customerStatement = $ordersStmt->fetchAll();
        } catch (Throwable $e) {
            error_log('Reports orders statement failed for user '.$userId.': '.$e->getMessage());
        }

        try {
            $walletStmt = $pdo->prepare("
                SELECT 'wallet' AS entry_type,
                       CAST(wt.id AS CHAR) AS ref,
                       wt.description AS description,
                       CASE WHEN wt.type = 'debit' THEN wt.amount ELSE 0 END AS debit,
                       CASE WHEN wt.type <> 'debit' THEN wt.amount ELSE 0 END AS credit,
                       wt.type AS status,
                       wt.created_at,
                       NULL AS quantity,
                       NULL AS unit_price,
                       NULL AS coupon_discount
                FROM wallet_transactions wt
                WHERE wt.user_id = ? AND wt.created_at BETWEEN ? AND ?
            ");
            $walletStmt->execute([$userId, $df, $dt]);
            $customerStatement = array_merge($customerStatement, $walletStmt->fetchAll());
        } catch (Throwable $e) {
            // كشف الطلبات يبقى ظاهراً حتى لو كان جدول المحفظة مختلفاً أو غير متاح.
            error_log('Reports wallet statement failed for user '.$userId.': '.$e->getMessage());
        }

        usort($customerStatement, static function ($a, $b) {
            return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
        });
    } else {
        // لا نسمح بعرض قالب التقرير مع بيانات عميل غير موجود.
        $userId = 0;
    }
}

// ══════════════════════════════════════════════════════════════
// 6. بحث العملاء
// ══════════════════════════════════════════════════════════════
$searchResults = [];
if ($search) {
    $s = "%$search%";
    $sr = $pdo->prepare("
        SELECT u.id, u.username, u.full_name, u.email, u.phone, u.balance,
               COUNT(DISTINCT o.id) as orders_count,
               COALESCE(SUM(CASE WHEN o.status='completed' THEN o.total_price END),0) as total_spent
        FROM users u
        LEFT JOIN orders o ON u.id=o.user_id
        WHERE u.role='customer' AND (u.username LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.id=?)
        GROUP BY u.id, u.username, u.full_name, u.email, u.phone, u.balance
        ORDER BY total_spent DESC
        LIMIT 20
    ");
    $sr->execute([$s,$s,$s,$s,(int)$search]);
    $searchResults = $sr->fetchAll();
}

// ══════════════════════════════════════════════════════════════
// 7. التقرير المحاسبي الشامل
// يفصل الإيراد المحاسبي عن حركة المحافظ ولا يحوّل تكلفة المزود
// غير المسجلة إلى ربح تقديري.
// ══════════════════════════════════════════════════════════════
$accounting = [
    'orders' => ['total'=>0,'completed'=>0,'pending'=>0,'cancelled'=>0,'revenue'=>0.0,'discounts'=>0.0,'refunded'=>0.0],
    'wallet' => ['deposits'=>['count'=>0,'amount'=>0.0],'refunds'=>['count'=>0,'amount'=>0.0],'p2p_credits'=>['count'=>0,'amount'=>0.0],'referrals'=>['count'=>0,'amount'=>0.0],'referral_reversals'=>['count'=>0,'amount'=>0.0],'gifts'=>['count'=>0,'amount'=>0.0],'prizes'=>['count'=>0,'amount'=>0.0],'debits'=>['count'=>0,'amount'=>0.0],'unclassified'=>['count'=>0,'amount'=>0.0]],
    'period_net'=>0.0,
    'opening_balance'=>null,
    'closing_balance'=>null,
    'current_balance'=>0.0,
    'entries'=>[],
    'expenses' => [],
    'expenses_total'=>0.0,
    'revenues' => [],
    'p2p_commission_total' => 0.0,
];
if ($tab === 'accounting') {
    // سجل مصروفات الإدارة/التشغيل مستقل عن محافظ العملاء، حتى لا تختلط
    // المصروفات الخارجية مع الخصميات من أرصدة العملاء.
    try {
        p2pAccountingEnsureTable($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounting_expenses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            category VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(18,4) NOT NULL DEFAULT 0,
            reference VARCHAR(120) DEFAULT NULL,
            status ENUM('approved','void') NOT NULL DEFAULT 'approved',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_expense_date (expense_date),
            INDEX idx_expense_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('Reports expenses table failed: '.$e->getMessage());
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
        adminCsrfVerify();
        $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
        $expenseCategory = trim((string)($_POST['expense_category'] ?? 'تشغيل'));
        $expenseDescription = trim((string)($_POST['expense_description'] ?? ''));
        $expenseAmount = (float)($_POST['expense_amount'] ?? 0);
        $expenseReference = trim((string)($_POST['expense_reference'] ?? ''));
        $validDate = DateTime::createFromFormat('Y-m-d', $expenseDate);
        if (!$validDate || $validDate->format('Y-m-d') !== $expenseDate || $expenseCategory === '' || $expenseDescription === '' || $expenseAmount <= 0) {
            flashMessage('danger', 'أدخل تاريخاً صحيحاً وبيان المصروف ومبلغاً أكبر من صفر');
        } else {
            $pdo->prepare("INSERT INTO accounting_expenses (expense_date,category,description,amount,reference,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$expenseDate,$expenseCategory,$expenseDescription,$expenseAmount,$expenseReference ?: null,$_SESSION['user_id'] ?? null]);
            flashMessage('success', 'تم تسجيل المصروف في التقرير المحاسبي');
        }
        redirect(SITE_URL.'/admin/reports.php?tab=accounting&from='.urlencode($dateFrom).'&to='.urlencode($dateTo));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_expense'])) {
        adminCsrfVerify();
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        if ($expenseId > 0) {
            $pdo->prepare("UPDATE accounting_expenses SET status='void' WHERE id=?")->execute([$expenseId]);
            flashMessage('success', 'تم إلغاء قيد المصروف دون حذفه من سجل التدقيق');
        }
        redirect(SITE_URL.'/admin/reports.php?tab=accounting&from='.urlencode($dateFrom).'&to='.urlencode($dateTo));
    }
    try {
        $orderAccounting = $pdo->prepare("SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN status='completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status IN ('pending','processing') THEN 1 END) AS pending,
            COUNT(CASE WHEN status IN ('cancelled','failed') THEN 1 END) AS cancelled,
            COALESCE(SUM(CASE WHEN status='completed' THEN total_price ELSE 0 END),0) AS revenue,
            COALESCE(SUM(CASE WHEN status='completed' THEN {$couponDiscountExpr} ELSE 0 END),0) AS discounts,
            COALESCE(SUM(CASE WHEN status IN ('cancelled','failed') THEN total_price ELSE 0 END),0) AS refunded
            FROM orders o WHERE o.created_at BETWEEN ? AND ?");
        $orderAccounting->execute([$df,$dt]);
        $accounting['orders'] = array_merge($accounting['orders'], $orderAccounting->fetch() ?: []);
    } catch (Throwable $e) {
        error_log('Reports accounting orders failed: '.$e->getMessage());
    }

    $walletSubExpr = "''";
    try {
        if ($pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'sub_type'")->fetch()) {
            $walletSubExpr = 'COALESCE(wt.sub_type, \'\')';
        }
    } catch (Throwable $e) {}

    $walletDeltaExpr = "CASE WHEN wt.balance_before IS NOT NULL AND wt.balance_after IS NOT NULL
        THEN (wt.balance_after - wt.balance_before)
        WHEN wt.type IN ('debit','referral_revoke') THEN -wt.amount
        ELSE wt.amount END";
    try {
        $revenueStmt = $pdo->prepare("SELECT r.*, u.username AS source_username FROM accounting_revenues r LEFT JOIN users u ON u.id=r.user_id WHERE r.revenue_date BETWEEN ? AND ? ORDER BY r.revenue_date ASC, r.id ASC");
        $revenueStmt->execute([$df, $dt]);
        $accounting['revenues'] = $revenueStmt->fetchAll();
        foreach ($accounting['revenues'] as $revenue) {
            if (($revenue['category'] ?? '') === 'p2p_commission') {
                $accounting['p2p_commission_total'] += (float)$revenue['amount'];
            }
        }
    } catch (Throwable $e) {
        error_log('Reports accounting revenues failed: '.$e->getMessage());
    }

    try {
        $expenseStmt = $pdo->prepare("SELECT e.*, u.username AS created_by_username FROM accounting_expenses e LEFT JOIN users u ON u.id=e.created_by WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date ASC, e.id ASC");
        $expenseStmt->execute([$dateFrom, $dateTo]);
        $accounting['expenses'] = $expenseStmt->fetchAll();
        foreach ($accounting['expenses'] as $expense) {
            if (($expense['status'] ?? 'approved') === 'approved') $accounting['expenses_total'] += (float)$expense['amount'];
        }
    } catch (Throwable $e) {
        error_log('Reports accounting expenses failed: '.$e->getMessage());
    }

    try {
        $walletAccounting = $pdo->prepare("SELECT wt.id, wt.type, {$walletSubExpr} AS sub_type,
            wt.amount, wt.description, wt.reference_id, wt.created_at,
            {$walletDeltaExpr} AS delta, u.id AS user_id, u.username, u.full_name
            FROM wallet_transactions wt JOIN users u ON u.id=wt.user_id
            WHERE wt.created_at BETWEEN ? AND ? ORDER BY wt.created_at ASC, wt.id ASC");
        $walletAccounting->execute([$df,$dt]);
        foreach ($walletAccounting->fetchAll() as $tx) {
            $type = strtolower((string)($tx['type'] ?? ''));
            $sub  = strtolower((string)($tx['sub_type'] ?? ''));
            $desc = (string)($tx['description'] ?? '');
            $amount = abs((float)($tx['amount'] ?? 0));
            if ($type === 'debit') {
                $category = 'debits';
            } elseif ($type === 'referral_revoke') {
                $category = 'referral_reversals';
            } elseif (in_array($type, ['referral','referral_welcome'], true)) {
                $category = 'referrals';
            } elseif ($type === 'prize') {
                $category = 'prizes';
            } elseif ($type === 'credit' && ($sub === 'refund' || preg_match('/استرداد|إلغاء|الغاء|refund|رد/i', $desc))) {
                $category = 'refunds';
            } elseif ($type === 'credit' && ($sub === 'gift' || preg_match('/هدية|مكافأة|مكافاه|جائزة|مجاني|gift/i', $desc))) {
                $category = 'gifts';
            } elseif ($type === 'credit' && ($sub === 'p2p' || preg_match('/P2P/i', $desc)) && !preg_match('/استرداد|إلغاء|الغاء|refund|رد/i', $desc)) {
                $category = 'p2p_credits';
            } elseif ($type === 'topup' || $sub === 'topup' || $sub === 'sms' || ($type === 'credit' && $sub === '')) {
                $category = 'deposits';
            } else {
                $category = 'unclassified';
            }
            $accounting['wallet'][$category]['count']++;
            $accounting['wallet'][$category]['amount'] += $amount;
            $accounting['period_net'] += (float)($tx['delta'] ?? 0);
            $tx['category'] = $category;
            $accounting['entries'][] = $tx;
        }

        $currentBalance = $pdo->query("SELECT COALESCE(SUM(balance),0) FROM users WHERE role='customer'")->fetchColumn();
        $accounting['current_balance'] = (float)$currentBalance;
        $postStmt = $pdo->prepare("SELECT COALESCE(SUM({$walletDeltaExpr}),0) FROM wallet_transactions wt WHERE wt.created_at > ?");
        $postStmt->execute([$dt]);
        $postPeriodNet = (float)$postStmt->fetchColumn();
        $accounting['closing_balance'] = $accounting['current_balance'] - $postPeriodNet;
        $accounting['opening_balance'] = $accounting['closing_balance'] - $accounting['period_net'];
    } catch (Throwable $e) {
        error_log('Reports accounting wallet failed: '.$e->getMessage());
    }
}

// ══ تصدير CSV قبل أي إخراج HTML ══
if (isset($_GET['export']) && $_GET['export']==='csv') {
    $filename = "report_{$tab}_{$dateFrom}_{$dateTo}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $csv = static function ($row) use ($out): void { fputcsv($out, $row); };

    if ($tab === 'services') {
        $csv(['الخدمة','القسم','الطلبات','الكمية','سعر البيع','الإيراد','المزود']);
        foreach ($topServices as $s) $csv([$s['name'],$s['cat_name'],$s['orders_count'],$s['total_qty'],number_format($s['current_price'],6),number_format($s['revenue'],4),$s['provider_name']??'يدوي']);
    } elseif ($tab === 'customers') {
        $csv(['العميل','البريد','الطلبات','الإنفاق','الرصيد']);
        foreach ($topCustomers as $c) $csv([$c['full_name']?:$c['username'],$c['email']??'',$c['orders_count'],number_format($c['total_spent'],4),number_format($c['balance'],4)]);
    } elseif ($tab === 'statement' && $userId && !empty($customerStatement)) {
        $csv(['التاريخ','البيان','النوع','مدين','دائن']);
        foreach ($customerStatement as $e) {
            $isD = ($e['entry_type']==='order'&&$e['status']==='completed')||($e['entry_type']==='wallet'&&$e['debit']>0);
            $csv([$e['created_at'],$e['description'],$e['entry_type'],$isD?number_format($e['debit'],4):'',!$isD&&$e['credit']>0?number_format($e['credit'],4):'']);
        }
    } elseif ($tab === 'accounting') {
        $csv(['التقرير المحاسبي','من',$dateFrom,'إلى',$dateTo]);
        $csv(['قيمة الطلبات المكتملة',number_format((float)$accounting['orders']['revenue'],4),'خصومات الكوبونات',number_format((float)$accounting['orders']['discounts'],4)]);
        $csv(['الإيداعات',number_format((float)$accounting['wallet']['deposits']['amount'],4),'الاستردادات',number_format((float)$accounting['wallet']['refunds']['amount'],4)]);
        $csv(['الخصميات',number_format((float)$accounting['wallet']['debits']['amount'],4),'صافي حركة المحافظ',number_format((float)$accounting['period_net'],4)]);
        $csv(['إيراد عمولات منصة P2P',number_format((float)$accounting['p2p_commission_total'],4),'المصروفات المعتمدة',number_format((float)$accounting['expenses_total'],4)]);
        $csv([]);
        $csv(['التاريخ','العميل','رقم العميل','التصنيف','المبلغ','التغير','النوع','المرجع','البيان']);
        $acctLabels=['deposits'=>'إيداع/شحن','refunds'=>'استرداد','p2p_credits'=>'تحويل P2P للبائع','debits'=>'خصم','referrals'=>'إحالة/عمولة','referral_reversals'=>'عكس عمولة','gifts'=>'هدية','prizes'=>'جائزة','unclassified'=>'غير مصنف'];
        foreach ($accounting['entries'] as $entry) $csv([$entry['created_at'],$entry['full_name']?:$entry['username'],$entry['user_id'],$acctLabels[$entry['category']]??'غير مصنف',number_format((float)$entry['amount'],4),number_format((float)$entry['delta'],4),$entry['type'],$entry['reference_id']??'', $entry['description']??'']);
        $csv([]);
        $csv(['التاريخ','نوع الإيراد','المبلغ','المرجع','البيان']);
        foreach ($accounting['revenues'] as $revenue) $csv([$revenue['revenue_date'],$revenue['category'],number_format((float)$revenue['amount'],4),$revenue['reference']??'', $revenue['description']??'']);
    } else {
        $csv(['الإيراد','الطلبات المكتملة','العملاء النشطون','الخصومات']);
        $csv([number_format($overview['revenue'],2),$overview['completed'],$overview['unique_customers'],number_format($overview['discounts'],2)]);
    }
    fclose($out);
    exit;
}

include 'header.php';
?>
<style>
.rpt-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.rpt-tab{padding:8px 16px;border-radius:10px;border:1.5px solid var(--border);background:var(--bg2);color:var(--text2);cursor:pointer;font-family:var(--font);font-size:.83rem;font-weight:700;text-decoration:none;transition:.15s}
.rpt-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;text-align:center}
.stat-num{font-size:1.8rem;font-weight:900;line-height:1.1}
.stat-lbl{font-size:.75rem;color:var(--text3);margin-top:4px}
.stat-change{font-size:.72rem;margin-top:4px;font-weight:700}
.chart-wrap{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px}
.profit-pos{color:#00e676}.profit-neg{color:#ff4455}.profit-neu{color:var(--text3)}
</style>

<!-- هيدر الصفحة -->
<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(30,111,255,.15);color:var(--primary)"><i class="fas fa-chart-line"></i></div>
      التقارير والإحصاءات
    </div>
  </div>
  <!-- تصدير -->
  <a href="?tab=<?=$tab?>&from=<?=$dateFrom?>&to=<?=$dateTo?>&export=csv<?=$userId?'&user_id='.$userId:''?>" class="btn btn-success btn-sm"><i class="fas fa-download"></i> تصدير CSV</a>
</div>

<!-- فلتر التاريخ -->
<div class="card mb-2">
  <div class="card-body" style="padding:12px">
    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>">
      <?php if($userId): ?><input type="hidden" name="user_id" value="<?=$userId?>"><?php endif; ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach(['7'=>'7 أيام','30'=>'30 يوم','90'=>'3 أشهر','365'=>'سنة'] as $d=>$lbl): ?>
        <a href="?tab=<?=$tab?>&period=<?=$d?><?=$userId?'&user_id='.$userId:''?>"
           class="btn btn-sm <?=$period==$d&&!isset($_GET['from'])?'btn-primary':'btn-secondary'?>"><?=$lbl?></a>
        <?php endforeach; ?>
      </div>
      <span style="color:var(--text3);font-size:.8rem">أو:</span>
      <input type="date" name="from" class="form-control" style="width:auto" value="<?=htmlspecialchars($dateFrom)?>">
      <span style="color:var(--text3)">—</span>
      <input type="date" name="to" class="form-control" style="width:auto" value="<?=htmlspecialchars($dateTo)?>">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
    </form>
  </div>
</div>

<!-- تبويبات -->
<div class="rpt-tabs">
  <?php foreach([
    'overview'  =>'📊 نظرة عامة',
    'services'  =>'📦 الخدمات والأرباح',
    'accounting'=>'🧾 المحاسبة',
    'customers' =>'👥 العملاء',
    'statement' =>'📋 كشف حساب',
    'search'    =>'🔍 بحث العملاء',
  ] as $t=>$lbl): ?>
  <a href="?tab=<?=$t?>&from=<?=$dateFrom?>&to=<?=$dateTo?><?=$userId?'&user_id='.$userId:''?>" class="rpt-tab <?=$tab===$t?'active':''?>"><?=$lbl?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>
<!-- ══════════════ نظرة عامة ══════════════ -->

<!-- إحصاءات رئيسية -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">
  <div class="stat-card">
    <div class="stat-num" style="color:#00e676"><?=number_format($overview['revenue'],2)?> $</div>
    <div class="stat-lbl">إجمالي الإيرادات</div>
    <div class="stat-change <?=$revChange>=0?'profit-pos':'profit-neg'?>">
      <?=$revChange>=0?'↑':'↓'?> <?=abs($revChange)?>% مقارنة بالفترة السابقة
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-num" style="color:var(--primary)"><?=number_format($overview['completed'])?></div>
    <div class="stat-lbl">طلبات مكتملة</div>
    <div class="stat-change profit-neu">من <?=number_format($overview['total'])?> إجمالي</div>
  </div>
  <div class="stat-card">
    <div class="stat-num" style="color:var(--cyan)"><?=number_format($overview['unique_customers'])?></div>
    <div class="stat-lbl">عملاء نشطون</div>
  </div>
  <div class="stat-card">
    <div class="stat-num" style="color:#f5a623"><?=number_format($overview['discounts'],2)?> $</div>
    <div class="stat-lbl">خصومات كوبونات</div>
  </div>
</div>

<!-- رسم بياني للمبيعات اليومية -->
<div class="chart-wrap">
  <div style="font-weight:800;font-size:.92rem;margin-bottom:14px"><i class="fas fa-chart-bar" style="color:var(--primary)"></i> المبيعات اليومية</div>
  <canvas id="salesChart" style="max-height:280px"></canvas>
</div>

<!-- مقارنة الحالات -->
<div class="chart-wrap">
  <div style="font-weight:800;font-size:.92rem;margin-bottom:14px"><i class="fas fa-chart-pie" style="color:#f5a623"></i> توزيع الطلبات</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:center">
    <canvas id="statusChart" style="max-height:200px"></canvas>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach([
        ['مكتملة',$overview['completed'],'#00e676'],
        ['جارية',$overview['pending'],'#00d4ff'],
        ['ملغية',$overview['cancelled'],'#ff4455'],
      ] as [$lbl,$cnt,$clr]): ?>
      <div style="display:flex;align-items:center;gap:8px">
        <div style="width:12px;height:12px;border-radius:50%;background:<?=$clr?>;flex-shrink:0"></div>
        <span style="font-size:.85rem;flex:1"><?=$lbl?></span>
        <strong style="color:<?=$clr?>"><?=number_format($cnt)?></strong>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// رسم مبيعات يومية
const days  = <?=json_encode(array_column($dailySales,'day'),JSON_UNESCAPED_UNICODE)?>;
const revs  = <?=json_encode(array_map(function($r){ return round($r['rev'],2); },$dailySales))?>;
const cnts  = <?=json_encode(array_column($dailySales,'cnt'))?>;
const ctx1  = document.getElementById('salesChart').getContext('2d');
new Chart(ctx1, {
  type:'bar',
  data:{
    labels:days,
    datasets:[
      {label:'الإيراد ($)',data:revs,backgroundColor:'rgba(30,111,255,.7)',yAxisID:'y'},
      {label:'عدد الطلبات',data:cnts,backgroundColor:'rgba(0,212,170,.5)',type:'line',yAxisID:'y1',tension:.4,pointRadius:3}
    ]
  },
  options:{responsive:true,plugins:{legend:{labels:{color:'#8fa3bf',font:{family:'Cairo'}}}},
    scales:{
      x:{ticks:{color:'#8fa3bf',maxTicksLimit:10},grid:{color:'rgba(255,255,255,.05)'}},
      y:{ticks:{color:'#00e676'},grid:{color:'rgba(255,255,255,.05)'},position:'right'},
      y1:{ticks:{color:'#00d4aa'},position:'left',grid:{display:false}}
    }
  }
});
// رسم حالات
const ctx2 = document.getElementById('statusChart').getContext('2d');
new Chart(ctx2,{
  type:'doughnut',
  data:{
    labels:['مكتملة','جارية','ملغية'],
    datasets:[{data:[<?=$overview['completed']?>,<?=$overview['pending']?>,<?=$overview['cancelled']?>],
      backgroundColor:['#00e676','#00d4ff','#ff4455'],borderWidth:0}]
  },
  options:{responsive:true,plugins:{legend:{display:false}},cutout:'70%'}
});
</script>

<?php elseif ($tab === 'accounting'): ?>
<!-- ══════════════ التقرير المحاسبي الشامل ══════════════ -->
<div class="stat-card" style="text-align:right;margin-bottom:16px;border-color:rgba(0,212,170,.25)">
  <strong><i class="fas fa-balance-scale" style="color:#00d4aa"></i> تقرير محاسبي للفترة المحددة</strong>
  <div style="font-size:.78rem;color:var(--text3);margin-top:5px">الإيراد هنا هو قيمة الطلبات المكتملة. الإيداعات وحركات المحافظ معروضة منفصلة، ولا يتم احتساب ربح المزود لعدم وجود تكلفة تنفيذ فعلية محفوظة لكل طلب.</div>
</div>
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px">
  <?php foreach([
    ['قيمة الطلبات المكتملة',$accounting['orders']['revenue'],'#00e676','fa-file-invoice-dollar'],
    ['الإيداعات الفعلية',$accounting['wallet']['deposits']['amount'],'#00d4aa','fa-arrow-down'],
    ['الاستردادات',$accounting['wallet']['refunds']['amount'],'#3dd6f5','fa-undo-alt'],
    ['خصميات المحافظ',$accounting['wallet']['debits']['amount'],'#ff4455','fa-arrow-up'],
    ['مصروفات الإدارة',$accounting['expenses_total'],'#ff8a65','fa-receipt'],
    ['عمولة منصة P2P',$accounting['p2p_commission_total'],'#b197fc','fa-handshake'],
  ] as [$label,$value,$color,$icon]): ?>
  <div class="stat-card"><div style="font-size:1.35rem;font-weight:900;color:<?=$color?>"><i class="fas <?=$icon?>"></i> <?=number_format((float)$value,4)?> $</div><div class="stat-lbl"><?=$label?></div></div>
  <?php endforeach; ?>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-chart-pie"></i> ملخص الحركات المالية</div></div>
    <div class="table-wrap"><table><thead><tr><th>التصنيف</th><th>عدد القيود</th><th>الإجمالي</th></tr></thead><tbody>
    <?php foreach([
      ['deposits','إيداعات وشحنات','#00d4aa'],['refunds','استردادات','#3dd6f5'],['p2p_credits','تحويلات P2P للبائع','#b197fc'],['debits','خصميات','#ff4455'],['referrals','إحالات وعمولات','#b197fc'],['referral_reversals','عكس عمولات إحالة','#ff8a65'],['gifts','هدايا ومكافآت','#f5a623'],['prizes','جوائز مسابقات','#b197fc'],['unclassified','غير مصنفة — تحتاج مراجعة','#ffcc00']
    ] as [$key,$label,$color]): $row=$accounting['wallet'][$key]; ?>
      <tr><td style="color:<?=$color?>;font-weight:700"><?=$label?></td><td><?=number_format($row['count'])?></td><td style="font-weight:800"><?=number_format($row['amount'],4)?> $</td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fas fa-check-double"></i> تسوية أرصدة العملاء</div></div>
    <div class="table-wrap"><table><tbody>
      <tr><td>الرصيد الافتتاحي المحسوب</td><td style="text-align:left;font-weight:800"><?=is_null($accounting['opening_balance'])?'—':number_format($accounting['opening_balance'],4).' $'?></td></tr>
      <tr><td>صافي حركة المحافظ في الفترة</td><td style="text-align:left;font-weight:800;color:<?=$accounting['period_net']>=0?'#00e676':'#ff4455'?>"><?=number_format($accounting['period_net'],4)?> $</td></tr>
      <tr><td>الرصيد الختامي للفترة</td><td style="text-align:left;font-weight:800"><?=is_null($accounting['closing_balance'])?'—':number_format($accounting['closing_balance'],4).' $'?></td></tr>
      <tr><td>الرصيد الحالي للعملاء</td><td style="text-align:left;font-weight:800;color:#00d4aa"><?=number_format($accounting['current_balance'],4)?> $</td></tr>
      <tr><td>الطلبات المكتملة / المعلقة / الملغاة</td><td style="text-align:left"><?=number_format((int)$accounting['orders']['completed'])?> / <?=number_format((int)$accounting['orders']['pending'])?> / <?=number_format((int)$accounting['orders']['cancelled'])?></td></tr>
      <tr><td>خصومات الكوبونات</td><td style="text-align:left;color:#f5a623;font-weight:800"><?=number_format((float)$accounting['orders']['discounts'],4)?> $</td></tr>
    </tbody></table></div>
  </div>
</div>
<div class="card" style="margin-top:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-receipt"></i> المصروفات الخارجية والتشغيلية</div><span style="font-size:.75rem;color:var(--text3)">لا تؤثر على أرصدة العملاء</span></div>
  <div class="card-body">
    <form method="POST" style="display:grid;grid-template-columns:150px 150px 1fr 150px 180px auto;gap:8px;align-items:end;margin-bottom:14px">
      <?=adminCsrfField()?>
      <input type="hidden" name="add_expense" value="1">
      <label style="font-size:.75rem;color:var(--text3)">التاريخ<input type="date" name="expense_date" class="form-control" value="<?=htmlspecialchars(date('Y-m-d'))?>" required></label>
      <label style="font-size:.75rem;color:var(--text3)">التصنيف<input type="text" name="expense_category" class="form-control" value="تشغيل" maxlength="80" required></label>
      <label style="font-size:.75rem;color:var(--text3)">البيان<input type="text" name="expense_description" class="form-control" placeholder="مثال: استضافة الموقع" maxlength="255" required></label>
      <label style="font-size:.75rem;color:var(--text3)">المبلغ بالدولار<input type="number" name="expense_amount" class="form-control" min="0.0001" step="0.0001" required></label>
      <label style="font-size:.75rem;color:var(--text3)">المرجع<input type="text" name="expense_reference" class="form-control" maxlength="120"></label>
      <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> تسجيل</button>
    </form>
    <?php if ($accounting['expenses']): ?>
    <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>التصنيف</th><th>البيان</th><th>المرجع</th><th>المبلغ</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>
    <?php foreach ($accounting['expenses'] as $expense): $isExpenseApproved=($expense['status']??'approved')==='approved'; ?>
      <tr style="opacity:<?=$isExpenseApproved?'1':'.55'?>"><td><?=htmlspecialchars($expense['expense_date'])?></td><td><?=htmlspecialchars($expense['category'])?></td><td><?=htmlspecialchars($expense['description'])?></td><td><?=htmlspecialchars($expense['reference']??'—')?></td><td style="color:#ff8a65;font-weight:800"><?=number_format((float)$expense['amount'],4)?> $</td><td><?=$isExpenseApproved?'معتمد':'ملغى'?></td><td><?php if($isExpenseApproved): ?><form method="POST" onsubmit="return confirm('إلغاء قيد المصروف؟')" style="display:inline"><?=adminCsrfField()?><input type="hidden" name="void_expense" value="1"><input type="hidden" name="expense_id" value="<?=$expense['id']?>"><button class="btn btn-sm btn-secondary" type="submit">إلغاء</button></form><?php else: ?>—<?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><div style="text-align:center;color:var(--text3);padding:1rem">لا توجد مصروفات مسجلة في الفترة المحددة.</div><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-handshake"></i> إيرادات منصة P2P المثبتة</div><span style="font-size:.75rem;color:var(--text3)">لا تُضاف إلى أرصدة العملاء</span></div>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>التصنيف</th><th>المبلغ</th><th>المرجع</th><th>البيان</th></tr></thead><tbody>
  <?php foreach ($accounting['revenues'] as $revenue): ?>
    <tr><td style="font-size:11px;white-space:nowrap"><?=date('d/m/Y H:i',strtotime($revenue['revenue_date']))?></td><td style="color:#b197fc;font-weight:700">عمولة منصة P2P</td><td style="font-weight:800;color:#b197fc"><?=number_format((float)$revenue['amount'],4)?> $</td><td><?=htmlspecialchars($revenue['reference']??'—')?></td><td><?=htmlspecialchars($revenue['description']??'')?></td></tr>
  <?php endforeach; ?>
  <?php if (!$accounting['revenues']): ?><tr><td colspan="5" style="text-align:center;color:var(--text3);padding:1rem">لا توجد إيرادات P2P مثبتة في الفترة المحددة.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-list"></i> تفاصيل قيود الفترة</div><span style="font-size:.75rem;color:var(--text3)"><?=number_format(count($accounting['entries']))?> قيد</span></div>
  <div class="table-wrap"><table><thead><tr><th>التاريخ</th><th>العميل</th><th>التصنيف</th><th>المبلغ</th><th>التغير</th><th>البيان/المرجع</th></tr></thead><tbody>
  <?php $acctLabels=['deposits'=>'إيداع/شحن','refunds'=>'استرداد','p2p_credits'=>'تحويل P2P للبائع','debits'=>'خصم','referrals'=>'إحالة/عمولة','referral_reversals'=>'عكس عمولة','gifts'=>'هدية','prizes'=>'جائزة','unclassified'=>'غير مصنف']; foreach(array_slice($accounting['entries'],0,200) as $entry): ?>
    <tr><td style="font-size:11px;white-space:nowrap"><?=date('d/m/Y H:i',strtotime($entry['created_at']))?></td><td><?=htmlspecialchars($entry['full_name']?:$entry['username'])?><div style="font-size:10px;color:var(--text3)">#<?=$entry['user_id']?></div></td><td><?=$acctLabels[$entry['category']]??'غير مصنف'?></td><td><?=number_format((float)$entry['amount'],4)?> $</td><td style="color:<?=$entry['delta']>=0?'#00e676':'#ff4455'?>;font-weight:800"><?=($entry['delta']>=0?'+':'').number_format((float)$entry['delta'],4)?> $</td><td><?=htmlspecialchars($entry['description']??'')?><?php if(!empty($entry['reference_id'])): ?><div style="font-size:10px;color:var(--text3)">مرجع: <?=htmlspecialchars((string)$entry['reference_id'])?></div><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$accounting['entries']): ?><tr><td colspan="6" style="text-align:center;color:var(--text3);padding:2rem">لا توجد قيود مالية في الفترة المحددة</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if(count($accounting['entries'])>200): ?><div style="padding:10px;color:var(--text3);font-size:.75rem">يُعرض أول 200 قيد. استخدم تصدير CSV للحصول على النطاق الكامل.</div><?php endif; ?>
</div>

<?php elseif ($tab === 'services'): ?>
<!-- ══════════════ تقرير الخدمات والأرباح ══════════════ -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-boxes"></i> تقرير الخدمات — الأرباح والخسائر</div>
    <span style="font-size:.75rem;color:var(--text3)">سعر البيع vs تكلفة المزود (إذا متوفرة)</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>الخدمة</th>
          <th>القسم</th>
          <th>الطلبات</th>
          <th>الكمية</th>
          <th>سعر البيع الحالي</th>
          <th>متوسط البيع الفعلي</th>
          <th>الإيراد</th>
          <th>المزود</th>
          <th>هامش الربح</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $totalRevenue = 0;
      foreach($topServices as $svc):
        $totalRevenue += $svc['revenue'];
        $margin = $svc['current_price'] > 0 ? round((($svc['avg_sell_price'] / $svc['current_price']) - 1) * 100, 1) : 0;
        $revenueShare = $overview['revenue'] > 0 ? round($svc['revenue'] / $overview['revenue'] * 100, 1) : 0;
      ?>
      <tr>
        <td>
          <strong><?=htmlspecialchars($svc['name'])?></strong>
          <div style="font-size:10px;color:var(--text3)">ID: <?=$svc['id']?></div>
        </td>
        <td style="font-size:12px;color:var(--text3)"><?=htmlspecialchars($svc['cat_name'])?></td>
        <td><span class="badge badge-primary"><?=number_format($svc['orders_count'])?></span></td>
        <td style="font-size:.82rem"><?=number_format($svc['total_qty'])?></td>
        <td style="font-weight:700;color:var(--cyan)"><?=number_format($svc['current_price'],6)?> $</td>
        <td style="font-weight:700"><?=number_format($svc['avg_sell_price'],6)?> $</td>
        <td>
          <strong style="color:#00e676"><?=number_format($svc['revenue'],4)?> $</strong>
          <div style="font-size:10px;color:var(--text3)"><?=$revenueShare?>% من الإجمالي</div>
        </td>
        <td style="font-size:.78rem;color:var(--text3)"><?=htmlspecialchars($svc['provider_name']??'يدوي')?></td>
        <td>
          <?php
          // هامش الربح بناءً على سعر البيع الحالي
          // (لا يوجد cost_price في orders — نعرض النسبة من الإيراد)
          $bar = min(100, $revenueShare * 2);
          ?>
          <div style="width:80px;background:var(--border);border-radius:4px;height:6px;overflow:hidden">
            <div style="width:<?=$bar?>%;background:#00e676;height:100%"></div>
          </div>
          <div style="font-size:10px;color:#00e676;margin-top:2px"><?=$revenueShare?>% من الإيراد</div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:rgba(0,0,0,.2);font-weight:900">
          <td colspan="6">الإجمالي</td>
          <td style="color:#00e676"><?=number_format($totalRevenue,4)?> $</td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php elseif ($tab === 'customers'): ?>
<!-- ══════════════ أكثر العملاء إنفاقاً ══════════════ -->
<div class="card">
  <div class="card-header"><div class="card-header-title"><i class="fas fa-crown" style="color:#f5a623"></i> أكثر العملاء إنفاقاً</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>العميل</th><th>الطلبات</th><th>الإنفاق</th><th>التوفير (كوبون)</th><th>الرصيد الحالي</th><th>آخر طلب</th><th></th></tr></thead>
      <tbody>
      <?php foreach($topCustomers as $i=>$c): ?>
      <tr>
        <td><?=$i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1))?></td>
        <td>
          <strong><?=htmlspecialchars($c['full_name']?:$c['username'])?></strong>
          <div style="font-size:11px;color:var(--text3)"><?=htmlspecialchars($c['email']??'')?></div>
        </td>
        <td><span class="badge badge-primary"><?=$c['orders_count']?></span></td>
        <td style="font-weight:900;color:#f5a623"><?=number_format($c['total_spent'],4)?> $</td>
        <td style="color:#00c853"><?=$c['saved']>0?'-'.number_format($c['saved'],2).'$':'—'?></td>
        <td style="color:var(--green)"><?=number_format($c['balance'],4)?> $</td>
        <td style="font-size:11px;color:var(--text3)"><?=date('d/m/Y',strtotime($c['last_order']))?></td>
        <td>
          <a href="?tab=statement&user_id=<?=$c['id']?>" class="btn btn-sm btn-primary" title="كشف حساب">
            <i class="fas fa-file-alt"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'statement'): ?>
<!-- ══════════════ كشف حساب عميل ══════════════ -->
<?php if (!$userId): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:2rem">
    <i class="fas fa-user-circle" style="font-size:3rem;opacity:.1;display:block;margin-bottom:12px"></i>
    <div style="font-weight:700;margin-bottom:10px">اختر عميلاً لعرض كشف حسابه</div>
    <form method="GET" style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
      <input type="hidden" name="tab" value="statement">
      <input type="hidden" name="from" value="<?=$dateFrom?>">
      <input type="hidden" name="to" value="<?=$dateTo?>">
      <input type="number" name="user_id" class="form-control" style="width:200px" placeholder="رقم العميل">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
    </form>
    <div style="margin-top:12px;font-size:.8rem;color:var(--text3)">أو انقر على أيقونة كشف الحساب من تبويب "العملاء"</div>
  </div>
</div>
<?php else: ?>

<?php
$totalDebit  = array_sum(array_column(array_filter($customerStatement, function($r){ return $r['entry_type']==='order'; }), 'debit'));
$totalCredit = array_sum(array_column(array_filter($customerStatement, function($r){ return $r['entry_type']==='wallet' && $r['credit']>0; }), 'credit'));
$balance_start = $totalCredit - $totalDebit;
?>

<!-- بيانات العميل -->
<div class="card mb-2">
  <div class="card-body">
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#7c3aed);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:900;flex-shrink:0">
        <?=mb_strtoupper(mb_substr($customerInfo['username']??'?',0,1))?>
      </div>
      <div style="flex:1">
        <div style="font-size:1.1rem;font-weight:900"><?=htmlspecialchars($customerInfo['full_name']?:$customerInfo['username'])?></div>
        <div style="font-size:.8rem;color:var(--text3)"><?=htmlspecialchars($customerInfo['email']??'')?> • <?=htmlspecialchars($customerInfo['phone']??'')?></div>
        <div style="font-size:.75rem;color:var(--text3)">عضو منذ <?=date('d/m/Y',strtotime($customerInfo['created_at']))?></div>
      </div>
      <div style="text-align:left">
        <div style="font-size:1.3rem;font-weight:900;color:#00e676"><?=number_format($customerInfo['balance'],4)?> $</div>
        <div style="font-size:.72rem;color:var(--text3)">الرصيد الحالي</div>
      </div>
      <div>
        <div style="font-size:1rem;font-weight:900;color:#f5a623"><?=number_format($totalDebit,4)?> $</div>
        <div style="font-size:.72rem;color:var(--text3)">مجموع الطلبات</div>
      </div>
      <div>
        <div style="font-size:1rem;font-weight:900;color:var(--cyan)"><?=number_format($totalCredit,4)?> $</div>
        <div style="font-size:.72rem;color:var(--text3)">مجموع الإيداعات</div>
      </div>
    </div>
  </div>
</div>

<!-- جدول الكشف -->
<div class="card">
  <div class="card-header">
    <div class="card-header-title"><i class="fas fa-file-alt"></i> كشف حساب تفصيلي — <?=date('d/m/Y',strtotime($dateFrom))?> إلى <?=date('d/m/Y',strtotime($dateTo))?></div>
    <a href="?tab=statement&from=<?=$dateFrom?>&to=<?=$dateTo?>&user_id=<?=$userId?>&export=csv" class="btn btn-success btn-sm"><i class="fas fa-download"></i> تصدير</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>التاريخ</th><th>البيان</th><th>النوع</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr>
      </thead>
      <tbody>
      <?php
      $runningBalance = 0;
      // ابدأ من أول رصيد مسجّل في الفترة
      foreach ($customerStatement as $entry):
        if ($entry['entry_type']==='wallet') {
            if ($entry['credit']>0) $runningBalance += $entry['credit'];
            else $runningBalance -= $entry['debit'];
        } else {
            if ($entry['status']==='completed') $runningBalance -= $entry['debit'];
        }
        $isDebit  = ($entry['entry_type']==='order' && $entry['status']==='completed') || ($entry['entry_type']==='wallet' && $entry['debit']>0);
        $amount   = $isDebit ? $entry['debit'] : $entry['credit'];
        $typeLabel= $entry['entry_type']==='order' ? '🛒 طلب' : '💰 محفظة';
      ?>
      <tr>
        <td style="font-size:11px;color:var(--text3);white-space:nowrap"><?=date('d/m/Y H:i',strtotime($entry['created_at']))?></td>
        <td>
          <?=htmlspecialchars($entry['description'])?>
          <?php if($entry['ref']): ?><div style="font-size:10px;color:var(--text3);font-family:monospace"><?=htmlspecialchars($entry['ref'])?></div><?php endif; ?>
        </td>
        <td style="font-size:.75rem"><?=$typeLabel?></td>
        <td style="color:#ff4455;font-weight:700"><?=$isDebit&&$amount>0?number_format($amount,4).' $':'—'?></td>
        <td style="color:#00e676;font-weight:700"><?=!$isDebit&&$amount>0?number_format($amount,4).' $':'—'?></td>
        <td style="font-weight:900;color:<?=$runningBalance>=0?'#00e676':'#ff4455'?>"><?=number_format($runningBalance,4)?> $</td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'search'): ?>
<!-- ══════════════ بحث العملاء ══════════════ -->
<div class="card mb-2">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:10px">
      <input type="hidden" name="tab" value="search">
      <input type="hidden" name="from" value="<?=$dateFrom?>">
      <input type="hidden" name="to" value="<?=$dateTo?>">
      <input type="text" name="q" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="ابحث بالاسم، البريد، الهاتف، رقم العميل..." style="flex:1">
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
    </form>
  </div>
</div>

<?php if ($search && empty($searchResults)): ?>
<div class="card"><div class="card-body" style="text-align:center;color:var(--text3);padding:2rem">لا توجد نتائج لـ "<?=htmlspecialchars($search)?>"</div></div>
<?php elseif (!empty($searchResults)): ?>
<div class="card">
  <div class="card-header"><div class="card-header-title">نتائج البحث (<?=count($searchResults)?>)</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>العميل</th><th>البريد</th><th>الهاتف</th><th>الرصيد</th><th>الطلبات</th><th>الإنفاق</th><th>إجراءات</th></tr></thead>
      <tbody>
      <?php foreach($searchResults as $c): ?>
      <tr>
        <td><?=str_pad($c['id'],6,'0',STR_PAD_LEFT)?></td>
        <td><strong><?=htmlspecialchars($c['full_name']?:$c['username'])?></strong></td>
        <td style="font-size:.8rem"><?=htmlspecialchars($c['email']??'')?></td>
        <td style="font-size:.8rem"><?=htmlspecialchars($c['phone']??'')?></td>
        <td style="color:#00e676;font-weight:700"><?=number_format($c['balance'],4)?> $</td>
        <td><?=$c['orders_count']?></td>
        <td style="color:#f5a623;font-weight:700"><?=number_format($c['total_spent'],4)?> $</td>
        <td>
          <div style="display:flex;gap:5px">
            <a href="customers.php?action=view&id=<?=$c['id']?>" class="btn btn-sm btn-primary"><i class="fas fa-eye"></i></a>
            <a href="?tab=statement&user_id=<?=$c['id']?>" class="btn btn-sm btn-secondary"><i class="fas fa-file-alt"></i> كشف</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
