<?php
/** List expenses / bills, plus header totals. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/core/Ledger.php';
require_once __DIR__ . '/../../../app/services/ExpensesService.php';

[$userId, $companyId, $in] = accounting_guard(false);

Response::ok([
    'activated' => Ledger::isActivated($companyId),
    'expenses'  => ExpensesService::expenses($companyId, [
        'from'      => $in['from'] ?? null,
        'to'        => $in['to'] ?? null,
        'status'    => $in['status'] ?? null,
        'vendor_id' => $in['vendor_id'] ?? null,
        'q'         => $in['q'] ?? null,
        'limit'     => $in['limit'] ?? 100,
        'offset'    => $in['offset'] ?? 0,
    ]),
    'summary' => ExpensesService::summary($companyId),
]);
