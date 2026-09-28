<?php
/**
 * A single lead plus its follow-up timeline (visibility per SalesLeadService::get()).
 * Body: { company_id, id }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_member();

$id = (int)($in['id'] ?? 0);
$lead = $id > 0 ? SalesLeadService::get($id, $companyId, $userId, $role) : null;
if (!$lead) {
    Response::error('Lead not found.', 404);
}

Response::ok([
    'lead'       => $lead,
    'follow_ups' => SalesLeadService::followUps($id),
]);
