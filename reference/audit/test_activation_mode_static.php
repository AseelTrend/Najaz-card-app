<?php
/**
 * فحص ثابت لوضع التنشيط الاحتياطي المؤجل — لا يتصل بالإنتاج ولا يرسل رسائل.
 */
$root = dirname(__DIR__);
$webhook = file_get_contents($root . '/register_webhook.php');
$admin = file_get_contents($root . '/admin/whatsapp.php');
$worker = file_get_contents($root . '/admin/wa_relay_worker.php');

$helperStart = strpos($webhook, 'function waMaybeSendActivation');
$helperEnd = strpos($webhook, 'function waSetActivationSetting', $helperStart === false ? 0 : $helperStart);
$helper = ($helperStart !== false && $helperEnd !== false) ? substr($webhook, $helperStart, $helperEnd - $helperStart) : '';
$workerRun = strpos($worker, 'waProcessActivationQueue($pdo, $apiKey, $sender, $log)');
$rulesRead = strpos($worker, '$rules = $pdo->query');
$earlyRulesExit = strpos($worker, 'if (!$rules)');
$persisted = strpos($webhook, "'stage' => 'persisted'");
$activationCall = strrpos($webhook, 'waMaybeSendActivation(');

$checks = [
    'activation_helper'          => $helperStart !== false,
    'queue_table_schema'         => substr_count($webhook, 'whatsapp_activation_queue') >= 2
                                      && str_contains($admin, 'whatsapp_activation_queue')
                                      && str_contains($worker, 'whatsapp_activation_queue'),
    'queue_columns_indexes'      => str_contains($webhook, 'scheduled_at')
                                      && str_contains($webhook, 'uq_activation_source_message')
                                      && str_contains($webhook, 'idx_activation_due'),
    'enabled_and_group_settings' => str_contains($webhook, 'activation_enabled')
                                      && str_contains($admin, 'name="activation_enabled"')
                                      && str_contains($webhook, 'activation_group_id')
                                      && str_contains($admin, 'name="activation_group_id"'),
    'group_validation'           => str_contains($helper, "str_ends_with(\$targetGroup, '@g.us')"),
    'delay_setting'              => str_contains($webhook, 'activation_delay_seconds')
                                      && str_contains($admin, 'name="activation_delay_seconds"')
                                      && str_contains($helper, 'time() + $delay'),
    'debounce_pending'            => str_contains($helper, "status='pending'")
                                      && str_contains($helper, 'scheduled_at=?')
                                      && str_contains($helper, 'GET_LOCK'),
    'loop_and_own_guards'        => str_contains($helper, 'activation_ignored')
                                      && str_contains($helper, 'own_message_ignored')
                                      && str_contains($helper, 'outgoing_ignored'),
    'enqueue_only'               => str_contains($helper, 'INSERT INTO whatsapp_activation_queue')
                                      && !str_contains($helper, 'sendAutoReply')
                                      && !str_contains($helper, 'curl_init'),
    'after_persist_with_event'   => $persisted !== false && $activationCall > $persisted
                                      && str_contains($webhook, '$activationEventId'),
    'worker_before_sawa_exit'    => $workerRun !== false && $rulesRead !== false
                                      && $workerRun < $rulesRead
                                      && $earlyRulesExit !== false
                                      && $workerRun < $earlyRulesExit,
    'heartbeat_precision'        => str_contains($worker, 'function waRunActivationHeartbeat')
                                      && str_contains($worker, 'usleep(700000)')
                                      && str_contains($worker, 'microtime(true) + max(5, min(55, $seconds))'),
    'heartbeat_lease'             => str_contains($worker, 'njaz_wa_activation_worker_lease')
                                      && str_contains($worker, 'SELECT GET_LOCK(?, 0)'),
    'atomic_worker_claim'        => str_contains($worker, "SET status='sending'")
                                      && str_contains($worker, "WHERE id=? AND status='pending' AND scheduled_at <= NOW()"),
    'worker_manual_style_send'   => str_contains($worker, "curl_init('https://sender.hetacloud.top/send-message')")
                                      && str_contains($worker, 'CURLOPT_TIMEOUT => 20')
                                      && str_contains($worker, 'CURLOPT_SSL_VERIFYPEER => false')
                                      && str_contains($worker, "isset(\$result['status'])"),
    'safe_status_transitions'    => str_contains($worker, "status='sent'")
                                      && str_contains($worker, "status='failed'")
                                      && str_contains($worker, "status='cancelled'"),
    'unique_nonce_at_send'       => str_contains($worker, 'random_bytes(4)')
                                      && str_contains($worker, '$activationText = $messageBase .'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    echo str_pad($name, 30) . ($ok ? "OK\n" : "FAIL\n");
    if (!$ok) $failed[] = $name;
}
if ($failed) {
    fwrite(STDERR, 'فشل: ' . implode(', ', $failed) . "\n");
    exit(1);
}
echo 'نجح فحص وضع التنشيط المؤجل: ' . count($checks) . '/' . count($checks) . "\n";

// لا يحتوي هذا الاختبار على بيانات اعتماد أو اتصال خارجي؛ هو فحص نصي للمسارات الحرجة فقط.
exit(0);
?>
