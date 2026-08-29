<?php
/** Routes dashboard: summary + routes + trips. Body: { company_id, status?, route_id? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[, $companyId, $in] = business_guard('routes', false);

Response::ok([
    'summary' => RoutesService::summary($companyId),
    'routes'  => RoutesService::routes($companyId),
    'members' => RoutesService::companyMembers($companyId),
    'trips'   => RoutesService::trips($companyId, [
        'status'   => $in['status'] ?? 'open',
        'route_id' => (int)($in['route_id'] ?? 0),
    ]),
]);
