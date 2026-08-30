<?php
/** Auto-post OnePay electronic payments to the AR ledger. Body: { company_id } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReceivablesService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

Response::ok(ReceivablesService::syncOnepayReceipts($companyId, $userId));
