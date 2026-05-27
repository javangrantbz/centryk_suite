<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';

$admin = require_admin();

$body   = json_decode(file_get_contents('php://input'), true);
$userId = (int)($body['user_id'] ?? 0);
$status = $body['status'] ?? '';

if (!$userId || !in_array($status, ['active', 'inactive'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

DB::pdo()->prepare('UPDATE users SET status = :status WHERE id = :id')
    ->execute(['status' => $status, 'id' => $userId]);

Audit::log([
    'actor_user_id' => $admin['id'],
    'target_user_id' => $userId,
    'event_type' => 'user.status.changed',
    'summary' => 'Changed user status to ' . $status,
    'metadata' => [
        'status' => $status,
    ],
]);

echo json_encode(['ok' => true]);
