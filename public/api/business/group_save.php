<?php
/**
 * Admin: create a group (optionally naming a first group_admin by email) or
 * rename one. Body: { id?, name, admin_email? }
 */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/GroupsService.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

try {
    $groupId = GroupsService::saveGroup((int)$admin['id'], [
        'id'   => (int)($in['id'] ?? 0),
        'name' => (string)($in['name'] ?? ''),
    ], true);

    $adminEmail = trim((string)($in['admin_email'] ?? ''));
    if ($adminEmail !== '' && empty($in['id'])) {
        $u = DB::pdo()->prepare("SELECT id FROM users WHERE email = :e AND status = 'active' LIMIT 1");
        $u->execute(['e' => $adminEmail]);
        $row = $u->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            GroupsService::setMember($groupId, (int)$row['id'], 'group_admin', (int)$admin['id'], true);
        }
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['group_id' => $groupId]);
