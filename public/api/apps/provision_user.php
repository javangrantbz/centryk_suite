<?php
/**
 * Server-to-server endpoint: provision a Centryk user for a given app.
 * Called by OnePay (and potentially MyPay) when an admin invites a new staff member.
 * Requires a shared PROVISION_SECRET set in Centryk's .env.
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim($body['provision_secret'] ?? '');
$appKey = trim($body['app_key'] ?? '');
$email  = strtolower(trim($body['email'] ?? ''));
$first  = trim($body['first_name'] ?? '');
$last   = trim($body['last_name'] ?? '');

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

if ($appKey === '' || $email === '') {
    Response::error('app_key and email are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Invalid email address.');
}

$pdo = DB::pdo();

$app = $pdo->prepare('SELECT id FROM apps WHERE `key` = :key AND status = "active" LIMIT 1');
$app->execute(['key' => $appKey]);
$appRow = $app->fetch();
if (!$appRow) {
    Response::error('Unknown app.');
}
$appId = (int)$appRow['id'];

$findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$findUser->execute(['email' => $email]);
$existing = $findUser->fetch();

if ($existing) {
    $userId = (int)$existing['id'];
    $isNew  = false;
} else {
    if ($first === '' || $last === '') {
        Response::error('first_name and last_name are required for new users.');
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
            'fn'    => $first,
            'ln'    => $last,
            'email' => $email,
            'pw'    => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
        ]);

    $userId = (int)$pdo->lastInsertId();
    $isNew  = true;
}

$pdo->prepare('INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)')
    ->execute(['uid' => $userId, 'aid' => $appId]);

// Optionally add the user to a Centryk company
$companyUuid = trim($body['company_uuid'] ?? '');
$companyRole = in_array($body['role'] ?? '', ['admin', 'manager', 'employee'], true)
    ? $body['role'] : 'employee';

if ($companyUuid !== '') {
    $companyRow = $pdo->prepare('SELECT id FROM companies WHERE uuid = :uuid AND status = "active" LIMIT 1');
    $companyRow->execute(['uuid' => $companyUuid]);
    $company = $companyRow->fetch();
    if ($company) {
        // Provisioning may run repeatedly (re-syncs from the calling app), so it
        // must never clobber an existing member's role — that would silently
        // downgrade admins to the default 'employee'. On a duplicate we only
        // re-activate access; role for existing members is managed deliberately
        // through the company member UI (invite.php).
        $pdo->prepare('
            INSERT INTO company_members (company_id, user_id, role)
            VALUES (:cid, :uid, :role)
            ON DUPLICATE KEY UPDATE status = "active"
        ')->execute([
            'cid'   => (int)$company['id'],
            'uid'   => $userId,
            'role'  => $companyRole,
        ]);
    }
}

Response::ok(['is_new' => $isNew, 'user_id' => $userId]);
