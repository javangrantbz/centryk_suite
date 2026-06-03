<?php
/**
 * Server-to-server endpoint: create a Centryk password reset for a user.
 * Called by OnePay (and potentially MyPay) when an admin uses "Resend
 * Invitation" for a team member who already has a Centryk account.
 *
 * Credentials live in centryk_core.users, so the reset must happen here —
 * resetting an individual app's local users table would not affect login.
 *
 * Requires the shared PROVISION_SECRET set in Centryk's .env.
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/PasswordResetService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim($body['provision_secret'] ?? '');
$email  = strtolower(trim($body['email'] ?? ''));
// Default to sending the email; OnePay passes send_email=false for
// @centryk.com placeholder accounts with no real inbox.
$send   = array_key_exists('send_email', $body) ? (bool)$body['send_email'] : true;

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('A valid email is required.');
}

try {
    $result = (new PasswordResetService())->createReset($email, $send);
} catch (Throwable $e) {
    Response::error('Could not create the reset link.', 500);
}

$created    = !empty($result['created']);
// 'sent' = relay accepted, 'logged' = driver != smtp, 'failed' = send error,
// 'skipped' = we deliberately didn't email (no inbox), 'no_account' = unknown email.
$mailStatus = $created ? ($result['mail_status'] ?? 'skipped') : 'no_account';
$sentOk     = $mailStatus === 'sent';

Response::ok([
    'created'     => $created,
    'mail_status' => $mailStatus,
    'email_sent'  => $sentOk,
    // Hand back the link whenever no real email reached the user, so the
    // caller can relay it (no inbox, log-mode, or a delivery failure).
    'reset_link'  => ($created && !$sentOk) ? ($result['reset_link'] ?? null) : null,
]);
