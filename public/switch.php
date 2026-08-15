<?php
/**
 * App switcher redirect.
 * Called by OnePay/MyPay header links: switch.php?app=mypay
 * Validates the Centryk session, issues an SSO token, and redirects.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();

$appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/centryk/public', '/');
$user   = Auth::user();

if (!$user) {
    header('Location: ' . $appUrl . '/');
    exit;
}

$appKey       = trim($_GET['app']          ?? '');
$companyUuid  = trim($_GET['company_uuid'] ?? '');
$redirectPath = trim($_GET['redirect']     ?? '');

if ($appKey === '') {
    header('Location: ' . $appUrl . '/');
    exit;
}

$url = AuthService::launchApp((int)$user['id'], $appKey, $companyUuid, $redirectPath);

if (!$url) {
    header('Location: ' . $appUrl . '/');
    exit;
}

header('Location: ' . $url);
exit;
