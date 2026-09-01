<?php
/**
 * Self-serve: a group admin turns on the group (Enterprise) view for a group
 * they already administer but which has no active entitlement — no advisor.
 * Can't use group_guard (that requires the entitlement to already be present).
 *
 * POST { group_id }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';
require_once __DIR__ . '/../../../app/services/GroupsService.php';

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

if (GroupsService::role($groupId, (int)$user['id']) !== 'group_admin') {
    Response::error('Only a group admin can do that.', 403);
}

Entitlements::grantGroup(
    $groupId,
    'enterprise',
    (int)$user['id'],
    Entitlements::promoActive() ? 'Self-serve (free preview)' : 'Self-serve'
);

Response::ok([]);
