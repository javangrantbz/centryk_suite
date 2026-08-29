<?php
/** One trip for the field view. POST { company_id, trip_id } */
require_once __DIR__ . '/../../../app/core/routes_field_guard.php';

[$userId, $companyId, $tripId, $in] = routes_field_guard();

$trip = RoutesService::trip($companyId, $tripId);
if (!$trip) {
    Response::error('Run not found.', 404);
}
Response::ok(['trip' => $trip]);
