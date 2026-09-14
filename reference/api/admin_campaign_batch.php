<?php
/**
 * api/admin_campaign_batch.php
 * تستدعيها لوحة الإدارة (JS) بشكل متكرر لإرسال دفعة من رسائل حملة
 * تسويقية في كل مرة، حتى تكتمل القائمة بالكامل — بهذا نتفادى تجاوز
 * مهلة تنفيذ PHP على الاستضافة المشتركة عند إرسال أعداد كبيرة.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/marketing_email_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    echo json_encode(['ok' => false, 'error' => 'غير مصرح']);
    exit;
}
if (!canAccess($pdo, 'perm_settings')) {
    echo json_encode(['ok' => false, 'error' => 'ليس لديك صلاحية لإدارة البريد الإلكتروني']);
    exit;
}

$campaignId = (int)($_POST['campaign_id'] ?? 0);
if (!$campaignId) {
    echo json_encode(['ok' => false, 'error' => 'معرّف الحملة مفقود']);
    exit;
}

// تأكد أن الحملة موجودة وليست ملغاة
$chk = $pdo->prepare("SELECT status FROM email_campaigns WHERE id=?");
$chk->execute([$campaignId]);
$status = $chk->fetchColumn();

if ($status === false) {
    echo json_encode(['ok' => false, 'error' => 'الحملة غير موجودة']);
    exit;
}
if ($status === 'cancelled') {
    echo json_encode(['ok' => false, 'error' => 'تم إلغاء هذه الحملة']);
    exit;
}
if ($status === 'completed') {
    echo json_encode(['ok' => true, 'done' => true, 'sent' => 0, 'failed' => 0, 'remaining' => 0]);
    exit;
}

$result = sendCampaignBatch($pdo, $campaignId);

echo json_encode(array_merge(['ok' => true], $result));
