<?php
/**
 * صفحة خدمات الاتصالات — Floosak Agent
 * شحن رصيد | دفع فواتير | باقات | فحص رصيد
 */
require_once 'includes/config.php';
require_once 'includes/floosak_agent.php';
require_once 'includes/telegram_internal_auth.php';

/**
 * جلب الوكيل المناسب لشبكة وعملية معينة
 * يرجع ['agent'=>row, 'config'=>[]] أو null
 */
function getAgentForMethod(PDO $pdo, int $methodId, string $opKey): ?array {
    try {
        $st = $pdo->prepare("
            SELECT a.* FROM telecom_agents a
            JOIN telecom_agent_routes r ON r.agent_id=a.id
            WHERE r.method_id=? AND r.op_key=? AND a.status=1
            LIMIT 1
        ");
        $st->execute([$methodId, $opKey]);
        $agent = $st->fetch();
        if (!$agent) return null;
        $cfg = json_decode($agent['config']??'{}', true) ?? [];
        return ['agent'=>$agent, 'config'=>$cfg];
    } catch(Exception $e) { return null; }
}

// ── Fore Yemen endpoint لكل method_id ───────────────────────
// الشبكات المدعومة في Fore Yemen فقط
const FORE_SUPPORTED_METHODS = [1, 2, 3, 12, 16, 4, 13];

function fore_endpoint_for_method(int $methodId): string {
    $map = [
        1  => 'yem',        // يمن موبايل
        2  => 'sabaphone',  // سبأفون
        3  => 'mtn',        // يو (MTN)
        12 => 'why',        // واي
        16 => 'yem',        // يمن فورجي
        4  => 'post',       // ADSL
        13 => 'post',       // الخط الثابت
        // 17 عدن نت — غير مدعوم في Fore، يستخدم فلوسك دائماً
    ];
    return $map[$methodId] ?? '';
}

$pageTitle = SITE_NAME . ' - خدمات الاتصالات';

function netIconHtml($m, $size=44, $radius=12): string {
    $color = $m['color'] ?? '#6c3fe0'; $icon = $m['icon'] ?? 'sim-card';
    $logo  = $m['logo'] ?? ''; $sUrl = SITE_URL;
    if ($logo) return '<div style="width:'.$size.'px;height:'.$size.'px;border-radius:'.$radius.'px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.25)"><img src="'.$sUrl.'/'.$logo.'" style="width:100%;height:100%;object-fit:contain;padding:3px"></div>';
    return '<div class="method-icon" style="background:'.htmlspecialchars($color).';width:'.$size.'px;height:'.$size.'px;border-radius:'.$radius.'px"><i class="fas fa-'.htmlspecialchars($icon).'"></i></div>';
}

if (!isLoggedIn() && !telegramAuthorizeInternalRequest($pdo)) {
    header('Location: ' . SITE_URL . '/mobile.php');
    exit;
}

$user        = getUser();
$userBalance = (float)($user['balance'] ?? 0);
$userName    = $user['full_name'] ?: $user['username'];
$currSymbol  = getSetting('currency_symbol') ?: '$';
$siteName    = getSetting('site_name') ?: SITE_NAME;
$siteLogo    = getSetting('site_logo') ?: '';
$siteLogoUrl = $siteLogo ? SITE_URL . '/' . $siteLogo : '';

$agCfg     = floosak_agent_get_config($pdo);
$agEnabled = !empty($agCfg['floosak_agent_enabled']) && $agCfg['floosak_agent_enabled'] === '1';

$methods = [];
$bunches = [];
try {
    try { $pdo->exec("ALTER TABLE floosak_agent_methods ADD COLUMN IF NOT EXISTS `logo` VARCHAR(300) DEFAULT NULL"); } catch(Exception $e){}
    $methods = $pdo->query("SELECT * FROM floosak_agent_methods WHERE status=1 ORDER BY sort_order,id")->fetchAll();
    $bunches_raw = $pdo->query("SELECT id,bunch_id,bunch_name,code,unified_code,price,validity,is_free_amount,payment_type,section,bundle_group FROM floosak_agent_bunches WHERE status=1 ORDER BY method_id, section, sort_order, id")->fetchAll();

    foreach ($bunches_raw as $b) { $bunches[$b['method_id']][] = $b; }
} catch (Exception $e) {}

// جلب الحقول الديناميكية — خارج try الرئيسي
$methodDynFields = [];
try {
    $dfStmt = $pdo->query("SELECT f.*, m.method_id as m_method_id FROM fa_method_fields f JOIN floosak_agent_methods m ON f.method_row_id=m.id WHERE f.is_enabled=1 ORDER BY f.sort_order,f.id");
    if ($dfStmt) {
        foreach ($dfStmt->fetchAll() as $df) {
            $methodDynFields[(int)$df['m_method_id']][] = $df;
        }
    }
} catch(Exception $e) { /* الجدول غير موجود */ }


// دالة عرض أيقونة أو صورة لعنصر سداد
function sadadIconHtml(string $icon, string $color, ?string $image, string $style='width:42px;height:42px;border-radius:13px'): string {
    if ($image && file_exists(__DIR__.'/'.$image)) {
        return '<div style="'.$style.';overflow:hidden;flex-shrink:0;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2)">'
            .'<img src="'.SITE_URL.'/'.htmlspecialchars($image).'" style="width:100%;height:100%;object-fit:cover"></div>';
    }
    return '<div style="'.$style.';display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.25);background:'.htmlspecialchars($color).'">'
        .'<i class="fas fa-'.htmlspecialchars($icon).'"></i></div>';
}

$topupMethods   = array_filter($methods, fn($m) => $m['transaction_type'] === 'TOPUP');
$billpayMethods = array_filter($methods, fn($m) => $m['transaction_type'] === 'BILLPAY');

$recentTx = [];
try {
    $stmt = $pdo->prepare("SELECT t.*, m.name_ar as method_name, m.color as method_color FROM floosak_agent_transactions t LEFT JOIN floosak_agent_methods m ON t.method_id = m.method_id WHERE t.user_id = ? ORDER BY t.created_at DESC LIMIT 10");
    $stmt->execute([$user['id']]);
    $recentTx = $stmt->fetchAll();
} catch (Exception $e) {}

// ── جلب قائمة سداد ──────────────────────────────────────────
$sadadTree = [];
try {
    // أنشئ الجداول تلقائياً إذا لم تكن موجودة
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sadad_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `parent_id` INT DEFAULT NULL,
        `name_ar` VARCHAR(100) NOT NULL, `icon` VARCHAR(50) DEFAULT 'list',
        `color` VARCHAR(30) DEFAULT '#6c3fe0', `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sadad_services` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `category_id` INT NOT NULL,
        `name_ar` VARCHAR(100) NOT NULL, `icon` VARCHAR(50) DEFAULT 'file-invoice',
        `color` VARCHAR(30) DEFAULT '#f5a623', `method_id` INT DEFAULT NULL,
        `action` ENUM('bill','external','none') DEFAULT 'bill',
        `action_value` VARCHAR(500) DEFAULT NULL, `sort_order` INT DEFAULT 0,
        `status` TINYINT(1) DEFAULT 1, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $allSadadCats = $pdo->query("SELECT * FROM sadad_categories WHERE status=1 ORDER BY sort_order,id")->fetchAll();
    $allSadadSvcs = $pdo->query("SELECT * FROM sadad_services WHERE status=1 ORDER BY sort_order,id")->fetchAll();
    // بناء الشجرة
    $sadadById = [];
    foreach ($allSadadCats as $c) {
        $sadadById[$c['id']] = $c + ['children'=>[],'services'=>[]];
    }
    foreach ($allSadadSvcs as $s) {
        if (isset($sadadById[$s['category_id']])) {
            $sadadById[$s['category_id']]['services'][] = $s;
        }
    }
    // جمع IDs الأقسام الفرعية
    $subCatIds = [];
    foreach ($sadadById as $cid => $c) {
        if ($c['parent_id']) $subCatIds[] = $cid;
    }
    // ربط الأقسام الفرعية بآبائها
    foreach ($sadadById as $cid => &$c) {
        if ($c['parent_id'] && isset($sadadById[$c['parent_id']])) {
            $sadadById[$c['parent_id']]['children'][] = &$c;
        }
    }
    unset($c);
    // إزالة الخدمات المكررة من القسم الرئيسي
    // (خدمة تنتمي لقسم فرعي لا تُعرض مرة أخرى في القسم الرئيسي)
    foreach ($sadadById as $cid => &$cat) {
        if (!$cat['parent_id'] && !empty($cat['children'])) {
            $cat['services'] = array_filter(
                $cat['services'],
                fn($s) => !in_array($s['category_id'], $subCatIds)
            );
        }
    }
    unset($cat);
    // فقط الأقسام الرئيسية مرتّبة حسب sort_order
    $sadadTree = array_filter($sadadById, fn($c)=>!$c['parent_id']);
    usort($sadadTree, fn($a,$b)=>($a['sort_order']??0)<=>($b['sort_order']??0) ?: $a['id']<=>$b['id']);
    // رتّب children و services لكل قسم
    foreach ($sadadTree as &$cat) {
        usort($cat['children'], fn($a,$b)=>($a['sort_order']??0)<=>($b['sort_order']??0) ?: $a['id']<=>$b['id']);
        usort($cat['services'], fn($a,$b)=>($a['sort_order']??0)<=>($b['sort_order']??0) ?: $a['id']<=>$b['id']);
        foreach ($cat['children'] as &$sub) {
            usort($sub['services'], fn($a,$b)=>($a['sort_order']??0)<=>($b['sort_order']??0) ?: $a['id']<=>$b['id']);
        }
        unset($sub);
    }
    unset($cat);
} catch (Exception $e) {}

// ── معالجة AJAX ─────────────────────────────────────────────
// جلب حقول المزود للفاتورة
if (isset($_GET['get_bill_fields'])) {
    header('Content-Type: application/json');
    $mid = (int)($_GET['method_id'] ?? 0);
    $fields = [];
    try {
        $st = $pdo->query("SELECT f.* FROM fa_method_fields f JOIN floosak_agent_methods m ON f.method_row_id=m.id WHERE m.method_id=$mid AND f.is_enabled=1 ORDER BY f.sort_order,f.id");
        if ($st) $fields = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
    echo json_encode(['ok'=>true,'fields'=>$fields], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['ajax_action']);
    function jr2(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

    if (!$agEnabled) jr2(['ok' => false, 'msg' => 'الخدمة غير متاحة حالياً']);

    if ($action === 'do_topup') {
        $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
        $methodId     = (int)($_POST['method_id']   ?? 0);
        $bunchId      = trim($_POST['bunch_id']      ?? '');
        $amount       = (float)($_POST['amount']     ?? 0);
        $withSolfa    = (int)($_POST['with_solfa']   ?? 0);

        if (strlen($targetNumber) < 7) jr2(['ok'=>false,'msg'=>'رقم الهاتف غير صالح']);
        if (!$methodId)                 jr2(['ok'=>false,'msg'=>'اختر المزود']);
        if ($bunchId === '')            jr2(['ok'=>false,'msg'=>'اختر الباقة أو أدخل المبلغ']);
        if ($amount <= 0)               jr2(['ok'=>false,'msg'=>'المبلغ يجب أن يكون أكبر من صفر']);

        $rate = 0;
        try {
            $rate = (float)(getSetting('telecom_sadad_rate') ?: getSetting('telecom_exchange_rate') ?: 1700);
        } catch (Throwable $e) {}
        $costUsd = $rate > 0 ? round($amount / $rate, 6) : 0;
        if ($costUsd <= 0) jr2(['ok'=>false,'msg'=>'لم يتم ضبط سعر صرف كابينة السداد — تواصل مع الدعم']);

        // تحديث الرصيد الحالي
        $freshUser = $pdo->prepare("SELECT balance FROM users WHERE id=?");
        $freshUser->execute([$user['id']]);
        $freshBal = (float)($freshUser->fetchColumn() ?? 0);
        if ($freshBal < $costUsd) jr2(['ok'=>false,'msg'=>'رصيدك غير كافٍ — رصيدك: '.number_format($freshBal,4).' '.$currSymbol]);

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $upd->execute([$costUsd, $user['id'], $costUsd]);
            if (!$upd->rowCount()) { $pdo->rollBack(); jr2(['ok'=>false,'msg'=>'رصيدك غير كافٍ']); }

            // اختر المزود حسب إعداد الشبكة
            // تحديد نوع العملية حسب الباقة المختارة
            $foreEndpoint = fore_endpoint_for_method($methodId);
            $opSection    = '';
            try {
                $bCheck = $pdo->prepare("SELECT section FROM floosak_agent_bunches WHERE (unified_code=? OR id=?) AND method_id=? LIMIT 1");
                $bCheck->execute([$bunchId,(int)$bunchId,$methodId]);
                $bCheckRow = $bCheck->fetch();
                $opSection = $bCheckRow['section'] ?? '';
            } catch(Exception $e){}

            // تحويل section → opKey للإعداد
            if (in_array($opSection,['amount','yemen4g_credit']))                       $opKey = 'amount';
            elseif ($opSection === 'fees')                                               $opKey = 'fees';
            elseif (in_array($opSection,['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice'])) $opKey = 'bundles';
            else                                                                         $opKey = 'amount'; // افتراضي

            // ── جلب الوكيل من الجدول الجديد أو fallback للإعداد القديم ──
            $agentRow = getAgentForMethod($pdo, $methodId, $opKey);

            if ($agentRow) {
                $networkProvider = $agentRow['agent']['type'] === 'fore' ? 'fore' : 'floosak';
            } else {
                // fallback للإعداد القديم
                $networkProvider = getSetting("np_{$methodId}_{$opKey}");
            }

            if ($networkProvider === '' || $networkProvider === null) {
                $pdo->rollBack();
                jr2(['ok'=>false,'msg'=>'لم يتم تحديد مزود الشحن لهذه العملية — يرجى الإعداد من لوحة وكلاء الشحن']);
            }

            if (empty($foreEndpoint) && $networkProvider === 'fore') {
                $pdo->rollBack();
                jr2(['ok'=>false,'msg'=>'هذه الشبكة غير مدعومة في Fore Yemen — استخدم فلوسك']);
            }

            if ($networkProvider === 'fore') {
                require_once __DIR__.'/includes/fore_api.php';
                // استخدم credentials الوكيل المحدد أو الإعداد العام
                if ($agentRow && $agentRow['agent']['type']==='fore') {
                    $cfg = $agentRow['config'];
                    $foreApi = new ForeYemenAPI($cfg['domain']??'',$cfg['userid']??'',$cfg['username']??'',$cfg['password']??'');
                } else {
                    $foreApi = getTelecomAPI($pdo);
                }
                if (!$foreApi) { $pdo->rollBack(); jr2(['ok'=>false,'msg'=>'Fore Yemen غير مفعّل — تحقق من الإعدادات']); }

                $endpoint = fore_endpoint_for_method($methodId);
                $netRow   = ['fore_endpoint' => $endpoint];

                // جلب section الباقة من DB لتحديد نوع العملية
                $bunchSection = '';
                $bunchUnifiedCode = $bunchId;
                try {
                    $bRow = $pdo->prepare("SELECT section, unified_code, code, price, fore_num FROM floosak_agent_bunches WHERE (unified_code=? OR id=?) AND method_id=? LIMIT 1");
                    $bRow->execute([$bunchId, (int)$bunchId, $methodId]);
                    $bRow = $bRow->fetch();
                    if ($bRow) {
                        $bunchSection     = $bRow['section'] ?? '';
                        // fore_num له الأولوية، ثم unified_code، ثم code
                        $bunchUnifiedCode = $bRow['fore_num'] ?: $bRow['unified_code'] ?: $bRow['code'] ?: $bunchId;
                    }
                } catch(Exception $e){}

                // اختر الدالة المناسبة حسب نوع الباقة
                if ($bunchSection === 'amount' || $bunchSection === 'yemen4g_credit' || $bunchSection === '') {
                    // رصيد مفتوح — أرسل amount
                    $postType = ($methodId === 4) ? 'adsl' : 'line';
                    $foreRes  = $foreApi->billBalance($netRow, $targetNumber, $amount, $postType);

                } elseif ($bunchSection === 'fees') {
                    // فئة بسعر ثابت — أرسل num (رقم الباقة/الوحدات)
                    if ($endpoint === 'yem') {
                        // يمن موبايل فئات → رصيد بالمبلغ
                        $foreRes = $foreApi->yemenMobileBill($targetNumber, $amount);
                    } elseif ($endpoint === 'why') {
                        $foreRes = $foreApi->whyBill($targetNumber, (int)$bunchUnifiedCode);
                    } elseif ($endpoint === 'sabaphone') {
                        $foreRes = $foreApi->sabaphoneBill($targetNumber, (int)$bunchUnifiedCode);
                    } elseif ($endpoint === 'mtn') {
                        $foreRes = $foreApi->mtnBill($targetNumber, (int)$bunchUnifiedCode);
                    } else {
                        $postType = ($methodId === 4) ? 'adsl' : 'line';
                        $foreRes  = $foreApi->billBalance($netRow, $targetNumber, $amount, $postType);
                    }

                } else {
                    // باقة (bundles, yemen4g_change, yemen4g_internet, yemen4g_voice)
                    if ($endpoint === 'yem') {
                        // يمن موبايل — خطوتان:
                        // 1) شحن قيمة الباقة كرصيد أولاً
                        $billRes = $foreApi->yemenMobileBill($targetNumber, $amount);
                        if (!ForeYemenAPI::isSuccess($billRes)) {
                            $pdo->rollBack();
                            jr2(['ok'=>false,'msg'=>'فشل شحن قيمة الباقة: '.($billRes['resultDesc']??'خطأ')]);
                        }
                        // 2) تفعيل الباقة
                        $foreRes = $foreApi->yemenMobileBillOffer($targetNumber, $bunchUnifiedCode, 'New');
                        // إذا فشل التفعيل أضف رسالة واضحة
                        if (!ForeYemenAPI::isSuccess($foreRes)) {
                            // الرصيد شُحن لكن الباقة لم تُفعَّل — سجّل وأبلّغ
                            $foreRes['resultDesc'] = 'تم شحن الرصيد لكن فشل تفعيل الباقة: '.($foreRes['resultDesc']??'');
                        }
                    } elseif ($endpoint === 'sabaphone') {
                        $foreRes = $foreApi->sabaphoneOffer($targetNumber, (int)$bunchUnifiedCode);
                    } elseif ($endpoint === 'why') {
                        $foreRes = $foreApi->whyBill($targetNumber, (int)$bunchUnifiedCode);
                    } elseif ($endpoint === 'mtn') {
                        $foreRes = $foreApi->mtnBill($targetNumber, (int)$bunchUnifiedCode);
                    } else {
                        $foreRes = $foreApi->billBalance($netRow, $targetNumber, $amount);
                    }
                }

                $res = [
                    'ok'         => ForeYemenAPI::isSuccess($foreRes),
                    'message'    => $foreRes['resultDesc'] ?? ($foreRes['resultCode'] ?? 'خطأ غير معروف'),
                    'request_id' => $foreRes['transid']   ?? ForeYemenAPI::genTransid(),
                ];
            } else {
                // فلوسك Agent
                $res = floosak_agent_topup($pdo, $targetNumber, $amount, $methodId, $bunchId, $withSolfa);
            }

            if (!$res['ok']) { $pdo->rollBack(); jr2(['ok'=>false,'msg'=>$res['message'] ?: 'فشل تنفيذ الشحن']); }

            $newBal = $freshBal - $costUsd;
            try {
                $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?)")
                    ->execute([$user['id'],'debit',$costUsd,$freshBal,$newBal,'شحن اتصالات — '.$targetNumber.' — '.number_format($amount).' ر.ي']);
            } catch(Exception $e){}
            $pdo->commit();
            jr2(['ok'=>true,'msg'=>'✅ تم الشحن بنجاح!','request_id'=>$res['request_id'],'new_balance'=>$newBal]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jr2(['ok'=>false,'msg'=>'خطأ داخلي — تواصل مع الدعم']);
        }
    }

    if ($action === 'check_service') {
        $targetNumber = preg_replace('/[^0-9]/', '', $_POST['target_number'] ?? '');
        $methodId     = (int)($_POST['method_id'] ?? 0);
        $bunchId      = trim($_POST['bunch_id']   ?? '340');
        if (strlen($targetNumber) < 7) jr2(['ok'=>false,'msg'=>'رقم الهاتف غير صالح']);
        if (!$methodId)                 jr2(['ok'=>false,'msg'=>'اختر الشبكة']);

        // جلب المزود من الجدول الجديد أو fallback
        $csAgentRow = getAgentForMethod($pdo, $methodId, 'amount');
        if ($csAgentRow) {
            $networkProvider = $csAgentRow['agent']['type'] === 'fore' ? 'fore' : 'floosak';
        } else {
            $networkProvider = getSetting("network_{$methodId}_provider") ?: 'fore';
        }

        if ($networkProvider === 'fore') {
            require_once __DIR__.'/includes/fore_api.php';
            if ($csAgentRow && $csAgentRow['agent']['type']==='fore') {
                $cfg = $csAgentRow['config'];
                $foreApi = new ForeYemenAPI($cfg['domain']??'',$cfg['userid']??'',$cfg['username']??'',$cfg['password']??'');
            } else {
                $foreApi = getTelecomAPI($pdo);
            }
            if (!$foreApi) jr2(['ok'=>false,'msg'=>'Fore Yemen غير مفعّل']);

            $endpoint = fore_endpoint_for_method($methodId);
            // استعلام حسب الشبكة
            if ($endpoint === 'yem') {
                $qRes = $foreApi->yemenMobileQuery($targetNumber);
                if (!ForeYemenAPI::isSuccess($qRes)) jr2(['ok'=>false,'msg'=>$qRes['resultDesc'] ?? 'فشل الاستعلام']);
                // باقات يمن موبايل
                $offRes = $foreApi->yemenMobileQueryOffers($targetNumber);
                $offers = [];
                if (ForeYemenAPI::isSuccess($offRes) && !empty($offRes['offers'])) {
                    foreach ((array)$offRes['offers'] as $o) {
                        $offers[] = ['offer_id'=>$o['offerId']??'','offer_name'=>$o['offerName']??''];
                    }
                }
                // السلفة — الحقل الصحيح حسب التوثيق: loan_amount
                $loanVal = 0;
                try {
                    $loanRes = $foreApi->yemenMobileLoan($targetNumber);
                    if (ForeYemenAPI::isSuccess($loanRes)) {
                        // الرد الصحيح حسب API: loan_amount | fallback: availableCredit | loan
                        $loanVal = (float)($loanRes['loan_amount'] ?? $loanRes['availableCredit'] ?? $loanRes['loan'] ?? 0);
                    }
                } catch (Exception $e) {
                    $loanVal = 0;
                }
                jr2(['ok'=>true,'data'=>[
                    'balance'     => $qRes['balance'] ?? '—',
                    'loan'        => $loanVal,
                    'loan_status' => isset($loanRes['status']) ? $loanRes['status'] : ($loanVal > 0 ? '1' : '0'),
                    'offers'      => $offers,
                ]]);
            } elseif ($endpoint === 'post') {
                $qRes = $foreApi->postQuery($targetNumber);
                if (!ForeYemenAPI::isSuccess($qRes)) jr2(['ok'=>false,'msg'=>$qRes['resultDesc'] ?? 'فشل الاستعلام']);
                jr2(['ok'=>true,'data'=>[
                    'balance' => $qRes['balance'] ?? '—',
                    'loan'    => 0,
                    'offers'  => [],
                ]]);
            } else {
                // سبأفون / واي / MTN — استعلام أساسي
                jr2(['ok'=>true,'data'=>['balance'=>'—','loan'=>0,'offers'=>[]]]);
            }
        } else {
            // فلوسك
            $res = floosak_agent_service_info($pdo, $targetNumber, $methodId, $bunchId);
            jr2($res['ok'] ? ['ok'=>true,'data'=>$res['data']] : ['ok'=>false,'msg'=>$res['message']?:'فشل الاستعلام']);
        }
    }

    if ($action === 'get_bunches') {
        $methodId = (int)($_POST['method_id'] ?? 0);
        try {
            $rows = $pdo->prepare("SELECT id,bunch_id,bunch_name,code,unified_code,price,validity,is_free_amount,payment_type,section,bundle_group FROM floosak_agent_bunches WHERE method_id=? AND status=1 ORDER BY section, sort_order, id");
            $rows->execute([$methodId]);
            jr2(['ok'=>true,'bunches'=>$rows->fetchAll()]);
        } catch (Exception $e) { jr2(['ok'=>false,'msg'=>'خطأ']); }
    }

    jr2(['ok'=>false,'msg'=>'إجراء غير معروف']);
}

$statusColors = ['completed'=>'#00e676','pending'=>'#f5a623','failed'=>'#ff4455','rejected'=>'#ff4455','canceled'=>'#8895a7'];
$statusLabels = ['completed'=>'مكتمل','pending'=>'معلق','failed'=>'فشل','rejected'=>'مرفوض','canceled'=>'ملغي'];

$sadadRate = 0;
try { $sadadRate = (float)(getSetting('telecom_sadad_rate') ?: getSetting('telecom_exchange_rate') ?: 1700); } catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#080c1a">
<title><?= htmlspecialchars($siteName) ?> — خدمات الاتصالات</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php if ($siteLogoUrl): ?>
<link rel="shortcut icon" href="<?= htmlspecialchars($siteLogoUrl) ?>">
<?php endif; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap');
:root{
  --bg:#07080f;--bg2:#0d0f1a;--card:#111523;--card2:#181c2e;--card3:#1e2338;
  --border:rgba(255,255,255,.06);--border2:rgba(255,255,255,.1);
  --primary:#2563eb;--primary-g:linear-gradient(135deg,#2563eb,#1d4ed8);
  --cyan:#06b6d4;--gold:#f59e0b;--green:#10b981;--red:#ef4444;--purple:#8b5cf6;
  --text:#f1f5f9;--text2:#64748b;--text3:#334155;
  --radius:20px;--radius-sm:14px;--radius-xs:10px;
  --font:'Tajawal',sans-serif;--nav-h:64px;
  --shadow-blue:0 8px 32px rgba(37,99,235,.25);
  --shadow-card:0 2px 20px rgba(0,0,0,.4);
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{background:var(--bg);color:var(--text);font-family:var(--font)}

/* ── mesh bg ── */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background:
    radial-gradient(ellipse 70% 40% at 100% 0%,rgba(37,99,235,.08) 0%,transparent 60%),
    radial-gradient(ellipse 50% 50% at 0% 100%,rgba(139,92,246,.05) 0%,transparent 60%);}

.app{display:flex;flex-direction:column;min-height:100vh;max-width:480px;margin:0 auto;position:relative;z-index:1}

/* ── header ── */
.header{height:62px;display:flex;align-items:center;gap:12px;padding:0 18px;
  background:rgba(7,8,15,.92);backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);flex-shrink:0;z-index:100;position:sticky;top:0}
.header-title{font-size:.95rem;font-weight:800;letter-spacing:-.01em}
.header-sub{font-size:.65rem;color:var(--text2);margin-top:1px}

/* ── nav ── */
.bottom-nav{height:var(--nav-h);
  background:rgba(7,8,15,.95);backdrop-filter:blur(20px);
  border-top:1px solid var(--border);
  display:flex;align-items:center;
  position:fixed;bottom:0;left:0;right:0;z-index:200;max-width:480px;margin:0 auto}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;cursor:pointer;padding:8px 0;transition:all .2s;position:relative}
.nav-item:active{transform:scale(.88)}
.nav-icon{font-size:20px;color:var(--text3);transition:all .2s}
.nav-label{font-size:10px;color:var(--text3);font-weight:700;transition:all .2s}
.nav-item.active .nav-icon,.nav-item.active .nav-label{color:var(--primary)}
.nav-item.active::before{content:'';position:absolute;top:0;left:50%;transform:translateX(-50%);width:28px;height:2px;border-radius:0 0 4px 4px;background:var(--primary)}
.nav-center{flex:1;display:flex;justify-content:center}
.nav-center-btn{width:52px;height:52px;background:var(--primary-g);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;cursor:pointer;box-shadow:var(--shadow-blue);margin-top:-14px;transition:transform .2s;border:3px solid var(--bg)}
.nav-center-btn:active{transform:scale(.9)}

/* ── content / screen ── */
.content{flex:1;overflow-x:hidden}
.screen{display:none;padding-bottom:20px}
.screen.active{display:block;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}

/* ── balance hero ── */
.balance-hero{
  margin:16px 18px 0;
  background:linear-gradient(135deg,rgba(37,99,235,.18),rgba(139,92,246,.1));
  border:1px solid rgba(37,99,235,.2);
  border-radius:22px;padding:18px 20px;
  display:flex;align-items:center;justify-content:space-between;
  position:relative;overflow:hidden;
}
.balance-hero::before{content:'';position:absolute;top:-30px;left:-30px;width:140px;height:140px;
  border-radius:50%;background:radial-gradient(circle,rgba(37,99,235,.12),transparent 70%)}
.balance-label{font-size:.72rem;color:rgba(255,255,255,.5);font-weight:600;margin-bottom:4px}
.balance-val{font-size:1.5rem;font-weight:900;letter-spacing:-.03em;
  background:linear-gradient(135deg,#fff,#93c5fd);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.balance-actions{display:flex;gap:8px;position:relative;z-index:1}
.balance-btn{padding:8px 14px;border-radius:12px;background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.12);color:rgba(255,255,255,.8);
  font-family:var(--font);font-size:.75rem;font-weight:700;cursor:pointer;transition:all .2s;
  display:flex;align-items:center;gap:6px;white-space:nowrap}
.balance-btn:active{background:rgba(255,255,255,.15)}

/* ── service cards (home) ── */
.sec-header{display:flex;align-items:center;justify-content:space-between;padding:18px 18px 10px}
.sec-title{font-size:.78rem;font-weight:800;color:var(--text2);text-transform:uppercase;letter-spacing:.06em}
.svc-type-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:0 18px 16px}
.svc-type-card{
  border-radius:var(--radius);padding:20px 16px;cursor:pointer;
  transition:transform .2s,box-shadow .2s;
  position:relative;overflow:hidden;border:1px solid rgba(255,255,255,.08);
  display:flex;flex-direction:column;gap:6px;
}
.svc-type-card:active{transform:scale(.96)}
.svc-type-card::after{content:'';position:absolute;top:-20px;left:-20px;width:80px;height:80px;
  border-radius:50%;background:rgba(255,255,255,.06)}
.svc-type-icon{font-size:1.8rem;position:relative;z-index:1}
.svc-type-title{font-size:.95rem;font-weight:900;position:relative;z-index:1}
.svc-type-sub{font-size:.68rem;color:rgba(255,255,255,.55);position:relative;z-index:1}

/* ── method grid ── */
.card{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden}
.method-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:0 18px 10px}
.method-card{
  background:var(--card);border:1.5px solid var(--border);
  border-radius:var(--radius-sm);padding:14px 8px;text-align:center;
  cursor:pointer;transition:all .22s;position:relative;
}
.method-card:active{transform:scale(.93)}
.method-card.selected{
  border-color:var(--primary);
  background:rgba(37,99,235,.1);
  box-shadow:0 0 0 3px rgba(37,99,235,.12);
}
.method-icon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;margin:0 auto 8px;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.3)}
.method-name{font-size:.72rem;font-weight:800;line-height:1.3;color:var(--text)}
.method-badge{position:absolute;top:-5px;right:-5px;background:var(--gold);color:#000;font-size:.5rem;font-weight:900;padding:2px 6px;border-radius:8px;box-shadow:0 2px 8px rgba(245,158,11,.4)}

/* ── number input ── */
.number-inp-wrap{padding:0 18px;margin-bottom:10px;position:relative}
.number-inp{
  width:100%;background:var(--card2);
  border:2px solid var(--border2);border-radius:18px;
  padding:18px 56px 18px 18px;color:var(--text);
  font-family:var(--font);font-size:1.25rem;font-weight:800;
  letter-spacing:.06em;outline:none;transition:all .25s;text-align:center;
}
.number-inp:focus{border-color:var(--primary);background:rgba(37,99,235,.05);box-shadow:0 0 0 4px rgba(37,99,235,.08)}
.number-inp.detected{border-color:var(--green);box-shadow:0 0 0 4px rgba(16,185,129,.08)}
.number-inp::placeholder{color:var(--text3);font-size:.9rem;font-weight:500;letter-spacing:0}

/* ── detect card ── */
.detect-wrap{padding:0 18px;margin-bottom:14px}
.detect-card{
  border-radius:16px;padding:14px 16px;display:flex;align-items:center;gap:14px;
  border:1.5px solid var(--border);transition:all .3s;background:var(--card);
}
.detect-card.found{
  border-color:rgba(16,185,129,.3);
  background:rgba(16,185,129,.05);
}
.detect-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;flex-shrink:0;transition:all .3s;box-shadow:0 4px 16px rgba(0,0,0,.3)}
.detect-name{font-size:.95rem;font-weight:900}
.detect-sub{font-size:.72rem;color:var(--text2);margin-top:2px}
.detect-badge{font-size:.68rem;font-weight:800;padding:3px 10px;border-radius:10px;border:1.5px solid}

/* ── section/ptype tabs ── */
.ptype-tabs{display:flex;gap:0;margin:12px 18px 14px;background:var(--card2);border-radius:var(--radius-xs);padding:4px;border:1px solid var(--border)}
.ptype-tab{flex:1;padding:9px;border-radius:8px;font-family:var(--font);font-size:.82rem;font-weight:800;border:none;background:transparent;color:var(--text2);cursor:pointer;transition:all .2s;text-align:center}
.ptype-tab.active{background:var(--primary);color:#fff;box-shadow:0 2px 12px rgba(37,99,235,.35)}
.section-tabs{display:flex;gap:6px;padding:0 18px 12px;overflow-x:auto;scrollbar-width:none}
.section-tabs::-webkit-scrollbar{display:none}
.section-tab{
  flex-shrink:0;padding:8px 14px;border-radius:20px;
  font-family:var(--font);font-size:.78rem;font-weight:800;
  border:1.5px solid var(--border);background:transparent;
  color:var(--text2);cursor:pointer;transition:all .2s;white-space:nowrap;
}
.section-tab.active{background:rgba(37,99,235,.15);border-color:var(--primary);color:#93c5fd}

/* ── accordion ── */
.accordion-wrap{padding:0 18px;display:flex;flex-direction:column;gap:8px}
.accordion-item{border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--border);background:var(--card)}
.accordion-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;cursor:pointer;transition:background .15s;user-select:none}
.accordion-header:active{background:var(--card2)}
.accordion-title{font-size:.88rem;font-weight:900}
.accordion-count{font-size:.68rem;background:rgba(37,99,235,.12);color:#93c5fd;padding:2px 9px;border-radius:20px;margin-left:8px;font-weight:800}
.accordion-arrow{font-size:.78rem;color:var(--text3);transition:transform .25s}
.accordion-arrow.open{transform:rotate(180deg)}
.accordion-body{display:none;background:rgba(255,255,255,.015);border-top:1px solid var(--border)}
.accordion-body.open{display:block}

/* ── bunch / fee items ── */
.bunch-list{padding:0 18px;display:flex;flex-direction:column;gap:8px}
.bunch-item{
  background:var(--card);border:1.5px solid var(--border);
  border-radius:var(--radius-sm);padding:13px 14px;
  cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:12px;
}
.bunch-item:active{transform:scale(.985)}
.bunch-item.selected{border-color:var(--primary);background:rgba(37,99,235,.08);box-shadow:0 0 0 2px rgba(37,99,235,.1)}
.bunch-dot{width:10px;height:10px;border-radius:50%;border:2px solid var(--text3);flex-shrink:0;transition:all .2s}
.bunch-item.selected .bunch-dot{background:var(--primary);border-color:var(--primary);box-shadow:0 0 6px rgba(37,99,235,.5)}
.bunch-info{flex:1;min-width:0}
.bunch-name{font-size:.85rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bunch-meta{font-size:.7rem;color:var(--text2);margin-top:2px}
.bunch-price{font-size:.82rem;font-weight:900;color:var(--gold);white-space:nowrap}
.bunch-item.free-type{border-color:rgba(245,158,11,.15);background:rgba(245,158,11,.03)}
.bunch-item.free-type.selected{border-color:var(--gold);background:rgba(245,158,11,.08)}
.bunch-item.free-type.selected .bunch-dot{background:var(--gold);border-color:var(--gold)}
.bunch-item .price-badge{background:rgba(37,99,235,.2);color:#93c5fd;font-size:.72rem;font-weight:900;padding:3px 10px;border-radius:20px;white-space:nowrap;flex-shrink:0;border:1px solid rgba(37,99,235,.3)}
.bunch-section-label{padding:12px 18px 6px;font-size:.7rem;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:6px}
.fees-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px 18px}
.fee-item{border-radius:var(--radius-sm);background:var(--card);border:1.5px solid var(--border);padding:14px 8px;text-align:center;cursor:pointer;transition:all .2s}
.fee-item:active{transform:scale(.93)}
.fee-item.selected{border-color:var(--gold);background:rgba(245,158,11,.08);box-shadow:0 0 0 2px rgba(245,158,11,.12)}
.fee-price{font-size:1.1rem;font-weight:900;color:var(--gold)}
.fee-name{font-size:.65rem;color:var(--text2);margin-top:3px;font-weight:600}
.fee-dot{width:8px;height:8px;border-radius:50%;border:2px solid var(--text3);margin:6px auto 0;transition:all .2s}

/* ── amount pills ── */
.amount-pills{display:flex;gap:8px;padding:0 18px 12px;flex-wrap:wrap}
.amount-pill{padding:8px 16px;border-radius:20px;border:1.5px solid var(--border);font-size:.78rem;font-weight:800;color:var(--text2);background:transparent;cursor:pointer;transition:all .2s;font-family:var(--font)}
.amount-pill.active{background:rgba(37,99,235,.15);border-color:var(--primary);color:#93c5fd}

/* ── inputs ── */
.inp-wrap{padding:0 18px;margin-bottom:12px}
.inp-label{font-size:.78rem;color:var(--text2);margin-bottom:7px;font-weight:700;display:flex;align-items:center;gap:5px}
.inp{width:100%;background:var(--card2);border:1.5px solid var(--border2);border-radius:var(--radius-xs);padding:13px 16px;color:var(--text);font-family:var(--font);font-size:.92rem;outline:none;transition:border .2s,box-shadow .2s}
.inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.inp::placeholder{color:var(--text3)}

/* ── main button ── */
.btn-main{
  width:calc(100% - 36px);margin:4px 18px 16px;padding:16px;border-radius:var(--radius-sm);
  background:var(--primary-g);color:#fff;font-family:var(--font);
  font-size:.95rem;font-weight:900;border:none;cursor:pointer;
  box-shadow:var(--shadow-blue);transition:all .2s;
  display:flex;align-items:center;justify-content:center;gap:9px;
  letter-spacing:.01em;
}
.btn-main:active{transform:scale(.97);box-shadow:none}
.btn-main:disabled{opacity:.4;cursor:not-allowed;transform:none;box-shadow:none}
.btn-outline{background:transparent;border:1.5px solid var(--border2);color:var(--text2);border-radius:var(--radius-xs);padding:10px 16px;font-family:var(--font);font-size:.82rem;cursor:pointer;transition:all .2s;font-weight:700}
.btn-outline:active{background:rgba(255,255,255,.05)}

/* ── balance bar (screens) ── */
.balance-bar{
  margin:14px 18px 0;background:var(--card2);border:1px solid var(--border);
  border-radius:16px;padding:13px 16px;display:flex;align-items:center;justify-content:space-between;
}
.balance-label{font-size:.72rem;color:var(--text2);font-weight:600}
.balance-val{font-size:1rem;font-weight:900;color:var(--green)}

/* ── info / tx ── */
.info-panel{margin:0 18px 12px;background:rgba(37,99,235,.05);border:1px solid rgba(37,99,235,.12);border-radius:var(--radius-sm);padding:14px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.info-row:last-child{border-bottom:none}
.info-key{font-size:.75rem;color:var(--text2)}
.info-val{font-size:.8rem;font-weight:800}
.tx-item{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;transition:background .15s;cursor:pointer}
.tx-item:last-child{border-bottom:none}
.tx-item:active{background:rgba(255,255,255,.03)}
.tx-icon-wrap{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem}
.tx-info{flex:1;min-width:0}
.tx-title{font-size:.84rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tx-sub{font-size:.7rem;color:var(--text2);margin-top:2px}
.tx-status{font-size:.7rem;font-weight:800;white-space:nowrap}
.sec-title{font-size:.72rem;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.07em;padding:16px 18px 8px}

/* ── back btn ── */
.back-btn{width:38px;height:38px;background:var(--card2);border:1px solid var(--border);border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text2);font-size:1rem;flex-shrink:0;transition:all .2s;text-decoration:none}
.back-btn:active{background:var(--card3);transform:scale(.92)}

/* ── sadad ── */
.sadad-row{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;cursor:pointer;user-select:none;transition:background .15s}
.sadad-row:active{background:rgba(255,255,255,.03)}
.sadad-row-right{display:flex;align-items:center;gap:12px}
.sadad-row-icon{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1rem;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.25)}
.sadad-row-name{font-size:.92rem;font-weight:800}
.sadad-row-arrow{color:var(--text3);font-size:.75rem;transition:transform .22s;flex-shrink:0}
.sadad-row-arrow.open{transform:rotate(180deg)}
.sadad-children{display:none;border-top:1px solid var(--border)}
.sadad-children.open{display:block}
.sadad-item{display:flex;align-items:center;gap:12px;padding:13px 18px 13px 22px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .15s}
.sadad-item:last-child{border-bottom:none}
.sadad-item:active{background:rgba(255,255,255,.03)}
.sadad-item-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem;color:#fff;flex-shrink:0}
.sadad-item-name{font-size:.85rem;font-weight:700;flex:1}
.sadad-sub-header{padding:10px 18px 6px;font-size:.7rem;font-weight:800;color:var(--text3);display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.05em}

/* ── conflict picker ── */
.conflict-picker{margin:0 18px 12px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.conflict-picker-title{padding:10px 16px;font-size:.75rem;color:var(--text2);border-bottom:1px solid var(--border);font-weight:700}
.conflict-option{display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;border-bottom:1px solid var(--border);transition:background .15s}
.conflict-option:last-child{border-bottom:none}
.conflict-option:active{background:rgba(255,255,255,.04)}
.conflict-option-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;flex-shrink:0}
.conflict-option-name{font-size:.9rem;font-weight:800}

/* ── bill screens ── */
.service-selector{margin:14px 18px 0;background:var(--card);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.service-selector-header{padding:13px 16px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none}
.service-selector-header:active{background:var(--card2)}
.service-selector-title{font-size:.88rem;font-weight:800}
.service-selector-body{display:none;border-top:1px solid var(--border);max-height:260px;overflow-y:auto}
.service-selector-body.open{display:block}
.service-option{padding:12px 16px;cursor:pointer;font-size:.84rem;border-bottom:1px solid var(--border);transition:background .15s;display:flex;align-items:center;gap:10px}
.service-option:last-child{border-bottom:none}
.service-option:active{background:var(--card2)}
.service-option.selected{background:rgba(37,99,235,.08);color:#93c5fd;font-weight:800}
.bill-result{margin:14px 18px 0;border-radius:var(--radius-sm);overflow:hidden;border:1px solid var(--border);background:var(--card)}
.bill-result-header{padding:14px 16px;background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(245,158,11,.05));border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.bill-result-name{font-size:.95rem;font-weight:900}
.bill-result-row{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid rgba(255,255,255,.04)}
.bill-result-row:last-child{border-bottom:none}
.bill-result-key{font-size:.75rem;color:var(--text2)}
.bill-result-val{font-size:.84rem;font-weight:800;text-align:left;max-width:60%}
.bill-result-val.danger{color:var(--red)}
.bill-result-val.gold{color:var(--gold)}
.bill-result-val.green{color:var(--green)}
.bill-note{margin:10px 18px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.18);border-radius:10px;padding:10px 12px;font-size:.75rem;color:var(--gold);line-height:1.6}
.bill-amount-wrap{margin:14px 18px 0}
.counter-wrap{display:flex;align-items:center;gap:0;background:var(--card2);border:1.5px solid var(--border);border-radius:14px;overflow:hidden}
.counter-btn{width:48px;height:52px;border:none;background:none;cursor:pointer;font-size:1.4rem;font-weight:900;transition:background .15s;flex-shrink:0}
.counter-btn:first-child{color:#ff4455;border-right:1px solid var(--border)}
.counter-btn:last-child{color:#00d4aa;border-left:1px solid var(--border)}
.counter-btn:active{background:rgba(255,255,255,.07)}
.counter-val{flex:1;text-align:center;font-size:1.6rem;font-weight:900;color:var(--text);line-height:52px}
.counter-price-hint{font-size:.75rem;color:var(--text2);text-align:center;margin-top:6px}
.bill-amount-inp{width:100%;background:var(--card2);border:2px solid var(--primary);border-radius:14px;padding:14px 16px;color:var(--text);font-family:var(--font);font-size:1.1rem;font-weight:800;outline:none;text-align:center;letter-spacing:.04em;transition:box-shadow .2s}
.bill-amount-inp:focus{box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.bill-amount-inp::placeholder{color:var(--text3);font-size:.9rem;font-weight:500;letter-spacing:0}

/* ── inline query ── */
.inline-query-btn{position:absolute;left:12px;top:50%;transform:translateY(-50%);background:var(--primary);color:#fff;border:none;border-radius:10px;padding:8px 12px;font-family:var(--font);font-size:.75rem;font-weight:800;cursor:pointer;transition:all .2s;white-space:nowrap}
.inline-query-btn:active{transform:translateY(-50%) scale(.93)}
.inline-query-btn:disabled{opacity:.4;cursor:not-allowed}
.inline-query-result{margin:0 18px 12px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;display:none}
.inline-query-result.show{display:block}
.iqr-header{padding:12px 14px;background:rgba(37,99,235,.07);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.iqr-name{font-size:.88rem;font-weight:900}
.iqr-tabs{display:flex;border-bottom:1px solid var(--border)}
.iqr-tab{flex:1;padding:9px;font-family:var(--font);font-size:.78rem;font-weight:800;border:none;background:transparent;color:var(--text2);cursor:pointer;transition:all .2s}
.iqr-tab.active{background:rgba(37,99,235,.08);color:var(--primary)}
.iqr-body{padding:12px 14px}
.iqr-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.8rem}
.iqr-row:last-child{border-bottom:none}
.iqr-key{color:var(--text2)}
.iqr-val{font-weight:800}
.iqr-offer{background:var(--card2);border-radius:10px;padding:8px 10px;margin-bottom:6px}
.iqr-offer-name{font-size:.8rem;font-weight:800}
.iqr-offer-id{font-size:.65rem;color:var(--text3);margin-top:2px}

/* ══ Check Screen Redesign ══ */
.check-screen-wrap{padding:0 0 20px}
.check-hero{
  margin:20px 18px 0;
  background:linear-gradient(135deg,rgba(139,92,246,.18),rgba(37,99,235,.12));
  border:1px solid rgba(139,92,246,.25);
  border-radius:24px;padding:24px 20px;
  display:flex;flex-direction:column;align-items:center;
  position:relative;overflow:hidden;
}
.check-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:radial-gradient(circle,rgba(139,92,246,.15),transparent 70%)}
.check-hero::after{content:'';position:absolute;bottom:-30px;left:-30px;width:120px;height:120px;border-radius:50%;background:radial-gradient(circle,rgba(37,99,235,.12),transparent 70%)}
.check-hero-icon{
  width:72px;height:72px;border-radius:22px;
  background:linear-gradient(135deg,#8b5cf6,#2563eb);
  display:flex;align-items:center;justify-content:center;
  font-size:1.8rem;margin-bottom:14px;
  box-shadow:0 12px 32px rgba(139,92,246,.4);
  position:relative;z-index:1;
}
.check-hero-title{font-size:1.1rem;font-weight:900;margin-bottom:4px;position:relative;z-index:1}
.check-hero-sub{font-size:.75rem;color:rgba(255,255,255,.5);position:relative;z-index:1}

/* شبكة الشبكات */
.check-networks{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;padding:16px 18px 0}
.check-net{
  background:var(--card);border:1.5px solid var(--border);
  border-radius:16px;padding:12px 6px;text-align:center;
  cursor:pointer;transition:all .22s;
}
.check-net:active{transform:scale(.9)}
.check-net.selected{border-color:var(--primary);background:rgba(37,99,235,.1);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.check-net-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1rem;margin:0 auto 6px;color:#fff}
.check-net-name{font-size:.65rem;font-weight:800;line-height:1.3;color:var(--text)}

/* حقل الرقم */
.check-inp-wrap{padding:16px 18px 0;position:relative}
.check-inp{
  width:100%;background:var(--card2);
  border:2px solid var(--border2);border-radius:20px;
  padding:20px 24px;color:var(--text);
  font-family:var(--font);font-size:1.4rem;font-weight:900;
  letter-spacing:.08em;outline:none;transition:all .3s;text-align:center;
  direction:ltr;
}
.check-inp:focus{border-color:var(--purple,#8b5cf6);box-shadow:0 0 0 4px rgba(139,92,246,.1)}
.check-inp.has-val{border-color:rgba(139,92,246,.4)}
.check-inp::placeholder{color:var(--text3);font-size:.95rem;font-weight:500;letter-spacing:0;direction:rtl}

/* زر الفحص */
.check-btn{
  width:calc(100% - 36px);margin:16px 18px 0;
  padding:18px;border-radius:20px;
  background:linear-gradient(135deg,#8b5cf6,#2563eb);
  color:#fff;font-family:var(--font);font-size:1rem;font-weight:900;
  border:none;cursor:pointer;
  box-shadow:0 8px 32px rgba(139,92,246,.4);
  display:flex;align-items:center;justify-content:center;gap:10px;
  transition:all .2s;letter-spacing:.01em;
}
.check-btn:active{transform:scale(.97);box-shadow:0 4px 16px rgba(139,92,246,.3)}
.check-btn:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}

/* بطاقة النتيجة */
.check-result-card{
  margin:20px 18px 0;
  border-radius:24px;overflow:hidden;
  border:1px solid rgba(255,255,255,.07);
  box-shadow:0 16px 48px rgba(0,0,0,.4);
  animation:slideUp .4s cubic-bezier(.32,1.2,.72,1);
}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.crc-header{
  padding:18px 20px;
  background:linear-gradient(135deg,rgba(37,99,235,.2),rgba(139,92,246,.1));
  border-bottom:1px solid rgba(255,255,255,.06);
  display:flex;align-items:center;gap:12px;
}
.crc-net-icon{
  width:48px;height:48px;border-radius:15px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.2rem;color:#fff;flex-shrink:0;
  box-shadow:0 4px 16px rgba(0,0,0,.3);
}
.crc-title{font-size:.95rem;font-weight:900}
.crc-num{font-size:.72rem;color:rgba(255,255,255,.5);margin-top:2px;direction:ltr;text-align:right}
.crc-stats{
  display:grid;grid-template-columns:1fr 1fr;
  background:var(--card);
}
.crc-stat{
  padding:20px 16px;position:relative;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  border-left:1px solid var(--border);
}
.crc-stat:first-child{border-left:none}
.crc-stat-icon{
  width:44px;height:44px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
  margin-bottom:10px;
}
.crc-stat-label{font-size:.68rem;color:var(--text2);font-weight:700;margin-bottom:4px}
.crc-stat-val{font-size:1.5rem;font-weight:900;letter-spacing:-.02em}
.crc-stat-unit{font-size:.7rem;color:var(--text2);margin-top:2px}

/* باقات مشتركة */
.crc-offers-wrap{background:var(--card);border-top:1px solid var(--border)}
.crc-offers-head{
  padding:14px 18px 10px;
  display:flex;align-items:center;justify-content:space-between;
}
.crc-offers-title{font-size:.78rem;font-weight:900;color:var(--text2);text-transform:uppercase;letter-spacing:.05em}
.crc-offers-count{font-size:.68rem;background:rgba(37,99,235,.12);color:#93c5fd;padding:2px 10px;border-radius:20px;font-weight:800}
.crc-offers-grid{display:flex;flex-direction:column;gap:0}
.crc-offer-row{
  display:flex;align-items:center;gap:12px;
  padding:12px 18px;border-top:1px solid var(--border);
  cursor:pointer;transition:background .15s;
}
.crc-offer-row:active{background:rgba(255,255,255,.03)}
.crc-offer-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;box-shadow:0 0 6px rgba(37,99,235,.5)}
.crc-offer-name{font-size:.84rem;font-weight:700;flex:1}
.crc-offer-id{font-size:.68rem;color:var(--text3);font-family:monospace}
.crc-empty-offers{padding:16px 18px;font-size:.8rem;color:var(--text3);text-align:center}
.number-clear{position:absolute;left:28px;top:50%;transform:translateY(-50%);width:28px;height:28px;background:var(--card2);border:1px solid var(--border);border-radius:8px;display:none;align-items:center;justify-content:center;cursor:pointer;color:var(--text3);font-size:.8rem;transition:all .2s}
.number-clear.show{display:flex}
.numpad-hint{display:flex;gap:6px;padding:0 18px 10px;flex-wrap:wrap}
.np-btn{flex:1;min-width:60px;padding:9px;border-radius:12px;background:var(--card2);border:1px solid var(--border);color:var(--text2);font-family:var(--font);font-size:.78rem;font-weight:800;cursor:pointer;text-align:center;transition:all .15s}
.np-btn:active{transform:scale(.92);background:rgba(37,99,235,.12);color:var(--primary)}

/* ── check / step ── */
.step-indicator{display:flex;align-items:center;gap:6px;padding:0 18px 14px}
.step-dot{width:8px;height:8px;border-radius:50%;background:var(--border);transition:all .3s}
.step-dot.active{background:var(--primary);width:24px;border-radius:4px}
.step-dot.done{background:var(--green)}
.check-result{margin:0 18px 16px;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.check-result-header{padding:14px 16px;background:rgba(37,99,235,.07);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;font-weight:800}
.offers-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px}
.offer-chip{background:var(--card2);border-radius:10px;padding:9px 10px;font-size:.72rem;border:1px solid var(--border)}
.offer-id{color:var(--text3);font-size:.65rem;margin-bottom:2px}
.offer-name{color:var(--text);font-weight:800;line-height:1.3}
.filter-tabs{display:flex;gap:6px;padding:0 18px 12px;overflow-x:auto;scrollbar-width:none}
.filter-tabs::-webkit-scrollbar{display:none}
.filter-tab{padding:7px 16px;border-radius:20px;font-size:.78rem;font-weight:800;border:1.5px solid var(--border);color:var(--text2);background:transparent;cursor:pointer;white-space:nowrap;transition:all .2s;font-family:var(--font)}
.filter-tab.active{background:var(--primary);border-color:var(--primary);color:#fff}

/* ── empty / toast ── */
.empty-state{padding:48px 20px;text-align:center;color:var(--text3)}
.empty-icon{font-size:3rem;margin-bottom:12px;opacity:.25}
.empty-text{font-size:.88rem;font-weight:600}
.toast{position:fixed;bottom:calc(var(--nav-h) + 12px);left:50%;transform:translateX(-50%) translateY(100px);
  background:var(--card2);border:1px solid var(--border2);border-radius:16px;
  padding:13px 22px;font-size:.85rem;font-weight:800;z-index:9999;
  box-shadow:0 12px 40px rgba(0,0,0,.5);transition:transform .35s cubic-bezier(.34,1.56,.64,1);
  white-space:nowrap;max-width:320px;text-align:center}
.toast.show{transform:translateX(-50%) translateY(0)}
.toast.success{border-color:rgba(16,185,129,.3);color:var(--green);background:rgba(16,185,129,.08)}
.toast.error{border-color:rgba(239,68,68,.3);color:var(--red);background:rgba(239,68,68,.08)}

/* ── misc ── */
.spin{animation:spin .7s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
body.light-mode{--bg:#f8faff;--bg2:#f0f4ff;--card:#fff;--card2:#f5f7ff;--card3:#eef0ff;--border:rgba(0,0,0,.07);--border2:rgba(0,0,0,.1);--text:#1e293b;--text2:#64748b;--text3:#94a3b8}
body.light-mode .header{background:rgba(248,250,255,.95)}
body.light-mode .bottom-nav{background:rgba(248,250,255,.97)}
body.light-mode .nav-icon,body.light-mode .nav-label{color:#94a3b8 !important}
body.light-mode .nav-item.active .nav-icon,body.light-mode .nav-item.active .nav-label{color:var(--primary) !important}
body.light-mode .nav-item.active::before{background:var(--primary) !important}
body.light-mode .balance-val{-webkit-text-fill-color:var(--primary)}
body::after{content:'';pointer-events:none;fixed;inset:0;z-index:0}
body { padding-bottom: calc(var(--nav-h) + 10px) !important; }
</style>
</head>
<body>
<style>body{padding-bottom:calc(var(--nav-h) + 10px)!important}</style>
<style>
body { padding-bottom: calc(var(--nav-h) + 10px) !important; }
</style>
<div class="app">

  <!-- HEADER -->
  <div class="header">
    <a href="<?=SITE_URL?>/mobile.php" class="back-btn" style="text-decoration:none"><i class="fas fa-arrow-right"></i></a>
    <div style="flex:1">
      <div style="font-size:.92rem;font-weight:900">خدمات الاتصالات</div>
      <div style="font-size:.65rem;color:var(--text2)">شحن • فواتير • باقات • فحص رصيد</div>
    </div>
    <?php if($siteLogoUrl): ?>
    <img src="<?= htmlspecialchars($siteLogoUrl) ?>" style="width:34px;height:34px;border-radius:10px;object-fit:cover">
    <?php endif; ?>
  </div>

  <div class="content" id="mainContent">

    <!-- ════ SCREEN HOME ════ -->
    <div class="screen active" id="screen-home">

      <?php if(!$agEnabled): ?>
      <div style="padding:60px 20px;text-align:center">
        <div style="font-size:3.5rem;margin-bottom:20px;opacity:.5">🔧</div>
        <div style="font-size:1.1rem;font-weight:900;margin-bottom:10px">الخدمة غير متاحة حالياً</div>
        <div style="font-size:.85rem;color:var(--text2)">سيتم تفعيل خدمات الاتصالات قريباً</div>
      </div>
      <?php else: ?>

      <div class="balance-hero">
        <div>
          <div class="balance-label">رصيدك المتاح</div>
          <div class="balance-val" id="live-balance"><?= number_format($userBalance,2) ?> <?= htmlspecialchars($currSymbol) ?></div>
        </div>
        <div class="balance-actions">
          <button class="balance-btn" onclick="showScreen('screen-history')"><i class="fas fa-history"></i> السجل</button>
          <button class="balance-btn" onclick="showScreen('screen-check')"><i class="fas fa-search"></i> فحص</button>
        </div>
      </div>

      <div class="sec-header"><div class="sec-title">اختر الخدمة</div></div>
      <div class="svc-type-grid">
        <div class="svc-type-card" style="background:linear-gradient(135deg,#0d3b8a,#1a5fc7)" onclick="startFlow('topup')">
          <div class="svc-type-icon">📱</div>
          <div class="svc-type-title">شحن رصيد</div>
          <div class="svc-type-sub"><?= count($topupMethods)+3 ?> خدمة</div>
        </div>
        <div class="svc-type-card" style="background:linear-gradient(135deg,#1a4a1a,#2d7a2d)" onclick="showScreen('screen-sadad')">
          <div class="svc-type-icon">🧾</div>
          <div class="svc-type-title">سداد</div>
          <div class="svc-type-sub">فواتير • تمويل • تبرعات</div>
        </div>


      </div>

      <?php if($recentTx): ?>
      <div class="sec-title">آخر العمليات</div>
      <div class="card" style="margin:0 16px">
        <?php foreach(array_slice($recentTx,0,4) as $tx):
          $color  = $statusColors[$tx['status']] ?? '#8895a7';
          $label  = $statusLabels[$tx['status']] ?? $tx['status'];
          $mcolor = $tx['method_color'] ?: '#1e6fff'; ?>
        <div class="tx-item">
          <div class="tx-icon-wrap" style="background:<?= $mcolor ?>22;color:<?= $mcolor ?>"><i class="fas fa-sim-card"></i></div>
          <div class="tx-info">
            <div class="tx-title"><?= htmlspecialchars($tx['method_name'] ?? 'شحن') ?> — <?= htmlspecialchars($tx['target_number']) ?></div>
            <div class="tx-sub"><?= number_format((float)$tx['amount']) ?> ر.ي • <?= date('d/m H:i', strtotime($tx['created_at'])) ?></div>
          </div>
          <div class="tx-status" style="color:<?= $color ?>"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="height:20px"></div>
      <?php endif; ?>
    </div><!-- /home -->

    <!-- ════ SCREEN TOPUP MENU (اختيار نوع الشحن) ════ -->
    <div class="screen" id="screen-topup-menu">
      <div style="padding:14px 16px 0;display:flex;align-items:center;gap:10px">
        <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
        <div>
          <div style="font-size:.92rem;font-weight:900">شحن رصيد</div>
          <div style="font-size:.67rem;color:var(--text2)">اختر نوع الخدمة</div>
        </div>
      </div>

      <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px">

        <!-- شبكات الهاتف -->
        <div style="font-size:.72rem;font-weight:700;color:var(--text3);margin-bottom:2px;padding-right:2px">
          <i class="fas fa-sim-card"></i> شبكات الهاتف
        </div>
        <?php foreach($topupMethods as $m): ?>
        <div class="card" style="padding:0;overflow:hidden"
             onclick="startFlowWithMethod(<?=(int)$m['method_id']?>,'<?=htmlspecialchars(addslashes($m['name_ar']))?>','<?=htmlspecialchars($m['color'])?>','<?=htmlspecialchars($m['icon'])?>','topup')">
          <div class="sadad-row">
            <div class="sadad-row-right">
              <div class="sadad-row-icon" style="background:<?=htmlspecialchars($m['color'])?>">
                <i class="fas fa-<?=htmlspecialchars($m['icon'])?>"></i>
              </div>
              <div class="sadad-row-name"><?=htmlspecialchars($m['name_ar'])?></div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.75rem"></i>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- فاصل -->
        <div style="font-size:.72rem;font-weight:700;color:var(--text3);margin-top:6px;margin-bottom:2px;padding-right:2px">
          <i class="fas fa-ethernet"></i> خدمات الإنترنت والهاتف الثابت
        </div>

        <!-- ADSL -->
        <div class="card" style="padding:0;overflow:hidden"
             onclick="startFlowWithMethod(4,'إنترنت منزلي ADSL','#00aaff','wifi','billpay_open')">
          <div class="sadad-row">
            <div class="sadad-row-right">
              <div class="sadad-row-icon" style="background:#00aaff">
                <i class="fas fa-wifi"></i>
              </div>
              <div>
                <div class="sadad-row-name">إنترنت منزلي ADSL</div>
                <div style="font-size:.68rem;color:var(--text2)">رقم الخط + مبلغ</div>
              </div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.75rem"></i>
          </div>
        </div>

        <!-- الخط الثابت -->
        <div class="card" style="padding:0;overflow:hidden"
             onclick="startFlowWithMethod(13,'الخط الثابت','#6c757d','phone','billpay_open')">
          <div class="sadad-row">
            <div class="sadad-row-right">
              <div class="sadad-row-icon" style="background:#6c757d">
                <i class="fas fa-phone"></i>
              </div>
              <div>
                <div class="sadad-row-name">الخط الثابت</div>
                <div style="font-size:.68rem;color:var(--text2)">رقم الخط + مبلغ</div>
              </div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.75rem"></i>
          </div>
        </div>

        <!-- يمن فورجي -->
        <div class="card" style="padding:0;overflow:hidden"
             onclick="startFlowWithMethod(16,'يمن فورجي','#e91e8c','signal','billpay_bundles')">
          <div class="sadad-row">
            <div class="sadad-row-right">
              <div class="sadad-row-icon" style="background:#e91e8c">
                <i class="fas fa-signal"></i>
              </div>
              <div>
                <div class="sadad-row-name">يمن فورجي</div>
                <div style="font-size:.68rem;color:var(--text2)">رقم الخط + مبلغ أو باقات</div>
              </div>
            </div>
            <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.75rem"></i>
          </div>
        </div>

      </div>
      <div style="height:20px"></div>
    </div><!-- /screen-topup-menu -->

    <!-- ════ SCREEN FLOW ════ -->
    <div class="screen" id="screen-flow">

      <!-- Step Manual: اختيار يدوي للمزود (للفواتير) -->
      <div id="step-method-manual" style="display:none">
        <div style="padding:14px 16px 4px;display:flex;align-items:center;gap:10px">
          <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
          <div>
            <div style="font-size:.88rem;font-weight:900">اختر الخدمة</div>
            <div style="font-size:.67rem;color:var(--text2)">الخطوة 1 من 3</div>
          </div>
        </div>
        <div class="step-indicator">
          <div class="step-dot active"></div>
          <div class="step-dot"></div>
          <div class="step-dot"></div>
        </div>
        <div class="sec-title">الخدمات المتاحة</div>
        <div class="method-grid" id="manual-method-grid">
          <?php foreach($methods as $m): ?>
          <?php if($m['transaction_type'] === 'BILLPAY'): ?>
          <div class="method-card"
               data-id="<?= (int)$m['method_id'] ?>"
               data-name="<?= htmlspecialchars($m['name_ar']) ?>"
               data-color="<?= htmlspecialchars($m['color']) ?>"
               data-icon="<?= htmlspecialchars($m['icon']) ?>"
               onclick="selectManualMethod(this)">
            <?= netIconHtml($m) ?>
            <div class="method-name"><?= htmlspecialchars($m['name_ar']) ?></div>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div style="height:20px"></div>
      </div><!-- /step-method-manual -->

      <!-- Step موحّد: الرقم + الباقات في نفس الشاشة -->
      <div id="step-method" style="display:none"></div><!-- dummy للتوافق مع goStep -->

      <div id="step-details">
        <div style="padding:14px 16px 4px;display:flex;align-items:center;gap:10px">
          <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
          <div style="flex:1">
            <div style="font-size:.88rem;font-weight:900" id="flow-title">شحن رصيد</div>
            <div style="font-size:.67rem;color:var(--text2)" id="step2-title">أدخل الرقم واختر الباقة</div>
          </div>
          <div id="step2-badge" style="background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:700;display:flex;align-items:center;gap:5px">
            <span id="step2-badge-icon"></span>
            <span id="step2-badge-name"></span>
          </div>
        </div>

        <!-- حقل الرقم + زر فحص -->
        <div class="number-inp-wrap" style="position:relative;margin-top:10px">
          <input type="tel" class="number-inp" id="target-number"
                 placeholder="أدخل رقم الهاتف"
                 inputmode="numeric"
                 maxlength="12"
                 style="padding-left:90px"
                 oninput="onNumberInput(this)">
          <div class="number-clear" id="number-clear" onclick="clearNumber()" style="left:80px">
            <i class="fas fa-times"></i>
          </div>
          <button class="inline-query-btn" id="btn-inline-query" onclick="doInlineQuery()">
            <i class="fas fa-search"></i> فحص
          </button>
        </div>

        <!-- بطاقة الشبكة المكتشفة (مخفية — ضرورية للـ JS) -->
        <div class="detect-wrap">
          <div class="detect-card unknown" id="detect-card">
            <div class="detect-icon" id="detect-icon" style="background:var(--card2)">
              <i class="fas fa-sim-card" style="color:var(--text3)"></i>
            </div>
            <div class="detect-info">
              <div class="detect-name" id="detect-name" style="color:var(--text3)">لم يُكتشف المزود بعد</div>
              <div class="detect-sub" id="detect-sub">أدخل أول رقمين لاكتشاف الشبكة</div>
            </div>
            <div class="detect-badge" id="detect-badge" style="color:var(--text3);border-color:var(--border)">—</div>
          </div>
        </div>

        <!-- نتيجة الفحص -->
        <div class="inline-query-result" id="inline-query-result">
          <div class="iqr-header" id="iqr-header">
            <div id="iqr-net-icon" style="width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:#fff">
              <i class="fas fa-sim-card"></i>
            </div>
            <div>
              <div class="iqr-name" id="iqr-name">—</div>
              <div style="font-size:.65rem;color:var(--text2)" id="iqr-number">—</div>
            </div>
          </div>
          <div class="iqr-tabs">
            <button class="iqr-tab active" onclick="switchIqrTab('balance',this)">تفاصيل الرصيد</button>
            <button class="iqr-tab" onclick="switchIqrTab('offers',this)">باقاتي</button>
          </div>
          <div id="iqr-balance-body" class="iqr-body"></div>
          <div id="iqr-offers-body" class="iqr-body" style="display:none"></div>
        </div>

        <!-- اختيار الخدمة عند التعارض (ADSL / خط ثابت) -->
        <div id="conflict-picker" class="conflict-picker" style="display:none">
          <div class="conflict-picker-title"><i class="fas fa-question-circle"></i> اختر نوع الخدمة</div>
          <div class="conflict-option" onclick="resolveConflict(4,'إنترنت ADSL','#00aaff','wifi')">
            <div class="conflict-option-icon" style="background:#00aaff"><i class="fas fa-wifi"></i></div>
            <div class="conflict-option-name">إنترنت ADSL</div>
          </div>
          <div class="conflict-option" onclick="resolveConflict(13,'الخط الثابت','#6c757d','phone')">
            <div class="conflict-option-icon" style="background:#6c757d"><i class="fas fa-phone"></i></div>
            <div class="conflict-option-name">الخط الثابت</div>
          </div>
        </div>

        <!-- اختصارات سريعة (مخفية) -->
        <div class="numpad-hint" style="display:none">
          <div class="np-btn" onclick="prefixHint('77')"><i class="fas fa-circle" style="color:#cc0000;font-size:.5rem"></i> 77</div>
          <div class="np-btn" onclick="prefixHint('78')"><i class="fas fa-circle" style="color:#cc0000;font-size:.5rem"></i> 78</div>
          <div class="np-btn" onclick="prefixHint('71')"><i class="fas fa-circle" style="color:#ff6600;font-size:.5rem"></i> 71</div>
          <div class="np-btn" onclick="prefixHint('73')"><i class="fas fa-circle" style="color:#0066cc;font-size:.5rem"></i> 73</div>
          <div class="np-btn" onclick="prefixHint('70')"><i class="fas fa-circle" style="color:#800080;font-size:.5rem"></i> 70</div>
          <div class="np-btn" onclick="prefixHint('79')"><i class="fas fa-circle" style="color:#20c997;font-size:.5rem"></i> 79</div>
          <div class="np-btn" onclick="prefixHint('10')"><i class="fas fa-circle" style="color:#e91e8c;font-size:.5rem"></i> 10</div>
        </div>

        <!-- رقم مصغّر للمراجعة (مخفي — للتوافق) -->
        <div id="step2-number-display" style="display:none">—</div>

        <div id="bundles-section">
          <!-- تبويب دفع مسبق / فوترة -->
          <div class="ptype-tabs" id="ptype-tabs">
            <button class="ptype-tab active" data-pt="prepaid" onclick="setPtype('prepaid',this)">دفع مسبق</button>
            <button class="ptype-tab" data-pt="postpaid" onclick="setPtype('postpaid',this)">فوترة</button>
          </div>

          <!-- تبويب مبلغ / فئات / باقات -->
          <div class="section-tabs" id="section-tabs">
            <button class="section-tab" data-sec="amount"          onclick="setSection('amount',this)">💰 مبلغ</button>
            <button class="section-tab" data-sec="fees"             onclick="setSection('fees',this)">🏷️ فئات</button>
            <button class="section-tab active" data-sec="bundles"   onclick="setSection('bundles',this)">📦 باقات</button>
            <button class="section-tab" data-sec="yemen4g_change"   onclick="setSection('yemen4g_change',this)">🔄 تغيير باقة</button>
            <button class="section-tab" data-sec="yemen4g_credit"   onclick="setSection('yemen4g_credit',this)">📶 رصيد اتصال</button>
            <button class="section-tab" data-sec="yemen4g_internet" onclick="setSection('yemen4g_internet',this)">🌐 إنترنت فقط</button>
            <button class="section-tab" data-sec="yemen4g_voice"    onclick="setSection('yemen4g_voice',this)">📞 صوت فقط</button>
          </div>

          <!-- محتوى القسم -->
          <div id="bunch-list">
            <div style="text-align:center;padding:30px;color:var(--text3)"><i class="fas fa-spinner spin"></i></div>
          </div>
        </div>

        <div id="free-amount-section" style="display:none">
          <div class="sec-title">المبلغ</div>
          <div class="inp-wrap">
            <div class="inp-label">المبلغ بالريال اليمني</div>
            <input type="number" class="inp" id="free-amount" placeholder="أدخل المبلغ" min="100" step="100">
          </div>
          <div class="amount-pills">
            <?php foreach([500,1000,2000,5000,10000] as $ap): ?>
            <div class="amount-pill" onclick="setAmount(<?= $ap ?>,this)"><?= number_format($ap) ?></div>
            <?php endforeach; ?>
          </div>
        </div>

        <button class="btn-main" onclick="goStep('confirm')" style="margin-top:8px">
          التالي <i class="fas fa-arrow-left"></i>
        </button>
        <div style="height:20px"></div>
      </div><!-- /step-details -->

      <!-- Step 3: تأكيد -->
      <div id="step-confirm" style="display:none">
        <div style="padding:14px 16px 4px;display:flex;align-items:center;gap:10px">
          <div class="back-btn" onclick="goStep('details')"><i class="fas fa-arrow-right"></i></div>
          <div>
            <div style="font-size:.88rem;font-weight:900">تأكيد العملية</div>
            <div style="font-size:.67rem;color:var(--text2)">الخطوة 3 من 3</div>
          </div>
        </div>
        <div class="step-indicator">
          <div class="step-dot done"></div>
          <div class="step-dot done"></div>
          <div class="step-dot active"></div>
        </div>

        <div class="info-panel" style="margin-top:4px">
          <div class="info-row"><div class="info-key">الشبكة</div><div class="info-val" id="conf-method">—</div></div>
          <div class="info-row"><div class="info-key">رقم الهاتف</div><div class="info-val" id="conf-number">—</div></div>
          <div class="info-row"><div class="info-key">الباقة / الخدمة</div><div class="info-val" id="conf-bunch" style="max-width:180px;text-align:left;font-size:.75rem">—</div></div>
          <div class="info-row"><div class="info-key">المبلغ (ريال)</div><div class="info-val" id="conf-amount" style="color:var(--gold)">—</div></div>
          <div class="info-row"><div class="info-key">التكلفة من رصيدك</div><div class="info-val" id="conf-cost" style="color:var(--cyan)">—</div></div>
          <div class="info-row"><div class="info-key">رصيدك بعد العملية</div><div class="info-val" id="conf-after" style="color:var(--green)">—</div></div>
        </div>

        <div style="padding:0 16px 12px;font-size:.75rem;color:var(--text3);line-height:1.7">
          ⚠️ تأكد من صحة الرقم — لا يمكن استرداد المبلغ بعد التنفيذ
        </div>

        <button class="btn-main" id="confirm-btn" onclick="executeTopup()">
          <i class="fas fa-check"></i> تأكيد وتنفيذ الآن
        </button>
        <div style="height:20px"></div>
      </div><!-- /step-confirm -->

    </div><!-- /flow -->

    <!-- ════ SCREEN CHECK ════ -->
    <div class="screen" id="screen-check">
      <div class="check-screen-wrap">

        <!-- Header -->
        <div style="padding:16px 18px 0;display:flex;align-items:center;gap:12px">
          <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
          <div>
            <div style="font-size:.95rem;font-weight:900">فحص الرصيد والباقات</div>
            <div style="font-size:.68rem;color:var(--text2)">استعلم فورياً عن رصيدك وسلفتك وباقاتك</div>
          </div>
        </div>

        <!-- Hero -->
        <div class="check-hero">
          <div class="check-hero-icon">🔍</div>
          <div class="check-hero-title">فحص فوري</div>
          <div class="check-hero-sub">اختر الشبكة، أدخل الرقم، واحصل على كل التفاصيل</div>
        </div>

        <!-- شبكات -->
        <div style="padding:18px 18px 4px;font-size:.72rem;font-weight:800;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">اختر شبكتك</div>
        <div class="check-networks" id="check-method-grid">
          <?php foreach($topupMethods as $m): ?>
          <div class="check-net" data-id="<?=(int)$m['method_id']; ?>" data-name="<?=htmlspecialchars($m['name_ar'])?>" data-color="<?=htmlspecialchars($m['color'])?>" data-icon="<?=htmlspecialchars($m['icon'])?>" onclick="selectCheckMethod(this)">
            <?php if(!empty($m['logo'])): ?>
            <div class="check-net-icon" style="background:#fff;overflow:hidden"><img src="<?=SITE_URL.'/'.htmlspecialchars($m['logo'])?>" style="width:100%;height:100%;object-fit:contain;padding:3px"></div>
            <?php else: ?>
            <div class="check-net-icon" style="background:<?=htmlspecialchars($m['color'])?>"><i class="fas fa-<?=htmlspecialchars($m['icon'])?>"></i></div>
            <?php endif; ?>
            <div class="check-net-name"><?=htmlspecialchars($m['name_ar'])?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- رقم الهاتف -->
        <div class="check-inp-wrap">
          <input type="tel" class="check-inp" id="check-number"
            placeholder="أدخل رقم الهاتف"
            inputmode="numeric"
            oninput="this.value=this.value.replace(/[^0-9]/g,'');this.classList.toggle('has-val',this.value.length>0);autoDetectCheck(this.value)">
        </div>

        <!-- زر الفحص -->
        <button class="check-btn" onclick="doCheckService()" id="check-btn">
          <i class="fas fa-search"></i>
          فحص الرصيد والسلفة والباقات
        </button>

        <!-- نتيجة -->
        <div id="check-result-area"></div>
        <div style="height:20px"></div>
      </div>
    </div>

    <!-- ════ SCREEN HISTORY ════ -->
    <div class="screen" id="screen-history">
      <div style="padding:14px 16px 0;display:flex;align-items:center;gap:10px">
        <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
        <div style="font-size:.88rem;font-weight:900">سجل العمليات</div>
      </div>
      <div style="height:12px"></div>
      <div class="card" style="margin:0 16px">
        <?php if(empty($recentTx)): ?>
        <div class="empty-state"><div class="empty-icon"><i class="fas fa-history"></i></div><div class="empty-text">لا توجد عمليات سابقة</div></div>
        <?php else: ?>
        <?php foreach($recentTx as $tx):
          $color  = $statusColors[$tx['status']] ?? '#8895a7';
          $label  = $statusLabels[$tx['status']] ?? $tx['status'];
          $mcolor = $tx['method_color'] ?: '#1e6fff'; ?>
        <div class="tx-item">
          <div class="tx-icon-wrap" style="background:<?= $mcolor ?>22;color:<?= $mcolor ?>"><i class="fas fa-sim-card"></i></div>
          <div class="tx-info">
            <div class="tx-title"><?= htmlspecialchars($tx['method_name'] ?? 'شحن اتصالات') ?></div>
            <div class="tx-sub"><?= htmlspecialchars($tx['target_number']) ?> • <?= number_format((float)$tx['amount']) ?> ر.ي • <?= date('d/m/Y H:i', strtotime($tx['created_at'])) ?></div>
          </div>
          <div class="tx-status" style="color:<?= $color ?>"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div style="height:20px"></div>
    </div>


    <!-- ════ SCREEN SADAD (قائمة سداد) ════ -->
    <div class="screen" id="screen-sadad">
      <div style="padding:14px 16px 0;display:flex;align-items:center;gap:10px">
        <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
        <div>
          <div style="font-size:.92rem;font-weight:900">سداد</div>
          <div style="font-size:.67rem;color:var(--text2)">اختر الخدمة</div>
        </div>
      </div>

      <div style="padding:12px 16px;display:flex;flex-direction:column;gap:8px">
        <?php if(empty($sadadTree)): ?>
        <div class="empty-state"><div class="empty-icon">📋</div><div class="empty-text">لا توجد خدمات — أضفها من لوحة التحكم</div></div>
        <?php else: ?>
        <?php foreach($sadadTree as $ci => $cat): ?>
        <?php
          $catUid  = 'sadad-cat-'.$cat['id'];
          $hasKids = !empty($cat['children']) || !empty($cat['services']);
        ?>
        <div class="card" style="overflow:hidden;padding:0">

          <!-- صف القسم الرئيسي -->
          <div class="sadad-row" onclick="toggleSadad('<?=$catUid?>')">
            <div class="sadad-row-right">
              <?=sadadIconHtml($cat['icon']??'list',$cat['color']??'#6c3fe0',$cat['image']??null)?>
              <div class="sadad-row-name"><?=htmlspecialchars($cat['name_ar'])?></div>
            </div>
            <?php if($hasKids): ?>
            <i class="fas fa-chevron-down sadad-row-arrow" id="arr-<?=$catUid?>"></i>
            <?php endif; ?>
          </div>

          <!-- محتوى القسم -->
          <?php if($hasKids): ?>
          <div class="sadad-children" id="<?=$catUid?>">

            <?php foreach($cat['children'] as $sub): ?>
            <?php $subUid = 'sadad-sub-'.$sub['id']; ?>

              <!-- رأس القسم الفرعي -->
              <div class="sadad-row sadad-sub-header" style="background:rgba(255,255,255,.02);padding:12px 16px"
                   onclick="toggleSadad('<?=$subUid?>')">
                <div class="sadad-row-right">
                  <?=sadadIconHtml($sub['icon']??'list',$sub['color']??'#6c3fe0',$sub['image']??null,'width:32px;height:32px;border-radius:8px')?>
                  <div style="font-size:.82rem;font-weight:700"><?=htmlspecialchars($sub['name_ar'])?></div>
                </div>
                <?php if(!empty($sub['services'])): ?>
                <i class="fas fa-chevron-down sadad-row-arrow" id="arr-<?=$subUid?>"></i>
                <?php endif; ?>
              </div>

              <!-- خدمات القسم الفرعي -->
              <?php if(!empty($sub['services'])): ?>
              <div class="sadad-children" id="<?=$subUid?>">
                <?php foreach($sub['services'] as $svc): ?>
                <?php
                  if($svc['action']==='bill' && $svc['method_id']) {
                      $mx = array_filter($methods, fn($x)=>$x['method_id']==$svc['method_id']);
                      $mx = reset($mx);
                      $onclick = "openBillScreen(".((int)$svc['method_id']).",'".addslashes($mx?$mx['name_ar']:$svc['name_ar'])."','".addslashes($mx?$mx['color']:$svc['color'])."','".addslashes($mx?$mx['icon']:'file-invoice')."')";
                  } elseif($svc['action']==='external' && $svc['action_value']) {
                      $onclick = "window.open('".addslashes($svc['action_value'])."','_blank')";
                  } else {
                      $onclick = "showToast('الخدمة غير متاحة حالياً','error')";
                  }
                ?>
                <div class="sadad-item" onclick="<?=htmlspecialchars($onclick)?>" data-method-id="<?=(int)$svc['method_id']?>" data-fields="<?=htmlspecialchars(json_encode($methodDynFields[(int)$svc['method_id']] ?? [], JSON_UNESCAPED_UNICODE))?>">
                  <?=sadadIconHtml($svc['icon']??'file-invoice',$svc['color']??'#f5a623',$svc['image']??null,'width:34px;height:34px;border-radius:10px')?>
                  <div class="sadad-item-name"><?=htmlspecialchars($svc['name_ar'])?></div>
                  <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.7rem"></i>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

            <?php endforeach; ?>

            <!-- خدمات مباشرة في القسم الرئيسي -->
            <?php foreach($cat['services'] as $svc): ?>
            <?php
              if($svc['action']==='bill' && $svc['method_id']) {
                  $mx2 = array_filter($methods, fn($x)=>$x['method_id']==$svc['method_id']);
                  $mx2 = reset($mx2);
                  $onclick2 = "openBillScreen(".((int)$svc['method_id']).",'".addslashes($mx2?$mx2['name_ar']:$svc['name_ar'])."','".addslashes($mx2?$mx2['color']:$svc['color'])."','".addslashes($mx2?$mx2['icon']:'file-invoice')."')";
              } elseif($svc['action']==='external' && $svc['action_value']) {
                  $onclick2 = "window.open('".addslashes($svc['action_value'])."','_blank')";
              } else {
                  $onclick2 = "showToast('الخدمة غير متاحة حالياً','error')";
              }
            ?>
            <div class="sadad-item" onclick="<?=htmlspecialchars($onclick2)?>" data-method-id="<?=(int)$svc['method_id']?>" data-fields="<?=htmlspecialchars(json_encode($methodDynFields[(int)$svc['method_id']] ?? [], JSON_UNESCAPED_UNICODE))?>">
              <?=sadadIconHtml($svc['icon']??'file-invoice',$svc['color']??'#f5a623',$svc['image']??null,'width:34px;height:34px;border-radius:10px')?>
              <div class="sadad-item-name"><?=htmlspecialchars($svc['name_ar'])?></div>
              <i class="fas fa-chevron-left" style="color:var(--text3);font-size:.7rem"></i>
            </div>
            <?php endforeach; ?>

          </div><!-- /sadad-children -->
          <?php endif; ?>

        </div><!-- /card -->
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div style="height:20px"></div>
    </div><!-- /screen-sadad -->

    <!-- ════ SCREEN BILL OPEN (ADSL / خط ثابت) ════ -->
    <div class="screen" id="screen-bill-open">
      <div style="padding:14px 16px 0;display:flex;align-items:center;gap:10px">
        <div class="back-btn" onclick="showScreen('screen-topup-menu')"><i class="fas fa-arrow-right"></i></div>
        <div style="flex:1">
          <div style="font-size:.92rem;font-weight:900" id="bos-title">شحن</div>
          <div style="font-size:.67rem;color:var(--text2)">أدخل الرقم والمبلغ</div>
        </div>
        <div id="bos-icon" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff"></div>
      </div>

      <div style="padding:16px 16px 0;display:flex;flex-direction:column;gap:14px">

        <!-- رقم الخط -->
        <div>
          <div style="font-size:.78rem;color:var(--text2);margin-bottom:6px;font-weight:600">
            <i class="fas fa-hashtag" style="color:var(--primary)"></i> رقم الخط
          </div>
          <input type="tel" class="inp" id="bos-number"
                 placeholder="أدخل رقم الخط"
                 inputmode="numeric"
                 oninput="this.value=this.value.replace(/[^0-9]/g,'')">
        </div>

        <!-- المبلغ -->
        <div>
          <div style="font-size:.78rem;color:var(--text2);margin-bottom:6px;font-weight:600">
            <i class="fas fa-coins" style="color:var(--gold)"></i> المبلغ (ريال يمني)
          </div>
          <input type="number" class="bill-amount-inp" id="bos-amount"
                 placeholder="أدخل المبلغ"
                 min="1" step="1">
        </div>

        <!-- أزرار مبالغ سريعة -->
        <div style="display:flex;flex-wrap:wrap;gap:8px">
          <?php foreach([500,1000,2000,5000,10000] as $q): ?>
          <button onclick="document.getElementById('bos-amount').value=<?=$q?>"
                  style="flex:1;min-width:60px;padding:8px 4px;border-radius:10px;border:1px solid var(--border);
                         background:var(--card2);color:var(--text);font-family:var(--font);font-size:.8rem;
                         cursor:pointer;font-weight:700">
            <?=number_format($q)?>
          </button>
          <?php endforeach; ?>
        </div>

        <!-- زر الشحن -->
        <button class="btn-main" id="btn-bos-pay" onclick="doBillOpenPay()" style="margin:4px 0 0">
          <i class="fas fa-check-circle"></i> شحن الآن
        </button>

      </div>
      <div style="height:20px"></div>
    </div><!-- /screen-bill-open -->

    <!-- ════ SCREEN BILL (كهرباء / مياه) ════ -->
    <div class="screen" id="screen-bill">
      <div style="padding:14px 16px 0;display:flex;align-items:center;gap:10px">
        <div class="back-btn" onclick="showScreen('screen-home')"><i class="fas fa-arrow-right"></i></div>
        <div style="flex:1">
          <div style="font-size:.92rem;font-weight:900" id="bill-title">دفع فاتورة</div>
          <div style="font-size:.67rem;color:var(--text2)" id="bill-subtitle">استعلم ثم سدّد</div>
        </div>
        <div id="bill-method-icon" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff"></div>
      </div>

      <div class="bill-screen">

        <!-- رقم المشترك + زر استعلام في نفس السطر -->
        <div style="padding:14px 16px 0">
          <div style="font-size:.78rem;color:var(--text2);margin-bottom:6px;font-weight:600" id="bill-number-label">
            <i class="fas fa-hashtag" style="color:var(--primary)"></i> رقم المشترك
          </div>
          <div style="display:flex;gap:8px;align-items:stretch">
            <input type="tel" class="inp" id="bill-number"
                   placeholder="أدخل رقم المشترك"
                   inputmode="numeric"
                   style="flex:1"
                   oninput="this.value=this.value.replace(/[^0-9]/g,'');resetBillResult();checkBillReady()">
            <button id="btn-query" onclick="doBillQuery()" disabled
                    style="padding:0 16px;border-radius:12px;border:none;cursor:pointer;
                           background:linear-gradient(135deg,var(--primary),var(--cyan));
                           color:#fff;font-family:var(--font);font-size:.82rem;font-weight:700;
                           white-space:nowrap;transition:all .2s;opacity:.5;flex-shrink:0">
              <i class="fas fa-search"></i> استعلام
            </button>
          </div>
        </div>

        <!-- اختيار المنطقة / المؤسسة — ديناميكي -->
        <div class="service-selector" id="area-selector">
          <div class="service-selector-header" onclick="toggleAreaList()">
            <div>
              <div style="font-size:.7rem;color:var(--text2);margin-bottom:2px" id="area-label">اختر</div>
              <div class="service-selector-title" id="area-selected-name">اختر...</div>
            </div>
            <i class="fas fa-chevron-down" id="area-arrow" style="color:var(--text3);transition:transform .2s"></i>
          </div>
          <!-- تُملأ ديناميكياً من JS حسب المزود -->
          <div class="service-selector-body" id="dyn-area-list"></div>
        </div>

        <!-- حقول ديناميكية إضافية -->
        <div id="dyn-extra-fields" style="padding:0 16px"></div>

        <!-- نتيجة الاستعلام -->
        <div id="bill-result-area"></div>

        <!-- مبلغ السداد + زر تسديد -->
        <!-- مبلغ السداد — ظاهر دائماً، يُملأ تلقائياً بعد الاستعلام -->
        <div id="bill-pay-section">
          <div class="bill-amount-wrap" id="dyn-bill-amount-wrap" style="margin-top:14px">
            <div style="font-size:.78rem;color:var(--text2);margin-bottom:6px;font-weight:600" id="dyn-bill-amount-label">
              <i class="fas fa-coins" style="color:var(--gold)"></i> مبلغ السداد (ريال يمني)
            </div>
            <input type="number" class="bill-amount-inp" id="bill-amount"
                   placeholder="يُملأ تلقائياً بعد الاستعلام"
                   min="1" step="1">
          </div>
          <div style="padding:12px 16px 0">
            <button class="btn-main" id="btn-pay-bill" onclick="doPayBill()"
                    style="margin:0;background:linear-gradient(135deg,#f5a623,#e8950f)">
              <i class="fas fa-check-circle"></i> تسديد الفاتورة
            </button>
          </div>
        </div>

      </div><!-- /bill-screen -->
      <div style="height:20px"></div>
    </div><!-- /screen-bill -->

  </div><!-- /content -->

  <!-- BOTTOM NAV -->
  <div class="bottom-nav">
    <div class="nav-item active" id="nav-home2" onclick="window.location.href='<?=SITE_URL?>/mobile.php'">
      <div class="nav-icon"><i class="fas fa-home"></i></div>
      <div class="nav-label">الرئيسية</div>
    </div>
    <div class="nav-item" id="nav-topup2" onclick="showScreen('screen-home');showScreen('screen-home')">
      <div class="nav-icon"><i class="fas fa-wallet"></i></div>
      <div class="nav-label">شحن رصيد</div>
    </div>
    <div class="nav-center">
      <div class="nav-center-btn" onclick="showScreen('screen-home')"><i class="fas fa-th-large"></i></div>
    </div>
    <div class="nav-item" id="nav-sadad2" onclick="showScreen('screen-sadad')">
      <div class="nav-icon"><i class="fas fa-list-alt"></i></div>
      <div class="nav-label">سداد</div>
    </div>
    <div class="nav-item" id="nav-history2" onclick="showScreen('screen-history')">
      <div class="nav-icon"><i class="fas fa-history"></i></div>
      <div class="nav-label">السجل</div>
    </div>
  </div>

  <div class="toast" id="toast"></div>
</div>

<script>
const SITE_URL = '<?= SITE_URL ?>';
const USER_BAL = <?= $userBalance ?>;
const CURR_SYM = '<?= addslashes($currSymbol) ?>';
const SADAD_RATE = <?= (float)$sadadRate ?>;
const PRELOADED = <?php
  $bj = [];
  foreach ($bunches as $mid => $blist) { $bj[$mid] = $blist; }
  echo json_encode($bj, JSON_UNESCAPED_UNICODE);
?>;
const NET_LOGOS = <?php
  $nl = [];
  foreach ($methods as $m) { $nl[$m['method_id']] = $m['logo'] ? SITE_URL.'/'.$m['logo'] : ''; }
  echo json_encode($nl, JSON_UNESCAPED_UNICODE);
?>;
const METHOD_DYN_FIELDS = <?php
  // حوّل المفاتيح لـ strings لتوافق JS object keys
  $mdf_str = [];
  foreach (($methodDynFields ?? []) as $k => $v) { $mdf_str[(string)$k] = $v; }
  echo json_encode($mdf_str, JSON_UNESCAPED_UNICODE);
?>;


// ══════════════════════════════════════════════════════════════
// خريطة البادئات → method_id
// ══════════════════════════════════════════════════════════════
// Auto-detect لشركات الاتصالات فقط
// الكهرباء والمياه وباقي BILLPAY = اختيار يدوي
const PREFIX_MAP = [
  { prefixes:['77','78'],      id:1,  name:'يمن موبايل', color:'#cc0000', icon:'mobile-alt', type:'TOPUP'   },
  { prefixes:['71'],           id:2,  name:'سبأفون',      color:'#ff6600', icon:'mobile-alt', type:'TOPUP'   },
  { prefixes:['73'],           id:3,  name:'يو',           color:'#0066cc', icon:'mobile-alt', type:'TOPUP'   },
  { prefixes:['70'],           id:12, name:'واي',          color:'#800080', icon:'mobile-alt', type:'TOPUP'   },
  { prefixes:['79'],           id:17, name:'عدن نت',       color:'#20c997', icon:'network-wired', type:'TOPUP', mode:'bundles_only' },
  { prefixes:['10'],           id:16, name:'يمن فورجي',   color:'#e91e8c', icon:'signal',     type:'BILLPAY', mode:'billpay_bundles' },
  { prefixes:['01','02','03','04','05','06','07','08','09'], id:4,  name:'إنترنت ADSL',  color:'#00aaff', icon:'wifi',  type:'BILLPAY', conflict:[4,13] },
  { prefixes:['01','02','03','04','05','06','07','08','09'], id:13, name:'الخط الثابت', color:'#6c757d', icon:'phone', type:'BILLPAY', conflict:[4,13] },
];

// خدمات الدفع الممتد (BILLPAY بإدخال يدوي كـ شحن رصيد)
const EXTENDED_SERVICES = [
  { id:4,  name:'إنترنت منزلي ADSL', color:'#00aaff', icon:'wifi',    numberLabel:'رقم خط الإنترنت', hasBundles:false },
  { id:13, name:'الخط الثابت',        color:'#6c757d', icon:'phone',   numberLabel:'رقم الخط الثابت', hasBundles:false },
  { id:16, name:'يمن فورجي',          color:'#e91e8c', icon:'signal',  numberLabel:'رقم الخط',        hasBundles:true  },
];

// بيانات المزودين من PHP (للعرض في step2-badge)
const METHODS_DATA = <?php
  $md = [];
  foreach ($methods as $m) {
      $md[$m['method_id']] = ['id'=>(int)$m['method_id'],'name'=>$m['name_ar'],'color'=>$m['color'],'icon'=>$m['icon'],'type'=>$m['transaction_type']];
  }
  echo json_encode($md, JSON_UNESCAPED_UNICODE);
?>;

// ── State ──────────────────────────────────────────────────────
let S = {
  flowType: 'topup',
  methodId: null, methodName: '', methodColor: '', methodIcon: '',
  bunchId: '', bunchName: '',
  amount: 0, targetNumber: '',
  allBunches: [], validityFilter: ''
};

// ══ Navigation ═════════════════════════════════════════════════
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  window.scrollTo({top:0, behavior:'smooth'});
  document.getElementById('mainContent').scrollTop = 0;
  const map = {'screen-home':'nav-home2','screen-flow':'nav-topup2','screen-topup-menu':'nav-topup2','screen-bill-open':'nav-topup2','screen-sadad':'nav-sadad2','screen-check':'nav-check2','screen-history':'nav-history2'};
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (map[id]) document.getElementById(map[id])?.classList.add('active');
}

// فتح شاشة الشحن مع تحديد الخدمة مباشرة
function startFlowWithMethod(methodId, methodName, methodColor, methodIcon, flowMode) {
  S.flowType    = flowMode;
  S.methodId    = methodId;
  S.methodName  = methodName;
  S.methodColor = methodColor;
  S.methodIcon  = methodIcon;
  S.bunchId = ''; S.amount = 0;

  document.getElementById('flow-title').textContent = methodName;

  if (flowMode === 'billpay_open') {
    // رصيد مفتوح فقط — ADSL والخط الثابت
    openBillOpenScreen(methodId, methodName, methodColor, methodIcon);
  } else if (flowMode === 'billpay_bundles') {
    // رصيد مفتوح + باقات — يمن فورجي
    S.flowType = 'topup';
    resetDetect();
    document.getElementById('target-number').value = '';
    document.getElementById('number-clear').classList.remove('show');
    document.getElementById('inline-query-result').classList.remove('show');
    // ضبط الشبكة يدوياً
    document.getElementById('detect-name').textContent  = methodName;
    document.getElementById('detect-name').style.color  = methodColor;
    document.getElementById('detect-sub').textContent   = '✓ ' + methodName + ' — أدخل الرقم';
    document.getElementById('detect-badge').textContent = 'BILLPAY';
    document.getElementById('detect-card').className    = 'detect-card found';
    const _di1=document.getElementById('detect-icon');const _nl1=typeof NET_LOGOS!=='undefined'&&NET_LOGOS[methodId];
    if(_nl1){_di1.style.background='#fff';_di1.style.overflow='hidden';_di1.innerHTML=`<img src="${_nl1}" style="width:100%;height:100%;object-fit:contain;padding:3px">`;}
    else{_di1.style.background=methodColor;_di1.style.overflow='';_di1.innerHTML=`<i class="fas fa-${methodIcon}"></i>`;}
    showScreen('screen-flow');
    goStep('details');
    autoLoadBunchesForMethod({id:methodId, name:methodName, color:methodColor, icon:methodIcon, type:'BILLPAY'});
  } else {
    // شحن هاتف عادي
    S.flowType = 'topup';
    resetDetect();
    document.getElementById('target-number').value = '';
    document.getElementById('number-clear').classList.remove('show');
    document.getElementById('inline-query-result').classList.remove('show');
    showScreen('screen-flow');
    goStep('details');
  }
}

// شاشة بسيطة: رقم + مبلغ مفتوح (ADSL / خط ثابت)
let billOpenState = {};
function openBillOpenScreen(methodId, methodName, methodColor, methodIcon) {
  billOpenState = { methodId, methodName, methodColor, methodIcon };
  document.getElementById('bos-title').textContent  = methodName;
  document.getElementById('bos-icon').style.background = methodColor;
  document.getElementById('bos-icon').innerHTML     = `<i class="fas fa-${methodIcon}"></i>`;
  document.getElementById('bos-number').value       = '';
  document.getElementById('bos-amount').value       = '';
  showScreen('screen-bill-open');
}
function doBillOpenPay() {
  const num = document.getElementById('bos-number').value.trim();
  const amt = parseFloat(document.getElementById('bos-amount').value || 0);
  if (!num)    { showToast('أدخل رقم الخط أولاً','error'); return; }
  if (amt <= 0){ showToast('أدخل المبلغ','error'); return; }

  const btn = document.getElementById('btn-bos-pay');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner spin"></i> جاري الشحن...';

  const fd = new FormData();
  fd.append('ajax_action',   'do_topup');
  fd.append('target_number', num);
  fd.append('method_id',     billOpenState.methodId);
  fd.append('bunch_id',      '0102'); // رصيد مفتوح
  fd.append('amount',        amt);
  fd.append('pay_from',      'balance');

  fetch(location.href, {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> شحن الآن';
      if (d.ok) {
        showToast(d.msg || '✅ تم الشحن بنجاح!', 'success');
        if (d.new_balance !== undefined)
          document.getElementById('live-balance').textContent =
            Number(d.new_balance).toFixed(4) + ' ' + CURR_SYM;
        setTimeout(() => showScreen('screen-home'), 1800);
      } else {
        showToast(d.msg || 'فشل الشحن', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> شحن الآن';
      showToast('خطأ في الاتصال', 'error');
    });
}

function startFlow(type) {
  S.flowType = type; S.methodId = null; S.bunchId = ''; S.amount = 0;
  const titles = {topup:'شحن رصيد', billpay:'دفع فواتير', bundles:'باقات وعروض'};
  document.getElementById('flow-title').textContent = titles[type] || 'الخدمة';

  if (type === 'billpay') {
    showScreen('screen-flow');
    goStep('method-manual');
  } else {
    resetDetect();
    document.getElementById('target-number').value = '';
    document.getElementById('number-clear').classList.remove('show');
    document.getElementById('inline-query-result').classList.remove('show');
    showScreen('screen-flow');
    goStep('details');
  }
}

// ══ اكتشاف الشبكة من الرقم ════════════════════════════════════
function detectFromNumber(num) {
  if (!num || num.length < 2) return null;
  // تحقق من تعارض — إذا بادئتان لهما نفس الـ prefix
  const prefix2 = num.substring(0, 2);
  const matches = PREFIX_MAP.filter(m => m.prefixes.includes(prefix2));
  if (matches.length > 1 && matches[0].conflict) {
    return { ...matches[0], isConflict:true, conflicts:matches };
  }
  // جرّب من أطول بادئة إلى أقصر (3 أرقام ثم 2)
  for (const m of PREFIX_MAP) {
    for (const p of m.prefixes) {
      if (num.startsWith(p)) return m;
    }
  }
  return null;
}

function onNumberInput(inp) {
  const val = inp.value.replace(/[^0-9]/g, '');
  inp.value = val;

  // إظهار/إخفاء زر المسح
  document.getElementById('number-clear').classList.toggle('show', val.length > 0);

  if (val.length === 0) { resetDetect(); return; }

  const detected = detectFromNumber(val);

  const card  = document.getElementById('detect-card');
  const icon  = document.getElementById('detect-icon');
  const name  = document.getElementById('detect-name');
  const sub   = document.getElementById('detect-sub');
  const badge = document.getElementById('detect-badge');
  if (detected) {
    // شبكة مكتشفة
    S.methodId    = detected.id;
    S.methodName  = detected.name;
    S.methodColor = detected.color;
    S.methodIcon  = detected.icon;

    // إذا تعارض — أظهر picker
    if (detected.isConflict) {
      card.className = 'detect-card unknown';
      icon.style.background = 'var(--card2)';
      icon.innerHTML = '<i class="fas fa-question-circle" style="color:var(--gold)"></i>';
      name.textContent = 'اختر نوع الخدمة';
      name.style.color = 'var(--gold)';
      sub.textContent  = 'هذه البادئة مشتركة — اختر من القائمة أدناه';
      badge.textContent = '?';
      badge.style.color = 'var(--gold)';
      badge.style.borderColor = 'rgba(245,166,35,.4)';
      badge.style.background  = 'rgba(245,166,35,.1)';
      document.getElementById('conflict-picker').style.display = '';
      S.methodId = null;
      inp.classList.remove('detected');
      return;
    }
    document.getElementById('conflict-picker').style.display = 'none';

    card.className  = 'detect-card found';
    const _nl2=typeof NET_LOGOS!=='undefined'&&NET_LOGOS[detected.id];
    if(_nl2){icon.style.background='#fff';icon.style.overflow='hidden';icon.innerHTML=`<img src="${_nl2}" style="width:100%;height:100%;object-fit:contain;padding:3px">`;}
    else{icon.style.background=detected.color;icon.style.overflow='';icon.innerHTML=`<i class="fas fa-${detected.icon}"></i>`;}
    name.textContent = detected.name;
    name.style.color = detected.color;
    sub.textContent  = '✓ ' + detected.name + ' — اختر الباقة أدناه';
    badge.textContent = detected.type;
    badge.style.color       = detected.color;
    badge.style.borderColor = detected.color + '44';
    badge.style.background  = detected.color + '15';

    inp.classList.add('detected');

    // إذا BILLPAY open → غيّر زر التالي لـ "شحن مباشر"
    if (detected.type === 'BILLPAY' && detected.mode === 'billpay_open') {
      // انتقل مباشرة لشاشة المبلغ المفتوح عند ضغط زر التالي
      // نحتفظ بـ S.methodId و نخفي باقي الخطوات
    }

    // حمّل الباقات تلقائياً عند تغيير الشبكة
    if (detected.id !== prevMethodId) {
      autoLoadBunchesForMethod(detected);
    }

  } else {
    // شبكة غير معروفة
    S.methodId = null; S.methodName = ''; S.methodColor = ''; S.methodIcon = '';
    card.className = 'detect-card unknown';
    icon.style.background = 'var(--card2)';
    icon.innerHTML  = '<i class="fas fa-question-circle" style="color:var(--text3)"></i>';
    name.textContent = 'شبكة غير معروفة';
    name.style.color = 'var(--text3)';
    sub.textContent  = 'تأكد من الرقم أو اختر المزود يدوياً';
    badge.textContent = '—';
    badge.style.color = 'var(--text3)';
    badge.style.borderColor = 'var(--border)';
    badge.style.background = 'transparent';
    inp.classList.remove('detected');
  }
}

function clearNumber() {
  const inp = document.getElementById('target-number');
  inp.value = '';
  inp.classList.remove('detected');
  document.getElementById('number-clear').classList.remove('show');
  resetDetect();
  document.getElementById('conflict-picker').style.display = 'none';
  inp.focus();
}

function resetDetect() {
  S.methodId = null; S.methodName = ''; S.methodColor = ''; S.methodIcon = '';
  const card  = document.getElementById('detect-card');
  const icon  = document.getElementById('detect-icon');
  const name  = document.getElementById('detect-name');
  const sub   = document.getElementById('detect-sub');
  const badge = document.getElementById('detect-badge');
  // btn-next-step1 removed

  card.className  = 'detect-card unknown';
  icon.style.background = 'var(--card2)';
  icon.innerHTML  = '<i class="fas fa-sim-card" style="color:var(--text3)"></i>';
  name.textContent = 'لم يُكتشف المزود بعد';
  name.style.color = 'var(--text3)';
  sub.textContent  = 'أدخل أول رقمين لاكتشاف الشبكة تلقائياً';
  badge.textContent = '—';
  badge.style.color = 'var(--text3)';
  badge.style.borderColor = 'var(--border)';
  badge.style.background = 'transparent';
  // nextBtn removed

  const iqr = document.getElementById('inline-query-result');
  if (iqr) iqr.classList.remove('show');
}

// ── فحص الرصيد المضمّن ──────────────────────────────────────
function doInlineQuery() {
  const num = document.getElementById('target-number').value.replace(/[^0-9]/g,'');
  if (!num) { showToast('أدخل رقم الهاتف أولاً','error'); return; }
  if (!S.methodId) { showToast('اكتشف الشبكة أولاً — أدخل رقماً يبدأ بـ 77 أو 71...','error'); return; }

  const btn = document.getElementById('btn-inline-query');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner spin"></i>';

  const result = document.getElementById('inline-query-result');
  result.classList.remove('show');

  const fd = new FormData();
  fd.append('ajax_action',   'check_service');
  fd.append('target_number', num);
  fd.append('method_id',     S.methodId);
  fd.append('bunch_id',      '340');

  fetch(location.href, {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-search"></i> فحص';

      if (d.ok && d.data) {
        renderInlineResult(d.data, num);
      } else {
        showToast(d.msg || 'فشل الاستعلام', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-search"></i> فحص';
      showToast('خطأ في الاتصال', 'error');
    });
}

function renderInlineResult(data, num) {
  // Header
  document.getElementById('iqr-name').textContent   = num;
  document.getElementById('iqr-number').textContent = num;
  const iconEl = document.getElementById('iqr-net-icon');
  iconEl.style.background = S.methodColor || 'var(--primary)';
  iconEl.innerHTML = `<i class="fas fa-${S.methodIcon||'sim-card'}"></i>`;

  // تبويب الرصيد
  const balRows = [
    ['مبلغ الفاتورة', data.invoice_amount != null ? Number(data.invoice_amount).toLocaleString() + ' ر.ي' : '0'],
    ['الرصيد',        data.balance         != null ? Number(data.balance).toLocaleString()         + ' ر.ي' : '—'],
    ['السلفة',        data.loan > 0 ? Number(data.loan).toLocaleString() + ' ر.ي' : 'لا توجد سلفة'],
  ];
  document.getElementById('iqr-balance-body').innerHTML = balRows.map(([k,v]) =>
    `<div class="iqr-row"><div class="iqr-key">${k}</div><div class="iqr-val">${e(String(v))}</div></div>`
  ).join('');

  // تبويب الباقات المشتركة
  const offers = data.offers || [];
  document.getElementById('iqr-offers-body').innerHTML = offers.length
    ? offers.map(o => `
        <div class="iqr-offer">
          <div class="iqr-offer-name">${e(o.offer_name||o.name||'باقة')}</div>
          <div class="iqr-offer-id">${e(String(o.offer_id||''))}</div>
        </div>`).join('')
    : '<div style="text-align:center;padding:16px;color:var(--text3);font-size:.82rem">لا توجد باقات مشتركة</div>';

  // أظهر النتيجة
  document.getElementById('inline-query-result').classList.add('show');
  switchIqrTab('balance', document.querySelector('.iqr-tab'));
}

function switchIqrTab(tab, el) {
  document.querySelectorAll('.iqr-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('iqr-balance-body').style.display = tab === 'balance' ? '' : 'none';
  document.getElementById('iqr-offers-body').style.display  = tab === 'offers'  ? '' : 'none';
}

// ── تحميل الباقات تلقائياً عند اكتشاف الشبكة ───────────────
let prevMethodId = null;
function autoLoadBunchesForMethod(detected) {
  prevMethodId = detected.id;

  // ضبط التبويبات
  const hasAmount = (detected.id === 1);
  const amountTab = document.querySelector('.section-tab[data-sec="amount"]');
  if (amountTab) amountTab.style.display = hasAmount ? '' : 'none';

  const hasPtype = (detected.id === 1 || detected.id === 2);
  document.getElementById('ptype-tabs').style.display = hasPtype ? '' : 'none';
  currentPtype = hasPtype ? 'prepaid' : 'both';
  if (hasPtype) {
    document.querySelectorAll('.ptype-tab').forEach(t => t.classList.toggle('active', t.dataset.pt === 'prepaid'));
  }
  currentSection = 'bundles';

  // badge
  document.getElementById('step2-badge-icon').innerHTML = `<i class="fas fa-${detected.icon}" style="color:${detected.color}"></i>`;
  document.getElementById('step2-badge-name').textContent = detected.name;
  document.getElementById('step2-title').textContent = detected.name;

  document.getElementById('bundles-section').style.display = '';
  document.getElementById('free-amount-section').style.display = 'none';
  loadBunches(detected.id);
}

// ── اختصار البادئة ─────────────────────────────────────────
function prefixHint(prefix) {
  const inp = document.getElementById('target-number');
  inp.value = prefix;
  onNumberInput(inp);
  inp.focus();
}

// ── الانتقال لخطوة 2 ─────────────────────────────────────────
function proceedToStep2() {
  const num = document.getElementById('target-number').value.replace(/[^0-9]/g, '');
  if (!num)         { showToast('أدخل الرقم أولاً', 'error'); return; }
  if (!S.methodId)  { showToast('لم يتم التعرف على الشبكة — تأكد من الرقم', 'error'); return; }

  S.targetNumber = num;
  goStep('confirm');
}

// billpay uses selectManualMethod() via step-method-manual

// ══ Steps ══════════════════════════════════════════════════════
function goStep(step) {
  ['step-method-manual','step-method','step-details','step-confirm'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  if (step === 'method-manual') document.getElementById('step-method-manual').style.display = '';
  if (step === 'method')        document.getElementById('step-details').style.display  = ''; // مدمج
  if (step === 'details')       document.getElementById('step-details').style.display = '';
  if (step === 'confirm') { if (!validateStep2()) return; buildConfirm(); document.getElementById('step-confirm').style.display = ''; }
  document.getElementById('mainContent').scrollTop = 0;
}

// اختيار مزود يدوي (للفواتير)
function selectManualMethod(el) {
  document.querySelectorAll('#manual-method-grid .method-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  S.methodId    = parseInt(el.dataset.id);
  S.methodName  = el.dataset.name;
  S.methodColor = el.dataset.color || '#6c3fe0';
  S.methodIcon  = el.dataset.icon  || 'sim-card';
  S.bunchId = ''; S.amount = 0;

  // إعداد step2
  document.getElementById('step2-badge-icon').innerHTML = `<i class="fas fa-${S.methodIcon}" style="color:${S.methodColor}"></i>`;
  document.getElementById('step2-badge-name').textContent = S.methodName;
  document.getElementById('step2-title').textContent = S.methodName;
  document.getElementById('step2-number-display').textContent = '—';
  // إعادة ضبط التبويبات — BILLPAY لا تبويب مبلغ ولا prepaid/postpaid
  const amountTabM = document.querySelector('.section-tab[data-sec="amount"]');
  if (amountTabM) amountTabM.style.display = 'none';
  document.getElementById('ptype-tabs').style.display = 'none';
  currentPtype   = 'both';
  currentSection = 'bundles';
  document.querySelectorAll('.section-tab').forEach(t => t.classList.toggle('active', t.dataset.sec === 'bundles'));

  // في الفواتير: نذهب مباشرة لخطوة 2 ونطلب رقم الحساب/العداد
  loadBunches(S.methodId);
  document.getElementById('bundles-section').style.display = '';
  document.getElementById('free-amount-section').style.display = 'none';

  // تغيير placeholder حقل الرقم لفواتير
  const numInp = document.getElementById('target-number');
  numInp.placeholder = S.methodName === 'الكهرباء' ? 'رقم العداد أو الحساب' :
                       S.methodName === 'المياه'    ? 'رقم الاشتراك'        :
                       S.methodName === 'الخط الثابت' ? 'رقم الهاتف الأرضي' : 'رقم الحساب';
  numInp.value = '';
  document.getElementById('number-clear').classList.remove('show');

  goStep('details');
  showScreen('screen-flow');
}

// ══ تحميل الباقات ══════════════════════════════════════════════
function loadBunches(mid) {
  const list = document.getElementById('bunch-list');
  if (PRELOADED[mid]?.length) { S.allBunches = PRELOADED[mid]; renderBunches(S.allBunches,'',''); return; }
  list.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text3)"><i class="fas fa-spinner spin"></i></div>';
  const fd = new FormData(); fd.append('ajax_action','get_bunches'); fd.append('method_id',mid);
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){ S.allBunches=d.bunches; renderBunches(d.bunches,'',''); }
    else list.innerHTML='<div class="empty-state"><div class="empty-icon">⚠️</div><div class="empty-text">'+(d.msg||'لا توجد باقات')+'</div></div>';
  }).catch(()=>list.innerHTML='<div class="empty-state"><div class="empty-text">فشل التحميل</div></div>');
}

// ══ حالة التبويبات ══════════════════════════════════════════════
let currentPtype   = 'prepaid';
let currentSection = 'bundles';

function setPtype(pt, el) {
  currentPtype = pt;
  document.querySelectorAll('.ptype-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  renderCurrentSection();
}

function setSection(sec, el) {
  currentSection = sec;
  document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');

  // فحص تلقائي عند اختيار قسم الباقات
  const BUNDLE_SECS_AUTO = ['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice','fees'];
  if (BUNDLE_SECS_AUTO.includes(sec) && S.methodId) {
    const num = document.getElementById('target-number').value.replace(/[^0-9]/g,'');
    if (num.length >= 7) {
      // فحص تلقائي بدون إظهار نتيجة كاملة — فقط لجلب الباقات المشتركة
      const iqrResult = document.getElementById('inline-query-result');
      if (!iqrResult.classList.contains('show')) {
        doInlineQuery();
      }
    }
  }

  const AMOUNT_SECS = ['amount', 'yemen4g_credit'];

  if (AMOUNT_SECS.includes(sec)) {
    // مبلغ مفتوح
    S.bunchId   = sec === 'yemen4g_credit' ? '0104' : '340';
    S.bunchName = sec === 'yemen4g_credit' ? 'رصيد اتصال' : 'رصيد';
    S.amount    = 0;
    document.getElementById('free-amount-section').style.display = '';
    document.getElementById('free-amount').value = '';
    document.querySelectorAll('.amount-pill').forEach(p => p.classList.remove('active'));
    document.getElementById('bunch-list').innerHTML =
      '<div style="padding:16px;text-align:center;color:var(--text2);font-size:.85rem">✅ اكتب المبلغ أدناه</div>';
    return;
  }
  document.getElementById('free-amount-section').style.display = 'none';
  renderCurrentSection();
}

function renderCurrentSection() {
  if (currentSection === 'amount') return;
  const list = document.getElementById('bunch-list');
  if (!S.allBunches.length) {
    list.innerHTML='<div class="empty-state"><div class="empty-icon">📭</div><div class="empty-text">لا توجد بيانات</div></div>';
    return;
  }

  // تصفية حسب نوع الدفع والقسم
  let items = S.allBunches.filter(b => {
    const pt  = b.payment_type || 'both';  // NULL → both
    const sec = b.section || 'bundles'; // NULL → bundles

    // فلتر نوع الدفع
    const ptOk = (currentPtype === 'both')
              || (pt === 'both')
              || (pt === currentPtype);

    // فلتر القسم — جمّع الأنواع المتشابهة
    const BUNDLE_SECS2 = ['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice'];
    const AMOUNT_SECS2 = ['amount','yemen4g_credit'];
    let secOk = false;
    if (currentSection === 'bundles') secOk = BUNDLE_SECS2.includes(sec);
    else if (currentSection === 'amount') secOk = AMOUNT_SECS2.includes(sec);
    else secOk = (sec === currentSection);

    return ptOk && secOk;
  });

  // إذا لا يوجد شيء وكان القسم bundles → جرّب عرض الكل بدون فلتر payment_type
  if (!items.length && currentSection === 'bundles') {
    items = S.allBunches.filter(b => (b.section || 'bundles') === 'bundles');
  }

  if (!items.length) {
    list.innerHTML='<div class="empty-state"><div class="empty-icon">📭</div><div class="empty-text">لا توجد عناصر في هذا القسم</div></div>';
    return;
  }

  // أنواع تُعرض كـ fees
  const feeTypes    = ['fees'];
  // أنواع تُعرض كـ accordion/bundles
  const bundleTypes = ['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice'];
  // أنواع تُعرض كـ مبلغ مفتوح
  const amountTypes = ['amount','yemen4g_credit'];

  if (feeTypes.includes(currentSection)) {
    renderFees(items);
  } else {
    renderAccordion(items);
  }
}

// ── عرض الفئات (grid) ────────────────────────────────────────
// استخراج السعر للعرض — يستخدم price أو يستخرجه من الاسم
function getFeeDisplay(b) {
  if (b.price && b.price > 0) return Number(b.price).toLocaleString() + ' ر.ي';
  // استخرج رقم من الاسم مثل "فئة 410" أو "40 وحده"
  const m = String(b.bunch_name).match(/[\d,]+/);
  if (m) return m[0].replace(',','') ;
  return b.unified_code || '—';
}

function renderFees(items) {
  const list = document.getElementById('bunch-list');
  list.innerHTML = `<div class="fees-grid">${items.map(b => `
    <div class="fee-item" data-unified="${e(b.unified_code)}" data-name="${e(b.bunch_name)}"
         data-amount="${b.price||0}" data-is-free="0" onclick="selectFee(this)">
      <div class="fee-price">${getFeeDisplay(b)}</div>
      <div class="fee-name">${e(b.bunch_name)}</div>
    </div>`).join('')}
  </div>`;
}

function selectFee(el) {
  document.querySelectorAll('.fee-item').forEach(f => f.classList.remove('selected'));
  el.classList.add('selected');
  S.bunchId   = el.dataset.unified;
  S.bunchName = el.dataset.name;
  S.amount    = parseFloat(el.dataset.amount) || 0;
  document.getElementById('free-amount-section').style.display = 'none';
}

// ── عرض الباقات (accordion) ──────────────────────────────────
function renderAccordion(items) {
  const list = document.getElementById('bunch-list');

  // تجميع حسب bundle_group
  const groups = {};
  items.forEach(b => {
    const g = b.bundle_group || 'أخرى';
    if (!groups[g]) groups[g] = [];
    groups[g].push(b);
  });

  let html = '<div class="accordion-wrap">';
  Object.entries(groups).forEach(([group, bunches], gi) => {
    const uid = 'acc-' + gi;
    html += `
      <div class="accordion-item">
        <div class="accordion-header" onclick="toggleAcc('${uid}')">
          <div style="display:flex;align-items:center;gap:8px">
            <span class="accordion-title">${e(group)}</span>
            <span class="accordion-count">${bunches.length}</span>
          </div>
          <i class="fas fa-chevron-down accordion-arrow" id="arr-${uid}"></i>
        </div>
        <div class="accordion-body" id="${uid}">
          ${bunches.map(b => `
            <div class="bunch-item" style="border-radius:0;border:none;border-bottom:1px solid var(--border)"
                 data-unified="${e(b.unified_code)}" data-name="${e(b.bunch_name)}"
                 data-amount="${b.price||0}" data-is-free="0"
                 onclick="selectBunch(this)">
              <div class="bunch-dot"></div>
              <div class="bunch-info">
                <div class="bunch-name">${e(b.bunch_name)}</div>
                <div class="bunch-meta">
                  ${b.validity ? `<span style="background:rgba(30,111,255,.12);color:#6ba3ff;padding:1px 6px;border-radius:6px;font-size:.65rem;margin-left:4px">${e(b.validity)}</span>` : ''}
                  <span style="font-family:monospace;font-size:.62rem;color:var(--text3)">${e(b.unified_code)}</span>
                </div>
              </div>
              <div style="text-align:left;flex-shrink:0">
                ${b.price && b.price > 0
                  ? `<div class="price-badge">${Number(b.price).toLocaleString()} ر.ي</div>`
                  : `<div style="font-size:.68rem;color:var(--text3)">—</div>`}
              </div>
            </div>`).join('')}
        </div>
      </div>`;
  });
  html += '</div>';
  list.innerHTML = html;
}

function toggleAcc(uid) {
  const body = document.getElementById(uid);
  const arr  = document.getElementById('arr-' + uid);
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  arr.classList.toggle('open', !isOpen);
}

function renderBunches(bunches, validity, search) {
  S.allBunches = bunches;

  // هل فيه باقات فئات؟ (NULL يُعامَل كـ 'bundles')
  const BUNDLE_SECS = ['bundles','yemen4g_change','yemen4g_internet','yemen4g_voice'];
  const AMOUNT_SECS = ['amount','yemen4g_credit'];
  const hasFees    = bunches.some(b => b.section === 'fees');
  const hasAmount  = bunches.some(b => AMOUNT_SECS.includes(b.section));
  const hasBundles = bunches.some(b => !b.section || BUNDLE_SECS.includes(b.section));

  // إظهار/إخفاء تبويب المبلغ
  const amountTab = document.querySelector('.section-tab[data-sec="amount"]');
  if (amountTab) amountTab.style.display = hasAmount ? '' : 'none';

  // إظهار/إخفاء تبويب الفئات
  const feesTab = document.querySelector('.section-tab[data-sec="fees"]');
  if (feesTab) feesTab.style.display = hasFees ? '' : 'none';

  // أظهر/أخفِ التبويبات الجديدة بناءً على وجود بيانات
  const secTypes = {
    'yemen4g_change':   bunches.some(b => b.section === 'yemen4g_change'),
    'yemen4g_credit':   bunches.some(b => b.section === 'yemen4g_credit'),
    'yemen4g_internet': bunches.some(b => b.section === 'yemen4g_internet'),
    'yemen4g_voice':    bunches.some(b => b.section === 'yemen4g_voice'),
  };
  Object.entries(secTypes).forEach(([sec, has]) => {
    const tab = document.querySelector(`.section-tab[data-sec="${sec}"]`);
    if (tab) tab.style.display = has ? '' : 'none';
  });

  // اختر القسم الافتراضي المنطقي دائماً
  // ابحث عن أول قسم متاح بالأولوية
  const firstAvail = (
    (hasBundles          && 'bundles') ||
    (hasFees             && 'fees')    ||
    (hasAmount           && 'amount')  ||
    (secTypes['yemen4g_change']   && 'yemen4g_change')   ||
    (secTypes['yemen4g_credit']   && 'yemen4g_credit')   ||
    (secTypes['yemen4g_internet'] && 'yemen4g_internet') ||
    (secTypes['yemen4g_voice']    && 'yemen4g_voice')    ||
    'bundles'
  );
  currentSection = firstAvail;

  // اضبط القسم النشط
  document.querySelectorAll('.section-tab').forEach(t => {
    const active = t.dataset.sec === currentSection && t.style.display !== 'none';
    t.classList.toggle('active', active);
  });

  document.getElementById('free-amount-section').style.display = 'none';
  renderCurrentSection();
}

function bunchCard(b, isFree) { return ''; } // legacy — unused

function e(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') }
function filterBunches(search){ renderCurrentSection(); }

// validity-filter removed — using ptype/section tabs instead

function selectBunch(el) {
  // إزالة التحديد من كل العناصر
  document.querySelectorAll('.bunch-item').forEach(b => b.classList.remove('selected'));
  el.classList.add('selected');
  S.bunchId   = el.dataset.unified;
  S.bunchName = el.dataset.name;
  S.amount    = parseFloat(el.dataset.amount) || 0;
  document.getElementById('free-amount-section').style.display = 'none';
}

function setAmount(val, el) {
  document.getElementById('free-amount').value = val;
  document.querySelectorAll('.amount-pill').forEach(p => p.classList.remove('active'));
  el.classList.add('active'); S.amount = val;
}

// ══ Validate & Confirm ══════════════════════════════════════════
function validateStep2() {
  // تأكد من وجود رقم الهاتف في S.targetNumber
  if (!S.targetNumber) {
    const num = document.getElementById('target-number').value.replace(/[^0-9]/g,'');
    if (num.length < 7) { showToast('أدخل رقم الهاتف أو الحساب','error'); return false; }
    S.targetNumber = num;
  }
  if (!S.bunchId) { showToast('اختر باقة أو خدمة','error'); return false; }
  const fa = parseFloat(document.getElementById('free-amount').value||0);
  const finalAmt = S.amount > 0 ? S.amount : fa;
  if (finalAmt <= 0) { showToast('أدخل المبلغ','error'); return false; }
  S.amount = finalAmt;
  return true;
}

function buildConfirm() {
  const costUsd = SADAD_RATE > 0 ? Number(S.amount / SADAD_RATE).toFixed(6) : '—';
  const after   = SADAD_RATE > 0 ? Number(USER_BAL - (S.amount / SADAD_RATE)).toFixed(4) : '—';
  document.getElementById('conf-method').textContent  = S.methodName;
  document.getElementById('conf-number').textContent  = S.targetNumber;
  document.getElementById('conf-bunch').textContent   = S.bunchName;
  document.getElementById('conf-amount').textContent  = Number(S.amount).toLocaleString() + ' ريال';
  document.getElementById('conf-cost').textContent    = costUsd + ' ' + CURR_SYM;
  document.getElementById('conf-after').textContent   = after + ' ' + CURR_SYM;
}

// ══ Execute ══════════════════════════════════════════════════════
function executeTopup() {
  const btn = document.getElementById('confirm-btn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner spin"></i> جاري التنفيذ...';
  const fd = new FormData();
  fd.append('ajax_action','do_topup'); fd.append('target_number',S.targetNumber);
  fd.append('method_id',S.methodId); fd.append('bunch_id',S.bunchId);
  fd.append('amount',S.amount); fd.append('pay_from','balance');
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML='<i class="fas fa-check"></i> تأكيد وتنفيذ الآن';
    if(d.ok){
      showToast(d.msg||'✅ تم بنجاح!','success');
      if(d.new_balance!==undefined) document.getElementById('live-balance').textContent=Number(d.new_balance).toFixed(4)+' '+CURR_SYM;
      setTimeout(()=>{ showScreen('screen-history'); location.reload(); },1800);
    } else showToast(d.msg||'فشل التنفيذ','error');
  }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fas fa-check"></i> تأكيد وتنفيذ الآن'; showToast('خطأ في الاتصال','error'); });
}

// ══ Check Service ════════════════════════════════════════════════
let checkMethodId = null;
function selectCheckMethod(el) {
  document.querySelectorAll('#check-method-grid .check-net').forEach(n=>n.classList.remove('selected'));
  el.classList.add('selected'); checkMethodId = parseInt(el.dataset.id);
}

// Auto-detect في شاشة الفحص أيضاً
document.addEventListener('DOMContentLoaded', function(){
  const chkInp = document.getElementById('check-number');
  if (chkInp) {
    chkInp.addEventListener('input', function(){
      const val = this.value.replace(/[^0-9]/g,'');
      this.value = val;
      if (val.length >= 2) {
        const detected = detectFromNumber(val);
        if (detected) {
          checkMethodId = detected.id;
          // حدّث بطاقة الشبكة في شاشة الفحص إن وجدت
          document.querySelectorAll('#check-method-grid .check-net').forEach(c => {
            c.classList.toggle('selected', parseInt(c.dataset.id) === detected.id);
          });
        }
      }
    });
  }
});

function doCheckService() {
  const num = document.getElementById('check-number').value.replace(/[^0-9]/g,'');
  // Auto-detect إذا لم يختر يدوياً
  if (!checkMethodId && num.length >= 2) {
    const d = detectFromNumber(num);
    if (d) checkMethodId = d.id;
  }
  if (!checkMethodId) { showToast('اختر الشبكة أولاً أو أدخل رقماً صحيحاً','error'); return; }
  if (num.length < 7)  { showToast('أدخل رقم هاتف صحيح','error'); return; }
  const btn = document.getElementById('check-btn');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner spin"></i> جاري الاستعلام...';
  document.getElementById('check-result-area').innerHTML = '';
  const fd = new FormData(); fd.append('ajax_action','check_service'); fd.append('target_number',num); fd.append('method_id',checkMethodId); fd.append('bunch_id','340');
  fetch(location.href,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    btn.disabled=false; btn.innerHTML='<i class="fas fa-search"></i> فحص الآن';
    if(d.ok&&d.data) renderCheck(d.data,num);
    else document.getElementById('check-result-area').innerHTML=`<div class="card" style="margin:0 16px"><div style="padding:20px;text-align:center;color:var(--red)">${d.msg||'فشل الاستعلام'}</div></div>`;
  }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fas fa-search"></i> فحص الآن'; showToast('خطأ في الاتصال','error'); });
}

function renderCheck(data,num) {
  const offers  = data.offers||[];
  const netEl   = document.querySelector('#check-method-grid .check-net.selected');
  const netColor= netEl?.dataset.color || 'var(--primary)';
  const netIcon = netEl?.dataset.icon  || 'sim-card';
  const netName = netEl?.dataset.name  || '';

  const hasLoan   = parseFloat(data.loan||0) > 0;
  const balColor  = '#10b981';
  const loanColor = hasLoan ? '#f59e0b' : 'var(--text3)';

  const offersHtml = offers.length
    ? offers.map(o=>`
        <div class="crc-offer-row">
          <div class="crc-offer-dot"></div>
          <div class="crc-offer-name">${e(o.offer_name||'—')}</div>
          <div class="crc-offer-id">${e(o.offer_id||'')}</div>
        </div>`).join('')
    : `<div class="crc-empty-offers"><i class="fas fa-inbox" style="opacity:.3;font-size:1.5rem;display:block;margin-bottom:6px"></i>لا توجد باقات مشتركة</div>`;

  document.getElementById('check-result-area').innerHTML = `
    <div class="check-result-card">

      <!-- Header -->
      <div class="crc-header">
        <div class="crc-net-icon" style="background:${netColor}">
          <i class="fas fa-${netIcon}"></i>
        </div>
        <div>
          <div class="crc-title">${e(netName)} — نتيجة الفحص</div>
          <div class="crc-num">${e(num)}</div>
        </div>
        <div style="margin-right:auto;background:rgba(16,185,129,.12);color:#10b981;font-size:.7rem;font-weight:800;padding:4px 12px;border-radius:20px;border:1px solid rgba(16,185,129,.2)">
          <i class="fas fa-check-circle"></i> ناجح
        </div>
      </div>

      <!-- Stats -->
      <div class="crc-stats">
        <div class="crc-stat">
          <div class="crc-stat-icon" style="background:rgba(16,185,129,.12)">
            <i class="fas fa-wallet" style="color:#10b981"></i>
          </div>
          <div class="crc-stat-label">الرصيد المتاح</div>
          <div class="crc-stat-val" style="color:${balColor}">${e(String(data.balance||'—'))}</div>
          <div class="crc-stat-unit">ريال يمني</div>
        </div>
        <div class="crc-stat">
          <div class="crc-stat-icon" style="background:${hasLoan?'rgba(245,158,11,.12)':'rgba(255,255,255,.04)'}">
            <i class="fas fa-hand-holding-usd" style="color:${loanColor}"></i>
          </div>
          <div class="crc-stat-label">السلفة</div>
          <div class="crc-stat-val" style="color:${loanColor}">${hasLoan?e(String(data.loan)):'لا يوجد'}</div>
          <div class="crc-stat-unit">${hasLoan?'ريال يمني':''}</div>
        </div>
      </div>

      <!-- Offers -->
      <div class="crc-offers-wrap">
        <div class="crc-offers-head">
          <div class="crc-offers-title">الباقات المشتركة</div>
          <div class="crc-offers-count">${offers.length} باقة</div>
        </div>
        <div class="crc-offers-grid">${offersHtml}</div>
      </div>

    </div>`;
}

// ══ Toast ════════════════════════════════════════════════════════
// ── حل تعارض البادئة ────────────────────────────────────────
function resolveConflict(methodId, methodName, methodColor, methodIcon) {
  document.getElementById('conflict-picker').style.display = 'none';

  S.methodId    = methodId;
  S.methodName  = methodName;
  S.methodColor = methodColor;
  S.methodIcon  = methodIcon;

  const card  = document.getElementById('detect-card');
  const icon  = document.getElementById('detect-icon');
  const name  = document.getElementById('detect-name');
  const sub   = document.getElementById('detect-sub');
  const badge = document.getElementById('detect-badge');
  const inp   = document.getElementById('target-number');

  card.className = 'detect-card found';
  icon.style.background = methodColor;
  icon.innerHTML = `<i class="fas fa-${methodIcon}"></i>`;
  name.textContent = methodName;
  name.style.color = methodColor;
  sub.textContent  = '✓ ' + methodName + ' — أدخل المبلغ وانقر التالي';
  badge.textContent = 'BILLPAY';
  badge.style.color       = methodColor;
  badge.style.borderColor = methodColor + '44';
  badge.style.background  = methodColor + '15';
  inp.classList.add('detected');

  // حمّل الباقات من DB بنفس آلية باقي الشركات
  autoLoadBunchesForMethod({id:methodId, name:methodName, color:methodColor, icon:methodIcon, type:'BILLPAY'});
}

// ══ Sadad Accordion ════════════════════════════════════════════
function toggleSadad(uid) {
  const body = document.getElementById(uid);
  const arr  = document.getElementById('arr-' + uid);
  if (!body) return;
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  if (arr) arr.classList.toggle('open', !isOpen);
}

let toastTimer = null;
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast ' + type + ' show';
  clearTimeout(toastTimer); toastTimer = setTimeout(()=>t.classList.remove('show'), 3000);
}

// ══════════════════════════════════════════════════════════════
//  شاشة الفواتير — كهرباء / مياه
// ══════════════════════════════════════════════════════════════
let billState = {
  methodId:    null,
  methodName:  '',
  methodColor: '',
  methodIcon:  '',
  bunchId:     '',    // bunch_id للمنطقة
  unifiedCode: '',    // unified_code للـ API
  areaName:    '',
  queryData:   null,  // نتيجة الاستعلام
  suggestedAmt: 0,
};

// ── عرض الحقول الديناميكية لمزود في screen-bill ─────────────
function renderDynFieldsForBill(methodId) {
  const fields = (typeof METHOD_DYN_FIELDS !== 'undefined' && METHOD_DYN_FIELDS[String(methodId)]) || [];
  const amountField = fields.find(f => f.field_type === 'amount');
  const wrap = document.getElementById('dyn-bill-amount-wrap');
  if (!wrap) return;
  if (amountField) {
    const label = amountField.field_label || 'مبلغ السداد';
    const plch  = amountField.placeholder  || 'أدخل المبلغ بالريال';
    wrap.style.display = '';
    document.getElementById('dyn-bill-amount-label').innerHTML =
      `<i class="fas fa-coins" style="color:var(--gold)"></i> ${label} (ريال يمني)`;
    document.getElementById('bill-amount').placeholder = plch;
  } else {
    // استخدم المبلغ الافتراضي الموجود
    wrap.style.display = '';
  }
}

function openBillScreen(methodId, name, color, icon, dynFieldsData) {
  billState.methodId    = methodId;
  billState.methodName  = name;
  billState.methodColor = color;
  billState.methodIcon  = icon;
  billState.bunchId     = '';
  billState.unifiedCode = '';
  billState.areaName    = '';
  billState.queryData   = null;
  billState.suggestedAmt = 0;

  // ضبط العنوان والأيقونة
  document.getElementById('bill-title').textContent    = name;
  document.getElementById('bill-subtitle').textContent = 'استعلم ثم سدّد';
  const iconEl = document.getElementById('bill-method-icon');
  iconEl.style.background = color;
  iconEl.innerHTML = `<i class="fas fa-${icon}"></i>`;

  // جلب الحقول الديناميكية للمزود
  // ابحث عن data-fields في الـ element الذي فتح الشاشة
  let dynFields = [];
  if (dynFieldsData) {
    try { dynFields = typeof dynFieldsData === 'string' ? JSON.parse(dynFieldsData) : dynFieldsData; } catch(e) {}
  }
  // fallback: ابحث في أي sadad-item له نفس method_id
  if (!dynFields.length) {
    const el = document.querySelector(`.sadad-item[data-method-id='${methodId}']`);
    if (el && el.dataset.fields) {
      try { dynFields = JSON.parse(el.dataset.fields); } catch(e) {}
    }
  }
  if (!dynFields.length) {
    dynFields = (typeof METHOD_DYN_FIELDS !== 'undefined' && METHOD_DYN_FIELDS[String(methodId)]) || [];
  }

  const numField    = dynFields.find(f => f.field_type !== 'select' && f.field_type !== 'amount' && f.field_type !== 'checkbox') || null;
  const selectField = dynFields.find(f => f.field_type === 'select') || null;
  const amountField = dynFields.find(f => f.field_type === 'amount') || null;

  // label حقل الرقم
  document.getElementById('bill-number-label').innerHTML =
    `<i class="fas fa-hashtag" style="color:var(--primary)"></i> ` +
    (numField ? numField.field_label : 'رقم المشترك');
  document.getElementById('bill-number').placeholder =
    (numField && numField.placeholder) ? numField.placeholder : 'أدخل الرقم';

  // area-selector: أظهر فقط إذا يوجد حقل select
  const areaSelector = document.getElementById('area-selector');
  const dynList      = document.getElementById('dyn-area-list');

  if (selectField && selectField.field_options) {
    areaSelector.style.display = '';
    document.getElementById('area-label').textContent = selectField.field_label || 'اختر';
    // ابنِ الخيارات
    dynList.innerHTML = '';
    selectField.field_options.split('\n').filter(Boolean).forEach(line => {
      const parts = line.split('|');
      const lbl   = (parts[0] || '').trim();
      const val   = (parts[1] || lbl).trim();
      const div   = document.createElement('div');
      div.className      = 'service-option';
      div.dataset.unified = val;
      div.dataset.bunch   = val;
      div.textContent     = lbl;
      div.onclick = function(){ selectArea(this); };
      dynList.appendChild(div);
    });
  } else {
    areaSelector.style.display = 'none';
  }

  // مبلغ السداد
  const amtWrap   = document.getElementById('dyn-bill-amount-wrap');
  const amtLabel  = document.getElementById('dyn-bill-amount-label');
  if (amountField) {
    amtWrap.style.display = '';
    amtLabel.innerHTML = `<i class="fas fa-coins" style="color:var(--gold)"></i> ${amountField.field_label||'مبلغ السداد'} (ريال يمني)`;
    document.getElementById('bill-amount').placeholder = amountField.placeholder || 'أدخل المبلغ';
  } else if (dynFields.length === 0) {
    // لا حقول → أظهر مبلغ السداد الافتراضي
    amtWrap.style.display = '';
  } else {
    // يوجد حقول لكن لا amount → أخفِ المبلغ حتى بعد الاستعلام
    amtWrap.style.display = '';
  }

  // reset
  document.getElementById('bill-number').value = '';
  document.getElementById('area-selected-name').textContent = 'اختر...';
  document.getElementById('area-arrow').style.transform = '';
  document.getElementById('bill-result-area').innerHTML = '';
  document.getElementById('bill-amount').value = '';
  var ef=document.getElementById('dyn-extra-fields'); if(ef) ef.innerHTML='';
  var asel2=document.getElementById('area-selector'); if(asel2) asel2.style.display='';
  checkBillReady();
  document.querySelectorAll('.service-option').forEach(o => o.classList.remove('selected'));

  // طبّق الحقول إذا موجودة، أو اجلبها من السيرفر
  if (dynFields.length) {
    applyBillFields(dynFields);
  } else {
    fetch(`<?=SITE_URL?>/telecom.php?get_bill_fields=1&method_id=${methodId}`)
      .then(r=>r.json()).then(d=>{ if(d.ok&&d.fields.length) applyBillFields(d.fields); })
      .catch(()=>{});
  }

  showScreen('screen-bill');
}

function applyBillFields(dynFields) {
  if (!dynFields || !dynFields.length) return;

  const extraWrap = document.getElementById('dyn-extra-fields');
  if (extraWrap) extraWrap.innerHTML = '';

  let firstTextDone = false;

  dynFields.forEach(function(f) {
    const type  = f.field_type || 'text';
    const label = f.field_label || '';
    const plch  = f.placeholder || '';
    const key   = f.field_key || '';

    // أول text/number → bill-number
    if ((type === 'text' || type === 'number') && !firstTextDone) {
      firstTextDone = true;
      const lbl = document.getElementById('bill-number-label');
      if (lbl) lbl.innerHTML = '<i class="fas fa-hashtag" style="color:var(--primary)"></i> ' + label;
      const inp = document.getElementById('bill-number');
      if (inp) { inp.placeholder = plch || 'أدخل الرقم'; inp.dataset.fieldKey = key; }
      return;
    }

    // select → area-selector
    if (type === 'select') {
      const asel  = document.getElementById('area-selector');
      const dlist = document.getElementById('dyn-area-list');
      const al    = document.getElementById('area-label');
      if (!f.field_options) { if (asel) asel.style.display = 'none'; return; }
      if (asel) asel.style.display = '';
      if (al) al.textContent = label || 'اختر';
      if (dlist) {
        dlist.innerHTML = '';
        f.field_options.replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n').filter(Boolean).forEach(function(line) {
          const p=line.split('|'), lbl2=(p[0]||'').trim(), val=(p[1]||lbl2).trim();
          const d = document.createElement('div');
          d.className = 'service-option';
          d.dataset.unified = val; d.dataset.bunch = val; d.dataset.fieldKey = key;
          d.textContent = lbl2;
          d.onclick = function(){ selectArea(this); };
          dlist.appendChild(d);
        });
      }
      return;
    }

    // amount → bill-amount
    if (type === 'amount') {
      const lbl = document.getElementById('dyn-bill-amount-label');
      if (lbl) lbl.innerHTML = '<i class="fas fa-coins" style="color:var(--gold)"></i> ' + (label||'مبلغ السداد') + ' (ريال يمني)';
      const inp = document.getElementById('bill-amount');
      if (inp) { inp.placeholder = plch || 'أدخل المبلغ'; inp.dataset.fieldKey = key; }
      return;
    }

    // باقي الحقول → dyn-extra-fields
    if (!extraWrap) return;
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-top:14px';
    const labelEl = document.createElement('div');
    labelEl.style.cssText = 'font-size:.78rem;color:var(--text2);margin-bottom:6px;font-weight:600';
    labelEl.textContent = label + (f.is_required == 1 ? ' *' : '');
    wrap.appendChild(labelEl);

    if (type === 'counter') {
      const pricePerPerson = parseFloat(plch) || 0;
      const cWrap = document.createElement('div');
      cWrap.className = 'counter-wrap';
      const btnM = document.createElement('button');
      btnM.type='button'; btnM.className='counter-btn'; btnM.textContent='−';
      const valEl = document.createElement('div');
      valEl.className='counter-val'; valEl.textContent='1';
      valEl.id='dyn_field_'+key; valEl.dataset.fieldKey=key; valEl.dataset.count='1';
      const btnP = document.createElement('button');
      btnP.type='button'; btnP.className='counter-btn'; btnP.textContent='+';
      cWrap.appendChild(btnM); cWrap.appendChild(valEl); cWrap.appendChild(btnP);
      wrap.appendChild(cWrap);
      if (pricePerPerson > 0) {
        const hint = document.createElement('div');
        hint.className='counter-price-hint'; hint.id='counter_hint_'+key;
        hint.textContent = 'الإجمالي: '+pricePerPerson.toLocaleString()+' ريال (1 شخص)';
        wrap.appendChild(hint);
      }
      function updateCounter(delta) {
        const cur = parseInt(valEl.dataset.count)||1;
        const nxt = Math.max(1, Math.min(100, cur+delta));
        valEl.dataset.count=nxt; valEl.textContent=nxt;
        if (pricePerPerson > 0) {
          const total = nxt * pricePerPerson;
          const h = document.getElementById('counter_hint_'+key);
          if (h) h.textContent='الإجمالي: '+total.toLocaleString()+' ريال ('+(nxt===1?'شخص واحد':nxt+' أشخاص')+')';
          const amtInp = document.getElementById('bill-amount');
          if (amtInp) amtInp.value = total;
        }
        checkBillReady();
      }
      btnM.onclick=function(){updateCounter(-1);};
      btnP.onclick=function(){updateCounter(+1);};
      if (pricePerPerson > 0) {
        const amtInp = document.getElementById('bill-amount');
        if (amtInp) amtInp.value = pricePerPerson;
      }

    } else if (type === 'textarea') {
      const ta = document.createElement('textarea');
      ta.className='inp'; ta.id='dyn_field_'+key;
      ta.placeholder=plch; ta.rows=3; ta.dataset.fieldKey=key;
      ta.style.cssText='width:100%;resize:vertical;font-family:var(--font)';
      ta.oninput=checkBillReady;
      wrap.appendChild(ta);

    } else if (type === 'checkbox') {
      const lbl2 = document.createElement('label');
      lbl2.style.cssText='display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 0';
      const cb = document.createElement('input');
      cb.type='checkbox'; cb.id='dyn_field_'+key; cb.dataset.fieldKey=key;
      cb.style.cssText='width:20px;height:20px;cursor:pointer';
      cb.onchange=checkBillReady;
      const span = document.createElement('span');
      span.style.cssText='font-size:.88rem';
      span.textContent = plch || label;
      lbl2.appendChild(cb); lbl2.appendChild(span);
      wrap.appendChild(lbl2);

    } else {
      // text/number إضافي
      const inp2 = document.createElement('input');
      inp2.type = type==='number'?'number':'text';
      inp2.className='inp'; inp2.id='dyn_field_'+key;
      inp2.placeholder=plch; inp2.dataset.fieldKey=key;
      inp2.oninput=checkBillReady;
      wrap.appendChild(inp2);
    }

    extraWrap.appendChild(wrap);
  });

  // أخفِ area-selector إن لم يكن هناك select
  if (!dynFields.some(function(f){return f.field_type==='select';})) {
    const asel = document.getElementById('area-selector');
    if (asel) asel.style.display = 'none';
  }
}

function toggleAreaList() {
  const list  = document.getElementById('dyn-area-list');
  const arrow = document.getElementById('area-arrow');
  const isOpen = list.classList.contains('open');
  list.classList.toggle('open', !isOpen);
  arrow.style.transform = isOpen ? '' : 'rotate(180deg)';
}

function selectArea(el) {
  document.querySelectorAll('.service-option').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  billState.bunchId     = el.dataset.bunch;
  billState.unifiedCode = el.dataset.unified;
  billState.areaName    = el.textContent.trim();

  document.getElementById('area-selected-name').textContent = billState.areaName;

  document.getElementById('dyn-area-list').classList.remove('open');
  document.getElementById('area-arrow').style.transform = '';

  checkBillReady();
  resetBillResult();
}

function checkBillReady() {
  const num   = document.getElementById('bill-number').value.trim();
  const ready = num.length > 0 && billState.bunchId;
  const btn   = document.getElementById('btn-query');
  btn.disabled      = !ready;
  btn.style.opacity = ready ? '1' : '0.5';
}

function resetBillResult() {
  billState.queryData = null;
  document.getElementById('bill-result-area').innerHTML = '';
  document.getElementById('bill-amount').value = '';
  checkBillReady();
}

// ── استعلام الفاتورة ─────────────────────────────────────────
function doBillQuery() {
  const num = document.getElementById('bill-number').value.trim();
  if (!num) { showToast('أدخل رقم المشترك أولاً','error'); return; }
  if (!billState.bunchId) { showToast('اختر المنطقة/المؤسسة','error'); return; }

  const btn = document.getElementById('btn-query');
  btn.disabled = true;
  btn.style.opacity = '0.7';
  btn.innerHTML = '<i class="fas fa-spinner spin"></i>';
  document.getElementById('bill-result-area').innerHTML = '';
  document.getElementById('bill-amount').value = '';

  const fd = new FormData();
  fd.append('ajax_action',   'check_service');
  fd.append('target_number', num);
  fd.append('method_id',     billState.methodId);
  fd.append('bunch_id',      billState.unifiedCode);

  fetch(location.href, {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.innerHTML = '<i class="fas fa-search"></i> استعلام';

      if (d.ok && d.data) {
        renderBillResult(d.data, num);
      } else {
        document.getElementById('bill-result-area').innerHTML = `
          <div class="bill-result" style="margin-top:14px">
            <div style="padding:20px;text-align:center;color:var(--red)">
              <i class="fas fa-exclamation-circle" style="font-size:2rem;margin-bottom:8px;display:block"></i>
              ${e(d.msg || 'لم نتمكن من الاستعلام — تأكد من الرقم')}
            </div>
          </div>`;
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.innerHTML = '<i class="fas fa-search"></i> استعلام';
      showToast('خطأ في الاتصال','error');
    });
}

// ── عرض نتيجة الاستعلام ─────────────────────────────────────
function renderBillResult(data, num) {
  billState.queryData = data;

  // استخرج البيانات من استجابة check-service
  const name       = data.name        || data.subscriber_name || '—';
  const balance    = data.balance      != null ? Number(data.balance).toLocaleString()  : '—';
  const minAmount  = data.min_amount   != null ? Number(data.min_amount)  : 0;
  const maxAmount  = data.max_amount   != null ? Number(data.max_amount)  : 0;
  const dueAmount  = data.due_amount   != null ? Number(data.due_amount)  : 0;
  const fees       = data.fees         != null ? Number(data.fees)        : 0;
  const partialPay = data.partial_pay  === true || data.partial_pay === 'يسمح' ? 'يُسمح' : 'غير مسموح';
  const expiry     = data.expiry_date  || data.due_date || null;
  const note       = data.note         || data.description || null;

  // المبلغ المقترح للسداد
  billState.suggestedAmt = dueAmount || minAmount || balance || 0;

  const rows = [
    ['اسم المشترك',       name,        ''],
    ['رصيد الباقة',       balance,     ''],
    ['المبلغ المستحق',    dueAmount  > 0 ? Number(dueAmount).toLocaleString()  + ' ر.ي' : '0', dueAmount > 0 ? 'danger' : ''],
    ['الرسوم',            fees       > 0 ? Number(fees).toLocaleString()       + ' ر.ي' : '0', ''],
    ['الرصيد',            maxAmount  > 0 ? Number(maxAmount).toLocaleString()  + ' ر.ي' : '—', 'gold'],
    ['أقل مبلغ للسداد',   minAmount  > 0 ? Number(minAmount).toLocaleString()  + ' ر.ي' : '—', ''],
    ['سداد جزئي',         partialPay,  partialPay === 'يُسمح' ? 'green' : ''],
    expiry ? ['تاريخ الانتهاء', expiry, ''] : null,
  ].filter(Boolean);

  let html = `
    <div class="bill-result" style="margin-top:14px">
      <div class="bill-result-header">
        <div style="width:38px;height:38px;border-radius:10px;background:${billState.methodColor};display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.1rem;flex-shrink:0">
          <i class="fas fa-${billState.methodIcon}"></i>
        </div>
        <div>
          <div class="bill-result-name">${e(billState.areaName)}</div>
          <div style="font-size:.7rem;color:var(--text2)">رقم ${e(num)}</div>
        </div>
      </div>
      ${rows.map(([k,v,cls]) => `
        <div class="bill-result-row">
          <div class="bill-result-key">${k}</div>
          <div class="bill-result-val ${cls}">${e(String(v))}</div>
        </div>`).join('')}
    </div>`;

  if (note) {
    html += `<div class="bill-note"><i class="fas fa-info-circle"></i> ${e(note)}</div>`;
  }

  document.getElementById('bill-result-area').innerHTML = html;

  // أظهر مربع السداد مع المبلغ المقترح
  // bill-pay-section ظاهر دائماً — فقط نملأ المبلغ
  if (billState.suggestedAmt > 0) {
    document.getElementById('bill-amount').value = Math.round(billState.suggestedAmt);
  }
}

// ── تسديد الفاتورة ──────────────────────────────────────────
function doPayBill() {
  const num    = document.getElementById('bill-number').value.trim();
  const amount = parseFloat(document.getElementById('bill-amount').value || 0);

  if (amount <= 0) { showToast('أدخل مبلغ السداد','error'); return; }

  const btn = document.getElementById('btn-pay-bill');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner spin"></i> جاري التسديد...';

  const fd = new FormData();
  fd.append('ajax_action',   'do_topup');
  fd.append('target_number', num);
  fd.append('method_id',     billState.methodId);
  fd.append('bunch_id',      billState.unifiedCode);
  fd.append('amount',        amount);
  fd.append('pay_from',      'balance');

  fetch(location.href, {method:'POST', body:fd})
    .then(r => r.json())
    .then(d => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> تسديد الفاتورة';
      if (d.ok) {
        showToast(d.msg || '✅ تم التسديد بنجاح!', 'success');
        if (d.new_balance !== undefined)
          document.getElementById('live-balance').textContent =
            Number(d.new_balance).toFixed(4) + ' ' + CURR_SYM;
        setTimeout(() => { showScreen('screen-history'); location.reload(); }, 1800);
      } else {
        showToast(d.msg || 'فشل التسديد', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> تسديد الفاتورة';
      showToast('خطأ في الاتصال', 'error');
    });
}

if (localStorage.getItem('theme') === 'light') document.body.classList.add('light-mode');
</script>
</body>
</html>
