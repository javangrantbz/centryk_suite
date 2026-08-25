-- Tracks whether a company's settlement bank account (company_bank_accounts)
-- has been pushed to OneLink via POST /user/bank/info, and the outcome.
ALTER TABLE company_bank_accounts
    ADD COLUMN IF NOT EXISTS onelink_bi_uuid    VARCHAR(64)  NULL AFTER branch,
    ADD COLUMN IF NOT EXISTS onelink_synced_at  DATETIME     NULL AFTER onelink_bi_uuid,
    ADD COLUMN IF NOT EXISTS onelink_sync_error VARCHAR(255) NULL AFTER onelink_synced_at;
