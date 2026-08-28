<?php
/** One trip with its stops. Body: { company_id, trip_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[, $companyId, $in] = business_guard('routes', false);

$tripId = (int)($in['trip_id'] ?? 0);
if ($tripId <= 0) {
    Response::error('trip_id is required.', 422);
}

$trip = RoutesService::trip($companyId, $tripId);
if ($trip === null) {
    Response::error('Trip not found.', 404);
}

Response::ok(['trip' => $trip]);
