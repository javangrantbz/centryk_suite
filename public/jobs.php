<?php
// Short link: /jobs (and /jobs?company=<uuid>) → the MyPay public job board.
// A redirect, not a rewrite, because MyPay is a separate origin in production.
require_once __DIR__ . '/../app/core/AppLinks.php';

$company = trim((string)($_GET['company'] ?? ''));
$target  = AppLinks::jobBoard($company !== '' ? $company : null);

if ($target === '') {
    // MyPay not registered — fall back to the marketing page.
    header('Location: about.php#mypay', true, 302);
    exit;
}

header('Location: ' . $target, true, 302);
exit;
