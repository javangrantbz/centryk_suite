<?php
/** Pay an outstanding bill. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/ExpensesService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$expenseId = (int)($in['expense_id'] ?? 0);
if ($expenseId <= 0) {
    Response::error('expense_id is required.', 422);
}

try {
    ExpensesService::payExpense($companyId, $expenseId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
