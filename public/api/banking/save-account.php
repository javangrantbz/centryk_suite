<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

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

// The company's own settlement account is self-service: any admin of the
// company may set it.
$check = $pdo->prepare("
    SELECT id FROM company_members
    WHERE company_id = :cid AND user_id = :uid AND role = 'admin' AND status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => $user['id']]);
if (!$check->fetch()) {
    Response::error('Permission denied.', 403);
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

Response::ok(['message' => 'Banking information saved.']);
