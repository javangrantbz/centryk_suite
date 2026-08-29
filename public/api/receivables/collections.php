<?php
/** Overdue accounts, worst first. */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId] = receivables_guard(false);

Response::ok(['accounts' => ReceivablesService::collections($companyId)]);
