<?php
/** Auto-ignore rules for the company. Body: { company_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId, $in] = business_guard('reconciliation', false);

Response::ok(['rules' => ReconciliationService::rules($companyId)]);
