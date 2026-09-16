<?php
/**
 * admin/usdt_deposits.php
 * ─────────────────────────────────────────────────────────────
 * لوحة إدارة إيداعات USDT — عرض الطلبات + السجلات + الإعدادات
 */
require_once '../includes/config.php';
require_once '../includes/usdt_deposit.php';
require_once '../includes/accounting_helper.php';
require_once '../includes/cashbox_helper.php';
requireStaffOrAdmin($pdo, 'settings');

// إنشاء جداول USDT ثم أعمدة تتبع الصندوق خارج أي transaction.
try {
    $usdtSchema = dirname(__DIR__) . '/usdt_deposit_schema.sql';
    if (is_file($usdtSchema)) $pdo->exec(file_get_contents($usdtSchema));
    cashboxEnsureSchema($pdo);
    accountingEnsureSchema($pdo);
    cashboxEnsureOperationalColumns($pdo);
} catch (Throwable $e) {
    error_log('USDT admin schema bootstrap failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }

$pageTitle = 'إدارة إيداعات USDT — ' . SITE_NAME;
$tab       = $_GET['tab'] ?? 'requests';
$msg       = '';
$msgType   = 'success';

// ══════════════════════════════════════════════════════════════
//  حفظ الإعدادات
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_usdt_settings'])) {
    $selectedCashboxId = (int)($_POST['usdt_cashbox_id'] ?? 0);
    $usdtImagePath = trim((string)(getSetting('usdt_image') ?: ''));
    $usdtIcon = strtolower(trim((string)($_POST['usdt_icon'] ?? 'coins')));
    $usdtIcon = preg_replace('/[^a-z0-9-]/', '', preg_replace('/^fa-/', '', $usdtIcon));
    if ($usdtIcon === '') $usdtIcon = 'coins';

    // صورة طريقة الدفع — اختيارية، وتُستخدم قبل الأيقونة الاحتياطية.
    if (!empty($_POST['delete_usdt_image']) && $_POST['delete_usdt_image'] === '1') {
        if ($usdtImagePath && is_file(dirname(__DIR__) . '/' . ltrim($usdtImagePath, '/'))) {
            @unlink(dirname(__DIR__) . '/' . ltrim($usdtImagePath, '/'));
        }
        $usdtImagePath = '';
    }
    if (!empty($_FILES['usdt_image']['tmp_name']) && (int)($_FILES['usdt_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['usdt_image']['name'], PATHINFO_EXTENSION));
        $allowedImageExt = ['jpg','jpeg','png','webp','svg','gif'];
        if (!in_array($ext, $allowedImageExt, true) || (int)$_FILES['usdt_image']['size'] > 2 * 1024 * 1024) {
            $msg = 'صورة USDT غير صالحة — الصيغ المسموحة JPG وPNG وWebP وSVG وGIF وبحجم أقصى 2MB';
            $msgType = 'danger';
        } else {
            $uploadDir = dirname(__DIR__) . '/uploads/payment_methods/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $fileName = 'usdt_logo_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['usdt_image']['tmp_name'], $uploadDir . $fileName)) {
                if ($usdtImagePath && is_file(dirname(__DIR__) . '/' . ltrim($usdtImagePath, '/'))) {
                    @unlink(dirname(__DIR__) . '/' . ltrim($usdtImagePath, '/'));
                }
                $usdtImagePath = 'uploads/payment_methods/' . $fileName;
            } else {
                $msg = 'تعذر حفظ صورة USDT المرفوعة';
                $msgType = 'danger';
            }
        }
    }

    $fields = [
        'usdt_enabled'           => isset($_POST['usdt_enabled']) ? '1' : '0',
        'usdt_cashbox_id'        => (string)$selectedCashboxId,
        'usdt_image'             => $usdtImagePath,
        'usdt_icon'              => $usdtIcon,
        'usdt_wallet_address'    => strtolower(trim($_POST['usdt_wallet_address']   ?? '')),
        'usdt_moralis_api_key'   => trim($_POST['usdt_moralis_api_key']  ?? ''),
        'usdt_moralis_stream_id' => trim($_POST['usdt_moralis_stream_id'] ?? ''),
        'usdt_webhook_secret'    => trim($_POST['usdt_webhook_secret']   ?? ''),
        'usdt_min_confirmations' => max(1, (int)($_POST['usdt_min_confirmations'] ?? 3)),
        'usdt_min_deposit'       => number_format(max(0.01, (float)($_POST['usdt_min_deposit'] ?? 1.00)), 2, '.', ''),
        'usdt_request_ttl'       => max(5, (int)($_POST['usdt_request_ttl'] ?? 30)),
    ];

    if (!empty($fields['usdt_wallet_address']) && !preg_match('/^0x[0-9a-f]{40}$/', $fields['usdt_wallet_address'])) {
        $msg = 'عنوان المحفظة غير صالح — يجب أن يبدأ بـ 0x ويكون 42 حرفاً';
        $msgType = 'danger';
    } elseif ($selectedCashboxId <= 0 && $fields['usdt_enabled'] === '1') {
        $msg = 'يجب اختيار صندوق USD نشط مرتبط بحساب محاسبي قبل تفعيل USDT';
        $msgType = 'danger';
    } elseif ($selectedCashboxId > 0) {
        $cashboxSettingCheck = $pdo->prepare("SELECT c.id FROM accounting_cashboxes c INNER JOIN accounting_accounts a ON a.id=c.account_id
            WHERE c.id=? AND c.status='active' AND c.currency_code='USD' AND a.status=1 AND a.currency_code='USD' LIMIT 1");
        $cashboxSettingCheck->execute([$selectedCashboxId]);
        if (!$cashboxSettingCheck->fetchColumn()) {
            $msg = 'الصندوق المحدد غير صالح: يجب أن يكون نشطاً، بعملة USD، ومرتبطاً بحساب محاسبي نشط';
            $msgType = 'danger';
        }
    }
    if ($msgType === 'success') {
        foreach ($fields as $k => $v) {
            $pdo->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$k, $v, $v]);
        }
        logStaffAction($pdo, 'usdt_settings_update', null, null, 'تحديث إعدادات USDT وربط الصندوق');
        $msg = 'تم حفظ إعدادات USDT وربطها بالصندوق بنجاح';
    }
}

// ── منتهية الصلاحية
usdt_expire_old_requests($pdo);

// ── إحصائيات سريعة
$stats = [];
try {
    $stats['total_completed']  = $pdo->query("SELECT COUNT(*) FROM usdt_deposit_requests WHERE status='completed'")->fetchColumn();
    $stats['total_amount']     = $pdo->query("SELECT COALESCE(SUM(credited_amount),0) FROM usdt_deposit_requests WHERE status='completed'")->fetchColumn();
    $stats['today_completed']  = $pdo->query("SELECT COUNT(*) FROM usdt_deposit_requests WHERE status='completed' AND DATE(completed_at)=CURDATE()")->fetchColumn();
    $stats['pending_count']    = $pdo->query("SELECT COUNT(*) FROM usdt_deposit_requests WHERE status='pending' AND expires_at>NOW()")->fetchColumn();
    $stats['suspicious_count'] = $pdo->query("SELECT COUNT(*) FROM usdt_suspicious_transactions WHERE DATE(created_at)=CURDATE()")->fetchColumn();
} catch (Throwable $e) {}

// ── بيانات التبويبات
$requests    = [];
$webhookLogs = [];
$suspicious  = [];
$settings    = [];
$usdtCashboxes = [];
try {
    $usdtCashboxes = $pdo->query("SELECT c.id,c.code,c.name,c.currency_code,c.staff_id,a.code AS account_code,a.name AS account_name
        FROM accounting_cashboxes c INNER JOIN accounting_accounts a ON a.id=c.account_id
        WHERE c.status='active' AND c.currency_code='USD' AND a.status=1 AND a.currency_code='USD'
        ORDER BY c.name,c.id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('USDT cashbox options failed: ' . $e->getMessage());
}

if ($tab === 'requests') {
    $requests = $pdo->query("
        SELECT r.*, c.code AS cashbox_code, c.name AS cashbox_name,
               j.id AS posted_journal_id, m.id AS posted_movement_id,
               u.username, u.full_name
        FROM usdt_deposit_requests r
        LEFT JOIN accounting_cashboxes c ON c.id = r.cashbox_id
        LEFT JOIN accounting_journal j ON j.id = r.cashbox_journal_id
        LEFT JOIN accounting_cashbox_movements m ON m.id = r.cashbox_movement_id
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.created_at DESC LIMIT 100
    ")->fetchAll();
}
if ($tab === 'logs') {
    $webhookLogs = $pdo->query("SELECT * FROM usdt_webhook_logs ORDER BY created_at DESC LIMIT 200")->fetchAll();
}
if ($tab === 'suspicious') {
    $suspicious = $pdo->query("SELECT * FROM usdt_suspicious_transactions ORDER BY created_at DESC LIMIT 200")->fetchAll();
}
if ($tab === 'settings') {
    $settingKeys = ['usdt_enabled','usdt_cashbox_id','usdt_image','usdt_icon','usdt_wallet_address','usdt_moralis_api_key','usdt_moralis_stream_id','usdt_webhook_secret','usdt_min_confirmations','usdt_min_deposit','usdt_request_ttl','usdt_contract_address'];
    foreach ($settingKeys as $k) {
        $settings[$k] = getSetting($k);
    }
}

require_once 'header.php';
?>

<style>
/* ═══════════════════════════════════════════
   USDT Dashboard — Custom Styles
   ═══════════════════════════════════════════ */

:root {
  --usdt-green:    #26a17b;
  --usdt-green-lt: #e8f8f3;
  --usdt-dark:     #0d1117;
  --card-radius:   14px;
  --shadow-sm:     0 2px 8px rgba(0,0,0,.07);
  --shadow-md:     0 4px 20px rgba(0,0,0,.10);
  --shadow-lg:     0 8px 32px rgba(0,0,0,.13);
}

/* ── رأس الصفحة ──────────────────────────── */
.usdt-page-header {
  background: linear-gradient(135deg, #0d1f1a 0%, #0f3326 60%, #1a5c42 100%);
  border-radius: var(--card-radius);
  padding: 28px 32px;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
  color: #fff;
}
.usdt-page-header::before {
  content: '';
  position: absolute;
  top: -60px; right: -60px;
  width: 220px; height: 220px;
  border-radius: 50%;
  background: rgba(38,161,123,.18);
  pointer-events: none;
}
.usdt-page-header::after {
  content: '';
  position: absolute;
  bottom: -40px; left: 40px;
  width: 140px; height: 140px;
  border-radius: 50%;
  background: rgba(38,161,123,.10);
  pointer-events: none;
}
.usdt-page-header .page-title {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -.3px;
  margin: 0;
}
.usdt-page-header .page-sub {
  opacity: .7;
  font-size: .875rem;
  margin-top: 4px;
}
.usdt-badge-live {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(38,161,123,.25);
  border: 1px solid rgba(38,161,123,.4);
  border-radius: 20px;
  padding: 3px 12px;
  font-size: .75rem;
  color: #6ee7c0;
}
.usdt-badge-live .dot {
  width: 7px; height: 7px;
  background: #26a17b;
  border-radius: 50%;
  animation: pulse-dot 1.6s infinite;
}
@keyframes pulse-dot {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .4; transform: scale(.7); }
}

/* ── بطاقات الإحصاء ──────────────────────── */
.stat-card {
  border-radius: var(--card-radius);
  border: none;
  padding: 22px 24px;
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  transition: transform .2s, box-shadow .2s;
}
.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-md);
}
.stat-card .stat-icon {
  width: 48px; height: 48px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem;
  margin-bottom: 14px;
}
.stat-card .stat-value {
  font-size: 1.65rem;
  font-weight: 700;
  line-height: 1;
  margin-bottom: 5px;
}
.stat-card .stat-label {
  font-size: .78rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .6px;
  opacity: .65;
}
.stat-card .stat-trend {
  position: absolute;
  bottom: 14px; left: 20px;
  font-size: .72rem;
  display: flex; align-items: center; gap: 4px;
}

/* ألوان البطاقات */
.stat-green  { background: linear-gradient(135deg, #e8f8f3, #d0f2e6); color: #0a4730; }
.stat-blue   { background: linear-gradient(135deg, #e8f0ff, #d4e3ff); color: #1a3a7a; }
.stat-amber  { background: linear-gradient(135deg, #fff8e8, #ffefc4); color: #6b4a00; }
.stat-red    { background: linear-gradient(135deg, #fdeef0, #fcd6db); color: #7a1a24; }

.stat-green .stat-icon  { background: rgba(38,161,123,.18); }
.stat-blue  .stat-icon  { background: rgba(59,130,246,.15); }
.stat-amber .stat-icon  { background: rgba(245,158,11,.15); }
.stat-red   .stat-icon  { background: rgba(239,68,68,.15);  }

/* ── التبويبات ───────────────────────────── */
.usdt-tabs {
  border-bottom: 2px solid #e9ecef;
  gap: 4px;
  flex-wrap: wrap;
}
.usdt-tabs .nav-link {
  border: none;
  border-radius: 8px 8px 0 0;
  padding: 10px 20px;
  font-weight: 500;
  font-size: .875rem;
  color: #6c757d;
  display: flex; align-items: center; gap: 7px;
  transition: all .18s;
  position: relative;
  background: transparent;
}
.usdt-tabs .nav-link:hover {
  color: var(--usdt-green);
  background: var(--usdt-green-lt);
}
.usdt-tabs .nav-link.active {
  color: var(--usdt-green);
  background: #fff;
  border-bottom: 2px solid var(--usdt-green);
  margin-bottom: -2px;
}
.usdt-tabs .tab-badge {
  background: #e9ecef;
  border-radius: 10px;
  padding: 1px 7px;
  font-size: .7rem;
  font-weight: 600;
}
.usdt-tabs .nav-link.active .tab-badge {
  background: var(--usdt-green-lt);
  color: var(--usdt-green);
}

/* ── بطاقة المحتوى ───────────────────────── */
.content-card {
  background: #fff;
  border-radius: var(--card-radius);
  box-shadow: var(--shadow-sm);
  border: 1px solid #f0f2f5;
  overflow: hidden;
}
.content-card .card-header-bar {
  padding: 16px 24px;
  border-bottom: 1px solid #f0f2f5;
  display: flex; align-items: center; justify-content: space-between;
  background: #fafbfc;
}
.content-card .card-header-bar .bar-title {
  font-weight: 600;
  font-size: .95rem;
  color: #212529;
  display: flex; align-items: center; gap: 8px;
}

/* ── جداول البيانات ──────────────────────── */
.data-table {
  width: 100%;
  margin: 0;
  font-size: .855rem;
}
.data-table thead th {
  background: #f8f9fa;
  border-bottom: 2px solid #e9ecef;
  color: #495057;
  font-weight: 600;
  font-size: .775rem;
  text-transform: uppercase;
  letter-spacing: .5px;
  padding: 12px 16px;
  white-space: nowrap;
}
.data-table tbody tr {
  border-bottom: 1px solid #f3f4f6;
  transition: background .12s;
}
.data-table tbody tr:hover {
  background: #fafbfc;
}
.data-table tbody td {
  padding: 12px 16px;
  vertical-align: middle;
  color: #374151;
}

/* ── الشارات ─────────────────────────────── */
.badge-status {
  display: inline-flex; align-items: center; gap: 5px;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: .75rem;
  font-weight: 600;
  letter-spacing: .2px;
}
.badge-completed { background: #e8f8f3; color: #0a6644; }
.badge-pending   { background: #fff8e8; color: #92600a; }
.badge-expired   { background: #f3f4f6; color: #6b7280; }
.badge-rejected  { background: #fdeef0; color: #9b1c2a; }
.badge-credited       { background: #e8f8f3; color: #0a6644; }
.badge-received       { background: #e8f0ff; color: #1a3a7a; }
.badge-no_transfers   { background: #f3f4f6; color: #6b7280; }
.badge-invalid_signature { background: #fdeef0; color: #9b1c2a; }

/* ── عنوان TX Hash ───────────────────────── */
.tx-hash-link {
  font-family: 'SFMono-Regular', Consolas, monospace;
  font-size: .775rem;
  color: var(--usdt-green);
  text-decoration: none;
  background: var(--usdt-green-lt);
  padding: 3px 8px;
  border-radius: 5px;
  transition: background .15s;
}
.tx-hash-link:hover {
  background: #c5efe0;
  color: #0a5c3a;
}
.addr-mono {
  font-family: 'SFMono-Regular', Consolas, monospace;
  font-size: .77rem;
  color: #6b7280;
}

/* ── تنبيه الرسائل ───────────────────────── */
.usdt-alert {
  border-radius: 10px;
  padding: 14px 18px;
  display: flex; align-items: flex-start; gap: 12px;
  font-size: .875rem;
  margin-bottom: 20px;
  border: 1px solid transparent;
}
.usdt-alert-success { background: #e8f8f3; border-color: #a8e6ce; color: #0a5c3a; }
.usdt-alert-danger  { background: #fdeef0; border-color: #f5c2c7; color: #7a1a24; }
.usdt-alert-warning { background: #fff8e6; border-color: #ffd68a; color: #7a4f00; }
.usdt-alert .alert-icon { font-size: 1.1rem; margin-top: 1px; }

/* ── نموذج الإعدادات ─────────────────────── */
.settings-section {
  border: 1px solid #e9ecef;
  border-radius: 10px;
  padding: 20px 22px;
  margin-bottom: 20px;
}
.settings-section-title {
  font-size: .8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .7px;
  color: #6c757d;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 7px;
}
.form-label-custom {
  font-size: .825rem;
  font-weight: 600;
  color: #374151;
  margin-bottom: 6px;
}
.form-control-custom {
  border-radius: 8px;
  border: 1.5px solid #dee2e6;
  padding: 9px 13px;
  font-size: .875rem;
  transition: border-color .18s, box-shadow .18s;
}
.form-control-custom:focus {
  border-color: var(--usdt-green);
  box-shadow: 0 0 0 3px rgba(38,161,123,.12);
  outline: none;
}
.form-control-custom.is-readonly {
  background: #f8f9fa;
  color: #6c757d;
}
.form-hint {
  font-size: .76rem;
  color: #9ca3af;
  margin-top: 5px;
}
.switch-card {
  background: #f8f9fa;
  border-radius: 10px;
  padding: 16px 20px;
  display: flex; align-items: center; justify-content: space-between;
  border: 1.5px solid #e9ecef;
}
.form-switch .form-check-input {
  width: 2.5em;
  height: 1.3em;
  cursor: pointer;
}
.form-switch .form-check-input:checked {
  background-color: var(--usdt-green);
  border-color: var(--usdt-green);
}

/* ── copy input group ────────────────────── */
.copy-group {
  display: flex; gap: 0;
}
.copy-group .form-control-custom {
  border-radius: 8px 0 0 8px;
  flex: 1;
}
.copy-btn {
  border-radius: 0 8px 8px 0;
  background: #f1f3f5;
  border: 1.5px solid #dee2e6;
  border-left: none;
  padding: 0 16px;
  font-size: .825rem;
  font-weight: 500;
  color: #495057;
  cursor: pointer;
  transition: background .15s;
  white-space: nowrap;
}
.copy-btn:hover { background: #e2e6ea; }

/* ── زر الحفظ ────────────────────────────── */
.btn-save-usdt {
  background: linear-gradient(135deg, var(--usdt-green), #1a8a62);
  color: #fff;
  border: none;
  border-radius: 9px;
  padding: 11px 32px;
  font-weight: 600;
  font-size: .9rem;
  transition: opacity .18s, transform .15s, box-shadow .18s;
  box-shadow: 0 4px 12px rgba(38,161,123,.35);
}
.btn-save-usdt:hover {
  opacity: .92;
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(38,161,123,.4);
  color: #fff;
}
.btn-save-usdt:active { transform: translateY(0); }

/* ── دليل الإعداد ────────────────────────── */
.setup-guide {
  background: linear-gradient(135deg, #f0fdf8, #e8f8f3);
  border: 1px solid #a8e6ce;
  border-radius: 10px;
  padding: 20px 24px;
}
.setup-guide-title {
  font-weight: 700;
  font-size: .9rem;
  color: #0a5c3a;
  margin-bottom: 14px;
  display: flex; align-items: center; gap: 7px;
}
.setup-guide ol {
  margin: 0; padding-right: 20px;
  color: #374151;
  font-size: .845rem;
  line-height: 1.8;
}
.setup-guide ol li { margin-bottom: 4px; }

/* ── مؤشر تحميل الجدول ───────────────────── */
.table-empty-state {
  text-align: center;
  padding: 50px 20px;
  color: #9ca3af;
}
.table-empty-state .empty-icon { font-size: 2.5rem; margin-bottom: 10px; }

/* ── رقم المستخدم ────────────────────────── */
.user-cell { display: flex; align-items: center; gap: 8px; }
.user-avatar {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: linear-gradient(135deg, #dbeafe, #bfdbfe);
  display: flex; align-items: center; justify-content: center;
  font-size: .7rem; font-weight: 700; color: #1e40af;
  flex-shrink: 0;
}
.user-name { font-weight: 500; font-size: .845rem; color: #111827; }
.user-id   { font-size: .72rem; color: #9ca3af; }

/* ── تمييز صفوف السجل ───────────────────── */
.row-success { background: #f0fdf8 !important; }
.row-danger  { background: #fef9f9 !important; }

/* ── مشبوهة ──────────────────────────────── */
.fake-badge {
  background: #fdeef0;
  color: #9b1c2a;
  border: 1px solid #f5c2c7;
  border-radius: 5px;
  padding: 2px 7px;
  font-size: .7rem;
  font-weight: 700;
}
/* ══════════════════════════════════════════════════════════════
   USDT VISUAL POLISH — مظهر متناسق مع لوحة الإدارة
   هذه الطبقة بصرية فقط ولا تغيّر أي سلوك أو منطق PHP.
══════════════════════════════════════════════════════════════ */
.usdt-dashboard {
  --usdt-surface: var(--card, #0f1726);
  --usdt-surface-2: var(--card2, #141f33);
  --usdt-surface-3: var(--card3, #1a2840);
  --usdt-border: var(--border, rgba(255,255,255,.07));
  --usdt-border-strong: var(--border2, rgba(255,255,255,.13));
  --usdt-text: var(--text, #f1f5f9);
  --usdt-text-2: var(--text2, #94a3b8);
  --usdt-text-3: var(--text3, #64748b);
  max-width: 1680px;
  margin-inline: auto;
  padding-top: 28px !important;
  padding-bottom: 36px !important;
}
.usdt-dashboard .usdt-page-header {
  background:
    radial-gradient(circle at 12% 120%, rgba(6,182,212,.18), transparent 34%),
    radial-gradient(circle at 92% -20%, rgba(37,99,235,.22), transparent 30%),
    linear-gradient(135deg, #0c1728 0%, #10263a 52%, #123d3d 100%);
  border: 1px solid rgba(94,234,212,.16);
  box-shadow: 0 18px 45px rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.07);
  padding: 25px 30px;
  min-height: 130px;
}
.usdt-dashboard .usdt-page-header .page-title {
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: clamp(1.25rem, 2vw, 1.7rem);
  letter-spacing: -.45px;
}
.usdt-dashboard .usdt-page-header .page-sub { color: rgba(226,232,240,.72); }
.usdt-dashboard .usdt-badge-live {
  padding: 6px 13px;
  background: rgba(16,185,129,.13);
  border-color: rgba(110,231,192,.28);
  box-shadow: 0 5px 16px rgba(0,0,0,.12);
}
.usdt-dashboard .stat-card {
  min-height: 142px;
  background: linear-gradient(145deg, var(--usdt-surface-2), var(--usdt-surface));
  color: var(--usdt-text);
  border: 1px solid var(--usdt-border);
  box-shadow: 0 10px 25px rgba(0,0,0,.14), inset 0 1px 0 rgba(255,255,255,.025);
}
.usdt-dashboard .stat-card::after {
  content: '';
  position: absolute;
  inset: auto 18px 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, rgba(6,182,212,.62), transparent);
  opacity: .72;
}
.usdt-dashboard .stat-card:hover {
  border-color: rgba(6,182,212,.3);
  box-shadow: 0 15px 30px rgba(0,0,0,.22), 0 0 0 1px rgba(6,182,212,.06);
}
.usdt-dashboard .stat-green,
.usdt-dashboard .stat-blue,
.usdt-dashboard .stat-amber,
.usdt-dashboard .stat-red {
  background: linear-gradient(145deg, var(--usdt-surface-2), var(--usdt-surface)) !important;
  color: var(--usdt-text) !important;
}
.usdt-dashboard .stat-green .stat-icon { color: #34d399; background: rgba(16,185,129,.14); }
.usdt-dashboard .stat-blue .stat-icon { color: #60a5fa; background: rgba(37,99,235,.15); }
.usdt-dashboard .stat-amber .stat-icon { color: #fbbf24; background: rgba(245,158,11,.14); }
.usdt-dashboard .stat-red .stat-icon { color: #fb7185; background: rgba(239,68,68,.14); }
.usdt-dashboard .stat-card .stat-label { color: var(--usdt-text-2); opacity: 1; }
.usdt-dashboard .stat-card .stat-trend { color: var(--usdt-text-3); }
.usdt-dashboard .stat-card .stat-value { color: var(--usdt-text); }
.usdt-dashboard .usdt-tabs {
  padding: 7px 8px 0;
  background: var(--usdt-surface);
  border: 1px solid var(--usdt-border) !important;
  border-bottom: 0 !important;
  border-radius: 14px 14px 0 0;
  gap: 6px;
}
.usdt-dashboard .usdt-tabs .nav-link {
  color: var(--usdt-text-2);
  border-radius: 9px 9px 0 0;
  padding: 11px 17px;
}
.usdt-dashboard .usdt-tabs .nav-link:hover {
  color: var(--usdt-text);
  background: rgba(6,182,212,.08);
}
.usdt-dashboard .usdt-tabs .nav-link.active {
  color: #67e8f9;
  background: rgba(6,182,212,.09);
  border-bottom-color: #06b6d4;
}
.usdt-dashboard .usdt-tabs .tab-badge {
  background: var(--usdt-surface-3);
  color: var(--usdt-text-2);
}
.usdt-dashboard > div[style*="background:#fff"] {
  background: var(--usdt-surface) !important;
  border-color: var(--usdt-border) !important;
  box-shadow: 0 14px 30px rgba(0,0,0,.14) !important;
}
.usdt-dashboard .content-card,
.usdt-dashboard .settings-section {
  background: var(--usdt-surface) !important;
  border-color: var(--usdt-border) !important;
}
.usdt-dashboard .content-card .card-header-bar,
.usdt-dashboard > div > .d-flex[style*="background:#fafbfc"] {
  background: linear-gradient(180deg, var(--usdt-surface-2), var(--usdt-surface)) !important;
  border-color: var(--usdt-border) !important;
  color: var(--usdt-text);
}
.usdt-dashboard .content-card .card-header-bar .bar-title,
.usdt-dashboard .settings-section-title,
.usdt-dashboard .form-label-custom,
.usdt-dashboard label { color: var(--usdt-text) !important; }
.usdt-dashboard .settings-section-title { color: #67e8f9 !important; }
.usdt-dashboard .data-table { color: var(--usdt-text); }
.usdt-dashboard .data-table thead th {
  background: #101c2e !important;
  border-color: var(--usdt-border-strong) !important;
  color: #a5b4fc !important;
  font-size: .72rem;
  letter-spacing: .35px;
}
.usdt-dashboard .data-table tbody tr { border-color: var(--usdt-border) !important; }
.usdt-dashboard .data-table tbody tr:hover { background: rgba(6,182,212,.055) !important; }
.usdt-dashboard .data-table tbody td { color: var(--usdt-text-2) !important; }
.usdt-dashboard .data-table tbody td strong,
.usdt-dashboard .data-table tbody td [style*="color:#111827"] { color: var(--usdt-text) !important; }
.usdt-dashboard .user-name { color: var(--usdt-text) !important; }
.usdt-dashboard .user-id,
.usdt-dashboard .addr-mono,
.usdt-dashboard .form-hint { color: var(--usdt-text-3) !important; }
.usdt-dashboard .user-avatar {
  background: linear-gradient(135deg, rgba(37,99,235,.24), rgba(6,182,212,.18));
  color: #93c5fd;
  border: 1px solid rgba(96,165,250,.18);
}
.usdt-dashboard .badge-completed,
.usdt-dashboard .badge-credited { background: rgba(16,185,129,.14); color: #6ee7b7; }
.usdt-dashboard .badge-pending { background: rgba(245,158,11,.14); color: #fcd34d; }
.usdt-dashboard .badge-expired,
.usdt-dashboard .badge-no_transfers { background: rgba(100,116,139,.16); color: #cbd5e1; }
.usdt-dashboard .badge-rejected,
.usdt-dashboard .badge-invalid_signature { background: rgba(239,68,68,.14); color: #fda4af; }
.usdt-dashboard .badge-received { background: rgba(37,99,235,.15); color: #93c5fd; }
.usdt-dashboard .tx-hash-link {
  background: rgba(6,182,212,.1);
  color: #67e8f9;
  border: 1px solid rgba(6,182,212,.13);
}
.usdt-dashboard .tx-hash-link:hover { background: rgba(6,182,212,.18); color: #a5f3fc; }
.usdt-dashboard .usdt-alert-success,
.usdt-dashboard .usdt-alert-danger,
.usdt-dashboard .usdt-alert-warning {
  box-shadow: 0 8px 20px rgba(0,0,0,.1);
}
.usdt-dashboard .usdt-alert-success { background: rgba(16,185,129,.1); border-color: rgba(16,185,129,.28); color: #6ee7b7; }
.usdt-dashboard .usdt-alert-danger { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.28); color: #fda4af; }
.usdt-dashboard .usdt-alert-warning { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.28); color: #fcd34d; }
.usdt-dashboard .form-control-custom,
.usdt-dashboard select.form-control-custom,
.usdt-dashboard textarea.form-control-custom {
  background: var(--usdt-surface-2) !important;
  border-color: var(--usdt-border-strong) !important;
  color: var(--usdt-text) !important;
}
.usdt-dashboard .form-control-custom::placeholder { color: var(--usdt-text-3); }
.usdt-dashboard .form-control-custom.is-readonly { background: rgba(100,116,139,.1) !important; color: var(--usdt-text-2) !important; }
.usdt-dashboard .switch-card {
  background: rgba(37,99,235,.07);
  border-color: rgba(96,165,250,.16);
}
.usdt-dashboard .copy-btn,
.usdt-dashboard .d-flex > a[style*="background:#f1f3f5"] {
  background: var(--usdt-surface-3) !important;
  border-color: var(--usdt-border-strong) !important;
  color: var(--usdt-text-2) !important;
}
.usdt-dashboard .copy-btn:hover,
.usdt-dashboard .d-flex > a[style*="background:#f1f3f5"]:hover { background: rgba(6,182,212,.14) !important; color: var(--usdt-text) !important; }
.usdt-dashboard .setup-guide {
  background: linear-gradient(135deg, rgba(16,185,129,.09), rgba(6,182,212,.06));
  border-color: rgba(52,211,153,.24);
}
.usdt-dashboard .setup-guide-title { color: #6ee7b7; }
.usdt-dashboard .setup-guide ol { color: var(--usdt-text-2); }
.usdt-dashboard .table-empty-state { color: var(--usdt-text-3); }
.usdt-dashboard .row-success { background: rgba(16,185,129,.06) !important; }
.usdt-dashboard .row-danger { background: rgba(239,68,68,.06) !important; }
.usdt-dashboard .table-responsive { scrollbar-color: var(--usdt-border-strong) transparent; }
.usdt-dashboard .table-responsive::-webkit-scrollbar { height: 7px; }
.usdt-dashboard .table-responsive::-webkit-scrollbar-thumb { background: var(--usdt-border-strong); border-radius: 10px; }
@media (max-width: 900px) {
  .usdt-dashboard { padding: 18px 12px 28px !important; }
  .usdt-dashboard .usdt-page-header { padding: 21px 19px; min-height: auto; }
  .usdt-dashboard .usdt-page-header > .d-flex { align-items: flex-start !important; }
  .usdt-dashboard .stat-card { min-height: 126px; padding: 18px 19px; }
  .usdt-dashboard .usdt-tabs { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: none; }
  .usdt-dashboard .usdt-tabs::-webkit-scrollbar { display: none; }
  .usdt-dashboard .usdt-tabs .nav-link { white-space: nowrap; padding-inline: 13px; }
  .usdt-dashboard .data-table { min-width: 980px; }
}
@media (prefers-reduced-motion: reduce) {
  .usdt-dashboard *, .usdt-dashboard *::before, .usdt-dashboard *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
</style>

<div class="container-fluid py-4 usdt-dashboard" dir="rtl">

  <!-- ── رأس الصفحة ───────────────────────── -->
  <div class="usdt-page-header mb-4">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
      <div>
        <p class="page-sub mb-1">لوحة التحكم / المالية</p>
        <h1 class="page-title">
          <svg width="28" height="28" viewBox="0 0 32 32" fill="none" style="margin-left:8px;vertical-align:-4px">
            <circle cx="16" cy="16" r="16" fill="#26a17b"/>
            <path d="M17.9 9H14.1V11.2H10V13.4H22V11.2H17.9V9ZM16 14.5C12.7 14.5 10 15.1 10 15.9C10 16.7 12.7 17.3 16 17.3C19.3 17.3 22 16.7 22 15.9C22 15.1 19.3 14.5 16 14.5ZM16 18.4C12.7 18.4 10 17.9 10 17.9V20C10 20.8 12.7 21.3 16 21.3C19.3 21.3 22 20.8 22 20V17.9C22 17.9 19.3 18.4 16 18.4Z" fill="white"/>
          </svg>
          إدارة إيداعات USDT
        </h1>
      </div>
      <div class="d-flex flex-column align-items-end gap-2">
        <span class="usdt-badge-live"><span class="dot"></span> BEP-20 · BSC Mainnet</span>
        <small style="opacity:.5;font-size:.75rem"><?= date('Y/m/d H:i') ?></small>
      </div>
    </div>
  </div>

  <!-- ── رسالة النتيجة ─────────────────────── -->
  <?php if ($msg): ?>
    <div class="usdt-alert usdt-alert-<?= $msgType ?>">
      <span class="alert-icon"><?= $msgType === 'success' ? '✅' : '❌' ?></span>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
  <?php endif; ?>

  <!-- ── بطاقات الإحصاء ───────────────────── -->
  <div class="row g-3 mb-4">

    <div class="col-xl-3 col-md-6">
      <div class="stat-card stat-green">
        <div class="stat-icon">💰</div>
        <div class="stat-value"><?= number_format((float)($stats['total_amount'] ?? 0), 2) ?></div>
        <div class="stat-label">إجمالي الإيداعات (USDT)</div>
        <div class="stat-trend">
          <span>🔄</span>
          <span><?= number_format((int)($stats['total_completed'] ?? 0)) ?> معاملة مكتملة</span>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card stat-blue">
        <div class="stat-icon">📅</div>
        <div class="stat-value"><?= (int)($stats['today_completed'] ?? 0) ?></div>
        <div class="stat-label">إيداعات اليوم</div>
        <div class="stat-trend">
          <span>📈</span>
          <span>معاملات مكتملة</span>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card stat-amber">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?= (int)($stats['pending_count'] ?? 0) ?></div>
        <div class="stat-label">طلبات نشطة</div>
        <div class="stat-trend">
          <span>⌛</span>
          <span>قيد الانتظار</span>
        </div>
      </div>
    </div>

    <div class="col-xl-3 col-md-6">
      <div class="stat-card stat-red">
        <div class="stat-icon">🚨</div>
        <div class="stat-value"><?= (int)($stats['suspicious_count'] ?? 0) ?></div>
        <div class="stat-label">معاملات مشبوهة اليوم</div>
        <div class="stat-trend">
          <span>⚠️</span>
          <span>تتطلب مراجعة</span>
        </div>
      </div>
    </div>

  </div>

  <!-- ── التبويبات ─────────────────────────── -->
  <ul class="nav usdt-tabs mb-0" style="border-bottom:2px solid #e9ecef">
    <li class="nav-item">
      <a class="nav-link <?= $tab==='requests'  ?'active':'' ?>" href="?tab=requests">
        <span>📋</span> الطلبات
        <?php if (!empty($stats['pending_count'])): ?>
          <span class="tab-badge"><?= (int)$stats['pending_count'] ?></span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='logs'      ?'active':'' ?>" href="?tab=logs">
        <span>📜</span> سجلات Webhook
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='suspicious'?'active':'' ?>" href="?tab=suspicious">
        <span>⚠️</span> مشبوهة
        <?php if (!empty($stats['suspicious_count'])): ?>
          <span class="tab-badge" style="background:#fdeef0;color:#9b1c2a"><?= (int)$stats['suspicious_count'] ?></span>
        <?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab==='settings'  ?'active':'' ?>" href="?tab=settings">
        <span>⚙️</span> الإعدادات
      </a>
    </li>
  </ul>

  <div style="background:#fff;border:1px solid #e9ecef;border-top:none;border-radius:0 0 var(--card-radius) var(--card-radius);box-shadow:var(--shadow-sm);padding:0 0 8px">

  <!-- ════════════════════════════════════════
       تبويب: الطلبات
       ════════════════════════════════════════ -->
  <?php if ($tab === 'requests'): ?>

    <div class="d-flex align-items-center justify-content-between px-4 py-3" style="border-bottom:1px solid #f0f2f5;background:#fafbfc">
      <span style="font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:8px">
        📋 طلبات الإيداع
        <span style="background:#f0f2f5;color:#6b7280;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:600">
          <?= count($requests) ?> طلب
        </span>
      </span>
      <a href="?tab=requests" class="btn btn-sm" style="background:#f1f3f5;border:none;border-radius:7px;font-size:.8rem;padding:6px 14px;color:#374151">
        🔄 تحديث
      </a>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>المستخدم</th>
            <th>المبلغ المطلوب</th>
            <th>الحالة</th>
            <th>TX Hash</th>
            <th>المبلغ المُضاف</th>
            <th>الصندوق والقيد</th>
            <th>تاريخ الطلب</th>
            <th>ينتهي في</th>
            <th>إجراء</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
            <tr><td colspan="10">
              <div class="table-empty-state">
                <div class="empty-icon">📭</div>
                <div>لا توجد طلبات حتى الآن</div>
              </div>
            </td></tr>
          <?php else: ?>
            <?php foreach ($requests as $r): ?>
            <tr>
              <td style="color:#9ca3af;font-size:.78rem">#<?= $r['id'] ?></td>
              <td>
                <div class="user-cell">
                  <div class="user-avatar"><?= mb_substr($r['username'] ?? '?', 0, 1) ?></div>
                  <div>
                    <div class="user-name"><?= htmlspecialchars($r['username'] ?? '—') ?></div>
                    <div class="user-id">#<?= $r['user_id'] ?></div>
                  </div>
                </div>
              </td>
              <td>
                <strong style="color:#111827"><?= $r['unique_amount'] ?></strong>
                <span style="font-size:.75rem;color:#9ca3af"> USDT</span>
              </td>
              <td>
                <?php $sc = ['completed'=>'completed','pending'=>'pending','expired'=>'expired','rejected'=>'rejected']; ?>
                <?php $labels = ['completed'=>'مكتمل','pending'=>'معلق','expired'=>'منتهي','rejected'=>'مرفوض']; ?>
                <span class="badge-status badge-<?= $sc[$r['status']] ?? 'expired' ?>">
                  <?= $labels[$r['status']] ?? $r['status'] ?>
                </span>
              </td>
              <td>
                <?php if ($r['tx_hash']): ?>
                  <a href="https://bscscan.com/tx/<?= $r['tx_hash'] ?>" target="_blank" class="tx-hash-link">
                    <?= substr($r['tx_hash'], 0, 10) ?>…
                  </a>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['credited_amount']): ?>
                  <span style="color:#0a6644;font-weight:600"><?= $r['credited_amount'] ?></span>
                  <span style="font-size:.75rem;color:#9ca3af"> USDT</span>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['cashbox_name'])): ?>
                  <div style="font-size:.78rem;font-weight:600;color:#374151"><?= htmlspecialchars($r['cashbox_name']) ?></div>
                  <div style="font-size:.7rem;color:#6b7280"><?= htmlspecialchars($r['cashbox_code'] ?? '') ?></div>
                  <?php if (!empty($r['posted_journal_id']) && !empty($r['posted_movement_id'])): ?>
                    <div style="font-size:.7rem;color:#0a6644">قيد #<?= (int)$r['posted_journal_id'] ?> · حركة #<?= (int)$r['posted_movement_id'] ?></div>
                  <?php else: ?>
                    <div style="font-size:.7rem;color:#b45309">لم تُرحّل بعد</div>
                  <?php endif; ?>
                <?php else: ?>
                  <span style="font-size:.75rem;color:#b45309">غير مرتبط</span>
                <?php endif; ?>
              </td>
              <td><span style="font-size:.8rem;color:#6b7280"><?= $r['created_at'] ?></span></td>
              <td>
                <span class="<?= strtotime($r['expires_at']) < time() ? 'text-danger' : '' ?>" style="font-size:.8rem">
                  <?= $r['expires_at'] ?>
                </span>
              </td>
              <td>
                <?php if ((int)$r['id'] === 24 && in_array($r['status'], ['expired','rejected'], true) && empty($r['tx_hash']) && $r['credited_amount'] === null): ?>
                  <form method="post" style="margin:0" onsubmit="return confirm('إعادة تنشيط طلب USDT #24 لمدة 30 دقيقة؟ لن يُضاف أي رصيد تلقائياً.');">
                    <?= adminCsrfField() ?>
                    <input type="hidden" name="reactivate_usdt_request" value="24">
                    <button type="submit" class="btn btn-sm" style="background:#0f766e;color:#fff;border:0;border-radius:7px;padding:6px 10px;font-size:.75rem">إعادة تنشيط</button>
                  </form>
                <?php else: ?>
                  <span style="color:#d1d5db">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <!-- ════════════════════════════════════════
       تبويب: سجلات Webhook
       ════════════════════════════════════════ -->
  <?php elseif ($tab === 'logs'): ?>

    <div class="d-flex align-items-center justify-content-between px-4 py-3" style="border-bottom:1px solid #f0f2f5;background:#fafbfc">
      <span style="font-weight:600;font-size:.95rem;display:flex;align-items:center;gap:8px">
        📜 سجلات Webhook
        <span style="background:#f0f2f5;color:#6b7280;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:600">
          <?= count($webhookLogs) ?> سجل
        </span>
      </span>
    </div>

    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>TX Hash</th>
            <th>النتيجة</th>
            <th>الملاحظة</th>
            <th>IP المصدر</th>
            <th>الوقت</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($webhookLogs)): ?>
            <tr><td colspan="6">
              <div class="table-empty-state">
                <div class="empty-icon">📭</div>
                <div>لا توجد سجلات</div>
              </div>
            </td></tr>
          <?php else: ?>
            <?php
            $logBadgeMap = [
              'credited'          => 'credited',
              'rejected'          => 'rejected',
              'received'          => 'received',
              'no_transfers'      => 'no_transfers',
              'invalid_signature' => 'invalid_signature',
              'sig_debug'         => 'invalid_signature',
              'ping'              => 'no_transfers',
              'warning'           => 'no_transfers',
              'credit_failed'     => 'rejected',
            ];
            $logLabelMap = [
              'credited'          => 'تم الإضافة ✅',
              'rejected'          => 'مرفوض',
              'received'          => 'مستلم',
              'no_transfers'      => 'بلا تحويلات',
              'invalid_signature' => 'توقيع خاطئ',
              'sig_debug'         => 'توقيع (تشخيص)',
              'ping'              => 'ping',
              'warning'           => 'تحذير',
              'credit_failed'     => 'فشل الإضافة',
            ];
            foreach ($webhookLogs as $log):
              $isGood = in_array($log['result'], ['credited']);
              $isBad  = in_array($log['result'], ['rejected','invalid_signature','credit_failed']);
            ?>
            <tr class="<?= $isGood ? 'row-success' : ($isBad ? 'row-danger' : '') ?>">
              <td style="color:#9ca3af;font-size:.78rem">#<?= $log['id'] ?></td>
              <td>
                <span class="addr-mono"><?= substr($log['tx_hash'] ?? '', 0, 16) ?>…</span>
              </td>
              <td>
                <span class="badge-status badge-<?= $logBadgeMap[$log['result']] ?? 'expired' ?>">
                  <?= $logLabelMap[$log['result']] ?? $log['result'] ?>
                </span>
              </td>
              <td><span style="font-size:.75rem;color:#6b7280;word-break:break-all;max-width:300px;display:block"><?= htmlspecialchars($log['note'] ?? '—') ?></span></td>
              <td><span class="addr-mono"><?= htmlspecialchars($log['ip'] ?? '—') ?></span></td>
              <td><span style="font-size:.8rem;color:#6b7280"><?= $log['created_at'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <!-- ════════════════════════════════════════
       تبويب: المشبوهة
       ════════════════════════════════════════ -->
  <?php elseif ($tab === 'suspicious'): ?>

    <div class="px-4 pt-4 pb-2">
      <div class="usdt-alert usdt-alert-warning">
        <span class="alert-icon">⚠️</span>
        <div>
          <strong>معاملات مشبوهة مرفوضة</strong><br>
          <span style="font-size:.83rem">هذه العمليات استخدمت توكنات مزيفة أو لا تتطابق مع عقد USDT الرسمي — قد تكون محاولات احتيال.</span>
        </div>
      </div>
    </div>

    <div class="table-responsive px-0">
      <table class="data-table">
        <thead>
          <tr>
            <th>TX Hash</th>
            <th>العقد (المُرسَل)</th>
            <th>من</th>
            <th>إلى</th>
            <th>المبلغ الخام</th>
            <th>سبب الرفض</th>
            <th>الوقت</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suspicious)): ?>
            <tr><td colspan="7">
              <div class="table-empty-state">
                <div class="empty-icon">✅</div>
                <div>لا توجد معاملات مشبوهة</div>
              </div>
            </td></tr>
          <?php else: ?>
            <?php foreach ($suspicious as $s): ?>
            <tr>
              <td><span class="addr-mono"><?= substr($s['tx_hash'] ?? '', 0, 12) ?>…</span></td>
              <td>
                <span class="addr-mono" style="color:#9b1c2a"><?= htmlspecialchars(substr($s['contract_address'] ?? '', 0, 16)) ?>…</span>
                <?php if (strtolower($s['contract_address'] ?? '') !== strtolower(USDT_OFFICIAL_CONTRACT)): ?>
                  <span class="fake-badge">مزيف!</span>
                <?php endif; ?>
              </td>
              <td><span class="addr-mono"><?= substr($s['from_address'] ?? '', 0, 10) ?>…</span></td>
              <td><span class="addr-mono"><?= substr($s['to_address']   ?? '', 0, 10) ?>…</span></td>
              <td style="font-size:.8rem"><?= htmlspecialchars($s['amount_raw'] ?? '—') ?></td>
              <td>
                <span style="color:#9b1c2a;font-weight:600;font-size:.82rem">
                  <?= htmlspecialchars($s['reason']) ?>
                </span>
              </td>
              <td><span style="font-size:.8rem;color:#6b7280"><?= $s['created_at'] ?></span></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <!-- ════════════════════════════════════════
       تبويب: الإعدادات
       ════════════════════════════════════════ -->
  <?php elseif ($tab === 'settings'): ?>

    <div class="p-4">
      <form method="POST" enctype="multipart/form-data">
        <?= adminCsrfField() ?>
        <input type="hidden" name="save_usdt_settings" value="1">

        <!-- تفعيل النظام -->
        <div class="settings-section">
          <div class="settings-section-title">🔌 حالة النظام</div>
          <div class="switch-card">
            <div>
              <div style="font-weight:600;color:#111827;font-size:.9rem">تفعيل نظام إيداع USDT</div>
              <div style="font-size:.8rem;color:#9ca3af;margin-top:3px">تشغيل أو إيقاف استقبال إيداعات USDT عبر BEP-20</div>
            </div>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="usdt_enabled" id="usdt_enabled"
                     <?= ($settings['usdt_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="usdt_enabled"></label>
            </div>
          </div>
        </div>

        <!-- إعدادات المحفظة -->
        <div class="settings-section">
          <div class="settings-section-title">🔑 إعدادات المحفظة</div>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label-custom">محفظة الاستقبال (BSC)</label>
              <input type="text" name="usdt_wallet_address"
                     class="form-control form-control-custom"
                     value="<?= htmlspecialchars($settings['usdt_wallet_address'] ?? '') ?>"
                     placeholder="0x..." dir="ltr">
              <div class="form-hint">العنوان الذي سيتم استقبال USDT إليه على شبكة BNB Smart Chain</div>
            </div>
            <div class="col-md-4">
              <label class="form-label-custom">عقد USDT الرسمي</label>
              <input type="text" class="form-control form-control-custom is-readonly"
                     value="<?= USDT_OFFICIAL_CONTRACT ?>" readonly dir="ltr">
              <div class="form-hint" style="color:#0a6644">✅ ثابت — لا يتغير</div>
            </div>
            <div class="col-12">
              <label class="form-label-custom">صندوق إيداعات USDT — USD</label>
              <select name="usdt_cashbox_id" class="form-control form-control-custom" required>
                <option value="0">اختر صندوقاً نشطاً مرتبطاً بحساب USD</option>
                <?php foreach ($usdtCashboxes as $box): ?>
                  <option value="<?= (int)$box['id'] ?>" <?= (int)($settings['usdt_cashbox_id'] ?? 0) === (int)$box['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($box['name']) ?> — <?= htmlspecialchars($box['code']) ?> — حساب <?= htmlspecialchars($box['account_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint" style="color:#0a6644">
                ستُرحّل الإيداعات الجديدة المؤكدة إلى هذا الصندوق كحركة قبض، ويُنشأ قيد مدين للصندوق ودائن لحساب محافظ العملاء 1100/USD داخل نفس العملية. الطلبات التاريخية لا تُرحّل تلقائياً.
              </div>
              <?php if (empty($usdtCashboxes)): ?>
                <div class="form-hint" style="color:#9b1c2a">لا توجد صناديق USD نشطة مرتبطة بحساب محاسبي نشط. أنشئ الصندوق من قسم الحسابات أولاً.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- مظهر طريقة الدفع -->
        <div class="settings-section">
          <div class="settings-section-title">🎨 مظهر USDT في لوحة العميل</div>
          <div class="row g-3 align-items-end">
            <div class="col-md-7">
              <label class="form-label-custom">صورة / شعار USDT</label>
              <input type="file" name="usdt_image" class="form-control form-control-custom" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif">
              <div class="form-hint">اختياري — PNG أو JPG أو WebP أو SVG أو GIF، وبحجم أقصى 2MB. تظهر الصورة داخل قسم «⚡ مباشر» في لوحة العميل.</div>
              <?php if (!empty($settings['usdt_image'])): ?>
                <label class="form-hint" style="display:flex;align-items:center;gap:8px;margin-top:8px;cursor:pointer">
                  <input type="checkbox" name="delete_usdt_image" value="1"> حذف الصورة الحالية واستخدام الأيقونة البديلة
                </label>
              <?php endif; ?>
            </div>
            <div class="col-md-5">
              <label class="form-label-custom">أيقونة Font Awesome البديلة</label>
              <div style="display:flex;align-items:center;gap:12px">
                <input type="text" name="usdt_icon" id="usdt_icon" class="form-control form-control-custom" value="<?= htmlspecialchars($settings['usdt_icon'] ?? 'coins') ?>" placeholder="coins" dir="ltr">
                <?php if (!empty($settings['usdt_image'])): ?>
                  <img src="<?= SITE_URL ?>/<?= htmlspecialchars(ltrim($settings['usdt_image'], '/')) ?>" alt="USDT" style="width:48px;height:48px;object-fit:contain;border-radius:12px;background:#f4f7fb;padding:5px">
                <?php else: ?>
                  <span id="usdtIconPreview" style="width:48px;height:48px;display:grid;place-items:center;border-radius:12px;background:rgba(38,161,123,.14);color:#26a17b;font-size:24px"><i class="fas fa-<?= htmlspecialchars($settings['usdt_icon'] ?? 'coins') ?>"></i></span>
                <?php endif; ?>
              </div>
              <div class="form-hint">اكتب اسم الأيقونة فقط مثل: coins أو wallet أو bolt. تُستخدم عند عدم وجود صورة.</div>
            </div>
          </div>
        </div>

        <!-- Moralis -->
        <div class="settings-section">
          <div class="settings-section-title">🌐 إعدادات Moralis Streams</div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label-custom">Moralis API Key</label>
              <input type="password" name="usdt_moralis_api_key"
                     class="form-control form-control-custom"
                     value="<?= htmlspecialchars($settings['usdt_moralis_api_key'] ?? '') ?>"
                     dir="ltr" autocomplete="off" placeholder="••••••••••••••••">
            </div>
            <div class="col-md-6">
              <label class="form-label-custom">Moralis Stream ID</label>
              <input type="text" name="usdt_moralis_stream_id"
                     class="form-control form-control-custom"
                     value="<?= htmlspecialchars($settings['usdt_moralis_stream_id'] ?? '') ?>"
                     dir="ltr">
            </div>
            <div class="col-12">
              <label class="form-label-custom">Webhook Secret (للتحقق من التوقيع)</label>
              <input type="password" name="usdt_webhook_secret"
                     class="form-control form-control-custom"
                     value="<?= htmlspecialchars($settings['usdt_webhook_secret'] ?? '') ?>"
                     dir="ltr" autocomplete="off">
              <div class="form-hint">يُحدَّد في: Moralis → Stream Settings → Secret</div>
            </div>
            <div class="col-12">
              <label class="form-label-custom">رابط Webhook (انسخه إلى Moralis)</label>
              <div class="copy-group">
                <input type="text" class="form-control form-control-custom is-readonly"
                       value="<?= SITE_URL ?>/usdt_webhook.php"
                       readonly dir="ltr" id="webhookUrl">
                <button type="button" class="copy-btn" id="copyBtn"
                        onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value).then(()=>{this.textContent='✅ تم النسخ';setTimeout(()=>this.textContent='📋 نسخ',2000)})">
                  📋 نسخ
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- حدود وقيود -->
        <div class="settings-section">
          <div class="settings-section-title">⚙️ الحدود والقيود</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label-custom">الحد الأدنى للتأكيدات</label>
              <input type="number" name="usdt_min_confirmations"
                     class="form-control form-control-custom"
                     value="<?= (int)($settings['usdt_min_confirmations'] ?? 3) ?>"
                     min="1" max="30">
              <div class="form-hint">يُنصح بـ 3 تأكيدات على الأقل</div>
            </div>
            <div class="col-md-4">
              <label class="form-label-custom">الحد الأدنى للإيداع (USDT)</label>
              <input type="number" name="usdt_min_deposit"
                     class="form-control form-control-custom"
                     value="<?= htmlspecialchars($settings['usdt_min_deposit'] ?? '1.00') ?>"
                     min="0.01" step="0.01">
              <div class="form-hint">أقل مبلغ مقبول للإيداع</div>
            </div>
            <div class="col-md-4">
              <label class="form-label-custom">مدة صلاحية الطلب (دقيقة)</label>
              <input type="number" name="usdt_request_ttl"
                     class="form-control form-control-custom"
                     value="<?= (int)($settings['usdt_request_ttl'] ?? 30) ?>"
                     min="5" max="1440">
              <div class="form-hint">بعدها يُعتبر الطلب منتهي الصلاحية</div>
            </div>
          </div>
        </div>

        <!-- زر الحفظ -->
        <div class="d-flex align-items-center gap-3">
          <button type="submit" class="btn-save-usdt">
            💾 حفظ الإعدادات
          </button>
          <span style="font-size:.8rem;color:#9ca3af">جميع الحقول محمية بـ CSRF Token</span>
        </div>

      </form>

      <!-- دليل الإعداد -->
      <hr class="my-4" style="border-color:#f0f2f5">
      <div class="setup-guide">
        <div class="setup-guide-title">📖 خطوات إعداد Moralis Streams</div>
        <ol>
          <li>سجّل في <a href="https://moralis.io" target="_blank" style="color:var(--usdt-green);font-weight:500">moralis.io</a> واحصل على API Key</li>
          <li>اذهب إلى <strong>Streams → Create New Stream</strong></li>
          <li>اختر <strong>EVM</strong> Chain = <strong>BSC Mainnet (0x38)</strong></li>
          <li>في <strong>Addresses</strong> أضف عنوان محفظتك</li>
          <li>في <strong>Topic</strong> اختر <strong>ERC20 Transfers</strong></li>
          <li>في <strong>Webhook URL</strong> الصق رابط الـ Webhook أعلاه</li>
          <li>في <strong>Secret</strong> أنشئ كلمة سر قوية وانسخها في الحقل أعلاه</li>
          <li>فعّل خيار <strong>Only confirmed txs</strong></li>
          <li>احفظ الـ <strong>Stream ID</strong> وضعه في إعدادات Moralis أعلاه</li>
        </ol>
      </div>

    </div>
  <?php endif; ?>

  </div><!-- /content box -->
</div><!-- /container -->

<?php require_once 'footer.php'; ?>
