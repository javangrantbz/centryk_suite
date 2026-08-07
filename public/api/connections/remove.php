<?php
/**
 * Cancel a pending outgoing request or withdraw an accepted connection.
 * POST { company_id, connection_id }
 * Caller must be an admin of company_id, on either side of the connection.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Connections.php';
require_once __DIR__ . '/../../../app/core/Audit.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId   = (int) ($body['company_id'] ?? 0);
$connectionId = (int) ($body['connection_id'] ?? 0);

if ($companyId <= 0 || $connectionId <= 0) {
    Response::error('company_id and connection_id are required.');
}

$pdo = DB::pdo();
$mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || $member['role'] !== 'admin') {
    Response::error('Only a company admin can manage connections.', 403);
}

if (!Connections::remove($connectionId, $companyId)) {
    Response::error('That connection was not found.', 404);
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'connection_removed',
    'summary'       => 'Removed a connection',
]);

Response::ok(['message' => 'Removed.']);
