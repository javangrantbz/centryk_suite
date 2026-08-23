<?php
/**
 * Called by the VPS-side recording processor (docs/streaming-server.md's
 * "Replay/VOD recording" section) right after a raw recording file closes,
 * before it spends CPU on an FFmpeg remux or disk on keeping the result.
 * Recording itself happens unconditionally at the nginx-rtmp layer (there's
 * no per-connection way to toggle nginx-rtmp's own `record` directive from
 * an on_publish response) - replay is opt-in per event
 * (tv_events.is_replay_enabled), so this is where that opt-in is actually
 * enforced: the processor deletes the raw file immediately for any stream
 * key this returns false for, rather than remuxing something nobody asked
 * to keep.
 *
 * GET/POST: key (shared secret), stream_key (the raw key).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_stream_origin_secret();

$rawKey = trim((string)($_POST['stream_key'] ?? $_GET['stream_key'] ?? ''));
if ($rawKey === '') {
    Response::error('stream_key is required.', 400);
}

$stmt = db()->prepare(
    'SELECT e.id
       FROM tv_stream_keys sk
       JOIN tv_events e ON e.id = sk.event_id
      WHERE sk.stream_key_hash = :hash AND e.is_replay_enabled = 1
      LIMIT 1'
);
$stmt->execute(['hash' => hash('sha256', $rawKey)]);

Response::ok(['data' => ['should_record' => (bool)$stmt->fetchColumn()]]);
