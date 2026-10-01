-- 027: Every scan attempt per day (for Recent Scans with result filters).
-- Counts of successful check-ins still come from registration_scans.
CREATE TABLE IF NOT EXISTS scan_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id       INT UNSIGNED    NOT NULL,
    event_day_id   INT UNSIGNED    NOT NULL,
    user_id        INT UNSIGNED    NULL,
    attendee_id    INT UNSIGNED    NULL,
    result         ENUM('registered', 'already_registered', 'invalid_qr', 'wrong_event', 'attendee_inactive') NOT NULL,
    scanned_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_scan_logs_day_time (event_day_id, scanned_at),
    KEY idx_scan_logs_day_user_time (event_day_id, user_id, scanned_at),
    KEY idx_scan_logs_day_result (event_day_id, result),
    KEY idx_scan_logs_user (user_id),
    KEY idx_scan_logs_attendee (attendee_id),
    KEY idx_scan_logs_event (event_id),
    CONSTRAINT fk_scan_logs_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_scan_logs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_scan_logs_attendee
        FOREIGN KEY (attendee_id) REFERENCES attendees (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
