<?php
/**
 * Binance Pay deposit HTTP adapter.
 * The domain logic lives in includes/binance_pay_deposit_service.php and is
 * shared by the customer website and the Telegram bot.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/telegram_internal_auth.php';
require_once __DIR__ . '/../includes/binance_pay_deposit_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function binancePayJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function binancePayRequirePostCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        binancePayJson(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
    }
    $submitted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    if ($submitted === '' || $stored === '' || !hash_equals($stored, $submitted)) {
        binancePayJson(['ok' => false, 'error_code' => 'csrf_failed'], 403);
    }
}

function binancePayHttpStatus(array $result, int $fallback = 500): int
{
    $status = (int)($result['_http_status'] ?? $fallback);
    return $status >= 200 && $status <= 599 ? $status : $fallback;
}

$isInternal = function_exists('telegramAuthorizeInternalRequest') && telegramAuthorizeInternalRequest($pdo);
if (!$isInternal) {
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        binancePayJson(['ok' => false, 'error_code' => 'login_required'], 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') binancePayRequirePostCsrf();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) binancePayJson(['ok' => false, 'error_code' => 'login_required'], 401);

// Normalize stale requests before any list/create/verify action. Only pending
// requests without a submitted identifier can be expired by this helper.
binancePayExpireUnsubmittedDeposits($pdo);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT * FROM binance_pay_deposits WHERE user_id=? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$userId]);
    $rows = array_map('binancePayDepositPayload', $stmt->fetchAll(PDO::FETCH_ASSOC));
    binancePayJson(['ok' => true, 'requests' => $rows]);
}

if ($action === 'create') {
    $result = binancePayCreateDeposit($pdo, $userId, (string)($_POST['amount'] ?? ''));
    unset($result['_http_status']);
    binancePayJson($result, binancePayHttpStatus($result));
}

if ($action === 'verify') {
    $result = binancePayVerifyDeposit(
        $pdo,
        $userId,
        (int)($_POST['request_id'] ?? 0),
        (string)($_POST['transaction_id'] ?? '')
    );
    unset($result['_http_status']);
    binancePayJson($result, binancePayHttpStatus($result));
}

binancePayJson(['ok' => false, 'error_code' => 'invalid_action'], 422);
?>
