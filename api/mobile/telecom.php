<?php
/**
 * جسر آمن لتشغيل كبينة السداد من تطبيق الموبايل.
 */
require_once __DIR__ . '/_common.php';

// يتحقق من JWT ويملأ $_SESSION للمستخدم في هذا الطلب فقط.
mobileAuthorizeRequest($pdo, true);

// تشخيص مؤقت فقط لمشكلة كشف الشبكة من تطبيق الموبايل.
// لا يعدل قاعدة البيانات ولا ينفذ أي عملية شحن.
if (($_GET['action'] ?? '') === 'detect_network') {
    header('Content-Type: application/json; charset=utf-8');

    $phone = preg_replace('/[^0-9]/', '', (string)($_GET['phone'] ?? ''));
    $prefix = substr($phone, 0, 2);

    try {
        $st = $pdo->query("SELECT id,name,name_en,prefixes,status,supports_balance,fore_endpoint FROM telecom_networks WHERE status=1 ORDER BY sort_order,id");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

        $networks = [];
        $matched = [];
        foreach ($rows as $row) {
            $prefixes = json_decode((string)($row['prefixes'] ?? ''), true);
            if (!is_array($prefixes)) {
                $prefixes = array_filter(array_map('trim', explode(',', (string)($row['prefixes'] ?? ''))));
            }
            $isMatch = in_array($prefix, array_map('strval', $prefixes), true);

            $networks[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'prefixes' => array_values($prefixes),
                'supports_balance' => (int)$row['supports_balance'],
                'fore_endpoint' => $row['fore_endpoint'],
                'prefix_match' => $isMatch,
            ];

            if ($isMatch) {
                $matched[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'prefixes' => array_values($prefixes),
                    'supports_balance' => (int)$row['supports_balance'],
                    'fore_endpoint' => $row['fore_endpoint'],
                ];
            }
        }

        echo json_encode([
            'status' => false,
            'message' => 'تشخيص كشف الشبكة: phone=' . $phone . ' | prefix=' . $prefix . ' | matches=' . count($matched),
            'debug' => [
                'phone' => $phone,
                'prefix' => $prefix,
                'matched' => $matched,
                'active_networks' => $networks,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'status' => false,
            'message' => 'خطأ تشخيص قاعدة البيانات: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// إعادة استخدام endpoint كبينة السداد الموجود في /api/telecom.php.
require dirname(__DIR__) . '/telecom.php';
