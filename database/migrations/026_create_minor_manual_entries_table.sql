-- 026: Manual additions to a day's Minor pool (source = manual).
-- Does NOT create a check-in: registration_scans stays untouched.
-- Minor pool for a day = registered that day UNION manually added that day.
CREATE TABLE IF NOT EXISTS minor_manual_entries (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED  NOT NULL,
    event_day_id   INT UNSIGNED  NOT NULL,
    attendee_id    INT UNSIGNED  NOT NULL,
    added_by       INT UNSIGNED  NULL,
    reason         VARCHAR(200)  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_minor_manual_day_attendee (event_day_id, attendee_id),
    KEY idx_minor_manual_attendee_event (attendee_id, event_id),
    KEY idx_minor_manual_day_event (event_day_id, event_id),
    KEY idx_minor_manual_added_by (added_by),
    CONSTRAINT fk_minor_manual_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_minor_manual_attendee_event
        FOREIGN KEY (attendee_id, event_id) REFERENCES attendees (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_minor_manual_added_by
        FOREIGN KEY (added_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
