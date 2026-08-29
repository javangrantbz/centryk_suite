<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Entitlements.php';
require_once __DIR__ . '/../services/RoutesService.php';

/**
 * Gate for the phone-first field view of Routes. Unlike business_guard(), a
 * plain 'employee' member passes — but only for a trip they are the assigned
 * driver on (admins/managers pass for any trip). The company must hold the
 * 'routes' package at FULL.
 *
 * POST + JSON body carrying company_id and trip_id.
 *
 * @return array{0:int,1:int,2:int,3:array}  [userId, companyId, tripId, body]
 */
function routes_field_guard(): array
{
    Auth::start();
    $user = Auth::user();
    if (!$user) {
        Response::error('Unauthorized.', 401);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Response::error('Method not allowed', 405);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        $in = $_POST;
    }

    $companyId = (int)($in['company_id'] ?? 0);
    $tripId    = (int)($in['trip_id'] ?? 0);
    if ($companyId <= 0 || $tripId <= 0) {
        Response::error('company_id and trip_id are required.', 422);
    }
    $userId = (int)$user['id'];

    $m = DB::pdo()->prepare("
        SELECT 1 FROM company_members
        WHERE user_id = :uid AND company_id = :cid AND status = 'active' LIMIT 1
    ");
    $m->execute(['uid' => $userId, 'cid' => $companyId]);
    if (!$m->fetchColumn()) {
        Response::error('You are not a member of this company.', 403);
    }

    if (Entitlements::level($companyId, 'routes') !== Entitlements::FULL) {
        Response::error('Routes is not active for this company.', 402, ['entitlement' => 'routes']);
    }

    if (!RoutesService::userCanRunTrip($companyId, $tripId, $userId)) {
        Response::error('This run is not assigned to you.', 403);
    }

    return [$userId, $companyId, $tripId, $in];
}
