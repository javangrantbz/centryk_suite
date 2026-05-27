<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$body            = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId       = (int)($body['company_id'] ?? 0);
$userId          = (int)($body['user_id'] ?? 0);
$revokeAppAccess = !empty($body['revoke_app_access']);

if (!$companyId || !$userId) {
    Response::error('company_id and user_id are required.');
}
if ($userId === (int)$user['id']) {
    Response::error('You cannot remove yourself from a company.');
}

$pdo = DB::pdo();

$callerStmt = $pdo->prepare('SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid AND status = "active"');
$callerStmt->execute(['cid' => $companyId, 'uid' => $user['id']]);
$caller = $callerStmt->fetch();
if (!$caller || $caller['role'] !== 'admin') {
    Response::error('Only company admins can remove members.', 403);
}

$detailStmt = $pdo->prepare(
    'SELECT c.name AS company_name, c.uuid AS company_uuid, u.email AS target_email
     FROM companies c
     LEFT JOIN users u ON u.id = :uid
     WHERE c.id = :cid
     LIMIT 1'
);
$detailStmt->execute(['cid' => $companyId, 'uid' => $userId]);
$detail = $detailStmt->fetch();

$pdo->prepare('UPDATE company_members SET status = "inactive" WHERE company_id = :cid AND user_id = :uid')
    ->execute(['cid' => $companyId, 'uid' => $userId]);

$revokedApps = [];

if ($revokeAppAccess && !empty($detail['company_uuid']) && !empty($detail['target_email'])) {
    $uuid  = $detail['company_uuid'];
    $email = $detail['target_email'];

    // Deactivate OnePay store memberships for all stores in this company
    try {
        $pdo->prepare("
            UPDATE onepay.store_memberships sm
            JOIN onepay.stores s ON s.id = sm.store_id
            JOIN onepay.companies oc ON oc.id = s.company_id
            SET sm.status = 'inactive'
            WHERE sm.user_id = :uid AND oc.centryk_uuid = :uuid AND sm.status != 'inactive'
        ")->execute(['uid' => $userId, 'uuid' => $uuid]);
        $revokedApps[] = 'onepay';
    } catch (Throwable $e) {}

    // Remove MyPay company assignment for this user
    try {
        $pdo->prepare("
            DELETE uca FROM payroll.user_company_assignments uca
            JOIN payroll.users pu ON pu.id = uca.user_id
            JOIN payroll.companies pc ON pc.id = uca.company_id
            WHERE pu.email = :email AND pc.centryk_uuid = :uuid
        ")->execute(['email' => $email, 'uuid' => $uuid]);
        $revokedApps[] = 'mypay';
    } catch (Throwable $e) {}
}

Audit::log([
    'actor_user_id'  => $user['id'],
    'target_user_id' => $userId,
    'company_id'     => $companyId,
    'event_type'     => 'company.member.removed',
    'summary'        => 'Removed company member ' . ($detail['target_email'] ?? ('#' . $userId)),
    'metadata'       => [
        'company_name' => $detail['company_name'] ?? null,
        'email'        => $detail['target_email'] ?? null,
        'revoked_apps' => $revokedApps ?: null,
    ],
]);

Response::ok(['message' => 'Member removed.']);
