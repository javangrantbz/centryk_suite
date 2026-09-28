<?php
/**
 * Log a follow-up on a lead and/or change status / next-follow-up date.
 * Admin/manager only. Body: { company_id, id, note?, status?, next_follow_up_date? }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_manager();

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}

try {
    $followUpId = SalesLeadService::addFollowUp($id, $companyId, $userId, $in);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $followUpId]);
