<?php
/** Place a customer on credit hold, or release them. */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}
$onHold = !empty($in['on_hold']);

try {
    ReceivablesService::setHold($companyId, $customerId, $onHold, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['on_hold' => $onHold]);
