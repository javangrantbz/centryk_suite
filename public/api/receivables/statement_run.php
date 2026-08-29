<?php
/** Month-end: email a statement to every customer with a balance.
 *  Body: { company_id, mode? }  mode = 'all' (default) | 'overdue' */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

$mode = ($in['mode'] ?? 'all') === 'overdue' ? 'overdue' : 'all';

Response::ok(ReceivablesService::statementRun($companyId, $userId, $mode));
