<?php
/**
 * Customer-facing: a company admin turns on ONE Centryk Business package for
 * their company — no advisor. Free while the preview promo is open.
 *
 * POST { company_id, package_key }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
$companyId = (int)($in['company_id'] ?? 0);
$packageKey = trim((string)($in['package_key'] ?? ''));
if ($companyId <= 0 || $packageKey === '') {
    Response::error('A company and a package are required.', 422);
}

// Requester must be an active admin of the company.
$check = DB::pdo()->prepare("
    SELECT c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.company_id = :cid AND cm.user_id = :uid
      AND cm.role = 'admin' AND cm.status = 'active' AND c.status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => (int)$user['id']]);
$company = $check->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Only a company admin can turn on a package.', 403);
}

try {
    $result = Entitlements::startPackage($companyId, (int)$user['id'], $packageKey);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}

$msg = !$result['granted']
    ? 'This package is already on your plan.'
    : ($result['promo']
        ? 'Done — it\'s on and free until ' . date('j M Y', strtotime($result['ends_on'])) . '.'
        : 'Done — the package is now active for ' . $company['name'] . '.');

Response::ok(['granted' => $result['granted'], 'promo' => $result['promo'], 'message' => $msg]);
