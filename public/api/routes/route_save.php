<?php
/** Create or update a route. Body: { company_id, id?, name, notes?, default_driver_name? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

try {
    $id = RoutesService::saveRoute($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['route_id' => $id]);
