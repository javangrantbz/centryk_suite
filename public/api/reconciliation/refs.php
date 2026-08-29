<?php
/** Open invoices with their payment reference, to share with customers. */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[, $companyId] = business_guard('reconciliation', false);

Response::ok(['invoices' => ReconciliationService::paymentRefs($companyId)]);
