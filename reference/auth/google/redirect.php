<?php
require_once __DIR__ . '/../../includes/config.php';

$googleClientId = getSetting('google_client_id');
$googleEnabled  = getSetting('google_login_enabled');
$redirectUri    = SITE_URL . '/auth/google/callback.php';

if (!$googleEnabled || !$googleClientId) {
    flashMessage('danger', 'تسجيل الدخول عبر Google غير مفعّل حالياً');
    redirect(SITE_URL . '/login.php');
}

// توليد state عشوائي لمنع CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => $googleClientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'online',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
