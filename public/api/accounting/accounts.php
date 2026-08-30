<?php
/** Chart of accounts + control-slot map + slot catalogue. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/core/Ledger.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$accounts = AccountingService::accounts($companyId, [
    'active_only'   => !empty($in['active_only']),
    'postable_only' => !empty($in['postable_only']),
]);

$slots = [];
foreach (AccountingService::SLOTS as $slot => [$label, $required]) {
    $slots[] = ['slot' => $slot, 'label' => $label, 'required' => $required];
}

Response::ok([
    'activated' => Ledger::isActivated($companyId),
    'accounts'  => $accounts,
    'map'       => AccountingService::accountMap($companyId),
    'slots'     => $slots,
    'unmapped'  => AccountingService::unmappedRequiredSlots($companyId),
]);
