<?php
require_once __DIR__ . '/../../../../app/core/Auth.php';
require_once __DIR__ . '/../../../../app/core/Response.php';
require_once __DIR__ . '/../../../../app/services/MyPayClient.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Not logged in.', 401);
}

function mobile_mypay_cookie(int $userId, bool $forceRelogin = false): ?string
{
    if (!$forceRelogin && !empty($_SESSION['mobile_mypay_cookie'])) {
        return $_SESSION['mobile_mypay_cookie'];
    }
    $cookie = (new MyPayClient())->login($userId);
    if ($cookie) {
        $_SESSION['mobile_mypay_cookie'] = $cookie;
    }
    return $cookie;
}

$cookie = mobile_mypay_cookie((int)$user['id']);
if (!$cookie) {
    Response::error('Could not connect to MyPay.', 502);
}

$client = new MyPayClient();
$requests = $client->listLeaveRequests($cookie);

// Session may have expired server-side on MyPay's end since we last logged
// in - one retry with a fresh login before giving up.
if ($requests === null) {
    $cookie = mobile_mypay_cookie((int)$user['id'], true);
    $requests = $cookie ? $client->listLeaveRequests($cookie) : null;
}

if ($requests === null) {
    Response::error('Could not load HR requests from MyPay.', 502);
}

Response::ok(['requests' => $requests]);
