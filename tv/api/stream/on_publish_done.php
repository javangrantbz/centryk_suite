<?php
/**
 * on_publish_done callback for the streaming server - fires when an RTMP
 * publish ends (encoder stopped or disconnected). Counterpart to
 * on_publish.php. Always returns 2xx: nginx-rtmp doesn't act on this
 * response, and an unknown/already-ended key here just means there's
 * nothing to clean up, not an error worth rejecting anything over.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_stream_origin_secret();

$rawKey = trim((string)($_POST['name'] ?? ''));
if ($rawKey !== '') {
    StreamingService::recordPublishEnded($rawKey);
}

Response::ok();
