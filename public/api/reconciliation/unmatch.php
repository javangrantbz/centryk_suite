<?php
/** Undo a match (voids any receipt it created). Body: { company_id, txn_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$txnId = (int)($in['txn_id'] ?? 0);
if ($txnId <= 0) {
    Response::error('txn_id is required.', 422);
}

try {
    ReconciliationService::unmatch($companyId, $txnId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not unmatch the line.', 500);
}

Response::ok([]);
