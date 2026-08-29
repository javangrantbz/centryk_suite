<?php
/** Commission rules + a per-driver statement. Body: { company_id, from?, to? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[, $companyId, $in] = business_guard('routes', false);

Response::ok([
    'rules'     => RoutesService::commissionRules($companyId),
    'statement' => RoutesService::commissionStatement($companyId, $in['from'] ?? null, $in['to'] ?? null),
]);
