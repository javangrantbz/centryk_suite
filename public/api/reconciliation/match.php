<?php
/** Match a bank line. Body: { company_id, txn_id, type: invoice|ar_payment, target_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$txnId    = (int)($in['txn_id'] ?? 0);
$type     = (string)($in['type'] ?? 'invoice');
$targetId = (int)($in['target_id'] ?? 0);
if ($txnId <= 0 || $targetId <= 0) {
    Response::error('txn_id and target_id are required.', 422);
}

try {
    $result = ReconciliationService::match($companyId, $txnId, $type, $targetId, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not match the line.', 500);
}

Response::ok($result);
