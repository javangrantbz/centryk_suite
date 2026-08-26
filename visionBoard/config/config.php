<?php
/**
 * VisionBoard (Centryk Signage) — configuration.
 *
 * This app is hosted inside Centryk. It no longer owns a database or a login:
 *   - data lives in centryk_core as vb_* tables (see database/add_signage_app.sql)
 *   - db() returns Centryk's shared PDO (centryk_core)
 *   - authentication + the session come from Centryk's Auth (see includes/auth.php)
 */

require_once __DIR__ . '/../../app/core/DB.php';   // Centryk PDO (centryk_core)

// ---- Application -------------------------------------------------------
define('APP_NAME', 'Vision Board');
define('UPLOAD_DIR', __DIR__ . '/../uploads');            // absolute path on disk
define('UPLOAD_URL', 'uploads');                          // web path (relative to app root)
define('MAX_UPLOAD_BYTES', 512 * 1024 * 1024);            // 512 MB per file
define('DEFAULT_DURATION', 12);                           // seconds for images / bios

// Allowed upload types: extension => [mime prefix, kind]
$GLOBALS['ALLOWED_TYPES'] = [
    'jpg'  => ['image/jpeg', 'image'],
    'jpeg' => ['image/jpeg', 'image'],
    'png'  => ['image/png',  'image'],
    'gif'  => ['image/gif',  'image'],
    'webp' => ['image/webp', 'image'],
    'mp4'  => ['video/mp4',  'video'],
    'webm' => ['video/webm', 'video'],
    'ogg'  => ['video/ogg',  'video'],
    'mov'  => ['video/quicktime', 'video'],
    'mp3'  => ['audio/mpeg', 'audio'],
    'm4a'  => ['audio/', 'audio'],
    'wav'  => ['audio/', 'audio'],
    'oga'  => ['audio/', 'audio'],
];

date_default_timezone_set('America/Belize');

/**
 * Shared database handle — Centryk's centryk_core PDO. Kept as db() so the rest
 * of the app is unchanged; all signage tables are vb_*-prefixed.
 */
function db(): PDO
{
    return DB::pdo();
}
