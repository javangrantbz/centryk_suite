<?php
/**
 * Materialise the current month's Centryk Business subscription charges.
 * Idempotent — safe to run daily; it only adds charges that don't exist yet.
 *
 *   php scripts/run_billing_cycle.php            # current month
 *   php scripts/run_billing_cycle.php 2026-09-15 # month containing that date
 *
 * Wire to a monthly (or daily) system cron / Cloudways scheduled task.
 */
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/services/BillingService.php';

$asOf = $argv[1] ?? null;
$r = BillingService::runCycle($asOf, null);
echo "Billing cycle {$r['month']}: {$r['created']} charge(s) created, {$r['skipped']} skipped.\n";

$d = BillingService::runDunning($asOf, null);
echo "Dunning {$d['as_of']}: {$d['past_due']} moved to past-due, {$d['recovered']} recovered.\n";
