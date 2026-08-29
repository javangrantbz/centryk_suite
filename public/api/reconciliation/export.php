<?php
/**
 * Bank-line export for the reconciliation workbench (Centryk Business).
 *   export.php?company_id=1&status=unmatched   (status: unmatched|matched|ignored|all)
 * GET so the browser can download it. Gated: admin/manager of the company
 * with the 'reconciliation' entitlement.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Not signed in.');
}

$companyId = (int)($_GET['company_id'] ?? 0);
$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetchColumn() || Entitlements::level($companyId, 'reconciliation') === Entitlements::NONE) {
    http_response_code(403);
    exit('Not available.');
}

$status = in_array($_GET['status'] ?? 'unmatched', ['unmatched', 'matched', 'ignored', 'all'], true)
    ? ($_GET['status'] ?? 'unmatched') : 'unmatched';

$rows = ReconciliationService::exportRows($companyId, $status === 'all' ? [] : ['status' => $status]);

$name = 'bank-lines-' . $status . '-' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Description', 'Reference', 'Amount', 'Direction', 'Status', 'Matched to', 'Note']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['txn_date'],
        $r['description'],
        $r['reference'],
        number_format((float)$r['amount'], 2, '.', ''),
        $r['direction'],
        $r['status'],
        $r['matched_to'] ?? '',
        $r['note'],
    ]);
}
fclose($out);
