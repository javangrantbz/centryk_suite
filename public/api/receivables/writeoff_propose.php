<?php
/** Propose a write-off / credit adjustment on an open invoice.
 *  Body: { company_id, invoice_id, amount, kind?, reason? } */
require_once __DIR__ . '/../../../app/core/receivables_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = receivables_guard(true);

try {
    $id = ReceivablesService::proposeWriteoff($companyId, $in, $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['writeoff_id' => $id]);
