<?php
/**
 * Router for /r/<code> and /review/<company-slug>/<form-slug> short links (see
 * the root .htaccess). Resolves the pair to a form's share token and sends the visitor
 * to the fill page. Unknown pairs land on the fill page's "form not found"
 * message. 302 (not 301) because a company can rename a form's short name.
 */
require_once __DIR__ . '/../app/core/Env.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/FormsService.php';

Env::load(__DIR__ . '/../.env');

$appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/centryk/public'), '/');
// /r/<code> (very short, unique across companies) or /review/<company>/<form>.
$token = isset($_GET['code'])
    ? FormsService::tokenForCode((string)$_GET['code'])
    : FormsService::tokenForShortLink((string)($_GET['c'] ?? ''), (string)($_GET['s'] ?? ''));

header('Location: ' . $appUrl . '/f.php?t=' . urlencode($token ?? 'not-found'), true, 302);
exit;
