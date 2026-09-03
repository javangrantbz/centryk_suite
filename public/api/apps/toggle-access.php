<?php
/**
 * Grant or revoke a user's access to an app.
 * Caller must be an admin of at least one company the target user belongs to,
 * or a site admin.
 *
 * POST body: { "user_id": 5, "app_key": "onepay", "grant": true }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/AppAccess.php';

Auth::start();
$caller = Auth::user();
if (!$caller) {
    Response::error('Unauthorized.', 401);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = (int)($body['user_id'] ?? 0);
$appKey = trim($body['app_key'] ?? '');
$grant  = !empty($body['grant']);

if (!$userId || $appKey === '') {
    Response::error('user_id and app_key are required.');
}

$pdo = DB::pdo();

// Must be a site admin OR an admin of a company the target user is in
if (!$caller['is_admin']) {
    $check = $pdo->prepare('
        SELECT 1
        FROM company_members AS cm_caller
        JOIN company_members AS cm_target
            ON cm_target.company_id = cm_caller.company_id
           AND cm_target.user_id    = :target_uid
           AND cm_target.status     = "active"
        WHERE cm_caller.user_id = :caller_uid
          AND cm_caller.role    = "admin"
          AND cm_caller.status  = "active"
        LIMIT 1
    ');
    $check->execute(['caller_uid' => (int)$caller['id'], 'target_uid' => $userId]);
    if (!$check->fetch()) {
        Response::error('Access denied.', 403);
    }
}

$appStmt = $pdo->prepare('SELECT id FROM apps WHERE `key` = :key AND status = "active" LIMIT 1');
$appStmt->execute(['key' => $appKey]);
$app = $appStmt->fetch();
if (!$app) {
    Response::error('App not found.', 404);
}

if ($grant) {
    $pdo->prepare('INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)')
        ->execute(['uid' => $userId, 'aid' => (int)$app['id']]);
    AppAccess::markGranted($userId, $appKey, (int)$caller['id']);
} else {
    $pdo->prepare('DELETE FROM user_app_access WHERE user_id = :uid AND app_id = :aid')
        ->execute(['uid' => $userId, 'aid' => (int)$app['id']]);
}

Response::ok(['granted' => $grant, 'app_key' => $appKey]);
