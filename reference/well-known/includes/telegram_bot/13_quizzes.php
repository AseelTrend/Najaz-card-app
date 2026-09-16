<?php
/**
 * تكامل مسابقات نجاز مع Telegram.
 * مصدر الحقيقة هو api/quiz.php وجداول الموقع الحالية؛ هذه الوحدة تدير العرض والحالة فقط.
 */

function njazTgQuizApiErrorText(array $result): string {
    $messages = [
        'login'   => 'يجب تسجيل الدخول وربط حساب نجاز أولاً.',
        'kyc'     => 'يجب توثيق هويتك أولاً حتى تتمكن من المشاركة في المسابقات.',
        'balance' => 'يجب أن يكون لديك رصيد أكبر من صفر في حسابك للمشاركة.',
        'already' => 'لقد شاركت في هذه المسابقة مسبقاً.',
        'closed'  => 'المسابقة مغلقة حالياً.',
        'invalid' => 'بيانات المشاركة غير مكتملة.',
        'no_active' => 'لا توجد مسابقة نشطة حالياً. تابع الإشعارات أو عُد لاحقاً.',
    ];
    if (!empty($result['transport_error'])) return 'تعذر الاتصال بنظام نجاز حالياً. حاول مرة أخرى بعد قليل.';
    return $messages[(string)($result['error'] ?? '')] ?? (string)($result['message'] ?? 'تعذر تنفيذ العملية حالياً.');
}

function njazTgQuizKycApproved(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM kyc_requests WHERE user_id=? AND status='approved' LIMIT 1");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Telegram quiz KYC check failed: ' . $e->getMessage());
        return false;
    }
}

function njazTgQuizActive(PDO $pdo, int $userId): array {
    return njazTgInternalGet($pdo, $userId, 'api/quiz.php', ['action' => 'active']);
}

function njazTgQuizTimeLabel(int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    if ($minutes > 0) return $minutes . ' د' . ($rest > 0 ? ' و ' . $rest . ' ث' : '');
    return $rest . ' ث';
}

function njazTgQuizMainButtons(string $action = 'menu:quizzes'): array {
    return [
        [['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
        [['text' => '🔄 تحديث المسابقة', 'callback_data' => 'menu:quizzes']],
    ];
}

function njazTgQuizShow(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $uid = (int)$tgUser['user_id'];
    $result = njazTgQuizActive($pdo, $uid);

    if (empty($result['ok'])) {
        $body = njazTgQuizApiErrorText($result);
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        njazTgRender($pdo, $tgUser, njazTgScreen('🏆', 'المسابقات', $body), njazTgQuizMainButtons(), $messageId);
        return;
    }

    $quiz = is_array($result['quiz'] ?? null) ? $result['quiz'] : [];
    $questions = is_array($result['questions'] ?? null) ? $result['questions'] : [];
    $entry = is_array($result['my_entry'] ?? null) ? $result['my_entry'] : null;

    if ($entry) {
        njazTgQuizShowEntry($pdo, $tgUser, $quiz, $entry, $messageId);
        return;
    }

    if (!njazTgQuizKycApproved($pdo, $uid)) {
        $body = 'لا يمكن المشاركة قبل اعتماد تحقق الهوية في الموقع.\n\nأكمل التحقق من حسابك ثم عُد إلى المسابقات للمشاركة.';
        $kb = [
            [['text' => '🪪 بدء تحقق الهوية', 'callback_data' => 'profile:kyc:start']],
            [['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
        ];
        njazTgRender($pdo, $tgUser, njazTgScreen('🔒', 'المشاركة غير متاحة', $body), $kb, $messageId);
        return;
    }

    $account = getUser($uid);
    $balance = (float)($account['balance'] ?? 0);
    if ($balance <= 0) {
        $body = 'تتطلب المشاركة رصيداً أكبر من صفر في حسابك، كما هو مطبق في موقع نجاز.';
        $kb = [
            [['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup']],
            [['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
        ];
        njazTgRender($pdo, $tgUser, njazTgScreen('💰', 'الرصيد غير كافٍ للمشاركة', $body), $kb, $messageId);
        return;
    }

    if (!$questions) {
        njazTgRender($pdo, $tgUser, njazTgScreen('🏆', 'المسابقة', 'لا توجد أسئلة متاحة حالياً.'), njazTgQuizMainButtons(), $messageId);
        return;
    }

    $name = njazTgHtml($quiz['name'] ?? 'مسابقة نجاز');
    $title = njazTgHtml($quiz['title'] ?? '');
    $prize = njazTgMoney((float)($quiz['prize_amount'] ?? 0));
    $time = njazTgQuizTimeLabel((int)($quiz['time_seconds'] ?? 0));
    $winners = (int)($quiz['winners_count'] ?? 1);
    $body = '<b>' . $name . '</b>';
    if ($title !== '') $body .= '\n' . $title;
    $body .= '\n\n🏆 الجائزة الكبرى: <b>' . $prize . '</b>';
    $body .= '\n⏱ وقت المسابقة: <b>' . $time . '</b>';
    $body .= '\n❓ عدد الأسئلة: <b>' . count($questions) . '</b>';
    $body .= '\n🥇 عدد الفائزين: <b>' . $winners . '</b>';
    $body .= '\n✅ المشاركة متاحة للحسابات الموثقة فقط.';
    $body .= '\n\nيُختار الفائزون وفق أعلى عدد من الإجابات الصحيحة ثم أقل وقت، كما في الموقع.';
    $kb = [
        [['text' => '🚀 ابدأ المسابقة', 'callback_data' => 'quiz:start:' . (int)($quiz['id'] ?? 0)]],
        [['text' => '🏠 الرئيسية', 'callback_data' => 'home']],
    ];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgRender($pdo, $tgUser, njazTgScreen('🏆', 'المسابقات', $body), $kb, $messageId);
}

function njazTgQuizShowEntry(PDO $pdo, array $tgUser, array $quiz, array $entry, ?int $messageId = null): void {
    $correct = (int)($entry['correct_count'] ?? 0);
    $total = max(1, (int)($entry['total_questions'] ?? 0));
    $percent = (int)round(($correct / $total) * 100);
    $icon = $percent >= 80 ? '🏆' : ($percent >= 50 ? '👍' : '😔');
    $body = 'شاركت في هذه المسابقة مسبقاً.\n\n';
    $body .= '<b>نتيجتك: ' . $correct . '/' . $total . '</b> (' . $percent . '%)';
    if (!empty($entry['is_winner'])) $body .= '\n\n🏆 <b>مبروك! أنت من الفائزين.</b>';
    $body .= '\n\nسيتم إعلان النتائج وإضافة الجائزة من خلال نظام نجاز المركزي.';
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgRender($pdo, $tgUser, njazTgScreen($icon, 'نتيجة المسابقة', $body), njazTgQuizMainButtons(), $messageId);
}

function njazTgQuizStart(PDO $pdo, array $tgUser, int $quizId, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    if ($quizId <= 0) { njazTgQuizShow($pdo, $tgUser, $messageId); return; }
    $uid = (int)$tgUser['user_id'];
    if (!njazTgQuizKycApproved($pdo, $uid)) {
        njazTgRender($pdo, $tgUser, njazTgScreen('🔒', 'تحقق الهوية مطلوب', 'اعتمد تحقق هويتك أولاً من الموقع أو من قسم حسابي، ثم ابدأ المسابقة.'), [[['text' => '🪪 تحقق الهوية', 'callback_data' => 'profile:kyc:start']], [['text' => '🏠 الرئيسية', 'callback_data' => 'home']]], $messageId);
        return;
    }
    $account = getUser($uid);
    if ((float)($account['balance'] ?? 0) <= 0) {
        njazTgRender($pdo, $tgUser, njazTgScreen('💰', 'الرصيد مطلوب', 'يجب أن يكون لديك رصيد أكبر من صفر للمشاركة.'), [[['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup']], [['text' => '🏠 الرئيسية', 'callback_data' => 'home']]], $messageId);
        return;
    }
    $result = njazTgQuizActive($pdo, $uid);
    if (empty($result['ok']) || (int)($result['quiz']['id'] ?? 0) !== $quizId) {
        njazTgQuizShow($pdo, $tgUser, $messageId);
        return;
    }
    if (!empty($result['my_entry'])) {
        njazTgQuizShowEntry($pdo, $tgUser, (array)$result['quiz'], (array)$result['my_entry'], $messageId);
        return;
    }
    $questions = is_array($result['questions'] ?? null) ? array_values($result['questions']) : [];
    if (!$questions) { njazTgQuizShow($pdo, $tgUser, $messageId); return; }
    $stateData = [
        'quiz_id' => $quizId,
        'quiz_questions' => $questions,
        'quiz_current' => 0,
        'quiz_answers' => [],
        'quiz_started_at' => time(),
        'quiz_time_seconds' => max(1, (int)($result['quiz']['time_seconds'] ?? 60)),
    ];
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'quiz_playing', $stateData);
    njazTgQuizRenderQuestion($pdo, $tgUser, $stateData, $messageId);
}

function njazTgQuizRenderQuestion(PDO $pdo, array $tgUser, array $data, ?int $messageId = null, string $notice = ''): void {
    $questions = is_array($data['quiz_questions'] ?? null) ? $data['quiz_questions'] : [];
    $index = (int)($data['quiz_current'] ?? 0);
    if (!isset($questions[$index])) { njazTgQuizSubmit($pdo, $tgUser, $data, $messageId); return; }
    $q = $questions[$index];
    $answers = is_array($data['quiz_answers'] ?? null) ? $data['quiz_answers'] : [];
    $selected = (string)($answers[(string)($q['id'] ?? '')] ?? '');
    $started = (int)($data['quiz_started_at'] ?? time());
    $limit = max(1, (int)($data['quiz_time_seconds'] ?? 60));
    $left = max(0, $limit - (time() - $started));
    $body = ($notice !== '' ? '<b>⚠️ ' . njazTgHtml($notice) . '</b>\n\n' : '');
    $body .= '<b>السؤال ' . ($index + 1) . ' من ' . count($questions) . '</b>';
    $body .= '\n⏱ الوقت المتبقي تقريباً: <b>' . njazTgQuizTimeLabel($left) . '</b>';
    $body .= '\n\n' . njazTgHtml($q['question'] ?? '');
    $kb = [];
    foreach (['a' => 'أ', 'b' => 'ب', 'c' => 'ج', 'd' => 'د'] as $option => $label) {
        $value = trim((string)($q['option_' . $option] ?? ''));
        if ($value === '') continue;
        $prefix = $selected === $option ? '✅ ' : $label . ' — ';
        $kb[] = [['text' => $prefix . njazTgClipLabel($value, 48), 'callback_data' => 'quiz:pick:' . $index . ':' . $option]];
    }
    $nav = [];
    if ($index > 0) $nav[] = ['text' => 'السابق', 'callback_data' => 'quiz:prev'];
    $nav[] = ['text' => $index < count($questions) - 1 ? 'التالي ←' : 'إنهاء وإرسال 🚀', 'callback_data' => 'quiz:next'];
    $kb[] = $nav;
    $kb[] = [['text' => '✖️ إلغاء المسابقة', 'callback_data' => 'quiz:cancel']];
    njazTgRender($pdo, $tgUser, njazTgScreen('🏆', 'المسابقة', $body), $kb, $messageId);
}

function njazTgQuizPick(PDO $pdo, array $tgUser, int $index, string $option, ?int $messageId = null): void {
    if ((string)($tgUser['state'] ?? '') !== 'quiz_playing') { njazTgQuizShow($pdo, $tgUser, $messageId); return; }
    $data = njazTgStateData($tgUser);
    $questions = is_array($data['quiz_questions'] ?? null) ? $data['quiz_questions'] : [];
    if (!isset($questions[$index]) || !in_array($option, ['a', 'b', 'c', 'd'], true)) return;
    $question = $questions[$index];
    if (trim((string)($question['option_' . $option] ?? '')) === '') return;
    $answers = is_array($data['quiz_answers'] ?? null) ? $data['quiz_answers'] : [];
    $answers[(string)($question['id'] ?? '')] = $option;
    $data['quiz_answers'] = $answers;
    $data['quiz_current'] = $index;
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'quiz_playing', $data);
    njazTgQuizRenderQuestion($pdo, $tgUser, $data, $messageId);
}

function njazTgQuizNext(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if ((string)($tgUser['state'] ?? '') !== 'quiz_playing') { njazTgQuizShow($pdo, $tgUser, $messageId); return; }
    $data = njazTgStateData($tgUser);
    $questions = is_array($data['quiz_questions'] ?? null) ? $data['quiz_questions'] : [];
    $index = (int)($data['quiz_current'] ?? 0);
    $question = $questions[$index] ?? null;
    $answers = is_array($data['quiz_answers'] ?? null) ? $data['quiz_answers'] : [];
    if (!$question || !array_key_exists((string)($question['id'] ?? ''), $answers)) {
        njazTgQuizRenderQuestion($pdo, $tgUser, $data, $messageId, 'اختر إجابة قبل الانتقال.');
        return;
    }
    if ($index < count($questions) - 1) {
        $data['quiz_current'] = $index + 1;
        njazTgState($pdo, (int)$tgUser['telegram_id'], 'quiz_playing', $data);
        njazTgQuizRenderQuestion($pdo, $tgUser, $data, $messageId);
        return;
    }
    njazTgQuizSubmit($pdo, $tgUser, $data, $messageId);
}

function njazTgQuizPrev(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if ((string)($tgUser['state'] ?? '') !== 'quiz_playing') { njazTgQuizShow($pdo, $tgUser, $messageId); return; }
    $data = njazTgStateData($tgUser);
    $data['quiz_current'] = max(0, (int)($data['quiz_current'] ?? 0) - 1);
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'quiz_playing', $data);
    njazTgQuizRenderQuestion($pdo, $tgUser, $data, $messageId);
}

function njazTgQuizSubmit(PDO $pdo, array $tgUser, array $data, ?int $messageId = null): void {
    if (!njazTgRequireLinked($pdo, $tgUser)) return;
    $answers = is_array($data['quiz_answers'] ?? null) ? $data['quiz_answers'] : [];
    if (!$answers) {
        njazTgQuizRenderQuestion($pdo, $tgUser, $data, $messageId, 'اختر إجابة واحدة على الأقل.');
        return;
    }
    $elapsed = max(0, time() - (int)($data['quiz_started_at'] ?? time()));
    $result = njazTgInternalPost($pdo, (int)$tgUser['user_id'], 'api/quiz.php', [
        'action' => 'submit',
        'quiz_id' => (int)($data['quiz_id'] ?? 0),
        'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        'time_taken' => $elapsed,
    ]);
    if (empty($result['ok'])) {
        $error = (string)($result['error'] ?? '');
        if (in_array($error, ['kyc', 'balance', 'already', 'closed', 'login'], true)) {
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
        } else {
            njazTgState($pdo, (int)$tgUser['telegram_id'], 'quiz_playing', $data);
        }
        $buttons = [[['text' => '🔄 المحاولة مرة أخرى', 'callback_data' => 'quiz:resume'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']]];
        if ($error === 'kyc') array_unshift($buttons, [['text' => '🪪 تحقق الهوية', 'callback_data' => 'profile:kyc:start']]);
        if ($error === 'balance') array_unshift($buttons, [['text' => '💳 شحن الرصيد', 'callback_data' => 'menu:topup']]);
        njazTgRender($pdo, $tgUser, njazTgScreen('⚠️', 'تعذر إرسال المشاركة', njazTgQuizApiErrorText($result)), $buttons, $messageId);
        return;
    }
    $correct = (int)($result['correct'] ?? 0);
    $total = max(1, (int)($result['total'] ?? count($data['quiz_questions'] ?? [])));
    $percent = (int)round(($correct / $total) * 100);
    $icon = $percent >= 80 ? '🏆' : ($percent >= 50 ? '👍' : '😊');
    $body = '<b>نتيجتك: ' . $correct . '/' . $total . '</b> (' . $percent . '%)';
    $body .= '\n' . njazTgHtml($result['message'] ?? 'تم حفظ مشاركتك بنجاح.');
    $body .= '\n\n⏱ الوقت المستغرق: ' . $elapsed . ' ث';
    $body .= '\nسيتم إعلان الفائزين من خلال نظام نجاز المركزي.';
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgRender($pdo, $tgUser, njazTgScreen($icon, 'تم تسجيل مشاركتك', $body), [[['text' => '🏆 المسابقات', 'callback_data' => 'menu:quizzes'], ['text' => '🏠 الرئيسية', 'callback_data' => 'home']]], $messageId);
}

function njazTgQuizCancel(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    njazTgState($pdo, (int)$tgUser['telegram_id'], 'menu', []);
    njazTgRender($pdo, $tgUser, njazTgScreen('✖️', 'تم إلغاء المسابقة', 'لم يتم إرسال أي مشاركة.'), [[['text' => '🏠 الرئيسية', 'callback_data' => 'home'], ['text' => '🏆 المسابقات', 'callback_data' => 'menu:quizzes']]], $messageId);
}

function njazTgQuizResume(PDO $pdo, array $tgUser, ?int $messageId = null): void {
    if ((string)($tgUser['state'] ?? '') === 'quiz_playing') {
        njazTgQuizRenderQuestion($pdo, $tgUser, njazTgStateData($tgUser), $messageId);
        return;
    }
    njazTgQuizShow($pdo, $tgUser, $messageId);
}
