<?php
/** Ignore / restore a bank line. Body: { company_id, txn_id, ignored: bool } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$txnId = (int)($in['txn_id'] ?? 0);
if ($txnId <= 0) {
    Response::error('txn_id is required.', 422);
}
$ignored = !empty($in['ignored']);

try {
    ReconciliationService::setIgnored($companyId, $txnId, $ignored, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['ignored' => $ignored]);
