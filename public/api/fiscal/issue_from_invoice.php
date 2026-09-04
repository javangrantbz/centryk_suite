<?php
/** Belize BTS e-invoicing: build a fiscal document from an existing invoice. POST { company_id, invoice_id } */
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

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($in['company_id'] ?? 0);
$invoiceId = (int)($in['invoice_id'] ?? 0);
if ($companyId <= 0 || $invoiceId <= 0) {
    Response::error('company_id and invoice_id are required.', 422);
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

try {
    $document = FiscalInvoicingService::fromInvoice($companyId, $invoiceId, (int)$user['id']);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    error_log('fiscal issue_from_invoice failed: ' . $e->getMessage());
    Response::error('Could not build a fiscal document from that invoice.');
}

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.document_built',
    'summary'       => 'Built a fiscal document from invoice #' . $invoiceId,
    'metadata'      => ['fiscal_document_id' => $document['id'] ?? null],
]);

Response::ok(['document' => $document]);
