<?php
require_once '../includes/config.php';
requireAdmin($pdo);
require_once '../includes/legacy_whatsapp_bridge.php';
require_once __DIR__ . '/legacy_whatsapp_worker.php';

$pageTitle = 'واتساب نجاز كارد القديم';
// GET للوحة قراءة فقط: لا CREATE/ALTER ولا ترحيل ولا تشغيل عامل عند فتح الصفحة.
$schema = legacyWaSchemaStatus($pdo);
$legacyBackfilled = 0;


if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['legacy_wa_csrf'])) $_SESSION['legacy_wa_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['legacy_wa_csrf'];
$activeTab = preg_replace('/[^a-z_]/', '', (string)($_GET['tab'] ?? 'dashboard')) ?: 'dashboard';
$allowedTabs = ['dashboard','single','inbox','outbox','logs','diagnostics','settings'];
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'dashboard';

function legacyWaH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function legacyWaFlashRedirect(string $tab = 'dashboard'): void
{
    redirect(SITE_URL . '/admin/legacy_whatsapp.php?tab=' . rawurlencode($tab));
    exit;
}
function legacyWaPublicUrl(string $path): string
{
    return rtrim((string)SITE_URL, '/') . '/' . ltrim($path, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    $returnTab = (string)($_POST['return_tab'] ?? 'dashboard');
    if (!in_array($returnTab, $allowedTabs, true)) $returnTab = 'dashboard';

    if (!hash_equals($csrf, $postedCsrf)) {
        flashMessage('danger', 'انتهت صلاحية النموذج. أعد تحميل الصفحة وحاول مرة أخرى.');
        legacyWaFlashRedirect($returnTab);
    }
    // تجهيز/ترقية الجداول مسموح فقط ضمن طلب POST صريح، وليس أثناء GET.
    if (!$schema['ok'] || !empty($schema['needs_upgrade'])) {
        $schema = legacyWaEnsureSchema($pdo);
    }
    if (!$schema['ok']) {
        flashMessage('danger', 'تعذر تجهيز جداول النظام المستقل: ' . legacyWaH($schema['error'] ?? 'schema_error'));
        legacyWaFlashRedirect($returnTab);
    }

    if (isset($_POST['check_legacy_wa'])) {
        $healthUrl = legacyWaPublicUrl('/api/send');
        $healthOk = false; $healthHttp = 0; $healthReason = 'unknown';
        if (function_exists('curl_init')) {
            $ch = curl_init($healthUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'Njaz-Legacy-WA-Health-Check/2.0',
            ]);
            $body = (string)curl_exec($ch);
            $curlError = curl_error($ch);
            $healthHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = json_decode($body, true);
            $healthOk = $curlError === '' && $healthHttp === 200 && is_array($decoded)
                && !empty($decoded['success']) && ($decoded['service'] ?? '') === 'njaz-legacy-whatsapp-bridge';
            $healthReason = $curlError !== '' ? 'network_error' : ($healthOk ? 'health_ok' : 'invalid_health_response');
            if (!$healthOk && $healthHttp !== 200) $healthReason = 'http_' . $healthHttp;
        } else $healthReason = 'curl_unavailable';
        $lastPost = null;
        try {
            $lastPost = $pdo->query("SELECT auth_status,auth_reason,response_status,created_at FROM legacy_wa_requests WHERE method='POST' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
        if ($healthOk) {
            $suffix = $lastPost ? ' آخر POST: ' . legacyWaH($lastPost['auth_status']) . ' في ' . legacyWaH($lastPost['created_at']) : ' لا يوجد POST مسجل حتى الآن.';
            flashMessage('success', 'فحص جسر يمن روبوت المحلي ناجح. هذا الفحص لم يرسل رسالة خارجية.' . $suffix);
        } else {
            flashMessage('danger', 'فشل فحص جسر يمن روبوت: ' . $healthReason . ' (HTTP ' . $healthHttp . ').');
        }
        legacyWaFlashRedirect('diagnostics');
    }

    if (isset($_POST['check_legacy_hetacloud_webhook'])) {
        $url = legacyWaPublicUrl('/legacy_hetacloud_webhook.php?monitor_probe=1');
        $ok = false; $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
            $body = (string)curl_exec($ch); $error = curl_error($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $decoded = json_decode($body, true); $ok = $error === '' && $status === 200 && is_array($decoded) && ($decoded['service'] ?? '') === 'njaz-legacy-hetacloud-webhook';
        }
        flashMessage($ok ? 'success' : 'danger', $ok ? 'فحص Webhook المستقل ناجح. لم تتم معالجة أي رسالة.' : 'تعذر فحص Webhook المستقل (HTTP ' . $status . ').');
        legacyWaFlashRedirect('diagnostics');
    }

    if (isset($_POST['cancel_legacy_outbox'])) {
        $outboxId = (int)($_POST['outbox_id'] ?? 0);
        $cancel = legacyWaCancelOutbox($pdo, $outboxId);
        if (!empty($cancel['ok'])) {
            flashMessage('success', 'تم إلغاء الصف #' . $outboxId . ' وتحويله إلى failed. لم يتم إرسال أي رسالة.');
        } else {
            $reasonLabels = [
                'not_found' => 'الصف غير موجود.',
                'not_cancellable' => 'لا يمكن إلغاء الصف لأن حالته تغيّرت أو بدأت معالجته.',
                'invalid_id' => 'معرّف الصف غير صالح.',
            ];
            flashMessage('danger', $reasonLabels[$cancel['reason'] ?? ''] ?? 'تعذر إلغاء الصف.');
        }
        legacyWaFlashRedirect('outbox');
    }

    if (isset($_POST['reschedule_legacy_outbox'])) {
        $outboxId = (int)($_POST['outbox_id'] ?? 0);
        if (legacyWaGetSetting($pdo, 'enabled') !== '1' || legacyWaGetSetting($pdo, 'mode') !== 'send') {
            $retry = ['ok' => false, 'reason' => 'bridge_not_in_send_mode'];
        } else {
            $retry = legacyWaRescheduleOutbox($pdo, $outboxId);
        }
        if (!empty($retry['ok'])) {
            $retryDelay = (int)($retry['delay_seconds'] ?? 0);
            register_shutdown_function(function () use ($pdo, $outboxId, $retryDelay): void {
                ignore_user_abort(true);
                @set_time_limit(max(180, $retryDelay + 90));
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                if ($retryDelay > 0) sleep($retryDelay);
                legacyWaRunWorker($pdo, 1, false, $outboxId);
            });
            flashMessage('success', 'تمت إعادة جدولة الصف #' . $outboxId . ' بموعد جديد: ' . legacyWaH($retry['available_at'] ?? '') . '. سيبدأ إرسال هذا الصف فقط عند حلول الموعد.');
        } else {
            $reasonLabels = [
                'not_found' => 'الصف غير موجود.',
                'not_retryable' => 'لا يمكن إعادة محاولة هذا الصف لأن حالته تغيّرت أو أُرسل بالفعل.',
                'not_due' => 'الصف pending لم يحِن موعده الحالي بعد؛ لا توجد حاجة لإعادة جدولتِه الآن.',
                'invalid_id' => 'معرّف الصف غير صالح.',
                'bridge_not_in_send_mode' => 'لم تتم إعادة الجدولة: يجب تفعيل الربط واختيار وضع الإرسال أولاً.',
            ];
            flashMessage('danger', $reasonLabels[$retry['reason'] ?? ''] ?? 'تعذرت إعادة جدولة الصف.');
        }
        legacyWaFlashRedirect('outbox');
    }

    if (isset($_POST['backfill_legacy_wa'])) {
        // الترحيل اليدوي يحدّث موعد الصفوف القديمة فقط؛ لا يرسل ولا يشغّل العامل.
        $batchSize = max(1, min(100, (int)($_POST['backfill_batch_size'] ?? 100)));
        $legacyBackfilled = legacyWaBackfillPendingSchedules($pdo, $batchSize);
        flashMessage('success', 'تمت إعادة جدولة ' . (int)$legacyBackfilled . ' صفوف pending ذات التأخير الصفري فقط. لم يتم إرسال أي رسالة.');
        legacyWaFlashRedirect('outbox');
    }

    if (isset($_POST['run_legacy_wa_worker'])) {
        if (legacyWaGetSetting($pdo, 'enabled') !== '1' || legacyWaGetSetting($pdo, 'mode') !== 'send') {
            flashMessage('danger', 'لم يُشغّل العامل: يجب تفعيل الربط واختيار وضع الإرسال.');
        } else {
            // تشغيل يدوي سريع: يعالج الصفوف المستحقة فقط ولا ينتظر موعداً مستقبلياً.
            $workerResult = legacyWaRunWorker($pdo, 20, false);
            $summary = 'تمت معالجة ' . (int)$workerResult['processed'] . '، نجح ' . (int)$workerResult['sent'] . '، فشل ' . (int)$workerResult['failed'] . '.';
            flashMessage(($workerResult['failed'] ?? 0) > 0 ? 'danger' : 'success', 'نتيجة تشغيل العامل: ' . $summary);
        }
        legacyWaFlashRedirect('outbox');
    }

    if (isset($_POST['save_legacy_wa'])) {
        $enabled = isset($_POST['enabled']) ? '1' : '0';
        $mode = (($_POST['mode'] ?? 'capture') === 'send') ? 'send' : 'capture';
        $autoSendEnabled = isset($_POST['auto_send_enabled']) ? '1' : '0';
        $delayEnabled = isset($_POST['send_delay_enabled']) ? '1' : '0';
        $delayMin = max(1, min(PHP_INT_MAX, (int)($_POST['send_delay_min_seconds'] ?? 60)));
        $delayMax = max(1, min(PHP_INT_MAX, (int)($_POST['send_delay_max_seconds'] ?? 120)));
        if ($delayMax < $delayMin) [$delayMin, $delayMax] = [$delayMax, $delayMin];
        legacyWaSetSetting($pdo, 'enabled', $enabled);
        legacyWaSetSetting($pdo, 'mode', $mode);
        legacyWaSetSetting($pdo, 'auto_send_enabled', $autoSendEnabled);
        legacyWaSetSetting($pdo, 'send_delay_enabled', $delayEnabled);
        legacyWaSetSetting($pdo, 'send_delay_min_seconds', (string)$delayMin);
        legacyWaSetSetting($pdo, 'send_delay_max_seconds', (string)$delayMax);
        legacyWaSetSetting($pdo, 'token', 'replo');
        legacyWaSetSetting($pdo, 'type', 'tws_ex');
        legacyWaSetSetting($pdo, 'ip_allowlist', trim((string)($_POST['ip_allowlist'] ?? '')));
        $optionalSettings = [
            'replo_api_key' => 'replo_api_key',
            'legacy_heta_api_key' => 'heta_api_key',
            'legacy_heta_sender' => 'heta_sender',
            'heta_webhook_secret' => 'heta_webhook_secret',
            'heta_webhook_expected_sender' => 'heta_webhook_expected_sender',
        ];
        foreach ($optionalSettings as $postKey => $settingKey) {
            $value = trim((string)($_POST[$postKey] ?? ''));
            if ($value !== '') legacyWaSetSetting($pdo, $settingKey, $value);
        }
        legacyWaSetSetting($pdo, 'heta_webhook_enabled', isset($_POST['heta_webhook_enabled']) ? '1' : '0');
        if (legacyWaGetSetting($pdo, 'api_key') === '') legacyWaSetSetting($pdo, 'api_key', legacyWaGenerateSecretToken());
        flashMessage('success', 'تم حفظ إعدادات نظام واتساب نجاز القديم فقط. القيم السرية الفارغة بقيت كما هي.');
        legacyWaFlashRedirect('settings');
    }

    if (isset($_POST['send_legacy_single'])) {
        $number = trim((string)($_POST['single_number'] ?? ''));
        $message = trim((string)($_POST['single_message'] ?? ''));
        if ($number === '' || $message === '') {
            flashMessage('danger', 'أدخل رقم الوجهة ونص الرسالة.');
        } else {
            $manualId = null;
            try {
                $stmt = $pdo->prepare("INSERT INTO legacy_wa_manual_sends (number,message,status) VALUES (?,?,?)");
                $stmt->execute([$number, $message, 'processing']);
                $manualId = (int)$pdo->lastInsertId();
                $provider = legacyWaProviderSend($pdo, $number, $message);
                $status = !empty($provider['ok']) ? 'sent' : 'failed';
                $pdo->prepare("UPDATE legacy_wa_manual_sends SET status=?,provider_message_id=?,http_status=?,error_message=?,response_excerpt=?,finished_at=NOW() WHERE id=?")
                    ->execute([$status, $provider['provider_id'] ?? null, (int)($provider['http_status'] ?? 0), ($provider['error'] ?? '') ?: null, ($provider['response_excerpt'] ?? '') ?: null, $manualId]);
                flashMessage(!empty($provider['ok']) ? 'success' : 'danger', !empty($provider['ok']) ? 'تم إرسال الرسالة عبر المرسل المستقل.' : 'فشل الإرسال: ' . ($provider['error'] ?? 'provider_rejected'));
            } catch (Throwable $e) {
                if ($manualId) { try { $pdo->prepare("UPDATE legacy_wa_manual_sends SET status='failed',error_message=?,finished_at=NOW() WHERE id=?")->execute([legacyWaSubstr($e->getMessage(), 0, 1000), $manualId]); } catch (Throwable $ignored) {} }
                flashMessage('danger', 'تعذر تسجيل أو إرسال الرسالة اليدوية.');
            }
        }
        legacyWaFlashRedirect('single');
    }
}

$settings = legacyWaAuthFromSettingsForDisplay($pdo);
$autoSendEnabled = legacyWaGetSetting($pdo, 'auto_send_enabled') === '1';
$sendDelaySettings = legacyWaGetSendDelaySettings($pdo);
$webhookEnabled = legacyWaGetSetting($pdo, 'heta_webhook_enabled') === '1';
$webhookSecretSet = legacyWaGetSetting($pdo, 'heta_webhook_secret') !== '';
$expectedSenderSet = legacyWaGetSetting($pdo, 'heta_webhook_expected_sender') !== '';
$hetaConfigured = legacyWaGetHetaSetting($pdo, 'heta_api_key') !== '' && legacyWaGetHetaSetting($pdo, 'heta_sender') !== '';
$endpoint = rtrim((string)SITE_URL, '/');
$stats = ['inbound'=>0,'queued'=>0,'sent'=>0,'failed'=>0,'duplicates'=>0,'webhooks'=>0,'incoming'=>0];
$inbound=[]; $outbox=[]; $attempts=[]; $requests=[]; $webhookLogs=[]; $incoming=[]; $statuses=[]; $receipts=[]; $manualSends=[];
$requestSummary = ['total_24h'=>0,'accepted_24h'=>0,'rejected_24h'=>0,'health_24h'=>0,'last_request_at'=>null,'last_accepted_at'=>null,'last_rejected_at'=>null,'last_outbox_at'=>null,'last_attempt_at'=>null];
if ($schema['ok']) {
    try {
        if ($activeTab === 'dashboard') {
            $stats['inbound'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_inbound")->fetchColumn();
            $stats['queued'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_outbox WHERE status IN ('pending','processing')")->fetchColumn();
            $stats['sent'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_outbox WHERE status='sent'")->fetchColumn();
            $stats['failed'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_outbox WHERE status='failed'")->fetchColumn();
            $stats['incoming'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_incoming_messages")->fetchColumn();
            $stats['webhooks'] = (int)$pdo->query("SELECT COUNT(*) FROM legacy_wa_webhook_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        }
        if ($activeTab === 'outbox') {
            $inbound = $pdo->query("SELECT id,external_id,number,message,amount,balance,status,error_message,created_at,processed_at FROM legacy_wa_inbound ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            try {
                $outbox = $pdo->query("SELECT o.id,o.inbound_id,o.number,o.message,o.status,o.attempt_count,o.last_error,o.provider_message_id,o.sent_at,o.available_at,o.delay_seconds,o.updated_at FROM legacy_wa_outbox o ORDER BY o.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $oldOutboxSchema) {
                $outbox = $pdo->query("SELECT o.id,o.inbound_id,o.number,o.message,o.status,o.attempt_count,o.last_error,o.provider_message_id,o.sent_at,o.available_at,0 AS delay_seconds,o.updated_at FROM legacy_wa_outbox o ORDER BY o.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if ($activeTab === 'single') {
            $manualSends = $pdo->query("SELECT id,number,message,status,provider_message_id,http_status,error_message,created_at,finished_at FROM legacy_wa_manual_sends ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($activeTab === 'inbox') {
            $incoming = $pdo->query("SELECT id,message_id,device,from_number,from_name,chat_id,chat_name,participant,message_type,message,is_group,event_at,created_at FROM legacy_wa_incoming_messages ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $statuses = $pdo->query("SELECT id,device,event_name,status_value,event_at,created_at FROM legacy_wa_device_status ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            $receipts = $pdo->query("SELECT id,receipt_id,message_id,device,recipient_number,status_value,receipt_at,created_at FROM legacy_wa_receipts ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($activeTab === 'logs') {
            $attempts = $pdo->query("SELECT a.id,a.outbox_id,a.ok,a.http_status,a.error_message,a.response_excerpt,a.attempted_at,o.number FROM legacy_wa_attempts a LEFT JOIN legacy_wa_outbox o ON o.id=a.outbox_id ORDER BY a.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $webhookLogs = $pdo->query("SELECT id,event_type,device,signature_status,payload_hash,remote_ip,http_status,processing_status,processing_message,created_at,processed_at FROM legacy_wa_webhook_log ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($activeTab === 'diagnostics') {
            $summaryStmt = $pdo->query("SELECT
                COUNT(*) AS total_24h,
                SUM(auth_status='accepted') AS accepted_24h,
                SUM(auth_status='rejected') AS rejected_24h,
                SUM(auth_reason='health_check') AS health_24h,
                MAX(created_at) AS last_request_at,
                MAX(CASE WHEN auth_status='accepted' THEN created_at END) AS last_accepted_at,
                MAX(CASE WHEN auth_status='rejected' THEN created_at END) AS last_rejected_at
                FROM legacy_wa_requests WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $requestSummary = array_merge($requestSummary, $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);
            $requestSummary['last_outbox_at'] = $pdo->query("SELECT MAX(created_at) FROM legacy_wa_outbox")->fetchColumn() ?: null;
            $requestSummary['last_attempt_at'] = $pdo->query("SELECT MAX(attempted_at) FROM legacy_wa_attempts")->fetchColumn() ?: null;
            $requests = $pdo->query("SELECT id,method,request_path,content_type,field_names,payload_shape,payload_hash,payload_bytes,auth_status,auth_reason,response_status,created_at,finished_at FROM legacy_wa_requests ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $schema['error'] = 'تعذر قراءة بعض سجلات النظام المستقل.';
    }
}

include 'header.php';
?>
<style>
.legacy-wrap{max-width:1280px;margin:0 auto;padding:22px 0}.legacy-head{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}.legacy-title{font-size:1.42rem;font-weight:900;color:var(--text1)}.legacy-sub{color:var(--text3);font-size:.84rem;margin-top:6px;line-height:1.7}.legacy-actions{display:flex;gap:8px;flex-wrap:wrap}.legacy-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:10px;padding:10px 15px;background:#1683dc;color:#fff;font-family:inherit;font-weight:800;cursor:pointer;text-decoration:none;font-size:.8rem}.legacy-btn:hover{filter:brightness(.93)}.legacy-btn.secondary{background:#64748b}.legacy-btn.green{background:#16a34a}.legacy-btn.orange{background:#d97706}.legacy-btn.red{background:#dc2626}.legacy-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:19px;margin-bottom:17px}.legacy-card h2{font-size:1rem;color:var(--text1);margin:0 0 15px}.legacy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:11px;margin-bottom:17px}.legacy-stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;text-align:center}.legacy-stat b{display:block;font-size:1.5rem;color:var(--text1)}.legacy-stat span{display:block;font-size:.75rem;color:var(--text3);margin-top:4px}.legacy-tabs{display:flex;gap:6px;overflow:auto;padding:5px;background:var(--card);border:1px solid var(--border);border-radius:14px;margin-bottom:17px}.legacy-tab{white-space:nowrap;text-decoration:none;color:var(--text2);font-size:.8rem;font-weight:800;padding:10px 13px;border-radius:10px}.legacy-tab.active{background:#1683dc;color:#fff}.legacy-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.legacy-field.full{grid-column:1/-1}.legacy-field label{display:block;font-size:.8rem;font-weight:800;color:var(--text2);margin-bottom:6px}.legacy-input,.legacy-select,.legacy-textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text1);font-family:inherit}.legacy-textarea{min-height:100px;resize:vertical}.legacy-help{font-size:.74rem;color:var(--text3);margin-top:5px;line-height:1.65}.legacy-check{display:flex;align-items:center;gap:9px;color:var(--text1);font-weight:800;font-size:.83rem}.legacy-check input{width:18px;height:18px}.legacy-note{border-right:4px solid #f59e0b;background:#f59e0b12;border-radius:8px;padding:11px 13px;color:var(--text2);font-size:.8rem;line-height:1.75;margin-bottom:15px}.legacy-note.ok{border-right-color:#16a34a;background:#16a34a12}.legacy-code{direction:ltr;text-align:left;font-family:monospace;word-break:break-all;background:var(--bg);border:1px dashed var(--border);border-radius:10px;padding:11px;font-size:.79rem}.legacy-badge{display:inline-block;padding:4px 9px;border-radius:999px;font-size:.7rem;font-weight:900;background:#64748b20;color:#64748b}.legacy-badge.ok{background:#16a34a20;color:#15803d}.legacy-badge.warn{background:#f59e0b20;color:#b45309}.legacy-table-wrap{overflow:auto}.legacy-table{width:100%;border-collapse:collapse;font-size:.76rem}.legacy-table th,.legacy-table td{padding:9px;border-bottom:1px solid var(--border);text-align:right;vertical-align:top;white-space:nowrap}.legacy-table th{color:var(--text3);background:var(--bg)}.legacy-message{white-space:pre-wrap;max-width:370px;word-break:break-word;line-height:1.55}.legacy-json{direction:ltr;text-align:left;white-space:pre-wrap;max-width:300px;word-break:break-word;font-family:monospace;font-size:.69rem;line-height:1.45}.legacy-muted{color:var(--text3)}.legacy-kpi{display:flex;gap:9px;flex-wrap:wrap}.legacy-kpi span{padding:7px 10px;border:1px solid var(--border);border-radius:9px;font-size:.78rem;color:var(--text2)}@media(max-width:720px){.legacy-form{grid-template-columns:1fr}.legacy-field.full{grid-column:auto}.legacy-actions{width:100%}.legacy-btn{flex:1}.legacy-table th,.legacy-table td{white-space:normal}}
</style>
<div class="legacy-wrap" dir="rtl">
    <div class="legacy-head">
        <div><div class="legacy-title">واتساب نجاز كارد القديم</div><div class="legacy-sub">لوحة مستقلة لجسر يمن روبوت وطابور الإرسال والمرسل الجديد في HetaCloud، دون ارتباط تشغيلي بواتساب العام أو Telegram أو Sawa أو المدفوعات.</div></div>
        <div class="legacy-actions">
            <span class="legacy-badge <?= $settings['enabled'] ? 'ok' : '' ?>"><?= $settings['enabled'] ? 'الربط مفعّل' : 'الربط متوقف' ?></span>
            <span class="legacy-badge <?= $settings['mode'] === 'send' ? 'warn' : 'ok' ?>"><?= $settings['mode'] === 'send' ? 'الإرسال مفعّل' : 'مراقبة فقط' ?></span>
            <span class="legacy-badge <?= $hetaConfigured ? 'ok' : '' ?>"><?= $hetaConfigured ? 'HetaCloud مضبوط' : 'HetaCloud غير مكتمل' ?></span>
        </div>
    </div>

    <nav class="legacy-tabs">
        <?php $tabs = ['dashboard'=>'لوحة التحكم','single'=>'رسالة واحدة','inbox'=>'الوارد الجديد','outbox'=>'الطابور والإرسال','logs'=>'السجلات والمحاولات','diagnostics'=>'التشخيص','settings'=>'الإعدادات']; foreach ($tabs as $key=>$label): ?>
            <a class="legacy-tab <?= $activeTab === $key ? 'active' : '' ?>" href="<?= legacyWaH('/admin/legacy_whatsapp.php?tab=' . $key) ?>"><?= legacyWaH($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$schema['ok']): ?><div class="legacy-note">تعذر تجهيز جداول النظام المستقل. افتح الصفحة بعد التأكد من صلاحية قاعدة البيانات. لا توجد أي تغييرات على جداول الأنظمة الأخرى.</div><?php endif; ?>

    <?php if ($activeTab === 'dashboard'): ?>
        <div class="legacy-grid">
            <div class="legacy-stat"><b><?= (int)$stats['inbound'] ?></b><span>إشعارات يمن روبوت</span></div>
            <div class="legacy-stat"><b><?= (int)$stats['queued'] ?></b><span>رسائل في الطابور</span></div>
            <div class="legacy-stat"><b><?= (int)$stats['sent'] ?></b><span>أُرسلت عبر HetaCloud</span></div>
            <div class="legacy-stat"><b><?= (int)$stats['failed'] ?></b><span>إرسالات فاشلة</span></div>
            <div class="legacy-stat"><b><?= (int)$stats['incoming'] ?></b><span>وارد الرقم الجديد</span></div>
            <div class="legacy-stat"><b><?= (int)$stats['webhooks'] ?></b><span>أحداث Webhook خلال 24 ساعة</span></div>
        </div>
        <div class="legacy-card">
            <h2>ملخص التشغيل</h2>
            <div class="legacy-note <?= $settings['mode'] === 'capture' ? 'ok' : '' ?>"><?= $settings['mode'] === 'send' ? 'وضع الإرسال مفعّل: إشعارات يمن روبوت الجديدة تُضاف إلى الطابور، والعامل يرسلها إلى number الموجود في كل صف باستخدام المرسل المستقل.' : 'وضع المراقبة فقط: يتم حفظ إشعارات يمن روبوت دون إرسال خارجي.' ?></div>
            <div class="legacy-kpi"><span>Webhook HetaCloud: <?= $webhookEnabled ? 'مفعّل' : 'متوقف' ?></span><span>فتح اللوحة: قراءة فقط</span><span>سر Webhook: <?= $webhookSecretSet ? 'محفوظ' : 'غير مضبوط' ?></span><span>المرسل المتوقع: <?= $expectedSenderSet ? 'محدد' : 'غير محدد' ?></span><span>جداول النظام: <?= $schema['ok'] ? 'جاهزة' : 'غير جاهزة' ?></span><?php if ($legacyBackfilled > 0): ?><span>تمت جدولة رسائل قديمة: <?= $legacyBackfilled ?></span><?php endif; ?></div>
        </div>
        <div class="legacy-card"><h2>روابط التشغيل</h2><div class="legacy-form"><div class="legacy-field full"><label>رابط يمن روبوت / الجسر الحالي</label><div class="legacy-code"><?= legacyWaH($endpoint . '/api/legacy_whatsapp_webhook.php') ?></div></div><div class="legacy-field full"><label>Webhook HetaCloud للجهاز الجديد فقط</label><div class="legacy-code"><?= legacyWaH($endpoint . '/legacy_hetacloud_webhook.php') ?></div><div class="legacy-help">ضع هذا الرابط في إعدادات Webhook للجهاز الجديد فقط. لا تغيّر Webhook الرقم العام أو الجهاز القديم.</div></div></div></div>
    <?php elseif ($activeTab === 'single'): ?>
        <div class="legacy-card"><h2>إرسال رسالة واحدة عبر المرسل المستقل</h2><div class="legacy-note">هذا الإرسال فعلي عند الضغط على الزر، ويستخدم إعداد HetaCloud المحلي للنظام القديم. لا تستخدمه إلا لاختبار الوجهة المقصودة.</div><form method="post" class="legacy-form"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="single"><div class="legacy-field"><label>رقم الوجهة</label><input class="legacy-input" name="single_number" required dir="ltr" placeholder="رقم العميل أو معرف المجموعة"></div><div class="legacy-field full"><label>نص الرسالة</label><textarea class="legacy-textarea" name="single_message" required></textarea></div><div class="legacy-field full"><button class="legacy-btn green" name="send_legacy_single" value="1">إرسال الرسالة الآن</button></div></form></div>
        <div class="legacy-card"><h2>آخر الرسائل اليدوية</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>الوجهة</th><th>الرسالة</th><th>الحالة</th><th>HTTP</th><th>الخطأ</th><th>الوقت</th></tr></thead><tbody><?php foreach($manualSends as $row): ?><tr><td><?= (int)$row['id'] ?></td><td dir="ltr"><?= legacyWaH($row['number']) ?></td><td><div class="legacy-message"><?= legacyWaH($row['message']) ?></div></td><td><?= legacyWaH($row['status']) ?></td><td><?= (int)$row['http_status'] ?></td><td><?= legacyWaH($row['error_message']) ?></td><td><?= legacyWaH($row['created_at']) ?></td></tr><?php endforeach; if(!$manualSends): ?><tr><td colspan="7" class="legacy-muted">لا توجد رسائل يدوية.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php elseif ($activeTab === 'inbox'): ?>
        <div class="legacy-card"><h2>صندوق وارد HetaCloud للرقم الجديد</h2><div class="legacy-note">يعرض هذا التبويب ما يستقبله Webhook المستقل من الجهاز الجديد. الحفظ فقط؛ لا توجد ردود تلقائية أو إعادة إرسال خارجية من Webhook.</div><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>المعرف</th><th>الجهاز</th><th>من</th><th>المحادثة</th><th>النص</th><th>النوع</th><th>مجموعة</th><th>الوقت</th></tr></thead><tbody><?php foreach($incoming as $row): ?><tr><td><?= (int)$row['id'] ?></td><td dir="ltr"><?= legacyWaH($row['message_id']) ?></td><td><?= legacyWaH($row['device']) ?></td><td dir="ltr"><?= legacyWaH($row['from_number']) ?></td><td><?= legacyWaH($row['chat_name'] ?: $row['chat_id']) ?></td><td><div class="legacy-message"><?= legacyWaH($row['message']) ?></div></td><td><?= legacyWaH($row['message_type']) ?></td><td><?= (int)$row['is_group'] ? 'نعم' : 'لا' ?></td><td><?= legacyWaH($row['event_at'] ?: $row['created_at']) ?></td></tr><?php endforeach; if(!$incoming): ?><tr><td colspan="9" class="legacy-muted">لا توجد رسائل واردة من Webhook الجديد بعد.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="legacy-card"><h2>آخر حالات الجهاز والإيصالات</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>النوع</th><th>الجهاز</th><th>الحالة/المعرف</th><th>الوقت</th></tr></thead><tbody><?php foreach($statuses as $row): ?><tr><td>حالة جهاز: <?= legacyWaH($row['event_name']) ?></td><td><?= legacyWaH($row['device']) ?></td><td><?= legacyWaH($row['status_value']) ?></td><td><?= legacyWaH($row['event_at'] ?: $row['created_at']) ?></td></tr><?php endforeach; foreach($receipts as $row): ?><tr><td>إيصال</td><td><?= legacyWaH($row['device']) ?></td><td><?= legacyWaH($row['status_value']) ?> / <?= legacyWaH($row['message_id']) ?></td><td><?= legacyWaH($row['receipt_at'] ?: $row['created_at']) ?></td></tr><?php endforeach; if(!$statuses && !$receipts): ?><tr><td colspan="4" class="legacy-muted">لا توجد حالات أو إيصالات.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php elseif ($activeTab === 'outbox'): ?>
        <div class="legacy-card"><h2>طابور الإرسال</h2><div class="legacy-actions"><form method="post"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="outbox"><button class="legacy-btn green" name="run_legacy_wa_worker" value="1">تشغيل العامل المستحق الآن</button></form><form method="post"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="outbox"><input type="hidden" name="backfill_batch_size" value="100"><button class="legacy-btn orange" name="backfill_legacy_wa" value="1">إعادة جدولة القديمة فقط</button></form><a class="legacy-btn secondary" href="<?= legacyWaH(SITE_URL . '/admin/legacy_whatsapp.php?tab=outbox') ?>">تحديث</a></div><div class="legacy-note">العامل يعالج جدول legacy_wa_outbox فقط، ويرسل كل صف إلى number الموجود في الصف، ويعالج الصفوف المستحقة فقط دون انتظار موعد مستقبلي. إعادة الجدولة اليدوية تمنح الصفوف القديمة pending ذات التأخير الصفري موعداً جديداً فقط، ولا ترسل أي رسالة. بجانب كل صف pending حلّ موعده أو failed يوجد زر لإعادة ضبط محاولاته ومنحه موعداً جديداً؛ بعد حلول الموعد يرسله العامل المستقل.</div><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>رقم الوجهة</th><th>النص</th><th>الحالة</th><th>التأخير</th><th>موعد الإرسال</th><th>المحاولات</th><th>آخر خطأ</th><th>مرسل في</th><th>تحديث</th><th>إجراء</th></tr></thead><tbody><?php foreach($outbox as $row): ?><?php $rowStatus = (string)($row['status'] ?? ''); $rowAvailableTs = strtotime((string)($row['available_at'] ?? '')); $rowRetryable = $rowStatus === 'failed' || ($rowStatus === 'pending' && ($rowAvailableTs === false || $rowAvailableTs <= time())); ?><tr><td><?= (int)$row['id'] ?></td><td dir="ltr"><?= legacyWaH($row['number']) ?></td><td><div class="legacy-message"><?= legacyWaH($row['message']) ?></div></td><td><?= legacyWaH($row['status']) ?></td><td><?= (int)($row['delay_seconds'] ?? 0) ?> ثانية</td><td><?= legacyWaH($row['available_at'] ?? '') ?></td><td><?= (int)$row['attempt_count'] ?></td><td><?= legacyWaH($row['last_error']) ?></td><td><?= legacyWaH($row['sent_at']) ?></td><td><?= legacyWaH($row['updated_at']) ?></td><td><?php if ($rowRetryable): ?><form method="post" style="margin:0 0 4px"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="outbox"><input type="hidden" name="outbox_id" value="<?= (int)$row['id'] ?>"><button class="legacy-btn orange" name="reschedule_legacy_outbox" value="1" type="submit">إعادة الجدولة والإرسال</button></form><?php endif; ?><?php if ($rowStatus === 'pending'): ?><form method="post" style="margin:0" onsubmit="return confirm('هل تريد إلغاء هذه العملية وتحويلها إلى failed؟')"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="outbox"><input type="hidden" name="outbox_id" value="<?= (int)$row['id'] ?>"><button class="legacy-btn danger" name="cancel_legacy_outbox" value="1" type="submit">إلغاء وجعلها فاشلة</button></form><?php elseif (!$rowRetryable): ?><span class="legacy-muted">غير متاح حالياً</span><?php endif; ?></td></tr><?php endforeach; if(!$outbox): ?><tr><td colspan="11" class="legacy-muted">الطابور فارغ.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="legacy-card"><h2>طلبات يمن روبوت المحفوظة</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>الوجهة</th><th>المعرف الخارجي</th><th>النص</th><th>الحالة</th><th>المبلغ</th><th>الوقت</th></tr></thead><tbody><?php foreach($inbound as $row): ?><tr><td><?= (int)$row['id'] ?></td><td dir="ltr"><?= legacyWaH($row['number']) ?></td><td><?= legacyWaH($row['external_id']) ?></td><td><div class="legacy-message"><?= legacyWaH($row['message']) ?></div></td><td><?= legacyWaH($row['status']) ?></td><td><?= legacyWaH($row['amount']) ?></td><td><?= legacyWaH($row['created_at']) ?></td></tr><?php endforeach; if(!$inbound): ?><tr><td colspan="7" class="legacy-muted">لا توجد طلبات.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php elseif ($activeTab === 'logs'): ?>
        <div class="legacy-card"><h2>محاولات HetaCloud</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>Outbox</th><th>الوجهة</th><th>النتيجة</th><th>HTTP</th><th>الخطأ</th><th>الوقت</th></tr></thead><tbody><?php foreach($attempts as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= (int)$row['outbox_id'] ?></td><td dir="ltr"><?= legacyWaH($row['number']) ?></td><td><?= (int)$row['ok'] ? 'نجاح' : 'فشل' ?></td><td><?= (int)$row['http_status'] ?></td><td><?= legacyWaH($row['error_message']) ?><div class="legacy-json"><?= legacyWaH($row['response_excerpt']) ?></div></td><td><?= legacyWaH($row['attempted_at']) ?></td></tr><?php endforeach; if(!$attempts): ?><tr><td colspan="7" class="legacy-muted">لا توجد محاولات.</td></tr><?php endif; ?></tbody></table></div></div>
        <div class="legacy-card"><h2>سجل Webhook المستقل</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>الحدث</th><th>الجهاز</th><th>التوقيع</th><th>الحالة</th><th>HTTP</th><th>السبب</th><th>الوقت</th></tr></thead><tbody><?php foreach($webhookLogs as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= legacyWaH($row['event_type']) ?></td><td><?= legacyWaH($row['device']) ?></td><td><?= legacyWaH($row['signature_status']) ?></td><td><?= legacyWaH($row['processing_status']) ?></td><td><?= (int)$row['http_status'] ?></td><td><?= legacyWaH($row['processing_message']) ?></td><td><?= legacyWaH($row['created_at']) ?></td></tr><?php endforeach; if(!$webhookLogs): ?><tr><td colspan="8" class="legacy-muted">لا توجد أحداث Webhook.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php elseif ($activeTab === 'diagnostics'): ?>
        <div class="legacy-card"><h2>فحوص الاتصال</h2><div class="legacy-actions"><form method="post"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="diagnostics"><button class="legacy-btn" name="check_legacy_wa" value="1">فحص جسر يمن روبوت</button></form><form method="post"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="diagnostics"><button class="legacy-btn secondary" name="check_legacy_hetacloud_webhook" value="1">فحص Webhook الجديد</button></form></div><div class="legacy-help">الفحصان قراءة فقط، ولا يرسلان رسالة إلى HetaCloud.</div></div>
        <?php if ($legacyBackfilled > 0): ?><div class="legacy-note ok">تمت إعادة جدولة <?= $legacyBackfilled ?> رسالة قديمة كانت pending بوقت انتظار صفري. أصبحت لكل رسالة مدة وموعد إرسال جديدان حسب إعدادات التأخير الحالية.</div><?php endif; ?>
        <div class="legacy-card"><h2>التمييز الزمني لمصدر المشكلة</h2><div class="legacy-note">هذا الملخص يثبت ما وصل إلى خادم نجاز فقط. إذا أرسلت يمن روبوت إشعاراً ولم يتغير «آخر طلب وصل» في نفس الدقيقة، فالمشكلة قبل Endpoint أو في اتصال يمن روبوت. إذا ظهر الطلب كمقبول ولم يظهر صف طابور، فالمشكلة تخزين؛ وإذا ظهر صف الطابور دون محاولة، فالمشكلة في التشغيل؛ وإذا ظهرت محاولة فاشلة، فالمشكلة بين العامل وHetaCloud.</div><div class="legacy-grid"><div class="legacy-stat"><b><?= (int)$requestSummary['total_24h'] ?></b><span>طلبات خلال 24 ساعة</span></div><div class="legacy-stat"><b><?= (int)$requestSummary['accepted_24h'] ?></b><span>طلبات مقبولة</span></div><div class="legacy-stat"><b><?= (int)$requestSummary['rejected_24h'] ?></b><span>طلبات مرفوضة</span></div><div class="legacy-stat"><b><?= (int)$requestSummary['health_24h'] ?></b><span>فحوص صحة</span></div></div><div class="legacy-kpi"><span>آخر طلب وصل: <?= legacyWaH($requestSummary['last_request_at'] ?: 'لا يوجد') ?></span><span>آخر طلب مقبول: <?= legacyWaH($requestSummary['last_accepted_at'] ?: 'لا يوجد') ?></span><span>آخر طلب مرفوض: <?= legacyWaH($requestSummary['last_rejected_at'] ?: 'لا يوجد') ?></span><span>آخر صف طابور: <?= legacyWaH($requestSummary['last_outbox_at'] ?: 'لا يوجد') ?></span><span>آخر محاولة إرسال: <?= legacyWaH($requestSummary['last_attempt_at'] ?: 'لا يوجد') ?></span></div></div>
        <div class="legacy-card"><h2>طلبات الجسر المشخصة</h2><div class="legacy-table-wrap"><table class="legacy-table"><thead><tr><th>#</th><th>الطريقة</th><th>المسار</th><th>الحقول</th><th>الحجم</th><th>التوثيق</th><th>HTTP</th><th>الوقت</th></tr></thead><tbody><?php foreach($requests as $row): ?><tr><td><?= (int)$row['id'] ?></td><td><?= legacyWaH($row['method']) ?></td><td dir="ltr"><?= legacyWaH($row['request_path']) ?></td><td><div class="legacy-json"><?= legacyWaH($row['field_names']) ?></div></td><td><?= (int)$row['payload_bytes'] ?></td><td><?= legacyWaH($row['auth_status'] . ' / ' . $row['auth_reason']) ?></td><td><?= (int)$row['response_status'] ?></td><td><?= legacyWaH($row['created_at']) ?></td></tr><?php endforeach; if(!$requests): ?><tr><td colspan="8" class="legacy-muted">لا توجد طلبات مسجلة.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php elseif ($activeTab === 'settings'): ?>
        <div class="legacy-card"><h2>إعدادات النظام المستقل</h2><div class="legacy-note">لا تعدّل هذه الصفحة إعدادات واتساب العام. المفتاح والمرسل المحليان خاصان بهذا النظام، والحقول السرية لا تُعرض بعد حفظها.</div><form method="post" class="legacy-form"><input type="hidden" name="csrf" value="<?= legacyWaH($csrf) ?>"><input type="hidden" name="return_tab" value="settings"><div class="legacy-field"><label class="legacy-check"><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>> تفعيل استقبال الجسر</label></div><div class="legacy-field"><label>وضع التشغيل</label><select class="legacy-select" name="mode"><option value="capture" <?= $settings['mode'] === 'capture' ? 'selected' : '' ?>>مراقبة وحفظ فقط</option><option value="send" <?= $settings['mode'] === 'send' ? 'selected' : '' ?>>إرسال كامل عبر الطابور</option></select><div class="legacy-help">اختر الإرسال الكامل حتى تنتقل إشعارات يمن روبوت إلى HetaCloud.</div></div><div class="legacy-field"><label class="legacy-check"><input type="checkbox" name="auto_send_enabled" value="1" <?= $autoSendEnabled ? 'checked' : '' ?>> تشغيل المعالجة التلقائية للطابور</label><div class="legacy-help">عند تفعيله تُحفظ الرسالة ثم تُرسل تلقائياً بعد موعد التأخير عبر العامل المخصص. فتح اللوحة لا يشغّل العامل؛ ويلزم مشغّل دوري مستقل للإرسال التلقائي المستمر.</div></div><div class="legacy-field full"><label class="legacy-check"><input type="checkbox" name="send_delay_enabled" value="1" <?= $sendDelaySettings['enabled'] ? 'checked' : '' ?>> تفعيل التأخير المحافظ قبل الإرسال</label><div class="legacy-help">عند التفعيل يُحدد لكل رسالة موعد إرسال عشوائي مستقل بين الحد الأدنى والأقصى. الحد الأدنى يبدأ من ثانية واحدة، والحد الأقصى أي عدد موجب تريده.</div></div><div class="legacy-field"><label>الحد الأدنى للتأخير (ثانية)</label><input class="legacy-input" type="number" name="send_delay_min_seconds" min="1" step="1" value="<?= (int)$sendDelaySettings['min'] ?>" dir="ltr"></div><div class="legacy-field"><label>الحد الأقصى للتأخير (ثانية)</label><input class="legacy-input" type="number" name="send_delay_max_seconds" min="1" step="1" value="<?= (int)$sendDelaySettings['max'] ?>" dir="ltr"></div><div class="legacy-field"><label>Token</label><input class="legacy-input" value="replo" readonly dir="ltr"></div><div class="legacy-field"><label>Type</label><input class="legacy-input" value="tws_ex" readonly dir="ltr"></div><div class="legacy-field full"><label>مفتاح الجسر المحلي</label><input class="legacy-input" value="<?= $settings['has_api_key'] ? '•••••••••••• محفوظ' : 'غير موجود' ?>" readonly dir="ltr"><div class="legacy-help">يُحفظ ولا يُعرض. لا تغيّره إذا كانت ربطية يمن روبوت الحالية تعمل به.</div></div><div class="legacy-field"><label>كلمة مرور الربطية الحالية</label><input class="legacy-input" type="password" name="replo_api_key" autocomplete="new-password" placeholder="اتركه فارغاً للإبقاء عليها"></div><div class="legacy-field"><label>مفتاح HetaCloud المحلي</label><input class="legacy-input" type="password" name="legacy_heta_api_key" autocomplete="new-password" placeholder="اتركه فارغاً للإبقاء عليه"><div class="legacy-help">اختياري؛ عند تركه فارغاً يمكن استخدام المفتاح العام كاحتياطي، بينما رقم المرسل لا يُورث من العام.</div></div><div class="legacy-field"><label>رقم/معرف مرسل HetaCloud الجديد</label><input class="legacy-input" type="password" name="legacy_heta_sender" autocomplete="new-password" placeholder="اتركه فارغاً للإبقاء عليه"><div class="legacy-help">هذا مرسل مستقل للنظام القديم فقط، وليس رقم وجهة الرسائل.</div></div><div class="legacy-field"><label class="legacy-check"><input type="checkbox" name="heta_webhook_enabled" value="1" <?= $webhookEnabled ? 'checked' : '' ?>> تفعيل استقبال Webhook الجديد</label></div><div class="legacy-field"><label>سر Webhook اختياري</label><input class="legacy-input" type="password" name="heta_webhook_secret" autocomplete="new-password" placeholder="<?= $webhookSecretSet ? 'محفوظ؛ اتركه فارغاً للإبقاء عليه' : 'اتركه فارغاً لتعطيل التحقق' ?>"></div><div class="legacy-field"><label>المرسل المتوقع اختيارياً</label><input class="legacy-input" type="password" name="heta_webhook_expected_sender" autocomplete="new-password" placeholder="<?= $expectedSenderSet ? 'محفوظ؛ اتركه فارغاً للإبقاء عليه' : 'مطابقة جهاز HetaCloud الجديد اختيارية' ?>"></div><div class="legacy-field full"><label>قائمة IP المسموحة للجسر</label><input class="legacy-input" name="ip_allowlist" value="<?= legacyWaH($settings['ip_allowlist']) ?>" dir="ltr"><div class="legacy-help">اتركها فارغة إذا لم تكن لديك قائمة ثابتة من يمن روبوت.</div></div><div class="legacy-field full"><button class="legacy-btn green" name="save_legacy_wa" value="1">حفظ إعدادات النظام القديم</button></div></form></div>
        <div class="legacy-card"><h2>روابط الربط</h2><div class="legacy-field"><label>رابط يمن روبوت الحالي</label><div class="legacy-code"><?= legacyWaH($endpoint . '/api/legacy_whatsapp_webhook.php') ?></div><div class="legacy-help">يمن روبوت يضيف /api/send تلقائياً عند استخدام الرابط الأساسي المعتمد في الربط.</div></div><div class="legacy-field" style="margin-top:14px"><label>Webhook HetaCloud المستقل</label><div class="legacy-code"><?= legacyWaH($endpoint . '/legacy_hetacloud_webhook.php') ?></div><div class="legacy-help">ضعه فقط في إعدادات Webhook للجهاز الجديد، ولا تستبدل به رابط الجهاز العام.</div></div></div>
    <?php endif; ?>
</div>
