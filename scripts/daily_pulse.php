<?php
/**
 * Daily "pulse" — Centryk notifications for public holidays (everyone) and
 * staff birthdays / work anniversaries (that person's company). Display only.
 *
 *   php scripts/daily_pulse.php
 *
 * Wire to a Cloudways scheduled task, once a day ~06:00 America/Belize. Safe to
 * run repeatedly — every batch is idempotent per (event, date). The dashboard
 * also self-primes this via api/pulse/tick.php, so a missed cron day is covered
 * as long as someone logs in.
 */
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/services/DailyPulse.php';

$r = DailyPulse::run();
echo sprintf(
    "Daily pulse: %d holiday(s), %d heads-up, %d birthday(s), %d anniversary(ies) — %d notification(s) sent.\n",
    $r['holidays'],
    $r['headsup'],
    $r['birthdays'],
    $r['anniversaries'],
    $r['notifs']
);
