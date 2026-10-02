# Online conferences (Calendar -> Centryk TV)

A Calendar event can be hosted as a live, multi-person video conference on
Centryk TV. This is separate from broadcasting (`go-live.php`, one-to-many
HLS): a conference is everyone-talks-to-everyone, served by a Jitsi Meet
server, not the nginx-rtmp / MediaMTX stack.

## The loop

1. **Calendar** (`public/calendar.php`): in the event form, tick *Online - host
   on Centryk TV as a conference*, set a start time and length, and add
   employees. Saved through `api/events/create.php` / `update.php`, which call
   `ConferenceService::saveForEvent()`.
2. **Invite**: every employee added gets a `conference.invited` notification
   (bell) with the join link. Reschedule -> `conference.rescheduled`; removing
   the conference or deleting the event -> `conference.cancelled`.
3. **Always visible**: `public/partials/conference_pill.php` shows a pill next
   to the notification bell (every hub header that includes the bell) and in
   the Centryk TV header. It lists the user's conferences that are live, due,
   or start within 24h, with a Join button (Start for the host). It polls
   `api/conferences/mine.php` every 30s.
4. **Reminder**: 15 minutes before the start every invitee gets a
   `conference.reminder`. There is no cron - `mine()` sweeps due reminders, so
   any invitee's open page triggers them (claimed atomically, sent once).
5. **Room** (`tv/conference.php?c=<token>`): invitation-only (the event
   creator + its attendees who are still active company members). Guests can
   enter 15 minutes early; the host any time. When the host first enters, the
   conference goes **live** and the other invitees get a `conference.live`
   notification. The page heartbeats every 30s (`tv/api/conference/heartbeat.php`)
   and shows who is in the room.
6. **End**: the host's *End for everyone* (`tv/api/conference/end.php`) ends it;
   it then leaves the pill. An unused conference expires 30 min past its
   scheduled end.

Not gated by `tv_gate_coming_soon()` on purpose: an invited employee must be
able to join even while public TV pages are blank in production.

## Tables (`database/add_event_conferences.sql`)

`event_conferences` (one row per conference event: unguessable `room_token`,
`start_time`, `duration_minutes`, `status`, heartbeat + reminder timestamps)
and `conference_presence`. `ConferenceService::ensureSchema()` creates both on
first use, so the migration is a convenience, not a prerequisite.

## Video provider (needs a decision before real use)

The room embeds Jitsi Meet through its External API. `.env`:

| Var | Meaning |
|---|---|
| `JITSI_DOMAIN` | Jitsi host. Default `meet.jit.si`. |
| `JITSI_APP_ID`, `JITSI_APP_SECRET` | Optional. HS256 token auth for a **self-hosted** Jitsi; Centryk then signs a JWT per user (host = moderator) so only Centryk-authorised people can enter. Unset = public room guarded only by the unguessable room name. |
| `JITSI_JWT_AUD` | JWT audience, default `jitsi`. |
| `TV_APP_URL` | Base URL used to build join links in notifications (falls back to `APP_URL` with `/public` -> `/tv`). |

- **`meet.jit.si` (default)**: free, zero setup, good for trying the flow, but
  Jitsi limits embedded use of it (short session caps) and there is no
  moderator control. Not for production.
- **Self-host Jitsi** (recommended): run `docker-jitsi-meet` on its own hostname
  (e.g. `meet.centryk.net`) with token auth enabled, and set the three vars
  above. It needs its own TLS/443 (the streaming VPS's nginx already owns 443
  for `stream.centryk.net`, so use a separate host or a reverse proxy) and UDP
  `10000` open in the IONOS firewall for the video bridge. The current 4 vCPU /
  4 GB VPS is fine for small meetings but shares CPU with live streaming - a
  separate small VPS is safer.
- **8x8 JaaS** uses RS256 JWTs and is not supported by `jitsiJwt()` yet.

## Known gaps

- No recording of conferences (Jitsi can record only with Jibri/dropbox).
- Notifications are in-app only; no email invite or `.ics`.
- The pill is on hub headers that include the bell and on Centryk TV pages.
  MyPay / OnePay / invoice-maker headers have their own bell and are not wired.
- Invitees must be members of the event's company.
