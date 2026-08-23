<?php
/**
 * Playback authorization for the streaming server's edge - meant to be
 * called as an NGINX auth_request subrequest (or SRS's equivalent hook)
 * before every HLS manifest/segment response, not by the browser directly.
 * This is the concrete implementation of the "playback token validation"
 * responsibility docs/streaming-server.md already assigned to the streaming
 * server but never actually specified - StreamingService::getPlaybackUrl()
 * signs `expires`/`token`/`event` into every playback URL; this endpoint is
 * the other half that checks them.
 *
 * NGINX's auth_request subrequest carries the client's original query
 * string by default, so expires/token/event arrive exactly as
 * getPlaybackUrl() embedded them. A 2xx response here means NGINX serves
 * the actual HLS content; 401/403 means it doesn't.
 *
 * Note on scope: this only proves the token is a genuine, unexpired grant
 * for this event id - it does not re-check the event's current visibility.
 * If an event flips from public to private mid-flight, a token already
 * issued still validates until its (short, default 5-minute) expiry. That
 * mirrors how most signed-URL schemes behave and is an accepted tradeoff
 * for keeping this check fast and stateless; it isn't a bug, but it's why
 * getPlaybackUrl()'s ttl should stay short rather than being lengthened for
 * convenience.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_stream_origin_secret();

$eventId = (int)($_GET['event'] ?? 0);
$expires = (int)($_GET['expires'] ?? 0);
$token = (string)($_GET['token'] ?? '');

if ($eventId <= 0 || $expires <= 0 || $token === '') {
    Response::error('Missing playback credentials.', 400);
}

if (!StreamingService::verifyPlaybackToken((string)$eventId, $expires, $token)) {
    Response::error('Invalid or expired token.', 403);
}

Response::ok();
