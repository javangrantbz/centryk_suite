<?php
/** Re-run all active auto-ignore rules against the unmatched backlog. Body: { company_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

Response::ok(['applied' => ReconciliationService::applyRules($companyId, $userId)]);
