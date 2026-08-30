<?php
/**
 * Daily Centryk Business sweep — notifies company admins about customer
 * invoices that crossed an overdue milestone, and platform admins about
 * subscription charges that fell overdue.
 *
 *   php scripts/business_daily.php
 *
 * Run once a day from a system cron / Cloudways scheduled task. Fires on the
 * exact day an item crosses a threshold, so it won't spam.
 */
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/services/BusinessNotifier.php';
require __DIR__ . '/../app/services/ReceivablesService.php';

// Post any OnePay electronic payments that haven't hit the AR ledger yet,
// for every company that runs Receivables.
$posted = 0;
$cos = DB::pdo()->query("
    SELECT DISTINCT company_id FROM company_entitlements
    WHERE package_key = 'receivables' AND state <> 'revoked'
")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cos as $cid) {
    try {
        $s = ReceivablesService::syncOnepayReceipts((int) $cid, null);
        $posted += $s['created'];
    } catch (Throwable $e) {
        error_log('[business_daily] onepay sync failed for company ' . $cid . ': ' . $e->getMessage());
    }
}

$r = BusinessNotifier::runDaily();
echo 'Business daily sweep: ' . $posted . ' OnePay receipt(s) posted, '
    . $r['invoice_alerts'] . ' invoice alert(s), '
    . ($r['cheque_alerts'] ?? 0) . ' cheque-due alert(s), '
    . $r['billing_alerts'] . " billing alert(s).\n";
