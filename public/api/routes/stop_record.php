<?php
/** Record a stop outcome. Body: { company_id, stop_id, status?, amount_collected?, method?, note? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$stopId = (int)($in['stop_id'] ?? 0);
if ($stopId <= 0) {
    Response::error('stop_id is required.', 422);
}

try {
    RoutesService::recordStop($companyId, $stopId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not record the stop.', 500);
}

Response::ok([]);
