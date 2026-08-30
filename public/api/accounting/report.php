<?php
/** Financial statements: trial_balance | pl | balance_sheet | gl. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$type  = (string)($in['type'] ?? '');
$today = date('Y-m-d');
$asOf  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['as_of'] ?? '')) ? $in['as_of'] : $today;
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? $in['from'] : date('Y-01-01');
$to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? $in['to'] : $today;

try {
    switch ($type) {
        case 'trial_balance':
            $report = AccountingService::trialBalance($companyId, $asOf);
            break;
        case 'pl':
            $report = AccountingService::profitAndLoss($companyId, $from, $to);
            break;
        case 'balance_sheet':
            $report = AccountingService::balanceSheet($companyId, $asOf);
            break;
        case 'gl':
            $accountId = (int)($in['account_id'] ?? 0);
            if ($accountId <= 0) {
                Response::error('account_id is required for the GL report.', 422);
            }
            $report = AccountingService::generalLedger($companyId, $accountId, $from, $to);
            break;
        default:
            Response::error('Unknown report type.', 422);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['type' => $type, 'report' => $report]);
