<?php
/** One journal entry with its lines. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$journalId = (int)($in['journal_id'] ?? 0);
if ($journalId <= 0) {
    Response::error('journal_id is required.', 422);
}

$journal = AccountingService::journal($companyId, $journalId);
if ($journal === null) {
    Response::error('Journal not found.', 404);
}

Response::ok(['journal' => $journal]);
