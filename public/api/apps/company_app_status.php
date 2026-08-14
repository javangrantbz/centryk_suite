<?php
/**
 * Server-to-server: does this company already have the named app enrolled
 * (i.e. does any active member of it have user_app_access for that app)?
 * Used by spokes (starting with OnePay) to decide whether to show a gentle
 * "you can also use X" nudge - never shown to companies already using it.
 *
 * POST { company_uuid, app_key, secret: '<PROVISION_SECRET>' }
 * Returns: { success, enrolled }
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$secret      = trim((string)($body['secret'] ?? ''));
$companyUuid = trim((string)($body['company_uuid'] ?? ''));
$appKey      = trim((string)($body['app_key'] ?? ''));

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}
if ($companyUuid === '' || $appKey === '') {
    Response::error('company_uuid and app_key are required.');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('
    SELECT COUNT(*)
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    JOIN user_app_access ua ON ua.user_id = cm.user_id
    JOIN apps a ON a.id = ua.app_id
    WHERE c.uuid = :uuid
      AND cm.status = "active"
      AND c.status = "active"
      AND a.`key` = :app_key
');
$stmt->execute(['uuid' => $companyUuid, 'app_key' => $appKey]);

Response::ok(['enrolled' => (int)$stmt->fetchColumn() > 0]);
