<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/ConnectionMessageService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int) ($body['company_id'] ?? 0);
$connectionId = (int) ($body['connection_id'] ?? 0);
if ($companyId <= 0 || $connectionId <= 0) {
    Response::error('company_id and connection_id are required.');
}

$mStmt = DB::pdo()->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
if (!$mStmt->fetch(PDO::FETCH_ASSOC)) {
    Response::error('Not a member of this company.', 403);
}

$marked = ConnectionMessageService::markConnectionRead($companyId, (int) $user['id'], $connectionId);

Response::ok(['message' => 'Marked thread as read.', 'marked' => $marked]);
