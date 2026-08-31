<?php
/**
 * Customer-facing: a company admin turns on the Centryk Business free preview
 * from business.php. Grants every promo package with the promo expiry.
 * Only works while the offer is open (Entitlements::promoActive()).
 *
 * POST { company_id }
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
if ($companyId <= 0) {
    Response::error('A company is required.', 422);
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
if (!$check->fetch()) {
    Response::error('Only a company admin can start the preview.', 403);
}

try {
    $result = Entitlements::startPreview($companyId, (int)$user['id']);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([
    'granted' => $result['granted'],
    'ends_on' => $result['ends_on'],
    'message' => $result['granted']
        ? 'Your free preview is on — every Centryk Business tool is open until ' . date('j M Y', strtotime($result['ends_on'])) . '.'
        : 'Your company already has these tools.',
]);
