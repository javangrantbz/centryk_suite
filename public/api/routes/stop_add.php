<?php
/** Add a stop to a trip. Body: { company_id, trip_id, customer_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId     = (int)($in['trip_id'] ?? 0);
$customerId = (int)($in['customer_id'] ?? 0);
if ($tripId <= 0 || $customerId <= 0) {
    Response::error('trip_id and customer_id are required.', 422);
}

try {
    $stopId = RoutesService::addStop($companyId, $tripId, $customerId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['stop_id' => $stopId]);
