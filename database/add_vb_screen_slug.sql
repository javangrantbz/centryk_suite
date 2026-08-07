-- Short-link slug for TV screens (e.g. centryk.net/vb/zoo instead of the
-- long token-bearing display link). Global uniqueness because the short
-- link lives in a single flat /vb/<slug> namespace across all companies.
ALTER TABLE vb_screens
    ADD COLUMN slug VARCHAR(64) NULL UNIQUE AFTER pair_token;
