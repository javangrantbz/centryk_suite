<?php
/** Post (or save as draft) a manual journal entry. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/core/Ledger.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

if (!Ledger::isActivated($companyId)) {
    Response::error('Set up accounting for this company first.', 409);
}

$lines = $in['lines'] ?? [];
if (!is_array($lines) || count($lines) < 2) {
    Response::error('A journal entry needs at least two lines.', 422);
}

$status = ($in['status'] ?? 'posted') === 'draft' ? 'draft' : 'posted';

try {
    $journalId = Ledger::post($companyId, [
        'date'    => (string)($in['date'] ?? ''),
        'memo'    => (string)($in['memo'] ?? ''),
        'source'  => 'manual',
        'status'  => $status,
        'user_id' => $userId,
        'lines'   => $lines,
    ]);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not save the journal entry.', 500);
}

Audit::log([
    'actor_user_id' => $userId,
    'company_id'    => $companyId,
    'event_type'    => $status === 'draft' ? 'accounting.journal.drafted' : 'accounting.journal.posted',
    'summary'       => ($status === 'draft' ? 'Drafted' : 'Posted') . ' manual journal #' . $journalId,
    'metadata'      => ['journal_id' => $journalId],
]);

Response::ok(['journal' => AccountingService::journal($companyId, $journalId)]);
