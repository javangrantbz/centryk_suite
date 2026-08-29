<?php
/** Suggested subject + body for an overdue-account reminder. Body: { company_id, customer_id } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId, $in] = receivables_guard(false);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}

try {
    Response::ok(ReceivablesService::reminderDraft($companyId, $customerId));
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}
