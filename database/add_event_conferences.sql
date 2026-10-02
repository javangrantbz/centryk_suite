-- Online conferences hosted on Centryk TV, attached to Calendar events.
-- Run against centryk_core. Idempotent. (ConferenceService::ensureSchema()
-- creates the same tables on first use, so forgetting this is not fatal.)

CREATE TABLE IF NOT EXISTS event_conferences (
    event_id         INT UNSIGNED NOT NULL PRIMARY KEY,
    room_token       CHAR(32)     NOT NULL,
    start_time       TIME         NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    status           ENUM('scheduled','live','ended') NOT NULL DEFAULT 'scheduled',
    started_at       DATETIME     NULL,
    ended_at         DATETIME     NULL,
    last_active_at   DATETIME     NULL,
    reminder_sent_at DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conference_token (room_token),
    CONSTRAINT fk_conference_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conference_presence (
    event_id     INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    CONSTRAINT fk_presence_conference FOREIGN KEY (event_id) REFERENCES event_conferences(event_id) ON DELETE CASCADE,
    CONSTRAINT fk_presence_user       FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
);
