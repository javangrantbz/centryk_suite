<?php
/**
 * Auto-provision a OneLink merchant account for one company (admin-only).
 * POST { company_id }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OneLinkProvisioning.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}
if (empty($user['is_admin'])) {
    Response::error('Only a Centryk administrator can provision OneLink accounts.', 403);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$companyId = isset($in['company_id']) ? (int)$in['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();
$result = OneLinkProvisioning::provision($pdo, $companyId);

if (empty($result['success'])) {
    Response::error((string)($result['message'] ?? 'Could not provision OneLink account.'), 422);
}

Response::ok($result);
