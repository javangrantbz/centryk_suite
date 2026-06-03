<?php
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/PasswordResetService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$token   = trim($body['token'] ?? '');
$pw      = (string)($body['password'] ?? '');
$confirm = (string)($body['confirm_password'] ?? '');

if ($token === '') {
    Response::error('Missing reset token.');
}
if ($pw === '' || $confirm === '') {
    Response::error('All fields are required.');
}
if ($pw !== $confirm) {
    Response::error('Passwords do not match.');
}

try {
    (new PasswordResetService())->reset($token, $pw);
    Response::ok(['message' => 'Password updated. You can now sign in.']);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    Response::error('Could not reset your password. Please try again.');
}
