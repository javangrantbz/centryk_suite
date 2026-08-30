<?php
/** Record a cheque as bounced — reverses the receipt. Body: { company_id, payment_id, reason } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$pid = (int)($in['payment_id'] ?? 0);
if ($pid <= 0) {
    Response::error('payment_id is required.', 422);
}
try {
    ReceivablesService::bounceCheque($companyId, $pid, (string)($in['reason'] ?? ''), $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}
Response::ok([]);
