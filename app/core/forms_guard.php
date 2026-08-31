<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';

/**
 * Gate for Centryk Forms builder endpoints (the app is free-core, so no
 * entitlement check — just auth + company admin/manager membership).
 *
 *   - authenticated
 *   - POST with a JSON body carrying company_id
 *   - caller is an active admin/manager of that company
 *
 * The public response endpoint (api/forms/submit.php) does NOT use this.
 *
 * @return array{0:int,1:int,2:array}  [userId, companyId, decodedBody]
 */
function forms_guard(): array
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
    if ($companyId <= 0) {
        Response::error('company_id is required.', 422);
    }

    $m = DB::pdo()->prepare("
        SELECT role FROM company_members
        WHERE user_id = :uid AND company_id = :cid AND status = 'active' AND role IN ('admin','manager')
        LIMIT 1
    ");
    $m->execute(['uid' => (int)$user['id'], 'cid' => $companyId]);
    if (!$m->fetch()) {
        Response::error('You need to be an admin or manager of this company.', 403);
    }

    return [(int)$user['id'], $companyId, $in];
}
