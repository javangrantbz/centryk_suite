<?php
/**
 * Insights data for one company. GET ?company_id=X&days=30 (7|30|90|365).
 * Caller must be an owner/admin/manager of the company (or a platform admin);
 * widgets are limited to the apps the caller is enrolled in.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/InsightsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int)($_GET['company_id'] ?? 0);
$days = (int)($_GET['days'] ?? 30);

$access = InsightsService::access($user, $companyId);
if (!$access) {
    Response::error('Insights are for company owners, admins and managers.', 403);
}

$company = ['id' => $companyId, 'name' => $access['name'], 'uuid' => $access['uuid']];
Response::ok(['company' => ['id' => $companyId, 'name' => $access['name'], 'role' => $access['role']]] + InsightsService::build($user, $company, $days));
