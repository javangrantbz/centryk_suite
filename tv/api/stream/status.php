<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    Response::error('event_id is required.');
}

$stmt = db()->prepare('SELECT * FROM tv_events WHERE id = :event_id LIMIT 1');
$stmt->execute(['event_id' => $eventId]);
$event = $stmt->fetch();
if (!$event) {
    Response::error('Event not found.', 404);
}

Response::ok(['data' => ['status' => StreamingService::getStreamStatus($event)]]);

