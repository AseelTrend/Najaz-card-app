<?php
/**
 * كتالوج لغات العرض للموقع وبوت Telegram.
 * القراءة عامة للغات والترجمات المفعلة فقط، ولا يتضمن أي بيانات مستخدمين.
 */
require_once '../includes/config.php';
require_once '../includes/language_catalog.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

try {
    $requestedLanguage = isset($_GET['lang']) ? (string)$_GET['lang'] : null;
    $payload = njazLanguageApiPayload($pdo, $requestedLanguage);
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Display languages API error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'languages' => [], 'translations' => []], JSON_UNESCAPED_UNICODE);
}
