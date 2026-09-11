<?php
/**
 * Change a case's status. Allowed for the company's admin/manager, or the
 * case's own requester/assignee (matches CaseManagementService::getCase()'s
 * visibility rule) — so whoever is doing the work can resolve it, not just
 * management.
 * Body: { company_id, id, status, note? }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_member();

$caseId = (int)($in['id'] ?? 0);
$case = CaseManagementService::getCase($caseId, $companyId, $userId, $role);
if (!$case) {
    Response::error('Case not found.', 404);
}

try {
    CaseManagementService::changeStatus($caseId, $companyId, $userId, (string)($in['status'] ?? ''), (string)($in['note'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['case' => CaseManagementService::getCase($caseId, $companyId, $userId, $role)]);
