<?php
/**
 * Connect or disconnect the company's Facebook Page (used to post approved
 * reviews). Company admins only: the token lets Centryk post as the Page.
 * Body: { company_id, action: connect|disconnect, page_id?, access_token? }
 * Returns: { connection } (never the token)
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FacebookPagePoster.php';

[$userId, $companyId, $in] = forms_guard();

$role = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :uid AND company_id = :cid AND status = 'active' AND role = 'admin' LIMIT 1
");
$role->execute(['uid' => $userId, 'cid' => $companyId]);
if (!$role->fetchColumn()) {
    Response::error('Only a company admin can connect or disconnect the Facebook Page.', 403);
}

$action = (string)($in['action'] ?? 'connect');
try {
    if ($action === 'disconnect') {
        FacebookPagePoster::disconnect($companyId);
        Response::ok(['connection' => null]);
    }
    $conn = FacebookPagePoster::connect(
        $companyId,
        $userId,
        (string)($in['page_id'] ?? ''),
        (string)($in['access_token'] ?? '')
    );
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok(['connection' => $conn]);
