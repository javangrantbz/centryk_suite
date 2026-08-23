<?php
/**
 * Called by the VPS-side recording job (docs/streaming-server.md's "Replay/
 * VOD recording" section) once it has finished remuxing a completed
 * publish's raw recording to a servable file, or given up trying.
 *
 * POST: key (shared secret), stream_key (the raw key, same one used for
 * ingest - identifies which session's recording this is), status
 * ('available' | 'failed'), replay_path (required when status is
 * 'available' - relative to STREAM_PLAYBACK_BASE_URL).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_stream_origin_secret();

$rawKey = trim((string)($_POST['stream_key'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$replayPath = trim((string)($_POST['replay_path'] ?? ''));

if ($rawKey === '' || !in_array($status, ['available', 'failed'], true)) {
    Response::error('stream_key and a valid status are required.', 400);
}

$key = StreamingService::finalizeReplay($rawKey, $status, $replayPath !== '' ? $replayPath : null);
if (!$key) {
    Response::error('Unknown stream key, or a missing replay_path for an available replay.', 400);
}

Response::ok();
