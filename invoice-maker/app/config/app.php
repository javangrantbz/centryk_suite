<?php
// Invoices app config (integrated into Centryk).
define('APP_NAME', 'Invoices');

$__host  = $_SERVER['HTTP_HOST'] ?? '';
$__local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $__host) === 1;

// Centryk web root and the invoice app base, per environment.
define('CENTRYK_BASE', $__local ? '/centryk/public' : '');
define('BASE_URL', CENTRYK_BASE . '/invoice-maker');

// Filesystem path to the Centryk public/ dir (this file: invoice-maker/app/config/app.php).
// Used to resolve company logo paths stored relative to the public web root.
define('CENTRYK_PUBLIC_DIR', dirname(__DIR__, 3) . '/public');
