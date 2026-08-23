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
- watch page heartbeat and viewer counting
- analytics, audit logging, seed data, and deployment docs

See [`docs/streaming-server.md`](./docs/streaming-server.md) for the actual
IONOS VPS / NGINX-RTMP configuration this app's ingest and playback
endpoints are built to work with, and its "Known gaps" section for what
isn't built yet (replay/VOD, paid/subscription enforcement).

## Local setup

1. Make sure the root Centryk app is already working at `C:\xampp\htdocs\centryk`.
2. Update the root `.env` with the TV-related keys from [`.env.example`](./.env.example).
3. Run the migrations:

```sql
SOURCE C:/xampp/htdocs/centryk/tv/database/add_tv_app.sql;
SOURCE C:/xampp/htdocs/centryk/tv/database/add_tv_stream_ingest_tracking.sql;
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

