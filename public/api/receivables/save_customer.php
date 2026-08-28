<?php
/** Create or update a customer's AR profile (name, credit limit, terms, opening balance). */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

try {
    $id = ReceivablesService::saveCustomer($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
} catch (Throwable $e) {
    Response::error('Could not save the customer.', 500);
}

Response::ok(['customer_id' => $id]);
