<?php
/**
 * Auto-provision OneLink merchant accounts for every active company that
 * doesn't already have one enabled (admin-only). Backfill for companies
 * created before auto-provisioning existed.
 * POST (no body required)
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OneLinkProvisioning.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}
if (empty($user['is_admin'])) {
    Response::error('Only a Centryk administrator can provision OneLink accounts.', 403);
}

$pdo = DB::pdo();

$rows = $pdo->query("
    SELECT c.id, c.name
    FROM companies c
    LEFT JOIN onelink_credentials o ON o.company_id = c.id
    WHERE c.status = 'active' AND COALESCE(o.enabled, 0) = 0
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$provisioned = [];
$failed = [];

foreach ($rows as $row) {
    $result = OneLinkProvisioning::provision($pdo, (int)$row['id']);
    if (!empty($result['success'])) {
        $provisioned[] = ['company_id' => (int)$row['id'], 'name' => $row['name']];
    } else {
        $failed[] = ['company_id' => (int)$row['id'], 'name' => $row['name'], 'message' => $result['message'] ?? 'Unknown error'];
    }
}

Response::ok([
    'total'       => count($rows),
    'provisioned' => $provisioned,
    'failed'      => $failed,
]);
