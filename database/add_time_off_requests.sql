-- Employee time-off requests (vacation / PTO / sick) for the Calendar app.
-- Approval by an admin or manager generates one `events` row per day in the
-- range (no attendees, so it's visible company-wide like a holiday event).
-- Run against centryk_core. Idempotent.

CREATE TABLE IF NOT EXISTS time_off_requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id     INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,                                  -- requester
    type           ENUM('vacation','pto','sick') NOT NULL DEFAULT 'vacation',
    start_date     DATE          NOT NULL,
    end_date       DATE          NOT NULL,
    reason         VARCHAR(500)  NULL,
    status         ENUM('pending','approved','denied','cancelled') NOT NULL DEFAULT 'pending',
    decided_by     INT UNSIGNED  NULL,
    decided_at     DATETIME      NULL,
    decision_note  VARCHAR(500)  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tor_company_status (company_id, status),
    INDEX idx_tor_user (user_id),
    CONSTRAINT fk_tor_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_tor_user    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_tor_decider FOREIGN KEY (decided_by) REFERENCES users(id)     ON DELETE SET NULL
);

-- Links a generated calendar event back to the request that created it, so
-- denying/cancelling an approved request can clean up its events directly.
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS time_off_request_id INT UNSIGNED NULL AFTER event_type,
    ADD INDEX IF NOT EXISTS idx_events_time_off (time_off_request_id);
