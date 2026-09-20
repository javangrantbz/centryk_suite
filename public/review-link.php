<?php
/**
 * Router for /r/<code> and /review/<company-slug>/<form-slug> short links (see
 * the root .htaccess). Resolves the link to a form's share token and serves the
 * fill page in place, so the address bar keeps the short link the visitor
 * scanned or typed instead of switching to f.php?t=<token>. Unknown links get
 * the fill page's "form not found" message with a 404 status.
 */
require_once __DIR__ . '/../app/core/Env.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/FormsService.php';

Env::load(__DIR__ . '/../.env');

// /r/<code> (very short, unique across companies) or /review/<company>/<form>.
$token = isset($_GET['code'])
    ? FormsService::tokenForCode((string)$_GET['code'])
    : FormsService::tokenForShortLink((string)($_GET['c'] ?? ''), (string)($_GET['s'] ?? ''));

if ($token === null) {
    http_response_code(404);
}
$_GET['t'] = $token ?? 'not-found';   // f.php reads the form from ?t=

require __DIR__ . '/f.php';
