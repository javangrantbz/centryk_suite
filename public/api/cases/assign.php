<?php
/**
 * Assign (or unassign) a case. Admin/manager only.
 * Body: { company_id, id, assignee_user_id }  (0 or omitted = unassign)
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_manager();

$caseId = (int)($in['id'] ?? 0);
$assigneeId = !empty($in['assignee_user_id']) ? (int)$in['assignee_user_id'] : null;

try {
    CaseManagementService::assign($caseId, $companyId, $userId, $assigneeId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['case' => CaseManagementService::getCase($caseId, $companyId, $userId, $role)]);
