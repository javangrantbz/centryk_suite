<?php
/** Record an expense / bill (posts a journal). */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/ExpensesService.php';

[$userId, $companyId, $in] = accounting_guard(true);

try {
    $id = ExpensesService::saveExpense($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not record the expense.', 500);
}

Response::ok(['expense_id' => $id]);
