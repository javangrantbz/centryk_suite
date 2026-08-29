<?php
/** Recent activity across the group's companies. Body: { group_id } */
require_once __DIR__ . '/../../../app/core/group_guard.php';

[, $groupId] = group_guard(false);

Response::ok(['activity' => GroupsService::activity($groupId)]);
