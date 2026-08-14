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
    'stream_signing_secret' => (string)($_ENV['STREAM_SIGNING_SECRET'] ?? ''),
    'stream_cipher_key' => (string)($_ENV['TV_STREAM_CIPHER_KEY'] ?? ''),
    'viewer_active_window_seconds' => 90,
];

