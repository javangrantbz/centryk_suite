<?php
/**
 * Issues an SSO token and returns the redirect URL for the requested app.
 * Called when the user clicks an app tile on the Centryk dashboard.
 *
 * POST body: { "app_key": "onepay" }
 */
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::user();
if (!$user) {
    Response::error('Unauthenticated.', 401);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$appKey      = trim($body['app_key']      ?? '');
$companyUuid = trim($body['company_uuid'] ?? '');

if ($appKey === '') {
    Response::error('app_key is required.');
}

$url = AuthService::launchApp((int)$user['id'], $appKey, $companyUuid);

if (!$url) {
    Response::error('App not found or access denied.', 403);
}

Response::ok(['redirect_url' => $url]);
