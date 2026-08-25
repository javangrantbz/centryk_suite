<?php
/**
 * One-off diagnostic for the TV_COMING_SOON_ALLOWLIST not matching as
 * expected. Requires being logged in already (reads the real session).
 * DELETE THIS FILE once the mismatch is found - it's not meant to stay
 * reachable.
 */
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$user = tv_user();
$rawEnvValue = (string)($_ENV['TV_COMING_SOON_ALLOWLIST'] ?? '(not set in $_ENV at all)');
$configValue = (string)tv_config('coming_soon_allowlist');

echo "is production: " . (Env::isProduction() ? 'yes' : 'no') . "\n\n";

echo "logged in: " . ($user ? 'yes' : 'NO - you are not logged in as far as this script can tell') . "\n";
if ($user) {
    echo "your email (raw):        " . json_encode($user['email'] ?? '(none)') . "\n";
    echo "your email (normalized): " . json_encode(strtolower(trim((string)($user['email'] ?? '')))) . "\n";
}

echo "\nTV_COMING_SOON_ALLOWLIST raw \$_ENV value: " . json_encode($rawEnvValue) . "\n";
echo "tv_config('coming_soon_allowlist') value:  " . json_encode($configValue) . "\n";

$allowlist = array_filter(array_map(
    static fn (string $e): string => strtolower(trim($e)),
    explode(',', $configValue)
));
echo "parsed allowlist entries: " . json_encode(array_values($allowlist)) . "\n";

$email = strtolower(trim((string)($user['email'] ?? '')));
$match = $email !== '' && in_array($email, $allowlist, true);
echo "\nWOULD BYPASS COMING SOON: " . ($match ? 'YES' : 'NO') . "\n";
