-- Cross-company playlist sharing: one company can share a playlist with
-- another, either "locked" (owner defines the play window, recipient just
-- turns it on) or "editable" (recipient schedules it like their own).
CREATE TABLE IF NOT EXISTS vb_playlist_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id INT NOT NULL,
    owner_company_id INT UNSIGNED NOT NULL,
    shared_with_company_id INT UNSIGNED NOT NULL,
    mode ENUM('locked','editable') NOT NULL DEFAULT 'locked',
    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
    -- Owner-defined play window, used only when mode = 'locked'.
    locked_start_date DATE DEFAULT NULL,
    locked_end_date DATE DEFAULT NULL,
    locked_start_time TIME DEFAULT NULL,
    locked_end_time TIME DEFAULT NULL,
    locked_days_of_week VARCHAR(20) DEFAULT NULL,
    locked_priority INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_vb_share (playlist_id, shared_with_company_id),
    CONSTRAINT fk_vb_share_playlist FOREIGN KEY (playlist_id) REFERENCES vb_playlists(id) ON DELETE CASCADE,
    INDEX idx_vb_share_owner (owner_company_id),
    INDEX idx_vb_share_recipient (shared_with_company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marks a schedule row as auto-created from a locked share, so the admin UI
-- can render it read-only and owner edits to the locked window can propagate.
ALTER TABLE vb_schedules
    ADD COLUMN source_share_id INT DEFAULT NULL AFTER playlist_id,
    ADD CONSTRAINT fk_vb_sch_share FOREIGN KEY (source_share_id) REFERENCES vb_playlist_shares(id) ON DELETE CASCADE;
