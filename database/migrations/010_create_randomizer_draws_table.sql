-- 010: Randomizer draw history (Phase 5).
-- One row per draw result. Kept separate from registration_scans: recording a
-- winner never changes registration or Minor eligibility. No repeat-winner
-- rule is enforced here (none has been defined).
-- randomizer_type is shared with the future Major Randomizer.

CREATE TABLE IF NOT EXISTS randomizer_draws (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED  NOT NULL,
    attendee_id       INT UNSIGNED  NOT NULL,
    randomizer_type   ENUM('minor', 'major') NOT NULL,
    drawn_by          INT UNSIGNED  NULL,
    selected_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_randomizer_draws_event_type_time (event_id, randomizer_type, selected_at),
    KEY idx_randomizer_draws_attendee (attendee_id),
    KEY idx_randomizer_draws_drawn_by (drawn_by),
    CONSTRAINT fk_randomizer_draws_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_randomizer_draws_attendee
        FOREIGN KEY (attendee_id) REFERENCES attendees (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_randomizer_draws_user
        FOREIGN KEY (drawn_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
