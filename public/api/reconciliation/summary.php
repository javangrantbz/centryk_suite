<?php
/** Reconciliation dashboard: counts + recent imports. */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId] = business_guard('reconciliation', false);

Response::ok([
    'summary' => ReconciliationService::summary($companyId),
    'imports' => ReconciliationService::imports($companyId),
]);
