<?php
/**
 * Mint a one-time SSO token that logs the browser into Centryk itself, for
 * embedding Centryk's calendar.php in an iframe from another suite app
 * (MyPay, OnePay). Server-to-server only.
 *
 * POST { app_key: 'mypay'|'onepay', secret: '<shared secret for that app>', email }
 * Returns: { success, token }
 *
 * Same trust boundary as the existing provisioning endpoints (api/apps/user_status.php,
 * provision_user.php) — same shared secrets, just a new capability within that already-
 * established trust relationship. The token itself is one-time and expires in 60s
 * (Auth::issueToken), tagged with app_key 'calendar_embed' so it can only be redeemed
 * by calendar.php's embed-login path, not any other spoke flow.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$appKey = trim((string)($body['app_key'] ?? ''));
$secret = trim((string)($body['secret']  ?? ''));
$email  = strtolower(trim((string)($body['email'] ?? '')));

// Each app authenticates with the shared secret it already uses with Centryk
// (same map as api/calendar/upcoming.php).
$secrets = [
    'mypay'  => $_ENV['MYPAY_WEBHOOK_SECRET'] ?? '',
    'onepay' => $_ENV['PROVISION_SECRET']     ?? '',
];
$expected = $secrets[$appKey] ?? '';

if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('A valid email is required.');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND status = "active" LIMIT 1');
$stmt->execute(['email' => $email]);
$userId = (int)($stmt->fetchColumn() ?: 0);

if (!$userId) {
    Response::error('No active Centryk account for that email.', 404);
}

$token = Auth::issueToken($userId, 'calendar_embed');

Response::ok(['token' => $token]);
