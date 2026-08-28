<?php
/** Group detail + consolidated numbers. Body: { group_id } */
require_once __DIR__ . '/../../../app/core/group_guard.php';

[$userId, $groupId] = group_guard(false);

$detail = GroupsService::detail($groupId);
if ($detail === null) {
    Response::error('Group not found.', 404);
}

Response::ok([
    'group'        => $detail,
    'consolidated' => GroupsService::consolidated($groupId),
    'attachable'   => GroupsService::attachableFor($userId),
]);
