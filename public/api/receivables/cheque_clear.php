<?php
/** Mark a pending cheque cleared. Body: { company_id, payment_id, cleared_on? } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$pid = (int)($in['payment_id'] ?? 0);
if ($pid <= 0) {
    Response::error('payment_id is required.', 422);
}
try {
    ReceivablesService::clearCheque($companyId, $pid, $in['cleared_on'] ?? null, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
Response::ok([]);
