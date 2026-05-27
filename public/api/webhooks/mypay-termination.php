<?php
/**
 * Inbound webhook called by MyPay when an employee is terminated.
 *
 * Expected request:
 *   POST /api/webhooks/mypay-termination.php
 *   Header: X-MyPay-Signature: sha256=<hmac-sha256 of raw body using MYPAY_WEBHOOK_SECRET>
 *   Body:   { "event": "employee.terminated", "email": "user@example.com" }
 *
 * Behaviour:
 *   - Looks up the Centryk account for that email.
 *   - If the user has no remaining active company memberships, deactivates their account.
 *   - If they still belong to other companies, leaves them active.
 */

require_once __DIR__ . '/../../../app/core/Env.php';
Env::load(__DIR__ . '/../../../.env');

require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/Response.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$secret  = $_ENV['MYPAY_WEBHOOK_SECRET'] ?? '';

// Validate HMAC signature when a secret is configured
if ($secret !== '') {
    $expected  = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    $signature = $_SERVER['HTTP_X_MYPAY_SIGNATURE'] ?? '';
    if (!hash_equals($expected, $signature)) {
        Response::error('Unauthorized', 401);
    }
}

$body  = json_decode($rawBody, true) ?? [];
$event = $body['event'] ?? '';
$email = strtolower(trim($body['email'] ?? ''));

if ($event !== 'employee.terminated' || $email === '') {
    Response::error('Invalid payload.', 400);
}

$pdo = DB::pdo();

$userStmt = $pdo->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1');
$userStmt->execute(['email' => $email]);
$centrkUser = $userStmt->fetch();

if (!$centrkUser) {
    Response::ok(['message' => 'No Centryk account found for this email.']);
}

$userId = (int)$centrkUser['id'];

// Check for remaining active company memberships
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM company_members WHERE user_id = :uid AND status = "active"');
$countStmt->execute(['uid' => $userId]);
$activeMemberships = (int)$countStmt->fetchColumn();

if ($activeMemberships > 0) {
    Response::ok(['message' => 'User has ' . $activeMemberships . ' active membership(s) — not deactivated.']);
}

// No active memberships remain — deactivate the Centryk account
$pdo->prepare('UPDATE users SET status = "inactive" WHERE id = :id')
    ->execute(['id' => $userId]);

Audit::log([
    'actor_user_id'  => null,
    'target_user_id' => $userId,
    'event_type'     => 'user.deactivated.mypay_webhook',
    'summary'        => 'Auto-deactivated via MyPay termination webhook: ' . $email,
    'metadata'       => ['email' => $email, 'event' => $event],
]);

Response::ok(['message' => 'User deactivated.']);
