<?php
/**
 * Open a new case.
 * Body: { company_id, subject, description?, priority?, category_id?, service_id?, due_date? }
 * Returns: { id }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_member();

try {
    $id = CaseManagementService::createCase($companyId, $userId, $in);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $id]);
