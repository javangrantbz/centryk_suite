<?php
/**
 * Record a sales lead. Any active company member (e.g. a sales agent).
 * Body: { company_id, business_name, contact_name?, phone?, email?, address?, order_details?, order_value? }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_member();

try {
    $id = SalesLeadService::create($companyId, $userId, $in);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $id]);
