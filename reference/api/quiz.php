<?php
// منع أي PHP warnings من كسر JSON
error_reporting(0);
ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = isLoggedIn() ? (int)$_SESSION['user_id'] : 0;

switch ($action) {

case 'active':
    // جلب المسابقة النشطة
    $quiz = $pdo->query("SELECT * FROM quizzes WHERE status=1 ORDER BY created_at DESC LIMIT 1")->fetch();
    if (!$quiz) { echo json_encode(['ok'=>false,'error'=>'no_active']); break; }
    $questions = $pdo->query("SELECT id,sort_order,question,option_a,option_b,option_c,option_d FROM quiz_questions WHERE quiz_id={$quiz['id']} ORDER BY sort_order")->fetchAll();
    $myEntry = null;
    if ($userId) {
        $e=$pdo->prepare("SELECT * FROM quiz_entries WHERE quiz_id=? AND user_id=?");
        $e->execute([$quiz['id'],$userId]); $myEntry=$e->fetch();
    }
    echo json_encode(['ok'=>true,'quiz'=>$quiz,'questions'=>$questions,'my_entry'=>$myEntry], JSON_UNESCAPED_UNICODE);
    break;

case 'submit':
    if (!$userId) { echo json_encode(['ok'=>false,'error'=>'login']); break; }
    $quizId   = (int)($_POST['quiz_id'] ?? 0);
    $answers  = json_decode($_POST['answers'] ?? '{}', true);
    $timeTaken= (int)($_POST['time_taken'] ?? 0);

    if (!$quizId || empty($answers)) { echo json_encode(['ok'=>false,'error'=>'invalid']); break; }

    // تحقق أن المسابقة نشطة
    $quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id=? AND status=1");
    $quiz->execute([$quizId]); $quiz=$quiz->fetch();
    if (!$quiz) { echo json_encode(['ok'=>false,'error'=>'closed']); break; }

    // شرط: حساب موثق
    $user = $pdo->prepare("SELECT * FROM users WHERE id=?"); $user->execute([$userId]); $user=$user->fetch();
    try {
        $kyc = $pdo->prepare("SELECT status FROM kyc_requests WHERE user_id=? AND status='approved' LIMIT 1");
        $kyc->execute([$userId]); $kycRow=$kyc->fetch();
        if (!$kycRow) { echo json_encode(['ok'=>false,'error'=>'kyc','message'=>'يجب توثيق حسابك للمشاركة في المسابقات']); break; }
    } catch(Exception $e) { /* جدول KYC غير موجود - تجاوز */ }

    // شرط: رصيد > 0
    if ((float)($user['balance']??0) <= 0) {
        echo json_encode(['ok'=>false,'error'=>'balance','message'=>'يجب أن يكون لديك رصيد في حسابك للمشاركة']);
        break;
    }

    // تحقق لم يشارك من قبل
    $existing = $pdo->prepare("SELECT id FROM quiz_entries WHERE quiz_id=? AND user_id=?");
    $existing->execute([$quizId,$userId]);
    if ($existing->fetch()) { echo json_encode(['ok'=>false,'error'=>'already','message'=>'شاركت في هذه المسابقة مسبقاً']); break; }

    // احتساب الإجابات الصحيحة
    $questions = $pdo->query("SELECT * FROM quiz_questions WHERE quiz_id=$quizId")->fetchAll();
    $correct = 0;
    foreach ($questions as $q) {
        $qid = (string)$q['id'];
        if (isset($answers[$qid]) && $answers[$qid] === $q['correct']) $correct++;
    }

    // حفظ المشاركة
    $pdo->prepare("INSERT INTO quiz_entries (quiz_id,user_id,answers,correct_count,total_questions,time_taken) VALUES (?,?,?,?,?,?)")
        ->execute([$quizId,$userId,json_encode($answers),$correct,count($questions),$timeTaken]);

    echo json_encode([
        'ok'      => true,
        'correct' => $correct,
        'total'   => count($questions),
        'message' => "أجبت على {$correct} من أصل ".count($questions)." سؤال بشكل صحيح! 🎉"
    ], JSON_UNESCAPED_UNICODE);
    break;

default:
    echo json_encode(['ok'=>false,'error'=>'invalid action']);
}
