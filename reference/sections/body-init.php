<?php
if (!defined('NJAZ_APP_LOADED')) { http_response_code(403); exit('Direct access forbidden.'); }
?>
<script src="<?= SITE_URL ?>/assets/js/i18n.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/i18n.js') ?>"></script>
</head>
<body>
<script>
// منع وميض الألوان عند التحميل (قبل CSS) — الافتراضي: نهاري، إلا إذا اختار المستخدم الليلي صراحة.
// نقرأ المفتاح القديم والجديد حتى لا يختلف زر الإعدادات عن زر الهيدر بعد إعادة التحميل.
let _njazTheme = '';
try {
  const _legacyTheme = localStorage.getItem('njaz_theme');
  const _settings = JSON.parse(localStorage.getItem('user_settings') || '{}');
  _njazTheme = (_settings && _settings.theme) || _legacyTheme || '';
} catch (e) {}
if (_njazTheme !== 'dark') {
  document.body ? document.body.classList.add('light-mode')
  : document.addEventListener('DOMContentLoaded', () => document.body.classList.add('light-mode'));
}
</script>
