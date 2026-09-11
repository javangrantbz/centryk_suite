<?php
/**
 * Create or update a case category. Admin/manager only.
 * Body: { company_id, id?, name, is_active? }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_manager();

try {
    $id = CaseManagementService::saveCategory($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $id, 'categories' => CaseManagementService::categories($companyId, false)]);
