<?php
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim((string)($body['provision_secret'] ?? ''));
$email  = strtolower(trim((string)($body['email'] ?? '')));

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

if ($email === '') {
    Response::ok(['has_email' => false, 'exists' => false, 'email' => '']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Invalid email address.');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('
    SELECT id, first_name, last_name, email, status
    FROM users
    WHERE email = :email
    LIMIT 1
');
$stmt->execute(['email' => $email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

Response::ok([
    'has_email' => true,
    'exists' => (bool)$row,
    'user_id' => $row ? (int)$row['id'] : null,
    'email' => $row['email'] ?? $email,
    'first_name' => $row['first_name'] ?? null,
    'last_name' => $row['last_name'] ?? null,
    'status' => $row['status'] ?? null,
]);
