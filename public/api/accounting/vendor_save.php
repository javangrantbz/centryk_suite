<?php
/** Create or update a vendor. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/ExpensesService.php';

[$userId, $companyId, $in] = accounting_guard(true);

try {
    if (!empty($in['archive'])) {
        ExpensesService::archiveVendor($companyId, (int)($in['id'] ?? 0), $userId);
        Response::ok([]);
    }
    $id = ExpensesService::saveVendor($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['vendor_id' => $id]);
