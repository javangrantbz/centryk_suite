<?php
/** Stop AR ledger auto-posting (posted journals are left as they are). */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/GlSync.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

GlSync::disableAr($companyId, $userId);

Response::ok(['summary' => AccountingService::deskSummary($companyId)]);
