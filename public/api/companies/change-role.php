<?php
/**
 * Change an existing company member's role (admin | manager | employee).
 * Body: { company_id, user_id, role }
 *
 * Rules: caller must be an active admin of the company; you can't change your own role
 * (so a company can never end up with no admin); the target must already be an active
 * member (this never adds or reactivates anyone). Writes to the audit trail and tells
 * MyPay its roster changed, same as the invite endpoint.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/MyPayWebhook.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);
$targetId  = (int)($body['user_id'] ?? 0);
$role      = (string)($body['role'] ?? '');

if (!$companyId || !$targetId) {
    Response::error('company_id and user_id are required.', 422);
}
if (!in_array($role, ['admin', 'manager', 'employee'], true)) {
    Response::error('Invalid role. Must be admin, manager, or employee.', 422);
}
if ($targetId === (int)$user['id']) {
    Response::error('You cannot change your own role. Ask another admin to do it.', 422);
}

$pdo = DB::pdo();

$callerStmt = $pdo->prepare('SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid AND status = "active"');
$callerStmt->execute(['cid' => $companyId, 'uid' => $user['id']]);
$caller = $callerStmt->fetch();
if (!$caller || $caller['role'] !== 'admin') {
    Response::error('Only company admins can change roles.', 403);
}

$targetStmt = $pdo->prepare('
    SELECT cm.role, cm.status, u.email, c.name AS company_name
    FROM company_members cm
    JOIN users u ON u.id = cm.user_id
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.company_id = :cid AND cm.user_id = :uid
');
$targetStmt->execute(['cid' => $companyId, 'uid' => $targetId]);
$target = $targetStmt->fetch();
if (!$target || $target['status'] !== 'active') {
    Response::error('That person is not an active member of this company.', 404);
}

$oldRole = (string)$target['role'];
if ($oldRole === $role) {
    Response::ok(['role' => $role, 'changed' => false, 'message' => 'Role unchanged.']);
}

try {
    $pdo->prepare('UPDATE company_members SET role = :role WHERE company_id = :cid AND user_id = :uid AND status = "active"')
        ->execute(['role' => $role, 'cid' => $companyId, 'uid' => $targetId]);
} catch (Throwable $e) {
    Response::error('Could not change the role.', 500);
}

Audit::log([
    'actor_user_id'  => $user['id'],
    'target_user_id' => $targetId,
    'company_id'     => $companyId,
    'event_type'     => 'company.member.role_changed',
    'summary'        => 'Changed ' . $target['email'] . ' from ' . $oldRole . ' to ' . $role,
    'metadata'       => [
        'company_name' => $target['company_name'] ?? null,
        'email'        => $target['email'],
        'old_role'     => $oldRole,
        'new_role'     => $role,
    ],
]);

// Real-time roster sync to MyPay (fire-and-forget; never blocks the response).
MyPayWebhook::memberSynced($pdo, $companyId, $targetId, 'centryk', 'updated');

Response::ok(['role' => $role, 'changed' => true, 'message' => 'Role changed to ' . $role . '.']);
