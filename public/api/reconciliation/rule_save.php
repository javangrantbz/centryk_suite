<?php
/** Create / update an auto-ignore rule, then apply active rules to the backlog.
 *  Body: { company_id, id?, description_like?, reference_like?, amount_exact?, direction?, note? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

try {
    $id = ReconciliationService::saveRule($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

$applied = ReconciliationService::applyRules($companyId, $userId);
Response::ok(['rule_id' => $id, 'applied' => $applied]);
