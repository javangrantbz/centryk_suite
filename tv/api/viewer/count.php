<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$eventId = (int)($_GET['event_id'] ?? 0);
if ($eventId <= 0) {
    Response::error('event_id is required.');
}

Response::ok(['data' => ['viewer_count' => TvMetricsService::currentViewerCount($eventId)]]);

