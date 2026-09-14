<?php

declare(strict_types=1);

// Webhook تشخيصي مستقل عن register_webhook.php وقاعدة بيانات نجاز.
// لا تضع هذا الملف بدلاً من Webhook الإنتاج إلا أثناء اختبار مؤقت ومقصود.
$config = require __DIR__ . '/wa_probe_data/config.php';
date_default_timezone_set('Asia/Riyadh');

$providedToken = (string)($_GET['token'] ?? '');
if ($providedToken === '') {
    $providedToken = (string)($_SERVER['HTTP_X_NJAZ_PROBE_TOKEN'] ?? '');
}
if ($providedToken === '' && !empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(.+)$/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $providedToken = trim($m[1]);
}

$expectedToken = (string)($config['token'] ?? '');
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

function probeSubstr(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

$maxBodyBytes = max(1024, (int)($config['max_body_bytes'] ?? 524288));
$declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$body = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
if ($body === false) $body = '';
$bodyTruncated = strlen($body) > $maxBodyBytes;
if ($bodyTruncated) $body = substr($body, 0, $maxBodyBytes);

function probeClientIp(): string {
    $value = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return substr(trim(explode(',', (string)$value)[0]), 0, 45);
}

function probeHeaders(): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) $headers = [];
    $safe = [];
    foreach ($headers as $name => $value) {
        $lower = strtolower((string)$name);
        if (str_contains($lower, 'authorization') || str_contains($lower, 'token') || str_contains($lower, 'api-key') || str_contains($lower, 'apikey')) {
            $safe[(string)$name] = '[masked]';
        } else {
            $safe[(string)$name] = is_scalar($value) ? (string)$value : '[complex]';
        }
    }
    return $safe;
}

function probeJsonSummary(string $body): ?array {
    if ($body === '' || strlen($body) > 1048576) return null;
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) return null;
    $keys = array_keys($decoded);
    $summary = ['valid_json' => true, 'top_level' => 'object', 'keys' => array_slice(array_map('strval', $keys), 0, 80)];
    foreach (['event','type','message_id','id','device','sender','from','group_id','chat_id','status'] as $key) {
        if (array_key_exists($key, $decoded) && is_scalar($decoded[$key])) {
            $summary['fields'][$key] = probeSubstr((string)$decoded[$key], 300);
        }
    }
    return $summary;
}

$record = [
    'request_id' => 'probe_' . bin2hex(random_bytes(10)),
    'received_at' => date('Y-m-d H:i:s.uP'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'path' => parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '',
    'client_ip' => probeClientIp(),
    'user_agent' => probeSubstr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500),
    'content_type' => probeSubstr((string)($_SERVER['CONTENT_TYPE'] ?? ''), 200),
    'declared_length' => $declaredLength,
    'captured_length' => strlen($body),
    'body_truncated' => $bodyTruncated,
    'body_sha256' => hash('sha256', $body),
    'headers' => probeHeaders(),
    'json_summary' => probeJsonSummary($body),
    'body_base64' => base64_encode($body),
];

$logFile = (string)($config['log_file'] ?? (__DIR__ . '/wa_probe_data/requests.jsonl'));
$logDir = dirname($logFile);
if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'probe_log_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$handle = @fopen($logFile, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) fclose($handle);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'probe_log_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

rewind($handle);
$existing = stream_get_contents($handle);
$lines = $existing === false || $existing === '' ? [] : preg_split('/\R/', trim($existing));
$lines = array_values(array_filter($lines, static fn($line) => $line !== ''));
$lines[] = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$maxEntries = max(10, (int)($config['max_entries'] ?? 500));
if (count($lines) > $maxEntries) $lines = array_slice($lines, -$maxEntries);
rewind($handle);
ftruncate($handle, 0);
fwrite($handle, implode("\n", $lines) . "\n");
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true, 'received'=>true, 'request_id'=>$record['request_id']], JSON_UNESCAPED_UNICODE);
