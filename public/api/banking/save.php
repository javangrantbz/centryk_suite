<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OnePayWebhook.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$companyId = isset($in['company_id']) ? (int)$in['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();

// OneLink gateway credentials (terminal id, salt, token) are sensitive
// infrastructure — only a Centryk platform admin may set them.
if (empty($user['is_admin'])) {
    Response::error('Only a Centryk administrator can configure the payment gateway.', 403);
}

$baseUrl = trim((string)($in['base_url'] ?? ''));
if ($baseUrl === '') {
    $baseUrl = 'https://op.onelink.bz';
}
if (!preg_match('#^https://#i', $baseUrl)) {
    Response::error('Base URL must start with https://', 422);
}

$terminalId = trim((string)($in['terminal_id'] ?? ''));
// Secrets: an empty submitted value means "keep the stored one".
$salt  = (string)($in['salt'] ?? '');
$token = (string)($in['token'] ?? '');
$enabled = !empty($in['enabled']) ? 1 : 0;

$existing = $pdo->prepare("SELECT salt, token FROM onelink_credentials WHERE company_id = :cid LIMIT 1");
$existing->execute(['cid' => $companyId]);
$ex = $existing->fetch(PDO::FETCH_ASSOC) ?: [];

$saltVal  = ($salt === '')  ? (string)($ex['salt']  ?? '') : $salt;
$tokenVal = ($token === '') ? (string)($ex['token'] ?? '') : $token;

// All three credentials must be present before OneLink can be switched on.
if ($enabled && ($terminalId === '' || $saltVal === '' || $tokenVal === '')) {
    Response::error('Terminal ID, salt and token are all required to enable OneLink.', 422);
}

$stmt = $pdo->prepare("
    INSERT INTO onelink_credentials
        (company_id, base_url, terminal_id, salt, token, enabled)
    VALUES
        (:cid, :base_url, :terminal_id, :salt, :token, :enabled)
    ON DUPLICATE KEY UPDATE
        base_url    = VALUES(base_url),
        terminal_id = VALUES(terminal_id),
        salt        = VALUES(salt),
        token       = VALUES(token),
        enabled     = VALUES(enabled)
");
$stmt->execute([
    'cid'         => $companyId,
    'base_url'    => $baseUrl,
    'terminal_id' => $terminalId,
    'salt'        => $saltVal,
    'token'       => $tokenVal,
    'enabled'     => $enabled,
]);

// Push the credentials to OnePay so its POS can charge cards. Fire-and-forget:
// never blocks or fails the save (no-op unless ONEPAY_SYNC_URL is configured).
OnePayWebhook::onelinkCredentialsSynced($pdo, $companyId);

Response::ok([
    'message'   => 'Banking settings saved.',
    'enabled'   => (bool)$enabled,
    'salt_set'  => $saltVal !== '',
    'token_set' => $tokenVal !== '',
]);
