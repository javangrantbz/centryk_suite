# Centryk TV Streaming Integration

## Current app contract

The PHP application already abstracts streaming behind `StreamingService`.

Available responsibilities:

- issue and rotate stream keys
- resolve ingest URL
- generate signed playback URLs
- expose mock stream status

## Expected future playback flow

1. Viewer opens a watch page.
2. The app checks event visibility and user access.
3. The app generates a short-lived playback URL.
4. The playback origin validates `expires` and `token`.
5. If valid, HLS playback is served.

## Token format

The current app generates:

- `expires`: unix timestamp
- `token`: `hash_hmac('sha256', event_id . '|' . expires, STREAM_SIGNING_SECRET)`

The streaming server should verify those values against the same secret.

## Future integration points

- `getStreamStatus()` can move from mock values to live origin polling.
- `getPlaybackUrl()` can add per-channel pathing or event-specific manifests.
- `generatePlaybackToken()` can be mirrored in NGINX Lua, njs, or an SRS hook service.
- `issueStreamKey()` can later push or validate keys against the streaming origin API.

