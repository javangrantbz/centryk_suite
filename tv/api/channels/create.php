<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = tv_require_organization();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}
if (!tv_role_at_least('admin')) {
    Response::error('Admin access required.', 403);
}

tv_verify_csrf();

try {
    $channel = TvManagementService::createChannel((int)tv_active_organization()['id'], (int)$user['id'], $_POST);
    Response::ok(['data' => $channel]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}

