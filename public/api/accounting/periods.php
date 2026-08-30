<?php
/** List accounting periods (optionally one fiscal year). */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$fy = isset($in['fiscal_year']) ? (int)$in['fiscal_year'] : null;
Response::ok(['periods' => AccountingService::periods($companyId, $fy)]);
