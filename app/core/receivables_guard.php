<?php
require_once __DIR__ . '/business_guard.php';

/**
 * Receivables endpoint gate — thin wrapper over business_guard() for the
 * 'receivables' package. See business_guard.php.
 *
 * @return array{0:int,1:int,2:array}  [userId, companyId, decodedBody]
 */
function receivables_guard(bool $writing): array
{
    return business_guard('receivables', $writing);
}
