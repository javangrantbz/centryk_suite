<?php
/**
 * Credit standing for a customer — ok / hold / over_limit / blocked.
 * For spoke apps (OnePay, invoice-maker) to gate new orders/invoices.
 * Body: { company_id, customer_id }
 */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId, $in] = receivables_guard(false);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}

try {
    Response::ok(ReceivablesService::creditStatus($companyId, $customerId));
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}
