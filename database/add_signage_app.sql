-- =============================================================================
-- VisionBoard (digital signage) — become a Centryk app.
--
-- Moves the standalone `zoocm` database into `centryk_core` as company-scoped
-- `vb_*` tables, registers the app in the apps registry, and copies existing
-- data across. Run against centryk_core:
--   mysql -u root centryk_core < database/add_signage_app.sql
--
-- Idempotent-ish: CREATE TABLE IF NOT EXISTS + INSERT IGNORE. Data copy guarded
-- so it only runs while the old zoocm tables still exist and targets are empty.
--
-- The old `zoocm` database is NOT dropped here — verify the app first, then run
-- database/drop_zoocm.sql.
--
-- IMPORTANT: import with utf8mb4 so the emoji below aren't mangled, e.g.
--   mysql --default-character-set=utf8mb4 -u root centryk_core < add_signage_app.sql
-- The SET NAMES line reinforces this for clients that honour it.
-- =============================================================================

SET NAMES utf8mb4;

-- Default company that inherits the existing (demo) signage content. Lowest
-- active company id. Reassign later per company as real tenants onboard.
SET @vb_cid := (SELECT id FROM companies WHERE status = 'active' ORDER BY id ASC LIMIT 1);

-- ---- Schema -----------------------------------------------------------------

CREATE TABLE IF NOT EXISTS vb_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    thumbnail_filename VARCHAR(255) DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    kind ENUM('image','video') NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_media_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_content_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    type ENUM('image','video','biography') NOT NULL,
    media_id INT DEFAULT NULL,
    subtitle VARCHAR(255) DEFAULT NULL,
    body TEXT DEFAULT NULL,
    duration_seconds INT NOT NULL DEFAULT 12,
    starts_on DATE DEFAULT NULL,
    ends_on DATE DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_ci_company (company_id),
    CONSTRAINT fk_vb_ci_media FOREIGN KEY (media_id) REFERENCES vb_media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(400) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_pl_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_playlist_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id INT NOT NULL,
    content_item_id INT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    duration_override INT DEFAULT NULL,
    CONSTRAINT fk_vb_pi_playlist FOREIGN KEY (playlist_id) REFERENCES vb_playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_vb_pi_content  FOREIGN KEY (content_item_id) REFERENCES vb_content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    playlist_id INT NOT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    days_of_week VARCHAR(20) DEFAULT NULL,
    priority INT NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_sch_company (company_id),
    CONSTRAINT fk_vb_sch_playlist FOREIGN KEY (playlist_id) REFERENCES vb_playlists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_settings (
    company_id INT UNSIGNED NOT NULL,
    setting_key VARCHAR(60) NOT NULL,
    setting_value TEXT,
    PRIMARY KEY (company_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_qr_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    caption VARCHAR(200) DEFAULT NULL,
    url VARCHAR(500) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    display_seconds INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_qr_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_marquee_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    message VARCHAR(500) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_mq_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_display_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    style ENUM('notice','warning','emergency') NOT NULL DEFAULT 'notice',
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    cleared_at DATETIME DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_ann_active (company_id, starts_at, expires_at, cleared_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registered TV screens. pair_token is the device credential the unattended
-- player presents to fetch its feed (screens are not logged-in users).
CREATE TABLE IF NOT EXISTS vb_screens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(200) DEFAULT NULL,
    pair_token CHAR(64) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_screen_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Playback heartbeat, now per screen instead of a single global row.
CREATE TABLE IF NOT EXISTS vb_display_status (
    screen_id INT NOT NULL PRIMARY KEY,
    last_seen_at DATETIME NOT NULL,
    current_title VARCHAR(200) DEFAULT NULL,
    current_type VARCHAR(40) DEFAULT NULL,
    current_index INT DEFAULT NULL,
    next_title VARCHAR(200) DEFAULT NULL,
    next_type VARCHAR(40) DEFAULT NULL,
    playlist_name VARCHAR(150) DEFAULT NULL,
    item_count INT NOT NULL DEFAULT 0,
    player_state VARCHAR(40) NOT NULL DEFAULT 'booting',
    client_time VARCHAR(60) DEFAULT NULL,
    client_version VARCHAR(40) DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_vb_ds_screen FOREIGN KEY (screen_id) REFERENCES vb_screens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vb_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    user_id INT DEFAULT NULL,
    username VARCHAR(120) DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vb_log_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Register the app -------------------------------------------------------

INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order, opt_in)
VALUES (
    'visionboard',
    'Vision Board',
    'Create and schedule content for your on-site TV screens',
    'http://localhost/centryk/visionBoard/admin/index.php',
    'https://centryk.net/visionBoard/admin/index.php',
    '📺',
    '#f43f5e',
    5,
    0
)
ON DUPLICATE KEY UPDATE
    label          = VALUES(label),
    description    = VALUES(description),
    url_local      = VALUES(url_local),
    url_production = VALUES(url_production),
    icon           = VALUES(icon),
    color          = VALUES(color),
    sort_order     = VALUES(sort_order),
    opt_in         = VALUES(opt_in);

INSERT IGNORE INTO user_app_access (user_id, app_id)
SELECT u.id, a.id FROM users u, apps a WHERE a.`key` = 'visionboard';

-- ---- Data migration from zoocm (ids preserved to keep relationships) --------
-- Guarded so re-running does nothing once vb_media has rows. uploaded_by /
-- created_by / user_id are nulled: they referenced the old zoocm.users table.

SET @has_zoocm := (SELECT COUNT(*) FROM information_schema.tables
                   WHERE table_schema = 'zoocm' AND table_name = 'media');
SET @vb_empty  := (SELECT COUNT(*) = 0 FROM vb_media);
SET @do_copy   := (@has_zoocm > 0 AND @vb_empty);

-- media
SET @sql := IF(@do_copy,
  'INSERT INTO vb_media (id, company_id, filename, thumbnail_filename, original_name, kind, mime, size_bytes, uploaded_by, created_at)
     SELECT id, @vb_cid, filename, thumbnail_filename, original_name, kind, mime, size_bytes, NULL, created_at FROM zoocm.media',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- content_items
SET @sql := IF(@do_copy,
  'INSERT INTO vb_content_items (id, company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active, created_at)
     SELECT id, @vb_cid, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active, created_at FROM zoocm.content_items',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- playlists
SET @sql := IF(@do_copy,
  'INSERT INTO vb_playlists (id, company_id, name, description, is_active, created_at)
     SELECT id, @vb_cid, name, description, is_active, created_at FROM zoocm.playlists',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- playlist_items
SET @sql := IF(@do_copy,
  'INSERT INTO vb_playlist_items (id, playlist_id, content_item_id, position, duration_override)
     SELECT id, playlist_id, content_item_id, position, duration_override FROM zoocm.playlist_items',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- schedules
SET @sql := IF(@do_copy,
  'INSERT INTO vb_schedules (id, company_id, name, playlist_id, start_date, end_date, start_time, end_time, days_of_week, priority, is_enabled, created_at)
     SELECT id, @vb_cid, name, playlist_id, start_date, end_date, start_time, end_time, days_of_week, priority, is_enabled, created_at FROM zoocm.schedules',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- settings
SET @sql := IF(@do_copy,
  'INSERT INTO vb_settings (company_id, setting_key, setting_value)
     SELECT @vb_cid, setting_key, setting_value FROM zoocm.settings',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- qr_codes
SET @sql := IF(@do_copy,
  'INSERT INTO vb_qr_codes (id, company_id, caption, url, position, display_seconds, is_active, created_at)
     SELECT id, @vb_cid, caption, url, position, display_seconds, is_active, created_at FROM zoocm.qr_codes',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- marquee_messages
SET @sql := IF(@do_copy,
  'INSERT INTO vb_marquee_messages (id, company_id, message, position, is_active, created_at)
     SELECT id, @vb_cid, message, position, is_active, created_at FROM zoocm.marquee_messages',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- display_announcements
SET @sql := IF(@do_copy,
  'INSERT INTO vb_display_announcements (id, company_id, message, style, starts_at, expires_at, cleared_at, created_by, created_at)
     SELECT id, @vb_cid, message, style, starts_at, expires_at, cleared_at, NULL, created_at FROM zoocm.display_announcements',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
-- activity_logs
SET @sql := IF(@do_copy,
  'INSERT INTO vb_activity_logs (id, company_id, user_id, username, action, entity_type, entity_id, details, ip_address, created_at)
     SELECT id, @vb_cid, NULL, username, action, entity_type, entity_id, details, ip_address, created_at FROM zoocm.activity_logs',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed one screen for the default company so there is a token to test the feed.
INSERT INTO vb_screens (company_id, name, location, pair_token)
SELECT @vb_cid, 'Main Display', 'Lobby', SHA2(CONCAT(RAND(), UUID()), 256)
WHERE NOT EXISTS (SELECT 1 FROM vb_screens WHERE company_id = @vb_cid);
