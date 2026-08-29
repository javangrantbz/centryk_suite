<?php
/** Recent Centryk Business activity for a company. POST { company_id }
 *  Caller must be an admin/manager of the company, which must hold >=1 package. */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/BusinessActivity.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }
$companyId = (int)($in['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

$chk = DB::pdo()->prepare("
    SELECT
      (SELECT 1 FROM company_members
        WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1) AS is_mgr,
      (SELECT 1 FROM company_entitlements
        WHERE company_id = :c2 AND state <> 'revoked' LIMIT 1) AS has_pkg
");
$chk->execute(['u' => (int)$user['id'], 'c' => $companyId, 'c2' => $companyId]);
$row = $chk->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($row['is_mgr'])) {
    Response::error('You need to be an admin or manager of this company.', 403);
}
if (empty($row['has_pkg'])) {
    Response::error('This company has no Centryk Business packages.', 402);
}

Response::ok(['activity' => BusinessActivity::forCompany($companyId, 40)]);
