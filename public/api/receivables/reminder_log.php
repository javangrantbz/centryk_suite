<?php
/** Log a collections reminder. Body: { company_id, customer_id, subject?, body?, kind?, channel?, mark_sent? } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$customerId = (int)($in['customer_id'] ?? 0);
if ($customerId <= 0) {
    Response::error('customer_id is required.', 422);
}

try {
    $id = ReceivablesService::logReminder($companyId, $customerId, $in, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['reminder_id' => $id]);
