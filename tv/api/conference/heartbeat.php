<?php
/**
 * Presence ping from the conference room page. POST { token, leave?: bool }.
 * Keeps the conference "active" for the header pill and returns who is in the
 * room. Only invited participants may call it.
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
if ($conf['status'] === 'ended') {
    Response::ok(['ended' => true, 'participants' => []]);
}

if (!empty($payload['leave'])) {
    ConferenceService::leave($conf, (int)$user['id']);
} else {
    ConferenceService::heartbeat($conf, (int)$user['id']);
}

Response::ok(['ended' => false, 'participants' => ConferenceService::participantsNow((int)$conf['event_id'])]);
