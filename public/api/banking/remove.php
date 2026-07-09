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

// Removing gateway credentials is a platform-admin action, same as saving them.
if (empty($user['is_admin'])) {
    Response::error('Only a Centryk administrator can remove the payment gateway.', 403);
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

// Blank + disable first so the push tells OnePay to clear and deactivate this
// store's payment_settings, then drop the Centryk row entirely.
$pdo->prepare("
    UPDATE onelink_credentials
    SET terminal_id = '', salt = '', token = '', enabled = 0
    WHERE company_id = :cid
")->execute(['cid' => $companyId]);

OnePayWebhook::onelinkCredentialsSynced($pdo, $companyId);

$pdo->prepare("DELETE FROM onelink_credentials WHERE company_id = :cid")->execute(['cid' => $companyId]);

Response::ok(['message' => 'OneLink gateway removed.']);
