<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/ConnectionRequestService.php';

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
$requestId = (int) ($body['request_id'] ?? 0);
$status = trim((string) ($body['status'] ?? ''));

if ($companyId <= 0 || $requestId <= 0 || $status === '') {
    Response::error('company_id, request_id, and status are required.');
}

$mStmt = DB::pdo()->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || ($member['role'] ?? '') !== 'admin') {
    Response::error('Only a company admin can update partner requests.', 403);
}

$result = ConnectionRequestService::respond($requestId, $companyId, (int) $user['id'], $status);
if (empty($result['success'])) {
    Response::error((string) ($result['message'] ?? 'Could not update partner request.'));
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id' => $companyId,
    'event_type' => 'connection_request_updated',
    'summary' => 'Updated a partner request to ' . $status,
    'metadata' => [
        'request_id' => $requestId,
        'status' => $status,
    ],
]);

Response::ok(['message' => (string) ($result['message'] ?? 'Partner request updated.')]);
