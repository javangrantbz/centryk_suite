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
    // Previously this dropped an unauthenticated visitor on Centryk's own
    // homepage, discarding the app=/company_uuid=/redirect= they arrived
    // with - after logging in they'd land on Centryk's dashboard instead of
    // back in the spoke app that sent them here. login.php's own redirect
    // param only accepts a same-directory relative path (no leading slash,
    // see centryk_safe_login_redirect() there), which "switch.php?..." is,
    // so this request's exact query string round-trips through login and
    // re-enters this same script once the user is authenticated.
    $selfQuery = $_SERVER['QUERY_STRING'] ?? '';
    $returnToSelf = 'switch.php' . ($selfQuery !== '' ? '?' . $selfQuery : '');
    header('Location: login.php?redirect=' . urlencode($returnToSelf));
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
