<?php
/** Record a receipt against a customer account; auto-allocate oldest due first. */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}

try {
    $result = ReceivablesService::recordPayment($companyId, $customerId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
} catch (Throwable $e) {
    Response::error('Could not record the payment.', 500);
}

Response::ok($result);
