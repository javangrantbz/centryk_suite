<?php
/**
 * Create or update a case service. Admin/manager only.
 * Body: { company_id, id?, name, category_id?, description?, estimated_turnaround_days?, is_active? }
 */
require_once __DIR__ . '/../../../app/core/cases_guard.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

[$userId, $companyId, $role, $in] = cases_guard_manager();

try {
    $id = CaseManagementService::saveService($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $id, 'services' => CaseManagementService::services($companyId, false)]);
