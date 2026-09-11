<?php
/**
 * Add a comment to a case. Anyone who can see the case can comment;
 * "internal" notes require admin/manager.
 * Body: { company_id, id, body, internal? }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_member();

$caseId = (int)($in['id'] ?? 0);
$case = CaseManagementService::getCase($caseId, $companyId, $userId, $role);
if (!$case) {
    Response::error('Case not found.', 404);
}

$internal = !empty($in['internal']) && in_array($role, ['admin', 'manager'], true);

try {
    CaseManagementService::addComment($caseId, $companyId, $userId, (string)($in['body'] ?? ''), $internal);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['events' => CaseManagementService::events($caseId, in_array($role, ['admin', 'manager'], true))]);
