<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();

$isPlatformAdmin = !empty($user['is_admin']);

// Platform admins may view any company (they manage OneLink for all of them);
// otherwise the viewer must be an admin of this company.
if (!$isPlatformAdmin) {
    $check = $pdo->prepare("
        SELECT id FROM company_members
        WHERE company_id = :cid AND user_id = :uid AND role = 'admin' AND status = 'active'
        LIMIT 1
    ");
    $check->execute(['cid' => $companyId, 'uid' => $user['id']]);
    if (!$check->fetch()) {
        Response::error('Permission denied.', 403);
    }
}

// ── Settlement bank account (company self-service) ─────────────────────────
$acctStmt = $pdo->prepare("SELECT bank_name, account_holder, account_number, branch FROM company_bank_accounts WHERE company_id = :cid LIMIT 1");
$acctStmt->execute(['cid' => $companyId]);
$acct = $acctStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ── OneLink gateway (platform-admin managed) ───────────────────────────────
$gwStmt = $pdo->prepare("SELECT base_url, terminal_id, salt, token, access_code, enabled FROM onelink_credentials WHERE company_id = :cid LIMIT 1");
$gwStmt->execute(['cid' => $companyId]);
$gw = $gwStmt->fetch(PDO::FETCH_ASSOC);

$gateway = [
    'configured' => (bool)$gw,
    'enabled'    => $gw ? (bool)(int)$gw['enabled'] : false,
];
// Only platform admins see the gateway detail (terminal id + whether secrets are set).
if ($isPlatformAdmin) {
    $gateway['base_url']    = $gw['base_url']    ?? 'https://op.onelink.bz';
    $gateway['terminal_id'] = $gw['terminal_id'] ?? '';
    $gateway['salt_set']    = $gw ? ($gw['salt']  !== '') : false;
    $gateway['token_set']   = $gw ? ($gw['token'] !== '') : false;
}
// The company side doesn't need the gateway secrets, but does need its own
// access code to log into OneLink and finish setup (bank details etc).
if (!$isPlatformAdmin && $gateway['enabled'] && !empty($gw['access_code'])) {
    $gateway['access_code']     = $gw['access_code'];
    $gateway['onelink_login_url'] = 'https://op.onelink.bz/docs.php';
}

// Card-acceptance intent for the "I want to accept payments via OneLink"
// checkbox: on by default, unless the company enabled it (already on) or
// explicitly withdrew a request (latest request dismissed).
$wantsOnelink = true;
if (!($gateway['enabled'] ?? false)) {
    $reqStmt = $pdo->prepare("SELECT status FROM banking_requests WHERE company_id = :cid ORDER BY id DESC LIMIT 1");
    $reqStmt->execute(['cid' => $companyId]);
    $lastReq = $reqStmt->fetchColumn();
    if ($lastReq === 'dismissed') {
        $wantsOnelink = false;
    }
}

Response::ok([
    'is_platform_admin' => $isPlatformAdmin,
    'wants_onelink'     => $wantsOnelink,
    'account' => [
        'bank_name'      => (string)($acct['bank_name'] ?? ''),
        'account_holder' => (string)($acct['account_holder'] ?? ''),
        'account_number' => (string)($acct['account_number'] ?? ''),
        'branch'         => (string)($acct['branch'] ?? ''),
    ],
    'gateway' => $gateway,
]);
