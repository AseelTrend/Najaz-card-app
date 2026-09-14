<?php
/**
 * اختبار ثابت محلي لنظام طلبات سوا.
 * لا يتصل بقاعدة البيانات ولا بمزود واتساب.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$worker = file_get_contents($root . '/admin/wa_relay_worker.php');
$admin = file_get_contents($root . '/admin/whatsapp.php');
if ($worker === false || $admin === false) {
    fwrite(STDERR, "تعذر قراءة ملفات نظام سوا\n");
    exit(1);
}

$checks = [];
$checks['multi_length_regex'] = strpos($worker, "static fn(\$n) => '[0-9]{' . (int)\$n . '}'") !== false
    && strpos($worker, "(' . \$lengthPattern . ')") !== false;
$checks['source_message_unique_key'] = strpos($worker, 'INSERT INTO whatsapp_relay_logs') !== false
    && strpos($admin, 'UNIQUE KEY `uq_relay_rule_source`') !== false;
$checks['atomic_reservation'] = strpos($worker, "'dispatching'") !== false
    && strpos($worker, 'catch (Throwable $e)') !== false;
$checks['reply_amount_validation'] = strpos($worker, 'waParseReplyAmount') !== false
    && strpos($worker, 'reply_min_amount') !== false
    && strpos($worker, 'reply_max_amount') !== false;
$checks['quote_matching'] = strpos($worker, 'waQuotedMessageId') !== false
    && strpos($worker, 'forwarded_message_id') !== false;
$checks['persistent_reply_claim'] = strpos($worker, 'whatsapp_relay_reply_claims') !== false
    && strpos($worker, 'INSERT INTO whatsapp_relay_reply_claims') !== false
    && strpos($worker, 'reply_message_id VARCHAR(255) NOT NULL PRIMARY KEY') !== false;
$checks['claim_before_result_send'] = strpos($worker, 'replyClaimId') !== false
    && strpos($worker, 'waRelaySend($apiKey, $sender, $sourceGroup, $resultText)') !== false
    && strpos($worker, 'if ($claim->rowCount() !== 1) continue;') !== false;
$checks['expiry_handling'] = strpos($worker, "status='expired'") !== false
    && strpos($worker, 'reply_timeout_min') !== false;
$checks['admin_reply_keyword_field'] = strpos($admin, 'name="sawa_reply_keyword"') !== false
    && strpos($admin, 'sawa_reply_keyword') !== false;
$checks['worker_token_protection'] = strpos($worker, "hash_equals(\$storedToken, \$providedToken)") !== false
    && strpos($admin, 'relay_worker_token') !== false;

$webhook = file_get_contents($root . '/register_webhook.php');
$checks['nested_group_capture_for_reverse_replies'] = $webhook !== false
    && strpos($webhook, 'function waFindNestedGroupId') !== false
    && strpos($webhook, "'remoteJid'") !== false
    && strpos($webhook, "str_ends_with(\$candidate, '@g.us')") !== false;
$checks['activation_source_is_preserved'] = $webhook !== false
    && strpos($webhook, '$activationSourceChat = $fromNumber;') !== false
    && strpos($webhook, 'waMaybeSendActivation($isGroup ? $activationSourceChat : $fromNumber') !== false;

$failed = [];
foreach ($checks as $name => $ok) {
    printf("%-30s %s\n", $name, $ok ? 'OK' : 'FAIL');
    if (!$ok) $failed[] = $name;
}

if ($failed) {
    fwrite(STDERR, "فشل: " . implode(', ', $failed) . "\n");
    exit(1);
}
echo "نجح اختبار نظام سوا الثابت: " . count($checks) . "/" . count($checks) . "\n";
