<?php
require_once __DIR__ . '/_common.php';
mobileAuthorizeRequest($pdo, true);
require dirname(__DIR__) . '/chat.php';
