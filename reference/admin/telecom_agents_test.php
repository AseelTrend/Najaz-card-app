<?php
/**
 * telecom_agents_test.php
 * ضع هذا الملف في: admin/telecom_agents_test.php
 * افتحه من المتصفح وأرسل النتيجة
 */
require_once '../includes/config.php';

$results = [];
$errors  = [];

// ─── 1. اتصال DB ─────────────────────────────────────────────────
try {
    $pdo->query("SELECT 1");
    $results['db_connection'] = '✅ اتصال DB يعمل';
} catch(Exception $e) {
    $errors[] = '❌ DB: ' . $e->getMessage();
}

// ─── 2. جداول موجودة؟ ────────────────────────────────────────────
$tables = ['telecom_agents', 'telecom_agent_routes', 'floosak_agent_methods', 'settings', 'users'];
foreach ($tables as $t) {
    try {
        $cnt = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $results["table_$t"] = "✅ جدول $t موجود ($cnt صف)";
    } catch(Exception $e) {
        $errors[] = "❌ جدول $t: " . $e->getMessage();
    }
}

// ─── 3. أعمدة telecom_agents ─────────────────────────────────────
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM telecom_agents")->fetchAll(), 'Field');
    $results['telecom_agents_cols'] = '✅ أعمدة telecom_agents: ' . implode(', ', $cols);
} catch(Exception $e) {
    $errors[] = '❌ SHOW COLUMNS telecom_agents: ' . $e->getMessage();
}

// ─── 4. أعمدة telecom_agent_routes ───────────────────────────────
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM telecom_agent_routes")->fetchAll(), 'Field');
    $results['telecom_routes_cols'] = '✅ أعمدة telecom_agent_routes: ' . implode(', ', $cols);
    if (!in_array('priority', $cols)) {
        $errors[] = '⚠️ عمود priority غير موجود في telecom_agent_routes';
    }
} catch(Exception $e) {
    $errors[] = '❌ SHOW COLUMNS telecom_agent_routes: ' . $e->getMessage();
}

// ─── 5. محتوى telecom_agents ─────────────────────────────────────
try {
    $agents = $pdo->query("SELECT id, name, type, status FROM telecom_agents ORDER BY id")->fetchAll();
    if (empty($agents)) {
        $errors[] = '⚠️ جدول telecom_agents فارغ — لا يوجد وكلاء';
    } else {
        foreach ($agents as $a) {
            $results['agent_' . $a['id']] = "✅ وكيل #{$a['id']}: {$a['name']} ({$a['type']}) — حالة: {$a['status']}";
        }
    }
} catch(Exception $e) {
    $errors[] = '❌ SELECT telecom_agents: ' . $e->getMessage();
}

// ─── 6. روابط يتيمة في telecom_agent_routes ──────────────────────
try {
    $orphans = $pdo->query("
        SELECT r.id, r.method_id, r.agent_id
        FROM telecom_agent_routes r
        LEFT JOIN telecom_agents a ON a.id = r.agent_id
        WHERE a.id IS NULL
    ")->fetchAll();
    if (!empty($orphans)) {
        $ids = implode(', ', array_column($orphans, 'id'));
        $errors[] = "⚠️ روابط يتيمة في telecom_agent_routes (id: $ids) — agent_id غير موجود";
        // محاولة حذفها
        try {
            $pdo->exec("DELETE r FROM telecom_agent_routes r LEFT JOIN telecom_agents a ON a.id=r.agent_id WHERE a.id IS NULL");
            $results['orphan_cleanup'] = '✅ تم حذف الروابط اليتيمة';
        } catch(Exception $e2) {
            $errors[] = '❌ فشل حذف الروابط اليتيمة: ' . $e2->getMessage();
        }
    } else {
        $results['orphan_check'] = '✅ لا توجد روابط يتيمة';
    }
} catch(Exception $e) {
    $errors[] = '❌ فحص الروابط اليتيمة: ' . $e->getMessage();
}

// ─── 7. إعدادات Fore Yemen في settings ───────────────────────────
try {
    $foreDomain = getSetting('fore_domain');
    $foreUserid = getSetting('fore_userid');
    $results['fore_settings'] = '✅ fore_domain: ' . ($foreDomain ?: '[فارغ]') . ' | fore_userid: ' . ($foreUserid ?: '[فارغ]');
    $foreInAgents = $pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='fore'")->fetchColumn();
    $results['fore_in_agents'] = $foreInAgents > 0
        ? "✅ Fore Yemen موجود في telecom_agents ($foreInAgents وكيل)"
        : "⚠️ Fore Yemen غير موجود في telecom_agents";
} catch(Exception $e) {
    $errors[] = '❌ فحص fore settings: ' . $e->getMessage();
}

// ─── 8. إعدادات Floosak في settings ──────────────────────────────
try {
    require_once '../includes/floosak_agent.php';
    $agCfg = floosak_agent_get_config($pdo);
    $faPhone = $agCfg['floosak_agent_phone'] ?? '';
    $results['floosak_settings'] = '✅ floosak_agent_phone: ' . ($faPhone ?: '[فارغ]');
    $floosakInAgents = $pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='floosak_agent'")->fetchColumn();
    $results['floosak_in_agents'] = $floosakInAgents > 0
        ? "✅ Floosak موجود في telecom_agents ($floosakInAgents وكيل)"
        : "⚠️ Floosak غير موجود في telecom_agents";
} catch(Exception $e) {
    $errors[] = '❌ فحص floosak settings: ' . $e->getMessage();
}

// ─── 9. اختبار JOIN الرئيسي ──────────────────────────────────────
try {
    $rows = $pdo->query("
        SELECT r.method_id, r.op_key, r.priority, a.id as agent_id, a.name, a.type
        FROM telecom_agent_routes r
        LEFT JOIN telecom_agents a ON a.id = r.agent_id
        WHERE a.id IS NOT NULL
        ORDER BY r.method_id, r.op_key, r.priority ASC
        LIMIT 5
    ")->fetchAll();
    $results['main_join'] = '✅ JOIN الرئيسي يعمل (' . count($rows) . ' نتيجة)';
} catch(Exception $e) {
    $errors[] = '❌ JOIN الرئيسي فشل: ' . $e->getMessage();
}

// ─── 10. اختبار floosak_agent_methods ────────────────────────────
try {
    $methods = $pdo->query("SELECT COUNT(*) FROM floosak_agent_methods WHERE status=1")->fetchColumn();
    $results['floosak_methods'] = "✅ floosak_agent_methods: $methods شبكة نشطة";
} catch(Exception $e) {
    $errors[] = '❌ floosak_agent_methods: ' . $e->getMessage();
}

// ─── 11. اختبار UNIQUE KEY في telecom_agent_routes ───────────────
try {
    $indexes = $pdo->query("SHOW INDEX FROM telecom_agent_routes")->fetchAll(PDO::FETCH_ASSOC);
    $idxNames = array_unique(array_column($indexes, 'Key_name'));
    $results['routes_indexes'] = '✅ فهارس telecom_agent_routes: ' . implode(', ', $idxNames);
} catch(Exception $e) {
    $errors[] = '❌ SHOW INDEX: ' . $e->getMessage();
}

// ─── 12. محاولة ترحيل Fore إن لم يكن موجوداً ────────────────────
$foreInAgents = (int)$pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='fore'")->fetchColumn();
if ($foreInAgents === 0) {
    try {
        $foreDomain   = getSetting('fore_domain');
        $foreUserid   = getSetting('fore_userid');
        $foreUsername = getSetting('fore_username');
        $forePass     = getSetting('fore_password');
        if (!empty($foreDomain) || !empty($foreUserid)) {
            $foreCfg = json_encode(['domain'=>$foreDomain??'','userid'=>$foreUserid??'','username'=>$foreUsername??'','password'=>$forePass??''],JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO telecom_agents (name,type,config,status,sort_order) VALUES (?,?,?,?,?)")
                ->execute(['Yemen Robot (الرئيسي)','fore',$foreCfg,1,0]);
            $results['migrate_fore'] = '✅ تم ترحيل Fore Yemen تلقائياً';
        } else {
            $results['migrate_fore'] = '⚠️ fore_domain و fore_userid فارغان — لا يمكن الترحيل';
        }
    } catch(Exception $e) {
        $errors[] = '❌ ترحيل Fore: ' . $e->getMessage();
    }
}

// ─── 13. محاولة ترحيل Floosak إن لم يكن موجوداً ─────────────────
$floosakInAgents = (int)$pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='floosak_agent'")->fetchColumn();
if ($floosakInAgents === 0) {
    try {
        $agCfgOld = floosak_agent_get_config($pdo);
        $faPhone = $agCfgOld['floosak_agent_phone'] ?? '';
        if (!empty($faPhone)) {
            $faCfg = json_encode([
                'phone'    => $faPhone,
                'password' => $agCfgOld['floosak_agent_password'] ?? '',
                'api_url'  => $agCfgOld['floosak_agent_api_url']  ?? '',
                'sandbox'  => $agCfgOld['floosak_agent_sandbox']  ?? '1',
            ], JSON_UNESCAPED_UNICODE);
            $faEnabled = $agCfgOld['floosak_agent_enabled'] ?? '0';
            $pdo->prepare("INSERT INTO telecom_agents (name,type,config,status,sort_order) VALUES (?,?,?,?,?)")
                ->execute(['Floosak (الرئيسي)', 'floosak_agent', $faCfg, $faEnabled==='1'?1:0, 1]);
            $results['migrate_floosak'] = '✅ تم ترحيل Floosak تلقائياً';
        } else {
            $results['migrate_floosak'] = '⚠️ floosak_agent_phone فارغ — لا يمكن الترحيل';
        }
    } catch(Exception $e) {
        $errors[] = '❌ ترحيل Floosak: ' . $e->getMessage();
    }
}

// ─── عرض النتائج ─────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تشخيص telecom_agents</title>
<style>
  body { font-family: monospace; background: #0d1117; color: #cdd; padding: 20px; font-size: 14px; }
  h2   { color: #a78bfa; border-bottom: 1px solid #333; padding-bottom: 8px; }
  .ok  { color: #00d4aa; margin: 4px 0; }
  .err { color: #ff6677; margin: 4px 0; background: rgba(255,100,100,.08); padding: 4px 8px; border-radius: 4px; border-right: 3px solid #ff4455; }
  .section { margin: 20px 0; }
  .summary { font-size: 16px; font-weight: bold; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
  .all-ok  { background: rgba(0,212,170,.1); color: #00d4aa; border: 1px solid rgba(0,212,170,.3); }
  .has-err { background: rgba(255,68,85,.1);  color: #ff6677; border: 1px solid rgba(255,68,85,.3); }
</style>
</head>
<body>
<h2>🔍 تشخيص صفحة وكلاء الشحن</h2>

<div class="summary <?=empty($errors)?'all-ok':'has-err'?>">
  <?=empty($errors)
    ? '✅ كل شيء يعمل بشكل صحيح!'
    : '❌ يوجد ' . count($errors) . ' مشكلة — راجع التفاصيل أدناه'?>
</div>

<?php if(!empty($errors)): ?>
<div class="section">
  <h2>❌ الأخطاء (<?=count($errors)?>)</h2>
  <?php foreach($errors as $e): ?>
  <div class="err"><?=htmlspecialchars($e)?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section">
  <h2>✅ النتائج (<?=count($results)?>)</h2>
  <?php foreach($results as $r): ?>
  <div class="ok"><?=htmlspecialchars($r)?></div>
  <?php endforeach; ?>
</div>

<div class="section" style="color:#8895a7;font-size:12px;border-top:1px solid #333;padding-top:10px">
  PHP <?=phpversion()?> | <?=date('Y-m-d H:i:s')?>
</div>
</body>
</html>
