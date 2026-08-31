<?php
/** Turn on the books: config + chart + slot bindings + periods. */
require_once __DIR__ . '/../../../app/core/accounting_guard.php';
require_once __DIR__ . '/../../../app/services/AccountingService.php';

[$userId, $companyId, $in] = accounting_guard(true);

try {
    AccountingService::activate($companyId, [
        'fiscal_year_start_month' => (int)($in['fiscal_year_start_month'] ?? 1),
        'base_currency'           => (string)($in['base_currency'] ?? 'BZD'),
        'use_template'            => !array_key_exists('use_template', $in) || !empty($in['use_template']),
    ], $userId);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not set up accounting.', 500);
}

Response::ok(['summary' => AccountingService::deskSummary($companyId)]);
