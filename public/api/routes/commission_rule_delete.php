<?php
/** Deactivate a commission rule (company admin). Body: { company_id, rule_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$m = DB::pdo()->prepare("SELECT 1 FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' AND role = 'admin' LIMIT 1");
$m->execute(['u' => $userId, 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('Only a company admin can change commission rules.', 403);
}

$ruleId = (int)($in['rule_id'] ?? 0);
if ($ruleId <= 0) {
    Response::error('rule_id is required.', 422);
}

try {
    RoutesService::deleteCommissionRule($companyId, $ruleId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok([]);
