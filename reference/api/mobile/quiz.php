<?php
// Mobile Quiz API
// Uses the mobile JWT authentication and handles quiz requests directly.
// This file is intended for: /api/mobile/quiz.php

require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');

$userId = mobileAuthorizeRequest($pdo, true);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function quizOut(bool $ok, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    $response = ['ok' => $ok];
    if ($message !== '') {
        $response['message'] = $message;
    }
    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireVerifiedQuizUser(PDO $pdo, int $userId): void
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM kyc_requests WHERE user_id = ? AND status = 'approved' LIMIT 1"
        );
        $stmt->execute([$userId]);

        if (!$stmt->fetchColumn()) {
            quizOut(false, 'يجب توثيق حسابك للمشاركة في المسابقات', ['error' => 'kyc'], 403);
        }
    } catch (Throwable $e) {
        quizOut(false, 'تعذر التحقق من حالة توثيق الحساب', ['error' => 'kyc_check'], 503);
    }
}

if ($action === 'active') {
    try {
        $stmt = $pdo->query(
            "SELECT * FROM quizzes WHERE status = 1 ORDER BY created_at DESC LIMIT 1"
        );
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            quizOut(false, 'لا توجد مسابقة نشطة حالياً', ['error' => 'no_active']);
        }

        $questionsStmt = $pdo->prepare(
            "SELECT id, sort_order, question, option_a, option_b, option_c, option_d
             FROM quiz_questions
             WHERE quiz_id = ?
             ORDER BY sort_order"
        );
        $questionsStmt->execute([(int)$quiz['id']]);
        $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $entryStmt = $pdo->prepare(
            "SELECT * FROM quiz_entries WHERE quiz_id = ? AND user_id = ? LIMIT 1"
        );
        $entryStmt->execute([(int)$quiz['id'], $userId]);
        $myEntry = $entryStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        quizOut(true, '', [
            'quiz' => $quiz,
            'questions' => $questions,
            'my_entry' => $myEntry,
        ]);
    } catch (Throwable $e) {
        quizOut(false, 'تعذر تحميل المسابقة', ['error' => 'quiz_load'], 500);
    }
}

if ($action !== 'submit' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    quizOut(false, 'طلب غير صالح', ['error' => 'invalid_request'], 400);
}

requireVerifiedQuizUser($pdo, $userId);

$quizId = (int)($_POST['quiz_id'] ?? 0);
$answersRaw = $_POST['answers'] ?? '';
$timeTaken = (int)($_POST['time_taken'] ?? 0);

$answers = json_decode($answersRaw, true);

if ($quizId <= 0 || !is_array($answers)) {
    quizOut(false, 'بيانات المشاركة غير صالحة', ['error' => 'invalid_data'], 400);
}

try {
    $quizStmt = $pdo->prepare(
        "SELECT * FROM quizzes WHERE id = ? AND status = 1 LIMIT 1"
    );
    $quizStmt->execute([$quizId]);
    $quiz = $quizStmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        quizOut(false, 'المسابقة غير متاحة حالياً', ['error' => 'closed'], 400);
    }

    $userStmt = $pdo->prepare(
        "SELECT balance FROM users WHERE id = ? LIMIT 1"
    );
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        quizOut(false, 'المستخدم غير موجود', ['error' => 'user'], 401);
    }

    if ((float)($user['balance'] ?? 0) <= 0) {
        quizOut(false, 'يجب أن يكون لديك رصيد في حسابك للمشاركة في المسابقة', ['error' => 'balance'], 400);
    }

    $existingStmt = $pdo->prepare(
        "SELECT id FROM quiz_entries WHERE quiz_id = ? AND user_id = ? LIMIT 1"
    );
    $existingStmt->execute([$quizId, $userId]);

    if ($existingStmt->fetchColumn()) {
        quizOut(false, 'لقد شاركت في هذه المسابقة مسبقاً', ['error' => 'already_entered'], 400);
    }

    $questionsStmt = $pdo->prepare(
        "SELECT id, correct FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order"
    );
    $questionsStmt->execute([$quizId]);
    $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $correctCount = 0;

    foreach ($questions as $question) {
        $questionId = (string)$question['id'];
        $answer = isset($answers[$questionId]) ? strtolower(trim((string)$answers[$questionId])) : '';
        $correct = strtolower(trim((string)$question['correct']));

        if ($answer !== '' && $answer === $correct) {
            $correctCount++;
        }
    }

    $totalQuestions = count($questions);

    $insertStmt = $pdo->prepare(
        "INSERT INTO quiz_entries
            (quiz_id, user_id, answers, correct_count, total_questions, time_taken, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );

    $insertStmt->execute([
        $quizId,
        $userId,
        json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $correctCount,
        $totalQuestions,
        max(0, $timeTaken),
    ]);

    quizOut(true, 'تم تسجيل مشاركتك في المسابقة بنجاح.', [
        'correct' => $correctCount,
        'total' => $totalQuestions,
    ]);
} catch (Throwable $e) {
    quizOut(false, 'تعذر تسجيل المشاركة، حاول مرة أخرى', ['error' => 'submit_failed'], 500);
}
