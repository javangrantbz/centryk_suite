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
  event live state, and flipping opted-in events to `replay_status =
  'processing'`
- verify a playback token in constant time (`verifyPlaybackToken()`)
- report stream status, preferring the real `is_publishing` signal from
  ingest over the editorial event status once a stream key has ever
  reported in, falling back to the mocked status mapping otherwise
- finalize a replay once the VPS-side recording job reports in
  (`finalizeReplay()`), and generate its own signed playback URL
  (`getReplayUrl()`)

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

## Replay flow (built, VPS-local disk)

1. `on_publish_done.php` marks an opted-in event's `replay_status`
   `processing` the moment the stream ends.
2. A cron job on the streaming server processes the raw recording nginx-rtmp
   already wrote, checking `api/stream/should_record_replay.php` first so a
   non-opted-in event's raw recording is deleted rather than remuxed.
3. If the event wants a replay, FFmpeg remuxes it and the job posts to
   `api/stream/replay_status.php` with `status=available` and where the
   result landed; a failed remux posts `status=failed` instead.
4. `finalizeReplay()` sets `tv_events.replay_url`/`replay_status`
   accordingly. `watch.php` then serves `getReplayUrl()`'s signed URL (same
   HMAC scheme as live, 6-hour ttl) instead of attempting live playback for
   an ended event with an available replay.

See `streaming-server.md`'s "Replay/VOD recording" section for the concrete
nginx `record` config and the cron script.

## Shared-secret auth for server-to-server callbacks

`api/stream/on_publish.php`, `on_publish_done.php`, `authorize_playback.php`,
`replay_status.php`, and `should_record_replay.php` are all called by the
streaming server itself, never by a logged-in browser session, so
`Auth::user()` doesn't apply. `tv_require_stream_origin_secret()`
(`includes/functions.php`) gates all five: it checks `STREAM_API_KEY` either
as an `Authorization: Bearer` header or a `key` request field, and fails
closed (401) if the secret is unconfigured or wrong.

## Payment flow (built, one-time `paid` access only)

1. `watch.php` redirects a signed-in viewer to `paywall.php` instead of a
   flat 403 when `tv_can_watch_event()` fails specifically because the
   channel is `paid` and the event has a price.
2. `paywall.php` posts card details to `api/payments/charge_for_access.php`,
   which calls `TvPaymentService::chargeForEventAccess()`.
3. That charges OneLink directly using the organization's company-level
   `onelink_credentials` (see `streaming-server.md`'s "Pay-per-event access"
   section for why this doesn't go through OnePay).
4. Only a confirmed OneLink success inserts a `tv_event_access` row -
   `tv_can_watch_event()` needs no changes for the paid case, since a
   successful payment is indistinguishable from any other private-grant
   access it already checks.

`subscription` visibility is explicitly out of scope - see
`streaming-server.md`.

## Remaining integration points

- A real health/status endpoint on the streaming origin, so
  `getStreamStatus()` can also detect a hard-crashed origin process
  (`on_publish_done` only fires on a clean disconnect).
- Automatic replay retention/expiry - nothing currently cleans up old
  recordings on VPS-local disk.
- Recurring billing for `subscription` channel visibility.
- The OneLink success path for `charge_for_access.php` wasn't verified
  against a real merchant account in development - only the failure path
  was exercised live.
