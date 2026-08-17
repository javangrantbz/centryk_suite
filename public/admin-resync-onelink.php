<?php
/**
 * One-off maintenance page: re-syncs OneLink credentials to OnePay for every
 * enabled company, correcting payment_settings.webhook_secret rows that
 * still hold the old (wrong) sSalt instead of the correct onelink_uuid.
 *
 * Browser-run equivalent of scripts/resync_onelink_credentials.php, for
 * servers reachable only via SFTP (no SSH/CLI access). Gated to platform
 * admins. DELETE THIS FILE after running it once - it's a one-time data
 * correction, not a page that should stay reachable.
 */

require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/Env.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/OnePayWebhook.php';

Auth::start();
$user = Auth::user();
if (!$user || empty($user['is_admin'])) {
    http_response_code(403);
    echo 'Forbidden. Log in as a Centryk platform admin first, then reload this page.';
    exit;
}

Env::load(__DIR__ . '/../.env');
$pdo = DB::pdo();

header('Content-Type: text/plain; charset=utf-8');

$rows = $pdo->query("
    SELECT c.id, c.name, o.terminal_id, o.salt, o.onelink_uuid
    FROM companies c
    JOIN onelink_credentials o ON o.company_id = c.id
    WHERE o.enabled = 1
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "No enabled OneLink companies found - nothing to do.\n";
    exit;
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
echo "\n>>> Delete this file (admin-resync-onelink.php) now that it's run. <<<\n";
