<?php
require_once __DIR__ . '/business_guard.php';

/**
 * Accounting endpoint gate — thin wrapper over business_guard() for the
 * 'accounting' package. See business_guard.php.
 *
 * @return array{0:int,1:int,2:array}  [userId, companyId, decodedBody]
 */
function accounting_guard(bool $writing): array
{
    return business_guard('accounting', $writing);
}
