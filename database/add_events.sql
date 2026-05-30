-- Calendar events for Centryk Calendar app.
-- Run against centryk_core. Idempotent.

CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id  INT UNSIGNED NOT NULL,
    title       VARCHAR(180) NOT NULL,
    description TEXT          NULL,
    event_date  DATE          NOT NULL,
    event_type  VARCHAR(40)   NOT NULL DEFAULT 'other',
    color       VARCHAR(20)   NOT NULL DEFAULT 'slate',
    created_by  INT UNSIGNED  NOT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_events_company_date (company_id, event_date),
    INDEX idx_events_created_by   (created_by),
    CONSTRAINT fk_events_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_user    FOREIGN KEY (created_by) REFERENCES users(id)     ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS event_attendees (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id   INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_user (event_id, user_id),
    CONSTRAINT fk_ea_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_ea_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
);
