<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

$user = require_admin();

if (strcasecmp((string)($user['email'] ?? ''), 'webdevelopment@bhilimited.com') !== 0) {
    Response::error('You are not allowed to delete registered companies.', 403);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);

if ($companyId < 1) {
    Response::error('company_id is required.');
}

$pdo = DB::pdo();

$stmt = $pdo->prepare('
    SELECT c.id, c.name, c.uuid, owner.email AS owner_email
    FROM companies c
    JOIN users owner ON owner.id = c.owner_id
    WHERE c.id = :id
    LIMIT 1
');
$stmt->execute(['id' => $companyId]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    Response::error('Company not found.', 404);
}

try {
    $pdo->beginTransaction();

    Audit::log([
        'actor_user_id' => (int)$user['id'],
        'company_id' => $companyId,
        'event_type' => 'company.deleted',
        'summary' => 'Deleted registered company ' . $company['name'],
        'metadata' => [
            'company_name' => $company['name'],
            'company_uuid' => $company['uuid'],
            'owner_email' => $company['owner_email'],
        ],
    ]);

    $delete = $pdo->prepare('DELETE FROM companies WHERE id = :id');
    $delete->execute(['id' => $companyId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    Response::error('Could not delete the company. It may still be referenced by records that must be removed first.', 500);
}

Response::ok(['message' => 'Company deleted.']);
