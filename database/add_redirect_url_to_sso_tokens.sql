-- Stores the final redirect URL inside the token row so session_bridge.php
-- never has to trust a user-supplied "next" parameter.
ALTER TABLE sso_tokens
    ADD COLUMN IF NOT EXISTS redirect_url VARCHAR(512) NULL DEFAULT NULL;
