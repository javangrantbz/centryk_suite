<?php
/** List vendors. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/ExpensesService.php';

[$userId, $companyId, $in] = accounting_guard(false);

Response::ok(['vendors' => ExpensesService::vendors($companyId, empty($in['include_archived']))]);
