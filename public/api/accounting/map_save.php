<?php
/** Bind a control-account slot to a GL account. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$slot      = (string)($in['slot'] ?? '');
$accountId = (int)($in['account_id'] ?? 0);
if ($slot === '' || $accountId <= 0) {
    Response::error('slot and account_id are required.', 422);
}

try {
    AccountingService::setMap($companyId, $slot, $accountId, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['unmapped' => AccountingService::unmappedRequiredSlots($companyId)]);
