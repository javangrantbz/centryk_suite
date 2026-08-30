<?php
/**
 * Update an accepted Centryk Connect relationship profile and scopes.
 * POST { company_id, connection_id, relationship_type, relationship_note, can_share_signage, can_share_events, can_share_campaigns, can_request_assets, can_message_admins }
 * Caller must be an admin of company_id and part of the accepted connection.
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int) ($body['company_id'] ?? 0);
$connectionId = (int) ($body['connection_id'] ?? 0);

if ($companyId <= 0 || $connectionId <= 0) {
    Response::error('company_id and connection_id are required.');
}

$pdo = DB::pdo();
$mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || $member['role'] !== 'admin') {
    Response::error('Only a company admin can manage connection settings.', 403);
}

$connection = Connections::getByIdForCompany($connectionId, $companyId);
if (!$connection || ($connection['status'] ?? '') !== 'accepted') {
    Response::error('That connection is not available.', 404);
}

$saved = Connections::updateProfile($connectionId, $companyId, [
    'relationship_type' => $body['relationship_type'] ?? 'partner',
    'relationship_note' => $body['relationship_note'] ?? '',
    'can_share_signage' => !empty($body['can_share_signage']),
    'can_share_events' => !empty($body['can_share_events']),
    'can_share_campaigns' => !empty($body['can_share_campaigns']),
    'can_request_assets' => !empty($body['can_request_assets']),
    'can_message_admins' => !empty($body['can_message_admins']),
]);

if (!$saved) {
    Response::error('No connection changes were saved.');
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id' => $companyId,
    'event_type' => 'connection_updated',
    'summary' => 'Updated connection settings for ' . ($connection['other_company_name'] ?? 'company'),
    'metadata' => [
        'connection_id' => $connectionId,
        'other_company_id' => (int) ($connection['other_company_id'] ?? 0),
    ],
]);

Response::ok(['message' => 'Connection settings saved.']);
