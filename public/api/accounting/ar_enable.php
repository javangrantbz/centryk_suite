<?php
/** Switch on AR ledger posting and take the opening balance. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/GlSync.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

$openingDate = (string)($in['opening_date'] ?? date('Y-m-d'));

try {
    $result = GlSync::enableAr($companyId, $openingDate, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::error('Could not switch on AR posting.', 500);
}

Response::ok(['result' => $result, 'summary' => AccountingService::deskSummary($companyId)]);
