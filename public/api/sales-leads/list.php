<?php
/**
 * List leads visible to the caller in a company (admin/manager: the whole
 * roster; anyone else: just what they added — see SalesLeadService::list()).
 * Body: { company_id, status?, due?, q? }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_member();

$filters = [
    'status' => (string)($in['status'] ?? ''),
    'due'    => !empty($in['due']),
    'q'      => (string)($in['q'] ?? ''),
];

Response::ok(['leads' => SalesLeadService::list($companyId, $userId, $role, $filters)]);
