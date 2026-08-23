-- Ingest-side authentication support for Centryk TV's streaming server plan
-- (docs/streaming-server.md). Closes the gap where the plan's own "add token
-- validation" step only covered viewer PLAYBACK tokens - nothing validated
-- the broadcaster's RTMP publish against a real stream key before now.
--
-- is_publishing / last_published_at / publish_addr are the objective truth
-- signal from the streaming origin (api/stream/on_publish.php and
-- on_publish_done.php write these), separate from tv_events.status which is
-- an editorial/scheduling concept an admin can set by hand. StreamingService::
-- getStreamStatus() should prefer this over the mocked status-column mapping
-- once a key has ever reported in.
--
-- A unique index on stream_key_hash also fixes a real gap: ingest auth runs
-- this lookup on every single publish attempt and there was no index backing
-- it at all (a full table scan per RTMP connection).
--
-- Safe to run more than once.

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_stream_keys' AND COLUMN_NAME = 'is_publishing'),
    'SELECT "is_publishing exists" AS info',
    'ALTER TABLE tv_stream_keys ADD COLUMN is_publishing TINYINT(1) NOT NULL DEFAULT 0 AFTER revoked_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_stream_keys' AND COLUMN_NAME = 'last_published_at'),
    'SELECT "last_published_at exists" AS info',
    'ALTER TABLE tv_stream_keys ADD COLUMN last_published_at DATETIME DEFAULT NULL AFTER is_publishing'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_stream_keys' AND COLUMN_NAME = 'publish_ended_at'),
    'SELECT "publish_ended_at exists" AS info',
    'ALTER TABLE tv_stream_keys ADD COLUMN publish_ended_at DATETIME DEFAULT NULL AFTER last_published_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_stream_keys' AND COLUMN_NAME = 'publish_addr'),
    'SELECT "publish_addr exists" AS info',
    'ALTER TABLE tv_stream_keys ADD COLUMN publish_addr VARCHAR(45) DEFAULT NULL AFTER publish_ended_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_stream_keys' AND INDEX_NAME = 'uq_tv_stream_keys_hash'),
    'SELECT "uq_tv_stream_keys_hash exists" AS info',
    'ALTER TABLE tv_stream_keys ADD UNIQUE INDEX uq_tv_stream_keys_hash (stream_key_hash)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Per-server live/viewer accounting for the capacity guardrail (docs/
-- streaming-server.md never enforced tv_stream_servers.capacity anywhere).
SET @sql := IF (
    EXISTS (SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_channels' AND COLUMN_NAME = 'is_live'),
    'SELECT "is_live exists" AS info',
    'ALTER TABLE tv_channels ADD COLUMN is_live TINYINT(1) NOT NULL DEFAULT 0 AFTER stream_server_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
