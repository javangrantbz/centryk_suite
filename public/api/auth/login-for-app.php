<?php
/**
 * Centryk-powered login for standalone apps (OnePay, MyPay).
 * Validates credentials, issues a one-time SSO token, and returns the app redirect URL.
 *
 * POST body: { "email": "...", "password": "...", "app_key": "onepay" }
 */
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = strtolower(trim($body['email']    ?? ''));
$password = $body['password']  ?? '';
$appKey   = trim($body['app_key'] ?? '');

if ($email === '' || $password === '' || $appKey === '') {
    Response::error('email, password and app_key are required.');
}

// Validate credentials without starting a Centryk session
$pdo  = DB::pdo();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND status = "active" LIMIT 1');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
    Auth::recordLoginEvent($user['id'] ?? null, $email, false);
    Response::error('Invalid email or password.', 401);
}

Auth::recordLoginEvent((int)$user['id'], $email, true);

$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
    ->execute(['id' => $user['id']]);

$url = AuthService::launchApp((int)$user['id'], $appKey);

if (!$url) {
    Response::error('App not found or access denied.', 403);
}

// Issue a short-lived bridge token so the browser can pick up a Centryk session
// on the way through, without requiring a separate Centryk login.
$bridgeToken  = bin2hex(random_bytes(32));
$bridgeExpiry = date('Y-m-d H:i:s', time() + 90);
$pdo->prepare('
    INSERT INTO sso_tokens (user_id, token, app_key, redirect_url, expires_at)
    VALUES (:uid, :tok, "centryk_bridge", :rurl, :exp)
')->execute([
    'uid'  => (int)$user['id'],
    'tok'  => $bridgeToken,
    'rurl' => $url,
    'exp'  => $bridgeExpiry,
]);

Response::ok(['redirect_url' => $url, 'bridge_token' => $bridgeToken]);
