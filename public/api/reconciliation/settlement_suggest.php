<?php
/** Settlement-batch candidates for an unmatched deposit. Body: { company_id, txn_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId, $in] = business_guard('reconciliation', false);

$txnId = (int)($in['txn_id'] ?? 0);
if ($txnId <= 0) {
    Response::error('txn_id is required.', 422);
}

Response::ok(ReconciliationService::settlementCandidates($companyId, $txnId));
