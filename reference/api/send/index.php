<?php
/**
 * مسار توافق يمن روبوت/ريبلو.
 * يمن روبوت يضيف /api/send إلى الرابط الأساسي المحفوظ في ربطية ريبلو.
 * المنطق الفعلي مستقل في endpoint الجسر، ولا يستدعي HetaCloud أثناء الاستقبال.
 */
require_once __DIR__ . '/../legacy_whatsapp_webhook.php';
