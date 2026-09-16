<?php
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/includes/password_reset_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/smtp_mailer.php';
require_once dirname(__DIR__, 2) . '/includes/rate_limiter.php';
apiRateLimit($pdo, 'mobile_forgot_password', 8, 900);
$email=mb_strtolower(trim($_POST['email']??''));
if(!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) jsonOutMobile(false,'الرجاء إدخال بريد إلكتروني صحيح',[],400);
$stmt=$pdo->prepare('SELECT id, username, email, full_name, last_password_reset_at FROM users WHERE email=? AND is_deleted=0 AND status=1 LIMIT 1'); $stmt->execute([$email]); $user=$stmt->fetch(PDO::FETCH_ASSOC);
$msg='إذا كان البريد مسجلاً لدينا، ستصلك رسالة تحتوي على كلمة المرور الجديدة'; if(!$user) jsonOutMobile(true,$msg);
$last=(int)($user['last_password_reset_at']??0); $cool=(int)(getSetting('password_reset_cooldown_hours')?:24); if($cool<1)$cool=24;
if($last>0 && time()-$last < $cool*3600) jsonOutMobile(false,"تم إرسال كلمة مرور جديدة لهذا الحساب خلال آخر {$cool} ساعة، يرجى المحاولة لاحقاً",[],429);
$new=generateStrongPassword(12); $hashed=password_hash($new,PASSWORD_DEFAULT); $site=getSetting('site_name')?:SITE_NAME; $subject=buildResetEmailSubject($site); $name=$user['full_name']?:$user['username']; $body=buildResetEmailHtml($site,$name,$new,SITE_URL.'/login.php');
$mailer=getSmtpMailerForSection($pdo,'reset'); $sent=$mailer->send($user['email'],$name,$subject,$body);
try{$pdo->prepare('INSERT INTO password_reset_logs (user_id,username,email,ip,status,error_message) VALUES (?,?,?,?,?,?)')->execute([$user['id'],$user['username'],$user['email'],getClientIP(),$sent?'sent':'failed',$sent?null:mb_substr($mailer->lastError,0,490)]);}catch(Exception $e){}
if(!$sent) jsonOutMobile(false,'تعذّر إرسال البريد الإلكتروني حالياً. يرجى المحاولة لاحقاً أو التواصل مع الدعم الفني.',[],503);
$pdo->prepare('UPDATE users SET password=?, last_password_reset_at=? WHERE id=?')->execute([$hashed,time(),$user['id']]);
jsonOutMobile(true,'تم إرسال كلمة مرور جديدة إلى بريدك الإلكتروني بنجاح');
