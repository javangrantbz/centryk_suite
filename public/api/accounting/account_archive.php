<?php
/** Make a chart-of-accounts row inactive. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$accountId = (int)($in['account_id'] ?? 0);
if ($accountId <= 0) {
    Response::error('account_id is required.', 422);
}

try {
    AccountingService::archiveAccount($companyId, $accountId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
