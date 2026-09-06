<?php
/**
 * Belize BTS e-invoicing: transmit the contingency backlog (Subsequent
 * Authorization) once BTS is reachable again. POST { company_id }.
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
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

$m = DB::pdo()->prepare("SELECT role FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if ($m->fetchColumn() !== 'admin') {
    Response::error('You need to be an admin of this company.', 403);
}

try {
    $result = FiscalInvoicingService::transmitContingencyBacklog($companyId, (int)$user['id']);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    error_log('fiscal contingency_transmit failed: ' . $e->getMessage());
    Response::error('Could not transmit the contingency backlog.');
}

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.contingency_transmitted',
    'summary'       => 'Transmitted contingency backlog: ' . json_encode($result['summary'] ?? []),
]);

Response::ok($result);
