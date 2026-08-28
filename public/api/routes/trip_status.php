<?php
/** Advance a trip. Body: { company_id, trip_id, status: out|settling|planned } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId = (int)($in['trip_id'] ?? 0);
$status = (string)($in['status'] ?? '');
if ($tripId <= 0 || $status === '') {
    Response::error('trip_id and status are required.', 422);
}

try {
    RoutesService::setTripStatus($companyId, $tripId, $status, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['status' => $status]);
