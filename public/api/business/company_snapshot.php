<?php
/**
 * One-line health numbers for the dashboard's Centryk Business module cards.
 *
 * POST {"company_id": 12}
 *   -> { receivables?: {...}, reconciliation?: {...}, routes?: {...} }
 *
 * Only sections the company is entitled to are returned. Caller must be an
 * active admin/manager of the company. One call per company switch.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
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
    Response::error('company_id is required.', 422);
}

$m = DB::pdo()->prepare("
    SELECT role FROM company_members
    WHERE user_id = :uid AND company_id = :cid AND status = 'active' AND role IN ('admin','manager')
    LIMIT 1
");
$m->execute(['uid' => (int)$user['id'], 'cid' => $companyId]);
if (!$m->fetch()) {
    Response::error('You need to be an admin or manager of this company.', 403);
}

$out = [];

if (Entitlements::level($companyId, 'receivables') !== Entitlements::NONE) {
    require_once __DIR__ . '/../../../app/services/ReceivablesService.php';
    $t = ReceivablesService::portfolio($companyId)['totals'];
    $out['receivables'] = [
        'outstanding' => round((float)($t['balance'] ?? 0), 2),
        'overdue'     => round((float)($t['overdue'] ?? 0), 2),
        'on_hold'     => (int)($t['on_hold'] ?? 0),
    ];
}

if (Entitlements::level($companyId, 'reconciliation') !== Entitlements::NONE) {
    require_once __DIR__ . '/../../../app/services/ReconciliationService.php';
    $s = ReconciliationService::summary($companyId);
    $out['reconciliation'] = [
        'unmatched_credits' => (int)$s['unmatched_credits'],
        'unmatched_value'   => round((float)$s['unmatched_value'], 2),
    ];
}

if (Entitlements::level($companyId, 'routes') !== Entitlements::NONE) {
    require_once __DIR__ . '/../../../app/services/RoutesService.php';
    $s = RoutesService::summary($companyId);
    $out['routes'] = [
        'cash_in_transit'   => round((float)$s['cash_in_transit'], 2),
        'awaiting_approval' => (int)$s['awaiting_approval'],
        'out'               => (int)$s['out'],
    ];
}

Response::ok($out);
