# Centryk TV Streaming Server Plan

## Target stack

- Ubuntu LTS or AlmaLinux/RHEL on IONOS VPS
- NGINX plus `nginx-rtmp-module` or SRS
- RTMP ingest for OBS and compatible encoders
- HLS output for browser playback
- HTTPS in front of the playback origin
- Optional FFmpeg for recording, transmuxing, and future renditions

### Installing on AlmaLinux/RHEL (no prebuilt package exists)

Debian/Ubuntu package `nginx-rtmp-module` (`libnginx-mod-rtmp`); RHEL-family
distros don't ship it anywhere, including EPEL. Compile from source instead
- this is genuinely how the module has always been installed on RHEL, not a
workaround:

```bash
dnf install -y gcc make pcre-devel pcre2-devel zlib-devel openssl-devel wget tar
mkdir -p /usr/local/src && cd /usr/local/src
wget -q https://nginx.org/download/nginx-1.26.2.tar.gz
wget -q https://github.com/arut/nginx-rtmp-module/archive/refs/heads/master.tar.gz -O nginx-rtmp-module.tar.gz
tar xzf nginx-1.26.2.tar.gz && tar xzf nginx-rtmp-module.tar.gz

cd nginx-1.26.2
./configure --prefix=/etc/nginx --sbin-path=/usr/sbin/nginx \
    --conf-path=/etc/nginx/nginx.conf --pid-path=/var/run/nginx.pid \
    --lock-path=/var/run/nginx.lock --error-log-path=/var/log/nginx/error.log \
    --http-log-path=/var/log/nginx/access.log \
    --with-http_ssl_module --with-http_auth_request_module --with-http_v2_module \
    --with-file-aio --with-threads \
    --add-module=/usr/local/src/nginx-rtmp-module-master
make -j2 && make install

groupadd -f nginx && useradd -r -g nginx -s /sbin/nologin -M nginx
mkdir -p /var/log/nginx /var/www/hls && chown nginx:nginx /var/www/hls
```

`--with-http_auth_request_module` is required (not on by default) - the
playback authorization section below depends on it. Source-installed nginx
has no systemd unit; create `/etc/systemd/system/nginx.service`:

```ini
[Unit]
Description=nginx (with RTMP module)
After=network.target

[Service]
Type=forking
PIDFile=/var/run/nginx.pid
ExecStartPre=/usr/sbin/nginx -t
ExecStart=/usr/sbin/nginx
ExecReload=/bin/kill -s HUP $MAINPID
ExecStop=/bin/kill -s TERM $MAINPID
KillMode=process
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

Then `systemctl daemon-reload && systemctl enable --now nginx`.

If SELinux is Enforcing (`getenforce`), check `ausearch -m avc -ts recent`
after first start/first publish attempt if anything behaves oddly - a
source-compiled binary may not carry the `httpd_exec_t` label RHEL's policy
expects, though in practice this has run clean without any AVC denials or
`setsebool` changes needed.

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

            on_publish http://127.0.0.1:8080/_callback/on_publish.php;
            on_publish_done http://127.0.0.1:8080/_callback/on_publish_done.php;

            hls on;
            hls_path /var/www/hls;
            hls_fragment 4s;
            hls_playlist_length 20s;
        }
    }
}
```

**nginx-rtmp's notify module (`on_publish`/`on_publish_done`) has no HTTPS
support at all** - confirmed against its actual source
(`ngx_rtmp_notify_module.c` has zero references to SSL/TLS). If the app only
serves HTTPS (the normal case), pointing these directly at `https://...`
silently fails. Route them through a local plain-HTTP bridge on the same
VPS instead - this uses nginx's regular HTTP module, which proxies to HTTPS
upstreams natively:

```nginx
http {
    # Loopback only - never reachable from outside this VPS.
    server {
        listen 127.0.0.1:8080;
        server_name _;

        location /_callback/on_publish.php {
            proxy_pass https://centryk.example/tv/api/stream/on_publish.php?key=STREAM_API_KEY_VALUE;
            proxy_ssl_server_name on;
            proxy_set_header Host centryk.example;
        }

        location /_callback/on_publish_done.php {
            proxy_pass https://centryk.example/tv/api/stream/on_publish_done.php?key=STREAM_API_KEY_VALUE;
            proxy_ssl_server_name on;
            proxy_set_header Host centryk.example;
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
            proxy_pass https://centryk.example/tv/api/stream/authorize_playback.php?key=STREAM_API_KEY_VALUE&$forwarded_qs;
            proxy_ssl_server_name on;
            proxy_set_header Host centryk.example;
            proxy_pass_request_body off;
            proxy_set_header Content-Length "";
        }

        location /hls/ {
            set $forwarded_qs $args;
            auth_request /_authorize_playback;
            alias /var/www/hls/;
            add_header Cache-Control no-cache;
        }
    }
}
```

**`auth_request` does NOT forward the client's original query string to the
subrequest** - this contradicted an earlier version of this doc, which was
wrong, confirmed the hard way against a real deployment. `$args` is a
request-line-derived variable that nginx recomputes fresh for the internal
subrequest (which has none), so referencing `$args` directly inside
`/_authorize_playback` is always empty there, regardless of what the client
actually requested. The `auth_request` directive itself also does not
accept variables in its own argument - `auth_request /_authorize_playback$is_args$args;`
looks plausible but nginx treats the whole string as a literal, unparsed
path and 404s trying to open a file with that literal name.

The fix that actually works: capture `$args` into a plain `set` variable
(`$forwarded_qs` above) in the outer location *before* calling
`auth_request`. Unlike `$args`, a `set` variable is not request-line-derived
and survives into the subrequest correctly. Verified against a live
deployment: an expired/bad token now correctly 403s (previously a 500
"auth request unexpected status" from `$args` arriving empty and failing
`authorize_playback.php`'s own "missing credentials" check), and a
genuinely valid signed token passes through to file serving (404 only
because no HLS segment exists yet - not an auth failure).

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
    on_publish http://127.0.0.1:8080/_callback/on_publish.php;
    on_publish_done http://127.0.0.1:8080/_callback/on_publish_done.php;

    hls on;
    hls_path /var/www/hls;

    # Raw recording, keyed by stream key - closes automatically when the
    # publish ends (nginx-rtmp finalizes the file on disconnect).
    record all;
    record_path /var/recordings/raw;
    record_suffix .flv;
}
```

(same local HTTP bridge as the ingest authentication section above - these
are the same two callbacks, shown again here as part of the full
`application live` block.)

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

## Pay-per-event access (built)

`paid` channel visibility now means what it says: `tv_can_watch_event()`
requires a real, confirmed payment (or org staff / private grant) before a
`paid` channel's events play, closing a real gap where a `paid` channel's
events were previously freely watchable by anyone (the event's own
`visibility` column defaults to `public` and nothing checked the channel's
`paid` flag at all).

Charges go straight to OneLink using Centryk's own `onelink_credentials`
(company-level - see `database/add_tv_payments.sql`), the same endpoint and
request shape OnePay's POS already uses
(`app/services/payments/onelink_pos.php` in the OnePay codebase), rather
than bridging to OnePay's `payment_settings`: that table is only ever a
one-way mirror of these same credentials out to a company's OnePay stores,
not the source of truth, and a company doesn't need an OnePay store at all
to have OneLink provisioned.

- `TvPaymentService::chargeForEventAccess()` validates the event actually
  requires payment and has a price, is idempotent (a user with an existing
  successful payment is never charged twice), and grants
  `tv_event_access` ONLY after OneLink's own response confirms success -
  never from the client's say-so. `api/payments/charge_for_access.php` is
  the sole caller.
- `paywall.php` is what `watch.php` redirects a signed-in viewer to instead
  of a flat 403 when the event is paid-gated; card details are POSTed
  straight through to OneLink and never stored, matching OnePay's existing
  pattern.
- **`subscription` visibility is explicitly out of scope.** Recurring
  billing (renewal, cancellation, dunning) is a materially different
  problem from a one-time charge; it still falls through to the same
  private-grant check, which is safe (fails closed) but not full-featured.
- The actual OneLink success path (a real charge going through) could not
  be verified in development - only the failure path was exercised against
  the live endpoint, using obviously-fake credentials that OneLink
  correctly rejected before any card processing. Confirm a real charge end
  to end against a real (or OneLink-provided sandbox) merchant account
  before relying on this in production.

## Known gaps not yet covered here

- **Token/session binding.** Playback tokens are valid for anyone who has
  them until `expires` (5 minutes for live, 6 hours for replay), not bound
  to a session or IP. This is a standard signed-URL tradeoff, not a bug, but
  keep these ttls short rather than lengthening them for convenience,
  especially with paid events now live.
- **No automatic replay retention/expiry.** Once disk usage from local
  recordings becomes a real concern, revisit moving to object storage or
  adding a cleanup job for old replays.
