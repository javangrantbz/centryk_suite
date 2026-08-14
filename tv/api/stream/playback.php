<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    Response::error('event_id is required.');
}

$stmt = db()->prepare(
    'SELECT e.*, c.slug AS channel_slug
     FROM tv_events e
     JOIN tv_channels c ON c.id = e.channel_id
     WHERE e.id = :event_id LIMIT 1'
);
$stmt->execute(['event_id' => $eventId]);
$event = $stmt->fetch();
if (!$event) {
    Response::error('Event not found.', 404);
}

$user = tv_user();
if (!tv_can_watch_event($event, $user)) {
    Response::error('Unauthorized.', 403);
}

Response::ok(['data' => ['playback_url' => StreamingService::getPlaybackUrl($event)]]);

