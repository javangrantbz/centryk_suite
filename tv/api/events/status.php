<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = tv_require_organization();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}
if (!tv_role_at_least('broadcaster')) {
    Response::error('Broadcaster access required.', 403);
}

$payload = tv_json_body();

try {
    TvManagementService::updateEventStatus((int)tv_active_organization()['id'], (int)($payload['event_id'] ?? 0), (string)($payload['status'] ?? ''), (int)$user['id']);
    Response::ok(['data' => ['event_id' => (int)($payload['event_id'] ?? 0), 'status' => (string)($payload['status'] ?? '')]]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}

