<?php
/**
 * A user requests access to an app they aren't enrolled in (dashboard
 * "Available Through Your Organization"). Grants on the spot for a company
 * owner/admin; otherwise records a request and notifies their company's admins.
 *
 * POST { app_key } — session-authed.
 * Returns: { success, granted?:bool, requested?:bool }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/AppAccess.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$appKey = trim((string)($body['app_key'] ?? ''));
if ($appKey === '') {
    Response::error('app_key is required.', 422);
}

$res = AppAccess::request((int)$user['id'], $appKey);
if (empty($res['granted']) && empty($res['requested'])) {
    Response::error($res['message'] ?? 'Could not request access.', 422);
}

Response::ok($res);
