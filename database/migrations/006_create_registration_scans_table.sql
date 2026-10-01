-- 006: Registration scans (successful check-ins).
-- One row = one attendee registered at one event. The unique key prevents an
-- attendee from being registered twice; repeat scans should be rejected by the
-- API and can be recorded in audit_logs.
-- The composite FK (attendee_id, event_id) guarantees the attendee belongs to
-- the same event the scan is recorded against.

CREATE TABLE IF NOT EXISTS registration_scans (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED  NOT NULL,
    attendee_id       INT UNSIGNED  NOT NULL,
    qr_code_id        INT UNSIGNED  NULL,
    scanner_user_id   INT UNSIGNED  NULL,
    scanned_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_scans_event_attendee (event_id, attendee_id),
    KEY idx_registration_scans_attendee_event (attendee_id, event_id),
    KEY idx_registration_scans_event_time (event_id, scanned_at),
    KEY idx_registration_scans_scanner (scanner_user_id),
    KEY idx_registration_scans_qr_code (qr_code_id),
    CONSTRAINT fk_registration_scans_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_registration_scans_attendee_event
        FOREIGN KEY (attendee_id, event_id) REFERENCES attendees (id, event_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_registration_scans_qr_code
        FOREIGN KEY (qr_code_id) REFERENCES attendee_qr_codes (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_registration_scans_scanner
        FOREIGN KEY (scanner_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
