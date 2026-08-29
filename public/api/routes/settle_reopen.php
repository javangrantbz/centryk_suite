<?php
/** Reopen a submitted or settled trip (admin only). Body: { company_id, trip_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId = (int)($in['trip_id'] ?? 0);
if ($tripId <= 0) {
    Response::error('trip_id is required.', 422);
}

try {
    RoutesService::reopenSettlement($companyId, $tripId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 403);
}

Response::ok([]);
