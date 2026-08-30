<?php
/** Deactivate an auto-ignore rule. Body: { company_id, rule_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$ruleId = (int)($in['rule_id'] ?? 0);
if ($ruleId <= 0) {
    Response::error('rule_id is required.', 422);
}
try {
    ReconciliationService::deleteRule($companyId, $ruleId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}
Response::ok([]);
