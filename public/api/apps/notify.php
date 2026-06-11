<?php
/**
 * Server-to-server: create a notification for a Centryk user (resolved by email).
 * Called by apps (MyPay, OnePay, ...) in API mode. Requires PROVISION_SECRET.
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/NotificationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim($body['provision_secret'] ?? '');

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

$email = strtolower(trim($body['email'] ?? ''));
$title = trim($body['title'] ?? '');
if ($email === '' || $title === '') {
    Response::error('email and title are required.');
}

$pdo = DB::pdo();
$u = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$u->execute(['e' => $email]);
$uid = (int)($u->fetchColumn() ?: 0);

if (!$uid) {
    // Unknown recipient — succeed quietly so the calling action isn't disrupted.
    Response::ok(['created' => false, 'id' => 0]);
}

$id = NotificationService::create([
    'user_id'    => $uid,
    'company_id' => $body['company_id'] ?? null,
    'app_key'    => $body['app_key'] ?? 'centryk',
    'type'       => $body['type'] ?? 'general',
    'title'      => $title,
    'body'       => $body['body'] ?? '',
    'url'        => $body['url'] ?? '',
    'icon'       => $body['icon'] ?? '',
    'color'      => $body['color'] ?? '',
]);

Response::ok(['created' => true, 'id' => $id]);
