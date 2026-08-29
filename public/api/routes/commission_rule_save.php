<?php
/** Create / update a commission rule (company admin).
 *  Body: { company_id, id?, scope, route_id?, driver_user_id?, basis, rate, note? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/RoutesService.php';

[$userId, $companyId, $in] = business_guard('routes', true);

$m = DB::pdo()->prepare("SELECT 1 FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' AND role = 'admin' LIMIT 1");
$m->execute(['u' => $userId, 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('Only a company admin can set commission rules.', 403);
}

try {
    $id = RoutesService::saveCommissionRule($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['rule_id' => $id]);
