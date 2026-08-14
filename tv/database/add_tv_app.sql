SET NAMES utf8mb4;
USE centryk_core;

CREATE TABLE IF NOT EXISTS tv_organizations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    banner_path VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    email VARCHAR(180) DEFAULT NULL,
    phone VARCHAR(60) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    status ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
    timezone VARCHAR(80) NOT NULL DEFAULT 'America/Guatemala',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tv_organizations_company (company_id),
    UNIQUE KEY uq_tv_organizations_slug (slug),
    CONSTRAINT fk_tv_organizations_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_organization_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner','admin','broadcaster','viewer') NOT NULL DEFAULT 'viewer',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tv_organization_user (organization_id, user_id),
    CONSTRAINT fk_tv_organization_users_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_organization_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_stream_servers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    region VARCHAR(120) DEFAULT NULL,
    provider VARCHAR(120) DEFAULT NULL,
    hostname VARCHAR(180) DEFAULT NULL,
    ingest_url VARCHAR(255) DEFAULT NULL,
    playback_url VARCHAR(255) DEFAULT NULL,
    api_url VARCHAR(255) DEFAULT NULL,
    driver VARCHAR(60) NOT NULL DEFAULT 'mock',
    status ENUM('active','offline','maintenance') NOT NULL DEFAULT 'active',
    capacity INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    description TEXT DEFAULT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    cover_image_path VARCHAR(255) DEFAULT NULL,
    visibility ENUM('public','authenticated','private','paid','subscription') NOT NULL DEFAULT 'public',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    stream_server_id INT UNSIGNED DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tv_channels_org_slug (organization_id, slug),
    CONSTRAINT fk_tv_channels_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_channels_stream_server FOREIGN KEY (stream_server_id) REFERENCES tv_stream_servers(id) ON DELETE SET NULL,
    CONSTRAINT fk_tv_channels_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_stream_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED DEFAULT NULL,
    event_id INT UNSIGNED DEFAULT NULL,
    stream_key_prefix VARCHAR(32) NOT NULL,
    stream_key_hash CHAR(64) NOT NULL,
    stream_key_encrypted TEXT NOT NULL,
    last_rotated_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tv_stream_keys_channel (channel_id),
    INDEX idx_tv_stream_keys_event (event_id),
    CONSTRAINT fk_tv_stream_keys_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_stream_keys_channel FOREIGN KEY (channel_id) REFERENCES tv_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_stream_keys_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    event_type ENUM('sports','business','education','church','government','conference','entertainment','other') NOT NULL DEFAULT 'other',
    thumbnail_path VARCHAR(255) DEFAULT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME DEFAULT NULL,
    status ENUM('draft','scheduled','live','ended','cancelled') NOT NULL DEFAULT 'draft',
    visibility ENUM('public','authenticated','private') NOT NULL DEFAULT 'public',
    stream_key_id INT UNSIGNED DEFAULT NULL,
    replay_url VARCHAR(255) DEFAULT NULL,
    replay_status ENUM('none','processing','available','failed') NOT NULL DEFAULT 'none',
    duration_seconds INT UNSIGNED DEFAULT NULL,
    viewer_limit INT UNSIGNED DEFAULT NULL,
    is_replay_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tv_events_slug (slug),
    INDEX idx_tv_events_org_status (organization_id, status),
    INDEX idx_tv_events_org_start (organization_id, start_at),
    CONSTRAINT fk_tv_events_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_events_channel FOREIGN KEY (channel_id) REFERENCES tv_channels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tv_events_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tv_stream_keys
    ADD CONSTRAINT fk_tv_stream_keys_event FOREIGN KEY (event_id) REFERENCES tv_events(id) ON DELETE CASCADE;

ALTER TABLE tv_events
    ADD CONSTRAINT fk_tv_events_stream_key FOREIGN KEY (stream_key_id) REFERENCES tv_stream_keys(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS tv_sports_event_details (
    event_id INT UNSIGNED PRIMARY KEY,
    sport VARCHAR(120) DEFAULT NULL,
    home_team VARCHAR(180) DEFAULT NULL,
    away_team VARCHAR(180) DEFAULT NULL,
    home_logo_path VARCHAR(255) DEFAULT NULL,
    away_logo_path VARCHAR(255) DEFAULT NULL,
    venue VARCHAR(180) DEFAULT NULL,
    competition VARCHAR(180) DEFAULT NULL,
    round_name VARCHAR(180) DEFAULT NULL,
    home_score INT NOT NULL DEFAULT 0,
    away_score INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tv_sports_event_details_event FOREIGN KEY (event_id) REFERENCES tv_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_event_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    granted_by INT UNSIGNED DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tv_event_access (event_id, user_id),
    CONSTRAINT fk_tv_event_access_event FOREIGN KEY (event_id) REFERENCES tv_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_event_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_event_access_granted_by FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_viewer_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    event_id INT UNSIGNED NOT NULL,
    session_token CHAR(64) NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_tv_viewer_sessions_token (session_token),
    INDEX idx_tv_viewer_sessions_event_seen (event_id, last_seen_at),
    CONSTRAINT fk_tv_viewer_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tv_viewer_sessions_event FOREIGN KEY (event_id) REFERENCES tv_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED DEFAULT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) DEFAULT NULL,
    entity_id INT UNSIGNED DEFAULT NULL,
    details JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tv_audit_logs_org_created (organization_id, created_at),
    CONSTRAINT fk_tv_audit_logs_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE SET NULL,
    CONSTRAINT fk_tv_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tv_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT DEFAULT NULL,
    status ENUM('pending','sent','read') NOT NULL DEFAULT 'pending',
    send_at DATETIME DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tv_notifications_org_status (organization_id, status),
    CONSTRAINT fk_tv_notifications_org FOREIGN KEY (organization_id) REFERENCES tv_organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order, status)
VALUES (
    'tv',
    'Centryk TV',
    'Live streaming and digital broadcasting for organizations',
    'http://localhost/centryk/tv/',
    'https://tv.centryk.net/',
    'TV',
    '#0f766e',
    6,
    'active'
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    url_local = VALUES(url_local),
    url_production = VALUES(url_production),
    icon = VALUES(icon),
    color = VALUES(color),
    sort_order = VALUES(sort_order),
    status = VALUES(status);

INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT DISTINCT cm.user_id, a.id
FROM apps a
JOIN company_members cm ON cm.status = 'active'
JOIN users u ON u.id = cm.user_id AND u.status = 'active'
WHERE a.`key` = 'tv';

INSERT INTO tv_stream_servers (name, region, provider, hostname, ingest_url, playback_url, driver, status, capacity)
SELECT 'Centryk Stream Mock', 'United States', 'IONOS', 'stream.example.com',
       'rtmp://stream.example.com/live', 'https://stream.example.com/hls', 'mock', 'active', 25
WHERE NOT EXISTS (SELECT 1 FROM tv_stream_servers WHERE name = 'Centryk Stream Mock');

INSERT INTO users (first_name, last_name, email, password_hash, status, is_admin)
VALUES
    ('TV', 'Admin', 'tv-admin@centryk.local', '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS', 'active', 1),
    ('Belize', 'Owner', 'owner@bba.tv.local', '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS', 'active', 0),
    ('Live', 'Broadcaster', 'broadcaster@bba.tv.local', '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS', 'active', 0),
    ('Viewer', 'Demo', 'viewer@bba.tv.local', '$2y$10$6PF8v2gMZTuQw0ShLY4OeONypfDGJLSveE/ww4p8mSBf2g6l831OS', 'active', 0)
ON DUPLICATE KEY UPDATE status = VALUES(status);

SET @owner_id := (SELECT id FROM users WHERE email = 'owner@bba.tv.local' LIMIT 1);
SET @broadcaster_id := (SELECT id FROM users WHERE email = 'broadcaster@bba.tv.local' LIMIT 1);
SET @viewer_id := (SELECT id FROM users WHERE email = 'viewer@bba.tv.local' LIMIT 1);

INSERT INTO companies (uuid, name, owner_id, status)
SELECT UUID(), 'Belize Basketball Association', @owner_id, 'active'
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE name = 'Belize Basketball Association');

SET @company_id := (SELECT id FROM companies WHERE name = 'Belize Basketball Association' LIMIT 1);

INSERT IGNORE INTO company_members (company_id, user_id, role, status)
VALUES
    (@company_id, @owner_id, 'admin', 'active'),
    (@company_id, @broadcaster_id, 'manager', 'active'),
    (@company_id, @viewer_id, 'employee', 'active');

INSERT INTO tv_organizations (company_id, name, slug, description, email, website, status, timezone)
SELECT @company_id, 'Belize Basketball Association', 'belize-basketball',
       'National basketball broadcasts, championships, youth tournaments, and live events.',
       'media@bba.example', 'https://example.com', 'active', 'America/Belize'
WHERE NOT EXISTS (SELECT 1 FROM tv_organizations WHERE company_id = @company_id);

SET @org_id := (SELECT id FROM tv_organizations WHERE company_id = @company_id LIMIT 1);

INSERT IGNORE INTO tv_organization_users (organization_id, user_id, role, status)
VALUES
    (@org_id, @owner_id, 'owner', 'active'),
    (@org_id, @broadcaster_id, 'broadcaster', 'active'),
    (@org_id, @viewer_id, 'viewer', 'active');

INSERT INTO tv_channels (organization_id, name, slug, description, visibility, status, created_by)
SELECT @org_id, 'Centryk Sports', 'centryk-sports', 'Primary live sports channel for BBA events.', 'public', 'active', @owner_id
WHERE NOT EXISTS (SELECT 1 FROM tv_channels WHERE organization_id = @org_id AND slug = 'centryk-sports');

SET @channel_id := (SELECT id FROM tv_channels WHERE organization_id = @org_id AND slug = 'centryk-sports' LIMIT 1);

INSERT INTO tv_stream_keys (organization_id, channel_id, stream_key_prefix, stream_key_hash, stream_key_encrypted, last_rotated_at, created_by)
SELECT @org_id, @channel_id, 'ctv_live_demo', SHA2('ctv_live_demo_seed', 256), 'ZGVtby1rZXk=', NOW(), @owner_id
WHERE NOT EXISTS (SELECT 1 FROM tv_stream_keys WHERE channel_id = @channel_id AND event_id IS NULL);

SET @channel_key_id := (SELECT id FROM tv_stream_keys WHERE channel_id = @channel_id AND event_id IS NULL ORDER BY id DESC LIMIT 1);

INSERT INTO tv_events (organization_id, channel_id, title, slug, description, event_type, start_at, end_at, status, visibility, stream_key_id, replay_status, is_replay_enabled, created_by)
SELECT @org_id, @channel_id, 'Tigers vs Warriors', 'tigers-vs-warriors',
       'Live coverage of the basketball finals from Belize City.', 'sports',
       DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_ADD(NOW(), INTERVAL 90 MINUTE), 'live', 'public', @channel_key_id, 'none', 1, @owner_id
WHERE NOT EXISTS (SELECT 1 FROM tv_events WHERE slug = 'tigers-vs-warriors');

INSERT INTO tv_events (organization_id, channel_id, title, slug, description, event_type, start_at, end_at, status, visibility, replay_status, is_replay_enabled, created_by)
SELECT @org_id, @channel_id, 'Basketball Championship Finals', 'basketball-championship-finals',
       'Scheduled championship night with pre-game and trophy ceremony.', 'sports',
       DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(DATE_ADD(NOW(), INTERVAL 2 DAY), INTERVAL 2 HOUR), 'scheduled', 'authenticated', 'none', 1, @owner_id
WHERE NOT EXISTS (SELECT 1 FROM tv_events WHERE slug = 'basketball-championship-finals');

INSERT INTO tv_events (organization_id, channel_id, title, slug, description, event_type, start_at, end_at, status, visibility, replay_url, replay_status, duration_seconds, is_replay_enabled, created_by)
SELECT @org_id, @channel_id, 'Youth Tournament', 'youth-tournament',
       'Replay coverage from the youth invitational tournament.', 'sports',
       DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 10 DAY), INTERVAL 2 HOUR), 'ended', 'public',
       'https://stream.example.com/replays/youth-tournament.m3u8', 'available', 7200, 1, @owner_id
WHERE NOT EXISTS (SELECT 1 FROM tv_events WHERE slug = 'youth-tournament');

INSERT INTO tv_sports_event_details (event_id, sport, home_team, away_team, competition, round_name, venue, home_score, away_score)
SELECT e.id, 'basketball', 'Tigers', 'Warriors', 'National Finals', 'Championship', 'Belize City Civic Center', 72, 68
FROM tv_events e
WHERE e.slug = 'tigers-vs-warriors'
  AND NOT EXISTS (SELECT 1 FROM tv_sports_event_details WHERE event_id = e.id);

INSERT INTO tv_sports_event_details (event_id, sport, home_team, away_team, competition, round_name, venue, home_score, away_score)
SELECT e.id, 'basketball', 'CJC', 'Belmopan', 'National Finals', 'Final', 'Belize City Civic Center', 0, 0
FROM tv_events e
WHERE e.slug = 'basketball-championship-finals'
  AND NOT EXISTS (SELECT 1 FROM tv_sports_event_details WHERE event_id = e.id);
