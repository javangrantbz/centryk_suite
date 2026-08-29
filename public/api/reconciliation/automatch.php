<?php
/** Auto-match every high-confidence unmatched deposit. Body: { company_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId] = business_guard('reconciliation', true);

Response::ok(ReconciliationService::autoMatch($companyId, $userId));
