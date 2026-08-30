<?php
/** Post the year-end closing journal and close the year's periods. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$fiscalYear = (int)($in['fiscal_year'] ?? 0);
if ($fiscalYear < 2000 || $fiscalYear > 2100) {
    Response::error('A valid fiscal_year is required.', 422);
}

try {
    $journalId = AccountingService::yearEndClose($companyId, $fiscalYear, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['journal' => AccountingService::journal($companyId, $journalId)]);
