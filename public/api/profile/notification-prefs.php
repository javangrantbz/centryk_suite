<?php
/**
 * Save a single notification preference (Profile → Notifications).
 * POST JSON: { "pref_key": "...", "enabled": true|false }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$catalog = require __DIR__ . '/../../../app/config/notifications.php';

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$prefKey = trim($body['pref_key'] ?? '');
$enabled = !empty($body['enabled']) ? 1 : 0;

if ($prefKey === '' || !isset($catalog[$prefKey])) {
    Response::error('Unknown preference.');
}

DB::pdo()->prepare(
    'INSERT INTO user_notification_prefs (user_id, pref_key, enabled)
     VALUES (:uid, :key, :enabled)
     ON DUPLICATE KEY UPDATE enabled = :enabled2'
)->execute([
    'uid'      => (int)$user['id'],
    'key'      => $prefKey,
    'enabled'  => $enabled,
    'enabled2' => $enabled,
]);

Response::ok(['pref_key' => $prefKey, 'enabled' => (bool)$enabled]);
