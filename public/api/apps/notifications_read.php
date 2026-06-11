<?php
/**
 * Server-to-server: mark all notifications read for a Centryk user (by email).
 * Called by apps in API mode. Requires PROVISION_SECRET.
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
if ($email === '') {
    Response::error('email is required.');
}

$pdo = DB::pdo();
$u = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$u->execute(['e' => $email]);
$uid = (int)($u->fetchColumn() ?: 0);

if (!$uid) {
    Response::ok(['updated' => 0, 'unread_count' => 0]);
}

$updated = NotificationService::markAllRead($uid);
Response::ok(['updated' => $updated, 'unread_count' => NotificationService::unreadCount($uid)]);
