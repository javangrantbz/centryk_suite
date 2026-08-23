# Centryk TV Streaming Server Plan

## Target stack

- Ubuntu LTS on IONOS VPS
- NGINX plus `nginx-rtmp-module` or SRS
- RTMP ingest for OBS and compatible encoders
- HLS output for browser playback
- HTTPS in front of the playback origin
- Optional FFmpeg for recording, transmuxing, and future renditions

## Responsibilities

The streaming server should own:

- ingest endpoints
- HLS segment generation
- stream presence checks
- playback token validation
- bandwidth-heavy media delivery

The Centryk TV web app should own:

- users and organizations
- channel and event setup
- stream key lifecycle
- playback authorization
- viewer session analytics

## Ingest authentication (built)

`api/stream/on_publish.php` and `api/stream/on_publish_done.php` are the app
side of ingest authentication - nginx-rtmp-module posts to these as its
`on_publish`/`on_publish_done` callbacks, once per publish attempt and once
per disconnect. A 2xx response allows the publish; anything else rejects and
disconnects the encoder. Without this, anyone who can reach the RTMP port
could publish to any stream path they can construct - this closes that gap.

Both endpoints require the shared secret configured as `STREAM_API_KEY`,
passed as a static extra arg on the callback (see the `nginx.conf` block
below). `on_publish.php` also enforces `tv_stream_servers.capacity` (503 if
the assigned server is full) and flips the bound event to `live` the moment
a real publish is confirmed - `on_publish_done.php` reverses both when the
encoder disconnects.

Nginx-rtmp-module config (adjust `centryk.example` to the real app host):

```nginx
rtmp {
    server {
        listen 1935;
        chunk_size 4096;

        application live {
            live on;
            record off;

            on_publish http://centryk.example/tv/api/stream/on_publish.php?key=STREAM_API_KEY_VALUE;
            on_publish_done http://centryk.example/tv/api/stream/on_publish_done.php?key=STREAM_API_KEY_VALUE;

            hls on;
            hls_path /var/www/hls;
            hls_fragment 4s;
            hls_playlist_length 20s;
        }
    }
}
```

`STREAM_API_KEY_VALUE` must match `STREAM_API_KEY` in the app's `.env`
exactly - generate it with `php -r "echo bin2hex(random_bytes(32));"` and
never commit it or the signing secret below.

## Playback authorization (built)

`api/stream/authorize_playback.php` is the concrete implementation of the
"playback token validation" responsibility this doc already assigned to the
streaming server but never specified. `StreamingService::getPlaybackUrl()`
signs `expires`/`token`/`event` into every playback URL it hands a viewer;
this endpoint checks them via NGINX's `auth_request` before serving the
actual HLS content.

```nginx
http {
    server {
        listen 443 ssl;
        server_name stream.example.com;

        # ── CORS ──────────────────────────────────────────────────────────
        # The player runs on a DIFFERENT origin (tv.centryk.net) and hls.js
        # fetches .m3u8/.ts cross-origin - without these headers, playback
        # fails silently in the browser on the first real test. Restrict
        # Access-Control-Allow-Origin to the actual app domain in production
        # rather than "*".
        add_header Access-Control-Allow-Origin "https://tv.centryk.net" always;
        add_header Access-Control-Allow-Methods "GET, OPTIONS" always;
        add_header Access-Control-Allow-Headers "Range" always;

        location = /_authorize_playback {
            internal;
            proxy_pass http://centryk.example/tv/api/stream/authorize_playback.php?key=STREAM_API_KEY_VALUE&$args;
            proxy_pass_request_body off;
            proxy_set_header Content-Length "";
        }

        location /hls/ {
            auth_request /_authorize_playback;
            alias /var/www/hls/;
            add_header Cache-Control no-cache;
        }
    }
}
```

`auth_request` forwards the client's original query string to the
subrequest by default, so `expires`/`token`/`event` arrive at
`authorize_playback.php` exactly as `getPlaybackUrl()` embedded them - no
extra wiring needed beyond appending the shared-secret `key` in the
`proxy_pass` line above.

## Recommended initial rollout

1. Provision the VPS and DNS. Use `stream.<production-domain>` to match the
   existing per-app subdomain convention (`tv.centryk.net`, `mypay.centryk.com`) -
   e.g. `stream.centryk.net`.
2. Install NGINX with `nginx-rtmp-module`, or SRS.
3. Configure RTMP ingest at `/live` with the `on_publish`/`on_publish_done`
   callbacks above.
4. Publish HLS playlists under `/hls`, with the CORS headers and
   `auth_request` block above in front of them.
5. Put HTTPS in front with Let's Encrypt: `certbot --nginx -d stream.centryk.net`,
   and confirm the certbot systemd timer (`certbot.timer`) is enabled for
   auto-renewal rather than a one-off manual cert.
6. Set `STREAM_INGEST_URL`, `STREAM_PLAYBACK_BASE_URL`, and `STREAM_API_KEY`
   in the app's `.env` to match, and add a row to `tv_stream_servers` for
   this VPS (name, provider, hostname, ingest/playback URLs, a real
   `capacity` figure sized to the VPS's CPU/bandwidth, status `active`).
7. Expose a small health/status endpoint for Centryk TV polling later
   (`getStreamStatus()` already prefers the real `is_publishing` signal set
   by `on_publish`/`on_publish_done` over the editorial status column once a
   stream key has ever reported in - a health endpoint would let it also
   detect a hard-crashed origin process, not just a graceful disconnect).

## Known gaps not yet covered here

- **Replay/VOD.** `tv_events.replay_url`/`replay_status` exist and the
  README lists replay as included, but there is no recording pipeline: no
  FFmpeg trigger tied to `on_publish`/`on_publish_done`, no defined storage
  path, and no code path that ever sets `replay_status` to anything but its
  default. Treat replay as a separate phase of work, not implied by
  anything above.
- **Paid/subscription visibility.** `tv_channels.visibility` allows `paid`
  and `subscription`, but `tv_can_watch_event()` treats both exactly like
  `private` (an explicit `tv_event_access` grant) - there is no payment or
  subscription verification anywhere in the app. Needs an explicit decision
  on which payment rail to integrate before this is real.
- **Token/session binding.** Playback tokens are valid for anyone who has
  them until `expires` (default 5 minutes), not bound to a session or IP.
  This is a standard signed-URL tradeoff, not a bug, but keep `getPlaybackUrl()`'s
  `$ttl` short rather than lengthening it for convenience, especially once
  paid events exist.
