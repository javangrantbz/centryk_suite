<?php
/** Submit end-of-day cash from the field view. POST { company_id, trip_id, cash_declared, notes? }
 *  The trip stays 'settling' until a company admin approves it. */
require_once __DIR__ . '/../../../app/core/routes_field_guard.php';

[$userId, $companyId, $tripId, $in] = routes_field_guard();

$declared = round((float)($in['cash_declared'] ?? 0), 2);
$notes    = (string)($in['notes'] ?? '');

try {
    $res = RoutesService::submitSettlement($companyId, $tripId, $declared, $notes, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok($res + ['trip' => RoutesService::trip($companyId, $tripId)]);
