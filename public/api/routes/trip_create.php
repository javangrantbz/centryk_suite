<?php
/** Start a trip on a route. Body: { company_id, route_id, trip_date?, driver_name? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$routeId = (int)($in['route_id'] ?? 0);
if ($routeId <= 0) {
    Response::error('route_id is required.', 422);
}

try {
    $tripId = RoutesService::createTrip(
        $companyId,
        $routeId,
        (string)($in['trip_date'] ?? date('Y-m-d')),
        (string)($in['driver_name'] ?? ''),
        $userId
    );
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['trip_id' => $tripId]);
