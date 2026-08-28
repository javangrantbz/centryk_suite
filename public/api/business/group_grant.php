<?php
/**
 * Admin: grant / move a package at group level. Member companies inherit it.
 * Body: { group_id, package_key, action: grant|suspend|resume|revoke, notes? }
 */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$groupId    = (int)($in['group_id'] ?? 0);
$packageKey = trim((string)($in['package_key'] ?? ''));
$action     = (string)($in['action'] ?? 'grant');
$notes      = trim((string)($in['notes'] ?? ''));

if ($groupId <= 0 || $packageKey === '') {
    Response::error('group_id and package_key are required.', 422);
}

$pdo = DB::pdo();
$grp = $pdo->prepare("SELECT id FROM company_groups WHERE id = :id LIMIT 1");
$grp->execute(['id' => $groupId]);
if (!$grp->fetch()) {
    Response::error('Group not found.', 404);
}
$pkg = $pdo->prepare("SELECT `key` FROM business_packages WHERE `key` = :k AND status = 'active' LIMIT 1");
$pkg->execute(['k' => $packageKey]);
if (!$pkg->fetch()) {
    Response::error('That package is not available.', 422);
}

$actorId = (int)$admin['id'];
match ($action) {
    'grant'   => Entitlements::grantGroup($groupId, $packageKey, $actorId, $notes),
    'suspend' => Entitlements::suspendGroup($groupId, $packageKey, $actorId),
    'resume'  => Entitlements::resumeGroup($groupId, $packageKey, $actorId),
    'revoke'  => Entitlements::revokeGroup($groupId, $packageKey, $actorId),
    default   => Response::error('Unknown action.', 422),
};

Response::ok(['action' => $action]);
