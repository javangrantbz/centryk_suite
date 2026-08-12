ALTER TABLE vb_screens
    ADD COLUMN playlist_id INT NULL AFTER company_id,
    ADD INDEX idx_vb_screen_playlist (playlist_id),
    ADD CONSTRAINT fk_vb_screen_playlist
        FOREIGN KEY (playlist_id) REFERENCES vb_playlists(id) ON DELETE CASCADE;
