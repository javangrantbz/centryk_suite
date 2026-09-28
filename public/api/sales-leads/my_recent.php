<?php
/**
 * The caller's own most-recently-added leads (mobile entry-page confirmation list).
 * Body: { company_id }
 */
require_once __DIR__ . '/../../../app/core/sales_leads_guard.php';
require_once __DIR__ . '/../../../app/services/SalesLeadService.php';

[$userId, $companyId, $role, $in] = sales_leads_guard_member();

Response::ok(['leads' => SalesLeadService::recentByAgent($companyId, $userId)]);
