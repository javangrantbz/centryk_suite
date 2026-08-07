<?php
/**
 * Send a Centryk Connect request from one company to another.
 * POST { company_id, target_company_id }
 * Caller must be an admin of company_id.
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

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int) ($body['company_id'] ?? 0);
$targetId  = (int) ($body['target_company_id'] ?? 0);

if ($companyId <= 0 || $targetId <= 0) {
    Response::error('company_id and target_company_id are required.');
}

$pdo = DB::pdo();
$mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
$member = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$member || $member['role'] !== 'admin') {
    Response::error('Only a company admin can send a connect request.', 403);
}

$target = $pdo->prepare('SELECT id, name FROM companies WHERE id=? AND status="active"');
$target->execute([$targetId]);
$target = $target->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    Response::error('Company not found.', 404);
}

if (!Connections::sendRequest($companyId, $targetId)) {
    Response::error('A connection with this company already exists.');
}

Audit::log([
    'actor_user_id' => (int) $user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'connection_requested',
    'summary'       => 'Sent a connect request to ' . $target['name'],
]);

Response::ok(['message' => 'Connect request sent to ' . $target['name'] . '.']);
