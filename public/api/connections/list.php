<?php
/**
 * List a company's Centryk Connect relationships.
 * GET ?company_id=X -> { incoming: [...], outgoing: [...], connected: [...] }
 * Caller must be an active member of the company.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Connections.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.');
}

$mStmt = DB::pdo()->prepare('SELECT role FROM company_members WHERE user_id=? AND company_id=? AND status="active" LIMIT 1');
$mStmt->execute([(int) $user['id'], $companyId]);
if (!$mStmt->fetch()) {
    Response::error('Not a member of this company.', 403);
}

Response::ok([
    'incoming'  => Connections::incomingPending($companyId),
    'outgoing'  => Connections::outgoingPending($companyId),
    'connected' => Connections::listConnected($companyId),
]);
