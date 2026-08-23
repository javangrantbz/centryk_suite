<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$payload = tv_json_body();
$eventId = (int)($payload['event_id'] ?? 0);
$sessionToken = trim((string)($payload['session_token'] ?? ''));
if ($eventId <= 0 || $sessionToken === '') {
    Response::error('event_id and session_token are required.');
}

$eventStmt = db()->prepare(
    'SELECT e.*, c.visibility AS channel_visibility
       FROM tv_events e
       JOIN tv_channels c ON c.id = e.channel_id
      WHERE e.id = :id LIMIT 1'
);
$eventStmt->execute(['id' => $eventId]);
$event = $eventStmt->fetch();
if (!$event) {
    Response::error('Event not found.', 404);
}

$user = tv_user();
if (!tv_can_watch_event($event, $user)) {
    Response::error('Unauthorized.', 403);
}

$count = TvMetricsService::recordHeartbeat($eventId, $user ? (int)$user['id'] : null, hash('sha256', $sessionToken));
Response::ok(['data' => ['viewer_count' => $count]]);

