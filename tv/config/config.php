<?php

return [
    'app_name' => 'Centryk TV',
    'tagline' => 'Live Streaming & Digital Broadcasting',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'America/Guatemala',
    'tv_app_url' => rtrim((string)($_ENV['TV_APP_URL'] ?? ''), '/'),
    'upload_max_bytes' => (int)($_ENV['TV_UPLOAD_MAX_BYTES'] ?? 5242880),
    'stream_driver' => (string)($_ENV['STREAM_DRIVER'] ?? 'mock'),
    'stream_ingest_url' => (string)($_ENV['STREAM_INGEST_URL'] ?? ''),
    'stream_playback_base_url' => rtrim((string)($_ENV['STREAM_PLAYBACK_BASE_URL'] ?? ''), '/'),
    'stream_api_url' => (string)($_ENV['STREAM_API_URL'] ?? ''),
    'stream_api_key' => (string)($_ENV['STREAM_API_KEY'] ?? ''),
    // Browser "Go Live" (WHIP) publish endpoint - separate from
    // stream_ingest_url (RTMP, for OBS/hardware encoders). See
    // go-live.php and docs/streaming-server.md's WHIP section.
    'stream_whip_url' => rtrim((string)($_ENV['STREAM_WHIP_URL'] ?? ''), '/'),
    // TURN relay for Go Live on cellular networks, where STUN alone can't
    // traverse carrier NAT. See StreamingService::generateTurnCredentials()
    // and docs/streaming-server.md's TURN section.
    'turn_host' => (string)($_ENV['TURN_HOST'] ?? ''),
    'turn_shared_secret' => (string)($_ENV['TURN_SHARED_SECRET'] ?? ''),
    'stream_signing_secret' => (string)($_ENV['STREAM_SIGNING_SECRET'] ?? ''),
    'stream_cipher_key' => (string)($_ENV['TV_STREAM_CIPHER_KEY'] ?? ''),
    'viewer_active_window_seconds' => 90,
    // Comma-separated emails let through the production "coming soon" gate
    // ahead of public launch. Kept out of source (not hardcoded in
    // functions.php) so it's editable without a code deploy and personal
    // addresses don't sit in git history.
    'coming_soon_allowlist' => (string)($_ENV['TV_COMING_SOON_ALLOWLIST'] ?? ''),
];

