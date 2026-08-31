<?php
/** Open / close a period. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$periodId = (int)($in['period_id'] ?? 0);
$status   = (string)($in['status'] ?? '');
if ($periodId <= 0) {
    Response::error('period_id is required.', 422);
}

try {
    AccountingService::setPeriodStatus($companyId, $periodId, $status, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['periods' => AccountingService::periods($companyId)]);
