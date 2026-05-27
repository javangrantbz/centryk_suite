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

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);
$email     = strtolower(trim($body['email'] ?? ''));
$firstName = trim($body['first_name'] ?? '');
$lastName  = trim($body['last_name'] ?? '');
$password  = $body['password'] ?? '';
$role      = $body['role'] ?? 'employee';

if (!$companyId || $email === '') {
    Response::error('company_id and email are required.');
}
if (!in_array($role, ['admin', 'manager', 'employee'], true)) {
    Response::error('Invalid role. Must be admin, manager, or employee.');
}

$pdo = DB::pdo();

// Only admins can add members
$callerStmt = $pdo->prepare('SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid AND status = "active"');
$callerStmt->execute(['cid' => $companyId, 'uid' => $user['id']]);
$caller = $callerStmt->fetch();
if (!$caller || $caller['role'] !== 'admin') {
    Response::error('Only company admins can add members.', 403);
}

$companyStmt = $pdo->prepare('SELECT name FROM companies WHERE id = :id LIMIT 1');
$companyStmt->execute(['id' => $companyId]);
$company = $companyStmt->fetch();

// Find or create the Centryk user
$findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$findUser->execute(['email' => $email]);
$target = $findUser->fetch();

$pdo->beginTransaction();
try {
    if (!$target) {
        if ($firstName === '' || $lastName === '' || $password === '') {
            $pdo->rollBack();
            Response::error('New users require first_name, last_name, and password.');
        }

        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $pdo->prepare('INSERT INTO users (uuid, first_name, last_name, email, password_hash) VALUES (:uuid, :fn, :ln, :email, :pw)')
            ->execute([
                'uuid'  => $uuid,
                'fn'    => $firstName,
                'ln'    => $lastName,
                'email' => $email,
                'pw'    => password_hash($password, PASSWORD_BCRYPT),
            ]);
        $targetId = (int)$pdo->lastInsertId();

        // Grant only the apps the inviting admin already has access to —
        // this scopes new accounts to the same apps as the company that invited them.
        $callerAppsStmt = $pdo->prepare('SELECT app_id FROM user_app_access WHERE user_id = :uid');
        $callerAppsStmt->execute(['uid' => $user['id']]);
        $grantStmt = $pdo->prepare('INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)');
        foreach ($callerAppsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grantStmt->execute(['uid' => $targetId, 'aid' => $row['app_id']]);
        }
    } else {
        $targetId = (int)$target['id'];
    }

    // Add to company, or update existing membership
    $pdo->prepare('
        INSERT INTO company_members (company_id, user_id, role)
        VALUES (:cid, :uid, :role)
        ON DUPLICATE KEY UPDATE role = :role2, status = "active"
    ')->execute(['cid' => $companyId, 'uid' => $targetId, 'role' => $role, 'role2' => $role]);

    $pdo->commit();
    Audit::log([
        'actor_user_id' => $user['id'],
        'target_user_id' => $targetId,
        'company_id' => $companyId,
        'event_type' => $target ? 'company.member.updated' : 'company.member.invited',
        'summary' => ($target ? 'Updated' : 'Added') . ' company member ' . $email,
        'metadata' => [
            'company_name' => $company['name'] ?? null,
            'email' => $email,
            'role' => $role,
            'is_new_user' => !$target ? 'yes' : 'no',
        ],
    ]);
    Response::ok(['message' => 'Member added.', 'user_id' => $targetId, 'is_new' => !$target]);
} catch (Throwable $e) {
    $pdo->rollBack();
    Response::error($e->getMessage());
}
