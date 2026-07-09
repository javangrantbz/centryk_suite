<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/NotificationService.php';

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

// The company's own settlement account is self-service for any admin of the
// company; Centryk platform admins may also set it for any company.
if (empty($user['is_admin'])) {
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

$bankName      = trim((string)($in['bank_name'] ?? ''));
$accountHolder = trim((string)($in['account_holder'] ?? ''));
$accountNumber = trim((string)($in['account_number'] ?? ''));
$branch        = trim((string)($in['branch'] ?? ''));

if ($bankName === '' || $accountHolder === '' || $accountNumber === '') {
    Response::error('Bank name, account holder and account number are required.', 422);
}

$stmt = $pdo->prepare("
    INSERT INTO company_bank_accounts
        (company_id, bank_name, account_holder, account_number, branch)
    VALUES
        (:cid, :bank_name, :account_holder, :account_number, :branch)
    ON DUPLICATE KEY UPDATE
        bank_name      = VALUES(bank_name),
        account_holder = VALUES(account_holder),
        account_number = VALUES(account_number),
        branch         = VALUES(branch)
");
$stmt->execute([
    'cid'            => $companyId,
    'bank_name'      => $bankName,
    'account_holder' => $accountHolder,
    'account_number' => $accountNumber,
    'branch'         => $branch,
]);

// Card-acceptance intent, expressed via the "I want to accept payments via
// OneLink" checkbox. Only meaningful for company self-service (not platform
// admins, who configure the gateway directly).
$requested = false;
if (empty($user['is_admin'])) {
    $accept = !empty($in['accept_onelink']);

    $gwStmt = $pdo->prepare("SELECT enabled FROM onelink_credentials WHERE company_id = :cid LIMIT 1");
    $gwStmt->execute(['cid' => $companyId]);
    $gatewayEnabled = ((int)($gwStmt->fetchColumn() ?: 0) === 1);

    $pendStmt = $pdo->prepare("SELECT id FROM banking_requests WHERE company_id = :cid AND status = 'pending' LIMIT 1");
    $pendStmt->execute(['cid' => $companyId]);
    $hasPending = (bool)$pendStmt->fetch();

    if ($accept) {
        // Opt-in: file a request the first time (skip if already active/pending).
        if (!$gatewayEnabled && !$hasPending) {
            $pdo->prepare("INSERT INTO banking_requests (company_id, requested_by) VALUES (:cid, :uid)")
                ->execute(['cid' => $companyId, 'uid' => (int)$user['id']]);
            $requested = true;

            $nameStmt = $pdo->prepare("SELECT name FROM companies WHERE id = :cid LIMIT 1");
            $nameStmt->execute(['cid' => $companyId]);
            $companyName = (string)($nameStmt->fetchColumn() ?: 'A company');
            $requester   = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
            $admins = $pdo->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                NotificationService::create([
                    'user_id'    => (int)$adminId,
                    'company_id' => $companyId,
                    'app_key'    => 'centryk',
                    'type'       => 'banking_request',
                    'title'      => 'Card payment setup requested',
                    'body'       => trim($requester) . ' wants to accept OneLink card payments for ' . $companyName . '.',
                    'url'        => 'onelink-api-accounts.php',
                    'icon'       => 'landmark',
                    'color'      => '#06b6d4',
                ]);
            }
        }
    } else {
        // Opt-out: withdraw any pending request.
        if ($hasPending) {
            $pdo->prepare("UPDATE banking_requests SET status = 'dismissed' WHERE company_id = :cid AND status = 'pending'")
                ->execute(['cid' => $companyId]);
        }
    }
}

Response::ok(['message' => 'Banking information saved.', 'requested' => $requested]);
