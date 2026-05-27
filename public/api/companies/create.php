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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($body['name'] ?? '');
if ($name === '') {
    Response::error('Company name is required.');
}

$pdo = DB::pdo();

$dup = $pdo->prepare('SELECT id FROM companies WHERE name = :name AND owner_id = :owner_id AND status = "active"');
$dup->execute(['name' => $name, 'owner_id' => $user['id']]);
if ($dup->fetch()) {
    Response::error('You already have a company with that name.');
}

$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO companies (uuid, name, owner_id) VALUES (:uuid, :name, :owner_id)')
        ->execute(['uuid' => $uuid, 'name' => $name, 'owner_id' => $user['id']]);
    $companyId = (int)$pdo->lastInsertId();

    // Auto-add owner as admin
    $pdo->prepare('INSERT INTO company_members (company_id, user_id, role) VALUES (:cid, :uid, "admin")')
        ->execute(['cid' => $companyId, 'uid' => $user['id']]);

    $pdo->commit();
    Audit::log([
        'actor_user_id' => $user['id'],
        'company_id' => $companyId,
        'event_type' => 'company.created',
        'summary' => 'Created company "' . $name . '"',
        'metadata' => [
            'company_name' => $name,
            'company_uuid' => $uuid,
        ],
    ]);
    Response::ok(['id' => $companyId, 'uuid' => $uuid, 'name' => $name]);
} catch (Throwable $e) {
    $pdo->rollBack();
    Response::error($e->getMessage());
}
