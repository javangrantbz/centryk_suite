<?php
/** Bulk-load a chart of accounts from pasted / uploaded CSV. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$csv = (string)($in['csv'] ?? '');
if (trim($csv) === '') {
    Response::error('Paste the CSV content in the "csv" field.', 422);
}

try {
    $result = AccountingService::importAccountsCsv($companyId, $csv, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::error('Could not import the chart of accounts.', 500);
}

Response::ok(['result' => $result]);
