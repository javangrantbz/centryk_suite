<?php
/**
 * List cases visible to the caller in a company.
 * Body: { company_id, status?, priority?, mine?, include_closed? }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_member();

$filters = [
    'status'         => (string)($in['status'] ?? ''),
    'priority'       => (string)($in['priority'] ?? ''),
    'mine'           => !empty($in['mine']),
    'include_closed' => !empty($in['include_closed']),
];

Response::ok(['cases' => CaseManagementService::listCases($companyId, $userId, $role, $filters)]);
