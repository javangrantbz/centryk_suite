<?php
/** Belize BTS e-invoicing: read a company's fiscal profile. GET ?company_id= */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';
require_once __DIR__ . '/../../../app/services/FiscalInvoicingService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int)($_GET['company_id'] ?? 0);
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
    Response::error('E-invoicing builds on your sales data from Receivables.', 402, ['entitlement' => 'receivables']);
}

Response::ok(['profile' => FiscalInvoicingService::getProfile($companyId)]);
