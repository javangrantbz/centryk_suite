<?php
require_once __DIR__ . '/../../../../app/core/Auth.php';
require_once __DIR__ . '/../../../../app/core/Response.php';
require_once __DIR__ . '/../../../../app/services/MyPayClient.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Not logged in.', 401);
}

$data   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = (int)($data['id'] ?? 0);
$action = (string)($data['action'] ?? '');
$notes  = trim((string)($data['notes'] ?? ''));

if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    Response::error('Invalid request.', 422);
}

if (empty($_SESSION['mobile_mypay_cookie'])) {
    $cookie = (new MyPayClient())->login((int)$user['id']);
    if (!$cookie) {
        Response::error('Could not connect to MyPay.', 502);
    }
    $_SESSION['mobile_mypay_cookie'] = $cookie;
}

$client = new MyPayClient();
$result = $action === 'approve'
    ? $client->approveLeaveRequest($_SESSION['mobile_mypay_cookie'], $id, $notes)
    : $client->rejectLeaveRequest($_SESSION['mobile_mypay_cookie'], $id, $notes);

Response::json([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? null,
]);
