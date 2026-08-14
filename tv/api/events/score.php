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
    TvManagementService::updateSportsScore(
        (int)tv_active_organization()['id'],
        (int)($payload['event_id'] ?? 0),
        (int)($payload['home_score'] ?? 0),
        (int)($payload['away_score'] ?? 0),
        (int)$user['id']
    );
    Response::ok(['data' => $payload]);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 422);
}

