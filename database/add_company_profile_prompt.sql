-- Lets the dashboard re-prompt a company admin to finish their company profile
-- (phone / email / address) after onboarding. "Skip for now" on that step sets
-- this ~14 days into the future; a NULL / past value means "prompt is due".
ALTER TABLE companies
    ADD COLUMN profile_prompt_snoozed_until DATETIME NULL AFTER onboarded_at;
