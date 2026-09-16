<?php
/**
 * ══════════════════════════════════════════════════════════════════
 *  admin/cron_sync_prices.php
 *  مزامنة تلقائية دورية لسعر وكمية كل خدمة من مزودها الحالي المعتمد
 * ══════════════════════════════════════════════════════════════════
 *  يُشغَّل كـ Cron Job (كل 30 دقيقة مثلاً — الفحص الفعلي لكل خدمة
 *  يعتمد على "كل كم ساعة" المحدد لها في إعداداتها الخاصة):
 *
 *    */30 * * * * php /home/USER/public_html/admin/cron_sync_prices.php >> /tmp/cron_sync_prices.log 2>&1
 *
 *  أو عبر URL بمفتاح حماية (نفس مفتاح الكرون المستخدم أصلاً بالموقع):
 *    https://yourdomain.com/admin/cron_sync_prices.php?key=YOUR_CRON_KEY
 */

define('CRON_MODE', true);
require_once __DIR__ . '/../includes/config.php';
ignore_user_abort(true);

// ── حماية: تشغيل CLI أو مفتاح صحيح ──────────────────────────────────────────
$cronKey = getSetting('cron_key') ?: 'cron_secret_key_change_me';
$isCli   = php_sapi_name() === 'cli';
$isWeb   = isset($_GET['key']) && hash_equals($cronKey, $_GET['key']);
if (!$isCli && !$isWeb) { http_response_code(403); exit('Forbidden'); }

// ── منع التشغيل المتزامن (Lock File) ─────────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/cron_sync_prices.lock';
$fp = fopen($lockFile, 'c');
if (!flock($fp, LOCK_EX | LOCK_NB)) { echo "⏭️ تخطي: مهمة مزامنة أسعار أخرى تعمل الآن\n"; exit; }

// ── جداول العمل (تُنشأ تلقائياً إن لم تكن موجودة) ────────────────────────────
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_enabled TINYINT(1) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_markup_percent DECIMAL(8,2) NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_interval_hours INT NOT NULL DEFAULT 6");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_last_run DATETIME NULL");
$pdo->exec("ALTER TABLE services ADD COLUMN IF NOT EXISTS sync_last_result VARCHAR(255) NULL");
$pdo->exec("CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    service_name VARCHAR(190) DEFAULT NULL,
    provider_id INT DEFAULT NULL,
    provider_service_id VARCHAR(60) DEFAULT NULL,
    old_price DECIMAL(18,8) DEFAULT NULL,
    provider_price DECIMAL(18,8) DEFAULT NULL,
    markup_percent DECIMAL(8,2) DEFAULT NULL,
    new_price DECIMAL(18,8) DEFAULT NULL,
    old_min INT DEFAULT NULL, new_min INT DEFAULT NULL,
    old_max INT DEFAULT NULL, new_max INT DEFAULT NULL,
    reason VARCHAR(20) NOT NULL DEFAULT 'auto',
    status VARCHAR(20) NOT NULL,
    message VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// ترقية الجدول القديم إن كان منشأ من نسخة سابقة بدون هذي الأعمدة
foreach ([
    "service_name VARCHAR(190) DEFAULT NULL",
    "provider_service_id VARCHAR(60) DEFAULT NULL",
    "provider_price DECIMAL(18,8) DEFAULT NULL",
    "markup_percent DECIMAL(8,2) DEFAULT NULL",
    "reason VARCHAR(20) NOT NULL DEFAULT 'auto'",
] as $col) {
    $colName = explode(' ', $col)[0];
    try { $pdo->exec("ALTER TABLE sync_log ADD COLUMN IF NOT EXISTS $col"); } catch (Exception $e) {}
}

/** يحدّد سبب التشغيل الحالي: manual (زر داخل لوحة الإدارة) أو auto (Cron/رابط مجدول) */
$SYNC_REASON = !empty($GLOBALS['__SYNC_MANUAL__']) ? 'manual' : 'auto';

function syncLog(string $msg): void { echo '[' . date('H:i:s') . "] $msg\n"; }

// ── مفتاح إيقاف/تشغيل عام للنظام بالكامل ─────────────────────────────────────
if (getSetting('price_sync_global_enabled') === '0') {
    syncLog('⏸️ المزامنة التلقائية موقوفة عمومياً من الإعدادات — لا شيء يُنفَّذ.');
    flock($fp, LOCK_UN);
    exit;
}

// ── الخدمات المستحقة للمزامنة الآن ───────────────────────────────────────────
if (!empty($GLOBALS['__SYNC_FORCE_IDS__'])) {
    // مزامنة قسرية لخدمات محددة بالذات (يدوياً)، بغض النظر عن تفعيلها أو وقتها المستحق
    $ids = array_filter(array_map('intval', $GLOBALS['__SYNC_FORCE_IDS__']));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM services WHERE id IN ($in)");
        $st->execute($ids);
        $due = $st->fetchAll();
    } else {
        $due = [];
    }
} else {
    $due = $pdo->query("
        SELECT * FROM services
        WHERE sync_enabled = 1
          AND (sync_last_run IS NULL OR sync_last_run <= DATE_SUB(NOW(), INTERVAL sync_interval_hours HOUR))
    ")->fetchAll();
}

syncLog('🔎 عدد الخدمات المستحقة للمزامنة الآن: ' . count($due));

// ── تجميع الخدمات حسب المزود الحالي المعتمد لكل خدمة (نفس منطق place_order.php) ──
$byProvider = []; // provider_id => ['provider'=>row, 'services'=>[service_id=>svcRow]]
$totalUpdated = 0; $totalErrors = 0;

foreach ($due as $svc) {
    $activeProv = null;

    // أولوية: service_providers (نظام تعدد المزودين)، وإلا provider_id المباشر بالخدمة (توافقية قديمة)
    $spStmt = $pdo->prepare("
        SELECT sp.provider_id, sp.provider_service_id, p.*
        FROM service_providers sp JOIN providers p ON sp.provider_id = p.id
        WHERE sp.service_id=? AND sp.is_active=1 AND p.status=1
        ORDER BY sp.priority ASC LIMIT 1
    ");
    $spStmt->execute([$svc['id']]);
    $sp = $spStmt->fetch();

    if ($sp) {
        $activeProv = $sp;
        $activeProv['provider_service_id'] = $sp['provider_service_id'];
    } elseif (!empty($svc['provider_id']) && !empty($svc['provider_service_id'])) {
        $p = $pdo->prepare("SELECT * FROM providers WHERE id=? AND status=1");
        $p->execute([$svc['provider_id']]);
        $p = $p->fetch();
        if ($p) { $activeProv = $p; $activeProv['provider_service_id'] = $svc['provider_service_id']; }
    }

    if (!$activeProv || !in_array($activeProv['provider_type'], ['oranos', 'ap4stor'], true)) {
        // لا يوجد مزود آلي معتمد لهذه الخدمة — تحديث وقت آخر محاولة فقط مع رسالة توضيحية
        $pdo->prepare("UPDATE services SET sync_last_run=NOW(), sync_last_result=? WHERE id=?")
            ->execute(['⚠️ لا يوجد مزود API معتمد لهذه الخدمة', $svc['id']]);
        $pdo->prepare("INSERT INTO sync_log (service_id,service_name,status,reason,message) VALUES (?,?,?,?,?)")
            ->execute([$svc['id'], $svc['name'], 'error', $SYNC_REASON, 'لا يوجد مزود API معتمد لهذه الخدمة']);
        $totalErrors++;
        continue;
    }

    $pid = $activeProv['id'];
    if (!isset($byProvider[$pid])) $byProvider[$pid] = ['provider' => $activeProv, 'services' => []];
    $byProvider[$pid]['services'][$svc['id']] = $svc + ['prov_service_id' => $activeProv['provider_service_id']];
}

// ── لكل مزود: جلب دفعة واحدة لكل منتجاته المطلوبة (كفاءة أعلى، طلب واحد بدل عشرات) ──

foreach ($byProvider as $pid => $bundle) {
    $prov = $bundle['provider'];
    $svcs = $bundle['services'];
    $idsList = array_unique(array_column($svcs, 'prov_service_id'));

    syncLog("🌐 مزامنة {$prov['name']} — " . count($idsList) . ' منتج...');

    $ch = curl_init(rtrim($prov['api_url'], '/') . '/client/api/products?products_id=' . implode(',', array_map('rawurlencode', $idsList)));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['api-token: ' . $prov['api_key']], CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    $list = json_decode($res, true);

    if (!is_array($list) || isset($list['error'])) {
        syncLog("   ❌ فشل الاتصال: " . ($err ?: ($list['error'] ?? 'رد غير صالح')));
        foreach ($svcs as $svcId => $svc) {
            $pdo->prepare("UPDATE services SET sync_last_run=NOW(), sync_last_result=? WHERE id=?")
                ->execute(['❌ فشل الاتصال بالمزود: ' . ($err ?: 'رد غير صالح'), $svcId]);
            $pdo->prepare("INSERT INTO sync_log (service_id,service_name,provider_id,provider_service_id,markup_percent,status,reason,message) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$svcId, $svc['name'], $pid, $svc['prov_service_id'], $svc['sync_markup_percent'], 'error', $SYNC_REASON, 'فشل الاتصال بالمزود: ' . ($err ?: 'رد غير صالح')]);
            $totalErrors++;
        }
        continue;
    }

    // فهرسة نتائج المزود بمعرّف المنتج لسهولة المطابقة
    $byId = [];
    foreach ($list as $p) { $byId[(string)($p['id'] ?? '')] = $p; }

    foreach ($svcs as $svcId => $svc) {
        $match = $byId[(string)$svc['prov_service_id']] ?? null;
        if (!$match) {
            $pdo->prepare("UPDATE services SET sync_last_run=NOW(), sync_last_result=? WHERE id=?")
                ->execute(['⚠️ المنتج لم يعد موجوداً عند المزود', $svcId]);
            $pdo->prepare("INSERT INTO sync_log (service_id,service_name,provider_id,provider_service_id,markup_percent,status,reason,message) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$svcId, $svc['name'], $pid, $svc['prov_service_id'], $svc['sync_markup_percent'], 'error', $SYNC_REASON, 'المنتج غير موجود عند المزود']);
            $totalErrors++;
            continue;
        }

        $oldPrice = (float)$svc['price'];
        $oldMin   = (int)$svc['min_qty'];
        $oldMax   = (int)$svc['max_qty'];

        $provPrice = (float)($match['price'] ?? 0);
        $markup    = (float)$svc['sync_markup_percent'];
        $newPrice  = $provPrice > 0 ? round($provPrice * (1 + $markup / 100), 8) : $oldPrice;

        $qty = $match['qty_values'] ?? null;
        $newMin = is_array($qty) && isset($qty['min']) ? (int)$qty['min'] : $oldMin;
        $newMax = is_array($qty) && isset($qty['max']) ? (int)$qty['max'] : $oldMax;
        if ($newMax < $newMin) $newMax = $newMin;

        $resultMsg = "السعر: {$oldPrice} → {$newPrice} · الكمية: {$oldMin}-{$oldMax} → {$newMin}-{$newMax}";

        $pdo->prepare("UPDATE services SET price=?, min_qty=?, max_qty=?, sync_last_run=NOW(), sync_last_result=? WHERE id=?")
            ->execute([$newPrice, $newMin, $newMax, '✅ ' . $resultMsg, $svcId]);

        $pdo->prepare("INSERT INTO sync_log
            (service_id,service_name,provider_id,provider_service_id,old_price,provider_price,markup_percent,new_price,old_min,new_min,old_max,new_max,status,reason,message)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$svcId, $svc['name'], $pid, $svc['prov_service_id'], $oldPrice, $provPrice, $markup, $newPrice, $oldMin, $newMin, $oldMax, $newMax, 'success', $SYNC_REASON, $resultMsg]);

        syncLog("   ✅ {$svc['name']}: $resultMsg");
        $totalUpdated++;
    }
}

// تنظيف سجل المزامنة القديم (الاحتفاظ بآخر 2000 سجل فقط لمنع تضخم الجدول)
$pdo->exec("DELETE FROM sync_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM sync_log ORDER BY id DESC LIMIT 2000) t)");

syncLog("🏁 انتهت المزامنة — تحديث: $totalUpdated · أخطاء: $totalErrors");

flock($fp, LOCK_UN);
