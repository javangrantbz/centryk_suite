<?php
/**
 * Router for /vb/<slug> short links (see .htaccess). Resolves the slug to
 * a screen's pairing token and hands off to the normal display player so
 * the not-paired fallback stays in one place.
 */
require_once __DIR__ . '/includes/functions.php';

$screen = vb_screen_by_slug($_GET['slug'] ?? '');
$_GET['screen'] = $screen['pair_token'] ?? '';

require __DIR__ . '/display/index.php';
