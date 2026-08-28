<?php
/** Candidate invoice matches for one bank line. Body: { company_id, txn_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId, $in] = business_guard('reconciliation', false);

$txnId = (int)($in['txn_id'] ?? 0);
if ($txnId <= 0) {
    Response::error('txn_id is required.', 422);
}

$data = ReconciliationService::suggestions($companyId, $txnId);
if ($data['transaction'] === null) {
    Response::error('Bank line not found.', 404);
}

Response::ok($data);
