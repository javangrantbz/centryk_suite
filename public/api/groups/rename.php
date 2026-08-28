<?php
/** Rename a group. Body: { group_id, name } */
require_once __DIR__ . '/../../../app/core/group_guard.php';

[$userId, $groupId, $in] = group_guard(true);

try {
    GroupsService::saveGroup($userId, ['id' => $groupId, 'name' => (string)($in['name'] ?? '')]);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 403);
}

Response::ok([]);
