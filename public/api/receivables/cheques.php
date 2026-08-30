<?php
/** Cheque register + summary. Body: { company_id, status? } status = pending|cleared|bounced */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId, $in] = receivables_guard(false);

Response::ok([
    'cheques' => ReceivablesService::chequeRegister($companyId, ['status' => $in['status'] ?? 'pending']),
    'summary' => ReceivablesService::chequesSummary($companyId),
]);
