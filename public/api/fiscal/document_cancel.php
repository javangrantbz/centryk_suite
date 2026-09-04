<?php
/** Belize BTS e-invoicing: void a fiscal document that was never submitted. POST { company_id, id, reason? } */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
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
$id = (int)($in['id'] ?? 0);
if ($companyId <= 0 || $id <= 0) {
    Response::error('company_id and id are required.', 422);
}

$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('You need to be an admin or manager of this company.', 403);
}
try {
    $document = FiscalInvoicingService::cancel($companyId, $id, (int)$user['id'], (string)($in['reason'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    error_log('fiscal document_cancel failed: ' . $e->getMessage());
    Response::error('Could not cancel that document.');
}

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.document_cancelled',
    'summary'       => 'Cancelled fiscal document #' . $id,
]);

Response::ok(['document' => $document]);
