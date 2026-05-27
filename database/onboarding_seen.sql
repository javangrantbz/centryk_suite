-- Add onboarding_seen flag to users
-- Users who haven't dismissed the welcome modal, or whose password is still
-- a default value, will see the onboarding modal on login.
ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_seen TINYINT(1) NOT NULL DEFAULT 0;
