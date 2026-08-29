<?php
/** Bulk-load an AR customer list from CSV. Body: { company_id, csv } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$csv = (string)($in['csv'] ?? '');
if (trim($csv) === '') {
    Response::error('Paste or upload a CSV first.', 422);
}

try {
    $res = ReceivablesService::importCustomers($companyId, $csv, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok($res);
