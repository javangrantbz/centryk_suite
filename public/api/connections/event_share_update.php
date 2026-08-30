<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/ConnectionEventShareService.php';

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
$shareId = (int) ($body['share_id'] ?? 0);
$action = trim((string) ($body['action'] ?? ''));
if ($companyId <= 0 || $shareId <= 0 || $action === '') {
    Response::error('company_id, share_id, and action are required.');
}

$mStmt = DB::pdo()->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || ($member['role'] ?? '') !== 'admin') {
    Response::error('Only a company admin can update shared events.', 403);
}

if ($action === 'revoke') {
    $result = ConnectionEventShareService::revoke($shareId, $companyId);
} else {
    $mapped = $action === 'accept' ? 'accepted' : ($action === 'decline' ? 'declined' : '');
    if ($mapped === '') {
        Response::error('Invalid action.');
    }
    $result = ConnectionEventShareService::respond($shareId, $companyId, (int) $user['id'], $mapped);
}

if (empty($result['success'])) {
    Response::error((string) ($result['message'] ?? 'Could not update shared event.'));
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id' => $companyId,
    'event_type' => 'connection_event_share_updated',
    'summary' => 'Updated a shared event: ' . $action,
    'metadata' => ['share_id' => $shareId, 'action' => $action],
]);

Response::ok(['message' => (string) ($result['message'] ?? 'Shared event updated.')]);
