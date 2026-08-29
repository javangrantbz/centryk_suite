<?php
/** Record a stop from the field view. POST { company_id, trip_id, stop_id, status?, amount_collected?, method?, note? } */
require_once __DIR__ . '/../../../app/core/routes_field_guard.php';

[$userId, $companyId, $tripId, $in] = routes_field_guard();

$stopId = (int)($in['stop_id'] ?? 0);
if ($stopId <= 0) {
    Response::error('stop_id is required.', 422);
}

// The stop must belong to this trip.
$chk = DB::pdo()->prepare("SELECT 1 FROM route_stops WHERE id = :s AND trip_id = :t AND company_id = :c LIMIT 1");
$chk->execute(['s' => $stopId, 't' => $tripId, 'c' => $companyId]);
if (!$chk->fetchColumn()) {
    Response::error('That stop is not on this run.', 404);
}

try {
    RoutesService::recordStop($companyId, $stopId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not save the stop.', 500);
}

Response::ok(['trip' => RoutesService::trip($companyId, $tripId)]);
