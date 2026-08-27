<?php
/**
 * Router for /s/<slug> short store links (see the root .htaccess).
 * Resolves the company slug and 301s to the canonical storefront URL.
 */
require_once __DIR__ . '/../app/core/Env.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/StoreLink.php';

Env::load(__DIR__ . '/../.env');

$appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/centryk/public'), '/');
$uuid   = StoreLink::resolve(DB::pdo(), (string)($_GET['slug'] ?? ''));

if ($uuid === null) {
    header('Location: ' . $appUrl . '/store.php', true, 302); // unknown slug → store feed
    exit;
}

header('Location: ' . $appUrl . '/store.php?company_uuid=' . urlencode($uuid), true, 301);
exit;
