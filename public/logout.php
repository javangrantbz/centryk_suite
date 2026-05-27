<?php
require_once __DIR__ . '/../app/core/Auth.php';

Auth::logout();

$appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/centryk/public', '/');
header('Location: ' . $appUrl . '/');
exit;
