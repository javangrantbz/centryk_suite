<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';

/**
 * Gates for Case Management API endpoints (free-core, no entitlement check —
 * RBAC is plain company_members.role, same as Calendar).
 *
 *   - authenticated
 *   - POST with a JSON body carrying company_id
 *   - caller is an active member of that company
 *
 * cases_guard_member() accepts any active role (admin/manager/employee) —
 * for viewing/creating/commenting on cases. cases_guard_manager() requires
 * admin/manager — for status changes, assignment, and category/service
 * management.
 *
 * @return array{0:int,1:int,2:string,3:array}  [userId, companyId, role, decodedBody]
 */
function cases_guard_member(): array
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
        WHERE user_id = :uid AND company_id = :cid AND status = 'active'
        LIMIT 1
    ");
    $m->execute(['uid' => (int)$user['id'], 'cid' => $companyId]);
    $role = $m->fetchColumn();
    if (!$role) {
        Response::error('You need to be a member of this company.', 403);
    }

    return [(int)$user['id'], $companyId, (string)$role, $in];
}

/** Same as cases_guard_member() but requires an admin/manager role. */
function cases_guard_manager(): array
{
    [$userId, $companyId, $role, $in] = cases_guard_member();
    if (!in_array($role, ['admin', 'manager'], true)) {
        Response::error('You need to be an admin or manager of this company.', 403);
    }
    return [$userId, $companyId, $role, $in];
}
