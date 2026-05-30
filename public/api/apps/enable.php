<?php
/**
 * Self-enable an opt-in app for the signed-in user.
 *
 * POST body: { "app_key": "calendar" }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';

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
    Response::error('app_key is required.');
}

$ok = AuthService::enableApp((int)$user['id'], $appKey);
if (!$ok) {
    Response::error('That app cannot be self-enabled.', 422);
}

Response::ok(['enabled' => true, 'app_key' => $appKey]);
