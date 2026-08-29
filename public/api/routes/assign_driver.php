<?php
/** Assign or clear the driver on a trip. Body: { company_id, trip_id, driver_user_id|null } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId = (int)($in['trip_id'] ?? 0);
if ($tripId <= 0) {
    Response::error('trip_id is required.', 422);
}
$driverUserId = isset($in['driver_user_id']) && $in['driver_user_id'] !== null && $in['driver_user_id'] !== ''
    ? (int)$in['driver_user_id'] : null;

try {
    RoutesService::assignDriver($companyId, $tripId, $driverUserId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['trip' => RoutesService::trip($companyId, $tripId)]);
