<?php
$root = dirname(__DIR__);
require_once $root . '/includes/legacy_whatsapp_bridge.php';

$pass = 0;
$fail = 0;
function checkFeature(bool $condition, string $name): void
{
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: {$name}\n"; }
    else { $fail++; echo "FAIL: {$name}\n"; }
}

$bridge = file_get_contents($root . '/includes/legacy_whatsapp_bridge.php');
$webhook = file_get_contents($root . '/legacy_hetacloud_webhook.php');
$yemenWebhook = file_get_contents($root . '/api/legacy_whatsapp_webhook.php');
$admin = file_get_contents($root . '/admin/legacy_whatsapp.php');
$worker = file_get_contents($root . '/admin/legacy_whatsapp_worker.php');

$defaults = legacyWaDefaultSettings();
checkFeature(isset($defaults['heta_webhook_enabled']), 'Webhook enabled setting exists');
checkFeature(isset($defaults['heta_webhook_secret']), 'Webhook secret setting exists');
checkFeature(isset($defaults['heta_webhook_expected_sender']), 'Expected sender setting exists');
checkFeature(($defaults['send_delay_enabled'] ?? '') === '1', 'Conservative send delay enabled by default');
checkFeature((int)($defaults['send_delay_min_seconds'] ?? 0) === 60, 'Send delay minimum defaults to 60 seconds');
checkFeature((int)($defaults['send_delay_max_seconds'] ?? 0) === 120, 'Send delay maximum defaults to 120 seconds');
checkFeature(strpos($bridge, 'max(1, min($safeIntegerMax') !== false, 'Delay bounds start at one second without fixed 120-second cap');
checkFeature(strpos($bridge, 'legacy_wa_webhook_log') !== false, 'Webhook log schema exists');
checkFeature(strpos($bridge, 'legacy_wa_incoming_messages') !== false, 'Incoming messages schema exists');
checkFeature(strpos($bridge, 'legacy_wa_group_events') !== false, 'Group events schema exists');
checkFeature(strpos($bridge, 'legacy_wa_device_status') !== false, 'Device status schema exists');
checkFeature(strpos($bridge, 'legacy_wa_receipts') !== false, 'Receipts schema exists');
checkFeature(strpos($bridge, 'legacy_wa_message_trace') !== false, 'Trace schema exists');
checkFeature(strpos($bridge, 'legacy_wa_manual_sends') !== false, 'Manual sends schema exists');
checkFeature(strpos($bridge, 'delay_seconds') !== false && strpos($bridge, 'available_at') !== false, 'Outbox stores delay and due time');
checkFeature(strpos($bridge, 'legacyWaBackfillPendingSchedules') !== false, 'Manual legacy pending backfill function exists');
checkFeature(strpos($bridge, '$backfilled = legacyWaBackfillPendingSchedules($pdo)') === false, 'Schema ensure does not auto-backfill on every request');
checkFeature(strpos($bridge, "status='pending' AND (delay_seconds IS NULL OR delay_seconds=0)") !== false, 'Backfill targets only pending zero-delay rows');
checkFeature(strpos($bridge, 'MODIFY COLUMN delay_seconds INT UNSIGNED') !== false, 'Delay column upgrades to a wide integer type');
checkFeature(strpos($bridge, 'legacyWaPickSendDelaySeconds') !== false && strpos($bridge, 'random_int') !== false, 'Delay is selected within configured bounds');
checkFeature(strpos($webhook, 'register_webhook.php') === false, 'New endpoint has no general webhook include');
checkFeature(strpos($webhook, 'whatsapp_settings') === false, 'New endpoint has no general settings table');
checkFeature(strpos($webhook, 'legacy_wa_incoming_messages') !== false, 'New endpoint writes legacy incoming table');
checkFeature(strpos($webhook, 'legacy_wa_webhook_log') !== false, 'New endpoint writes legacy log table');
checkFeature(strpos($webhook, "'status' => 'ok'") !== false, 'Health response exists');
checkFeature(strpos($webhook, 'webhook_disabled') !== false, 'Disabled webhook behavior exists');
checkFeature(strpos($admin, 'legacy_hetacloud_webhook.php') !== false, 'Admin displays independent webhook URL');
checkFeature(strpos($admin, 'send_legacy_single') !== false, 'Admin has single-message action');
checkFeature(strpos($admin, 'run_legacy_wa_worker') !== false, 'Admin keeps worker action');
checkFeature(strpos($admin, 'legacyWaProviderSend') !== false, 'Admin uses isolated provider sender');
checkFeature(strpos($yemenWebhook, 'auto_send_enabled') !== false, 'Yemen Robot webhook checks automatic send setting');
checkFeature(strpos($yemenWebhook, 'legacyWaRunWorker(') !== false, 'Yemen Robot webhook invokes isolated worker for immediate delivery');
checkFeature(strpos($yemenWebhook, 'admin/legacy_whatsapp_worker.php') !== false, 'Yemen Robot webhook reuses worker implementation');
checkFeature(strpos($yemenWebhook, 'legacyWaDelayedAt($pdo)') !== false, 'Yemen Robot webhook schedules a due time');
checkFeature(strpos($yemenWebhook, 'fastcgi_finish_request') !== false, 'Webhook can return before delayed background processing');
checkFeature(strpos($worker, 'onlyOutboxId') !== false && strpos($yemenWebhook, 'legacyWaRunWorker($pdo, 1, false, $outboxId)') !== false, 'Webhook delayed worker is restricted to the new outbox row');
checkFeature(strpos($admin, 'name="auto_send_enabled"') !== false, 'Admin exposes automatic send control');
checkFeature(strpos($admin, "legacyWaSetSetting(\$pdo, 'auto_send_enabled'") !== false, 'Admin persists automatic send control');
checkFeature(strpos($admin, 'name="send_delay_enabled"') !== false, 'Admin exposes send delay control');
checkFeature(strpos($admin, 'name="send_delay_min_seconds" min="1"') !== false && strpos($admin, 'name="send_delay_max_seconds" min="1"') !== false, 'Admin accepts delay values from one second');
checkFeature(strpos($admin, 'max="120"') === false, 'Admin has no fixed 120-second maximum attribute');
checkFeature(strpos($admin, "legacyWaSetSetting(\$pdo, 'send_delay_min_seconds'") !== false && strpos($admin, "legacyWaSetSetting(\$pdo, 'send_delay_max_seconds'") !== false, 'Admin persists delay bounds');
checkFeature(strpos($admin, 'legacyWaRunWorker($pdo, 20, false)') !== false, 'Manual worker is due-only and does not wait');
$retryPostPos = strpos($admin, "if (isset(\$_POST['reschedule_legacy_outbox']))");
$shutdownPos = strpos($admin, 'register_shutdown_function');
checkFeature($retryPostPos !== false && $shutdownPos !== false && $shutdownPos > $retryPostPos, 'Retry background worker is registered only inside explicit retry POST');
checkFeature(strpos($admin, 'register_shutdown_function') !== false && strpos($admin, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\')') < $shutdownPos, 'Admin GET never registers a worker');
checkFeature(strpos($admin, 'legacyWaRunWorker($pdo, 1, false, $outboxId)') !== false, 'Individual retry runs only its selected outbox row');
checkFeature(strpos($admin, 'name="reschedule_legacy_outbox"') !== false && strpos($admin, 'legacyWaRescheduleOutbox($pdo, $outboxId)') !== false, 'Individual retry action exists and reschedules before sending');
checkFeature(strpos($bridge, 'function legacyWaCancelOutbox') !== false && strpos($admin, 'name="cancel_legacy_outbox"') !== false, 'Individual cancel action exists');
$cancelPos = strpos($admin, "if (isset(\$_POST['cancel_legacy_outbox']))");
$cancelWorkerPos = $cancelPos === false ? false : strpos($admin, 'legacyWaRunWorker(', $cancelPos);
checkFeature($cancelPos !== false && ($cancelWorkerPos === false || $cancelWorkerPos > strpos($admin, "legacyWaFlashRedirect('outbox');", $cancelPos)), 'Cancel action does not run worker in its POST handler');
checkFeature(strpos($bridge, 'function legacyWaCleanupTerminalOutbox') !== false && strpos($bridge, "INTERVAL 24 HOUR") !== false, 'Terminal cleanup uses a 24-hour retention window');
checkFeature(strpos($worker, 'legacyWaCleanupTerminalOutbox($pdo, 100)') !== false && strpos($admin, 'legacyWaCleanupTerminalOutbox') === false, 'Cleanup runs from worker, never from admin GET');
checkFeature(strpos($bridge, "status='sent'") !== false && strpos($bridge, "status='failed'") !== false && strpos($bridge, 'DELETE FROM legacy_wa_outbox') !== false, 'Cleanup removes old sent and failed outbox rows');
checkFeature(strpos($admin, 'legacyWaBackfillPendingSchedules($pdo') === false || strpos($admin, 'if (isset($_POST[\'backfill_legacy_wa\']))') < strpos($admin, 'legacyWaBackfillPendingSchedules($pdo'), 'GET does not invoke pending backfill');
checkFeature(strpos($admin, 'legacyWaRunWorker($pdo') === false || strpos($admin, 'legacyWaRunWorker($pdo, 20, false)') !== false, 'Admin worker call is limited to explicit POST and due-only mode');
checkFeature(strpos($admin, '$schema = legacyWaSchemaStatus($pdo);') !== false, 'Admin GET uses read-only schema status');
$postGuardPos = strpos($admin, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
$ensurePos = strpos($admin, '$schema = legacyWaEnsureSchema($pdo);');
checkFeature($postGuardPos !== false && $ensurePos !== false && $ensurePos > $postGuardPos, 'Schema upgrade is available only inside POST handling');
checkFeature(strpos($admin, '$legacyAutoKickEnabled') === false, 'Removed undefined auto-kick variable reference');
checkFeature(strpos($admin, 'name="backfill_legacy_wa"') !== false && strpos($admin, 'legacyWaBackfillPendingSchedules($pdo, $batchSize)') !== false, 'Backfill is an explicit CSRF-protected POST action');
checkFeature(strpos($worker, 'min(120') === false && strpos($worker, 'sleep($waitSeconds)') !== false, 'Worker respects due times beyond 120 seconds');
checkFeature(strpos($admin, 'تمت إعادة جدولة') !== false && strpos($admin, '$legacyBackfilled') !== false, 'Admin reports manual legacy pending backfill count');
checkFeature(strpos($admin, 'التمييز الزمني لمصدر المشكلة') !== false, 'Admin shows source diagnosis summary');
checkFeature(strpos($admin, 'last_request_at') !== false && strpos($admin, 'last_attempt_at') !== false, 'Admin shows last request and last provider attempt');
checkFeature(strpos($yemenWebhook, "legacyWaFinishRequest(\$pdo, \$diagnosticId, 'failed', 'storage_error'") !== false, 'Storage failures are marked failed in request diagnostics');
checkFeature(strpos($yemenWebhook, "legacyWaFinishRequest(\$pdo, \$diagnosticId, 'failed', 'processing_error'") !== false, 'Processing failures are marked failed in request diagnostics');

$sanitized = legacyWaSanitizeValue(['api_key'=>'secret-value','token'=>'token-value','message'=>'hello']);
checkFeature(($sanitized['api_key'] ?? '') === '[redacted]', 'API key redacted');
checkFeature(($sanitized['token'] ?? '') === '[redacted]', 'Token redacted');
checkFeature(($sanitized['message'] ?? '') === 'hello', 'Non-secret message retained for diagnostic excerpt');

$raw = '{"event":"message","text":"hello"}';
$valid = hash_hmac('sha256', $raw, 'test-secret');
checkFeature(legacyWaHetaSignatureStatus($raw, 'test-secret', ['X-Hub-Signature-256'=>'sha256=' . $valid]) === 'valid', 'HMAC signature accepted');
checkFeature(legacyWaHetaSignatureStatus($raw, 'test-secret', ['X-Hub-Signature-256'=>'bad']) === 'invalid', 'Bad HMAC signature rejected');
checkFeature(legacyWaHetaSignatureStatus($raw, 'test-secret', []) === 'missing', 'Missing HMAC signature detected');
checkFeature(legacyWaHetaSignatureStatus($raw, '', []) === 'not_configured', 'Optional signature mode detected');
checkFeature(legacyWaHetaGroupId(['data'=>['chat_id'=>'123@g.us']]) === '123@g.us', 'Nested group id extracted');
checkFeature(legacyWaHetaDateTime(['timestamp'=>1700000000]) === '2023-11-14 22:13:20', 'Unix timestamp converted');

checkFeature(strpos($bridge, 'UNIQUE KEY `uq_legacy_wa_incoming_message`') !== false, 'Duplicate message constraint exists');
checkFeature(strpos($bridge, "'legacy_wa_manual_sends'") !== false && strpos($bridge, "'legacy_wa_message_trace'") !== false, 'Schema readiness list includes all legacy tables');

printf("SUMMARY: %d PASS, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
