<?php
/**
 * صفحة الهدايا — إرسال واستقبال رصيد كهدية
 */
require_once 'includes/config.php';

$pageTitle = SITE_NAME . ' - الهدايا 🎁';

if (!isLoggedIn()) {
    header('Location: ' . SITE_URL . '/mobile.php');
    exit;
}

$user        = getUser();
$userId      = (int)$user['id'];
$userBalance = (float)($user['balance'] ?? 0);
$currSymbol  = getSetting('currency_symbol') ?: '$';
$siteName    = SITE_NAME;

// ── إنشاء الجدول تلقائياً ──────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `gifts` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `sender_id`   INT NOT NULL,
        `receiver_id` INT NOT NULL,
        `amount`      DECIMAL(12,4) NOT NULL,
        `message`     VARCHAR(500)  DEFAULT NULL,
        `status`      ENUM('pending','opened','rejected') DEFAULT 'pending',
        `opened_at`   DATETIME DEFAULT NULL,
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_sender`   (`sender_id`),
        KEY `idx_receiver` (`receiver_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e){ error_log('gifts table error: '.$e->getMessage()); }

// ── AJAX handlers ───────────────────────────────────────────
function jr(array $d): void { header('Content-Type: application/json'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // البحث عن مستخدم
    if (isset($_POST['action']) && $_POST['action'] === 'find_user') {
        $q = trim($_POST['q'] ?? '');
        if (strlen($q) < 2) jr(['ok'=>false,'msg'=>'أدخل اسم المستخدم أو كود الإحالة']);
        try {
            $stmt = $pdo->prepare("SELECT id,username,full_name,referral_code FROM users
                WHERE (username=? OR referral_code=?) AND id!=? AND role='customer'
                AND (is_deleted IS NULL OR is_deleted=0) LIMIT 1");
            $stmt->execute([$q,$q,$userId]);
            $found = $stmt->fetch();
            if (!$found) jr(['ok'=>false,'msg'=>'لم يُعثر على هذا المستخدم']);
            jr(['ok'=>true,'user'=>[
                'id'       => $found['id'],
                'username' => $found['username'],
                'name'     => $found['full_name'] ?: $found['username'],
                'initial'  => mb_substr($found['full_name'] ?: $found['username'], 0, 1),
            ]]);
        } catch(Exception $e){ jr(['ok'=>false,'msg'=>'خطأ في البحث']); }
    }

    // إرسال هدية
    if (isset($_POST['action']) && $_POST['action'] === 'send_gift') {
        $receiverId = (int)($_POST['receiver_id'] ?? 0);
        $amount     = (float)($_POST['amount']      ?? 0);
        $message    = trim(mb_substr($_POST['message'] ?? '', 0, 200));

        if ($receiverId <= 0 || $receiverId === $userId) jr(['ok'=>false,'msg'=>'مستقبل غير صالح']);
        if ($amount <= 0)   jr(['ok'=>false,'msg'=>'أدخل مبلغاً صحيحاً']);

        // تحقق من الرصيد
        $fresh = $pdo->prepare("SELECT balance,username,full_name FROM users WHERE id=? AND (is_deleted IS NULL OR is_deleted=0)");
        $fresh->execute([$userId]);
        $sender = $fresh->fetch();
        if (!$sender || (float)$sender['balance'] < $amount)
            jr(['ok'=>false,'msg'=>'رصيدك غير كافٍ']);

        // تحقق من المستقبل
        $recvStmt = $pdo->prepare("SELECT id,username,full_name FROM users WHERE id=? AND role='customer' AND (is_deleted IS NULL OR is_deleted=0)");
        $recvStmt->execute([$receiverId]);
        $receiver = $recvStmt->fetch();
        if (!$receiver) jr(['ok'=>false,'msg'=>'المستخدم غير موجود']);

        $pdo->beginTransaction();
        try {
            // خصم من المُرسِل
            $pdo->prepare("UPDATE users SET balance=balance-? WHERE id=? AND balance>=?")
                ->execute([$amount,$userId,$amount]);

            // إضافة للمستقبل
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")
                ->execute([$amount,$receiverId]);

            // تسجيل الهدية
            $pdo->prepare("INSERT INTO gifts (sender_id,receiver_id,amount,message,status) VALUES (?,?,?,?,'pending')")
                ->execute([$userId,$receiverId,$amount,$message ?: null]);
            $giftId = (int)$pdo->lastInsertId();

            // wallet transactions
            $desc = '🎁 هدية لـ '.($receiver['full_name']?:$receiver['username']);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id")
                ->execute([$userId,'debit',$amount,(float)$sender['balance'],(float)$sender['balance']-$amount,$desc]);
            $recvDesc = '🎁 هدية من '.($sender['full_name']?:$sender['username']);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,description) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id")
                ->execute([$receiverId,'credit',$amount,0,0,$recvDesc]);

            $pdo->commit();
            $newBal = (float)$sender['balance'] - $amount;
            jr(['ok'=>true,'msg'=>'تم إرسال الهدية! 🎁','new_balance'=>$newBal,'gift_id'=>$giftId]);
        } catch(Exception $e) {
            $pdo->rollBack();
            jr(['ok'=>false,'msg'=>'خطأ داخلي — تواصل مع الدعم']);
        }
    }

    // فتح هدية
    if (isset($_POST['action']) && $_POST['action'] === 'open_gift') {
        $gid = (int)($_POST['gift_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM gifts WHERE id=? AND receiver_id=? AND status='pending'");
        $stmt->execute([$gid,$userId]);
        $gift = $stmt->fetch();
        if (!$gift) jr(['ok'=>false,'msg'=>'الهدية غير موجودة أو مفتوحة مسبقاً']);
        $pdo->prepare("UPDATE gifts SET status='opened', opened_at=NOW() WHERE id=?")
            ->execute([$gid]);
        jr(['ok'=>true,'amount'=>$gift['amount'],'message'=>$gift['message']]);
    }
}

// ── جلب البيانات ────────────────────────────────────────────
$sent = $pdo->prepare("
    SELECT g.*,u.username as recv_username, u.full_name as recv_name
    FROM gifts g JOIN users u ON g.receiver_id=u.id
    WHERE g.sender_id=? ORDER BY g.created_at DESC LIMIT 20");
$sent->execute([$userId]);
$sentGifts = $sent->fetchAll();

$received = $pdo->prepare("
    SELECT g.*,u.username as send_username, u.full_name as send_name
    FROM gifts g JOIN users u ON g.sender_id=u.id
    WHERE g.receiver_id=? ORDER BY g.created_at DESC LIMIT 20");
$received->execute([$userId]);
$receivedGifts = $received->fetchAll();

$pendingCount = count(array_filter($receivedGifts, fn($g)=>$g['status']==='pending'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?=$pageTitle?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#07080f;--surface:#0e1018;--card:#13151f;--card2:#181b27;
  --border:rgba(255,255,255,.06);--text:#edf0fb;--text2:#7a84a8;--text3:#3a4060;
  --rose:#ff5f8f;--violet:#8b5cf6;--amber:#f59e0b;--teal:#14b8a6;
  --font:"Tajawal",sans-serif;--ease:cubic-bezier(.32,1.2,.72,1);
}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;overflow-x:hidden}
body::before{
  content:"";position:fixed;inset:0;z-index:0;pointer-events:none;
  background:
    radial-gradient(ellipse 60% 40% at 80% -10%,rgba(139,92,246,.12) 0%,transparent 60%),
    radial-gradient(ellipse 50% 30% at -10% 60%,rgba(255,95,143,.08) 0%,transparent 60%),
    radial-gradient(ellipse 40% 40% at 50% 100%,rgba(20,184,166,.06) 0%,transparent 60%);
}
/* header */
.header{position:sticky;top:0;z-index:80;padding:14px 18px;display:flex;align-items:center;gap:14px;
  background:rgba(7,8,15,.88);backdrop-filter:blur(24px) saturate(180%);border-bottom:1px solid var(--border)}
.back-btn{width:38px;height:38px;border-radius:12px;background:var(--card2);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;color:var(--text2);text-decoration:none;font-size:.9rem;flex-shrink:0;transition:all .2s}
.back-btn:active{transform:scale(.93)}
.header-title{font-size:1.05rem;font-weight:800;letter-spacing:-.02em}
.header-sub{font-size:.68rem;color:var(--text2);margin-top:1px}
/* balance */
.balance-strip{margin:16px 18px 0;background:linear-gradient(135deg,rgba(139,92,246,.15),rgba(255,95,143,.1));
  border:1px solid rgba(139,92,246,.2);border-radius:18px;padding:16px 18px;
  display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden}
.balance-strip::after{content:"";position:absolute;top:-20px;left:-20px;width:120px;height:120px;border-radius:50%;
  background:radial-gradient(circle,rgba(139,92,246,.15),transparent 70%)}
.balance-label{font-size:.72rem;color:var(--text2);font-weight:600}
.balance-val{font-size:1.45rem;font-weight:900;letter-spacing:-.03em;
  background:linear-gradient(135deg,#fff,#c4b5fd);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
/* tabs */
.tab-wrap{padding:16px 18px 8px;display:flex;gap:8px}
.tab-btn{flex:1;padding:11px 8px;border-radius:14px;border:1px solid var(--border);background:var(--card);
  color:var(--text2);font-family:var(--font);font-size:.82rem;font-weight:700;cursor:pointer;
  transition:all .25s var(--ease);display:flex;align-items:center;justify-content:center;gap:7px}
.tab-btn.active{background:linear-gradient(135deg,rgba(139,92,246,.2),rgba(255,95,143,.1));
  border-color:rgba(139,92,246,.4);color:var(--text);box-shadow:0 4px 24px rgba(139,92,246,.15)}
.tab-pill{background:var(--rose);color:#fff;font-size:.62rem;font-weight:900;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center}
/* gift card */
.gift-card{margin:8px 18px;border-radius:20px;background:var(--card);border:1px solid var(--border);overflow:hidden;transition:transform .2s,border-color .2s;position:relative}
.gift-card::before{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(139,92,246,.04),transparent 50%);pointer-events:none}
.gift-card.fresh{border-color:rgba(255,95,143,.25);background:linear-gradient(135deg,rgba(255,95,143,.06),var(--card) 50%)}
.gift-card:active{transform:scale(.985)}
.gc-top{padding:16px 16px 12px;display:flex;align-items:center;gap:13px}
.gc-avatar{width:46px;height:46px;border-radius:15px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.15rem;font-weight:900;color:#fff;position:relative}
.gc-avatar.recv{background:linear-gradient(135deg,#ff5f8f,#c026d3)}
.gc-avatar.sent{background:linear-gradient(135deg,#8b5cf6,#4f46e5)}
.gc-avatar-badge{position:absolute;bottom:-3px;right:-3px;width:18px;height:18px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-size:.6rem;border:2px solid var(--bg)}
.gc-info{flex:1;min-width:0}
.gc-name{font-size:.9rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gc-time{font-size:.67rem;color:var(--text3);margin-top:2px;display:flex;align-items:center;gap:4px}
.gc-amount{font-size:1.15rem;font-weight:900;letter-spacing:-.02em;white-space:nowrap;
  background:linear-gradient(135deg,var(--amber),#fbbf24);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.gc-msg{margin:0 16px 12px;padding:10px 14px;background:rgba(255,255,255,.03);border-radius:12px;
  border-right:3px solid rgba(139,92,246,.4);font-size:.78rem;color:var(--text2);font-style:italic;line-height:1.5}
.gc-footer{padding:10px 16px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.status-badge{font-size:.68rem;font-weight:700;padding:4px 12px;border-radius:20px;display:inline-flex;align-items:center;gap:5px}
.status-pending{background:rgba(245,158,11,.12);color:var(--amber)}
.status-opened {background:rgba(20,184,166,.12);color:var(--teal)}
.open-btn{padding:8px 18px;border-radius:12px;background:linear-gradient(135deg,#ff5f8f,#c026d3);
  color:#fff;font-family:var(--font);font-size:.8rem;font-weight:800;border:none;cursor:pointer;
  transition:all .2s var(--ease);display:flex;align-items:center;gap:7px;box-shadow:0 4px 16px rgba(255,95,143,.3)}
.open-btn:active{transform:scale(.95)}
/* FAB */
.fab{position:fixed;bottom:28px;left:50%;transform:translateX(-50%);z-index:60;
  background:linear-gradient(135deg,#ff5f8f,#8b5cf6);color:#fff;border:none;border-radius:100px;
  padding:15px 30px;font-family:var(--font);font-size:.92rem;font-weight:800;cursor:pointer;
  box-shadow:0 8px 32px rgba(139,92,246,.5),0 0 0 1px rgba(255,255,255,.08);
  display:flex;align-items:center;gap:10px;transition:all .2s var(--ease);white-space:nowrap}
.fab:active{transform:translateX(-50%) scale(.95)}
.fab-pulse{position:absolute;inset:0;border-radius:100px;background:inherit;animation:fab-pulse 2.5s ease-out infinite;z-index:-1}
@keyframes fab-pulse{0%{opacity:.6;transform:scale(1)}100%{opacity:0;transform:scale(1.4)}}
/* empty */
.empty{text-align:center;padding:56px 24px;display:flex;flex-direction:column;align-items:center;gap:12px}
.empty-icon{width:72px;height:72px;border-radius:22px;background:var(--card2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:1.8rem}
.empty-txt{font-size:.88rem;color:var(--text2);font-weight:600}
.empty-sub{font-size:.75rem;color:var(--text3)}
/* overlay */
.overlay{position:fixed;inset:0;z-index:200;background:rgba(7,8,15,.8);display:flex;align-items:flex-end;justify-content:center;opacity:0;pointer-events:none;transition:opacity .3s;backdrop-filter:blur(12px)}
.overlay.open{opacity:1;pointer-events:all}
.sheet{background:var(--card);width:100%;max-width:480px;border-radius:28px 28px 0 0;transform:translateY(100%);transition:transform .4s var(--ease);max-height:94vh;overflow-y:auto;border-top:1px solid rgba(255,255,255,.08);box-shadow:0 -20px 60px rgba(0,0,0,.5)}
.overlay.open .sheet{transform:translateY(0)}
.sheet-drag{width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,.12);margin:12px auto 0}
.sheet-head{padding:16px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.sheet-icon{width:44px;height:44px;border-radius:14px;flex-shrink:0;background:linear-gradient(135deg,rgba(255,95,143,.2),rgba(139,92,246,.2));border:1px solid rgba(139,92,246,.2);display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.sheet-title{font-size:1rem;font-weight:800}
.sheet-sub{font-size:.72rem;color:var(--text2);margin-top:1px}
/* fields */
.field{padding:14px 20px 0}
.field label{font-size:.74rem;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:6px;margin-bottom:7px}
.field label i{color:var(--violet)}
.inp{width:100%;padding:13px 16px;border-radius:14px;background:var(--card2);border:1px solid var(--border);color:var(--text);font-family:var(--font);font-size:.92rem;outline:none;transition:border-color .2s,box-shadow .2s}
.inp:focus{border-color:rgba(139,92,246,.5);box-shadow:0 0 0 3px rgba(139,92,246,.08)}
.inp::placeholder{color:var(--text3)}
textarea.inp{resize:none;height:75px;line-height:1.5}
.hint{font-size:.67rem;color:var(--text3);margin-top:5px;display:flex;align-items:center;gap:4px}
.found-user{margin:10px 20px 0;border-radius:14px;background:rgba(20,184,166,.06);border:1px solid rgba(20,184,166,.2);padding:12px 14px;display:none;align-items:center;gap:12px;animation:slideIn .25s var(--ease)}
.found-user.show{display:flex}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.found-avatar{width:42px;height:42px;border-radius:12px;flex-shrink:0;background:linear-gradient(135deg,#14b8a6,#0ea5e9);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1.1rem;color:#fff}
.found-name{font-size:.88rem;font-weight:800}
.found-user-tag{font-size:.7rem;color:var(--teal)}
.quick-row{padding:10px 20px 0;display:flex;gap:7px;flex-wrap:wrap}
.qa{padding:7px 14px;border-radius:10px;border:1px solid var(--border);background:var(--card2);color:var(--text2);font-family:var(--font);font-size:.8rem;font-weight:700;cursor:pointer;transition:all .2s}
.qa.on,.qa:hover{background:rgba(139,92,246,.15);border-color:rgba(139,92,246,.4);color:var(--violet)}
/* preview card */
.preview-section{padding:14px 20px 0;display:none}
.preview-section.show{display:block}
.preview-label{font-size:.72rem;font-weight:700;color:var(--text2);margin-bottom:9px;display:flex;align-items:center;gap:6px}
.gift-preview-card{border-radius:20px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.06);position:relative;background:linear-gradient(145deg,#0c0118,#160830,#060d1f)}
.gpc-glow{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 60% 50% at 20% 20%,rgba(139,92,246,.18) 0%,transparent 60%),radial-gradient(ellipse 40% 40% at 80% 80%,rgba(255,95,143,.12) 0%,transparent 60%)}
.gpc-stars{position:absolute;inset:0;pointer-events:none;background-image:radial-gradient(1.5px 1.5px at 15% 25%,rgba(255,255,255,.7) 0%,transparent 100%),radial-gradient(1px 1px at 40% 15%,rgba(255,255,255,.5) 0%,transparent 100%),radial-gradient(1.5px 1.5px at 70% 35%,rgba(255,255,255,.6) 0%,transparent 100%),radial-gradient(1px 1px at 85% 15%,rgba(255,255,255,.4) 0%,transparent 100%),radial-gradient(1.5px 1.5px at 25% 70%,rgba(255,255,255,.5) 0%,transparent 100%)}
.gpc-body{padding:22px 20px 16px;position:relative;z-index:1}
.gpc-ribbon{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,rgba(255,95,143,.2),rgba(139,92,246,.2));border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:4px 12px;margin-bottom:14px;font-size:.65rem;font-weight:800;color:rgba(255,255,255,.7);letter-spacing:.06em;text-transform:uppercase}
.gpc-from{font-size:.7rem;color:rgba(255,255,255,.45);margin-bottom:3px}
.gpc-name{font-size:1.05rem;font-weight:900;color:#fff;margin-bottom:16px;letter-spacing:-.01em}
.gpc-amount-box{background:rgba(255,255,255,.06);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:13px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between}
.gpc-amount-label{font-size:.7rem;color:rgba(255,255,255,.45)}
.gpc-amount-val{font-size:1.5rem;font-weight:900;letter-spacing:-.03em;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.gpc-msg{background:rgba(255,255,255,.04);border-radius:12px;padding:10px 14px;font-size:.78rem;color:rgba(255,255,255,.6);font-style:italic;line-height:1.55;border-right:2px solid rgba(255,95,143,.4);display:none}
.gpc-msg.show{display:block}
.gpc-footer{background:rgba(0,0,0,.25);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid rgba(255,255,255,.04);position:relative;z-index:1}
.gpc-footer-brand{font-size:.68rem;font-weight:800;color:rgba(255,255,255,.3);letter-spacing:.05em}
.gpc-footer-badge{font-size:.62rem;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.04em;background:rgba(245,158,11,.12);color:var(--amber);border:1px solid rgba(245,158,11,.15)}
.send-btn{width:calc(100% - 40px);margin:14px 20px 0;padding:15px;border-radius:16px;background:linear-gradient(135deg,#ff5f8f,#8b5cf6);color:#fff;font-family:var(--font);font-size:.92rem;font-weight:800;border:none;cursor:pointer;transition:all .2s var(--ease);display:flex;align-items:center;justify-content:center;gap:9px;box-shadow:0 8px 32px rgba(139,92,246,.35)}
.send-btn:disabled{opacity:.4;pointer-events:none}
.send-btn:active{transform:scale(.97)}
/* envelope */
.env-wrap{position:fixed;inset:0;z-index:500;background:rgba(7,8,15,.93);
  display:flex;align-items:center;justify-content:center;flex-direction:column;
  backdrop-filter:blur(20px);opacity:0;pointer-events:none;transition:opacity .3s}
.env-wrap.vis{opacity:1;pointer-events:all}
.env-scene{position:relative;cursor:pointer;user-select:none}
.env-body{width:300px;height:190px;background:linear-gradient(160deg,#1a0533,#2d1060,#120820);border-radius:16px;border:1px solid rgba(139,92,246,.3);box-shadow:0 32px 80px rgba(139,92,246,.35),0 0 0 1px rgba(255,255,255,.05);position:relative;overflow:hidden;transition:transform .5s var(--ease)}
.env-body::before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse 70% 50% at 50% 100%,rgba(139,92,246,.2),transparent 60%),radial-gradient(1px 1px at 20% 30%,rgba(255,255,255,.5) 0%,transparent 100%),radial-gradient(1px 1px at 70% 20%,rgba(255,255,255,.4) 0%,transparent 100%),radial-gradient(1px 1px at 85% 60%,rgba(255,255,255,.3) 0%,transparent 100%)}
.env-flap{position:absolute;top:0;left:0;right:0;height:0;border-left:150px solid transparent;border-right:150px solid transparent;border-top:95px solid #2d1060;transform-origin:top center;transition:transform .65s var(--ease);z-index:5;filter:drop-shadow(0 3px 8px rgba(0,0,0,.4))}
.env-flap::after{content:"";position:absolute;top:-97px;left:-150px;border-left:150px solid transparent;border-right:150px solid transparent;border-top:95px solid rgba(139,92,246,.25)}
.env-bottom{position:absolute;bottom:0;left:0;right:0;height:0;border-left:150px solid transparent;border-right:150px solid transparent;border-bottom:95px solid rgba(139,92,246,.1)}
.env-seal{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:6;width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,#ff5f8f,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:1.5rem;box-shadow:0 0 28px rgba(255,95,143,.6),0 0 0 4px rgba(255,255,255,.08);transition:all .4s}
.env-card{position:absolute;top:30px;left:50%;transform:translateX(-50%) translateY(30px);width:260px;border-radius:18px;z-index:4;background:linear-gradient(145deg,#0c0118,#160830);border:1px solid rgba(139,92,246,.25);padding:18px;opacity:0;transition:all .7s .25s var(--ease);box-shadow:0 24px 60px rgba(0,0,0,.7);pointer-events:none}
.env-scene.opened .env-flap{transform:rotateX(-180deg)}
.env-scene.opened .env-seal{opacity:0;transform:translate(-50%,-50%) scale(0)}
.env-scene.opened .env-body{transform:translateY(20px)}
.env-scene.opened .env-card{opacity:1;transform:translateX(-50%) translateY(-110px)}
.env-card-tag{font-size:.62rem;font-weight:800;color:rgba(255,255,255,.4);letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.env-card-amount{font-size:2rem;font-weight:900;letter-spacing:-.04em;text-align:center;background:linear-gradient(135deg,#fbbf24,#f97316);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.env-card-curr{font-size:.75rem;color:rgba(255,255,255,.4);text-align:center;margin-bottom:10px}
.env-card-msg{background:rgba(255,255,255,.05);border-radius:10px;padding:9px 12px;font-size:.75rem;color:rgba(255,255,255,.65);font-style:italic;line-height:1.5;text-align:center;border:1px solid rgba(255,255,255,.06);display:none}
.env-tap{text-align:center;color:rgba(255,255,255,.4);font-size:.8rem;font-weight:600;margin-top:20px}
.env-tap span{display:inline-block;animation:bounce .9s ease-in-out infinite}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.env-close{margin-top:16px;color:rgba(255,255,255,.35);font-size:.78rem;font-weight:600;cursor:pointer;padding:8px 20px;border-radius:20px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);font-family:var(--font);transition:all .2s}
.conf{position:fixed;pointer-events:none;z-index:600;border-radius:3px}
@keyframes fall{0%{transform:translateY(-20px) rotate(0deg);opacity:1}100%{transform:translateY(105vh) rotate(600deg);opacity:0}}
.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(8px);z-index:9999;padding:11px 22px;border-radius:50px;font-family:var(--font);font-size:.84rem;font-weight:700;opacity:0;pointer-events:none;transition:opacity .25s,transform .25s;box-shadow:0 8px 32px rgba(0,0,0,.4);white-space:nowrap}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.s{background:rgba(20,184,166,.15);border:1px solid rgba(20,184,166,.3);color:var(--teal)}
.toast.e{background:rgba(255,95,143,.12);border:1px solid rgba(255,95,143,.3);color:var(--rose)}
</style>
</head>
<body>

<div class="header">
  <a href="javascript:history.back()" class="back-btn"><i class="fas fa-chevron-right"></i></a>
  <div>
    <div class="header-title">الهدايا 🎁</div>
    <div class="header-sub">أرسل رصيداً كهدية لأصدقائك</div>
  </div>
  <?php if($pendingCount): ?>
  <div style="margin-right:auto;background:linear-gradient(135deg,#ff5f8f,#c026d3);color:#fff;font-size:.72rem;font-weight:800;padding:5px 13px;border-radius:20px;box-shadow:0 4px 12px rgba(255,95,143,.4)">
    <?=$pendingCount?> هدية جديدة 🎉
  </div>
  <?php endif; ?>
</div>

<div class="balance-strip">
  <div>
    <div class="balance-label">رصيدك المتاح</div>
    <div class="balance-val"><?=number_format($userBalance,2)?> <span style="font-size:.9rem;opacity:.7"><?=$currSymbol?></span></div>
  </div>
  <div style="font-size:2rem;opacity:.3">💳</div>
</div>

<div class="tab-wrap">
  <button class="tab-btn active" id="t-recv" onclick="sw('recv')">
    <i class="fas fa-inbox" style="font-size:.9rem"></i> المستلمة
    <?php if($pendingCount): ?><span class="tab-pill"><?=$pendingCount?></span><?php endif; ?>
  </button>
  <button class="tab-btn" id="t-sent" onclick="sw('sent')">
    <i class="fas fa-paper-plane" style="font-size:.9rem"></i> المُرسَلة
  </button>
</div>

<div id="p-recv">
  <?php if(empty($receivedGifts)): ?>
  <div class="empty"><div class="empty-icon">📭</div><div class="empty-txt">لا هدايا مستلمة بعد</div><div class="empty-sub">عندما يرسل لك أحد هدية ستظهر هنا</div></div>
  <?php else: foreach($receivedGifts as $g):
    $person=$g['send_name']?:$g['send_username']; $initial=mb_substr($person,0,1); $isPending=$g['status']==='pending';
  ?>
  <div class="gift-card <?=$isPending?'fresh':''?>" id="gc-<?=$g['id']?>">
    <div class="gc-top">
      <div class="gc-avatar recv"><?=$initial?><div class="gc-avatar-badge"><?=$isPending?'🎁':'✓'?></div></div>
      <div class="gc-info">
        <div class="gc-name">من <?=htmlspecialchars($person)?></div>
        <div class="gc-time"><i class="fas fa-clock" style="font-size:.6rem"></i> <?=date('d/m/Y • H:i',strtotime($g['created_at']))?></div>
      </div>
      <div class="gc-amount"><?=number_format((float)$g['amount'],2)?><span style="font-size:.7rem;opacity:.7;font-weight:600"> <?=$currSymbol?></span></div>
    </div>
    <?php if($g['message']): ?><div class="gc-msg">"<?=htmlspecialchars($g['message'])?>"</div><?php endif; ?>
    <div class="gc-footer">
      <div class="status-badge status-<?=$g['status']?>"><?=$isPending?'<i class="fas fa-gift"></i> لم تُفتح':'<i class="fas fa-check"></i> مفتوحة'?></div>
      <?php if($isPending): ?>
      <button class="open-btn" onclick="openEnv(<?=$g['id']?>,<?=(float)$g['amount']?>,<?=htmlspecialchars(json_encode($g['message']??'',JSON_UNESCAPED_UNICODE),ENT_QUOTES)?>)"><i class="fas fa-gift"></i> افتح الهدية</button>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<div id="p-sent" style="display:none">
  <?php if(empty($sentGifts)): ?>
  <div class="empty"><div class="empty-icon">📤</div><div class="empty-txt">لم ترسل أي هدية بعد</div><div class="empty-sub">اضغط على الزر أدناه لإرسال هديتك الأولى</div></div>
  <?php else: foreach($sentGifts as $g):
    $person=$g['recv_name']?:$g['recv_username']; $initial=mb_substr($person,0,1);
    $stMap=['pending'=>'⏳ لم تُفتح','opened'=>'✅ تم الفتح','rejected'=>'❌ مرفوضة'];
  ?>
  <div class="gift-card">
    <div class="gc-top">
      <div class="gc-avatar sent"><?=$initial?><div class="gc-avatar-badge">📤</div></div>
      <div class="gc-info">
        <div class="gc-name">إلى <?=htmlspecialchars($person)?></div>
        <div class="gc-time"><i class="fas fa-clock" style="font-size:.6rem"></i> <?=date('d/m/Y • H:i',strtotime($g['created_at']))?></div>
      </div>
      <div class="gc-amount"><?=number_format((float)$g['amount'],2)?><span style="font-size:.7rem;opacity:.7;font-weight:600"> <?=$currSymbol?></span></div>
    </div>
    <?php if($g['message']): ?><div class="gc-msg">"<?=htmlspecialchars($g['message'])?>"</div><?php endif; ?>
    <div class="gc-footer"><div class="status-badge status-<?=$g['status']?>"><?=$stMap[$g['status']]??$g['status']?></div></div>
  </div>
  <?php endforeach; endif; ?>
</div>

<div style="height:90px"></div>

<button class="fab" onclick="openSheet()">
  <div class="fab-pulse"></div>
  <i class="fas fa-gift"></i> أرسل هدية
</button>

<!-- Send Sheet -->
<div class="overlay" id="sendOverlay" onclick="if(event.target===this)closeSheet()">
  <div class="sheet">
    <div class="sheet-drag"></div>
    <div class="sheet-head">
      <div class="sheet-icon">🎁</div>
      <div><div class="sheet-title">أرسل هدية لصديق</div><div class="sheet-sub">سيصله الرصيد فوراً مع رسالتك</div></div>
    </div>
    <div class="field">
      <label><i class="fas fa-search"></i> اسم المستخدم أو كود الإحالة</label>
      <input class="inp" id="sIn" placeholder="username أو referral code..." oninput="onSI(this.value)" autocomplete="off">
      <div class="hint"><i class="fas fa-info-circle"></i> أدخل اسم المستخدم أو كود الإحالة</div>
    </div>
    <div class="found-user" id="foundUser">
      <div class="found-avatar" id="fAvatar"></div>
      <div><div class="found-name" id="fName"></div><div class="found-user-tag" id="fTag"></div></div>
      <i class="fas fa-check-circle" style="margin-right:auto;color:var(--teal);font-size:1.3rem"></i>
    </div>
    <input type="hidden" id="rId">
    <div class="field">
      <label><i class="fas fa-coins"></i> المبلغ (<?=$currSymbol?>)</label>
      <input class="inp" type="number" id="aIn" placeholder="0.00" min="0.01" step="0.01" oninput="onAI()" style="font-size:1.1rem;font-weight:800;letter-spacing:-.02em">
    </div>
    <div class="quick-row">
      <?php foreach([1,5,10,25,50,100] as $q): ?>
      <button class="qa" onclick="setA(<?=$q?>)"><?=$q?></button>
      <?php endforeach; ?>
    </div>
    <div class="field">
      <label><i class="fas fa-pen-nib"></i> رسالة (اختياري)</label>
      <textarea class="inp" id="mIn" placeholder="اكتب رسالتك هنا..." oninput="upPrev()"></textarea>
    </div>
    <div class="preview-section" id="prevSec">
      <div class="preview-label"><i class="fas fa-eye" style="color:var(--violet)"></i> معاينة ما سيصل لصديقك</div>
      <div class="gift-preview-card">
        <div class="gpc-glow"></div><div class="gpc-stars"></div>
        <div class="gpc-body">
          <div class="gpc-ribbon">✨ &nbsp;هدية خاصة</div>
          <div class="gpc-from">هدية من</div>
          <div class="gpc-name"><?=htmlspecialchars($userName)?></div>
          <div class="gpc-amount-box"><span class="gpc-amount-label">المبلغ</span><span class="gpc-amount-val" id="pAmt">—</span></div>
          <div class="gpc-msg" id="pMsg"></div>
        </div>
        <div class="gpc-footer"><span class="gpc-footer-brand"><?=htmlspecialchars($siteName)?></span><span class="gpc-footer-badge">🎉 هدية</span></div>
      </div>
    </div>
    <button class="send-btn" id="sendBtn" onclick="doSend()" disabled><i class="fas fa-paper-plane"></i> أرسل الهدية الآن</button>
    <div style="text-align:center;font-size:.72rem;color:var(--text3);margin:8px 20px 0;padding-bottom:4px">
      رصيدك: <strong style="color:var(--teal)"><?=number_format($userBalance,2)?> <?=$currSymbol?></strong>
    </div>
    <div style="height:16px"></div>
  </div>
</div>

<!-- Envelope -->
<div class="env-wrap" id="envWrap">
  <div class="env-scene" id="envScene" onclick="tapEnv()">
    <div class="env-card" id="envCard">
      <div class="env-card-tag">🎉 هدية وصلتك</div>
      <div class="env-card-amount" id="envAmt"></div>
      <div class="env-card-curr"><?=$currSymbol?></div>
      <div class="env-card-msg" id="envMsg"></div>
    </div>
    <div class="env-body">
      <div class="env-flap"></div>
      <div class="env-bottom"></div>
      <div class="env-seal">🎁</div>
    </div>
  </div>
  <div class="env-tap" id="envTap"><span>👆</span> انقر لفتح الهدية</div>
  <button class="env-close" onclick="closeEnvW()">إغلاق</button>
</div>

<div class="toast" id="toast"></div>

<script>
const CURR=<?=json_encode($currSymbol)?>;
let curR=null,sT=null,eOpened=false,curGid=null;
function sw(t){
  document.getElementById('p-recv').style.display=t==='recv'?'':"none";
  document.getElementById('p-sent').style.display=t==='sent'?'':"none";
  document.getElementById('t-recv').classList.toggle('active',t==='recv');
  document.getElementById('t-sent').classList.toggle('active',t==='sent');
}
function openSheet(){document.getElementById('sendOverlay').classList.add('open')}
function closeSheet(){document.getElementById('sendOverlay').classList.remove('open')}
function onSI(v){
  clearTimeout(sT);curR=null;document.getElementById('rId').value='';
  document.getElementById('foundUser').classList.remove('show');
  checkBtn();if(v.length<2)return;
  sT=setTimeout(()=>findU(v),450);
}
async function findU(q){
  const fd=new FormData();fd.append('action','find_user');fd.append('q',q);
  const r=await fetch(location.href,{method:'POST',body:fd}).then(x=>x.json());
  if(!r.ok){toast(r.msg,'e');return;}
  curR=r.user;document.getElementById('rId').value=r.user.id;
  document.getElementById('fAvatar').textContent=r.user.initial;
  document.getElementById('fName').textContent=r.user.name;
  document.getElementById('fTag').textContent='@'+r.user.username;
  document.getElementById('foundUser').classList.add('show');
  upPrev();checkBtn();
}
function setA(v){
  document.getElementById('aIn').value=v;
  document.querySelectorAll('.qa').forEach(b=>b.classList.toggle('on',parseFloat(b.textContent)===v));
  upPrev();checkBtn();
}
function onAI(){document.querySelectorAll('.qa').forEach(b=>b.classList.remove('on'));upPrev();checkBtn();}
function upPrev(){
  const amt=parseFloat(document.getElementById('aIn').value)||0;
  const msg=document.getElementById('mIn').value.trim();
  const ps=document.getElementById('prevSec');
  if(amt>0&&curR){
    ps.classList.add('show');
    document.getElementById('pAmt').textContent=amt.toFixed(2)+' '+CURR;
    const pm=document.getElementById('pMsg');
    if(msg){pm.textContent='"'+msg+'"';pm.classList.add('show');}else{pm.textContent='';pm.classList.remove('show');}
  }else ps.classList.remove('show');
}
function checkBtn(){document.getElementById('sendBtn').disabled=!(curR&&parseFloat(document.getElementById('aIn').value)>0);}
async function doSend(){
  const amt=parseFloat(document.getElementById('aIn').value);
  if(!curR||amt<=0)return;
  const btn=document.getElementById('sendBtn');
  btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> جاري الإرسال...';
  const fd=new FormData();
  fd.append('action','send_gift');fd.append('receiver_id',curR.id);
  fd.append('amount',amt);fd.append('message',document.getElementById('mIn').value.trim());
  const r=await fetch(location.href,{method:'POST',body:fd}).then(x=>x.json());
  btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> أرسل الهدية الآن';
  if(!r.ok){toast(r.msg,'e');return;}
  toast(r.msg,'s');closeSheet();setTimeout(()=>location.reload(),1000);
}
function openEnv(id,amount,message){
  curGid=id;eOpened=false;amount=parseFloat(amount)||0;
  document.getElementById('envScene').classList.remove('opened');
  document.getElementById('envTap').style.display='';
  document.getElementById('envAmt').textContent=amount.toFixed(2);
  const em=document.getElementById('envMsg');
  if(message){em.textContent='"'+message+'"';em.style.display='';}else em.style.display='none';
  const w=document.getElementById('envWrap');
  w.classList.add('vis');
}
function tapEnv(){
  if(eOpened)return;eOpened=true;
  document.getElementById('envScene').classList.add('opened');
  document.getElementById('envTap').style.display='none';
  confetti();
  const fd=new FormData();fd.append('action','open_gift');fd.append('gift_id',curGid);
  fetch(location.href,{method:'POST',body:fd}).then(x=>x.json()).then(r=>{
    if(r.ok){
      const c=document.getElementById('gc-'+curGid);
      if(c){
        c.classList.remove('fresh');
        const sb=c.querySelector('.status-badge');
        if(sb){sb.className='status-badge status-opened';sb.innerHTML='<i class="fas fa-check"></i> مفتوحة';}
        const ob=c.querySelector('.open-btn');if(ob)ob.remove();
      }
    }
  });
}
function closeEnvW(){
  document.getElementById('envWrap').classList.remove('vis');
}
function confetti(){
  const col=['#ff5f8f','#f59e0b','#8b5cf6','#14b8a6','#f97316','#fff','#c026d3'];
  for(let i=0;i<90;i++){
    const el=document.createElement('div');el.className='conf';
    el.style.cssText=`left:${Math.random()*100}vw;top:-12px;background:${col[i%col.length]};width:${5+Math.random()*8}px;height:${5+Math.random()*8}px;border-radius:${Math.random()>.5?'50%':'3px'};animation:fall ${1.4+Math.random()*2}s ${Math.random()*.6}s linear forwards`;
    document.body.appendChild(el);setTimeout(()=>el.remove(),3500);
  }
}
function toast(msg,t='s'){
  const el=document.getElementById('toast');
  el.textContent=msg;el.className='toast show '+t;
  clearTimeout(el._t);el._t=setTimeout(()=>el.className='toast',3000);
}
// envelope ready
</script>
</body>
</html>