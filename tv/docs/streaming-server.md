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

## Recommended initial rollout

1. Provision the VPS and DNS for `stream.example.com`.
2. Install NGINX or SRS.
3. Configure RTMP ingest at `/live`.
4. Publish HLS playlists under `/hls`.
5. Put HTTPS in front of HLS playback.
6. Add token validation that matches the HMAC logic documented in `streaming-integration.md`.
7. Expose a small health/status endpoint for Centryk TV polling later.

## Notes

- Keep stream-key validation server-side.
- Do not expose the signing secret to the client.
- Start with a single region and one active stream server row in the database.
- Add recording and multi-bitrate output after the core authorization flow is stable.

