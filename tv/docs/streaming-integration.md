# Centryk TV Streaming Integration

## Current app contract

The PHP application abstracts streaming behind `StreamingService`.

Available responsibilities:

- issue and rotate stream keys
- resolve ingest URL
- generate signed playback URLs
- authorize an incoming RTMP publish against a real stream key
  (`authorizePublish()`) and reject it once the assigned
  `tv_stream_servers.capacity` is reached
- record a publish ending (`recordPublishEnded()`), reverting the channel/
  event live state
- verify a playback token in constant time (`verifyPlaybackToken()`)
- report stream status, preferring the real `is_publishing` signal from
  ingest over the editorial event status once a stream key has ever
  reported in, falling back to the mocked status mapping otherwise

## Playback flow (built)

1. Viewer opens a watch page.
2. The app checks event visibility and user access (`tv_can_watch_event()`).
3. The app generates a short-lived playback URL (`getPlaybackUrl()`).
4. The playback origin validates `expires` and `token` via
   `api/stream/authorize_playback.php`, called through NGINX's
   `auth_request` (see `streaming-server.md`).
5. If valid, HLS playback is served.

## Ingest flow (built)

1. A broadcaster pastes their channel's ingest URL + raw stream key into
   OBS (or any RTMP encoder).
2. NGINX's `on_publish` callback posts the stream key to
   `api/stream/on_publish.php`.
3. The app hashes it, looks it up against `tv_stream_keys`, checks the
   assigned server's capacity, and - if valid - marks the key publishing
   and flips its bound channel/event live.
4. When the encoder stops, `on_publish_done` posts to
   `api/stream/on_publish_done.php`, which reverses step 3 and marks the
   event ended with a computed `duration_seconds`.

## Token format

The app generates:

- `expires`: unix timestamp
- `token`: `hash_hmac('sha256', event_id . '|' . expires, STREAM_SIGNING_SECRET)`

`StreamingService::verifyPlaybackToken()` recomputes this and compares with
`hash_equals()`, and separately rejects anything already past `expires`.
`api/stream/authorize_playback.php` is a thin wrapper around it for use as
an NGINX `auth_request` target.

Scope note: this proves the token is a genuine, unexpired grant for this
event id - it does not re-check the event's current visibility. A token
already issued still validates until its (short) expiry even if the event's
visibility changes in between. That mirrors how most signed-URL schemes
behave and is why `getPlaybackUrl()`'s default `$ttl` should stay short.

## Shared-secret auth for server-to-server callbacks

`api/stream/on_publish.php`, `on_publish_done.php`, and
`authorize_playback.php` are all called by the streaming server itself,
never by a logged-in browser session, so `Auth::user()` doesn't apply.
`tv_require_stream_origin_secret()` (`includes/functions.php`) gates all
three: it checks `STREAM_API_KEY` either as an `Authorization: Bearer`
header or a `key` request field, and fails closed (401) if the secret is
unconfigured or wrong.

## Remaining integration points

- A real health/status endpoint on the streaming origin, so
  `getStreamStatus()` can also detect a hard-crashed origin process
  (`on_publish_done` only fires on a clean disconnect).
- Recording/replay: no FFmpeg trigger or storage convention exists yet -
  see the "Known gaps" section of `streaming-server.md`.
- Payment/subscription verification for `paid`/`subscription` channel
  visibility - currently unenforced beyond the same grant table `private`
  visibility uses.
