-- 018: One check-in per attendee PER DAY (was per event). The composite FK
-- guarantees the day belongs to the same event as the check-in.
ALTER TABLE registration_scans
    MODIFY event_day_id INT UNSIGNED NOT NULL,
    DROP INDEX uq_registration_scans_event_attendee,
    ADD UNIQUE KEY uq_registration_scans_day_attendee (event_day_id, attendee_id),
    ADD KEY idx_registration_scans_day_time (event_day_id, scanned_at),
    ADD KEY idx_registration_scans_day_scanner (event_day_id, scanner_user_id),
    ADD CONSTRAINT fk_registration_scans_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
