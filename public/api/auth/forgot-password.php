<?php
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/PasswordResetService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim($body['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Please enter a valid email address.');
}

try {
    (new PasswordResetService())->createReset($email);
} catch (Throwable $e) {
    // Never reveal failures or whether the email exists.
}

// Always a generic success response.
Response::ok(['message' => 'If an account exists for that email, a reset link has been sent.']);
