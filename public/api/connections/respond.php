<?php
/**
 * Accept or decline an incoming Centryk Connect request.
 * POST { company_id, connection_id, accept: bool }
 * Caller must be an admin of company_id, which must be the recipient.
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
$accept      = !empty($body['accept']);

if ($companyId <= 0 || $connectionId <= 0) {
    Response::error('company_id and connection_id are required.');
}

$pdo = DB::pdo();
$mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || $member['role'] !== 'admin') {
    Response::error('Only a company admin can respond to a connect request.', 403);
}

if (!Connections::respond($connectionId, $companyId, $accept)) {
    Response::error('That request is no longer available.', 404);
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id'    => $companyId,
    'event_type'    => $accept ? 'connection_accepted' : 'connection_declined',
    'summary'       => $accept ? 'Accepted a connect request' : 'Declined a connect request',
]);

Response::ok(['message' => $accept ? 'Connected.' : 'Request declined.']);
