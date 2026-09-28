<?php
/**
 * Convert a lead into an invoice-maker client (customers table). Idempotent —
 * converting twice returns the same customer id. Admin/manager only.
 * Body: { company_id, id }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_manager();

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}

try {
    $customerId = SalesLeadService::convertToClient($id, $companyId, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['customer_id' => $customerId]);
