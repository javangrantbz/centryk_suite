<?php
/**
 * Server-to-server: this company's BTS e-invoicing status, for a spoke's own
 * UI badge (starting with OnePay - see onepay's CentrykFiscalStatus.php).
 * Read-only, no write path here.
 *
 * POST { company_uuid, secret: '<PROVISION_SECRET>' }
 * Returns: { success, enabled, environment }
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$secret      = trim((string)($body['secret'] ?? ''));
$companyUuid = trim((string)($body['company_uuid'] ?? ''));

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}
if ($companyUuid === '') {
    Response::error('company_uuid is required.');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('
    SELECT p.enabled, p.environment
    FROM company_fiscal_profiles p
    JOIN companies c ON c.id = p.company_id
    WHERE c.uuid = :uuid AND c.status = "active"
    LIMIT 1
');
$stmt->execute(['uuid' => $companyUuid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

Response::ok([
    'enabled'     => $row ? (bool)$row['enabled'] : false,
    'environment' => $row['environment'] ?? null,
]);
