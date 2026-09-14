<?php
// api/mobile/chat.php
// جسر آمن لتشغيل نظام الدعم الحالي مع JWT الخاص بتطبيق الموبايل.

header('Content-Type: application/json; charset=utf-8');

// سجل تشخيصي مؤقت: لا يسجل التوكن أو أي بيانات حساسة.
function mobileChatDebug(string $message): void
{
    error_log('[NAJAZ_CHAT_DEBUG] ' . date('Y-m-d H:i:s') . ' | ' . $message);
}

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        mobileChatDebug('FATAL | type=' . $error['type'] . ' | message=' . $error['message'] . ' | file=' . $error['file'] . ' | line=' . $error['line']);
    }
});

mobileChatDebug('START | method=' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' | uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' | file=' . __FILE__);

try {
    $commonFile = __DIR__ . '/_common.php';
    mobileChatDebug('COMMON | path=' . $commonFile . ' | exists=' . (is_file($commonFile) ? 'YES' : 'NO'));

    require_once $commonFile;
    mobileChatDebug('COMMON_LOADED | pdo=' . (isset($pdo) ? 'YES' : 'NO'));

    mobileChatDebug('AUTH | starting mobileAuthorizeRequest');
    mobileAuthorizeRequest($pdo, true);
    mobileChatDebug('AUTH | completed | session_user_id=' . (isset($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : 'NONE') . ' | session_role=' . (isset($_SESSION['role']) ? (string)$_SESSION['role'] : 'NONE'));

    $chatFile = dirname(__DIR__) . '/chat.php';
    mobileChatDebug('CHAT | path=' . $chatFile . ' | exists=' . (is_file($chatFile) ? 'YES' : 'NO') . ' | readable=' . (is_readable($chatFile) ? 'YES' : 'NO'));

    if (!is_file($chatFile) || !is_readable($chatFile)) {
        mobileChatDebug('CHAT | target file missing or unreadable');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Support service file is unavailable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    mobileChatDebug('CHAT | loading api/chat.php');
    require $chatFile;
    mobileChatDebug('END | api/chat.php returned normally');
} catch (Throwable $e) {
    mobileChatDebug('EXCEPTION | class=' . get_class($e) . ' | message=' . $e->getMessage() . ' | file=' . $e->getFile() . ' | line=' . $e->getLine());
    mobileChatDebug('TRACE | ' . str_replace(["\r", "\n"], ' ', $e->getTraceAsString()));

    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Support service error.',
            'debug_id' => 'NAJAZ_CHAT_DEBUG'
        ], JSON_UNESCAPED_UNICODE);
    }
}
