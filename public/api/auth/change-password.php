<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body           = json_decode(file_get_contents('php://input'), true) ?? [];
$currentPw      = $body['current_password']  ?? '';
$newPw          = $body['new_password']      ?? '';
$confirmPw      = $body['confirm_password']  ?? '';

if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
    Response::error('All fields are required.');
}
if (strlen($newPw) < 8) {
    Response::error('New password must be at least 8 characters.');
}
if ($newPw !== $confirmPw) {
    Response::error('Passwords do not match.');
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND status = "active" LIMIT 1');
$stmt->execute(['id' => $user['id']]);
$row  = $stmt->fetch();

if (!$row || !password_verify($currentPw, (string)($row['password_hash'] ?? ''))) {
    Response::error('Current password is incorrect.', 401);
}

$pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
    ->execute(['hash' => password_hash($newPw, PASSWORD_BCRYPT), 'id' => $user['id']]);

Audit::log([
    'actor_user_id' => $user['id'],
    'target_user_id' => $user['id'],
    'event_type' => 'auth.password.changed',
    'summary' => 'Changed account password',
]);

Response::ok(['message' => 'Password updated successfully.']);
