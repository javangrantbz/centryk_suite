<?php
/**
 * Server-to-server: which of a company's OnePay catalog items are currently
 * live on the Centryk store, and to which audience. Used by OnePay to show an
 * "On Store" badge in its inventory list without a cross-database read.
 *
 * POST { company_uuid, secret: '<PROVISION_SECRET>' }
 * Returns: { success, items: { "<source_item_id>": "employee|market|both", ... } }
 *
 * Only rows that a public/member visitor of store.php would actually see are
 * returned: enabled = 1 and inside the visibility window (same predicate as
 * store.php).
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
    SELECT sl.source_item_id, sl.audience
    FROM store_listings sl
    JOIN companies c ON c.id = sl.company_id
    WHERE c.uuid = :uuid
      AND c.status = "active"
      AND sl.source_app = "onepay"
      AND sl.enabled = 1
      AND sl.source_item_id IS NOT NULL
      AND (sl.starts_at IS NULL OR sl.starts_at <= NOW())
      AND (sl.ends_at IS NULL OR sl.ends_at >= NOW())
');
$stmt->execute(['uuid' => $companyUuid]);

$items = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $items[(string)(int)$row['source_item_id']] = (string)$row['audience'];
}

Response::ok(['items' => $items]);
