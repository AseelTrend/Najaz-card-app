<?php
require_once '../includes/config.php';
requireStaffOrAdmin($pdo, 'perm_settings');
$pageTitle = 'سجل المعاملات المالية - ' . SITE_NAME;

// ── إنشاء عمود sub_type إن لم يكن موجوداً ───────────────────────────────────
try { $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN sub_type VARCHAR(20) NULL DEFAULT NULL"); } catch(Exception $e) {}

// ── تحديث تلقائي: تصنيف السجلات القديمة (sub_type=NULL) بناءً على الوصف ────
try {
    $pdo->exec("
        UPDATE wallet_transactions
        SET sub_type = CASE
            WHEN type = 'credit' AND (
                description LIKE '%استرداد%' OR description LIKE '%refund%' OR
                description LIKE '%إلغاء%'   OR description LIKE '%الغاء%'  OR
                description LIKE '%إعادة%'   OR description LIKE '%اعادة%'  OR
                description LIKE '%رد%'
            ) THEN 'refund'
            WHEN type = 'credit' AND description LIKE '%P2P%' THEN 'p2p'
            WHEN type = 'credit' AND (
                description LIKE '%هدية%'  OR description LIKE '%gift%'   OR
                description LIKE '%مكافأة%' OR description LIKE '%مكافاة%' OR
                description LIKE '%جائزة%' OR description LIKE '%جايزة%'  OR
                description LIKE '%هدايا%' OR description LIKE '%مجاني%'  OR
                description LIKE '%هبة%'
            ) THEN 'gift'
            WHEN type = 'credit' THEN 'topup'
            ELSE sub_type
        END
        WHERE type = 'credit' AND sub_type IS NULL
    ");
} catch(Exception $e) {}

$tab    = $_GET['tab'] ?? 'topup';
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

function fetchTx($pdo, $where, $params, $limit, $offset) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions wt JOIN users u ON wt.user_id=u.id $where");
    $c->execute($params); $total=(int)$c->fetchColumn();
    $s = $pdo->prepare("SELECT wt.*, u.username, u.full_name, u.phone, u.id as uid FROM wallet_transactions wt JOIN users u ON wt.user_id=u.id $where ORDER BY wt.created_at DESC LIMIT $limit OFFSET $offset");
    $s->execute($params);
    return [$total, $s->fetchAll()];
}
function addSearch($base, $search, &$params) {
    if ($search) { $base .= " AND (u.username LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR u.id=?)"; $params=array_merge($params,["%$search%","%$search%","%$search%",(int)$search]); }
    return $base;
}

$rows=[]; $rowsTotal=0;
try {
    // جميع مصادر الشحن الفعلية: الشحن اليدوي/الدفع، SMS، والبطاقات.
    $topupWhere = "WHERE ((wt.type='credit' AND wt.sub_type='topup') OR wt.type='topup' OR wt.sub_type IN ('topup','sms'))";
    if ($tab==='topup')  { $p=[]; $w=addSearch($topupWhere,$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='refund'){ $p=[]; $w=addSearch("WHERE wt.type='credit' AND wt.sub_type='refund'",$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='p2p'){ $p=[]; $w=addSearch("WHERE wt.type='credit' AND wt.sub_type='p2p'",$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='gift')  { $p=[]; $w=addSearch("WHERE wt.type='credit' AND wt.sub_type='gift'",$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='prizes'){ $p=[]; $w=addSearch("WHERE wt.type='prize'",$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='referrals'){ $p=[]; $w=addSearch("WHERE wt.type IN ('referral','referral_welcome','referral_revoke')",$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset); }
    elseif($tab==='all') {
        $p=[]; $base="WHERE 1=1";
        $tf=$_GET['type_filter']??''; $sf=$_GET['sub_filter']??'';
        if($tf && in_array($tf,['credit','topup','debit','prize','referral','referral_welcome','referral_revoke'],true)){ $base.=" AND wt.type=?"; $p[]=$tf; }
        if($sf && in_array($sf,['topup','sms','refund','gift','p2p'],true)){ $base.=" AND wt.sub_type=?"; $p[]=$sf; }
        $w=addSearch($base,$search,$p); [$rowsTotal,$rows]=fetchTx($pdo,$w,$p,$limit,$offset);
    }
} catch(Exception $e){ $rows=[]; $rowsTotal=0; }

$totalPages=max(1,(int)ceil($rowsTotal/$limit));

try {
    $sTopup =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE ((type='credit' AND sub_type='topup') OR type='topup' OR sub_type IN ('topup','sms'))")->fetch(PDO::FETCH_NUM);
    $sRefund=$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='credit' AND sub_type='refund'")->fetch(PDO::FETCH_NUM);
    $sP2P   =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='credit' AND sub_type='p2p'")->fetch(PDO::FETCH_NUM);
    $sGift  =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='credit' AND sub_type='gift'")->fetch(PDO::FETCH_NUM);
    $sDebit =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='debit'")->fetch(PDO::FETCH_NUM);
    $sPrize =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='prize'")->fetch(PDO::FETCH_NUM);
    $sToday =$pdo->query("SELECT COUNT(*),COALESCE(SUM(amount),0) FROM wallet_transactions WHERE (type IN ('credit','topup') OR type IN ('referral','referral_welcome','prize')) AND DATE(created_at)=CURDATE()")->fetch(PDO::FETCH_NUM);
    $sAllC  =$pdo->query("SELECT COUNT(*) FROM wallet_transactions")->fetchColumn();
} catch(Exception $e){ $sTopup=$sRefund=$sP2P=$sGift=$sDebit=$sPrize=$sToday=[0,0]; $sAllC=0; }

include 'header.php';
?>
<style>
.ledger-tabs{display:flex;gap:5px;margin-bottom:1.4rem;background:var(--card2);border-radius:13px;padding:5px;flex-wrap:wrap}
.ledger-tab{flex:1;min-width:90px;padding:9px 6px;border-radius:9px;cursor:pointer;font-weight:700;font-size:.82rem;color:var(--text2);text-align:center;transition:.2s;border:none;background:none;font-family:var(--font);display:flex;align-items:center;justify-content:center;gap:5px;white-space:nowrap}
.ledger-tab.active{background:var(--primary);color:#fff;box-shadow:0 2px 12px rgba(108,63,224,.35)}
.tab-count{background:rgba(255,255,255,.1);padding:1px 7px;border-radius:10px;font-size:.67rem}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-bottom:1.4rem}
.stat-card{background:var(--card2);border:1px solid var(--border);border-radius:13px;padding:13px 15px;display:flex;align-items:center;gap:11px}
.stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.stat-val{font-size:1.15rem;font-weight:900;line-height:1.15}
.stat-lbl{font-size:.69rem;color:#8895a7;margin-top:2px}
.tx-table{width:100%;border-collapse:collapse}
.tx-table th{font-size:.7rem;color:#8895a7;font-weight:700;padding:9px 12px;background:var(--bg);border-bottom:2px solid var(--border);text-align:right;white-space:nowrap}
.tx-table td{padding:9px 12px;border-bottom:1px solid var(--border);font-size:.82rem;vertical-align:middle}
.tx-table tr:hover td{background:rgba(255,255,255,.017)}
.tx-amount{font-weight:900;font-size:.92rem;direction:ltr;text-align:right;white-space:nowrap}
.c-topup{color:#00d4aa}.c-refund{color:#3dd6f5}.c-gift{color:#f5a623}.c-debit{color:#ff4455}.c-prize{color:#b197fc}
.sub-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:800;white-space:nowrap}
.sb-topup{background:rgba(0,212,170,.1);color:#00d4aa;border:1px solid rgba(0,212,170,.2)}
.sb-refund{background:rgba(61,214,245,.1);color:#3dd6f5;border:1px solid rgba(61,214,245,.2)}
.sb-gift{background:rgba(245,166,35,.1);color:#f5a623;border:1px solid rgba(245,166,35,.2)}
.sb-debit{background:rgba(255,68,85,.1);color:#ff4455;border:1px solid rgba(255,68,85,.2)}
.sb-prize{background:rgba(177,151,252,.1);color:#b197fc;border:1px solid rgba(177,151,252,.2)}
.search-bar{display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap}
.search-input{flex:1;min-width:180px;background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:9px 14px;color:#fff;font-family:var(--font);font-size:.87rem;outline:none}
.search-input:focus{border-color:var(--primary)}
.f-sel{background:var(--card2);border:1px solid var(--border);border-radius:10px;padding:9px 12px;color:#fff;font-family:var(--font);font-size:.83rem;outline:none;cursor:pointer}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:1.2rem;flex-wrap:wrap}
.pg-btn{background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:6px 13px;color:var(--text2);font-family:var(--font);font-size:.8rem;cursor:pointer;font-weight:700;text-decoration:none;transition:.15s}
.pg-btn:hover{border-color:var(--primary);color:var(--primary)}
.pg-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.user-chip{display:inline-flex;align-items:center;gap:4px;background:rgba(30,111,255,.1);border:1px solid rgba(30,111,255,.2);border-radius:7px;padding:2px 8px;font-size:.74rem;text-decoration:none;color:var(--primary);white-space:nowrap}
.user-chip:hover{background:rgba(30,111,255,.2)}
</style>

<div class="page-header">
  <div class="page-header-left">
    <div class="page-header-title">
      <div class="page-header-title-icon" style="background:rgba(0,212,170,.15)"><i class="fas fa-wallet" style="color:#00d4aa"></i></div>
      سجل المعاملات المالية
    </div>
  </div>
</div>

<!-- إحصائيات -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(0,212,170,.12);color:#00d4aa"><i class="fas fa-arrow-down"></i></div>
    <div><div class="stat-val c-topup">$<?=number_format($sTopup[1],2)?></div><div class="stat-lbl">تأمينات (<?=number_format($sTopup[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(61,214,245,.12);color:#3dd6f5"><i class="fas fa-undo-alt"></i></div>
    <div><div class="stat-val c-refund">$<?=number_format($sRefund[1],2)?></div><div class="stat-lbl">استردادات (<?=number_format($sRefund[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(245,166,35,.12);color:#f5a623"><i class="fas fa-gift"></i></div>
    <div><div class="stat-val c-gift">$<?=number_format($sGift[1],2)?></div><div class="stat-lbl">هدايا (<?=number_format($sGift[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(255,68,85,.1);color:#ff4455"><i class="fas fa-arrow-up"></i></div>
    <div><div class="stat-val c-debit">$<?=number_format($sDebit[1],2)?></div><div class="stat-lbl">خصميات (<?=number_format($sDebit[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(177,151,252,.12);color:#b197fc"><i class="fas fa-handshake"></i></div>
    <div><div class="stat-val" style="color:#b197fc">$<?=number_format($sP2P[1],2)?></div><div class="stat-lbl">تحويلات P2P (<?=number_format($sP2P[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(177,151,252,.12);color:#b197fc"><i class="fas fa-trophy"></i></div>
    <div><div class="stat-val c-prize">$<?=number_format($sPrize[1],2)?></div><div class="stat-lbl">جوائز (<?=number_format($sPrize[0])?>)</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(108,63,224,.12);color:var(--primary)"><i class="fas fa-calendar-day"></i></div>
    <div><div class="stat-val" style="color:var(--primary)">$<?=number_format($sToday[1],2)?></div><div class="stat-lbl">إضافات اليوم (<?=number_format($sToday[0])?>)</div></div>
  </div>
</div>

<!-- التابات -->
<div class="ledger-tabs">
  <button class="ledger-tab <?=$tab==='topup'?'active':''?>"  onclick="goTab('topup')">
    <i class="fas fa-arrow-circle-down"></i> التأمينات <span class="tab-count"><?=number_format($sTopup[0])?></span>
  </button>
  <button class="ledger-tab <?=$tab==='refund'?'active':''?>" onclick="goTab('refund')">
    <i class="fas fa-undo-alt" style="color:#3dd6f5"></i> الاستردادات <span class="tab-count"><?=number_format($sRefund[0])?></span>
  </button>
  <button class="ledger-tab <?=$tab==='p2p'?'active':''?>" onclick="goTab('p2p')">
    <i class="fas fa-handshake" style="color:#b197fc"></i> تحويلات P2P <span class="tab-count"><?=number_format($sP2P[0])?></span>
  </button>
  <button class="ledger-tab <?=$tab==='gift'?'active':''?>"   onclick="goTab('gift')">
    <i class="fas fa-gift" style="color:#f5a623"></i> الهدايا <span class="tab-count"><?=number_format($sGift[0])?></span>
  </button>
  <button class="ledger-tab <?=$tab==='prizes'?'active':''?>" onclick="goTab('prizes')">
    <i class="fas fa-trophy" style="color:#b197fc"></i> الجوائز <span class="tab-count"><?=number_format($sPrize[0])?></span>
  </button>
  <button class="ledger-tab <?=$tab==='referrals'?'active':''?>" onclick="goTab('referrals')">
    <i class="fas fa-user-friends" style="color:#b197fc"></i> الإحالات
  </button>
  <button class="ledger-tab <?=$tab==='all'?'active':''?>"    onclick="goTab('all')">
    <i class="fas fa-exchange-alt"></i> الكل <span class="tab-count"><?=number_format((int)$sAllC)?></span>
  </button>
</div>

<!-- بحث -->
<form method="GET" class="search-bar">
  <input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>">
  <input type="text" name="q" class="search-input" placeholder="🔍 ابحث باسم العميل أو رقم الهاتف..." value="<?=htmlspecialchars($search)?>">
  <?php if($tab==='all'): ?>
  <select name="type_filter" class="f-sel" onchange="this.form.submit()">
    <option value="">كل الأنواع</option>
    <option value="credit" <?=($_GET['type_filter']??'')==='credit'?'selected':''?>>إضافات</option>
    <option value="topup"  <?=($_GET['type_filter']??'')==='topup' ?'selected':''?>>شحن SMS/بطاقات</option>
    <option value="debit"  <?=($_GET['type_filter']??'')==='debit' ?'selected':''?>>خصميات</option>
    <option value="prize"  <?=($_GET['type_filter']??'')==='prize' ?'selected':''?>>جوائز</option>
    <option value="referral" <?=($_GET['type_filter']??'')==='referral' ?'selected':''?>>إحالات</option>
  </select>
  <select name="sub_filter" class="f-sel" onchange="this.form.submit()">
    <option value="">كل التصنيفات</option>
    <option value="topup"  <?=($_GET['sub_filter']??'')==='topup' ?'selected':''?>>تأمين</option>
    <option value="sms"    <?=($_GET['sub_filter']??'')==='sms' ?'selected':''?>>SMS</option>
    <option value="refund" <?=($_GET['sub_filter']??'')==='refund'?'selected':''?>>استرداد</option>
    <option value="gift"   <?=($_GET['sub_filter']??'')==='gift'  ?'selected':''?>>هدية</option>
  </select>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> بحث</button>
  <?php if($search): ?><a href="?tab=<?=urlencode($tab)?>" class="btn btn-secondary"><i class="fas fa-times"></i> مسح</a><?php endif; ?>
</form>

<?php if($search): ?>
<div style="margin-bottom:.8rem;font-size:.82rem;color:#8895a7">
  نتائج عن "<strong style="color:#fff"><?=htmlspecialchars($search)?></strong>": <?=number_format($rowsTotal)?> نتيجة
</div>
<?php endif; ?>

<!-- الجدول -->
<div class="card" style="padding:0;overflow:hidden">
<?php if(empty($rows)): ?>
  <div style="text-align:center;padding:3rem;color:#8895a7">
    <i class="fas fa-inbox" style="font-size:2.2rem;opacity:.18;display:block;margin-bottom:.8rem"></i>
    <?=$search?'لا توجد نتائج لهذا البحث':'لا توجد سجلات في هذا القسم بعد'?>
  </div>
<?php else: ?>
  <div style="overflow-x:auto">
  <table class="tx-table">
    <thead><tr>
      <th>#</th><th>العميل</th><th>التصنيف</th><th>المبلغ</th><th>الرصيد بعده</th><th>البيان</th><th>التاريخ</th>
    </tr></thead>
    <tbody>
    <?php foreach($rows as $tx):
      $st = $tx['sub_type'] ?? ($tx['type']==='debit'?'debit':($tx['type']==='prize'?'prize':'topup'));
      $amtCls = match($st){'refund'=>'c-refund','gift'=>'c-gift','debit'=>'c-debit','prize'=>'c-prize','p2p'=>'c-prize',default=>'c-topup'};
      $sbCls  = match($st){'refund'=>'sb-refund','gift'=>'sb-gift','debit'=>'sb-debit','prize'=>'sb-prize','p2p'=>'sb-prize',default=>'sb-topup'};
      $sign   = $tx['type']==='debit'?'−':'+';
      $labels = ['topup'=>'💰 تأمين','refund'=>'↩️ استرداد','gift'=>'🎁 هدية','debit'=>'↑ خصم','prize'=>'🏆 جائزة','p2p'=>'🤝 تحويل P2P'];
    ?>
    <tr>
      <td style="color:#8895a7;font-size:.72rem">#<?=$tx['id']?></td>
      <td>
        <a href="customers.php?action=view&id=<?=$tx['uid']?>" class="user-chip">
          <i class="fas fa-user" style="font-size:.6rem"></i><?=htmlspecialchars($tx['username'])?>
          <span style="color:#8895a7;font-weight:400">#<?=str_pad($tx['uid'],5,'0',STR_PAD_LEFT)?></span>
        </a>
        <?php if($tx['full_name']): ?><div style="font-size:.69rem;color:#8895a7;margin-top:2px"><?=htmlspecialchars($tx['full_name'])?></div><?php endif; ?>
        <?php if($tx['phone']): ?><div style="font-size:.69rem;color:#8895a7"><i class="fas fa-phone" style="font-size:.58rem"></i> <?=htmlspecialchars($tx['phone'])?></div><?php endif; ?>
      </td>
      <td><span class="sub-badge <?=$sbCls?>"><?=$labels[$st]??$st?></span></td>
      <td><span class="tx-amount <?=$amtCls?>"><?=$sign?>$<?=number_format($tx['amount'],4)?></span></td>
      <td style="font-size:.79rem;color:#8895a7">$<?=number_format($tx['balance_after'],2)?></td>
      <td style="font-size:.74rem;color:#8895a7;max-width:140px"><span title="<?=htmlspecialchars($tx['description']??'')?>"><?=htmlspecialchars(mb_substr($tx['description']??'—',0,38))?></span></td>
      <td style="font-size:.74rem;color:#8895a7;white-space:nowrap"><?=date('d/m/Y H:i',strtotime($tx['created_at']))?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<?php if($totalPages>1): ?>
<div class="pagination">
  <?php for($p=1;$p<=$totalPages;$p++): ?>
  <a href="?tab=<?=urlencode($tab)?>&q=<?=urlencode($search)?>&page=<?=$p?>" class="pg-btn <?=$p===$page?'active':''?>"><?=$p?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<script>
function goTab(t){
  const q=document.querySelector('.search-input')?.value??'';
  location.href='?tab='+t+(q?'&q='+encodeURIComponent(q):'');
}
</script>

<?php include 'footer.php'; ?>
