<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = tv_require_organization();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}
if (!tv_role_at_least('broadcaster')) {
    Response::error('Broadcaster access required.', 403);
}

tv_verify_csrf();

try {
    $event = TvManagementService::createEvent((int)tv_active_organization()['id'], (int)$user['id'], $_POST);
    Response::ok(['data' => $event]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}

