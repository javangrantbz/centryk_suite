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

## Replay/VOD recording (local VPS disk)

Recording happens unconditionally at the nginx-rtmp layer - there's no
per-connection way to toggle its `record` directive from an `on_publish`
response - so a separate small poller decides, per finished recording,
whether the event actually wanted a replay (`tv_events.is_replay_enabled`)
before spending CPU on a remux or disk on keeping the result.

```nginx
application live {
    live on;
    on_publish http://centryk.example/tv/api/stream/on_publish.php?key=STREAM_API_KEY_VALUE;
    on_publish_done http://centryk.example/tv/api/stream/on_publish_done.php?key=STREAM_API_KEY_VALUE;

    hls on;
    hls_path /var/www/hls;

    # Raw recording, keyed by stream key - closes automatically when the
    # publish ends (nginx-rtmp finalizes the file on disconnect).
    record all;
    record_path /var/recordings/raw;
    record_suffix .flv;
}
```

A cron job (every minute is fine) processes anything nginx-rtmp has
finished writing:

```bash
#!/usr/bin/env bash
# /usr/local/bin/process_tv_replays.sh
set -euo pipefail
APP_KEY="STREAM_API_KEY_VALUE"
APP_BASE="http://centryk.example/tv"
RAW_DIR="/var/recordings/raw"
OUT_DIR="/var/www/hls/replays"

for raw in "$RAW_DIR"/*.flv; do
    [ -e "$raw" ] || continue
    # nginx-rtmp still holds an open file handle on the active recording -
    # only touch files whose mtime is comfortably in the past.
    [ "$(( $(date +%s) - $(stat -c %Y "$raw") ))" -lt 30 ] && continue

    stream_key="$(basename "$raw" .flv)"

    should_record=$(curl -s "$APP_BASE/api/stream/should_record_replay.php?key=$APP_KEY&stream_key=$stream_key" \
        | grep -o '"should_record":true' || true)

    if [ -z "$should_record" ]; then
        rm -f "$raw"
        continue
    fi

    out_name="$(date +%s)-${stream_key:0:18}.mp4"
    if ffmpeg -y -i "$raw" -c copy "$OUT_DIR/$out_name" 2>/tmp/ffmpeg_last_error.log; then
        curl -s -X POST "$APP_BASE/api/stream/replay_status.php" \
            --data-urlencode "key=$APP_KEY" \
            --data-urlencode "stream_key=$stream_key" \
            --data-urlencode "status=available" \
            --data-urlencode "replay_path=replays/$out_name" > /dev/null
    else
        curl -s -X POST "$APP_BASE/api/stream/replay_status.php" \
            --data-urlencode "key=$APP_KEY" \
            --data-urlencode "stream_key=$stream_key" \
            --data-urlencode "status=failed" > /dev/null
    fi
    rm -f "$raw"
done
```

`-c copy` remuxes the container without re-encoding (fast, no quality loss);
add real transcoding flags later if multiple renditions are ever needed.
`$OUT_DIR` should sit under the same NGINX root that already serves `/hls/`
so `replay_path` values resolve under `STREAM_PLAYBACK_BASE_URL` exactly
like live segments do - the `auth_request` block already in front of that
location protects replay files the same way it protects live ones, since
`StreamingService::getReplayUrl()` signs tokens with the same scheme
`authorize_playback.php` already checks.

Since this is VPS-local disk (the simplest option, chosen over object
storage for now): monitor `/var/recordings` and `/var/www/hls/replays` disk
usage directly - nothing here expires old replays automatically, and video
fills a disk fast. Revisit object storage if retention needs grow.

## Known gaps not yet covered here

- **Paid/subscription visibility.** `tv_channels.visibility` allows `paid`
  and `subscription`, but `tv_can_watch_event()` treats both exactly like
  `private` (an explicit `tv_event_access` grant) - there is no payment or
  subscription verification anywhere in the app yet. Slated to integrate
  with OnePay/OneLink.
- **Token/session binding.** Playback tokens are valid for anyone who has
  them until `expires` (5 minutes for live, 6 hours for replay), not bound
  to a session or IP. This is a standard signed-URL tradeoff, not a bug, but
  keep these ttls short rather than lengthening them for convenience,
  especially once paid events exist.
- **No automatic replay retention/expiry.** Once disk usage from local
  recordings becomes a real concern, revisit moving to object storage or
  adding a cleanup job for old replays.
