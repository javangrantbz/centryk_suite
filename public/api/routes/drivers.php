<?php
/** Per-driver performance over a window. Body: { company_id, days? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[, $companyId, $in] = business_guard('routes', false);

$days = (int)($in['days'] ?? 30);
Response::ok(RoutesService::driverPerformance($companyId, $days));
