<?php
/**
 * Example only. Do not put real Binance credentials in this file.
 * The admin page stores credentials encrypted only when the storage key is
 * provided as a server-side environment variable.
 */
return [
    'api_key'    => getenv('NJAZ_BINANCE_PAY_API_KEY') ?: '',
    'api_secret' => getenv('NJAZ_BINANCE_PAY_API_SECRET') ?: '',
    'base_url'   => getenv('NJAZ_BINANCE_PAY_BASE_URL') ?: 'https://api.binance.com',
    // Optional: dedicated encryption key for installations that want to override
    // the automatic key derived from the existing site configuration.
    'storage_key_override_env' => 'NJAZ_BINANCE_PAY_STORAGE_KEY',
];
/*
 * لا يلزم تعريف أي متغير خادم لإدخال المفاتيح من لوحة الإدارة.
 * يمكن اختيارياً تعريف NJAZ_BINANCE_PAY_STORAGE_KEY لتوفير مفتاح تشفير مستقل.
 * لا تضع القيم الحقيقية في هذا الملف أو في الحزمة.
 */
