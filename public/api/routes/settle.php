<?php
/** Settle a trip. Body: { company_id, trip_id, cash_declared, notes? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$tripId = (int)($in['trip_id'] ?? 0);
if ($tripId <= 0 || !isset($in['cash_declared']) || !is_numeric($in['cash_declared'])) {
    Response::error('trip_id and cash_declared are required.', 422);
}

try {
    $result = RoutesService::settleTrip($companyId, $tripId, (float)$in['cash_declared'], (string)($in['notes'] ?? ''), $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok($result);
