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

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$firstName = trim($body['first_name'] ?? '');
$lastName  = trim($body['last_name']  ?? '');
$phone     = trim($body['phone']      ?? '');

if ($firstName === '' || $lastName === '') {
    Response::error('First and last name are required.');
}
if (strlen($firstName) > 50 || strlen($lastName) > 50) {
    Response::error('Name must be 50 characters or fewer.');
}
if (strlen($phone) > 30) {
    Response::error('Phone number is too long.');
}

DB::pdo()
    ->prepare('UPDATE users SET first_name = :fn, last_name = :ln, phone = :ph WHERE id = :id')
    ->execute(['fn' => $firstName, 'ln' => $lastName, 'ph' => $phone ?: null, 'id' => $user['id']]);

Audit::log([
    'actor_user_id' => $user['id'],
    'target_user_id' => $user['id'],
    'event_type' => 'profile.updated',
    'summary' => 'Updated profile details',
    'metadata' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone ?: null,
    ],
]);

Response::ok([
    'message'    => 'Profile updated successfully.',
    'first_name' => $firstName,
    'last_name'  => $lastName,
    'full_name'  => $firstName . ' ' . $lastName,
]);
