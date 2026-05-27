<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';

$admin = require_admin();

$body   = json_decode(file_get_contents('php://input'), true);
$id     = (int)($body['id'] ?? 0);
$status = (string)($body['status'] ?? '');
$notes  = trim((string)($body['notes'] ?? ''));

if (!$id || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

DB::pdo()->prepare("
    UPDATE account_requests
    SET status = :status, notes = :notes, reviewed_at = NOW(), reviewed_by = :reviewed_by
    WHERE id = :id
")->execute([
    'status'      => $status,
    'notes'       => $notes ?: null,
    'reviewed_by' => $admin['id'],
    'id'          => $id,
]);

Audit::log([
    'actor_user_id' => $admin['id'],
    'event_type' => 'request.status.updated',
    'summary' => 'Updated account request #' . $id . ' to ' . $status,
    'metadata' => [
        'request_id' => $id,
        'status' => $status,
        'notes' => $notes ?: null,
    ],
]);

echo json_encode(['ok' => true]);
