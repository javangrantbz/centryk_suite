<?php
/** List bank statement lines. Body: { company_id, status?, direction?, q? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId, $in] = business_guard('reconciliation', false);

Response::ok([
    'transactions' => ReconciliationService::transactions($companyId, [
        'status'    => $in['status'] ?? 'unmatched',
        'direction' => $in['direction'] ?? '',
        'q'         => trim((string)($in['q'] ?? '')),
    ]),
]);
