<?php
/** Accounting desk summary — setup state, current period, YTD P&L, drafts. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$summary = AccountingService::deskSummary($companyId);
if (empty($summary['activated'])) {
    $summary['template'] = AccountingService::templatePreview();
}

Response::ok(['summary' => $summary]);
