-- Belize public & bank holidays (Holidays Act, Ch. 289) — national, not
-- company-scoped. Drives calendar markers in Centryk and the holiday panels
-- in MyPay (which pulls this table).
--
-- pay_rate = multiplier for hours WORKED on that day, per the Labour Act /
-- Ministry of Labour guidance:
--   2.00  Good Friday, Easter Monday, Christmas Day  (double time)
--   1.50  every other public/bank holiday, incl. Holy Saturday  (time and a half)
--
-- Dates seeded from the Government Press Office notices:
--   https://www.pressoffice.gov.bz/public-and-bank-holidays-2025/
--   https://www.pressoffice.gov.bz/public-and-bank-holidays-2026/
-- CHECK against the gazette PDF before relying on the weekend-adjacent 2026
-- rows (Emancipation Day Sat 1 Aug, Boxing Day Sat 26 Dec) — they may be
-- moved to the following Monday. Fix any date in public/admin-holidays.php.
--
-- Idempotent. Run against centryk_core:
--   C:/xampp/mysql/bin/mysql.exe -u root centryk_core < database/add_public_holidays.sql

CREATE TABLE IF NOT EXISTS public_holidays (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date  DATE NOT NULL,
    name          VARCHAR(120) NOT NULL,
    category      ENUM('public','bank','both') NOT NULL DEFAULT 'both',
    pay_rate      DECIMAL(3,2) NOT NULL DEFAULT 1.50,
    observed_note VARCHAR(120) NOT NULL DEFAULT '',
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_public_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO public_holidays (holiday_date, name, category, pay_rate, observed_note) VALUES
    -- 2025
    ('2025-01-01', "New Year's Day",                        'both', 1.50, ''),
    ('2025-01-15', 'George Price Day',                       'both', 1.50, ''),
    ('2025-03-10', 'National Heroes and Benefactor Day',     'both', 1.50, 'Moved from Sun 9 Mar'),
    ('2025-04-18', 'Good Friday',                            'both', 2.00, ''),
    ('2025-04-19', 'Holy Saturday',                          'both', 1.50, ''),
    ('2025-04-21', 'Easter Monday',                          'both', 2.00, ''),
    ('2025-05-01', 'Labour Day',                             'both', 1.50, ''),
    ('2025-08-01', 'Emancipation Day',                       'both', 1.50, ''),
    ('2025-09-10', "St. George's Caye Day",                  'both', 1.50, ''),
    ('2025-09-22', 'Independence Day',                       'both', 1.50, 'Moved from Sun 21 Sep'),
    ('2025-10-13', "Indigenous Peoples' Resistance Day",     'both', 1.50, 'Moved from Sun 12 Oct'),
    ('2025-11-19', 'Garifuna Settlement Day',                'both', 1.50, ''),
    ('2025-12-25', 'Christmas Day',                          'both', 2.00, ''),
    ('2025-12-26', 'Boxing Day',                             'both', 1.50, ''),
    -- 2026
    ('2026-01-01', "New Year's Day",                        'both', 1.50, ''),
    ('2026-01-15', 'George Price Day',                       'both', 1.50, ''),
    ('2026-03-09', 'National Heroes and Benefactor Day',     'both', 1.50, ''),
    ('2026-04-03', 'Good Friday',                            'both', 2.00, ''),
    ('2026-04-04', 'Holy Saturday',                          'both', 1.50, ''),
    ('2026-04-06', 'Easter Monday',                          'both', 2.00, ''),
    ('2026-05-01', 'Labour Day',                             'both', 1.50, ''),
    ('2026-08-01', 'Emancipation Day',                       'both', 1.50, 'Verify: falls on Saturday'),
    ('2026-09-10', "St. George's Caye Day",                  'both', 1.50, ''),
    ('2026-09-21', 'Independence Day',                       'both', 1.50, ''),
    ('2026-10-12', "Indigenous Peoples' Resistance Day",     'both', 1.50, ''),
    ('2026-11-19', 'Garifuna Settlement Day',                'both', 1.50, ''),
    ('2026-12-25', 'Christmas Day',                          'both', 2.00, ''),
    ('2026-12-26', 'Boxing Day',                             'both', 1.50, 'Verify: falls on Saturday')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    category = VALUES(category),
    pay_rate = VALUES(pay_rate),
    observed_note = VALUES(observed_note);
