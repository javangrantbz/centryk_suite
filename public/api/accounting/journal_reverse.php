<?php
/** Post the accounting-correct reversal of a journal entry. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/core/Ledger.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$journalId = (int)($in['journal_id'] ?? 0);
if ($journalId <= 0) {
    Response::error('journal_id is required.', 422);
}

try {
    $revId = Ledger::reverse(
        $companyId,
        $journalId,
        isset($in['date']) ? (string)$in['date'] : null,
        isset($in['memo']) ? (string)$in['memo'] : null,
        $userId
    );
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Audit::log([
    'actor_user_id' => $userId,
    'company_id'    => $companyId,
    'event_type'    => 'accounting.journal.reversed',
    'summary'       => "Reversed journal #{$journalId} with #{$revId}",
    'metadata'      => ['journal_id' => $journalId, 'reversal_id' => $revId],
]);

Response::ok(['journal' => AccountingService::journal($companyId, $revId)]);
