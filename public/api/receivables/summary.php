<?php
/** Receivables portfolio: every customer with balance + aging, plus totals. */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[, $companyId] = receivables_guard(false);

Response::ok(ReceivablesService::portfolio($companyId));
