# Centryk TV

Centryk TV is a Centryk suite app for organization-owned live streaming and digital broadcasting. This module reuses Centryk core authentication, company membership, and app access.

## What is included

- multi-tenant TV organizations mapped to Centryk companies
- TV-specific roles and dashboard shell
- channels, events, sports details, and replay metadata
- secure stream-key generation with encrypted storage plus SHA-256 hashing
- signed playback URL generation through a streaming abstraction
- ingest-side authentication (`api/stream/on_publish.php`) so a real RTMP
  publish must present a valid, unrevoked stream key, plus a per-server
  capacity guardrail
- edge-side playback token validation (`api/stream/authorize_playback.php`),
  meant to run behind NGINX's `auth_request` on the real streaming server
- opt-in replay/VOD: an ended event's recording gets remuxed and served
  with the same signed-URL scheme as live, once the streaming server's
  cron-driven recording job reports in (`api/stream/replay_status.php`,
  `should_record_replay.php`)
- pay-per-event access for `paid` channels, charged directly through
  OneLink using the organization's own `onelink_credentials`
  (`paywall.php`, `api/payments/charge_for_access.php`) - and a fix to a
  real pre-existing gap where a `paid` channel's events were previously
  watchable by anyone, since nothing actually checked the channel's
  payment requirement
- watch page heartbeat and viewer counting
- analytics, audit logging, seed data, and deployment docs
- browser "Go Live" via WHIP (`go-live.php`), with a header shortcut for
  broadcasters: stream straight from a phone/laptop's own browser, no OBS or
  app install. Bridged through MediaMTX into the same RTMP ingest OBS uses,
  so nothing downstream needed to change. A self-hosted TURN relay (coturn)
  keeps this working on cellular data, not just wifi. Going live creates a
  real event under the hood (optional title, optional "Save a replay"),
  so it gets a shareable watch link and can produce a replay exactly like
  an OBS-based event.

See [`docs/streaming-server.md`](./docs/streaming-server.md) for the actual
IONOS VPS / NGINX-RTMP configuration this app's ingest, playback, and
replay endpoints are built to work with, and its "Known gaps" section for
what isn't built yet (`subscription` recurring billing).

## Local setup

1. Make sure the root Centryk app is already working at `C:\xampp\htdocs\centryk`.
2. Update the root `.env` with the TV-related keys from [`.env.example`](./.env.example).
3. Run the migrations:

```sql
SOURCE C:/xampp/htdocs/centryk/tv/database/add_tv_app.sql;
SOURCE C:/xampp/htdocs/centryk/tv/database/add_tv_stream_ingest_tracking.sql;
SOURCE C:/xampp/htdocs/centryk/tv/database/add_tv_payments.sql;
```

4. Open `http://localhost/centryk/tv/`.

## Demo accounts

The migration seeds these local-only demo users with password `password123`:

- `tv-admin@centryk.local`
- `owner@bba.tv.local`
- `broadcaster@bba.tv.local`
- `viewer@bba.tv.local`

## Main routes

- `/centryk/tv/`
- `/centryk/tv/dashboard`
- `/centryk/tv/dashboard/channels`
- `/centryk/tv/dashboard/events`
- `/centryk/tv/dashboard/viewers`
- `/centryk/tv/dashboard/analytics`
- `/centryk/tv/dashboard/settings`
- `/centryk/tv/admin`
- `/centryk/tv/watch/{event-slug}`
- `/centryk/tv/{organization-slug}`

## Notes

- Authentication is Centryk-native. No new signup flow is added.
- The mock streaming driver is intentionally marked as development-only.
- If the playback base URL is not configured, the watch page shows an offline/unavailable state instead of pretending video is live.

