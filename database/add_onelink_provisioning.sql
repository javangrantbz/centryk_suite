-- Auto-provisioning support for OneLink merchant accounts (POST /user/create).
-- access_code is what the merchant enters at op.onelink.bz's login tab;
-- onelink_uid/onelink_uuid are OneLink's own identifiers for the account;
-- provisioned_at/provision_error track auto-provision attempts.
-- Run against centryk_core. Idempotent.

ALTER TABLE onelink_credentials
    ADD COLUMN IF NOT EXISTS access_code     VARCHAR(16)  NOT NULL DEFAULT '' AFTER token,
    ADD COLUMN IF NOT EXISTS onelink_uid     INT UNSIGNED NULL AFTER access_code,
    ADD COLUMN IF NOT EXISTS onelink_uuid    VARCHAR(64)  NULL AFTER onelink_uid,
    ADD COLUMN IF NOT EXISTS provisioned_at  DATETIME     NULL AFTER onelink_uuid,
    ADD COLUMN IF NOT EXISTS provision_error VARCHAR(255) NULL AFTER provisioned_at;
