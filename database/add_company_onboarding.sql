-- Company onboarding flag. Set when the company admin completes (or skips) the
-- first-login setup wizard (nature of business + apps). NULL = not yet onboarded
-- → the wizard is shown. Additive + idempotent. Run against centryk_core.
ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS onboarded_at DATETIME NULL AFTER customer_noun_plural;

-- Existing companies are already established — don't greet them with the wizard.
-- Only companies created AFTER this migration (inserted with onboarded_at NULL)
-- will see it. Run once; safe to re-run (only touches still-NULL rows).
UPDATE companies SET onboarded_at = NOW() WHERE onboarded_at IS NULL;
