<?php
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Entitlements.php';
require_once __DIR__ . '/../services/GroupsService.php';

/**
 * Gate for customer-facing company-group (Enterprise) endpoints:
 *   - authenticated, POST + JSON body carrying group_id
 *   - caller is an active member of the group ($writing => group_admin)
 *   - the group holds the 'enterprise' entitlement (FULL to write, READ to view)
 *
 * Group creation and the first Enterprise grant happen in the admin console,
 * not here — this only manages groups the caller already belongs to.
 *
 * @return array{0:int,1:int,2:array}  [userId, groupId, decodedBody]
 */
function group_guard(bool $writing): array
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

    $groupId = (int)($in['group_id'] ?? 0);
    if ($groupId <= 0) {
        Response::error('group_id is required.', 422);
    }

    $role = GroupsService::role($groupId, (int)$user['id']);
    if ($role === null) {
        Response::error('You are not a member of this group.', 403);
    }
    if ($writing && $role !== 'group_admin') {
        Response::error('Only a group admin can do that.', 403);
    }

    $level = Entitlements::groupLevel($groupId, 'enterprise');
    if ($level === Entitlements::NONE || ($writing && $level !== Entitlements::FULL)) {
        Response::error(
            $level === Entitlements::READ
                ? "The group's Enterprise subscription is paused — read-only until billing is resolved."
                : 'Company groups are part of Centryk Business (Enterprise).',
            402,
            ['entitlement' => 'enterprise', 'level' => $level]
        );
    }

    return [(int)$user['id'], $groupId, $in];
}
