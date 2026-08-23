<?php
/**
 * on_publish callback for the streaming server (nginx-rtmp-module's
 * `on_publish` directive, or the equivalent SRS HTTP hook). Called once per
 * RTMP publish attempt, before the encoder is allowed to start pushing
 * video - a 2xx response here allows the publish, anything else rejects and
 * disconnects it. This is the ingest-side authentication docs/
 * streaming-server.md never actually specified: without it, anyone who can
 * reach the RTMP port could publish to any stream path they can construct.
 *
 * nginx-rtmp posts the stream key as `name` (the path segment after the
 * application name, e.g. rtmp://host/live/<name>). Configure the static arg
 * `key=<STREAM_API_KEY>` alongside it so tv_require_stream_origin_secret()
 * can tell this call apart from a random request to this URL.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_stream_origin_secret();

$rawKey = trim((string)($_POST['name'] ?? ''));
$addr = trim((string)($_POST['addr'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));

if ($rawKey === '') {
    Response::error('Missing stream key.', 400);
}

try {
    $key = StreamingService::authorizePublish($rawKey, $addr !== '' ? substr($addr, 0, 45) : null);
} catch (TvStreamCapacityExceededException $e) {
    Response::error($e->getMessage(), 503);
}

if (!$key) {
    Response::error('Unknown or revoked stream key.', 403);
}

Response::ok();
