<?php
/** Belize GST output-tax summary for a company. POST { company_id, period?, treat_untaxed_as? }
 *  Gated: admin/manager of the company + the 'receivables' entitlement (sales data). */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';
require_once __DIR__ . '/../../../app/services/BusinessTax.php';

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

$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('You need to be an admin or manager of this company.', 403);
}
if (Entitlements::level($companyId, 'receivables') === Entitlements::NONE) {
    Response::error('The GST summary reads sales from Receivables.', 402, ['entitlement' => 'receivables']);
}

Response::ok(BusinessTax::gstReport(
    $companyId,
    (string)($in['period'] ?? ''),
    (string)($in['treat_untaxed_as'] ?? 'inclusive')
));
