<?php
/** Write-off list + bad-debt summary. Body: { company_id, status?, customer_id? } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(false);

$filters = [
    'status'      => $in['status'] ?? 'pending',
    'customer_id' => (int)($in['customer_id'] ?? 0),
];

Response::ok([
    'writeoffs' => ReceivablesService::writeoffs($companyId, $filters),
    'summary'   => ReceivablesService::badDebtReport($companyId, $in['from'] ?? null, $in['to'] ?? null),
]);
