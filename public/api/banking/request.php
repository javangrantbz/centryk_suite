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

// Only an admin of the company may request card-acceptance setup.
$check = $pdo->prepare("
    SELECT c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.company_id = :cid AND cm.user_id = :uid AND cm.role = 'admin' AND cm.status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => $user['id']]);
$company = $check->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Permission denied.', 403);
}

// One open request at a time per company.
$dup = $pdo->prepare("SELECT id FROM banking_requests WHERE company_id = :cid AND status = 'pending' LIMIT 1");
$dup->execute(['cid' => $companyId]);
if ($dup->fetch()) {
    Response::ok(['message' => 'A request is already pending for this company.', 'already' => true]);
}

$ins = $pdo->prepare("INSERT INTO banking_requests (company_id, requested_by) VALUES (:cid, :uid)");
$ins->execute(['cid' => $companyId, 'uid' => (int)$user['id']]);

// Notify every Centryk platform admin.
$companyName = (string)($company['name'] ?? 'A company');
$requester   = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
$admins = $pdo->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($admins as $adminId) {
    NotificationService::create([
        'user_id'    => (int)$adminId,
        'company_id' => $companyId,
        'app_key'    => 'centryk',
        'type'       => 'banking_request',
        'title'      => 'Card payment setup requested',
        'body'       => trim($requester) . ' requested OneLink card acceptance for ' . $companyName . '.',
        'url'        => 'onelink-api-accounts.php',
        'icon'       => 'landmark',
        'color'      => '#06b6d4',
    ]);
}

Response::ok(['message' => 'Request sent. A Centryk admin will set up card payments for your company.']);
