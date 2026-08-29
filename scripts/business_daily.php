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
require __DIR__ . '/../app/services/BusinessNotifier.php';

$r = BusinessNotifier::runDaily();
echo 'Business daily sweep: ' . $r['invoice_alerts'] . ' invoice alert(s), '
    . $r['billing_alerts'] . " billing alert(s).\n";
