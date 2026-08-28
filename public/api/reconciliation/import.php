<?php
/** Import bank statement CSV text. Body: { company_id, csv, filename?, mapping? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';
require_once __DIR__ . '/../../../app/services/ReconciliationService.php';

[$userId, $companyId, $in] = business_guard('reconciliation', true);

$csv = (string)($in['csv'] ?? '');
if (strlen($csv) > 2_000_000) {
    Response::error('That file is too large (2 MB max).', 422);
}
$mapping = is_array($in['mapping'] ?? null) ? $in['mapping'] : [];

try {
    $result = ReconciliationService::import($companyId, $csv, $mapping, $userId, (string)($in['filename'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::error('Could not import the file.', 500);
}

Response::ok($result);
