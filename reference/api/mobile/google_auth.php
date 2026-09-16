<?php
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/device_helper.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';
apiRateLimit($pdo, 'mobile_google_auth', 10, 60);
$action=trim($_POST['action']??'login'); $idToken=trim($_POST['id_token']??''); $deviceId=trim($_POST['device_id']??''); $phone=trim($_POST['phone']??'');
if (!$idToken) jsonOutMobile(false,'تعذر التحقق من حساب Google',[],400);
$clientId=trim((string)getSetting('google_client_id')); $enabled=getSetting('google_login_enabled');
if (!$enabled || !$clientId) jsonOutMobile(false,'تسجيل الدخول عبر Google غير مفعّل حالياً',[],503);
$ch=curl_init('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($idToken));
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_TIMEOUT=>15]);
$raw=curl_exec($ch); $err=curl_error($ch); curl_close($ch); $info=json_decode($raw?:'',true);
if (!$info || $err || empty($info['sub']) || empty($info['email']) || ($info['aud']??'')!==$clientId || ($info['email_verified']??'false')!=='true') jsonOutMobile(false,'رمز Google غير صالح أو غير موثّق',[],401);
$email=mb_strtolower(trim((string)$info['email'])); $googleId=(string)$info['sub']; $fullName=trim((string)($info['name']??'')); $avatar=trim((string)($info['picture']??''));
$stmt=$pdo->prepare('SELECT * FROM users WHERE google_id=? AND status=1 LIMIT 1'); $stmt->execute([$googleId]); $user=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { $stmt=$pdo->prepare('SELECT * FROM users WHERE email=? AND status=1 LIMIT 1'); $stmt->execute([$email]); $user=$stmt->fetch(PDO::FETCH_ASSOC); if($user) $pdo->prepare('UPDATE users SET google_id=?, google_avatar=? WHERE id=?')->execute([$googleId,$avatar,$user['id']]); }
if ($action==='login') {
  if(!$user) jsonOutMobile(true,'الحساب غير موجود، أكمل التسجيل',['needs_registration'=>true,'google'=>['email'=>$email,'name'=>$fullName,'avatar'=>$avatar]]);
  $auto=getSetting('device_auto_approve'); $deviceStatus=$auto ? (checkAndRegisterDevice($pdo,$user['id'],true,$deviceId),'approved') : checkAndRegisterDevice($pdo,$user['id'],false,$deviceId);
  if($deviceStatus==='blocked') jsonOutMobile(false,'هذا الجهاز محظور. تواصل مع الإدارة.',['device_blocked'=>true],403);
  if($deviceStatus==='pending') jsonOutMobile(false,'جهاز غير مصرح، تحقق من بريدك أو واتساب لتفعيله',['device_pending'=>true],403);
  $pdo->prepare('UPDATE users SET last_login=NOW(), google_avatar=? WHERE id=?')->execute([$avatar,$user['id']]);
  $token=generateMobileJWT($user['id'],$user['role']);
  jsonOutMobile(true,'مرحباً '.($user['full_name']?:$user['username']),['token'=>$token,'user'=>['id'=>$user['id'],'uid'=>str_pad($user['id'],6,'0',STR_PAD_LEFT),'name'=>$user['full_name']?:$user['username'],'username'=>$user['username'],'email'=>$user['email'],'balance'=>number_format((float)$user['balance'],2),'role'=>$user['role']]]);
}
if($action!=='register') jsonOutMobile(false,'طلب غير صالح',[],400);
if(!preg_match('/^\+?[0-9]{7,18}$/',$phone)) jsonOutMobile(false,'رقم الهاتف غير صحيح',[],400);
if($user) jsonOutMobile(false,'هذا البريد مرتبط بحساب موجود بالفعل، استخدم تسجيل الدخول عبر Google',[],409);
$chk=$pdo->prepare('SELECT id FROM users WHERE phone=? LIMIT 1'); $chk->execute([$phone]); if($chk->fetch()) jsonOutMobile(false,'رقم الهاتف مستخدم بالفعل لحساب آخر',[],409);
$temp='g_'.time().'_'.random_int(100,999);
$pdo->prepare("INSERT INTO users (username,email,password,full_name,display_name,phone,google_id,google_avatar,status,role,registration_source,created_at,last_login) VALUES (?,?,?,?,?,?,?,?,1,'customer','google_mobile',NOW(),NOW())")->execute([$temp,$email,'',$fullName,$fullName,$phone,$googleId,$avatar]);
$id=(int)$pdo->lastInsertId(); $username='g'.$id; $pdo->prepare('UPDATE users SET username=? WHERE id=?')->execute([$username,$id]); checkAndRegisterDevice($pdo,$id,true,$deviceId);
$token=generateMobileJWT($id,'customer'); jsonOutMobile(true,'تم إنشاء حسابك بنجاح!',['token'=>$token,'user'=>['id'=>$id,'uid'=>str_pad($id,6,'0',STR_PAD_LEFT),'name'=>$fullName?:$username,'username'=>$username,'email'=>$email,'balance'=>'0.00','role'=>'customer']],201);
