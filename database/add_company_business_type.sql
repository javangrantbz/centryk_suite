-- Company business type + customer noun (Centryk is the source of truth).
-- Set during first-login onboarding (Layer B) and editable in the company
-- profile. Flows to OnePay via the SSO company sync + the OnePay profile webhook,
-- where it drives the invoicing UI wording ("Batch Invoice Students" etc.).
-- Additive + idempotent. Run against centryk_core.
--
--   business_type            e.g. 'school','gym','clinic','retail','restaurant',
--                            'services','property','other'  (nullable = unset)
--   customer_noun_singular   e.g. 'Student'  (nullable → apps default to 'Customer')
--   customer_noun_plural     e.g. 'Students' (nullable → apps default to 'Customers')

ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS business_type          VARCHAR(40) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS customer_noun_singular VARCHAR(40) NULL AFTER business_type,
    ADD COLUMN IF NOT EXISTS customer_noun_plural   VARCHAR(40) NULL AFTER customer_noun_singular;
