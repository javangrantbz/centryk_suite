<?php
/** Reorder a trip's stops. Body: { company_id, trip_id, stop_ids: [..] } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId  = (int)($in['trip_id'] ?? 0);
$stopIds = is_array($in['stop_ids'] ?? null) ? $in['stop_ids'] : [];
if ($tripId <= 0 || !$stopIds) {
    Response::error('trip_id and stop_ids are required.', 422);
}

try {
    RoutesService::reorderStops($companyId, $tripId, $stopIds, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
