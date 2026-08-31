<?php
/** Post any Receivables activity not yet in the ledger. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/GlSync.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

try {
    $result = GlSync::sync($companyId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not run the AR sync.', 500);
}

Response::ok(['result' => $result, 'summary' => AccountingService::deskSummary($companyId)]);
