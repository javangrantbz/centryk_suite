<?php
/**
 * One-off maintenance script: re-syncs OneLink credentials to OnePay for
 * every enabled company, correcting payment_settings.webhook_secret rows
 * that still hold the old (wrong) sSalt instead of the correct onelink_uuid.
 *
 * Background: OnePayWebhook::onelinkCredentialsSynced() used to push
 * onelink_credentials.salt (the sSalt we generated at account creation) to
 * OnePay as "salt" - but OneLink's API actually authenticates against the
 * u_uuid it assigned (onelink_uuid), not that self-generated value. The
 * function is now fixed to prefer onelink_uuid, but that fix only affects
 * *future* syncs - any company already provisioned before the fix still has
 * the wrong value sitting in OnePay's payment_settings table. This script
 * re-triggers the sync (now correct) for every already-enabled company, so
 * nothing needs to be manually re-saved through the admin UI one at a time.
 *
 * Prerequisites before running:
 *   1. The OnePayWebhook.php fix (onelink_uuid preferred over salt) must
 *      already be deployed on this server.
 *   2. ONEPAY_SYNC_URL and ONEPAY_WEBHOOK_SECRET must be set in .env and
 *      reachable from this server (the script is a silent no-op per company
 *      otherwise - see onelinkCredentialsSynced()'s early return).
 *
 * Usage (from the centryk repo root on the server):
 *   php scripts/resync_onelink_credentials.php
 *
 * Safe to run multiple times (each sync call is idempotent - it just
 * re-pushes the current, now-correct row). Delete this file once you've
 * confirmed the fix landed everywhere; it's a one-time correction, not a
 * script that should stay in place.
 */

require_once __DIR__ . '/../app/core/Env.php';
Env::load(__DIR__ . '/../.env');
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/OnePayWebhook.php';

$pdo = DB::pdo();

$rows = $pdo->query("
    SELECT c.id, c.name, o.terminal_id, o.salt, o.onelink_uuid
    FROM companies c
    JOIN onelink_credentials o ON o.company_id = c.id
    WHERE o.enabled = 1
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No enabled OneLink companies found - nothing to do.\n";
    exit(0);
}

echo "Re-syncing " . count($rows) . " company(ies)...\n\n";

foreach ($rows as $row) {
    $correctSalt = $row['onelink_uuid'] !== null && $row['onelink_uuid'] !== ''
        ? $row['onelink_uuid']
        : $row['salt'];

    $wasWrong = $row['onelink_uuid'] !== null && $row['onelink_uuid'] !== '' && $row['salt'] !== $row['onelink_uuid'];

    printf(
        "- [%d] %s (terminal %s): %s\n",
        $row['id'],
        $row['name'],
        $row['terminal_id'],
        $wasWrong ? "was wrong (salt=$row[salt]) -> pushing onelink_uuid=$correctSalt" : 'already correct, re-pushing anyway'
    );

    OnePayWebhook::onelinkCredentialsSynced($pdo, (int)$row['id']);
}

echo "\nDone. Check OnePay's payment_settings.webhook_secret for each store above to confirm.\n";
