<?php
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    Response::error('Email and password are required.');
}

$user = Auth::attempt($email, $password);

if (!$user) {
    Response::error('Invalid email or password.', 401);
}

$apps = AuthService::accessibleApps((int)$user['id']);

Response::ok([
    'user' => $user,
    'apps' => $apps,
]);
