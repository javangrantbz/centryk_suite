<?php
/**
 * SSO token verification endpoint.
 * Called by OnePay / MyPay after receiving an sso_token redirect from Centryk.
 *
 * POST body: { "token": "...", "app_key": "onepay" }
 * Returns the user record on success (one-time use, 60-second window).
 */
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$token  = trim($body['token']   ?? '');
$appKey = trim($body['app_key'] ?? '');

if ($token === '' || $appKey === '') {
    Response::error('token and app_key are required.');
}

$user = Auth::consumeToken($token, $appKey);

if (!$user) {
    Response::error('Invalid or expired SSO token.', 401);
}

Response::ok(['user' => $user]);
