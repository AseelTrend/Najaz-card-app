<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');

// [C-2 FIX] CSRF verification لجميع طلبات POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') { adminCsrfVerify(); }
$IS_ADMIN = isAdmin();
$pageTitle = 'وكلاء الشحن — ' . SITE_NAME;

// ══════════════════════════════════════════════════════════════════
// إنشاء / ترقية الجداول
// ══════════════════════════════════════════════════════════════════
$pdo->exec("CREATE TABLE IF NOT EXISTS `telecom_agents` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL,
  `type`        ENUM('fore','floosak_agent') NOT NULL DEFAULT 'fore',
  `config`      TEXT DEFAULT NULL,
  `status`      TINYINT(1) DEFAULT 1,
  `sort_order`  INT DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// الجدول الجديد: يدعم قائمة مزودين مرتبة بـ priority
$pdo->exec("CREATE TABLE IF NOT EXISTS `telecom_agent_routes` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `method_id`   INT NOT NULL,
  `op_key`      ENUM('amount','fees','bundles') NOT NULL DEFAULT 'amount',
  `agent_id`    INT NOT NULL,
  `priority`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY `uq_route` (`method_id`,`op_key`,`agent_id`),
  KEY `idx_lookup` (`method_id`,`op_key`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ترقية الجدول القديم: أضف عمود priority إن لم يكن موجوداً
try {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM telecom_agent_routes")->fetchAll(), 'Field');
    if (!in_array('priority', $cols)) {
        $pdo->exec("ALTER TABLE telecom_agent_routes ADD COLUMN `priority` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `agent_id`");
        try { $pdo->exec("ALTER TABLE telecom_agent_routes DROP INDEX uq_route"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE telecom_agent_routes ADD UNIQUE KEY `uq_route` (`method_id`,`op_key`,`agent_id`)"); } catch(Exception $e){}
        try { $pdo->exec("ALTER TABLE telecom_agent_routes ADD KEY `idx_lookup` (`method_id`,`op_key`,`priority`)"); } catch(Exception $e){}
        $pdo->exec("DELETE FROM telecom_agent_routes WHERE agent_id IS NULL");
        try { $pdo->exec("ALTER TABLE telecom_agent_routes MODIFY `agent_id` INT NOT NULL"); } catch(Exception $e){}
    }
} catch(Exception $e){}

// ── ترحيل الإعدادات القديمة — يعمل لكل مزود بشكل مستقل ──────────
// Fore Yemen: رحّل إن لم يكن موجوداً بعد
$foreExists = (int)$pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='fore'")->fetchColumn();
if ($foreExists === 0) {
    $foreDomain   = getSetting('fore_domain');
    $foreUserid   = getSetting('fore_userid');
    $foreUsername = getSetting('fore_username');
    $forePass     = getSetting('fore_password');
    $foreEnabled  = getSetting('fore_enabled');
    if (!empty($foreDomain) || !empty($foreUserid)) {
        $foreCfg = json_encode(['domain'=>$foreDomain??'','userid'=>$foreUserid??'','username'=>$foreUsername??'','password'=>$forePass??''],JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO telecom_agents (name,type,config,status,sort_order) VALUES (?,?,?,?,?)")
            ->execute(['Yemen Robot (الرئيسي)','fore',$foreCfg,$foreEnabled==='1'?1:1,0]);
        $foreAgentId = (int)$pdo->lastInsertId();
        foreach ([1,2,3,4,12,13,16,17] as $mid) {
            foreach (['amount','fees','bundles'] as $opKey) {
                if (getSetting("np_{$mid}_{$opKey}") === 'fore') {
                    $pdo->prepare("INSERT IGNORE INTO telecom_agent_routes (method_id,op_key,agent_id,priority) VALUES (?,?,?,?)")
                        ->execute([$mid,$opKey,$foreAgentId,1]);
                }
            }
        }
    }
}

// Floosak Agent: رحّل إن لم يكن موجوداً بعد
$floosakExists = (int)$pdo->query("SELECT COUNT(*) FROM telecom_agents WHERE type='floosak_agent'")->fetchColumn();
if ($floosakExists === 0) {
    $agCfgOld = [];
    try { $agCfgOld = floosak_agent_get_config($pdo); } catch(Exception $e){}
    $faPhone   = $agCfgOld['floosak_agent_phone']    ?? '';
    $faPass    = $agCfgOld['floosak_agent_password'] ?? '';
    $faEnabled = $agCfgOld['floosak_agent_enabled']  ?? '0';
    $faSandbox = $agCfgOld['floosak_agent_sandbox']  ?? '1';
    $faUrl     = $agCfgOld['floosak_agent_api_url']  ?? '';
    if (!empty($faPhone)) {
        $faCfg = json_encode(['phone'=>$faPhone,'password'=>$faPass,'api_url'=>$faUrl,'sandbox'=>$faSandbox],JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO telecom_agents (name,type,config,status,sort_order) VALUES (?,?,?,?,?)")
            ->execute(['Floosak (الرئيسي)','floosak_agent',$faCfg,$faEnabled==='1'?1:0,1]);
        $faAgentId = (int)$pdo->lastInsertId();
        foreach ([1,2,3,4,12,13,16,17] as $mid) {
            foreach (['amount','fees','bundles'] as $opKey) {
                if (getSetting("np_{$mid}_{$opKey}") === 'floosak') {
                    $pdo->prepare("INSERT IGNORE INTO telecom_agent_routes (method_id,op_key,agent_id,priority) VALUES (?,?,?,?)")
                        ->execute([$mid,$opKey,$faAgentId,1]);
                }
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════
// معالجة الطلبات
// ══════════════════════════════════════════════════════════════════

// حفظ/تعديل وكيل
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_agent'])) {
    $aid    = (int)($_POST['agent_id'] ?? 0);
    $name   = trim($_POST['agent_name'] ?? '');
    $type   = in_array($_POST['agent_type']??'',['fore','floosak_agent']) ? $_POST['agent_type'] : 'fore';
    $status = isset($_POST['agent_status']) ? 1 : 0;
    $sort   = (int)($_POST['agent_sort'] ?? 0);
    $cfg = [];
    if ($type === 'fore') {
        $cfg = ['domain'=>trim($_POST['cfg_fore_domain']??''),'userid'=>trim($_POST['cfg_fore_userid']??''),'username'=>trim($_POST['cfg_fore_username']??''),'password'=>trim($_POST['cfg_fore_password']??'')];
    } elseif ($type === 'floosak_agent') {
        $cfg = ['phone'=>preg_replace('/[^0-9]/','', $_POST['cfg_fa_phone']??''),'password'=>trim($_POST['cfg_fa_password']??''),'api_url'=>trim($_POST['cfg_fa_api_url']??''),'sandbox'=>isset($_POST['cfg_fa_sandbox'])?'1':'0'];
    }
    $cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE);
    if ($aid) {
        $pdo->prepare("UPDATE telecom_agents SET name=?,type=?,config=?,status=?,sort_order=? WHERE id=?")->execute([$name,$type,$cfgJson,$status,$sort,$aid]);
        flashMessage('success', "✅ تم تحديث الوكيل: $name");
    } else {
        $pdo->prepare("INSERT INTO telecom_agents (name,type,config,status,sort_order) VALUES (?,?,?,?,?)")->execute([$name,$type,$cfgJson,$status,$sort]);
        flashMessage('success', "✅ تمت إضافة الوكيل: $name");
    }
    redirect(SITE_URL.'/admin/telecom_agents.php');
}

// حذف وكيل
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_agent'])) {
    $aid = (int)$_POST['agent_id'];
    $pdo->prepare("DELETE FROM telecom_agent_routes WHERE agent_id=?")->execute([$aid]);
    $pdo->prepare("DELETE FROM telecom_agents WHERE id=?")->execute([$aid]);
    flashMessage('success', 'تم حذف الوكيل');
    redirect(SITE_URL.'/admin/telecom_agents.php');
}

// حفظ ربط الشبكات — routes[mid][op_key][] = agent_id مرتبة
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_routes'])) {
    $routesPost = $_POST['routes'] ?? [];
    $validOps   = ['amount','fees','bundles'];
    foreach ($routesPost as $mid => $ops) {
        $mid = (int)$mid;
        foreach ($ops as $opKey => $agentIds) {
            if (!in_array($opKey, $validOps)) continue;
            $pdo->prepare("DELETE FROM telecom_agent_routes WHERE method_id=? AND op_key=?")->execute([$mid,$opKey]);
            if (!is_array($agentIds)) continue;
            $priority = 1;
            foreach ($agentIds as $agentId) {
                $agentId = (int)$agentId;
                if ($agentId <= 0) continue;
                $pdo->prepare("INSERT IGNORE INTO telecom_agent_routes (method_id,op_key,agent_id,priority) VALUES (?,?,?,?)")
                    ->execute([$mid,$opKey,$agentId,$priority]);
                $priority++;
            }
        }
    }
    flashMessage('success', '✅ تم حفظ ربط الشبكات');
    redirect(SITE_URL.'/admin/telecom_agents.php');
}

// اختبار وكيل
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['test_agent'])) {
    $aid = (int)$_POST['agent_id'];
    $stmt = $pdo->prepare("SELECT * FROM telecom_agents WHERE id=?"); $stmt->execute([$aid]); $agent=$stmt->fetch();
    $result = ['ok'=>false,'msg'=>'وكيل غير موجود'];
    if ($agent) {
        $cfg = json_decode($agent['config']??'{}',true)??[];
        if ($agent['type']==='fore') {
            require_once '../includes/fore_api.php';
            $api = new ForeYemenAPI($cfg['domain']??'',$cfg['userid']??'',$cfg['username']??'',$cfg['password']??'');
            $r = $api->checkBalance();
            $result = ForeYemenAPI::isSuccess($r)
                ? ['ok'=>true,'msg'=>'✅ متصل — الرصيد: '.($r['balance']??'—')]
                : ['ok'=>false,'msg'=>'❌ '.($r['resultDesc']??'فشل الاتصال')];
        } elseif ($agent['type']==='floosak_agent') {
            require_once '../includes/floosak_agent.php';
            $sandbox = ($cfg['sandbox']??'1')==='1';
            $r = floosak_agent_login($cfg['phone']??'',$cfg['password']??'',$sandbox,$cfg['api_url']??'');
            if (!empty($r['token'])) {
                $pdo->prepare("UPDATE telecom_agents SET config=? WHERE id=?")->execute([
                    json_encode(array_merge($cfg,['token'=>$r['token'],'wallet_id'=>$r['yer_wallet_id']??'','balance'=>$r['yer_balance']??0]),JSON_UNESCAPED_UNICODE), $aid]);
                $result = ['ok'=>true,'msg'=>'✅ متصل — الرصيد: '.number_format($r['yer_balance']??0,2)];
            } else {
                $result = ['ok'=>false,'msg'=>'❌ '.($r['error']??'فشل تسجيل الدخول')];
            }
        }
    }
    header('Content-Type: application/json');
    ob_end_clean();
    echo json_encode($result);
    exit;
}

// ══════════════════════════════════════════════════════════════════
// جلب البيانات
// ══════════════════════════════════════════════════════════════════
$agents = [];
try { $agents = $pdo->query("SELECT * FROM telecom_agents ORDER BY sort_order,id")->fetchAll(); } catch(Exception $e){}

// routeMap[method_id][op_key] = [ [agent_id, priority, name, type], ... ]
$routeMap = [];
// تنظيف الروابط اليتيمة (وكيل محذوف)
try { $pdo->exec("DELETE r FROM telecom_agent_routes r LEFT JOIN telecom_agents a ON a.id=r.agent_id WHERE a.id IS NULL"); } catch(Exception $e){}

$allRoutes = $pdo->query("
    SELECT r.method_id, r.op_key, r.priority, a.id as agent_id, a.name, a.type
    FROM telecom_agent_routes r
    LEFT JOIN telecom_agents a ON a.id = r.agent_id
    WHERE a.id IS NOT NULL
    ORDER BY r.method_id, r.op_key, r.priority ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allRoutes as $row) {
    $routeMap[$row['method_id']][$row['op_key']][] = [
        'agent_id' => (int)$row['agent_id'],
        'priority' => (int)$row['priority'],
        'name'     => $row['name'],
        'type'     => $row['type'],
    ];
}

// جلب الخدمات
$networkGroups = ['TOPUP'=>[],'BILLPAY'=>[]];
try {
    $allMethods = $pdo->query("SELECT method_id,name_ar,color,transaction_type,icon FROM floosak_agent_methods WHERE status=1 ORDER BY transaction_type DESC,sort_order,id")->fetchAll();
    $methodOps = [];
    try {
        $bunchOps = $pdo->query("SELECT DISTINCT method_id, MAX(CASE WHEN section IN('amount','yemen4g_credit') THEN 1 ELSE 0 END) has_amount, MAX(CASE WHEN section='fees' THEN 1 ELSE 0 END) has_fees, MAX(CASE WHEN section IN('bundles','yemen4g_change','yemen4g_internet','yemen4g_voice') THEN 1 ELSE 0 END) has_bundles FROM floosak_agent_bunches WHERE status=1 GROUP BY method_id")->fetchAll();
        foreach ($bunchOps as $bo) {
            $ops = [];
            if ($bo['has_amount'])  $ops['amount']  = 'رصيد مفتوح';
            if ($bo['has_fees'])    $ops['fees']    = 'فئات';
            if ($bo['has_bundles']) $ops['bundles'] = 'باقات';
            if (!empty($ops)) $methodOps[$bo['method_id']] = $ops;
        }
    } catch(Exception $e){}
    foreach ($allMethods as $m) {
        $mid = (int)$m['method_id']; $type = $m['transaction_type'];
        if (!empty($methodOps[$mid])) $ops = $methodOps[$mid];
        elseif ($type==='TOPUP')      $ops = ['amount'=>'رصيد مفتوح'];
        else                          $ops = ['amount'=>'سداد/استعلام'];
        $networkGroups[$type][$mid] = ['name'=>$m['name_ar'],'color'=>$m['color']?:'#6c3fe0','icon'=>$m['icon']?:'sim-card','ops'=>$ops];
    }
} catch(Exception $e) {
    $networkGroups['TOPUP'] = [
        1  => ['name'=>'يمن موبايل','color'=>'#cc0000','icon'=>'sim-card','ops'=>['amount'=>'رصيد مفتوح','fees'=>'فئات','bundles'=>'باقات']],
        2  => ['name'=>'سبأفون',    'color'=>'#ff6600','icon'=>'sim-card','ops'=>['fees'=>'فئات/وحدات','bundles'=>'باقات']],
        3  => ['name'=>'يو',        'color'=>'#0066cc','icon'=>'sim-card','ops'=>['fees'=>'فئات','bundles'=>'باقات']],
        12 => ['name'=>'واي',       'color'=>'#800080','icon'=>'sim-card','ops'=>['fees'=>'فئات','bundles'=>'باقات']],
    ];
}

$editAgent = null;
if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $ea = $pdo->prepare("SELECT * FROM telecom_agents WHERE id=?"); $ea->execute([(int)$_GET['edit']]); $editAgent=$ea->fetch();
}

include 'header.php';
?>

<div class="page-header">
  <h2><i class="fas fa-satellite-dish" style="color:#a78bfa"></i> وكلاء الشحن</h2>
  <a href="?add=1" class="btn btn-primary"><i class="fas fa-plus"></i> وكيل جديد</a>
</div>

<?php if(isset($_GET['add']) || $editAgent): ?>
<!-- ══ نموذج الإضافة/التعديل ══════════════════════════════════ -->
<div class="card mb-2">
  <h3><?=$editAgent?'تعديل الوكيل':'إضافة وكيل جديد'?></h3>
  <form method="POST">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_agent" value="1">
    <input type="hidden" name="agent_id" value="<?=$editAgent?$editAgent['id']:0?>">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.5rem">
      <div class="form-group">
        <label>اسم الوكيل *</label>
        <input type="text" name="agent_name" class="form-control" required value="<?=htmlspecialchars($editAgent?$editAgent['name']:'')?>" placeholder="مثال: Fore Yemen الرئيسي">
      </div>
      <div class="form-group">
        <label>النوع *</label>
        <select name="agent_type" id="agentTypeSelect" class="form-control" onchange="onAgentTypeChange(this.value)">
          <option value="fore"          <?=($editAgent&&$editAgent['type']==='fore')?'selected':''?>>🤖 Yemen Robot (Fore Yemen)</option>
          <option value="floosak_agent" <?=($editAgent&&$editAgent['type']==='floosak_agent')?'selected':''?>>📱 Floosak (وكيل الشحن)</option>
        </select>
      </div>
      <div class="form-group" style="display:flex;gap:1rem;align-items:flex-end">
        <div><label>الترتيب</label><input type="number" name="agent_sort" class="form-control" value="<?=$editAgent?$editAgent['sort_order']:0?>" style="width:90px"></div>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding-bottom:10px">
          <input type="checkbox" name="agent_status" value="1" <?=(!$editAgent||$editAgent['status'])?'checked':''?>> نشط
        </label>
      </div>
    </div>
    <?php
    $ec = $editAgent ? json_decode($editAgent['config']??'{}',true) : [];
    $isFA = $editAgent && $editAgent['type']==='floosak_agent';
    ?>
    <div id="cfg-fore" style="<?=$isFA?'display:none':''?>">
      <div style="background:rgba(0,212,170,.05);border:1px solid rgba(0,212,170,.15);border-radius:12px;padding:1.25rem;margin-bottom:1rem">
        <div style="font-size:.82rem;color:#00d4aa;font-weight:700;margin-bottom:1rem"><i class="fas fa-robot"></i> إعدادات Yemen Robot</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group"><label>Domain</label><input type="url" name="cfg_fore_domain" class="form-control" value="<?=htmlspecialchars($ec['domain']??'')?>" placeholder="https://fore.yemoney.net"></div>
          <div class="form-group"><label>User ID</label><input type="text" name="cfg_fore_userid" class="form-control" value="<?=htmlspecialchars($ec['userid']??'')?>" placeholder="رقم المعرّف"></div>
          <div class="form-group"><label>اسم المستخدم</label><input type="text" name="cfg_fore_username" class="form-control" value="<?=htmlspecialchars($ec['username']??'')?>" placeholder="Username"></div>
          <div class="form-group"><label>كلمة المرور</label><input type="password" name="cfg_fore_password" class="form-control" value="<?=htmlspecialchars($ec['password']??'')?>" placeholder="••••••••"></div>
        </div>
      </div>
    </div>
    <div id="cfg-floosak" style="<?=$isFA?'':'display:none'?>">
      <div style="background:rgba(139,92,246,.05);border:1px solid rgba(139,92,246,.15);border-radius:12px;padding:1.25rem;margin-bottom:1rem">
        <div style="font-size:.82rem;color:#a78bfa;font-weight:700;margin-bottom:1rem"><i class="fas fa-sim-card"></i> إعدادات Floosak Agent</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
          <div class="form-group"><label>رقم الهاتف</label><input type="text" name="cfg_fa_phone" class="form-control" value="<?=htmlspecialchars($ec['phone']??'')?>" placeholder="7xxxxxxxx"></div>
          <div class="form-group"><label>كلمة المرور</label><input type="password" name="cfg_fa_password" class="form-control" value="<?=htmlspecialchars($ec['password']??'')?>" placeholder="••••••••"></div>
          <div class="form-group"><label>API URL</label><input type="url" name="cfg_fa_api_url" class="form-control" value="<?=htmlspecialchars($ec['api_url']??'')?>" placeholder="https://agent.floosak.com"></div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:1.8rem">
            <input type="checkbox" name="cfg_fa_sandbox" id="cfg_fa_sandbox" value="1" <?=($ec['sandbox']??'1')==='1'?'checked':''?>>
            <label for="cfg_fa_sandbox">Sandbox (تجريبي)</label>
          </div>
        </div>
        <?php if($editAgent && $isFA && !empty($ec['balance'])): ?>
        <div style="margin-top:.5rem;font-size:.8rem;color:#8895a7">
          آخر رصيد: <strong style="color:#00d4aa"><?=number_format($ec['balance'],2)?></strong>
          <?php if(!empty($ec['wallet_id'])): ?> | Wallet: <?=htmlspecialchars($ec['wallet_id'])?><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> حفظ</button>
      <?php if($editAgent): ?>
      <button type="button" class="btn btn-info" onclick="testAgent(<?=$editAgent['id']?>)"><i class="fas fa-plug"></i> اختبار الاتصال</button>
      <?php endif; ?>
      <a href="telecom_agents.php" class="btn btn-secondary">إلغاء</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ══ قائمة الوكلاء ══════════════════════════════════════════ -->
<div class="card mb-2">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <span><i class="fas fa-list"></i> الوكلاء المضافون (<?=count($agents)?>)</span>
  </div>
  <?php if(empty($agents)): ?>
  <div style="padding:2rem;text-align:center;color:#8895a7">
    <i class="fas fa-satellite-dish" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px"></i>
    لا يوجد وكلاء — أضف وكيلاً للبدء
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>الاسم</th><th>النوع</th><th>الحالة</th><th>إجراء</th></tr></thead>
      <tbody>
      <?php foreach($agents as $ag):
        $agCfg = json_decode($ag['config']??'{}',true)??[];
      ?>
      <tr>
        <td><?=$ag['id']?></td>
        <td>
          <strong><?=htmlspecialchars($ag['name'])?></strong>
          <?php if($ag['type']==='fore' && !empty($agCfg['domain'])): ?>
          <small style="color:#8895a7;display:block;font-size:.7rem"><?=htmlspecialchars(substr($agCfg['domain'],0,35))?></small>
          <?php elseif($ag['type']==='floosak_agent' && !empty($agCfg['phone'])): ?>
          <small style="color:#8895a7;display:block;font-size:.7rem"><?=htmlspecialchars($agCfg['phone'])?> <?=($agCfg['sandbox']??'1')==='1'?'[sandbox]':''?></small>
          <?php endif; ?>
        </td>
        <td>
          <?php if($ag['type']==='fore'): ?>
          <span style="background:rgba(0,212,170,.12);color:#00d4aa;padding:3px 10px;border-radius:6px;font-size:.78rem"><i class="fas fa-robot"></i> Yemen Robot</span>
          <?php else: ?>
          <span style="background:rgba(139,92,246,.12);color:#a78bfa;padding:3px 10px;border-radius:6px;font-size:.78rem"><i class="fas fa-sim-card"></i> Floosak Agent</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($ag['status']): ?>
          <span style="color:#00d4aa;font-size:.8rem"><i class="fas fa-circle"></i> نشط</span>
          <?php else: ?>
          <span style="color:#ff4455;font-size:.8rem"><i class="fas fa-circle"></i> معطّل</span>
          <?php endif; ?>
          <?php if(!empty($agCfg['balance'])): ?>
          <div style="font-size:.7rem;color:#f5a623">💰 <?=number_format($agCfg['balance'],2)?></div>
          <?php endif; ?>
        </td>
        <td style="display:flex;gap:5px">
          <a href="?edit=<?=$ag['id']?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i></a>
          <button class="btn btn-info btn-sm" onclick="testAgent(<?=$ag['id']?>)" title="اختبار"><i class="fas fa-plug"></i></button>
          <form method="POST" style="display:inline" onsubmit="return confirm('حذف هذا الوكيل؟')">
        <?= adminCsrfField() ?>
            <input type="hidden" name="agent_id" value="<?=$ag['id']?>">
            <button type="submit" name="delete_agent" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ ربط الشبكات — Failover Chain ══════════════════════════ -->
<style>
.routes-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
  gap: 12px;
}
.route-card {
  border-radius: 14px; border: 1px solid var(--border);
  overflow: hidden; transition: box-shadow .2s;
}
.route-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.18); }
.route-card-header {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; background: rgba(255,255,255,.04);
  border-bottom: 1px solid var(--border);
}
.route-card-icon {
  width: 32px; height: 32px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .82rem; flex-shrink: 0;
}
.route-card-body { padding: 8px 10px; display: flex; flex-direction: column; gap: 7px; }

/* صف العملية */
.op-row { display: flex; align-items: center; gap: 6px; }
.op-label { font-size:.72rem; font-weight:700; white-space:nowrap; width:52px; flex-shrink:0; }

/* سلسلة الأولوية */
.priority-chain {
  display: flex; align-items: center; gap: 3px;
  flex: 1; flex-wrap: nowrap; overflow-x: auto;
  min-height: 26px; padding: 1px 0;
}
.priority-chain::-webkit-scrollbar { height: 2px; }
.priority-chain::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 2px; }

/* عنصر مزود في السلسلة */
.priority-item {
  display: flex; align-items: center; gap: 2px;
  background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12);
  border-radius: 6px; padding: 2px 5px 2px 3px;
  font-size: .68rem; cursor: grab; user-select: none;
  white-space: nowrap; flex-shrink: 0;
  transition: background .1s;
}
.priority-item:hover { background: rgba(255,255,255,.12); }
.priority-item:active { cursor: grabbing; opacity: .6; }
.priority-item .pnum {
  background: rgba(255,255,255,.15); border-radius: 3px;
  padding: 0 3px; font-size: .58rem; font-weight: 900;
  color: #8895a7; flex-shrink: 0; min-width: 12px; text-align: center;
}
.priority-item .pname { max-width: 80px; overflow: hidden; text-overflow: ellipsis; }
.priority-item .pdel {
  color: #ff6677; cursor: pointer; font-size: .58rem;
  padding: 0 1px; margin-right: -2px; line-height: 1; flex-shrink: 0;
  opacity: .7;
}
.priority-item .pdel:hover { opacity: 1; color: #ff2233; }

/* سهم Failover بين المزودين */
.fo-arrow { color: #4a5568; font-size: .55rem; flex-shrink: 0; pointer-events: none; }

/* زر إضافة */
.add-agent-btn {
  display: flex; align-items: center; gap: 2px;
  font-size: .65rem; color: #8895a7;
  background: rgba(255,255,255,.03);
  border: 1px dashed rgba(255,255,255,.15);
  border-radius: 6px; padding: 2px 7px;
  cursor: pointer; white-space: nowrap; flex-shrink: 0;
  transition: border-color .15s, color .15s;
}
.add-agent-btn:hover { border-color: #6ba3ff; color: #6ba3ff; }

/* شارات */
.badge-linked   { font-size:.6rem; background:rgba(0,212,170,.12); color:#00d4aa; padding:1px 6px; border-radius:5px; margin-right:auto; flex-shrink:0; }
.badge-unlinked { font-size:.6rem; background:rgba(255,68,85,.08);  color:#ff4455; padding:1px 6px; border-radius:5px; margin-right:auto; flex-shrink:0; }

.group-divider {
  font-size:.78rem; font-weight:900; color:#8895a7; text-transform:uppercase;
  letter-spacing:.08em; padding:6px 2px 8px; border-bottom:1px solid var(--border);
  margin-bottom:10px; display:flex; align-items:center; gap:8px;
}
.group-divider .cnt { background:rgba(255,255,255,.07); border-radius:20px; padding:1px 9px; font-size:.7rem; color:#aab; }

/* Picker dropdown */
.agent-picker-wrap { position: relative; }
.agent-picker {
  position: absolute; z-index: 9999; bottom: calc(100% + 4px); left: 0;
  background: var(--card-bg, #1e2532);
  border: 1px solid var(--border); border-radius: 10px;
  padding: 4px; min-width: 160px;
  box-shadow: 0 8px 24px rgba(0,0,0,.4);
  display: none;
}
.agent-picker.open { display: block; }
.agent-picker-item {
  display: flex; align-items: center; gap: 6px;
  padding: 5px 10px; border-radius: 7px; cursor: pointer;
  font-size: .74rem; white-space: nowrap;
  transition: background .12s;
}
.agent-picker-item:hover { background: rgba(255,255,255,.08); }
.agent-picker-item.used { opacity: .35; pointer-events: none; }
</style>

<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span><i class="fas fa-network-wired"></i> ربط الشبكات بالمزودين</span>
    <span style="font-size:.72rem;color:#8895a7;display:flex;align-items:center;gap:5px">
      <i class="fas fa-info-circle"></i>
      الأول أساسي — عند الفشل ينتقل للتالي تلقائياً (Failover)
    </span>
  </div>
  <div class="card-body">
    <?php if(empty($agents)): ?>
    <p style="color:#8895a7">أضف وكيلاً أولاً ثم ارجع لهذه الصفحة لربط الشبكات.</p>
    <?php else: ?>
    <form method="POST" id="routesForm">
        <?= adminCsrfField() ?>
    <input type="hidden" name="save_routes" value="1">

    <?php
    $groupLabels = [
      'TOPUP'   => ['label'=>'شبكات الشحن',           'icon'=>'fas fa-signal',       'color'=>'#6ba3ff'],
      'BILLPAY' => ['label'=>'خدمات السداد والفواتير', 'icon'=>'fas fa-file-invoice', 'color'=>'#f5a623'],
    ];
    $opMeta = [
      'amount'  => ['💰','رصيد','#00d4aa'],
      'fees'    => ['🏷️','فئات','#f5a623'],
      'bundles' => ['📦','باقات','#6ba3ff'],
    ];
    foreach($networkGroups as $groupType => $groupMethods):
      if(empty($groupMethods)) continue;
      $gl = $groupLabels[$groupType] ?? ['label'=>$groupType,'icon'=>'fas fa-circle','color'=>'#8895a7'];
    ?>
    <div style="margin-bottom:2rem">
      <div class="group-divider">
        <i class="<?=$gl['icon']?>" style="color:<?=$gl['color']?>"></i>
        <?=$gl['label']?>
        <span class="cnt"><?=count($groupMethods)?></span>
      </div>
      <div class="routes-grid">
      <?php foreach($groupMethods as $mid => $net):
        $hasAny = false;
        foreach($net['ops'] as $opKey=>$_) { if(!empty($routeMap[$mid][$opKey])) $hasAny=true; }
      ?>
      <div class="route-card">
        <div class="route-card-header">
          <div class="route-card-icon" style="background:<?=htmlspecialchars($net['color'])?>">
            <i class="fas fa-<?=htmlspecialchars($net['icon'])?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-weight:900;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($net['name'])?></div>
            <div style="font-size:.62rem;color:#8895a7">ID: <?=$mid?></div>
          </div>
          <?=$hasAny?'<span class="badge-linked">✓ مربوط</span>':'<span class="badge-unlinked">غير مربوط</span>'?>
        </div>
        <div class="route-card-body">
        <?php foreach($net['ops'] as $opKey => $opLabel):
          [$opIc,$opNm,$opCl] = $opMeta[$opKey] ?? ['⚙️',$opLabel,'#8895a7'];
          $chain = $routeMap[$mid][$opKey] ?? [];
          $chainJson = htmlspecialchars(json_encode($chain, JSON_UNESCAPED_UNICODE));
        ?>
        <div class="op-row">
          <div class="op-label" style="color:<?=htmlspecialchars($opCl)?>"><?=$opIc?> <?=$opNm?></div>

          <!-- سلسلة Failover -->
          <div class="priority-chain"
               data-mid="<?=$mid?>"
               data-op="<?=$opKey?>"
               data-chain='<?=$chainJson?>'
               ondragover="dragOver(event)"
               ondrop="onDrop(event,this)">
            <!-- تُبنى بـ JS -->
          </div>

          <!-- زر إضافة مزود -->
          <div class="agent-picker-wrap">
            <button type="button" class="add-agent-btn" onclick="togglePicker(this)">
              <i class="fas fa-plus" style="font-size:.55rem"></i> مزود
            </button>
            <div class="agent-picker">
              <?php foreach($agents as $ag): ?>
              <div class="agent-picker-item"
                   data-agent-id="<?=$ag['id']?>"
                   data-agent-name="<?=htmlspecialchars($ag['name'])?>"
                   data-agent-type="<?=$ag['type']?>"
                   onclick="addAgentToChain(this)">
                <span><?=$ag['type']==='fore'?'🤖':'📱'?></span>
                <span><?=htmlspecialchars($ag['name'])?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border)">
      <button type="button" class="btn btn-primary" onclick="submitRoutes()">
        <i class="fas fa-save"></i> حفظ الربط
      </button>
    </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<script>
const AGENTS = <?=json_encode(array_map(fn($a)=>['id'=>(int)$a['id'],'name'=>$a['name'],'type'=>$a['type']], $agents), JSON_UNESCAPED_UNICODE)?>;

// ── بناء الواجهة عند التحميل ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.priority-chain').forEach(chain => {
    const data = JSON.parse(chain.dataset.chain || '[]');
    data.forEach(item => appendChainItem(chain, item.agent_id, item.name, item.type));
  });
});

// ── helpers ──────────────────────────────────────────────────
function agentInfo(id) {
  return AGENTS.find(x => x.id === parseInt(id)) || {name:'?', type:'fore'};
}

// ── إضافة عنصر للسلسلة ──────────────────────────────────────
function appendChainItem(chainEl, agentId, name, type) {
  // منع التكرار
  if (chainEl.querySelector(`.priority-item[data-agent-id="${agentId}"]`)) return;

  // سهم فاصل
  if (chainEl.querySelector('.priority-item')) {
    const arrow = document.createElement('span');
    arrow.className = 'fo-arrow';
    arrow.innerHTML = '<i class="fas fa-chevron-left"></i>';
    chainEl.appendChild(arrow);
  }

  const item = document.createElement('div');
  item.className = 'priority-item';
  item.draggable = true;
  item.dataset.agentId = agentId;
  item.innerHTML = `<span class="pnum">?</span><span style="font-size:.65rem">${type==='fore'?'🤖':'📱'}</span><span class="pname">${escHtml(name||agentInfo(agentId).name)}</span><span class="pdel" onclick="removeChainItem(this)">✕</span>`;
  item.addEventListener('dragstart', e => { dragSrc = item; e.dataTransfer.effectAllowed='move'; });
  chainEl.appendChild(item);
  reNumberChain(chainEl);
}

// ── إعادة الترقيم والفواصل ───────────────────────────────────
function reNumberChain(chainEl) {
  chainEl.querySelectorAll('.fo-arrow').forEach(e => e.remove());
  const items = [...chainEl.querySelectorAll('.priority-item')];
  items.forEach((item, i) => {
    item.querySelector('.pnum').textContent = i + 1;
    if (i > 0) {
      const arrow = document.createElement('span');
      arrow.className = 'fo-arrow';
      arrow.innerHTML = '<i class="fas fa-chevron-left"></i>';
      chainEl.insertBefore(arrow, item);
    }
  });
  // تحديث شارة البطاقة
  const card = chainEl.closest('.route-card');
  if (card) {
    const anyLinked = !!card.querySelector('.priority-item');
    const badge = card.querySelector('.badge-linked, .badge-unlinked');
    if (badge) {
      badge.className = anyLinked ? 'badge-linked' : 'badge-unlinked';
      badge.textContent = anyLinked ? '✓ مربوط' : 'غير مربوط';
    }
  }
}

// ── حذف عنصر ─────────────────────────────────────────────────
function removeChainItem(delBtn) {
  const item  = delBtn.closest('.priority-item');
  const chain = item.closest('.priority-chain');
  item.remove();
  reNumberChain(chain);
}

// ── Drag & Drop ───────────────────────────────────────────────
let dragSrc = null;
function dragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect='move'; }
function onDrop(e, chainEl) {
  e.preventDefault();
  if (!dragSrc || dragSrc.closest('.priority-chain') !== chainEl) return;
  const after = getDropTarget(chainEl, e.clientX);
  if (after) chainEl.insertBefore(dragSrc, after);
  else        chainEl.appendChild(dragSrc);
  reNumberChain(chainEl);
  dragSrc = null;
}
function getDropTarget(container, x) {
  const items = [...container.querySelectorAll('.priority-item')].filter(el => el !== dragSrc);
  return items.find(el => {
    const box = el.getBoundingClientRect();
    return x > box.left && x < box.left + box.width / 2;
  }) || null;
}

// ── Picker ────────────────────────────────────────────────────
function togglePicker(btn) {
  const picker = btn.nextElementSibling;
  const wasOpen = picker.classList.contains('open');
  document.querySelectorAll('.agent-picker.open').forEach(p => p.classList.remove('open'));
  if (!wasOpen) {
    const chain   = btn.closest('.op-row').querySelector('.priority-chain');
    const usedIds = new Set([...chain.querySelectorAll('.priority-item')].map(el => el.dataset.agentId));
    picker.querySelectorAll('.agent-picker-item').forEach(it => {
      it.classList.toggle('used', usedIds.has(it.dataset.agentId));
    });
    picker.classList.add('open');
  }
}
function addAgentToChain(pickerItem) {
  const { agentId, agentName, agentType } = pickerItem.dataset;
  const chain = pickerItem.closest('.op-row').querySelector('.priority-chain');
  appendChainItem(chain, agentId, agentName, agentType);
  pickerItem.closest('.agent-picker').classList.remove('open');
}
document.addEventListener('click', e => {
  if (!e.target.closest('.agent-picker-wrap'))
    document.querySelectorAll('.agent-picker.open').forEach(p => p.classList.remove('open'));
});

// ── إرسال النموذج ────────────────────────────────────────────
function submitRoutes() {
  const form = document.getElementById('routesForm');
  form.querySelectorAll('.dyn-inp').forEach(e => e.remove());
  document.querySelectorAll('.priority-chain').forEach(chain => {
    const mid = chain.dataset.mid;
    const op  = chain.dataset.op;
    chain.querySelectorAll('.priority-item').forEach(item => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = `routes[${mid}][${op}][]`;
      inp.value = item.dataset.agentId;
      inp.className = 'dyn-inp';
      form.appendChild(inp);
    });
  });
  form.submit();
}

// ── دوال عامة ────────────────────────────────────────────────
function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function onAgentTypeChange(type) {
  document.getElementById('cfg-fore').style.display    = type==='fore'           ? '' : 'none';
  document.getElementById('cfg-floosak').style.display = type==='floosak_agent' ? '' : 'none';
}
function testAgent(id) {
  const btn = event.target.closest('button');
  const orig = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  btn.disabled = true;
  const fd = new FormData();
  fd.append('test_agent','1'); fd.append('agent_id',id);
  fetch('<?=SITE_URL?>/admin/telecom_agents.php', {method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{ alert(d.msg); btn.innerHTML=orig; btn.disabled=false; })
    .catch(()=>{ alert('خطأ في الاتصال'); btn.innerHTML=orig; btn.disabled=false; });
}
</script>

<?php include 'footer.php'; ?>
