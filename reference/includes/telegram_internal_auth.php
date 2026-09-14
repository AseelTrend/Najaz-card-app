<?php
/**
 * مصادقة الاستدعاءات الداخلية من Telegram Bot إلى نقاط النظام الحالية.
 * لا تُستخدم من المستخدمين؛ تعتمد على HMAC سري محفوظ في settings.
 */

function telegramInternalSecret(PDO $pdo): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $stmt->execute(['telegram_internal_secret']);
        $value = (string)($stmt->fetchColumn() ?: '');
        if ($value !== '') return $value;
    } catch (Throwable $e) {
        error_log('Telegram internal secret lookup failed: ' . $e->getMessage());
    }
    return hash('sha256', DB_PASS . '|' . DB_NAME . '|njaz-telegram-internal');
}

function telegramAuthorizeInternalRequest(PDO $pdo): bool {
    $userId = (int)($_POST['telegram_user_id'] ?? $_GET['telegram_user_id'] ?? 0);
    $nonce  = (string)($_POST['telegram_nonce'] ?? $_GET['telegram_nonce'] ?? '');
    $given  = (string)($_SERVER['HTTP_X_NJAZ_INTERNAL'] ?? '');
    if ($userId <= 0 || $nonce === '' || !preg_match('/^[a-f0-9]{32}$/', $nonce) || $given === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $userId . '|' . $nonce, telegramInternalSecret($pdo));
    if (!hash_equals($expected, $given)) return false;

    $userStmt = $pdo->prepare("SELECT id, role, status, is_deleted FROM users WHERE id=? AND status=1 LIMIT 1");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user || !empty($user['is_deleted'])) return false;

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'] ?: 'user';
    $_SESSION['telegram_internal'] = true;
    return true;
}

function telegramInternalAuthSignature(PDO $pdo, int $userId, string $nonce): string {
    return hash_hmac('sha256', $userId . '|' . $nonce, telegramInternalSecret($pdo));
}

function telegramBuildInternalRequest(PDO $pdo, int $userId, array $fields = []): array {
    $nonce = bin2hex(random_bytes(16));
    $fields['telegram_user_id'] = $userId;
    $fields['telegram_nonce'] = $nonce;
    return [
        'fields' => $fields,
        'headers' => [
            'X-Njaz-Internal: ' . telegramInternalAuthSignature($pdo, $userId, $nonce),
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ];
}
?>
