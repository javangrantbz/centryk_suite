<?php
/**
 * Belize BTS e-invoicing: build a debit note against an authorized invoice /
 * tax receipt. POST { company_id, id, reason?, lines: [{description, quantity,
 * unit_price, tax_category, tax_rate}] } - the lines are brand-new charges,
 * not selections from the original. Only *creates* the debit note (status
 * 'built') - call submit.php on the returned document to sign and send it.
 */
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
    SELECT role FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if ($m->fetchColumn() !== 'admin') {
    Response::error('You need to be an admin of this company.', 403);
}

$lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];

try {
    $document = FiscalInvoicingService::issueDebitNote($companyId, $id, $lines, (int)$user['id'], (string)($in['reason'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    error_log('fiscal debit_note failed: ' . $e->getMessage());
    Response::error('Could not prepare the debit note.');
}

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.debit_note_prepared',
    'summary'       => 'Prepared a BTS debit note against fiscal document #' . $id,
]);

Response::ok(['document' => $document]);
