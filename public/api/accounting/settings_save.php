<?php
/** Update accounting settings (fiscal year start, hard lock date, currency). */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

if (!Ledger::isActivated($companyId)) {
    Response::error('Set up accounting for this company first.', 409);
}

AccountingService::updateSettings($companyId, $in, $userId);

Response::ok(['summary' => AccountingService::deskSummary($companyId)]);
