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
$targetCompanyId = (int) ($body['target_company_id'] ?? 0);
if ($companyId <= 0 || $targetCompanyId <= 0) {
    Response::error('company_id and target_company_id are required.');
}

$mStmt = DB::pdo()->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || ($member['role'] ?? '') !== 'admin') {
    Response::error('Only a company admin can share events.', 403);
}

$result = ConnectionEventShareService::create($companyId, $targetCompanyId, (int) $user['id'], $body);
if (empty($result['success'])) {
    Response::error((string) ($result['message'] ?? 'Could not share event.'));
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id' => $companyId,
    'event_type' => 'connection_event_shared',
    'summary' => 'Shared an event with a connected company',
    'metadata' => ['target_company_id' => $targetCompanyId],
]);

Response::ok(['message' => (string) ($result['message'] ?? 'Shared event sent.')]);
