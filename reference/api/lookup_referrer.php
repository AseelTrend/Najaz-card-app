<?php
require_once '../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    echo json_encode(['ok' => false]);
    exit;
}

$code = strtoupper(trim($_POST['code'] ?? ''));
if (strlen($code) < 6) { echo json_encode(['ok' => false]); exit; }

$stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE referral_code = ? AND role='customer' AND (is_deleted IS NULL OR is_deleted=0)");
$stmt->execute([$code]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode([
        'ok'   => true,
        'id'   => $user['id'],
        'name' => htmlspecialchars($user['full_name'] ?: $user['username']),
    ]);
} else {
    echo json_encode(['ok' => false]);
}
