<?php
/** Set / remove a group member. Body: { group_id, user_id, role: group_admin|group_viewer|remove } */
require_once __DIR__ . '/../../../app/core/group_guard.php';

[$userId, $groupId, $in] = group_guard(true);

$targetId = (int)($in['user_id'] ?? 0);
$email    = trim((string)($in['email'] ?? ''));
$role     = (string)($in['role'] ?? '');

if ($targetId <= 0 && $email !== '') {
    $u = DB::pdo()->prepare("SELECT id FROM users WHERE email = :e AND status = 'active' LIMIT 1");
    $u->execute(['e' => $email]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Response::error('No active Centryk user with that email.', 404);
    }
    $targetId = (int)$row['id'];
}
if ($targetId <= 0) {
    Response::error('A user_id or email is required.', 422);
}

try {
    GroupsService::setMember($groupId, $targetId, $role, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
