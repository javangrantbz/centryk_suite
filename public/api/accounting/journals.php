<?php
/** List journal entries with filters. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(false);

$rows = AccountingService::journals($companyId, [
    'from'           => $in['from'] ?? null,
    'to'             => $in['to'] ?? null,
    'source'         => $in['source'] ?? null,
    'account_id'     => $in['account_id'] ?? null,
    'q'              => $in['q'] ?? null,
    'limit'          => $in['limit'] ?? 50,
    'offset'         => $in['offset'] ?? 0,
    'include_drafts' => !empty($in['include_drafts']),
]);

Response::ok(['journals' => $rows]);
