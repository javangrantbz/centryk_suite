<?php
/** Approve / reject / reverse a write-off. Company-admin only.
 *  Body: { company_id, writeoff_id, action: 'approve'|'reject'|'reverse', note? } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$writeoffId = (int)($in['writeoff_id'] ?? 0);
$action     = (string)($in['action'] ?? '');
if ($writeoffId <= 0) {
    Response::error('writeoff_id is required.', 422);
}

try {
    if ($action === 'reverse') {
        ReceivablesService::reverseWriteoff($companyId, $writeoffId, $userId, (string)($in['note'] ?? ''));
    } elseif (in_array($action, ['approve', 'reject'], true)) {
        ReceivablesService::decideWriteoff($companyId, $writeoffId, $action, $in, $userId);
    } else {
        Response::error('action must be approve, reject or reverse.', 422);
    }
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
