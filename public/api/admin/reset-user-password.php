<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/PasswordResetService.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$userId = (int)($in['user_id'] ?? 0);
$sendEmail = !empty($in['send_email']);
if (!$userId) {
    Response::error('user_id is required.', 422);
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT id, email, first_name, last_name, status FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    Response::error('User not found.', 404);
}
if ($target['status'] !== 'active') {
    Response::error('This account is inactive — reactivate it before resetting the password.', 422);
}

$result = (new PasswordResetService())->createReset($target['email'], $sendEmail);

// PasswordResetService::createReset() attributes its own audit entry to the
// target user (it assumes self-service). Log a second, admin-attributed
// event here so it's clear in the audit trail that this was admin-triggered,
// not the user requesting their own reset.
try {
    Audit::log([
        'actor_user_id'  => (int)$admin['id'],
        'target_user_id' => (int)$target['id'],
        'event_type'     => 'admin.password.reset_triggered',
        'summary'        => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            . ' reset the password for ' . trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? '')),
        'metadata'       => ['sent_email' => $sendEmail, 'mail_status' => $result['mail_status'] ?? null],
    ]);
} catch (Throwable $e) { /* audit is best-effort */ }

if (!$result['created']) {
    Response::error('Could not start a password reset for this account.', 500);
}

Response::ok([
    'sent_email'  => $sendEmail,
    'mail_status' => $result['mail_status'] ?? null,
    'reset_link'  => $sendEmail ? null : ($result['reset_link'] ?? null),
]);
