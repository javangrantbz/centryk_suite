-- OneLink's per-merchant fee percentage, returned as `perc` by POST /user/create
-- (e.g. "0.0275" = 2.75%). Used to estimate OneLink's revenue on the payment
-- ledger — an estimate, not authoritative, since OneLink doesn't expose the
-- real per-transaction fee anywhere.
-- Run against centryk_core. Idempotent.

ALTER TABLE onelink_credentials
    ADD COLUMN IF NOT EXISTS fee_percentage DECIMAL(6,4) NULL AFTER access_code;
