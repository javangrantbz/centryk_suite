<?php
/** Belize BTS e-invoicing: save a company's fiscal profile / registration info. POST */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/FiscalInvoicingService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}
$companyId = (int)($in['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role = 'admin' LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('You need to be an admin of this company.', 403);
}
if (Entitlements::level($companyId, 'receivables') === Entitlements::NONE) {
    Response::error('E-invoicing builds on your sales data from Receivables.', 402, ['entitlement' => 'receivables']);
}

try {
    $profile = FiscalInvoicingService::saveProfile($companyId, $in);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    error_log('fiscal profile_save failed: ' . $e->getMessage());
    Response::error('Could not save the fiscal profile.');
}

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.profile_saved',
    'summary'       => 'Updated the BTS e-invoicing profile',
]);

Response::ok(['profile' => $profile]);
