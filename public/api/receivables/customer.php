<?php
/** One customer's statement — invoices, receipts, computed balance. */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId, $in] = receivables_guard(false);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}

$statement = ReceivablesService::statement($companyId, $customerId);
if ($statement === null) {
    Response::error('Customer not found.', 404);
}

Response::ok($statement);
