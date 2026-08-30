<?php
/** Reconcile a deposit against a batch of receipts. Body: { company_id, txn_id, payment_ids: [] } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$txnId = (int)($in['txn_id'] ?? 0);
$ids   = is_array($in['payment_ids'] ?? null) ? $in['payment_ids'] : [];
if ($txnId <= 0) {
    Response::error('txn_id is required.', 422);
}

try {
    $res = ReconciliationService::matchSettlement($companyId, $txnId, $ids, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok($res);
