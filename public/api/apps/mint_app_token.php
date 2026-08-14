<?php
/**
 * Mint a one-time SSO token for a NAMED target app, on behalf of a known
 * Centryk user. Server-to-server only. Generalises mint_calendar_token.php
 * (which always mints for 'calendar_embed') for the Centryk PWA's need to
 * log its own backend into a spoke (e.g. MyPay) on the user's behalf, since
 * that spoke's own APIs only accept its own browser session - there's no
 * other server-to-server door into them.
 *
 * POST { caller: 'centryk_pwa', secret: '<CENTRYK_PWA_SECRET>', email, target_app: 'mypay' }
 * Returns: { success, token }
 *
 * Same trust boundary as the existing provisioning/calendar-embed endpoints -
 * a caller can only mint tokens for the specific target apps it's allow-listed
 * for below, never an arbitrary app, so a leaked PWA secret can't be used to
 * mint tokens into apps it was never meant to reach.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$caller    = trim((string)($body['caller']     ?? ''));
$secret    = trim((string)($body['secret']     ?? ''));
$email     = strtolower(trim((string)($body['email'] ?? '')));
$targetApp = trim((string)($body['target_app'] ?? ''));

// caller => [expected secret env var, allow-listed target app_keys]
$callers = [
    'centryk_pwa' => [
        'secret'  => $_ENV['CENTRYK_PWA_SECRET'] ?? '',
        'targets' => ['mypay', 'onepay'],
    ],
];

$callerConfig = $callers[$caller] ?? null;
if (!$callerConfig || $callerConfig['secret'] === '' || $secret === '' || !hash_equals($callerConfig['secret'], $secret)) {
    Response::error('Unauthorized.', 401);
}
if (!in_array($targetApp, $callerConfig['targets'], true)) {
    Response::error('This caller is not allowed to mint tokens for that app.', 403);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('A valid email is required.');
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND status = "active" LIMIT 1');
$stmt->execute(['email' => $email]);
$userId = (int)($stmt->fetchColumn() ?: 0);

if (!$userId) {
    Response::error('No active Centryk account for that email.', 404);
}

$token = Auth::issueToken($userId, $targetApp);

Response::ok(['token' => $token]);
