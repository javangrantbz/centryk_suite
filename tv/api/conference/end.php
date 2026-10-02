<?php
/**
 * Host ends the conference for everyone. POST { token }. Creator only.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../../app/services/ConferenceService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$user = tv_user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$payload = tv_json_body();
$conf = ConferenceService::accessFor((int)$user['id'], (string)($payload['token'] ?? ''));
if (!$conf) {
    Response::error('Unauthorized.', 403);
}
if (!ConferenceService::end($conf, (int)$user['id'])) {
    Response::error('Only the host can end the conference.', 403);
}

Response::ok(['ended' => true]);
